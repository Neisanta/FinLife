<?php
/**
 * FinLife — Autenticação e sessão.
 *
 * - Senhas com password_hash (bcrypt)
 * - Sessão HttpOnly + SameSite=Lax, regeneração do ID no login
 * - Fingerprint de User-Agent (detecta roubo de cookie)
 * - Expiração por inatividade
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Auth
{
    private static ?array $cachedUser = null;

    /** Registra um novo usuário (validações básicas aqui; API valida o resto). */
    public static function register(string $name, string $email, string $password): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return ['ok' => false, 'error' => 'Informe seu nome completo (2 a 120 caracteres).'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Informe um e-mail válido.'];
        }
        if (strlen($password) < 8 || strlen($password) > 100) {
            return ['ok' => false, 'error' => 'A senha deve ter no mínimo 8 caracteres.'];
        }
        if (User::findByEmail($email) !== null) {
            return ['ok' => false, 'error' => 'Este e-mail já está cadastrado.'];
        }

        $userId = User::create($name, $email, password_hash($password, PASSWORD_DEFAULT));

        // Seed inicial: categorias padrão + carteira de dinheiro
        Category::seedDefaults($userId);
        Account::create($userId, [
            'name'            => 'Carteira',
            'type'            => 'cash',
            'institution'     => 'Dinheiro físico',
            'initial_balance' => 0.0,
            'color'           => '#f59e0b',
        ]);

        return ['ok' => true, 'user_id' => $userId];
    }

    /** Verifica credenciais. */
    public static function attempt(string $email, string $password): array|false
    {
        $email = mb_strtolower(trim($email));
        $user = User::findByEmail($email);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        // rehash automático se o custo padrão mudar
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            User::updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        return $user;
    }

    /** Inicia a sessão do usuário. */
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['ua_hash'] = sha1($_SERVER['HTTP_USER_AGENT'] ?? '');
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$cachedUser = null;
    }

    /** Encerra a sessão. */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Sessão válida? */
    public static function check(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        // fingerprint do User-Agent
        $current = sha1($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (!empty($_SESSION['ua_hash']) && !hash_equals($_SESSION['ua_hash'], $current)) {
            self::logout();
            return false;
        }
        return true;
    }

    /** Dados do usuário autenticado (consulta o banco) ou null. */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        if (!self::check()) {
            return null;
        }
        $user = User::findById((int) $_SESSION['user_id']);
        if ($user === null) {
            self::logout();
            return null;
        }
        self::$cachedUser = $user;
        return $user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    /** Exige sessão em páginas (redireciona para /login). */
    public static function requirePage(): array
    {
        $user = self::user();
        if ($user === null) {
            redirect('login');
        }
        return $user;
    }


}
