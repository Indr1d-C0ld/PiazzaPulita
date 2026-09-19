<?php

declare(strict_types=1);

/**
 * Uso interno: disegna le icone PNG dell'app a partire dallo stesso segno del
 * lampione che sta in assets/img/icona.svg.
 *
 *   php bin/_icone.php
 *
 * Non usa librerie SVG: il segno è fatto di quattro forme geometriche, e
 * ridisegnarle con GD costa meno che portarsi dietro un rasterizzatore. Se un
 * giorno l'icona diventa complicata, questo script va buttato e sostituito con
 * un PNG disegnato a mano — non fatto crescere.
 */

$fondo  = [0x1c, 0x1a, 0x16];
$ambra  = [0xd9, 0xa4, 0x41];
$carta  = [0xe9, 0xe4, 0xd8];
$rosso  = [0xa5, 0x28, 0x1d];

/** Disegna il segno su una tela quadrata di lato $lato. $margine = zona di sicurezza. */
function segno(int $lato, float $margine, array $c): \GdImage
{
    [$fondo, $ambra, $carta, $rosso] = $c;
    $im = imagecreatetruecolor($lato, $lato);
    imageantialias($im, true);
    $col = static fn(array $r) => imagecolorallocate($im, $r[0], $r[1], $r[2]);

    imagefilledrectangle($im, 0, 0, $lato, $lato, $col($fondo));

    // Tutto il resto è in coordinate 0-64, come nell'SVG, poi scalato.
    $k = $lato * (1 - 2 * $margine) / 64.0;
    $o = $lato * $margine;
    $x = static fn(float $v) => (int) round($o + $v * $k);

    imagefilledellipse($im, $x(32), $x(24), (int) round(22 * $k), (int) round(22 * $k), $col($ambra));

    imagesetthickness($im, max(1, (int) round(3 * $k)));
    imageline($im, $x(32), $x(35), $x(32), $x(52), $col($carta));
    imagesetthickness($im, max(1, (int) round(4 * $k)));
    imageline($im, $x(22), $x(52), $x(42), $x(52), $col($rosso));

    return $im;
}

$c = [$fondo, $ambra, $carta, $rosso];
$dir = dirname(__DIR__) . '/assets/img';

foreach ([[192, 0.10, 'icona-192.png'], [512, 0.10, 'icona-512.png'],
          // La «maskable» viene ritagliata a piacere dal sistema: il segno sta
          // dentro il 60% centrale, così nessun ritaglio lo mangia.
          [512, 0.20, 'icona-maskable.png']] as [$lato, $margine, $nome]) {
    $im = segno($lato, $margine, $c);
    imagepng($im, $dir . '/' . $nome, 9);
    imagedestroy($im);
    echo "  {$nome}  ({$lato}x{$lato})\n";
}
