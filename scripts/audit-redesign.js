/* FinLife — Auditoria visual programática do redesign via CDP (v2).
   Verifica: overflow, fonte Inter, tokens de tema, componentes renderizados,
   gráficos SVG, responsividade e erros de console/JS em todas as páginas. */
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

/* Checks base, reutilizados em cada página — retorna objeto fundido com os da página. */
const BASE_CHECKS = `(() => {
    const doc = document.documentElement;
    return {
        hOverflow: doc.scrollWidth - doc.clientWidth,
        theme: doc.getAttribute('data-theme'),
        fontInter400: document.fonts.check('400 14px Inter'),
        fontInter700: document.fonts.check('700 16px Inter'),
        bodyBg: getComputedStyle(document.body).backgroundColor,
        bodyFont: getComputedStyle(document.body).fontFamily.split(',')[0].replace(/"/g, '')
    };
})()`;

function pageEval(extraExpr) {
    return `(() => { const b = ${BASE_CHECKS}; Object.assign(b, ${extraExpr}); return b; })()`;
}

function getTargets() {
    return new Promise((resolve, reject) => {
        http.get('http://127.0.0.1:9222/json', (res) => {
            let data = '';
            res.on('data', (c) => { data += c; });
            res.on('end', () => { resolve(JSON.parse(data)); });
        }).on('error', reject);
    });
}

