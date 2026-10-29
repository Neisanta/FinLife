<?php
/**
 * Mercado: portfólio do usuário (multiativo).
 * GET     ?year= (dividendos)   → resumo + posições + transações + dividendos
 * POST    {action:'transaction', symbol, type, quantity, price, fees, date, note}
 * POST    {action:'dividend', symbol, type, amount, per_unit, ex_date, pay_date, note}
 * DELETE  ?tx_id= | ?div_id=
 */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $year = preg_match('~^\d{4}$~', $_GET['year'] ?? '') ? (string) $_GET['year'] : null;
        json_out([
            'ok' => true,
            'summary' => Portfolio::summary($MARKET_USER_ID),
            'transactions' => array_map(static function ($t) {
                return [
                    'id' => (int) $t['id'],
                    'symbol' => (string) $t['symbol'],
                    'display' => MarketUniverse::display((string) $t['symbol']),
                    'class' => (string) $t['class'],
                    'type' => (string) $t['type'],
                    'quantity' => (float) $t['quantity'],
                    'price' => (float) $t['price'],
                    'fees' => (float) $t['fees'],
                    'total' => round(((float) $t['quantity'] * (float) $t['price']) + ($t['type'] === 'buy' ? (float) $t['fees'] : -(float) $t['fees']), 2),
                    'date' => $t['date'],
                    'note' => $t['note'],
                ];
            }, Portfolio::transactions($MARKET_USER_ID)),
            'dividends' => array_map(static function ($d) {
                return [
                    'id' => (int) $d['id'],
                    'symbol' => (string) $d['symbol'],
                    'display' => MarketUniverse::display((string) $d['symbol']),
                    'type' => (string) $d['type'],
                    'amount' => (float) $d['amount'],
                    'per_unit' => $d['per_unit'] !== null ? (float) $d['per_unit'] : null,
                    'ex_date' => $d['ex_date'],
                    'pay_date' => $d['pay_date'],
                    'note' => $d['note'],
                ];
            }, Portfolio::dividends($MARKET_USER_ID, $year)),
        ]);
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        $action = market_action();

        if ($action === 'transaction') {
            $r = Portfolio::addTransaction($MARKET_USER_ID, $body);
            if (($r['ok'] ?? false) !== true) {
                json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível registrar.'], 422);
            }
            json_out(['ok' => true, 'id' => $r['id'], 'position' => $r['position']], 201);
        }

        if ($action === 'dividend') {
            $r = Portfolio::addDividend($MARKET_USER_ID, $body);
            if (($r['ok'] ?? false) !== true) {
                json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível registrar.'], 422);
            }
            json_out(['ok' => true, 'id' => $r['id']], 201);
        }

        json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }

    if ($method === 'DELETE') {
        csrf_guard();
        $txId = Validator::positiveInt($_GET['tx_id'] ?? null);
        $divId = Validator::positiveInt($_GET['div_id'] ?? null);
        if ($txId !== null && Portfolio::deleteTransaction($MARKET_USER_ID, $txId)) {
            json_out(['ok' => true]);
        }
        if ($divId !== null && Portfolio::deleteDividend($MARKET_USER_ID, $divId)) {
            json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'error' => 'Registro não encontrado.'], 404);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API market/portfolio: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
