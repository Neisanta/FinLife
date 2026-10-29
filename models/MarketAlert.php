<?php
/**
 * FinLife — Alertas de preço do mercado.
 * Verificação disparada pelo MarketDataService quando cotações frescas
 * passam por ele (não há polling de agenda: o dado é avaliado em trânsito).
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class MarketAlert
{
    public const TYPES = ['above', 'below', 'pct_up', 'pct_down'];

    public const TYPE_LABELS = [
        'above' => 'Preço acima de',
        'below' => 'Preço abaixo de',
        'pct_up' => 'Variação acima de (+%)',
        'pct_down' => 'Variação abaixo de (−%)',
    ];

    public static function create(int $userId, array $d): array
    {
        $symbol = MarketUniverse::normalize((string) ($d['symbol'] ?? ''));
        if ($symbol === '') {
            return ['ok' => false, 'error' => 'Informe o ativo do alerta.'];
        }
        $type = Validator::in($d['type'] ?? '', self::TYPES);
        if ($type === null) {
            return ['ok' => false, 'error' => 'Tipo de alerta inválido.'];
        }
        $target = Validator::amount($d['target'] ?? null);
        if ($target === null) {
            return ['ok' => false, 'error' => 'Informe o valor-alvo do alerta.'];
        }
        $meta = MarketUniverse::meta($symbol);
        $class = $meta['class'] ?? (BinanceProvider::supports($symbol) ? 'cripto' : 'br_stock');
        Database::run(
            'INSERT INTO market_alerts (user_id, symbol, class, type, target, note)
             VALUES (:uid, :sym, :cls, :type, :target, :note)',
            [
                'uid' => $userId,
                'sym' => $symbol,
                'cls' => $class,
                'type' => $type,
                'target' => $target,
                'note' => Validator::text($d['note'] ?? '', 255) ?: null,
            ]
        );
        return ['ok' => true, 'id' => (int) Database::lastId()];
    }

    public static function cancel(int $userId, int $id): bool
    {
        return Database::run(
            "UPDATE market_alerts SET status = 'cancelled' WHERE id = :id AND user_id = :uid AND status = 'active'",
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    /** Reativar um alerta disparado (para acompanhar de novo). */
    public static function reactivate(int $userId, int $id, ?float $newTarget = null): bool
    {
        $set = 'status = \'active\', triggered_at = NULL, last_notified_at = NULL';
        $params = ['id' => $id, 'uid' => $userId];
        if ($newTarget !== null) {
            $set .= ', target = :target';
            $params['target'] = $newTarget;
        }
        return Database::run(
            "UPDATE market_alerts SET {$set} WHERE id = :id AND user_id = :uid",
            $params
        )->rowCount() > 0;
    }

    public static function delete(int $userId, int $id): bool
    {
        return Database::run(
            'DELETE FROM market_alerts WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function list(int $userId): array
    {
        return Database::run(
            'SELECT * FROM market_alerts WHERE user_id = :uid
             ORDER BY FIELD(status, \'active\', \'triggered\', \'cancelled\'), created_at DESC',
            ['uid' => $userId]
        )->fetchAll();
    }
}
