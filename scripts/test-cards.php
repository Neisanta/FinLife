<?php
/**
 * FinLife — Testes do módulo de cartões (8 cenários via models).
 *
 * Uso:  C:\xampp\php\php.exe scripts/test-cards.php
 *
 * Cenários (item 29 do módulo de cartões):
 *  1. Limite 5.000 + compra 500 à vista → usado 500, disponível 4.500
 *  2. Compra 1.200 em 12x → 12 parcelas de 100
 *  3. Dois cartões → limites independentes
 *  4. Cartão adicional → compra afeta limite/fatura do principal
 *  5. Compra após o fechamento → entra na próxima fatura
 *  6. Pagamento da fatura → limite volta + saída real na conta
 *  7. VA + VR → saldos separados
 *  8. Crédito mensal de benefício → saldo atualiza
 *  + IA: intents de compra em cartão e perguntas respondidas com dados reais
 */
declare(strict_types=1);

/**
 * Credencial do usuário de teste: vem do ambiente, nunca fica no código.
 * Defina FINLIFE_TEST_PASSWORD no shell antes de rodar.
 */
$testPassword = getenv('FINLIFE_TEST_PASSWORD');
if ($testPassword === false || $testPassword === '') {
    fwrite(STDERR, "Defina FINLIFE_TEST_PASSWORD antes de rodar este script.\n");
    exit(1);
}


define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

const TEST_EMAIL = 'test-cards@teste.com';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}
function near(float $a, float $b, float $tol = 0.005): bool
{
    return abs($a - $b) <= $tol;
}

// ------------------------------------------------------------------
// Preparação: usuário de teste isolado
// ------------------------------------------------------------------
echo "=== FinLife — Testes do módulo de Cartões ===\n";
Database::run('DELETE FROM users WHERE email = :email', ['email' => TEST_EMAIL]);
$reg = Auth::register('Teste Cartões', TEST_EMAIL, $testPassword);
if (($reg['ok'] ?? false) !== true) {
    fwrite(STDERR, 'Falha ao criar usuário de teste: ' . json_encode($reg) . "\n");
    exit(1);
}
$userId = (int) $reg['user_id'];
$account = Account::create($userId, ['name' => 'Conta teste', 'type' => 'checking', 'institution' => '', 'initial_balance' => 5000.0, 'color' => null]);
/** Saldo atual da conta de teste (Auth::register também cria uma "Carteira"). */
function balanceOf(int $userId, int $accountId): float
{
    foreach (Account::list($userId) as $a) {
        if ((int) $a['id'] === $accountId) {
            return (float) $a['current_balance'];
        }
    }
    return 0.0;
}
$balanceBefore = balanceOf($userId, $account);
echo "Usuário de teste criado (id {$userId})\n\n";

// ==================================================================
echo "--- Cenário 1: compra à vista no crédito ---\n";
$r = Card::create($userId, [
    'name' => 'Nubank', 'type' => 'credit', 'institution' => 'Nubank', 'brand' => 'mastercard',
    'last4' => '4521', 'color' => '#7C3AED', 'limit_total' => 5000, 'closing_day' => 20, 'due_day' => 27,
]);
check('cartão criado', ($r['ok'] ?? false) === true, json_encode($r));
$nubankId = (int) $r['id'];

$r = CardPurchase::create($userId, [
    'card_id' => $nubankId, 'description' => 'Fone de ouvido', 'amount' => 500, 'method' => 'credit', 'date' => date('Y-m-d'),
]);
check('compra 500 registrada', ($r['ok'] ?? false) === true, json_encode($r));
$purchase1 = (int) $r['id'];

$usage = Card::limitUsageByCard($userId);
check('usado = 500', near((float) $usage[$nubankId], 500), 'usado=' . ($usage[$nubankId] ?? 0));
check('disponível = 4500', near((float) Card::overview($userId)['total_available'], 4500));

