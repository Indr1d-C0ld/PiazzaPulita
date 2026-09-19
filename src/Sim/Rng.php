<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Generatore pseudocasuale deterministico (xorshift64).
 *
 * Serve perché il mondo dev'essere riproducibile: dati lo stesso seme e lo
 * stesso istante, la stessa piazza deve rifornirsi allo stesso modo su
 * qualunque processo — il battito da cron e la richiesta web sono processi
 * diversi e devono concordare, o le giacenze divergono.
 *
 * **Niente moltiplicazioni a 64 bit.** In PHP un intero è a 64 bit con segno e
 * una moltiplicazione che trabocca diventa silenziosamente un float: il
 * determinismo salta e non se ne accorge nessuno finché due processi non
 * calcolano due mondi diversi. xorshift64 usa soltanto scorrimenti e xor, ed è
 * scelto esattamente per questo.
 *
 * L'altra insidia è lo scorrimento a destra: in PHP `>>` propaga il bit di
 * segno, mentre xorshift ne vuole uno logico. Senza la maschera di `srl()` la
 * sequenza degenera appena lo stato diventa negativo — cioè quasi subito.
 */
final class Rng
{
    private int $stato;

    public function __construct(int $seme)
    {
        // Lo zero è l'unico stato da cui xorshift non esce più.
        $this->stato = $seme === 0 ? 0x2545F4914F6CDD1D : $seme;
    }

    /**
     * Costruisce un generatore da un seme di mondo e da un'etichetta: due
     * flussi con etichette diverse non si influenzano a vicenda, e ognuno è
     * ripetibile per conto suo.
     */
    public static function da(int $seme, string $etichetta): self
    {
        // crc32 è deterministico, veloce, e sta nei 32 bit: niente traboccamenti.
        return new self($seme ^ (int) crc32($etichetta));
    }

    /** Scorrimento a destra logico: senza questo, xorshift si spegne. */
    private static function srl(int $x, int $n): int
    {
        return ($x >> $n) & (PHP_INT_MAX >> ($n - 1));
    }

    public function successivo(): int
    {
        $x = $this->stato;
        $x ^= $x << 13;              // i bit che escono in cima si perdono: resta un int
        $x ^= self::srl($x, 7);
        $x ^= $x << 17;
        return $this->stato = $x;
    }

    /** Un reale in [0;1). */
    public function reale(): float
    {
        // 53 bit: quanti ne regge la mantissa di un double senza perderne nessuno.
        return self::srl($this->successivo(), 11) / 9007199254740992.0;
    }

    /** Un intero in [$da; $a], estremi compresi. */
    public function intero(int $da, int $a): int
    {
        if ($a <= $da) {
            return $da;
        }
        return $da + (int) floor($this->reale() * ($a - $da + 1));
    }

    /** Un reale in [$da; $a). */
    public function fra(float $da, float $a): float
    {
        return $da + $this->reale() * ($a - $da);
    }

    /**
     * Normale standard (Box-Muller). Servirà alla passeggiata dei prezzi di
     * riferimento in F2.
     */
    public function normale(): float
    {
        $u1 = max(1e-12, $this->reale());
        $u2 = $this->reale();
        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }
}
