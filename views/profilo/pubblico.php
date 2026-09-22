<?php /** @var array $p @var ?string $avatar */ ?>
<div class="foglio foglio--stretto">
  <span class="occhiello">Profilo</span>

  <div class="vetrina">
    <?php if ($avatar !== null): ?>
      <img class="avatar avatar--grande" src="<?= e(asset($avatar)) ?>"
           alt="Fotografia di <?= e($p['username']) ?>" width="320" height="320">
    <?php else: ?>
      <div class="avatar avatar--grande avatar--vuoto" aria-hidden="true">
        <?= e(mb_strtoupper(mb_substr($p['username'], 0, 1))) ?>
      </div>
    <?php endif; ?>
    <div>
      <h1 style="margin-bottom:.2rem"><?= e($p['username']) ?></h1>
      <?php if ($p['role'] === 'admin'): ?><span class="stato stato--admin">amministra</span><?php endif; ?>

      <?php if (!empty($admin) && empty($mio)): ?>
        <div class="moderazione">
          <span class="occhiello" style="margin:0">Amministrazione</span>
          <p class="minuto" style="margin:.2rem 0 .5rem">
            Una vetrina con le facce prima o poi ne ospita una che non va bene.
            Togliere non rovina niente: al suo posto torna l'iniziale, e il giocatore
            può ricaricarne un'altra.
          </p>
          <form method="post" action="<?= e(url('/admin/foto')) ?>" class="modulo" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="chi" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="torna" value="<?= e(url('/profilo/' . (int) $p['id'])) ?>">
            <?php if ($avatar !== null): ?>
              <button name="azione" value="togli" class="bottone--fantasma bottone--minuto">Togli la fotografia</button>
            <?php endif; ?>
            <label style="margin-top:.6rem">Sostituiscila
              <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" required>
            </label>
            <button name="azione" value="sostituisci" class="bottone--minuto">Metti questa</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($p['nota'])): ?>
    <blockquote><?= e($p['nota']) ?></blockquote>
  <?php else: ?>
    <p class="minuto">Non ha lasciato detto niente.</p>
  <?php endif; ?>

  <dl class="griglia griglia--2" style="margin:1.2rem 0 0">
    <div class="dato"><dt>In giro da</dt><dd style="font-size:.95rem"><?= e(fmt_date($p['created_at'])) ?></dd></div>
    <div class="dato"><dt>Visto</dt><dd style="font-size:.95rem"><?= e(fmt_dt($p['last_seen_at'])) ?></dd></div>
  </dl>

  <?php if (!empty($mio)): ?>
    <div class="azioni"><a class="bottone bottone--fantasma" href="<?= e(url('/profilo')) ?>">Modifica</a></div>
  <?php endif; ?>
</div>
