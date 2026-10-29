/* ==============================================================
   FinLife — Página Cartões (Central de Cartões)
   Crédito, débito e benefícios: limites, faturas por ciclo,
   parcelamento automático, assinaturas, relatórios e calendário.
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL;
    if (!FL) { return; }

    var API = '/api/cards.php';
    var API_PURCHASES = '/api/card_purchases.php';
    var API_INVOICES = '/api/card_invoices.php';

    var BENEFIT_KINDS = [
        ['meal', 'Vale-refeição (VR)'],
        ['food', 'Vale-alimentação (VA)'],
        ['fuel', 'Combustível'],
        ['mobility', 'Mobilidade'],
        ['home_office', 'Home Office'],
        ['transport', 'Vale-transporte'],
        ['award', 'Premiação'],
        ['other', 'Outro benefício'],
    ];
    var BRANDS = ['visa', 'mastercard', 'elo', 'amex', 'hipercard', 'diners', 'outros'];
    var MONTHS_FULL = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    var DOW = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];

    var state = {
        overview: null,
        cards: [],
        accounts: [],
        categories: [],
        tab: 'purchases',
        loaded: {},
    };

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------
    function $(id) { return document.getElementById(id); }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }
    function ymLabel(ym) {
        if (!/^\d{4}-\d{2}$/.test(ym || '')) { return ym || ''; }
        return MONTHS_FULL[parseInt(ym.slice(5), 10) - 1] + ' de ' + ym.slice(0, 4);
    }
    function isLightColor(hex) {
        var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        if (!m) { return false; }
        var v = m[1];
        var r = parseInt(v.slice(0, 2), 16), g = parseInt(v.slice(2, 4), 16), b = parseInt(v.slice(4, 6), 16);
        return (0.2126 * r + 0.7152 * g + 0.0722 * b) > 175;
    }
    function ymd(d) { return (d < 10 ? '0' : '') + d; }

    /** Modal custom (campos condicionais exigem HTML próprio). */
    function customModal(title, bodyHtml, saveLabel) {
        var overlay = $('modal-overlay');
        var modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML =
            '<div class="modal-head"><h3>' + FL.esc(title) + '</h3>' +
            '<button type="button" class="modal-close" aria-label="Fechar">×</button></div>' +
            '<div class="modal-body">' + bodyHtml + '</div>' +
            '<div class="modal-foot">' +
            '<button type="button" class="btn btn-ghost" data-cancel>Cancelar</button>' +
            '<button type="button" class="btn btn-primary" data-save>' + FL.esc(saveLabel || 'Salvar') + '</button>' +
            '</div>';
        overlay.innerHTML = '';
        overlay.appendChild(modal);
        overlay.hidden = false;
        modal.querySelector('.modal-close').addEventListener('click', FL.closeModal);
        modal.querySelector('[data-cancel]').addEventListener('click', FL.closeModal);
        var first = modal.querySelector('input, select, textarea');
        if (first) { setTimeout(function () { first.focus(); }, 60); }
        return modal;
    }
    function modalError(modal, msg) {
        var p = modal.querySelector('.form-error');
        if (p) { p.textContent = msg; p.hidden = false; }
    }
    function fieldHtml(id, label, inputHtml, extra) {
        return '<div class="field"><label for="' + id + '">' + FL.esc(label) + '</label>' + inputHtml +
            (extra || '') + '</div>';
    }
    function selectHtml(id, options, selected, placeholder) {
        var html = '<select class="input" id="' + id + '">';
        if (placeholder !== undefined) { html += '<option value="">' + FL.esc(placeholder) + '</option>'; }
        options.forEach(function (o) {
            html += '<option value="' + FL.esc(String(o[0])) + '"' + (String(o[0]) === String(selected) ? ' selected' : '') + '>' + FL.esc(o[1]) + '</option>';
        });
        return html + '</select>';
    }
    function inputHtml(id, type, value, attrs) {
        return '<input class="input" type="' + type + '" id="' + id + '" value="' + FL.esc(value === null || value === undefined ? '' : value) + '" ' + (attrs || '') + '>';
    }

    function cardById(id) {
        return state.cards.find(function (c) { return String(c.id) === String(id); }) || null;
    }
    /** Todos os cartões "compráveis" (ativos, incluindo adicionais), com o nome do principal. */
    function purchasableCards(includeArchived) {
        var out = [];
        state.cards.forEach(function (c) {
            if (c.status !== 'blocked' && (includeArchived || c.status !== 'archived')) {
                out.push(c);
                (c.additional || []).forEach(function (a) {
                    if (a.status !== 'blocked' && (includeArchived || a.status !== 'archived')) { out.push(a); }
                });
            }
        });
        return out;
    }

    var METHOD_ICON = {
        credit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
        debit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg>',
        benefit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="13" rx="2"/><path d="M12 8v13M3 12h18"/><path d="M12 8a3 3 0 0 1-3-3c0-1.5 1.3-2 3-2s3 .5 3 2a3 3 0 0 1-3 3z"/></svg>',
    };

    // ------------------------------------------------------------
    // Carga inicial
    // ------------------------------------------------------------
    function loadOverview() {
        FL.api(API).then(function (r) {
            if (!r.data || !r.data.ok) {
                FL.toastError((r.data && r.data.error) || 'Erro ao carregar cartões.');
                return;
            }
            state.overview = r.data;
            state.cards = r.data.cards || [];
            renderHero();
            renderAlerts();
            renderCardsGrid();
            renderBenefits();
            refreshCardSelects();
            if (state.tab === 'benefits' && !state.loaded.benefits) { state.loaded.benefits = true; }
        });
    }

    function loadRefs() {
        FL.api('/api/accounts.php', { silent: true }).then(function (r) {
            if (r.data && r.data.ok) { state.accounts = r.data.accounts || []; }
        });
        FL.api('/api/categories.php', { silent: true }).then(function (r) {
            if (r.data && r.data.ok) { state.categories = (r.data.categories && r.data.categories.expense) || []; }
        });
    }

    function refreshCardSelects() {
        var opts = state.cards.map(function (c) { return [c.id, c.name]; });
        ['p-filter-card', 'inv-filter-card'].forEach(function (id) {
            var sel = $(id);
            if (!sel) { return; }
            var cur = sel.value;
            sel.innerHTML = '<option value="">Todos</option>';
            opts.forEach(function (o) {
                var op = el('option', '', o[1]);
                op.value = o[0];
                sel.appendChild(op);
            });
            if (cur) { sel.value = cur; }
        });
    }

    // ------------------------------------------------------------
    // Hero consolidado + alertas
    // ------------------------------------------------------------
    function renderHero() {
        var o = state.overview;
        if (!o) { return; }
        var grid = $('cards-hero-grid');
        grid.innerHTML = '';

        function stat(label, value, sub, cls) {
            var d = el('div', 'hero-stat' + (cls ? ' ' + cls : ''));
            d.appendChild(el('span', 'stat-label', label));
            d.appendChild(el('span', 'hero-stat-value', value));
            if (sub) { d.appendChild(el('span', 'hero-stat-sub', sub)); }
            grid.appendChild(d);
        }

        stat('Limite total', FL.money(o.total_limit));
        stat('Limite utilizado', FL.money(o.total_used), o.total_limit > 0 ? FL.pct(o.total_used / o.total_limit * 100) : '', 'negative');
        stat('Disponível', FL.money(o.total_available), '', 'positive');
        stat('Faturas abertas', FL.money(o.open_invoices_total));

        var months = Object.keys(o.future_installments || {});
        var nearMonths = months.slice(0, 3).map(function (m) { return FL.monthLabel(m); }).join(', ');
        stat('Parcelas futuras', FL.money(o.future_installments_total), nearMonths ? 'em ' + nearMonths : '');
        stat('Assinaturas', FL.money(o.subscriptions_monthly), 'por mês');
        if (o.next_due) {
            stat('Próximo vencimento', FL.money(o.next_due.total), o.next_due.card_name + ' · ' + FL.brDate(o.next_due.due_date), 'negative');
        } else {
            stat('Próximo vencimento', '—', 'nenhuma fatura em aberto');
        }
        stat('Saldos de benefícios', FL.money(o.benefits_balance), '', 'positive');
    }

    function renderAlerts() {
        var wrap = $('cards-alerts');
        wrap.innerHTML = '';
        var alerts = (state.overview && state.overview.alerts) || [];
        if (!alerts.length) { return; }
        var list = el('div', 'alerts-list');
        var icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>';
        alerts.slice(0, 6).forEach(function (a) {
            var b = el('div', 'alert-banner ' + a.level);
            b.innerHTML = icon + '<span></span>';
            b.querySelector('span').textContent = a.message;
            list.appendChild(b);
        });
        wrap.appendChild(list);
    }

    // ------------------------------------------------------------
    // Grade de cartões (visuais)
    // ------------------------------------------------------------
    function renderCardsGrid() {
        var grid = $('cards-grid');
        grid.innerHTML = '';
        if (!state.cards.length) {
            var empty = el('p', 'empty', 'Você ainda não cadastrou cartões. Crie o primeiro — crédito, débito ou benefício.');
            empty.style.gridColumn = '1 / -1';
            grid.appendChild(empty);
            return;
        }

        // ordena: ativos primeiro, arquivados no fim
        var cards = state.cards.slice().sort(function (a, b) {
            var ra = a.status === 'archived' ? 1 : 0, rb = b.status === 'archived' ? 1 : 0;
            return ra - rb;
        });

        cards.forEach(function (c) {
            var tile = el('div', 'card-tile');
            tile.appendChild(bankCardEl(c));

            var actions = el('div', 'card-tile-actions');
            actions.appendChild(el('span', 'chip', c.type_label));

            var buyBtn = el('button', 'icon-btn');
            buyBtn.title = c.type === 'benefit' ? 'Registrar gasto' : 'Registrar compra';
            buyBtn.setAttribute('aria-label', buyBtn.title);
            buyBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
            buyBtn.addEventListener('click', function () { openPurchaseForm(null, c); });
            actions.appendChild(buyBtn);

            var editBtn = el('button', 'icon-btn');
            editBtn.title = 'Editar cartão';
            editBtn.setAttribute('aria-label', 'Editar cartão');
            editBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
            editBtn.addEventListener('click', function () { openCardForm(c); });
            actions.appendChild(editBtn);

            var blockBtn = el('button', 'icon-btn');
            var blocked = c.status === 'blocked';
            blockBtn.title = blocked ? 'Desbloquear' : 'Bloquear';
            blockBtn.setAttribute('aria-label', blockBtn.title);
            blockBtn.innerHTML = blocked
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 11V7a4 4 0 0 1 8 0v4"/><rect x="4" y="11" width="16" height="10" rx="2"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
            blockBtn.addEventListener('click', function () { toggleCardStatus(c); });
            actions.appendChild(blockBtn);

            var archBtn = el('button', 'icon-btn');
            archBtn.title = c.status === 'archived' ? 'Reativar cartão' : 'Arquivar cartão';
            archBtn.setAttribute('aria-label', archBtn.title);
            archBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h18v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8z"/><path d="M2 4h20v4H2zM10 12h4"/></svg>';
            archBtn.addEventListener('click', function () { archiveCard(c); });
            actions.appendChild(archBtn);

            var delBtn = el('button', 'icon-btn danger');
            delBtn.title = 'Excluir cartão';
            delBtn.setAttribute('aria-label', 'Excluir cartão');
            delBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>';
            delBtn.addEventListener('click', function () { deleteCard(c); });
            actions.appendChild(delBtn);

            tile.appendChild(actions);

            // adicionais
            (c.additional || []).forEach(function (a) {
                var add = el('div', 'additional-card');
                add.appendChild(el('span', 'add-dots', '•••• ' + (a.last4 || '')));
                var nm = el('strong', '', a.name);
                add.appendChild(nm);
                var chipA = el('span', 'chip' + (a.status === 'blocked' ? ' warn' : ''), a.type_label);
                add.appendChild(chipA);
                tile.appendChild(add);
            });

            grid.appendChild(tile);
        });
    }

    function bankCardEl(c) {
        var card = el('div', 'bank-card');
        if (isLightColor(c.color)) { card.classList.add('light-color'); }
        if (c.status === 'blocked') { card.classList.add('is-blocked'); }
        if (c.status === 'archived') { card.classList.add('is-archived'); }
        card.style.setProperty('--card-color', c.color);

        var top = el('div', 'bc-top');
        top.appendChild(el('span', 'bc-brand', (c.institution || c.brand || '').toUpperCase()));
        top.appendChild(el('span', 'bc-type', c.type_label));
        card.appendChild(top);

        card.appendChild(el('div', 'bc-chip'));

        var num = el('div', 'bc-number');
        num.textContent = '•••• ' + (c.last4 || '••••');
        card.appendChild(num);

        var nm = el('div', 'bc-name');
        nm.textContent = c.name + (c.nickname ? ' · ' + c.nickname : '');
        card.appendChild(nm);

        if (c.is_credit_like) {
            var meta = el('div', 'bc-meta');
            var row1 = el('div');
            var s1 = el('small', '', 'Fatura atual');
            var st1 = el('strong', '', c.current_invoice ? FL.money(c.current_invoice.total) : FL.money(0));
            row1.appendChild(s1); row1.appendChild(st1);
            var row2 = el('div');
            var s2 = el('small', '', 'Vence');
            var st2 = el('strong', '', c.current_invoice ? FL.brDate(c.current_invoice.due_date) : (c.due_day ? 'dia ' + c.due_day : '—'));
            row2.appendChild(s2); row2.appendChild(st2);
            meta.appendChild(row1); meta.appendChild(row2);
            card.appendChild(meta);

            if (c.limit_total > 0) {
                var lim = el('div', 'bc-limit');
                var bar = el('div', 'bc-limit-bar');
                var fill = el('div');
                fill.style.width = Math.min(100, c.limit_used_pct) + '%';
                bar.appendChild(fill);
                lim.appendChild(bar);
                var labels = el('div', 'bc-limit-labels');
                var l1 = el('span', '', 'Usado ' + FL.money(c.limit_used));
                var l2 = el('span', '', FL.pct(c.limit_used_pct));
                labels.appendChild(l1); labels.appendChild(l2);
                lim.appendChild(labels);
                card.appendChild(lim);
            }
        } else if ((c.balances || []).length) {
            var metaB = el('div', 'bc-meta');
            var rowB = el('div');
            var sb = el('small', '', c.balances.length === 1 ? c.balances[0].label : c.balances.length + ' benefícios');
            var stb = el('strong', '', FL.money(c.balances.reduce(function (t, b) { return t + b.balance; }, 0)));
            rowB.appendChild(sb); rowB.appendChild(stb);
            metaB.appendChild(rowB);
            card.appendChild(metaB);
        }

        var overlay = el('div', 'bc-overlay');
        overlay.appendChild(el('span', 'badge', c.status === 'blocked' ? 'Bloqueado' : 'Arquivado'));
        card.appendChild(overlay);

        card.setAttribute('role', 'button');
        card.tabIndex = 0;
        card.addEventListener('click', function () { cardDetail(c); });
        card.addEventListener('keydown', function (e) { if (e.key === 'Enter') { cardDetail(c); } });
        return card;
    }

    // ------------------------------------------------------------
    // Modal de cartão (campos condicionais por tipo)
    // ------------------------------------------------------------
    function openCardForm(card) {
        var isEdit = !!card;
        var creditRoots = state.cards.filter(function (c) {
            return c.type === 'credit' || c.type === 'multiple';
        });

        var body =
            fieldHtml('cf-name', 'Nome do cartão *', inputHtml('cf-name', 'text', card ? card.name : '', 'maxlength="80" placeholder="Ex.: Nubank, Itaú, Alelo"')) +
            '<div class="modal-row">' +
            fieldHtml('cf-nickname', 'Apelido', inputHtml('cf-nickname', 'text', card ? (card.nickname || '') : '', 'maxlength="40" placeholder="Ex.: cartão da empresa"')) +
            fieldHtml('cf-institution', 'Instituição', inputHtml('cf-institution', 'text', card ? (card.institution || '') : '', 'maxlength="80" placeholder="Banco/emissor"')) +
            '</div>' +
            '<div class="modal-row">' +
            fieldHtml('cf-type', 'Tipo *', selectHtml('cf-type', [['credit', 'Crédito'], ['debit', 'Débito'], ['benefit', 'Benefício'], ['multiple', 'Múltiplo (crédito + débito)']], card ? card.type : 'credit')) +
            fieldHtml('cf-brand', 'Bandeira', selectHtml('cf-brand', BRANDS.map(function (b) { return [b, b.charAt(0).toUpperCase() + b.slice(1)]; }), card ? (card.brand || '') : '', '—')) +
            '</div>' +
            '<div class="modal-row">' +
            fieldHtml('cf-last4', 'Últimos 4 dígitos', inputHtml('cf-last4', 'text', card ? (card.last4 || '') : '', 'maxlength="4" inputmode="numeric" pattern="[0-9]{4}" placeholder="Ex.: 4521"') +
                '<div class="hint">Apenas os 4 últimos números — nunca o número completo.</div>') +
            fieldHtml('cf-color', 'Cor do cartão', inputHtml('cf-color', 'color', card ? card.color : '#7C3AED')) +
            '</div>' +
            '<div class="cf-credit">' +
            '<div class="modal-row">' +
            fieldHtml('cf-limit', 'Limite total (R$)', inputHtml('cf-limit', 'number', card ? card.limit_total : '', 'min="0" step="0.01" placeholder="0,00"')) +
            fieldHtml('cf-fee', 'Anuidade (R$)', inputHtml('cf-fee', 'number', card ? (card.annual_fee || '') : '', 'min="0" step="0.01" placeholder="0,00"')) +
            '</div>' +
            '<div class="modal-row">' +
            fieldHtml('cf-closing', 'Dia de fechamento (1-28)', inputHtml('cf-closing', 'number', card ? (card.closing_day || 20) : 20, 'min="1" max="28"')) +
            fieldHtml('cf-due', 'Dia de vencimento (1-28)', inputHtml('cf-due', 'number', card ? (card.due_day || 27) : 27, 'min="1" max="28"')) +
            '</div>' +
            fieldHtml('cf-alert', 'Alertar uso de limite a partir de', selectHtml('cf-alert', [[50, '50%'], [60, '60%'], [70, '70%'], [80, '80% (padrão)'], [90, '90%'], [100, '100%']], card ? card.limit_alert_pct : 80)) +
            '</div>' +
            '<div class="cf-debit">' +
            fieldHtml('cf-account', 'Conta associada', selectHtml('cf-account', state.accounts.map(function (a) { return [a.id, a.name]; }), card ? (card.account_id || '') : '', 'Nenhuma')) +
            '</div>' +
            (!isEdit && creditRoots.length ? fieldHtml('cf-parent', 'Cartão adicional de', selectHtml('cf-parent', creditRoots.map(function (c) { return [c.id, c.name]; }), '', 'Nenhum — cartão principal')) : '') +
            '<div class="cf-benefit">' +
            '<div class="field"><label>Benefícios deste cartão</label><div class="chips cf-kinds">' +
            BENEFIT_KINDS.map(function (k) {
                var checked = card && (card.balances || []).some(function (b) { return b.benefit_kind === k[0]; }) ? ' checked' : '';
                return '<label class="chip" style="cursor:pointer"><input type="checkbox" class="cf-kind" value="' + k[0] + '"' + checked + ' style="accent-color:var(--primary)"> ' + k[1] + '</label>';
            }).join('') +
            '</div><div class="hint">Cada benefício tem saldo independente. Os créditos mensais podem ser configurados depois, na aba Benefícios.</div></div>' +
            '</div>' +
            (isEdit ? fieldHtml('cf-status', 'Status', selectHtml('cf-status', [['active', 'Ativo'], ['blocked', 'Bloqueado'], ['archived', 'Arquivado']], card.status)) : '') +
            fieldHtml('cf-notes', 'Observações', inputHtml('cf-notes', 'text', card ? (card.notes || '') : '', 'maxlength="500"')) +
            '<p class="form-error" hidden></p>';

        var modal = customModal(isEdit ? 'Editar cartão' : 'Novo cartão', body);

        function toggleSections() {
            var t = modal.querySelector('#cf-type').value;
            modal.querySelector('.cf-credit').style.display = (t === 'credit' || t === 'multiple') ? '' : 'none';
            modal.querySelector('.cf-debit').style.display = (t === 'debit' || t === 'multiple') ? '' : 'none';
            modal.querySelector('.cf-benefit').style.display = (t === 'benefit' || t === 'multiple') ? '' : 'none';
        }
        modal.querySelector('#cf-type').addEventListener('change', toggleSections);
        toggleSections();

        modal.querySelector('[data-save]').addEventListener('click', function () {
            var saveBtn = modal.querySelector('[data-save]');
            var payload = {
                name: modal.querySelector('#cf-name').value.trim(),
                nickname: modal.querySelector('#cf-nickname').value.trim(),
                institution: modal.querySelector('#cf-institution').value.trim(),
                brand: modal.querySelector('#cf-brand').value,
                type: modal.querySelector('#cf-type').value,
                last4: modal.querySelector('#cf-last4').value.trim(),
                color: modal.querySelector('#cf-color').value,
                limit_total: modal.querySelector('#cf-limit').value || '0',
                annual_fee: modal.querySelector('#cf-fee').value || '0',
                closing_day: modal.querySelector('#cf-closing').value,
                due_day: modal.querySelector('#cf-due').value,
                limit_alert_pct: modal.querySelector('#cf-alert').value,
                account_id: modal.querySelector('#cf-account').value || null,
                notes: modal.querySelector('#cf-notes').value.trim(),
                benefit_kinds: Array.prototype.slice.call(modal.querySelectorAll('.cf-kind:checked')).map(function (k) { return k.value; }),
            };
            if (isEdit) { payload.status = modal.querySelector('#cf-status').value; }
            else {
                var parent = modal.querySelector('#cf-parent');
                if (parent && parent.value) { payload.parent_card_id = parent.value; }
            }
            if (payload.name === '') { modalError(modal, 'Informe o nome do cartão.'); return; }
            if (payload.last4 !== '' && !/^\d{4}$/.test(payload.last4)) { modalError(modal, 'Os últimos 4 dígitos devem ser exatamente 4 números.'); return; }

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner"></span> Salvando…';
            var req = isEdit
                ? FL.api(API, { method: 'PUT', body: Object.assign({ id: card.id }, payload) })
                : FL.api(API, { method: 'POST', body: payload });
            req.then(function (r) {
                if (r.data && r.data.ok) {
                    FL.closeModal();
                    FL.toastSuccess(isEdit ? 'Cartão atualizado.' : 'Cartão cadastrado com sucesso!');
                    loadOverview();
                } else {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Salvar';
                    modalError(modal, (r.data && r.data.error) || 'Não foi possível salvar.');
                }
            });
        });
    }

    function cardDetail(c) {
        var rows = [
            ['Tipo', c.type_label + (c.institution ? ' · ' + c.institution : '')],
            ['Número', '•••• ' + (c.last4 || '—')],
            ['Status', { active: 'Ativo', blocked: 'Bloqueado', archived: 'Arquivado' }[c.status] || c.status],
        ];
        if (c.is_credit_like) {
            rows.push(['Limite total', FL.money(c.limit_total)]);
            rows.push(['Utilizado', FL.money(c.limit_used) + ' (' + FL.pct(c.limit_used_pct) + ')']);
            rows.push(['Disponível', FL.money(c.limit_available)]);
            rows.push(['Ciclo', 'fecha dia ' + c.closing_day + ' · vence dia ' + c.due_day]);
            if (c.annual_fee !== null) { rows.push(['Anuidade', FL.money(c.annual_fee)]); }
            if (c.current_invoice) {
                rows.push(['Fatura atual', FL.money(c.current_invoice.total) + ' — vence ' + FL.brDate(c.current_invoice.due_date)]);
            }
            if (c.next_due) {
                rows.push(['A pagar (próx. faturas)', FL.money(c.next_due.total) + ' — ' + FL.brDate(c.next_due.due_date)]);
            }
        }
        (c.balances || []).forEach(function (b) {
            rows.push([b.label, 'Saldo ' + FL.money(b.balance) + (b.next_credit ? ' · próximo crédito ' + FL.brDate(b.next_credit) : '')]);
        });
        if (c.notes) { rows.push(['Observações', c.notes]); }

        var body = '<div class="kv-list">' + rows.map(function (r) {
            return '<div class="kv"><span>' + FL.esc(r[0]) + '</span><strong>' + FL.esc(r[1]) + '</strong></div>';
        }).join('') + '</div>';

        var overlay = $('modal-overlay');
        var modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML =
            '<div class="modal-head"><h3>' + FL.esc(c.name) + '</h3>' +
            '<button type="button" class="modal-close" aria-label="Fechar">×</button></div>' +
            '<div class="modal-body">' + body + '</div>' +
            '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Fechar</button>' +
            '<button type="button" class="btn btn-primary" data-edit>Editar</button></div>';
        overlay.innerHTML = '';
        overlay.appendChild(modal);
        overlay.hidden = false;
        modal.querySelector('.modal-close').addEventListener('click', FL.closeModal);
        modal.querySelector('[data-close]').addEventListener('click', FL.closeModal);
        modal.querySelector('[data-edit]').addEventListener('click', function () { FL.closeModal(); openCardForm(c); });
    }

    function toggleCardStatus(c) {
        var action = c.status === 'blocked' ? 'unblock' : 'block';
        var msg = c.status === 'blocked'
            ? 'Desbloquear o cartão "' + c.name + '"?'
            : 'Bloquear o cartão "' + c.name + '"? Compras novas ficam bloqueadas (histórico é preservado).';
        FL.confirm(c.status === 'blocked' ? 'Desbloquear cartão' : 'Bloquear cartão', msg)
            .then(function (ok) {
                if (!ok) { return; }
                FL.api(API, { method: 'POST', body: { action: action, id: c.id } }).then(function (r) {
                    if (r.data && r.data.ok) {
                        FL.toastSuccess(action === 'block' ? 'Cartão bloqueado.' : 'Cartão desbloqueado.');
                        loadOverview();
                    } else { FL.toastError((r.data && r.data.error) || 'Não foi possível atualizar.'); }
                });
            });
    }

    function archiveCard(c) {
        var toArchive = c.status !== 'archived';
        var msg = toArchive
            ? 'Arquivar o cartão "' + c.name + '"? Ele sai da visão principal, mas o histórico é mantido.'
            : 'Reativar o cartão "' + c.name + '"?';
        FL.confirm(toArchive ? 'Arquivar cartão' : 'Reativar cartão', msg)
            .then(function (ok) {
                if (!ok) { return; }
                FL.api(API, { method: 'POST', body: { action: toArchive ? 'archive' : 'unblock', id: c.id } })
                    .then(function (r) {
                        if (r.data && r.data.ok) {
                            FL.toastSuccess(toArchive ? 'Cartão arquivado.' : 'Cartão reativado.');
                            loadOverview();
                        } else { FL.toastError((r.data && r.data.error) || 'Não foi possível atualizar.'); }
                    });
            });
    }

    function deleteCard(c) {
        FL.confirm('Excluir cartão',
            'Excluir o cartão "' + c.name + '"? Compras, parcelas e faturas dele também serão removidas. ' +
            'Pagamentos de fatura já lançados nas suas contas permanecem no histórico financeiro. Esta ação não pode ser desfeita.', true)
            .then(function (ok) {
                if (!ok) { return; }
                FL.api(API + '?id=' + encodeURIComponent(c.id), { method: 'DELETE' }).then(function (r) {
                    if (r.data && r.data.ok) {
                        FL.toastSuccess('Cartão excluído.');
                        loadOverview();
                    } else { FL.toastError((r.data && r.data.error) || 'Não foi possível excluir.'); }
                });
            });
    }

    // ------------------------------------------------------------
    // Compras
    // ------------------------------------------------------------
    function loadPurchases() {
        var params = [];
        var cardId = $('p-filter-card').value;
        var method = $('p-filter-method').value;
        var month = $('p-filter-month').value;
        var q = $('p-filter-q').value.trim();
        if (cardId) { params.push('card_id=' + encodeURIComponent(cardId)); }
        if (method) { params.push('method=' + encodeURIComponent(method)); }
        if (month) { params.push('month=' + encodeURIComponent(month)); }
        if (q) { params.push('q=' + encodeURIComponent(q)); }

        FL.api(API_PURCHASES + '?' + params.join('&')).then(function (r) {
            if (!r.data || !r.data.ok) {
                FL.toastError((r.data && r.data.error) || 'Erro ao carregar compras.');
                return;
            }
            renderPurchases(r.data.purchases || []);
        });
    }

    function renderPurchases(rows) {
        var wrap = $('purchases-list');
        wrap.innerHTML = '';
        var list = el('div', 'purchase-list');

        if (!rows.length) {
            var p = el('p', 'empty', 'Nenhuma compra registrada com os filtros atuais.');
            p.style.padding = '26px 10px';
            wrap.appendChild(p);
        } else {
            rows.forEach(function (p) { list.appendChild(purchaseRow(p)); });
            wrap.appendChild(list);
        }
        $('purchases-count').textContent = rows.length + ' compra(s)';
    }

    function purchaseRow(p) {
        var row = el('div', 'purchase-row');

        var icon = el('span', 'pr-icon tint-' + p.method);
        icon.innerHTML = METHOD_ICON[p.method] || METHOD_ICON.credit;
        row.appendChild(icon);

        var info = el('span', 'pr-info');
        var strong = el('strong', '', p.description);
        info.appendChild(strong);
        var smallText = p.card_name + (p.is_additional ? ' (adicional de ' + p.parent_name + ')' : '') +
            ' · ' + FL.brDate(p.date) +
            (p.category_name ? ' · ' + p.category_name : '') +
            (p.store ? ' · ' + p.store : '');
        var small = el('small', '', smallText);
        info.appendChild(small);
        row.appendChild(info);

        var amount = el('span', 'pr-amount');
        var amtStrong = el('strong', '', FL.money(p.amount));
        amount.appendChild(amtStrong);
        if (p.installments_total > 1) {
            amount.appendChild(el('small', '', p.installments_total + '× de ' + FL.money(p.installment_amount) +
                (p.pending_installments !== null ? ' · faltam ' + p.pending_installments : '')));
        } else {
            amount.appendChild(el('small', '', p.method_label));
        }
        row.appendChild(amount);

        var actions = el('span', 'pr-actions');
        var viewBtn = el('button', 'icon-btn');
        viewBtn.title = 'Ver parcelas';
        viewBtn.setAttribute('aria-label', 'Ver parcelas de ' + p.description);
        viewBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>';
        viewBtn.addEventListener('click', function () { purchaseDetail(p.id); });
        actions.appendChild(viewBtn);

        var editBtn = el('button', 'icon-btn');
        editBtn.title = 'Editar';
        editBtn.setAttribute('aria-label', 'Editar compra');
        editBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
        editBtn.addEventListener('click', function () { openPurchaseForm(p); });
        actions.appendChild(editBtn);

        var delBtn = el('button', 'icon-btn danger');
        delBtn.title = 'Excluir';
        delBtn.setAttribute('aria-label', 'Excluir compra');
        delBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>';
        delBtn.addEventListener('click', function () {
            FL.confirm('Excluir compra', 'Excluir "' + p.description + '" (' + FL.money(p.amount) + ')? As parcelas e efeitos em faturas/limites serão removidos.', true)
                .then(function (ok) {
                    if (!ok) { return; }
                    FL.api(API_PURCHASES + '?id=' + encodeURIComponent(p.id), { method: 'DELETE' }).then(function (r) {
                        if (r.data && r.data.ok) { FL.toastSuccess('Compra excluída.'); loadOverview(); loadPurchases(); }
                        else { FL.toastError((r.data && r.data.error) || 'Não foi possível excluir.'); }
                    });
                });
        });
        actions.appendChild(delBtn);
        row.appendChild(actions);
        return row;
    }

    function openPurchaseForm(purchase, presetCard) {
        var isEdit = !!purchase;
        var cards = purchasableCards();
        if (!cards.length) {
            FL.toastError('Cadastre um cartão ativo antes de registrar compras.');
            return;
        }
        var editCard = isEdit ? (function () {
            var rootId = null;
            var found = null;
            cards.forEach(function (c) {
                if (String(c.id) === String(purchase.card_id)) { found = c; }
                (c.additional || []).forEach(function (a) { if (String(a.id) === String(purchase.card_id)) { found = a; rootId = c.id; } });
            });
            return found;
        })() : null;

        var body =
            fieldHtml('pf-card', 'Cartão *', selectHtml('pf-card', cards.map(function (c) {
                return [c.id, c.name + (c.type === 'benefit' ? ' (benefício)' : c.type === 'debit' ? ' (débito)' : '')];
            }), isEdit && editCard ? editCard.id : (presetCard ? presetCard.id : cards[0].id))) +
            fieldHtml('pf-description', 'Descrição *', inputHtml('pf-description', 'text', isEdit ? purchase.description : '', 'maxlength="255" placeholder="Ex.: Notebook, mercado, presente"')) +
            '<div class="modal-row">' +
            fieldHtml('pf-amount', 'Valor total (R$) *', inputHtml('pf-amount', 'number', isEdit ? purchase.amount : '', 'min="0.01" step="0.01" placeholder="0,00"')) +
            fieldHtml('pf-date', 'Data da compra *', inputHtml('pf-date', 'date', isEdit ? purchase.date : new Date().toISOString().slice(0, 10))) +
            '</div>' +
            fieldHtml('pf-method', 'Forma de pagamento *', selectHtml('pf-method', [['credit', 'Crédito'], ['debit', 'Débito'], ['benefit', 'Benefício']], isEdit ? purchase.method : (presetCard && presetCard.type === 'benefit' ? 'benefit' : (presetCard && presetCard.type === 'debit' ? 'debit' : 'credit')))) +
            '<div class="pf-credit-wrap">' +
            fieldHtml('pf-installments', 'Número de parcelas', inputHtml('pf-installments', 'number', isEdit ? purchase.installments_total : 1, 'min="1" max="48" step="1"') +
                '<div class="pf-preview hint" id="pf-preview"></div><div class="hint">1 = compra à vista. O sistema gera automaticamente uma parcela por fatura.</div>') +
            '</div>' +
            '<div class="pf-benefit-wrap" style="display:none">' +
            fieldHtml('pf-balance', 'Saldo do benefício', '<select class="input" id="pf-balance"></select>' +
                '<div class="hint">O valor é debitado do saldo escolhido — nunca da sua conta bancária.</div>') +
            '</div>' +
            '<div class="modal-row">' +
            fieldHtml('pf-category', 'Categoria', selectHtml('pf-category', state.categories.map(function (c) { return [c.id, (c.icon ? c.icon + ' ' : '') + c.name]; }), isEdit ? (purchase.category_id || '') : '', '—')) +
            fieldHtml('pf-store', 'Estabelecimento', inputHtml('pf-store', 'text', isEdit ? (purchase.store || '') : '', 'maxlength="120" placeholder="Opcional"')) +
            '</div>' +
            '<div class="modal-row">' +
            fieldHtml('pf-interest', 'Juros embutidos (R$)', inputHtml('pf-interest', 'number', isEdit ? (purchase.interest || '') : '', 'min="0" step="0.01" placeholder="0,00"')) +
            fieldHtml('pf-fees', 'Taxas (R$)', inputHtml('pf-fees', 'number', isEdit ? (purchase.fees || '') : '', 'min="0" step="0.01" placeholder="0,00"')) +
            '</div>' +
            fieldHtml('pf-notes', 'Observação', inputHtml('pf-notes', 'text', isEdit ? (purchase.notes || '') : '', 'maxlength="500"')) +
            '<p class="form-error" hidden></p>';

        var modal = customModal(isEdit ? 'Editar compra' : 'Registrar compra', body, isEdit ? 'Salvar alterações' : 'Registrar compra');

        function currentCard() { return cardById(modal.querySelector('#pf-card').value) || cards[0]; }
        function cardRoot(c) {
            if (c && c.parent_card_id !== undefined) {
                var root = state.cards.find(function (rc) { return String(rc.id) === String(c.parent_card_id); });
                if (root) { return root; }
            }
            return c;
        }

        function refreshMethodOptions() {
            var c = cardRoot(currentCard());
            var sel = modal.querySelector('#pf-method');
            var cur = sel.value;
            var type = c ? c.type : 'credit';
            var opts = [];
            if (type === 'credit' || type === 'multiple') { opts.push(['credit', 'Crédito']); }
            if (type === 'debit' || type === 'multiple') { opts.push(['debit', 'Débito']); }
            if (type === 'benefit' || type === 'multiple') { opts.push(['benefit', 'Benefício']); }
            sel.innerHTML = opts.map(function (o) {
                return '<option value="' + o[0] + '"' + (o[0] === cur ? ' selected' : '') + '>' + o[1] + '</option>';
            }).join('');
            if (!isEdit) { sel.value = opts[0][0]; }
            toggleSections();
        }

        function toggleSections() {
            var method = modal.querySelector('#pf-method').value;
            modal.querySelector('.pf-credit-wrap').style.display = method === 'credit' ? '' : 'none';
            modal.querySelector('.pf-benefit-wrap').style.display = method === 'benefit' ? '' : 'none';
            if (method === 'benefit') { refreshBalances(); }
            updatePreview();
        }

        function refreshBalances() {
            var c = cardRoot(currentCard());
            var sel = modal.querySelector('#pf-balance');
            var balances = (c && c.balances) || [];
            sel.innerHTML = balances.length
                ? balances.map(function (b) {
                    return '<option value="' + b.id + '">' + FL.esc(b.label) + ' — saldo ' + FL.esc(FL.money(b.balance)) + '</option>';
                }).join('')
                : '<option value="">— sem saldos cadastrados neste cartão —</option>';
        }

        function updatePreview() {
            var preview = modal.querySelector('#pf-preview');
            if (!preview) { return; }
            if (modal.querySelector('#pf-method').value !== 'credit') { preview.textContent = ''; return; }
            var amount = parseFloat(modal.querySelector('#pf-amount').value) || 0;
            var n = parseInt(modal.querySelector('#pf-installments').value, 10) || 1;
            if (amount > 0 && n > 0) {
                preview.textContent = n === 1
                    ? 'Compra à vista: ' + FL.money(amount)
                    : n + '× de ' + FL.money(Math.round(amount / n * 100) / 100) + ' (total ' + FL.money(amount) + ')';
            } else { preview.textContent = ''; }
        }

        modal.querySelector('#pf-card').addEventListener('change', refreshMethodOptions);
        modal.querySelector('#pf-method').addEventListener('change', toggleSections);
        ['pf-amount', 'pf-installments'].forEach(function (id) {
            modal.querySelector('#' + id).addEventListener('input', updatePreview);
        });
        refreshMethodOptions();
        updatePreview();

        modal.querySelector('[data-save]').addEventListener('click', function () {
            var saveBtn = modal.querySelector('[data-save]');
            var payload = {
                card_id: modal.querySelector('#pf-card').value,
                description: modal.querySelector('#pf-description').value.trim(),
                amount: modal.querySelector('#pf-amount').value,
                date: modal.querySelector('#pf-date').value,
                method: modal.querySelector('#pf-method').value,
                installments: modal.querySelector('#pf-installments').value || '1',
                category_id: modal.querySelector('#pf-category').value || null,
                store: modal.querySelector('#pf-store').value.trim(),
                interest: modal.querySelector('#pf-interest').value || '0',
                fees: modal.querySelector('#pf-fees').value || '0',
                notes: modal.querySelector('#pf-notes').value.trim(),
            };
            if (payload.method === 'benefit') { payload.balance_id = modal.querySelector('#pf-balance').value || null; }
            if (isEdit) { payload.installments_total = payload.installments; }

            if (payload.description === '') { modalError(modal, 'Informe a descrição.'); return; }
            if (!payload.amount || parseFloat(payload.amount) <= 0) { modalError(modal, 'Informe um valor válido.'); return; }
            if (!payload.date) { modalError(modal, 'Informe a data da compra.'); return; }
            if (payload.method === 'benefit' && !payload.balance_id) { modalError(modal, 'Este cartão não possui saldos de benefício. Configure na aba Benefícios.'); return; }

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner"></span> Salvando…';
            var req = isEdit
                ? FL.api(API_PURCHASES, { method: 'PUT', body: Object.assign({ id: purchase.id }, payload) })
                : FL.api(API_PURCHASES, { method: 'POST', body: payload });
            req.then(function (r) {
                if (r.data && r.data.ok) {
                    FL.closeModal();
                    FL.toastSuccess(isEdit ? 'Compra atualizada.' : 'Compra registrada!' +
                        (!isEdit && r.data.installments > 1 ? ' ' + r.data.installments + ' parcelas geradas.' : ''));
                    loadOverview();
                    loadPurchases();
                } else {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Salvar';
                    modalError(modal, (r.data && r.data.error) || 'Não foi possível salvar.');
                }
            });
        });
    }

    function purchaseDetail(id) {
        FL.api(API_PURCHASES + '?action=detail&id=' + encodeURIComponent(id), { silent: true })
            .then(function (r) {
                if (!r.data || !r.data.ok) { FL.toastError('Compra não encontrada.'); return; }
                var p = r.data.purchase;
                var rows = '<div class="kv-list">' +
                    '<div class="kv"><span>Cartão</span><strong>' + FL.esc(p.card_name) + '</strong></div>' +
                    '<div class="kv"><span>Valor total</span><strong>' + FL.esc(FL.money(p.amount)) + '</strong></div>' +
                    (p.installments_total > 1 ? '<div class="kv"><span>Parcelas</span><strong>' + p.installments_total + '× de ' + FL.esc(FL.money(p.installment_amount)) + '</strong></div>' : '') +
                    '</div>';
                var listHtml = '';
                (p.installments_list || []).forEach(function (i) {
                    listHtml += '<div class="installment-row' + (i.status === 'paid' ? ' done' : '') + '">' +
                        '<span class="ins-num">' + i.number + '/' + i.total + ' — ' + FL.esc(FL.brDate(i.due_date)) + '</span>' +
                        '<span class="ins-amount">' + FL.esc(FL.money(i.status === 'paid' ? i.paid_amount : i.amount)) +
                        (i.status === 'paid' ? ' ✓' : '') + '</span></div>';
                });

                var overlay = $('modal-overlay');
                var modal = document.createElement('div');
                modal.className = 'modal';
                modal.innerHTML =
                    '<div class="modal-head"><h3>' + FL.esc(p.description) + '</h3>' +
                    '<button type="button" class="modal-close" aria-label="Fechar">×</button></div>' +
                    '<div class="modal-body">' + rows +
                    '<h4 style="font-size:.8125rem;margin:14px 0 8px">Linha do tempo das parcelas</h4>' +
                    '<div class="installment-list">' + (listHtml || '<p class="muted small">Sem parcelas (compra no débito/benefício).</p>') + '</div>' +
                    '</div>' +
                    '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Fechar</button></div>';
                overlay.innerHTML = '';
                overlay.appendChild(modal);
                overlay.hidden = false;
                modal.querySelector('.modal-close').addEventListener('click', FL.closeModal);
                modal.querySelector('[data-close]').addEventListener('click', FL.closeModal);
            });
    }

    // ------------------------------------------------------------
    // Faturas
    // ------------------------------------------------------------
    function loadInvoices() {
        var cardId = $('inv-filter-card').value;
        var url = API_INVOICES + (cardId ? '?card_id=' + encodeURIComponent(cardId) : '');
        FL.api(url).then(function (r) {
            if (!r.data || !r.data.ok) {
                FL.toastError((r.data && r.data.error) || 'Erro ao carregar faturas.');
                return;
            }
            renderInvoices((r.data.invoices || []).filter(function (i) { return i.status !== 'empty'; }));
        });
    }

    function renderInvoices(invoices) {
        var wrap = $('invoices-list');
        wrap.innerHTML = '';
        if (!invoices.length) {
            var p = el('p', 'empty', 'Nenhuma fatura registrada ainda. As faturas são criadas automaticamente quando você registra compras no crédito.');
            p.style.margin = '10px 2px';
            wrap.appendChild(p);
            return;
        }

        var cardsByColor = {};
        state.cards.forEach(function (c) { cardsByColor[c.id] = c; });

        invoices.forEach(function (i) {
            var item = el('div', 'invoice-item');

            var head = el('div', 'inv-head');
            var title = el('div', 'inv-title');
            var dot = el('span', 'inv-dot');
            dot.style.background = i.card_color || 'var(--primary)';
            title.appendChild(dot);
            var titleText = el('span');
            var strong = el('strong', '', i.card_name);
            var small = el('small', '', 'Fecha ' + FL.brDate(i.period_end) + ' · período ' + FL.brDate(i.period_start) + ' a ' + FL.brDate(i.period_end));
            titleText.appendChild(strong);
            titleText.appendChild(small);
            title.appendChild(titleText);
            head.appendChild(title);
            head.appendChild(el('span', 'badge status-' + i.status, i.status_label));
            item.appendChild(head);

            var body = el('div', 'inv-body');
            var amounts = el('div', 'inv-amounts');

            function invAmount(label, value, cls) {
                var d = el('div', 'inv-amount');
                d.appendChild(el('small', '', label));
                d.appendChild(el('strong', (cls || ''), value));
                return d;
            }
            amounts.appendChild(invAmount('Total da fatura', FL.money(i.total)));
            amounts.appendChild(invAmount('Vencimento', FL.brDate(i.due_date)));
            if (i.paid_total > 0) { amounts.appendChild(invAmount('Pago', FL.money(i.paid_total), 'text-success')); }
            if (i.remaining > 0) { amounts.appendChild(invAmount('Restante', FL.money(i.remaining), 'text-danger')); }
            amounts.appendChild(invAmount('Pagamento mínimo', FL.money(i.minimum_payment)));
            body.appendChild(amounts);

            var actions = el('div', 'inv-actions');
            if (i.remaining > 0.005) {
                var payBtn = el('button', 'btn btn-primary btn-sm', 'Pagar fatura');
                payBtn.addEventListener('click', function () { openPayModal(i); });
                actions.appendChild(payBtn);
                if (i.status === 'open') {
                    var closeBtn = el('button', 'btn btn-ghost btn-sm', 'Fechar fatura');
                    closeBtn.addEventListener('click', function () { closeInvoice(i); });
                    actions.appendChild(closeBtn);
                }
            } else {
                actions.appendChild(el('span', 'chip good', 'Fatura quitada'));
            }
            body.appendChild(actions);
            item.appendChild(body);

            if (i.total > 0 && i.paid_total > 0) {
                var bar = el('div', 'bar');
                var fill = el('div', 'bar-fill success');
                fill.style.width = Math.min(100, (i.paid_total / i.total) * 100) + '%';
                bar.appendChild(fill);
                item.appendChild(bar);
            }
            wrap.appendChild(item);
        });
    }

    function openPayModal(invoice) {
        var body =
            '<div class="kv-list" style="margin-bottom:14px">' +
            '<div class="kv"><span>Cartão</span><strong>' + FL.esc(invoice.card_name) + '</strong></div>' +
            '<div class="kv"><span>Total</span><strong>' + FL.esc(FL.money(invoice.total)) + '</strong></div>' +
            '<div class="kv"><span>Restante</span><strong>' + FL.esc(FL.money(invoice.remaining)) + '</strong></div>' +
            '<div class="kv"><span>Vencimento</span><strong>' + FL.esc(FL.brDate(invoice.due_date)) + '</strong></div>' +
            '</div>' +
            fieldHtml('pay-amount', 'Valor do pagamento (R$) *', inputHtml('pay-amount', 'number', invoice.remaining, 'min="0.01" step="0.01"') +
                '<div class="hint">O valor sai da conta escolhida como uma despesa real — as parcelas são quitadas e o limite é liberado.</div>') +
            '<div class="modal-row">' +
            fieldHtml('pay-date', 'Data do pagamento *', inputHtml('pay-date', 'date', new Date().toISOString().slice(0, 10))) +
            fieldHtml('pay-account', 'Conta usada *', selectHtml('pay-account', state.accounts.map(function (a) {
                return [a.id, a.name + ' (' + FL.money(a.current_balance) + ')'];
            }), '', 'Escolha a conta')) +
            '</div>' +
            fieldHtml('pay-note', 'Observação (opcional)', inputHtml('pay-note', 'text', '', 'maxlength="255"')) +
            '<p class="form-error" hidden></p>';

        var modal = customModal('Pagar fatura — ' + invoice.card_name, body, 'Registrar pagamento');

        modal.querySelector('[data-save]').addEventListener('click', function () {
            var saveBtn = modal.querySelector('[data-save]');
            var payload = {
                action: 'pay',
                invoice_id: invoice.id,
                amount: modal.querySelector('#pay-amount').value,
                date: modal.querySelector('#pay-date').value,
                account_id: modal.querySelector('#pay-account').value,
                note: modal.querySelector('#pay-note').value.trim(),
            };
            if (!payload.amount || parseFloat(payload.amount) <= 0) { modalError(modal, 'Informe um valor válido.'); return; }
            if (!payload.account_id) { modalError(modal, 'Escolha a conta usada para o pagamento.'); return; }

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner"></span> Registrando…';
            FL.api(API_INVOICES, { method: 'POST', body: payload }).then(function (r) {
                if (r.data && r.data.ok) {
                    FL.closeModal();
                    FL.toastSuccess(r.data.kind === 'full' ? 'Fatura paga! Limite liberado.' : 'Pagamento parcial registrado.');
                    loadOverview();
                    loadInvoices();
                } else {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Registrar pagamento';
                    modalError(modal, (r.data && r.data.error) || 'Não foi possível registrar o pagamento.');
                }
            });
        });
    }

    function closeInvoice(invoice) {
        FL.confirm('Fechar fatura', 'Marcar a fatura de ' + invoice.card_name + ' (' + FL.money(invoice.total) + ') como fechada? Ela deixa de receber novas compras neste ciclo.')
            .then(function (ok) {
                if (!ok) { return; }
                FL.api(API_INVOICES, { method: 'POST', body: { action: 'close', id: invoice.id } }).then(function (r) {
                    if (r.data && r.data.ok) { FL.toastSuccess('Fatura fechada.'); loadInvoices(); }
                    else { FL.toastError((r.data && r.data.error) || 'Não foi possível fechar.'); }
                });
            });
    }

    // ------------------------------------------------------------
    // Parcelas futuras (timeline)
    // ------------------------------------------------------------
    function loadInstallments() {
        FL.api(API_PURCHASES + '?action=installments', { silent: true }).then(function (r) {
            if (!r.data || !r.data.ok) { FL.toastError('Erro ao carregar parcelas.'); return; }
            renderInstallments(r.data.timeline || []);
        });
    }

    function renderInstallments(groups) {
        var wrap = $('installments-timeline');
        wrap.innerHTML = '';
        if (!groups.length) {
            var p = el('p', 'empty', 'Nenhuma parcela pendente. Registre uma compra parcelada para ver a projeção aqui.');
            p.style.margin = '10px 2px';
            wrap.appendChild(p);
            $('installments-summary').textContent = 'Parcelas futuras comprometidas nos próximos meses.';
            return;
        }
        var total = 0;
        groups.forEach(function (g) { total += g.total; });
        $('installments-summary').textContent = 'Você tem ' + FL.money(total) + ' comprometidos em ' + groups.length + ' mês(es) de parcelas.';

        groups.forEach(function (g) {
            var group = el('div', 'timeline-group');
            var head = el('div', 'timeline-group-head');
            head.appendChild(el('strong', '', ymLabel(g.month)));
            head.appendChild(el('span', 'tg-total', FL.money(g.total)));
            group.appendChild(head);

            var items = el('div', 'timeline-items');
            g.items.forEach(function (it) {
                var item = el('div', 'timeline-item');
                var sub = el('div', 'sub-item');
                var icon = el('span', 'sub-icon', '💳');
                sub.appendChild(icon);
                var info = el('span', 'sub-info');
                info.appendChild(el('strong', '', it.description + ' — ' + it.number + '/' + it.total));
                info.appendChild(el('small', '', it.card_name + ' · vence ' + FL.brDate(it.due_date)));
                sub.appendChild(info);
                sub.appendChild(el('span', 'sub-amount', FL.money(it.amount)));
                item.appendChild(sub);
                items.appendChild(item);
            });
            group.appendChild(items);
            wrap.appendChild(group);
        });
    }

    // ------------------------------------------------------------
    // Assinaturas
    // ------------------------------------------------------------
    function loadSubscriptions() {
        FL.api(API_PURCHASES + '?action=recurring', { silent: true }).then(function (r) {
            if (!r.data || !r.data.ok) { FL.toastError('Erro ao carregar assinaturas.'); return; }
            renderSubscriptions(r.data.subscriptions || [], r.data.monthly_total || 0);
        });
    }

    function renderSubscriptions(subs, monthlyTotal) {
        var totalEl = $('subs-total');
        totalEl.innerHTML = '';
        totalEl.appendChild(el('strong', '', FL.money(monthlyTotal)));
        totalEl.appendChild(el('small', '', 'por mês em assinaturas'));

        var wrap = $('subscriptions-list');
        wrap.innerHTML = '';
        if (!subs.length) {
            var p = el('p', 'empty', 'Nenhuma assinatura cadastrada. Registre Netflix, academia, internet… para o FinLife considerar no planejamento.');
            p.style.margin = '10px 2px';
            wrap.appendChild(p);
            return;
        }
        var PERIOD = { monthly: 'mensal', weekly: 'semanal', yearly: 'anual' };
        subs.forEach(function (s) {
            var item = el('div', 'sub-item' + (s.is_active == 0 ? ' inactive' : ''));
            var icon = el('span', 'sub-icon', (s.category_icon || s.name || '✦').slice(0, 2));
            item.appendChild(icon);
            var info = el('span', 'sub-info');
            info.appendChild(el('strong', '', s.name));
            var dayTxt = s.charge_day ? ' · cobrança dia ' + s.charge_day : '';
            info.appendChild(el('small', '', (s.card_name || '') + ' · ' + (PERIOD[s.periodicity] || s.periodicity) + dayTxt + (s.is_active == 0 ? ' · pausada' : '')));
            item.appendChild(info);
            item.appendChild(el('span', 'sub-amount', FL.money(s.amount)));

            var actions = el('span', 'sub-actions');
            var editBtn = el('button', 'icon-btn');
            editBtn.title = 'Editar';
            editBtn.setAttribute('aria-label', 'Editar assinatura');
            editBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
            editBtn.addEventListener('click', function () { openSubscriptionForm(s); });
            actions.appendChild(editBtn);

            var delBtn = el('button', 'icon-btn danger');
            delBtn.title = 'Excluir';
            delBtn.setAttribute('aria-label', 'Excluir assinatura');
            delBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>';
            delBtn.addEventListener('click', function () {
                FL.confirm('Excluir assinatura', 'Excluir a assinatura "' + s.name + '" (' + FL.money(s.amount) + ')?', true)
                    .then(function (ok) {
                        if (!ok) { return; }
                        FL.api(API_PURCHASES + '?action=recurring&id=' + encodeURIComponent(s.id), { method: 'DELETE' })
                            .then(function (r) {
                                if (r.data && r.data.ok) { FL.toastSuccess('Assinatura excluída.'); loadSubscriptions(); loadOverview(); }
                                else { FL.toastError('Não foi possível excluir.'); }
                            });
                    });
            });
            actions.appendChild(delBtn);
            item.appendChild(actions);
            wrap.appendChild(item);
        });
    }

    function openSubscriptionForm(sub) {
        var isEdit = !!sub;
        var cards = purchasableCards(true).filter(function (c) { return c.status !== 'blocked'; });
        if (!cards.length) { FL.toastError('Cadastre um cartão antes de criar assinaturas.'); return; }

        FL.formModal(isEdit ? 'Editar assinatura' : 'Nova assinatura', [
            { name: 'name', label: 'Nome da assinatura *', type: 'text', value: isEdit ? sub.name : '', required: true, maxlength: 120, placeholder: 'Ex.: Netflix, Academia' },
            { name: 'amount', label: 'Valor (R$) *', type: 'number', value: isEdit ? sub.amount : '', required: true, min: 0.01, step: '0.01', placeholder: '0,00' },
            { name: 'card_id', label: 'Cartão *', type: 'select', value: isEdit ? String(sub.card_id) : String(cards[0].id), required: true, options: cards.map(function (c) { return { value: c.id, label: c.name }; }) },
            { name: 'periodicity', label: 'Periodicidade', type: 'select', value: isEdit ? sub.periodicity : 'monthly', options: [
                { value: 'monthly', label: 'Mensal' }, { value: 'weekly', label: 'Semanal' }, { value: 'yearly', label: 'Anual' },
            ] },
            { name: 'category_id', label: 'Categoria', type: 'select', value: isEdit ? (sub.category_id ? String(sub.category_id) : '') : '', options: state.categories.map(function (c) { return { value: c.id, label: (c.icon ? c.icon + ' ' : '') + c.name }; }) },
            { name: 'charge_day', label: 'Dia de cobrança (1-28)', type: 'number', value: isEdit ? (sub.charge_day || '') : '5', min: 1, max: 28, hint: 'Aparece no calendário financeiro.' },
            { name: 'start_date', label: 'Início', type: 'date', value: isEdit ? sub.start_date : new Date().toISOString().slice(0, 10), required: true },
            { name: 'end_date', label: 'Fim (opcional)', type: 'date', value: isEdit ? (sub.end_date || '') : '' },
            { name: 'status', label: 'Situação', type: 'select', value: isEdit ? (Number(sub.is_active) ? 'active' : 'paused') : 'active', options: [
                { value: 'active', label: 'Ativa' }, { value: 'paused', label: 'Pausada' },
            ] },
        ], function (values, helpers) {
            var payload = {
                action: 'recurring',
                name: values.name,
                amount: values.amount,
                card_id: values.card_id,
                periodicity: values.periodicity,
                category_id: values.category_id === '' ? null : values.category_id,
                charge_day: values.charge_day,
                start_date: values.start_date,
                end_date: values.end_date === '' ? null : values.end_date,
                is_active: values.status === 'active',
            };
            var req = isEdit
                ? FL.api(API_PURCHASES, { method: 'PUT', body: Object.assign({ id: sub.id }, payload) })
                : FL.api(API_PURCHASES, { method: 'POST', body: payload });
            return req.then(function (r) {
                if (r.data && r.data.ok) {
                    helpers.close();
                    FL.toastSuccess(isEdit ? 'Assinatura atualizada.' : 'Assinatura cadastrada!');
                    loadSubscriptions();
                    loadOverview();
                } else {
                    helpers.showFormError((r.data && r.data.error) || 'Não foi possível salvar.');
                }
            });
        });
    }

    // ------------------------------------------------------------
    // Benefícios
    // ------------------------------------------------------------
    function renderBenefits() {
        var wrap = $('benefits-list');
        wrap.innerHTML = '';
        var benefitCards = state.cards.filter(function (c) {
            return c.type === 'benefit' || c.type === 'multiple';
        });
        if (!benefitCards.length) {
            var p = el('p', 'empty', 'Nenhum cartão de benefício cadastrado. Crie um cartão do tipo "Benefício" para controlar VA, VR e outros saldos separadamente.');
            p.style.margin = '10px 2px';
            wrap.appendChild(p);
            return;
        }

        var grid = el('div', 'benefits-grid');
        benefitCards.forEach(function (c) {
            (c.balances || []).forEach(function (b) {
                var card = el('div', 'benefit-card');
                var head = el('div', 'b-head');
                var kind = el('div', 'b-kind');
                var icon = el('span', 'b-kind-icon', b.benefit_kind === 'fuel' ? '⛽' : b.benefit_kind === 'mobility' ? '🚌' : b.benefit_kind === 'home_office' ? '💻' : '🍽️');
                kind.appendChild(icon);
                var kindText = el('span');
                kindText.appendChild(el('strong', '', b.label));
                kindText.appendChild(el('small', '', c.name));
                kind.appendChild(kindText);
                head.appendChild(kind);
                head.appendChild(el('span', 'chip', c.type_label));
                card.appendChild(head);

                var balance = el('span', 'b-balance' + (b.balance < 0 ? ' negative' : ''), FL.money(b.balance));
                card.appendChild(balance);

                var stats = el('div', 'b-stats');
                function bStat(label, value) {
                    var d = el('div', 'b-stat');
                    d.appendChild(el('small', '', label));
                    d.appendChild(el('span', '', value));
                    return d;
                }
                stats.appendChild(bStat('Créditos recebidos', FL.money(b.total_credits)));
                stats.appendChild(bStat('Gastos', FL.money(b.total_spent)));
                if (b.monthly_credit) {
                    stats.appendChild(bStat('Crédito mensal', FL.money(b.monthly_credit) + (b.next_credit ? ' · próximo ' + FL.brDate(b.next_credit) : '')));
                }
                card.appendChild(stats);

                var actions = el('div', 'b-actions');
                var creditBtn = el('button', 'btn btn-soft btn-sm', 'Registrar crédito');
                creditBtn.addEventListener('click', function () { openCreditModal(c, b); });
                actions.appendChild(creditBtn);
                var spendBtn = el('button', 'btn btn-ghost btn-sm', 'Registrar gasto');
                spendBtn.addEventListener('click', function () { openPurchaseForm(null, c); });
                actions.appendChild(spendBtn);
                var histBtn = el('button', 'btn btn-ghost btn-sm', 'Histórico');
                histBtn.addEventListener('click', function () { openCreditsHistory(c, b); });
                actions.appendChild(histBtn);
                card.appendChild(actions);

                grid.appendChild(card);
            });
        });
        if (!grid.children.length) {
            var p2 = el('p', 'empty', 'Este cartão ainda não possui saldos de benefício configurados. Edite o cartão e selecione os benefícios.');
            p2.style.gridColumn = '1 / -1';
            grid.appendChild(p2);
        }
        wrap.appendChild(grid);
    }

    function openCreditModal(card, balance) {
        var body =
            '<div class="kv-list" style="margin-bottom:14px">' +
            '<div class="kv"><span>Benefício</span><strong>' + FL.esc(balance.label) + '</strong></div>' +
            '<div class="kv"><span>Saldo atual</span><strong>' + FL.esc(FL.money(balance.balance)) + '</strong></div>' +
            '</div>' +
            fieldHtml('bc-amount', 'Valor do crédito (R$) *', inputHtml('bc-amount', 'number', balance.monthly_credit || '', 'min="0.01" step="0.01" placeholder="Ex.: 700"')) +
            '<div class="modal-row">' +
            fieldHtml('bc-date', 'Data do crédito *', inputHtml('bc-date', 'date', new Date().toISOString().slice(0, 10))) +
            fieldHtml('bc-per', 'Periodicidade', selectHtml('bc-per', [['monthly', 'Mensal'], ['weekly', 'Semanal'], ['one_time', 'Eventual']], 'monthly')) +
            '</div>' +
            fieldHtml('bc-note', 'Observação', inputHtml('bc-note', 'text', '', 'maxlength="255" placeholder="Ex.: crédito de outubro"')) +
            '<p class="form-error" hidden></p>';

        var modal = customModal('Registrar crédito — ' + card.name, body);
        modal.querySelector('[data-save]').addEventListener('click', function () {
            var saveBtn = modal.querySelector('[data-save]');
            var payload = {
                action: 'add_credit',
                balance_id: balance.id,
                amount: modal.querySelector('#bc-amount').value,
                date: modal.querySelector('#bc-date').value,
                periodicity: modal.querySelector('#bc-per').value,
                note: modal.querySelector('#bc-note').value.trim(),
            };
            if (!payload.amount || parseFloat(payload.amount) <= 0) { modalError(modal, 'Informe um valor válido.'); return; }
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner"></span> Salvando…';
            FL.api(API, { method: 'POST', body: payload }).then(function (r) {
                if (r.data && r.data.ok) {
                    FL.closeModal();
                    FL.toastSuccess('Crédito registrado!');
                    loadOverview();
                } else {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Salvar';
                    modalError(modal, (r.data && r.data.error) || 'Não foi possível registrar.');
                }
            });
        });
    }

    function openCreditsHistory(card, balance) {
        FL.api(API + '?action=credits&balance_id=' + encodeURIComponent(balance.id), { silent: true })
            .then(function (r) {
                if (!r.data || !r.data.ok) { FL.toastError('Erro ao carregar histórico.'); return; }
                var credits = r.data.credits || [];
                var listHtml = credits.length
                    ? credits.map(function (c) {
                        return '<div class="installment-row"><span class="ins-num">' + FL.esc(FL.brDate(c.date)) + ' · ' + FL.esc(c.periodicity) + '</span>' +
                            '<span class="ins-amount">+' + FL.esc(FL.money(c.amount)) + '</span></div>';
                    }).join('')
                    : '<p class="muted small">Nenhum crédito registrado ainda.</p>';

                var overlay = $('modal-overlay');
                var modal = document.createElement('div');
                modal.className = 'modal';
                modal.innerHTML =
                    '<div class="modal-head"><h3>Créditos — ' + FL.esc(balance.label) + '</h3>' +
                    '<button type="button" class="modal-close" aria-label="Fechar">×</button></div>' +
                    '<div class="modal-body"><div class="installment-list">' + listHtml + '</div></div>' +
                    '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Fechar</button></div>';
                overlay.innerHTML = '';
                overlay.appendChild(modal);
                overlay.hidden = false;
                modal.querySelector('.modal-close').addEventListener('click', FL.closeModal);
                modal.querySelector('[data-close]').addEventListener('click', FL.closeModal);
            });
    }

    // ------------------------------------------------------------
    // Relatórios
    // ------------------------------------------------------------
    function loadReport() {
        var month = $('rep-month').value || FL.month;
        FL.api(API + '?action=report&month=' + encodeURIComponent(month), { silent: true }).then(function (r) {
            if (!r.data || !r.data.ok) { FL.toastError('Erro ao carregar relatório.'); return; }
            var d = r.data;
            $('rep-month-label').textContent = FL.monthLabel(month);

            if (window.FLCharts) {
                var byCard = (d.by_card || []).map(function (c) {
                    return { name: c.name, color: c.color || '#7C3AED', total: (parseFloat(c.total) || 0), pct: 0 };
                });
                var totalCard = byCard.reduce(function (t, c) { return t + c.total; }, 0) || 1;
                byCard.forEach(function (c) { c.pct = Math.round(c.total / totalCard * 1000) / 10; });
                FLCharts.donut('rep-by-card', 'rep-by-card-legend', byCard);

                var byCat = (d.by_category || []).map(function (c) {
                    return { name: c.name, color: c.color || '#7C3AED', total: (parseFloat(c.total) || 0), pct: 0 };
                });
                var totalCat = byCat.reduce(function (t, c) { return t + c.total; }, 0) || 1;
                byCat.forEach(function (c) { c.pct = Math.round(c.total / totalCat * 1000) / 10; });
                FLCharts.donut('rep-by-cat', 'rep-by-cat-legend', byCat);

                FLCharts.barsSingle('rep-evolution', (d.evolution || []).map(function (e) {
                    return { month: e.month, total: parseFloat(e.total) || 0 };
                }), { label: 'Evolução das compras em cartões' });
            }

            renderPlans(d.installment_plans || []);
            renderLimits(d.limits || []);
        });
    }

    function renderPlans(plans) {
        var wrap = $('rep-plans');
        wrap.innerHTML = '';
        var total = 0;
        plans.forEach(function (p) { total += parseFloat(p.remaining) || 0; });
        $('rep-plans-total').textContent = plans.length + ' plano(s) · ' + FL.money(total) + ' restantes';

        if (!plans.length) {
            var p = el('p', 'empty', 'Nenhum parcelamento ativo no momento.');
            p.style.margin = '10px 2px';
            wrap.appendChild(p);
            return;
        }
        var list = el('div', 'plans-list');
        plans.forEach(function (pl) {
            var row = el('div', 'plan-row');
            var info = el('span', 'pl-info');
            info.appendChild(el('strong', '', pl.description + ' — ' + pl.installments_total + '× de ' + FL.money(Math.round(pl.amount / pl.installments_total * 100) / 100)));
            info.appendChild(el('small', '', pl.card + ' · desde ' + FL.brDate(pl.date)));
            row.appendChild(info);
            var rem = el('span', 'pl-remaining');
            rem.appendChild(el('strong', '', FL.money(pl.remaining)));
            rem.appendChild(el('small', '', pl.pending_count + ' parcela(s)'));
            row.appendChild(rem);
            list.appendChild(row);
        });
        wrap.appendChild(list);
    }

    function renderLimits(cards) {
        var wrap = $('rep-limits');
        wrap.innerHTML = '';
        if (!cards.length) {
            var p = el('p', 'empty', 'Nenhum cartão de crédito ativo.');
            p.style.margin = '10px 2px';
            wrap.appendChild(p);
            return;
        }
        cards.forEach(function (c) {
            var group = el('div', 'budget-strip');
            group.style.padding = '10px 14px';
            var left = el('div', 'budget-left');
            var icon = el('span', 'budget-icon');
            icon.style.background = c.color + '22';
            icon.style.color = c.color;
            icon.textContent = '💳';
            left.appendChild(icon);
            var info = el('div', 'budget-info');
            info.appendChild(el('h2', '', c.name));
            info.appendChild(el('p', '', 'usado ' + FL.money(c.limit_used) + ' de ' + FL.money(c.limit_total)));
            left.appendChild(info);
            group.appendChild(left);

            var right = el('div', 'budget-right');
            var meter = el('div', 'budget-meter');
            var bar = el('div', 'bar');
            var fill = el('div', 'bar-fill');
            var pct = c.limit_used_pct;
            fill.style.width = Math.min(100, pct) + '%';
            fill.className = 'bar-fill' + (pct >= 100 ? ' danger' : pct >= (c.limit_alert_pct || 80) ? '' : ' success');
            if (pct < (c.limit_alert_pct || 80)) { fill.classList.add('success'); }
            bar.appendChild(fill);
            meter.appendChild(bar);
            var pctEl = el('span', 'budget-pct' + (pct >= 100 ? ' over' : ''), FL.pct(pct));
            meter.appendChild(pctEl);
            right.appendChild(meter);
            group.appendChild(right);
            wrap.appendChild(group);
        });
    }

    // ------------------------------------------------------------
    // Calendário financeiro
    // ------------------------------------------------------------
    function loadCalendar() {
        var month = $('cal-month').value || FL.month;
        FL.api(API + '?action=calendar&month=' + encodeURIComponent(month), { silent: true }).then(function (r) {
            if (!r.data || !r.data.ok) { FL.toastError('Erro ao carregar calendário.'); return; }
            renderCalendar(month, r.data.events || []);
        });
    }

    var EVENT_COLOR = {
        closing: 'var(--primary)', due: 'var(--danger)', installment: 'var(--success-bright)',
        subscription: 'var(--warning)', purchase: 'var(--text-2)', credit: 'var(--success)',
    };

    function renderCalendar(month, events) {
        var head = $('calendar-head');
        head.innerHTML = '';
        var title = el('strong', '', ymLabel(month));
        var sub = el('small', '', events.length + ' evento(s) — clique em um dia para ver os detalhes');
        head.appendChild(title);
        head.appendChild(sub);

        var grid = $('calendar-grid');
        grid.innerHTML = '';
        DOW.forEach(function (d) { grid.appendChild(el('span', 'calendar-dow', d)); });

        var byDate = {};
        events.forEach(function (e) {
            (byDate[e.date] = byDate[e.date] || []).push(e);
        });

        // Parse LOCAL (não UTC) — new Date('YYYY-MM-DD') deslocaria o dia em alguns fusos
        var year = parseInt(month.slice(0, 4), 10);
        var monthIdx = parseInt(month.slice(5, 7), 10) - 1;
        var first = new Date(year, monthIdx, 1);
        var daysInMonth = new Date(year, monthIdx + 1, 0).getDate();
        var startDow = first.getDay();
        for (var i = 0; i < startDow; i++) { grid.appendChild(el('div', 'calendar-day empty-day')); }

        var now = new Date();
        var todayStr = now.getFullYear() + '-' + ymd(now.getMonth() + 1) + '-' + ymd(now.getDate());

        for (var day = 1; day <= daysInMonth; day++) {
            // let: cada dia mantém sua própria data/eventos nos listeners (escopo de bloco)
            let dateStr = month + '-' + ymd(day);
            let dayEvents = byDate[dateStr] || [];
            var cell = el('div', 'calendar-day' + (dayEvents.length ? ' has-events' : '') + (dateStr === todayStr ? ' today' : ''));
            cell.appendChild(el('span', 'cd-num', String(day)));

            var shown = dayEvents.slice(0, 3);
            shown.forEach(function (ev) {
                var evEl = el('span', 'cd-event');
                var dot = el('span', 'dot');
                dot.style.background = EVENT_COLOR[ev.type] || 'var(--text-2)';
                evEl.appendChild(dot);
                evEl.appendChild(document.createTextNode(ev.title.length > 22 ? ev.title.slice(0, 21) + '…' : ev.title));
                cell.appendChild(evEl);
            });
            if (dayEvents.length > 3) {
                cell.appendChild(el('span', 'cd-more', '+' + (dayEvents.length - 3) + ' evento(s)'));
            }
            if (dayEvents.length) {
                cell.addEventListener('click', function () { showDayEvents(dateStr, dayEvents); });
            }
            grid.appendChild(cell);
        }
    }

    function showDayEvents(dateStr, dayEvents) {
        var listHtml = dayEvents.map(function (ev) {
            return '<div class="installment-row"><span class="ins-num"><span class="dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' +
                (EVENT_COLOR[ev.type] || 'var(--text-2)') + ';margin-right:7px;vertical-align:middle"></span>' +
                FL.esc(ev.title) + (ev.amount ? ' · ' + FL.esc(ev.card || '') : '') + '</span>' +
                '<span class="ins-amount">' + (ev.amount ? FL.esc(FL.money(ev.amount)) : '—') + '</span></div>';
        }).join('');

        var overlay = $('modal-overlay');
        var modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML =
            '<div class="modal-head"><h3>' + FL.esc(FL.brDate(dateStr)) + '</h3>' +
            '<button type="button" class="modal-close" aria-label="Fechar">×</button></div>' +
            '<div class="modal-body"><div class="installment-list">' + listHtml + '</div></div>' +
            '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Fechar</button></div>';
        overlay.innerHTML = '';
        overlay.appendChild(modal);
        overlay.hidden = false;
        modal.querySelector('.modal-close').addEventListener('click', FL.closeModal);
        modal.querySelector('[data-close]').addEventListener('click', FL.closeModal);
    }

    // ------------------------------------------------------------
    // Tabs
    // ------------------------------------------------------------
    function switchTab(name) {
        state.tab = name;
        FL.$$('.tab-link').forEach(function (t) {
            t.classList.toggle('active', t.getAttribute('data-tab') === name);
        });
        FL.$$('.tab-panel').forEach(function (p) {
            p.classList.toggle('active', p.id === 'tab-' + name);
        });
        if (state.loaded[name]) { return; }
        state.loaded[name] = true;
        if (name === 'purchases') { loadPurchases(); }
        if (name === 'invoices') { loadInvoices(); }
        if (name === 'installments') { loadInstallments(); }
        if (name === 'subscriptions') { loadSubscriptions(); }
        if (name === 'reports') { loadReport(); }
        if (name === 'calendar') { loadCalendar(); }
    }

    // ------------------------------------------------------------
    // Init
    // ------------------------------------------------------------
    FL.$$('.tab-link').forEach(function (t) {
        t.addEventListener('click', function () { switchTab(t.getAttribute('data-tab')); });
    });

    var cardNew = $('card-new');
    if (cardNew) { cardNew.addEventListener('click', function () { openCardForm(null); }); }
    var purchaseNew = $('purchase-new');
    if (purchaseNew) { purchaseNew.addEventListener('click', function () { openPurchaseForm(null); }); }
    var subNew = $('subscription-new');
    if (subNew) { subNew.addEventListener('click', function () { openSubscriptionForm(null); }); }

    ['p-filter-card', 'p-filter-method', 'p-filter-month'].forEach(function (id) {
        var elx = $(id);
        if (elx) { elx.addEventListener('change', loadPurchases); }
    });
    var pq = $('p-filter-q');
    if (pq) { pq.addEventListener('input', FL.debounce(loadPurchases, 350)); }
    var invFilter = $('inv-filter-card');
    if (invFilter) { invFilter.addEventListener('change', loadInvoices); }
    var repMonth = $('rep-month');
    if (repMonth) { repMonth.addEventListener('change', loadReport); }
    var calMonth = $('cal-month');
    if (calMonth) { calMonth.addEventListener('change', loadCalendar); }

    loadRefs();
    loadOverview();
    loadPurchases(); // primeira aba
    state.loaded.purchases = true;
})();
