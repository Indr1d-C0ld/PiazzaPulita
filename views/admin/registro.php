<?php /** @var list<array<string,mixed>> $righe */ ?>
<?= partial('nav_admin') ?>

<div class="foglio">
  <span class="occhiello">Registro</span>
  <h1>Azioni rilevanti</h1>
  <p class="sommario minuto">Le ultime duecento. Sopravvive alla cancellazione degli
     account: è il motivo per cui <code>audit_log</code> non ha vincolo di chiave esterna.</p>

  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>Quando</th><th>Chi</th><th>Azione</th><th>Oggetto</th><th>Dettagli</th></tr></thead>
      <tbody>
      <?php foreach ($righe as $r): ?>
        <tr>
          <td class="minuto"><?= e(fmt_dt($r['created_at'], true)) ?></td>
          <td><?= e($r['username'] ?? '—') ?></td>
          <td class="cifra"><?= e($r['action']) ?></td>
          <td class="minuto"><?= e(trim(($r['target_type'] ?? '') . ' ' . ($r['target_id'] ?? ''))) ?></td>
          <td class="minuto" style="max-width:22rem;overflow-wrap:anywhere"><?= e((string) ($r['meta'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($righe === []): ?><tr><td colspan="5" class="minuto">Registro vuoto.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
