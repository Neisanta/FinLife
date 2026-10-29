/* FinLife — Screenshots da auditoria UX v2 (novo design mobile-first). */
const fs = require('fs');
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
const OUT = process.env.TEMP + '\\fl_shots_ux2';
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
    ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id && p.has(m.id)) { p.get(m.id)(m.result); p.delete(m.id); } };
    const send = (method, params) => new Promise(r => { const i = ++id; p.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) { return null; }
        return r.result.value;
    };
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');
    async function navigate(url, ms) { await send('Page.navigate', { url }); await sleep(ms || 2500); }
    async function shot(name) {
        const res = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(OUT + '\\' + name + '.png', Buffer.from(res.data, 'base64'));
        console.log('shot OK: ' + name);
    }

    // ============ MOBILE 390 (referência principal) ============
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await evalJs(`localStorage.setItem('fl-theme','light'); true`);
    await navigate(BASE + '/login');
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
        return true;
    })()`);

    await navigate(BASE + '/dashboard', 3500);
    await shot('ux2-01-mobile-dashboard');

    // FAB sheet aberto
    await evalJs(`document.getElementById('bn-fab').click(); true`);
    await sleep(500);
    await shot('ux2-02-mobile-fab-sheet');
    await evalJs(`FL.closeSheet(); true`);
    await sleep(300);

    // Mais sheet
    await evalJs(`document.getElementById('bn-more').click(); true`);
    await sleep(500);
    await shot('ux2-03-mobile-mais-sheet');
    await evalJs(`FL.closeSheet(); true`);
    await sleep(300);

    // dark
    await evalJs(`FL.theme.apply('dark'); true`);
    await sleep(400);
    await shot('ux2-04-mobile-dark');
    await evalJs(`FL.theme.apply('light'); true`);

    // transações
    await navigate(BASE + '/transacoes', 2500);
    await shot('ux2-05-mobile-transacoes');

    // mercados
    await navigate(BASE + '/mercados', 3500);
    await shot('ux2-06-mobile-mercados');

    // login (visitante)
    await evalJs(`(async () => { await fetch('${BASE}/api/auth.php?action=logout', { method: 'POST', credentials: 'same-origin' }); return true; })()`);
    await navigate(BASE + '/login', 2000);
    await shot('ux2-07-mobile-login');

    // re-login para desktop
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
        return true;
    })()`);

    // ============ 320px ============
    await send('Emulation.setDeviceMetricsOverride', { width: 320, height: 700, deviceScaleFactor: 2, mobile: true });
    await navigate(BASE + '/dashboard', 3000);
    await shot('ux2-08-320-dashboard');

    // ============ TABLET 768 ============
    await send('Emulation.setDeviceMetricsOverride', { width: 768, height: 1024, deviceScaleFactor: 1, mobile: true });
    await navigate(BASE + '/dashboard', 3000);
    await shot('ux2-09-768-dashboard');

    // ============ DESKTOP 1440 ============
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await navigate(BASE + '/dashboard', 3200);
    await shot('ux2-10-desktop-dashboard');

    await navigate(BASE + '/mercados', 3500);
    await shot('ux2-11-desktop-mercados');

    await evalJs(`FL.theme.apply('dark'); true`);
    await sleep(400);
    await shot('ux2-12-desktop-dark');
    await evalJs(`FL.theme.apply('light'); true`);

    ws.close();
    console.log('CONCLUIDO — ' + OUT);
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