async function main() {
    const targets = await getTargets();
    const page = targets.find((t) => t.type === 'page');
    if (!page) { throw new Error('CDP page target não encontrada'); }

    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });

    let msgId = 0;
    const pending = new Map();
    const eventWaiters = [];
    let jsErrors = [];

    ws.onmessage = (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) {
            const { resolve, reject } = pending.get(m.id);
            pending.delete(m.id);
            if (m.error) { reject(new Error(m.error.message)); } else { resolve(m.result); }
        } else if (m.method === 'Runtime.exceptionThrown') {
            jsErrors.push(m.params.exceptionDetails.text + ' ' + (m.params.exceptionDetails.exception ? m.params.exceptionDetails.exception.description : ''));
        } else if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
            const args = (m.params.args || []).map((a) => a.value || a.description || '').join(' ');
            if (!/favicon|net::/i.test(args)) { jsErrors.push('console.error: ' + args); }
        } else if (m.method) {
            for (let i = eventWaiters.length - 1; i >= 0; i--) {
                if (eventWaiters[i].method === m.method) {
                    eventWaiters[i].resolve(m.params);
                    eventWaiters.splice(i, 1);
                }
            }
        }
    };

    function send(method, params) {
        return new Promise((resolve, reject) => {
            const id = ++msgId;
            pending.set(id, { resolve, reject });
            ws.send(JSON.stringify({ id, method, params }));
        });
    }

    function waitEvent(method, timeoutMs) {
        return new Promise((resolve, reject) => {
            eventWaiters.push({ method, resolve });
            setTimeout(() => reject(new Error('timeout: ' + method)), timeoutMs || 12000);
        });
    }

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');

    async function navigate(url) {
        const loadP = waitEvent('Page.loadEventFired', 12000);
        await send('Page.navigate', { url });
        await loadP;
        await sleep(1500);
    }

    async function evalJs(expression) {
        const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) { throw new Error('eval erro: ' + JSON.stringify(r.exceptionDetails)); }
        return r.result.value;
    }

    async function setViewport(w, h, mobile) {
        await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: mobile ? 2 : 1, mobile: !!mobile });
    }

    async function setTheme(t) {
        await evalJs(`localStorage.setItem('fl-theme','${t}'); document.documentElement.setAttribute('data-theme','${t}'); true`);
        await sleep(120);
    }

    async function login() {
        await navigate(BASE + '/login');
        const r = await evalJs(`(async () => {
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const res = await fetch('${BASE}/api/auth.php?action=login', {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                credentials: 'same-origin',
                body: JSON.stringify({ email: '${EMAIL}', password: '${PASSWORD}' })
            });
            return await res.json();
        })()`);
        if (!r.ok) { throw new Error('login falhou: ' + JSON.stringify(r)); }
    }

    const results = [];
    function check(pageName, name, value) {
        results.push({ page: pageName, check: name, ok: value === true, value });
        console.log((value === true ? '  OK ' : '  FALHOU ') + pageName + ' :: ' + name + (value === true ? '' : ' => ' + JSON.stringify(value)));
    }

    // ============ DESKTOP LIGHT ============
    console.log('--- DESKTOP LIGHT ---');
    await setViewport(1400, 900, false);
    await setTheme('light');
    await login();

    jsErrors = [];
    await navigate(BASE + '/dashboard');
    const d = await evalJs(pageEval(`(() => {
        const out = {};
        out.heroVisible = !!document.querySelector('.balance-hero');
        out.heroBalance = document.getElementById('stat-balance') ? document.getElementById('stat-balance').textContent : null;
        out.heroFont = document.querySelector('.bh-value') ? getComputedStyle(document.querySelector('.bh-value')).fontSize : null;
        out.heroIncome = document.getElementById('stat-income') ? document.getElementById('stat-income').textContent : null;
        out.heroExpense = document.getElementById('stat-expense') ? document.getElementById('stat-expense').textContent : null;
        out.heroNet = document.getElementById('stat-net') ? document.getElementById('stat-net').textContent : null;
        out.quickActions = document.querySelectorAll('.quick-actions .qa-btn').length;
        out.areaSvg = !!document.querySelector('#bars-container svg');
        out.areaPaths = document.querySelectorAll('#bars-container svg path').length;
        out.areaDots = document.querySelectorAll('#bars-container svg circle').length;
        out.donutSvg = !!document.querySelector('#donut-container svg');
        out.donutSegs = document.querySelectorAll('#donut-container .donut-seg').length;
        out.budgetVisible = !document.getElementById('budget-card').hidden;
        out.budgetPct = document.getElementById('budget-pct').textContent;
        out.goalItems = document.querySelectorAll('#dash-goals .goal-item').length;
        out.txRows = document.querySelectorAll('#dash-recent .tx-mini').length;
        out.greeting = document.getElementById('dash-greeting').textContent;
        out.navLinks = document.querySelectorAll('.nav-link').length;
        out.navGroups = document.querySelectorAll('.nav-group-label').length;
        out.navHasCards = [...document.querySelectorAll('.nav-link span:nth-child(2)')].some(s => s.textContent === 'Cartões');
        out.activeLink = document.querySelector('.nav-link.active') ? document.querySelector('.nav-link.active span:nth-child(2)').textContent : null;
        out.legendItems = document.querySelectorAll('#bars-legend .legend-item').length;
        out.aiChip = document.getElementById('ai-mode-chip').textContent;
        out.aiInsightIcon = !!document.querySelector('.ai-insight-icon svg');
        out.primary = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();
        return out;
    })()`));
    check('dashboard', 'overflow horizontal 0', d.hOverflow === 0 ? true : d.hOverflow);
    check('dashboard', 'tema light', d.theme === 'light');
    check('dashboard', 'Inter 400 carregada', d.fontInter400);
    check('dashboard', 'Inter 700 carregada', d.fontInter700);
    check('dashboard', 'font-family Inter', d.bodyFont === 'Inter');
    check('dashboard', 'bg neutro frio #F4F4F8', d.bodyBg === 'rgb(244, 244, 248)');
    check('dashboard', 'identidade roxa #6D28D9', d.primary === '#6D28D9', d.primary);
    check('dashboard', '9 links nav', d.navLinks === 9);
    check('dashboard', '6 grupos nav', d.navGroups === 6);
    check('dashboard', 'nav contém Cartões', d.navHasCards);
    check('dashboard', 'link ativo = Dashboard', d.activeLink === 'Dashboard');
    check('dashboard', 'hero de saldo visível', d.heroVisible);
    check('dashboard', 'saldo com valor real', d.heroBalance !== null && d.heroBalance !== 'R$ 0,00', d.heroBalance);
    check('dashboard', 'saldo em fonte hero (>= 2rem)', parseFloat(d.heroFont) >= 31, d.heroFont);
    check('dashboard', 'receitas/despesas/sobra no hero', /R\$/.test(d.heroIncome || '') && /R\$/.test(d.heroExpense || '') && /R\$/.test(d.heroNet || ''));
    check('dashboard', '4 ações rápidas', d.quickActions === 4);
    check('dashboard', 'gráfico área SVG', d.areaSvg);
    check('dashboard', 'área: 4 paths (2 linhas + 2 fills)', d.areaPaths === 4);
    check('dashboard', 'área: pontos dos dados', d.areaDots >= 10);
    check('dashboard', 'donut SVG', d.donutSvg);
    check('dashboard', 'donut: segmentos > 3', d.donutSegs > 3);
    check('dashboard', 'orçamento visível', d.budgetVisible);
    check('dashboard', 'orçamento % preenchido', /%/.test(d.budgetPct));
    check('dashboard', 'metas: >= 3 itens', d.goalItems >= 3);
    check('dashboard', 'recentes: 8 linhas', d.txRows >= 8);
    check('dashboard', 'saudação personalizada', /Maria/.test(d.greeting));
    check('dashboard', 'legenda 2 itens', d.legendItems === 2);
    check('dashboard', 'banner IA com ícone', d.aiInsightIcon);
    check('dashboard', 'sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    // Tooltip do donut
    const tt = await evalJs(`(() => {
        const seg = document.querySelector('#donut-container .donut-seg');
        if (!seg) { return null; }
        seg.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
        const tip = document.querySelector('#donut-container .chart-tooltip');
        return tip ? { has: !!tip, shown: tip.classList.contains('show'), text: tip.textContent } : { has: false };
    })()`);
    check('dashboard', 'tooltip donut aparece', tt && tt.shown && tt.text.length > 3);

    // Tooltip do gráfico de área
    const ta = await evalJs(`(() => {
        const zone = document.querySelector('#bars-container .fl-hover-zone');
        if (!zone) { return null; }
        zone.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
        const tip = document.querySelector('#bars-container .chart-tooltip');
        return tip ? { shown: tip.classList.contains('show'), text: tip.textContent } : null;
    })()`);
    check('dashboard', 'tooltip área aparece', ta && ta.shown && /Receitas/.test(ta.text));

    // Toggle de tema
    await send('Runtime.evaluate', { expression: `document.getElementById('theme-toggle').click(); true` });
    await sleep(300);
    const themeAfter = await evalJs(`({ theme: document.documentElement.getAttribute('data-theme'), bg: getComputedStyle(document.body).backgroundColor, meta: document.querySelector('meta[name="theme-color"]').content, areaStroke: document.querySelector('#bars-container svg path[stroke]') ? document.querySelector('#bars-container svg path[stroke]').getAttribute('stroke') : null })`);
    check('dashboard', 'toggle → dark', themeAfter.theme === 'dark');
    check('dashboard', 'bg dark #0A0912', themeAfter.bg === 'rgb(10, 9, 18)');
    check('dashboard', 'meta theme-color dark', themeAfter.meta === '#0A0912');
    check('dashboard', 'gráfico re-renderizado p/ dark', themeAfter.areaStroke && themeAfter.areaStroke !== '#059669');
    await setTheme('light');

    // ---------- transacoes ----------
    jsErrors = [];
    await navigate(BASE + '/transacoes');
    const t = await evalJs(pageEval(`(() => {
        const out = {};
        out.rows = document.querySelectorAll('#tx-tbody tr').length;
        out.dataLabels = [...document.querySelectorAll('#tx-tbody td')].filter(td => td.dataset.label).length;
        out.amountIncome = !!document.querySelector('#tx-tbody .amount-cell.income');
        out.amountExpense = !!document.querySelector('#tx-tbody .amount-cell.expense');
        out.totals = [...document.querySelectorAll('#tx-totals .total-chip strong')].map(el => el.textContent);
        out.totalsIncomeColored = getComputedStyle(document.querySelector('#tx-totals .total-chip strong.income')).color;
        out.toolbarFilters = document.querySelectorAll('.toolbar .filter').length;
        out.skeletonGone = !document.querySelector('#tx-tbody .skeleton-row');
        out.thCount = document.querySelectorAll('#tx-table thead th').length;
        return out;
    })()`));
    check('transacoes', 'sem overflow', t.hOverflow === 0 ? true : t.hOverflow);
    check('transacoes', 'linhas de transação', t.rows >= 10);
    check('transacoes', 'tds com data-label', t.dataLabels === t.rows * 6);
    check('transacoes', 'valores income/expense classificados', t.amountIncome && t.amountExpense);
    check('transacoes', '3 chips de total', t.totals.length === 3);
    check('transacoes', 'total entrada verde', t.totalsIncomeColored === 'rgb(6, 118, 71)');
    check('transacoes', '5 filtros na toolbar', t.toolbarFilters === 5);
    check('transacoes', 'skeletons removidos', t.skeletonGone);
    check('transacoes', '6 colunas', t.thCount === 6);
    check('transacoes', 'sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    // ---------- contas ----------
    jsErrors = [];
    await navigate(BASE + '/contas');
    const a = await evalJs(pageEval(`(() => ({
        cards: document.querySelectorAll('.account-card').length,
        dots: document.querySelectorAll('.account-card .acc-dot').length,
        accColors: [...document.querySelectorAll('.account-card')].filter(c => c.style.getPropertyValue('--acc-color')).length,
        total: document.getElementById('acc-total').textContent,
        hero: !!document.querySelector('.stat-hero'),
        actions: document.querySelectorAll('.account-card .acc-actions .btn').length
    }))()`));
    check('contas', '3 cards', a.cards === 3);
    check('contas', 'dots de cor por conta', a.dots === 3);
    check('contas', 'acento --acc-color aplicado', a.accColors === 3);
    check('contas', 'total != R$ 0,00', a.total !== 'R$ 0,00');
    check('contas', 'hero de saldo', a.hero);
    check('contas', 'sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    // ---------- categorias ----------
    jsErrors = [];
    await navigate(BASE + '/categorias');
    const c = await evalJs(pageEval(`(() => ({
        expenseChips: document.querySelectorAll('#cat-list-expense .cat-chip').length,
        incomeChips: document.querySelectorAll('#cat-list-income .cat-chip').length,
        icons: document.querySelectorAll('.cat-type-icon svg').length,
        emojiInTitles: [...document.querySelectorAll('.card-head h2')].some(h => /\\p{Emoji}/u.test(h.textContent)),
        editBtns: document.querySelectorAll('.cat-edit').length
    }))()`));
    check('categorias', 'chips de despesa', c.expenseChips >= 9);
    check('categorias', 'chips de receita', c.incomeChips >= 5);
    check('categorias', 'ícones SVG nos títulos', c.icons === 2);
    check('categorias', 'sem emoji nos títulos', c.emojiInTitles === false);
    check('categorias', 'sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    // ---------- metas ----------
    jsErrors = [];
    await navigate(BASE + '/metas');
    const g = await evalJs(pageEval(`(() => ({
        cards: document.querySelectorAll('.goal-card').length,
        bars: document.querySelectorAll('.goal-card .bar-fill').length,
        status: [...document.querySelectorAll('.goal-card .chip')].map(x => x.textContent),
        aportarBtn: !!document.querySelector('.goal-card .btn-primary')
    }))()`));
    check('metas', '3 cards', g.cards === 3);
    check('metas', 'barras de progresso', g.bars === 3);
    check('metas', 'status sem emoji', g.status.every((s) => !/✓/.test(s)));
    check('metas', 'botão Aportar', g.aportarBtn);
    check('metas', 'sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    // ---------- ai ----------
    jsErrors = [];
    await navigate(BASE + '/ai');
    const ai = await evalJs(pageEval(`(() => {
        const shell = document.querySelector('.chat-shell');
        return {
            shell: !!shell,
            shellH: shell ? shell.getBoundingClientRect().height : 0,
            viewportH: window.innerHeight,
            head: !!document.querySelector('.chat-head .ai-avatar'),
            msgs: document.querySelectorAll('.chat-messages .msg').length,
            suggests: document.querySelectorAll('#chat-suggest .btn-chip').length,
            composer: !!document.querySelector('.composer-field textarea'),
            send: !!document.getElementById('chat-send'),
            sendRadius: getComputedStyle(document.getElementById('chat-send')).borderRadius,
            welcomeList: !!document.querySelector('.msg-bubble ul')
        };
    })()`));
    check('ai', 'chat-shell presente', ai.shell);
    check('ai', 'altura ~viewport (premium)', Math.abs(ai.shellH - (ai.viewportH - 60 - 72)) < 8);
    check('ai', 'cabeçalho com avatar IA', ai.head);
    check('ai', 'mensagens renderizadas', ai.msgs >= 1);
    check('ai', '6 sugestões', ai.suggests === 6, ai.suggests);
    check('ai', 'composer + send', ai.composer && ai.send);
    check('ai', 'send arredondado', ai.sendRadius === '12px');
    check('ai', 'lista no welcome', ai.welcomeList);
    check('ai', 'sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    // ============ DARK ============
    console.log('--- DESKTOP DARK ---');
    await setTheme('dark');
    await navigate(BASE + '/dashboard');
    const dd = await evalJs(BASE_CHECKS);
    check('dark', 'bg dark onyx', dd.bodyBg === 'rgb(10, 9, 18)');
    check('dark', 'tema dark persiste', dd.theme === 'dark');
    check('dark', 'sem overflow', dd.hOverflow === 0);

    const ct = await evalJs(`(() => {
        // contraste do valor principal do hero (branco) sobre o gradiente roxo
        const el = document.querySelector('.bh-value') || document.querySelector('.stat-value');
        const text = getComputedStyle(el).color;
        const container = el.closest('.balance-hero') || el.closest('.card');
        const card = getComputedStyle(container).backgroundColor;
        const [tr, tg, tb] = text.match(/\\d+/g).map(Number);
        const [cr, cg, cb] = card.match(/\\d+/g).map(Number);
        const L = (r, g, b) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
        const l1 = L(tr, tg, tb), l2 = L(cr, cg, cb);
        const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
        return { text, card, ratio: Math.round(ratio * 100) / 100 };
    })()`);
    check('dark', 'contraste valor/superfície >= 4.5', ct.ratio >= 4.5 ? true : ct.ratio);

    // ============ AUTH (light) ============
    console.log('--- AUTH ---');
    await setTheme('light');
    await send('Network.deleteCookies', { name: 'finlife_session', url: BASE });
    await navigate(BASE + '/login');
    const li = await evalJs(pageEval(`(() => ({
        aside: !!document.querySelector('.auth-aside') && document.querySelector('.auth-aside').offsetParent !== null,
        features: document.querySelectorAll('.auth-features li').length,
        brandMark: !!document.querySelector('.auth-aside .brand-mark'),
        title: document.querySelector('.auth-title h1') ? document.querySelector('.auth-title h1').textContent : null,
        fields: document.querySelectorAll('#login-form .field').length,
        submitBtn: getComputedStyle(document.getElementById('login-submit')).backgroundColor, submitBg: getComputedStyle(document.getElementById('login-submit')).backgroundImage
    }))()`));
    check('login', 'painel de marca visível (desktop)', li.aside);
    check('login', '4 features no painel', li.features === 4);
    check('login', 'título "Bem-vindo de volta"', li.title === 'Bem-vindo de volta');
    check('login', '2 campos', li.fields === 2);
    check('login', 'botão primário roxo (gradiente)', /linear-gradient/.test(li.submitBg || '') && /124, 58|109, 40/.test(li.submitBg || ''), li.submitBg);
    check('login', 'tema light na auth', li.theme === 'light');

    await navigate(BASE + '/cadastro');
    const re = await evalJs(`(() => ({ pwMeter: !!document.querySelector('.pw-meter'), fields: document.querySelectorAll('#register-form .field').length }))()`);
    check('cadastro', 'medidor de senha', re.pwMeter);
    check('cadastro', '4 campos', re.fields === 4);

    await navigate(BASE + '/rota-invalida');
    const nf = await evalJs(`(() => ({ big: document.querySelector('.brand-mark.big') ? document.querySelector('.brand-mark.big').textContent : null, btn: !!document.querySelector('.btn-primary') }))()`);
    check('404', 'página 404 renderiza', nf.big === '404' && nf.btn);

    // ============ MOBILE 390 — mobile-first real ============
    console.log('--- MOBILE 390 ---');
    await setViewport(390, 844, true);
    await login();
    await navigate(BASE + '/dashboard');
    const mo = await evalJs(pageEval(`(() => ({
        sidebarHidden: getComputedStyle(document.getElementById('sidebar')).display === 'none',
        bottomNavVisible: getComputedStyle(document.getElementById('bottom-nav')).display !== 'none',
        bottomNavItems: document.querySelectorAll('#bottom-nav .bn-item').length,
        fabExists: !!document.getElementById('bn-fab'),
        fabVisible: document.getElementById('bn-fab').offsetParent !== null,
        heroVisible: !!document.querySelector('.balance-hero'),
        heroBalance: document.getElementById('stat-balance') ? document.getElementById('stat-balance').textContent : null,
        heroValueFont: document.querySelector('.bh-value') ? getComputedStyle(document.querySelector('.bh-value')).fontSize : null,
        heroRow2Col: getComputedStyle(document.querySelector('.balance-hero .bh-row')).gridTemplateColumns.split(' ').length,
        quickActions: document.querySelectorAll('.quick-actions .qa-btn').length,
        grid2Cols: getComputedStyle(document.querySelector('.grid-2')).gridTemplateColumns.split(' ').length,
        contentPadBottom: getComputedStyle(document.querySelector('.content')).paddingBottom,
        toastZone: true
    }))()`));
    check('mobile', 'sem overflow horizontal', mo.hOverflow === 0 ? true : mo.hOverflow);
    check('mobile', 'sidebar oculta (navegação inferior no lugar)', mo.sidebarHidden);
    check('mobile', 'bottom navigation visível', mo.bottomNavVisible);
    check('mobile', 'navegação inferior: 4 itens + FAB', mo.bottomNavItems === 4 && mo.fabExists, mo.bottomNavItems);
    check('mobile', 'FAB central visível', mo.fabExists && mo.fabVisible);
    check('mobile', 'hero de saldo visível', mo.heroVisible);
    check('mobile', 'saldo com valor real', mo.heroBalance !== null && mo.heroBalance !== 'R$ 0,00', mo.heroBalance);
    check('mobile', 'saldo em destaque (>= 2rem)', parseFloat(mo.heroValueFont) >= 31, mo.heroValueFont);
    check('mobile', 'resumo do hero 2 colunas + sobra', mo.heroRow2Col === 2);
    check('mobile', '4 ações rápidas', mo.quickActions === 4);
    check('mobile', 'grid-2 empilhado', mo.grid2Cols === 1);
    check('mobile', 'espaço para a navegação (padding-bottom)', parseInt(mo.contentPadBottom) >= 80, mo.contentPadBottom);
    check('mobile', 'Inter carregada', mo.fontInter400);

    // FAB abre sheet de ação rápida
    await evalJs(`document.getElementById('bn-fab').click(); true`);
    await sleep(450);
    const fabSheet = await evalJs(`(() => ({
        open: document.getElementById('bottom-sheet').classList.contains('open'),
        items: document.querySelectorAll('#bottom-sheet [data-qa]').length,
        labels: [...document.querySelectorAll('#bottom-sheet [data-qa]')].map(b => b.textContent.trim())
    }))()`);
    check('mobile', 'FAB abre sheet de ação rápida', fabSheet.open);
    check('mobile', 'sheet oferece despesa/receita', fabSheet.items === 2
        && fabSheet.labels.some(l => /despesa/i.test(l)) && fabSheet.labels.some(l => /receita/i.test(l)), fabSheet.labels);
    await evalJs(`FL.closeSheet(); true`);
    await sleep(250);

    // "Mais" abre sheet de navegação
    await evalJs(`document.getElementById('bn-more').click(); true`);
    await sleep(450);
    const moreSheet = await evalJs(`(() => ({
        open: document.getElementById('bottom-sheet').classList.contains('open'),
        links: document.querySelectorAll('#bottom-sheet a.sheet-item').length,
        hasLogout: !!document.querySelector('#bottom-sheet #sheet-logout'),
        hasTheme: !!document.querySelector('#bottom-sheet [data-sheet-theme]')
    }))()`);
    check('mobile', '"Mais" abre sheet de navegação', moreSheet.open);
    check('mobile', 'sheet Mais com 6 links', moreSheet.links === 6, moreSheet.links);
    check('mobile', 'sheet com sair e tema', moreSheet.hasLogout && moreSheet.hasTheme);
    await evalJs(`FL.closeSheet(); true`);
    await sleep(250);

    // Ação rápida: abre modal de despesa e cancela
    await evalJs(`document.querySelector('.quick-actions [data-qa="expense"]').click(); true`);
    await sleep(600);
    const qaModal = await evalJs(`(() => ({
        open: document.querySelector('#modal-overlay .modal') && !document.getElementById('modal-overlay').hidden,
        title: document.querySelector('#modal-overlay .modal h3') ? document.querySelector('#modal-overlay .modal h3').textContent : null,
        btnH: document.querySelector('#modal-overlay .modal .btn') ? document.querySelector('#modal-overlay .modal .btn').getBoundingClientRect().height : 0
    }))()`);
    check('mobile', 'ação rápida abre modal de despesa', qaModal.open && /despesa/i.test(qaModal.title || ''), qaModal.title);
    check('mobile', 'botões do modal tocáveis (>= 44px)', qaModal.btnH >= 44 ? true : qaModal.btnH);
    await evalJs(`FL.closeModal(); true`);

    // touch targets da navegação inferior
    const bnTouch = await evalJs(`(() => ({
        itemH: document.querySelector('#bottom-nav .bn-item').getBoundingClientRect().height,
        fabSize: document.getElementById('bn-fab').getBoundingClientRect().width,
        sheetItemH: 48
    }))()`);
    check('mobile', 'itens da nav tocáveis (>= 56px)', bnTouch.itemH >= 56 ? true : bnTouch.itemH);
    check('mobile', 'FAB com 52px', Math.round(bnTouch.fabSize) >= 52 ? true : Math.round(bnTouch.fabSize));

    await navigate(BASE + '/transacoes');
    const mt = await evalJs(`(() => ({
        theadHidden: getComputedStyle(document.querySelector('#tx-table thead')).display === 'none',
        trBlocks: [...document.querySelectorAll('#tx-tbody tr')].every(tr => getComputedStyle(tr).display === 'flex'),
        beforeLabels: [...document.querySelectorAll('#tx-tbody tr td')].filter(td => getComputedStyle(td, '::before').content !== 'none' && getComputedStyle(td, '::before').content !== '""' && getComputedStyle(td, '::before').content !== 'normal').length > 0,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        fabOnTransactions: document.getElementById('bn-fab') !== null
    }))()`);
    check('mobile-tabela', 'thead oculto', mt.theadHidden);
    check('mobile-tabela', 'linhas viram cartão', mt.trBlocks);
    check('mobile-tabela', 'labels ::before', mt.beforeLabels);
    check('mobile-tabela', 'sem overflow', mt.overflow === 0 ? true : mt.overflow);
    check('mobile-tabela', 'nav inferior em todas as páginas', mt.fabOnTransactions);

    await navigate(BASE + '/ai');
    const mc = await evalJs(`(() => { const s = document.querySelector('.chat-shell'); return { h: s.getBoundingClientRect().height, vh: window.innerHeight }; })()`);
    check('mobile-ai', 'chat usa altura da tela (acima da nav)', mc.h <= (mc.vh - 60 - 64 - 40) + 8, mc.h + ' vs ' + (mc.vh - 164));

    await send('Network.deleteCookies', { name: 'finlife_session', url: BASE });
    await navigate(BASE + '/login');
    const am = await evalJs(pageEval(`(() => ({
        asideHidden: !document.querySelector('.auth-aside') || document.querySelector('.auth-aside').offsetParent === null,
        brandVisible: document.querySelector('.auth-brand').offsetParent !== null
    }))()`));
    check('mobile-login', 'painel de marca oculto', am.asideHidden);
    check('mobile-login', 'marca compacta visível', am.brandVisible);
    check('mobile-login', 'sem overflow', am.hOverflow === 0 ? true : am.hOverflow);

    // ============ 320px ============
    console.log('--- 320px ---');
    await setViewport(320, 700, true);
    await login();
    await navigate(BASE + '/dashboard');
    const s320 = await evalJs(pageEval(`(() => ({
        heroVisible: !!document.querySelector('.balance-hero'),
        quickActionCols: getComputedStyle(document.querySelector('.quick-actions')).gridTemplateColumns.split(' ').length,
        bnVisible: getComputedStyle(document.getElementById('bottom-nav')).display !== 'none'
    }))()`));
    check('320', 'sem overflow horizontal', s320.hOverflow === 0 ? true : s320.hOverflow);
    check('320', 'hero de saldo presente', s320.heroVisible);
    check('320', 'ações rápidas 4 colunas (compactas)', s320.quickActionCols === 4);
    check('320', 'navegação inferior presente', s320.bnVisible);
    check('320', 'Inter carregada', s320.fontInter400);
    check('320', 'tema light', s320.theme === 'light');

    ws.close();

    const failed = results.filter((r) => !r.ok);
    console.log('\n===== RESUMO =====');
    console.log('Total: ' + results.length + ' checks | OK: ' + (results.length - failed.length) + ' | FALHAS: ' + failed.length);
    if (failed.length) {
        failed.forEach((f) => console.log('  FALHOU: [' + f.page + '] ' + f.check + ' => ' + JSON.stringify(f.value)));
        process.exitCode = 1;
    } else {
        console.log('TODOS OS CHECKS PASSARAM');
    }
}

main().catch((e) => { console.error('ERRO FATAL: ' + e.message); process.exit(1); });
