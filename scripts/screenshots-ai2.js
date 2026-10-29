/* Screenshot do chat IA v2 com rich cards. */
const fs = require('fs');
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
const OUT = process.env.TEMP + '\\fl_shots_ai2';
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

    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(1800);
    await evalJs(`(async () => { const c = document.querySelector('meta[name="csrf-token"]').content; await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': c }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) }); return true; })()`);
    await send('Page.navigate', { url: BASE + '/ai' });
    await sleep(2500);

    // limpa e faz perguntas que geram rich cards
    await evalJs(`(async () => { await fetch('${BASE}/api/ai.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, credentials: 'same-origin', body: JSON.stringify({ action: 'clear' }) }); return true; })()`);
    await sleep(400);
    await evalJs(`document.getElementById('chat-messages').innerHTML = ''; true`);

    async function chat(msg, wait) {
        await evalJs(`(() => { const i = document.getElementById('chat-text'); i.value = ${JSON.stringify(msg)}; document.getElementById('chat-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); return true; })()`);
        await sleep(wait || 2500);
    }

    await chat('qual minha saúde financeira?', 3500);
    await shot('ai2-01-saude-rich');

    await chat('meus maiores gastos do mês', 3000);
    await shot('ai2-02-maiores-gastos');

    await chat('como estão minhas metas?', 3000);
    await shot('ai2-03-metas-progress');

    await chat('quanto tenho em contas?', 3000);
    await shot('ai2-04-saldo-quick');

    ws.close();
    console.log('CONCLUIDO');
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
