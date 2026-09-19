<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;

/**
 * Le quattro graduatorie e l'albo d'oro.
 *
 * **Perché quattro e non una** (docs/DESIGN.md §7.1): in un mondo che non
 * riparte mai, una classifica sola premia solo chi è arrivato primo. Chi
 * comincia oggi sa già che non raggiungerà mai chi gioca da tre mesi, e allora
 * la graduatoria smette di essere un motivo per giocare e diventa un cartello
 * con su scritto «è tardi».
 *
 * - **Patrimonio**: il denaro pulito. È quella classica, e la più lenta.
 * - **Territorio**: le piazze tenute. Cambia in continuazione.
 * - **Reddito**: quanto hai estratto negli ultimi trenta giorni. **È quella che
 *   conta davvero**, perché il passato non ci pesa: è contendibile da chiunque,
 *   sempre, ed è anche l'unica che misura il gioco invece del saldo.
 * - **Longevità**: giorni senza arresti né ospedale, con il profilo criminale
 *   alto. Premia chi rischia e non si fa prendere, non chi non rischia: senza
 *   la soglia di profilo la vincerebbe chi non ha mai fatto niente.
 */
final class Classifica
{
    public const GRADUATORIE = [
        'patrimonio' => ['nome' => 'Patrimonio',  'unita' => 'lire',   'nota' => 'Denaro pulito in cassa. La classifica classica, e la più lenta a muoversi.'],
        'territorio' => ['nome' => 'Territorio',  'unita' => 'piazze', 'nota' => 'Piazze tenute dalla propria batteria. Cambia in continuazione.'],
        'reddito'    => ['nome' => 'Reddito',     'unita' => 'lire',   'nota' => 'Quanto hai estratto dal mondo negli ultimi trenta giorni. È quella che conta.'],
        'longevita'  => ['nome' => 'Longevità',   'unita' => 'giorni', 'nota' => 'Giorni senza arresti né ospedale, con il profilo criminale sopra cinque.'],
    ];

