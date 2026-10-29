<?php
/**
 * FinLife — API REST de metas.
 *
 * GET    /api/goals.php                       → lista com progresso
 * POST   {name, target_amount, deadline}       → cria
 * POST   ?action=deposit {goal_id, amount, date, note} → aporte
 * PUT    {id, name, target_amount, deadline}
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
    $action = $_GET['action'] ?? '';

    if ($method === 'GET') {
        if ($action === 'deposits') {
            $goalId = Validator::positiveInt($_GET['goal_id'] ?? null);
            if ($goalId === null) {
                json_out(['ok' => false, 'error' => 'Meta inválida.'], 400);
            }
            $rows = Goal::deposits($userId, $goalId);
            foreach ($rows as &$r) { $r['amount'] = (float) $r['amount']; }
            json_out(['ok' => true, 'deposits' => $rows]);
        }
        $goals = Goal::list($userId);
        foreach ($goals as &$g) {
            $g['target_amount'] = (float) $g['target_amount'];
        }
        json_out(['ok' => true, 'goals' => $goals]);
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        if ($action === 'deposit') {
            $goalId = Validator::positiveInt($body['goal_id'] ?? null);
            if ($goalId === null) {
                json_out(['ok' => false, 'error' => 'Meta inválida.'], 400);
            }
            $result = Goal::addDeposit($userId, $goalId, $body);
            if (!$result['ok']) {
                json_out($result, 422);
            }
            json_out($result, 201);
        }
        $result = Goal::create($userId, $body);
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
        $result = Goal::update($userId, $id, $body ?? []);
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
        if (!Goal::delete($userId, $id)) {
            json_out(['ok' => false, 'error' => 'Meta não encontrada.'], 404);
        }
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API goals: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
