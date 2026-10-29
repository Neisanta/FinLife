/* FinLife — Checagem funcional v2: modais, toasts, medidor de senha, chat.
   Garante que o redesign não quebrou nenhuma interação existente. */
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
    ws.onmessage = (ev) => {
        const m = JSON.parse(ev.data);
        if (m.id && pending.has(m.id)) {
            const { resolve, reject } = pending.get(m.id);
            pending.delete(m.id);
            m.error ? reject(new Error(m.error.message)) : resolve(m.result);
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
    await send('Page.enable');
    await send('Runtime.enable');

    async function navigate(url) {
        await send('Page.navigate', { url });
        await sleep(1700);
    }

    const results = [];
    function check(name, value) {
        results.push({ check: name, ok: value === true, value });
        console.log((value === true ? '  OK ' : '  FALHOU ') + name + (value === true ? '' : ' => ' + JSON.stringify(value)));
    }

    await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });

    // ---- login ----
    await navigate(BASE + '/login');
    const login = await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const res = await fetch('${BASE}/api/auth.php?action=login', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD })
        });
        return await res.json();
    })()`);
    check('login API', login.ok === true);

    // ---- modal: nova transação ----
    await navigate(BASE + '/transacoes');
    await evalJs(`document.getElementById('tx-new').click(); true`);
    await sleep(500);
    const modal1 = await evalJs(`(() => {
        const modal = document.querySelector('#modal-overlay .modal');
        return {
            open: !!modal && !document.getElementById('modal-overlay').hidden,
            fields: modal ? modal.querySelectorAll('.field').length : 0,
            inputs: modal ? modal.querySelectorAll('.input').length : 0,
            title: modal ? modal.querySelector('h3').textContent : null
        };
    })()`);
    check('modal nova transação abre', modal1.open);
    check('modal: 6 campos', modal1.fields === 6);
    check('modal: inputs estilizados', modal1.inputs >= 6);
    check('modal: título correto', modal1.title === 'Nova transação');

    await evalJs(`document.querySelector('#modal-overlay .modal .btn-ghost').click(); true`);
    await sleep(300);
    const modalClose = await evalJs(`document.getElementById('modal-overlay').hidden`);
    check('modal fecha ao cancelar', modalClose === true);

    // ---- toast ----
    const toast = await evalJs(`(() => {
        FL.toastSuccess('Teste de toast do redesign');
        const el = document.querySelector('.toasts .toast.success');
        return { exists: !!el, hasSvg: el ? !!el.querySelector('.toast-ico svg') : false, msg: el ? el.querySelector('.toast-msg').textContent : null };
    })()`);
    check('toast renderiza', toast.exists);
    check('toast com ícone SVG', toast.hasSvg);
    check('toast mensagem correta', toast.msg === 'Teste de toast do redesign');

    // ---- modal: ajustar orçamento (dashboard) ----
    await navigate(BASE + '/dashboard');
    await evalJs(`document.getElementById('budget-edit').click(); true`);
    await sleep(900);
    const budgetModal = await evalJs(`(() => {
        const modal = document.querySelector('#modal-overlay .modal');
        return { open: !!modal, title: modal ? modal.querySelector('h3').textContent : null };
    })()`);
    check('modal orçamento abre (skeleton→form)', budgetModal.open);
    check('modal orçamento: título', budgetModal.title === 'Orçamento mensal');
    await evalJs(`FL.closeModal(); true`);

    // ---- metas: modal aporte ----
    await navigate(BASE + '/metas');
    await evalJs(`document.querySelector('.goal-card .btn-primary').click(); true`);
    await sleep(500);
    const goalModal = await evalJs(`(() => {
        const modal = document.querySelector('#modal-overlay .modal');
        return { open: !!modal, title: modal ? modal.querySelector('h3').textContent : null };
    })()`);
    check('modal aporte abre', goalModal.open);
    check('modal aporte: título com nome da meta', /Aportar/.test(goalModal.title || ''));
    await evalJs(`FL.closeModal(); true`);

    // ---- medidor de senha (cadastro) ----
    await send('Network.deleteCookies', { name: 'finlife_session', url: BASE });
    await navigate(BASE + '/cadastro');
    const pw = await evalJs(`(() => {
        const input = document.getElementById('password');
        input.value = 'SenhaForte123!@#';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        const bar = document.getElementById('pw-bar');
        return { width: bar.style.width, cls: bar.className };
    })()`);
    check('medidor de senha preenche 100%', pw.width === '100%');
    check('medidor classe pw-good', pw.cls === 'pw-good');

    // ---- validação inline do login (precisa estar na página de login) ----
    await send('Network.deleteCookies', { name: 'finlife_session', url: BASE });
    await navigate(BASE + '/login');
    const loginErr = await evalJs(`(() => {
        const form = document.getElementById('login-form');
        document.getElementById('email').value = 'a';
        document.getElementById('password').value = '';
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        const err = document.getElementById('form-error');
        return { shown: !err.hidden, msg: err.textContent };
    })()`);
    check('erro inline aparece (sem submit)', loginErr.shown);
    check('mensagem de validação clara', /e-mail e senha/i.test(loginErr.msg));

    // ---- chat real ----
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin', body: JSON.stringify({ email: 'redesign@teste.com', password: process.env.FINLIFE_TEST_PASSWORD })
        });
        return true;
    })()`);
    await navigate(BASE + '/ai');
    // dispatch + leitura no MESMO eval: o fetch não pode completar dentro do dispatchEvent síncrono
    const chat = await evalJs(`(() => {
        const before = document.querySelectorAll('#chat-messages .msg.user').length;
        document.getElementById('chat-text').value = 'Quanto economizei este mês?';
        document.getElementById('chat-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        const after = document.querySelectorAll('#chat-messages .msg.user').length;
        const users = document.querySelectorAll('#chat-messages .msg.user');
        const lastUserP = users.length ? users[users.length - 1].querySelector('.msg-bubble p') : null;
        return {
            added: after > before,
            userMsg: lastUserP ? lastUserP.textContent : null,
            typing: !!document.querySelector('.typing'),
            sendDisabled: document.getElementById('chat-send').disabled
        };
    })()`);
    check('chat: mensagem do usuário aparece', chat.added && chat.userMsg === 'Quanto economizei este mês?');
    check('chat: indicador de digitação', chat.typing);
    check('chat: send desabilitado durante envio', chat.sendDisabled);

    // espera a resposta chegar (a IA externa pode levar até ~20s)
    await evalJs(`(() => {
        return new Promise(function (resolve) {
            let t = 0;
            const x = setInterval(function () {
                t++;
                if (!document.querySelector('.typing') || t > 40) { clearInterval(x); resolve(true); }
            }, 500);
        });
    })()`);
    await sleep(400);
    const chatDone = await evalJs(`(() => {
        const msgs = [...document.querySelectorAll('.msg.assistant .msg-bubble')].map(b => b.textContent.trim()).filter(t => t.length > 0);
        return { reply: msgs.length ? msgs[msgs.length - 1] : null, typingGone: !document.querySelector('.typing') };
    })()`);
    check('chat: resposta da IA recebida', chatDone.reply && chatDone.reply.length > 10);
    check('chat: indicador some após resposta', chatDone.typingGone);

    ws.close();
    const failed = results.filter((r) => !r.ok);
    console.log('\n===== FUNCIONAL: ' + (results.length - failed.length) + '/' + results.length + ' OK =====');
    if (failed.length) { failed.forEach((f) => console.log('FALHOU: ' + f.check + ' => ' + JSON.stringify(f.value))); process.exitCode = 1; }
    else { console.log('TODAS AS INTERAÇÕES FUNCIONAM'); }
}

main().catch((e) => { console.error('ERRO: ' + e.message); process.exit(1); });
