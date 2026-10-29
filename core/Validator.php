<?php
/**
 * FinLife — Validação e sanitização de entrada.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Validator
{
    /** Texto seguro: trim, sem tags, sem caracteres de controle. */
    public static function text(mixed $value, int $max = 255): string
    {
        if (!is_string($value)) {
            return '';
        }
        $v = trim($value);
        $v = strip_tags($v);
        $v = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $v) ?? $v;
        return mb_substr($v, 0, $max);
    }

    public static function email(mixed $value): string
    {
        $v = mb_strtolower(trim((string) $value));
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
    }

    /** Valor monetário: retorna float >= 0.01 ou null. */
    public static function amount(mixed $value): ?float
    {
        if (is_int($value)) {
            $value = (float) $value;
        }
        if (!is_string($value) && !is_float($value)) {
            return null;
        }
        if (is_string($value)) {
            $v = str_replace(['R$', ' '], '', trim($value));
            $v = str_replace(',', '.', $v);
            if (!is_numeric($v)) {
                return null;
            }
            $value = (float) $v;
        }
        $value = round((float) $value, 2);
        return ($value > 0 && $value <= 99999999.99) ? $value : null;
    }

    /** Data YYYY-MM-DD válida. */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y) ? $value : null;
    }

    /** Cor hexadecimal #rrggbb ou null. */
    public static function color(mixed $value): ?string
    {
        $v = trim((string) $value);
        return preg_match('~^#[0-9a-fA-F]{6}$~', $v) ? strtoupper($v) : null;
    }

    /** Valor dentro de uma lista permitida. */
    public static function in(mixed $value, array $allowed): ?string
    {
        return (is_string($value) && in_array($value, $allowed, true)) ? $value : null;
    }

    /** Número inteiro positivo. */
    public static function positiveInt(mixed $value): ?int
    {
        $i = filter_var($value, FILTER_VALIDATE_INT);
        return ($i !== false && $i > 0) ? $i : null;
    }
}
