<?php /** @var list $piazze @var array $bilancio */
$b = $bilancio;
?>
<?= partial('nav_admin') ?>

<div class="foglio">
  <span class="occhiello">Amministrazione</span>
  <h1>Il mondo</h1>
  <p class="sommario minuto">
    Qui non si regala niente. In un mondo a reddito orario finito, contanti
    regalati da un amministratore li pagano gli altri giocatori senza accorgersene:
    queste azioni servono a <strong>rimettere a posto</strong> una piazza rimasta in
    uno stato assurdo, non a premiare o punire.
  </p>
</div>

<div class="foglio">
  <span class="occhiello">Bilanciamento</span>
  <h2 style="margin-top:0">L'invariante del tetto</h2>
  <p class="sommario minuto">Le stesse tre righe di <code>php bin/console.php balance:report</code>,
     che non sono statistiche ma prove.</p>
  <dl class="griglia griglia--3" style="margin:0 0 1rem">
    <div class="dato"><dt>Tetto</dt><dd style="font-size:1rem"><?= e(lire((int) $b['tetto'])) ?></dd></div>
    <div class="dato"><dt>Somma teorica</dt>
      <dd style="font-size:1rem;color:<?= abs($b['scarto']) <= 0.02 ? 'inherit' : 'var(--rosso)' ?>">
        <?= e(lire((int) round($b['teorico']))) ?></dd></div>
    <div class="dato"><dt>Scarto</dt>
      <dd style="font-size:1rem"><?= e(number_format($b['scarto'] * 100, 2, ',', '.')) ?>%</dd></div>
    <div class="dato"><dt>Estrazione reale</dt>
      <dd style="font-size:1rem;color:<?= $b['reale_ora'] > $b['tetto'] ? 'var(--rosso)' : 'inherit' ?>">
        <?= e(lire((int) round($b['reale_ora']))) ?>/h</dd></div>
    <div class="dato"><dt>Attivi in 24 h</dt><dd><?= (int) $b['attivi_24h'] ?></dd></div>
    <div class="dato"><dt>Utilizzo</dt><dd style="font-size:1rem">
      <?= e(number_format($b['utilizzo'] * 100, 1, ',', '.')) ?>%</dd></div>
  </dl>
  <p class="minuto">
    <?php if ((int) $b['attivi_24h'] < 3): ?>
      L'utilizzo non è misurabile con meno di tre giocatori attivi: con uno solo il mondo
      risulta sempre «troppo generoso», e non è un difetto di taratura.
    <?php else: ?>
      Per giocatore: <?= e(lire((int) round($b['per_giocatore']))) ?> l'ora — i profili di
      riferimento sono 250 k (principiante), 1,2 M (medio), 4,5 M (maturo).
    <?php endif; ?>
    <?php if ($b['fuori_banda'] !== []): ?>
      <br><?= count($b['fuori_banda']) ?> nodi fuori dalla banda di giocabilità.
    <?php endif; ?>
  </p>
  <form method="post" action="<?= e(url('/admin/mondo')) ?>" class="azioni" style="margin-top:1rem">
    <?= csrf_field() ?>
    <button name="azione" value="raffredda_tutto" class="bottone--fantasma bottone--minuto">
      Raffredda tutte le piazze
    </button>
  </form>
</div>

<div class="foglio foglio--largo">
  <span class="occhiello">Le piazze</span>
  <h2 style="margin-top:0"><?= count($piazze) ?> piazze in <?= count($citta) ?> città</h2>
  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr>
        <th>Piazza</th><th>Città</th><th>Tipo</th><th class="num">Polizia</th>
        <th class="num">Calore</th><th class="num">Gente</th><th class="num">Nodi</th>
        <th>Tenuta da</th><th>Azioni</th>
      </tr></thead>
      <tbody>
      <?php foreach ($piazze as $z): ?>
        <tr>
          <td><?= e($z['nome']) ?></td>
          <td class="minuto"><?= e($z['citta']) ?></td>
          <td class="minuto"><?= e($z['tipo']) ?></td>
          <td class="num"><?= (int) $z['polizia'] ?>%</td>
          <td class="num" style="color:<?= (float) $z['calore'] > 60 ? 'var(--rosso)'
              : ((float) $z['calore'] > 20 ? 'var(--ambra)' : 'inherit') ?>">
            <?= e(number_format((float) $z['calore'], 1, ',', '.')) ?></td>
          <td class="num"><?= (int) $z['gente'] ?></td>
          <td class="num minuto"><?= (int) $z['nodi'] ?></td>
          <td class="minuto"><?= e((string) ($z['padrone'] ?? '—')) ?></td>
          <td>
            <form method="post" action="<?= e(url('/admin/mondo')) ?>" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="piazza" value="<?= (int) $z['id'] ?>">
              <button name="azione" value="raffredda" class="bottone--fantasma bottone--minuto">raffredda</button>
              <button name="azione" value="equilibrio" class="bottone--fantasma bottone--minuto"
                      title="Riporta giacenze e assorbimento all'equilibrio">equilibrio</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
