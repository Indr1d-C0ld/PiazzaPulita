<?php /** @var array $carta @var array $presenze @var array $piazze @var int $quanti */ ?>
<?= partial('nav_admin') ?>

<div class="foglio">
  <span class="occhiello">Amministrazione</span>
  <h1>La carta globale</h1>
  <p class="sommario minuto">
    Il paese in una schermata: dove sta la gente adesso, e chi è in viaggio. È l'unica
    pagina del gioco che vede tutto insieme — per un giocatore questa sarebbe
    informazione che si paga, ed è il mestiere del basista.
  </p>
  <dl class="griglia griglia--3" style="margin:0 0 1rem">
    <div class="dato"><dt>Personaggi</dt><dd><?= (int) $quanti ?></dd></div>
    <div class="dato"><dt>In viaggio adesso</dt><dd><?= (int) $in_giro ?></dd></div>
    <div class="dato"><dt>Città con qualcuno</dt><dd><?= count($presenze) ?></dd></div>
  </dl>

  <?= partial('carta', ['disegno' => $carta, 'presenze' => $presenze, 'titolo' => 'Presenze']) ?>
</div>

<div class="foglio">
  <span class="occhiello">Un avviso a tutti</span>
  <h2 style="margin-top:0">Scrivere ai giocatori</h2>
  <p class="sommario minuto">
    Il messaggio arriva <strong>dentro il gioco</strong>, come segnale: è il canale che il
    giocatore guarda già. L'e-mail è facoltativa e passa dalla coda come tutto il resto,
    quindi conta verso il tetto giornaliero del provider.
  </p>
  <form method="post" action="<?= e(url('/admin/carta/scrivi')) ?>" class="modulo">
    <?= csrf_field() ?>
    <input type="hidden" name="a" value="tutti">
    <label>Messaggio<input type="text" name="testo" maxlength="220" required
           placeholder="Domani alle 21 il server si ferma un quarto d'ora."></label>
    <label style="display:flex;gap:.5rem;align-items:center">
      <input type="checkbox" name="email" value="1" style="width:auto;min-height:0">
      Mandalo anche per e-mail
    </label>
    <button type="submit">Scrivi a tutti</button>
  </form>
</div>

<div class="foglio">
  <span class="occhiello">Un cartello in una piazza</span>
  <h2 style="margin-top:0">Affiggere</h2>
  <p class="sommario minuto">
    Compare nella voce di quella piazza, marcato come avviso: lo legge chi ci passa,
    finché non si dimentica come tutte le altre voci.
  </p>
  <form method="post" action="<?= e(url('/admin/carta/affiggi')) ?>" class="modulo">
    <?= csrf_field() ?>
    <label>Piazza
      <select name="piazza">
        <?php foreach ($piazze as $id => $dati): ?>
          <option value="<?= (int) $id ?>"><?= e($dati['piazza']) ?> — <?= e($dati['citta']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Testo<input type="text" name="testo" maxlength="220" required></label>
    <button type="submit" class="bottone--fantasma">Affiggi</button>
  </form>
</div>

<div class="foglio foglio--largo">
  <span class="occhiello">Dove sta la gente</span>
  <?php if ($piazze === []): ?>
    <p class="minuto">Nessun personaggio nel mondo.</p>
  <?php else: ?>
    <?php foreach ($piazze as $id => $dati): ?>
      <h3 style="margin:1.2rem 0 .4rem"><?= e($dati['piazza']) ?>
        <span class="minuto"><?= e($dati['citta']) ?> · <?= count($dati['gente']) ?></span></h3>
      <div class="gente">
        <?php foreach ($dati['gente'] as $g): ?>
          <div class="tizio">
            <?php $foto = App\Game\Avatar::url($g['avatar_file'] ?? null); ?>
            <?php if ($foto !== null): ?>
              <img class="avatar tizio-faccia" src="<?= e(asset($foto)) ?>" alt="" width="72" height="72">
            <?php else: ?>
              <div class="avatar tizio-faccia avatar--vuoto" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($g['username'], 0, 1))) ?></div>
            <?php endif; ?>
            <div class="tizio-corpo">
              <a href="<?= e(url('/profilo/' . $g['id'])) ?>"><strong><?= e($g['username']) ?></strong></a>
              <p class="minuto" style="margin:.1rem 0 .4rem">
                <?= $g['batteria'] !== null ? e($g['batteria']) . ' · ' : '' ?>
                <?= e(lire((int) $g['contante'])) ?> in tasca · <?= e(lire((int) $g['pulito'])) ?> puliti ·
                calore <?= e(number_format((float) $g['calore'], 1, ',', '.')) ?> ·
                p.n. <?= (int) $g['profilo'] ?>
                <?php if ($g['arrivo_at'] !== null): ?> · <span class="stato stato--attesa">in viaggio</span><?php endif; ?>
                <?php if ($g['carcere_fino_a'] !== null): ?> · <span class="stato stato--sospeso">dentro</span><?php endif; ?>
                <?php if ($g['ospedale_fino_a'] !== null): ?> · <span class="stato stato--attesa">ospedale</span><?php endif; ?>
                <br>visto <?= e(fmt_dt($g['last_seen_at'])) ?>
              </p>
              <form method="post" action="<?= e(url('/admin/carta/scrivi')) ?>" class="modulo" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="chi" value="<?= (int) $g['id'] ?>">
                <span class="coppia" style="display:flex;gap:.4rem">
                  <input type="text" name="testo" maxlength="220" placeholder="scrivi a <?= e($g['username']) ?>"
                         style="flex:1 1 auto;min-width:0" required>
                  <button class="bottone--minuto">manda</button>
                </span>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
