<?php
/**
 * FinLife — Model de compras em cartões (crédito, débito e benefício).
 *
 * Regra de contabilidade (item 27 — sem dupla contabilização):
 *   credit  → cria parcelas vinculadas às faturas dos ciclos correspondentes.
 *             NÃO gera saída de conta (a saída ocorre no pagamento da fatura).
 *   debit   → cria 1 transaction imediata na conta associada (saída real).
 *   benefit → debita o saldo do benefício (card_balances). Não toca contas.
 *
 * Parcelamento (item 7): "R$ 1.200 em 12x" gera automaticamente 12 parcelas
 * de R$ 100, cada uma com número, data prevista e fatura própria do ciclo.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class CardPurchase
{
    public const METHODS = ['credit', 'debit', 'benefit'];
    private const MAX_INSTALLMENTS = 48;

    // ------------------------------------------------------------------
    // Criação
    // ------------------------------------------------------------------

    public static function create(int $userId, array $d, string $createdBy = 'web'): array
    {
        $cardId = Validator::positiveInt($d['card_id'] ?? null);
        if ($cardId === null) {
            return ['ok' => false, 'error' => 'Escolha o cartão.'];
        }
        $card = Card::get($userId, $cardId);
        if ($card === null) {
            return ['ok' => false, 'error' => 'Cartão não encontrado.'];
        }
        if ($card['status'] === 'blocked') {
            return ['ok' => false, 'error' => 'O cartão "' . $card['name'] . '" está bloqueado. Desbloqueie para registrar compras.'];
        }

        $description = Validator::text($d['description'] ?? '', 255);
        if ($description === '') {
            return ['ok' => false, 'error' => 'Informe a descrição da compra.'];
        }
        $amount = Validator::amount($d['amount'] ?? null);
        if ($amount === null) {
            return ['ok' => false, 'error' => 'Informe um valor válido maior que zero.'];
        }
        $date = Validator::date($d['date'] ?? null) ?? date('Y-m-d');
        $installments = max(1, min(self::MAX_INSTALLMENTS, (int) ($d['installments'] ?? 1)));
        $method = self::resolveMethod($card, $d['method'] ?? null);
        if ($method === null) {
            return ['ok' => false, 'error' => 'Forma de pagamento inválida para este cartão.'];
        }

        // Categoria: precisa ser de despesa do usuário
        $categoryId = Validator::positiveInt($d['category_id'] ?? null);
        if ($categoryId !== null) {
            $cat = Category::get($userId, $categoryId);
            if ($cat === null || $cat['type'] !== 'expense') {
                $categoryId = null;
            }
        }

        $transactionId = null;
        $balanceId = null;

        if ($method === 'debit') {
            // Saída real imediata na conta associada
            $accountId = Validator::positiveInt($d['account_id'] ?? null) ?? $card['account_id'];
            if ($accountId === null || Account::get($userId, $accountId) === null) {
                return ['ok' => false, 'error' => 'Este cartão precisa de uma conta associada para compras no débito.'];
            }
            $tx = Transaction::create($userId, [
                'type'        => 'expense',
                'amount'      => $amount,
                'category_id' => $categoryId,
                'account_id'  => $accountId,
                'description' => $description . ' (débito ' . $card['name'] . ')',
                'date'        => $date,
            ], $createdBy);
            if (($tx['ok'] ?? false) !== true) {
                return ['ok' => false, 'error' => $tx['error'] ?? 'Não foi possível registrar a saída na conta.'];
            }
            $transactionId = (int) $tx['id'];
        }

        if ($method === 'benefit') {
            // O saldo precisa pertencer ao próprio cartão (saldos separados — item 16)
            $balanceId = Validator::positiveInt($d['balance_id'] ?? null);
            if ($balanceId === null) {
                $balances = Card::balancesByCard($userId)[$cardId] ?? [];
                if (count($balances) === 1) {
                    $balanceId = (int) $balances[0]['id'];
                }
            } else {
                $bal = Card::balance($userId, $balanceId);
                if ($bal === null || (int) $bal['card_id'] !== $cardId) {
                    return ['ok' => false, 'error' => 'O saldo de benefício não pertence a este cartão.'];
                }
            }
            if ($balanceId === null) {
                return ['ok' => false, 'error' => 'Escolha o saldo do benefício (VA, VR, ...) desta compra.'];
            }
        }

        Database::run(
            'INSERT INTO card_purchases (user_id, card_id, balance_id, category_id, transaction_id,
                                          description, store, amount, method, installments_total, interest, fees, notes, date, created_by)
             VALUES (:uid, :cid, :bid, :cat, :txid, :descr, :store, :amount, :method, :inst, :interest, :fees, :notes, :date, :by)',
            [
                'uid'     => $userId,
                'cid'     => $cardId,
                'bid'     => $balanceId,
                'cat'     => $categoryId,
                'txid'    => $transactionId,
                'descr'   => $description,
                'store'   => Validator::text($d['store'] ?? '', 120) ?: null,
                'amount'  => $amount,
                'method'  => $method,
                'inst'    => $installments,
                'interest' => Validator::amount($d['interest'] ?? 0) ?? 0.0,
                'fees'    => Validator::amount($d['fees'] ?? 0) ?? 0.0,
                'notes'   => Validator::text($d['notes'] ?? '', 500) ?: null,
                'date'    => $date,
                'by'      => $createdBy,
            ]
        );
        $purchaseId = (int) Database::lastId();

        // Crédito: gera as parcelas e as vincula às faturas dos ciclos
        if ($method === 'credit') {
            $root = Card::rootOf($userId, $cardId);
            if (!in_array($root['type'], ['credit', 'multiple'], true)) {
                // método crédito forçado em cartão de benefício/débito — desfaz
                Database::run('DELETE FROM card_purchases WHERE id = :id', ['id' => $purchaseId]);
                return ['ok' => false, 'error' => 'Compras no crédito são permitidas apenas em cartões de crédito.'];
            }
            self::generateInstallments($userId, $purchaseId, $root, $amount, $installments, $date);
        }

        return ['ok' => true, 'id' => $purchaseId, 'installments' => $method === 'credit' ? $installments : 0];
    }

    /** Resolve o método da compra considerando o tipo do cartão. */
    private static function resolveMethod(array $card, ?string $method): ?string
    {
        $method = Validator::in($method ?? '', self::METHODS);
        $type = $card['type'];
        if ($method === null) {
            return match ($type) {
                'debit' => 'debit',
                'benefit' => 'benefit',
                default => 'credit',
            };
        }
        // valida coerência cartão × método
        if ($method === 'credit' && !in_array($type, ['credit', 'multiple'], true)) {
            return null;
        }
        if ($method === 'debit' && !in_array($type, ['debit', 'multiple'], true)) {
            return null;
        }
        if ($method === 'benefit' && !in_array($type, ['benefit', 'multiple'], true)) {
            return null;
        }
        return $method;
    }

    /**
     * Gera as parcelas da compra (item 7) e as vincula às faturas (item 10).
     * Compras adicionais caem na fatura do cartão principal (raiz).
     */
    private static function generateInstallments(int $userId, int $purchaseId, array $root, float $amount, int $n, string $date): void
    {
        $perInstallment = round($amount / $n, 2);
        $rootId = (int) $root['id'];

        for ($k = 1; $k <= $n; $k++) {
            $base = self::addMonths($date, $k - 1);
            $cycleKey = Card::cycleKeyFor($base, $root);
            $invoice = CardInvoice::getOrCreate($userId, $root, $cycleKey);
            $value = ($k === $n) ? round($amount - $perInstallment * ($n - 1), 2) : $perInstallment;

            Database::run(
                'INSERT INTO card_installments (purchase_id, user_id, card_id, number, total, amount, due_date, invoice_id, status)
                 VALUES (:pid, :uid, :cid, :num, :tot, :amount, :due, :inv, \'pending\')',
                [
                    'pid'    => $purchaseId,
                    'uid'    => $userId,
                    'cid'    => $rootId,
                    'num'    => $k,
                    'tot'    => $n,
                    'amount' => $value,
                    'due'    => $invoice['due_date'],
                    'inv'    => (int) $invoice['id'],
                ]
            );
        }
    }

    /** Adiciona meses preservando o dia (com clamp para meses curtos). */
    private static function addMonths(string $date, int $n): string
    {
        if ($n === 0) {
            return $date;
        }
        $t = strtotime($date);
        $day = (int) date('j', $t);
        $target = strtotime(date('Y-m-01', $t) . ' +' . $n . ' months');
        $lastDay = (int) date('t', $target);
        return date('Y-m-', $target) . sprintf('%02d', min($day, $lastDay));
    }

    // ------------------------------------------------------------------
    // Edição e exclusão
    // ------------------------------------------------------------------

    /** Edita metadados. Valor/parcelas só podem mudar se nenhuma parcela foi paga. */
    public static function update(int $userId, int $id, array $d): array
    {
        $purchase = self::get($userId, $id);
        if ($purchase === null) {
            return ['ok' => false, 'error' => 'Compra não encontrada.'];
        }

        $paidCount = (int) (Database::run(
            "SELECT COUNT(*) AS c FROM card_installments WHERE purchase_id = :pid AND status = 'paid'",
            ['pid' => $id]
        )->fetch()['c'] ?? 0);

        $newAmount = Validator::amount($d['amount'] ?? $purchase['amount']) ?? (float) $purchase['amount'];
        $newInstallments = max(1, min(self::MAX_INSTALLMENTS, (int) ($d['installments_total'] ?? $purchase['installments_total'])));
        $newDate = Validator::date($d['date'] ?? null) ?? $purchase['date'];
        $changedPlan = (abs($newAmount - (float) $purchase['amount']) > 0.005)
            || $newInstallments !== (int) $purchase['installments_total']
            || $newDate !== $purchase['date'];

        if ($changedPlan && $purchase['method'] === 'credit') {
            if ($paidCount > 0) {
                return ['ok' => false, 'error' => 'Esta compra já possui parcelas pagas (fatura quitada). Exclua e registre uma nova compra para alterar o plano.'];
            }
            // regenera o parcelamento
            Database::run('DELETE FROM card_installments WHERE purchase_id = :pid', ['pid' => $id]);
            $root = Card::rootOf($userId, (int) $purchase['card_id']);
            self::generateInstallments($userId, $id, $root, $newAmount, $newInstallments, $newDate);
        }

        $categoryId = Validator::positiveInt($d['category_id'] ?? $purchase['category_id']);
        if ($categoryId !== null) {
            $cat = Category::get($userId, $categoryId);
            if ($cat === null) {
                $categoryId = $purchase['category_id'];
            }
        }

        // Compra no débito com valor alterado: ajusta a transaction vinculada
        if ($purchase['method'] === 'debit' && $changedPlan && $purchase['transaction_id'] !== null) {
            Database::run(
                'UPDATE transactions SET amount = :amount, date = :date, description = :descr WHERE id = :id AND user_id = :uid',
                [
                    'amount' => $newAmount,
                    'date'   => $newDate,
                    'descr'  => Validator::text($d['description'] ?? $purchase['description'], 255),
                    'id'     => (int) $purchase['transaction_id'],
                    'uid'    => $userId,
                ]
            );
        }

        Database::run(
            'UPDATE card_purchases
             SET description = :descr, store = :store, amount = :amount, installments_total = :inst,
                 date = :date, category_id = :cat, interest = :interest, fees = :fees, notes = :notes
             WHERE id = :id AND user_id = :uid',
            [
                'id'       => $id,
                'uid'      => $userId,
                'descr'    => Validator::text($d['description'] ?? $purchase['description'], 255),
                'store'    => Validator::text($d['store'] ?? '', 120) ?: null,
                'amount'   => $newAmount,
                'inst'     => $newInstallments,
                'date'     => $newDate,
                'cat'      => $categoryId,
                'interest' => Validator::amount($d['interest'] ?? $purchase['interest']) ?? 0.0,
                'fees'     => Validator::amount($d['fees'] ?? $purchase['fees']) ?? 0.0,
                'notes'    => Validator::text($d['notes'] ?? '', 500) ?: null,
            ]
        );
        return ['ok' => true, 'id' => $id];
    }

    public static function delete(int $userId, int $id): bool
    {
        $purchase = self::get($userId, $id);
        if ($purchase === null) {
            return false;
        }
        // Compra no débito: remove a saída real da conta
        if ($purchase['transaction_id'] !== null) {
            Database::run(
                'DELETE FROM transactions WHERE id = :id AND user_id = :uid',
                ['id' => (int) $purchase['transaction_id'], 'uid' => $userId]
            );
        }
        return Database::run(
            'DELETE FROM card_purchases WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0; // parcelas caem por CASCADE
    }

    // ------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------

    public static function get(int $userId, int $id): ?array
    {
        return Database::run(
            'SELECT * FROM card_purchases WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch() ?: null;
    }

    /** Lista de compras com filtros (cartão, mês, método, busca). */
    public static function list(int $userId, array $f = []): array
    {
        $sql = 'SELECT p.*, c.name AS card_name, c.color AS card_color, c.last4 AS card_last4, c.type AS card_type,
                       c.parent_card_id, pc.name AS parent_name,
                       cat.name AS category_name, cat.color AS category_color, cat.icon AS category_icon,
                       cb.benefit_kind
                FROM card_purchases p
                JOIN cards c ON c.id = p.card_id
                LEFT JOIN cards pc ON pc.id = c.parent_card_id
                LEFT JOIN categories cat ON cat.id = p.category_id
                LEFT JOIN card_balances cb ON cb.id = p.balance_id
                WHERE p.user_id = :uid';
        $params = ['uid' => $userId];

        if (!empty($f['month']) && preg_match('~^\d{4}-\d{2}$~', (string) $f['month'])) {
            [$s, $e] = month_bounds((string) $f['month']);
            $sql .= ' AND p.date BETWEEN :ms AND :me';
            $params['ms'] = $s;
            $params['me'] = $e;
        }
        if (!empty($f['card_id'])) {
            $sql .= ' AND (p.card_id = :cid OR c.parent_card_id = :cid2)';
            $params['cid'] = (int) $f['card_id'];
            $params['cid2'] = (int) $f['card_id'];
        }
        if (!empty($f['method']) && Validator::in($f['method'], self::METHODS)) {
            $sql .= ' AND p.method = :m';
            $params['m'] = $f['method'];
        }
        if (!empty($f['q'])) {
            $sql .= ' AND (p.description LIKE :q OR p.store LIKE :q2)';
            $params['q'] = '%' . Validator::text($f['q'], 60) . '%';
            $params['q2'] = $params['q'];
        }
        $sql .= ' ORDER BY p.date DESC, p.id DESC LIMIT 200';

        $rows = Database::run($sql, $params)->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::hydrate($r);
        }
        return $out;
    }

    /** Detalhe de uma compra com a timeline completa de parcelas. */
    public static function detail(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT p.*, c.name AS card_name, c.color AS card_color, c.last4 AS card_last4,
                    cat.name AS category_name, cat.color AS category_color
             FROM card_purchases p
             JOIN cards c ON c.id = p.card_id
             LEFT JOIN categories cat ON cat.id = p.category_id
             WHERE p.id = :id AND p.user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        if ($row === null) {
            return null;
        }
        $purchase = self::hydrate($row);
        $purchase['installments_list'] = Database::run(
            'SELECT ins.number, ins.total, ins.amount, ins.due_date, ins.status, ins.paid_amount,
                    i.due_date AS invoice_due, i.cycle_key AS invoice_cycle
             FROM card_installments ins
             LEFT JOIN card_invoices i ON i.id = ins.invoice_id
             WHERE ins.purchase_id = :pid ORDER BY ins.number',
            ['pid' => $id]
        )->fetchAll();
        return $purchase;
    }

    private static function hydrate(array $r): array
    {
        $isInstallment = (int) $r['installments_total'] > 1;
        $pending = null;
        if ($r['method'] === 'credit' && $isInstallment) {
            $pending = (int) (Database::run(
                "SELECT COUNT(*) AS c FROM card_installments WHERE purchase_id = :pid AND status = 'pending'",
                ['pid' => (int) $r['id']]
            )->fetch()['c'] ?? 0);
        }
        return [
            'id'               => (int) $r['id'],
            'card_id'          => (int) $r['card_id'],
            'card_name'        => $r['card_name'],
            'card_color'       => $r['card_color'],
            'card_last4'       => $r['card_last4'],
            'card_type'        => $r['card_type'],
            'parent_name'      => $r['parent_name'] ?? null,
            'is_additional'    => $r['parent_card_id'] !== null,
            'method'           => $r['method'],
            'method_label'     => Card::methodLabel((string) $r['method']),
            'benefit_kind'     => $r['benefit_kind'] ?? null,
            'benefit_label'    => isset($r['benefit_kind']) ? (Card::BENEFIT_LABELS[$r['benefit_kind']] ?? null) : null,
            'description'      => $r['description'],
            'store'            => $r['store'],
            'amount'           => (float) $r['amount'],
            'installments_total' => (int) $r['installments_total'],
            'installment_amount' => $isInstallment ? round((float) $r['amount'] / (int) $r['installments_total'], 2) : (float) $r['amount'],
            'pending_installments' => $pending,
            'interest'         => (float) $r['interest'],
            'fees'             => (float) $r['fees'],
            'notes'            => $r['notes'],
            'date'             => $r['date'],
            'created_by'       => $r['created_by'],
            'category_id'      => $r['category_id'] !== null ? (int) $r['category_id'] : null,
            'category_name'    => $r['category_name'] ?? null,
            'category_color'   => $r['category_color'] ?? null,
            'category_icon'    => $r['category_icon'] ?? null,
        ];
    }

    /**
     * Parcelas futuras agrupadas por mês (item 8) — quanto já está
     * comprometido nos próximos meses.
     */
    public static function installmentsTimeline(int $userId): array
    {
        $rows = Database::run(
            "SELECT ins.*, p.description, p.installments_total, p.method,
                    c.name AS card_name, c.color AS card_color
             FROM card_installments ins
             JOIN card_purchases p ON p.id = ins.purchase_id
             JOIN cards c ON c.id = p.card_id
             WHERE ins.user_id = :uid AND ins.status = 'pending'
             ORDER BY ins.due_date ASC, ins.id ASC",
            ['uid' => $userId]
        )->fetchAll();

        $byMonth = [];
        foreach ($rows as $r) {
            $ym = substr((string) $r['due_date'], 0, 7);
            $byMonth[$ym]['month'] = $ym;
            $byMonth[$ym]['total'] = ($byMonth[$ym]['total'] ?? 0) + ((float) $r['amount'] - (float) $r['paid_amount']);
            $byMonth[$ym]['items'][] = [
                'id'           => (int) $r['id'],
                'purchase_id'  => (int) $r['purchase_id'],
                'number'       => (int) $r['number'],
                'total'        => (int) $r['total'],
                'amount'       => (float) $r['amount'],
                'due_date'     => $r['due_date'],
                'paid_amount'  => (float) $r['paid_amount'],
                'description'  => $r['description'],
                'card_name'    => $r['card_name'],
                'card_color'   => $r['card_color'],
            ];
        }
        return array_values($byMonth);
    }
}
