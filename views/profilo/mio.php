<?php /** @var array $utente @var ?string $avatar */ ?>
<div class="foglio">
  <span class="occhiello">Il tuo profilo</span>
  <h1><?= e($utente['username']) ?></h1>

  <dl class="griglia griglia--3" style="margin:0 0 1.4rem">
    <div class="dato"><dt>Iscritto</dt><dd style="font-size:.95rem"><?= e(fmt_date($utente['created_at'])) ?></dd></div>
    <div class="dato"><dt>Indirizzo</dt><dd style="font-size:.95rem"><?= e($utente['email']) ?></dd></div>
    <div class="dato"><dt>Confermato</dt><dd style="font-size:.95rem"><?= e(fmt_date($utente['email_verified_at'])) ?></dd></div>
  </dl>

  <form method="post" action="<?= e(url('/profilo/nota')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="nota">Due righe su di te</label>
      <textarea id="nota" name="nota" maxlength="<?= (int) $notaMax ?>"
                placeholder="Le leggeranno gli altri giocatori sul tuo profilo."><?= e($utente['nota'] ?? '') ?></textarea>
      <p class="aiuto">Massimo <?= (int) $notaMax ?> caratteri.</p>
    </div>
    <div class="azioni"><button type="submit">Salva</button></div>
  </form>
</div>

<div class="foglio">
  <span class="occhiello">La fotografia</span>
  <h2 style="margin-top:0">Il tuo volto</h2>
  <p class="sommario minuto">
    Sta sulla vetrina pubblica: la vedono gli altri giocatori. Scegli una foto,
    trascinala dentro il riquadro per centrarla e stringi o allarga con la barra.
    Quello che entra nel quadrato è quello che si vedrà.
  </p>

  <div class="griglia griglia--2">
    <div>
      <?php if ($avatar !== null): ?>
        <img class="avatar avatar--grande" src="<?= e(asset($avatar)) ?>" alt="La tua fotografia" width="<?= (int) $lato ?>" height="<?= (int) $lato ?>">
        <form method="post" action="<?= e(url('/profilo/foto/togli')) ?>" style="margin-top:.8rem">
          <?= csrf_field() ?>
          <button class="bottone--fantasma bottone--minuto">Togli la fotografia</button>
        </form>
      <?php else: ?>
        <div class="avatar avatar--grande avatar--vuoto" aria-hidden="true">
          <?= e(mb_strtoupper(mb_substr($utente['username'], 0, 1))) ?>
        </div>
        <p class="minuto" style="margin-top:.6rem">Nessuna fotografia: sul profilo compare l'iniziale.</p>
      <?php endif; ?>
    </div>

    <form method="post" action="<?= e(url('/profilo/foto')) ?>" enctype="multipart/form-data" data-ritaglio>
      <?= csrf_field() ?>
      <div class="campo">
        <label for="foto">Scegli una fotografia</label>
        <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp" required>
        <p class="aiuto">JPEG, PNG o WebP, fino a 6 MB. Gli SVG non si accettano.</p>
        <p class="aiuto" style="color:var(--rosso)" data-avviso></p>
      </div>

      <div class="cornice" hidden><img alt="" draggable="false"></div>

      <div data-comandi hidden>
        <div class="campo" style="margin-top:.8rem">
          <label for="zoom">Stringi o allarga</label>
          <input type="range" id="zoom" min="1" max="4" step="0.02" value="1">
        </div>
      </div>

      <input type="hidden" name="sx" value="0">
      <input type="hidden" name="sy" value="0">
      <input type="hidden" name="lato" value="0">

      <div class="azioni"><button type="submit">Metti questa</button></div>
      <noscript>
        <p class="minuto">Senza JavaScript il riquadro non c'è: la fotografia viene
           ritagliata quadrata e centrata da sola.</p>
      </noscript>
    </form>
  </div>
</div>

<div class="foglio">
  <span class="occhiello">Luce della pagina</span>
  <p class="sommario minuto">Segue il tuo dispositivo se non scegli. La preferenza resta
     sul tuo account, non sul browser: vale anche dal telefono.</p>
  <form method="post" action="<?= e(url('/profilo/luce')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="luce">Preferenza</label>
      <select id="luce" name="luce">
        <?php foreach (['auto' => 'Come il dispositivo', 'carta' => 'Carta (chiaro)', 'notte' => 'Notte (scuro)'] as $v => $etichetta): ?>
          <option value="<?= $v ?>"<?= ($utente['luce'] ?? 'auto') === $v ? ' selected' : '' ?>><?= e($etichetta) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" name="torna" value="/profilo">
    <div class="azioni"><button type="submit" class="bottone--fantasma">Applica</button></div>
  </form>
</div>
