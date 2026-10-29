/* ==============================================================
   FinLife — Página Transações
   CRUD completo de receitas/despesas com filtros, modais e
   confirmação antes de excluir.
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL;
    if (!FL) { return; }

    var categories = { income: [], expense: [] };
    var accounts = [];
    var loading = false;

    var tbody = document.getElementById('tx-tbody');
    var monthInput = document.getElementById('f-month');
    var typeSelect = document.getElementById('f-type');
    var catSelect = document.getElementById('f-category');
    var accSelect = document.getElementById('f-account');
    var qInput = document.getElementById('f-q');

    // ------------------------------------------------------------
    // Carregamento de referências (categorias e contas)
    // ------------------------------------------------------------
    function loadRefs() {
        FL.api('/api/categories.php', { silent: true }).then(function (r) {
            if (r.data && r.data.ok) {
                categories = r.data.categories;
                var all = categories.expense.concat(categories.income);
                FL.fillSelect(catSelect, all, 'id', 'name', 'Todas');
            }
        });
        FL.api('/api/accounts.php', { silent: true }).then(function (r) {
            if (r.data && r.data.ok) {
                accounts = r.data.accounts;
                FL.fillSelect(accSelect, accounts, 'id', 'name', 'Todas');
            }
        });
    }

    // ------------------------------------------------------------
    // Lista
    // ------------------------------------------------------------
    function currentFilters() {
        return {
            month: (monthInput && monthInput.value) || '',
            type: (typeSelect && typeSelect.value) || '',
            category_id: (catSelect && catSelect.value) || '',
            account_id: (accSelect && accSelect.value) || '',
            q: (qInput && qInput.value.trim()) || ''
        };
    }

    function load() {
        if (loading) { return; }
        loading = true;
        var f = currentFilters();
        var qs = [];
        Object.keys(f).forEach(function (k) {
            if (f[k] !== '') { qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(f[k])); }
        });
        FL.api('/api/transactions.php?' + qs.join('&')).then(function (r) {
            loading = false;
            if (!r.data || !r.data.ok) {
                FL.toastError((r.data && r.data.error) || 'Erro ao carregar transações.');
                return;
            }
            render(r.data.transactions || [], r.data.totals || {});
        });
    }

    function render(rows, totals) {
        if (!tbody) { return; }
        tbody.innerHTML = '';

        if (!rows.length) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 6;
            td.className = 'empty-cell';
            td.textContent = 'Nenhuma transação encontrada com os filtros atuais.';
            tr.appendChild(td);
            tbody.appendChild(tr);
        } else {
            rows.forEach(function (t) { tbody.appendChild(rowEl(t)); });
        }

        var count = document.getElementById('tx-count');
        if (count) {
            count.textContent = rows.length + ' transação(ões) listada(s)' + (rows.length >= 100 ? ' (limite de 100)' : '');
        }

        var summary = document.getElementById('tx-totals');
        if (summary) {
            summary.innerHTML = '';
            function totalChip(label, valueClass, value) {
                var chip = document.createElement('span');
                chip.className = 'total-chip';
                var lab = document.createElement('span');
                lab.textContent = label;
                var strong = document.createElement('strong');
                strong.className = valueClass;
                strong.textContent = value;
                chip.appendChild(lab);
                chip.appendChild(strong);
                summary.appendChild(chip);
            }
            totalChip('Entradas', 'income', FL.money(totals.income || 0));
            totalChip('Saídas', 'expense', FL.money(totals.expense || 0));
            totalChip('Saldo do mês', '', FL.money(totals.net || 0));
        }
    }

    function categoryLabel(t) {
        var label = t.category_name || 'Sem categoria';
        return (t.category_icon ? t.category_icon + ' ' : '') + label;
    }

    function rowEl(t) {
        var tr = document.createElement('tr');

        var tdDate = document.createElement('td');
        tdDate.dataset.label = 'Data';
        tdDate.textContent = FL.brDate(t.date);
        tdDate.style.whiteSpace = 'nowrap';

        var tdDesc = document.createElement('td');
        tdDesc.dataset.label = 'Descrição';
        tdDesc.textContent = t.description;
        if (t.created_by === 'ai') {
            var aiBadge = document.createElement('span');
            aiBadge.className = 'chip ai';
            aiBadge.style.cssText = 'margin-left:8px;padding:1px 8px;font-size:.6563rem';
            aiBadge.textContent = 'via IA';
            tdDesc.appendChild(aiBadge);
        }

        var tdCat = document.createElement('td');
        tdCat.dataset.label = 'Categoria';
        var dot = document.createElement('span');
        dot.className = 'cat-dot';
        dot.style.cssText = 'display:inline-block;margin-right:7px;vertical-align:middle;background:' + (t.category_color || '#7C3AED');
        tdCat.appendChild(dot);
        tdCat.appendChild(document.createTextNode(t.category_name || '—'));

        var tdAcc = document.createElement('td');
        tdAcc.dataset.label = 'Conta';
        tdAcc.textContent = t.account_name || '—';

        var tdAmount = document.createElement('td');
        tdAmount.dataset.label = 'Valor';
        tdAmount.className = 'right amount-cell ' + t.type;
        tdAmount.textContent = (t.type === 'income' ? '+ ' : '− ') + FL.money(Math.abs(t.amount)).replace('− ', '');

        var tdActions = document.createElement('td');
        tdActions.dataset.label = 'Ações';
        tdActions.className = 'right';
        var actions = document.createElement('div');
        actions.className = 'table-actions';

        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'icon-btn';
        editBtn.title = 'Editar';
        editBtn.setAttribute('aria-label', 'Editar');
        editBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
        editBtn.addEventListener('click', function () { openEditModal(t); });

        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'icon-btn danger';
        delBtn.title = 'Excluir';
        delBtn.setAttribute('aria-label', 'Excluir');
        delBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>';
        delBtn.addEventListener('click', function () { removeTx(t); });

        actions.appendChild(editBtn);
        actions.appendChild(delBtn);
        tdActions.appendChild(actions);

        tr.appendChild(tdDate);
        tr.appendChild(tdDesc);
        tr.appendChild(tdCat);
        tr.appendChild(tdAcc);
        tr.appendChild(tdAmount);
        tr.appendChild(tdActions);
        return tr;
    }

    // ------------------------------------------------------------
    // Modal: criar / editar
    // ------------------------------------------------------------
    function fieldsFor(t) {
        var isEdit = !!t;
        var catOptions = [];
        catOptions.push({ value: '', label: 'Automática (por tipo)' });
        categories.expense.forEach(function (c) { catOptions.push({ value: c.id, label: '💸 ' + c.name }); });
        categories.income.forEach(function (c) { catOptions.push({ value: c.id, label: '💰 ' + c.name }); });
        var accOptions = [{ value: '', label: 'Sem conta' }];
        accounts.forEach(function (a) { accOptions.push({ value: a.id, label: a.name }); });

        return [
            { name: 'type', label: 'Tipo', type: 'select', value: t ? t.type : 'expense', required: true, options: [
                { value: 'expense', label: 'Despesa' },
                { value: 'income', label: 'Receita' }
            ] },
            { name: 'description', label: 'Descrição', type: 'text', value: t ? t.description : '', required: true, maxlength: 255, placeholder: 'Ex.: Mercado da esquina' },
            { name: 'amount', label: 'Valor (R$)', type: 'number', value: t ? t.amount : '', required: true, min: 0.01, step: '0.01', placeholder: '0,00' },
            { name: 'date', label: 'Data', type: 'date', value: t ? t.date : new Date().toISOString().slice(0, 10), required: true },
            { name: 'category_id', label: 'Categoria', type: 'select', value: t && t.category_id ? String(t.category_id) : '', options: catOptions },
            { name: 'account_id', label: 'Conta', type: 'select', value: t && t.account_id ? String(t.account_id) : '', options: accOptions }
        ];
    }

    function submitTx(values, helpers, id) {
        var payload = {
            type: values.type,
            description: values.description,
            amount: values.amount,
            date: values.date,
            category_id: values.category_id === '' ? null : values.category_id,
            account_id: values.account_id === '' ? null : values.account_id
        };
        if (id) { payload.id = id; }
        return FL.api('/api/transactions.php', { method: id ? 'PUT' : 'POST', body: payload })
            .then(function (res) {
                if (res.data && res.data.ok) {
                    helpers.close();
                    FL.toastSuccess(id ? 'Transação atualizada.' : 'Transação registrada com sucesso!');
                    load();
                } else {
                    helpers.showFormError((res.data && res.data.error) || 'Não foi possível salvar.');
                }
            });
    }

    function openNewModal() {
        FL.formModal('Nova transação', fieldsFor(null), function (values, helpers) {
            return submitTx(values, helpers, null);
        });
    }

    function openEditModal(t) {
        FL.formModal('Editar transação', fieldsFor(t), function (values, helpers) {
            return submitTx(values, helpers, t.id);
        });
    }

    function removeTx(t) {
        FL.confirm('Excluir transação', 'Excluir "' + t.description + '" (' + FL.money(t.amount) + ')? Esta ação não pode ser desfeita.', true)
            .then(function (ok) {
                if (!ok) { return; }
                FL.api('/api/transactions.php?id=' + encodeURIComponent(t.id), { method: 'DELETE' })
                    .then(function (res) {
                        if (res.data && res.data.ok) {
                            FL.toastSuccess('Transação excluída.');
                            load();
                        } else {
                            FL.toastError((res.data && res.data.error) || 'Não foi possível excluir.');
                        }
                    });
            });
    }

    // ------------------------------------------------------------
    // Eventos
    // ------------------------------------------------------------
    var newBtn = document.getElementById('tx-new');
    if (newBtn) { newBtn.addEventListener('click', function () { FL.quickTransaction(null, load); }); }

    if (monthInput) { monthInput.addEventListener('change', load); }
    if (typeSelect) { typeSelect.addEventListener('change', load); }
    if (catSelect) { catSelect.addEventListener('change', load); }
    if (accSelect) { accSelect.addEventListener('change', load); }
    if (qInput) { qInput.addEventListener('input', FL.debounce(load, 350)); }

    // ------------------------------------------------------------
    // Init
    // ------------------------------------------------------------
    loadRefs();
    load();
})();
