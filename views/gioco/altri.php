<?php
/** @var array $p @var list $altri @var list $corse @var array $spiati */
use App\Sim\Viaggio;
?>

<?php if ($inOspedale): ?>
  <div class="foglio">
    <span class="occhiello">All'ospedale</span>
    <h1>Te le hanno date</h1>
    <p class="sommario">Ne esci fra <strong><?= e(Viaggio::durata((int) ceil($mancano / 60))) ?></strong>.
       Fino ad allora non c'è niente da fare.</p>
  </div>
<?php endif; ?>

<div class="foglio">
  <span class="occhiello">Chi c'è · <?= e($piazza['nome'] ?? '') ?></span>
  <h1>Il giro degli altri</h1>
  <p class="sommario minuto">
    La concorrenza vera non è qui: è nel listino, dove chi compra prima alza il prezzo a
    chi viene dopo. Qui c'è il resto — quello che si fa a una persona invece che a un
    prezzo. <strong>Costa caro</strong>: ogni aggressione alza di uno il tuo numero di
    pubblico nemico, e quello non scende col tempo.
  </p>

  <?php if ($padrone !== null): ?>
    <p class="sommario">Questa piazza è roba di <strong><?= e($padrone['nome']) ?></strong>
       (<?= e($padrone['sigla']) ?>) dal <?= e(fmt_date($padrone['dal'])) ?>.
       <?php if ((int) ($p['batteria_id'] ?? 0) !== (int) $padrone['id']): ?>
         <span class="minuto">Su quello che tratti qui, una quota va nella loro cassa.</span>
       <?php endif; ?></p>
  <?php endif; ?>

  <?php if ($altri === []): ?>
    <p class="minuto">Non c'è nessuno. In una piazza vuota si lavora tranquilli e non si impara niente.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Chi</th><th>Batteria</th><th class="num">Pubblico nemico</th><th>Cosa puoi fargli</th></tr></thead>
        <tbody>
        <?php foreach ($altri as $a): $fuori = $a['ospedale_fino_a'] !== null || $a['carcere_fino_a'] !== null; ?>
          <tr>
            <td><a href="<?= e(url('/profilo/' . $a['id'])) ?>"><strong><?= e($a['username']) ?></strong></a>
                <?php if ($fuori): ?><br><span class="minuto">fuori gioco</span><?php endif; ?></td>
            <td class="minuto"><?= e((string) ($a['batteria'] ?? '—')) ?></td>
            <td class="num"><?= (int) $a['profilo'] ?></td>
            <td>
              <?php if (!$inOspedale): ?>
              <form method="post" action="<?= e(url('/altri/attacca')) ?>" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="chi" value="<?= (int) $a['id'] ?>">
                <button class="bottone--minuto" <?= $fuori ? 'disabled' : '' ?>
                        title="Ti prendi quello che ha addosso. E un punto di pubblico nemico.">addosso</button>
              </form>
              <form method="post" action="<?= e(url('/altri/soffiata')) ?>" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="chi" value="<?= (int) $a['id'] ?>">
                <button class="bottone--fantasma bottone--minuto"
                        title="<?= e(lire($prezzi['soffiata'])) ?> · una su quattro si ritorce">soffiata</button>
              </form>
              <?php if ($uomini !== [] && (int) $a['spiato'] === 0): ?>
                <form method="post" action="<?= e(url('/altri/infiltra')) ?>" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="chi" value="<?= (int) $a['id'] ?>">
                  <select name="uomo" style="padding:.2rem;font-size:.7rem;max-width:8rem">
                    <?php foreach ($uomini as $u): ?>
                      <option value="<?= (int) $u['id'] ?>"><?= e($u['nome']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="bottone--fantasma bottone--minuto"
                          title="<?= e(lire($prezzi['spia'])) ?> · lo perdi comunque">infiltra</button>
                </form>
              <?php elseif ((int) $a['spiato'] > 0): ?>
                <span class="stato stato--attivo">hai uno dentro</span>
              <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php $sp = $spiati[(int) $a['id']] ?? null; if ($sp !== null): ?>
          <tr><td colspan="4" style="background:var(--carta-3)">
            <span class="occhiello" style="margin:0"><?= e($sp['spia']) ?> riferisce</span>
            <div class="griglia griglia--3" style="margin-top:.5rem">
              <div class="dato"><dt>In tasca</dt><dd style="font-size:.9rem"><?= e(lire($sp['sporco'])) ?></dd></div>
              <div class="dato"><dt>Pulito</dt><dd style="font-size:.9rem"><?= e(lire($sp['pulito'])) ?></dd></div>
              <div class="dato"><dt>Debito</dt><dd style="font-size:.9rem"><?= e(lire($sp['debito'])) ?></dd></div>
              <div class="dato"><dt>Scotta</dt><dd style="font-size:.9rem"><?= e(App\Sim\Calore::aParole($sp['calore'])) ?></dd></div>
              <div class="dato"><dt>Uomini</dt><dd style="font-size:.9rem"><?= (int) $sp['uomini'] ?></dd></div>
              <div class="dato"><dt>Addosso</dt><dd style="font-size:.9rem">
                <?= $sp['carico'] === [] ? 'niente' : e(implode(', ', array_map(
                    static fn($c) => quantita($c['quantita']) . ' ' . mb_strtolower($c['bene']['nome']), $sp['carico']))) ?>
              </dd></div>
            </div>
          </td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($corse !== [] && !$inOspedale): ?>
<div class="foglio">
  <span class="occhiello">Roba per strada</span>
  <h2 style="margin-top:0">Corse altrui che passano di qui</h2>
  <p class="sommario minuto">
    Colpire un carico non manda nessuno all'ospedale: è il modo di farsi male a vicenda
    che lascia tutti in piedi. Scotta lo stesso, e il corriere racconta cosa ha visto.
  </p>
  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>Di chi</th><th>Cosa</th><th>Rotta</th><th class="num">Arriva</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($corse as $c): ?>
        <tr>
          <td><?= e($c['padrone']) ?></td>
          <td class="minuto"><?= e(quantita((int) $c['quantita'])) ?> <?= e($c['bene']) ?></td>
          <td class="minuto"><?= e($c['da_piazza']) ?> → <?= e($c['a_piazza']) ?></td>
          <td class="num minuto"><?= e(fmt_dt($c['arrivo_at'])) ?></td>
          <td>
            <form method="post" action="<?= e(url('/altri/rapina')) ?>">
              <?= csrf_field() ?><input type="hidden" name="corsa" value="<?= (int) $c['id'] ?>">
              <button class="bottone--fantasma bottone--minuto">fermalo</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
