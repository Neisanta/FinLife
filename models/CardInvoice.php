<?php
/**
 * FinLife — Model de faturas de cartão de crédito.
 *
 * Ciclo: fechamento (closing_day) → vencimento (due_day). Uma compra entra
 * na fatura do ciclo que contém sua data (item 10 — não usa mês calendário).
 *
 * Pagamento: cada pagamento cria 1 saída REAL na conta (transaction) e
 * quita parcelas da fatura em ordem — liberando o limite do cartão.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class CardInvoice
{
    /** Subconsulta: faturas com saldo em aberto (total de parcelas − pagamentos). */
    public const OPEN_SUM_SQL =
        'SELECT i.id, i.card_id, i.cycle_key, i.due_date, i.status,
                COALESCE((SELECT SUM(ins.amount) FROM card_installments ins WHERE ins.invoice_id = i.id), 0)
              - COALESCE((SELECT SUM(pay.amount) FROM card_invoice_payments pay WHERE pay.invoice_id = i.id), 0) AS open_total
         FROM card_invoices i
         WHERE i.user_id = :uid';

    // ------------------------------------------------------------------
    // Criação/obtenção de faturas
    // ------------------------------------------------------------------

    /** Retorna a fatura do ciclo — criando-a se ainda não existir. */
    public static function getOrCreate(int $userId, array $rootCard, string $cycleKey): array
    {
        $existing = Database::run(
            'SELECT * FROM card_invoices WHERE card_id = :cid AND cycle_key = :ck LIMIT 1',
            ['cid' => (int) $rootCard['id'], 'ck' => $cycleKey]
        )->fetch();
        if ($existing) {
            return $existing;
        }

        $dueDate = Card::dueDateForCycle($cycleKey, (int) ($rootCard['due_day'] ?? 10));
        $prevCycle = date('Y-m-d', strtotime($cycleKey . ' -1 month'));
        $periodStart = date('Y-m-d', strtotime($prevCycle . ' +1 day'));

        Database::run(
            'INSERT INTO card_invoices (user_id, card_id, cycle_key, period_start, period_end, due_date, status)
             VALUES (:uid, :cid, :ck, :ps, :pe, :dd, :st)',
            [
                'uid' => $userId,
                'cid' => (int) $rootCard['id'],
                'ck'  => $cycleKey,
                'ps'  => $periodStart,
                'pe'  => $cycleKey,
                'dd'  => $dueDate,
                'st'  => 'open',
            ]
        );
        return self::get($userId, (int) Database::lastId());
    }

    public static function get(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM card_invoices WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        return $row ?: null;
    }

    /** Faturas com dados computados (total, pago, restante, status derivado). */
    public static function hydrate(array $inv, ?array $card = null): array
    {
        $invoiceId = (int) $inv['id'];
        $totals = Database::run(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM card_installments WHERE invoice_id = :iid',
            ['iid' => $invoiceId]
        )->fetch();
        $paid = Database::run(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM card_invoice_payments WHERE invoice_id = :iid',
            ['iid' => $invoiceId]
        )->fetch();
        $total = (float) ($totals['total'] ?? 0);
        $paidTotal = (float) ($paid['total'] ?? 0);
        $remaining = max(0.0, $total - $paidTotal);
        $today = date('Y-m-d');

        // Status derivado (itens 9 e 10)
        if ($total <= 0 && $paidTotal <= 0) {
            $status = 'empty'; $label = 'Sem movimentações';
        } elseif ($remaining <= 0.005) {
            $status = 'paid'; $label = 'Paga';
        } elseif ($inv['due_date'] < $today) {
            $status = 'late'; $label = 'Atrasada';
        } elseif ($paidTotal > 0) {
            $status = 'partial'; $label = 'Parcialmente paga';
        } elseif ($today > $inv['period_end']) {
            $status = 'closed'; $label = 'Fechada';
        } else {
            $status = 'open'; $label = 'Aberta';
        }

        return [
            'id'              => $invoiceId,
            'card_id'         => (int) $inv['card_id'],
            'card_name'       => $card['name'] ?? null,
            'card_color'      => $card['color'] ?? null,
            'card_last4'      => $card['last4'] ?? null,
            'cycle_key'       => $inv['cycle_key'],
            'period_start'    => $inv['period_start'],
            'period_end'      => $inv['period_end'],
            'due_date'        => $inv['due_date'],
            'total'           => round($total, 2),
            'paid_total'      => round($paidTotal, 2),
            'remaining'       => round($remaining, 2),
            'minimum_payment' => $total > 0 ? round(max(1.0, $total * 0.15), 2) : 0.0,
            'status'          => $status,
            'status_label'    => $label,
            'installments_count' => (int) (Database::run(
                'SELECT COUNT(*) AS c FROM card_installments WHERE invoice_id = :iid',
                ['iid' => $invoiceId]
            )->fetch()['c'] ?? 0),
        ];
    }

    /** Lista de faturas (todas ou de um cartão), mais recentes primeiro. */
    public static function list(int $userId, ?int $cardId = null): array
    {
        $sql = 'SELECT i.*, c.name AS card_name, c.color AS card_color, c.last4 AS card_last4, c.due_day, c.closing_day
                FROM card_invoices i JOIN cards c ON c.id = i.card_id
                WHERE i.user_id = :uid';
        $params = ['uid' => $userId];
        if ($cardId !== null) {
            $sql .= ' AND i.card_id = :cid';
            $params['cid'] = $cardId;
        }
        $sql .= ' ORDER BY i.cycle_key DESC, i.card_id LIMIT 60';
        $rows = Database::run($sql, $params)->fetchAll();

        return array_map(static fn ($r) => self::hydrate($r, $r), $rows);
    }

    /** Por cartão: fatura atual (ciclo aberto) e próximo vencimento não pago. */
    public static function openSummaryByCard(int $userId): array
    {
        $rows = Database::run(
            'SELECT f.card_id, f.cycle_key, f.due_date, f.open_total
             FROM (' . self::OPEN_SUM_SQL . ') f
             WHERE f.open_total > 0.005
             ORDER BY f.due_date',
            ['uid' => $userId]
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['card_id'];
            // próximo vencimento: menor due_date não pago
            if (!isset($out[$cid]['next_due'])) {
                $out[$cid]['next_due'] = [
                    'open_total' => (float) $r['open_total'],
                    'due_date'   => $r['due_date'],
                    'cycle_key'  => $r['cycle_key'],
                ];
            }
            // fatura atual: ciclo ainda aberto (fechamento >= hoje)
            if ($r['cycle_key'] >= date('Y-m-d') && !isset($out[$cid]['current'])) {
                $out[$cid]['current'] = [
                    'open_total' => (float) $r['open_total'],
                    'due_date'   => $r['due_date'],
                    'cycle_key'  => $r['cycle_key'],
                ];
            }
        }
        return $out;
    }

    /** Média das últimas N faturas quitadas de um cartão (base para alerta de valor acima da média). */
    public static function closedAverage(int $userId, int $cardId, int $n = 3): float
    {
        $rows = Database::run(
            'SELECT i.id, i.cycle_key,
                    COALESCE((SELECT SUM(ins.amount) FROM card_installments ins WHERE ins.invoice_id = i.id), 0) AS total
             FROM card_invoices i
             WHERE i.user_id = :uid AND i.card_id = :cid AND i.cycle_key < :today
             ORDER BY i.cycle_key DESC
             LIMIT ' . max(1, min(12, $n)),
            ['uid' => $userId, 'cid' => $cardId, 'today' => date('Y-m-d')]
        )->fetchAll();
        if ($rows === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($rows as $r) {
            $sum += (float) $r['total'];
        }
        return $sum / count($rows);
    }

    // ------------------------------------------------------------------
    // Fechamento
    // ------------------------------------------------------------------

    /** Marca a fatura como fechada (ação manual — normalmente derivada da data). */
    public static function close(int $userId, int $invoiceId): bool
    {
        return Database::run(
            "UPDATE card_invoices SET status = 'closed' WHERE id = :id AND user_id = :uid",
            ['id' => $invoiceId, 'uid' => $userId]
        )->rowCount() > 0;
    }

    // ------------------------------------------------------------------
    // Pagamentos (item 11) — cada pagamento gera 1 saída REAL na conta
    // ------------------------------------------------------------------

    public static function pay(int $userId, int $invoiceId, array $d): array
    {
        $inv = self::get($userId, $invoiceId);
        if ($inv === null) {
            return ['ok' => false, 'error' => 'Fatura não encontrada.'];
        }
        $hydrated = self::hydrate($inv);
        $remaining = (float) $hydrated['remaining'];
        $amount = Validator::amount($d['amount'] ?? null);
        if ($amount === null) {
            return ['ok' => false, 'error' => 'Informe um valor de pagamento válido.'];
        }
        if ($amount > $remaining + 0.005) {
            return ['ok' => false, 'error' => 'O valor excede o restante da fatura (' . money($remaining) . ').'];
        }

        $date = Validator::date($d['date'] ?? null) ?? date('Y-m-d');
        $accountId = Validator::positiveInt($d['account_id'] ?? null);
        if ($accountId === null || Account::get($userId, $accountId) === null) {
            return ['ok' => false, 'error' => 'Escolha a conta usada para o pagamento.'];
        }

        $card = Card::get($userId, (int) $inv['card_id']);
        $kind = ($amount >= $remaining - 0.005) ? 'full' : 'partial';
        $cycleLabel = ContextBuilder::monthLabel(substr((string) $inv['cycle_key'], 0, 7));
        $description = 'Pagamento de fatura ' . ($card['name'] ?? 'cartão') . ' (' . $cycleLabel . ')';

        // 1) Saída real na conta (a ÚNICA saída gerada por compras a crédito)
        $categoryId = self::invoiceCategoryId($userId);
        $tx = Transaction::create($userId, [
            'type'        => 'expense',
            'amount'      => $amount,
            'category_id' => $categoryId,
            'account_id'  => $accountId,
            'description' => $description,
            'date'        => $date,
        ], 'web');
        if (($tx['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => $tx['error'] ?? 'Não foi possível registrar a saída na conta.'];
        }

        // 2) Registro do pagamento da fatura
        Database::run(
            'INSERT INTO card_invoice_payments (invoice_id, user_id, amount, date, account_id, transaction_id, kind, note)
             VALUES (:iid, :uid, :amount, :date, :acc, :tx, :kind, :note)',
            [
                'iid'    => $invoiceId,
                'uid'    => $userId,
                'amount' => $amount,
                'date'   => $date,
                'acc'    => $accountId,
                'tx'     => (int) $tx['id'],
                'kind'   => $kind,
                'note'   => Validator::text($d['note'] ?? '', 255) ?: null,
            ]
        );

        // 3) Quita parcelas em ordem (libera o limite do cartão)
        self::applyPaymentsToInstallments($invoiceId);

        return ['ok' => true, 'id' => Database::lastId(), 'kind' => $kind, 'transaction_id' => (int) $tx['id']];
    }

    /** Remove um pagamento (reverte a saída da conta e a quitação das parcelas). */
    public static function removePayment(int $userId, int $paymentId): array
    {
        $pay = Database::run(
            'SELECT * FROM card_invoice_payments WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $paymentId, 'uid' => $userId]
        )->fetch();
        if ($pay === null) {
            return ['ok' => false, 'error' => 'Pagamento não encontrado.'];
        }

        // Reverte a transaction (saída da conta)
        if ($pay['transaction_id'] !== null) {
            $tx = Database::run(
                'SELECT id FROM transactions WHERE id = :id AND user_id = :uid LIMIT 1',
                ['id' => (int) $pay['transaction_id'], 'uid' => $userId]
            )->fetch();
            if ($tx) {
                Database::run('DELETE FROM transactions WHERE id = :id AND user_id = :uid', ['id' => (int) $tx['id'], 'uid' => $userId]);
            }
        }

        Database::run('DELETE FROM card_invoice_payments WHERE id = :id AND user_id = :uid', ['id' => $paymentId, 'uid' => $userId]);
        self::applyPaymentsToInstallments((int) $pay['invoice_id']);
        return ['ok' => true];
    }

    /**
     * Redistribui todos os pagamentos da fatura sobre as parcelas, em ordem.
     * Determinístico: parcelas mais antigas são quitadas primeiro.
     */
    private static function applyPaymentsToInstallments(int $invoiceId): void
    {
        Database::run(
            "UPDATE card_installments SET status = 'pending', paid_amount = 0, paid_at = NULL WHERE invoice_id = :iid",
            ['iid' => $invoiceId]
        );

        $payments = Database::run(
            'SELECT amount, date FROM card_invoice_payments WHERE invoice_id = :iid ORDER BY date ASC, id ASC',
            ['iid' => $invoiceId]
        )->fetchAll();
        $installments = Database::run(
            'SELECT id, amount FROM card_installments WHERE invoice_id = :iid ORDER BY number ASC, id ASC',
            ['iid' => $invoiceId]
        )->fetchAll();

        $pool = 0.0;
        $lastDate = null;
        foreach ($payments as $p) {
            $pool += (float) $p['amount'];
            $lastDate = $p['date'];
        }
        foreach ($installments as $ins) {
            if ($pool <= 0.005) {
                break;
            }
            $due = (float) $ins['amount'];
            $apply = min($pool, $due);
            $isPaid = ($apply >= $due - 0.005);
            Database::run(
                'UPDATE card_installments SET status = :st, paid_amount = :paid, paid_at = :at WHERE id = :id',
                [
                    'st'   => $isPaid ? 'paid' : 'pending',
                    'paid' => round($apply, 2),
                    'at'   => $isPaid ? ($lastDate . ' ' . date('H:i:s')) : null,
                    'id'   => (int) $ins['id'],
                ]
            );
            $pool -= $apply;
        }
    }

    /** Categoria "Fatura de cartão" (criada automaticamente na primeira necessidade). */
    private static function invoiceCategoryId(int $userId): ?int
    {
        $cat = Category::matchByName($userId, 'fatura de cartão', 'expense');
        if ($cat !== null) {
            return (int) $cat['id'];
        }
        $id = Category::create($userId, [
            'type'  => 'expense',
            'name'  => 'Fatura de cartão',
            'icon'  => '💳',
            'color' => '#4F46E5',
        ]);
        return $id > 0 ? $id : null;
    }
}
