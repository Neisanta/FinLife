/* ==============================================================
   FinLife — Página Contas
   Gerenciamento das contas financeiras: criar, editar, excluir.
   O saldo é sempre calculado pelo backend a partir das transações.
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL;
    if (!FL) { return; }

    var TYPE_LABELS = {
        checking: 'Conta corrente',
        savings: 'Poupança',
        cash: 'Dinheiro',
        digital_wallet: 'Carteira digital',
        investment: 'Investimento',
        other: 'Outra'
    };

    var grid = document.getElementById('accounts-grid');

    function load() {
        FL.api('/api/accounts.php').then(function (r) {
            if (!r.data || !r.data.ok) {
                FL.toastError((r.data && r.data.error) || 'Erro ao carregar contas.');
                return;
            }
            render(r.data.accounts || [], r.data.total_balance || 0);
        });
    }

    function render(accounts, totalBalance) {
        var total = document.getElementById('acc-total');
        var count = document.getElementById('acc-count');
        if (total) { total.textContent = FL.money(totalBalance); }
        if (count) { count.textContent = accounts.length + ' conta(s) cadastrada(s)'; }

        if (!grid) { return; }
        grid.innerHTML = '';

        if (!accounts.length) {
            var p = document.createElement('p');
            p.className = 'empty';
            p.style.gridColumn = '1 / -1';
            p.textContent = 'Você ainda não tem contas. Crie a primeira para organizar seu dinheiro!';
            grid.appendChild(p);
            return;
        }

        accounts.forEach(function (a) { grid.appendChild(cardEl(a)); });
    }

    function cardEl(a) {
        var card = document.createElement('article');
        card.className = 'account-card';
        if (a.color) { card.style.setProperty('--acc-color', a.color); }

        var head = document.createElement('div');
        head.className = 'acc-name';
        var nameWrap = document.createElement('span');
        nameWrap.className = 'acc-name-text';
        var dot = document.createElement('span');
        dot.className = 'acc-dot';
        dot.setAttribute('aria-hidden', 'true');
        var name = document.createElement('span');
        name.textContent = a.name;
        nameWrap.appendChild(dot);
        nameWrap.appendChild(name);
        var typeChip = document.createElement('span');
        typeChip.className = 'chip';
        typeChip.style.cssText = 'padding:2px 9px;font-size:.6563rem';
        typeChip.textContent = TYPE_LABELS[a.type] || a.type;
        head.appendChild(nameWrap);
        head.appendChild(typeChip);

        var balance = document.createElement('div');
        balance.className = 'acc-balance' + (Number(a.current_balance) < 0 ? ' negative' : '');
        balance.textContent = FL.money(a.current_balance);

        var meta = document.createElement('div');
        meta.className = 'acc-meta';
        var parts = [];
        if (a.institution) { parts.push(a.institution); }
        parts.push('Saldo inicial: ' + FL.money(a.initial_balance));
        parts.push(a.tx_count + ' lançamento(s)');
        meta.textContent = parts.join(' · ');

        var actions = document.createElement('div');
        actions.className = 'acc-actions';
        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'btn btn-ghost btn-sm';
        editBtn.textContent = 'Editar';
        editBtn.addEventListener('click', function () { openModal(a); });
        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'btn btn-danger-ghost btn-sm';
        delBtn.textContent = 'Excluir';
        delBtn.addEventListener('click', function () { remove(a); });
        actions.appendChild(editBtn);
        actions.appendChild(delBtn);

        card.appendChild(head);
        card.appendChild(balance);
        card.appendChild(meta);
        card.appendChild(actions);
        return card;
    }

    function openModal(a) {
        FL.formModal(a ? 'Editar conta' : 'Nova conta', [
            { name: 'name', label: 'Nome da conta', type: 'text', value: a ? a.name : '', required: true, maxlength: 80, placeholder: 'Ex.: Nubank, Carteira, Poupança' },
            { name: 'type', label: 'Tipo', type: 'select', value: a ? a.type : 'checking', required: true, options: Object.keys(TYPE_LABELS).map(function (k) {
                return { value: k, label: TYPE_LABELS[k] };
            }) },
            { name: 'institution', label: 'Instituição (opcional)', type: 'text', value: a ? (a.institution || '') : '', maxlength: 80, placeholder: 'Ex.: Banco do Brasil' },
            { name: 'initial_balance', label: 'Saldo inicial (R$)', type: 'number', value: a ? a.initial_balance : '0', step: '0.01', placeholder: '0,00', hint: 'Usado como ponto de partida do saldo calculado.' },
            { name: 'color', label: 'Cor (opcional)', type: 'color', value: a && a.color ? a.color : '#7C3AED', hint: 'Destaque visual da conta.' }
        ], function (values, helpers) {
            var payload = {
                name: values.name,
                type: values.type,
                institution: values.institution,
                initial_balance: values.initial_balance,
                color: values.color
            };
            return FL.api('/api/accounts.php', { method: a ? 'PUT' : 'POST', body: a ? Object.assign({ id: a.id }, payload) : payload })
                .then(function (res) {
                    if (res.data && res.data.ok) {
                        helpers.close();
                        FL.toastSuccess(a ? 'Conta atualizada.' : 'Conta criada com sucesso!');
                        load();
                    } else {
                        helpers.showFormError((res.data && res.data.error) || 'Não foi possível salvar.');
                    }
                });
        });
    }

    function remove(a) {
        FL.confirm('Excluir conta', 'Excluir a conta "' + a.name + '"? O histórico de transações dela será preservado (ficará sem conta).', true)
            .then(function (ok) {
                if (!ok) { return; }
                FL.api('/api/accounts.php?id=' + encodeURIComponent(a.id), { method: 'DELETE' })
                    .then(function (res) {
                        if (res.data && res.data.ok) {
                            FL.toastSuccess('Conta excluída.');
                            load();
                        } else {
                            FL.toastError((res.data && res.data.error) || 'Não foi possível excluir.');
                        }
                    });
            });
    }

    var newBtn = document.getElementById('acc-new');
    if (newBtn) { newBtn.addEventListener('click', function () { openModal(null); }); }

    load();
})();
