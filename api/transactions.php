<?php
/**
 * FinLife — API REST de transações.
 *
 * GET    /api/transactions.php?month=YYYY-MM&type=&category_id=&account_id=&q=&limit=&offset=
 * POST   {account_id, category_id, type, amount, description, date}
 * PUT    {id, ...campos}
 * DELETE ?id=
 */
declare(strict_types=1);

define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

header('Cache-Control: no-store');
$user = Auth::user();
if ($user === null) {
    json_out(['ok' => false, 'error' => 'Não autenticado.'], 401);
}
$userId = (int) $user['id'];

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $rows = Transaction::list($userId, [
            'month'       => $_GET['month'] ?? null,
            'type'        => $_GET['type'] ?? null,
            'category_id' => $_GET['category_id'] ?? null,
            'account_id'  => $_GET['account_id'] ?? null,
            'q'           => $_GET['q'] ?? null,
            'limit'       => $_GET['limit'] ?? 100,
            'offset'      => $_GET['offset'] ?? 0,
        ]);
        $month = $_GET['month'] ?? month_now();
        json_out([
            'ok'    => true,
            'transactions' => $rows,
            'totals' => Transaction::monthTotals($userId, $month),
            'month' => $month,
        ]);
    }

    if ($method === 'POST') {
        csrf_guard();
        if (RateLimiter::hit('tx:user:' . $userId, 120, 60)) {
            json_out(['ok' => false, 'error' => 'Muitas requisições. Aguarde alguns segundos.'], 429);
        }
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        $result = Transaction::create($userId, $body);
        if (!$result['ok']) {
            json_out($result, 422);
        }
        json_out($result, 201);
    }

    if ($method === 'PUT') {
        csrf_guard();
        $body = json_input();
        $id = Validator::positiveInt($body['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        $result = Transaction::update($userId, $id, $body ?? []);
        if (!$result['ok']) {
            json_out($result, 422);
        }
        json_out($result);
    }

    if ($method === 'DELETE') {
        csrf_guard();
        $id = Validator::positiveInt($_GET['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        if (!Transaction::delete($userId, $id)) {
            json_out(['ok' => false, 'error' => 'Transação não encontrada.'], 404);
        }
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API transactions: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
