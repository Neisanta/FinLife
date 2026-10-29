<?php
/**
 * Mercado: watchlists do usuário.
 * GET                    → listas + cotações ao vivo dos itens
 * POST   {action}        → item_add {watchlist_id, symbol} | list_create {name}
 * PUT    {action}        → list_rename {id, name} | items_reorder {watchlist_id, item_ids[]}
 * DELETE ?item_id=|?list_id=
 */
define('FINLIFE', true);
require __DIR__ . '/_bootstrap.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $lists = MarketWatchlist::list($MARKET_USER_ID);
        // cotações reais para todos os itens (avalia alertas em trânsito)
        $allSymbols = [];
        foreach ($lists as $l) {
            foreach ($l['items'] as $i) {
                $allSymbols[] = $i['symbol'];
            }
        }
        $quotes = MarketDataService::getQuotes($allSymbols, $MARKET_USER_ID);
        foreach ($lists as &$l) {
            foreach ($l['items'] as &$i) {
                $i['quote'] = $quotes[$i['symbol']] ?? null;
            }
        }
        unset($l, $i);
        json_out([
            'ok' => true,
            'watchlists' => $lists,
            'triggered_alerts' => MarketDataService::pullTriggered(),
        ]);
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        $action = market_action();

        if ($action === 'list_create') {
            $id = MarketWatchlist::create($MARKET_USER_ID, (string) ($body['name'] ?? ''));
            if ($id === 0) {
                json_out(['ok' => false, 'error' => 'Informe o nome da watchlist.'], 422);
            }
            json_out(['ok' => true, 'id' => $id], 201);
        }

        if ($action === 'item_add') {
            $wid = Validator::positiveInt($body['watchlist_id'] ?? null);
            if ($wid === null) {
                json_out(['ok' => false, 'error' => 'Watchlist inválida.'], 400);
            }
            $r = MarketWatchlist::addItem($MARKET_USER_ID, $wid, (string) ($body['symbol'] ?? ''));
            if (($r['ok'] ?? false) !== true) {
                json_out(['ok' => false, 'error' => $r['error'] ?? 'Não foi possível adicionar.'], 422);
            }
            json_out(['ok' => true, 'id' => $r['id'], 'symbol' => $r['symbol']], 201);
        }

        json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }

    if ($method === 'PUT') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        $action = market_action();

        if ($action === 'list_rename') {
            $id = Validator::positiveInt($body['id'] ?? null);
            if ($id === null || !MarketWatchlist::rename($MARKET_USER_ID, $id, (string) ($body['name'] ?? ''))) {
                json_out(['ok' => false, 'error' => 'Watchlist não encontrada ou nome vazio.'], 404);
            }
            json_out(['ok' => true]);
        }

        if ($action === 'items_reorder') {
            $wid = Validator::positiveInt($body['watchlist_id'] ?? null);
            if ($wid === null || MarketWatchlist::get($MARKET_USER_ID, $wid) === null) {
                json_out(['ok' => false, 'error' => 'Watchlist não encontrada.'], 404);
            }
            MarketWatchlist::reorder($MARKET_USER_ID, (array) ($body['item_ids'] ?? []));
            json_out(['ok' => true]);
        }

        json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }

    if ($method === 'DELETE') {
        csrf_guard();
        $itemId = Validator::positiveInt($_GET['item_id'] ?? null);
        $listId = Validator::positiveInt($_GET['list_id'] ?? null);
        if ($itemId !== null && MarketWatchlist::removeItem($MARKET_USER_ID, $itemId)) {
            json_out(['ok' => true]);
        }
        if ($listId !== null && MarketWatchlist::delete($MARKET_USER_ID, $listId)) {
            json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'error' => 'Não encontrado.'], 404);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API market/watchlist: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
