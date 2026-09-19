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
