<?php
/**
 * FinLife — API REST de cartões.
 *
 * GET    /api/cards.php                      → lista + visão consolidada + alertas
 * GET    ?action=calendar&month=YYYY-MM      → eventos do calendário financeiro
 * GET    ?action=report&month=YYYY-MM        → relatório analítico
 * GET    ?action=credits&balance_id=         → histórico de créditos do benefício
 * POST   {name, type, ...}                   → cadastrar cartão
 * POST   {action:'block'|'unblock'|'archive', id}
 * POST   {action:'add_balance', card_id, benefit_kind, ...}
 * POST   {action:'remove_balance', id}
 * POST   {action:'add_credit', balance_id, amount, ...}
 * PUT    {id, ...campos}                     → editar cartão
 * PUT    {action:'update_balance', id, ...}  → editar saldo de benefício
 * DELETE ?id=                                → excluir cartão
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
        if ($action === 'calendar') {
            $month = preg_match('~^\d{4}-\d{2}$~', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
            json_out(['ok' => true, 'month' => $month, 'events' => Card::calendarEvents($userId, $month)]);
        }
        if ($action === 'report') {
            $month = preg_match('~^\d{4}-\d{2}$~', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
            json_out(['ok' => true, 'month' => $month] + Card::report($userId, $month));
        }
        if ($action === 'credits') {
            $balanceId = Validator::positiveInt($_GET['balance_id'] ?? null);
            if ($balanceId === null || Card::balance($userId, $balanceId) === null) {
                json_out(['ok' => false, 'error' => 'Saldo de benefício não encontrado.'], 404);
            }
            json_out(['ok' => true, 'credits' => Card::credits($userId, $balanceId)]);
        }
        if ($action === 'overview') {
            json_out(['ok' => true] + Card::overview($userId));
        }
        // padrão: lista + consolidado
        json_out(['ok' => true] + Card::overview($userId));
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        $postAction = Validator::text($body['action'] ?? '', 30);

        // Ações de status (bloquear/desbloquear/arquivar)
        if (in_array($postAction, ['block', 'unblock', 'archive'], true)) {
            $id = Validator::positiveInt($body['id'] ?? null);
            if ($id === null) {
                json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
            }
            $status = ['block' => 'blocked', 'unblock' => 'active', 'archive' => 'archived'][$postAction];
            if (!Card::setStatus($userId, $id, $status)) {
                json_out(['ok' => false, 'error' => 'Cartão não encontrado.'], 404);
            }
            json_out(['ok' => true, 'status' => $status]);
        }

        if ($postAction === 'add_balance') {
            $cardId = Validator::positiveInt($body['card_id'] ?? null);
            if ($cardId === null) {
                json_out(['ok' => false, 'error' => 'Cartão inválido.'], 400);
            }
            $r = Card::addBalance($userId, $cardId, $body);
            if (($r['ok'] ?? false) !== true) {
                json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível adicionar o saldo.'], 422);
            }
            json_out(['ok' => true, 'id' => $r['id']], 201);
        }

        if ($postAction === 'remove_balance') {
            $id = Validator::positiveInt($body['id'] ?? null);
            if ($id === null || !Card::removeBalance($userId, $id)) {
                json_out(['ok' => false, 'error' => 'Saldo não encontrado.'], 404);
            }
            json_out(['ok' => true]);
        }

        if ($postAction === 'add_credit') {
            $balanceId = Validator::positiveInt($body['balance_id'] ?? null);
            if ($balanceId === null) {
                json_out(['ok' => false, 'error' => 'Saldo de benefício inválido.'], 400);
            }
            $r = Card::addCredit($userId, $balanceId, $body);
            if (($r['ok'] ?? false) !== true) {
                json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível registrar o crédito.'], 422);
            }
            json_out(['ok' => true, 'id' => $r['id']], 201);
        }

        // padrão: cadastrar cartão
        $r = Card::create($userId, $body);
        if (($r['ok'] ?? false) !== true) {
            json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível cadastrar o cartão.'], 422);
        }
        json_out(['ok' => true, 'id' => $r['id']], 201);
    }

    if ($method === 'PUT') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        $putAction = Validator::text($body['action'] ?? '', 30);

        if ($putAction === 'update_balance') {
            $id = Validator::positiveInt($body['id'] ?? null);
            if ($id === null || !Card::updateBalance($userId, $id, $body)) {
                json_out(['ok' => false, 'error' => 'Saldo de benefício não encontrado.'], 404);
            }
            json_out(['ok' => true]);
        }

        $id = Validator::positiveInt($body['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        if (!Card::update($userId, $id, $body)) {
            json_out(['ok' => false, 'error' => 'Cartão não encontrado.'], 404);
        }
        json_out(['ok' => true, 'id' => $id]);
    }

    if ($method === 'DELETE') {
        csrf_guard();
        $id = Validator::positiveInt($_GET['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        if (!Card::delete($userId, $id)) {
            json_out(['ok' => false, 'error' => 'Cartão não encontrado.'], 404);
        }
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API cards: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
