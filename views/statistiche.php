<?php /** @var array<string,mixed> $dati */ ?>
<div class="foglio">
  <span class="occhiello">Statistiche</span>
  <h1>I numeri del mondo</h1>
  <p class="sommario minuto">Per ora sono i numeri delle persone. Quando il mercato
     esisterà, qui si leggeranno il volume scambiato, i prezzi medi per piazza e
     quanto denaro il mondo ha prodotto nell'ultima ora.</p>

  <dl class="griglia griglia--2" style="margin:1.4rem 0 0">
    <?php foreach ($dati as $voce => $valore): ?>
      <div class="dato">
        <dt><?= e($voce) ?></dt>
        <dd<?= is_int($valore) ? '' : ' style="font-size:.95rem"' ?>><?= is_int($valore) ? e(quantita($valore)) : e($valore) ?></dd>
      </div>
    <?php endforeach; ?>
  </dl>
</div>
