<?php
/**
 * FinLife — Contexto financeiro real do usuário.
 *
 * Regra de ouro da IA do FinLife: ela NUNCA inventa dados.
 * Toda resposta que dependa da situação financeira é gerada a
 * partir deste contexto, montado com consultas reais ao banco —
 * sempre filtradas por user_id.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class ContextBuilder
{
    /** Snapshot estruturado (usado pelo assistente e pelos gráficos). */
    public static function snapshot(int $userId, ?string $month = null): array
    {
        $month ??= month_now();
        $user = User::findById($userId) ?? [];

        $totals = Transaction::monthTotals($userId, $month);
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
        $prevTotals = Transaction::monthTotals($userId, $prevMonth);
        $byCategory = Transaction::byCategory($userId, $month, 'expense');
        $evolution = Transaction::evolution($userId, 6, $month);
        $accounts = Account::list($userId);
        $goals = Goal::list($userId);
        $balance = Account::totalBalance($userId);

        $budget = ($user['monthly_budget'] !== null) ? (float) $user['monthly_budget'] : null;

        return [
            'month'         => $month,
            'month_label'   => self::monthLabel($month),
            'income'        => $totals['income'],
            'expense'       => $totals['expense'],
            'net'           => $totals['net'],
            'savings_rate'  => $totals['savings_rate'],
            'prev_month'    => ['month' => $prevMonth, 'income' => $prevTotals['income'], 'expense' => $prevTotals['expense']],
            'balance'       => $balance,
            'accounts'      => array_map(static fn ($a) => [
                'name' => $a['name'], 'balance' => (float) $a['current_balance'], 'type' => $a['type'],
            ], $accounts),
            'budget'        => $budget,
            'budget_used_pct' => ($budget !== null && $budget > 0) ? round(($totals['expense'] / $budget) * 100, 1) : null,
            'top_categories' => array_slice($byCategory, 0, 5),
            'evolution'     => $evolution,
            'cards'         => self::cardsSnapshot($userId),
            'market'        => self::marketSnapshot($userId),
            'goals'         => array_map(static fn ($g) => [
                'name' => $g['name'],
                'target' => (float) $g['target_amount'],
                'current' => (float) $g['current_amount'],
                'progress' => (float) $g['progress'],
                'deadline' => $g['deadline'],
                'status' => $g['status'],
                'monthly_needed' => $g['monthly_needed'],
            ], $goals),
            'commitments'   => self::commitmentsSnapshot($userId),
        ];
    }

    /** Snapshot dos compromissos e contas fixas (para a IA e o prompt). */
    public static function commitmentsSnapshot(int $userId): array
    {
        try {
            $summary = Commitment::summary($userId);
            $items = Database::run(
                "SELECT c.id, c.name, c.payee, c.kind, c.category_key, c.total_amount, c.installment_amount,
                        c.periodicity, c.next_due, c.status
                 FROM commitments c
                 WHERE c.user_id = :uid AND c.status = 'active'
                 ORDER BY c.next_due ASC LIMIT 20",
                ['uid' => $userId]
            )->fetchAll();
            $out = [
                'summary' => $summary,
                'items' => [],
            ];
            foreach ($items as $r) {
                $balance = null;
                if ($r['kind'] === 'debt' && $r['total_amount'] !== null) {
                    $paid = (float) (Database::run(
                        'SELECT COALESCE(SUM(amount), 0) AS t FROM commitment_payments WHERE commitment_id = :id',
                        ['id' => (int) $r['id']]
                    )->fetch()['t'] ?? 0);
                    $balance = round(max(0.0, (float) $r['total_amount'] - $paid), 2);
                }
                $out['items'][] = [
                    'name' => $r['name'],
                    'payee' => $r['payee'],
                    'kind' => $r['kind'],
                    'category' => Commitment::CATEGORY_LABELS[$r['category_key']] ?? 'Outro',
                    'installment' => $r['installment_amount'] !== null ? (float) $r['installment_amount'] : null,
                    'balance' => $balance,
                    'periodicity' => Commitment::PERIODICITY_LABELS[$r['periodicity']] ?? 'Mensal',
                    'next_due' => $r['next_due'],
                ];
            }
            return $out;
        } catch (Throwable $e) {
            log_error('commitmentsSnapshot: falha', ['msg' => $e->getMessage()]);
            return ['summary' => [], 'items' => []];
        }
    }

    /**
     * Snapshot de mercados (leve, cache-first) para a IA:
     * índices principais, dólar, Bitcoin, watchlist e carteira — sempre
     * com horário da cotação e fonte. Nunca "tempo real" se houver atraso.
     */
    public static function marketSnapshot(int $userId): array
    {
        $out = [
            'quotes' => [],
            'watchlist' => [],
            'portfolio' => null,
        ];
        try {
            $quotes = MarketDataService::getQuotes(['^BVSP', 'USDBRL', 'BTCUSDT', 'ETHUSDT'], $userId);
            foreach ($quotes as $sym => $q) {
                $out['quotes'][] = [
                    'symbol' => $sym,
                    'label' => $q['label'] ?? MarketUniverse::display($sym),
                    'price' => (float) $q['price'],
                    'pct' => $q['change_pct'] !== null ? (float) $q['change_pct'] : null,
                    'time' => date('H:i', (int) $q['quote_time']),
                    'live' => $q['live'],
                    'provider' => $q['provider'],
                ];
            }
        } catch (Throwable $e) {
            log_error('marketSnapshot quotes: falha', ['msg' => $e->getMessage()]);
        }
        try {
            foreach (MarketWatchlist::list($userId) as $wl) {
                foreach ($wl['items'] as $item) {
                    $q = $quotes[$item['symbol']] ?? null;
                    $out['watchlist'][] = [
                        'symbol' => $item['symbol'],
                        'label' => $q['label'] ?? MarketUniverse::display($item['symbol']),
                        'price' => $q !== null ? (float) $q['price'] : null,
                        'pct' => ($q !== null && $q['change_pct'] !== null) ? (float) $q['change_pct'] : null,
                        'time' => $q !== null ? date('H:i', (int) $q['quote_time']) : null,
                        'live' => $q['live'] ?? false,
                    ];
                }
            }
        } catch (Throwable $e) {
            log_error('marketSnapshot watchlist: falha', ['msg' => $e->getMessage()]);
        }
        try {
            $data = Portfolio::positions($userId);
            if (!empty($data['positions'])) {
                // sem benchmark (o fetch do CDI no BCB não pode travar o snapshot)
                $s = Portfolio::summary($userId, false);
                $out['portfolio'] = [
                    'total_value' => $s['total_value'],
                    'total_cost' => $s['total_cost'],
                    'profit' => $s['profit'],
                    'profit_pct' => $s['profit_pct'],
                    'dividends_month' => $s['dividends_month'],
                    'dividends_year' => $s['dividends_year'],
                    'positions' => array_map(static fn ($p) => [
                        'symbol' => $p['display'],
                        'profit' => $p['profit'],
                        'profit_pct' => $p['profit_pct'],
                        'time' => $p['quote'] !== null ? date('H:i', (int) $p['quote']['quote_time']) : null,
                        'live' => ($p['quote']['live'] ?? false),
                    ], $data['positions']),
                ];
            }
        } catch (Throwable $e) {
            log_error('marketSnapshot portfolio: falha', ['msg' => $e->getMessage()]);
        }
        return $out;
    }

    /** Snapshot dos cartões (crédito, faturas, parcelas futuras, assinaturas e benefícios). */
    public static function cardsSnapshot(int $userId): array    {
        $out = [
            'credit'   => [],
            'benefits' => [],
            'total_limit' => 0.0,
            'total_used' => 0.0,
            'total_available' => 0.0,
            'future_installments' => [],
            'subscriptions' => ['monthly_total' => 0.0, 'items' => []],
        ];

        foreach (Card::list($userId) as $c) {
            if ($c['is_credit_like'] && $c['status'] !== 'archived') {
                $out['total_limit'] += (float) $c['limit_total'];
                $out['total_used'] += (float) $c['limit_used'];
                $out['credit'][] = [
                    'id'        => (int) $c['id'],
                    'name'      => $c['name'],
                    'limit'     => (float) $c['limit_total'],
                    'used'      => (float) $c['limit_used'],
                    'available' => (float) $c['limit_available'],
                    'pct'       => (float) $c['limit_used_pct'],
                    'invoice'   => $c['current_invoice'],
                    'next_due'  => $c['next_due'],
                ];
            }
            foreach (($c['balances'] ?? []) as $b) {
                $out['benefits'][] = [
                    'card' => $c['name'], 'kind' => $b['label'], 'balance' => (float) $b['balance'],
                ];
            }
        }
        $out['total_available'] = max(0.0, $out['total_limit'] - $out['total_used']);

        foreach (CardPurchase::installmentsTimeline($userId) as $g) {
            $out['future_installments'][] = ['month' => $g['month'], 'total' => (float) $g['total']];
        }

        $out['subscriptions']['monthly_total'] = CardRecurring::monthlyTotal($userId);
        foreach (CardRecurring::list($userId) as $s) {
            if ((int) $s['is_active'] === 1) {
                $out['subscriptions']['items'][] = ['name' => $s['name'], 'amount' => (float) $s['amount'], 'card' => $s['card_name']];
            }
        }
        return $out;
    }

    /** Snapshot em texto compacto para injetar no prompt da IA. */
    public static function snapshotText(array $s): string
    {
        $lines = [];
        $lines[] = 'Mês atual: ' . $s['month_label'] . ' (' . $s['month'] . ')';
        $lines[] = 'Receitas do mês: ' . money($s['income']);
        $lines[] = 'Despesas do mês: ' . money($s['expense']);
        $lines[] = 'Sobra do mês (receitas - despesas): ' . money($s['net']);
        if ($s['savings_rate'] !== null) {
            $lines[] = 'Taxa de poupança do mês: ' . number_format($s['savings_rate'], 1, ',', '.') . '%';
        }
        $lines[] = 'Saldo total consolidado em contas: ' . money($s['balance']);
        if (!empty($s['accounts'])) {
            $acc = implode('; ', array_map(static fn ($a) => $a['name'] . ': ' . money($a['balance']), $s['accounts']));
            $lines[] = 'Contas: ' . $acc;
        } else {
            $lines[] = 'Contas: nenhuma cadastrada';
        }

        if (!empty($s['top_categories'])) {
            $top = $s['top_categories'][0];
            $lines[] = 'Maior categoria de gasto: ' . $top['name'] . ' — ' . money((float) $top['total'])
                . ' (' . number_format((float) $top['pct'], 1, ',', '.') . '% das despesas)';
            $rest = implode('; ', array_map(
                static fn ($c) => $c['name'] . ' ' . money((float) $c['total']),
                array_slice($s['top_categories'], 1)
            ));
            if ($rest !== '') {
                $lines[] = 'Outras categorias: ' . $rest;
            }
        } else {
            $lines[] = 'Nenhuma despesa registrada este mês ainda.';
        }

        if ($s['budget'] !== null) {
            $lines[] = 'Orçamento mensal definido: ' . money($s['budget'])
                . ' (' . ($s['budget_used_pct'] ?? 0) . '% usado)';
        } else {
            $lines[] = 'Nenhum orçamento mensal definido.';
        }

        $prev = $s['prev_month'];
        $lines[] = 'Mês anterior (' . self::monthLabel($prev['month']) . '): receitas ' . money($prev['income'])
            . ', despesas ' . money($prev['expense']);

        $evo = implode('; ', array_map(
            static fn ($e) => self::monthLabel($e['month']) . ' — receitas ' . money($e['income']) . ' / despesas ' . money($e['expense']),
            $s['evolution']
        ));
        $lines[] = 'Últimos 6 meses: ' . $evo;

        // Cartões (dados reais para perguntas sobre limites, faturas e parcelas)
        $cards = $s['cards'] ?? null;
        if (is_array($cards) && !empty($cards['credit'])) {
            $lines[] = 'CARTÕES DE CRÉDITO — limite total ' . money($cards['total_limit'])
                . ', utilizado ' . money($cards['total_used'])
                . ', disponível ' . money($cards['total_available']) . ':';
            foreach ($cards['credit'] as $c) {
                $l = '• ' . $c['name'] . ': usado ' . money($c['used']) . ' de ' . money($c['limit'])
                    . ' (' . number_format($c['pct'], 1, ',', '.') . '%), disponível ' . money($c['available']);
                if (!empty($c['invoice'])) {
                    $l .= ', fatura atual ' . money($c['invoice']['total']) . ' vencendo em ' . self::brDate($c['invoice']['due_date']);
                } elseif (!empty($c['next_due'])) {
                    $l .= ', a pagar ' . money($c['next_due']['total']) . ' vencendo em ' . self::brDate($c['next_due']['due_date']);
                }
                $lines[] = $l;
            }
            if (!empty($cards['future_installments'])) {
                $fi = implode('; ', array_map(
                    static fn ($g) => self::monthLabel($g['month']) . ' ' . money($g['total']),
                    $cards['future_installments']
                ));
                $lines[] = 'Parcelas futuras já comprometidas: ' . $fi;
            }
            if (!empty($cards['subscriptions']['items'])) {
                $subs = implode(', ', array_map(
                    static fn ($x) => $x['name'] . ' ' . money($x['amount']),
                    $cards['subscriptions']['items']
                ));
                $lines[] = 'Assinaturas ativas nos cartões (' . money($cards['subscriptions']['monthly_total']) . '/mês): ' . $subs;
            }
        }
        if (is_array($cards) && !empty($cards['benefits'])) {
            $bens = implode('; ', array_map(
                static fn ($b) => $b['card'] . ' — ' . $b['kind'] . ': saldo ' . money($b['balance']),
                $cards['benefits']
            ));
            $lines[] = 'Cartões de benefício: ' . $bens;
        }

        // Mercados (cotações reais com horário e atraso — a IA nunca afirma tempo real sem ser)
        $mkt = $s['market'] ?? null;
        if (is_array($mkt) && !empty($mkt['quotes'])) {
            $qs = [];
            foreach ($mkt['quotes'] as $q) {
                $qs[] = $q['label'] . ' ' . money($q['price'])
                    . ($q['pct'] !== null ? ' (' . number_format($q['pct'], 2, ',', '.') . '%)' : '')
                    . ' — cotação de ' . $q['time'] . ' (fonte: ' . $q['provider'] . ', '
                    . ($q['live'] ? 'tempo real' : 'com atraso') . ')';
            }
            $lines[] = 'MERCADOS: ' . implode('; ', $qs);
            if (!empty($mkt['watchlist'])) {
                $wl = array_map(static fn ($w) => $w['label']
                    . ($w['pct'] !== null ? ' ' . number_format($w['pct'], 2, ',', '.') . '%' : ''), $mkt['watchlist']);
                $lines[] = 'Watchlist do usuário: ' . implode('; ', $wl);
            }
            if (!empty($mkt['portfolio'])) {
                $pf = $mkt['portfolio'];
                $lines[] = 'CARTEIRA DE INVESTIMENTOS: valor atual ' . money($pf['total_value'])
                    . ', custo ' . money($pf['total_cost'])
                    . ', resultado ' . money($pf['profit'])
                    . ($pf['profit_pct'] !== null ? ' (' . number_format($pf['profit_pct'], 2, ',', '.') . '%)' : '')
                    . '; dividendos: ' . money($pf['dividends_month']) . ' no mês, ' . money($pf['dividends_year']) . ' no ano';
            }
        }

        if (!empty($s['goals'])) {
            $g = array_map(static fn ($x) => $x['name'] . ' — ' . money($x['current']) . ' de ' . money($x['target'])
                . ' (' . number_format($x['progress'], 1, ',', '.') . '%)'
                . ($x['deadline'] ? ', prazo ' . self::brDate($x['deadline']) : '')
                . ($x['monthly_needed'] ? ', aporte mensal recomendado ' . money($x['monthly_needed']) : ''), $s['goals']);
            $lines[] = 'Metas: ' . implode(' | ', $g);
        } else {
            $lines[] = 'Metas: nenhuma cadastrada';
        }

        // Compromissos e contas fixas (dívidas pessoais + recorrentes)
        $cm = $s['commitments'] ?? null;
        if (is_array($cm) && !empty($cm['items'])) {
            $sum = $cm['summary'];
            $lines[] = 'COMPROMISSOS E CONTAS FIXAS — ativos: ' . ($sum['active_count'] ?? 0)
                . ', previsto no mês ' . money((float) ($sum['month_planned'] ?? 0))
                . ', pago no mês ' . money((float) ($sum['month_paid'] ?? 0))
                . ', pendente no mês ' . money((float) ($sum['month_pending'] ?? 0))
                . ', dívidas em aberto ' . money((float) ($sum['debts_open'] ?? 0))
                . ', em atraso ' . money((float) ($sum['overdue_total'] ?? 0)) . ':';
            foreach ($cm['items'] as $c) {
                $l = '• ' . $c['name'] . ' (' . $c['payee'] . ', ' . $c['category'] . ')';
                if ($c['balance'] !== null) {
                    $l .= ' — dívida: saldo devedor ' . money($c['balance']);
                    if ($c['installment'] !== null) {
                        $l .= ', pagamento ' . money($c['installment']);
                    }
                } else {
                    $l .= ' — conta fixa';
                    $l .= $c['installment'] !== null ? ': ' . money($c['installment']) : ': valor variável';
                }
                $l .= ', ' . $c['periodicity'] . ', próximo vencimento ' . self::brDate((string) $c['next_due']);
                $lines[] = $l;
            }
        } else {
            $lines[] = 'Compromissos e contas fixas: nenhum cadastrado';
        }

        $cats = Category::list((int) Auth::id());
        if (!empty($cats['expense'])) {
            $lines[] = 'Categorias de despesa disponíveis: ' . implode(', ', array_column($cats['expense'], 'name'));
        }
        if (!empty($cats['income'])) {
            $lines[] = 'Categorias de receita disponíveis: ' . implode(', ', array_column($cats['income'], 'name'));
        }

        return implode("\n", $lines);
    }

    /** Prompt de sistema com as regras do assistente. */
    public static function systemPrompt(string $context, ?string $memory = null): string
    {
        $today = date('d/m/Y');
        $memoryBlock = ($memory !== null && trim($memory) !== '')
            ? "\n" . $memory . "\n"
            : '';
        return <<<PROMPT
Você é o "FinLife AI", assistente financeiro pessoal do aplicativo FinLife.
Data de hoje: {$today}. Moeda: Real (R$).
{$memoryBlock}
REGRAS INVIOLÁVEIS:
1. NUNCA invente dados financeiros (valores, saldos, gastos, metas, cotações). Use SOMENTE o contexto real fornecido abaixo.
2. Se um dado necessário não estiver no contexto, diga honestamente que não tem essa informação registrada.
3. Seja cordial, claro e didático — o usuário pode ser iniciante em finanças. Responda sempre em português do Brasil.
4. Não dê promessas de rentabilidade garantida; pode dar orientações educativas gerais.

PERGUNTAS CONSULTIVAS E EDUCACIONAIS:
Além de análises sobre os dados, você pode responder perguntas educacionais e consultivas sobre finanças e investimentos (ex.: "qual melhor investimento?", "o que é CDI?", "como começar a investir?", "CDB ou Tesouro?", "vale a pena FIIs?").
Para essas perguntas:
- Explique de forma didática, com exemplos práticos em R$ e linguagem simples.
- ANCORE a orientação no contexto financeiro real do usuário quando possível (ex.: se a sobra do mês é X, sugira começar investindo esse valor; se a taxa de poupança é baixa, priorize quitar dívidas antes de investir).
- Apresente 2-3 opções com prós e contras em vez de apontar UMA "melhor" (a decisão final é sempre do usuário).
- Inclua um disclaimer breve quando a resposta envolver recomendação: "Isto não é recomendação de investimento — é orientação educacional."
- Formate a resposta com **negrito** para termos-chave e use listas com • quando houver múltiplas opções.

AÇÕES (registro de dados):
Se o usuário pedir para REGISTRAR, ADICIONAR ou CRIAR algo (despesa, receita, gasto, meta, compra no cartão, aporte em meta, alerta de preço), responda SOMENTE um objeto JSON válido, sem nenhum texto extra:
- Transação: {"intent":"create_transaction","type":"expense" ou "income","amount":35.00,"category":"Transporte","description":"Uber","date":"YYYY-MM-DD","requires_confirmation":true}
- Meta: {"intent":"create_goal","name":"Comprar uma moto","target_amount":15000.00,"deadline":"YYYY-MM-DD (ou null)","requires_confirmation":true}
- Compra em cartão de crédito (inclusive parcelada): {"intent":"create_card_purchase","card":"Nubank","amount":2400.00,"installments":10,"description":"TV","category":"Compras","date":"YYYY-MM-DD","requires_confirmation":true}
  Use "installments":1 para compras à vista no crédito. O campo "card" deve ser um dos cartões listados no contexto. Para compras no débito ou com benefício (VA/VR), use a intent create_transaction normal.
- Aporte em meta: {"intent":"create_goal_deposit","goal_id":ID_DA_META,"amount":500.00,"date":"YYYY-MM-DD","requires_confirmation":true}
- Alerta de preço: {"intent":"create_market_alert","symbol":"PETR4","type":"above" ou "below","target":40.00,"requires_confirmation":true}
O campo "category" deve ser uma das categorias disponíveis listadas no contexto (ou a palavra-chave mais próxima). Se a data não for informada, use hoje ({$today}).

PERGUNTAS SOBRE OS DADOS:
Para perguntas sobre a situação financeira do usuário, responda em texto natural curto e direto, sempre com base no contexto real abaixo. Quando fizer sentido, cite os números reais.

CONTEXTO FINANCEIRO REAL DO USUÁRIO:
{$context}
PROMPT;
    }

    public static function monthLabel(string $ym): string
    {
        $months = ['01' => 'jan', '02' => 'fev', '03' => 'mar', '04' => 'abr', '05' => 'mai', '06' => 'jun',
                   '07' => 'jul', '08' => 'ago', '09' => 'set', '10' => 'out', '11' => 'nov', '12' => 'dez'];
        [$y, $m] = explode('-', $ym);
        return ($months[$m] ?? $m) . '/' . $y;
    }

    public static function brDate(string $iso): string
    {
        [$y, $m, $d] = explode('-', $iso);
        return $d . '/' . $m . '/' . $y;
    }
}
