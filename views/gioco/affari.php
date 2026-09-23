<?php
/** @var array $p @var array $conti @var array $catalogo @var array $mezzi @var list $depositi */
use App\Sim\Viaggio;
?>
<div class="foglio">
  <span class="occhiello">Gli affari</span>
  <h1>Le due casse</h1>
  <p class="sommario minuto">
    Il denaro del commercio entra <strong>sporco</strong>: ingombra, si perde, e non compra
    niente che duri. Diventa pulito solo passando da un canale, che si prende la sua
    percentuale e ne lava una certa quantità <em>all'ora</em>. È la capacità oraria, non la
    commissione, a fare il gioco: si può avere una cantina piena e non poterla usare.
  </p>

  <dl class="griglia griglia--3" style="margin:0">
    <div class="dato"><dt>Sporco, in tasca</dt><dd style="font-size:1.1rem"><?= e(lire($conti['sporco'])) ?></dd></div>
    <div class="dato"><dt>In lavaggio</dt><dd style="font-size:1.1rem"><?= e(lire($conti['in_lavaggio'])) ?></dd></div>
    <div class="dato"><dt>Pulito</dt><dd style="font-size:1.1rem;color:var(--verde)"><?= e(lire($conti['pulito'])) ?></dd></div>
    <div class="dato"><dt>Debito</dt><dd style="font-size:1.1rem;color:var(--rosso)"><?= e(lire($conti['debito'])) ?></dd></div>
    <div class="dato"><dt>Interessi al giorno</dt><dd style="font-size:1.1rem"><?= e(lire($conti['interesse_giorno'])) ?></dd></div>
    <div class="dato"><dt>Si lava all'ora</dt><dd style="font-size:1.1rem"><?= e(lire($conti['capacita_ora'])) ?></dd></div>
  </dl>
</div>

