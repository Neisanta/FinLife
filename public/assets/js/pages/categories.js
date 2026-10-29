/* ==============================================================
   FinLife — Página Categorias
   Criar, editar e excluir categorias por tipo (receita/despesa).
   Categorias com transações vinculadas não podem ser excluídas.
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL;
    if (!FL) { return; }

    var listExpense = document.getElementById('cat-list-expense');
    var listIncome = document.getElementById('cat-list-income');

    function load() {
        FL.api('/api/categories.php').then(function (r) {
            if (!r.data || !r.data.ok) {
                FL.toastError((r.data && r.data.error) || 'Erro ao carregar categorias.');
                return;
            }
            var cats = r.data.categories || {};
            render(cats.expense || [], listExpense, 'cat-count-expense', 'despesa');
            render(cats.income || [], listIncome, 'cat-count-income', 'receita');
        });
    }

    function render(cats, wrap, countId, typeLabel) {
        var count = document.getElementById(countId);
        if (count) { count.textContent = cats.length + ' ' + typeLabel + '(s)'; }
        if (!wrap) { return; }
        wrap.innerHTML = '';

        if (!cats.length) {
            var p = document.createElement('p');
            p.className = 'empty';
            p.textContent = 'Nenhuma categoria deste tipo ainda.';
            wrap.appendChild(p);
            return;
        }

        cats.forEach(function (c) { wrap.appendChild(chipEl(c)); });
    }

    function chipEl(c) {
        var chip = document.createElement('span');
        chip.className = 'cat-chip';

        var dot = document.createElement('span');
        dot.className = 'cat-dot';
        dot.style.background = c.color || '#7C3AED';

        var label = document.createElement('span');
        label.textContent = (c.icon ? c.icon + ' ' : '') + c.name;

        var uses = document.createElement('span');
        uses.className = 'cat-uses';
        uses.textContent = c.usage_count > 0 ? ('· ' + c.usage_count) : '';

        var edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'cat-edit';
        edit.title = 'Editar';
        edit.setAttribute('aria-label', 'Editar categoria');
        edit.textContent = '✎';
        edit.addEventListener('click', function () { openModal(c); });

        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'cat-remove';
        del.title = 'Excluir';
        del.setAttribute('aria-label', 'Excluir categoria');
        del.textContent = '×';
        del.addEventListener('click', function () { remove(c); });

        chip.appendChild(dot);
        chip.appendChild(label);
        if (c.usage_count > 0) { chip.appendChild(uses); }
        chip.appendChild(edit);
        chip.appendChild(del);
        return chip;
    }

    function openModal(c) {
        var isEdit = !!c;
        FL.formModal(isEdit ? 'Editar categoria' : 'Nova categoria', [
            { name: 'type', label: 'Tipo', type: 'select', value: c ? c.type : 'expense', required: true, options: [
                { value: 'expense', label: 'Despesa' },
                { value: 'income', label: 'Receita' }
            ], hint: isEdit ? 'O tipo não pode ser alterado depois de criada.' : undefined },
            { name: 'name', label: 'Nome', type: 'text', value: c ? c.name : '', required: true, maxlength: 60, placeholder: 'Ex.: Academia, Viagens' },
            { name: 'icon', label: 'Emoji (opcional)', type: 'text', value: c ? (c.icon || '') : '', maxlength: 8, placeholder: 'Ex.: 🎯' },
            { name: 'color', label: 'Cor (opcional)', type: 'color', value: c && c.color ? c.color : '#7C3AED' }
        ], function (values, helpers) {
            var payload = {
                name: values.name,
                icon: values.icon,
                color: values.color
            };
            var req;
            if (isEdit) {
                payload.id = c.id;
                req = FL.api('/api/categories.php', { method: 'PUT', body: payload });
            } else {
                payload.type = values.type;
                req = FL.api('/api/categories.php', { method: 'POST', body: payload });
            }
            return req.then(function (res) {
                if (res.data && res.data.ok) {
                    helpers.close();
                    FL.toastSuccess(isEdit ? 'Categoria atualizada.' : 'Categoria criada com sucesso!');
                    load();
                } else {
                    helpers.showFormError((res.data && res.data.error) || 'Não foi possível salvar.');
                }
            });
        });
    }

    function remove(c) {
        FL.confirm('Excluir categoria', 'Excluir a categoria "' + c.name + '"? Categorias com transações vinculadas não podem ser excluídas.', true)
            .then(function (ok) {
                if (!ok) { return; }
                FL.api('/api/categories.php?id=' + encodeURIComponent(c.id), { method: 'DELETE' })
                    .then(function (res) {
                        if (res.data && res.data.ok) {
                            FL.toastSuccess('Categoria excluída.');
                            load();
                        } else {
                            FL.toastError((res.data && res.data.error) || 'Não foi possível excluir.');
                        }
                    });
            });
    }

    var newBtn = document.getElementById('cat-new');
    if (newBtn) { newBtn.addEventListener('click', function () { openModal(null); }); }

    load();
})();
