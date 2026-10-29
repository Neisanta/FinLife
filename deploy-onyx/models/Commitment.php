<?php
/**
 * FinLife — Model de Compromissos e Contas Fixas.
 *
 * kind='debt':      dívida com valor total (total_amount) — saldo devedor =
 *                   total - soma dos pagamentos. Ex.: R$ 1.200 ao pai, R$ 200/mês.
 * kind='recurring': conta fixa sem total (telefone, internet) — apenas ocorrências
 *                   mensais; valor pode variar por ocorrência.
 *
 * Regras:
 * - Cada vencimento gera UMA ocorrência (UNIQUE commitment_id+due_date).
 * - Pagamentos reduzem o saldo uma única vez (registro + transação DB).
 * - Pago integral → gera próxima ocorrência e avança next_due.
 * - Dívida quitada → ocorrências futuras pendentes viram 'skipped' (histórico preservado).
 * - Status de atraso/em dia é DERIVADO (não persistido).
 * - Cada pagamento espelha uma transação expense (transaction_id) — sem duplicar
 *   despesas: o vínculo é único e o undo reverte o par.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Commitment
{
    public const CATEGORY_LABELS = [
        'divida_pessoal' => 'Dívida pessoal',
        'telefone' => 'Telefone',
        'internet' => 'Internet',
        'servico' => 'Serviço',
        'mensalidade' => 'Mensalidade',
        'emprestimo_informal' => 'Empréstimo informal',
        'assinatura' => 'Assinatura',
        'outro' => 'Outro',
    ];

    public const PERIODICITY_LABELS = [
        'weekly' => 'Semanal',
        'biweekly' => 'Quinzenal',
        'monthly' => 'Mensal',
        'quarterly' => 'Trimestral',
        'semiannual' => 'Semestral',
        'yearly' => 'Anual',
        'custom' => 'Personalizada',
    ];

    public const PAYMENT_METHODS = ['pix', 'dinheiro', 'debito', 'transferencia', 'boleto', 'credito', 'outro'];

    // ------------------------------------------------------------------
    // Listagem e leitura
    // ------------------------------------------------------------------

    /** Lista com status derivado, saldo devedor e progresso. */
    public static function list(int $userId, array $filters = []): array
    {
        $sql = 'SELECT c.*, a.name AS account_name
                FROM commitments c
                LEFT JOIN accounts a ON a.id = c.account_id
                WHERE c.user_id = :uid';
        $params = ['uid' => $userId];
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'overdue') {
                // atrasado: derivado — ocorrência pendente vencida
                $sql .= " AND c.status = 'active' AND EXISTS (
                          SELECT 1 FROM commitment_occurrences o
                          WHERE o.commitment_id = c.id AND o.status IN ('pending','partial') AND o.due_date < CURDATE())";
            } elseif (in_array($filters['status'], ['active', 'paused', 'settled'], true)) {
                $sql .= ' AND c.status = :status';
                $params['status'] = $filters['status'];
            }
        }
        if (!empty($filters['category_key'])) {
            $sql .= ' AND c.category_key = :cat';
            $params['cat'] = (string) $filters['category_key'];
        }
        if (!empty($filters['payee'])) {
            $sql .= ' AND c.payee LIKE :payee';
            $params['payee'] = '%' . $filters['payee'] . '%';
        }
        $sql .= ' ORDER BY c.next_due ASC, c.id DESC';

        $rows = Database::run($sql, $params)->fetchAll();
        $today = new DateTimeImmutable('today');
        $out = [];
        foreach ($rows as $r) {
            $c = self::decorate($r, $today);
            $out[] = $c;
        }
        return $out;
    }

    /** Detalhe completo: cadastro + pagamentos + ocorrências. */
    public static function get(int $userId, int $id): ?array
    {
        $r = Database::run(
            'SELECT c.*, a.name AS account_name FROM commitments c
             LEFT JOIN accounts a ON a.id = c.account_id
             WHERE c.id = :id AND c.user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        if (!$r) {
            return null;
        }
        $c = self::decorate($r, new DateTimeImmutable('today'));
        // pagamentos (mais recentes primeiro)
        $c['payments'] = Database::run(
            'SELECT p.id, p.amount, p.paid_date, p.description, p.transaction_id, p.occurrence_id, a.name AS account_name
             FROM commitment_payments p
             LEFT JOIN accounts a ON a.id = p.account_id
             WHERE p.commitment_id = :cid AND p.user_id = :uid
             ORDER BY p.paid_date DESC, p.id DESC LIMIT 200',
            ['cid' => $id, 'uid' => $userId]
        )->fetchAll();
        foreach ($c['payments'] as &$p) {
            $p['amount'] = (float) $p['amount'];
            $p['paid_date'] = ContextBuilder::brDate((string) $p['paid_date']);
        }
        unset($p);
        // ocorrências (pendentes e próximas + últimas 10)
        $occ = Database::run(
            "SELECT id, due_date, amount, paid_amount, status, paid_at
             FROM commitment_occurrences WHERE commitment_id = :cid AND user_id = :uid
             ORDER BY due_date DESC LIMIT 200",
            ['cid' => $id, 'uid' => $userId]
        )->fetchAll();
        $future = [];
        $past = [];
        foreach ($occ as $o) {
            $o['amount'] = (float) $o['amount'];
            $o['paid_amount'] = (float) $o['paid_amount'];
            if ($o['status'] === 'pending' || $o['status'] === 'partial') {
                $future[] = $o;
            } else {
                $past[] = $o;
            }
        }
        usort($future, static fn ($a, $b) => strcmp((string) $a['due_date'], (string) $b['due_date']));
        $c['occurrences_future'] = array_slice($future, 0, 12);
        $c['occurrences_past'] = array_slice($past, 0, 12);
        return $c;
    }

    /** Enriquece uma linha com campos derivados. */
    private static function decorate(array $r, DateTimeImmutable $today): array
    {
        $id = (int) $r['id'];
        $r['id'] = $id;
        $r['total_amount'] = $r['total_amount'] !== null ? (float) $r['total_amount'] : null;
        $r['installment_amount'] = $r['installment_amount'] !== null ? (float) $r['installment_amount'] : null;
        $r['total_installments'] = $r['total_installments'] !== null ? (int) $r['total_installments'] : null;

        // totais de pagamentos (fonte da verdade)
        $paid = (float) (Database::run(
            'SELECT COALESCE(SUM(amount), 0) AS t FROM commitment_payments WHERE commitment_id = :id',
            ['id' => $id]
        )->fetch()['t'] ?? 0);
        $r['paid_total'] = round($paid, 2);
        $r['payments_count'] = (int) (Database::run(
            'SELECT COUNT(*) AS n FROM commitment_payments WHERE commitment_id = :id',
            ['id' => $id]
        )->fetch()['n'] ?? 0);

        if ($r['kind'] === 'debt' && $r['total_amount'] !== null) {
            $r['balance'] = round(max(0.0, (float) $r['total_amount'] - $paid), 2);
            $r['progress_pct'] = (float) $r['total_amount'] > 0
                ? (float) min(100, round($paid / (float) $r['total_amount'] * 100, 1)) : 0.0;
        } else {
            $r['balance'] = null;       // recorrente: sem saldo devedor
            $r['progress_pct'] = null;
        }

        // status derivado: atrasado se existe ocorrência pendente vencida
        $overdue = (int) (Database::run(
            "SELECT COUNT(*) AS n FROM commitment_occurrences
             WHERE commitment_id = :id AND status IN ('pending','partial') AND due_date < :today",
            ['id' => $id, 'today' => $today->format('Y-m-d')]
        )->fetch()['n'] ?? 0);
        $r['overdue_count'] = $overdue;
        $r['overdue_amount'] = (float) (Database::run(
            "SELECT COALESCE(SUM(amount - paid_amount), 0) AS t FROM commitment_occurrences
             WHERE commitment_id = :id AND status IN ('pending','partial') AND due_date < :today",
            ['id' => $id, 'today' => $today->format('Y-m-d')]
        )->fetch()['t'] ?? 0);

        $r['derived_status'] = $r['status'] === 'paused' ? 'paused'
            : ($r['status'] === 'settled' ? 'settled'
            : ($overdue > 0 ? 'overdue' : 'ontime'));

        // próximo vencimento = próxima ocorrência pendente (ou next_due)
        $nextOcc = Database::run(
            "SELECT due_date, amount FROM commitment_occurrences
             WHERE commitment_id = :id AND status IN ('pending','partial')
             ORDER BY due_date ASC LIMIT 1",
            ['id' => $id]
        )->fetch();
        $r['next_due_display'] = ContextBuilder::brDate((string) $r['next_due']);
        if ($nextOcc) {
            $r['next_occurrence'] = [
                'due_date' => (string) $nextOcc['due_date'],
                'br' => ContextBuilder::brDate((string) $nextOcc['due_date']),
                'amount' => (float) $nextOcc['amount'],
            ];
        } else {
            $r['next_occurrence'] = null;
        }

        $r['category_label'] = self::CATEGORY_LABELS[$r['category_key']] ?? 'Outro';
        $r['periodicity_label'] = self::PERIODICITY_LABELS[$r['periodicity']] ?? 'Mensal';
        $r['payment_method'] = $r['payment_method'] !== null ? (string) $r['payment_method'] : null;
        return $r;
    }

    /** KPIs do mês para a tela e o dashboard. */
    public static function summary(int $userId): array
    {
        $month = date('Y-m');
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t');

        // previsto do mês: ocorrências com vencimento no mês (pendentes + pagas)
        $planned = (float) (Database::run(
            "SELECT COALESCE(SUM(amount), 0) AS t FROM commitment_occurrences
             WHERE user_id = :uid AND due_date BETWEEN :a AND :b AND status IN ('pending','partial','paid')",
            ['uid' => $userId, 'a' => $monthStart, 'b' => $monthEnd]
        )->fetch()['t'] ?? 0);
        // pago no mês: pelos pagamentos efetivos
        $paid = (float) (Database::run(
            'SELECT COALESCE(SUM(amount), 0) AS t FROM commitment_payments
             WHERE user_id = :uid AND paid_date BETWEEN :a AND :b',
            ['uid' => $userId, 'a' => $monthStart, 'b' => $monthEnd]
        )->fetch()['t'] ?? 0);
        // pendente do mês: ocorrências do mês não pagas integralmente
        $pending = (float) (Database::run(
            "SELECT COALESCE(SUM(amount - paid_amount), 0) AS t FROM commitment_occurrences
             WHERE user_id = :uid AND due_date BETWEEN :a AND :b AND status IN ('pending','partial')",
            ['uid' => $userId, 'a' => $monthStart, 'b' => $monthEnd]
        )->fetch()['t'] ?? 0);
        // dívidas em aberto (kind=debt, saldo > 0)
        $debts = (float) (Database::run(
            "SELECT COALESCE(SUM(
                SELECT_BAL), 0) AS t FROM (
                SELECT (c.total_amount - COALESCE((SELECT SUM(p.amount) FROM commitment_payments p WHERE p.commitment_id = c.id), 0)) AS SELECT_BAL
                FROM commitments c
                WHERE c.user_id = :uid AND c.kind = 'debt' AND c.total_amount IS NOT NULL
                  AND (c.total_amount - COALESCE((SELECT SUM(p2.amount) FROM commitment_payments p2 WHERE p2.commitment_id = c.id), 0)) > 0
                  AND c.status <> 'settled'
            ) x",
            ['uid' => $userId]
        )->fetch()['t'] ?? 0);
        // atrasado: ocorrências vencidas não pagas
        $overdue = (float) (Database::run(
            "SELECT COALESCE(SUM(amount - paid_amount), 0) AS t FROM commitment_occurrences
             WHERE user_id = :uid AND status IN ('pending','partial') AND due_date < CURDATE()",
            ['uid' => $userId]
        )->fetch()['t'] ?? 0);
        $activeCount = (int) (Database::run(
            "SELECT COUNT(*) AS n FROM commitments WHERE user_id = :uid AND status = 'active'",
            ['uid' => $userId]
        )->fetch()['n'] ?? 0);
        $overdueCount = (int) (Database::run(
            "SELECT COUNT(DISTINCT commitment_id) AS n FROM commitment_occurrences
             WHERE user_id = :uid AND status IN ('pending','partial') AND due_date < CURDATE()",
            ['uid' => $userId]
        )->fetch()['n'] ?? 0);

        return [
            'active_count' => $activeCount,
            'month_planned' => round($planned, 2),
            'month_paid' => round($paid, 2),
            'month_pending' => round(max(0, $pending), 2),
            'debts_open' => round($debts, 2),
            'overdue_total' => round($overdue, 2),
            'overdue_count' => $overdueCount,
            'month' => $month,
        ];
    }

    /** Compromissos com vencimento nos próximos N dias (dashboard/AI). */
    public static function dueSoon(int $userId, int $days = 7): array
    {
        $rows = Database::run(
            "SELECT c.id, c.name, c.payee, c.kind, o.due_date, o.amount, o.paid_amount,
                    (o.amount - o.paid_amount) AS pending_amount
             FROM commitment_occurrences o
             JOIN commitments c ON c.id = o.commitment_id
             WHERE o.user_id = :uid AND c.status = 'active' AND o.status IN ('pending','partial')
               AND o.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :d DAY)
             ORDER BY o.due_date ASC LIMIT 20",
            ['uid' => $userId, 'd' => $days]
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['amount'] = (float) $r['amount'];
            $r['pending_amount'] = (float) $r['pending_amount'];
        }
        return $rows;
    }

    /** Compromissos pendentes do mês (previsão de fluxo de caixa). */
    public static function monthPending(int $userId, ?string $month = null): array
    {
        $month = $month ?: date('Y-m');
        $rows = Database::run(
            "SELECT c.id, c.name, c.payee, c.kind, c.category_key, o.due_date, o.amount,
                    (o.amount - o.paid_amount) AS pending_amount
             FROM commitment_occurrences o
             JOIN commitments c ON c.id = o.commitment_id
             WHERE o.user_id = :uid AND c.status = 'active' AND o.status IN ('pending','partial')
               AND o.due_date LIKE :ym
             ORDER BY o.due_date ASC LIMIT 50",
            ['uid' => $userId, 'ym' => $month . '%']
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['amount'] = (float) $r['amount'];
            $r['pending_amount'] = (float) $r['pending_amount'];
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    public static function create(int $userId, array $data): array
    {
        $name = Validator::text($data['name'] ?? '', 120);
        $payee = Validator::text($data['payee'] ?? '', 120);
        if ($name === '' || $payee === '') {
            return ['ok' => false, 'error' => 'Nome e pessoa/empresa são obrigatórios.'];
        }
        $kind = Validator::in($data['kind'] ?? 'recurring', ['debt', 'recurring']) ?? 'recurring';
        $catKey = Validator::in($data['category_key'] ?? 'outro', array_keys(self::CATEGORY_LABELS)) ?? 'outro';
        $periodicity = Validator::in($data['periodicity'] ?? 'monthly', array_keys(self::PERIODICITY_LABELS)) ?? 'monthly';

        $total = $kind === 'debt' ? Validator::amount($data['total_amount'] ?? null) : null;
        if ($kind === 'debt' && ($total === null || $total <= 0)) {
            return ['ok' => false, 'error' => 'Informe o valor total da dívida.'];
        }
        $installment = Validator::amount($data['installment_amount'] ?? null);
        if ($installment === null && $kind === 'debt') {
            return ['ok' => false, 'error' => 'Informe o valor de cada pagamento combinado.'];
        }
        $startDate = Validator::date($data['start_date'] ?? null) ?? date('Y-m-d');
        $nextDue = Validator::date($data['next_due'] ?? null) ?? $startDate;
        $endDate = Validator::date($data['end_date'] ?? null);
        $totalInstallments = $kind === 'debt' ? Validator::positiveInt($data['total_installments'] ?? null) : null;
        $customDays = $periodicity === 'custom' ? Validator::positiveInt($data['custom_days'] ?? null) : null;
        if ($periodicity === 'custom' && $customDays === null) {
            return ['ok' => false, 'error' => 'Informe o intervalo em dias da recorrência personalizada.'];
        }
        $accountId = Validator::positiveInt($data['account_id'] ?? null);
        if ($accountId !== null && Account::get($userId, $accountId) === null) {
            $accountId = null;
        }
        $categoryId = Validator::positiveInt($data['category_id'] ?? null);
        if ($categoryId !== null && Category::get($userId, $categoryId) === null) {
            $categoryId = null;
        }
        $method = Validator::in($data['payment_method'] ?? null, self::PAYMENT_METHODS);
        $description = Validator::text($data['description'] ?? '', 500);

        Database::run(
            "INSERT INTO commitments (user_id, name, payee, description, kind, category_key, total_amount,
                installment_amount, periodicity, custom_days, next_due, start_date, end_date,
                total_installments, account_id, payment_method, category_id, status)
             VALUES (:uid, :name, :payee, :descr, :kind, :cat, :total, :inst, :per, :cdays,
                :next_due, :start, :endd, :total_inst, :account, :method, :category, 'active')",
            [
                'uid' => $userId, 'name' => $name, 'payee' => $payee, 'descr' => $description ?: null,
                'kind' => $kind, 'cat' => $catKey,
                'total' => $total, 'inst' => $installment, 'per' => $periodicity, 'cdays' => $customDays,
                'next_due' => $nextDue, 'start' => $startDate, 'endd' => $endDate,
                'total_inst' => $totalInstallments, 'account' => $accountId,
                'method' => $method, 'category' => $categoryId,
            ]
        );
        $id = (int) Database::lastId();
        // primeira ocorrência
        $firstAmount = $installment ?? 0.0;
        self::ensureOccurrence($userId, $id, $nextDue, $firstAmount > 0 ? $firstAmount : 0.0);
        notify_push($userId, 'commitment_created', 'Compromisso criado', $name . ' — ' . $payee);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Edita um compromisso. $scope:
     *  - 'all': campos gerais do cadastro
     *  - 'occurrence': altera somente a próxima ocorrência (valor/data)
     *  - 'future': altera o cadastro E refaz as ocorrências futuras pendentes
     */
    public static function update(int $userId, int $id, array $data, string $scope = 'all'): array
    {
        $c = self::get($userId, $id);
        if ($c === null) {
            return ['ok' => false, 'error' => 'Compromisso não encontrado.'];
        }
        if ($scope === 'occurrence') {
            // altera apenas a próxima ocorrência pendente (valor/data/valor variável)
            $occId = Validator::positiveInt($data['occurrence_id'] ?? null);
            $occ = $occId !== null ? self::findOccurrence($userId, $id, $occId) : self::nextPendingOccurrence($id);
            if ($occ === null) {
                return ['ok' => false, 'error' => 'Não há ocorrência pendente para alterar.'];
            }
            $amount = Validator::amount($data['amount'] ?? null) ?? (float) $occ['amount'];
            $due = Validator::date($data['due_date'] ?? null) ?? (string) $occ['due_date'];
            // move a ocorrência: se a data mudou, atualiza (UNIQUE protege duplicatas)
            Database::run(
                'UPDATE commitment_occurrences SET amount = :a, due_date = :d WHERE id = :id AND user_id = :uid',
                ['a' => $amount, 'd' => $due, 'id' => (int) $occ['id'], 'uid' => $userId]
            );
            // sincroniza next_due do compromisso
            self::syncNextDue($id);
            return ['ok' => true, 'scope' => 'occurrence'];
        }

        // 'all' ou 'future': atualiza o cadastro
        $name = Validator::text($data['name'] ?? $c['name'], 120) ?: $c['name'];
        $payee = Validator::text($data['payee'] ?? $c['payee'], 120) ?: $c['payee'];
        $description = Validator::text($data['description'] ?? $c['description'] ?? '', 500);
        $kind = Validator::in($data['kind'] ?? $c['kind'], ['debt', 'recurring']) ?? $c['kind'];
        $catKey = Validator::in($data['category_key'] ?? $c['category_key'], array_keys(self::CATEGORY_LABELS)) ?? $c['category_key'];
        $periodicity = Validator::in($data['periodicity'] ?? $c['periodicity'], array_keys(self::PERIODICITY_LABELS)) ?? $c['periodicity'];
        $total = $kind === 'debt' ? (Validator::amount($data['total_amount'] ?? null) ?? $c['total_amount']) : null;
        $installment = Validator::amount($data['installment_amount'] ?? null) ?? $c['installment_amount'];
        $customDays = $periodicity === 'custom' ? (Validator::positiveInt($data['custom_days'] ?? null) ?? $c['custom_days']) : null;
        if ($periodicity === 'custom' && $customDays === null) {
            return ['ok' => false, 'error' => 'Informe o intervalo em dias da recorrência personalizada.'];
        }
        $endDate = Validator::date($data['end_date'] ?? null);
        $totalInstallments = $kind === 'debt' ? (Validator::positiveInt($data['total_installments'] ?? null) ?? $c['total_installments']) : null;
        $accountId = array_key_exists('account_id', $data)
            ? Validator::positiveInt($data['account_id'] ?? null)
            : ($c['account_id'] !== null ? (int) $c['account_id'] : null);
        if ($accountId !== null && Account::get($userId, $accountId) === null) {
            $accountId = null;
        }
        $method = Validator::in($data['payment_method'] ?? null, self::PAYMENT_METHODS) ?? $c['payment_method'];

        Database::run(
            "UPDATE commitments SET name = :name, payee = :payee, description = :descr, kind = :kind,
                category_key = :cat, total_amount = :total, installment_amount = :inst,
                periodicity = :per, custom_days = :cdays, end_date = :endd,
                total_installments = :total_inst, account_id = :account, payment_method = :method
             WHERE id = :id AND user_id = :uid",
            [
                'name' => $name, 'payee' => $payee, 'descr' => $description ?: null, 'kind' => $kind,
                'cat' => $catKey, 'total' => $total, 'inst' => $installment, 'per' => $periodicity,
                'cdays' => $customDays, 'endd' => $endDate, 'total_inst' => $totalInstallments,
                'account' => $accountId, 'method' => $method, 'id' => $id, 'uid' => $userId,
            ]
        );

        if ($scope === 'future') {
            // refaz ocorrências futuras pendentes com os novos valores/regra
            // (preserva pagas/parciais — histórico intocado)
            Database::run(
                "DELETE FROM commitment_occurrences WHERE commitment_id = :id
                 AND status IN ('pending') AND due_date > CURDATE()",
                ['id' => $id]
            );
            $occ = self::nextPendingOccurrence($id);
            $nextDue = $occ ? (string) $occ['due_date'] : (string) $c['next_due'];
            $amount = $installment ?? (float) ($occ['amount'] ?? 0);
            self::ensureOccurrence($userId, $id, $nextDue, $amount);
            self::syncNextDue($id);
        }
        return ['ok' => true, 'scope' => $scope];
    }

    public static function setStatus(int $userId, int $id, string $status): array
    {
        if (!in_array($status, ['active', 'paused', 'settled'], true)) {
            return ['ok' => false, 'error' => 'Status inválido.'];
        }
        $c = self::get($userId, $id);
        if ($c === null) {
            return ['ok' => false, 'error' => 'Compromisso não encontrado.'];
        }
        if ($status === 'settled') {
            // quitação: encerra ocorrências futuras pendentes (sem apagar histórico)
            Database::run(
                "UPDATE commitment_occurrences SET status = 'skipped'
                 WHERE commitment_id = :id AND status IN ('pending','partial') AND due_date > CURDATE()",
                ['id' => $id]
            );
        }
        Database::run(
            'UPDATE commitments SET status = :s WHERE id = :id AND user_id = :uid',
            ['s' => $status, 'id' => $id, 'uid' => $userId]
        );
        return ['ok' => true, 'status' => $status];
    }

    /** Exclui APENAS se não houver pagamentos (histórico é preservado). */
    public static function delete(int $userId, int $id): array
    {
        $c = self::get($userId, $id);
        if ($c === null) {
            return ['ok' => false, 'error' => 'Compromisso não encontrado.'];
        }
        if ((int) $c['payments_count'] > 0) {
            return ['ok' => false, 'error' => 'Este compromisso tem pagamentos registrados. Encerre-o (quitar) em vez de excluir — o histórico financeiro é preservado.'];
        }
        Database::run('DELETE FROM commitments WHERE id = :id AND user_id = :uid', ['id' => $id, 'uid' => $userId]);
        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Pagamentos
    // ------------------------------------------------------------------

    /**
     * Registra um pagamento (transação DB):
     * pagamento → ocorrência → saldo → próxima ocorrência → transação espelho → notificação.
     */
    public static function registerPayment(int $userId, int $id, array $data): array
    {
        $c = self::get($userId, $id);
        if ($c === null) {
            return ['ok' => false, 'error' => 'Compromisso não encontrado.'];
        }
        if ($c['status'] === 'settled') {
            return ['ok' => false, 'error' => 'Este compromisso já está quitado.'];
        }
        $amount = Validator::amount($data['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            return ['ok' => false, 'error' => 'Informe um valor válido.'];
        }
        // dívida: não permite pagar mais que o saldo restante
        if ($c['kind'] === 'debt' && $c['balance'] !== null && $amount > $c['balance'] + 0.009) {
            return ['ok' => false, 'error' => 'O valor excede o saldo devedor (R$ ' . number_format($c['balance'], 2, ',', '.') . '). Ajuste o valor ou quite pelo botão "Quitar dívida".'];
        }
        $paidDate = Validator::date($data['paid_date'] ?? null) ?? date('Y-m-d');
        $accountId = Validator::positiveInt($data['account_id'] ?? null) ?? ($c['account_id'] !== null ? (int) $c['account_id'] : null);
        if ($accountId !== null && Account::get($userId, $accountId) === null) {
            $accountId = null;
        }
        $description = Validator::text($data['description'] ?? '', 300);

        $conn = Database::conn();
        $conn->beginTransaction();
        try {
            // ocorrência alvo: a informada ou a pendente mais próxima
            $occ = null;
            $occId = Validator::positiveInt($data['occurrence_id'] ?? null);
            if ($occId !== null) {
                $occ = self::findOccurrence($userId, $id, $occId);
            }
            if ($occ === null) {
                $occ = self::nextPendingOccurrence($id);
            }

            $occStatus = null;
            if ($occ !== null) {
                $occAmount = (float) $occ['amount'];
                $newPaid = (float) $occ['paid_amount'] + $amount;
                $occStatus = $newPaid >= $occAmount - 0.009 ? 'paid' : 'partial';
                Database::run(
                    'UPDATE commitment_occurrences SET paid_amount = :p, status = :s, paid_at = :d
                     WHERE id = :id',
                    ['p' => round($newPaid, 2), 's' => $occStatus, 'd' => $paidDate, 'id' => (int) $occ['id']]
                );
            }

            // registra o pagamento
            Database::run(
                'INSERT INTO commitment_payments (commitment_id, occurrence_id, user_id, amount, paid_date, account_id, description)
                 VALUES (:cid, :oid, :uid, :a, :d, :acc, :descr)',
                ['cid' => $id, 'oid' => $occ !== null ? (int) $occ['id'] : null, 'uid' => $userId,
                 'a' => $amount, 'd' => $paidDate, 'acc' => $accountId, 'descr' => $description ?: null]
            );
            $paymentId = (int) Database::lastId();

            // espelha como transação expense (integração com despesas/dashboard)
            $txDescription = $c['name'] . ($occ !== null ? ' — ' . ContextBuilder::brDate((string) $occ['due_date']) : '');
            $tx = Transaction::create($userId, [
                'type' => 'expense',
                'amount' => $amount,
                'description' => $txDescription . ($description !== '' ? ' · ' . $description : ''),
                'date' => $paidDate,
                'category_id' => $c['category_id'] ?? null,
                'account_id' => $accountId,
            ]);
            $txId = ($tx['ok'] ?? false) ? (int) $tx['id'] : null;
            Database::run(
                'UPDATE commitment_payments SET transaction_id = :tx WHERE id = :pid',
                ['tx' => $txId, 'pid' => $paymentId]
            );

            // recalcula saldo da dívida
            $balance = null;
            if ($c['kind'] === 'debt' && $c['total_amount'] !== null) {
                $paidTotal = (float) (Database::run(
                    'SELECT COALESCE(SUM(amount), 0) AS t FROM commitment_payments WHERE commitment_id = :id',
                    ['id' => $id]
                )->fetch()['t'] ?? 0);
                $balance = round(max(0.0, (float) $c['total_amount'] - $paidTotal), 2);
            }

            // quitação: encerra pendências futuras
            if ($balance !== null && $balance <= 0.009) {
                Database::run(
                    "UPDATE commitment_occurrences SET status = 'skipped'
                     WHERE commitment_id = :id AND status IN ('pending','partial') AND due_date > CURDATE()",
                    ['id' => $id]
                );
                Database::run(
                    "UPDATE commitments SET status = 'settled' WHERE id = :id",
                    ['id' => $id]
                );
                notify_push($userId, 'commitment_settled', 'Dívida quitada 🎉', $c['name'] . ' — ' . $c['payee']);
            } elseif ($occ !== null && $occStatus === 'paid') {
                // gera a próxima ocorrência (recorrência contínua)
                self::generateNextAfterPaid($userId, $id, (string) $occ['due_date']);
                self::syncNextDue($id);
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            log_error('commitment pay: erro', ['msg' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Não foi possível registrar o pagamento. Nenhum dado foi alterado.'];
        }

        return [
            'ok' => true,
            'payment_id' => $paymentId,
            'balance' => $balance,
            'settled' => $balance !== null && $balance <= 0.009,
        ];
    }

    /** Desfaz um pagamento (reverte saldo, ocorrência e transação espelho). */
    public static function undoPayment(int $userId, int $id, int $paymentId): array
    {
        $payment = Database::run(
            'SELECT * FROM commitment_payments WHERE id = :pid AND commitment_id = :cid AND user_id = :uid',
            ['pid' => $paymentId, 'cid' => $id, 'uid' => $userId]
        )->fetch();
        if (!$payment) {
            return ['ok' => false, 'error' => 'Pagamento não encontrado.'];
        }
        $conn = Database::conn();
        $conn->beginTransaction();
        try {
            $amount = (float) $payment['amount'];
            // reverte a transação espelho
            if ($payment['transaction_id'] !== null) {
                Database::run(
                    'DELETE FROM transactions WHERE id = :tx AND user_id = :uid',
                    ['tx' => (int) $payment['transaction_id'], 'uid' => $userId]
                );
            }
            // reverte a ocorrência
            if ($payment['occurrence_id'] !== null) {
                $occ = Database::run(
                    'SELECT * FROM commitment_occurrences WHERE id = :oid',
                    ['oid' => (int) $payment['occurrence_id']]
                )->fetch();
                if ($occ) {
                    $newPaid = round(max(0.0, (float) $occ['paid_amount'] - $amount), 2);
                    $status = $newPaid <= 0.009 ? 'pending' : 'partial';
                    Database::run(
                        'UPDATE commitment_occurrences SET paid_amount = :p, status = :s, paid_at = NULL WHERE id = :oid',
                        ['p' => $newPaid, 's' => $status, 'oid' => (int) $occ['id']]
                    );
                }
            }
            Database::run('DELETE FROM commitment_payments WHERE id = :pid', ['pid' => $paymentId]);

            // se estava quitado, reativa
            $c = Database::run('SELECT status FROM commitments WHERE id = :id', ['id' => $id])->fetch();
            if ($c && $c['status'] === 'settled') {
                $balance = self::recalcBalance($id);
                if ($balance > 0.009) {
                    Database::run("UPDATE commitments SET status = 'active' WHERE id = :id", ['id' => $id]);
                    self::ensureOccurrencesFor($userId, $id);
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            log_error('commitment undo: erro', ['msg' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Não foi possível desfazer o pagamento.'];
        }
        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Geração de ocorrências (recorrência)
    // ------------------------------------------------------------------

    /** Garante as próximas ocorrências de todos os compromissos ativos (lazy). */
    public static function ensureOccurrences(int $userId): void
    {
        $rows = Database::run(
            "SELECT id, next_due, installment_amount FROM commitments
             WHERE user_id = :uid AND status = 'active'",
            ['uid' => $userId]
        )->fetchAll();
        foreach ($rows as $r) {
            self::ensureOccurrencesFor($userId, (int) $r['id']);
        }
    }

    /** Garante que exista a ocorrência de next_due (+1 futura para recorrentes). */
    public static function ensureOccurrencesFor(int $userId, int $id): void
    {
        $c = Database::run(
            'SELECT * FROM commitments WHERE id = :id AND user_id = :uid AND status = \'active\'',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        if (!$c) {
            return;
        }
        $amount = $c['installment_amount'] !== null ? (float) $c['installment_amount'] : 0.0;
        $due = (string) $c['next_due'];
        // até 2 ocorrências futuras
        for ($i = 0; $i < 2; $i++) {
            $created = self::ensureOccurrence($userId, $id, $due, $amount);
            if (!$created) {
                break; // fim (end_date) ou já existia até o limite
            }
            $next = self::nextDueFrom($due, (string) $c['periodicity'], $c['custom_days'] !== null ? (int) $c['custom_days'] : null);
            if ($next === null || ($c['end_date'] !== null && $next > (string) $c['end_date'])) {
                break;
            }
            $due = $next;
        }
        self::syncNextDue($id);
    }

    /** Cria a ocorrência se não existir (UNIQUE deduplica). Retorna true se criada. */
    private static function ensureOccurrence(int $userId, int $id, string $due, float $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }
        $exists = Database::run(
            'SELECT 1 FROM commitment_occurrences WHERE commitment_id = :id AND due_date = :d',
            ['id' => $id, 'd' => $due]
        )->fetch();
        if ($exists) {
            return false;
        }
        Database::run(
            "INSERT IGNORE INTO commitment_occurrences (commitment_id, user_id, due_date, amount)
             VALUES (:id, :uid, :d, :a)",
            ['id' => $id, 'uid' => $userId, 'd' => $due, 'a' => $amount]
        );
        return true;
    }

    /** Após pagar uma ocorrência: cria a próxima (respeita end_date/parcelas). */
    private static function generateNextAfterPaid(int $userId, int $id, string $paidDue): void
    {
        $c = Database::run('SELECT * FROM commitments WHERE id = :id', ['id' => $id])->fetch();
        if (!$c || $c['status'] !== 'active') {
            return;
        }
        $next = self::nextDueFrom($paidDue, (string) $c['periodicity'], $c['custom_days'] !== null ? (int) $c['custom_days'] : null);
        if ($next === null) {
            return;
        }
        if ($c['end_date'] !== null && $next > (string) $c['end_date']) {
            return;
        }
        // limite por nº de parcelas: conta quantas ocorrências não-skipped já existem
        if ($c['total_installments'] !== null) {
            $count = (int) (Database::run(
                "SELECT COUNT(*) AS n FROM commitment_occurrences WHERE commitment_id = :id AND status <> 'skipped'",
                ['id' => $id]
            )->fetch()['n'] ?? 0);
            if ($count >= (int) $c['total_installments']) {
                return;
            }
        }
        $amount = $c['installment_amount'] !== null ? (float) $c['installment_amount'] : 0.0;
        self::ensureOccurrence($userId, $id, $next, $amount);
    }

    /** Próximo vencimento a partir de uma data, pela recorrência. */
    private static function nextDueFrom(string $due, string $periodicity, ?int $customDays): ?string
    {
        try {
            $d = new DateTimeImmutable($due);
        } catch (Throwable) {
            return null;
        }
        $next = match ($periodicity) {
            'weekly' => $d->modify('+7 days'),
            'biweekly' => $d->modify('+14 days'),
            'monthly' => self::addMonthsKeepDay($d, 1),
            'quarterly' => self::addMonthsKeepDay($d, 3),
            'semiannual' => self::addMonthsKeepDay($d, 6),
            'yearly' => self::addMonthsKeepDay($d, 12),
            'custom' => $customDays !== null && $customDays > 0 ? $d->modify('+' . $customDays . ' days') : null,
            default => null,
        };
        return $next?->format('Y-m-d');
    }

    /** +N meses mantendo o dia (clamp p/ fim de mês: 31 → 28/30). */
    private static function addMonthsKeepDay(DateTimeImmutable $d, int $months): DateTimeImmutable
    {
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n') + $months;
        while ($m > 12) { $m -= 12; $y++; }
        $day = (int) $d->format('j');
        $daysIn = (int) (new DateTimeImmutable("$y-$m-01"))->format('t');
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, min($day, $daysIn)));
    }

    private static function syncNextDue(int $id): void
    {
        $next = Database::run(
            "SELECT MIN(due_date) AS d FROM commitment_occurrences
             WHERE commitment_id = :id AND status IN ('pending','partial')",
            ['id' => $id]
        )->fetch()['d'] ?? null;
        if ($next !== null) {
            Database::run('UPDATE commitments SET next_due = :d WHERE id = :id', ['d' => $next, 'id' => $id]);
        }
    }

    private static function nextPendingOccurrence(int $id): ?array
    {
        $occ = Database::run(
            "SELECT * FROM commitment_occurrences WHERE commitment_id = :id AND status IN ('pending','partial')
             ORDER BY due_date ASC LIMIT 1",
            ['id' => $id]
        )->fetch();
        return $occ ?: null;
    }

    private static function findOccurrence(int $userId, int $id, int $occId): ?array
    {
        $occ = Database::run(
            'SELECT * FROM commitment_occurrences WHERE id = :oid AND commitment_id = :cid AND user_id = :uid',
            ['oid' => $occId, 'cid' => $id, 'uid' => $userId]
        )->fetch();
        return $occ ?: null;
    }

    private static function recalcBalance(int $id): float
    {
        $c = Database::run('SELECT total_amount FROM commitments WHERE id = :id', ['id' => $id])->fetch();
        if (!$c || $c['total_amount'] === null) {
            return 0.0;
        }
        $paid = (float) (Database::run(
            'SELECT COALESCE(SUM(amount), 0) AS t FROM commitment_payments WHERE commitment_id = :id',
            ['id' => $id]
        )->fetch()['t'] ?? 0);
        return round(max(0.0, (float) $c['total_amount'] - $paid), 2);
    }

    // ------------------------------------------------------------------
    // Notificações (vencendo em ≤3 dias e atraso, 1x por dia)
    // ------------------------------------------------------------------

    public static function checkNotifications(int $userId): void
    {
        $today = date('Y-m-d');
        $already = Database::run(
            "SELECT title FROM notifications WHERE user_id = :uid
             AND type LIKE 'commitment%' AND created_at >= :today",
            ['uid' => $userId, 'today' => $today . ' 00:00:00']
        )->fetchAll(PDO::FETCH_COLUMN);
        $titles = [];
        foreach ($already as $t) {
            $titles[md5((string) $t)] = true;
        }
        $push = static function (string $type, string $title, string $msg) use ($userId, &$titles): void {
            $key = md5($title);
            if (isset($titles[$key])) {
                return;
            }
            $titles[$key] = true;
            notify_push($userId, $type, $title, $msg);
        };

        // atrasados
        $overdue = Database::run(
            "SELECT c.name, c.payee, MIN(o.due_date) AS due, SUM(o.amount - o.paid_amount) AS total
             FROM commitment_occurrences o JOIN commitments c ON c.id = o.commitment_id
             WHERE o.user_id = :uid AND c.status = 'active' AND o.status IN ('pending','partial') AND o.due_date < CURDATE()
             GROUP BY c.id LIMIT 10",
            ['uid' => $userId]
        )->fetchAll();
        foreach ($overdue as $o) {
            $push('commitment_overdue', 'Compromisso atrasado: ' . $o['name'],
                $o['payee'] . ' — vencia em ' . ContextBuilder::brDate((string) $o['due'])
                . ' · ' . money((float) $o['total']) . ' em aberto.');
        }
        // vencendo em ≤3 dias
        $soon = Database::run(
            "SELECT c.name, c.payee, o.due_date, (o.amount - o.paid_amount) AS pending
             FROM commitment_occurrences o JOIN commitments c ON c.id = o.commitment_id
             WHERE o.user_id = :uid AND c.status = 'active' AND o.status IN ('pending','partial')
               AND o.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
             ORDER BY o.due_date LIMIT 10",
            ['uid' => $userId]
        )->fetchAll();
        foreach ($soon as $o) {
            $push('commitment_due_soon', 'Vence em breve: ' . $o['name'],
                $o['payee'] . ' — ' . ContextBuilder::brDate((string) $o['due_date'])
                . ' · ' . money((float) $o['pending']) . '.');
        }
    }
}
