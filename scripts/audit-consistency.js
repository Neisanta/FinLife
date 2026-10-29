/* Consistência de componentes: botões primários, page-titles e cards idênticos entre páginas. */
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
const PAGES = ['dashboard', 'transacoes', 'contas', 'cartoes', 'mercados', 'categorias', 'metas', 'compromissos'];
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
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(1500);
    await evalJs(`(async () => { const c = document.querySelector('meta[name="csrf-token"]').content; await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': c }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) }); return true; })()`);
    for (const theme of ['light', 'dark']) {
        await evalJs(`FL.theme.apply('${theme}'); true`);
        for (const pg of PAGES) {
            await send('Page.navigate', { url: BASE + '/' + pg });
            await sleep(1400);
            const m = await evalJs(`(() => {
                const btn = document.querySelector('main .btn-primary');
                const title = document.querySelector('.page-title');
                const card = document.querySelector('main .card');
                return {
                    btn: btn ? { h: Math.round(btn.getBoundingClientRect().height), radius: getComputedStyle(btn).borderRadius, bg: getComputedStyle(btn).backgroundColor, minH: getComputedStyle(btn).minHeight } : null,
                    title: title ? { size: getComputedStyle(title).fontSize, weight: getComputedStyle(title).fontWeight, family: getComputedStyle(title).fontFamily.split(',')[0] } : null,
                    card: card ? { radius: getComputedStyle(card).borderRadius, bg: getComputedStyle(card).backgroundColor } : null,
                    focusVisible: (() => { const s = [...document.styleSheets]; try { for (const sh of s) { for (const rule of sh.cssRules) { if (rule.selectorText && rule.selectorText.includes(':focus-visible')) { return rule.style.outlineColor || rule.cssText.slice(0, 40); } } } } catch (e) {} return null; })()
                };
            })()`);
            console.log(theme + ' ' + pg + ': ' + JSON.stringify(m));
        }
    }
    ws.close();
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
