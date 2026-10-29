/* FinLife — Auditoria UX multi-breakpoint: overflow, densidade, hierarquia, toque. */
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
    ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id && p.has(m.id)) { p.get(m.id)(m.result); p.delete(m.id); } };
    const send = (method, params) => new Promise(r => { const i = ++id; p.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
    const evalJs = async (expr) => {
        const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
        if (r.exceptionDetails) { return 'ERR: ' + r.exceptionDetails.exception.description; }
        return r.result.value;
    };
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    await send('Page.enable'); await send('Runtime.enable');

    // login
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await send('Page.navigate', { url: BASE + '/login' });
    await sleep(1800);
    await evalJs(`(async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        await fetch('${BASE}/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin', body: JSON.stringify({ email: '${EMAIL}', password: '${PASSWORD}' }) });
        return true;
    })()`);

    const PAGES = ['dashboard', 'transacoes', 'cartoes', 'mercados'];
    const WIDTHS = [320, 375, 430, 768, 1024, 1440];
    const report = {};

    for (const w of WIDTHS) {
        const mobile = w <= 820;
        await send('Emulation.setDeviceMetricsOverride', { width: w, height: mobile ? 844 : 900, deviceScaleFactor: mobile ? 2 : 1, mobile });
        for (const pg of PAGES) {
            await send('Page.navigate', { url: BASE + '/' + pg });
            await sleep(mobile ? 2600 : 2000);
            const m = await evalJs(`(() => {
                const doc = document.documentElement;
                const vw = doc.clientWidth;
                const out = { vw, overflowX: doc.scrollWidth - vw, offenders: [] };
                // elementos que estouram
                document.querySelectorAll('#view *, .topbar *, .sidebar *').forEach(function (e) {
                    const r = e.getBoundingClientRect();
                    if (r.right > vw + 1 && r.width > 4 && out.offenders.length < 3) {
                        out.offenders.push(e.tagName + '.' + String(e.className).split(' ')[0] + ' w=' + Math.round(r.width));
                    }
                });
                // densidade de cards
                out.cards = document.querySelectorAll('#view .card, #view .stat-card, #view .pf-stat, #view .bank-card, #view .benefit-card').length;
                // hierarquia: maior fonte de valor
                const sv = document.querySelector('.stat-value');
                out.heroFont = sv ? getComputedStyle(sv).fontSize : null;
                out.heroIsBalance = !!document.querySelector('[id="stat-balance"]');
                // navegação mobile
                out.hasBottomNav = !!document.querySelector('.bottom-nav');
                out.drawerVisible = (function () { const s = document.getElementById('sidebar'); if (!s) return false; const r = s.getBoundingClientRect(); return r.left > -50 && r.left < vw - 50; })();
                out.menuBtnVisible = (function () { const b = document.getElementById('menu-btn'); return b ? getComputedStyle(b).display !== 'none' : false; })();
                // toque: menores alvos clicáveis visíveis
                let minTouch = 999;
                document.querySelectorAll('#view button, #view .btn, #view a.btn, .nav-link, .tab-link, .icon-btn').forEach(function (e) {
                    const r = e.getBoundingClientRect();
                    if (r.width > 0 && r.height > 0 && r.height < minTouch) { minTouch = Math.round(r.height); }
                });
                out.minTouchH = minTouch === 999 ? null : minTouch;
                // texto pequeno
                let small = 0;
                document.querySelectorAll('#view *').forEach(function (e) {
                    if (e.children.length === 0 && e.textContent.trim().length > 2) {
                        const fs = parseFloat(getComputedStyle(e).fontSize);
                        if (fs < 11) { small++; }
                    }
                });
                out.smallTexts = small;
                // primary color atual
                const primary = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();
                out.primary = primary;
                // radius dos cards
                const card = document.querySelector('#view .card');
                out.cardRadius = card ? getComputedStyle(card).borderRadius : null;
                out.cardCount = document.querySelectorAll('#view .card').length;
                return out;
            })()`);
            report[w + '|' + pg] = m;
            const flag = (typeof m === 'object' && m.overflowX > 0) ? ' ⚠OVERFLOW ' + m.overflowX : '';
            const touch = (typeof m === 'object' && m.minTouchH && m.minTouchH < 40) ? ' ⚠TOUCH' + m.minTouchH : '';
            console.log(String(w).padEnd(5) + pg.padEnd(12) + (typeof m === 'object'
                ? ' ovf=' + m.overflowX + ' cards=' + m.cards + ' hero=' + m.heroFont + ' touch=' + m.minTouchH + ' small=' + m.smallTexts + ' bottomNav=' + m.hasBottomNav + ' primary=' + m.primary + ' r=' + m.cardRadius
                : String(m).slice(0, 60)) + flag + touch);
        }
    }

    // auditoria do CSS via leitura de padrões problemáticos
    console.log('\n--- auditoria de código (CSS) ---');
    const css = await evalJs(`(async () => {
        const res = await fetch('${BASE}/assets/css/app.css');
        const css = await res.text();
        const lines = css.split('\\n').length;
        return {
            lines,
            important: (css.match(/!important/g) || []).length,
            fixedHeights: (css.match(/height:\\s*\\d{3,}px/g) || []).length,
            zIndexes: [...new Set((css.match(/z-index:\\s*\\d+/g) || []).map(z => z.replace('z-index: ', '')))],
            mediaQueries: (css.match(/@media/g) || []).length,
            maxw1280: css.includes('max-width: 1280px'),
            purple: /#7C3AED|#8B5CF6|#A78BFA/.test(css),
            indigo: /#4F46E5|#6366F1/.test(css),
            gradientCount: (css.match(/linear-gradient/g) || []).length,
            overflowHidden: (css.match(/overflow-x:\\s*hidden/g) || []).length
        };
    })()`);
    console.log(JSON.stringify(css, null, 2));

    ws.close();
}
main().catch(e => { console.error('ERRO: ' + e.message); process.exit(1); });
