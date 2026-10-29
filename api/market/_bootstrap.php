<?php
/**
 * FinLife — Bootstrapping comum das rotas /api/market/*.
 * Não é acessível diretamente (bloqueado em _guard abaixo).
 */
declare(strict_types=1);

// Guard: nega acesso direto ao arquivo
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === '_bootstrap.php') {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

if (!defined('FINLIFE')) {
    define('FINLIFE', true);
}
require __DIR__ . '/../../includes/bootstrap.php';

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$user = Auth::user();
if ($user === null) {
    json_out(['ok' => false, 'error' => 'Não autenticado.'], 401);
}
$MARKET_USER = $user;
$MARKET_USER_ID = (int) $user['id'];

/** Rate limit global do módulo para o usuário (protege os provedores). */
if (RateLimiter::hit('market:user:' . $MARKET_USER_ID, 240, 60)) {
    json_out(['ok' => false, 'error' => 'Muitas consultas em sequência. Aguarde alguns segundos.'], 429);
}

/** helper: action da query */
function market_action(): string
{
    return Validator::text($_GET['action'] ?? '', 30);
}
