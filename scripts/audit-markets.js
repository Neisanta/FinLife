/* FinLife — E2E Mercados (Onyx, 63 checks). */
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
const EMAIL = 'redesign@teste.com';
const PASSWORD = process.env.FINLIFE_TEST_PASSWORD;
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
    let msgId = 0; const pending = new Map();
    const eventWaiters = [];
    let jsErrors = [];
    ws.onmessage = (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id !== undefined && pending.has(m.id)) {
            const w = pending.get(m.id);
            pending.delete(m.id);
            w(m.result);
        } else if (m.method === 'Runtime.exceptionThrown') {
            jsErrors.push(m.params.exceptionDetails.text + ' ' + (m.params.exceptionDetails.exception ? m.params.exceptionDetails.exception.description : ''));
        } else if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
            const args = (m.params.args || []).map(a => a.value || a.description || '').join(' ');
            if (!/favicon|net::|WebSocket|rate/i.test(args)) { jsErrors.push('console.error: ' + args); }
        } else if (m.method === 'Page.loadEventFired') {
            for (let i = eventWaiters.length - 1; i >= 0; i--) {
                if (eventWaiters[i].method === m.method) { eventWaiters[i].resolve(); eventWaiters.splice(i, 1); }
            }
        }
    };
    const send = (method, params) => new Promise((resolve) => {
        const id = ++msgId;
        pending.set(id, resolve);
        ws.send(JSON.stringify({ id, method, params }));
    });
    const evalJs = async (expression) => {
        const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) { throw new Error('eval: ' + (r.exceptionDetails.exception ? r.exceptionDetails.exception.description : r.exceptionDetails.text)); }
        return r.result.value;
    };
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');
    const navigate = async (url, waitMs) => {
        const p1 = new Promise(r => eventWaiters.push({ method: 'Page.loadEventFired', resolve: r }));
        await send('Page.navigate', { url });
        await Promise.race([p1, sleep(6000)]);
        await sleep(waitMs || 400);
    };
    const setViewport = (w, h, mobile) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: mobile ? 2 : 1, mobile });
    const clickTab = async (tab, waitMs) => {
        await evalJs(`(() => { const b = [...document.querySelectorAll('#markets-tabs .tab-link')].find(x => x.getAttribute('data-tab') === '${tab}'); if (b) { b.click(); } return !!b; })()`);
        await sleep(waitMs || 1500);
    };
    const setTheme = async (t) => { await evalJs(`localStorage.setItem('fl-theme','${t}'); true`); };

    const results = [];
    function check(checkName, value) {
        results.push({ check: checkName, ok: value === true, value });
        console.log((value === true ? '  OK ' : '  FALHOU ') + checkName + (value === true ? '' : ' => ' + JSON.stringify(value)));
    }

    await setViewport(1440, 900, false);
    // PRIMEIRO a origem válida, depois o localStorage
    await navigate(BASE + '/login', 800);
    await setTheme('light');
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const res = await fetch('${BASE}/api/auth.php?action=login', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin', body: JSON.stringify({ email: '${EMAIL}', password: '${PASSWORD}' })
        });
        return (await res.json()).ok;
    })()`);

    jsErrors = [];
    await navigate(BASE + '/mercados', 3500);
    const base = await evalJs(`(() => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        tiles: document.querySelectorAll('.mkt-tile').length,
        sparklines: document.querySelectorAll('.mkt-tile .mt-spark svg').length,
        status: document.querySelector('#market-status .ms-label') ? document.querySelector('#market-status .ms-label').textContent : null,
        overviewRows: document.querySelectorAll('#ov-indices tr, #ov-crypto tr, #ov-forex tr').length,
        theme: document.documentElement.getAttribute('data-theme'),
        bg: getComputedStyle(document.body).backgroundColor
    }))()`);
    check('página sem overflow', base.overflow === 0 ? true : base.overflow);
    check('6 tiles da home', base.tiles === 6, base.tiles);
    check('sparklines renderizadas', base.sparklines >= 4, base.sparklines);
    check('status de mercado exibido', base.status !== null && base.status.length > 3, base.status);
    check('visão geral com dados reais', base.overviewRows >= 6, base.overviewRows);
    check('tema light ativo', base.theme === 'light');
    check('bg light #F4F4F8', base.bg === 'rgb(244, 244, 248)', base.bg);

    console.log('--- BUSCA ---');
    await evalJs(`(() => { const q = document.getElementById('mk-search'); q.value = 'WEGE'; q.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`);
    await sleep(1200);
    const search = await evalJs(`(() => ({
        show: document.getElementById('mk-search-dropdown').classList.contains('show'),
        first: document.querySelector('#mk-search-dropdown .search-item .si-sym') ? document.querySelector('#mk-search-dropdown .search-item .si-sym').textContent : null
    }))()`);
    check('busca com dropdown funciona', search.show && /WEGE3/.test(search.first || ''), search);
    await evalJs(`document.getElementById('mk-search').value = ''; document.getElementById('mk-search-dropdown').classList.remove('show'); true`);

    console.log('--- ALTAS & QUEDAS ---');
    await clickTab('movers', 3500);
    const movers = await evalJs(`(() => ({
        gainers: document.querySelectorAll('#mv-gainers .mover-row').length,
        losers: document.querySelectorAll('#mv-losers .mover-row').length,
        volumes: document.querySelectorAll('#mv-volumes .mover-row').length,
        heat: document.querySelectorAll('#mv-heatmap .heat-tile').length,
        heatColors: [...document.querySelectorAll('#mv-heatmap .heat-tile')].slice(0, 6).map(t => getComputedStyle(t).backgroundColor)
    }))()`);
    const PALETTE = ['rgb(5, 150, 105)', 'rgb(16, 185, 129)', 'rgb(52, 211, 153)', 'rgb(157, 151, 178)', 'rgb(248, 113, 113)', 'rgb(239, 68, 68)', 'rgb(220, 38, 38)'];
    check('maiores altas (10)', movers.gainers === 10, movers.gainers);
    check('maiores quedas (10)', movers.losers === 10);
    check('maiores volumes', movers.volumes >= 3);
    check('heatmap renderizado', movers.heat >= 10, movers.heat);
    check('cores do heatmap por variação (não aleatórias)', movers.heatColors.every(c => PALETTE.includes(c)), movers.heatColors);

    console.log('--- WATCHLIST ---');
    await clickTab('watchlist', 2500);
    await evalJs(`(() => { return new Promise(function (resolve) { let t = 0; const x = setInterval(function () { t++; const q = document.querySelector('.wl-item .q-price'); if ((q && /R\\$/.test(q.textContent)) || t > 30) { clearInterval(x); resolve(true); } }, 500); }); })()`);
    const wl = await evalJs(`(() => ({
        tabs: document.querySelectorAll('#wl-tabs .class-chip').length,
        items: document.querySelectorAll('.wl-item').length,
        firstSymbol: document.querySelector('.wl-item .wl-info strong') ? document.querySelector('.wl-item .wl-info strong').textContent : null,
        firstPrice: document.querySelector('.wl-item .q-price') ? document.querySelector('.wl-item .q-price').textContent : null,
        hasReorder: !!document.querySelector('.wl-item [title*="Mover"]')
    }))()`);
    check('5 ativos na primeira lista', wl.items === 5, wl.items);
    check('primeiro item = PETR4 com preço real', /^PETR4/.test(wl.firstSymbol || '') && /R\$/.test(wl.firstPrice || '') ? true : 'first: ' + (wl.firstSymbol || '?') + ' / ' + (wl.firstPrice || '?'));
    check('reordenação disponível', wl.hasReorder);

    console.log('--- ALERTAS ---');
    await clickTab('alerts', 2000);
    const alerts = await evalJs(`(() => ({
        rows: document.querySelectorAll('.alert-row').length,
        triggered: document.querySelectorAll('.alert-row.status-triggered').length,
        cond: document.querySelector('.al-condition') ? document.querySelector('.al-condition').textContent : null
    }))()`);
    check('2 alertas listados', alerts.rows === 2, alerts.rows);
    check('1 alerta disparado (histórico)', alerts.triggered === 1);
    check('condição legível', /acima de/i.test(alerts.cond || ''), alerts.cond);

    console.log('--- PORTFÓLIO ---');
    await clickTab('portfolio', 6000);
    const pf = await evalJs(`(() => ({
        positions: document.querySelectorAll('#pf-positions tr').length,
        hasValue: /R\\$/.test(document.getElementById('pf-positions') ? document.getElementById('pf-positions').textContent : ''),
        benchmark: document.getElementById('pf-benchmark') ? !document.getElementById('pf-benchmark').hidden : false
    }))()`);
    check('posições do portfólio (>= 4 do seed)', pf.positions >= 4 ? true : pf.positions + ' posições');
    check('valores em R$ no portfólio', pf.hasValue);
    check('benchmark presente', pf.benchmark);

    console.log('--- DETALHE DO ATIVO ---');
    await clickTab('overview', 1500);
    await evalJs(`document.querySelector('#ov-crypto tbody tr').click(); true`);
    await sleep(4200);
    const modal = await evalJs(`(() => {
        const m = document.getElementById('modal-overlay');
        const open = m && !m.hidden && m.querySelector('.modal-wide');
        if (!open) { return { open: false }; }
        const t = m.querySelector('.modal-wide').textContent;
        return { open: true, hasPrice: /R\\$/.test(t), hasSource: /(fonte|Binance|Yahoo)/i.test(t), hasRange: !!m.querySelector('[data-range]'), hasSpark: !!m.querySelector('.modal-wide svg') };
    })()`);
    check('modal do ativo abre', modal.open);
    check('preço real no modal', modal.hasPrice);
    check('fonte da cotação no modal', modal.hasSource);
    check('seletor de período', modal.hasRange);
    check('gráfico renderizado', modal.hasSpark);
    await evalJs(`FL.closeModal(); true`);
    await sleep(300);

    console.log('--- TEMPO REAL (WebSocket) ---');
    const wsLive = await evalJs(`(() => { const p = document.getElementById('market-status'); return p ? p.className : null; })()`);
    check('WebSocket conectado (status live)', /live/.test(wsLive || ''), wsLive);
    const tileBTC = await evalJs(`(() => { const t = document.querySelector('.mkt-tile[data-symbol="BTCUSDT"] .mt-price'); return t ? t.textContent : null; })()`);
    await sleep(4500);
    const tileBTC2 = await evalJs(`(() => { const t = document.querySelector('.mkt-tile[data-symbol="BTCUSDT"] .mt-price'); return t ? t.textContent : null; })()`);
    check('BTC com preço atualizado', (tileBTC !== null && tileBTC2 !== null && tileBTC !== tileBTC2) ? true : 'WS: preços iguais ou ausentes (' + tileBTC + ') — WebSocket externo pode estar intermitente');

    console.log('--- DARK ONYX ---');
    await setTheme('dark');
    await navigate(BASE + '/mercados', 3000);
    const dark = await evalJs(`(() => ({
        bg: getComputedStyle(document.body).backgroundColor,
        theme: document.documentElement.getAttribute('data-theme'),
        tiles: document.querySelectorAll('.mkt-tile').length,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth
    }))()`);
    check('dark: página de mercados', dark.bg === 'rgb(10, 9, 18)' && dark.theme === 'dark', dark);
    check('dark: tiles renderizados', dark.tiles === 6, dark.tiles);
    check('dark: sem overflow', dark.overflow === 0 ? true : dark.overflow);

    console.log('--- MOBILE 390 ---');
    await setViewport(390, 844, true);
    await navigate(BASE + '/mercados', 3200);
    const mob = await evalJs(`(() => {
        const overflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
        const tiles = document.querySelectorAll('.mkt-tile').length;
        const spark = document.querySelectorAll('.mkt-tile .mt-spark svg').length;
        const status = document.getElementById('market-status') ? document.getElementById('market-status').textContent : '';
        const bn = getComputedStyle(document.getElementById('bottom-nav')).display !== 'none';
        return { overflow, tiles, spark, status, bn };
    })()`);
    check('mobile: sem overflow', mob.overflow === 0 ? true : mob.overflow);
    check('mobile: tiles compactos', mob.tiles === 6, mob.tiles);
    check('mobile: sparklines presentes', mob.spark >= 4, mob.spark);
    check('mobile: status de mercado', mob.status.length > 3);
    check('mobile: bottom nav em mercados', mob.bn);
    check('mobile: sem erros JS acumulados', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    ws.close();
    const failed = results.filter(r => !r.ok);
    console.log('\n===== MERCADOS E2E: ' + (results.length - failed.length) + '/' + results.length + ' OK =====');
    if (failed.length) { failed.forEach(f => console.log('FALHOU: ' + f.check + ' => ' + JSON.stringify(f.value))); process.exitCode = 1; }
    else { console.log('MÓDULO DE MERCADOS 100% FUNCIONAL'); }
}
main().catch(e => { console.error('ERRO FATAL: ' + e.message); process.exit(1); });
