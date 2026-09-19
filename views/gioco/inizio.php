<?php /** @var array $citta @var array $piazze */ ?>
<div class="foglio">
  <span class="occhiello">Prima cosa</span>
  <h1>Da dove cominci?</h1>
  <p class="sommario">
    Scegli la città. La piazza no — quella la scopri scendendo, e trovarsi allo Zen
    invece che a Ballarò è già una differenza. Da qui non si torna indietro: il
    personaggio è uno e non si rifà.
  </p>
  <p class="minuto">
    Non esiste una scelta sbagliata, esistono partite diverse. Un porto ti mette vicino
    a quello che entra nel paese; una città di consumo ti mette vicino a chi paga.
  </p>
</div>

<div class="griglia griglia--2">
  <?php foreach ($citta as $id => $c):
      $sue = array_filter($piazze, static fn($p) => $p['citta_id'] === $id);
      $pol = $sue === [] ? 0 : (int) round(array_sum(array_column($sue, 'polizia')) / count($sue));
  ?>
    <div class="foglio">
      <span class="occhiello"><?= e(match ($c['carattere']) {
          'porto'   => 'Porto',
          'snodo'   => 'Snodo',
          default   => 'Consumo',
      }) ?></span>
      <h2 style="margin-top:0"><?= e($c['nome']) ?></h2>
      <p class="sommario minuto"><?= e($c['nota']) ?></p>
      <dl class="griglia griglia--3" style="margin:0 0 1rem">
        <div class="dato"><dt>Piazze</dt><dd style="font-size:1rem"><?= count($sue) ?></dd></div>
        <div class="dato"><dt>Polizia media</dt><dd style="font-size:1rem"><?= $pol ?>%</dd></div>
      </dl>
      <p class="minuto" style="margin-bottom:.8rem"><?= e(implode(' · ', array_column($sue, 'nome'))) ?></p>
      <form method="post" action="<?= e(url('/inizio')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="citta" value="<?= (int) $id ?>">
        <button type="submit">Comincio da <?= e($c['nome']) ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
