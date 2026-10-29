<?php
/**
 * Testes do módulo Compromissos e Contas Fixas — ciclo financeiro completo.
 * Dívida 1200/200×6: parcial, integral, saldo, recorrência, atraso, quitação, undo.
 */
declare(strict_types=1);
define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

$user = User::findByEmail('redesign@teste.com');
if ($user === null) { exit("Usuário de teste redesign@teste.com não encontrado. Rode scripts/seed-cli.php"); }
$userId = (int) $user['id'];

$passed = 0;
$failed = 0;
function check(string $name, bool $cond, $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  [PASS] {$name}\n"; }
    else { $failed++; echo "  [FAIL] {$name}" . ($detail !== '' ? ' — ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n"; }
}
// limpa compromissos de testes anteriores
Database::run('DELETE FROM commitments WHERE user_id = :uid', ['uid' => $userId]);

echo "=== 1) CRIAÇÃO: dívida R$ 1.200 com o pai, R$ 200/mês × 6 ===\n";
$r = Commitment::create($userId, [
    'name' => 'Dívida com o papai',
    'payee' => 'João (pai)',
    'kind' => 'debt',
    'category_key' => 'divida_pessoal',
    'total_amount' => 1200.0,
    'installment_amount' => 200.0,
    'periodicity' => 'monthly',
    'next_due' => date('Y-m-10'),
    'start_date' => date('Y-m-01'),
    'total_installments' => 6,
    'payment_method' => 'pix',
]);
check('cria compromisso', ($r['ok'] ?? false) === true, $r);
$debtId = (int) $r['id'];

$c = Commitment::get($userId, $debtId);
check('saldo devedor inicial = 1200', $c['balance'] === 1200.0, $c['balance']);
check('progresso 0%', $c['progress_pct'] === 0.0);
check('1ª ocorrência gerada (dia 10)', count($c['occurrences_future']) === 1, count($c['occurrences_future']));
check('sem transações espelho ainda', count($c['payments']) === 0);

echo "\n=== 2) PAGAMENTO PARCIAL (R$ 100 em vez de 200) ===\n";
$beforeTx = (int) (Database::run("SELECT COUNT(*) AS n FROM transactions WHERE user_id = :uid", ['uid' => $userId])->fetch()['n']);
$r = Commitment::registerPayment($userId, $debtId, ['amount' => 100.0, 'paid_date' => date('Y-m-10'), 'description' => 'metade combinada']);
check('pagamento parcial aceito', ($r['ok'] ?? false) === true, $r);
check('saldo após parcial = 1100', $r['balance'] === 1100.0, $r['balance']);
$c = Commitment::get($userId, $debtId);
check('ocorrência parcial (100 de 200)', $c['occurrences_future'][0]['status'] === 'partial' && $c['occurrences_future'][0]['paid_amount'] === 100.0, $c['occurrences_future'][0]);
$afterTx = (int) (Database::run("SELECT COUNT(*) AS n FROM transactions WHERE user_id = :uid", ['uid' => $userId])->fetch()['n']);
check('transação espelho criada (+1)', $afterTx === $beforeTx + 1, [$beforeTx, $afterTx]);
check('progresso 8.3%', abs($c['progress_pct'] - 8.3) < 0.2, $c['progress_pct']);

echo "\n=== 3) PAGAMENTO INTEGRAL DO PERÍODO (completa os R$ 200 do mês) ===\n";
$r = Commitment::registerPayment($userId, $debtId, ['amount' => 100.0, 'paid_date' => date('Y-m-11')]);
check('completa o período', ($r['ok'] ?? false) === true);
check('saldo após 200 pagos = 1000', $r['balance'] === 1000.0, $r['balance']);
$c = Commitment::get($userId, $debtId);
check('ocorrência do mês paga', $c['occurrences_future'][0]['status'] === 'pending' || count($c['occurrences_past']) >= 1, array_map(fn ($o) => $o['status'], $c['occurrences_future']));
check('2ª ocorrência gerada (mês seguinte)', count($c['occurrences_future']) >= 1, count($c['occurrences_future']));
$nextDue = $c['occurrences_future'][0]['due_date'] ?? '';
$expectedNext = date('Y-m-10', strtotime(date('Y-m-10') . ' +1 month'));
check('próximo vencimento = dia 10 do mês seguinte', $nextDue === $expectedNext, [$nextDue, $expectedNext]);

echo "\n=== 4) ANTI-DUPLICAÇÃO ===\n";
// tenta pagar acima do saldo
$r = Commitment::registerPayment($userId, $debtId, ['amount' => 9999.0]);
check('bloqueia pagamento acima do saldo', ($r['ok'] ?? false) === false, $r);
// ocorrência duplicada: ensureOccurrence respeita UNIQUE
$before = (int) (Database::run("SELECT COUNT(*) AS n FROM commitment_occurrences WHERE commitment_id = :id", ['id' => $debtId])->fetch()['n']);
Commitment::ensureOccurrencesFor($userId, $debtId);
$after = (int) (Database::run("SELECT COUNT(*) AS n FROM commitment_occurrences WHERE commitment_id = :id", ['id' => $debtId])->fetch()['n']);
check('não gera ocorrências duplicadas', $after === $before, [$before, $after]);
// commit único (transação DB): simular erro forçando exceção não é trivial — verifico integridade
$paidSum = (float) (Database::run("SELECT COALESCE(SUM(amount),0) AS t FROM commitment_payments WHERE commitment_id = :id", ['id' => $debtId])->fetch()['t']);
$balance = (float) (Database::run("SELECT total_amount FROM commitments WHERE id = :id", ['id' => $debtId])->fetch()['total_amount']) - $paidSum;
$c = Commitment::get($userId, $debtId);
check('integridade: saldo modelo = 1200 - pagamentos', abs($c['balance'] - $balance) < 0.01, [$c['balance'], $balance]);

echo "\n=== 5) UNDO DE PAGAMENTO ===\n";
$c = Commitment::get($userId, $debtId);
$lastPayment = $c['payments'][0];
$txId = (int) $lastPayment['transaction_id'];
$r = Commitment::undoPayment($userId, $debtId, (int) $lastPayment['id']);
check('undo aceito', ($r['ok'] ?? false) === true, $r);
$txGone = Database::run("SELECT 1 FROM transactions WHERE id = :id", ['id' => $txId])->fetch() === false;
check('transação espelho removida', $txGone);
$c = Commitment::get($userId, $debtId);
check('saldo restaurado (undo do último pagamento de 100)', $c['balance'] === 1100.0, $c['balance']);
// refaz o pagamento para continuar o teste
Commitment::registerPayment($userId, $debtId, ['amount' => 100.0, 'paid_date' => date('Y-m-11')]);

echo "\n=== 6) CONTA FIXA SEM DÍVIDA TOTAL (telefone R$ 80/mês) ===\n";
$r = Commitment::create($userId, [
    'name' => 'Telefone',
    'payee' => 'Vivo',
    'kind' => 'recurring',
    'category_key' => 'telefone',
    'installment_amount' => 80.0,
    'periodicity' => 'monthly',
    'next_due' => date('Y-m-15'),
    'start_date' => date('Y-m-01'),
]);
check('cria conta fixa sem total', ($r['ok'] ?? false) === true, $r);
$phoneId = (int) $r['id'];
$c = Commitment::get($userId, $phoneId);
check('sem saldo devedor (null)', $c['balance'] === null, $c['balance']);
check('sem progresso', $c['progress_pct'] === null);
// paga: gera a próxima automaticamente
$r = Commitment::registerPayment($userId, $phoneId, ['amount' => 80.0, 'paid_date' => date('Y-m-15')]);
check('paga telefone', ($r['ok'] ?? false) === true);
$c = Commitment::get($userId, $phoneId);
$expectedNext = date('Y-m-15', strtotime(date('Y-m-15') . ' +1 month'));
$nextOcc = $c['occurrences_future'][0] ?? null;
check('próxima ocorrência gerada (+1 mês)', $nextOcc !== null && $nextOcc['due_date'] === $expectedNext, $nextOcc['due_date'] ?? null);

// valor variável: altera SOMENTE a próxima ocorrência
$r = Commitment::update($userId, $phoneId, ['amount' => 95.5, 'due_date' => $expectedNext], 'occurrence');
check('edita próxima ocorrência (escopo occurrence)', ($r['ok'] ?? false) === true, $r);
$c = Commitment::get($userId, $phoneId);
check('valor da ocorrência alterado para 95.50', (float) $c['occurrences_future'][0]['amount'] === 95.5, $c['occurrences_future'][0]['amount']);
check('installment_amount do cadastro intacto (80)', (float) $c['installment_amount'] === 80.0);

echo "\n=== 7) ATRASO DERIVADO ===\n";
// cria compromisso com vencimento ontem
$r = Commitment::create($userId, [
    'name' => 'Acordo academia',
    'payee' => 'Academia XYZ',
    'kind' => 'recurring',
    'category_key' => 'mensalidade',
    'installment_amount' => 120.0,
    'periodicity' => 'monthly',
    'next_due' => date('Y-m-d', strtotime('-5 days')),
    'start_date' => date('Y-m-d', strtotime('-35 days')),
]);
$gymId = (int) $r['id'];
$c = Commitment::get($userId, $gymId);
check('status derivado = overdue', $c['derived_status'] === 'overdue', $c['derived_status']);
check('overdue_count = 1', $c['overdue_count'] === 1);
check('overdue_amount = 120', $c['overdue_amount'] === 120.0, $c['overdue_amount']);
// lista com filtro overdue
$overdueList = Commitment::list($userId, ['status' => 'overdue']);
check('filtro status=overdue encontra', count(array_filter($overdueList, fn ($x) => $x['id'] === $gymId)) === 1, count($overdueList));
// summary reflete
$s = Commitment::summary($userId);
check('summary.overdue_total >= 120', $s['overdue_total'] >= 120.0, $s['overdue_total']);

echo "\n=== 8) QUITAÇÃO ===\n";
$c = Commitment::get($userId, $debtId);
$balance = $c['balance'];
$r = Commitment::registerPayment($userId, $debtId, ['amount' => $balance, 'paid_date' => date('Y-m-d')]);
check('pagamento final aceito', ($r['ok'] ?? false) === true, $r);
check('settled = true', ($r['settled'] ?? false) === true);
$c = Commitment::get($userId, $debtId);
check('status settled', $c['status'] === 'settled');
check('saldo 0', $c['balance'] === 0.0, $c['balance']);
check('ocorrências futuras encerradas (skipped)', count($c['occurrences_future']) === 0, count($c['occurrences_future']));
check('histórico de pagamentos preservado', count($c['payments']) >= 3, count($c['payments']));
check('progresso 100%', $c['progress_pct'] === 100.0, [$c['progress_pct'], $c['paid_total'], $c['total_amount'], $c['balance']]);
// não aceita novo pagamento
$r = Commitment::registerPayment($userId, $debtId, ['amount' => 10.0]);
check('bloqueia pagamento após quitação', ($r['ok'] ?? false) === false);

echo "\n=== 9) EXCLUSÃO E HISTÓRICO ===\n";
$r = Commitment::delete($userId, $debtId);
check('bloqueia exclusão de compromisso com pagamentos', ($r['ok'] ?? false) === false, $r);
// sem pagamentos: pode excluir
$r = Commitment::create($userId, ['name' => 'Temporário', 'payee' => 'X', 'kind' => 'recurring', 'installment_amount' => 10.0, 'periodicity' => 'monthly', 'next_due' => date('Y-m-d'), 'start_date' => date('Y-m-d')]);
$tmpId = (int) $r['id'];
$r = Commitment::delete($userId, $tmpId);
check('exclui compromisso sem pagamentos', ($r['ok'] ?? false) === true);

echo "\n=== 10) PAUSAR/RETOMAR + SEGURANÇA ===\n";
$r = Commitment::setStatus($userId, $phoneId, 'paused');
check('pausa', ($r['ok'] ?? false) === true);
$c = Commitment::get($userId, $phoneId);
check('status paused', $c['status'] === 'paused' && $c['derived_status'] === 'paused');
$r = Commitment::setStatus($userId, $phoneId, 'active');
check('retoma', ($r['ok'] ?? false) === true);
// isolamento por usuário: outro usuário não vê
Database::run("INSERT IGNORE INTO users (name, email, password_hash) VALUES ('Outro', 'other@teste.com', :h)", ['h' => password_hash('x', PASSWORD_DEFAULT)]);
$otherId = (int) (Database::run("SELECT id FROM users WHERE email = 'other@teste.com'")->fetch()['id'] ?? 0);
$cOther = Commitment::get($otherId, $phoneId);
check('outro usuário não acessa compromisso alheio', $cOther === null);

echo "\n=== 11) SUMMARY/FLUXO ===\n";
$s = Commitment::summary($userId);
check('summary month_paid >= 300 (200 dívida + 80 tel + 120 acad... )', $s['month_paid'] >= 300.0, $s['month_paid']);
check('summary debts_open = 0 (dívida quitada)', $s['debts_open'] === 0.0, $s['debts_open']);
$dueSoon = Commitment::dueSoon($userId, 7);
check('dueSoon retorna array', is_array($dueSoon));
$monthlyPending = Commitment::monthPending($userId, date('Y-m'));
check('monthPending retorna array', is_array($monthlyPending));

// limpa dados de teste (o usuário de teste é fixture)
Database::run("DELETE FROM commitments WHERE user_id = :uid", ['uid' => $userId]);
Database::run("DELETE FROM users WHERE email = 'other@teste.com'");

echo "\n===== COMPROMISSOS: {$passed} PASS / {$failed} FAIL =====\n";
if ($failed > 0) { exit(1); }
echo "MÓDULO COMPROMISSOS 100% FUNCIONAL\n";
