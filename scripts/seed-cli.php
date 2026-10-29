<?php
/**
 * FinLife — Seed CLI de dados de demonstração (usuário redesign@teste.com).
 * Usa os models reais da aplicação. Idempotente: limpa os dados do usuário antes.
 *
 * Uso: php scripts/seed-cli.php
 */
declare(strict_types=1);

define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

const SEED_EMAIL = 'redesign@teste.com';

$user = User::findByEmail(SEED_EMAIL);
if ($user === null) {
    fwrite(STDERR, "Usuário " . SEED_EMAIL . " não encontrado. Crie-o via /cadastro primeiro.\n");
    exit(1);
}
$userId = (int) $user['id'];

echo "Usuário: {$user['name']} (id {$userId})\n";

// ---------- Limpa dados existentes do usuário ----------
Database::run('DELETE FROM card_invoice_payments WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM card_installments WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM card_purchases WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM card_invoices WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM card_recurring WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM cards WHERE user_id = :uid', ['uid' => $userId]); // balances/credits via CASCADE
Database::run('DELETE FROM market_watchlists WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM market_alerts WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM portfolio_transactions WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM portfolio_dividends WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM investments WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM goal_deposits WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM goals WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM transactions WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM accounts WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM categories WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM ai_messages WHERE conversation_id IN (SELECT id FROM ai_conversations WHERE user_id = :uid)', ['uid' => $userId]);
Database::run('DELETE FROM ai_conversations WHERE user_id = :uid', ['uid' => $userId]);
echo "Dados antigos removidos.\n";

// ---------- 2) Categorias padrão ----------
Category::seedDefaults($userId);
$cats = Category::list($userId);
$catId = [];
foreach (['expense', 'income'] as $type) {
    foreach ($cats[$type] as $c) {
        $catId[$c['name']] = (int) $c['id'];
    }
}
echo 'Categorias: ' . count($catId) . "\n";

// ---------- 3) Contas ----------
$accNubank = Account::create($userId, [
    'name' => 'Nubank', 'type' => 'digital_wallet', 'institution' => 'Nubank',
    'initial_balance' => 300.0, 'color' => '#7C3AED',
]);
$accBB = Account::create($userId, [
    'name' => 'Banco do Brasil', 'type' => 'checking', 'institution' => 'Banco do Brasil',
    'initial_balance' => 1500.0, 'color' => '#F5B544',
]);
$accCarteira = Account::create($userId, [
    'name' => 'Carteira', 'type' => 'cash', 'institution' => 'Dinheiro físico',
    'initial_balance' => 120.0, 'color' => '#059669',
]);
echo "Contas: 3\n";

// ---------- 4) Transações (últimos 6 meses) ----------
$tx = 0;
$today = new DateTimeImmutable('today');
$mkDate = static function (int $monthsAgo, int $day, int $maxDayCurrent) use ($today): string {
    $base = $today->modify("-{$monthsAgo} months");
    $max = (int) $base->format('t');
    if ($monthsAgo === 0) {
        $day = min($day, (int) $today->format('j'), $maxDayCurrent);
    } else {
        $day = min($day, $max);
    }
    return $base->format('Y-m-') . sprintf('%02d', $day);
};

$add = static function (string $type, string $desc, float $amount, string $date, string $cat, int $accId) use ($userId, $catId, &$tx): void {
    $r = Transaction::create($userId, [
        'type' => $type, 'description' => $desc, 'amount' => $amount,
        'date' => $date, 'category_id' => $catId[$cat] ?? null, 'account_id' => $accId,
    ]);
    if (($r['ok'] ?? false) === true) { $tx++; } else { fwrite(STDERR, "tx ERRO ($desc): " . ($r['error'] ?? '?') . "\n"); }
};

