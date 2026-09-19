<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Impostazioni di gioco lette da game_config (modificabili a caldo, senza deploy).
 * Cache per richiesta: una sola query per tutta la vita del processo.
 */
final class GameConfig
{
    /** @var array<string,array{value:string,type:string,note:?string}>|null */
    private static ?array $cache = null;

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = [];
        try {
            foreach (Database::all('SELECT ckey, cvalue, ctype, note FROM game_config ORDER BY ckey') as $r) {
                $rows[(string) $r['ckey']] = [
                    'value' => (string) $r['cvalue'],
                    'type'  => (string) $r['ctype'],
                    'note'  => $r['note'] === null ? null : (string) $r['note'],
                ];
            }
        } catch (\Throwable) {
            // Prima delle migrazioni la tabella non esiste: si usano i default.
        }
        return self::$cache = $rows;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = self::load()[$key] ?? null;
        if ($row === null) {
            return $default;
        }
        return match ($row['type']) {
            'int'   => (int) $row['value'],
            'float' => (float) $row['value'],
            'bool'  => in_array(strtolower($row['value']), ['1', 'true', 'si', 'yes', 'on'], true),
            'json'  => json_decode($row['value'], true) ?? $default,
            default => $row['value'],
        };
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    public static function set(string $key, string $value, string $type = 'string', ?string $note = null): void
    {
        Database::run(
            'INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype),
                                     note = COALESCE(VALUES(note), note)',
            [$key, $value, $type, $note]
        );
        self::$cache = null;
    }

    /** @return array<string,array{value:string,type:string,note:?string}> */
    public static function all(): array
    {
        return self::load();
    }
}
