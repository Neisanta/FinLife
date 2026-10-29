/* Screenshots do módulo Compromissos. */
const fs = require('fs');
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
const OUT = process.env.TEMP + '\\fl_shots_commitments';
if (!fs.existsSync(OUT)) { fs.mkdirSync(OUT, { recursive: true }); }
function getT() {
    return new Promise((r, j) => {
        http.get('http://127.0.0.1:9222/json', res => { let d=''; res.on('data', c=>d+=c); res.on('end',()=>r(JSON.parse(d))); }).on('error', j);
    });
}
async function main() {
    const t = await getT();
    const page = t.find(x => x.type === 'page');
    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
    let id = 0; const p = new Map();
    ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id !== undefined && p.has(m.id)) { const e = p.get(m.id); p.delete(m.id); e.resolve(m.result); } };
    const send = (me, pa) => new Promise(r => { const i = ++id; p.set(i, { resolve: r }); ws.send(JSON.stringify({ id: i, method: me, params: pa })); });
    const evalJs = async (e) => { const r = await send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true }); return r.result ? r.result.value : null; };
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');
    async function shot(name) { const r = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(OUT + '\\' + name + '.png', Buffer.from(r.data, 'base64')); console.log('shot: ' + name); }

    const csrf = await (async () => {
        await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
        await send('Page.navigate', { url: BASE + '/login' });
        await sleep(1600);
        return await evalJs(`(async () => {
            const c = document.querySelector('meta[name="csrf-token"]').content;
            await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': c }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
            // limpa e cria dados demo do módulo
            const list = await (await fetch('${BASE}/api/commitments.php?action=list', { credentials: 'same-origin' })).json();
            for (const c of (list.commitments || [])) {
                await fetch('${BASE}/api/commitments.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': c }, credentials: 'same-origin', body: JSON.stringify({ action: 'delete', id: c.id }) }).catch(() => {});
            }
            const post = (body) => fetch('${BASE}/api/commitments.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': c }, credentials: 'same-origin', body: JSON.stringify(body) });
            const d5 = new Date(); d5.setDate(d5.getDate() + 5);
            const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            const r1 = await (await post({ action: 'create', name: 'Dívida com o papai', payee: 'João (pai)', kind: 'debt', category_key: 'divida_pessoal', total_amount: 1200, installment_amount: 200, periodicity: 'monthly', next_due: iso(d5), start_date: iso(new Date()), total_installments: 6, payment_method: 'pix' })).json();
            // paga 400 para o progresso aparecer
            await post({ action: 'pay', id: r1.id, amount: 200, paid_date: iso(new Date()), description: 'primeira parcela' });
            await post({ action: 'pay', id: r1.id, amount: 200, paid_date: iso(new Date()), description: 'adiantamento' });
            await post({ action: 'create', name: 'Internet de casa', payee: 'Claro Net', kind: 'recurring', category_key: 'internet', installment_amount: 89.9, periodicity: 'monthly', next_due: iso(new Date(Date.now() + 12 * 864e5)), start_date: iso(new Date()) });
            await post({ action: 'create', name: 'Acordo academia', payee: 'Academia XYZ', kind: 'recurring', category_key: 'mensalidade', installment_amount: 120, periodicity: 'monthly', next_due: iso(new Date(Date.now() - 4 * 864e5)), start_date: iso(new Date()) });
            await post({ action: 'create', name: 'Parcelas do celular', payee: 'Tio Carlos', kind: 'debt', category_key: 'emprestimo_informal', total_amount: 2400, installment_amount: 400, periodicity: 'monthly', next_due: iso(new Date(Date.now() + 18 * 864e5)), start_date: iso(new Date()), total_installments: 6, payment_method: 'transferencia' });
            return true;
        })()`);
    })();

    await send('Page.navigate', { url: BASE + '/compromissos' });
    await sleep(2500);
    await shot('cm-01-lista');

    // detalhe da dívida
    await evalJs(`[...document.querySelectorAll('.cm-card button')].find(b => b.textContent === 'Detalhes').click(); true`);
    await sleep(1500);
    await shot('cm-02-detalhe');
    await evalJs(`document.querySelector('.modal-close').click(); true`);
    await sleep(300);

    // modal de pagamento
    await evalJs(`[...document.querySelectorAll('.cm-card button')].find(b => b.textContent === 'Registrar pagamento').click(); true`);
    await sleep(800);
    await shot('cm-03-pagamento');
    await evalJs(`document.querySelector('.modal-close').click(); true`);
    await sleep(300);

    // formulário novo
    await evalJs(`document.getElementById('cm-new').click(); true`);
    await sleep(700);
    await evalJs(`document.querySelector('input[name="cm-kind"][value="debt"]').click(); true`);
    await sleep(300);
    await shot('cm-04-form');
    await evalJs(`document.querySelector('.modal-close').click(); true`);

    // mobile
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await send('Page.navigate', { url: BASE + '/compromissos' });
    await sleep(2200);
    await shot('cm-05-mobile');

    // dashboard com card de compromissos
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(2600);
    await shot('cm-06-dashboard');

    ws.close();
    console.log('CONCLUIDO');
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
