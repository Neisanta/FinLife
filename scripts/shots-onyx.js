/* Screenshots do redesign Onyx — antes/depois comparáveis. */
const fs = require('fs');
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
const OUT = process.env.TEMP + '\\fl_shots_onyx';
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
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    async function shot(name) { const r = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(OUT + '\\' + name + '.png', Buffer.from(r.data, 'base64')); console.log('shot: ' + name); }

    // limpa a preferência de tema salva para ver o NOVO DEFAULT (dark)
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(1600);
    await evalJs(`localStorage.removeItem('fl-theme'); true`);
    await shot('onyx-00-login-default-dark');

    await evalJs(`(async () => { const c = document.querySelector('meta[name="csrf-token"]').content; await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': c }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) }); return true; })()`);

    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await evalJs(`localStorage.removeItem('fl-theme'); true`);
    // dashboard NOVO PADRÃO (dark onyx)
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(2800);
    await shot('onyx-01-dashboard-dark');

    // mercados dark
    await send('Page.navigate', { url: BASE + '/mercados' });
    await sleep(3200);
    await shot('onyx-02-mercados-dark');

    // compromissos dark
    await send('Page.navigate', { url: BASE + '/compromissos' });
    await sleep(2200);
    await shot('onyx-03-compromissos-dark');

    // light refinado
    await evalJs(`FL.theme.apply('light'); true`);
    await sleep(500);
    await shot('onyx-04-dashboard-light');
    await send('Page.navigate', { url: BASE + '/cartoes' });
    await sleep(2200);
    await shot('onyx-05-cartoes-light');

    // mobile dark
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(2200);
    await evalJs(`FL.theme.apply('dark'); true`);
    await sleep(400);
    await shot('onyx-06-mobile-dark');

    ws.close();
    console.log('CONCLUIDO');
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
