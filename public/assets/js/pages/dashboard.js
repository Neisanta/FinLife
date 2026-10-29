/* ==============================================================
   FinLife — Página Dashboard
   Carrega dados REAIS de /api/dashboard.php e renderiza os cards,
   gráficos, metas, lançamentos recentes e resumo com IA.
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL;
    if (!FL) { return; }

    var currentMonth = (FL.month && /^\d{4}-\d{2}$/.test(FL.month)) ? FL.month : new Date().toISOString().slice(0, 7);
    var monthInput = document.getElementById('dash-month');
    if (monthInput && monthInput.value) { currentMonth = monthInput.value; }

    function greeting() {
        var h = new Date().getHours();
        var part = (h >= 5 && h < 12) ? 'Bom dia' : (h >= 12 && h < 18) ? 'Boa tarde' : 'Boa noite';
        var name = (FL.user && FL.user.name) ? FL.user.name.split(' ')[0] : '';
        var el = document.getElementById('dash-greeting');
        if (el) { el.textContent = part + (name ? ', ' + name : ''); }
    }

    function setBar(id, pctValue) {
        var el = document.getElementById(id);
        if (!el) { return; }
        var p = Math.max(0, Math.min(100, Number(pctValue) || 0));
        el.style.width = p + '%';
        el.classList.remove('danger', 'success');
        if (p >= 90) { el.classList.add('danger'); }
        else if (p >= 70) { el.classList.add('danger'); }
        else { el.classList.add('success'); }
    }

    function render(data) {
        if (!data || !data.ok) {
            FL.toastError((data && data.error) ? data.error : 'Não foi possível carregar o dashboard.');
            return;
        }

        var c = data.cards;

        // Cards principais
        var el;
        var badgeBalance = document.getElementById('badge-balance');
        if (badgeBalance) {
            badgeBalance.textContent = c.balance >= 0 ? 'positivo' : 'negativo';
            badgeBalance.className = 'stat-badge ' + (c.balance >= 0 ? 'good' : 'bad');
        }
        if ((el = document.getElementById('stat-balance'))) { el.textContent = FL.money(c.balance); }

        if ((el = document.getElementById('stat-income'))) { el.textContent = FL.money(c.income); }
        if ((el = document.getElementById('stat-income-prev'))) {
            el.textContent = (Number(c.prev_income) > 0)
                ? 'Mês anterior: ' + FL.money(c.prev_income)
                : 'Sem receitas no mês anterior';
        }

        if ((el = document.getElementById('stat-expense'))) { el.textContent = FL.money(c.expense); }
        if ((el = document.getElementById('stat-expense-prev'))) {
            el.textContent = (Number(c.prev_expense) > 0)
                ? 'Mês anterior: ' + FL.money(c.prev_expense)
                : 'Sem despesas no mês anterior';
        }

        if ((el = document.getElementById('stat-net'))) { el.textContent = FL.money(c.net); }
        var badgeSavings = document.getElementById('badge-savings');
        if (badgeSavings) {
            if (c.savings_rate === null) { badgeSavings.textContent = 'sem receitas'; badgeSavings.className = 'stat-badge'; }
            else if (c.savings_rate >= 20) { badgeSavings.textContent = 'saudável'; badgeSavings.className = 'stat-badge good'; }
            else if (c.savings_rate >= 0) { badgeSavings.textContent = 'apertado'; badgeSavings.className = 'stat-badge warn'; }
            else { badgeSavings.textContent = 'no vermelho'; badgeSavings.className = 'stat-badge bad'; }
        }
        if ((el = document.getElementById('stat-savings-rate'))) {
            el.textContent = (c.savings_rate !== null)
                ? 'Taxa de poupança: ' + FL.pct(c.savings_rate) + ' das receitas'
                : 'Registre receitas para calcular';
        }

        // Orçamento
        var budgetCard = document.getElementById('budget-card');
        if (budgetCard) {
            if (c.budget === null) {
                budgetCard.hidden = false;
                if ((el = document.getElementById('budget-text'))) { el.textContent = 'Defina um limite mensal para acompanhar seus gastos.'; }
                if ((el = document.getElementById('budget-pct'))) { el.textContent = '—'; }
                setBar('budget-bar', 0);
            } else {
                budgetCard.hidden = false;
                if ((el = document.getElementById('budget-text'))) {
                    el.textContent = FL.money(c.expense) + ' de ' + FL.money(c.budget) + ' usados';
                }
                if ((el = document.getElementById('budget-pct'))) {
                    el.textContent = FL.pct(c.budget_used_pct);
                    el.classList.toggle('over', Number(c.budget_used_pct) > 100);
                }
                setBar('budget-bar', c.budget_used_pct);
            }
        }

        // Gráficos
        var chartLabel = document.getElementById('chart-month-label');
        if (chartLabel) { chartLabel.textContent = data.month_label; }
        if (window.FLCharts) {
            FLCharts.donut('donut-container', 'donut-legend', data.expenses_by_category);
            FLCharts.area('bars-container', 'bars-legend', data.evolution);
        }

        // Metas (top 4)
        renderGoals(data.goals || []);

        // Compromissos e contas fixas
        renderCommitments(data.commitments);

        // Lançamentos recentes
        renderRecent(data.recent_transactions || []);

        // Chip do modo da IA
        var chip = document.getElementById('ai-mode-chip');
        if (chip) {
            if (data.ai && data.ai.configured) { chip.textContent = 'IA externa ativa'; chip.className = 'chip ai'; }
            else { chip.textContent = 'modo local (dados reais)'; chip.className = 'chip'; }
        }
    }

    function renderGoals(goals) {
        var wrap = document.getElementById('dash-goals');
        if (!wrap) { return; }
        wrap.innerHTML = '';
        if (!goals.length) {
            var p = document.createElement('p');
            p.className = 'empty';
            p.textContent = 'Você ainda não tem metas. Crie a primeira na página de Metas!';
            wrap.appendChild(p);
            return;
        }
        goals.slice(0, 4).forEach(function (g) {
            var item = document.createElement('div');
            item.className = 'goal-item';

            var top = document.createElement('div');
            top.className = 'goal-item-top';
            var name = document.createElement('strong');
            name.textContent = g.name;
            var pctEl = document.createElement('span');
            pctEl.className = 'goal-item-pct';
            pctEl.textContent = FL.pct(g.progress);
            top.appendChild(name);
            top.appendChild(pctEl);

            var bar = document.createElement('div');
            bar.className = 'bar';
            var fill = document.createElement('div');
            fill.className = 'bar-fill' + (g.status === 'completed' ? ' success' : '');
            fill.style.width = Math.min(100, Number(g.progress) || 0) + '%';
            bar.appendChild(fill);

            var info = document.createElement('div');
            info.className = 'goal-item-info';
            info.textContent = FL.money(g.current_amount) + ' de ' + FL.money(g.target_amount);
            if (g.monthly_needed !== null && g.status !== 'completed') {
                info.textContent += ' · aporte sugerido: ' + FL.money(g.monthly_needed) + '/mês';
            }

            item.appendChild(top);
            item.appendChild(bar);
            item.appendChild(info);
            wrap.appendChild(item);
        });
    }

    function renderRecent(txs) {
        var wrap = document.getElementById('dash-recent');
        if (!wrap) { return; }
        wrap.innerHTML = '';
        if (!txs.length) {
            var p = document.createElement('p');
            p.className = 'empty';
            p.textContent = 'Nenhuma movimentação ainda. Registre a primeira pelo chat ou em Transações.';
            wrap.appendChild(p);
            return;
        }
        txs.forEach(function (t) {
            var row = document.createElement('div');
            row.className = 'tx-mini';

            var icon = document.createElement('span');
            icon.className = 'tx-icon';
            icon.textContent = t.category_icon || (t.type === 'income' ? '💰' : '💸');

            var info = document.createElement('span');
            info.className = 'tx-info';
            var strong = document.createElement('strong');
            strong.textContent = t.description;
            var small = document.createElement('small');
            small.textContent = (t.category_name || 'Sem categoria') + ' · ' + FL.brDate(t.date);
            info.appendChild(strong);
            info.appendChild(small);

            var amount = document.createElement('span');
            amount.className = 'tx-amount ' + t.type;
            amount.textContent = (t.type === 'income' ? '+ ' : '− ') + FL.money(Math.abs(t.amount)).replace('− ', '');

            row.appendChild(icon);
            row.appendChild(info);
            row.appendChild(amount);
            wrap.appendChild(row);
        });
    }

    function load() {
        FL.api('/api/dashboard.php?month=' + encodeURIComponent(currentMonth))
            .then(function (r) { render(r.data); });
    }

    /** Card compacto de compromissos vencendo em breve (inserido após o orçamento). */
    function renderCommitments(cm) {
        var budgetCard = document.getElementById('budget-card');
        if (!budgetCard || !cm) { return; }
        var existing = document.getElementById('commitments-card');
        if (existing) { existing.remove(); }
        var due = cm.due_soon || [];
        var sum = cm.summary || {};
        if (!due.length && !(sum.month_pending > 0)) { return; }
        var card = document.createElement('section');
        card.className = 'card';
        card.id = 'commitments-card';
        var head = document.createElement('div');
        head.className = 'card-head';
        head.innerHTML = '<h3>Compromissos próximos</h3>';
        var link = document.createElement('a');
        link.href = FL.base + '/compromissos';
        link.className = 'btn btn-ghost btn-sm';
        link.textContent = 'Ver todos';
        head.appendChild(link);
        card.appendChild(head);

        if (due.length) {
            var list = document.createElement('div');
            list.className = 'dash-commitments';
            due.slice(0, 4).forEach(function (o) {
                var row = document.createElement('div');
                row.className = 'dash-commitment-row';
                var info = document.createElement('span');
                info.className = 'dc-info';
                var strong = document.createElement('strong');
                strong.textContent = o.name;
                var small = document.createElement('small');
                small.textContent = o.payee + ' · ' + FL.brDate(o.due_date);
                info.appendChild(strong); info.appendChild(small);
                var amount = document.createElement('span');
                amount.className = 'dc-amount';
                amount.textContent = FL.money(o.pending_amount);
                var pay = document.createElement('button');
                pay.type = 'button'; pay.className = 'btn btn-ghost btn-sm'; pay.textContent = 'Pagar';
                pay.addEventListener('click', function () {
                    window.location.href = FL.base + '/compromissos';
                });
                row.appendChild(info); row.appendChild(amount); row.appendChild(pay);
                list.appendChild(row);
            });
            card.appendChild(list);
        }
        if (sum.month_pending > 0) {
            var foot = document.createElement('p');
            foot.className = 'muted';
            foot.style.cssText = 'margin:10px 0 0;font-size:.7813rem';
            foot.textContent = 'Pendente do mês: ' + FL.money(sum.month_pending)
                + (sum.overdue_total > 0 ? ' · Em atraso: ' + FL.money(sum.overdue_total) : '');
            card.appendChild(foot);
        }
        budgetCard.parentNode.insertBefore(card, budgetCard.nextSibling);
    }

    // ------------------------------------------------------------
    // Eventos
    // ------------------------------------------------------------
    if (monthInput) {
        monthInput.addEventListener('change', function () {
            currentMonth = monthInput.value || currentMonth;
            load();
        });
    }

    var budgetEdit = document.getElementById('budget-edit');
    if (budgetEdit) {
        budgetEdit.addEventListener('click', function () {
            // busca o orçamento atual direto da API
            FL.api('/api/dashboard.php?month=' + encodeURIComponent(currentMonth), { silent: true })
                .then(function (r) {
                    var budget = (r.data && r.data.ok && r.data.cards) ? r.data.cards.budget : null;
                    FL.formModal('Orçamento mensal', [{
                        name: 'amount',
                        label: 'Limite mensal de gastos (R$)',
                        type: 'number',
                        value: budget !== null && budget !== undefined ? budget : '',
                        min: 0,
                        step: '0.01',
                        placeholder: 'Ex.: 3500.00',
                        hint: 'Deixe vazio para remover o orçamento.'
                    }], function (values, helpers) {
                        var raw = String(values.amount).trim();
                        var payload = { action: 'set_budget', amount: raw === '' ? null : raw };
                        return FL.api('/api/dashboard.php', { method: 'POST', body: payload })
                            .then(function (res) {
                                if (res.data && res.data.ok) {
                                    helpers.close();
                                    FL.toastSuccess(raw === '' ? 'Orçamento removido.' : 'Orçamento atualizado.');
                                    load();
                                } else {
                                    helpers.showFormError((res.data && res.data.error) || 'Não foi possível salvar.');
                                }
                            });
                    });
                });
        });
    }

    var summaryBtn = document.getElementById('ai-summary-btn');
    if (summaryBtn) {
        summaryBtn.addEventListener('click', function () {
            var wrap = document.getElementById('ai-summary');
            summaryBtn.disabled = true;
            summaryBtn.innerHTML = '<span class="spinner"></span> Analisando seus dados…';
            FL.api('/api/ai.php', { method: 'POST', body: { action: 'summary' } })
                .then(function (r) {
                    summaryBtn.disabled = false;
                    summaryBtn.textContent = 'Gerar novo resumo';
                    if (r.data && r.data.ok && wrap) {
                        wrap.innerHTML = '';
                        var p = document.createElement('p');
                        p.style.cssText = 'margin:0;white-space:pre-wrap';
                        p.textContent = r.data.reply;
                        wrap.appendChild(p);
                    } else {
                        FL.toastError((r.data && r.data.error) || 'Não foi possível gerar o resumo.');
                    }
                });
        });
    }

    // ------------------------------------------------------------
    // Init
    // ------------------------------------------------------------
    greeting();
    load();

    // Ações rápidas (Receita/Despesa) — modal global de transação
    FL.$$('.quick-actions [data-qa]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (FL.quickTransaction) { FL.quickTransaction(btn.getAttribute('data-qa')); }
        });
    });
})();
