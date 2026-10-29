/* FinLife — Screenshots via CDP (Edge headless), sem dependências. */
const fs = require('fs');
const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}


const SESSION_COOKIE = fs.readFileSync(process.env.TEMP + '\\fl_session.txt', 'utf8').trim();
const BASE = 'http://127.0.0.1:8090';
const EMAIL = 'maria@teste.com';
const PASSWORD = process.env.FINLIFE_TEST_PASSWORD;
const OUT = process.env.TEMP + '\\fl_shots';

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
            setTimeout(() => reject(new Error('timeout esperando ' + method)), timeoutMs || 8000);
        });
    }

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');

    async function navigate(url) {
        const loadP = waitEvent('Page.loadEventFired', 10000);
        await send('Page.navigate', { url });
        await loadP;
        await sleep(1500); // aguarda o JS da página carregar os dados via fetch
    }

    async function shot(name) {
        const res = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(OUT + '\\' + name + '.png', Buffer.from(res.data, 'base64'));
        console.log('shot OK: ' + name);
    }

    /**
     * Login REAL dentro do navegador (via fetch do contexto da página):
     * o fingerprint de User-Agent da sessão passa a ser o do Edge,
     * igual ao de qualquer usuário de verdade.
     */
    async function loginInBrowser(email, password) {
        await navigate(BASE + '/login');
        const evalP = send('Runtime.evaluate', {
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
        // Runtime.evaluate precisa de Runtime.enable? Não é obrigatório.
        const result = await evalP;
        if (!result.result || !result.result.value || !result.result.value.ok) {
            throw new Error('Login no navegador falhou: ' + JSON.stringify(result.result && result.result.value));
        }
        console.log('login no navegador OK');
    }

    // ---------- 1) Desktop: dashboard autenticado ----------
    await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });
    await loginInBrowser('maria@teste.com', process.env.FINLIFE_TEST_PASSWORD);
    await navigate(BASE + '/dashboard');
    await shot('dashboard-desktop');

    // ---------- 2) Desktop: transações ----------
    await navigate(BASE + '/transacoes');
    await shot('transacoes-desktop');

    // ---------- 3) Desktop: metas ----------
    await navigate(BASE + '/metas');
    await shot('metas-desktop');

    // ---------- 4) Desktop: FinLife AI ----------
    await navigate(BASE + '/ai');
    await shot('ai-desktop');

    // ---------- 5) Mobile: dashboard ----------
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await navigate(BASE + '/dashboard');
    await shot('dashboard-mobile');

    // ---------- 6) Mobile: login (sem cookie) ----------
    await send('Network.deleteCookies', { name: 'finlife_session', url: BASE });
    await navigate(BASE + '/login');
    await shot('login-mobile');

    // ---------- 7) Desktop: login ----------
    await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });
    await navigate(BASE + '/cadastro');
    await shot('cadastro-desktop');

    ws.close();
    console.log('CONCLUIDO');
}

main().catch((e) => { console.error('ERRO: ' + e.message); process.exit(1); });
