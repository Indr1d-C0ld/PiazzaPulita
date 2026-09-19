<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function check(?string $candidate): bool
    {
        $stored = $_SESSION[self::KEY] ?? null;
        return is_string($stored)
            && is_string($candidate)
            && $candidate !== ''
            && hash_equals($stored, $candidate);
    }

    public static function field(): string
    {
        $t = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_token" value="' . $t . '">';
    }
}
