<?php
/**
 * FinLife — API de autenticação.
 *
 * POST  ?action=register  {name, email, password, password_confirm}
 * POST  ?action=login     {email, password}
 * POST  ?action=logout
 * GET   ?action=me
 * GET   ?action=csrf
 */
declare(strict_types=1);

define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET') {
        if ($action === 'me') {
            $user = Auth::user();
            if ($user === null) {
                json_out(['ok' => false, 'error' => 'Não autenticado.'], 401);
            }
            json_out(['ok' => true, 'user' => $user, 'csrf' => csrf_token()]);
        }
        if ($action === 'csrf') {
            csrf_token();
            json_out(['ok' => true, 'csrf' => csrf_token()]);
        }
        json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }

    if ($method !== 'POST') {
        json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
    }

    $body = json_input() ?? [];

    if ($action === 'register') {
        // rate limit: 5 cadastros por hora por IP
        if (RateLimiter::hit('register:ip:' . client_ip(), 5, 3600)) {
            json_out(['ok' => false, 'error' => 'Muitas tentativas de cadastro. Tente novamente mais tarde.'], 429);
        }
        if (!csrf_verify()) {
            json_out(['ok' => false, 'error' => 'Token CSRF inválido. Recarregue a página.'], 403);
        }

        $confirm = (string) ($body['password_confirm'] ?? $body['password'] ?? '');
        $result = Auth::register(
            (string) ($body['name'] ?? ''),
            (string) ($body['email'] ?? ''),
            (string) ($body['password'] ?? '')
        );
        if (!$result['ok']) {
            json_out(['ok' => false, 'error' => $result['error']], 422);
        }
        if ($confirm !== (string) ($body['password'] ?? '')) {
            json_out(['ok' => false, 'error' => 'As senhas não conferem.'], 422);
        }

        $user = User::findById((int) $result['user_id']);
        Auth::login($user);
        json_out(['ok' => true, 'redirect' => 'dashboard', 'csrf' => csrf_token()]);
    }

    if ($action === 'login') {
        // rate limit: 8 tentativas por 15 minutos por IP+e-mail
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        if (RateLimiter::hit('login:ip:' . client_ip() . ':' . substr(md5($email), 0, 12), 8, 900)) {
            json_out(['ok' => false, 'error' => 'Muitas tentativas de login. Aguarde 15 minutos.'], 429);
        }

        $user = Auth::attempt($email, (string) ($body['password'] ?? ''));
        if ($user === false) {
            // mensagem genérica: não revela se o e-mail existe
            json_out(['ok' => false, 'error' => 'E-mail ou senha incorretos.'], 401);
        }
        Auth::login($user);
        json_out(['ok' => true, 'redirect' => 'dashboard', 'csrf' => csrf_token()]);
    }

    if ($action === 'logout') {
        csrf_guard();
        Auth::logout();
        json_out(['ok' => true, 'redirect' => 'login']);
    }

    json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    log_error('API auth: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
