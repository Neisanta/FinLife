<?php
/**
 * FinLife — API de Compromissos e Contas Fixas.
 *
 * GET  ?action=list    → compromissos + resumo do mês
 * GET  ?action=get&id= → detalhe (pagamentos + ocorrências)
 * GET  ?action=accounts→ contas p/ os formulários
 * POST {action:create}         → cria compromisso
 * POST {action:update}         → edita (scope: all|occurrence|future)
 * POST {action:pay}            → registra pagamento
 * POST {action:undo_payment}   → desfaz pagamento
 * POST {action:set_status}     → pause|resume|settle
 * POST {action:delete}         → exclui (bloqueado se houver pagamentos)
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

    // gera ocorrências pendentes e checa notificações em toda leitura
    Commitment::ensureOccurrences($userId);
    Commitment::checkNotifications($userId);

    if ($method === 'GET') {
        if ($action === 'get') {
            $id = Validator::positiveInt($_GET['id'] ?? null);
            if ($id === null) {
                json_out(['ok' => false, 'error' => 'Compromisso inválido.'], 400);
            }
            $c = Commitment::get($userId, $id);
            if ($c === null) {
                json_out(['ok' => false, 'error' => 'Compromisso não encontrado.'], 404);
            }
            json_out(['ok' => true, 'commitment' => $c]);
        }
        if ($action === 'accounts') {
            $accounts = Account::list($userId);
            json_out(['ok' => true, 'accounts' => $accounts]);
        }
        // lista padrão
        $filters = [
            'status' => $_GET['status'] ?? '',
            'category_key' => $_GET['category'] ?? '',
            'payee' => $_GET['payee'] ?? '',
        ];
        $items = Commitment::list($userId, $filters);
        $summary = Commitment::summary($userId);
        $dueSoon = Commitment::dueSoon($userId, 7);
        json_out(['ok' => true, 'commitments' => $items, 'summary' => $summary, 'due_soon' => $dueSoon]);
    }

    if ($method !== 'POST') {
        json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
    }

    csrf_guard();
    $body = json_input();
    if ($body === null) {
        json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
    }
    $action = (string) ($body['action'] ?? $action);
    $id = Validator::positiveInt($body['id'] ?? null);

    switch ($action) {
        case 'create':
            $result = Commitment::create($userId, $body);
            json_out($result, ($result['ok'] ?? false) ? 201 : 422);
            // no break

        case 'update':
            if ($id === null) {
                json_out(['ok' => false, 'error' => 'Compromisso inválido.'], 400);
            }
            $scope = Validator::in($body['scope'] ?? 'all', ['all', 'occurrence', 'future']) ?? 'all';
            $result = Commitment::update($userId, $id, $body, $scope);
            json_out($result, ($result['ok'] ?? false) ? 200 : 422);
            // no break

        case 'pay':
            if ($id === null) {
                json_out(['ok' => false, 'error' => 'Compromisso inválido.'], 400);
            }
            $result = Commitment::registerPayment($userId, $id, $body);
            json_out($result, ($result['ok'] ?? false) ? 200 : 422);
            // no break

        case 'undo_payment':
            if ($id === null) {
                json_out(['ok' => false, 'error' => 'Compromisso inválido.'], 400);
            }
            $paymentId = Validator::positiveInt($body['payment_id'] ?? null);
            if ($paymentId === null) {
                json_out(['ok' => false, 'error' => 'Pagamento inválido.'], 400);
            }
            $result = Commitment::undoPayment($userId, $id, $paymentId);
            json_out($result, ($result['ok'] ?? false) ? 200 : 422);
            // no break

        case 'set_status':
            if ($id === null) {
                json_out(['ok' => false, 'error' => 'Compromisso inválido.'], 400);
            }
            $map = ['pause' => 'paused', 'resume' => 'active', 'settle' => 'settled'];
            $verb = (string) ($body['verb'] ?? '');
            if (!isset($map[$verb])) {
                json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
            }
            $result = Commitment::setStatus($userId, $id, $map[$verb]);
            json_out($result, ($result['ok'] ?? false) ? 200 : 422);
            // no break

        case 'delete':
            if ($id === null) {
                json_out(['ok' => false, 'error' => 'Compromisso inválido.'], 400);
            }
            $result = Commitment::delete($userId, $id);
            json_out($result, ($result['ok'] ?? false) ? 200 : 422);
            // no break

        default:
            json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }
} catch (Throwable $e) {
    log_error('API commitments: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
