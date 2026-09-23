<?php /** @var array $p @var array|null $mia @var list $elenco @var list $domande @var array|null $miaDomanda */ ?>

<?php if ($mia === null): ?>
<div class="foglio">
  <span class="occhiello">Le batterie</span>
  <h1>Da solo si arriva fin qui</h1>
  <p class="sommario minuto">
    Una batteria è gente che divide il territorio invece di contenderselo. Chi comanda
    una piazza incassa il <?= e(number_format($pizzo * 100, 0, ',', '.')) ?>% su quello
    che ci trattano gli altri — ma il territorio non si prende dichiarandolo: si prende
    lavorandoci, e decade se lo si lascia.
  </p>

  <div class="griglia griglia--2">
    <section class="riquadro">
      <h2>Fondarne una</h2>
      <p class="minuto">Costa <?= e(lire($fondazione)) ?> <strong>puliti</strong>. Hai
         <?= e(lire((int) $p['pulito'])) ?>.</p>
      <form method="post" action="<?= e(url('/batteria/fonda')) ?>" class="modulo">
        <?= csrf_field() ?>
        <label>Nome<input type="text" name="nome" maxlength="40" required></label>
        <label>Sigla<input type="text" name="sigla" maxlength="5" required
               style="text-transform:uppercase"></label>
        <label>Motto<input type="text" name="motto" maxlength="80"></label>
        <button type="submit" <?= (int) $p['pulito'] < $fondazione ? 'disabled' : '' ?>>Fonda</button>
      </form>
    </section>

    <section class="riquadro">
      <h2>Entrare in una</h2>
      <p class="minuto">Non si entra: si chiede. Decide chi comanda, e una domanda alla
         volta — chiederne un'altra ritira la prima.</p>
      <?php if ($miaDomanda !== null): ?>
        <p class="avviso">Hai chiesto di entrare in <strong><?= e($miaDomanda['nome']) ?></strong>
           (<?= e($miaDomanda['sigla']) ?>) il <?= e(fmt_dt($miaDomanda['fatta_at'])) ?>. Aspetti la risposta.</p>
      <?php endif; ?>
      <?php if ($elenco === []): ?>
        <p class="minuto">Non ce n'è ancora nessuna. Il paese è tutto da spartire.</p>
      <?php else: ?>
        <div class="tabella-avvolgi">
          <table class="tabella">
            <thead><tr><th>Batteria</th><th>Capo</th><th class="num">Gente</th><th class="num">Piazze</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($elenco as $b): ?>
              <tr>
                <td><strong><?= e($b['sigla']) ?></strong> <?= e($b['nome']) ?>
                    <?php if ($b['motto'] !== null && $b['motto'] !== ''): ?>
                      <br><span class="minuto">«<?= e($b['motto']) ?>»</span><?php endif; ?></td>
                <td class="minuto"><?= e($b['capo']) ?></td>
                <td class="num"><?= (int) $b['membri'] ?></td>
                <td class="num"><?= (int) $b['piazze'] ?></td>
                <td>
                  <form method="post" action="<?= e(url('/batteria/entra')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="batteria" value="<?= (int) $b['id'] ?>">
                    <button class="bottone--fantasma bottone--minuto">chiedi</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php else: ?>

<div class="foglio">
  <span class="occhiello"><?= e($mia['sigla']) ?><?= $sonoCapo ? ' · comandi tu' : '' ?></span>
  <h1><?= e($mia['nome']) ?></h1>
  <?php if ($mia['motto'] !== null && $mia['motto'] !== ''): ?>
    <p class="sommario">«<?= e($mia['motto']) ?>»</p>
  <?php endif; ?>

  <div class="griglia griglia--3">
    <div class="dato"><dt>Cassa comune</dt><dd><?= e(lire((int) $mia['cassa'])) ?></dd></div>
    <div class="dato"><dt>Gente</dt><dd><?= count($membri) ?></dd></div>
    <div class="dato"><dt>Piazze tenute</dt><dd><?= count($territori) ?></dd></div>
  </div>
</div>

