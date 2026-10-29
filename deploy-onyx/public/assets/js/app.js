/* ==============================================================
   FinLife — Núcleo do frontend (vanilla JS, sem dependências)
   - Cliente de API (fetch + CSRF + tratamento de 401)
   - Formatação de moeda/datas
   - Toasts, modais de formulário e confirmação
   - Menu mobile, logout
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL || {};
    FL.base = FL.base || '';

    // ------------------------------------------------------------
    // Helpers básicos
    // ------------------------------------------------------------
    FL.esc = function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    FL.money = function (value, withSign) {
        var v = Number(value) || 0;
        var formatted = 'R$ ' + Math.abs(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        if (withSign && v > 0) { return '+ ' + formatted; }
        if (withSign && v < 0) { return '− ' + formatted; }
        return (v < 0 ? '− ' : '') + formatted;
    };

    FL.brDate = function (iso) {
        if (!iso || !/^\d{4}-\d{2}-\d{2}/.test(iso)) { return String(iso || '—'); }
        var parts = iso.slice(0, 10).split('-');
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    };

    FL.monthLabel = function (ym) {
        var months = { '01': 'jan', '02': 'fev', '03': 'mar', '04': 'abr', '05': 'mai', '06': 'jun', '07': 'jul', '08': 'ago', '09': 'set', '10': 'out', '11': 'nov', '12': 'dez' };
        if (!/^\d{4}-\d{2}$/.test(ym || '')) { return String(ym || ''); }
        return (months[ym.slice(5)] || ym.slice(5)) + '/' + ym.slice(0, 4);
    };

    FL.pct = function (value) {
        if (value === null || value === undefined || isNaN(Number(value))) { return '—'; }
        return Number(value).toFixed(1).replace('.', ',') + '%';
    };

    FL.debounce = function (fn, ms) {
        var t = null;
        return function () {
            var args = arguments, self = this;
            if (t) { clearTimeout(t); }
            t = setTimeout(function () { fn.apply(self, args); }, ms || 300);
        };
    };

    FL.$ = function (sel) { return document.querySelector(sel); };
    FL.$$ = function (sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); };

    // ------------------------------------------------------------
    // Cliente de API
    // ------------------------------------------------------------
    FL.api = function (path, opts) {
        opts = opts || {};
        var url = FL.base + path;
        var init = {
            method: opts.method || 'GET',
            headers: { 'X-CSRF-Token': FL.csrf || '' },
            credentials: 'same-origin'
        };
        if (opts.body !== undefined && opts.body !== null) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(opts.body);
        }
        return fetch(url, init).then(function (res) {
            return res.json().catch(function () { return { ok: false, error: 'Resposta inválida do servidor.' }; })
                .then(function (data) { return { status: res.status, data: data }; });
        }).then(function (r) {
            // sessão expirada: volta para o login
            if (r.status === 401 && !opts.silent) {
                window.location.href = FL.base + '/login';
                return new Promise(function () { /* trava até navegar */ });
            }
            return r;
        }).catch(function () {
            return { status: 0, data: { ok: false, error: 'Falha de conexão. Verifique sua internet e tente novamente.' } };
        });
    };

    // ------------------------------------------------------------
    // Tema (claro/escuro)
    // ------------------------------------------------------------
    FL.theme = {
        get: function () {
            return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        },
        apply: function (t) {
            document.documentElement.setAttribute('data-theme', t);
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) { meta.setAttribute('content', t === 'dark' ? '#0A0912' : '#F4F4F8'); }
            try { localStorage.setItem('fl-theme', t); } catch (e) { /* modo privado */ }
            document.dispatchEvent(new CustomEvent('fl:themechange', { detail: { theme: t } }));
        },
        toggle: function () {
            this.apply(this.get() === 'dark' ? 'light' : 'dark');
        }
    };

    var themeToggleBtn = document.getElementById('theme-toggle');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function () { FL.theme.toggle(); });
    }

    // ------------------------------------------------------------
    // Toasts
    // ------------------------------------------------------------
    var TOAST_ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M12 11.5v5"/></svg>'
    };

    FL.toast = function (message, type, timeout) {
        type = type || 'info';
        var wrap = document.getElementById('toasts');
        if (!wrap) { return; }
        var el = document.createElement('div');
        el.className = 'toast ' + type;
        el.innerHTML = '<span class="toast-ico">' + (TOAST_ICONS[type] || TOAST_ICONS.info) + '</span><span class="toast-msg"></span>';
        el.querySelector('.toast-msg').textContent = message;
        wrap.appendChild(el);
        var ttl = timeout || (type === 'error' ? 6000 : 3600);
        setTimeout(function () {
            el.classList.add('hide');
            setTimeout(function () { el.remove(); }, 280);
        }, ttl);
    };

    FL.toastSuccess = function (m) { FL.toast(m, 'success'); };
    FL.toastError = function (m) { FL.toast(m, 'error'); };

    /** Modal genérico de HTML arbitrário: FL.modal(html, onReady(body, close)). */
    FL.modal = function (html, onReady) {
        var modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = '<button type="button" class="modal-close" aria-label="Fechar">✕</button><div class="modal-body"></div>';
        var body = modal.querySelector('.modal-body');
        body.innerHTML = html;
        modal.querySelector('.modal-close').addEventListener('click', closeModal);
        openModal(modal);
        if (typeof onReady === 'function') { onReady(body, closeModal); }
        return { close: closeModal, body: body };
    };

    // ------------------------------------------------------------
    // Modais
    // ------------------------------------------------------------
    var overlay = document.getElementById('modal-overlay');

    function openModal(modal) {
        if (!overlay) { return; }
        overlay.classList.remove('closing');
        overlay.innerHTML = '';
        overlay.appendChild(modal);
        overlay.hidden = false;
        var first = modal.querySelector('input, select, textarea, button.btn-primary');
        if (first) { setTimeout(function () { first.focus(); }, 60); }
    }

    function closeModal() {
        if (!overlay || overlay.hidden) { return; }
        const modal = overlay.querySelector('.modal');
        // Saída animada assimétrica: 140ms (mais rápida que a entrada), depois limpa
        if (modal && !overlay.classList.contains('closing')) {
            overlay.classList.add('closing');
            setTimeout(function () {
                overlay.classList.remove('closing');
                overlay.hidden = true;
                overlay.innerHTML = '';
            }, 145);
        } else {
            overlay.classList.remove('closing');
            overlay.hidden = true;
            overlay.innerHTML = '';
        }
        document.removeEventListener('keydown', escHandler);
    }

    function escHandler(e) { if (e.key === 'Escape') { closeModal(); } }

    function skeletonBase() {
        var m = document.createElement('div');
        m.className = 'modal';
        m.innerHTML =
            '<div class="modal-head"><h3>Carregando…</h3><button type="button" class="modal-close" aria-label="Fechar">×</button></div>' +
            '<div class="modal-body"><div class="skeleton" style="height:34px;margin-bottom:12px"></div>' +
            '<div class="skeleton" style="height:34px;margin-bottom:12px"></div>' +
            '<div class="skeleton" style="height:34px"></div></div>';
        m.querySelector('.modal-close').addEventListener('click', closeModal);
        return m;
    }

    /**
     * Modal de formulário genérico.
     * fields: [{name, label, type, value, required, options:[{value,label}], min, max, step, placeholder, hint}]
     * onSubmit(values, helpers) — retorne Promise; helpers.showFormError(msg), helpers.close()
     */
    FL.formModal = function (title, fields, onSubmit) {
        var modal = skeletonBase();
        modal.querySelector('.modal-head h3').textContent = title;

        var body = modal.querySelector('.modal-body');
        var form = document.createElement('form');
        form.className = 'auth-form';
        form.id = 'fl-modal-form';
        form.noValidate = true;

        fields.forEach(function (f) {
            var wrap = document.createElement('div');
            wrap.className = (f.inline === 'half') ? 'modal-row' : 'field';
            var id = 'mf-' + f.name;
            var inputHtml;
            var common = ' class="input" id="' + id + '" name="' + f.name + '"' +
                (f.required ? ' required' : '') +
                (f.placeholder ? ' placeholder="' + FL.esc(f.placeholder) + '"' : '') +
                (f.min !== undefined ? ' min="' + f.min + '"' : '') +
                (f.max !== undefined ? ' max="' + f.max + '"' : '') +
                (f.step !== undefined ? ' step="' + f.step + '"' : '') +
                (f.autocomplete ? ' autocomplete="' + f.autocomplete + '"' : '');

            if (f.type === 'select') {
                inputHtml = '<select' + common + '>';
                (f.options || []).forEach(function (o) {
                    var sel = (String(f.value) === String(o.value)) ? ' selected' : '';
                    inputHtml += '<option value="' + FL.esc(o.value) + '"' + sel + '>' + FL.esc(o.label) + '</option>';
                });
                inputHtml += '</select>';
            } else {
                inputHtml = '<input type="' + (f.type || 'text') + '"' + common +
                    ' value="' + FL.esc(f.value !== undefined && f.value !== null ? f.value : '') + '">';
            }
            wrap.innerHTML = (f.inline === 'half' ? '<div class="field"><label for="' + id + '">' + FL.esc(f.label) + '</label>' + inputHtml + '</div>'
                : '<label for="' + id + '">' + FL.esc(f.label) + '</label>' + inputHtml) +
                (f.hint ? '<div class="hint">' + FL.esc(f.hint) + '</div>' : '');
            form.appendChild(wrap);
        });

        var err = document.createElement('p');
        err.className = 'form-error';
        err.hidden = true;
        form.appendChild(err);
        body.appendChild(form);

        // Footer FIXO no pé do modal (fora da área rolável): os botões
        // Cancelar/Salvar ficam sempre visíveis, mesmo com campos que exigem
        // rolagem — padrão dos dashboards modernos.
        var foot = document.createElement('div');
        foot.className = 'modal-foot';
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-ghost';
        cancel.textContent = 'Cancelar';
        var submit = document.createElement('button');
        submit.type = 'submit';
        submit.setAttribute('form', form.id);
        submit.className = 'btn btn-primary';
        submit.textContent = 'Salvar';
        foot.appendChild(cancel);
        foot.appendChild(submit);
        modal.appendChild(foot);

        modal.querySelector('.modal-close').addEventListener('click', closeModal);
        cancel.addEventListener('click', closeModal);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            err.hidden = true;
            // validação nativa
            if (!form.checkValidity()) {
                var invalid = form.querySelector(':invalid');
                err.textContent = (invalid && invalid.validationMessage) ? invalid.validationMessage : 'Verifique os campos do formulário.';
                err.hidden = false;
                if (invalid) { invalid.focus(); }
                return;
            }
            var values = {};
            fields.forEach(function (f) {
                var input = form.querySelector('[name="' + f.name + '"]');
                values[f.name] = input ? input.value : '';
            });
            submit.disabled = true;
            submit.innerHTML = '<span class="spinner"></span> Salvando…';
            Promise.resolve(onSubmit(values, {
                showFormError: function (msg) { err.textContent = msg; err.hidden = false; submit.disabled = false; submit.textContent = 'Salvar'; },
                close: closeModal
            })).catch(function () {
                err.textContent = 'Erro inesperado. Tente novamente.';
                err.hidden = false;
                submit.disabled = false;
                submit.textContent = 'Salvar';
            });
        });

        openModal(modal);
    };

    /** Modal de confirmação. Retorna Promise<boolean>. */
    FL.confirm = function (title, message, danger) {
        return new Promise(function (resolve) {
            var modal = document.createElement('div');
            modal.className = 'modal';
            modal.innerHTML =
                '<div class="modal-head"><h3></h3><button type="button" class="modal-close" aria-label="Fechar">×</button></div>' +
                '<div class="modal-body"><p></p></div>' +
                '<div class="modal-foot">' +
                '<button type="button" class="btn btn-ghost" data-act="no">Cancelar</button>' +
                '<button type="button" class="btn ' + (danger ? 'btn-danger' : 'btn-primary') + '" data-act="yes">Confirmar</button>' +
                '</div>';
            modal.querySelector('h3').textContent = title;
            modal.querySelector('.modal-body p').textContent = message;

            function done(val) { closeModal(); resolve(val); }
            modal.querySelector('[data-act="yes"]').addEventListener('click', function () { done(true); });
            modal.querySelector('[data-act="no"]').addEventListener('click', function () { done(false); });
            modal.querySelector('.modal-close').addEventListener('click', function () { done(false); });

            openModal(modal);
        });
    };

    FL.closeModal = closeModal;

    if (overlay) {
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeModal(); } });
    }

    // ------------------------------------------------------------
    // Bottom sheet (menus mobile: navegação "Mais" e ação rápida "+")
    // ------------------------------------------------------------
    var sheet = document.getElementById('bottom-sheet');
    var sheetBackdrop = document.getElementById('sheet-backdrop');

    FL.bottomSheet = function (html) {
        if (!sheet) { return null; }
        sheet.innerHTML = html;
        sheet.hidden = false;
        if (sheetBackdrop) { sheetBackdrop.hidden = false; }
        requestAnimationFrame(function () { sheet.classList.add('open'); });
        document.addEventListener('keydown', sheetEsc);
        return sheet;
    };
    FL.closeSheet = function () {
        if (!sheet) { return; }
        sheet.classList.remove('open');
        document.removeEventListener('keydown', sheetEsc);
        setTimeout(function () {
            sheet.hidden = true;
            if (sheetBackdrop) { sheetBackdrop.hidden = true; }
        }, 200);
    };
    function sheetEsc(e) { if (e.key === 'Escape') { FL.closeSheet(); } }
    if (sheetBackdrop) { sheetBackdrop.addEventListener('click', FL.closeSheet); }

    var bnMore = document.getElementById('bn-more');
    if (bnMore) {
        bnMore.addEventListener('click', function () {
            var items = [
                { href: FL.base + '/contas', icon: '💳', label: 'Contas' },
                { href: FL.base + '/cartoes', icon: '🏦', label: 'Cartões' },
                { href: FL.base + '/compromissos', icon: '📅', label: 'Compromissos' },
                { href: FL.base + '/metas', icon: '🎯', label: 'Metas' },
                { href: FL.base + '/categorias', icon: '🏷️', label: 'Categorias' },
                { href: FL.base + '/ai', icon: '✨', label: 'FinLife AI' },
            ];
            var firstName = (FL.user && FL.user.name) ? FL.user.name.split(' ')[0] : '';
            var initial = firstName ? firstName.charAt(0).toUpperCase() : '?';
            var html =
                '<div class="sheet-user">' +
                '<span class="avatar">' + initial + '</span>' +
                '<span class="user-meta"><strong></strong><small></small></span>' +
                '</div>' +
                '<div class="sheet-title">Explorar</div>' + items.map(function (i) {
                return '<a class="sheet-item" href="' + i.href + '"><span class="sheet-ico">' + i.icon + '</span>' + i.label + '</a>';
            }).join('')
                + '<div class="sheet-title" style="margin-top:6px">Preferências</div>'
                + '<button type="button" class="sheet-item" data-sheet-theme><span class="sheet-ico">🌓</span>Alternar tema</button>'
                + '<button type="button" class="sheet-item danger" id="sheet-logout"><span class="sheet-ico">⏻</span>Sair</button>';
            FL.bottomSheet(html);
            // identificação real do usuário (nunca coberta: o sheet está acima de tudo)
            var meta = sheet.querySelector('.sheet-user .user-meta');
            if (meta) {
                meta.querySelector('strong').textContent = FL.user ? FL.user.name : '';
                meta.querySelector('small').textContent = FL.user ? FL.user.email : '';
            }
            var themeBtn = sheet.querySelector('[data-sheet-theme]');
            if (themeBtn) { themeBtn.addEventListener('click', function () { FL.closeSheet(); FL.theme.toggle(); }); }
            var out = sheet.querySelector('#sheet-logout');
            if (out) {
                out.addEventListener('click', function () {
                    FL.closeSheet();
                    FL.api('/api/auth.php?action=logout', { method: 'POST' }).then(function () {
                        window.location.href = FL.base + '/login';
                    });
                });
            }
        });
    }

    var bnFab = document.getElementById('bn-fab');
    if (bnFab) {
        bnFab.addEventListener('click', function () {
            var html = '<div class="sheet-title">Adicionar</div>'
                + '<button type="button" class="sheet-item" data-qa="expense"><span class="sheet-ico" style="color:var(--danger)">↓</span>Nova despesa</button>'
                + '<button type="button" class="sheet-item" data-qa="income"><span class="sheet-ico" style="color:var(--success)">↑</span>Nova receita</button>'
                + '<p class="sheet-note">Registre em segundos — categorias e contas são sugeridas automaticamente.</p>';
            FL.bottomSheet(html);
            sheet.querySelectorAll('[data-qa]').forEach(function (b) {
                b.addEventListener('click', function () {
                    FL.closeSheet();
                    FL.quickTransaction(b.getAttribute('data-qa'));
                });
            });
        });
    }

    // ------------------------------------------------------------
    // Auto-hide da navegação inferior: no meio do scroll ela sai de
    // cena — nenhum conteúdo fica encoberto ou perde clique. Volta no
    // topo, no fim da página (onde o padding garante respiro) e ao
    // rolar para cima (intenção de navegar).
    // ------------------------------------------------------------
    var bottomNav = document.getElementById('bottom-nav');
    if (bottomNav) {
        var lastScrollY = window.scrollY;
        window.addEventListener('scroll', function () {
            var y = window.scrollY;
            var maxScroll = document.documentElement.scrollHeight - window.innerHeight;
            var nearTop = y < 120;
            var nearBottom = maxScroll - y < 8;
            if (nearTop || nearBottom || y < lastScrollY - 4) {
                bottomNav.classList.remove('nav-hidden');
            } else if (y > lastScrollY + 4) {
                bottomNav.classList.add('nav-hidden');
            }
            lastScrollY = y;
        }, { passive: true });
    }

    // ------------------------------------------------------------
    // Nova transação rápida (global — usado pela bottom nav e páginas)
    // ------------------------------------------------------------
    FL.quickTransaction = function (presetType, onDone) {
        var categories = { income: [], expense: [] };
        var accounts = [];
        Promise.all([
            FL.api('/api/categories.php', { silent: true }),
            FL.api('/api/accounts.php', { silent: true }),
        ]).then(function (rs) {
            if (rs[0].data && rs[0].data.ok) { categories = rs[0].data.categories; }
            if (rs[1].data && rs[1].data.ok) { accounts = rs[1].data.accounts || []; }
            var catOptions = [{ value: '', label: 'Automática (por tipo)' }];
            categories.expense.forEach(function (c) { catOptions.push({ value: c.id, label: '💸 ' + c.name }); });
            categories.income.forEach(function (c) { catOptions.push({ value: c.id, label: '💰 ' + c.name }); });
            var accOptions = [{ value: '', label: 'Sem conta' }];
            accounts.forEach(function (a) { accOptions.push({ value: a.id, label: a.name }); });

            var title = presetType === 'income' ? 'Nova receita'
                : presetType === 'expense' ? 'Nova despesa'
                : 'Nova transação';
            FL.formModal(title, [
                { name: 'type', label: 'Tipo', type: 'select', value: presetType || 'expense', required: true, options: [
                    { value: 'expense', label: 'Despesa' },
                    { value: 'income', label: 'Receita' },
                ] },
                { name: 'description', label: 'Descrição', type: 'text', required: true, maxlength: 255, placeholder: 'Ex.: Mercado da esquina' },
                { name: 'amount', label: 'Valor (R$)', type: 'number', required: true, min: 0.01, step: '0.01', placeholder: '0,00' },
                { name: 'date', label: 'Data', type: 'date', value: new Date().toISOString().slice(0, 10), required: true },
                { name: 'category_id', label: 'Categoria', type: 'select', value: '', options: catOptions },
                { name: 'account_id', label: 'Conta', type: 'select', value: '', options: accOptions },
            ], function (values, helpers) {
                return FL.api('/api/transactions.php', {
                    method: 'POST',
                    body: {
                        type: values.type,
                        description: values.description,
                        amount: values.amount,
                        date: values.date,
                        category_id: values.category_id === '' ? null : values.category_id,
                        account_id: values.account_id === '' ? null : values.account_id,
                    },
                }).then(function (res) {
                    if (res.data && res.data.ok) {
                        helpers.close();
                        FL.toastSuccess('Transação registrada com sucesso!');
                        if (typeof onDone === 'function') { onDone(); }
                        else { window.location.reload(); }
                    } else {
                        helpers.showFormError((res.data && res.data.error) || 'Não foi possível salvar.');
                    }
                });
            });
        });
    };

    // ------------------------------------------------------------
    // Logout
    // ------------------------------------------------------------
    var logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
            FL.api('/api/auth.php?action=logout', { method: 'POST' }).then(function () {
                window.location.href = FL.base + '/login';
            });
        });
    }

    // ------------------------------------------------------------
    // Tela de login
    // ------------------------------------------------------------
    var loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var err = document.getElementById('form-error');
            var btn = document.getElementById('login-submit');
            if (err) { err.hidden = true; }
            var emailInput = document.getElementById('email');
            var passInput = document.getElementById('password');
            var email = emailInput ? emailInput.value.trim() : '';
            var password = passInput ? passInput.value : '';
            if (!email || !password) {
                if (err) { err.textContent = 'Informe e-mail e senha.'; err.hidden = false; }
                return;
            }
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Entrando…';
            // silent: 401 aqui significa "credenciais inválidas" (não sessão expirada)
            FL.api('/api/auth.php?action=login', { method: 'POST', body: { email: email, password: password }, silent: true })
                .then(function (r) {
                    if (r.data && r.data.ok) {
                        window.location.href = FL.base + '/' + (r.data.redirect || 'dashboard');
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Entrar';
                        if (err) { err.textContent = (r.data && r.data.error) || 'Não foi possível entrar.'; err.hidden = false; }
                    }
                });
        });
    }

    // ------------------------------------------------------------
    // Tela de cadastro
    // ------------------------------------------------------------
    var registerForm = document.getElementById('register-form');
    if (registerForm) {
        // medidor de força da senha
        var pwInput = document.getElementById('password');
        var pwBar = document.getElementById('pw-bar');
        if (pwInput && pwBar) {
            pwInput.addEventListener('input', function () {
                var v = pwInput.value;
                var score = 0;
                if (v.length >= 8) { score++; }
                if (v.length >= 12) { score++; }
                if (/[A-Z]/.test(v) && /[a-z]/.test(v)) { score++; }
                if (/\d/.test(v)) { score++; }
                if (/[^A-Za-z0-9]/.test(v)) { score++; }
                var strength = Math.min(100, (score / 5) * 100);
                pwBar.style.width = strength + '%';
                pwBar.className = (strength < 40) ? '' : (strength < 70) ? 'pw-ok' : 'pw-good';
            });
        }

        registerForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var err = document.getElementById('form-error');
            var btn = document.getElementById('register-submit');
            if (err) { err.hidden = true; }
            var nameInput = document.getElementById('name');
            var emailInput = document.getElementById('email');
            var passInput = document.getElementById('password');
            var confirmInput = document.getElementById('password_confirm');
            var name = nameInput ? nameInput.value.trim() : '';
            var email = emailInput ? emailInput.value.trim() : '';
            var password = passInput ? passInput.value : '';
            var confirm = confirmInput ? confirmInput.value : '';

            if (!name || !email || !password) {
                if (err) { err.textContent = 'Preencha nome, e-mail e senha.'; err.hidden = false; }
                return;
            }
            if (password !== confirm) {
                if (err) { err.textContent = 'As senhas não conferem.'; err.hidden = false; }
                return;
            }
            if (password.length < 8) {
                if (err) { err.textContent = 'A senha deve ter no mínimo 8 caracteres.'; err.hidden = false; }
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Criando conta…';
            // silent: erros de validação/CSRF aqui não devem recarregar a página
            FL.api('/api/auth.php?action=register', { method: 'POST', body: { name: name, email: email, password: password, password_confirm: confirm }, silent: true })
                .then(function (r) {
                    if (r.data && r.data.ok) {
                        window.location.href = FL.base + '/' + (r.data.redirect || 'dashboard');
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Criar conta';
                        if (err) { err.textContent = (r.data && r.data.error) || 'Não foi possível criar a conta.'; err.hidden = false; }
                    }
                });
        });
    }

    // ------------------------------------------------------------
    // Utilidades compartilhadas de páginas
    // ------------------------------------------------------------
    /** Selects de categoria/conta com options a partir de listas da API. */
    FL.fillSelect = function (select, items, valueKey, labelKey, emptyLabel) {
        if (!select) { return; }
        var current = select.value;
        select.innerHTML = '<option value="">' + FL.esc(emptyLabel || 'Todos') + '</option>';
        (items || []).forEach(function (it) {
            var opt = document.createElement('option');
            opt.value = it[valueKey];
            opt.textContent = it[labelKey];
            select.appendChild(opt);
        });
        if (current) { select.value = current; }
    };

    window.FL = FL;
})();