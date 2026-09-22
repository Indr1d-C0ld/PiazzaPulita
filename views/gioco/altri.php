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
    <div class="gente">
      <?php foreach ($altri as $a): $fuori = $a['ospedale_fino_a'] !== null || $a['carcere_fino_a'] !== null; ?>
        <div class="tizio <?= $fuori ? 'tizio--fuori' : '' ?>">
          <?php if (($a['avatar'] ?? null) !== null): ?>
            <img class="avatar tizio-faccia" src="<?= e(asset($a['avatar'])) ?>" alt="" width="72" height="72">
          <?php else: ?>
            <div class="avatar tizio-faccia avatar--vuoto" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($a['username'], 0, 1))) ?></div>
          <?php endif; ?>

          <div class="tizio-corpo">
            <a href="<?= e(url('/profilo/' . $a['id'])) ?>"><strong><?= e($a['username']) ?></strong></a>
            <p class="minuto" style="margin:.1rem 0 .4rem">
              <?= $a['batteria'] !== null ? e($a['batteria']) . ' · ' : '' ?>
              pubblico nemico <?= (int) $a['profilo'] ?>
              <?php if ($fuori): ?> · <span class="stato stato--sospeso">fuori gioco</span><?php endif; ?>
            </p>

            <?php if (!$inOspedale): ?>
            <div class="tizio-azioni">
              <?php if (!$fuori && $mioCarico !== []): ?>
                <details>
                  <summary class="bottone bottone--fantasma bottone--minuto">scambio</summary>
                  <form method="post" action="<?= e(url('/altri/baratto')) ?>" class="modulo baratto">
                    <?= csrf_field() ?>
                    <input type="hidden" name="chi" value="<?= (int) $a['id'] ?>">
                    <label>Gli do
                      <span class="coppia">
                        <input type="number" name="quanto_dato" min="1" value="1" aria-label="quante gliene do">
                        <select name="bene_dato">
                          <?php foreach ($mioCarico as $c): ?>
                            <option value="<?= (int) $c['bene']['id'] ?>"><?= e($c['bene']['nome']) ?> (ne hai <?= e(quantita((int) $c['quantita'])) ?>)</option>
                          <?php endforeach; ?>
                        </select>
                      </span>
                    </label>
                    <label>In cambio di
                      <span class="coppia">
                        <input type="number" name="quanto_chiesto" min="1" value="1" aria-label="quante ne voglio">
                        <select name="bene_chiesto">
                          <?php foreach ($beni as $b): ?>
                            <option value="<?= (int) $b['id'] ?>"><?= e($b['nome']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </span>
                    </label>
                    <button class="bottone--minuto">Proponi lo scambio</button>
                  </form>
                </details>
              <?php endif; ?>

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
                  <select name="uomo" style="padding:.2rem;font-size:.75rem;max-width:8rem">
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
            </div>
            <?php endif; ?>

            <?php $sp = $spiati[(int) $a['id']] ?? null; if ($sp !== null): ?>
              <div class="rapporto">
                <span class="occhiello" style="margin:0"><?= e($sp['spia']) ?> riferisce</span>
                <div class="griglia griglia--3" style="margin-top:.4rem">
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
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($baratti !== []): ?>
<div class="foglio">
  <span class="occhiello">Scambi in ballo</span>
  <h2 style="margin-top:0">Merce contro merce</h2>
  <p class="sommario minuto">
    Nel baratto non girano soldi, mai: sposta roba fra due carichi e basta. È il motivo
    per cui esiste — ti libera di quello che qui non assorbe nessuno, senza aprire una
    seconda cassa che scavalcherebbe i canali.
  </p>
  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>Chi</th><th>Dà</th><th>Chiede</th><th class="num">Scade</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($baratti as $b): $mio = (int) $b['da_id'] === (int) $p['id']; ?>
        <tr>
          <td><?= $mio ? 'tu → ' . e($b['a_nome']) : e($b['da_nome']) . ' → te' ?></td>
          <td><?= e(quantita((int) $b['quanto_dato'])) ?> <?= e($b['nome_dato']) ?></td>
          <td><?= e(quantita((int) $b['quanto_chiesto'])) ?> <?= e($b['nome_chiesto']) ?></td>
          <td class="num minuto"><?= e(fmt_dt($b['scade_at'])) ?></td>
          <td>
            <form method="post" action="<?= e(url('/altri/baratto/rispondi')) ?>">
              <?= csrf_field() ?><input type="hidden" name="baratto" value="<?= (int) $b['id'] ?>">
              <?php if ($mio): ?>
                <button name="risposta" value="ritira" class="bottone--fantasma bottone--minuto">ritira</button>
              <?php else: ?>
                <button name="risposta" value="accetta" class="bottone--minuto">accetta</button>
                <button name="risposta" value="rifiuta" class="bottone--fantasma bottone--minuto">no</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="foglio">
  <span class="occhiello">Si dice in giro</span>
  <h2 style="margin-top:0">La voce di <?= e($piazza['nome'] ?? 'qui') ?></h2>
  <p class="sommario minuto">
    Si parla per strada, non al telefono: quello che dici lo sente chi è qui adesso, e
    dopo qualche ora non se lo ricorda più nessuno. Per dire una cosa a qualcuno bisogna
    essere dove sta lui — è anche il motivo per cui un basista si fa pagare.
  </p>

  <?php if ($voci === []): ?>
    <p class="minuto">Silenzio.</p>
  <?php else: ?>
    <ul class="voci">
      <?php foreach ($voci as $v): ?>
        <li class="<?= (string) $v['genere'] === 'avviso' ? 'voce--avviso' : '' ?>">
          <span class="voce-ora"><?= e(fmt_dt($v['fatto_at'])) ?></span>
          <strong><?= e($v['autore']) ?></strong>
          <span><?= e($v['testo']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!$inOspedale): ?>
    <form method="post" action="<?= e(url('/altri/parla')) ?>" class="modulo" style="margin-top:.8rem">
      <?= csrf_field() ?>
      <label>Di\' la tua
        <input type="text" name="testo" maxlength="<?= (int) $lunghezzaVoce ?>"
               placeholder="qualcuno cerca roba?" required>
      </label>
      <button class="bottone--minuto">Parla</button>
    </form>
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
