<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
$icoPlus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>';
?>
<div class="page-head">
    <p class="muted">Suas contas e carteiras. O saldo é calculado automaticamente pelas transações.</p>
    <div class="page-head-actions">
        <button class="btn btn-primary" id="acc-new"><?= $icoPlus ?> Nova conta</button>
    </div>
</div>

<section class="stat-hero" aria-label="Saldo consolidado">
    <span class="stat-label">Saldo consolidado</span>
    <p class="stat-value" id="acc-total">R$ 0,00</p>
    <p class="stat-hint" id="acc-count">—</p>
</section>

<section class="accounts-grid" id="accounts-grid">
    <p class="empty">Carregando…</p>
</section>
