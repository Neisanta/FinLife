<?php
/**
 * FinLife — Model de usuários.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class User
{
    public static function findByEmail(string $email): ?array
    {
        $row = Database::run(
            'SELECT id, name, email, password_hash, currency, monthly_budget, ai_chat_enabled
             FROM users WHERE email = :email LIMIT 1',
            ['email' => $email]
        )->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $row = Database::run(
            'SELECT id, name, email, currency, monthly_budget, ai_chat_enabled, created_at
             FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        )->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $email, string $passwordHash): int
    {
        Database::run(
            'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)',
            ['name' => $name, 'email' => $email, 'hash' => $passwordHash]
        );
        return Database::lastId();
    }

    public static function updatePassword(int $id, string $passwordHash): void
    {
        Database::run('UPDATE users SET password_hash = :hash WHERE id = :id', ['id' => $id, 'hash' => $passwordHash]);
    }

    /** Define (ou limpa) o orçamento mensal do usuário. */
    public static function setMonthlyBudget(int $id, ?float $budget): void
    {
        Database::run('UPDATE users SET monthly_budget = :b WHERE id = :id', ['id' => $id, 'b' => $budget]);
    }
}
