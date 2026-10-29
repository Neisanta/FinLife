<?php
/**
 * FinLife — Model de compras recorrentes/assinaturas no cartão.
 * (Netflix, Spotify, academia, internet, celular... — item 13)
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class CardRecurring
{
    public const PERIODICITIES = ['monthly', 'weekly', 'yearly'];

    public static function create(int $userId, array $d): array
    {
        $name = Validator::text($d['name'] ?? '', 120);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Informe o nome da assinatura.'];
        }
        $amount = Validator::amount($d['amount'] ?? null);
        if ($amount === null) {
            return ['ok' => false, 'error' => 'Informe o valor da assinatura.'];
        }
        $cardId = Validator::positiveInt($d['card_id'] ?? null);
        if ($cardId === null || Card::get($userId, $cardId) === null) {
            return ['ok' => false, 'error' => 'Escolha o cartão da assinatura.'];
        }
        $periodicity = Validator::in($d['periodicity'] ?? 'monthly', self::PERIODICITIES) ?? 'monthly';
        $startDate = Validator::date($d['start_date'] ?? null) ?? date('Y-m-d');
        $endDate = Validator::date($d['end_date'] ?? null);

        $categoryId = Validator::positiveInt($d['category_id'] ?? null);
        if ($categoryId !== null && Category::get($userId, $categoryId) === null) {
            $categoryId = null;
        }
        $chargeDay = $d['charge_day'] ?? null;
        $chargeDay = ($chargeDay === null || $chargeDay === '') ? null
            : (int) max(1, min(28, (int) $chargeDay));

        Database::run(
            'INSERT INTO card_recurring (user_id, card_id, category_id, name, amount, periodicity, charge_day, start_date, end_date, is_active)
             VALUES (:uid, :cid, :cat, :name, :amount, :per, :cd, :sd, :ed, 1)',
            [
                'uid'    => $userId,
                'cid'    => $cardId,
                'cat'    => $categoryId,
                'name'   => $name,
                'amount' => $amount,
                'per'    => $periodicity,
                'cd'     => $chargeDay,
                'sd'     => $startDate,
                'ed'     => $endDate,
            ]
        );
        return ['ok' => true, 'id' => Database::lastId()];
    }

    public static function update(int $userId, int $id, array $d): bool
    {
        $sub = self::get($userId, $id);
        if ($sub === null) {
            return false;
        }
        $cardId = Validator::positiveInt($d['card_id'] ?? $sub['card_id']) ?? (int) $sub['card_id'];
        if (Card::get($userId, $cardId) === null) {
            return false;
        }
        $categoryId = Validator::positiveInt($d['category_id'] ?? $sub['category_id']);
        $isActive = array_key_exists('is_active', $d) ? (int) (bool) $d['is_active'] : (int) $sub['is_active'];

        Database::run(
            'UPDATE card_recurring
             SET name = :name, amount = :amount, card_id = :cid, category_id = :cat,
                 periodicity = :per, charge_day = :cd, start_date = :sd, end_date = :ed, is_active = :active
             WHERE id = :id AND user_id = :uid',
            [
                'id'     => $id,
                'uid'    => $userId,
                'name'   => Validator::text($d['name'] ?? $sub['name'], 120),
                'amount' => Validator::amount($d['amount'] ?? $sub['amount']) ?? (float) $sub['amount'],
                'cid'    => $cardId,
                'cat'    => $categoryId,
                'per'    => Validator::in($d['periodicity'] ?? $sub['periodicity'], self::PERIODICITIES) ?? $sub['periodicity'],
                'cd'     => self::chargeDay($d['charge_day'] ?? $sub['charge_day']),
                'sd'     => Validator::date($d['start_date'] ?? $sub['start_date']) ?? $sub['start_date'],
                'ed'     => Validator::date($d['end_date'] ?? $sub['end_date']),
                'active' => $isActive,
            ]
        );
        return true;
    }

    public static function delete(int $userId, int $id): bool
    {
        return Database::run(
            'DELETE FROM card_recurring WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function get(int $userId, int $id): ?array
    {
        return Database::run(
            'SELECT * FROM card_recurring WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch() ?: null;
    }

    public static function list(int $userId): array
    {
        return Database::run(
            'SELECT r.*, c.name AS card_name, c.color AS card_color, c.last4 AS card_last4,
                    cat.name AS category_name, cat.icon AS category_icon, cat.color AS category_color
             FROM card_recurring r
             JOIN cards c ON c.id = r.card_id
             LEFT JOIN categories cat ON cat.id = r.category_id
             WHERE r.user_id = :uid
             ORDER BY r.is_active DESC, r.name',
            ['uid' => $userId]
        )->fetchAll();
    }

    /** Total mensal comprometido com assinaturas ativas. */
    public static function monthlyTotal(int $userId): float
    {
        $rows = Database::run(
            "SELECT periodicity, amount FROM card_recurring
             WHERE user_id = :uid AND is_active = 1
               AND start_date <= :today1 AND (end_date IS NULL OR end_date >= :today2)",
            ['uid' => $userId, 'today1' => date('Y-m-d'), 'today2' => date('Y-m-d')]
        )->fetchAll();
        $total = 0.0;
        foreach ($rows as $r) {
            $total += match ($r['periodicity']) {
                'weekly'  => (float) $r['amount'] * 52 / 12,
                'yearly'  => (float) $r['amount'] / 12,
                default   => (float) $r['amount'],
            };
        }
        return round($total, 2);
    }

    /**
     * Assinaturas ativas cobradas num mês específico, com a(s) data(s) prevista(s)
     * (usado pelo calendário financeiro).
     */
    public static function activeForMonth(int $userId, string $month): array
    {
        if (!preg_match('~^\d{4}-\d{2}$~', $month)) {
            $month = date('Y-m');
        }
        $subs = Database::run(
            "SELECT r.*, c.name AS card_name, c.color AS card_color
             FROM card_recurring r JOIN cards c ON c.id = r.card_id
             WHERE r.user_id = :uid AND r.is_active = 1
               AND r.start_date <= :me AND (r.end_date IS NULL OR r.end_date >= :ms)",
            ['uid' => $userId, 'ms' => $month . '-01', 'me' => date('Y-m-t', strtotime($month . '-01'))]
        )->fetchAll();

        $out = [];
        foreach ($subs as $r) {
            $days = self::chargeDaysInMonth($r, $month);
            foreach ($days as $day) {
                $out[] = [
                    'id'          => (int) $r['id'],
                    'name'        => $r['name'],
                    'amount'      => (float) $r['amount'],
                    'periodicity' => $r['periodicity'],
                    'next_charge' => $day,
                    'card_name'   => $r['card_name'],
                    'card_color'  => $r['card_color'],
                ];
            }
        }
        return $out;
    }

    /** Dias de cobrança de uma assinatura dentro do mês. */
    private static function chargeDaysInMonth(array $r, string $month): array
    {
        $first = strtotime($month . '-01');
        $lastDay = (int) date('t', $first);
        $start = (string) $r['start_date'];
        $end = $r['end_date'] !== null ? (string) $r['end_date'] : null;
        $days = [];

        if ($r['charge_day'] === null) {
            // sem dia fixo: usa o dia do início da assinatura
            $r['charge_day'] = (int) date('j', strtotime($start));
        }
        $day = max(1, min(28, (int) $r['charge_day']));

        $candidates = match ($r['periodicity']) {
            'weekly'  => [$day, $day + 7, $day + 14, $day + 21],
            'yearly'  => ((int) date('n', strtotime($start)) === (int) date('n', $first)) ? [$day] : [],
            default   => [$day],
        };
        foreach ($candidates as $d) {
            if ($d > $lastDay) {
                continue;
            }
            $date = date('Y-m-', $first) . sprintf('%02d', $d);
            if ($date >= $start && ($end === null || $date <= $end)) {
                $days[] = $date;
            }
        }
        return $days;
    }

    private static function chargeDay(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $i = filter_var($value, FILTER_VALIDATE_INT);
        return ($i !== false && $i >= 1 && $i <= 28) ? $i : null;
    }
}
