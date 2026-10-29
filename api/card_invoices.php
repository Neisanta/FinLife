<?php
/**
 * FinLife — API REST de faturas de cartão.
 *
 * GET    /api/card_invoices.php            → lista de faturas (?card_id= opcional)
 * POST   {action:'pay', invoice_id, amount, date, account_id, note} → pagamento
 * POST   {action:'close', id}              → fechar fatura manualmente
 * DELETE ?payment_id=                      → remover pagamento (reverte saída da conta)
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
        $cardId = Validator::positiveInt($_GET['card_id'] ?? null);
        json_out(['ok' => true, 'invoices' => CardInvoice::list($userId, $cardId)]);
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        $action = Validator::text($body['action'] ?? '', 20);

        if ($action === 'close') {
            $id = Validator::positiveInt($body['id'] ?? null);
            if ($id === null || !CardInvoice::close($userId, $id)) {
                json_out(['ok' => false, 'error' => 'Fatura não encontrada.'], 404);
            }
            json_out(['ok' => true]);
        }

        if ($action === 'pay') {
            $invoiceId = Validator::positiveInt($body['invoice_id'] ?? null);
            if ($invoiceId === null) {
                json_out(['ok' => false, 'error' => 'Fatura inválida.'], 400);
            }
            $r = CardInvoice::pay($userId, $invoiceId, $body);
            if (($r['ok'] ?? false) !== true) {
                json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível registrar o pagamento.'], 422);
            }
            json_out(['ok' => true, 'id' => $r['id'], 'kind' => $r['kind'], 'transaction_id' => $r['transaction_id']], 201);
        }

        json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }

    if ($method === 'DELETE') {
        csrf_guard();
        $paymentId = Validator::positiveInt($_GET['payment_id'] ?? null);
        if ($paymentId === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        $r = CardInvoice::removePayment($userId, $paymentId);
        if (($r['ok'] ?? false) !== true) {
            json_out(['ok' => false, 'error' => $r['error'] ?? 'Pagamento não encontrado.'], 404);
        }
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API card_invoices: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
