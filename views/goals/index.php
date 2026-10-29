<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
$icoPlus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>';
?>
<div class="page-head">
    <p class="muted">Defina objetivos e acompanhe o progresso com aportes. O FinLife sugere o aporte mensal ideal.</p>
    <div class="page-head-actions">
        <button class="btn btn-primary" id="goal-new"><?= $icoPlus ?> Nova meta</button>
    </div>
</div>

<section class="goals-grid" id="goals-grid">
    <p class="empty">Carregando…</p>
</section>