for ($m = 5; $m >= 0; $m--) {
    $d = static fn (int $day): string => $mkDate($m, $day, 999);

    // Receitas
    $add('income', 'Salário mensal', 3200.0, $mkDate($m, 5, 999), 'Salário', $accBB);
    if ($m % 2 === 0) { $add('income', 'Projeto freelance', 850.0, $mkDate($m, 12, 999), 'Freelance', $accBB); }
    if ($m === 3) { $add('income', 'Rendimento da poupança', 74.30, $mkDate($m, 20, 999), 'Rendimentos', $accBB); }

    // Despesas fixas
    $add('expense', 'Aluguel', 1150.0, $mkDate($m, 8, 999), 'Moradia', $accBB);
    $add('expense', 'Internet fibra', 99.90, $mkDate($m, 10, 999), 'Contas', $accBB);
    $add('expense', 'Plano de saúde', 210.0, $mkDate($m, 15, 999), 'Saúde', $accBB);

    // Despesas variáveis
    foreach ([3, 9, 16, 23] as $day) {
        $add('expense', 'Mercado da esquina', round(142.50 + $day * 3.7, 2), $mkDate($m, $day, 999), 'Alimentação', $accNubank);
    }
    $add('expense', 'Restaurante japonês', 89.90, $mkDate($m, 6, 999), 'Alimentação', $accNubank);
    $add('expense', 'Restaurante japonês', 76.40, $mkDate($m, 13, 999), 'Alimentação', $accNubank);
    $add('expense', 'Uber para o trabalho', 23.40, $mkDate($m, 4, 999), 'Transporte', $accNubank);
    $add('expense', 'Combustível', 180.0, $mkDate($m, 11, 999), 'Transporte', $accBB);
    $add('expense', 'Uber para o trabalho', 19.80, $mkDate($m, 18, 999), 'Transporte', $accNubank);
    $add('expense', 'Cinema com amigos', 54.0, $mkDate($m, 7, 999), 'Lazer', $accNubank);
    $add('expense', 'Streaming (Netflix + Spotify)', 45.90, $mkDate($m, 21, 999), 'Assinaturas', $accBB);
    $add('expense', 'Farmácia', 68.35, $mkDate($m, 14, 999), 'Saúde', $accNubank);
    if ($m === 2) { $add('expense', 'Curso de design online', 249.0, $mkDate($m, 9, 999), 'Educação', $accBB); }
    if ($m === 4) { $add('expense', 'Compra de roupas', 329.90, $mkDate($m, 17, 999), 'Compras', $accNubank); }
    if ($m === 1) { $add('expense', 'Presente de aniversário', 150.0, $mkDate($m, 22, 999), 'Lazer', $accNubank); }
}
echo "Transações: {$tx}\n";

// ---------- 5) Metas + aportes ----------
$goalReserva = Goal::create($userId, [
    'name' => 'Reserva de emergência', 'target_amount' => 12000.0,
    'deadline' => $today->modify('+12 months')->format('Y-m-d'),
]);
$goalViagem = Goal::create($userId, [
    'name' => 'Viagem para a praia', 'target_amount' => 4500.0,
    'deadline' => $today->modify('+6 months')->format('Y-m-d'),
]);
$goalNotebook = Goal::create($userId, [
    'name' => 'Notebook novo', 'target_amount' => 6500.0, 'deadline' => null,
]);
echo 'Metas: 3' . "\n";

if (($goalReserva['ok'] ?? false) === true) {
    Goal::addDeposit($userId, (int) $goalReserva['id'], ['amount' => 1500.0, 'date' => $today->modify('-2 months')->format('Y-m-d'), 'note' => 'mês inicial']);
    Goal::addDeposit($userId, (int) $goalReserva['id'], ['amount' => 1300.0, 'date' => $today->modify('-1 month')->format('Y-m-d'), 'note' => null]);
}
if (($goalViagem['ok'] ?? false) === true) {
    Goal::addDeposit($userId, (int) $goalViagem['id'], ['amount' => 800.0, 'date' => $today->modify('-10 days')->format('Y-m-d'), 'note' => 'décimo terceiro']);
}
echo "Aportes: 3\n";

// ---------- 6) Orçamento ----------
User::setMonthlyBudget($userId, 3200.0);
echo "Orçamento: R$ 3.200\n";

// ---------- 7) Cartões (módulo completo) ----------
$mkCardDate = static function (int $monthsAgo, int $day) use ($today): string {
    $base = $today->modify("-{$monthsAgo} months");
    return $base->format('Y-m-') . sprintf('%02d', min($day, (int) $base->format('t')));
};

