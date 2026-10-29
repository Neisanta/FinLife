/**
 * FinLife — Compromissos e Contas Fixas.
 * Cards, KPIs, filtros, pagamento (parcial/total), edição com escopo,
 * pausar/retomar/quitar/excluir. Dados reais via /api/commitments.php.
 */
(function () {
    'use strict';

    var listEl, emptyEl, overlay;
    var state = { items: [], accounts: [], filters: { q: '', category: '', status: '', kind: '' }, editing: null };
    var CATEGORY_LABELS = {
        divida_pessoal: 'Dívida pessoal', emprestimo_informal: 'Empréstimo informal',
        telefone: 'Telefone', internet: 'Internet', servico: 'Serviço',
        mensalidade: 'Mensalidade', assinatura: 'Assinatura', outro: 'Outro'
    };
    var PERIODICITY_LABELS = {
        weekly: 'Semanal', biweekly: 'Quinzenal', monthly: 'Mensal', quarterly: 'Trimestral',
        semiannual: 'Semestral', yearly: 'Anual', custom: 'Personalizada'
    };
    var STATUS = {
        ontime: { label: 'Em dia', cls: 'success' },
        overdue: { label: 'Atrasado', cls: 'danger' },
        paused: { label: 'Pausado', cls: 'muted' },
        settled: { label: 'Quitado', cls: 'info' }
    };

    // ------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        listEl = document.getElementById('cm-list');
        emptyEl = document.getElementById('cm-empty');
        overlay = document.getElementById('cm-modal-overlay');
        bindStatic();
        load();
    });

    function bindStatic() {
        document.getElementById('cm-new').addEventListener('click', function () { openForm(null); });
        document.querySelectorAll('[data-new-cta]').forEach(function (b) {
            b.addEventListener('click', function () { openForm(null); });
        });
        ['f-search', 'f-category', 'f-status', 'f-kind'].forEach(function (id) {
            var el = document.getElementById(id);
            el.addEventListener('input', applyFilters);
            el.addEventListener('change', applyFilters);
        });
    }

    function applyFilters() {
        state.filters = {
            q: document.getElementById('f-search').value.trim().toLowerCase(),
            category: document.getElementById('f-category').value,
            status: document.getElementById('f-status').value,
            kind: document.getElementById('f-kind').value
        };
        render();
    }

    // ------------------------------------------------------------
    // Dados
    // ------------------------------------------------------------
    function load() {
        FL.api('/api/commitments.php?action=list').then(function (r) {
            if (!r.data || !r.data.ok) { FL.toastError((r.data && r.data.error) || 'Erro ao carregar.'); return; }
            state.items = r.data.commitments || [];
            renderKpis(r.data.summary || {}, r.data.due_soon || []);
            render();
        });
        FL.api('/api/commitments.php?action=accounts').then(function (r) {
            state.accounts = (r.data && r.data.ok) ? (r.data.accounts || []) : [];
        });
    }

    function renderKpis(s, dueSoon) {
        document.getElementById('kpi-active').textContent = s.active_count || 0;
        document.getElementById('kpi-active-sub').textContent = 'compromissos ativos';
        document.getElementById('kpi-planned').textContent = FL.money(s.month_planned || 0);
        document.getElementById('kpi-paid').textContent = FL.money(s.month_paid || 0);
        document.getElementById('kpi-pending').textContent = FL.money(s.month_pending || 0);
        document.getElementById('kpi-debts').textContent = FL.money(s.debts_open || 0);
        document.getElementById('kpi-overdue').textContent = FL.money(s.overdue_total || 0);
        var oc = document.getElementById('kpi-overdue-count');
        oc.hidden = !(s.overdue_count > 0);
        oc.textContent = (s.overdue_count || 0) + ' em atraso';

        var card = document.getElementById('cm-soon-card');
        var wrap = document.getElementById('cm-soon');
        wrap.innerHTML = '';
        if (!dueSoon.length) { card.hidden = true; return; }
        card.hidden = false;
        dueSoon.forEach(function (o) {
            var row = document.createElement('div');
            row.className = 'cm-soon-row';
            var d = (o.due_date || '').split('-');
            row.innerHTML =
                '<span class="cm-soon-date"><strong>' + (d[2] || '') + '</strong><small>' + monthShort(d[1]) + '</small></span>' +
                '<span class="cm-soon-info"><strong></strong><small></small></span>' +
                '<span class="cm-soon-amount"></span>';
            row.querySelector('.cm-soon-info strong').textContent = o.name;
            row.querySelector('.cm-soon-info small').textContent = o.payee;
            row.querySelector('.cm-soon-amount').textContent = FL.money(o.pending_amount);
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'btn btn-sm btn-primary'; btn.textContent = 'Pagar';
            btn.addEventListener('click', function () { openPay(o.commitment_id || o.id, o.pending_amount); });
            row.appendChild(btn);
            wrap.appendChild(row);
        });
    }

    function monthShort(m) {
        return ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][parseInt(m, 10) - 1] || '';
    }

    // ------------------------------------------------------------
    // Renderização da lista
    // ------------------------------------------------------------
    function render() {
        listEl.innerHTML = '';
        var f = state.filters;
        var visible = state.items.filter(function (c) {
            if (f.kind && c.kind !== f.kind) { return false; }
            if (f.category && c.category_key !== f.category) { return false; }
            if (f.status && c.derived_status !== f.status) { return false; }
            if (f.q && (c.payee || '').toLowerCase().indexOf(f.q) === -1 && (c.name || '').toLowerCase().indexOf(f.q) === -1) { return false; }
            return true;
        });
        emptyEl.hidden = visible.length > 0;
        if (!visible.length) { return; }
        visible.forEach(function (c) { listEl.appendChild(card(c)); });
    }

    function card(c) {
        var st = STATUS[c.derived_status] || STATUS.ontime;
        var el = document.createElement('div');
        el.className = 'card cm-card' + (c.derived_status === 'overdue' ? ' cm-card-overdue' : '');
        el.dataset.id = c.id;

        var head = document.createElement('div');
        head.className = 'cm-card-head';
        head.innerHTML =
            '<div class="cm-card-title"><h4></h4><span class="badge badge-' + st.cls + '">' + st.label + '</span></div>' +
            '<div class="cm-card-sub"></div>';
        head.querySelector('h4').textContent = c.name;
        head.querySelector('.cm-card-sub').textContent = c.payee + ' · ' + (c.category_label || '');

        var meta = document.createElement('div');
        meta.className = 'cm-card-meta';
        var next = c.next_occurrence;
        meta.innerHTML =
            '<div class="cm-cell"><small>Próximo vencimento</small><strong></strong></div>' +
            '<div class="cm-cell"><small>Valor</small><strong class="cm-val"></strong></div>' +
            '<div class="cm-cell"><small>Frequência</small><strong></strong></div>' +
            (c.balance !== null
                ? '<div class="cm-cell"><small>Saldo devedor</small><strong class="cm-val"></strong></div>'
                : '');
        var cells = meta.querySelectorAll('.cm-cell strong');
        cells[0].textContent = next ? next.br : '—';
        cells[0].className = c.derived_status === 'overdue' ? 'text-danger' : '';
        cells[1].textContent = next ? FL.money(next.amount) : (c.installment_amount ? FL.money(c.installment_amount) : 'variável');
        cells[2].textContent = c.periodicity_label + (c.total_installments ? ' (' + (c.installments_paid || 0) + '/' + c.total_installments + ')' : '');
        if (cells[3]) { cells[3].textContent = FL.money(c.balance); }

        // progresso da dívida
        if (c.kind === 'debt' && c.total_amount) {
            var prog = document.createElement('div');
            prog.className = 'cm-progress';
            prog.innerHTML = '<div class="bar"><div class="bar-fill' + (c.progress_pct >= 100 ? ' success' : '') + '" style="width:' + Math.min(100, c.progress_pct) + '%"></div></div>' +
                '<small>' + FL.money(c.paid_total) + ' pagos de ' + FL.money(c.total_amount) + ' (' + pct(c.progress_pct) + ')</small>';
            meta.appendChild(prog);
        }
        if (c.overdue_count > 0) {
            var warn = document.createElement('div');
            warn.className = 'cm-overdue-warn';
            warn.textContent = '⚠ ' + c.overdue_count + ' vencimento(s) em atraso — ' + FL.money(c.overdue_amount);
            meta.appendChild(warn);
        }

        var actions = document.createElement('div');
        actions.className = 'cm-card-actions';
        if (c.status === 'active' && c.derived_status !== 'settled') {
            var pay = document.createElement('button');
            pay.type = 'button'; pay.className = 'btn btn-primary btn-sm'; pay.textContent = 'Registrar pagamento';
            pay.addEventListener('click', function () { openPay(c.id); });
            actions.appendChild(pay);
        }
        var detail = document.createElement('button');
        detail.type = 'button'; detail.className = 'btn btn-ghost btn-sm'; detail.textContent = 'Detalhes';
        detail.addEventListener('click', function () { openDetail(c.id); });
        actions.appendChild(detail);

        el.appendChild(head);
        el.appendChild(meta);
        el.appendChild(actions);
        return el;
    }

    function pct(v) { return (v === null || v === undefined) ? '—' : Number(v).toLocaleString('pt-BR') + '%'; }

    // ------------------------------------------------------------
    // Modal: pagamento
    // ------------------------------------------------------------
    function openPay(id, suggested) {
        var c = state.items.find(function (x) { return x.id === id; });
        if (!c) { return; }
        var next = c.next_occurrence;
        var suggestedAmount = suggested || (next ? next.amount : (c.balance !== null ? c.balance : (c.installment_amount || 0)));
        var maxDebt = c.balance !== null ? c.balance : null;

        var html =
            '<h3>' + FL.esc(c.name) + '</h3>' +
            '<p class="muted">' + FL.esc(c.payee) + (next ? ' · vence em ' + next.br : '') +
            (maxDebt !== null ? ' · saldo devedor ' + FL.money(maxDebt) : '') + '</p>' +
            '<div class="field"><label>Valor pago (R$)</label>' +
            '<input type="number" step="0.01" min="0.01" id="pay-amount" value="' + Number(suggestedAmount).toFixed(2) + '"' +
            (maxDebt !== null ? ' max="' + maxDebt.toFixed(2) + '"' : '') + ' required>' +
            (maxDebt !== null ? '<small class="hint">Pague menos que o total para um pagamento parcial.</small>' : '') + '</div>' +
            '<div class="field"><label>Data do pagamento</label><input type="date" id="pay-date" value="' + todayISO() + '" required></div>' +
            '<div class="field"><label>Conta utilizada (opcional)</label><select id="pay-account"><option value="">Sem conta</option>' +
            state.accounts.map(function (a) { return '<option value="' + a.id + '">' + FL.esc(a.name) + '</option>'; }).join('') + '</select></div>' +
            '<div class="field"><label>Observação (opcional)</label><input type="text" id="pay-note" maxlength="200" placeholder="Ex.: combinei pagar metade"></div>' +
            '<div class="modal-actions"><button type="button" class="btn btn-ghost" data-cancel>Cancelar</button>' +
            '<button type="button" class="btn btn-primary" id="pay-confirm">Confirmar pagamento</button></div>';

        FL.modal(html, function (body, close) {
            body.querySelector('[data-cancel]').addEventListener('click', close);
            body.querySelector('#pay-confirm').addEventListener('click', function () {
                var amount = parseFloat(body.querySelector('#pay-amount').value);
                if (!amount || amount <= 0) { FL.toastError('Informe um valor válido.'); return; }
                var btn = this;
                btn.disabled = true;
                FL.api('/api/commitments.php', {
                    method: 'POST',
                    body: {
                        action: 'pay', id: c.id,
                        amount: amount,
                        paid_date: body.querySelector('#pay-date').value,
                        account_id: body.querySelector('#pay-account').value || null,
                        description: body.querySelector('#pay-note').value.trim()
                    }
                }).then(function (r) {
                    btn.disabled = false;
                    if (!r.data || !r.data.ok) { FL.toastError((r.data && r.data.error) || 'Erro ao registrar.'); return; }
                    close();
                    if (r.data.settled) { FL.toastSuccess('Pagamento registrado — dívida quitada! 🎉'); }
                    else { FL.toastSuccess('Pagamento de ' + FL.money(amount) + ' registrado.'); }
                    load();
                });
            });
        });
    }

    // ------------------------------------------------------------
    // Modal: detalhes
    // ------------------------------------------------------------
    function openDetail(id) {
        FL.api('/api/commitments.php?action=get&id=' + id).then(function (r) {
            if (!r.data || !r.data.ok) { FL.toastError((r.data && r.data.error) || 'Erro ao carregar.'); return; }
            var c = r.data.commitment;
            var st = STATUS[c.derived_status] || STATUS.ontime;

            var html =
                '<h3>' + FL.esc(c.name) + ' <span class="badge badge-' + st.cls + '">' + st.label + '</span></h3>' +
                '<div class="cm-detail-grid">' +
                cell('Para quem', c.payee) +
                cell('Categoria', c.category_label) +
                cell('Frequência', c.periodicity_label + (c.periodicity === 'custom' && c.custom_days ? ' (a cada ' + c.custom_days + ' dias)' : '')) +
                cell('Próximo vencimento', c.next_occurrence ? c.next_occurrence.br : '—') +
                (c.kind === 'debt' ? cell('Dívida original', FL.money(c.total_amount)) : '') +
                (c.kind === 'debt' ? cell('Saldo devedor', FL.money(c.balance)) : '') +
                cell('Total pago', FL.money(c.paid_total)) +
                (c.account_name ? cell('Conta', c.account_name) : '') +
                (c.payment_method ? cell('Forma de pagamento', c.payment_method) : '') +
                (c.total_installments ? cell('Parcelas', (c.installments_paid || 0) + ' de ' + c.total_installments) : '') +
                (c.start_date ? cell('Início', brDate(c.start_date)) : '') +
                (c.end_date ? cell('Fim', brDate(c.end_date)) : '') +
                '</div>' +
                (c.kind === 'debt'
                    ? '<div class="cm-progress"><div class="bar"><div class="bar-fill' + (c.progress_pct >= 100 ? ' success' : '') + '" style="width:' + Math.min(100, c.progress_pct) + '%"></div></div><small>' + pct(c.progress_pct) + ' pago</small></div>'
                    : '') +
                (c.description ? '<p class="cm-note">' + FL.esc(c.description) + '</p>' : '') +
                '<div class="cm-section"><h4>Próximas ocorrências</h4><div class="cm-occs">' +
                (c.occurrences_future.length
                    ? c.occurrences_future.map(function (o) {
                        return '<div class="cm-occ"><span>' + brDate(o.due_date) + '</span><span class="cm-occ-amt">' + FL.money(o.amount - o.paid_amount) + '</span><span class="badge badge-muted">' + (o.status === 'partial' ? 'parcial' : 'pendente') + '</span></div>';
                    }).join('')
                    : '<p class="muted">Nenhuma pendência futura.</p>') +
                '</div></div>' +
                '<div class="cm-section"><h4>Histórico de pagamentos (' + c.payments.length + ')</h4><div class="cm-occs">' +
                (c.payments.length
                    ? c.payments.map(function (p) {
                        return '<div class="cm-occ"><span>' + FL.esc(p.paid_date) + '</span><span class="cm-occ-amt">' + FL.money(p.amount) + '</span>' +
                            '<button type="button" class="btn btn-ghost btn-sm" data-undo="' + p.id + '" title="Desfazer pagamento">↩</button></div>';
                    }).join('')
                    : '<p class="muted">Nenhum pagamento registrado ainda.</p>') +
                '</div></div>' +
                '<div class="modal-actions cm-detail-actions">' + buildActions(c) + '</div>';

            FL.modal(html, function (body, close) {
                body.querySelectorAll('[data-act]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var act = btn.getAttribute('data-act');
                        close();
                        if (act === 'edit') { openForm(c, 'all'); }
                        if (act === 'edit-occurrence') { openEditOccurrence(c); }
                        if (act === 'edit-future') { openForm(c, 'future'); }
                        if (act === 'pay') { openPay(c.id); }
                        if (act === 'pause' || act === 'resume' || act === 'settle') { setStatus(c.id, act); }
                        if (act === 'delete') { doDelete(c); }
                    });
                });
                body.querySelectorAll('[data-undo]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var pid = parseInt(btn.getAttribute('data-undo'), 10);
                        FL.confirm('Desfazer pagamento', 'O pagamento será removido, o saldo devedor restaurado e a transação correspondente excluída. Continuar?', true)
                            .then(function (ok) {
                                if (!ok) { return; }
                                FL.api('/api/commitments.php', { method: 'POST', body: { action: 'undo_payment', id: c.id, payment_id: pid } })
                                    .then(function (r2) {
                                        if (!r2.data || !r2.data.ok) { FL.toastError((r2.data && r2.data.error) || 'Erro.'); return; }
                                        FL.toastSuccess('Pagamento desfeito.');
                                        close();
                                        load();
                                    });
                            });
                    });
                });
            });
        });
    }

    function buildActions(c) {
        var out = '';
        if (c.status === 'active') {
            out += '<button type="button" class="btn btn-primary" data-act="pay">Registrar pagamento</button>';
            out += '<button type="button" class="btn btn-ghost" data-act="edit">Editar</button>';
            out += '<button type="button" class="btn btn-ghost" data-act="edit-occurrence">Alterar próxima ocorrência</button>';
            out += '<button type="button" class="btn btn-ghost" data-act="edit-future">Editar e aplicar às futuras</button>';
            out += '<button type="button" class="btn btn-ghost" data-act="pause">Pausar</button>';
            if (c.kind === 'debt' && c.balance > 0) {
                out += '<button type="button" class="btn btn-ghost" data-act="settle">Quitar dívida</button>';
            }
        } else if (c.status === 'paused') {
            out += '<button type="button" class="btn btn-primary" data-act="resume">Retomar</button>';
            out += '<button type="button" class="btn btn-ghost" data-act="edit">Editar</button>';
        } else if (c.status === 'settled') {
            out += '<button type="button" class="btn btn-ghost" disabled>Quitado — histórico preservado</button>';
        }
        if (!c.payments || !c.payments.length) {
            out += '<button type="button" class="btn btn-danger-ghost" data-act="delete">Excluir</button>';
        }
        return out;
    }

    function openEditOccurrence(c) {
        var next = c.next_occurrence;
        if (!next) { FL.toastError('Não há ocorrência pendente.'); return; }
        var html =
            '<h3>Alterar próxima ocorrência</h3>' +
            '<p class="muted">Vale somente para este vencimento — os futuros seguem a regra original.</p>' +
            '<div class="field"><label>Valor (R$)</label><input type="number" step="0.01" min="0.01" id="oe-amount" value="' + Number(next.amount).toFixed(2) + '" required></div>' +
            '<div class="field"><label>Data de vencimento</label><input type="date" id="oe-date" value="' + next.due_date + '" required></div>' +
            '<div class="modal-actions"><button type="button" class="btn btn-ghost" data-cancel>Cancelar</button>' +
            '<button type="button" class="btn btn-primary" data-save>Salvar</button></div>';
        FL.modal(html, function (body, close) {
            body.querySelector('[data-cancel]').addEventListener('click', close);
            body.querySelector('[data-save]').addEventListener('click', function () {
                FL.api('/api/commitments.php', {
                    method: 'POST',
                    body: {
                        action: 'update', id: c.id, scope: 'occurrence',
                        amount: parseFloat(body.querySelector('#oe-amount').value),
                        due_date: body.querySelector('#oe-date').value
                    }
                }).then(function (r) {
                    if (!r.data || !r.data.ok) { FL.toastError((r.data && r.data.error) || 'Erro.'); return; }
                    FL.toastSuccess('Ocorrência atualizada.');
                    close(); load();
                });
            });
        });
    }

    function setStatus(id, verb) {
        var labels = { pause: 'Pausar', resume: 'Retomar', settle: 'Quitar dívida' };
        var msgs = {
            pause: 'As notificações pausam e o compromisso sai da agenda até você retomar.',
            resume: 'O compromisso volta para a agenda de pagamentos.',
            settle: 'A dívida será marcada como quitada e os vencimentos futuros pendentes serão encerrados. O histórico de pagamentos é preservado.'
        };
        FL.confirm(labels[verb], msgs[verb], verb === 'settle').then(function (ok) {
            if (!ok) { return; }
            FL.api('/api/commitments.php', { method: 'POST', body: { action: 'set_status', id: id, verb: verb } })
                .then(function (r) {
                    if (!r.data || !r.data.ok) { FL.toastError((r.data && r.data.error) || 'Erro.'); return; }
                    FL.toastSuccess(labels[verb] + ' — feito.');
                    load();
                });
        });
    }

    function doDelete(c) {
        FL.confirm('Excluir compromisso', 'Só é possível excluir compromissos sem pagamentos registrados. Deseja excluir "' + c.name + '"?', true).then(function (ok) {
            if (!ok) { return; }
            FL.api('/api/commitments.php', { method: 'POST', body: { action: 'delete', id: c.id } })
                .then(function (r) {
                    if (!r.data || !r.data.ok) { FL.toastError((r.data && r.data.error) || 'Erro.'); return; }
                    FL.toastSuccess('Compromisso excluído.');
                    load();
                });
        });
    }

    // ------------------------------------------------------------
    // Modal: novo / editar
    // ------------------------------------------------------------
    function openForm(existing, scope) {
        state.editing = existing ? { id: existing.id, scope: scope || 'all' } : null;
        var c = existing || {};
        var isEdit = !!existing;
        var kind = c.kind || 'recurring';

        var html =
            '<h3>' + (isEdit ? 'Editar compromisso' : 'Novo compromisso') + '</h3>' +
            (isEdit && state.editing.scope === 'future' ? '<p class="muted">As alterações serão aplicadas a <strong>esta e às ocorrências futuras</strong>. Pagamentos feitos são preservados.</p>' : '') +
            '<div class="field"><label>Tipo</label><div class="cm-kind-toggle">' +
            '<label class="cm-kind"><input type="radio" name="cm-kind" value="debt"' + (kind === 'debt' ? ' checked' : '') + '><span><strong>💳 Dívida com total</strong><small>Ex.: R$ 1.200 ao pai, R$ 200/mês</small></span></label>' +
            '<label class="cm-kind"><input type="radio" name="cm-kind" value="recurring"' + (kind === 'recurring' ? ' checked' : '') + '><span><strong>🔄 Conta fixa</strong><small>Ex.: telefone R$ 80/mês, sem total</small></span></label>' +
            '</div></div>' +
            '<div class="field"><label>Nome do compromisso</label><input type="text" id="f-name" maxlength="120" value="' + FL.esc(c.name || '') + '" placeholder="Ex.: Dívida com o papai / Internet casa" required></div>' +
            '<div class="field"><label>Para quem (pessoa, empresa ou instituição)</label><input type="text" id="f-payee" maxlength="120" value="' + FL.esc(c.payee || '') + '" placeholder="Ex.: João (pai) / Vivo" required></div>' +
            '<div class="cm-debt-only">' +
            '<div class="field"><label>Valor total da dívida (R$)</label><input type="number" step="0.01" min="0" id="f-total" value="' + (c.total_amount || '') + '" placeholder="Ex.: 1200"></div>' +
            '<div class="field"><label>Total de parcelas estimadas (opcional)</label><input type="number" min="1" id="f-installments" value="' + (c.total_installments || '') + '" placeholder="Ex.: 6"></div>' +
            '</div>' +
            '<div class="field"><label>Valor de cada pagamento (R$) — deixe vazio para valor variável</label><input type="number" step="0.01" min="0" id="f-installment" value="' + (c.installment_amount || '') + '" placeholder="Ex.: 200"></div>' +
            '<div class="field-row">' +
            '<div class="field"><label>Frequência</label><select id="f-periodicity">' +
            Object.keys(PERIODICITY_LABELS).map(function (k) {
                return '<option value="' + k + '"' + ((c.periodicity || 'monthly') === k ? ' selected' : '') + '>' + PERIODICITY_LABELS[k] + '</option>';
            }).join('') + '</select></div>' +
            '<div class="field cm-custom-days" hidden><label>A cada (dias)</label><input type="number" min="1" id="f-custom-days" value="' + (c.custom_days || '') + '" placeholder="Ex.: 10"></div>' +
            '</div>' +
            '<div class="field-row">' +
            '<div class="field"><label>Próximo vencimento</label><input type="date" id="f-next-due" value="' + (c.next_due || todayISO()) + '" required></div>' +
            '<div class="field"><label>Data de início</label><input type="date" id="f-start" value="' + (c.start_date || todayISO()) + '"></div>' +
            '</div>' +
            '<div class="field"><label>Data final (opcional)</label><input type="date" id="f-end" value="' + (c.end_date || '') + '"></div>' +
            '<div class="field"><label>Categoria</label><select id="f-category-key">' +
            Object.keys(CATEGORY_LABELS).map(function (k) {
                return '<option value="' + k + '"' + ((c.category_key || 'outro') === k ? ' selected' : '') + '>' + CATEGORY_LABELS[k] + '</option>';
            }).join('') + '</select></div>' +
            '<div class="field"><label>Conta de origem (opcional)</label><select id="f-account"><option value="">Sem conta</option>' +
            state.accounts.map(function (a) { return '<option value="' + a.id + '"' + (c.account_id == a.id ? ' selected' : '') + '>' + FL.esc(a.name) + '</option>'; }).join('') + '</select></div>' +
            '<div class="field"><label>Forma de pagamento</label><select id="f-method"><option value="">Não definida</option>' +
            ['pix', 'dinheiro', 'debito', 'transferencia', 'boleto', 'credito', 'outro'].map(function (m) {
                return '<option value="' + m + '"' + (c.payment_method === m ? ' selected' : '') + '>' + m + '</option>';
            }).join('') + '</select></div>' +
            '<div class="field"><label>Descrição e observações</label><textarea id="f-desc" rows="2" maxlength="500" placeholder="Ex.: combinado em 05/03, pagar todo dia 10">' + FL.esc(c.description || '') + '</textarea></div>' +
            '<div class="modal-actions"><button type="button" class="btn btn-ghost" data-cancel>Cancelar</button>' +
            '<button type="button" class="btn btn-primary" data-save>' + (isEdit ? 'Salvar alterações' : 'Criar compromisso') + '</button></div>';

        FL.modal(html, function (body, close) {
            var kindRadios = body.querySelectorAll('input[name="cm-kind"]');
            var debtOnly = body.querySelector('.cm-debt-only');
            var customDays = body.querySelector('.cm-custom-days');
            var periodicity = body.querySelector('#f-periodicity');
            function syncKind() {
                var k = body.querySelector('input[name="cm-kind"]:checked').value;
                debtOnly.style.display = k === 'debt' ? '' : 'none';
            }
            function syncPeriodicity() {
                customDays.hidden = periodicity.value !== 'custom';
            }
            kindRadios.forEach(function (r) { r.addEventListener('change', syncKind); });
            periodicity.addEventListener('change', syncPeriodicity);
            syncKind(); syncPeriodicity();

            body.querySelector('[data-cancel]').addEventListener('click', close);
            body.querySelector('[data-save]').addEventListener('click', function () {
                var payload = {
                    action: isEdit ? 'update' : 'create',
                    kind: body.querySelector('input[name="cm-kind"]:checked').value,
                    name: body.querySelector('#f-name').value.trim(),
                    payee: body.querySelector('#f-payee').value.trim(),
                    description: body.querySelector('#f-desc').value.trim(),
                    category_key: body.querySelector('#f-category-key').value,
                    periodicity: periodicity.value,
                    custom_days: body.querySelector('#f-custom-days').value || null,
                    installment_amount: body.querySelector('#f-installment').value || null,
                    next_due: body.querySelector('#f-next-due').value,
                    start_date: body.querySelector('#f-start').value,
                    end_date: body.querySelector('#f-end').value || null,
                    account_id: body.querySelector('#f-account').value || null,
                    payment_method: body.querySelector('#f-method').value || null
                };
                if (payload.kind === 'debt') {
                    payload.total_amount = body.querySelector('#f-total').value || null;
                    payload.total_installments = body.querySelector('#f-installments').value || null;
                }
                if (isEdit) {
                    payload.id = c.id;
                    payload.scope = state.editing.scope === 'future' ? 'future' : 'all';
                }
                if (!payload.name || !payload.payee) { FL.toastError('Nome e pessoa/empresa são obrigatórios.'); return; }
                if (payload.kind === 'debt' && !payload.total_amount) { FL.toastError('Dívidas precisam do valor total.'); return; }

                var btn = this;
                btn.disabled = true;
                FL.api('/api/commitments.php', { method: 'POST', body: payload }).then(function (r) {
                    btn.disabled = false;
                    if (!r.data || !r.data.ok) { FL.toastError((r.data && r.data.error) || 'Erro ao salvar.'); return; }
                    close();
                    FL.toastSuccess(isEdit ? 'Compromisso atualizado.' : 'Compromisso criado.');
                    load();
                });
            });
        });
    }

    // ------------------------------------------------------------
    // Utilitários
    // ------------------------------------------------------------
    function todayISO() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function brDate(iso) { if (!iso) { return '—'; } var p = String(iso).split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
    function cell(label, value) { return '<div class="cm-detail-cell"><small>' + FL.esc(label) + '</small><strong>' + FL.esc(value === null || value === undefined || value === '' ? '—' : value) + '</strong></div>'; }
})();
