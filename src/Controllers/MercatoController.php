<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Legge;
use App\Game\Listino;
use App\Game\Personaggio;
use App\Sim\Mercato;

final class MercatoController
{
    public function ordina(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) {
            return redirect('/inizio');
        }

        $beneId = $request->int('bene');
        $verso  = $request->str('verso');
        $q      = $request->int('quantita');

        // I due pulsanti «tutto» e «svuota» mandano un'azione invece di una
        // quantità. Il numero lo calcola il server: comprando è quanto denaro e
        // spazio consentono, vendendo è quanto si ha addosso. Metterlo in un
        // campo nascosto sarebbe metterlo in mano a chi apre gli strumenti di
        // sviluppo — e da lì a comprare mille unità con duemila lire è un passo.
        $azione = $request->str('azione');
        if ($azione === 'compra_tutto') {
            $verso = 'acquisto';
            $q = $this->massimoAcquistabile($p, $beneId);
        } elseif ($azione === 'vendi_tutto') {
            $verso = 'vendita';
            $q = $this->quantoHo((int) $p['id'], $beneId);
        }
        if ($q < 1) {
            Session::flash('error', $azione !== ''
                ? 'Non c\'è niente da fare con questa merce, qui e adesso.'
                : 'Quantità non valida.');
            return redirect('/strada');
        }

        $chiesto = $q;
        $res = Listino::ordina((int) $p['id'], (int) $p['piazza_id'], $beneId, $verso, $q);

        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Affare non concluso.');
            return redirect('/strada');
        }

        $bene = Listino::beni()[$beneId] ?? null;
        $nome = $bene['nome'] ?? 'merce';

        // Ogni operazione scalda, in proporzione superlineare al suo valore e
        // al rischio della merce. Poi si tira per il controllo: il calore non è
        // una decorazione, è quello che decide se stasera ti fermano.
        Legge::scalda((int) $p['id'], (int) $p['piazza_id'], (int) $res['totale'], (int) ($bene['rischio'] ?? 10));
        $controllo = Legge::controllaIn((int) $p['id'], (int) $p['piazza_id']);

        // Quando se ne muove meno di quanto chiesto va detto, e va detto
        // PERCHÉ. «Svuota» non sempre svuota: se la piazza si satura a metà
        // vendita, il resto resta addosso — ed è il gioco che funziona, non un
        // intoppo. Un giocatore che se lo ritrova senza spiegazione pensa a un
        // baco; se glielo si dice, impara come gira il mercato.
        $parziale = '';
        if ($res['quantita'] < $chiesto) {
            $parziale = $verso === 'acquisto'
                ? ' Più di così non ce n\'era, o non ci stava.'
                : ' Il resto ti resta addosso: qui non ne assorbono altre.';
        }

        if ($verso === 'acquisto') {
            Session::flash('success', sprintf('Comprate %s di %s a %s l\'una — %s in tutto.%s%s',
                quantita($res['quantita']), mb_strtolower($nome), lire($res['medio']),
                lire($res['totale']), $parziale,
                $res['fornitore'] === null ? '' : ' Prezzo da ' . $res['fornitore'] . '.'));
        } else {
            $m = (int) ($res['margine'] ?? 0);
            // Niente punto finale dopo lire(): il simbolo «L.» ne porta già uno,
            // e due di fila si vedono.
            Session::flash('success', sprintf('Vendute %s di %s a %s l\'una — %s in tutto%s',
                quantita($res['quantita']), mb_strtolower($nome), lire($res['medio']), lire($res['totale']),
                $m === 0 ? '.' : ($m > 0 ? ', ci hai guadagnato ' . lire($m) : ', ci hai rimesso ' . lire(-$m))) . $parziale);
        }

        if (!empty($controllo['controllo'])) {
            Session::flash('warning', $controllo['messaggio'] ?? 'Controllo.');
        }
        return redirect('/strada');
    }

    /** @param array<string,mixed> $p */
    private function massimoAcquistabile(array $p, int $beneId): int
    {
        $bene = Listino::beni()[$beneId] ?? null;
        if ($bene === null) {
            return 0;
        }
        foreach (Listino::perPiazza((int) $p['piazza_id'], $p) as $v) {
            if ((int) $v['bene']['id'] !== $beneId) {
                continue;
            }
            $spazio = (int) $p['capienza'] - Listino::ingombroUsato((int) $p['id']);
            return Mercato::quantoPosso($v['stato_m'], Listino::parametri($p),
                (int) $p['contante'], $spazio, (int) $bene['ingombro']);
        }
        return 0;
    }

    private function quantoHo(int $personaggioId, int $beneId): int
    {
        foreach (Listino::carico($personaggioId) as $c) {
            if ((int) $c['bene']['id'] === $beneId) {
                return (int) $c['quantita'];
            }
        }
        return 0;
    }
}
