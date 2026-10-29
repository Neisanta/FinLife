/* FinLife — E2E do chat IA v2: rich cards, quick replies, novas ações, contexto. */
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

    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(1800);
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
        return true;
    })()`);
    await send('Page.navigate', { url: BASE + '/ai' });
    await sleep(2500);

    // limpa conversa para começar limpa
    await evalJs(`(async () => {
        await fetch('${BASE}/api/ai.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, credentials: 'same-origin', body: JSON.stringify({ action: 'clear' }) });
        return true;
    })()`);
    await sleep(400);
    await evalJs(`document.getElementById('chat-messages').innerHTML = ''; true`);

    async function sendChat(msg, waitMs) {
        const before = await evalJs(`document.querySelectorAll('.msg').length`);
        await evalJs(`(() => {
            const i = document.getElementById('chat-text');
            i.value = ${JSON.stringify(msg)};
            document.getElementById('chat-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            return true;
        })()`);
        // espera a resposta do assistente chegar (polling; IA externa pode demorar)
        await evalJs(`(() => {
            const base = ${before};
            return new Promise(function (resolve) {
                let tries = 0;
                const t = setInterval(function () {
                    tries++;
                    const now = document.querySelectorAll('.msg').length;
                    if ((now > base + 1 && !document.querySelector('.typing')) || tries > 100) { clearInterval(t); resolve(true); }
                }, 600);
            });
        })()`);
        await sleep(waitMs || 1500);
    }

    // ===== 1) Rich cards: saldo =====
    console.log('--- Rich cards no chat ---');
    await sendChat('quanto tenho em contas?');
    let r = await evalJs(`(() => ({
        lastMsg: [...document.querySelectorAll('.msg.assistant')].pop(),
        stats: [...document.querySelectorAll('.chat-stats .chat-stat')].length,
        quick: [...document.querySelectorAll('.chat-quick .chat-quick-btn')].length,
        text: [...document.querySelectorAll('.msg.assistant .msg-bubble p')].pop() ? [...document.querySelectorAll('.msg.assistant .msg-bubble p')].pop().textContent : ''
    }))()`);
    check('saldo: rich stats renderizadas', r.stats >= 2, r.stats);
    check('saldo: quick replies dinâmicas', r.quick >= 2, r.quick);
    check('saldo: texto com valor', /R\$/.test(r.text), r.text);

    // ===== 2) Saúde financeira (score + rich + alert) =====
    await sendChat('qual minha saúde financeira?');
    r = await evalJs(`(() => ({
        stats: [...document.querySelectorAll('.chat-stats .chat-stat')].length,
        alert: document.querySelectorAll('.chat-alert').length,
        alertLevel: document.querySelector('.chat-alert') ? document.querySelector('.chat-alert').className : null,
        fullText: [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop() ? [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop().textContent : '',
        quick: [...document.querySelectorAll('.chat-quick .chat-quick-btn')].length
    }))()`);
    check('saúde: score no texto', /\/100/.test(r.fullText), r.fullText.slice(0, 80));
    check('saúde: rich stats', r.stats >= 3, r.stats);
    check('saúde: alerta com nível', r.alert >= 1 && /chat-alert-(good|warn|bad)/.test(r.alertLevel || ''), r.alertLevel);
    check('saúde: quick replies', r.quick >= 2, r.quick);

    // ===== 3) Maiores gastos (tx-list) =====
    await sendChat('meus maiores gastos do mês');
    r = await evalJs(`(() => ({
        txrows: document.querySelectorAll('.chat-txrow').length,
        title: document.querySelector('.chat-txlist-title') ? document.querySelector('.chat-txlist-title').textContent : '',
        amounts: [...document.querySelectorAll('.chat-txval')].map(e => e.textContent).length
    }))()`);
    check('maiores gastos: tx-list renderizada', r.txrows >= 3, r.txrows);
    check('maiores gastos: título do card', /maiores gastos/i.test(r.title), r.title);
    check('maiores gastos: valores nas linhas', r.amounts >= 3, r.amounts);

    // ===== 4) Metas (progress bars) =====
    await sendChat('como estão minhas metas?');
    r = await evalJs(`(() => ({
        progs: document.querySelectorAll('.chat-prog').length,
        bars: document.querySelectorAll('.chat-prog .bar-fill').length,
        quick: [...document.querySelectorAll('.chat-quick .chat-quick-btn')].map(b => b.textContent).join('|')
    }))()`);
    check('metas: progress bars renderizadas', r.progs >= 2, r.progs);
    check('metas: barras preenchidas', r.bars >= 2, r.bars);
    check('metas: quick sugere aporte', /aport/i.test(r.quick), r.quick);

    // ===== 5) Aporte via chat (ação com confirmação) =====
    console.log('--- Ações via conversa ---');
    await sendChat('aportei R$ 50 na reserva de emergência');
    r = await evalJs(`(() => ({
        confirmBtns: document.querySelectorAll('.chat-actions .btn').length,
        text: [...document.querySelectorAll('.msg.assistant .msg-bubble p')].pop() ? [...document.querySelectorAll('.msg.assistant .msg-bubble p')].pop().textContent : ''
    }))()`);
    check('aporte: pede confirmação', r.confirmBtns === 2, r.confirmBtns);
    check('aporte: valor e meta no texto', /R\$ 50/.test(r.text) && /reserva/i.test(r.text), r.text);
    await evalJs(`[...document.querySelectorAll('.chat-actions .btn-primary')].pop().click(); true`);
    await sleep(3500);
    r = await evalJs(`(() => ({ fullText: [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop() ? [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop().textContent : '' }))()`);
    check('aporte: confirmado com sucesso', /sucesso/i.test(r.fullText), r.fullText.slice(0, 100));

    // ===== 6) Alerta de preço via chat =====
    await sendChat('avise quando PETR4 chegar a R$ 40');
    r = await evalJs(`(() => ({
        confirmBtns: document.querySelectorAll('.chat-actions .btn').length,
        text: [...document.querySelectorAll('.msg.assistant .msg-bubble p')].pop() ? [...document.querySelectorAll('.msg.assistant .msg-bubble p')].pop().textContent : ''
    }))()`);
    check('alerta: pede confirmação', r.confirmBtns === 2);
    check('alerta: PETR4 e valor no texto', /PETR4/i.test(r.text) && /40/.test(r.text), r.text);
    await evalJs(`[...document.querySelectorAll('.chat-actions .btn-primary')].pop().click(); true`);
    await sleep(3500);
    r = await evalJs(`(() => ({ fullText: [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop() ? [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop().textContent : '' }))()`);
    check('alerta: criado com sucesso', /alerta criado/i.test(r.fullText), r.fullText.slice(0, 100));

    // ===== 7) Quick reply clicável funciona =====
    const quickText = await evalJs(`(() => { const q = [...document.querySelectorAll('.chat-quick .chat-quick-btn')].pop(); return q ? q.textContent : null; })()`);
    if (quickText) {
        await evalJs(`[...document.querySelectorAll('.chat-quick .chat-quick-btn')].pop().click(); true`);
        // espera a resposta chegar (polling até 12s — pode ir para a IA externa)
        r = await evalJs(`(() => {
            const count = document.querySelectorAll('.msg').length;
            return new Promise(function (resolve) {
                let tries = 0;
                const t = setInterval(function () {
                    tries++;
                    const now = document.querySelectorAll('.msg').length;
                    if (now > count || tries > 24) { clearInterval(t); resolve({ count: now, lastUser: [...document.querySelectorAll('.msg.user .msg-bubble p')].pop() ? [...document.querySelectorAll('.msg.user .msg-bubble p')].pop().textContent : '' }); }
                }, 500);
            });
        })()`);
        check('quick reply envia mensagem ao clicar', r.lastUser === quickText, { quick: quickText, sent: r.lastUser });
        r = await evalJs(`(() => ({ lastAssistant: [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop() ? [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop().textContent.slice(0, 100) : '' }))()`);
        check('quick reply gera resposta da IA', r.lastAssistant.length > 10, r.lastAssistant);
    } else {
        check('quick reply clicável funciona', false, 'nenhuma quick reply encontrada');
    }

    // ===== 8) Consulta NVIDIA: "qual melhor investimento?" =====
    console.log('--- Consultas educacionais (IA externa NVIDIA) ---');
    await sendChat('qual melhor investimento?', 30000);
    r = await evalJs(`(() => ({
        fullText: [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop() ? [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop().textContent : '',
        bolds: [...document.querySelectorAll('.msg.assistant .msg-bubble strong')].length,
        listItems: [...document.querySelectorAll('.msg.assistant .msg-bubble li')].length
    }))()`);
    check('investimento: resposta consultiva com dados reais', /R\$/.test(r.fullText), r.fullText.slice(0, 90));
    check('investimento: formatação rica (negrito)', r.bolds >= 2, r.bolds);
    check('investimento: múltiplas opções em lista', r.listItems >= 2, r.listItems);
    check('investimento: disclaimer educacional', /n(?:ã|a)o\s+(?:é|e)\s+recomend/i.test(r.fullText), 'disclaimer buscado (espaços nbsp cobertos)');

    await sendChat('o que é CDI?', 30000);
    r = await evalJs(`(() => ({
        fullText: [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop() ? [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop().textContent : ''
    }))()`);
    check('CDI: resposta educacional didática', /CDI/i.test(r.fullText) && r.fullText.length > 60, r.fullText.slice(0, 90));

    check('console sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    ws.close();
    const failed = results.filter(x => !x.ok);
    console.log('\n===== CHAT IA v2: ' + (results.length - failed.length) + '/' + results.length + ' OK =====');
    if (failed.length) { failed.forEach(f => console.log('FALHOU: ' + f.name + ' => ' + JSON.stringify(f.value))); process.exitCode = 1; }
    else { console.log('CHAT IA v2 100% FUNCIONAL'); }
}
main().catch(e => { console.error('ERRO FATAL: ' + e.message); process.exit(1); });
