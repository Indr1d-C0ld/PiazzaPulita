<div class="foglio foglio--stretto">
  <span class="occhiello">Iscrizione</span>
  <h1>Entra nel giro</h1>

  <?php if (empty($open)): ?>
    <p class="sommario">Le iscrizioni sono chiuse al momento. Riprova più avanti.</p>
  <?php else: ?>
    <p class="sommario minuto">Serve un indirizzo e-mail vero: senza conferma l'account
       non si attiva. Non riceverai altro che i messaggi del gioco.</p>

    <form method="post" action="<?= e(url('/iscrizione')) ?>">
      <?= csrf_field() ?>
      <div class="campo">
        <label for="username">Come ti fai chiamare</label>
        <input type="text" id="username" name="username" value="<?= e(old('username')) ?>"
               maxlength="32" required autofocus>
        <p class="aiuto">Da 3 a 32 caratteri. Gli spazi vanno bene.</p>
      </div>
      <div class="campo">
        <label for="email">Indirizzo e-mail</label>
        <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" maxlength="190" required>
      </div>
      <div class="campo">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="new-password">
        <p class="aiuto">Almeno <?= (int) ($minPassword ?? 9) ?> caratteri.</p>
      </div>
      <div class="campo">
        <label for="password_confirm">Ripeti la password</label>
        <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
      </div>
      <div class="azioni">
        <button type="submit">Iscriviti</button>
        <a class="bottone bottone--fantasma" href="<?= e(url('/accesso')) ?>">Ho già un posto</a>
      </div>
    </form>
  <?php endif; ?>
</div>