// Cartões
$r = Card::create($userId, [
    'name' => 'Nubank', 'nickname' => 'cartão principal', 'type' => 'credit', 'institution' => 'Nubank',
    'brand' => 'mastercard', 'last4' => '4521', 'color' => '#7C3AED', 'limit_total' => 5000,
    'closing_day' => 20, 'due_day' => 27, 'annual_fee' => 0, 'account_id' => $accBB,
]);
$cardNubank = (int) $r['id'];
$r = Card::create($userId, [
    'name' => 'Itaú', 'type' => 'credit', 'institution' => 'Itaú Unibanco', 'brand' => 'visa',
    'last4' => '1122', 'color' => '#F5B544', 'limit_total' => 3000, 'closing_day' => 5, 'due_day' => 12,
    'annual_fee' => 120,
]);
$cardItau = (int) $r['id'];
$r = Card::create($userId, [
    'name' => 'Nubank Adicional', 'type' => 'credit', 'last4' => '8871', 'color' => '#7C3AED',
    'parent_card_id' => $cardNubank,
]);
$r = Card::create($userId, [
    'name' => 'Cartão da Empresa', 'type' => 'debit', 'institution' => 'Empresa', 'brand' => 'elo',
    'last4' => '7788', 'color' => '#4F46E5', 'account_id' => $accBB,
]);
$cardEmpresa = (int) $r['id'];
$r = Card::create($userId, [
    'name' => 'Alelo', 'type' => 'benefit', 'institution' => 'Alelo', 'last4' => '3390', 'color' => '#059669',
    'benefit_kinds' => ['food', 'meal'],
]);
$cardAlelo = (int) $r['id'];
$balances = Card::balancesByCard($userId)[$cardAlelo] ?? [];
$balFood = $balMeal = null;
foreach ($balances as $b) {
    if ($b['benefit_kind'] === 'food') { $balFood = (int) $b['id']; }
    if ($b['benefit_kind'] === 'meal') { $balMeal = (int) $b['id']; }
}
echo "Cartões: 5 (2 crédito + adicional + débito + benefício)\n";

// Saldos de benefício: créditos mensais (histórico de 3 meses) + gastos
foreach ([2, 1, 0] as $mAgo) {
    Card::addCredit($userId, $balFood, ['amount' => 700.0, 'date' => $mkCardDate($mAgo, 5), 'periodicity' => 'monthly', 'note' => 'crédito mensal VA']);
    Card::addCredit($userId, $balMeal, ['amount' => 500.0, 'date' => $mkCardDate($mAgo, 5), 'periodicity' => 'monthly', 'note' => 'crédito mensal VR']);
}
$addBenefit = static function (int $balanceId, string $desc, float $amount, int $monthsAgo, int $day) use ($userId, $cardAlelo, $catId, $mkCardDate): void {
    CardPurchase::create($userId, [
        'card_id' => $cardAlelo, 'balance_id' => $balanceId, 'description' => $desc, 'amount' => $amount,
        'method' => 'benefit', 'date' => $mkCardDate($monthsAgo, $day), 'category_id' => $catId['Alimentação'] ?? null,
    ]);
};
$addBenefit($balFood, 'Supermercado da esquina', 154.80, 2, 8);
$addBenefit($balFood, 'Hortifruti', 89.40, 1, 12);
$addBenefit($balFood, 'Padaria pão de queijo', 42.50, 0, 3);
$addBenefit($balMeal, 'Restaurante da firma', 32.00, 1, 15);
$addBenefit($balMeal, 'Almoço executivo', 28.90, 0, 6);
echo "Benefícios: 6 créditos + 5 gastos (VA e VR separados)\n";

