<?php
/**
 * Mercado: alertas de preço.
 * GET  ?symbol= (opcional)     → alertas + cotação atual do alvo
 * POST {symbol, type, target, note}
 * PUT  {action:'cancel'|'reactivate', id, target?}
 * DELETE ?id=
 */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $alerts = MarketAlert::list($MARKET_USER_ID);
        $symbols = array_values(array_unique(array_column($alerts, 'symbol')));
        $quotes = MarketDataService::getQuotes($symbols, $MARKET_USER_ID);
        $out = array_map(static function ($a) use ($quotes) {
            $sym = (string) $a['symbol'];
            return [
                'id' => (int) $a['id'],
                'symbol' => $sym,
                'display' => MarketUniverse::display($sym),
                'label' => MarketUniverse::label($sym),
                'class' => (string) $a['class'],
                'type' => (string) $a['type'],
                'type_label' => MarketAlert::TYPE_LABELS[$a['type']] ?? $a['type'],
                'target' => (float) $a['target'],
                'status' => (string) $a['status'],
                'note' => $a['note'],
                'trigger_price' => $a['trigger_price'] !== null ? (float) $a['trigger_price'] : null,
                'triggered_at' => $a['triggered_at'],
                'created_at' => $a['created_at'],
                'quote' => $quotes[$sym] ?? null,
            ];
        }, $alerts);
        json_out(['ok' => true, 'alerts' => $out]);
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        $r = MarketAlert::create($MARKET_USER_ID, $body);
        if (($r['ok'] ?? false) !== true) {
            json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível criar o alerta.'], 422);
        }
        json_out(['ok' => true, 'id' => $r['id']], 201);
    }

    if ($method === 'PUT') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        $action = market_action();
        $id = Validator::positiveInt($body['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        if ($action === 'cancel') {
            if (!MarketAlert::cancel($MARKET_USER_ID, $id)) {
                json_out(['ok' => false, 'error' => 'Alerta não encontrado ou já cancelado.'], 404);
            }
            json_out(['ok' => true]);
        }
        if ($action === 'reactivate') {
            $newTarget = Validator::amount($body['target'] ?? null);
            if (!MarketAlert::reactivate($MARKET_USER_ID, $id, $newTarget)) {
                json_out(['ok' => false, 'error' => 'Alerta não encontrado.'], 404);
            }
            json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }

    if ($method === 'DELETE') {
        csrf_guard();
        $id = Validator::positiveInt($_GET['id'] ?? null);
        if ($id === null || !MarketAlert::delete($MARKET_USER_ID, $id)) {
            json_out(['ok' => false, 'error' => 'Alerta não encontrado.'], 404);
        }
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API market/alerts: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
