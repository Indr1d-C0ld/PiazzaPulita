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

    /**
     * Quante mani si bruciano alla semina.
     *
     * **Senza questo, il primo numero è spazzatura**, e non si vede: la
     * sequenza lunga è ottima, ma il PRIMO valore dopo la semina no. Un seme
     * che sta in 32 bit ha i bit alti a zero, `reale()` legge i 53 bit alti, e
     * alla prima mano quei bit non sono ancora stati toccati da niente. Misurato
     * su 200.000 semine consecutive: media 0,125 invece di 0,5, e **nessun
     * valore sopra 0,25**.
     *
     * Conta perché mezzo gioco semina un generatore per estrarne un numero solo
     * (un carico in quell'ora, il rumore del prezzo a quel passo): quella è
     * sempre e solo la prima mano. Bastava una mano per raddrizzare la
     * distribuzione; se ne bruciano tre perché costano niente.
     */
    private const RISCALDAMENTO = 3;

    public function __construct(int $seme)
    {
        // Lo zero è l'unico stato da cui xorshift non esce più.
        $this->stato = $seme === 0 ? 0x2545F4914F6CDD1D : $seme;
        for ($i = 0; $i < self::RISCALDAMENTO; $i++) {
            $this->successivo();
        }
    }

    /**
     * Costruisce un generatore da un seme di mondo e da un'etichetta: due
     * flussi con etichette diverse non si influenzano a vicenda, e ognuno è
     * ripetibile per conto suo.
     */
    public static function da(int $seme, string $etichetta): self
    {
        // crc32 è deterministico, veloce, e sta nei 32 bit: niente traboccamenti.
        // Se ne prendono DUE, di cui uno spostato in alto, così il seme riempie
        // tutti e 64 i bit invece che solo i primi 32 — il riscaldamento fa il
        // resto, ma partire già larghi non costa niente.
        $a = (int) crc32($etichetta);
        $b = (int) crc32($etichetta . "\x1f");
        return new self($seme ^ $a ^ ($b << 32));
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
