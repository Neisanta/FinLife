<?php
/**
 * FinLife — Model de contas (corrente, poupança, dinheiro, etc.).
 * O saldo atual é sempre calculado a partir do saldo inicial +
 * transações da conta — nunca é um campo editável isolado.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Account
{
    public const TYPES = ['checking', 'savings', 'cash', 'digital_wallet', 'investment', 'other'];

    public static function create(int $userId, array $d): int
    {
        Database::run(
            'INSERT INTO accounts (user_id, name, type, institution, initial_balance, color)
             VALUES (:uid, :name, :type, :inst, :bal, :color)',
            [
                'uid'   => $userId,
                'name'  => Validator::text($d['name'], 80),
                'type'  => Validator::in($d['type'], self::TYPES) ?? 'checking',
                'inst'  => Validator::text($d['institution'] ?? '', 80) ?: null,
                'bal'   => round((float) ($d['initial_balance'] ?? 0), 2),
                'color' => Validator::color($d['color'] ?? null),
            ]
        );
        return Database::lastId();
    }

    public static function update(int $userId, int $id, array $d): bool
    {
        $acc = self::get($userId, $id);
        if ($acc === null) {
            return false;
        }
        Database::run(
            'UPDATE accounts
             SET name = :name, type = :type, institution = :inst, initial_balance = :bal, color = :color
             WHERE id = :id AND user_id = :uid',
            [
                'id'    => $id,
                'uid'   => $userId,
                'name'  => Validator::text($d['name'] ?? $acc['name'], 80),
                'type'  => Validator::in($d['type'] ?? $acc['type'], self::TYPES) ?? 'checking',
                'inst'  => Validator::text($d['institution'] ?? '', 80) ?: null,
                'bal'   => round((float) ($d['initial_balance'] ?? $acc['initial_balance']), 2),
                'color' => Validator::color($d['color'] ?? $acc['color']),
            ]
        );
        return true;
    }

    public static function delete(int $userId, int $id): bool
    {
        // transações ficam com account_id = NULL (histórico preservado)
        return Database::run(
            'DELETE FROM accounts WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function get(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM accounts WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        return $row ?: null;
    }

    /** Lista com saldo atual calculado. */
    public static function list(int $userId): array
    {
        return Database::run(
            "SELECT a.id, a.name, a.type, a.institution, a.initial_balance, a.color, a.created_at,
                    (a.initial_balance + COALESCE(SUM(
                        CASE WHEN t.type = 'income' THEN t.amount WHEN t.type = 'expense' THEN -t.amount END
                    ), 0)) AS current_balance,
                    COUNT(t.id) AS tx_count
             FROM accounts a
             LEFT JOIN transactions t ON t.account_id = a.id AND t.user_id = a.user_id
             WHERE a.user_id = :uid
             GROUP BY a.id
             ORDER BY a.created_at, a.id",
            ['uid' => $userId]
        )->fetchAll();
    }

    /** Saldo total consolidado: somas dos saldos iniciais + rede líquido de transações. */
    public static function totalBalance(int $userId): float
    {
        $initials = (float) (Database::run(
            'SELECT COALESCE(SUM(initial_balance), 0) AS ini FROM accounts WHERE user_id = :uid',
            ['uid' => $userId]
        )->fetch()['ini'] ?? 0);

        $net = (float) (Database::run(
            "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount WHEN type = 'expense' THEN -amount END), 0) AS net
             FROM transactions WHERE user_id = :uid",
            ['uid' => $userId]
        )->fetch()['net'] ?? 0);

        return $initials + $net;
    }

    /** Primeira conta do usuário (usada como padrão pela IA). */
    public static function firstId(int $userId): ?int
    {
        $row = Database::run(
            'SELECT id FROM accounts WHERE user_id = :uid ORDER BY created_at, id LIMIT 1',
            ['uid' => $userId]
        )->fetch();
        return $row ? (int) $row['id'] : null;
    }
}
