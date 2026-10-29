<?php
/**
 * FinLife — Model de cartões (crédito, débito, benefício e múltiplo).
 *
 * Regras centrais:
 * - Apenas últimos 4 dígitos (last4). Número completo/CVV/senha NUNCA são aceitos.
 * - Cartão adicional (parent_card_id) herda limite e fatura do cartão principal (raiz).
 * - Limite usado = soma das parcelas ainda não quitas (pagar a fatura libera limite).
 * - Ciclo de fatura: fechamento (closing_day) → vencimento (due_day), não mês calendário.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Card
{
    public const TYPES = ['credit', 'debit', 'benefit', 'multiple'];
    public const STATUS = ['active', 'blocked', 'archived'];
    public const BRANDS = ['visa', 'mastercard', 'elo', 'amex', 'hipercard', 'diners', 'outros'];
    public const BENEFIT_KINDS = ['meal', 'food', 'fuel', 'mobility', 'home_office', 'transport', 'award', 'other'];

    public const BENEFIT_LABELS = [
        'meal'        => 'Vale-refeição (VR)',
        'food'        => 'Vale-alimentação (VA)',
        'fuel'        => 'Combustível',
        'mobility'    => 'Mobilidade',
        'home_office' => 'Home Office',
        'transport'   => 'Vale-transporte',
        'award'       => 'Premiação',
        'other'       => 'Outro benefício',
    ];

    /** Categoria sugerida por natureza do benefício (respeita a natureza — item 18). */
    public const BENEFIT_CATEGORY_HINT = [
        'meal' => 'Alimentação', 'food' => 'Alimentação', 'fuel' => 'Transporte',
        'mobility' => 'Transporte', 'home_office' => 'Compras', 'transport' => 'Transporte',
        'award' => 'Lazer', 'other' => 'Outras',
    ];

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    public static function create(int $userId, array $d): array
    {
        $name = Validator::text($d['name'] ?? '', 80);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Informe o nome do cartão.'];
        }
        $type = Validator::in($d['type'] ?? '', self::TYPES);
        if ($type === null) {
            return ['ok' => false, 'error' => 'Tipo de cartão inválido.'];
        }
        $last4 = self::last4($d['last4'] ?? null);
        if ($last4 === false) {
            return ['ok' => false, 'error' => 'Os últimos 4 dígitos devem conter exatamente 4 números.'];
        }
        $limit = Validator::amount($d['limit_total'] ?? 0);
        if ($limit === null) {
            $limit = 0.0;
        }
        $closingDay = self::day($d['closing_day'] ?? null);
        $dueDay = self::day($d['due_day'] ?? null);

        // Cartão adicional: precisa de um principal válido do próprio usuário
        $parentId = null;
        if (!empty($d['parent_card_id'])) {
            $parent = self::get($userId, (int) $d['parent_card_id']);
            if ($parent === null) {
                return ['ok' => false, 'error' => 'Cartão principal inválido.'];
            }
            if ($parent['parent_card_id'] !== null) {
                return ['ok' => false, 'error' => 'Um cartão adicional não pode ter outro adicional como principal.'];
            }
            if ($parent['type'] !== 'credit' && $parent['type'] !== 'multiple') {
                return ['ok' => false, 'error' => 'Cartões adicionais são permitidos apenas em cartões de crédito.'];
            }
            $parentId = (int) $parent['id'];
        }

        // Conta associada precisa pertencer ao usuário
        $accountId = null;
        if (!empty($d['account_id'])) {
            $accountId = Validator::positiveInt($d['account_id']);
            if ($accountId === null || Account::get($userId, $accountId) === null) {
                return ['ok' => false, 'error' => 'Conta associada inválida.'];
            }
        }

        $isCreditLike = in_array($type, ['credit', 'multiple'], true);
        if ($isCreditLike && $parentId === null && ($closingDay === null || $dueDay === null)) {
            return ['ok' => false, 'error' => 'Cartões de crédito precisam do dia de fechamento e de vencimento.'];
        }
        // Adicional herda o ciclo do cartão principal (fatura/limite são do principal)
        if ($parentId !== null && $isCreditLike && ($closingDay === null || $dueDay === null)) {
            $parent = self::get($userId, $parentId);
            $closingDay = $closingDay ?? (int) ($parent['closing_day'] ?? 1);
            $dueDay = $dueDay ?? (int) ($parent['due_day'] ?? 10);
        }

        Database::run(
            'INSERT INTO cards (user_id, name, nickname, institution, brand, type, last4, color, limit_total,
                                closing_day, due_day, annual_fee, account_id, parent_card_id, status, limit_alert_pct, notes)
             VALUES (:uid, :name, :nick, :inst, :brand, :type, :last4, :color, :limit_t,
                     :closing, :due, :fee, :acc, :parent, :status, :alert_pct, :notes)',
            [
                'uid'       => $userId,
                'name'      => $name,
                'nick'      => Validator::text($d['nickname'] ?? '', 40) ?: null,
                'inst'      => Validator::text($d['institution'] ?? '', 80) ?: null,
                'brand'     => self::brand($d['brand'] ?? null),
                'type'      => $type,
                'last4'     => $last4,
                'color'     => Validator::color($d['color'] ?? null),
                'limit_t'   => $limit,
                'closing'   => $isCreditLike ? ($closingDay ?? 1) : $closingDay,
                'due'       => $isCreditLike ? ($dueDay ?? 10) : $dueDay,
                'fee'       => Validator::amount($d['annual_fee'] ?? 0) ?? 0.0,
                'acc'       => $accountId,
                'parent'    => $parentId,
                'status'    => Validator::in($d['status'] ?? 'active', self::STATUS) ?? 'active',
                'alert_pct' => self::alertPct($d['limit_alert_pct'] ?? 80),
                'notes'     => Validator::text($d['notes'] ?? '', 500) ?: null,
            ]
        );
        $id = Database::lastId();

        // Cartão de benefício: cria o saldo do benefício escolhido
        if ($type === 'benefit' || $type === 'multiple') {
            $kinds = $d['benefit_kinds'] ?? [];
            if (is_string($kinds)) {
                $kinds = [$kinds];
            }
            foreach ($kinds as $k) {
                $kind = Validator::in($k, self::BENEFIT_KINDS);
                if ($kind !== null) {
                    self::addBalance($userId, $id, [
                        'benefit_kind'  => $kind,
                        'monthly_credit' => $d['monthly_credit'] ?? null,
                        'credit_day'    => $d['credit_day'] ?? null,
                    ]);
                }
            }
        }

        return ['ok' => true, 'id' => $id];
    }

    public static function update(int $userId, int $id, array $d): bool
    {
        $card = self::get($userId, $id);
        if ($card === null) {
            return false;
        }
        $last4 = self::last4($d['last4'] ?? $card['last4']);
        if ($last4 === false) {
            return false; // validação falhou — tratada pela API com mensagem própria
        }
        Database::run(
            'UPDATE cards
             SET name = :name, nickname = :nick, institution = :inst, brand = :brand, type = :type,
                 last4 = :last4, color = :color, limit_total = :limit_t, closing_day = :closing, due_day = :due,
                 annual_fee = :fee, account_id = :acc, status = :status, limit_alert_pct = :alert_pct, notes = :notes
             WHERE id = :id AND user_id = :uid',
            [
                'id'        => $id,
                'uid'       => $userId,
                'name'      => Validator::text($d['name'] ?? $card['name'], 80),
                'nick'      => Validator::text($d['nickname'] ?? '', 40) ?: null,
                'inst'      => Validator::text($d['institution'] ?? '', 80) ?: null,
                'brand'     => self::brand($d['brand'] ?? $card['brand']),
                'type'      => Validator::in($d['type'] ?? $card['type'], self::TYPES) ?? $card['type'],
                'last4'     => $last4,
                'color'     => Validator::color($d['color'] ?? $card['color']),
                'limit_t'   => Validator::amount($d['limit_total'] ?? $card['limit_total']) ?? (float) $card['limit_total'],
                'closing'   => self::day($d['closing_day'] ?? $card['closing_day']),
                'due'       => self::day($d['due_day'] ?? $card['due_day']),
                'fee'       => Validator::amount($d['annual_fee'] ?? $card['annual_fee']) ?? 0.0,
                'acc'       => empty($d['account_id']) ? null : (Validator::positiveInt($d['account_id']) ?: $card['account_id']),
                'status'    => Validator::in($d['status'] ?? $card['status'], self::STATUS) ?? $card['status'],
                'alert_pct' => self::alertPct($d['limit_alert_pct'] ?? $card['limit_alert_pct']),
                'notes'     => Validator::text($d['notes'] ?? '', 500) ?: null,
            ]
        );
        return true;
    }

    public static function delete(int $userId, int $id): bool
    {
        return Database::run(
            'DELETE FROM cards WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function setStatus(int $userId, int $id, string $status): bool
    {
        $status = Validator::in($status, self::STATUS);
        if ($status === null) {
            return false;
        }
        return Database::run(
            'UPDATE cards SET status = :st WHERE id = :id AND user_id = :uid',
            ['st' => $status, 'id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function get(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM cards WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        return $row ?: null;
    }

    /** Cartão pelo id — garantindo a raiz (adicionais resolvem para o principal). */
    public static function rootOf(int $userId, int $cardId): ?array
    {
        $card = self::get($userId, $cardId);
        if ($card === null) {
            return null;
        }
        if ($card['parent_card_id'] !== null) {
            $parent = self::get($userId, (int) $card['parent_card_id']);
            return $parent ?? $card;
        }
        return $card;
    }

    /** Busca cartão por nome/apelido (usado pela IA). Case/acento-insensível, incluindo raiz. */
    public static function matchByName(int $userId, string $query): ?array
    {
        $q = normalize($query);
        if ($q === '') {
            return null;
        }
        $cards = Database::run(
            "SELECT * FROM cards WHERE user_id = :uid AND status != 'archived' ORDER BY created_at, id",
            ['uid' => $userId]
        )->fetchAll();
        // 1) match exato de nome/apelido
        foreach ($cards as $c) {
            if (normalize($c['name']) === $q || normalize((string) $c['nickname']) === $q) {
                return $c;
            }
        }
        // 2) contains
        foreach ($cards as $c) {
            if (str_contains(normalize($c['name']), $q) || ($c['nickname'] !== null && str_contains(normalize($c['nickname']), $q))) {
                return $c;
            }
        }
        return null;
    }

    /** Nomes/apelidos de cartões ativos (leve — usado pelo NLU local). */
    public static function activeNames(int $userId): array
    {
        return Database::run(
            "SELECT id, name, nickname, type FROM cards
             WHERE user_id = :uid AND status = 'active'
             ORDER BY created_at, id",
            ['uid' => $userId]
        )->fetchAll();
    }

    // ------------------------------------------------------------------
    // Ciclo de fatura (item 10 — não usa mês calendário)
    // ------------------------------------------------------------------

    /** Data de fechamento do ciclo que contém $date. */
    public static function closingDateFor(string $date, int $closingDay): string
    {
        $closingDay = max(1, min(28, $closingDay));
        $t = strtotime($date);
        if ((int) date('j', $t) <= $closingDay) {
            return date('Y-m-', $t) . sprintf('%02d', $closingDay);
        }
        $next = strtotime(date('Y-m-01', $t) . ' +1 month');
        return date('Y-m-', $next) . sprintf('%02d', $closingDay);
    }

    /** Data de vencimento de uma fatura fechada em $closingDate. */
    public static function dueDateForCycle(string $closingDate, int $dueDay): string
    {
        $dueDay = max(1, min(28, $dueDay));
        $ct = strtotime($closingDate);
        if ($dueDay > (int) date('j', $ct)) {
            return date('Y-m-', $ct) . sprintf('%02d', $dueDay);
        }
        $next = strtotime(date('Y-m-01', $ct) . ' +1 month');
        return date('Y-m-', $next) . sprintf('%02d', $dueDay);
    }

    /** Chave de ciclo (data de fechamento) para uma data de compra. */
    public static function cycleKeyFor(string $purchaseDate, array $rootCard): string
    {
        return self::closingDateFor($purchaseDate, (int) ($rootCard['closing_day'] ?? 1));
    }

    // ------------------------------------------------------------------
    // Limites (itens 5 e 21)
    // ------------------------------------------------------------------

    /** Uso de limite por cartão raiz: {card_id: used} — parcelas não quitas. */
    public static function limitUsageByCard(int $userId): array
    {
        $rows = Database::run(
            "SELECT card_id, SUM(amount - paid_amount) AS used
             FROM card_installments
             WHERE user_id = :uid AND status = 'pending'
             GROUP BY card_id",
            ['uid' => $userId]
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['card_id']] = (float) $r['used'];
        }
        return $out;
    }

    /** Lista completa com dados computados (limites, fatura atual, adicionais, benefícios). */
    public static function list(int $userId): array
    {
        $cards = Database::run(
            'SELECT * FROM cards WHERE user_id = :uid ORDER BY created_at, id',
            ['uid' => $userId]
        )->fetchAll();
        if ($cards === []) {
            return [];
        }

        $usage = self::limitUsageByCard($userId);
        $invoiceData = CardInvoice::openSummaryByCard($userId);
        $balances = self::balancesByCard($userId);

        $byParent = [];
        foreach ($cards as $c) {
            if ($c['parent_card_id'] !== null) {
                $byParent[(int) $c['parent_card_id']][] = self::hydrate($c, $usage, $invoiceData, [], $userId);
            }
        }

        $out = [];
        foreach ($cards as $c) {
            if ($c['parent_card_id'] !== null) {
                continue; // adicionais são anexados ao principal
            }
            $out[] = self::hydrate($c, $usage, $invoiceData, $byParent[(int) $c['id']] ?? [], $userId, $balances[(int) $c['id']] ?? []);
        }
        return $out;
    }

    /** Enriquece um cartão com limites, fatura atual, status e valores de exibição. */
    private static function hydrate(array $c, array $usage, array $invoiceData, array $additional, int $userId, array $balances = []): array
    {
        $isCreditLike = in_array($c['type'], ['credit', 'multiple'], true);
        $limit = (float) $c['limit_total'];
        $used = $isCreditLike ? (float) ($usage[(int) $c['id']] ?? 0) : 0.0;
        $available = max(0.0, $limit - $used);
        $pct = ($limit > 0) ? round(($used / $limit) * 100, 1) : 0.0;

        $inv = $invoiceData[(int) $c['id']] ?? null;
        $today = date('Y-m-d');

        $card = [
            'id'            => (int) $c['id'],
            'name'          => $c['name'],
            'nickname'      => $c['nickname'],
            'institution'   => $c['institution'],
            'brand'         => $c['brand'],
            'type'          => $c['type'],
            'type_label'    => self::typeLabel((string) $c['type']),
            'last4'         => $c['last4'],
            'color'         => $c['color'] ?: '#4F46E5',
            'limit_total'   => $limit,
            'closing_day'   => $c['closing_day'] !== null ? (int) $c['closing_day'] : null,
            'due_day'       => $c['due_day'] !== null ? (int) $c['due_day'] : null,
            'annual_fee'    => $c['annual_fee'] !== null ? (float) $c['annual_fee'] : null,
            'account_id'    => $c['account_id'] !== null ? (int) $c['account_id'] : null,
            'status'        => $c['status'],
            'limit_alert_pct' => (int) $c['limit_alert_pct'],
            'notes'         => $c['notes'],
            'is_credit_like'  => $isCreditLike,
            'limit_used'      => round($used, 2),
            'limit_available' => round($available, 2),
            'limit_used_pct'  => $pct,
            'current_invoice' => ($inv['current'] ?? null) !== null ? [
                'total'     => (float) $inv['current']['open_total'],
                'due_date'  => $inv['current']['due_date'],
                'cycle_key' => $inv['current']['cycle_key'],
            ] : null,
            'next_due' => ($inv['next_due'] ?? null) !== null ? [
                'total'     => (float) $inv['next_due']['open_total'],
                'due_date'  => $inv['next_due']['due_date'],
                'cycle_key' => $inv['next_due']['cycle_key'],
            ] : null,
            'additional' => $additional,
        ];

        if ($c['type'] === 'benefit' || $c['type'] === 'multiple') {
            $card['balances'] = $balances;
        }
        return $card;
    }

    public static function typeLabel(string $type): string
    {
        return [
            'credit'  => 'Crédito',
            'debit'   => 'Débito',
            'benefit' => 'Benefício',
            'multiple' => 'Crédito + Débito',
        ][$type] ?? $type;
    }

    // ------------------------------------------------------------------
    // Benefícios: saldos independentes (itens 15-19)
    // ------------------------------------------------------------------

    /** Cria/valida um saldo de benefício. */
    public static function addBalance(int $userId, int $cardId, array $d): array
    {
        $card = self::get($userId, $cardId);
        if ($card === null || !in_array($card['type'], ['benefit', 'multiple'], true)) {
            return ['ok' => false, 'error' => 'Saldos de benefício são permitidos apenas em cartões de benefício.'];
        }
        $kind = Validator::in($d['benefit_kind'] ?? '', self::BENEFIT_KINDS);
        if ($kind === null) {
            return ['ok' => false, 'error' => 'Tipo de benefício inválido.'];
        }
        $exists = Database::run(
            'SELECT id FROM card_balances WHERE card_id = :cid AND benefit_kind = :k LIMIT 1',
            ['cid' => $cardId, 'k' => $kind]
        )->fetch();
        if ($exists) {
            return ['ok' => false, 'error' => 'Este cartão já possui um saldo de ' . self::BENEFIT_LABELS[$kind] . '.'];
        }
        Database::run(
            'INSERT INTO card_balances (card_id, benefit_kind, monthly_credit, credit_day)
             VALUES (:cid, :k, :mc, :cd)',
            [
                'cid' => $cardId,
                'k'   => $kind,
                'mc'  => Validator::amount($d['monthly_credit'] ?? 0) ?? null,
                'cd'  => self::day($d['credit_day'] ?? null),
            ]
        );
        return ['ok' => true, 'id' => Database::lastId()];
    }

    public static function updateBalance(int $userId, int $balanceId, array $d): bool
    {
        $b = self::balance($userId, $balanceId);
        if ($b === null) {
            return false;
        }
        Database::run(
            'UPDATE card_balances SET monthly_credit = :mc, credit_day = :cd WHERE id = :id',
            [
                'id' => $balanceId,
                'mc' => Validator::amount($d['monthly_credit'] ?? 0) ?? null,
                'cd' => self::day($d['credit_day'] ?? null),
            ]
        );
        return true;
    }

    public static function removeBalance(int $userId, int $balanceId): bool
    {
        return Database::run(
            'DELETE cb FROM card_balances cb
             JOIN cards c ON c.id = cb.card_id
             WHERE cb.id = :id AND c.user_id = :uid',
            ['id' => $balanceId, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function balance(int $userId, int $balanceId): ?array
    {
        return Database::run(
            'SELECT cb.*, c.user_id AS card_user, c.name AS card_name
             FROM card_balances cb JOIN cards c ON c.id = cb.card_id
             WHERE cb.id = :id AND c.user_id = :uid LIMIT 1',
            ['id' => $balanceId, 'uid' => $userId]
        )->fetch() ?: null;
    }

    /** Saldos computados por cartão: {card_id: [{kind, label, balance, ...}]}. */
    public static function balancesByCard(int $userId): array
    {
        $rows = Database::run(
            'SELECT cb.*, c.id AS cid
             FROM card_balances cb JOIN cards c ON c.id = cb.card_id
             WHERE c.user_id = :uid
             ORDER BY cb.id',
            ['uid' => $userId]
        )->fetchAll();
        if ($rows === []) {
            return [];
        }
        $credits = Database::run(
            'SELECT balance_id, SUM(amount) AS total FROM card_benefit_credits
             WHERE user_id = :uid GROUP BY balance_id',
            ['uid' => $userId]
        )->fetchAll();
        $creditBy = [];
        foreach ($credits as $cr) {
            $creditBy[(int) $cr['balance_id']] = (float) $cr['total'];
        }
        $spends = Database::run(
            'SELECT balance_id, SUM(amount) AS total FROM card_purchases
             WHERE user_id = :uid AND method = \'benefit\' AND balance_id IS NOT NULL
             GROUP BY balance_id',
            ['uid' => $userId]
        )->fetchAll();
        $spendBy = [];
        foreach ($spends as $sp) {
            $spendBy[(int) $sp['balance_id']] = (float) $sp['total'];
        }

        $out = [];
        foreach ($rows as $b) {
            $balance = (float) ($creditBy[(int) $b['id']] ?? 0) - (float) ($spendBy[(int) $b['id']] ?? 0);
            $nextCredit = null;
            if ($b['monthly_credit'] !== null && (float) $b['monthly_credit'] > 0 && $b['credit_day'] !== null) {
                $day = (int) $b['credit_day'];
                $nextCredit = date('Y-m-', strtotime(date('Y-m-01'))) . sprintf('%02d', $day);
                if ($nextCredit < date('Y-m-d')) {
                    $nextCredit = date('Y-m-d', strtotime($nextCredit . ' +1 month'));
                }
            }
            $out[(int) $b['cid']][] = [
                'id'             => (int) $b['id'],
                'benefit_kind'   => $b['benefit_kind'],
                'label'          => self::BENEFIT_LABELS[$b['benefit_kind']] ?? $b['benefit_kind'],
                'balance'        => round($balance, 2),
                'total_credits'  => round((float) ($creditBy[(int) $b['id']] ?? 0), 2),
                'total_spent'    => round((float) ($spendBy[(int) $b['id']] ?? 0), 2),
                'monthly_credit' => $b['monthly_credit'] !== null ? (float) $b['monthly_credit'] : null,
                'credit_day'     => $b['credit_day'] !== null ? (int) $b['credit_day'] : null,
                'next_credit'    => $nextCredit,
            ];
        }
        return $out;
    }

    /** Registra um crédito recebido no benefício (histórico). */
    public static function addCredit(int $userId, int $balanceId, array $d): array
    {
        $b = self::balance($userId, $balanceId);
        if ($b === null) {
            return ['ok' => false, 'error' => 'Saldo de benefício não encontrado.'];
        }
        $amount = Validator::amount($d['amount'] ?? null);
        if ($amount === null) {
            return ['ok' => false, 'error' => 'Informe um valor de crédito válido.'];
        }
        $date = Validator::date($d['date'] ?? null) ?? date('Y-m-d');
        Database::run(
            'INSERT INTO card_benefit_credits (balance_id, user_id, amount, date, periodicity, note)
             VALUES (:bid, :uid, :amount, :date, :per, :note)',
            [
                'bid'    => $balanceId,
                'uid'    => $userId,
                'amount' => $amount,
                'date'   => $date,
                'per'    => Validator::in($d['periodicity'] ?? 'monthly', ['monthly', 'weekly', 'one_time']) ?? 'monthly',
                'note'   => Validator::text($d['note'] ?? '', 255) ?: null,
            ]
        );
        return ['ok' => true, 'id' => Database::lastId()];
    }

    public static function credits(int $userId, int $balanceId): array
    {
        return Database::run(
            'SELECT * FROM card_benefit_credits WHERE balance_id = :bid AND user_id = :uid ORDER BY date DESC, id DESC LIMIT 50',
            ['bid' => $balanceId, 'uid' => $userId]
        )->fetchAll();
    }

    // ------------------------------------------------------------------
    // Visão consolidada + alertas (itens 12 e 21)
    // ------------------------------------------------------------------

    public static function overview(int $userId): array
    {
        $cards = self::list($userId);
        $totalLimit = 0.0;
        $totalUsed = 0.0;
        $openInvoices = 0.0;
        $benefitsBalance = 0.0;
        $mostUsed = null;
        $nextDue = null;
        $creditCards = [];

        foreach ($cards as $c) {
            if ($c['is_credit_like'] && $c['status'] !== 'archived') {
                $creditCards[] = $c;
                $totalLimit += (float) $c['limit_total'];
                $totalUsed += (float) $c['limit_used'];
                if ($mostUsed === null || $c['limit_used_pct'] > $mostUsed['limit_used_pct']) {
                    $mostUsed = $c;
                }
                if ($c['next_due'] !== null && $c['next_due']['total'] > 0) {
                    if ($nextDue === null || $c['next_due']['due_date'] < $nextDue['due_date']) {
                        $nextDue = $c['next_due'] + ['card_name' => $c['name']];
                    }
                }
            }
            if (!empty($c['balances'])) {
                foreach ($c['balances'] as $b) {
                    $benefitsBalance += (float) $b['balance'];
                }
            }
        }

        // Faturas abertas (não quitas) e parcelas futuras
        $open = Database::run(
            "SELECT COALESCE(SUM(i.open_total), 0) AS total
             FROM (" . CardInvoice::OPEN_SUM_SQL . ") i",
            ['uid' => $userId]
        )->fetch();
        $openInvoices = (float) ($open['total'] ?? 0);

        $futureRows = Database::run(
            "SELECT CONCAT(YEAR(due_date), '-', LPAD(MONTH(due_date), 2, '0')) AS ym, SUM(amount - paid_amount) AS total
             FROM card_installments
             WHERE user_id = :uid AND status = 'pending' AND due_date >= :today
             GROUP BY ym ORDER BY ym",
            ['uid' => $userId, 'today' => date('Y-m-d')]
        )->fetchAll();
        $futureByMonth = [];
        $futureTotal = 0.0;
        foreach ($futureRows as $r) {
            $futureByMonth[$r['ym']] = (float) $r['total'];
            $futureTotal += (float) $r['total'];
        }

        $subsMonthly = CardRecurring::monthlyTotal($userId);

        return [
            'cards'                => $cards,
            'total_limit'          => round($totalLimit, 2),
            'total_used'           => round($totalUsed, 2),
            'total_available'      => round(max(0, $totalLimit - $totalUsed), 2),
            'open_invoices_total'  => round($openInvoices, 2),
            'future_installments'  => $futureByMonth,
            'future_installments_total' => round($futureTotal, 2),
            'subscriptions_monthly'=> $subsMonthly,
            'benefits_balance'     => round($benefitsBalance, 2),
            'next_due'             => $nextDue,
            'most_used_card'       => $mostUsed !== null ? [
                'name' => $mostUsed['name'], 'pct' => $mostUsed['limit_used_pct'], 'color' => $mostUsed['color'],
            ] : null,
            'alerts'               => self::alerts($userId, $cards),
        ];
    }

    /** Alertas inteligentes (item 12) — computados, nunca persistidos. */
    public static function alerts(int $userId, array $cards): array
    {
        $alerts = [];
        $today = date('Y-m-d');

        foreach ($cards as $c) {
            if (!$c['is_credit_like'] || $c['status'] === 'archived') {
                continue;
            }

            // Limite próximo do máximo (alerta configurável por cartão)
            $pct = (float) $c['limit_used_pct'];
            $threshold = (int) $c['limit_alert_pct'];
            if ($c['limit_total'] > 0 && $pct >= 100) {
                $alerts[] = ['level' => 'danger', 'type' => 'limit', 'card' => $c['name'],
                    'message' => 'O cartão ' . $c['name'] . ' está com o limite 100% comprometido (' . money($c['limit_used']) . ' de ' . money($c['limit_total']) . ').'];
            } elseif ($c['limit_total'] > 0 && $pct >= $threshold) {
                $alerts[] = ['level' => 'warn', 'type' => 'limit', 'card' => $c['name'],
                    'message' => 'O cartão ' . $c['name'] . ' está utilizando ' . number_format($pct, 1, ',', '.') . '% do limite (' . money($c['limit_used']) . ' de ' . money($c['limit_total']) . ').'];
            }

            // Fatura: vencendo / hoje / atrasada
            $inv = $c['next_due'] ?? null;
            if ($inv !== null && $inv['total'] > 0) {
                $days = (int) floor((strtotime($inv['due_date']) - strtotime($today)) / 86400);
                if ($days < 0) {
                    $alerts[] = ['level' => 'danger', 'type' => 'invoice_due', 'card' => $c['name'],
                        'message' => 'A fatura do ' . $c['name'] . ' está atrasada — vencia em ' . ContextBuilder::brDate($inv['due_date']) . ' (' . money($inv['total']) . ' pendentes).'];
                } elseif ($days === 0) {
                    $alerts[] = ['level' => 'danger', 'type' => 'invoice_due', 'card' => $c['name'],
                        'message' => 'A fatura do ' . $c['name'] . ' vence HOJE (' . money($inv['total']) . ').'];
                } elseif ($days <= 7) {
                    $alerts[] = ['level' => 'warn', 'type' => 'invoice_due', 'card' => $c['name'],
                        'message' => 'A fatura do ' . $c['name'] . ' vence em ' . $days . ' dia(s) — ' . money($inv['total']) . '.'];
                }
            }
        }

        // Fatura muito acima da média (compara com as 3 últimas faturas fechadas)
        foreach ($cards as $c) {
            if (!$c['is_credit_like'] || $c['status'] === 'archived' || $c['current_invoice'] === null) {
                continue;
            }
            $avg = CardInvoice::closedAverage($userId, (int) $c['id'], 3);
            $current = (float) $c['current_invoice']['total'];
            if ($avg > 0 && $current > $avg * 1.2) {
                $alerts[] = ['level' => 'warn', 'type' => 'invoice_avg', 'card' => $c['name'],
                    'message' => 'A fatura atual do ' . $c['name'] . ' (' . money($current) . ') está ' . number_format((($current / $avg) - 1) * 100, 0, ',', '.') . '% acima da média das últimas faturas (' . money($avg) . ').'];
            }
        }

        return $alerts;
    }

    // ------------------------------------------------------------------
    // Calendário financeiro (item 22)
    // ------------------------------------------------------------------

    /** Eventos de cartões de um mês: fechamentos, vencimentos, parcelas, compras, créditos e assinaturas. */
    public static function calendarEvents(int $userId, string $month): array
    {
        if (!preg_match('~^\d{4}-\d{2}$~', $month)) {
            $month = date('Y-m');
        }
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        $events = [];

        // Fechamentos de todos os cartões de crédito ativos (apenas cartões principais)
        $cards = Database::run(
            "SELECT * FROM cards
             WHERE user_id = :uid AND status = 'active' AND closing_day IS NOT NULL AND parent_card_id IS NULL",
            ['uid' => $userId]
        )->fetchAll();
        foreach ($cards as $c) {
            $closingDate = date('Y-m-', strtotime($start)) . sprintf('%02d', (int) $c['closing_day']);
            $events[] = [
                'date' => $closingDate, 'type' => 'closing',
                'title' => 'Fechamento — ' . $c['name'],
                'amount' => null, 'card' => $c['name'], 'card_color' => $c['color'],
            ];
        }

        // Vencimentos de faturas existentes
        $invoices = Database::run(
            'SELECT i.due_date, c.name AS card, c.color FROM card_invoices i
             JOIN cards c ON c.id = i.card_id
             WHERE i.user_id = :uid AND i.due_date BETWEEN :s AND :e',
            ['uid' => $userId, 's' => $start, 'e' => $end]
        )->fetchAll();
        foreach ($invoices as $i) {
            $events[] = [
                'date' => $i['due_date'], 'type' => 'due',
                'title' => 'Vencimento da fatura — ' . $i['card'],
                'amount' => null, 'card' => $i['card'], 'card_color' => $i['color'],
            ];
        }

        // Parcelas previstas
        $installments = Database::run(
            'SELECT ins.due_date, ins.amount, ins.number, ins.total, c.name AS card, c.color, p.description
             FROM card_installments ins
             JOIN card_purchases p ON p.id = ins.purchase_id
             JOIN cards c ON c.id = p.card_id
             WHERE ins.user_id = :uid AND ins.status = \'pending\' AND ins.due_date BETWEEN :s AND :e',
            ['uid' => $userId, 's' => $start, 'e' => $end]
        )->fetchAll();
        foreach ($installments as $i) {
            $events[] = [
                'date' => $i['due_date'], 'type' => 'installment',
                'title' => $i['description'] . ' (parcela ' . $i['number'] . '/' . $i['total'] . ')',
                'amount' => (float) $i['amount'], 'card' => $i['card'], 'card_color' => $i['color'],
            ];
        }

        // Compras realizadas no mês
        $purchases = Database::run(
            'SELECT p.date, p.amount, p.description, p.method, c.name AS card, c.color
             FROM card_purchases p JOIN cards c ON c.id = p.card_id
             WHERE p.user_id = :uid AND p.date BETWEEN :s AND :e',
            ['uid' => $userId, 's' => $start, 'e' => $end]
        )->fetchAll();
        foreach ($purchases as $p) {
            $events[] = [
                'date' => $p['date'], 'type' => 'purchase',
                'title' => $p['description'] . ' (' . self::methodLabel($p['method']) . ')',
                'amount' => (float) $p['amount'], 'card' => $p['card'], 'card_color' => $p['color'],
            ];
        }

        // Créditos de benefício
        $credits = Database::run(
            'SELECT bc.date, bc.amount, c.name AS card, c.color, cb.benefit_kind
             FROM card_benefit_credits bc
             JOIN card_balances cb ON cb.id = bc.balance_id
             JOIN cards c ON c.id = cb.card_id
             WHERE bc.user_id = :uid AND bc.date BETWEEN :s AND :e',
            ['uid' => $userId, 's' => $start, 'e' => $end]
        )->fetchAll();
        foreach ($credits as $cr) {
            $events[] = [
                'date' => $cr['date'], 'type' => 'credit',
                'title' => 'Crédito ' . (self::BENEFIT_LABELS[$cr['benefit_kind']] ?? 'benefício') . ' — ' . $cr['card'],
                'amount' => (float) $cr['amount'], 'card' => $cr['card'], 'card_color' => $cr['color'],
            ];
        }

        // Assinaturas ativas cobradas no mês
        foreach (CardRecurring::activeForMonth($userId, $month) as $sub) {
            $events[] = [
                'date' => $sub['next_charge'], 'type' => 'subscription',
                'title' => $sub['name'] . ' (assinatura)',
                'amount' => (float) $sub['amount'], 'card' => $sub['card_name'], 'card_color' => $sub['card_color'],
            ];
        }

        usort($events, static fn ($a, $b) => strcmp($a['date'], $b['date']));
        return $events;
    }

    public static function methodLabel(string $m): string
    {
        return ['credit' => 'crédito', 'debit' => 'débito', 'benefit' => 'benefício'][$m] ?? $m;
    }

    // ------------------------------------------------------------------
    // Relatório analítico (item 20)
    // ------------------------------------------------------------------

    public static function report(int $userId, string $month): array
    {
        [$start, $end] = month_bounds($month);

        $byCard = Database::run(
            'SELECT c.id, c.name, c.color, COUNT(p.id) AS purchases, COALESCE(SUM(p.amount), 0) AS total
             FROM card_purchases p JOIN cards c ON c.id = p.card_id
             WHERE p.user_id = :uid AND p.date BETWEEN :s AND :e
             GROUP BY c.id ORDER BY total DESC',
            ['uid' => $userId, 's' => $start, 'e' => $end]
        )->fetchAll();

        $byCategory = Database::run(
            'SELECT COALESCE(cat.name, \'Sem categoria\') AS name, COALESCE(cat.color, :dc) AS color,
                     COUNT(p.id) AS purchases, COALESCE(SUM(p.amount), 0) AS total
             FROM card_purchases p
             LEFT JOIN categories cat ON cat.id = p.category_id
             WHERE p.user_id = :uid AND p.date BETWEEN :s AND :e
             GROUP BY cat.id ORDER BY total DESC LIMIT 12',
            ['uid' => $userId, 's' => $start, 'e' => $end, 'dc' => '#4F46E5']
        )->fetchAll();

        // Evolução: total de compras nos últimos 6 meses
        $months = last_months(6, $month);
        $evolution = [];
        foreach ($months as $ym) {
            [$s, $e] = month_bounds($ym);
            $row = Database::run(
                'SELECT COALESCE(SUM(amount), 0) AS total FROM card_purchases
                 WHERE user_id = :uid AND date BETWEEN :s AND :e',
                ['uid' => $userId, 's' => $s, 'e' => $e]
            )->fetch();
            $evolution[] = ['month' => $ym, 'total' => (float) ($row['total'] ?? 0)];
        }

        // Parcelamentos ativos (compras com parcelas pendentes)
        $installmentPlans = Database::run(
            "SELECT p.id, p.description, p.amount, p.installments_total, p.date, c.name AS card, c.color,
                    SUM(CASE WHEN ins.status = 'pending' THEN ins.amount - ins.paid_amount ELSE 0 END) AS remaining,
                    SUM(CASE WHEN ins.status = 'pending' THEN 1 ELSE 0 END) AS pending_count
             FROM card_purchases p
             JOIN cards c ON c.id = p.card_id
             JOIN card_installments ins ON ins.purchase_id = p.id
             WHERE p.user_id = :uid AND p.installments_total > 1
             GROUP BY p.id
             HAVING pending_count > 0
             ORDER BY p.date DESC",
            ['uid' => $userId]
        )->fetchAll();

        $overview = self::overview($userId);

        return [
            'by_card'        => $byCard,
            'by_category'    => $byCategory,
            'evolution'      => $evolution,
            'installment_plans' => $installmentPlans,
            'subscriptions'   => CardRecurring::list($userId),
            'limits'          => array_filter($overview['cards'], static fn ($c) => $c['is_credit_like'] && $c['status'] !== 'archived'),
        ];
    }

    // ------------------------------------------------------------------
    // Validações auxiliares
    // ------------------------------------------------------------------

    /** Últimos 4 dígitos: exatamente 4 números ou null. false se inválido. */
    private static function last4(mixed $value): bool|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = trim((string) $value);
        return preg_match('~^\d{4}$~', $v) ? $v : false;
    }

    /** Dia do mês 1-28 (ou null). */
    private static function day(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }
        $i = filter_var($value, FILTER_VALIDATE_INT);
        return ($i !== false && $i >= 1 && $i <= 28) ? $i : null;
    }

    private static function brand(mixed $value): ?string
    {
        $v = strtolower(trim((string) $value));
        return Validator::in($v, self::BRANDS);
    }

    /** Percentual de alerta de limite: 50, 60, 70, 80, 90 ou 100. */
    private static function alertPct(mixed $value): int
    {
        $i = filter_var($value, FILTER_VALIDATE_INT);
        return ($i !== false && $i >= 10 && $i <= 100) ? $i : 80;
    }
}
