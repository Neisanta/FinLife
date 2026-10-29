<?php
declare(strict_types=1);
define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';
$u = User::findByEmail('redesign@teste.com');
$uid = (int) $u['id'];
Database::run("DELETE FROM market_alerts WHERE user_id = :uid", ['uid' => $uid]);
Database::run(
    "INSERT INTO market_alerts (user_id, symbol, type, target, note, status, triggered_at, trigger_price)
     VALUES (:uid, 'BTCUSDT', 'above', 600000, 'realizar parte da posição', 'triggered', NOW() - INTERVAL 2 DAY, 605123.45)",
    ['uid' => $uid]
);
Database::run("INSERT INTO market_alerts (user_id, symbol, type, target) VALUES (:uid, 'PETR4', 'above', 30)", ['uid' => $uid]);
echo 'alertas restaurados: ' . count(MarketAlert::list($uid)) . "\n";

// watchlist: PETR4 primeiro (ordem do seed) — via items_reorder
try {
    $wl = MarketWatchlist::list($uid);
    $first = $wl[0] ?? null;
    if ($first && ($first['symbol'] ?? '') !== 'PETR4') {
        foreach ($wl as $w) {
            if (($w['symbol'] ?? '') === 'PETR4') {
                $ids = [];
                foreach ($w['items'] as $it) { $ids[] = (int) $it['id']; }
                $pos = array_search((int) $w['items'][0]['id'], $ids, true);
                if ($pos !== false && $pos > 0) {
                    $tmp = $ids[$pos]; $ids[$pos] = $ids[0]; $ids[0] = $tmp;
                }
                Database::run('UPDATE market_watchlist_items SET sort_order = :o WHERE id = :id', ['o' => 0, 'id' => $ids[0]]);
                $i = 1;
                foreach (array_slice($ids, 1) as $id2) {
                    Database::run('UPDATE market_watchlist_items SET sort_order = :o WHERE id = :id', ['o' => $i, 'id' => $id2]);
                    $i++;
                }
                break;
            }
        }
    }
    $wl2 = MarketWatchlist::list($uid);
    echo 'watchlist primeiro: ' . ($wl2[0]['items'][0]['symbol'] ?? '?') . "\n";
} catch (Throwable $e) {
    echo 'wl: ' . $e->getMessage() . "\n";
}
