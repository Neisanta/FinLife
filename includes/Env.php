<?php
/**
 * FinLife — Carregador de variáveis de ambiente (.env)
 * Nada aqui expõe segredos ao navegador: arquivo apenas server-side.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): bool
    {
        self::$loaded = true;
        if (!is_file($path)) {
            return false;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // remove aspas opcionais
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[strlen($val) - 1] === $val[0]) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '') {
                self::$vars[$key] = $val;
            }
        }
        return true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$vars)) {
            $v = self::$vars[$key];
            return ($v === '') ? $default : $v;
        }
        // Fallback: variável real do ambiente (putenv/variáveis do sistema)
        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return ($v === null) ? $default : (int) $v;
    }

    public static function float(string $key, float $default): float
    {
        $v = self::get($key);
        return ($v === null) ? $default : (float) str_replace(',', '.', $v);
    }

    public static function bool(string $key, bool $default): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
}
