<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
$icoPlus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>';
?>
<div class="page-head">
    <p class="muted">Registre receitas e despesas — pelo formulário ou pelo chat do FinLife AI.</p>
    <div class="page-head-actions">
        <button class="btn btn-primary" id="tx-new"><?= $icoPlus ?> Nova transação</button>
    </div>
</div>

<section class="toolbar" aria-label="Filtros de transações">
    <div class="filter">
        <label for="f-month">Mês</label>
        <input type="month" id="f-month" class="input" value="<?= e(date('Y-m')) ?>">
    </div>
    <div class="filter">
        <label for="f-type">Tipo</label>
        <select id="f-type" class="input">
            <option value="">Todos</option>
            <option value="income">Receitas</option>
            <option value="expense">Despesas</option>
        </select>
    </div>
    <div class="filter">
        <label for="f-category">Categoria</label>
        <select id="f-category" class="input"><option value="">Todas</option></select>
    </div>
    <div class="filter">
        <label for="f-account">Conta</label>
        <select id="f-account" class="input"><option value="">Todas</option></select>
    </div>
    <div class="filter filter-growable">
        <label for="f-q">Buscar</label>
        <input type="search" id="f-q" class="input" placeholder="descrição…">
    </div>
    <div class="toolbar-totals" id="tx-totals"></div>
</section>

<section class="card table-card">
    <div class="table-wrap">
        <table class="table" id="tx-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Categoria</th>
                    <th>Conta</th>
                    <th class="right">Valor</th>
                    <th class="right">Ações</th>
                </tr>
            </thead>
            <tbody id="tx-tbody">
                <tr class="skeleton-row"><td colspan="6"><div class="skeleton"></div></td></tr>
                <tr class="skeleton-row"><td colspan="6"><div class="skeleton"></div></td></tr>
                <tr class="skeleton-row"><td colspan="6"><div class="skeleton"></div></td></tr>
                <tr class="skeleton-row"><td colspan="6"><div class="skeleton"></div></td></tr>
                <tr class="skeleton-row"><td colspan="6"><div class="skeleton"></div></td></tr>
                <tr class="skeleton-row"><td colspan="6"><div class="skeleton"></div></td></tr>
            </tbody>
        </table>
    </div>
    <div class="table-foot">
        <span class="muted small" id="tx-count"></span>
    </div>
</section>
