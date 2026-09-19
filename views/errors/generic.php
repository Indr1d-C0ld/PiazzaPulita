<div class="foglio foglio--stretto">
  <span class="occhiello">Errore <?= e($status ?? 500) ?></span>
  <h1><?= e($title ?? 'Errore') ?></h1>
  <p class="sommario"><?= e($message ?? 'Si e\' verificato un problema.') ?></p>
  <div class="azioni"><a class="bottone" href="<?= e(url('/')) ?>">Torna in strada</a></div>
</div>
