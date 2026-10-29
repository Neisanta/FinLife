<?php
/** Testes das novas capacidades do FinLife AI (inteligência + interatividade). */
declare(strict_types=1);
define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

Database::run('DELETE FROM rate_limits');
$user = User::findByEmail('redesign@teste.com');
$uid = (int) $user['id'];
$_SESSION = []; // limpa contexto conversacional

$passed = 0;
$failed = 0;
function check(string $name, bool $cond, $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  [PASS] {$name}\n"; }
    else { $failed++; echo "  [FAIL] {$name}" . ($detail !== '' ? " — " . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n"; }
}
function ask(int $uid, string $q): array
{
    $snap = ContextBuilder::snapshot($uid);
    $r = LocalNlu::handle($uid, $q, $snap);
    return [
        'action' => $r['action'] ?? null,
        'reply' => is_string($r['reply'] ?? null) ? $r['reply'] : ($r['reply']['reply'] ?? ''),
        'rich' => $r['rich'] ?? null,
        'quick' => $r['quick'] ?? null,
    ];
}

echo "=== NOVAS ANÁLISES ===\n";
$r = ask($uid, 'qual minha saúde financeira?');
check('saúde financeira responde com score', preg_match('/score \d+\/100/i', $r['reply']) === 1 || preg_match('/\d+\/100/', $r['reply']) === 1, $r['reply']);
check('saúde financeira tem rich stats', is_array($r['rich']) && $r['rich'][0]['type'] === 'stats', $r['rich']);

$r = ask($uid, 'vou estourar o orçamento?');
check('projeção de orçamento responde', preg_match('/(ritmo|orçamento)/i', $r['reply']) === 1, $r['reply']);
check('projeção tem progress bar', is_array($r['rich']) && strpos(json_encode($r['rich']), 'progress') !== false, $r['rich']);

$r = ask($uid, 'meus maiores gastos do mês');
check('maiores gastos lista transações', preg_match('/maiores gastos/i', $r['reply']) === 1, $r['reply']);
check('maiores gastos tem tx-list rich', is_array($r['rich']) && $r['rich'][0]['type'] === 'tx-list', $r['rich']);
check('tx-list tem >= 3 itens', is_array($r['rich']) && count($r['rich'][0]['items']) >= 3, $r['rich']);

$r = ask($uid, 'dicas para economizar');
check('dicas baseadas em dados reais', preg_match('/(•|assina|fatura|poupa|sobra|categor)/i', $r['reply']) === 1, $r['reply']);

$r = ask($uid, 'quanto gastei hoje?');
check('gastos de hoje responde', preg_match('/(hoje|Hoje)/i', $r['reply']) === 1, $r['reply']);

echo "\n=== MEMÓRIA CONTEXTUAL ===\n";
ask($uid, 'quanto gastei com alimentação esse mês?');
$r = ask($uid, 'e no mês passado?');
check('follow-up "e no mês passado?" responde com contexto', preg_match('/mês anterior|mes anterior/i', $r['reply']) === 1, $r['reply']);

echo "\n=== RICH CARDS EM RESPOSTAS EXISTENTES ===\n";
$r = ask($uid, 'quanto tenho em contas?');
check('saldo com rich stats', is_array($r['rich']) && $r['rich'][0]['type'] === 'stats', $r['rich']);
check('saldo com quick replies', is_array($r['quick']) && count($r['quick']) >= 2, $r['quick']);

$r = ask($uid, 'como estão minhas metas?');
check('metas com progress bars', is_array($r['rich']) && strpos(json_encode($r['rich']), 'progress') !== false, $r['rich']);

echo "\n=== NOVAS AÇÕES (com confirmação) ===\n";
$r = ask($uid, 'aportei R$ 500 na reserva de emergência');
check('aporte detecta intent', ($r['action']['intent'] ?? '') === 'create_goal_deposit', $r);
check('aporte acerta valor', ($r['action']['amount'] ?? 0) === 500.0);
check('aporte acerta meta', stripos($r['action']['goal_name'] ?? '', 'reserva') !== false, $r['action'] ?? null);

$validated = AiAssistant::validateAction($uid, $r['action']);
check('AiAssistant valida aporte', $validated !== null && $validated['intent'] === 'create_goal_deposit');

$r = ask($uid, 'avise quando PETR4 chegar a R$ 40');
check('alerta detecta intent', ($r['action']['intent'] ?? '') === 'create_market_alert', $r);
check('alerta acerta ativo', ($r['action']['symbol'] ?? '') === 'PETR4.SA', $r['action'] ?? null);
check('alerta acerta alvo', ($r['action']['target'] ?? 0) === 40.0);

$validated = AiAssistant::validateAction($uid, $r['action']);
check('AiAssistant valida alerta', $validated !== null && $validated['intent'] === 'create_market_alert');

echo "\n=== EXECUÇÃO REAL (aporte + alerta) ===\n";
$goalBefore = 0.0;
$goalId = null;
foreach (Goal::list($uid) as $g) {
    if (stripos($g['name'], 'reserva') !== false) { $goalBefore = (float) $g['current_amount']; $goalId = (int) $g['id']; break; }
}
check('meta da reserva encontrada', $goalId !== null);
$dep = Goal::addDeposit($uid, $goalId, ['amount' => 100.0, 'date' => date('Y-m-d'), 'note' => 'teste IA v2']);
check('aporte executado de fato (model)', ($dep['ok'] ?? false) === true);
$goalAfter = 0.0;
foreach (Goal::list($uid) as $g) {
    if (stripos($g['name'], 'reserva') !== false) { $goalAfter = (float) $g['current_amount']; break; }
}
check('saldo da meta subiu R$ 100', abs(($goalAfter - $goalBefore) - 100.0) < 0.01, [$goalBefore, $goalAfter]);

$alertsBefore = count(MarketAlert::list($uid));
$al = MarketAlert::create($uid, ['symbol' => 'PETR4', 'type' => 'above', 'target' => 99.99]);
$alertsAfter = count(MarketAlert::list($uid));
check('alerta executado de fato (+1)', ($al['ok'] ?? false) === true && $alertsAfter === $alertsBefore + 1, [$alertsBefore, $alertsAfter]);

echo "\n===== IA: {$passed} PASS / {$failed} FAIL =====\n";
if ($failed > 0) { exit(1); }
echo "NOVAS CAPACIDADES DA IA FUNCIONANDO\n";
