<?php
/**
 * FinLife — Model de metas financeiras e aportes.
 * Progresso, valor restante e aporte mensal recomendado
 * são sempre calculados a partir dos dados reais.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Goal
{
    public static function create(int $userId, array $d): array
    {
        $name = Validator::text($d['name'] ?? '', 120);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Informe o nome da meta.'];
        }
        $target = Validator::amount($d['target_amount'] ?? null);
        if ($target === null) {
            return ['ok' => false, 'error' => 'Informe um valor alvo válido.'];
        }
        $deadline = Validator::date($d['deadline'] ?? null);
        if ($deadline !== null && strtotime($deadline) < strtotime(date('Y-m-d'))) {
            return ['ok' => false, 'error' => 'O prazo deve ser uma data futura.'];
        }
        Database::run(
            'INSERT INTO goals (user_id, name, target_amount, deadline) VALUES (:uid, :name, :target, :deadline)',
            ['uid' => $userId, 'name' => $name, 'target' => $target, 'deadline' => $deadline]
        );
        return ['ok' => true, 'id' => Database::lastId()];
    }

    public static function update(int $userId, int $id, array $d): array
    {
        $goal = self::get($userId, $id);
        if ($goal === null) {
            return ['ok' => false, 'error' => 'Meta não encontrada.'];
        }
        $name = Validator::text($d['name'] ?? $goal['name'], 120);
        $target = Validator::amount($d['target_amount'] ?? $goal['target_amount']) ?? (float) $goal['target_amount'];
        $deadline = Validator::date($d['deadline'] ?? $goal['deadline']);
        Database::run(
            'UPDATE goals SET name = :name, target_amount = :target, deadline = :deadline WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId, 'name' => $name, 'target' => $target, 'deadline' => $deadline]
        );
        return ['ok' => true, 'id' => $id];
    }

    public static function delete(int $userId, int $id): bool
    {
        return Database::run(
            'DELETE FROM goals WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function get(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM goals WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        return $row ?: null;
    }

    /** Lista com progresso e aporte mensal recomendado. */
    public static function list(int $userId): array
    {
        $rows = Database::run(
            'SELECT g.*, COALESCE(SUM(d.amount), 0) AS current_amount
             FROM goals g
             LEFT JOIN goal_deposits d ON d.goal_id = g.id AND d.user_id = g.user_id
             WHERE g.user_id = :uid
             GROUP BY g.id
             ORDER BY g.status = "completed", g.created_at DESC',
            ['uid' => $userId]
        )->fetchAll();

        foreach ($rows as &$r) {
            $r = self::enrich($r);
        }
        return $rows;
    }

    /** Adiciona um aporte à meta. */
    public static function addDeposit(int $userId, int $goalId, array $d): array
    {
        $goal = self::get($userId, $goalId);
        if ($goal === null) {
            return ['ok' => false, 'error' => 'Meta não encontrada.'];
        }
        $amount = Validator::amount($d['amount'] ?? null);
        if ($amount === null) {
            return ['ok' => false, 'error' => 'Informe um valor de aporte válido.'];
        }
        $date = Validator::date($d['date'] ?? null) ?? date('Y-m-d');
        $note = Validator::text($d['note'] ?? '', 255) ?: null;

        Database::run(
            'INSERT INTO goal_deposits (goal_id, user_id, amount, date, note) VALUES (:gid, :uid, :amount, :date, :note)',
            ['gid' => $goalId, 'uid' => $userId, 'amount' => $amount, 'date' => $date, 'note' => $note]
        );
        $depositId = Database::lastId();

        // completa a meta automaticamente quando o alvo é atingido
        $row = Database::run(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM goal_deposits WHERE goal_id = :gid',
            ['gid' => $goalId]
        )->fetch();
        if ((float) ($row['total'] ?? 0) >= (float) $goal['target_amount'] && $goal['status'] !== 'completed') {
            Database::run(
                'UPDATE goals SET status = "completed", completed_at = NOW() WHERE id = :id',
                ['id' => $goalId]
            );
        }
        return ['ok' => true, 'id' => $depositId];
    }

    public static function deposits(int $userId, int $goalId): array
    {
        return Database::run(
            'SELECT * FROM goal_deposits WHERE goal_id = :gid AND user_id = :uid ORDER BY date DESC, id DESC LIMIT 100',
            ['gid' => $goalId, 'uid' => $userId]
        )->fetchAll();
    }

    /** Calcula campos derivados de uma meta. */
    private static function enrich(array $r): array
    {
        $target = (float) $r['target_amount'];
        $current = (float) ($r['current_amount'] ?? 0);
        $remaining = max(0.0, $target - $current);
        $progress = ($target > 0) ? min(100.0, round(($current / $target) * 100, 1)) : 0.0;

        $monthsLeft = null;
        $monthlyNeeded = null;
        if (!empty($r['deadline'])) {
            $monthsLeft = max(0, (int) self::monthsUntil($r['deadline']));
        }
        if ($remaining > 0) {
            $monthlyNeeded = $remaining / max(1, $monthsLeft ?? 12);
            $monthlyNeeded = round($monthlyNeeded, 2);
        }

        $status = $r['status'];
        if ($current >= $target && $status !== 'completed') {
            $status = 'completed';
        }
        $overdue = ($r['deadline'] !== null && $status === 'active' && $monthsLeft === 0 && $remaining > 0);

        $r['current_amount'] = $current;
        $r['remaining'] = round($remaining, 2);
        $r['progress'] = $progress;
        $r['months_left'] = $monthsLeft;
        $r['monthly_needed'] = $monthlyNeeded;
        $r['status'] = $status;
        $r['overdue'] = $overdue;
        return $r;
    }

    public static function monthsUntil(string $date): int
    {
        $now = new DateTimeImmutable('first day of this month');
        $end = new DateTimeImmutable($date);
        $end = $end->modify('first day of this month');
        return max(0, (int) $now->diff($end)->m + ($now->diff($end)->y * 12));
    }
}
