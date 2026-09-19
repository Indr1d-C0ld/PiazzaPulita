<?php /** @var array $u @var list<array<string,mixed>> $registro */ ?>
<?= partial('nav_admin') ?>

<div class="foglio">
  <span class="occhiello">Utente #<?= (int) $u['id'] ?></span>
  <h1><?= e($u['username']) ?></h1>

  <div class="tabella-avvolgi">
    <table class="tabella">
      <tbody>
        <tr><th>E-mail</th><td class="cifra"><?= e($u['email']) ?></td></tr>
        <tr><th>Stato</th><td><?= e($u['status']) ?></td></tr>
        <tr><th>Ruolo</th><td><?= e($u['role']) ?></td></tr>
        <tr><th>Confermato</th><td><?= e(fmt_dt($u['email_verified_at'])) ?></td></tr>
        <tr><th>Iscritto</th><td><?= e(fmt_dt($u['created_at'])) ?></td></tr>
        <tr><th>Ultimo accesso</th><td><?= e(fmt_dt($u['last_login_at'])) ?></td></tr>
        <tr><th>Visto</th><td><?= e(fmt_dt($u['last_seen_at'])) ?></td></tr>
        <tr><th>Verifiche inviate</th><td class="cifra"><?= (int) $u['verify_count'] ?></td></tr>
      </tbody>
    </table>
  </div>

  <h2>Azioni</h2>
  <div class="azioni">
    <?php
    $azioni = [
        'conferma' => 'Conferma a mano',
        'riattiva' => 'Riattiva',
        'sospendi' => 'Sospendi',
        'revoca'   => 'Revoca',
        'promuovi' => 'Promuovi ad admin',
        'degrada'  => 'Riporta a giocatore',
    ];
    foreach ($azioni as $chiave => $etichetta): ?>
      <form method="post" action="<?= e(url('/admin/utente')) ?>" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <input type="hidden" name="azione" value="<?= $chiave ?>">
        <button type="submit" class="bottone--fantasma bottone--minuto"><?= e($etichetta) ?></button>
      </form>
    <?php endforeach; ?>
  </div>
  <p class="minuto" style="margin-top:.8rem">Sospensione, revoca e degrado non si possono
     applicare al proprio account: chiudersi fuori dal pannello si rimedia solo da riga di comando.</p>
</div>

<div class="foglio">
  <span class="occhiello">Ultime azioni</span>
  <?php if ($registro === []): ?>
    <p class="minuto">Nessuna azione registrata.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Quando</th><th>Azione</th><th>Oggetto</th></tr></thead>
        <tbody>
        <?php foreach ($registro as $r): ?>
          <tr>
            <td class="minuto"><?= e(fmt_dt($r['created_at'], true)) ?></td>
            <td class="cifra"><?= e($r['action']) ?></td>
            <td class="minuto"><?= e(trim(($r['target_type'] ?? '') . ' ' . ($r['target_id'] ?? ''))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
