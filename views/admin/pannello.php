<?php /** @var array $utenti @var array $posta @var list<array<string,mixed>> $migrazioni */ ?>
<?= partial('nav_admin') ?>

<div class="foglio">
  <span class="occhiello">Amministrazione</span>
  <h1>Pannello</h1>

  <dl class="griglia griglia--3" style="margin:0 0 1.4rem">
    <div class="dato"><dt>Iscritti</dt><dd><?= e(quantita($utenti['totale'])) ?></dd></div>
    <div class="dato"><dt>Attivi</dt><dd><?= e(quantita($utenti['attivi'])) ?></dd></div>
    <div class="dato"><dt>In attesa</dt><dd><?= e(quantita($utenti['attesa'])) ?></dd></div>
    <div class="dato"><dt>Fermati</dt><dd><?= e(quantita($utenti['sospesi'])) ?></dd></div>
    <div class="dato"><dt>Posta in coda</dt><dd><?= e(quantita($posta['in_coda'])) ?></dd></div>
    <div class="dato"><dt>Inviate 24 h</dt><dd><?= e(quantita($posta['inviate_24h'])) ?><span class="minuto"> / <?= e(quantita($posta['tetto'])) ?></span></dd></div>
  </dl>

  <h2>Impianto</h2>
  <div class="tabella-avvolgi">
    <table class="tabella">
      <tbody>
        <tr><th>File di configurazione</th><td class="cifra"><?= e($config) ?></td></tr>
        <tr><th>Database</th><td><?= $db ? '<span class="stato stato--attivo">raggiungibile</span>' : '<span class="stato stato--sospeso">non raggiungibile</span>' ?></td></tr>
        <tr><th>Migrazioni applicate</th><td class="cifra"><?= count($migrazioni) ?><?= $migrazioni ? ' (ultima: ' . e(end($migrazioni)['version']) . ')' : '' ?></td></tr>
        <tr><th>Trasporto e-mail</th><td class="cifra"><?= e($trasporto) ?><?= $trasporto === 'log' ? ' <span class="stato stato--attesa">non spedisce</span>' : '' ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="foglio">
  <span class="occhiello">Parametri di gioco</span>
  <h2 style="margin-top:0">Modifica a caldo</h2>
  <p class="sommario minuto">Cambiano subito, senza deploy. Ogni modifica finisce nel
     <a href="<?= e(url('/admin/registro')) ?>">registro</a> con il valore precedente.</p>

  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>Chiave</th><th>Tipo</th><th>Valore</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($parametri as $chiave => $p): ?>
        <tr>
          <td class="cifra"><?= e($chiave) ?></td>
          <td class="minuto"><?= e($p['type']) ?></td>
          <td>
            <form method="post" action="<?= e(url('/admin/config')) ?>" style="display:flex;gap:.4rem">
              <?= csrf_field() ?>
              <input type="hidden" name="chiave" value="<?= e($chiave) ?>">
              <input type="hidden" name="tipo" value="<?= e($p['type']) ?>">
              <input type="text" name="valore" value="<?= e($p['value']) ?>"
                     style="padding:.25rem .45rem;font-family:var(--mono);font-size:.85rem;
                            background:var(--carta);color:var(--inchiostro);
                            border:1px solid var(--riga-2);border-radius:2px;width:9rem">
              <button class="bottone--fantasma bottone--minuto" type="submit">Salva</button>
            </form>
          </td>
          <td class="minuto"><?= e($p['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
