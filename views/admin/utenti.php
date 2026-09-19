<?php /** @var list<array<string,mixed>> $righe */ ?>
<?= partial('nav_admin') ?>

<div class="foglio">
  <span class="occhiello">Utenti</span>
  <h1>Registro degli iscritti</h1>

  <form method="get" action="<?= e(url('/admin/utenti')) ?>" style="margin-bottom:1.2rem">
    <div class="campo" style="max-width:22rem;margin:0">
      <label for="q">Cerca per nome o e-mail</label>
      <input type="text" id="q" name="q" value="<?= e($q) ?>">
    </div>
  </form>

  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>#</th><th>Nome</th><th>E-mail</th><th>Stato</th><th>Ruolo</th><th>Iscritto</th><th>Visto</th></tr></thead>
      <tbody>
      <?php foreach ($righe as $r): ?>
        <tr>
          <td class="num"><?= (int) $r['id'] ?></td>
          <td><a href="<?= e(url('/admin/utente/' . $r['id'])) ?>"><?= e($r['username']) ?></a></td>
          <td class="cifra minuto"><?= e($r['email']) ?></td>
          <td><?php
            $cl = ['active' => 'attivo', 'pending' => 'attesa', 'suspended' => 'sospeso', 'banned' => 'sospeso'][$r['status']] ?? 'attesa';
            echo '<span class="stato stato--' . $cl . '">' . e($r['status']) . '</span>';
          ?></td>
          <td class="minuto"><?= e($r['role']) ?></td>
          <td class="minuto"><?= e(fmt_date($r['created_at'])) ?></td>
          <td class="minuto"><?= e(fmt_dt($r['last_seen_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($righe === []): ?>
        <tr><td colspan="7" class="minuto">Nessun risultato.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
