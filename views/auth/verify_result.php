<div class="foglio foglio--stretto">
  <?php if (!empty($ok)): ?>
    <span class="occhiello">Indirizzo confermato</span>
    <h1>Sei dentro</h1>
    <p class="sommario">Il tuo posto è attivo<?= !empty($user['username']) ? ', ' . e($user['username']) : '' ?>.
       Da qui si comincia.</p>
    <div class="azioni"><a class="bottone" href="<?= e(url('/accesso')) ?>">Entra</a></div>
  <?php else: ?>
    <span class="occhiello">Verifica non riuscita</span>
    <h1>Questo collegamento non va</h1>
    <p class="sommario"><?= e($error ?? 'Collegamento non valido.') ?></p>
    <div class="azioni">
      <a class="bottone" href="<?= e(url('/accesso')) ?>">Pagina di accesso</a>
      <a class="bottone bottone--fantasma" href="<?= e(url('/iscrizione')) ?>">Iscriviti</a>
    </div>
  <?php endif; ?>
</div>
