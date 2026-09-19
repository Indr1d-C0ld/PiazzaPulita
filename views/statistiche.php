<?php /** @var array $m @var array|null $mio */ ?>
<div class="foglio">
  <span class="occhiello">Statistiche</span>
  <h1>I numeri del mondo</h1>
  <p class="sommario minuto">
    Tutto quello che sta qui sotto si legge dalle righe che il gioco scrive comunque:
    nessun contatore tenuto a parte, che prima o poi divergerebbe dai fatti e mentirebbe
    con sicurezza.
  </p>

  <dl class="griglia griglia--3" style="margin:1.4rem 0 0">
    <div class="dato"><dt>Iscritti</dt><dd><?= e(quantita($m['iscritti'])) ?></dd></div>
    <div class="dato"><dt>Personaggi</dt><dd><?= e(quantita($m['personaggi'])) ?></dd></div>
    <div class="dato"><dt>Visti in 24 ore</dt><dd><?= e(quantita($m['visti24'])) ?></dd></div>
    <div class="dato"><dt>Città</dt><dd><?= e(quantita($m['citta'])) ?></dd></div>
    <div class="dato"><dt>Piazze</dt><dd><?= e(quantita($m['piazze'])) ?></dd></div>
    <div class="dato"><dt>Nodi di mercato</dt><dd><?= e(quantita($m['nodi'])) ?></dd></div>
  </dl>
  <p class="minuto" style="margin-top:1rem">Primo iscritto: <?= e($primo) ?>.</p>
</div>

<div class="foglio">
  <span class="occhiello">Il mercato · ultime 24 ore</span>
  <h2 style="margin-top:0">Quanto ha girato</h2>
  <dl class="griglia griglia--3" style="margin:0 0 1rem">
    <div class="dato"><dt>Compravendite</dt><dd><?= e(quantita($m['scambi24'])) ?></dd></div>
    <div class="dato"><dt>Volume</dt><dd style="font-size:1rem"><?= e(lire($m['volume24'])) ?></dd></div>
    <div class="dato"><dt>Chi ha lavorato</dt><dd><?= e(quantita($m['attivi24'])) ?></dd></div>
  </dl>
  <p class="sommario">
    Il mondo ha un <strong>reddito massimo orario</strong> di <?= e(lire($m['tetto'])) ?>:
    la ricchezza qui è una torta finita da contendersi, non una fontana. Nelle ultime 24
    ore i giocatori ne hanno estratto <?= e(lire($m['estratto24'])) ?>, cioè il
    <strong><?= e(number_format($m['utilizzo'] * 100, 1, ',', '.')) ?>%</strong> di quanto
    il mondo avrebbe potuto dare.
    <?php if ($m['utilizzo'] > 1.0): ?>
      <span style="color:var(--rosso)">Sopra il cento per cento: c'è qualcosa che non va.</span>
    <?php endif; ?>
  </p>

  <?php if ($m['merci'] !== []): ?>
    <h3>Cosa gira, negli ultimi sette giorni</h3>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Merce</th><th class="num">Scambi</th><th class="num">Volume</th></tr></thead>
        <tbody>
        <?php foreach ($m['merci'] as $r): ?>
          <tr><td><?= e($r['nome']) ?></td>
              <td class="num"><?= e(quantita((int) $r['scambi'])) ?></td>
              <td class="num"><?= e(lire((int) $r['volume'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="griglia griglia--2">
  <section class="foglio">
    <span class="occhiello">La legge</span>
    <dl class="griglia griglia--2" style="margin:0">
      <div class="dato"><dt>Fascicoli aperti</dt><dd><?= e(quantita($m['fascicoli'])) ?></dd></div>
      <div class="dato"><dt>Arresti (30 giorni)</dt><dd><?= e(quantita($m['arresti30'])) ?></dd></div>
      <div class="dato"><dt>Dentro adesso</dt><dd><?= e(quantita($m['in_carcere'])) ?></dd></div>
      <div class="dato"><dt>All'ospedale</dt><dd><?= e(quantita($m['ricoverati'])) ?></dd></div>
    </dl>
    <?php if ($m['piazze_calde'] !== []): ?>
      <p class="minuto" style="margin-top:1rem">Le piazze che scottano:
        <?= e(implode(', ', array_map(
            static fn($r) => $r['piazza'] . ' (' . $r['citta'] . ')', $m['piazze_calde']))) ?>.</p>
    <?php endif; ?>
  </section>

  <section class="foglio">
    <span class="occhiello">Gli altri</span>
    <dl class="griglia griglia--2" style="margin:0">
      <div class="dato"><dt>Scontri (30 giorni)</dt><dd><?= e(quantita($m['scontri30'])) ?></dd></div>
      <div class="dato"><dt>Batterie</dt><dd><?= e(quantita($m['batterie'])) ?></dd></div>
      <div class="dato"><dt>Piazze tenute</dt><dd><?= e(quantita($m['tenute'])) ?></dd></div>
      <div class="dato"><dt>Debiti in giro</dt><dd style="font-size:1rem"><?= e(lire($m['debiti'])) ?></dd></div>
    </dl>
    <p class="minuto" style="margin-top:1rem">Denaro pulito consolidato nel mondo:
       <?= e(lire($m['pulito'])) ?>.</p>
  </section>
</div>

<?php if ($mio !== null): ?>
<div class="foglio">
  <span class="occhiello">I tuoi</span>
  <h2 style="margin-top:0">Come stai andando</h2>
  <dl class="griglia griglia--3" style="margin:0">
    <div class="dato"><dt>Compravendite</dt><dd><?= e(quantita($mio['operazioni'])) ?></dd></div>
    <div class="dato"><dt>Guadagnato in tutto</dt><dd style="font-size:1rem"><?= e(lire($mio['guadagno'])) ?></dd></div>
    <div class="dato"><dt>Ultimi 30 giorni</dt><dd style="font-size:1rem"><?= e(lire($mio['reddito30'])) ?></dd></div>
    <div class="dato"><dt>Colpo migliore</dt><dd style="font-size:1rem"><?= e(lire($mio['migliore'])) ?></dd></div>
    <div class="dato"><dt>Merci trattate</dt><dd><?= (int) $mio['beni'] ?></dd></div>
    <div class="dato"><dt>Piazze battute</dt><dd><?= (int) $mio['piazze'] ?></dd></div>
    <div class="dato"><dt>Arresti</dt><dd><?= (int) $mio['arresti'] ?></dd></div>
    <div class="dato"><dt>Pubblico nemico</dt><dd><?= (int) $mio['profilo'] ?></dd></div>
    <div class="dato"><dt>Scontri</dt><dd><?= (int) $mio['vinti'] ?>–<?= (int) $mio['persi'] ?></dd></div>
  </dl>
  <p class="minuto" style="margin-top:1rem">
    <a href="<?= e(url('/obiettivi')) ?>">Obiettivi raggiunti</a>: <?= (int) $mio['obiettivi'] ?>.
  </p>
</div>
<?php endif; ?>
