<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
$icoPlus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>';
$icoArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
?>
<div class="page-head">
    <p class="muted">Central de cartões: crédito, débito e benefícios — limites, faturas, parcelas e assinaturas em um só lugar.</p>
    <div class="page-head-actions">
        <button class="btn btn-primary" id="card-new"><?= $icoPlus ?> Novo cartão</button>
    </div>
</div>

<section id="cards-alerts" aria-live="polite"></section>

<section class="stat-hero cards-hero" id="cards-hero">
    <span class="stat-label">Visão geral dos cartões</span>
    <div class="cards-hero-grid" id="cards-hero-grid"></div>
</section>

<section class="cards-grid" id="cards-grid" aria-label="Meus cartões"></section>

<nav class="tabs" id="cards-tabs" role="tablist" aria-label="Seções de cartões">
    <button type="button" class="tab-link active" data-tab="purchases" role="tab">Compras</button>
    <button type="button" class="tab-link" data-tab="invoices" role="tab">Faturas</button>
    <button type="button" class="tab-link" data-tab="installments" role="tab">Parcelas</button>
    <button type="button" class="tab-link" data-tab="subscriptions" role="tab">Assinaturas</button>
    <button type="button" class="tab-link" data-tab="benefits" role="tab">Benefícios</button>
    <button type="button" class="tab-link" data-tab="reports" role="tab">Relatórios</button>
    <button type="button" class="tab-link" data-tab="calendar" role="tab">Calendário</button>
</nav>

<!-- ==================== COMPRAS ==================== -->
<section class="tab-panel active" id="tab-purchases" role="tabpanel">
    <section class="toolbar" aria-label="Filtros de compras">
        <div class="filter">
            <label for="p-filter-card">Cartão</label>
            <select id="p-filter-card" class="input"><option value="">Todos</option></select>
        </div>
        <div class="filter">
            <label for="p-filter-method">Forma</label>
            <select id="p-filter-method" class="input">
                <option value="">Todas</option>
                <option value="credit">Crédito</option>
                <option value="debit">Débito</option>
                <option value="benefit">Benefício</option>
            </select>
        </div>
        <div class="filter">
            <label for="p-filter-month">Mês</label>
            <input type="month" id="p-filter-month" class="input" value="<?= e(date('Y-m')) ?>">
        </div>
        <div class="filter filter-growable">
            <label for="p-filter-q">Buscar</label>
            <input type="search" id="p-filter-q" class="input" placeholder="descrição ou estabelecimento…">
        </div>
        <div class="page-head-actions">
            <button class="btn btn-primary" id="purchase-new"><?= $icoPlus ?> Nova compra</button>
        </div>
    </section>
    <section class="card table-card">
        <div id="purchases-list"></div>
        <div class="table-foot"><span class="muted small" id="purchases-count"></span></div>
    </section>
</section>

<!-- ==================== FATURAS ==================== -->
<section class="tab-panel" id="tab-invoices" role="tabpanel">
    <section class="toolbar">
        <div class="filter">
            <label for="inv-filter-card">Cartão</label>
            <select id="inv-filter-card" class="input"><option value="">Todos</option></select>
        </div>
        <span class="muted small" id="invoices-hint">Cada cartão de crédito tem seu próprio ciclo: fechamento → vencimento.</span>
    </section>
    <div id="invoices-list"></div>
</section>

<!-- ==================== PARCELAS ==================== -->
<section class="tab-panel" id="tab-installments" role="tabpanel">
    <div class="page-head">
        <p class="muted" id="installments-summary">Parcelas futuras comprometidas nos próximos meses.</p>
    </div>
    <div id="installments-timeline"></div>
</section>

<!-- ==================== ASSINATURAS ==================== -->
<section class="tab-panel" id="tab-subscriptions" role="tabpanel">
    <section class="toolbar">
        <div class="subs-total" id="subs-total"></div>
        <div class="page-head-actions">
            <button class="btn btn-primary" id="subscription-new"><?= $icoPlus ?> Nova assinatura</button>
        </div>
    </section>
    <div id="subscriptions-list"></div>
</section>

<!-- ==================== BENEFÍCIOS ==================== -->
<section class="tab-panel" id="tab-benefits" role="tabpanel">
    <div class="page-head">
        <p class="muted">Saldos de benefícios (VA, VR, combustível, mobilidade…) controlados separadamente — nunca misturados com o saldo das contas.</p>
    </div>
    <div id="benefits-list"></div>
</section>

<!-- ==================== RELATÓRIOS ==================== -->
<section class="tab-panel" id="tab-reports" role="tabpanel">
    <section class="toolbar">
        <div class="filter">
            <label for="rep-month">Mês</label>
            <input type="month" id="rep-month" class="input" value="<?= e(date('Y-m')) ?>">
        </div>
    </section>
    <section class="grid-2">
        <article class="card chart-card">
            <div class="card-head"><div class="card-head-text"><h2>Compras por cartão</h2><span class="muted" id="rep-month-label"></span></div></div>
            <div class="chart-wrap" id="rep-by-card"></div>
            <div class="legend" id="rep-by-card-legend"></div>
        </article>
        <article class="card chart-card">
            <div class="card-head"><div class="card-head-text"><h2>Compras por categoria</h2><span class="muted">Onde o dinheiro dos cartões foi</span></div></div>
            <div class="chart-wrap" id="rep-by-cat"></div>
            <div class="legend" id="rep-by-cat-legend"></div>
        </article>
    </section>
    <section class="card chart-card">
        <div class="card-head"><div class="card-head-text"><h2>Evolução das compras</h2><span class="muted">Últimos 6 meses</span></div></div>
        <div class="chart-wrap" id="rep-evolution"></div>
        <div class="legend row" id="rep-evolution-legend"></div>
    </section>
    <section class="grid-2">
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>Parcelamentos ativos</h2><span class="muted" id="rep-plans-total"></span></div></div>
            <div id="rep-plans"></div>
        </article>
        <article class="card">
            <div class="card-head"><div class="card-head-text"><h2>Limites</h2><span class="muted">Por cartão de crédito</span></div></div>
            <div id="rep-limits"></div>
        </article>
    </section>
</section>

<!-- ==================== CALENDÁRIO ==================== -->
<section class="tab-panel" id="tab-calendar" role="tabpanel">
    <section class="toolbar">
        <div class="filter">
            <label for="cal-month">Mês</label>
            <input type="month" id="cal-month" class="input" value="<?= e(date('Y-m')) ?>">
        </div>
        <div class="cal-legend">
            <span class="cal-legend-item"><span class="dot" style="background:var(--primary)"></span> Fechamento</span>
            <span class="cal-legend-item"><span class="dot" style="background:var(--danger)"></span> Vencimento</span>
            <span class="cal-legend-item"><span class="dot" style="background:var(--success-bright)"></span> Parcela</span>
            <span class="cal-legend-item"><span class="dot" style="background:var(--warning)"></span> Assinatura</span>
            <span class="cal-legend-item"><span class="dot" style="background:var(--text-2)"></span> Compra/crédito</span>
        </div>
    </section>
    <section class="card">
        <div class="calendar-head" id="calendar-head"></div>
        <div class="calendar-grid" id="calendar-grid"></div>
    </section>
</section>
