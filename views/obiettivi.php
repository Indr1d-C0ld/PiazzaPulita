<?php /** @var array $catalogo @var array $sbloccati @var array $diffusione @var list $nuovi */
$gruppi = [];
foreach ($catalogo as $cod => $o) { $gruppi[$o['gruppo']][$cod] = $o; }
$fatti = count($sbloccati);
?>
<div class="foglio">
  <span class="occhiello">Obiettivi</span>
  <h1>Quello che hai fatto</h1>
  <p class="sommario minuto">
    <?= (int) $fatti ?> su <?= count($catalogo) ?>. Non danno vantaggi e non danno denaro:
    in un mondo a reddito orario finito, un premio in contanti lo pagherebbero gli altri
    giocatori senza saperlo. Sono una traccia, e basta.
  </p>

  <?php if ($nuovi !== []): ?>
    <div class="avviso avviso--ok">
      <?= count($nuovi) === 1 ? 'Obiettivo raggiunto' : 'Obiettivi raggiunti' ?>:
      <?= e(implode(', ', array_map(static fn($c) => $catalogo[$c]['nome'], $nuovi))) ?>.
    </div>
  <?php endif; ?>
</div>

<?php foreach ($gruppi as $gruppo => $voci): ?>
<div class="foglio">
  <span class="occhiello"><?= e($gruppo) ?></span>
  <div class="traguardi">
    <?php foreach ($voci as $cod => $o): $mio = isset($sbloccati[$cod]); $n = $diffusione[$cod] ?? 0; ?>
      <div class="traguardo <?= $mio ? 'traguardo--fatto' : '' ?> <?= $o['raro'] ? 'traguardo--raro' : '' ?>">
        <b><?= e($o['nome']) ?></b>
        <p><?= e($o['testo']) ?></p>
        <?php if ($mio): ?>
          <p class="quando">preso il <?= e(fmt_date($sbloccati[$cod])) ?></p>
        <?php else: ?>
          <p class="minuto"><?= $n === 0
              ? 'Nessuno ce l\'ha ancora.'
              : ($n === 1 ? 'Ce l\'ha una persona.' : 'Ce l\'hanno ' . $n . ' persone.') ?></p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
