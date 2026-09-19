<?php /** @var array $stato @var list<array<string,mixed>> $righe */ ?>
<?= partial('nav_admin') ?>

<div class="foglio">
  <span class="occhiello">Posta in uscita</span>
  <h1>Coda di spedizione</h1>

  <dl class="griglia griglia--3" style="margin:0 0 1.2rem">
    <div class="dato"><dt>In coda</dt><dd><?= e(quantita($stato['in_coda'])) ?></dd></div>
    <div class="dato"><dt>Inviate 24 h</dt><dd><?= e(quantita($stato['inviate_24h'])) ?><span class="minuto"> / <?= e(quantita($stato['tetto'])) ?></span></dd></div>
    <div class="dato"><dt>Rinunciate</dt><dd><?= e(quantita($stato['rinunciate'])) ?></dd></div>
  </dl>

  <form method="post" action="<?= e(url('/admin/posta/smista')) ?>">
    <?= csrf_field() ?>
    <div class="azioni" style="margin:0"><button type="submit" class="bottone--fantasma">Smista adesso</button></div>
  </form>
</div>

<div class="foglio">
  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>#</th><th>Destinatario</th><th>Genere</th><th>Tent.</th><th>Stato</th><th>Quando</th><th>Errore</th></tr></thead>
      <tbody>
      <?php foreach ($righe as $m): ?>
        <tr>
          <td class="num"><?= (int) $m['id'] ?></td>
          <td class="cifra minuto"><?= e($m['destinatario']) ?></td>
          <td class="minuto"><?= e($m['genere']) ?></td>
          <td class="num"><?= (int) $m['tentativi'] ?></td>
          <td><?php
            if ($m['inviato_at'] !== null)          { echo '<span class="stato stato--attivo">inviata</span>'; }
            elseif ($m['rinunciato_at'] !== null)   { echo '<span class="stato stato--sospeso">rinunciata</span>'; }
            else                                    { echo '<span class="stato stato--attesa">in coda</span>'; }
          ?></td>
          <td class="minuto"><?= e(fmt_dt($m['inviato_at'] ?? $m['prossimo_at'])) ?></td>
          <td class="minuto"><?= e($m['ultimo_errore'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($righe === []): ?><tr><td colspan="7" class="minuto">Coda vuota.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