// ==================================================================
echo "\n--- Cenário 2: compra parcelada 1.200 em 12x ---\n";
$r = CardPurchase::create($userId, [
    'card_id' => $nubankId, 'description' => 'Notebook', 'amount' => 1200, 'method' => 'credit',
    'installments' => 12, 'date' => date('Y-m-d'),
]);
check('compra 12x registrada', ($r['ok'] ?? false) === true, json_encode($r));
$purchase2 = (int) $r['id'];

$installments = Database::run(
    'SELECT * FROM card_installments WHERE purchase_id = :pid ORDER BY number',
    ['pid' => $purchase2]
)->fetchAll();
check('gerou 12 parcelas', count($installments) === 12, 'gerou ' . count($installments));
$all100 = array_reduce($installments, static fn ($ok, $i) => $ok && near((float) $i['amount'], 100), true);
check('cada parcela = R$ 100', $all100);
$cycleOk = true;
$root = Card::rootOf($userId, $nubankId);
$today = new DateTimeImmutable('today');
for ($k = 1; $k <= 12; $k++) {
    $base = $today->modify(($k - 1) . ' months')->format('Y-m-d');
    $expectCycle = Card::cycleKeyFor($base, $root);
    if ($installments[$k - 1]['due_date'] !== Card::dueDateForCycle($expectCycle, (int) $root['due_day'])) {
        $cycleOk = false;
    }
}
check('datas previstas seguem o ciclo (fechamento→vencimento)', $cycleOk);
$sumTotal = array_sum(array_map(static fn ($i) => (float) $i['amount'], $installments));
check('soma das parcelas = 1.200', near($sumTotal, 1200), 'soma=' . $sumTotal);

// ==================================================================
echo "\n--- Cenário 3: limites independentes ---\n";
$r = Card::create($userId, [
    'name' => 'Itaú', 'type' => 'credit', 'institution' => 'Itaú', 'brand' => 'visa',
    'last4' => '1122', 'color' => '#F5B544', 'limit_total' => 3000, 'closing_day' => 5, 'due_day' => 12,
]);
$itauId = (int) $r['id'];
CardPurchase::create($userId, ['card_id' => $itauId, 'description' => 'Mercado', 'amount' => 300, 'method' => 'credit', 'date' => date('Y-m-d')]);

$usage = Card::limitUsageByCard($userId);
check('Nubank usado = 1.700 (500 + 1.200)', near((float) $usage[$nubankId], 1700), 'usado=' . ($usage[$nubankId] ?? 0));
check('Itaú usado = 300 (não misturado)', near((float) $usage[$itauId], 300), 'usado=' . ($usage[$itauId] ?? 0));
check('Itaú disponível = 2.700', near(3000 - (float) $usage[$itauId], 2700));

// ==================================================================
echo "\n--- Cenário 4: cartão adicional afeta o principal ---\n";
$r = Card::create($userId, [
    'name' => 'Nubank Adicional', 'type' => 'credit', 'last4' => '8871', 'color' => '#7C3AED',
    'parent_card_id' => $nubankId,
]);
check('adicional criado', ($r['ok'] ?? false) === true, json_encode($r));
$addId = (int) $r['id'];

CardPurchase::create($userId, ['card_id' => $addId, 'description' => 'Presente', 'amount' => 200, 'method' => 'credit', 'date' => date('Y-m-d')]);
$usage = Card::limitUsageByCard($userId);
check('compra do adicional somou no principal (1.900)', near((float) $usage[$nubankId], 1900), 'usado=' . $usage[$nubankId]);
$addInstallment = Database::run(
    'SELECT ins.* FROM card_installments ins JOIN card_purchases p ON p.id = ins.purchase_id WHERE p.card_id = :aid',
    ['aid' => $addId]
)->fetch();
check('parcela do adicional vinculada à fatura do principal', $addInstallment !== false && (int) $addInstallment['card_id'] === $nubankId);

// ==================================================================
echo "\n--- Cenário 5: compra após o fechamento → próxima fatura ---\n";
// Nubank fecha dia 20: compra no dia 25 entra no ciclo do mês seguinte
$afterClosing = date('Y-m-d', strtotime(date('Y-m-') . '25'));
$r = CardPurchase::create($userId, ['card_id' => $nubankId, 'description' => 'Passagem aérea', 'amount' => 300, 'method' => 'credit', 'date' => $afterClosing]);
check('compra pós-fechamento registrada', ($r['ok'] ?? false) === true, json_encode($r));

