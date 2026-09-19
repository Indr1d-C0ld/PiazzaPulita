<div class="foglio foglio--stretto">
  <span class="occhiello">Quasi fatto</span>
  <h1>Controlla la posta</h1>
  <p class="sommario">
    <?php if (!empty($email)): ?>
      Abbiamo scritto a <strong><?= e($email) ?></strong>.
    <?php else: ?>
      Abbiamo scritto all'indirizzo che hai indicato.
    <?php endif; ?>
    Dentro c'è un collegamento: aprilo e sei dentro.
  </p>
  <p class="minuto">Se non arriva entro qualche minuto, guarda nella posta indesiderata.
     Dalla pagina di accesso puoi chiederne un altro.</p>
  <div class="azioni"><a class="bottone" href="<?= e(url('/accesso')) ?>">Vai all'accesso</a></div>
</div>
