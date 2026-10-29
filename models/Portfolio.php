<?php
/**
 * FinLife — Portfólio do usuário (multiativo).
 *
 * Fonte da verdade: portfolio_transactions (compras/vendas com taxas).
 * A posição (quantidade/preço médio) é recalculada a cada transação e
 * materializada em investments (snapshot rápido de leitura).
 *
 * Preço médio correto (com custos): 10 @ 30 + 10 @ 40 → R$ 35.
 * Rentabilidade usa cotações REAIS do MarketDataService (nunca fixas).
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Portfolio
{
    /** classes de mercado → classe de portfólio (investments.asset_class). */
    public const CLASS_MAP = [
        'br_stock' => 'acoes', 'us_stock' => 'acoes', 'fii' => 'fii', 'fiagro' => 'fiagro',
        'etf' => 'etf', 'bdr' => 'bdr', 'cripto' => 'cripto',
        'index' => 'outro', 'forex' => 'outro', 'commodity' => 'outro',
    ];

    public const CLASS_LABELS = [
        'renda_fixa' => 'Renda fixa', 'acoes' => 'Ações', 'fii' => 'FIIs', 'fiagro' => 'FIAGROs',
        'etf' => 'ETFs', 'bdr' => 'BDRs', 'cripto' => 'Criptomoedas', 'outro' => 'Outros',
    ];

    /** Registra compra/venda e recalcula a posição (preço médio com taxas). */
    public static function addTransaction(int $userId, array $d): array
    {
        $symbol = MarketUniverse::normalize((string) ($d['symbol'] ?? ''));
        if ($symbol === '') {
            return ['ok' => false, 'error' => 'Informe o ativo.'];
        }
        $type = Validator::in($d['type'] ?? 'buy', ['buy', 'sell']);
        if ($type === null) {
            return ['ok' => false, 'error' => 'Tipo de operação inválido.'];
        }
        $quantity = self::quantity($d['quantity'] ?? null);
        if ($quantity === null) {
            return ['ok' => false, 'error' => 'Informe a quantidade.'];
        }
        $price = Validator::amount($d['price'] ?? null);
        if ($price === null) {
            return ['ok' => false, 'error' => 'Informe o preço unitário.'];
        }
        $fees = Validator::amount($d['fees'] ?? 0) ?? 0.0;
        $date = Validator::date($d['date'] ?? null) ?? date('Y-m-d');
        $class = self::portfolioClass($symbol);

        $pos = self::rawPosition($userId, $symbol);
        if ($type === 'sell' && ($pos === null || $pos['quantity'] + 0.00000001 < $quantity)) {
            return ['ok' => false, 'error' => 'Quantidade a vender maior que a posição atual.'];
        }

        Database::run(
            'INSERT INTO portfolio_transactions (user_id, symbol, class, type, quantity, price, fees, date, note)
             VALUES (:uid, :sym, :cls, :type, :qty, :price, :fees, :date, :note)',
            [
                'uid' => $userId, 'sym' => $symbol, 'cls' => $class, 'type' => $type,
                'qty' => $quantity, 'price' => $price, 'fees' => $fees, 'date' => $date,
                'note' => Validator::text($d['note'] ?? '', 255) ?: null,
            ]
        );
        $txId = (int) Database::lastId();

        // recalcula a posição a partir de TODAS as transações
        $newPos = self::recompute($userId, $symbol);
        if ($newPos !== null) {
            self::upsertPosition($userId, $symbol, $class, $newPos);
        } else {
            self::removePosition($userId, $symbol);
        }
        return ['ok' => true, 'id' => $txId, 'position' => $newPos];
    }

    public static function deleteTransaction(int $userId, int $id): bool
    {
        $tx = Database::run(
            'SELECT * FROM portfolio_transactions WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        if (!$tx) {
            return false;
        }
        Database::run('DELETE FROM portfolio_transactions WHERE id = :id AND user_id = :uid', ['id' => $id, 'uid' => $userId]);
        $newPos = self::recompute($userId, (string) $tx['symbol']);
        if ($newPos !== null) {
            self::upsertPosition($userId, (string) $tx['symbol'], (string) $tx['class'], $newPos);
        } else {
            self::removePosition($userId, (string) $tx['symbol']);
        }
        return true;
    }

    /**
     * Recalcula quantidade e preço médio a partir das transações.
     * Compras somam custo (com taxas); vendas reduzem quantidade e mantêm o preço médio.
     */
    private static function recompute(int $userId, string $symbol): ?array
    {
        $txs = Database::run(
            'SELECT * FROM portfolio_transactions WHERE user_id = :uid AND symbol = :sym ORDER BY date, id',
            ['uid' => $userId, 'sym' => $symbol]
        )->fetchAll();
        if ($txs === []) {
            return null;
        }
        $qty = 0.0;
        $cost = 0.0; // custo total das posições em posse (com fees)
        $realized = 0.0; // resultado realizado em vendas
        foreach ($txs as $tx) {
            $q = (float) $tx['quantity'];
            $p = (float) $tx['price'];
            $f = (float) $tx['fees'];
            if ($tx['type'] === 'buy') {
                $cost += ($q * $p) + $f;
                $qty += $q;
            } else {
                $avg = ($qty > 0) ? $cost / $qty : 0.0;
                $realized += ($q * ($p - $avg)) - $f;
                $cost -= $avg * $q;
                $qty -= $q;
            }
        }
        if ($qty <= 0.00000001) {
            return ['quantity' => 0.0, 'avg_price' => 0.0, 'cost' => 0.0, 'realized' => round($realized, 2)];
        }
        return [
            'quantity' => round($qty, 8),
            'avg_price' => round($cost / $qty, 4),
            'cost' => round($cost, 2),
            'realized' => round($realized, 2),
        ];
    }

    /** Posição bruta calculada (sem cotação). */
    private static function rawPosition(int $userId, string $symbol): ?array
    {
        $txs = Database::run(
            'SELECT * FROM portfolio_transactions WHERE user_id = :uid AND symbol = :sym ORDER BY date, id',
            ['uid' => $userId, 'sym' => $symbol]
        )->fetchAll();
        if ($txs === []) {
            return null;
        }
        return self::recompute($userId, $symbol);
    }

    private static function upsertPosition(int $userId, string $symbol, string $class, array $pos): void
    {
        $exists = Database::run(
            'SELECT id FROM investments WHERE user_id = :uid AND symbol = :sym LIMIT 1',
            ['uid' => $userId, 'sym' => $symbol]
        )->fetch();
        $label = MarketUniverse::label($symbol);
        if ($exists) {
            Database::run(
                'UPDATE investments SET quantity = :qty, avg_price = :avg, current_value = 0,
                        asset_class = :cls, name = :name, updated_at = NOW()
                 WHERE id = :id',
                ['qty' => $pos['quantity'], 'avg' => $pos['avg_price'], 'cls' => $class,
                 'name' => $label, 'id' => (int) $exists['id']]
            );
            return;
        }
        Database::run(
            'INSERT INTO investments (user_id, name, asset_class, symbol, quantity, avg_price, current_value)
             VALUES (:uid, :name, :cls, :sym, :qty, :avg, 0)',
            ['uid' => $userId, 'name' => $label, 'cls' => $class, 'sym' => $symbol,
             'qty' => $pos['quantity'], 'avg' => $pos['avg_price']]
        );
    }

    private static function removePosition(int $userId, string $symbol): void
    {
        Database::run(
            'DELETE FROM investments WHERE user_id = :uid AND symbol = :sym',
            ['uid' => $userId, 'sym' => $symbol]
        );
    }

    /**
     * Posições com cotação REAL (preço atual, lucro, rentabilidade, peso).
     */
    public static function positions(int $userId): array
    {
        $rows = Database::run(
            'SELECT * FROM investments WHERE user_id = :uid AND quantity > 0 ORDER BY asset_class, symbol',
            ['uid' => $userId]
        )->fetchAll();
        if ($rows === []) {
            return ['positions' => [], 'total_value' => 0.0];
        }
        $symbols = array_column($rows, 'symbol');
        $quotes = MarketDataService::getQuotes($symbols);

        $positions = [];
        $totalValue = 0.0;
        foreach ($rows as $r) {
            $sym = (string) $r['symbol'];
            $q = $quotes[$sym] ?? null;
            $price = $q !== null ? (float) $q['price'] : (float) $r['avg_price'];
            $qty = (float) $r['quantity'];
            $avg = (float) $r['avg_price'];
            $cost = $avg * $qty;
            $value = $price * $qty;
            $profit = $value - $cost;
            $totalValue += $value;
            $positions[] = [
                'symbol' => $sym,
                'display' => MarketUniverse::display($sym),
                'name' => (string) $r['name'],
                'class' => (string) $r['asset_class'],
                'class_label' => self::CLASS_LABELS[$r['asset_class']] ?? $r['asset_class'],
                'quantity' => $qty,
                'avg_price' => $avg,
                'cost' => round($cost, 2),
                'price' => $price,
                'value' => round($value, 2),
                'profit' => round($profit, 2),
                'profit_pct' => $cost > 0 ? round(($profit / $cost) * 100, 2) : null,
                'quote' => $q, // com fonte, timestamp, delay — sempre
            ];
        }
        foreach ($positions as &$p) {
            $p['weight'] = $totalValue > 0 ? round(($p['value'] / $totalValue) * 100, 1) : 0;
        }
        unset($p);
        return ['positions' => $positions, 'total_value' => round($totalValue, 2)];
    }

    /** Resumo: custo, valor, lucro, %, alocação por classe, dividendos, benchmark opcional. */
    public static function summary(int $userId, bool $withBenchmark = true): array
    {
        $data = self::positions($userId);
        $positions = $data['positions'];
        $totalValue = $data['total_value'];
        $totalCost = 0.0;
        $byClass = [];
        foreach ($positions as $p) {
            $totalCost += $p['cost'];
            $byClass[$p['class']] = ($byClass[$p['class']] ?? 0) + $p['value'];
        }
        $profit = $totalValue - $totalCost;
        $allocation = [];
        foreach ($byClass as $cls => $v) {
            $allocation[] = [
                'class' => $cls,
                'label' => self::CLASS_LABELS[$cls] ?? $cls,
                'value' => round($v, 2),
                'pct' => $totalValue > 0 ? round(($v / $totalValue) * 100, 1) : 0,
            ];
        }
        usort($allocation, static fn ($a, $b) => $b['pct'] <=> $a['pct']);

        $month = date('Y-m');
        $year = date('Y');
        $divMonth = (float) (Database::run(
            "SELECT COALESCE(SUM(amount), 0) AS t FROM portfolio_dividends
             WHERE user_id = :uid AND pay_date LIKE :ym",
            ['uid' => $userId, 'ym' => $month . '%']
        )->fetch()['t'] ?? 0);
        $divYear = (float) (Database::run(
            "SELECT COALESCE(SUM(amount), 0) AS t FROM portfolio_dividends
             WHERE user_id = :uid AND pay_date LIKE :y",
            ['uid' => $userId, 'y' => $year . '%']
        )->fetch()['t'] ?? 0);

        return [
            'positions' => $positions,
            'total_cost' => round($totalCost, 2),
            'total_value' => $totalValue,
            'profit' => round($profit, 2),
            'profit_pct' => $totalCost > 0 ? round(($profit / $totalCost) * 100, 2) : null,
            'allocation' => $allocation,
            'dividends_month' => round($divMonth, 2),
            'dividends_year' => round($divYear, 2),
            'dividend_yield' => $totalValue > 0 ? round(($divYear / $totalValue) * 100, 2) : null,
            'benchmark' => $withBenchmark ? self::benchmark($userId, $positions) : null,
        ];
    }

    /**
     * Benchmark REAL: Ibovespa e CDI acumulados entre a primeira
     * transação e hoje — mesma janela da carteira.
     */
    private static function benchmark(int $userId, array $positions): ?array
    {
        $first = Database::run(
            'SELECT MIN(date) AS d FROM portfolio_transactions WHERE user_id = :uid',
            ['uid' => $userId]
        )->fetch();
        if (!$first || $first['d'] === null) {
            return null;
        }
        $from = (string) $first['d'];
        $days = max(1, (int) floor((time() - strtotime($from)) / 86400));

        // IBOV real no período
        $ibov = MarketDataService::getHistory('^BVSP', '5A');
        $ibovPct = null;
        if (count($ibov) >= 2) {
            $start = null;
            foreach ($ibov as $c) {
                if ($c['t'] >= strtotime($from)) { $start = $c; break; }
            }
            $end = end($ibov);
            if ($start !== null && $start['c'] > 0) {
                $ibovPct = round((($end['c'] / $start['c']) - 1) * 100, 2);
            }
        }

        // CDI real (série 12 do BCB — % diário) — melhor esforço, timeout curto
        $cdiPct = null;
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'header' => "User-Agent: FinLife/1.0\r\n"]]);
        $raw = @file_get_contents('https://api.bcb.gov.br/dados/serie/bcdata.sgs.12/dados/ultimos/'
            . min(2600, $days + 10) . '?formato=json', false, $ctx);
        if ($raw !== false) {
            $series = json_decode($raw, true);
            if (is_array($series)) {
                $factor = 1.0;
                foreach ($series as $row) {
                    $ts = DateTime::createFromFormat('d/m/Y', (string) ($row['data'] ?? ''));
                    if ($ts === false || $ts->format('Y-m-d') < $from) { continue; }
                    $factor *= 1 + ((float) $row['valor'] / 100);
                }
                $cdiPct = round(($factor - 1) * 100, 2);
            }
        }

        return [
            'from' => $from,
            'days' => $days,
            'ibov_pct' => $ibovPct,
            'cdi_pct' => $cdiPct,
            'source' => 'B3 (via Yahoo) · BCB SGS 12',
        ];
    }

    // ------------------------------------------------------------------
    // Dividendos e proventos
    // ------------------------------------------------------------------

    public static function addDividend(int $userId, array $d): array
    {
        $symbol = MarketUniverse::normalize((string) ($d['symbol'] ?? ''));
        if ($symbol === '') {
            return ['ok' => false, 'error' => 'Informe o ativo.'];
        }
        $amount = Validator::amount($d['amount'] ?? null);
        if ($amount === null) {
            return ['ok' => false, 'error' => 'Informe o valor total recebido.'];
        }
        $type = Validator::in($d['type'] ?? 'dividend', ['dividend', 'jcp', 'rendimento_fii', 'juros', 'outro']);
        $payDate = Validator::date($d['pay_date'] ?? null) ?? date('Y-m-d');
        Database::run(
            'INSERT INTO portfolio_dividends (user_id, symbol, type, amount, per_unit, ex_date, pay_date, note)
             VALUES (:uid, :sym, :type, :amount, :per, :ex, :pay, :note)',
            [
                'uid' => $userId, 'sym' => $symbol, 'type' => $type ?? 'dividend',
                'amount' => $amount,
                'per' => Validator::amount($d['per_unit'] ?? null),
                'ex' => Validator::date($d['ex_date'] ?? null),
                'pay' => $payDate,
                'note' => Validator::text($d['note'] ?? '', 255) ?: null,
            ]
        );
        return ['ok' => true, 'id' => (int) Database::lastId()];
    }

    public static function deleteDividend(int $userId, int $id): bool
    {
        return Database::run(
            'DELETE FROM portfolio_dividends WHERE id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        )->rowCount() > 0;
    }

    public static function dividends(int $userId, ?string $year = null): array
    {
        $sql = 'SELECT * FROM portfolio_dividends WHERE user_id = :uid';
        $params = ['uid' => $userId];
        if ($year !== null && preg_match('~^\d{4}$~', $year)) {
            $sql .= ' AND pay_date LIKE :y';
            $params['y'] = $year . '%';
        }
        $sql .= ' ORDER BY pay_date DESC, id DESC LIMIT 200';
        return Database::run($sql, $params)->fetchAll();
    }

    public static function transactions(int $userId, ?string $symbol = null): array
    {
        $sql = 'SELECT * FROM portfolio_transactions WHERE user_id = :uid';
        $params = ['uid' => $userId];
        if ($symbol !== null) {
            $sql .= ' AND symbol = :sym';
            $params['sym'] = MarketUniverse::normalize($symbol);
        }
        $sql .= ' ORDER BY date DESC, id DESC LIMIT 200';
        return Database::run($sql, $params)->fetchAll();
    }

    private static function quantity(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $v = (float) $value;
        } elseif (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
            $v = (float) str_replace(',', '.', $value);
        } else {
            return null;
        }
        return ($v > 0 && $v <= 999999999) ? round($v, 8) : null;
    }

    private static function portfolioClass(string $symbol): string
    {
        $meta = MarketUniverse::meta($symbol);
        $marketClass = $meta['class'] ?? (BinanceProvider::supports($symbol) ? 'cripto' : 'br_stock');
        return self::CLASS_MAP[$marketClass] ?? 'outro';
    }
}
