/**
 * FinLife — Motion Layer JS (upgrade conforme prompt-redesign §8/§13/§17/§19)
 * Nenhuma lógica financeira é tocada: apenas observadores de UI.
 * ES5-friendly, com guards (uma execução por elemento/página) e reduced-motion.
 */
(function () {
    'use strict';

    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---------- §6.1 Ripple no ponto do clique ----------
    document.addEventListener('pointerdown', function (e) {
        var btn = e.target.closest ? e.target.closest('.btn') : null;
        if (!btn || btn.disabled) { return; }
        var rect = btn.getBoundingClientRect();
        btn.style.setProperty('--ripple-x', (e.clientX - rect.left) + 'px');
        btn.style.setProperty('--ripple-y', (e.clientY - rect.top) + 'px');
        btn.classList.remove('fl-ripple');
        void btn.offsetWidth; // restart animation
        btn.classList.add('fl-ripple');
        setTimeout(function () { btn.classList.remove('fl-ripple'); }, 600);
    }, { passive: true });

    // ---------- §8.1 Count-up (uma vez por elemento, reduced-motion off) ----------
    var BRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

    function parseBRL(text) {
        // "R$ 5.312,60" → 5312.60
        var m = String(text).replace(/R\$\s?/, '').trim().replace(/\./g, '').replace(',', '.');
        var v = parseFloat(m);
        return isNaN(v) ? null : v;
    }

    function countUp(el, target, duration) {
        if (REDUCED) { el.classList.add('fl-count-in'); return; }
        var start = null;
        var dur = duration || 1100;
        el.classList.add('fl-count-in');
        function tick(now) {
            if (!el.isConnected) { return; } // elemento saiu do DOM: cancela (sem vazamento)
            if (start === null) { start = now; }
            var t = Math.min((now - start) / dur, 1);
            var eased = 1 - Math.pow(1 - t, 3); // easeOutCubic
            el.textContent = BRL.format(target * eased);
            if (t < 1) { requestAnimationFrame(tick); }
        }
        requestAnimationFrame(tick);
    }

    function watchMoney(el) {
        if (!el || el.__flCounted) { return; }
        var val = parseBRL(el.textContent || '');
        if (val !== null && Math.abs(val) > 0) {
            el.__flCounted = true;
            countUp(el, val);
        }
    }

    // observa os valores financeiros principais quando o app os popula (JS-rendered)
    var moneySelector = '.bh-value, .account-card .acc-balance, .cm-kpi-value, .goal-card .goal-amount';
    var mo = new MutationObserver(function (muts) {
        var candidates = [];
        for (var i = 0; i < muts.length; i++) {
            var m = muts[i];
            if (m.target && m.target.nodeType === 1) { candidates.push(m.target); }
            for (var j = 0; j < m.addedNodes.length; j++) {
                var n = m.addedNodes[j];
                if (n.nodeType === 1) { candidates.push(n); }
                else if (n.parentElement) { candidates.push(n.parentElement); }
            }
        }
        for (var c = 0; c < candidates.length; c++) {
            var el = candidates[c];
            if (el.matches && el.matches(moneySelector)) { watchMoney(el); }
            else if (el.querySelectorAll) {
                var inner = el.querySelectorAll(moneySelector);
                for (var k = 0; k < inner.length; k++) { watchMoney(inner[k]); }
            }
        }
    });
    mo.observe(document.body, { childList: true, subtree: true, characterData: true });
    // valores já presentes (server-rendered)
    var initial = document.querySelectorAll(moneySelector);
    for (var q = 0; q < initial.length; q++) { watchMoney(initial[q]); }

    // ---------- §13.2 Tilt 3D nos cartões (apenas com cursor real) ----------
    if (window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches && !REDUCED) {
        document.querySelectorAll('.bank-card').forEach(function (card) {
            card.classList.add('fl-tilt');
            card.addEventListener('pointermove', function (e) {
                var rect = card.getBoundingClientRect();
                var x = (e.clientX - rect.left) / rect.width - 0.5;
                var y = (e.clientY - rect.top) / rect.height - 0.5;
                card.style.transform = 'rotateY(' + (x * 10).toFixed(2) + 'deg) rotateX(' + (-y * 8).toFixed(2) + 'deg) translateY(-3px)';
            });
            card.addEventListener('pointerleave', function () {
                card.style.transform = '';
            });
        });
    }

    // ---------- §17 View Transitions entre rotas ----------
    if (document.startViewTransition) {
        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('a') : null;
            if (!link || link.target === '_blank' ||
                link.hasAttribute('download') ||
                link.origin !== location.origin ||
                link.href.indexOf('#') === link.href.length - 1) { return; }
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            e.preventDefault();
            document.startViewTransition(function () {
                location.href = link.href;
            });
        });
    }

    // ---------- §14.3 Tab indicator (agrupa .tab-link pelo container real) ----------
    (function () {
        var bars = [];
        document.querySelectorAll('.tab-link').forEach(function (link) {
            var bar = link.parentElement;
            if (bar && bars.indexOf(bar) === -1) { bars.push(bar); }
        });
        bars.forEach(function (tabbar) {
            var links = tabbar.querySelectorAll('.tab-link');
            if (!links.length) { return; }
            var ind = document.createElement('span');
            ind.className = 'fl-tab-indicator';
            function move() {
                var act = tabbar.querySelector('.tab-link.active');
                if (!act) { ind.style.opacity = '0'; return; }
                ind.style.opacity = '1';
                ind.style.left = act.offsetLeft + 'px';
                ind.style.width = act.offsetWidth + 'px';
            }
            tabbar.style.position = tabbar.style.position || 'relative';
            tabbar.appendChild(ind);
            window.addEventListener('resize', move);
            var tmo = new MutationObserver(move);
            links.forEach(function (l) { tmo.observe(l, { attributes: true, attributeFilter: ['class'] }); });
            requestAnimationFrame(move);
            setTimeout(move, 600);
            setTimeout(move, 1600);
        });
    })();

    // ---------- §19.1 Botão magnético (login, sutil) ----------
    if (window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches && !REDUCED) {
        document.querySelectorAll('.auth-form .btn-primary').forEach(function (btn) {
            btn.addEventListener('pointermove', function (e) {
                var r = btn.getBoundingClientRect();
                var x = e.clientX - r.left - r.width / 2;
                var y = e.clientY - r.top - r.height / 2;
                btn.style.transform = 'translate(' + (x * 0.12).toFixed(1) + 'px,' + (y * 0.18).toFixed(1) + 'px)';
            });
            btn.addEventListener('pointerleave', function () { btn.style.transform = ''; });
        });
    }

    // ---------- §19.2 Scroll-to-top ----------
    (function () {
        var st = document.createElement('button');
        st.className = 'fl-scroll-top';
        st.setAttribute('aria-label', 'Voltar ao topo');
        st.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
        document.body.appendChild(st);
        var onScroll = function () {
            if (window.scrollY > 600) { st.classList.add('show'); }
            else { st.classList.remove('show'); }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        st.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: REDUCED ? 'auto' : 'smooth' });
        });
    })();

    // ---------- §20 FAB: glow de "pronto" uma única vez ----------
    var fab = document.getElementById('bn-fab');
    if (fab) {
        setTimeout(function () {
            fab.classList.add('fl-loaded');
            setTimeout(function () { fab.classList.remove('fl-loaded'); }, 1600);
        }, 900);
    }

    // ---------- Login/registro: shake no erro + loading no submit ----------
    var authForm = document.getElementById('login-form') || document.getElementById('register-form');
    if (authForm) {
        authForm.addEventListener('submit', function () {
            var btn = authForm.querySelector('button[type="submit"]');
            if (btn) {
                btn.classList.add('is-loading');
                // re-ativa se a página não navegar (erro voltará via handler existente)
                setTimeout(function () { btn.classList.remove('is-loading'); }, 6000);
            }
        });
        var errEl = document.getElementById('form-error');
        if (errEl) {
            var eo = new MutationObserver(function () {
                if (!errEl.hidden && errEl.textContent.trim()) {
                    var card = errEl.closest('.auth-card');
                    if (card) {
                        card.classList.remove('fl-shake');
                        void card.offsetWidth;
                        card.classList.add('fl-shake');
                    }
                }
            });
            eo.observe(errEl, { attributes: true, characterData: true, childList: true, subtree: true });
        }
    }
})();
