<?php /** @var list $righe */ ?>
<?= partial('nav_admin') ?>

<div class="foglio">
  <span class="occhiello">Amministrazione</span>
  <h1>I giocatori</h1>
  <p class="sommario minuto">
    Un personaggio per account, e non si ricrea mai da solo: in questo mondo non si
    azzera niente, nemmeno le persone. Da qui si rimette in piedi chi è rimasto
    incastrato — e si cancella un personaggio, se il giocatore vuole ricominciare.
  </p>
</div>

<div class="foglio foglio--largo">
  <?php if ($righe === []): ?>
    <p class="minuto">Nessun personaggio: il mondo è vuoto.</p>
  <?php else: ?>
  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr>
        <th>Chi</th><th>Dove</th><th class="num">Contante</th><th class="num">Pulito</th>
        <th class="num">Debito</th><th class="num">Calore</th><th class="num">P.N.</th>
        <th>Stato</th><th>Azioni</th>
      </tr></thead>
      <tbody>
      <?php foreach ($righe as $p): ?>
        <tr>
          <td>
              <?php $foto = App\Game\Avatar::url($p['avatar_file'] ?? null); ?>
              <span style="display:flex;gap:.5rem;align-items:center">
                <?php if ($foto !== null): ?>
                  <img class="avatar" src="<?= e(asset($foto)) ?>" alt="" width="36" height="36"
                       style="width:36px;height:36px;object-fit:cover;flex:0 0 36px">
                <?php endif; ?>
                <a href="<?= e(url('/profilo/' . $p['id'])) ?>"><?= e($p['username']) ?></a>
              </span>
              <form method="post" action="<?= e(url('/admin/foto')) ?>" enctype="multipart/form-data"
                    style="margin-top:.35rem;display:flex;gap:.3rem;flex-wrap:wrap;align-items:center">
                <?= csrf_field() ?>
                <input type="hidden" name="chi" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="torna" value="<?= e(url('/admin/giocatori')) ?>">
                <input type="file" name="foto" accept="image/jpeg,image/png,image/webp"
                       style="max-width:9rem;font-size:.7rem">
                <button name="azione" value="sostituisci" class="bottone--fantasma bottone--minuto">metti</button>
                <?php if ($foto !== null): ?>
                  <button name="azione" value="togli" class="bottone--fantasma bottone--minuto">togli foto</button>
                <?php endif; ?>
              </form>
              <?php if ($p['batteria'] !== null): ?>
                <span class="minuto">(<?= e($p['batteria']) ?>)</span><?php endif; ?>
              <?php if ((string) $p['status'] !== 'active'): ?>
                <br><span class="stato stato--sospeso"><?= e($p['status']) ?></span><?php endif; ?></td>
          <td class="minuto"><?= e($p['piazza']) ?>, <?= e($p['citta']) ?>
              <?= $p['arrivo_at'] !== null ? '<br>in viaggio' : '' ?></td>
          <td class="num"><?= e(lire((int) $p['contante'])) ?></td>
          <td class="num"><?= e(lire((int) $p['pulito'])) ?></td>
          <td class="num"><?= e(lire((int) $p['debito'])) ?></td>
          <td class="num"><?= e(number_format((float) $p['calore'], 1, ',', '.')) ?></td>
          <td class="num"><?= (int) $p['profilo'] ?></td>
          <td class="minuto">
            <?php if ($p['carcere_fino_a'] !== null): ?><span class="stato stato--sospeso">dentro</span><br><?php endif; ?>
            <?php if ($p['ospedale_fino_a'] !== null): ?><span class="stato stato--attesa">ospedale</span><br><?php endif; ?>
            <?php if ((int) $p['fascicoli'] > 0): ?><?= (int) $p['fascicoli'] ?> fascicoli<?php endif; ?>
          </td>
          <td>
            <form method="post" action="<?= e(url('/admin/giocatori')) ?>" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button name="azione" value="libera" class="bottone--fantasma bottone--minuto">libera</button>
              <button name="azione" value="dimetti" class="bottone--fantasma bottone--minuto">dimetti</button>
              <button name="azione" value="raffredda" class="bottone--fantasma bottone--minuto">raffredda</button>
              <button name="azione" value="archivia" class="bottone--fantasma bottone--minuto">archivia</button>
            </form>
            <form method="post" action="<?= e(url('/admin/giocatori')) ?>" style="display:inline-flex;gap:.3rem">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <input type="text" name="conferma" placeholder="cancella"
                     style="width:5.5rem;padding:.2rem;font-size:.68rem"
                     aria-label="scrivi cancella per confermare">
              <button name="azione" value="cancella" class="bottone--minuto">cancella</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
