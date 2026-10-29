<?php
/**
 * FinLife — Limitação de requisições (brute force de login, abuso da IA).
 * Implementação simples com tabela no banco: conta eventos por
 * identificador dentro de uma janela de tempo.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class RateLimiter
{
    /**
     * Registra um evento e informa se o limite foi ultrapassado.
     *
     * @param string $identifier  ex.: "login:ip:1.2.3.4" ou "ai:user:7"
     * @param int    $max        máximo de eventos na janela
     * @param int    $window     janela em segundos
     */
    public static function hit(string $identifier, int $max, int $window): bool
    {
        $since = date('Y-m-d H:i:s', time() - $window);

        $count = Database::run(
            'SELECT COUNT(*) AS c FROM rate_limits WHERE identifier = :id AND created_at > :since',
            ['id' => $identifier, 'since' => $since]
        )->fetch();

        $total = (int) ($count['c'] ?? 0);
        if ($total >= $max) {
            return true; // excedeu
        }

        Database::run(
            'INSERT INTO rate_limits (identifier, created_at) VALUES (:id, NOW())',
            ['id' => $identifier]
        );

        // limpeza periódica (remove registros com mais de 1 dia)
        if ($total > 0 && $total % 100 === 0) {
            Database::run('DELETE FROM rate_limits WHERE created_at < :cut', ['cut' => date('Y-m-d H:i:s', time() - 86400)]);
        }
        return false;
    }

    /** Verifica o limite sem registrar novo evento. */
    public static function exceeded(string $identifier, int $max, int $window): bool
    {
        $since = date('Y-m-d H:i:s', time() - $window);
        $count = Database::run(
            'SELECT COUNT(*) AS c FROM rate_limits WHERE identifier = :id AND created_at > :since',
            ['id' => $identifier, 'since' => $since]
        )->fetch();
        return (int) ($count['c'] ?? 0) >= $max;
    }
}
