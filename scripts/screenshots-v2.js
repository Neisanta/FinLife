/* FinLife — Screenshots do redesign (CDP Edge headless, sem dependências). */
const fs = require('fs');
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
const OUT = process.env.TEMP + '\\fl_shots_v2';

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
    if (!page) { throw new Error('Nenhuma page target do CDP encontrada'); }

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
            if (m.error) { reject(new Error(m.error.message)); } else { resolve(m.result); }
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
            setTimeout(() => reject(new Error('timeout esperando ' + method)), timeoutMs || 10000);
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
        await sleep(1400); // JS carrega dados via fetch
    }

    async function shot(name) {
        const res = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(OUT + '\\' + name + '.png', Buffer.from(res.data, 'base64'));
        console.log('shot OK: ' + name);
    }

    async function setViewport(width, height, mobile) {
        await send('Emulation.setDeviceMetricsOverride', {
            width, height, deviceScaleFactor: mobile ? 2 : 1, mobile: !!mobile
        });
    }

    async function setTheme(theme) {
        await send('Runtime.evaluate', {
            expression: `localStorage.setItem('fl-theme', '${theme}'); document.documentElement.setAttribute('data-theme','${theme}'); true`
        });
        await sleep(150);
    }

    async function loginInBrowser() {
        await navigate(BASE + '/login');
        const evalP = await send('Runtime.evaluate', {
            expression: `(async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                const res = await fetch('${BASE}/api/auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    credentials: 'same-origin',
                    body: JSON.stringify({ email: '${EMAIL}', password: '${PASSWORD}' })
                });
                return await res.json();
            })()`,
            awaitPromise: true,
            returnByValue: true
        });
        if (!evalP.result || !evalP.result.value || !evalP.result.value.ok) {
            throw new Error('Login falhou: ' + JSON.stringify(evalP.result && evalP.result.value));
        }
        console.log('login OK');
    }

    async function logoutInBrowser() {
        await send('Runtime.evaluate', {
            expression: `(async () => {
                document.cookie = 'finlife_session=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
                localStorage.removeItem('fl-theme');
                true
            })()`,
            awaitPromise: true, returnByValue: true
        });
        await send('Network.deleteCookies', { name: 'finlife_session', url: BASE });
    }

    // ============ DESKTOP LIGHT ============
    await setViewport(1400, 900, false);
    await setTheme('light');
    await loginInBrowser();

    await navigate(BASE + '/dashboard');
    await sleep(600);
    await shot('01-dashboard-light');

    await navigate(BASE + '/transacoes');
    await shot('02-transacoes-light');

    await navigate(BASE + '/contas');
    await shot('03-contas-light');

    await navigate(BASE + '/categorias');
    await shot('04-categorias-light');

    await navigate(BASE + '/metas');
    await shot('05-metas-light');

    await navigate(BASE + '/ai');
    await sleep(600);
    await shot('06-ai-light');

    // ============ DESKTOP DARK ============
    await setTheme('dark');
    await navigate(BASE + '/dashboard');
    await sleep(600);
    await shot('07-dashboard-dark');

    await navigate(BASE + '/transacoes');
    await shot('08-transacoes-dark');

    await navigate(BASE + '/ai');
    await sleep(600);
    await shot('09-ai-dark');

    // ============ AUTH DESKTOP ============
    await logoutInBrowser();
    await navigate(BASE + '/login');
    await sleep(400);
    await shot('10-login-light');

    await setTheme('dark');
    await navigate(BASE + '/login');
    await shot('11-login-dark');

    await setTheme('light');
    await navigate(BASE + '/cadastro');
    await shot('12-cadastro-light');

    await navigate(BASE + '/rota-que-nao-existe');
    await shot('13-404-light');

    // ============ MOBILE (390) ============
    await loginInBrowser();

    await setViewport(390, 844, true);
    await setTheme('light');
    await navigate(BASE + '/dashboard');
    await sleep(600);
    await shot('14-dashboard-mobile-light');

    await navigate(BASE + '/transacoes');
    await shot('15-transacoes-mobile');

    await navigate(BASE + '/ai');
    await sleep(400);
    await shot('16-ai-mobile');

    await logoutInBrowser();
    await navigate(BASE + '/login');
    await shot('17-login-mobile');

    // ============ 320px ============
    await setViewport(320, 700, true);
    await loginInBrowser();
    await navigate(BASE + '/dashboard');
    await sleep(600);
    await shot('18-dashboard-320');

    // menu mobile aberto
    await send('Runtime.evaluate', { expression: `document.getElementById('menu-btn').click(); true` });
    await sleep(400);
    await shot('19-menu-mobile-320');

    ws.close();
    console.log('CONCLUIDO — pasta: ' + OUT);
}

main().catch((e) => { console.error('ERRO: ' + e.message); process.exit(1); });
