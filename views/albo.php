<?php /** @var list $righe @var array $primati @var array $graduatorie */ ?>
<div class="foglio">
  <span class="occhiello">Albo d'oro</span>
  <h1>Chi c'è stato</h1>
  <p class="sommario minuto">
    Un primato perso sparisce dalla classifica, ma qui resta scritto: il tempo passato in
    cima non si cancella quando qualcun altro ti supera. Ci si entra tenendo una
    graduatoria per almeno <strong><?= (int) $minimi ?> giorni</strong>, e il nome ci
    rimane anche se l'account un giorno non ci sarà più.
  </p>

  <nav class="linguette">
    <?php foreach ($graduatorie as $cod => $g): ?>
      <a href="<?= e(url('/classifica?g=' . $cod)) ?>"><?= e($g['nome']) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(url('/albo')) ?>" class="attiva">Albo d'oro</a>
  </nav>

  <h2>In cima adesso</h2>
  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>Graduatoria</th><th>Chi</th><th class="num">Dal</th></tr></thead>
      <tbody>
      <?php foreach ($graduatorie as $cod => $g): $pr = $primati[$cod] ?? null; ?>
        <tr>
          <td><?= e($g['nome']) ?></td>
          <td><?= ($pr['username'] ?? null) === null
                ? '<span class="minuto">nessuno</span>'
                : '<a href="' . e(url('/profilo/' . $pr['personaggio_id'])) . '">' . e($pr['username']) . '</a>' ?></td>
          <td class="num minuto"><?= $pr === null ? '—' : e(fmt_date($pr['dal'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="foglio">
  <h2 style="margin-top:0">I regni chiusi</h2>
  <?php if ($righe === []): ?>
    <p class="minuto">Ancora nessuno. Il mondo è giovane: nessun primato è durato
       abbastanza da meritare una riga qui.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Chi</th><th>Graduatoria</th><th class="num">Giorni</th><th class="num">Dal</th><th class="num">Al</th></tr></thead>
        <tbody>
        <?php foreach ($righe as $r): ?>
          <tr>
            <td><strong><?= e($r['nome']) ?></strong></td>
            <td class="minuto"><?= e($graduatorie[$r['graduatoria']]['nome'] ?? $r['graduatoria']) ?></td>
            <td class="num"><?= (int) $r['giorni'] ?></td>
            <td class="num minuto"><?= e(fmt_date($r['dal'])) ?></td>
            <td class="num minuto"><?= e(fmt_date($r['al'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
