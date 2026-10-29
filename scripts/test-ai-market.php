<?php
/** Teste das respostas da IA sobre mercados (dados reais). */
declare(strict_types=1);
define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

Database::run('DELETE FROM rate_limits');
$user = User::findByEmail('redesign@teste.com');
$uid = (int) $user['id'];

$questions = [
    'Como está o mercado hoje?',
    'Quanto está o bitcoin?',
    'Quanto subiu o ibovespa?',
    'Quanto está o dólar?',
    'Quanto estou ganhando com dividendos?',
    'Qual ação da minha watchlist mais subiu?',
];
foreach ($questions as $q) {
    $snap = ContextBuilder::snapshot($uid);
    $r = LocalNlu::handle($uid, $q, $snap);
    echo 'P: ' . $q . "\nR: " . $r['reply'] . "\n\n";
}
