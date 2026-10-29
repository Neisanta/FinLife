<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
?>
<section class="auth-card center">
    <div class="auth-brand">
        <span class="brand-mark big">404</span>
    </div>
    <div class="auth-title" style="text-align:center">
        <h1>Página não encontrada</h1>
        <p class="auth-sub">O endereço que você tentou acessar não existe.</p>
    </div>
    <a class="btn btn-primary btn-block" href="<?= e(url('dashboard')) ?>">Ir para o Dashboard</a>
    <a class="btn btn-ghost btn-block" style="margin-top:10px" href="<?= e(url('login')) ?>">Tela de login</a>
</section>
