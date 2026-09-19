<?php /** @var list $righe */ ?>
<div class="foglio">
  <span class="occhiello">La cronaca</span>
  <h1>Quello che è successo</h1>
  <p class="sommario minuto">
    Il notiziario del mondo, uguale per tutti. Senza, metà di quello che accade sarebbe
    invisibile — e un mondo condiviso che non si vede tanto vale non averlo.
  </p>

  <?php if ($righe === []): ?>
    <p class="minuto">Ancora niente. Il paese è tranquillo, e non è una buona notizia per nessuno.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <tbody>
        <?php foreach ($righe as $r): ?>
          <tr>
            <td class="minuto" style="white-space:nowrap;width:9rem"><?= e(fmt_dt($r['fatto_at'])) ?></td>
            <td style="border-left:3px solid <?= (int) $r['rilievo'] >= 4 ? 'var(--rosso)'
                : ((int) $r['rilievo'] >= 3 ? 'var(--ambra)' : 'var(--riga-2)') ?>;padding-left:.7rem">
              <?= e($r['testo']) ?>
              <?php if ($r['piazza'] !== null): ?>
                <span class="minuto">— <?= e($r['piazza']) ?><?= $r['citta'] !== null ? ', ' . e($r['citta']) : '' ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
