<?php
$mappa = [
    'error'   => 'avviso--errore',
    'success' => 'avviso--ok',
    'warning' => 'avviso--attenzione',
    'info'    => '',
];
foreach ($mappa as $chiave => $classe):
    $msg = flash($chiave);
    if (!is_string($msg) || $msg === '') { continue; }
?>
<div class="avviso <?= $classe ?>"><?= e($msg) ?></div>
<?php endforeach; ?>
