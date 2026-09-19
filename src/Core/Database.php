<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Wrapper sottile su PDO/MariaDB. Connessione lazy e condivisa.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $lastError = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = (string) Config::get('db.host', '127.0.0.1');
        $port    = (int) Config::get('db.port', 3306);
        $name    = (string) Config::get('db.name', '');
        $user    = (string) Config::get('db.user', '');
        $pass    = (string) Config::get('db.pass', '');
        $charset = (string) Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            self::$lastError = $e->getMessage();
            throw new RuntimeException('Connessione al database non riuscita: ' . $e->getMessage(), 0, $e);
        }

        self::allineaOrologio();

        return self::$pdo;
    }

    /**
     * Mette il database sullo stesso fuso orario dell'applicazione.
     *
     * Serve perché nel codice convivono due sorgenti di «adesso»: `Clock`, che
     * è PHP, e `NOW()`, che è il server del database. Se i due non concordano,
     * ogni conto sul tempo sbaglia in silenzio — e lo sbaglio non si vede
     * guardando i numeri, si vede solo confrontandoli con quello che dovevano
     * essere. È saltato fuori in prova: un debito che doveva crescere di due
     * giorni cresceva di 2,08, cioè di due ore in più, perché il MariaDB di
     * prova girava a UTC e l'applicazione a Roma.
     *
     * In produzione i due coincidono, ma per coincidenza: il server è
     * configurato sull'ora italiana e l'applicazione pure. Basterebbe spostare
     * il database, o cambiare `app.timezone`, perché tutto slittasse senza che
     * nessuno se ne accorga. Qui la coincidenza diventa una garanzia.
     *
     * Si usa lo scarto numerico e non il nome del fuso perché i nomi richiedono
     * le tabelle dei fusi caricate in MariaDB, che spesso non ci sono. Lo
     * scarto si ricalcola a ogni connessione, quindi l'ora legale è gestita:
     * al massimo una connessione aperta a cavallo del cambio resta indietro di
     * un'ora per il resto della sua vita, che dura pochi millisecondi.
     */
    private static function allineaOrologio(): void
    {
        try {
            $scarto = (new \DateTimeImmutable('now'))->format('P');   // es. +02:00
            self::$pdo?->exec("SET time_zone = '{$scarto}'");
        } catch (\Throwable $e) {
            // Se non si può, si continua: peggio che va, si torna al
            // comportamento di prima. Ma resta scritto nel diario.
            logger('fuso del database non allineato: ' . $e->getMessage(), 'warning');
        }
    }

    /** Verifica non lanciante: utile per la pagina di setup. */
    public static function isReachable(): bool
    {
        try {
            self::pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
