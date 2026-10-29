<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
/** @var string $title */
?>
<section class="auth-card">
    <div class="auth-brand">
        <span class="brand-mark big">₣</span>
        <h1>FinLife</h1>
        <p class="auth-sub">Seu assistente financeiro pessoal</p>
    </div>

    <div class="auth-title">
        <h1>Comece agora</h1>
        <p class="auth-sub">Crie sua conta e cuide do seu dinheiro</p>
    </div>

    <form id="register-form" class="auth-form" novalidate>
        <div class="field field-float">
            <input type="text" id="name" name="name" class="input" autocomplete="name" placeholder=" " required minlength="2" maxlength="120">
            <label for="name">Nome completo</label>
        </div>
        <div class="field field-float">
            <input type="email" id="email" name="email" class="input" autocomplete="email" placeholder=" " required maxlength="190">
            <label for="email">E-mail</label>
        </div>
        <div class="field field-float">
            <input type="password" id="password" name="password" class="input" autocomplete="new-password" placeholder=" " required minlength="8" maxlength="100">
            <label for="password">Senha</label>
            <div class="pw-meter" aria-hidden="true"><div id="pw-bar"></div></div>
        </div>
        <div class="field field-float">
            <input type="password" id="password_confirm" name="password_confirm" class="input" autocomplete="new-password" placeholder=" " required minlength="8" maxlength="100">
            <label for="password_confirm">Confirmar senha</label>
        </div>

        <p class="form-error" id="form-error" hidden></p>

        <button type="submit" class="btn btn-primary btn-block" id="register-submit">Criar conta</button>
    </form>

    <p class="auth-alt">Já tem conta? <a href="<?= e(url('login')) ?>">Entrar</a></p>
</section>
<p class="auth-footnote">Seus dados ficam isolados por conta e protegidos com senha criptografada.</p>
