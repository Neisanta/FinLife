/* Revisão de designer (métricas objetivas): proporções, hierarquia, densidade, identidade. */
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
    ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id && p.has(m.id)) { p.get(m.id)(m.result); p.delete(m.id); } };
    const send = (method, params) => new Promise(r => { const i = ++id; p.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) { return 'ERR'; }
        return r.result.value;
    };
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');

    console.log('=== REVISAO DE DESIGNER (390px) ===');
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(1500);
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD }) });
        return true;
    })()`);
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(3500);

    const d = await evalJs(`(() => {
        const vw = 390;
        const hero = document.querySelector('.balance-hero');
        const hr = hero.getBoundingClientRect();
        const bh = hero.querySelector('.bh-value');
        const bhf = getComputedStyle(bh);
        const bn = document.getElementById('bottom-nav').getBoundingClientRect();
        const fab = document.getElementById('bn-fab').getBoundingClientRect();
        const qa = document.querySelector('.quick-actions');
        const cards = document.querySelectorAll('#view .card').length;
        const sections = document.querySelectorAll('#view > *').length;
        const firstGap = qa.getBoundingClientRect().top - hr.bottom;
        const title = document.querySelector('.page-title');
        return {
            heroH_vs_vw: Math.round(hr.height / vw * 100),               // % da tela que o hero ocupa
            heroW_vs_vw: Math.round(hr.width / vw * 100),
            saldoFontPx: parseFloat(bhf.fontSize),
            saldoVsBodyRatio: (parseFloat(bhf.fontSize) / 14).toFixed(1), // quantas x o corpo
            saldoWeight: bhf.fontWeight,
            heroRadius: getComputedStyle(hero).borderRadius,
            bnH: Math.round(bn.height),
            bnBottom: Math.round(bn.bottom),
            fabSize: Math.round(fab.width),
            fabOverflows: Math.round(fab.top) < Math.round(bn.top),
            qaCount: qa.querySelectorAll('.qa-btn').length,
            qaH: Math.round(qa.getBoundingClientRect().height),
            visibleCards: cards,
            sections: sections,
            gapHeroQa: Math.round(firstGap),
            titleFont: getComputedStyle(title).fontSize,
            topbarH: document.querySelector('.topbar').getBoundingClientRect().height,
            contentBottomPad: getComputedStyle(document.querySelector('.content')).paddingBottom
        };
    })()`);
    console.log(JSON.stringify(d, null, 2));

    // densidade de texto do dashboard (espaço negativo)
    const dens = await evalJs(`(() => {
        const view = document.getElementById('view');
        const total = view.innerText.length;
        const h = view.getBoundingClientRect().height;
        return { chars: total, height: Math.round(h), charsPer100px: Math.round(total / (h / 100)) };
    })()`);
    console.log('densidade: ' + JSON.stringify(dens));

    // identidade: roxo em elementos-chave
    const ident = await evalJs(`(() => {
        const heroBg = getComputedStyle(document.querySelector('.balance-hero')).backgroundImage.slice(0, 60);
        const fabBg = getComputedStyle(document.getElementById('bn-fab')).backgroundImage.slice(0, 60);
        const activeNav = document.querySelector('#bottom-nav .bn-item.active');
        const activeColor = activeNav ? getComputedStyle(activeNav).color : null;
        const activeBg = activeNav ? getComputedStyle(activeNav).backgroundColor : null;
        return { heroGrad: heroBg, fabGrad: fabBg, navActive: activeColor, navActiveBg: activeBg };
    })()`);
    console.log('identidade: ' + JSON.stringify(ident));

    // dark mode mobile
    await evalJs(`FL.theme.apply('dark'); true`);
    await sleep(400);
    const dk = await evalJs(`(() => {
        const hero = getComputedStyle(document.querySelector('.balance-hero')).backgroundImage.slice(0, 60);
        const bn = getComputedStyle(document.getElementById('bottom-nav'));
        return { heroGrad: hero, bnBg: bn.backgroundColor, bnShadow: bn.boxShadow.slice(0, 40) };
    })()`);
    console.log('dark: ' + JSON.stringify(dk));
    await evalJs(`FL.theme.apply('light'); true`);

    ws.close();
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
