<?php
/** Teste: NVIDIA NIM API + resposta consultiva "qual melhor investimento?". */
declare(strict_types=1);
define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

Database::run('DELETE FROM rate_limits');
$user = User::findByEmail('redesign@teste.com');
$uid = (int) $user['id'];

echo "=== 1) Configuração da IA ===\n";
$ai = new AIService();
echo 'configured: ' . var_export($ai->isConfigured(), true) . "\n";
echo 'model: ' . $ai->model() . "\n";
echo 'url: ' . Env::get('AI_API_URL') . "\n";
echo 'key (mascarada): ' . substr(Env::get('AI_API_KEY', ''), 0, 8) . '...' . substr(Env::get('AI_API_KEY', ''), -4) . "\n\n";

echo "=== 2) Conectividade NVIDIA NIM ===\n";
$snap = ContextBuilder::snapshot($uid);
$system = ContextBuilder::systemPrompt(ContextBuilder::snapshotText($snap));
$res = $ai->chat([
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => 'qual melhor investimento?'],
]);
if (!$res['ok']) {
    echo 'FALHOU: ' . ($res['error'] ?? 'desconhecido') . "\n";
    exit(1);
}
echo "Resposta (primeiros 400 chars):\n" . mb_substr($res['content'], 0, 400) . "...\n\n";

echo "=== 3) Resposta completa via AiAssistant (com validação de actions) ===\n";
$r = AiAssistant::message($user, 'qual melhor investimento?');
echo 'ok: ' . var_export($r['ok'], true) . "\n";
echo 'source: ' . ($r['source'] ?? '?') . "\n";
echo 'reply (primeiros 300 chars): ' . mb_substr($r['reply'] ?? '', 0, 300) . "...\n\n";

echo "=== 4) Resposta via LocalNlu (fallback local) ===\n";
$local = LocalNlu::handle($uid, 'qual melhor investimento?', $snap);
$reply = is_string($local['reply'] ?? null) ? $local['reply'] : ($local['reply']['reply'] ?? '');
echo 'reply (primeiros 300 chars): ' . mb_substr($reply, 0, 300) . "...\n\n";

echo "=== 5) Outras perguntas consultivas ===\n";
foreach (['onde investir meu dinheiro?', 'como comecar a investir?', 'o que e CDI?'] as $q) {
    $r2 = AiAssistant::message($user, $q);
    $src = $r2['source'] ?? '?';
    echo 'Q: "' . $q . '" [source=' . $src . ']: ' . mb_substr($r2['reply'] ?? '', 0, 120) . "...\n";
    Database::run('DELETE FROM rate_limits');
}

echo "\nTUDO FUNCIONANDO\n";
