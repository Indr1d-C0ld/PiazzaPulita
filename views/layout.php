<?php
/** @var string $content @var string $title */
$u = auth_check() ? auth_user() : null;
$luce = is_array($u) ? (string) ($u['luce'] ?? 'auto') : 'auto';
?>
<!doctype html>
<html lang="it"<?= $luce !== 'auto' ? ' data-luce="' . e($luce) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e($title ?? 'Piazza Pulita') ?> · Piazza Pulita</title>
<meta name="description" content="Piazza Pulita — gioco di commercio, rischio e territorio nell'Italia degli anni ottanta.">
<link rel="stylesheet" href="<?= e(asset('css/piazzapulita.css')) ?>">
<link rel="icon" href="<?= e(asset('img/icona.svg')) ?>" type="image/svg+xml">
</head>
<body>

<header class="testata">
  <div class="testata-corpo">
    <a class="marchio" href="<?= e(url('/')) ?>">
      <b>Piazza Pulita</b>
      <span>Italia · anni ottanta</span>
    </a>
    <nav class="nav-alto">
      <?php if ($u !== null): ?>
        <span class="chi"><?= e($u['username'] ?? '') ?></span>
        <a href="<?= e(url('/strada')) ?>">La strada</a>
        <a href="<?= e(url('/mappa')) ?>">Mappa</a>
        <a href="<?= e(url('/altri')) ?>">Altri</a>
        <a href="<?= e(url('/batteria')) ?>">Batteria</a>
        <a href="<?= e(url('/affari')) ?>">Affari</a>
        <a href="<?= e(url('/personaggio')) ?>">Tu</a>
        <a href="<?= e(url('/fascicolo')) ?>">Fascicolo</a>
        <a href="<?= e(url('/profilo')) ?>">Profilo</a>
        <a href="<?= e(url('/classifica')) ?>">Classifica</a>
        <?php if (is_admin()): ?><a href="<?= e(url('/admin')) ?>">Amministrazione</a><?php endif; ?>
        <form method="post" action="<?= e(url('/profilo/luce')) ?>" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="luce" value="<?= $luce === 'notte' ? 'carta' : 'notte' ?>">
          <input type="hidden" name="torna" value="<?= e($_SERVER['REQUEST_URI'] ?? '/profilo') ?>">
          <button class="bottone--fantasma bottone--minuto" title="Cambia la luce della pagina">
            <?= $luce === 'notte' ? 'giorno' : 'notte' ?>
          </button>
        </form>
        <form method="post" action="<?= e(url('/esci')) ?>" style="display:inline">
          <?= csrf_field() ?>
          <button class="bottone--fantasma bottone--minuto">Esci</button>
        </form>
      <?php else: ?>
        <a href="<?= e(url('/regole')) ?>">Come funziona</a>
        <a href="<?= e(url('/classifica')) ?>">Classifica</a>
        <a href="<?= e(url('/accesso')) ?>">Accesso</a>
        <a href="<?= e(url('/iscrizione')) ?>">Iscriviti</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main>
<?= partial('flash') ?>
<?= $content ?>
</main>

<footer>
  <div class="colophon">
    <span>Piazza Pulita — gioco di finzione. Progetto personale, nessun fine commerciale.</span>
    <span><a href="<?= e(url('/cronaca')) ?>">Cronaca</a> · <a href="<?= e(url('/statistiche')) ?>">Statistiche</a> · <a href="<?= e(url('/regole')) ?>">Come funziona</a></span>
    <span>Ora di Roma: <?= e(fmt_dt(time())) ?></span>
  </div>
</footer>

<script src="<?= e(asset('js/viaggio.js')) ?>" defer></script>
<script src="<?= e(asset('js/mappa.js')) ?>" defer></script>
<script src="<?= e(asset('js/avatar.js')) ?>" defer></script>

</body>
</html>
