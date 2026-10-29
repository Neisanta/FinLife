<?php
/**
 * FinLife — Watchlists do usuário (múltiplas, ordenáveis).
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class MarketWatchlist
{
    public const DEFAULT_NAME = 'Minha watchlist';

    /** Garante a lista padrão do usuário (na primeira visita). */
    public static function ensureDefault(int $userId): int
    {
        $row = Database::run(
            'SELECT id FROM market_watchlists WHERE user_id = :uid ORDER BY position, id LIMIT 1',
            ['uid' => $userId]
        )->fetch();
        if ($row) {
            return (int) $row['id'];
        }
        Database::run(
            'INSERT INTO market_watchlists (user_id, name, position) VALUES (:uid, :name, 0)',
            ['uid' => $userId, 'name' => self::DEFAULT_NAME]
        );
        return (int) Database::lastId();
    }

    public static function list(int $userId): array
    {
        self::ensureDefault($userId);
        $lists = Database::run(
            'SELECT * FROM market_watchlists WHERE user_id = :uid ORDER BY position, id',
            ['uid' => $userId]
        )->fetchAll();
        $out = [];
        foreach ($lists as $l) {
            $items = Database::run(
                'SELECT * FROM market_watchlist_items WHERE watchlist_id = :wid ORDER BY position, id',
                ['wid' => (int) $l['id']]
            )->fetchAll();
            $out[] = [
                'id' => (int) $l['id'],
                'name' => (string) $l['name'],
                'items' => array_map(static fn ($i) => [
                    'id' => (int) $i['id'],
                    'symbol' => (string) $i['symbol'],
                    'class' => (string) $i['class'],
                    'position' => (int) $i['position'],
                ], $items),
            ];
        }
        return $out;
    }

    public static function create(int $userId, string $name): int
    {
        $name = Validator::text($name, 80);
        if ($name === '') {
            return 0;
        }
        $max = (int) (Database::run(
            'SELECT COALESCE(MAX(position), -1) AS p FROM market_watchlists WHERE user_id = :uid',
            ['uid' => $userId]
        )->fetch()['p'] ?? -1);
        Database::run(
            'INSERT INTO market_watchlists (user_id, name, position) VALUES (:uid, :name, :pos)',
            ['uid' => $userId, 'name' => $name, 'pos' => $max + 1]
        );
        return (int) Database::lastId();
    }

    public static function rename(int $userId, int $id, string $name): bool
    {
        $name = Validator::text($name, 80);
        if ($name === '') {
            return false;
        }
        return Database::run(
            'UPDATE market_watchlists SET name = :name WHERE id = :id AND user_id = :uid',
            ['name' => $name, 'id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function delete(int $userId, int $id): bool
    {
        return Database::run(
            'DELETE FROM market_watchlists WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function addItem(int $userId, int $watchlistId, string $symbolInput): array
    {
        $wl = self::get($userId, $watchlistId);
        if ($wl === null) {
            return ['ok' => false, 'error' => 'Watchlist não encontrada.'];
        }
        $symbol = MarketUniverse::normalize($symbolInput);
        if ($symbol === '') {
            return ['ok' => false, 'error' => 'Símbolo inválido.'];
        }
        $exists = Database::run(
            'SELECT id FROM market_watchlist_items WHERE watchlist_id = :wid AND symbol = :sym LIMIT 1',
            ['wid' => $watchlistId, 'sym' => $symbol]
        )->fetch();
        if ($exists) {
            return ['ok' => false, 'error' => 'Este ativo já está na watchlist.'];
        }
        $meta = MarketUniverse::meta($symbol);
        $class = $meta['class'] ?? (BinanceProvider::supports($symbol) ? 'cripto' : 'br_stock');
        $max = (int) (Database::run(
            'SELECT COALESCE(MAX(position), -1) AS p FROM market_watchlist_items WHERE watchlist_id = :wid',
            ['wid' => $watchlistId]
        )->fetch()['p'] ?? -1);
        Database::run(
            'INSERT INTO market_watchlist_items (watchlist_id, symbol, class, position)
             VALUES (:wid, :sym, :cls, :pos)',
            ['wid' => $watchlistId, 'sym' => $symbol, 'cls' => $class, 'pos' => $max + 1]
        );
        return ['ok' => true, 'id' => (int) Database::lastId(), 'symbol' => $symbol, 'class' => $class];
    }

    public static function removeItem(int $userId, int $itemId): bool
    {
        return Database::run(
            'DELETE i FROM market_watchlist_items i
             JOIN market_watchlists w ON w.id = i.watchlist_id
             WHERE i.id = :id AND w.user_id = :uid',
            ['id' => $itemId, 'uid' => $userId]
        )->rowCount() > 0;
    }

    /** Reordenar: recebe a lista de item-ids na ordem desejada. */
    public static function reorder(int $userId, array $itemIds): bool
    {
        $pos = 0;
        foreach ($itemIds as $id) {
            $id = Validator::positiveInt($id);
            if ($id === null) {
                continue;
            }
            Database::run(
                'UPDATE market_watchlist_items i
                 JOIN market_watchlists w ON w.id = i.watchlist_id
                 SET i.position = :pos
                 WHERE i.id = :id AND w.user_id = :uid',
                ['pos' => $pos++, 'id' => $id, 'uid' => $userId]
            );
        }
        return true;
    }

    public static function get(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM market_watchlists WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        return $row ?: null;
    }
}
