<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use GdImage;

/**
 * Il volto del giocatore, che sta sulla vetrina pubblica del profilo.
 *
 * **Quello che si carica non è quello che si serve.** Il file portato da casa
 * non arriva mai al disco: viene riaperto con GD, ritagliato nel quadrato che
 * l'utente ha scelto col riquadro, riscalato e riscritto in WebP. Quello che
 * finisce in `assets/img/avatar/` è un'immagine costruita qui dentro, con un
 * formato che decidiamo noi. È l'unico modo serio di accettare immagini da
 * sconosciuti: un JPEG con dentro del PHP resta un JPEG finché qualcuno non lo
 * serve come PHP, e la via più sicura è non conservare mai l'originale.
 *
 * Il tipo si decide guardando i byte, non l'estensione né quello che dichiara
 * il browser: tutti e due se li sceglie chi carica.
 *
 * Il nome del file è l'impronta sha256 del risultato, quindi non è indovinabile
 * e due immagini identiche non occupano il disco due volte.
 */
final class Avatar
{
    private const DIR = 'assets/img/avatar';
    private const URL = 'img/avatar';

    /** Il lato dell'immagine finale. Abbastanza per uno schermo fitto, non di più. */
    public const LATO = 320;

    private const PESO_MAX = 6_291_456;          // 6 MB in ingresso
    private const LATO_MIN = 96;                  // sotto, non c'è niente da ritagliare
    private const LATO_MAX = 8000;

    private const FORMATI = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpeg',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** L'indirizzo dell'avatar di un utente, o null se non ne ha. */
    public static function url(?string $file): ?string
    {
        return $file === null || $file === '' ? null : self::URL . '/' . basename($file);
    }

    /**
     * Prende il file caricato, ritaglia il quadrato scelto e lo salva.
     *
     * Il ritaglio arriva dal riquadro come rettangolo in pixel dell'immagine
     * ORIGINALE — `sx`, `sy`, `lato` — e non come «zoom e spostamento». È una
     * scelta precisa: il client e il server devono parlare della stessa cosa in
     * modo che il server possa controllarla da solo. Un ritaglio fuori dai bordi
     * viene riportato dentro qui, senza fidarsi di niente.
     *
     * @param array<string,mixed> $file la voce di $_FILES
     * @return array{ok:bool,error?:string}
     */
    public static function carica(int $userId, array $file, int $sx, int $sy, int $lato, string $radice): array
    {
        $errore = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errore === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Non hai scelto nessuna fotografia.'];
        }
        if ($errore === UPLOAD_ERR_INI_SIZE || $errore === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'error' => 'La fotografia è troppo pesante.'];
        }
        if ($errore !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Caricamento non riuscito (codice ' . $errore . ').'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'File non valido.'];
        }
        if ((int) ($file['size'] ?? 0) > self::PESO_MAX) {
            return ['ok' => false, 'error' => 'La fotografia supera i 6 MB.'];
        }

        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::FORMATI[$info[2]])) {
            return ['ok' => false, 'error' => 'Serve un JPEG, un PNG o un WebP. '
                . 'Gli SVG non si accettano: possono contenere codice.'];
        }
        [$larghezza, $altezza] = $info;
        if ($larghezza < self::LATO_MIN || $altezza < self::LATO_MIN) {
            return ['ok' => false, 'error' => 'Fotografia troppo piccola: almeno '
                . self::LATO_MIN . ' pixel per lato.'];
        }
        if ($larghezza > self::LATO_MAX || $altezza > self::LATO_MAX) {
            return ['ok' => false, 'error' => 'Fotografia troppo grande: non oltre '
                . self::LATO_MAX . ' pixel per lato.'];
        }

        // Il ritaglio si riporta dentro i bordi qualunque cosa sia arrivato.
        // Se non è arrivato niente di sensato si centra sul lato corto, un po'
        // più in alto del centro geometrico: in un ritratto la testa sta in
        // alto, e tagliare dal centro decapita.
        $maxLato = min($larghezza, $altezza);
        if ($lato < 16 || $lato > $maxLato) {
            $lato = $maxLato;
            $sx = (int) (($larghezza - $lato) / 2);
            $sy = (int) max(0, ($altezza - $lato) * 0.18);
        }
        $sx = max(0, min($sx, $larghezza - $lato));
        $sy = max(0, min($sy, $altezza - $lato));

        $sorgente = match (self::FORMATI[$info[2]]) {
            'png'  => @imagecreatefrompng($tmp),
            'jpeg' => @imagecreatefromjpeg($tmp),
            'webp' => @imagecreatefromwebp($tmp),
        };
        if (!$sorgente instanceof GdImage) {
            return ['ok' => false, 'error' => 'Non riesco ad aprire questa immagine.'];
        }

        $out = imagecreatetruecolor(self::LATO, self::LATO);
        // Fondo pieno: un PNG trasparente ritagliato lascerebbe buchi neri.
        imagefilledrectangle($out, 0, 0, self::LATO, self::LATO, imagecolorallocate($out, 233, 228, 216));
        imagecopyresampled($out, $sorgente, 0, 0, $sx, $sy, self::LATO, self::LATO, $lato, $lato);
        imagedestroy($sorgente);

        $temporaneo = tempnam(sys_get_temp_dir(), 'avat');
        imagewebp($out, $temporaneo, 86);
        imagedestroy($out);

        $hash = hash_file('sha256', $temporaneo);
        $nome = $hash . '.webp';
        $dir  = rtrim($radice, '/') . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            @unlink($temporaneo);
            return ['ok' => false, 'error' => 'Non riesco a scrivere la cartella delle fotografie.'];
        }

        $vecchio = Database::first('SELECT avatar_file FROM users WHERE id = ?', [$userId]);

        // Si scrive prima la riga, poi il file: se il database rifiuta, sul
        // disco non resta niente di orfano.
        Database::run('UPDATE users SET avatar_file = ?, avatar_hash = ?, avatar_at = NOW() WHERE id = ?',
            [$nome, $hash, $userId]);

        @rename($temporaneo, $dir . '/' . $nome);
        @chmod($dir . '/' . $nome, 0664);

        self::rimuoviFile($radice, $vecchio['avatar_file'] ?? null, $nome);

        return ['ok' => true];
    }

    public static function togli(int $userId, string $radice): void
    {
        $r = Database::first('SELECT avatar_file FROM users WHERE id = ?', [$userId]);
        Database::run('UPDATE users SET avatar_file = NULL, avatar_hash = NULL, avatar_at = NULL WHERE id = ?', [$userId]);
        self::rimuoviFile($radice, $r['avatar_file'] ?? null, null);
    }

    /**
     * Toglie dal disco un'immagine che non serve più — ma solo se non la sta
     * usando nessun altro. Il nome è l'impronta del contenuto, quindi due
     * persone che caricano la stessa fotografia condividono lo stesso file:
     * cancellarlo perché uno dei due l'ha cambiata lascerebbe l'altro senza.
     */
    private static function rimuoviFile(string $radice, ?string $file, ?string $tranne): void
    {
        if ($file === null || $file === '' || $file === $tranne) {
            return;
        }
        $ancora = Database::first('SELECT COUNT(*) n FROM users WHERE avatar_file = ?', [$file]);
        if ((int) ($ancora['n'] ?? 0) > 0) {
            return;
        }
        $percorso = rtrim($radice, '/') . '/' . self::DIR . '/' . basename($file);
        if (is_file($percorso)) {
            @unlink($percorso);
        }
    }
}
