/* FinLife — Teste do fluxo REAL do usuário (formulários de auth) via CDP. */
const BASE = process.env.FL_BASE || 'http://127.0.0.1:8090';
const CDP_PORT = parseInt(process.env.FL_CDP_PORT || '9223', 10);
const EMAIL = 'fluxo@teste.com';
const PASSWORD = process.env.FINLIFE_TEST_PASSWORD;

const http = require('http');

// Credencial do usuário de teste: vem do ambiente, nunca fica no código.
// Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
if (!process.env.FINLIFE_TEST_PASSWORD) {
  console.error('Defina FINLIFE_TEST_PASSWORD antes de rodar este script.');
  process.exit(1);
}


function getTargets() {
    return new Promise((resolve, reject) => {
        http.get('http://127.0.0.1:' + CDP_PORT + '/json', (res) => {
            let data = '';
            res.on('data', (c) => { data += c; });
            res.on('end', () => { resolve(JSON.parse(data)); });
        }).on('error', reject);
    });
}

async function main() {
    const targets = await getTargets();
    const page = targets.find((t) => t.type === 'page');
    if (!page) { throw new Error('Nenhuma page target'); }

    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });

    let msgId = 0;
    const pending = new Map();
    ws.onmessage = (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) {
            const { resolve, reject } = pending.get(m.id);
            pending.delete(m.id);
            if (m.error) { reject(new Error(m.error.message)); } else { resolve(m.result); }
        }
    };
    function send(method, params) {
        return new Promise((resolve, reject) => {
            const id = ++msgId;
            pending.set(id, { resolve, reject });
            ws.send(JSON.stringify({ id, method, params }));
        });
    }
    await send('Page.enable');
    await send('Runtime.enable');

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    async function navigate(url) {
        const navDone = new Promise((resolve) => {
            const h = (ev) => {
                const m = JSON.parse(ev.data);
                if (m.method === 'Page.loadEventFired') { ws.removeEventListener('message', h); resolve(); }
            };
            ws.addEventListener('message', h);
            setTimeout(() => resolve(), 10000);
        });
        await send('Page.navigate', { url });
        await navDone;
        await sleep(800);
    }

    async function evalJs(expression, awaitPromise) {
        const r = await send('Runtime.evaluate', { expression, awaitPromise: !!awaitPromise, returnByValue: true });
        if (r.exceptionDetails) {
            throw new Error('JS exception: ' + (r.exceptionDetails.exception && r.exceptionDetails.exception.description || r.exceptionDetails.text));
        }
        return r.result && r.result.value;
    }

    function fillAndSubmit(formId, fields) {
        const sets = fields.map((f) => {
            return `var el = document.getElementById('${f.id}'); el.value = '${f.value}'; el.dispatchEvent(new Event('input', {bubbles:true})); el.dispatchEvent(new Event('change', {bubbles:true}));`;
        }).join('\n');
        return `(function () {
            ${sets}
            var form = document.getElementById('${formId}');
            form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
            return 'submitted';
        })()`;
    }

    /** Espera até que o elemento exista no documento (poll). */
    async function waitForElement(id, timeoutMs) {
        const deadline = Date.now() + (timeoutMs || 8000);
        while (Date.now() < deadline) {
            const found = await evalJs(`!!document.getElementById('${id}')`, false).catch(() => false);
            if (found) { return true; }
            await sleep(400);
        }
        return false;
    }

    /** Dispara o submit e ignora o erro de contexto destruído pelo redirect. */
    async function submitAndIgnoreNav(formId, fields) {
        const ok = await waitForElement(formId);
        if (!ok) { throw new Error('Formulário ' + formId + ' não apareceu na página (url atual: ' + await currentUrl() + ')'); }
        try {
            await evalJs(fillAndSubmit(formId, fields), false);
        } catch (e) {
            if (!/navigated or closed/i.test(e.message)) { throw e; }
            console.log('   (redirect disparado pelo formulário — contexto recriado)');
        }
        await sleep(3000);
    }

    async function currentUrl() {
        return await evalJs('location.href', false);
    }

    // ---------- 0) Garante sessão encerrada e e-mail único ----------
    await navigate(BASE + '/login');
    await evalJs(`(function () {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        fetch('${BASE}/api/auth.php?action=logout', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
        return true;
    })()`, false);
    await sleep(1200);
    const email = 'fluxo' + Date.now() + '@teste.com';
    console.log('0. Sessão encerrada. E-mail do teste: ' + email);

    // ---------- 1) Cadastro via formulário ----------
    await navigate(BASE + '/cadastro');
    await submitAndIgnoreNav('register-form', [
        { id: 'name', value: 'Usuario Fluxo' },
        { id: 'email', value: email },
        { id: 'password', value: PASSWORD },
        { id: 'password_confirm', value: PASSWORD }
    ]);
    const url1 = await currentUrl();
    console.log('1. CADASTRO => url=' + url1);
    if (!/\/dashboard/.test(url1)) { throw new Error('Cadastro via formulário falhou — não redirecionou para /dashboard'); }

    // ---------- 2) Dashboard carregado e autenticado? ----------
    await navigate(BASE + '/dashboard');
    const dashState = await evalJs(`(async () => {
        await new Promise(r => setTimeout(r, 1500));
        var b = document.getElementById('stat-balance');
        var greeting = document.getElementById('dash-greeting');
        return { url: location.href, greeting: greeting ? greeting.textContent : null, balance: b ? b.textContent : null };
    })()`, true);
    console.log('2. DASHBOARD => url=' + dashState.url + ' | saudação="' + dashState.greeting + '" | saldo=' + dashState.balance);

    // ---------- 3) Logout ----------
    await evalJs(`(async () => {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=logout', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
        return true;
    })()`, true);
    console.log('3. LOGOUT OK');

    // ---------- 4) Login via formulário ----------
    await navigate(BASE + '/login');
    await submitAndIgnoreNav('login-form', [
        { id: 'email', value: email },
        { id: 'password', value: PASSWORD }
    ]);
    const url4 = await currentUrl();
    console.log('4. LOGIN => url=' + url4);
    if (!/\/dashboard/.test(url4)) { throw new Error('Login via formulário falhou — não redirecionou para /dashboard'); }

    // ---------- 5) Senha errada mostra erro? ----------
    await evalJs(`(async () => {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=logout', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
        return true;
    })()`, true);
    await navigate(BASE + '/login');
    await submitAndIgnoreNav('login-form', [
        { id: 'email', value: email },
        { id: 'password', value: 'senhaerrada999' }
    ]);
    const badErr = await evalJs(`(function () {
        var e = document.getElementById('form-error');
        return (e && !e.hidden) ? e.textContent : null;
    })()`, false);
    console.log('5. LOGIN senha errada => url=' + await currentUrl() + ' | ERRO exibido: "' + badErr + '"');

    ws.close();
    console.log('CONCLUIDO');
}

main().catch((e) => { console.error('ERRO: ' + e.message); process.exit(1); });
