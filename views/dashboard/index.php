<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
$icoUp = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 7v10m0 0 4-4m-4 4-4-4"/><path d="M7 17V7m0 0L3 11m4-4 4 4"/></svg>';
$icoDown = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7v10m0 0-4-4m4 4 4-4"/><path d="M17 17V7m0 0 4 4m-4-4-4 4"/></svg>';
$icoCard = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg>';
$icoChart = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/></svg>';
$icoGauge = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="m13.4 10.6 4-4"/><path d="M20.2 17.5a9 9 0 1 0-16.4 0"/></svg>';
$icoSpark = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/></svg>';
$icoArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
?>
<div class="dash-hero">
    <div class="dash-hero-copy">
        <h2 class="display-hello" id="dash-greeting">Olá!</h2>
        <p class="muted">Aqui está a visão real das suas finanças.</p>
    </div>
    <div class="dash-hero-actions">
        <input type="month" id="dash-month" class="input month-input" value="<?= e(date('Y-m')) ?>" aria-label="Mês de referência">
    </div>
</div>

<section class="balance-hero" aria-label="Saldo e resumo do mês">
    <div class="bh-top">
        <span class="bh-label">Saldo disponível</span>
        <span class="stat-badge" id="badge-balance">—</span>
    </div>
    <p class="bh-value" id="stat-balance">R$ 0,00</p>
    <p class="bh-hint">Soma de todas as suas contas</p>
    <div class="bh-row">
        <div class="bh-cell">
            <small>Receitas do mês</small>
            <strong class="income" id="stat-income">R$ 0,00</strong>
            <span class="bh-sub" id="stat-income-prev">—</span>
        </div>
        <div class="bh-cell">
            <small>Despesas do mês</small>
            <strong class="expense" id="stat-expense">R$ 0,00</strong>
            <span class="bh-sub" id="stat-expense-prev">—</span>
        </div>
        <div class="bh-cell">
            <small>Sobra do mês</small>
            <strong id="stat-net">R$ 0,00</strong>
            <span class="bh-sub" id="stat-savings-rate">—</span>
            <span class="stat-badge" id="badge-savings">—</span>
        </div>
    </div>
</section>

<section class="quick-actions" aria-label="Ações rápidas">
    <button type="button" class="qa-btn tint-success" data-qa="income">
        <span class="qa-ico" aria-hidden="true"><?= $icoUp ?></span>Receita
    </button>
    <button type="button" class="qa-btn tint-danger" data-qa="expense">
        <span class="qa-ico" aria-hidden="true"><?= $icoDown ?></span>Despesa
    </button>
    <a class="qa-btn" href="<?= e(url('cartoes')) ?>">
        <span class="qa-ico" aria-hidden="true"><?= $icoCard ?></span>Cartões
    </a>
    <a class="qa-btn" href="<?= e(url('mercados')) ?>">
        <span class="qa-ico" aria-hidden="true"><?= $icoChart ?></span>Mercados
    </a>
</section>

<section class="budget-strip" id="budget-card" hidden>
    <div class="budget-left">
        <span class="budget-icon" aria-hidden="true"><?= $icoGauge ?></span>
        <div class="budget-info">
            <h2>Orçamento mensal</h2>
            <p id="budget-text">—</p>
        </div>
    </div>
    <div class="budget-right">
        <div class="budget-meter">
            <div class="bar"><div class="bar-fill" id="budget-bar"></div></div>
            <span class="budget-pct" id="budget-pct">—</span>
        </div>
        <button class="btn btn-soft btn-sm" id="budget-edit">Ajustar</button>
    </div>
</section>

<section class="ai-insight" aria-label="Resumo com IA">
    <div class="ai-insight-head">
        <div class="ai-insight-title">
            <span class="ai-insight-icon" aria-hidden="true"><?= $icoSpark ?></span>
            <h2>Resumo com IA</h2>
            <span class="chip" id="ai-mode-chip">verificando…</span>
        </div>
        <button class="btn btn-primary btn-sm" id="ai-summary-btn">Gerar resumo inteligente</button>
    </div>
    <div class="ai-insight-body" id="ai-summary">
        <p class="muted">Peça ao FinLife AI uma análise do seu mês — sempre baseada nos seus dados reais.</p>
    </div>
</section>

<section class="card chart-card">
    <div class="card-head">
        <div class="card-head-text">
            <h2>Evolução financeira</h2>
            <span class="muted">Receitas e despesas dos últimos 6 meses</span>
        </div>
    </div>
    <div class="chart-wrap" id="bars-container">
        <p class="empty">Sem histórico ainda.</p>
    </div>
    <div class="legend row" id="bars-legend"></div>
</section>

<section class="grid-2">
    <article class="card">
        <div class="card-head">
            <div class="card-head-text">
                <h2>Gastos por categoria</h2>
                <span class="muted" id="chart-month-label"></span>
            </div>
        </div>
        <div class="chart-wrap" id="donut-container">
            <p class="empty">Sem despesas neste mês.</p>
        </div>
        <div class="legend" id="donut-legend"></div>
    </article>

    <article class="card">
        <div class="card-head">
            <div class="card-head-text">
                <h2>Metas</h2>
                <span class="muted">Progresso dos seus objetivos</span>
            </div>
            <a class="link-arrow" href="<?= e(url('metas')) ?>">Ver todas <?= $icoArrow ?></a>
        </div>
        <div id="dash-goals" class="stack"></div>
    </article>
</section>

<section class="card">
    <div class="card-head">
        <div class="card-head-text">
            <h2>Lançamentos recentes</h2>
            <span class="muted">Suas últimas movimentações</span>
        </div>
        <a class="link-arrow" href="<?= e(url('transacoes')) ?>">Ver todas <?= $icoArrow ?></a>
    </div>
    <div id="dash-recent" class="tx-mini-list"></div>
</section>