    /**
     * Una graduatoria.
     *
     * @return list<array<string,mixed>> id, username, valore
     */
    public static function righe(string $graduatoria, ?int $quante = null): array
    {
        $n = $quante ?? GameConfig::int('classifica.righe', 50);
        $n = max(1, min(200, $n));
        $giorni = max(1, min(365, GameConfig::int('classifica.reddito_giorni', 30)));

        // `status = 'active'` dappertutto: un account sospeso o mai confermato
        // non sta in vetrina.
        return match ($graduatoria) {
            'patrimonio' => Database::all(
                "SELECT p.id, u.username, p.pulito AS valore
                   FROM personaggi p JOIN users u ON u.id = p.user_id
                  WHERE u.status = 'active' AND p.pulito > 0
                  ORDER BY valore DESC, u.username LIMIT {$n}"),

            'territorio' => Database::all(
                "SELECT p.id, u.username, COUNT(t.piazza_id) AS valore, b.sigla
                   FROM personaggi p JOIN users u ON u.id = p.user_id
                   JOIN batterie b ON b.id = p.batteria_id
                   JOIN territori t ON t.batteria_id = b.id
                  WHERE u.status = 'active'
                  GROUP BY p.id, u.username, b.sigla
                  HAVING valore > 0
                  ORDER BY valore DESC, u.username LIMIT {$n}"),

            // Il margine è già il guadagno netto della vendita: sommarlo è
            // esattamente «quanto ha estratto dal mondo», la stessa grandezza
            // che `balance:report` confronta con il tetto orario.
            'reddito' => Database::all(
                "SELECT p.id, u.username, COALESCE(SUM(t.margine), 0) AS valore
                   FROM personaggi p JOIN users u ON u.id = p.user_id
                   JOIN transazioni t ON t.personaggio_id = p.id
                  WHERE u.status = 'active' AND t.verso = 'vendita'
                    AND t.fatto_at >= DATE_SUB(NOW(), INTERVAL {$giorni} DAY)
                  GROUP BY p.id, u.username
                  HAVING valore > 0
                  ORDER BY valore DESC, u.username LIMIT {$n}"),

            'longevita' => Database::all(
                "SELECT p.id, u.username, TIMESTAMPDIFF(DAY, p.pulito_dal, NOW()) AS valore, p.profilo
                   FROM personaggi p JOIN users u ON u.id = p.user_id
                  WHERE u.status = 'active' AND p.pulito_dal IS NOT NULL AND p.profilo >= 5
                  ORDER BY valore DESC, p.profilo DESC, u.username LIMIT {$n}"),

            default => [],
        };
    }

    /**
     * Riazzera la longevità: succede quando ti prendono o quando ti ricoverano.
     *
     * Sta qui e non dentro `Legge` o `Rivalita` perché la longevità è una
     * grandezza della classifica, non della legge: se domani cambia la
     * definizione, si cambia in un posto solo.
     */
    public static function azzeraLongevita(int $personaggioId): void
    {
        Database::run('UPDATE personaggi SET pulito_dal = ? WHERE id = ?',
            [Clock::adesso()->format('Y-m-d H:i:s'), $personaggioId]);
    }

    // --- L'albo d'oro ----------------------------------------------------------

    /**
     * Aggiorna i primati e chiude i regni finiti. Chiamata dal battito.
     *
     * Un primato che cambia ogni cinque minuti non è un primato: quello che si
     * misura è **da quanto** uno sta in cima, non che ci sia adesso. Per questo
     * il regno si chiude solo quando il primo cambia davvero, e finisce
     * nell'albo solo se è durato abbastanza.
     *
     * @return array{cambi:int,iscritti:int}
     */
    public static function aggiornaPrimati(): array
    {
        $ora = Clock::adesso();
        $minimi = max(1, GameConfig::int('albo.giorni_minimi', 30));
        $cambi = 0; $iscritti = 0;

        foreach (array_keys(self::GRADUATORIE) as $g) {
            $prime = self::righe($g, 1);
            $nuovo = $prime === [] ? null : (int) $prime[0]['id'];
            $valore = $prime === [] ? 0 : (int) $prime[0]['valore'];

            $attuale = Database::first('SELECT * FROM primati WHERE graduatoria = ?', [$g]);

            if ($attuale === null) {
                Database::run(
                    'INSERT INTO primati (graduatoria, personaggio_id, valore, dal, agg_a) VALUES (?, ?, ?, ?, ?)',
                    [$g, $nuovo, $valore, $ora->format('Y-m-d H:i:s'), Clock::perDb()]);
                continue;
            }

            $vecchio = $attuale['personaggio_id'] === null ? null : (int) $attuale['personaggio_id'];
            if ($vecchio === $nuovo) {
                // Stesso primo: si aggiorna solo il valore, la data no.
                Database::run('UPDATE primati SET valore = ?, agg_a = ? WHERE graduatoria = ?',
                    [$valore, Clock::perDb(), $g]);
                continue;
            }

            $cambi++;
            if ($vecchio !== null) {
                $dal = Clock::daDb((string) $attuale['dal']);
                $giorni = $dal === null ? 0
                    : (int) floor(($ora->getTimestamp() - $dal->getTimestamp()) / 86400);
                if ($giorni >= $minimi) {
                    Database::run(
                        'INSERT INTO albo (graduatoria, personaggio_id, nome, valore, dal, al, giorni)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$g, $vecchio, Rivalita::nome($vecchio), (int) $attuale['valore'],
                         (string) $attuale['dal'], $ora->format('Y-m-d H:i:s'), min(65535, $giorni)]);
                    $iscritti++;
                    Cronaca::scrivi('albo',
                        Rivalita::nome($vecchio) . ' lascia il primato di '
                        . mb_strtolower(self::GRADUATORIE[$g]['nome']) . ' dopo ' . $giorni . ' giorni.', null, 3);
                }
            }

            Database::run(
                'UPDATE primati SET personaggio_id = ?, valore = ?, dal = ?, agg_a = ? WHERE graduatoria = ?',
                [$nuovo, $valore, $ora->format('Y-m-d H:i:s'), Clock::perDb(), $g]);
        }

        return ['cambi' => $cambi, 'iscritti' => $iscritti];
    }

    /** Chi tiene adesso, e da quanto. @return array<string,array<string,mixed>> */
    public static function primatiCorrenti(): array
    {
        $fuori = [];
        foreach (Database::all(
            'SELECT pr.*, u.username FROM primati pr
             LEFT JOIN personaggi p ON p.id = pr.personaggio_id
             LEFT JOIN users u ON u.id = p.user_id') as $r) {
            $fuori[(string) $r['graduatoria']] = $r;
        }
        return $fuori;
    }

    /** @return list<array<string,mixed>> */
    public static function albo(?string $graduatoria = null, int $quanti = 60): array
    {
        $n = max(1, min(200, $quanti));
        if ($graduatoria === null) {
            return Database::all("SELECT * FROM albo ORDER BY giorni DESC, id DESC LIMIT {$n}");
        }
        return Database::all("SELECT * FROM albo WHERE graduatoria = ? ORDER BY giorni DESC, id DESC LIMIT {$n}",
            [$graduatoria]);
    }
}
