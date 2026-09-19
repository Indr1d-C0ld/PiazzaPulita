<?php
/** @var array $p @var float $calore @var ?array $fascicolo @var list $segnali */
use App\Sim\Calore;
use App\Sim\Viaggio;
?>

<?php if ($inCarcere): ?>
  <div class="foglio" data-viaggio data-stato-url="<?= e(url('/api/stato')) ?>" data-mancano="<?= (int) $mancano ?>">
    <span class="occhiello">Dentro</span>
    <h1>Non si esce prima</h1>
    <p class="sommario">
      Ti hanno preso. Esci fra <strong data-mancano-testo><?= e(Viaggio::durata((int) ceil($mancano / 60))) ?></strong>.
      Da qui non si compra, non si vende e non si va da nessuna parte.
    </p>
    <p class="minuto">
      I depositi fuori città sono ancora tuoi, e il denaro pulito pure: quello è
      intestato, e per toccarlo serve un altro tipo di processo. Il resto no.
    </p>
  </div>
<?php endif; ?>

<div class="foglio">
  <span class="occhiello">Quanto scotti</span>
  <h1><?= e(ucfirst($caloreParole)) ?></h1>

  <dl class="griglia griglia--3" style="margin:0 0 1.2rem">
    <div class="dato"><dt>Il tuo calore</dt>
        <dd style="color:<?= $calore > 90 ? 'var(--rosso)' : ($calore > 40 ? 'var(--ambra)' : 'inherit') ?>">
          <?= e(number_format($calore, 1, ',', '.')) ?></dd></div>
    <div class="dato"><dt>La piazza dove sei</dt><dd><?= e(number_format($calorePiazza, 1, ',', '.')) ?></dd></div>
    <div class="dato"><dt>Polizia qui</dt><dd><?= (int) ($piazza['polizia'] ?? 0) ?>%</dd></div>
    <div class="dato"><dt>Rischio di un controllo</dt>
        <dd style="font-size:1rem"><?= e(Calore::rischioAParole($rischio)) ?>
          <span class="minuto">(<?= e(number_format($rischio * 100, 1, ',', '.')) ?>%)</span></dd></div>
    <div class="dato"><dt>Posto di blocco</dt>
        <dd style="font-size:1rem"><?= $rischioBlocco > 0 ? e(Calore::rischioAParole($rischioBlocco))
            . ' <span class="minuto">(' . e(number_format($rischioBlocco * 100, 1, ',', '.')) . '%)</span>'
            : '<span class="minuto">solo se guidi tu</span>' ?></dd></div>
    <div class="dato"><dt>Pubblico nemico numero</dt>
        <dd><?= (int) $p['profilo'] ?><?= (int) $p['arresti'] > 0 ? ' <span class="minuto">(' . (int) $p['arresti'] . ' arresti)</span>' : '' ?></dd></div>
  </dl>

  <p class="sommario minuto">
    Il calore sale con il <strong>valore</strong> di quello che muovi, più che in
    proporzione: un'operazione da cinque milioni scotta sessanta volte una da
    trecentomila, non sedici. E sale di più con la merce che pesa di più. Poi decade da
    solo — si dimezza ogni <?= e(\App\Core\GameConfig::int('calore.dimezzamento_ore', 12)) ?> ore —
    quindi stare fermi funziona davvero. Il numero è sempre qui, e la legge reagisce a
    quello: <strong>il rischio non ti capita addosso, te lo procuri</strong>.
  </p>
</div>