$root = Card::rootOf($userId, $nubankId);
$expectNextCycle = date('Y-m-', strtotime(date('Y-m-01') . ' +1 month')) . '20';
$inv = CardInvoice::getOrCreate($userId, $root, $expectNextCycle);
$installment = Database::run(
    'SELECT ins.* FROM card_installments ins JOIN card_purchases p ON p.id = ins.purchase_id
     WHERE p.description = \'Passagem aérea\' LIMIT 1'
)->fetch();
check('compra caiu na fatura do próximo ciclo',
    $installment !== false && (int) $installment['invoice_id'] === (int) $inv['id'],
    'invoice_id=' . ($installment['invoice_id'] ?? '?') . ' esperado=' . $inv['id']);

// ==================================================================
echo "\n--- Cenário 6: pagamento da fatura ---\n";
$cycleKey = Card::cycleKeyFor(date('Y-m-d'), $root);
$invoice = CardInvoice::getOrCreate($userId, $root, $cycleKey);
$hydrated = CardInvoice::hydrate($invoice);
check('fatura atual do Nubank = 800 (500 + 100 + 200)', near((float) $hydrated['total'], 800), 'total=' . $hydrated['total']);

$r = CardInvoice::pay($userId, (int) $invoice['id'], ['amount' => $hydrated['total'], 'date' => date('Y-m-d'), 'account_id' => $account]);
check('pagamento total registrado', ($r['ok'] ?? false) === true, json_encode($r));

$usage = Card::limitUsageByCard($userId);
// usado antes do pagamento: 1900 + 300 = 2200 → fatura quitada libera 800 → 1400
check('limite liberado: usado = 1.400', near((float) $usage[$nubankId], 1400), 'usado=' . $usage[$nubankId]);

$balanceAfter = balanceOf($userId, $account);
check('saída real na conta (−800)', near($balanceBefore - $balanceAfter, 800), 'delta=' . ($balanceBefore - $balanceAfter));

$paid = CardInvoice::hydrate(CardInvoice::get($userId, (int) $invoice['id']));
check('fatura marcada como paga', $paid['status'] === 'paid', 'status=' . $paid['status']);
$paidInstallment = Database::run(
    'SELECT status FROM card_installments WHERE purchase_id = :pid',
    ['pid' => $purchase1]
)->fetch();
check('parcelas da fatura quitadas', $paidInstallment !== false && $paidInstallment['status'] === 'paid');

// ==================================================================
echo "\n--- Cenário 7: VA + VR com saldos separados ---\n";
$r = Card::create($userId, [
    'name' => 'Alelo', 'type' => 'benefit', 'institution' => 'Alelo', 'last4' => '3390', 'color' => '#059669',
    'benefit_kinds' => ['food', 'meal'],
]);
check('cartão de benefício criado', ($r['ok'] ?? false) === true, json_encode($r));
$aleloId = (int) $r['id'];

$balances = Card::balancesByCard($userId)[$aleloId] ?? [];
$foodId = null; $mealId = null;
foreach ($balances as $b) {
    if ($b['benefit_kind'] === 'food') { $foodId = (int) $b['id']; }
    if ($b['benefit_kind'] === 'meal') { $mealId = (int) $b['id']; }
}
check('dois saldos independentes (VA e VR)', $foodId !== null && $mealId !== null);

Card::addCredit($userId, $foodId, ['amount' => 700, 'date' => date('Y-m-d'), 'periodicity' => 'monthly']);
Card::addCredit($userId, $mealId, ['amount' => 500, 'date' => date('Y-m-d'), 'periodicity' => 'monthly']);