<div class="foglio">
  <span class="occhiello">La lavanderia</span>
  <h2 style="margin-top:0">I tuoi canali</h2>
  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>Canale</th><th class="num">Trattiene</th><th class="num">All'ora</th>
                 <th class="num">In coda</th><th class="num">Finisce fra</th><th>Metti a lavare</th></tr></thead>
      <tbody>
      <?php foreach ($conti['canali'] as $c): ?>
        <tr>
          <td><strong><?= e($c['nome']) ?></strong><br><span class="minuto"><?= e($c['descrizione']) ?></span></td>
          <td class="num"><?= e(number_format($c['commissione'] * 100, 0, ',', '.')) ?>%</td>
          <td class="num"><?= e(lire($c['capacita'])) ?></td>
          <td class="num"><?= e(lire($c['coda'])) ?></td>
          <td class="num minuto"><?= $c['coda'] > 0 ? e(Viaggio::durata((int) ceil($c['finisce_fra'] / 60))) : '—' ?></td>
          <td>
            <form method="post" action="<?= e(url('/affari/lava')) ?>" class="ordine">
              <?= csrf_field() ?>
              <input type="hidden" name="canale" value="<?= e($c['codice']) ?>">
              <input type="number" name="importo" min="1" step="10000" placeholder="lire" style="width:7rem" aria-label="quanto">
              <button class="bottone--minuto" <?= $conti['sporco'] < 1 ? 'disabled' : '' ?>>lava</button>
              <button name="tutto" value="1" class="bottone--fantasma bottone--minuto"
                      <?= $conti['sporco'] < 1 ? 'disabled' : '' ?>>tutto</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php $daPrendere = array_diff_key($catalogo, array_flip(array_column($conti['canali'], 'codice'))); ?>
  <?php if ($daPrendere !== []): ?>
    <h3>Quelli che non hai</h3>
    <p class="sommario minuto">Si comprano col pulito: un'attività si intesta a qualcuno,
       e nessuno intesta niente a una borsa di contanti.</p>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Canale</th><th class="num">Trattiene</th><th class="num">All'ora</th><th class="num">Costa</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($daPrendere as $codice => $c): ?>
          <tr>
            <td><strong><?= e($c['nome']) ?></strong><br><span class="minuto"><?= e($c['descrizione']) ?></span></td>
            <td class="num"><?= e(number_format($c['commissione'] * 100, 0, ',', '.')) ?>%</td>
            <td class="num"><?= e(lire((int) $c['capacita'])) ?></td>
            <td class="num"><?= e(lire((int) $c['prezzo'])) ?></td>
            <td>
              <form method="post" action="<?= e(url('/affari/canale')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="canale" value="<?= e($codice) ?>">
                <button class="bottone--fantasma bottone--minuto"
                        <?= $conti['pulito'] < (int) $c['prezzo'] ? 'disabled' : '' ?>>prendilo</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="foglio">
  <span class="occhiello">L'usuraio</span>
  <h2 style="margin-top:0">Quello che devi</h2>
  <?php if ($conti['debito'] > 0): ?>
    <p class="sommario">
      <strong><?= e(lire($conti['debito'])) ?></strong>, che crescono del <?= e(percento($conti['tasso'])) ?> al giorno —
      <?= e(lire($conti['interesse_giorno'])) ?> al giorno, e non aspettano che tu apra la pagina.
      <?php if ($conti['al_tetto']): ?>
        <span style="color:var(--rosso)">Sei al tetto: il debito non cresce più, ma da qui in poi
        non aumentano gli interessi — cominciano le conseguenze.</span>
      <?php else: ?>
        Oltre <?= e(lire($conti['tetto'])) ?> smette di crescere, e allora sono guai di un altro tipo.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <p class="sommario">Non devi niente a nessuno. È una condizione rara e non dura.</p>
  <?php endif; ?>

  <div class="griglia griglia--2">
    <form method="post" action="<?= e(url('/affari/restituisci')) ?>">
      <?= csrf_field() ?>
      <div class="campo" style="margin:0">
        <label for="restituisci">Dagli qualcosa (contanti)</label>
        <input type="number" id="restituisci" name="importo" min="1" step="10000">
      </div>
      <div class="azioni" style="margin-top:.7rem">
        <button <?= $conti['debito'] < 1 || $conti['sporco'] < 1 ? 'disabled' : '' ?>>paga</button>
        <button name="tutto" value="1" class="bottone--fantasma"
                <?= $conti['debito'] < 1 || $conti['sporco'] < 1 ? 'disabled' : '' ?>>tutto quello che ho</button>
      </div>
    </form>

    <form method="post" action="<?= e(url('/affari/prestito')) ?>">
      <?= csrf_field() ?>
      <div class="campo" style="margin:0">
        <label for="prestito">Fatti prestare (fino a <?= e(lire($conti['prestabile'])) ?>)</label>
        <input type="number" id="prestito" name="importo" min="1" step="100000" max="<?= (int) $conti['prestabile'] ?>">
      </div>
      <div class="azioni" style="margin-top:.7rem">
        <button class="bottone--fantasma" <?= $conti['prestabile'] < 1 ? 'disabled' : '' ?>>chiedi</button>
      </div>
      <p class="aiuto">Il <?= e(percento($conti['tasso'])) ?> al giorno corre da subito — meno, man mano che
         ti fai un nome pagando. Serve per comprare
         quello che ti fa guadagnare, non per campare.</p>
    </form>
  </div>
</div>

