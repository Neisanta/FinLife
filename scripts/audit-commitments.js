/* FinLife — E2E Compromissos e Contas Fixas: UI real, ciclo completo. */
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
function getTargets() {
    return new Promise((resolve, reject) => {
        http.get('http://127.0.0.1:9222/json', (res) => {
            let d = ''; res.on('data', c => d += c); res.on('end', () => resolve(JSON.parse(d)));
        }).on('error', reject);
    });
}
async function main() {
    const targets = await getTargets();
    const page = targets.find(t => t.type === 'page');
    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
    let id = 0; const p = new Map();
    let jsErrors = [];
    ws.onmessage = ev => {
        const m = JSON.parse(ev.data);
        if (m.id !== undefined && p.has(m.id)) { const e = p.get(m.id); p.delete(m.id); e.resolve(m.result); }
        else if (m.method === 'Runtime.exceptionThrown') { jsErrors.push(m.params.exceptionDetails.text); }
    };
    const send = (method, params) => new Promise(resolve => { const i = ++id; p.set(i, { resolve }); ws.send(JSON.stringify({ id: i, method, params })); });
    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) { throw new Error(r.exceptionDetails.exception.description); }
        return r.result.value;
    };
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');

    const results = [];
    function check(name, value) {
        results.push({ name, ok: value === true, value });
        console.log((value === true ? '  OK ' : '  FALHOU ') + name + (value === true ? '' : ' => ' + JSON.stringify(value)));
    }

    // limpa compromissos anteriores via API
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(1800);
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
        // limpa compromissos de testes anteriores
        const r = await fetch('${BASE}/api/commitments.php?action=list', { credentials: 'same-origin' });
        const d = await r.json();
        for (const c of (d.commitments || [])) {
            await fetch('${BASE}/api/commitments.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ action: 'delete', id: c.id }) });
        }
        return true;
    })()`);

    console.log('--- NAVEGAÇÃO E KPIs ---');
    await send('Page.navigate', { url: BASE + '/compromissos' });
    await sleep(2200);
    const nav = await evalJs(`(() => ({
        route: window.location.pathname.includes('compromissos'),
        kpis: document.querySelectorAll('#cm-kpis .stat-card').length,
        empty: !document.getElementById('cm-empty').hidden,
        sidebar: [...document.querySelectorAll('.nav-link')].some(a => a.href.includes('compromissos') && a.offsetParent !== null)
    }))()`);
    check('rota /compromissos carrega', nav.route);
    check('6 KPIs renderizados', nav.kpis === 6, nav.kpis);
    check('estado vazio visível', nav.empty);
    check('link na sidebar', nav.sidebar);

    console.log('--- CRIAR DÍVIDA (R$ 1.200 / R$ 200 × 6) ---');
    await evalJs(`document.getElementById('cm-new').click(); true`);
    await sleep(600);
    const form = await evalJs(`(() => ({
        modal: !!document.querySelector('.modal'),
        kindRadios: document.querySelectorAll('input[name="cm-kind"]').length,
        debtFields: document.querySelector('.cm-debt-only') ? document.querySelector('.cm-debt-only').style.display !== 'none' : false
    }))()`);
    check('modal de criação abre', form.modal);
    check('toggle dívida/conta fixa', form.kindRadios === 2);
    check('padrão é conta fixa (campos de dívida ocultos)', form.debtFields === false);
    // preenche: dívida com o pai
    await evalJs(`(() => {
        document.querySelector('input[name="cm-kind"][value="debt"]').checked = true;
        document.querySelector('input[name="cm-kind"][value="debt"]').dispatchEvent(new Event('change', { bubbles: true }));
        document.getElementById('f-name').value = 'Dívida com o papai';
        document.getElementById('f-payee').value = 'João (pai)';
        document.getElementById('f-total').value = '1200';
        document.getElementById('f-installment').value = '200';
        document.getElementById('f-installments').value = '6';
        var d5 = new Date(); d5.setDate(d5.getDate() + 5); document.getElementById('f-next-due').value = d5.getFullYear() + '-' + String(d5.getMonth() + 1).padStart(2, '0') + '-' + String(d5.getDate()).padStart(2, '0');
        document.getElementById('f-category-key').value = 'divida_pessoal';
        document.querySelector('[data-save]').click();
        return true;
    })()`);
    await sleep(2500);
    const afterCreate = await evalJs(`(() => {
        const cards = [...document.querySelectorAll('.cm-card')];
        return {
            count: cards.length,
            name: cards[0] ? cards[0].querySelector('h4').textContent : null,
            badge: cards[0] ? cards[0].querySelector('.badge').textContent : null,
            balance: cards[0] ? cards[0].querySelectorAll('.cm-cell strong')[3] ? cards[0].querySelectorAll('.cm-cell strong')[3].textContent : null : null,
            payBtn: cards[0] ? !!([...cards[0].querySelectorAll('button')].find(b => b.textContent === 'Registrar pagamento')) : false
        };
    })()`);
    check('card da dívida aparece', afterCreate.count === 1 && afterCreate.name === 'Dívida com o papai', afterCreate);
    check('status válido (Em dia)', afterCreate.badge === 'Em dia', afterCreate.badge);
    check('saldo devedor R$ 1.200,00 no card', afterCreate.balance === 'R$ 1.200,00', afterCreate.balance);
    check('botão Registrar pagamento presente', afterCreate.payBtn);

    console.log('--- PAGAMENTO PARCIAL VIA UI ---');
    await evalJs(`[...document.querySelectorAll('.cm-card button')].find(b => b.textContent === 'Registrar pagamento').click(); true`);
    await sleep(700);
    const payModal = await evalJs(`(() => ({
        open: !!document.querySelector('.modal #pay-amount'),
        amount: document.querySelector('.modal #pay-amount') ? parseFloat(document.querySelector('.modal #pay-amount').value) : null,
        hint: !!document.querySelector('.modal .hint')
    }))()`);
    check('modal de pagamento abre com valor sugerido 200', payModal.open && payModal.amount === 200, payModal);
    check('dica de pagamento parcial', payModal.hint);
    // paga 100 (parcial)
    await evalJs(`(() => {
        document.querySelector('.modal #pay-amount').value = '100';
        document.querySelector('.modal #pay-note').value = 'metade agora';
        document.querySelector('.modal #pay-confirm').click();
        return true;
    })()`);
    await sleep(2500);
    const afterPartial = await evalJs(`(() => {
        const card = document.querySelector('.cm-card');
        return {
            balance: card.querySelectorAll('.cm-cell strong')[3].textContent,
            progress: card.querySelector('.cm-progress small') ? card.querySelector('.cm-progress small').textContent : null
        };
    })()`);
    check('saldo após parcial: R$ 1.100,00', afterPartial.balance === 'R$ 1.100,00', afterPartial.balance);
    check('progresso 8,3% exibido', /8,3%/.test(afterPartial.progress || ''), afterPartial.progress);

    // paga o restante do período (100)
    await evalJs(`[...document.querySelectorAll('.cm-card button')].find(b => b.textContent === 'Registrar pagamento').click(); true`);
    await sleep(600);
    await evalJs(`(() => {
        const input = document.querySelector('.modal #pay-amount');
        const suggested = parseFloat(input.value);
        input.value = String(suggested - 100); // paga o restante do período
        document.querySelector('.modal #pay-confirm').click();
        return true;
    })()`);
    await sleep(2500);
    const afterFull = await evalJs(`(() => {
        const card = document.querySelector('.cm-card');
        return { balance: card.querySelectorAll('.cm-cell strong')[3].textContent };
    })()`);
    check('saldo após integral do período: R$ 1.000,00', afterFull.balance === 'R$ 1.000,00', afterFull.balance);

    console.log('--- DETALHES E HISTÓRICO ---');
    await evalJs(`[...document.querySelectorAll('.cm-card button')].find(b => b.textContent === 'Detalhes').click(); true`);
    await sleep(2000);
    const detail = await evalJs(`(() => ({
        modal: !!document.querySelector('.modal'),
        sections: [...document.querySelectorAll('.modal .cm-section h4')].map(h => h.textContent),
        payments: [...document.querySelectorAll('.modal .cm-occ')].length,
        undoBtns: document.querySelectorAll('.modal [data-undo]').length,
        actions: [...document.querySelectorAll('.modal [data-act]')].map(b => b.getAttribute('data-act')).join(',')
    }))()`);
    check('modal de detalhes abre', detail.modal);
    check('seções próximas + histórico', detail.sections.length >= 2, detail.sections);
    check('pagamentos listados', detail.payments >= 2, detail.payments);
    check('botão undo por pagamento', detail.undoBtns >= 2);
    check('ações editar/pausar/quitar presentes', /edit/.test(detail.actions) && /pause/.test(detail.actions) && /settle/.test(detail.actions), detail.actions);
    // fecha
    await evalJs(`document.querySelector('.modal-close').click(); true`);
    await sleep(400);

    console.log('--- CONTA FIXA SEM DÍVIDA ---');
    await evalJs(`document.getElementById('cm-new').click(); true`);
    await sleep(500);
    await evalJs(`(() => {
        // recurring já é o padrão — campos de dívida devem sumir
        document.getElementById('f-name').value = 'Internet de casa';
        document.getElementById('f-payee').value = 'Claro';
        document.getElementById('f-installment').value = '89.90';
        var d3 = new Date(); d3.setDate(d3.getDate() + 3); document.getElementById('f-next-due').value = d3.getFullYear() + '-' + String(d3.getMonth() + 1).padStart(2, '0') + '-' + String(d3.getDate()).padStart(2, '0');
        document.getElementById('f-category-key').value = 'internet';
        document.querySelector('[data-save]').click();
        return true;
    })()`);
    await sleep(2200);
    const recurring = await evalJs(`(() => {
        const cards = [...document.querySelectorAll('.cm-card')];
        const internet = cards.find(c => c.querySelector('h4').textContent === 'Internet de casa');
        return {
            found: !!internet,
            cells: internet ? internet.querySelectorAll('.cm-cell').length : 0,
            balanceCell: internet ? internet.querySelectorAll('.cm-cell strong')[3] : null
        };
    })()`);
    check('conta fixa criada (sem toggle dívida)', recurring.found);
    check('sem célula de saldo devedor', !recurring.balanceCell, recurring.cells);

    console.log('--- DASHBOARD INTEGRADO ---');
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(2800);
    const dash = await evalJs(`(() => ({
        card: !!document.getElementById('commitments-card'),
        rows: document.querySelectorAll('.dash-commitment-row').length
    }))()`);
    check('card de compromissos no dashboard', dash.card);
    check('compromissos listados no dashboard', dash.rows >= 1, dash.rows);

    console.log('--- IA: PERGUNTAS DE COMPROMISSOS ---');
    await send('Page.navigate', { url: BASE + '/ai' });
    await sleep(2500);
    async function chat(msg) {
        await evalJs(`(() => { const i = document.getElementById('chat-text'); i.value = ${JSON.stringify(msg)}; document.getElementById('chat-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); return true; })()`);
        await evalJs(`(() => { const base = document.querySelectorAll('.msg').length; return new Promise(function (resolve) { let t = 0; const x = setInterval(function () { t++; if (document.querySelectorAll('.msg').length > base + 1 && !document.querySelector('.typing') || t > 60) { clearInterval(x); resolve(true); } }, 500); }); })()`);
        await sleep(600);
        return await evalJs(`(() => { const b = [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop(); return b ? b.textContent : ''; })()`);
    }
    const ai1 = await chat('Quanto ainda devo ao meu pai?');
    check('IA: saldo devedor do pai', /1\.0?000|1\.000/i.test(ai1.replace(/\s/g, '').replace('R$', '')) || /1\.000,00/.test(ai1), ai1.slice(0, 120));
    const ai2 = await chat('Quais contas preciso pagar este mês?');
    check('IA: agenda do mês com vencimentos', /vence/i.test(ai2), ai2.slice(0, 120));
    const ai3 = await chat('Quanto dos meus próximos salários já está comprometido?');
    check('IA: fluxo comprometido 60 dias', /comprometid/i.test(ai3), ai3.slice(0, 120));
    const ai4 = await chat('Quanto gasto mensalmente com contas fixas?');
    check('IA: custo mensal contas fixas', /por m(ê|e)s/i.test(ai4), ai4.slice(0, 120));

    check('console sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    // limpeza
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const r = await fetch('${BASE}/api/commitments.php?action=list', { credentials: 'same-origin' });
        const d = await r.json();
        for (const c of (d.commitments || [])) {
            await fetch('${BASE}/api/commitments.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ action: 'delete', id: c.id }) }).catch(() => {});
        }
        return true;
    })()`);

    ws.close();
    const failed = results.filter(r => !r.ok);
    console.log('\n===== COMPROMISSOS E2E: ' + (results.length - failed.length) + '/' + results.length + ' OK =====');
    if (failed.length) { failed.forEach(f => console.log('FALHOU: ' + f.name + ' => ' + JSON.stringify(f.value))); process.exitCode = 1; }
    else { console.log('COMPROMISSOS E CONTAS FIXAS 100% FUNCIONAIS'); }
}
main().catch(e => { console.error('ERRO FATAL: ' + e.message); process.exit(1); });