<div class="foglio">
  <span class="occhiello">Il fascicolo</span>
  <?php if ($fascicolo === null): ?>
    <h2 style="margin-top:0">Nessuno si sta occupando di te</h2>
    <p class="sommario minuto">
      Si apre da solo quando scotti abbastanza. Da quel momento qualcuno con un nome
      comincia a mettere fogli in una cartella, e quei fogli non se ne vanno da soli
      come il calore.
    </p>
  <?php else: ?>
    <h2 style="margin-top:0"><?= e($fascicolo['inquirente']) ?></h2>
    <p class="sommario">
      <?= e(match ((string) $fascicolo['corpo']) {
          'carabinieri' => 'Arma dei Carabinieri',
          'finanza'     => 'Guardia di Finanza',
          default       => 'Questura',
      }) ?> · aperto il <?= e(fmt_date($fascicolo['aperto_at'])) ?>
    </p>

    <?php $q = min(100, (float) $fascicolo['prove'] / max(1, $soglia) * 100); ?>
    <div style="background:var(--carta-3);border:1px solid var(--riga-2);border-radius:2px;height:1.4rem;position:relative;margin:.8rem 0">
      <div style="background:<?= $q > 75 ? 'var(--rosso)' : ($q > 40 ? 'var(--ambra)' : 'var(--inchiostro-3)') ?>;
                  width:<?= e(number_format($q, 1, '.', '')) ?>%;height:100%"></div>
      <span class="cifra" style="position:absolute;inset:0;display:grid;place-items:center;font-size:.72rem">
        <?= e(number_format((float) $fascicolo['prove'], 0, ',', '.')) ?> prove su <?= e(quantita($soglia)) ?>
      </span>
    </div>
    <p class="minuto">
      Le prove crescono in proporzione a quanto scotti. A cento scatta il blitz:
      perquisizione, sequestro di quello che hai addosso e dei depositi in questa città,
      il sessanta per cento dei contanti, e del tempo dentro.
    </p>

    <h3>Cosa puoi farci</h3>
    <div class="griglia griglia--2">
      <form method="post" action="<?= e(url('/fascicolo/avvocato')) ?>">
        <?= csrf_field() ?>
        <p class="sommario minuto"><strong>Un penalista</strong> smonta
          <?= e(\App\Core\GameConfig::int('legge.avvocato_prove', 30)) ?> prove.
          <?= e(lire($prezzi['avvocato'])) ?> di parcella, in denaro <strong>pulito</strong>:
          non lavora per contanti in una busta.</p>
        <div class="azioni" style="margin:0">
          <button <?= (int) $p['pulito'] < $prezzi['avvocato'] ? 'disabled' : '' ?>>Prendi un avvocato</button>
        </div>
      </form>

      <form method="post" action="<?= e(url('/fascicolo/bustarella')) ?>">
        <?= csrf_field() ?>
        <p class="sommario minuto"><strong>Comprare qualcuno</strong> fa sparire
          <?= e(\App\Core\GameConfig::int('legge.bustarella_prove', 18)) ?> prove per
          <?= e(lire($prezzi['bustarella'])) ?> in contanti. Una volta su
          <?= e(round(1 / max(0.01, (float) \App\Core\GameConfig::get('legge.bustarella_rischio', 0.22)))) ?>
          però la busta finisce agli atti, e diventa lei una prova.</p>
        <div class="azioni" style="margin:0">
          <button class="bottone--fantasma" <?= (int) $p['contante'] < $prezzi['bustarella'] ? 'disabled' : '' ?>>
            Prova a comprarlo</button>
        </div>
      </form>
    </div>
    <p class="minuto" style="margin-top:.8rem">
      La terza cosa non costa niente: <strong>stare fermo</strong>. Il calore decade, le
      prove rallentano, e un fascicolo che smette di crescere prima o poi si archivia.
    </p>
  <?php endif; ?>
</div>

<div class="foglio">
  <span class="occhiello">Quello che hai notato</span>
  <h2 style="margin-top:0">Segnali</h2>
  <?php if ($segnali === []): ?>
    <p class="minuto">Niente di strano. Per ora.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <tbody>
        <?php foreach ($segnali as $s): ?>
          <tr>
            <td class="minuto" style="white-space:nowrap"><?= e(fmt_dt($s['fatto_at'])) ?></td>
            <td style="border-left:3px solid <?= (int) $s['gravita'] >= 4 ? 'var(--rosso)'
                : ((int) $s['gravita'] >= 2 ? 'var(--ambra)' : 'var(--riga-2)') ?>;padding-left:.7rem">
              <?= e($s['testo']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
