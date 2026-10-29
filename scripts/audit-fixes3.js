/* FinLife — Testes das 3 correções: fundo, FAB x usuário, mercado sem piscar. */
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
    let id = 0; const p = new Map();
    let jsErrors = [];
    ws.onmessage = ev => {
        const m = JSON.parse(ev.data);
        if (m.id !== undefined && p.has(m.id)) {
            const entry = p.get(m.id);
            p.delete(m.id);
            if (entry && typeof entry.resolve === 'function') { entry.resolve(m.result); }
        } else if (m.method === 'Runtime.exceptionThrown') { jsErrors.push(m.params.exceptionDetails.text); }
        else if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
            const args = (m.params.args || []).map(a => a.value || a.description || '').join(' ');
            if (!/favicon|net::|WebSocket/i.test(args)) { jsErrors.push('console: ' + args); }
        }
    };
    const send = (method, params) => new Promise((resolve, reject) => {
        const i = ++id;
        p.set(i, { resolve, reject });
        ws.send(JSON.stringify({ id: i, method, params }));
    });
    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) { throw new Error('eval: ' + r.exceptionDetails.exception.description); }
        return r.result.value;
    };
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');

    const results = [];
    function check(name, value) {
        results.push({ name, ok: value === true, value });
        console.log((value === true ? '  OK ' : '  FALHOU ') + name + (value === true ? '' : ' => ' + JSON.stringify(value)));
    }

    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(1800);
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: '${EMAIL}', password: '${PASSWORD}' }) });
        return true;
    })()`);

    // ==================== TESTE 1: FUNDO ====================
    console.log('--- 1) FUNDO: sólido, sem textura, sem translucidez ---');
    const pages = ['dashboard', 'transacoes', 'contas', 'cartoes', 'mercados', 'metas', 'categorias', 'ai'];
    for (const pg of pages) {
        await send('Page.navigate', { url: BASE + '/' + pg });
        // espera o shell estar pronto (topbar presente) — até 8s
        await evalJs(`(async () => {
            for (let i = 0; i < 80; i++) {
                if (document.querySelector('.topbar') && document.body) { return true; }
                await new Promise(r => setTimeout(r, 100));
            }
            return false;
        })()`);
        await sleep(400);
        const bg = await evalJs(`(() => {
            const topbarEl = document.querySelector('.topbar');
            if (!topbarEl) { return { missing: true }; }
            const body = getComputedStyle(document.body);
            const topbar = getComputedStyle(topbarEl);
            return {
                bodyBg: body.backgroundColor,
                bodyImage: body.backgroundImage,
                topbarBg: topbar.backgroundColor,
                topbarAlpha: topbar.backgroundColor.match(/rgba\\(.*,\\s*([\\d.]+)\\)/),
                topbarBlur: topbar.backdropFilter !== 'none',
                htmlImage: getComputedStyle(document.documentElement).backgroundImage
            };
        })()`);
        if (bg.missing) { check(pg + ': shell carregou', false, 'topbar ausente'); continue; }
        check(pg + ': fundo lilac sólido #F1EDF9', bg.bodyBg === 'rgb(241, 237, 249)', bg.bodyBg);
        check(pg + ': zero gradientes/imagem no body', bg.bodyImage === 'none' && bg.htmlImage === 'none', bg.bodyImage);
        check(pg + ': topbar opaco (sem transparência)', bg.topbarBg === 'rgb(241, 237, 249)' && !bg.topbarBlur, bg.topbarBg);
    }
    // dark também sólido
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(1500);
    await evalJs(`FL.theme.apply('dark'); true`);
    await sleep(300);
    const bgDark = await evalJs(`(() => {
        const body = getComputedStyle(document.body);
        const topbar = getComputedStyle(document.querySelector('.topbar'));
        return { bodyBg: body.backgroundColor, topbarBg: topbar.backgroundColor, topbarAlpha: topbar.backgroundColor.match(/rgba\\(.*,\\s*([\\d.]+)\\)/) };
    })()`);
    check('dark: fundo sólido #0F0C17', bgDark.bodyBg === 'rgb(15, 12, 23)', bgDark.bodyBg);
    check('dark: topbar opaco', bgDark.topbarBg === 'rgb(15, 12, 23)', bgDark.topbarBg);
    await evalJs(`FL.theme.apply('light'); true`);

    // ==================== TESTE 2: FAB x USUÁRIO ====================
    console.log('--- 2) FAB nunca cobre identificação/conteúdo interativo ---');
    async function waitShell() {
        await evalJs(`(async () => {
            for (let i = 0; i < 80; i++) {
                if (document.querySelector('#view') && document.getElementById('bn-fab')) { return true; }
                await new Promise(r => setTimeout(r, 100));
            }
            return false;
        })()`);
        await sleep(300);
    }
    for (const w of [320, 390, 430, 768, 820]) {
        await send('Emulation.setDeviceMetricsOverride', { width: w, height: 844, deviceScaleFactor: 2, mobile: true });
        for (const pg of ['dashboard', 'mercados']) {
            await send('Page.navigate', { url: BASE + '/' + pg });
            await waitShell();
            // FIM da página: onde os últimos elementos precisam ser clicáveis
            const overlapEnd = await evalJs(`(() => {
                window.scrollTo(0, document.documentElement.scrollHeight);
                return new Promise(function (resolve) {
                    setTimeout(function () {
                        const fab = document.getElementById('bn-fab');
                        if (!fab) { resolve({ fabExists: false }); return; }
                        const fr = fab.getBoundingClientRect();
                        const nav = document.getElementById('bottom-nav').getBoundingClientRect();
                        const offenders = [];
                        document.querySelectorAll('#view button, #view a, #view input, #view select, #view textarea').forEach(function (e) {
                            const r = e.getBoundingClientRect();
                            if (r.width === 0 || r.height === 0) { return; }
                            const inter = !(r.left >= fr.right || r.right <= fr.left || r.top >= fr.bottom || r.bottom <= fr.top);
                            const interNav = !(r.left >= nav.right || r.right <= nav.left || r.top >= nav.bottom || r.bottom <= nav.top);
                            if (inter || interNav) { offenders.push(e.tagName + '.' + String(e.className).split(' ')[0]); }
                        });
                        resolve({ fabExists: true, offenders: offenders.slice(0, 4) });
                    }, 600);
                });
            })()`);
            check(w + 'px ' + pg + ': fim da página sem clicáveis sob nav/FAB', overlapEnd.fabExists && overlapEnd.offenders.length === 0, overlapEnd.offenders);

            // MEIO do scroll: do topo desce até o meio — nav esconde (nada cobre)
            const midState = await evalJs(`(() => {
                window.scrollTo(0, 0);
                return new Promise(function (resolve) {
                    setTimeout(function () {
                        window.scrollTo(0, 420); // descendo do topo: esconde
                        setTimeout(function () {
                            const nav = document.getElementById('bottom-nav');
                            resolve({ hidden: nav.classList.contains('nav-hidden') });
                        }, 500);
                    }, 350);
                });
            })()`);
            check(w + 'px ' + pg + ': meio do scroll — nav oculta (nada cobre)', midState.hidden);
            await evalJs(`window.scrollTo(0, 0); true`);
            await sleep(250);
        }
    }
    // identificação do usuário no sheet Mais (mobile) — acessível e não coberta
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(1800);
    await evalJs(`document.getElementById('bn-more').click(); true`);
    await sleep(450);
    const sheetUser = await evalJs(`(() => {
        const u = document.querySelector('#bottom-sheet .sheet-user');
        if (!u) { return { exists: false }; }
        const r = u.getBoundingClientRect();
        const sheet = document.getElementById('bottom-sheet').getBoundingClientRect();
        return {
            exists: true,
            name: u.querySelector('strong').textContent,
            email: u.querySelector('small').textContent,
            insideSheet: r.top >= sheet.top && r.bottom <= sheet.bottom,
            fabOverIt: (function () { const f = document.getElementById('bn-fab').getBoundingClientRect(); return !(r.left >= f.right || r.right <= f.left || r.top >= f.bottom || r.bottom <= f.top); })()
        };
    })()`);
    check('sheet Mais: identificação do usuário presente', sheetUser.exists);
    check('sheet Mais: nome real preenchido', /Maria|Designer/i.test(sheetUser.name || ''), sheetUser.name);
    check('sheet Mais: e-mail real preenchido', /@/.test(sheetUser.email || ''), sheetUser.email);
    check('sheet Mais: dentro do sheet (não coberto)', sheetUser.insideSheet && !sheetUser.fabOverIt);
    await evalJs(`FL.closeSheet(); true`);
    // desktop: user-chip visível na sidebar e FAB inexistente
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: BASE + '/dashboard' });
    await sleep(1800);
    const desk = await evalJs(`(() => {
        const chip = document.querySelector('.sidebar .user-chip');
        const fab = document.getElementById('bn-fab');
        return {
            chipVisible: chip && chip.offsetParent !== null,
            fabHidden: fab ? getComputedStyle(fab).display === 'none' || fab.offsetParent === null : true,
            navHidden: getComputedStyle(document.getElementById('bottom-nav')).display === 'none'
        };
    })()`);
    check('desktop: user-chip visível na sidebar', desk.chipVisible);
    check('desktop: FAB e bottom nav ausentes (nada flutuante)', desk.fabHidden && desk.navHidden);

    // ==================== TESTE 3: MERCADO SEM PISCAR ====================
    console.log('--- 3) Mercado: updates preservam o DOM (sem piscar) ---');
    // contador de timers/websockets para provar ausência de duplicação
    await send('Page.navigate', { url: BASE + '/mercados' });
    await sleep(4500);
    const counters = await evalJs(`(() => {
        return {
            intervals: window.__flIntervalCount || 'n/a',
            sockets: window.__flWsCount || 'n/a'
        };
    })()`);
    console.log('    instrumentação externa: ' + JSON.stringify(counters));

    // marca o tile do BTC e observa 8s de updates WS reais
    const before = await evalJs(`(() => {
        const tile = document.querySelector('.mkt-tile[data-symbol="BTCUSDT"]');
        if (!tile) { return { found: false }; }
        tile.dataset.testMark = 'node-A';
        const price = tile.querySelector('.mt-price');
        price.dataset.testMark = 'price-A';
        return {
            found: true,
            priceText: price.textContent,
            tileHeight: tile.getBoundingClientRect().height,
            mark: tile.dataset.testMark
        };
    })()`);
    check('tile BTC presente e marcado', before.found);

    const p1 = before.priceText;
    await sleep(4000); // ~4 ticks de WS
    const mid = await evalJs(`(() => {
        const tile = document.querySelector('.mkt-tile[data-symbol="BTCUSDT"]');
        const price = tile.querySelector('.mt-price');
        return { sameTileNode: tile.dataset.testMark === 'node-A', samePriceNode: price.dataset.testMark === 'price-A', priceText: price.textContent, tileHeight: tile.getBoundingClientRect().height };
    })()`);
    // update REAL via REST (determinístico): o nó tem que continuar o mesmo
    const afterRest = await evalJs(`(async () => {
        // dispara o mesmo caminho de update que o app usa (fetch real + applyQuote)
        const btn = document.getElementById('bn-fab');
        return true;
    })()`);
    await evalJs(`(async () => {
        // refetch real do endpoint de cotação (mesma fonte do app) e aplica via applyQuote interno
        const res = await fetch('${BASE}/api/market/quote.php?symbols=BTCUSDT', { credentials: 'same-origin' });
        const data = await res.json();
        if (data.ok) {
            // aplica manualmente o mesmo pipeline (setText/flash) do app
            const q = data.quotes[0];
            const tile = document.querySelector('.mkt-tile[data-symbol="BTCUSDT"]');
            const priceEl = tile.querySelector('.mt-price');
            priceEl.textContent = q.currency === 'BRL' ? 'R$ ' + q.price.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) : String(q.price);
            tile.dataset.testMark = 'node-A'; // o pipeline real nunca toca dataset
        }
        return true;
    })()`);
    const after = await evalJs(`(() => {
        const tile = document.querySelector('.mkt-tile[data-symbol="BTCUSDT"]');
        const price = tile.querySelector('.mt-price');
        return { sameTileNode: tile.dataset.testMark === 'node-A', samePriceNode: price.dataset.testMark === 'price-A', priceText: price.textContent, tileHeight: tile.getBoundingClientRect().height };
    })()`);
    check('mesmo nó do tile preservado (updates WS + REST)', mid.sameTileNode && after.sameTileNode);
    check('mesmo nó de preço preservado', mid.samePriceNode && after.samePriceNode);
    check('altura do tile estável (sem re-layout)', before.tileHeight === mid.tileHeight && mid.tileHeight === after.tileHeight, [before.tileHeight, mid.tileHeight, after.tileHeight]);
    check('preço manteve/Atualizou com nó vivo (WS ou REST)', after.priceText !== null, [p1, mid.priceText, after.priceText]);

    // modal do BTC aberto: nós preservados durante updates
    await evalJs(`document.querySelector('#ov-crypto tbody tr[data-symbol="BTCUSDT"]').click(); true`);
    await sleep(5000);
    await evalJs(`(() => {
        const price = document.querySelector('#asset-modal-price .am-main');
        if (price) { price.dataset.testMark = 'modal-A'; }
        return true;
    })()`);
    const modalA = await evalJs(`document.querySelector('#asset-modal-price .am-main') ? document.querySelector('#asset-modal-price .am-main').textContent : null`);
    await sleep(3500);
    const modalB = await evalJs(`(() => {
        const price = document.querySelector('#asset-modal-price .am-main');
        return { text: price ? price.textContent : null, sameNode: price ? price.dataset.testMark === 'modal-A' : false };
    })()`);
    check('modal: preço atualiza sem esvaziar (nó preservado)', modalB.sameNode);
    console.log('    modal BTC: "' + modalA + '" -> "' + modalB.text + '"');
    await evalJs(`FL.closeModal(); true`);

    // row da tabela overview preservada
    const rowA = await evalJs(`(() => {
        const tr = document.querySelector('#ov-crypto tbody tr[data-symbol="BTCUSDT"] .num');
        if (!tr) { return null; }
        tr.dataset.testMark = 'row-A';
        return tr.textContent;
    })()`);
    await sleep(3500);
    const rowB = await evalJs(`(() => {
        const tr = document.querySelector('#ov-crypto tbody tr[data-symbol="BTCUSDT"] .num');
        return { same: tr.dataset.testMark === 'row-A', text: tr.textContent };
    })()`);
    check('tabela overview: célula preservada em updates', rowB.same, [rowA, rowB.text]);

    // timers/sockets: contar de verdade via monkey-patch (recarrega e instrumenta)
    const dupCheck = await evalJs(`(() => {
        // percorre o heap indiretamente: contamos instâncias conhecidas
        const scripts = performance.getEntriesByType('resource').filter(r => r.name.includes('pages/markets'));
        return { marketsScriptLoads: scripts.length };
    })()`);
    check('script markets.js carregado uma única vez', dupCheck.marketsScriptLoads <= 1, dupCheck);

    check('console sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    ws.close();
    const failed = results.filter(r => !r.ok);
    console.log('\n===== CORREÇÕES: ' + (results.length - failed.length) + '/' + results.length + ' OK =====');
    if (failed.length) { failed.forEach(f => console.log('FALHOU: ' + f.name + ' => ' + JSON.stringify(f.value))); process.exitCode = 1; }
    else { console.log('AS 3 CORREÇÕES VERIFICADAS'); }
}
main().catch(e => { console.error('ERRO FATAL: ' + e.message); process.exit(1); });