$r = CardPurchase::create($userId, ['card_id' => $aleloId, 'balance_id' => $foodId, 'description' => 'Supermercado', 'amount' => 154.80, 'method' => 'benefit', 'date' => date('Y-m-d'), 'category_id' => Category::matchByName($userId, 'alimentação', 'expense')['id'] ?? null]);
check('gasto de VA registrado', ($r['ok'] ?? false) === true, json_encode($r));

$balances = Card::balancesByCard($userId)[$aleloId] ?? [];
$foodBalance = $mealBalance = null;
foreach ($balances as $b) {
    if ($b['benefit_kind'] === 'food') { $foodBalance = (float) $b['balance']; }
    if ($b['benefit_kind'] === 'meal') { $mealBalance = (float) $b['balance']; }
}
check('saldo VA = 545,20 (700 − 154,80)', near($foodBalance, 545.20), 'VA=' . $foodBalance);
check('saldo VR = 500 (intocado)', near($mealBalance, 500), 'VR=' . $mealBalance);
check('gasto de VA NÃO gerou saída em contas', near(balanceOf($userId, $account), $balanceAfter));

// ==================================================================
echo "\n--- Cenário 8: crédito mensal atualiza o saldo ---\n";
Card::addCredit($userId, $foodId, ['amount' => 700, 'date' => date('Y-m-d', strtotime('+1 month')), 'periodicity' => 'monthly', 'note' => 'crédito do mês']);
$balances = Card::balancesByCard($userId)[$aleloId] ?? [];
$foodBalance = null;
foreach ($balances as $b) {
    if ($b['benefit_kind'] === 'food') { $foodBalance = (float) $b['balance']; }
}
check('novo crédito somado: VA = 1.245,20', near($foodBalance, 1245.20), 'VA=' . $foodBalance);

// ==================================================================
echo "\n--- Bônus: IA com dados reais de cartões ---\n";
$snap = ContextBuilder::snapshot($userId);
$cardsSnap = $snap['cards'];
check('snapshot de cartões no contexto da IA', count($cardsSnap['credit']) === 2 && near((float) $cardsSnap['total_available'], 3600 + 2700));
check('parcelas futuras no contexto', !empty($cardsSnap['future_installments']));

$reply = LocalNlu::handle($userId, 'quanto ainda tenho de limite', $snap)['reply'];
check('NLU responde sobre limite com dados reais', str_contains($reply, '3.600') || str_contains($reply, '6.300'), $reply);

$intent = LocalNlu::handle($userId, 'comprei uma tv de 2400 em 10 vezes no nubank', $snap);
check('NLU detecta compra parcelada no cartão', ($intent['action']['intent'] ?? '') === 'create_card_purchase', json_encode($intent));
check('NLU acerta cartão/valor/parcelas', ($intent['action']['card'] ?? '') === 'Nubank'
    && near((float) $intent['action']['amount'], 2400)
    && (int) $intent['action']['installments'] === 10);

$validated = AiAssistant::validateAction($userId, $intent['action'] ?? null);
check('AiAssistant valida a ação de compra', $validated !== null && $validated['card_id'] === $nubankId);

$reply = LocalNlu::handle($userId, 'quais parcelas ainda tenho', $snap)['reply'];
check('NLU responde sobre parcelas', str_contains($reply, 'Parcelas') || str_contains($reply, 'parcelas'), $reply);

// Assinatura
CardRecurring::create($userId, ['name' => 'Netflix', 'amount' => 55.90, 'card_id' => $nubankId, 'periodicity' => 'monthly', 'charge_day' => 15, 'start_date' => date('Y-m-d')]);
$reply = LocalNlu::handle($userId, 'quanto gasto por mes em assinaturas', $snap)['reply'];
check('NLU responde sobre assinaturas', str_contains($reply, 'Netflix'), $reply);

// ==================================================================
// Limpeza: usuário de teste sai do banco (CASCADE remove tudo)
Database::run('DELETE FROM users WHERE id = :id', ['id' => $userId]);

echo "\n===== RESULTADO: {$passed} PASS / {$failed} FAIL =====\n";
if ($failed > 0) {
    exit(1);
}
echo "Todos os cenários passaram. Usuário de teste removido.\n";
