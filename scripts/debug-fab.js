/* Debug: o que o FAB intersecciona de fato em 390px? */
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
    ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id !== undefined && p.has(m.id)) { const e = p.get(m.id); p.delete(m.id); e.resolve(m.result); } };
    const send = (method, params) => new Promise(resolve => { const i = ++id; p.set(i, { resolve }); ws.send(JSON.stringify({ id: i, method, params })); });
    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        return r.result ? r.result.value : null;
    };
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(1500);
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
        return true;
    })()`);
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(2200);
    const dbg = await evalJs(`(() => {
        const fab = document.getElementById('bn-fab');
        if (!fab) { return { fab: false }; }
        const fr = fab.getBoundingClientRect();
        const offenders = [];
        document.querySelectorAll('#view button, #view a, #view input, #view select, #view textarea, .wl-item, .mkt-tile, .card-tile').forEach(function (e) {
            const r = e.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) { return; }
            const inter = !(r.left >= fr.right || r.right <= fr.left || r.top >= fr.bottom || r.bottom <= fr.top);
            if (inter) {
                offenders.push({
                    el: e.tagName + '.' + String(e.className).split(' ')[0],
                    text: (e.textContent || e.value || '').slice(0, 20),
                    rect: { t: Math.round(r.top), b: Math.round(r.bottom), l: Math.round(r.left), rt: Math.round(r.right) }
                });
            }
        });
        const nav = document.getElementById('bottom-nav').getBoundingClientRect();
        return {
            fab: true,
            fabRect: { t: Math.round(fr.top), b: Math.round(fr.bottom), l: Math.round(fr.left), rt: Math.round(fr.right) },
            navRect: { t: Math.round(nav.top), b: Math.round(nav.bottom) },
            vh: window.innerHeight,
            offenders: offenders.slice(0, 6),
            contentPad: getComputedStyle(document.querySelector('.content')).paddingBottom
        };
    })()`);
    console.log(JSON.stringify(dbg, null, 2));
    ws.close();
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
