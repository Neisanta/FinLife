<?php
/**
 * FinLife — Conexão com o banco (PDO + prepared statements).
 * Todas as queries da aplicação passam por aqui; nenhuma string
 * de usuário é concatenada em SQL.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::int('DB_PORT', 3306);
            $name = Env::get('DB_NAME', 'finlife');
            $user = Env::get('DB_USER', 'root');
            $pass = Env::get('DB_PASS', '');

            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

            try {
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES  => false,
                ]);
            } catch (PDOException $e) {
                log_error('Falha na conexão com o banco', ['msg' => $e->getMessage()]);
                throw new RuntimeException('Não foi possível conectar ao banco de dados.');
            }
        }
        return self::$pdo;
    }

    /** Executa uma query preparada e retorna o statement. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        foreach ($params as $key => $value) {
            $key = (str_starts_with($key, ':')) ? $key : ':' . $key;
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function lastId(): int
    {
        return (int) self::conn()->lastInsertId();
    }
}
