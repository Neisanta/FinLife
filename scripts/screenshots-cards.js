/* FinLife — Screenshots do módulo de cartões (desktop light/dark, tabs, mobile). */
const fs = require('fs');
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}

const BASE = 'http://127.0.0.1:8090';
const OUT = process.env.TEMP + '\\fl_shots_cards';

if (!fs.existsSync(OUT)) { fs.mkdirSync(OUT, { recursive: true }); }

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
    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
    let msgId = 0;
    const pending = new Map();
    const eventWaiters = [];
    ws.onmessage = (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) {
            const { resolve, reject } = pending.get(m.id);
            pending.delete(m.id);
            m.error ? reject(new Error(m.error.message)) : resolve(m.result);
        } else if (m.method === 'Page.loadEventFired') {
            for (let i = eventWaiters.length - 1; i >= 0; i--) {
                if (eventWaiters[i].method === m.method) { eventWaiters[i].resolve(); eventWaiters.splice(i, 1); }
            }
        }
    };
    const send = (method, params) => new Promise((resolve, reject) => {
        const id = ++msgId;
        pending.set(id, { resolve, reject });
        ws.send(JSON.stringify({ id, method, params }));
    });
    const evalJs = async (expression) => {
        const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) { throw new Error('eval: ' + JSON.stringify(r.exceptionDetails.exception)); }
        return r.result.value;
    };
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const waitEvent = (method, timeoutMs) => new Promise((resolve, reject) => {
        eventWaiters.push({ method, resolve });
        setTimeout(() => reject(new Error('timeout ' + method)), timeoutMs || 10000);
    });
    await send('Page.enable');
    await send('Runtime.enable');

    async function navigate(url, ms) {
        const loadP = waitEvent('Page.loadEventFired', 12000);
        await send('Page.navigate', { url });
        await loadP;
        await sleep(ms || 2000);
    }
    async function shot(name) {
        const res = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(OUT + '\\' + name + '.png', Buffer.from(res.data, 'base64'));
        console.log('shot OK: ' + name);
    }
    async function setViewport(w, h, mobile) {
        await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: mobile ? 2 : 1, mobile: !!mobile });
    }
    async function setTheme(t) {
        await evalJs(`localStorage.setItem('fl-theme','${t}'); document.documentElement.setAttribute('data-theme','${t}'); true`);
        await sleep(120);
    }
    async function clickTab(name) {
        await evalJs(`document.querySelector('.tab-link[data-tab="${name}"]').click(); true`);
        await sleep(1300);
    }

    await setViewport(1440, 900, false);
    await setTheme('light');
    await navigate(BASE + '/login');
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD })
        });
        return true;
    })()`);

    await navigate(BASE + '/cartoes', 2800);
    await shot('cards-01-overview-light');

    await clickTab('invoices');
    await shot('cards-02-invoices');

    await clickTab('installments');
    await shot('cards-03-installments');

    await clickTab('subscriptions');
    await shot('cards-04-subscriptions');

    await clickTab('benefits');
    await shot('cards-05-benefits');

    await clickTab('reports');
    await sleep(800);
    await shot('cards-06-reports');

    await clickTab('calendar');
    await sleep(800);
    await shot('cards-07-calendar');

    // modal de nova compra (desktop)
    await clickTab('purchases');
    await evalJs(`document.getElementById('purchase-new').click(); true`);
    await sleep(500);
    await evalJs(`(() => {
        const m = document.querySelector('#modal-overlay .modal');
        m.querySelector('#pf-amount').value = '2400';
        m.querySelector('#pf-installments').value = '10';
        m.querySelector('#pf-amount').dispatchEvent(new Event('input'));
        m.querySelector('#pf-installments').dispatchEvent(new Event('input'));
        return true;
    })()`);
    await sleep(300);
    await shot('cards-08-purchase-modal');
    await evalJs(`FL.closeModal(); true`);

    // dark
    await setTheme('dark');
    await navigate(BASE + '/cartoes', 2600);
    await shot('cards-09-overview-dark');

    // mobile
    await setTheme('light');
    await setViewport(390, 844, true);
    await navigate(BASE + '/cartoes', 2600);
    await shot('cards-10-mobile');

    await clickTab('calendar');
    await shot('cards-11-mobile-calendar');

    ws.close();
    console.log('CONCLUIDO — ' + OUT);
}

main().catch((e) => { console.error('ERRO: ' + e.message); process.exit(1); });
