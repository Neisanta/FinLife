<?php
/**
 * FinLife — Model de transações (receitas e despesas).
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Transaction
{
    public const TYPES = ['income', 'expense'];

    /** Cria uma transação validada. Retorna ['ok', id|error]. */
    public static function create(int $userId, array $d, string $createdBy = 'web'): array
    {
        $type = Validator::in($d['type'] ?? '', self::TYPES);
        if ($type === null) {
            return ['ok' => false, 'error' => 'Tipo de transação inválido.'];
        }
        $amount = Validator::amount($d['amount'] ?? null);
        if ($amount === null) {
            return ['ok' => false, 'error' => 'Informe um valor válido maior que zero.'];
        }
        $description = Validator::text($d['description'] ?? '', 255);
        if ($description === '') {
            return ['ok' => false, 'error' => 'Informe uma descrição.'];
        }
        $date = Validator::date($d['date'] ?? '') ?? date('Y-m-d');
        if (strtotime($date) < strtotime('-5 years') || strtotime($date) > strtotime('+2 years')) {
            return ['ok' => false, 'error' => 'Data fora do intervalo permitido.'];
        }

        $accountId = Validator::positiveInt($d['account_id'] ?? null);
        if ($accountId !== null && Account::get($userId, $accountId) === null) {
            return ['ok' => false, 'error' => 'Conta inválida.'];
        }
        $categoryId = Validator::positiveInt($d['category_id'] ?? null);
        if ($categoryId !== null) {
            $cat = Category::get($userId, $categoryId);
            if ($cat === null) {
                return ['ok' => false, 'error' => 'Categoria inválida.'];
            }
            if ($cat['type'] !== $type) {
                return ['ok' => false, 'error' => 'A categoria escolhida não corresponde ao tipo de transação.'];
            }
        } else {
            $cat = Category::fallbackFor($userId, $type);
            $categoryId = $cat ? (int) $cat['id'] : null;
        }

        Database::run(
            'INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, created_by)
             VALUES (:uid, :acc, :cat, :type, :amount, :descr, :date, :by)',
            [
                'uid'   => $userId,
                'acc'   => $accountId,
                'cat'   => $categoryId,
                'type'  => $type,
                'amount' => $amount,
                'descr' => $description,
                'date'  => $date,
                'by'    => $createdBy,
            ]
        );
        return ['ok' => true, 'id' => Database::lastId()];
    }

    public static function update(int $userId, int $id, array $d): array
    {
        $tx = self::get($userId, $id);
        if ($tx === null) {
            return ['ok' => false, 'error' => 'Transação não encontrada.'];
        }
        $type = Validator::in($d['type'] ?? $tx['type'], self::TYPES) ?? $tx['type'];
        $amount = Validator::amount($d['amount'] ?? $tx['amount']) ?? (float) $tx['amount'];
        $description = Validator::text($d['description'] ?? $tx['description'], 255);
        $date = Validator::date($d['date'] ?? $tx['date']) ?? $tx['date'];
        if ($description === '') {
            return ['ok' => false, 'error' => 'Informe uma descrição.'];
        }
        if ((float) $tx['amount'] !== $amount && $amount <= 0) {
            return ['ok' => false, 'error' => 'Valor inválido.'];
        }

        $accountId = Validator::positiveInt($d['account_id'] ?? $tx['account_id']);
        if ($accountId !== null && Account::get($userId, $accountId) === null) {
            return ['ok' => false, 'error' => 'Conta inválida.'];
        }
        $categoryId = Validator::positiveInt($d['category_id'] ?? $tx['category_id']);
        if ($categoryId !== null) {
            $cat = Category::get($userId, $categoryId);
            if ($cat === null || $cat['type'] !== $type) {
                return ['ok' => false, 'error' => 'Categoria inválida para este tipo de transação.'];
            }
        }

        Database::run(
            'UPDATE transactions
             SET account_id = :acc, category_id = :cat, type = :type, amount = :amount,
                 description = :descr, date = :date
             WHERE id = :id AND user_id = :uid',
            [
                'id'     => $id,
                'uid'    => $userId,
                'acc'    => $accountId,
                'cat'    => $categoryId,
                'type'   => $type,
                'amount' => $amount,
                'descr'  => $description,
                'date'   => $date,
            ]
        );
        return ['ok' => true, 'id' => $id];
    }

    public static function delete(int $userId, int $id): bool
    {
        return Database::run(
            'DELETE FROM transactions WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function get(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM transactions WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        return $row ?: null;
    }

    /**
     * Lista com filtros (mes, tipo, categoria, conta, busca textual).
     */
    public static function list(int $userId, array $f): array
    {
        $where = ['t.user_id = :uid'];
        $params = ['uid' => $userId];

        if (!empty($f['month'])) {
            [$m0, $m1] = month_bounds($f['month']);
            $where[] = 't.date BETWEEN :m0 AND :m1';
            $params['m0'] = $m0;
            $params['m1'] = $m1;
        }
        if (!empty($f['type']) && in_array($f['type'], self::TYPES, true)) {
            $where[] = 't.type = :type';
            $params['type'] = $f['type'];
        }
        if (!empty($f['category_id'])) {
            $where[] = 't.category_id = :cat';
            $params['cat'] = (int) $f['category_id'];
        }
        if (!empty($f['account_id'])) {
            $where[] = 't.account_id = :acc';
            $params['acc'] = (int) $f['account_id'];
        }
        if (!empty($f['q'])) {
            $where[] = 't.description LIKE :q';
            $params['q'] = '%' . Validator::text($f['q'], 60) . '%';
        }

        $limit = min(200, max(1, (int) ($f['limit'] ?? 100)));
        $offset = max(0, (int) ($f['offset'] ?? 0));

        $sql = 'SELECT t.id, t.account_id, t.category_id, t.type, t.amount, t.description,
                       t.date, t.created_by, t.created_at,
                       c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                       a.name AS account_name
                FROM transactions t
                LEFT JOIN categories c ON c.id = t.category_id
                LEFT JOIN accounts a ON a.id = t.account_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY t.date DESC, t.id DESC
                LIMIT ' . $limit . ' OFFSET ' . $offset;
        return Database::run($sql, $params)->fetchAll();
    }

    /** Totais do mês. */
    public static function monthTotals(int $userId, string $month): array
    {
        [$m0, $m1] = month_bounds($month);
        $rows = Database::run(
            'SELECT type, COALESCE(SUM(amount), 0) AS total
             FROM transactions
             WHERE user_id = :uid AND date BETWEEN :m0 AND :m1
             GROUP BY type',
            ['uid' => $userId, 'm0' => $m0, 'm1' => $m1]
        )->fetchAll();
        $out = ['income' => 0.0, 'expense' => 0.0];
        foreach ($rows as $r) {
            $out[$r['type']] = (float) $r['total'];
        }
        $out['net'] = $out['income'] - $out['expense'];
        $out['savings_rate'] = ($out['income'] > 0)
            ? round(($out['net'] / $out['income']) * 100, 1)
            : null;
        return $out;
    }

    /** Despesas (ou receitas) por categoria em um mês. */
    public static function byCategory(int $userId, string $month, string $type = 'expense'): array
    {
        [$m0, $m1] = month_bounds($month);
        $rows = Database::run(
            "SELECT c.name, c.color, c.icon, COALESCE(SUM(t.amount), 0) AS total
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = :uid AND t.type = :type AND t.date BETWEEN :m0 AND :m1
             GROUP BY c.id, c.name, c.color, c.icon
             ORDER BY total DESC",
            ['uid' => $userId, 'type' => $type, 'm0' => $m0, 'm1' => $m1]
        )->fetchAll();
        $sum = array_sum(array_map(floatval(...), array_column($rows, 'total')));
        foreach ($rows as &$r) {
            $r['total'] = (float) $r['total'];
            $r['pct'] = ($sum > 0) ? round(($r['total'] / $sum) * 100, 1) : 0;
        }
        return $rows;
    }

    /** Evolução mensal: receitas e despesas dos últimos N meses. */
    public static function evolution(int $userId, int $months = 6, ?string $from = null): array
    {
        $list = last_months($months, $from);
        $start = $list[0] . '-01';
        $rows = Database::run(
            "SELECT DATE_FORMAT(date, '%Y-%m') AS ym, type, COALESCE(SUM(amount), 0) AS total
             FROM transactions
             WHERE user_id = :uid AND date >= :start
             GROUP BY ym, type",
            ['uid' => $userId, 'start' => $start]
        )->fetchAll();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['ym']][$r['type']] = (float) $r['total'];
        }
        $out = [];
        foreach ($list as $ym) {
            $out[] = [
                'month'  => $ym,
                'income' => $map[$ym]['income'] ?? 0.0,
                'expense' => $map[$ym]['expense'] ?? 0.0,
            ];
        }
        return $out;
    }

    /** Transações mais recentes. */
    public static function recent(int $userId, int $limit = 8): array
    {
        return Database::run(
            'SELECT t.id, t.type, t.amount, t.description, t.date, t.created_by,
                    c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                    a.name AS account_name
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN accounts a ON a.id = t.account_id
             WHERE t.user_id = :uid
             ORDER BY t.date DESC, t.id DESC
             LIMIT ' . max(1, min(50, $limit)),
            ['uid' => $userId]
        )->fetchAll();
    }

    /** Total gasto em uma categoria no mês (para perguntas da IA). */
    public static function categoryTotal(int $userId, int $categoryId, string $month, ?string $type = null): float
    {
        [$m0, $m1] = month_bounds($month);
        $sql = 'SELECT COALESCE(SUM(amount), 0) AS total
                FROM transactions
                WHERE user_id = :uid AND category_id = :cat AND date BETWEEN :m0 AND :m1';
        $params = ['uid' => $userId, 'cat' => $categoryId, 'm0' => $m0, 'm1' => $m1];
        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }
        return (float) (Database::run($sql, $params)->fetch()['total'] ?? 0);
    }

    /** Total de uma categoria nos últimos N meses. */
    public static function categoryTotalMonths(int $userId, int $categoryId, int $months): float
    {
        $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        return (float) (Database::run(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM transactions
             WHERE user_id = :uid AND category_id = :cat AND date >= :start AND type = "expense"',
            ['uid' => $userId, 'cat' => $categoryId, 'start' => $start]
        )->fetch()['total'] ?? 0);
    }

    public static function countByCategory(int $userId, int $categoryId): int
    {
        return (int) (Database::run(
            'SELECT COUNT(*) AS c FROM transactions WHERE user_id = :uid AND category_id = :cat',
            ['uid' => $userId, 'cat' => $categoryId]
        )->fetch()['c'] ?? 0);
    }
}
