<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Client SMTP minimale (no dipendenze): STARTTLS su 587 o TLS implicito su
 * 465, AUTH LOGIN. Solo testo semplice, un destinatario per invio — quanto
 * basta per le notifiche interne.
 *
 * Config attesa (chiave 'mail'):
 *   transport   'smtp' | 'log'   ('log' non invia: scrive su storage/logs)
 *   smtp_host, smtp_port, smtp_secure ('tls' | 'ssl')
 *   smtp_user, smtp_pass
 *   from_email  (deve essere un mittente verificato dal provider), from_name
 */
final class Mailer
{
    /** @return array{ok:bool, error?:string} */
    public static function send(string $toEmail, string $subject, string $textBody): array
    {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'destinatario non valido'];
        }

        $transport = (string) Config::get('mail.transport', 'log');
        $from = (string) Config::get('mail.from_email', 'noreply@localhost');
        $fromName = (string) Config::get('mail.from_name', 'Piazza Pulita');

        if ($transport === 'log' || $transport === 'none') {
            logger(sprintf("MAIL (log) to=%s subj=%s\n%s", $toEmail, $subject, $textBody), 'info');
            return ['ok' => true];
        }
        if ($transport !== 'smtp') {
            return ['ok' => false, 'error' => "transport sconosciuto: {$transport}"];
        }

        $host = (string) Config::get('mail.smtp_host', '');
        $port = (int) Config::get('mail.smtp_port', 587);
        $secure = (string) Config::get('mail.smtp_secure', 'tls');
        $user = (string) Config::get('mail.smtp_user', '');
        $pass = (string) Config::get('mail.smtp_pass', '');
        if ($host === '') {
            return ['ok' => false, 'error' => 'mail.smtp_host mancante'];
        }

        $ehlo = gethostname() ?: 'piazzapulita.local';
        $timeout = (int) Config::get('mail.timeout', 15);

        $transportUri = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client($transportUri, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            return ['ok' => false, 'error' => "connessione fallita: {$errstr} ({$errno})"];
        }
        stream_set_timeout($fp, $timeout);

        try {
            self::expect($fp, 220);

            self::cmd($fp, "EHLO {$ehlo}");
            self::readReply($fp); // 250-...

            if ($secure === 'tls') {
                self::cmd($fp, 'STARTTLS');
                self::expectReply($fp, 220);
                $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($ok !== true) {
                    throw new \RuntimeException('STARTTLS: handshake fallito');
                }
                self::cmd($fp, "EHLO {$ehlo}");
                self::readReply($fp);
            }

            self::cmd($fp, 'AUTH LOGIN');
            self::expectReply($fp, 334);
            self::cmd($fp, base64_encode($user));
            self::expectReply($fp, 334);
            self::cmd($fp, base64_encode($pass));
            [$code] = self::readReply($fp);
            if ($code !== 235) {
                throw new \RuntimeException("autenticazione rifiutata ({$code})");
            }

            self::cmd($fp, 'MAIL FROM:<' . $from . '>');
            self::expectReply($fp, 250);
            self::cmd($fp, 'RCPT TO:<' . $toEmail . '>');
            [$code] = self::readReply($fp);
            if ($code !== 250 && $code !== 251) {
                throw new \RuntimeException("destinatario rifiutato ({$code})");
            }

            self::cmd($fp, 'DATA');
            self::expectReply($fp, 354);

            $data = self::buildMessage($from, $fromName, $toEmail, $subject, $textBody);
            fwrite($fp, $data . "\r\n.\r\n");
            [$code] = self::readReply($fp);
            if ($code !== 250) {
                throw new \RuntimeException("consegna rifiutata ({$code})");
            }

            self::cmd($fp, 'QUIT');
        } catch (\Throwable $e) {
            @fclose($fp);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        @fclose($fp);
        return ['ok' => true];
    }

    private static function buildMessage(string $from, string $fromName, string $to, string $subject, string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = [];
        foreach (explode("\n", $body) as $ln) {
            $lines[] = (isset($ln[0]) && $ln[0] === '.') ? '.' . $ln : $ln; // dot-stuffing
        }
        $body = implode("\r\n", $lines);

        $encName = self::encodeHeader($fromName);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($encName !== '' ? "{$encName} <{$from}>" : $from),
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (gethostname() ?: 'piazzapulita') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function encodeHeader(string $s): string
    {
        if ($s === '') {
            return '';
        }
        return preg_match('/[^\x20-\x7E]/', $s)
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }

    private static function cmd($fp, string $line): void
    {
        fwrite($fp, $line . "\r\n");
    }

    /**
     * Legge una risposta SMTP (gestisce le righe multiple 250-...).
     * @return array{0:int,1:string}
     */
    private static function readReply($fp): array
    {
        $code = 0;
        $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('timeout in lettura');
            }
            $code = (int) substr($line, 0, 3);
            $text .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if ($code === 0) {
            throw new \RuntimeException('nessuna risposta dal server');
        }
        return [$code, trim($text)];
    }

    private static function expect($fp, int $want): void
    {
        [$code, $text] = self::readReply($fp);
        if ($code !== $want) {
            throw new \RuntimeException("atteso {$want}, ricevuto {$code}: {$text}");
        }
    }

    private static function expectReply($fp, int $want): void
    {
        self::expect($fp, $want);
    }
}
