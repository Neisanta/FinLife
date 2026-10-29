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
        <h1>Bem-vindo de volta</h1>
        <p class="auth-sub">Entre para organizar sua vida financeira</p>
    </div>

    <form id="login-form" class="auth-form" novalidate>
        <div class="field field-float">
            <input type="email" id="email" name="email" class="input" autocomplete="email" placeholder=" " required maxlength="190">
            <label for="email">E-mail</label>
        </div>
        <div class="field field-float">
            <input type="password" id="password" name="password" class="input" autocomplete="current-password" placeholder=" " required minlength="8" maxlength="100">
            <label for="password">Senha</label>
        </div>

        <p class="form-error" id="form-error" hidden></p>

        <button type="submit" class="btn btn-primary btn-block" id="login-submit">Entrar</button>
    </form>

    <p class="auth-alt">Ainda não tem conta? <a href="<?= e(url('register')) ?>">Criar conta gratuita</a></p>
</section>
<p class="auth-footnote">FinLife · seu assistente financeiro pessoal</p>
