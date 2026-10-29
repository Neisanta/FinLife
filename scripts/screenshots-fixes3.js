/* Screenshots finais das 3 correções. */
const fs = require('fs');
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
const OUT = process.env.TEMP + '\\fl_shots_fixes3';
if (!fs.existsSync(OUT)) { fs.mkdirSync(OUT, { recursive: true }); }
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
    ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id !== undefined && p.has(m.id)) { const e = p.get(m.id); p.delete(m.id); e.resolve(m.result); } };
    const send = (method, params) => new Promise(resolve => { const i = ++id; p.set(i, { resolve }); ws.send(JSON.stringify({ id: i, method, params })); });
    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        return r.exceptionDetails ? null : r.result.value;
    };
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');
    async function navigate(url, ms) { await send('Page.navigate', { url }); await sleep(ms || 2500); }
    async function shot(name) {
        const res = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(OUT + '\\' + name + '.png', Buffer.from(res.data, 'base64'));
        console.log('shot: ' + name);
    }

    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await navigate(BASE + '/login');
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
        return true;
    })()`);

    await navigate(BASE + '/dashboard', 3200);
    await shot('fix-01-fundo-mobile');

    await evalJs(`document.getElementById('bn-more').click(); true`);
    await sleep(500);
    await shot('fix-02-sheet-user');
    await evalJs(`FL.closeSheet(); true`);
    await sleep(300);

    await navigate(BASE + '/mercados', 4500);
    await shot('fix-03-mercado-vivo');
    await sleep(3000);
    await shot('fix-04-mercado-apos-updates');

    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await navigate(BASE + '/dashboard', 3000);
    await shot('fix-05-fundo-desktop');

    await evalJs(`FL.theme.apply('dark'); true`);
    await sleep(400);
    await shot('fix-06-fundo-dark');
    await evalJs(`FL.theme.apply('light'); true`);

    ws.close();
    console.log('CONCLUIDO');
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
