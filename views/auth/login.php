<div class="foglio foglio--stretto">
  <span class="occhiello">Accesso</span>
  <h1>Bentornato</h1>

  <form method="post" action="<?= e(url('/accesso')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="login">Nome o e-mail</label>
      <input type="text" id="login" name="login" value="<?= e(old('login')) ?>" required autofocus>
    </div>
    <div class="campo">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <div class="azioni">
      <button type="submit">Entra</button>
      <a class="bottone bottone--fantasma" href="<?= e(url('/iscrizione')) ?>">Iscriviti</a>
    </div>
  </form>
</div>

<?php $needVerify = flash('need_verify'); if (is_string($needVerify) && $needVerify !== ''): ?>
<div class="foglio foglio--stretto">
  <h2 style="margin-top:0">Conferma non ancora arrivata?</h2>
  <p class="sommario minuto">Possiamo rimandarti il collegamento di verifica.</p>
  <form method="post" action="<?= e(url('/rinvia-verifica')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="login" value="<?= e($needVerify) ?>">
    <div class="azioni"><button type="submit" class="bottone--fantasma">Mandalo di nuovo</button></div>
  </form>
</div>
<?php endif; ?>
