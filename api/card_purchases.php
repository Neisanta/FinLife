<?php
/**
 * FinLife — API REST de compras em cartões e assinaturas.
 *
 * GET    /api/card_purchases.php                     → lista com filtros
 * GET    ?action=detail&id=                          → compra + timeline de parcelas
 * GET    ?action=installments                        → parcelas futuras por mês
 * GET    ?action=recurring                           → assinaturas
 * POST   {card_id, description, amount, method, ...} → registrar compra (gera parcelas)
 * POST   {action:'recurring', ...}                   → cadastrar assinatura
 * PUT    {id, ...}                                   → editar compra (metadados/plano)
 * PUT    {action:'recurring', id, ...}               → editar assinatura
 * DELETE ?id=                                        → excluir compra
 * DELETE ?action=recurring&id=                       → excluir assinatura
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
    $action = Validator::text($_GET['action'] ?? '', 30);

    if ($method === 'GET') {
        if ($action === 'detail') {
            $id = Validator::positiveInt($_GET['id'] ?? null);
            $detail = ($id !== null) ? CardPurchase::detail($userId, $id) : null;
            if ($detail === null) {
                json_out(['ok' => false, 'error' => 'Compra não encontrada.'], 404);
            }
            json_out(['ok' => true, 'purchase' => $detail]);
        }
        if ($action === 'installments') {
            json_out(['ok' => true, 'timeline' => CardPurchase::installmentsTimeline($userId)]);
        }
        if ($action === 'recurring') {
            json_out(['ok' => true, 'subscriptions' => CardRecurring::list($userId),
                'monthly_total' => CardRecurring::monthlyTotal($userId)]);
        }
        // lista com filtros
        $filters = [
            'month'   => $_GET['month'] ?? null,
            'card_id' => Validator::positiveInt($_GET['card_id'] ?? null),
            'method'  => $_GET['method'] ?? null,
            'q'       => $_GET['q'] ?? null,
        ];
        json_out(['ok' => true, 'purchases' => CardPurchase::list($userId, $filters)]);
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }

        if (Validator::text($body['action'] ?? '', 30) === 'recurring') {
            $r = CardRecurring::create($userId, $body);
            if (($r['ok'] ?? false) !== true) {
                json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível cadastrar a assinatura.'], 422);
            }
            json_out(['ok' => true, 'id' => $r['id']], 201);
        }

        $r = CardPurchase::create($userId, $body);
        if (($r['ok'] ?? false) !== true) {
            json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível registrar a compra.'], 422);
        }
        json_out(['ok' => true, 'id' => $r['id'], 'installments' => $r['installments'] ?? 0], 201);
    }

    if ($method === 'PUT') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }

        if (Validator::text($body['action'] ?? '', 30) === 'recurring') {
            $id = Validator::positiveInt($body['id'] ?? null);
            if ($id === null || !CardRecurring::update($userId, $id, $body)) {
                json_out(['ok' => false, 'error' => 'Assinatura não encontrada.'], 404);
            }
            json_out(['ok' => true, 'id' => $id]);
        }

        $id = Validator::positiveInt($body['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        $r = CardPurchase::update($userId, $id, $body);
        if (($r['ok'] ?? false) !== true) {
            json_out(['ok' => false, 'error' => $r['error'] ?? 'Compra não encontrada.'], 422);
        }
        json_out(['ok' => true, 'id' => $id]);
    }

    if ($method === 'DELETE') {
        csrf_guard();
        if ($action === 'recurring') {
            $id = Validator::positiveInt($_GET['id'] ?? null);
            if ($id === null || !CardRecurring::delete($userId, $id)) {
                json_out(['ok' => false, 'error' => 'Assinatura não encontrada.'], 404);
            }
            json_out(['ok' => true]);
        }
        $id = Validator::positiveInt($_GET['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        if (!CardPurchase::delete($userId, $id)) {
            json_out(['ok' => false, 'error' => 'Compra não encontrada.'], 404);
        }
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API card_purchases: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