<div class="griglia griglia--2">
  <section class="foglio">
    <h2 style="margin-top:0">La cassa</h2>
    <p class="minuto">Ci si versa <strong>contante</strong> e se ne preleva contante: la
       cassa comune è sporca quanto i soldi che ci entrano. Il pizzo delle piazze tenute
       finisce qui da solo. <?= $sonoCapo
       ? 'Prelevare lo può fare solo chi comanda: sei tu.'
       : 'Prelevare lo può fare solo chi comanda.' ?></p>
    <form method="post" action="<?= e(url('/batteria/cassa')) ?>" class="modulo">
      <?= csrf_field() ?>
      <label>Importo<input type="number" name="importo" min="1" step="1" required></label>
      <div class="bottoniera">
        <button type="submit" name="verso" value="versa">Versa</button>
        <?php if ($sonoCapo): ?>
          <button type="submit" name="verso" value="preleva" class="bottone--fantasma">Preleva</button>
        <?php endif; ?>
      </div>
    </form>
  </section>

  <section class="foglio">
    <h2 style="margin-top:0">Chi c'è dentro</h2>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Chi</th><th class="num">Rispetto</th><th class="num">Timore</th><?= $sonoCapo ? '<th></th>' : '' ?></tr></thead>
        <tbody>
        <?php foreach ($membri as $m): ?>
          <tr>
            <td><a href="<?= e(url('/profilo/' . $m['id'])) ?>"><?= e($m['username']) ?></a>
                <?= (int) $m['id'] === (int) $mia['capo_id'] ? ' <span class="stato stato--attivo">capo</span>' : '' ?></td>
            <td class="num"><?= (int) $m['rispetto'] ?></td>
            <td class="num"><?= (int) $m['timore'] ?></td>
            <?php if ($sonoCapo): ?>
              <td><?php if ((int) $m['id'] !== (int) $mia['capo_id']): ?>
                <form method="post" action="<?= e(url('/batteria/caccia')) ?>">
                  <?= csrf_field() ?><input type="hidden" name="membro" value="<?= (int) $m['id'] ?>">
                  <button class="bottone--fantasma bottone--minuto">caccia</button>
                </form>
              <?php endif; ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($sonoCapo && $domande !== []): ?>
      <h3>Chi chiede di entrare</h3>
      <div class="tabella-avvolgi">
        <table class="tabella">
          <thead><tr><th>Chi</th><th class="num">Rispetto</th><th class="num">Timore</th><th class="num">Profilo</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($domande as $d): ?>
            <tr>
              <td><a href="<?= e(url('/profilo/' . $d['id'])) ?>"><?= e($d['username']) ?></a></td>
              <td class="num"><?= (int) $d['rispetto'] ?></td>
              <td class="num"><?= (int) $d['timore'] ?></td>
              <td class="num"><?= (int) $d['profilo'] ?></td>
              <td>
                <form method="post" action="<?= e(url('/batteria/domanda')) ?>" class="bottoniera">
                  <?= csrf_field() ?><input type="hidden" name="chi" value="<?= (int) $d['id'] ?>">
                  <button name="esito" value="si" class="bottone--minuto">prendilo</button>
                  <button name="esito" value="no" class="bottone--fantasma bottone--minuto">no</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php if ($sonoCapo && count($membri) > 1): ?>
      <form method="post" action="<?= e(url('/batteria/capo')) ?>" class="modulo" style="margin-top:1rem">
        <?= csrf_field() ?>
        <label>Passa la mano a
          <select name="membro" required>
            <?php foreach ($membri as $m): ?>
              <?php if ((int) $m['id'] !== (int) $mia['capo_id']): ?>
                <option value="<?= (int) $m['id'] ?>"><?= e($m['username']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </label>
        <p class="minuto">Chi comanda è l'unico che preleva dalla cassa.
           Un capo con qualcuno dentro, per uscire, prima deve lasciare il posto.</p>
        <button type="submit" class="bottone--fantasma bottone--minuto">Passa la mano</button>
      </form>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/batteria/esci')) ?>" style="margin-top:1rem">
      <?= csrf_field() ?>
      <button class="bottone--fantasma bottone--minuto">Esci dalla batteria</button>
    </form>
  </section>
</div>

<div class="foglio">
  <h2 style="margin-top:0">Il territorio</h2>
  <?php if ($territori === []): ?>
    <p class="minuto">Nessuna piazza, per adesso. Si tengono lavorandoci: ogni lira
       movimentata in una piazza lascia presenza, e la presenza decade in tre giorni.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Piazza</th><th>Città</th><th class="num">Presenza</th><th class="num">Dal</th></tr></thead>
        <tbody>
        <?php foreach ($territori as $t): ?>
          <tr>
            <td><?= e($t['piazza']) ?></td>
            <td class="minuto"><?= e($t['citta']) ?></td>
            <td class="num"><?= e(quantita(round((float) $t['presenza']))) ?></td>
            <td class="num minuto"><?= e(fmt_date($t['dal'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
