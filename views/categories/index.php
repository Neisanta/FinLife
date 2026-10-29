<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
$icoPlus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>';
$icoOut = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7v10m0 0-4-4m4 4 4-4"/><path d="M17 17V7m0 0 4 4m-4-4-4 4"/></svg>';
$icoIn = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 7v10m0 0 4-4m-4 4-4-4"/><path d="M7 17V7m0 0L3 11m4-4 4 4"/></svg>';
?>
<div class="page-head">
    <p class="muted">Organize seus lançamentos. Você pode criar categorias livres a qualquer momento.</p>
    <div class="page-head-actions">
        <button class="btn btn-primary" id="cat-new"><?= $icoPlus ?> Nova categoria</button>
    </div>
</div>

<section class="grid-2">
    <article class="card">
        <div class="card-head">
            <div class="cat-type-head">
                <span class="cat-type-icon tint-danger" aria-hidden="true"><?= $icoOut ?></span>
                <h2>Despesas</h2>
            </div>
            <span class="muted small" id="cat-count-expense"></span>
        </div>
        <div class="chips" id="cat-list-expense"><p class="empty">Carregando…</p></div>
    </article>
    <article class="card">
        <div class="card-head">
            <div class="cat-type-head">
                <span class="cat-type-icon tint-success" aria-hidden="true"><?= $icoIn ?></span>
                <h2>Receitas</h2>
            </div>
            <span class="muted small" id="cat-count-income"></span>
        </div>
        <div class="chips" id="cat-list-income"><p class="empty">Carregando…</p></div>
    </article>
</section>
