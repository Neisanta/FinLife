<?php
/** Layout para telas de autenticação (visitantes). Variáveis: $title, $content */
if (!defined('FINLIFE')) { http_response_code(403); exit; }
/** @var string $title */
/** @var string $content */
$icons = [
    'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#0A0912">
    <title><?= e($title ?? 'FinLife') ?></title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <script>
    (function () {
        try {
            var t = null;
            try { t = localStorage.getItem('fl-theme'); } catch (e) {}
            if (t !== 'dark' && t !== 'light') {
                t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    })();
    </script>
</head>
<body class="auth-body">
<div class="auth-shell">
    <aside class="auth-aside" aria-hidden="true">
        <div class="auth-aside-inner">
            <div class="brand">
                <span class="brand-link">
                    <span class="brand-mark">₣</span>
                    <span class="brand-text">
                        <strong>FinLife</strong>
                        <small>Gestão financeira</small>
                    </span>
                </span>
            </div>

            <div class="auth-aside-copy">
                <h1>Sua vida financeira,<br>finalmente organizada.</h1>
                <p>Controle gastos, planeje metas e converse com um assistente que entende seus números reais — sem planilhas.</p>
            </div>

            <ul class="auth-features">
                <li><span class="feat-check"><?= $icons['check'] ?></span> Visão completa do mês em um só lugar</li>
                <li><span class="feat-check"><?= $icons['check'] ?></span> Registro de transações em segundos</li>
                <li><span class="feat-check"><?= $icons['check'] ?></span> Metas com aporte mensal calculado</li>
                <li><span class="feat-check"><?= $icons['check'] ?></span> Assistente financeiro com seus dados reais</li>
            </ul>

            <p class="auth-aside-foot">Dados isolados por conta · senha protegida</p>
        </div>
    </aside>

    <main class="auth-main">
        <div class="auth-wrap">
            <?= $content ?>
        </div>
    </main>
</div>

<div class="toasts" id="toasts" role="status" aria-live="polite"></div>
<script>
    window.FL = { base: <?= json_encode(base_path()) ?>, csrf: <?= json_encode(csrf_token()) ?> };
</script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
