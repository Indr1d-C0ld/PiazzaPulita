<?php
/** @var array $stato @var string $cittaJson @var list $destinazioni @var int $qui */
use App\Sim\Viaggio;
?>
<div class="foglio">
  <span class="occhiello">La rete</span>
  <h1>La mappa</h1>
  <p class="sommario minuto">
    Nove città. Non è una carta geografica ma uno schema della rete, come un orario
    ferroviario: le posizioni sono quelle vere, il resto è tolto perché non serve.
    <?php if (!$stato['in_viaggio']): ?>
      Sei a <strong><?= e($stato['citta']['nome']) ?></strong>, in <?= e($stato['piazza']['nome']) ?>.
    <?php else: ?>
      Sei in viaggio verso <strong><?= e($stato['piazza']['nome']) ?></strong>.
    <?php endif; ?>
  </p>
  <canvas id="mappa" width="720" height="900" style="width:100%;max-width:38rem;height:auto;display:block;margin:0 auto"
          data-citta='<?= e($cittaJson) ?>'></canvas>
</div>

<?php if ($stato['in_viaggio']): ?>
  <div class="foglio">
    <p class="sommario">Si riparte quando si è arrivati. <a href="<?= e(url('/strada')) ?>">Torna alla strada</a>.</p>
  </div>
<?php else: ?>
  <div class="foglio">
    <span class="occhiello">Le altre città</span>
    <h2 style="margin-top:0">Dove si sbarca, e quanto costa</h2>
    <p class="sommario minuto">
      Si arriva alla stazione, o alla piazza più vicina se stazione non ce n'è. Da lì
      ci si muove dentro la città come al solito.
    </p>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Città</th><th>Si scende a</th><th class="num">km</th><th>Come</th></tr></thead>
        <tbody>
        <?php foreach ($destinazioni as $d): ?>
          <tr>
            <td><strong><?= e($d['citta']['nome']) ?></strong>
                <span class="minuto"><?= e(match ($d['citta']['carattere']) {
                    'porto' => 'porto', 'snodo' => 'snodo', default => 'consumo' }) ?></span></td>
            <td class="minuto"><?= e($d['sbarco']['nome']) ?></td>
            <td class="num"><?= e(number_format($d['km'], 0, ',', '.')) ?></td>
            <td>
              <?php foreach ($d['opzioni'] as $o): ?>
                <form method="post" action="<?= e(url('/parti')) ?>" style="display:inline-block;margin:0 .3rem .3rem 0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="piazza" value="<?= (int) $d['sbarco']['id'] ?>">
                  <input type="hidden" name="mezzo" value="<?= e($o['mezzo']) ?>">
                  <input type="hidden" name="torna" value="/mappa">
                  <button class="bottone--fantasma bottone--minuto" title="<?= e($o['nota']) ?>">
                    <?= e($o['mezzo']) ?> · <?= e(Viaggio::durata($o['minuti'])) ?> · <?= e(lire($o['costo'])) ?>
                  </button>
                </form>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