// Compras no crédito
$addPurchase = static function (array $d) use ($userId): void {
    $r = CardPurchase::create($userId, $d);
    if (($r['ok'] ?? false) !== true) { echo 'ERRO compra: ' . json_encode($r) . "\n"; }
};
$addPurchase(['card_id' => $cardNubank, 'description' => 'Smart TV 50"', 'store' => 'Magalu', 'amount' => 2400, 'method' => 'credit', 'installments' => 10, 'date' => $mkCardDate(2, 10), 'category_id' => $catId['Compras'] ?? null, 'notes' => 'compra de black friday']);
$addPurchase(['card_id' => $cardNubank, 'description' => 'Fone bluetooth', 'store' => 'Amazon', 'amount' => 500, 'method' => 'credit', 'date' => $mkCardDate(1, 12), 'category_id' => $catId['Compras'] ?? null]);
$addPurchase(['card_id' => $cardNubank, 'description' => 'Mercado do mês', 'store' => 'Assaí', 'amount' => 300, 'method' => 'credit', 'date' => $mkCardDate(0, 2), 'category_id' => $catId['Alimentação'] ?? null]);
$addPurchase(['card_id' => $cardNubank, 'description' => 'Restaurante japonês', 'store' => 'Sushi K', 'amount' => 120, 'method' => 'credit', 'date' => $mkCardDate(0, 4), 'category_id' => $catId['Lazer'] ?? null]);
$addPurchase(['card_id' => $cardItau, 'description' => 'Notebook Dell', 'store' => 'Dell', 'amount' => 3600, 'method' => 'credit', 'installments' => 12, 'date' => $mkCardDate(2, 15), 'category_id' => $catId['Educação'] ?? null]);
$addPurchase(['card_id' => $cardItau, 'description' => 'Cadeira gamer', 'store' => 'Kabum', 'amount' => 850, 'method' => 'credit', 'installments' => 3, 'date' => $mkCardDate(1, 18), 'category_id' => $catId['Compras'] ?? null]);
echo "Compras no crédito: 6 (com parcelamentos de 10x, 12x e 3x)\n";

// Fatura do mês passado do Nubank quitada (gera saída real na conta)
$root = Card::rootOf($userId, $cardNubank);
$prevCycle = Card::cycleKeyFor($mkCardDate(1, 15), $root);
$prevInvoice = CardInvoice::getOrCreate($userId, $root, $prevCycle);
$prevData = CardInvoice::hydrate($prevInvoice);
if ($prevData['total'] > 0) {
    CardInvoice::pay($userId, (int) $prevInvoice['id'], [
        'amount' => $prevData['total'], 'date' => $prevInvoice['due_date'], 'account_id' => $accBB,
    ]);
    echo 'Fatura anterior do Nubank paga: ' . money($prevData['total']) . "\n";
}

// Compra no débito (saída imediata na conta)
$addPurchase(['card_id' => $cardEmpresa, 'description' => 'Combustível', 'store' => 'Posto Shell', 'amount' => 200, 'method' => 'debit', 'date' => $mkCardDate(0, 3), 'category_id' => $catId['Transporte'] ?? null]);

// Assinaturas
CardRecurring::create($userId, ['name' => 'Netflix', 'amount' => 55.90, 'card_id' => $cardNubank, 'periodicity' => 'monthly', 'charge_day' => 15, 'start_date' => $mkCardDate(3, 15), 'category_id' => $catId['Assinaturas'] ?? null]);
CardRecurring::create($userId, ['name' => 'Spotify', 'amount' => 21.90, 'card_id' => $cardNubank, 'periodicity' => 'monthly', 'charge_day' => 8, 'start_date' => $mkCardDate(3, 8), 'category_id' => $catId['Assinaturas'] ?? null]);
CardRecurring::create($userId, ['name' => 'Academia', 'amount' => 120.0, 'card_id' => $cardItau, 'periodicity' => 'monthly', 'charge_day' => 10, 'start_date' => $mkCardDate(4, 10), 'category_id' => $catId['Saúde'] ?? null]);
CardRecurring::create($userId, ['name' => 'Internet fibra', 'amount' => 99.90, 'card_id' => $cardItau, 'periodicity' => 'monthly', 'charge_day' => 1, 'start_date' => $mkCardDate(5, 1), 'category_id' => $catId['Contas'] ?? null]);
echo "Assinaturas: 4\n";

$overview = Card::overview($userId);
echo 'Limites: ' . money($overview['total_limit']) . ' | Usado: ' . money($overview['total_used'])
    . ' | Disponível: ' . money($overview['total_available']) . "\n";
echo 'Faturas abertas: ' . money($overview['open_invoices_total'])
    . ' | Parcelas futuras: ' . money($overview['future_installments_total'])
    . ' | Assinaturas: ' . money($overview['subscriptions_monthly']) . "/mês\n";
echo 'Alertas ativos: ' . count($overview['alerts']) . "\n";

// ---------- 8) Mercados e investimentos ----------
Database::run('DELETE FROM market_watchlists WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM market_alerts WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM portfolio_transactions WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM portfolio_dividends WHERE user_id = :uid', ['uid' => $userId]);
Database::run('DELETE FROM investments WHERE user_id = :uid', ['uid' => $userId]);

