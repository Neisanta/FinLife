<?php
/** Layout principal do app (autenticado). Variáveis: $title, $active, $user, $content, $scripts */
if (!defined('FINLIFE')) { http_response_code(403); exit; }
/** @var string $title */
/** @var string $active */
/** @var array $user */
/** @var string $content */
/** @var array $scripts */
$nav = [
    'dashboard'    => ['url' => 'dashboard',   'label' => 'Dashboard',   'icon' => 'grid'],
    'transactions' => ['url' => 'transacoes',  'label' => 'Transações',  'icon' => 'swap'],
    'goals'        => ['url' => 'metas',       'label' => 'Metas',       'icon' => 'target'],
    'commitments'  => ['url' => 'compromissos', 'label' => 'Compromissos', 'icon' => 'calendar'],
    'categories'   => ['url' => 'categorias', 'label' => 'Categorias',  'icon' => 'tag'],
    'accounts'     => ['url' => 'contas',      'label' => 'Contas',      'icon' => 'wallet'],
    'cards'        => ['url' => 'cartoes',     'label' => 'Cartões',     'icon' => 'card'],
    'markets'      => ['url' => 'mercados',     'label' => 'Mercados',    'icon' => 'chart'],
    'ai'           => ['url' => 'ai',          'label' => 'FinLife AI',  'icon' => 'spark', 'badge' => 'AI'],
];
$navGroups = [
    'Visão geral'    => ['dashboard'],
    'Movimentações'  => ['transactions'],
    'Planejamento'   => ['commitments', 'goals', 'categories'],
    'Patrimônio'     => ['accounts', 'cards'],
    'Investimentos'  => ['markets'],
    'FinLife'        => ['ai'],
];
$icons = [
    'grid'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
    'swap'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10h13l-3-3M17 14H4l3 3"/></svg>',
    'wallet' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/><path d="M16 12h2"/></svg>',
    'card'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg>',
    'chart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/></svg>',
    'tag'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0l-7.2-7.2a2 2 0 0 1-.6-1.4V4.8A1.8 1.8 0 0 1 4.6 3h7.2a2 2 0 0 1 1.4.6l7.4 7.4a2 2 0 0 1 0 2.4z"/><circle cx="7.5" cy="7.5" r="1.2"/></svg>',
    'target' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/></svg>',
    'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18M8 15h.01M12 15h.01M16 15h.01"/></svg>',
    'spark' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/></svg>',
    'menu'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
    'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
    'sun'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
    'moon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#0A0912">
    <title><?= e($title ?? 'FinLife') ?> · <?= e(Env::get('APP_NAME', 'FinLife')) ?></title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/motion-layer.css')) ?>">
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
<body class="app">
<a class="fl-skip-link" href="#view">Pular para o conteúdo</a>
<div class="layout">
    <aside class="sidebar" id="sidebar" aria-label="Barra lateral">
        <div class="brand">
            <a class="brand-link" href="<?= e(url('dashboard')) ?>">
                <span class="brand-mark">₣</span>
                <span class="brand-text">
                    <strong>FinLife</strong>
                    <small>Gestão financeira</small>
                </span>
            </a>
        </div>

        <nav class="nav" aria-label="Navegação principal">
            <?php foreach ($navGroups as $groupLabel => $keys): ?>
                <div class="nav-group">
                    <span class="nav-group-label"><?= e($groupLabel) ?></span>
                    <?php foreach ($keys as $key): $item = $nav[$key]; ?>
                        <a href="<?= e(url($item['url'])) ?>" class="nav-link <?= ($active === $key) ? 'active' : '' ?>" <?= ($active === $key) ? 'aria-current="page"' : '' ?>>
                            <span class="nav-icon"><?= $icons[$item['icon']] ?></span>
                            <span><?= e($item['label']) ?></span>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="nav-badge"><?= e($item['badge']) ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-chip">
                <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
                <span class="user-meta">
                    <strong title="<?= e($user['name']) ?>"><?= e(explode(' ', trim($user['name']))[0]) ?></strong>
                    <small><?= e($user['email']) ?></small>
                </span>
            </div>
            <button type="button" class="btn btn-ghost btn-block" id="logout-btn">
                <span class="nav-icon"><?= $icons['logout'] ?></span> Sair
            </button>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <h1 class="page-title"><?= e($title ?? '') ?></h1>
            <div class="topbar-right" id="topbar-right">
                <button type="button" class="icon-btn theme-toggle" id="theme-toggle" aria-label="Alternar tema claro/escuro" title="Alternar tema">
                    <span class="icon-sun"><?= $icons['sun'] ?></span>
                    <span class="icon-moon"><?= $icons['moon'] ?></span>
                </button>
            </div>
        </header>

        <main class="content" id="view">
            <?= $content ?>
        </main>
    </div>
</div>

<nav class="bottom-nav" id="bottom-nav" aria-label="Navegação principal">
    <a href="<?= e(url('dashboard')) ?>" class="bn-item <?= ($active === 'dashboard') ? 'active' : '' ?>" <?= ($active === 'dashboard') ? 'aria-current="page"' : '' ?>>
        <span class="bn-icon"><?= $icons['grid'] ?></span>
        <span class="bn-label">Início</span>
    </a>
    <a href="<?= e(url('transacoes')) ?>" class="bn-item <?= ($active === 'transactions') ? 'active' : '' ?>" <?= ($active === 'transactions') ? 'aria-current="page"' : '' ?>>
        <span class="bn-icon"><?= $icons['swap'] ?></span>
        <span class="bn-label">Transações</span>
    </a>
    <button type="button" class="bn-fab" id="bn-fab" aria-label="Adicionar lançamento" aria-haspopup="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
    <a href="<?= e(url('mercados')) ?>" class="bn-item <?= ($active === 'markets') ? 'active' : '' ?>" <?= ($active === 'markets') ? 'aria-current="page"' : '' ?>>
        <span class="bn-icon"><?= $icons['chart'] ?></span>
        <span class="bn-label">Mercados</span>
    </a>
    <button type="button" class="bn-item" id="bn-more" aria-haspopup="true" aria-expanded="false">
        <span class="bn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/></svg></span>
        <span class="bn-label">Mais</span>
    </button>
</nav>

<div class="sheet-backdrop" id="sheet-backdrop" hidden></div>
<div class="bottom-sheet" id="bottom-sheet" hidden role="dialog" aria-modal="true"></div>

<div class="toasts" id="toasts" role="status" aria-live="polite"></div>
<div class="modal-overlay" id="modal-overlay" hidden></div>

<script>
    window.FL = {
        base: <?= json_encode(base_path()) ?>,
        csrf: <?= json_encode(csrf_token()) ?>,
        user: <?= json_encode(['name' => $user['name'], 'email' => $user['email'], 'budget' => $user['monthly_budget']]) ?>,
        month: <?= json_encode(date('Y-m')) ?>
    };
</script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<script src="<?= e(url('assets/js/motion.js')) ?>" defer></script>
<script src="<?= e(url('assets/js/charts.js')) ?>"></script>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e(url('assets/js/' . $s . '.js')) ?>" <?= (str_contains($s, 'pages/') ? 'defer' : '') ?>></script>
<?php endforeach; ?>
</body>
</html>
