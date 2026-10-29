<?php
/**
 * FinLife — API do assistente (FinLife AI).
 *
 * GET  ?action=status   → configuração do provedor (sem expor a chave)
 * GET  ?action=history  → histórico da conversa
 * POST {action:'message', text}
 * POST {action:'confirm', accept:bool}
 * POST {action:'summary'}        → resumo do mês (card do dashboard)
 * POST {action:'clear'}          → apaga a conversa
 *
 * A API Key NUNCA sai daqui: o frontend só conversa com este endpoint.
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
        if ($action === 'status') {
            $ai = new AIService();
            json_out([
                'ok' => true,
                'configured' => $ai->isConfigured(),
                'provider' => $ai->isConfigured() ? 'openai_compatible' : 'local',
                'model' => $ai->isConfigured() ? $ai->model() : 'motor de regras local',
            ]);
        }
        if ($action === 'history') {
            $convId = AiChat::conversationId($userId);
            json_out(['ok' => true, 'messages' => AiChat::history($userId, $convId, 50)]);
        }
        json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
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

    switch ($action) {
        case 'message':
            if (empty($user['ai_chat_enabled'])) {
                json_out(['ok' => false, 'error' => 'O assistente está desativado para sua conta.'], 403);
            }
            $result = AiAssistant::message($user, (string) ($body['text'] ?? ''));
            json_out($result);
            // no break

        case 'confirm':
            $result = AiAssistant::confirm($user, filter_var($body['accept'] ?? false, FILTER_VALIDATE_BOOLEAN));
            json_out($result);
            // no break

        case 'summary':
            $result = AiAssistant::summary($user);
            json_out($result);
            // no break

        case 'clear':
            AiChat::clear($userId);
            json_out(['ok' => true, 'reply' => 'Conversa apagada. Podemos começar de novo — como posso ajudar?']);
            // no break

        default:
            json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }
} catch (Throwable $e) {
    log_error('API ai: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
