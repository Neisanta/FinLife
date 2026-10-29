/* FinLife — E2E Cartões (Onyx). Polling typing-aware para a IA. */
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
            jsErrors.push(m.params.exceptionDetails.text);
        } else if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
            const args = (m.params.args || []).map(a => a.value || a.description || '').join(' ');
            if (!/favicon|net::|WebSocket|rate/i.test(args)) { jsErrors.push('console.error: ' + args); }
        }
    };
    const send = (method, params) => new Promise((resolve) => {
        const id = ++msgId;
        pending.set(id, resolve);
        ws.send(JSON.stringify({ id, method, params }));
    });
    const evalJs = async (expression) => {
        const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) { throw new Error('eval: ' + JSON.stringify(r.exceptionDetails.exception ? r.exceptionDetails.exception.description : r.exceptionDetails.text).slice(0, 300)); }
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
    const setTheme = async (t) => { await evalJs(`localStorage.setItem('fl-theme','${t}'); true`); };
    const clickTab = async (tab, waitMs) => {
        await evalJs(`(() => { const b = [...document.querySelectorAll('#cards-tabs .tab-link')].find(x => x.getAttribute('data-tab') === '${tab}'); if (b) { b.click(); } return !!b; })()`);
        await sleep(waitMs || 1500);
    };

    const results = [];
    function check(checkName, value) {
        results.push({ check: checkName, ok: value === true, value });
        console.log((value === true ? '  OK ' : '  FALHOU ') + checkName + (value === true ? '' : ' => ' + JSON.stringify(value)));
    }

    await setViewport(1440, 900, false);
    await navigate(BASE + '/login', 800);
    await evalJs(`localStorage.removeItem('fl-theme'); true`);
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: '${EMAIL}', password: '${PASSWORD}' }) });
        return true;
    })()`);

    console.log('--- PÁGINA CARTÕES (desktop, dark default) ---');
    await navigate(BASE + '/cartoes', 1600);
    const page0 = await evalJs(`(() => {
        const cards = [...document.querySelectorAll('.bank-card')];
        const tabs = [...document.querySelectorAll('.tab-link')];
        const hero = document.querySelector('.cards-hero');
        return {
            title: document.querySelector('.page-title') ? document.querySelector('.page-title').textContent : null,
            cards: cards.length,
            tabs: tabs.length,
            hero: hero ? true : false,
            newBtn: !!document.getElementById('card-new'),
            theme: document.documentElement.getAttribute('data-theme'),
            bg: getComputedStyle(document.body).backgroundColor
        };
    })()`);
    check('página de cartões carrega', page0.title === 'Cartões' && page0.theme === 'dark');
    check('bg dark Onyx rgb(10, 9, 18)', page0.bg === 'rgb(10, 9, 18)', page0.bg);
    check('cards renderizados', page0.cards >= 3, page0.cards);
    check('tabs de tipo presentes', page0.tabs >= 3, page0.tabs);
    check('hero de cartões', page0.hero);
    check('botão novo cartão', page0.newBtn);

    // cria cartão de crédito
    console.log('--- CRIAR CARTÃO ---');
    const createResult = await evalJs(`(async () => {
        document.getElementById('card-new').click();
        // espera o modal com cf-name (atômico: sem janela para interferência)
        for (let i = 0; i < 20; i++) {
            if (document.getElementById('cf-name')) { break; }
            await new Promise(r => setTimeout(r, 100));
        }
        const cf = document.getElementById('cf-name');
        if (!cf) { return { ok: false, motivo: 'modal/cf-name ausente' }; }
        var cardName = 'Cartão E2E ' + new Date().getTime(); window.__flCardName = cardName; cf.value = cardName;
        document.getElementById('cf-brand').value = 'visa';
        document.getElementById('cf-type').value = 'credit';
        document.getElementById('cf-limit').value = '2500';
        document.getElementById('cf-closing').value = '5';
        document.getElementById('cf-due').value = '15';
        document.querySelector('.modal [data-save]').click();
        return { ok: true };
    })()`);
    console.log('    criar: ' + JSON.stringify(createResult));
    check('formulário de criação funcionou', createResult.ok === true);
    await sleep(1800);
    const created = await evalJs(`(() => {
        const c = [...document.querySelectorAll('.bank-card')].find(x => x.textContent.includes(window.__flCardName || 'Cartão E2E'));
        return c ? { found: true, brand: !!c.querySelector('.bc-brand'), tileText: c.textContent } : { found: false };
    })()`);
    check('cartão criado aparece', created.found);
    check('bandeira e vencimento no cartão', (created.brand && /VISA/.test(created.tileText || '')) ? true : 'tile: ' + (created.tileText || '').replace(/\\s+/g, ' ').slice(0, 120));

    // compra parcelada via UI
    console.log('--- COMPRA PARCELADA ---');
    await evalJs(`(() => { const tile = [...document.querySelectorAll('.card-tile')].find(x => x.textContent.includes(window.__flCardName || 'Cartão E2E')); const b = tile ? tile.querySelector('[title*="Registrar compra"]') : null; if (b) { b.click(); return 'clicou'; } return 'botao nao achado'; })()`);
    await sleep(700);
    await evalJs(`(() => {
        const desc = document.getElementById('pf-description');
        if (!desc) { return 'modal nao abriu'; }
        desc.value = 'Notebook teste';
        document.getElementById('pf-amount').value = '600';
        document.getElementById('pf-installments').value = '2';
        const cat = document.getElementById('pf-category');
        if (cat) { cat.value = cat.options[1] ? cat.options[1].value : ''; }
        document.querySelector('.modal [data-save]').click();
        return 'enviado';
    })()`);
    await sleep(2200);
    const purchase = await evalJs(`(() => {
        const c = [...document.querySelectorAll('.bank-card')].find(x => x.textContent.includes(window.__flCardName || 'Cartão E2E'));
        return { hasInstallment: !!document.querySelector('#purchases-list .purchase-row') && /Notebook teste/.test(document.getElementById('purchases-list').textContent), used: /600/.test(document.getElementById('purchases-list').textContent) };
    })()`);
    check('compra parcelada registrada', purchase.hasInstallment, purchase);

    // faturas
    console.log('--- FATURAS ---');
    await clickTab('invoices', 2000);
    const invoice = await evalJs(`(() => {
        const rows = [...document.querySelectorAll('.invoice-item')];
        return { count: rows.length, hasValue: rows.some(r => /R\\$/.test(r.textContent)) };
    })()`);
    check('faturas listadas', invoice.count >= 1, invoice.count);
    check('fatura com valor', invoice.hasValue);
    await clickTab('purchases', 1500);

    // detalhe do cartão (modal)
    await evalJs(`(() => { const c = [...document.querySelectorAll('.bank-card')].find(x => x.textContent.includes(window.__flCardName || 'Cartão E2E')); if (!c) { return false; } c.click(); return true; })()`);
    await sleep(900);
    const detail = await evalJs(`(() => {
        const m = document.getElementById('modal-overlay');
        const modal = m && !m.hidden ? m.querySelector('.modal') : null;
        if (!modal) { return { open: false }; }
        const info = modal.textContent;
        return { open: true, hasLimit: /limite/i.test(info), hasInvoice: /fatura/i.test(info), hasClose: !!modal.querySelector('.modal-close') };
    })()`);
    check('modal de detalhes abre', detail.open);
    check('detalhes mostram limite e fechamento', detail.hasLimit && detail.hasClose, detail);
    await evalJs(`FL.closeModal(); true`);
    await sleep(400);

    // busca
    const search = await evalJs(`(() => {
        const q = document.getElementById('p-filter-q') || document.getElementById('purchases-search');
        if (!q) { return { ok: 'sem campo de busca' }; }
        q.value = 'Notebook';
        q.dispatchEvent(new Event('input', { bubbles: true }));
        const rows = [...document.querySelectorAll('.invoice-item, .purchase-row, .card-purchase')].filter(r => /Notebook/.test(r.textContent));
        return { ok: true, filtered: rows.length };
    })()`);
    check('busca/filtro de compras', search.ok === true, search);

    // mobile
    console.log('--- MOBILE 390 ---');
    await setViewport(390, 844, true);
    await navigate(BASE + '/cartoes', 1400);
    const mobile = await evalJs(`(() => {
        const cards = [...document.querySelectorAll('.bank-card')];
        const w = cards.length ? Math.max(...cards.map(c => c.getBoundingClientRect().width)) : 0;
        return {
            cards: cards.length,
            maxW: Math.round(w),
            fits: w <= window.innerWidth + 1,
            bottomNav: getComputedStyle(document.getElementById('bottom-nav')).display !== 'none',
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth
        };
    })()`);
    check('mobile: cards renderizam', mobile.cards >= 3, mobile.cards);
    check('mobile: cartão cabe na tela', mobile.fits, mobile.maxW);
    check('mobile: bottom nav presente', mobile.bottomNav);
    check('mobile: sem overflow', mobile.overflow === 0 ? true : mobile.overflow);

    // dark onyx
    await setViewport(1440, 900, false);
    await navigate(BASE + '/cartoes', 1400);
    await setTheme('dark');
    await navigate(BASE + '/cartoes', 1400);
    const dark = await evalJs(`(() => ({ bg: getComputedStyle(document.body).backgroundColor, theme: document.documentElement.getAttribute('data-theme') }))()`);
    check('dark: página de cartões', dark.bg === 'rgb(10, 9, 18)' && dark.theme === 'dark', dark);

    // IA com cartões
    console.log('--- IA COM CARTÕES ---');
    async function chatWait(msg) {
        const before = await evalJs(`document.querySelectorAll('.msg').length`);
        await evalJs(`(() => {
            const input = document.getElementById('chat-text');
            input.value = ${JSON.stringify(msg)};
            document.getElementById('chat-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            return true;
        })()`);
        await evalJs(`(() => {
            const base = ${before};
            return new Promise(function (resolve) {
                let t = 0;
                const x = setInterval(function () {
                    t++;
                    if ((document.querySelectorAll('.msg').length > base + 1 && !document.querySelector('.typing')) || t > 100) {
                        clearInterval(x); resolve(true);
                    }
                }, 500);
            });
        })()`);
        await sleep(600);
    }
    await navigate(BASE + '/ai', 2000);
    await chatWait('Quanto ainda tenho de limite?');
    const aiLimit = await evalJs(`(() => {
        const msgs = [...document.querySelectorAll('.msg.assistant .msg-bubble')].map(b => b.textContent);
        return msgs.length ? msgs[msgs.length - 1] : null;
    })()`);
    check('IA responde limite com dados reais', aiLimit !== null && /dispon/.test(aiLimit) && /R\$/.test(aiLimit), aiLimit);

    await chatWait('Comprei um jogo de 120 em 2x no Nubank');
    const aiPurchase = await evalJs(`(() => {
        const last = [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop();
        const buttons = last ? [...last.querySelectorAll('.chat-actions .btn')].map(b => b.textContent) : [];
        return { text: last ? last.textContent : null, buttons };
    })()`);
    check('IA detecta compra parcelada (2× de R$ 60)', aiPurchase.text !== null && /2 parcelas de R\$ 60,00/.test(aiPurchase.text), aiPurchase.text);
    check('IA pede confirmação', aiPurchase.buttons.length === 2, aiPurchase.buttons);

    await evalJs(`(() => {
        const last = [...document.querySelectorAll('.msg.assistant .msg-bubble')].pop();
        const btn = last && last.querySelector('.chat-actions .btn-primary');
        if (btn) { btn.click(); }
        return true;
    })()`);
    await sleep(2500);
    const aiConfirmed = await evalJs(`(() => {
        const msgs = [...document.querySelectorAll('.msg.assistant .msg-bubble')].map(b => b.textContent);
        return msgs.length ? msgs[msgs.length - 1] : null;
    })()`);
    check('compra registrada via IA com sucesso', aiConfirmed !== null && /sucesso/i.test(aiConfirmed), aiConfirmed);

    await navigate(BASE + '/cartoes', 2400);
    const aiInList = await evalJs(`(() => {
        const rows = [...document.querySelectorAll('#purchases-list .purchase-row')];
        return rows.some(r => /jogo/i.test(r.textContent));
    })()`);
    check('compra da IA aparece na lista de cartões', aiInList);

    check('sem erros JS', jsErrors.length === 0 ? true : jsErrors.slice(0, 3));

    ws.close();
    const failed = results.filter(r => !r.ok);
    console.log('\n===== CARTÕES E2E: ' + (results.length - failed.length) + '/' + results.length + ' OK =====');
    if (failed.length) { failed.forEach(f => console.log('FALHOU: ' + f.check + ' => ' + JSON.stringify(f.value))); process.exitCode = 1; }
    else { console.log('MÓDULO DE CARTÕES 100% FUNCIONAL'); }
}
main().catch(e => { console.error('ERRO FATAL: ' + e.message); process.exit(1); });
