/* ==============================================================
   FinLife — Página Metas
   Criar metas com valor objetivo e prazo, registrar aportes,
   acompanhar progresso e o aporte mensal recomendado.
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL;
    if (!FL) { return; }

    var grid = document.getElementById('goals-grid');

    function load() {
        FL.api('/api/goals.php').then(function (r) {
            if (!r.data || !r.data.ok) {
                FL.toastError((r.data && r.data.error) || 'Erro ao carregar metas.');
                return;
            }
            render(r.data.goals || []);
        });
    }

    function render(goals) {
        if (!grid) { return; }
        grid.innerHTML = '';

        if (!goals.length) {
            var p = document.createElement('p');
            p.className = 'empty';
            p.style.gridColumn = '1 / -1';
            p.textContent = 'Você ainda não tem metas. Crie a primeira — por exemplo, uma reserva de emergência!';
            grid.appendChild(p);
            return;
        }

        goals.forEach(function (g) { grid.appendChild(cardEl(g)); });
    }

    function cardEl(g) {
        var card = document.createElement('article');
        card.className = 'goal-card';

        // topo: nome + status
        var top = document.createElement('div');
        top.className = 'goal-top';
        var name = document.createElement('span');
        name.className = 'goal-name';
        name.textContent = g.name;
        var status = document.createElement('span');
        status.className = 'chip ' + (g.status === 'completed' ? 'good' : (g.overdue ? 'bad' : ''));
        status.textContent = g.status === 'completed' ? 'Concluída' : (g.overdue ? 'Prazo vencido' : 'Ativa');
        top.appendChild(name);
        top.appendChild(status);

        // progresso
        var bar = document.createElement('div');
        bar.className = 'bar';
        var fill = document.createElement('div');
        fill.className = 'bar-fill' + (g.status === 'completed' ? ' success' : (g.overdue ? ' danger' : ''));
        fill.style.width = Math.min(100, Number(g.progress) || 0) + '%';
        bar.appendChild(fill);

        // valores
        var values = document.createElement('div');
        values.className = 'goal-values';
        var v1 = document.createElement('div');
        v1.innerHTML = '<strong></strong> de <strong></strong>';
        var strongs = v1.querySelectorAll('strong');
        strongs[0].textContent = FL.money(g.current_amount);
        strongs[1].textContent = FL.money(g.target_amount);
        values.appendChild(v1);

        var v2 = document.createElement('div');
        v2.style.marginTop = '2px';
        var v2Parts = [];
        if (g.remaining > 0) { v2Parts.push('Falta: ' + FL.money(g.remaining)); }
        if (g.monthly_needed !== null && g.status !== 'completed') {
            v2Parts.push('Aporte sugerido: ' + FL.money(g.monthly_needed) + '/mês');
        }
        if (g.deadline) {
            v2Parts.push('Prazo: ' + FL.brDate(g.deadline) + (g.months_left !== null ? ' (' + g.months_left + ' mês(es))' : ''));
        }
        v2.textContent = v2Parts.join(' · ');
        values.appendChild(v2);

        // ações
        var foot = document.createElement('div');
        foot.className = 'goal-foot';
        var actions = document.createElement('div');
        actions.className = 'goal-actions';

        var depositBtn = document.createElement('button');
        depositBtn.type = 'button';
        depositBtn.className = 'btn btn-primary btn-sm';
        depositBtn.textContent = 'Aportar';
        depositBtn.addEventListener('click', function () { openDepositModal(g); });

        var depositsBtn = document.createElement('button');
        depositsBtn.type = 'button';
        depositsBtn.className = 'btn btn-ghost btn-sm';
        depositsBtn.textContent = 'Aportes (' + (Number(g.current_amount) > 0 ? '' : '0') + ')';
        depositsBtn.textContent = 'Histórico';
        depositsBtn.addEventListener('click', function () { showDeposits(g); });

        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'btn btn-ghost btn-sm';
        editBtn.textContent = 'Editar';
        editBtn.addEventListener('click', function () { openEditModal(g); });

        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'btn btn-danger-ghost btn-sm';
        delBtn.textContent = 'Excluir';
        delBtn.addEventListener('click', function () { remove(g); });

        actions.appendChild(depositBtn);
        actions.appendChild(depositsBtn);
        actions.appendChild(editBtn);
        actions.appendChild(delBtn);
        foot.appendChild(actions);

        card.appendChild(top);
        card.appendChild(bar);
        card.appendChild(values);
        card.appendChild(foot);
        return card;
    }

    // ------------------------------------------------------------
    // Modal: criar / editar meta
    // ------------------------------------------------------------
    function openNewModal() {
        FL.formModal('Nova meta', goalFields(null), function (values, helpers) {
            return FL.api('/api/goals.php', { method: 'POST', body: {
                name: values.name,
                target_amount: values.target_amount,
                deadline: values.deadline === '' ? null : values.deadline
            } }).then(function (res) {
                if (res.data && res.data.ok) {
                    helpers.close();
                    FL.toastSuccess('Meta criada com sucesso!');
                    load();
                } else {
                    helpers.showFormError((res.data && res.data.error) || 'Não foi possível criar a meta.');
                }
            });
        });
    }

    function openEditModal(g) {
        FL.formModal('Editar meta', goalFields(g), function (values, helpers) {
            return FL.api('/api/goals.php', { method: 'PUT', body: {
                id: g.id,
                name: values.name,
                target_amount: values.target_amount,
                deadline: values.deadline === '' ? null : values.deadline
            } }).then(function (res) {
                if (res.data && res.data.ok) {
                    helpers.close();
                    FL.toastSuccess('Meta atualizada.');
                    load();
                } else {
                    helpers.showFormError((res.data && res.data.error) || 'Não foi possível salvar.');
                }
            });
        });
    }

    function goalFields(g) {
        return [
            { name: 'name', label: 'Nome da meta', type: 'text', value: g ? g.name : '', required: true, maxlength: 120, placeholder: 'Ex.: Reserva de emergência' },
            { name: 'target_amount', label: 'Valor objetivo (R$)', type: 'number', value: g ? g.target_amount : '', required: true, min: 0.01, step: '0.01', placeholder: 'Ex.: 10000' },
            { name: 'deadline', label: 'Prazo (opcional)', type: 'date', value: g && g.deadline ? g.deadline : '', hint: 'Com prazo definido, o aporte mensal recomendado é calculado.' }
        ];
    }

    // ------------------------------------------------------------
    // Modal: aporte
    // ------------------------------------------------------------
    function openDepositModal(g) {
        FL.formModal('Aportar em "' + g.name + '"', [
            { name: 'amount', label: 'Valor do aporte (R$)', type: 'number', value: g.monthly_needed !== null && g.monthly_needed > 0 ? g.monthly_needed : '', required: true, min: 0.01, step: '0.01', placeholder: 'Ex.: 500' },
            { name: 'date', label: 'Data', type: 'date', value: new Date().toISOString().slice(0, 10), required: true },
            { name: 'note', label: 'Observação (opcional)', type: 'text', maxlength: 255, placeholder: 'Ex.: parte do 13º' }
        ], function (values, helpers) {
            return FL.api('/api/goals.php?action=deposit', { method: 'POST', body: {
                goal_id: g.id,
                amount: values.amount,
                date: values.date,
                note: values.note
            } }).then(function (res) {
                if (res.data && res.data.ok) {
                    helpers.close();
                    FL.toastSuccess('Aporte registrado com sucesso!');
                    load();
                } else {
                    helpers.showFormError((res.data && res.data.error) || 'Não foi possível registrar o aporte.');
                }
            });
        });
    }

    // ------------------------------------------------------------
    // Histórico de aportes
    // ------------------------------------------------------------
    function showDeposits(g) {
        FL.api('/api/goals.php?action=deposits&goal_id=' + encodeURIComponent(g.id), { silent: true })
            .then(function (r) {
                if (!r.data || !r.data.ok) {
                    FL.toastError((r.data && r.data.error) || 'Não foi possível carregar os aportes.');
                    return;
                }
                var rows = r.data.deposits || [];

                var modal = document.createElement('div');
                modal.className = 'modal';
                var listHtml = '';
                modal.innerHTML = '<div class="modal-head"><h3>Aportes — ' + FL.esc(g.name) + '</h3><button type="button" class="modal-close" aria-label="Fechar">×</button></div>'
                    + '<div class="modal-body"><div class="tx-mini-list" id="deposits-list"></div></div>'
                    + '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Fechar</button></div>';

                var list = modal.querySelector('#deposits-list');
                if (!rows.length) {
                    var p = document.createElement('p');
                    p.className = 'empty';
                    p.textContent = 'Nenhum aporte registrado ainda para esta meta.';
                    list.appendChild(p);
                } else {
                    rows.forEach(function (d) {
                        var row = document.createElement('div');
                        row.className = 'tx-mini';
                        var info = document.createElement('span');
                        info.className = 'tx-info';
                        var strong = document.createElement('strong');
                        strong.textContent = FL.money(d.amount);
                        var small = document.createElement('small');
                        small.textContent = FL.brDate(d.date) + (d.note ? ' · ' + d.note : '');
                        info.appendChild(strong);
                        info.appendChild(small);
                        row.appendChild(info);
                        list.appendChild(row);
                    });
                }

                modal.querySelector('.modal-close').addEventListener('click', FL.closeModal);
                modal.querySelector('[data-close]').addEventListener('click', FL.closeModal);

                var overlay = document.getElementById('modal-overlay');
                if (overlay) {
                    overlay.innerHTML = '';
                    overlay.appendChild(modal);
                    overlay.hidden = false;
                }
            });
    }

    function remove(g) {
        FL.confirm('Excluir meta', 'Excluir a meta "' + g.name + '" e todos os aportes dela? Esta ação não pode ser desfeita.', true)
            .then(function (ok) {
                if (!ok) { return; }
                FL.api('/api/goals.php?id=' + encodeURIComponent(g.id), { method: 'DELETE' })
                    .then(function (res) {
                        if (res.data && res.data.ok) {
                            FL.toastSuccess('Meta excluída.');
                            load();
                        } else {
                            FL.toastError((res.data && res.data.error) || 'Não foi possível excluir.');
                        }
                    });
            });
    }

    var newBtn = document.getElementById('goal-new');
    if (newBtn) { newBtn.addEventListener('click', openNewModal); }

    load();
})();
