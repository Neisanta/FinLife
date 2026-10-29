/* Verificação de ciclo de vida: nenhum timer/WS duplicado no mercado. */
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
        if (r.exceptionDetails) { throw new Error(r.exceptionDetails.exception.description); }
        return r.result.value;
    };
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');

    // instrumenta ANTES de qualquer script da página
    await send('Page.addScriptToEvaluateOnNewDocument', { source: `
        (function () {
            window.__flStats = { intervals: 0, timeouts: 0, sockets: 0, liveIntervals: 0, liveSockets: 0 };
            const _si = window.setInterval;
            window.setInterval = function (fn, ms) {
                window.__flStats.intervals++;
                const id = _si.apply(this, arguments);
                const origClear = window.clearInterval;
                return id;
            };
            const _ws = window.WebSocket;
            window.WebSocket = function () {
                window.__flStats.sockets++;
                const s = new _ws(...arguments);
                window.__flStats.liveSockets++;
                s.addEventListener('close', function () { window.__flStats.liveSockets--; });
                return s;
            };
            window.WebSocket.prototype = _ws.prototype;
            Object.defineProperty(window.WebSocket, 'name', { value: 'WebSocket' });
        })();
    ` });

    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(2000);
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
        return true;
    })()`);
    await send('Page.navigate', { url: BASE + '/mercados' });
    await sleep(6000);

    const s1 = await evalJs(`JSON.parse(JSON.stringify(window.__flStats))`);
    // navega para outra página e volta: nenhum acúmulo (página recarrega do zero)
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(2500);
    const s2 = await evalJs(`JSON.parse(JSON.stringify(window.__flStats))`);

    console.log('mercados (60s): ' + JSON.stringify(s1));
    console.log('dashboard (fresh load): ' + JSON.stringify(s2));
    console.log('analise: ' + JSON.stringify({
        mercadoUmSoWS: s1.sockets === 1,
        intervalosEsperados: s1.intervals,
        semAcumuloEntrePaginas: s2.sockets <= 1
    }));
    ws.close();
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
