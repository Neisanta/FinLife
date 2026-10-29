<?php
/** Cria o usuário de teste redesign@teste.com («senha-do-usuário-de-teste») se não existir. */
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

$existing = User::findByEmail('redesign@teste.com');
if ($existing !== null) {
    echo 'usuário já existe (id ' . $existing['id'] . ")\n";
    exit(0);
}
$id = User::create('Maria Designer', 'redesign@teste.com', password_hash($testPassword, PASSWORD_DEFAULT));
echo 'usuário criado id=' . $id . "\n";