<div class="foglio">
  <span class="occhiello">I mezzi</span>
  <h2 style="margin-top:0">Quanto ci porti</h2>
  <p class="sommario minuto">
    Adesso: <strong><?= e(quantita($capienza)) ?> spazi</strong>
    (<?= e(quantita($usato)) ?> occupati)<?= $mezzoMio !== null ? ' — ' . e($mezzoMio['nome']) : ' — a piedi, con un borsone' ?>.
    Un mezzo si compra col pulito e ti porta anche in giro da solo: niente attese,
    solo benzina, e nessuno a cui dire dove vai.
    <?php if ($rivendita > 0): ?>
      Cambiandolo, il tuo lo rivendi di fretta: <?= e(lire($rivendita)) ?>, che contano nel prezzo.
    <?php endif; ?>
  </p>
  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>Mezzo</th><th class="num">Spazi</th><th class="num">In città</th>
                 <th class="num">Fuori</th><th class="num">Benzina</th><th class="num">Costa</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($mezzi as $codice => $m): $mio = $mezzoMio !== null && $mezzoMio['codice'] === $codice; ?>
        <tr>
          <td><strong><?= e($m['nome']) ?></strong><?= $mio ? ' <span class="stato stato--attivo">tua</span>' : '' ?><br>
              <span class="minuto"><?= e($m['descrizione']) ?></span></td>
          <td class="num">+<?= e(quantita($m['capienza'])) ?></td>
          <td class="num minuto"><?= e(number_format($m['kmh_citta'], 0)) ?> km/h</td>
          <td class="num minuto"><?= e(number_format($m['kmh_paese'], 0)) ?> km/h</td>
          <td class="num minuto"><?= e(lire($m['costo_km'])) ?>/km</td>
          <td class="num"><?= e(lire($m['prezzo'])) ?></td>
          <td>
            <?php if (!$mio): ?>
              <form method="post" action="<?= e(url('/affari/mezzo')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="mezzo" value="<?= e($codice) ?>">
                <button class="bottone--fantasma bottone--minuto"
                        <?= $conti['pulito'] + $rivendita < (int) $m['prezzo'] ? 'disabled' : '' ?>>compra</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="foglio">
  <span class="occhiello">I depositi</span>
  <h2 style="margin-top:0">Dove lasci la roba</h2>
  <?php if ($depositi === []): ?>
    <p class="sommario minuto">Nessuno. Un deposito si affitta di persona, dalla piazza in
       cui sei — il pulsante è sulla <a href="<?= e(url('/strada')) ?>">strada</a>. Serve a
       comprare quando costa poco e aspettare che la piazza si riprenda: senza un posto dove
       mettere la merce, quella strategia non esiste.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Dove</th><th class="num">Occupato</th><th class="num">Affitto</th>
                   <th class="num">Pagato fino a</th><th>Dentro c'è</th></tr></thead>
        <tbody>
        <?php foreach ($depositi as $d): ?>
          <tr>
            <td><strong><?= e($d['piazza']) ?></strong><br><span class="minuto"><?= e($d['citta']) ?></span></td>
            <td class="num"><?= e(quantita($d['usato'])) ?> / <?= e(quantita((int) $d['capienza'])) ?></td>
            <td class="num minuto"><?= e(lire((int) $d['affitto_ora'])) ?>/ora</td>
            <td class="num minuto"><?= e(fmt_dt($d['pagato_fino_a'])) ?></td>
            <td class="minuto">
              <?php if ($d['merce'] === []): ?>vuoto
              <?php else: foreach ($d['merce'] as $m): ?>
                <?= e($m['bene']['nome']) ?> ×<?= e(quantita($m['quantita'])) ?><br>
              <?php endforeach; endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="minuto" style="margin-top:.8rem">L'affitto si paga in contanti sporchi, a ore.
       Chi non paga trova la serratura cambiata, e quello che c'era dentro non torna.</p>
  <?php endif; ?>
</div>

<div class="foglio">
  <span class="occhiello">Il registro</span>
  <h2 style="margin-top:0">Ogni lira che entra e che esce</h2>
  <?php if ($movimenti === []): ?>
    <p class="minuto">Ancora niente.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Quando</th><th>Cosa</th><th>Cassa</th><th class="num">Importo</th><th>Nota</th></tr></thead>
        <tbody>
        <?php foreach ($movimenti as $m): $i = (int) $m['importo']; ?>
          <tr>
            <td class="minuto"><?= e(fmt_dt($m['fatto_at'], true)) ?></td>
            <td><?= e($m['genere']) ?></td>
            <td class="minuto"><?= e($m['cassa']) ?></td>
            <td class="num" style="color:<?= $i > 0 ? 'var(--verde)' : ($i < 0 ? 'var(--rosso)' : 'inherit') ?>">
              <?= $i > 0 ? '+' : '' ?><?= e(lire($i)) ?></td>
            <td class="minuto"><?= e((string) ($m['nota'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
