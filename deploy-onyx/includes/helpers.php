<?php
/**
 * FinLife — Funções auxiliares globais.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

/** Caminho base público da aplicação (ex.: "" ou "/FinLife"). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $b = preg_replace('~/(public/)?index\.php$~', '', $script);
        $base = ($b === '/' || $b === null) ? '' : $b;
    }
    return $base;
}

/** URL absoluta interna a partir de uma rota. */
function url(string $route): string
{
    return base_path() . '/' . ltrim($route, '/');
}

/** Escapa saída HTML. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redireciona e encerra. */
function redirect(string $route): never
{
    header('Location: ' . url($route));
    exit;
}

/** Envia uma resposta JSON e encerra. */
function json_out(array $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

/** Lê e decodifica o corpo JSON da requisição. Retorna null se inválido. */
function json_input(): ?array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        // fallback: form-encoded
        if (!empty($_POST)) {
            return $_POST;
        }
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** Token CSRF da sessão. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Verifica o token CSRF (header X-CSRF-Token ou campo _csrf). */
function csrf_verify(): bool
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf'] ?? '', $sent);
}

/** Exige CSRF para requisições que alteram estado; aborta em 403 se inválido. */
function csrf_guard(): void
{
    if (!csrf_verify()) {
        json_out(['ok' => false, 'error' => 'Token CSRF inválido. Recarregue a página e tente novamente.'], 403);
    }
}

/** Formata valor monetário em Real. */
function money(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

/** Cria uma notificação in-app para o usuário (centralizada p/ todos os módulos). */
function notify_push(int $userId, string $type, string $title, string $message): void
{
    try {
        Database::run(
            'INSERT INTO notifications (user_id, type, title, message) VALUES (:uid, :type, :title, :msg)',
            ['uid' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 120), 'msg' => mb_substr($message, 0, 500)]
        );
    } catch (Throwable $e) {
        log_error('notify_push: falha', ['msg' => $e->getMessage()]);
    }
}

/** Formata percentagem. */
function pct(?float $value, int $decimals = 1): string
{
    if ($value === null) {
        return '—';
    }
    return number_format($value, $decimals, ',', '.') . '%';
}

/** Mês atual no formato YYYY-MM. */
function month_now(): string
{
    return date('Y-m');
}

/** Primeiro e último dia de um mês YYYY-MM. */
function month_bounds(string $ym): array
{
    $t = strtotime($ym . '-01');
    if ($t === false) {
        $t = time();
    }
    return [date('Y-m-01', $t), date('Y-m-t', $t)];
}

/** Lista dos últimos N meses no formato YYYY-MM (ordem crescente). */
function last_months(int $count, ?string $from = null): array
{
    $out = [];
    $base = strtotime(($from ?? date('Y-m')) . '-01');
    for ($i = $count - 1; $i >= 0; $i--) {
        $out[] = date('Y-m', strtotime("-{$i} months", $base));
    }
    return $out;
}

/** Log de erro sem segredos. */
function log_error(string $message, array $context = []): void
{
    $msg = date('Y-m-d H:i:s') . ' | ' . $message;
    foreach ($context as $k => $v) {
        // máscara valores potencialmente sensíveis
        if (is_string($v) && (preg_match('~key|token|secret|password~i', (string) $k) || preg_match('~sk-[A-Za-z0-9]{8,}~', $v))) {
            $v = '***';
        }
        $msg .= ' | ' . $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v));
    }
    @file_put_contents(LOG_PATH . '/app.log', $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** IP do cliente (usado apenas para rate limiting). */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Remove acentos e normaliza texto para comparações. */
function normalize(string $text): string
{
    $t = mb_strtolower(trim($text), 'UTF-8');
    $t = preg_replace('~[áàâãä]~u', 'a', $t) ?? $t;
    $t = preg_replace('~[éèêë]~u', 'e', $t) ?? $t;
    $t = preg_replace('~[íìîï]~u', 'i', $t) ?? $t;
    $t = preg_replace('~[óòôõö]~u', 'o', $t) ?? $t;
    $t = preg_replace('~[úùûü]~u', 'u', $t) ?? $t;
    $t = preg_replace('~[ç]~u', 'c', $t) ?? $t;
    return trim($t);
}
