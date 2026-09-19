<?php

declare(strict_types=1);

namespace App\Sim;

use DateTimeImmutable;
use DateTimeZone;

/**
 * L'ora del mondo.
 *
 * Il tempo di Piazza Pulita è quello vero, uno a uno (decisione 2): questa
 * classe non comprime niente e non lo farà mai. Esiste per un solo motivo, che
 * però è decisivo: **rendere "adesso" un valore iniettabile**. Senza di lei una
 * prova sugli arrivi dovrebbe aspettare davvero venti minuti; con lei si sposta
 * l'orologio e si guarda cosa succede.
 *
 * L'unica compressione del gioco è sui *tempi di percorrenza*, e sta in
 * Viaggio: è una scelta di giocabilità dichiarata, non un orologio diverso.
 */
final class Clock
{
    private static ?DateTimeImmutable $fissato = null;

    public static function adesso(): DateTimeImmutable
    {
        if (self::$fissato !== null) {
            return self::$fissato;
        }
        return new DateTimeImmutable('now', self::fuso());
    }

    /** Millisecondi dall'epoca: comodo per i conti sugli arrivi. */
    public static function ms(): int
    {
        return (int) round((float) self::adesso()->format('U.u') * 1000);
    }

    /** Formato DATETIME(3) di MariaDB. */
    public static function perDb(?DateTimeImmutable $t = null): string
    {
        return ($t ?? self::adesso())->format('Y-m-d H:i:s.v');
    }

    public static function daDb(?string $s): ?DateTimeImmutable
    {
        if ($s === null || $s === '' || str_starts_with($s, '0000-00-00')) {
            return null;
        }
        return new DateTimeImmutable($s, self::fuso());
    }

    /** Solo per le prove: fissa l'orologio. Vietato fuori da riga di comando. */
    public static function fissa(?DateTimeImmutable $t): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('L\'orologio si ferma solo da riga di comando.');
        }
        self::$fissato = $t;
    }

    /** Sposta l'orologio fissato in avanti di N secondi (solo prove). */
    public static function avanzaDi(int $secondi): void
    {
        self::fissa(self::adesso()->modify("+{$secondi} seconds"));
    }

    private static function fuso(): DateTimeZone
    {
        static $tz = null;
        return $tz ??= new DateTimeZone((string) \App\Core\Config::get('app.timezone', 'Europe/Rome'));
    }
}
