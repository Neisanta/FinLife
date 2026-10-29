<?php
/**
 * FinLife — Controller de páginas (server-side).
 * As páginas renderizam o shell; os dados chegam via AJAX/REST.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class PageController
{
    public static function home(): never
    {
        if (Auth::user() !== null) {
            redirect('dashboard');
        }
        redirect('login');
    }

    public static function login(): never
    {
        if (Auth::user() !== null) {
            redirect('dashboard');
        }
        self::render('auth/login', [
            'title'  => 'Entrar — FinLife',
            'active' => 'login',
        ], layout: 'auth');
    }

    public static function register(): never
    {
        if (Auth::user() !== null) {
            redirect('dashboard');
        }
        self::render('auth/register', [
            'title'  => 'Criar conta — FinLife',
            'active' => 'register',
        ], layout: 'auth');
    }

    public static function logout(): never
    {
        Auth::logout();
        redirect('login');
    }

    public static function dashboard(): never
    {
        $user = Auth::requirePage();
        self::render('dashboard/index', [
            'title'   => 'Dashboard',
            'active'  => 'dashboard',
            'user'    => $user,
            'scripts' => ['pages/dashboard'],
        ]);
    }

    public static function transactions(): never
    {
        $user = Auth::requirePage();
        self::render('transactions/index', [
            'title'   => 'Transações',
            'active'  => 'transactions',
            'user'    => $user,
            'scripts' => ['pages/transactions'],
        ]);
    }

    public static function accounts(): never
    {
        $user = Auth::requirePage();
        self::render('accounts/index', [
            'title'   => 'Contas',
            'active'  => 'accounts',
            'user'    => $user,
            'scripts' => ['pages/accounts'],
        ]);
    }

    public static function cards(): never
    {
        $user = Auth::requirePage();
        self::render('cards/index', [
            'title'   => 'Cartões',
            'active'  => 'cards',
            'user'    => $user,
            'scripts' => ['pages/cards'],
        ]);
    }

    public static function commitments(): never
    {
        $user = Auth::requirePage();
        self::render('commitments/index', [
            'title'   => 'Compromissos e Contas Fixas',
            'active'  => 'commitments',
            'user'    => $user,
            'scripts' => ['pages/commitments'],
        ]);
    }

    public static function markets(): never
    {
        $user = Auth::requirePage();
        self::render('markets/index', [
            'title'   => 'Mercados',
            'active'  => 'markets',
            'user'    => $user,
            'scripts' => ['market-charts', 'pages/markets'],
        ]);
    }

    public static function categories(): never
    {
        $user = Auth::requirePage();
        self::render('categories/index', [
            'title'   => 'Categorias',
            'active'  => 'categories',
            'user'    => $user,
            'scripts' => ['pages/categories'],
        ]);
    }

    public static function goals(): never
    {
        $user = Auth::requirePage();
        self::render('goals/index', [
            'title'   => 'Metas',
            'active'  => 'goals',
            'user'    => $user,
            'scripts' => ['pages/goals'],
        ]);
    }

    public static function aiChat(): never
    {
        $user = Auth::requirePage();
        self::render('ai/chat', [
            'title'   => 'FinLife AI',
            'active'  => 'ai',
            'user'    => $user,
            'scripts' => ['pages/ai'],
        ]);
    }

    public static function notFound(): never
    {
        http_response_code(404);
        self::render('errors/404', [
            'title'  => 'Página não encontrada',
            'active' => '',
        ], layout: 'auth');
    }

    /**
     * Renderiza uma view dentro de um layout.
     * @param array<string,mixed> $data
     */
    private static function render(string $view, array $data, string $layout = 'app'): never
    {
        extract($data, EXTR_SKIP);
        $viewFile = APP_ROOT . '/views/' . $view . '.php';
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        require APP_ROOT . '/views/layouts/' . $layout . '.php';
        exit;
    }
}
