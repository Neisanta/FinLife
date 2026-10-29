/* FinLife — Screenshots do módulo de Mercados. */
const fs = require('fs');
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
const OUT = process.env.TEMP + '\\fl_shots_markets';
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
    async function clickTab(name, ms) {
        await evalJs(`document.querySelector('.tab-link[data-tab="${name}"]').click(); true`);
        await sleep(ms || 2500);
    }

    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await evalJs(`localStorage.setItem('fl-theme','light'); true`);
    await navigate(BASE + '/login');
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
        return true;
    })()`);

    await navigate(BASE + '/mercados', 4500);
    await shot('mkt-01-home');

    await clickTab('explore', 4000);
    await shot('mkt-02-explorar');

    await clickTab('movers', 4000);
    await shot('mkt-03-altas-quedas-heatmap');

    await clickTab('watchlist', 3000);
    await shot('mkt-04-watchlist');

    await clickTab('portfolio', 4000);
    await shot('mkt-05-carteira');

    // modal com gráfico
    await clickTab('overview', 1500);
    await evalJs(`document.querySelector('#ov-crypto tbody tr[data-symbol="BTCUSDT"]').click(); true`);
    await sleep(6000);
    await shot('mkt-06-ativo-grafico');
    await evalJs(`FL.closeModal(); true`);

    // dark
    await evalJs(`FL.theme.apply('dark'); true`);
    await sleep(500);
    await evalJs(`document.querySelector('.tab-link[data-tab="overview"]').click(); true`);
    await sleep(1500);
    await shot('mkt-07-dark');

    // mobile
    await evalJs(`FL.theme.apply('light'); true`);
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await navigate(BASE + '/mercados', 4000);
    await shot('mkt-08-mobile');

    ws.close();
    console.log('CONCLUIDO — ' + OUT);
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
