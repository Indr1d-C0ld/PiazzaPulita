<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Configurazione applicativa.
 *
 * Ordine di ricerca del file config:
 *   1. env PIAZZAPULITA_CONFIG
 *   2. /etc/piazzapulita/config.php
 *   3. /data/piazzapulita-config/config.php     (default, fuori dal DocumentRoot)
 *   4. <project>/config/config.php              (fallback di sviluppo)
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $data = [];
    private static bool $loaded = false;
    private static string $sourceFile = '';

    public static function load(string $projectRoot): void
    {
        if (self::$loaded) {
            return;
        }

        $candidates = array_values(array_filter([
            getenv('PIAZZAPULITA_CONFIG') ?: null,
            '/etc/piazzapulita/config.php',
            '/data/piazzapulita-config/config.php',
            $projectRoot . '/config/config.php',
        ]));

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                /** @var array<string,mixed> $cfg */
                $cfg = require $path;
                if (!is_array($cfg)) {
                    throw new RuntimeException("Config non valida in {$path}: atteso array.");
                }
                self::$data = $cfg;
                self::$sourceFile = $path;
                self::$loaded = true;
                return;
            }
        }

        throw new RuntimeException(
            "Nessun file di configurazione trovato. Cercati:\n  - " . implode("\n  - ", $candidates)
        );
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    public static function sourceFile(): string
    {
        return self::$sourceFile;
    }

    /** Accesso con dot-notation: Config::get('db.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }
}
