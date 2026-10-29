<?php
/**
 * FinLife — API REST de contas.
 *
 * GET    /api/accounts.php
 * POST   {name, type, institution, initial_balance, color}
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
        $accounts = Account::list($userId);
        json_out([
            'ok' => true,
            'accounts' => array_map(static function ($a) {
                $a['initial_balance'] = (float) $a['initial_balance'];
                $a['current_balance'] = (float) $a['current_balance'];
                $a['tx_count'] = (int) $a['tx_count'];
                return $a;
            }, $accounts),
            'total_balance' => Account::totalBalance($userId),
        ]);
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        if (Validator::text($body['name'] ?? '', 80) === '') {
            json_out(['ok' => false, 'error' => 'Informe o nome da conta.'], 422);
        }
        $id = Account::create($userId, $body);
        json_out(['ok' => true, 'id' => $id], 201);
    }

    if ($method === 'PUT') {
        csrf_guard();
        $body = json_input();
        $id = Validator::positiveInt($body['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        if (Account::update($userId, $id, $body ?? [])) {
            json_out(['ok' => true, 'id' => $id]);
        }
        json_out(['ok' => false, 'error' => 'Conta não encontrada.'], 404);
    }

    if ($method === 'DELETE') {
        csrf_guard();
        $id = Validator::positiveInt($_GET['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        if (!Account::delete($userId, $id)) {
            json_out(['ok' => false, 'error' => 'Conta não encontrada.'], 404);
        }
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API accounts: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