// Watchlist
$wlId = MarketWatchlist::ensureDefault($userId);
foreach (['PETR4', 'VALE3', 'HGLG11', 'BTC', 'ETH'] as $sym) {
    MarketWatchlist::addItem($userId, $wlId, $sym);
}
$wl2 = MarketWatchlist::create($userId, 'FIIs e renda');
foreach (['MXRF11', 'KNCR11', 'RZAG11'] as $sym) {
    MarketWatchlist::addItem($userId, $wl2, $sym);
}
echo "Watchlists: 2 (8 ativos)\n";

// Alertas: um ativo distante do alvo + um histórico disparado
MarketAlert::create($userId, ['symbol' => 'BTC', 'type' => 'above', 'target' => 600000, 'note' => 'realizar parte da posição']);
MarketAlert::create($userId, ['symbol' => 'PETR4', 'type' => 'above', 'target' => 30, 'note' => null]);
Database::run(
    "UPDATE market_alerts SET status = 'triggered', triggered_at = NOW() - INTERVAL 3 DAY,
     trigger_price = 31.50 WHERE user_id = :uid AND symbol = 'PETR4.SA'",
    ['uid' => $userId]
);
echo "Alertas: 2 (1 ativo, 1 disparado)\n";

// Carteira: preço médio verificável (50@30 + 50@34 = 32)
Portfolio::addTransaction($userId, ['symbol' => 'PETR4', 'type' => 'buy', 'quantity' => 50, 'price' => 30.0, 'fees' => 0, 'date' => $mkCardDate(6, 10)]);
Portfolio::addTransaction($userId, ['symbol' => 'PETR4', 'type' => 'buy', 'quantity' => 50, 'price' => 34.0, 'fees' => 0, 'date' => $mkCardDate(4, 12)]);
Portfolio::addTransaction($userId, ['symbol' => 'HGLG11', 'type' => 'buy', 'quantity' => 60, 'price' => 140.0, 'fees' => 4.20, 'date' => $mkCardDate(5, 5)]);
Portfolio::addTransaction($userId, ['symbol' => 'IVVB11', 'type' => 'buy', 'quantity' => 20, 'price' => 380.0, 'fees' => 2.90, 'date' => $mkCardDate(3, 18)]);
Portfolio::addTransaction($userId, ['symbol' => 'BTC', 'type' => 'buy', 'quantity' => 0.05, 'price' => 300000.0, 'fees' => 12.5, 'date' => $mkCardDate(2, 22)]);
echo "Carteira: 4 posições (PETR4 preço médio R$ 32,00)\n";

// Dividendos
Portfolio::addDividend($userId, ['symbol' => 'PETR4', 'type' => 'dividend', 'amount' => 230.0, 'per_unit' => 2.30, 'ex_date' => $mkCardDate(1, 5), 'pay_date' => $mkCardDate(0, 8)]);
Portfolio::addDividend($userId, ['symbol' => 'HGLG11', 'type' => 'rendimento_fii', 'amount' => 78.9, 'per_unit' => 1.315, 'pay_date' => $mkCardDate(0, 15)]);
Portfolio::addDividend($userId, ['symbol' => 'PETR4', 'type' => 'jcp', 'amount' => 96.5, 'per_unit' => 0.965, 'pay_date' => $mkCardDate(2, 20)]);
echo "Dividendos: 3 lançamentos\n";

$pf = Portfolio::summary($userId);
echo 'Carteira: valor ' . money($pf['total_value']) . ' | custo ' . money($pf['total_cost'])
    . ' | resultado ' . money($pf['profit']) . ' | dividendos ano ' . money($pf['dividends_year']) . "\n";

// ---------- Resumo ----------
$snap = ContextBuilder::snapshot($userId);
echo "\n=== RESUMO ===\n";
echo 'Mês: ' . $snap['month_label'] . "\n";
echo 'Saldo em contas: ' . money($snap['balance']) . "\n";
echo 'Receitas: ' . money($snap['income']) . ' | Despesas: ' . money($snap['expense']) . ' | Sobra: ' . money($snap['net']) . "\n";
echo 'Evolução (meses): ' . count($snap['evolution']) . ' | Metas: ' . count($snap['goals']) . "\n";
echo "SEED CLI CONCLUÍDO\n";
