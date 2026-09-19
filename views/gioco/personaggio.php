<?php
/** @var array $p @var list $uomini @var list $corse @var array $ruoli */
use App\Sim\Crescita;
use App\Sim\Viaggio;
$attributi = [
    'trattativa'     => ['Trattativa', 'Stringe lo spread: compri meglio e vendi meglio.'],
    'fiuto'          => ['Fiuto', 'Quanto vedi del mercato prima di arrivarci.'],
    'sangue_freddo'  => ['Sangue freddo', 'Controlli e posti di blocco pesano meno.'],
    'organizzazione' => ['Organizzazione', 'Quanti uomini riesci a tenere insieme.'],
    'credito'        => ['Credito', 'Ti prestano di più, e a meno.'],
];
?>
<div class="foglio">
  <span class="occhiello">Chi sei diventato</span>
  <h1><?= e(auth_user()['username'] ?? 'Tu') ?></h1>
  <p class="sommario minuto">
    Non c'è niente da scegliere qui dentro: si cresce con l'uso. Si diventa bravi a
    trattare trattando, e si impara il sangue freddo quando serve. I primi gradi si
    sentono a ogni operazione, gli ultimi quasi più — è voluto.
  </p>

  <div class="tabella-avvolgi">
    <table class="tabella">
      <tbody>
      <?php foreach ($attributi as $k => [$nome, $spiega]): $v = (float) $p[$k]; ?>
        <tr>
          <td style="width:11rem"><strong><?= e($nome) ?></strong><br><span class="minuto"><?= e($spiega) ?></span></td>
          <td>
            <div style="background:var(--carta-3);border:1px solid var(--riga);height:.9rem;border-radius:2px">
              <div style="background:var(--inchiostro-3);width:<?= e(number_format($v, 1, '.', '')) ?>%;height:100%"></div>
            </div>
          </td>
          <td class="num" style="width:9rem"><?= e(Crescita::aParole($v)) ?>
            <span class="minuto"><?= e(number_format($v, 0, ',', '.')) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2>Come ti vedono</h2>
  <dl class="griglia griglia--2" style="margin:0">
    <div class="dato"><dt>Rispetto</dt><dd style="font-size:.95rem"><?= e(Crescita::rispettoAParole((float) $p['rispetto'])) ?></dd></div>
    <div class="dato"><dt>Timore</dt><dd style="font-size:.95rem"><?= e(Crescita::timoreAParole((float) $p['timore'])) ?></dd></div>
  </dl>
  <h2>I fornitori</h2>
  <p class="sommario minuto">
    Sono l'unica cosa del gioco che non si compra: si sbloccano col rispetto, e chiedono
    un lotto minimo. Scontano il prezzo di piazza, non lo sostituiscono.
  </p>
  <div class="tabella-avvolgi">
    <table class="tabella">
      <thead><tr><th>Chi</th><th>Fascia</th><th class="num">Sconto</th><th class="num">Da</th><th>Serve</th></tr></thead>
      <tbody>
      <?php foreach ($fornitori as $f): $mio = (float) $p['rispetto'] >= (int) $f['rispetto_min']; ?>
        <tr style="<?= $mio ? '' : 'opacity:.55' ?>">
          <td><strong><?= e($f['nome']) ?></strong><?= $mio ? ' <span class="stato stato--attivo">tuo</span>' : '' ?>
              <br><span class="minuto"><?= e($f['descrizione']) ?></span></td>
          <td class="minuto"><?= e($f['fascia']) ?></td>
          <td class="num">−<?= e(number_format((float) $f['sconto'] * 100, 0)) ?>%</td>
          <td class="num"><?= e(quantita((int) $f['lotto_min'])) ?></td>
          <td class="minuto"><?= $mio ? '—' : 'rispetto ' . (int) $f['rispetto_min'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="minuto" style="margin-top:.6rem">
    Sono due cose diverse e si possono giocare in modi opposti. Il rispetto porta gente
    più brava; il timore porta gente più fedele. Il rispetto lo costruisci facendo girare
    merce e pagando i debiti; il timore, reggendo i colpi.
  </p>
</div>

<div class="foglio">
  <span class="occhiello">L'organico</span>
  <h2 style="margin-top:0"><?= count($uomini) ?> su <?= (int) $tetto ?></h2>
  <p class="sommario minuto">
    <?= e(lire($stipendi)) ?> l'ora di stipendi, pagati in contanti a ogni ora.
    <strong>Chi non viene pagato smette di volerti bene</strong>, e la lealtà è quello che
    tiene chiuse le bocche: un uomo poco leale, il giorno che ti prendono, parla.
  </p>

  <?php if ($uomini === []): ?>
    <p class="minuto">Nessuno. Fai tutto da solo, e infatti non riesci a fare granché.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Nome</th><th>Mestiere</th><th class="num">Competenza</th><th class="num">Lealtà</th>
                   <th class="num">Stipendio</th><th>Dove</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($uomini as $u): $l = (float) $u['lealta']; ?>
          <tr>
            <td><strong><?= e($u['nome']) ?></strong>
              <?php if ((string) $u['stato'] === 'in_viaggio'): ?><br><span class="minuto">per strada</span><?php endif; ?></td>
            <td class="minuto"><?= e($ruoli[(string) $u['ruolo']][0] ?? $u['ruolo']) ?></td>
            <td class="num"><?= (int) $u['competenza'] ?></td>
            <td class="num" style="color:<?= $l < 35 ? 'var(--rosso)' : ($l < 55 ? 'var(--ambra)' : 'var(--verde)') ?>">
              <?= e(number_format($l, 0, ',', '.')) ?></td>
            <td class="num minuto"><?= e(lire((int) $u['stipendio_ora'])) ?></td>
            <td class="minuto"><?= e((string) ($u['piazza'] ?? '—')) ?></td>
            <td>
              <form method="post" action="<?= e(url('/organico/licenzia')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="uomo" value="<?= (int) $u['id'] ?>">
                <button class="bottone--fantasma bottone--minuto"
                        <?= (string) $u['stato'] === 'in_viaggio' ? 'disabled' : '' ?>>manda via</button>
              </form>
              <?php if (in_array((string) $u['ruolo'], ['vedetta', 'basista'], true) && !$inCarcere): ?>
                <form method="post" action="<?= e(url('/organico/piazza')) ?>" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="uomo" value="<?= (int) $u['id'] ?>">
                  <button class="bottone--fantasma bottone--minuto">mettilo qui</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (count($uomini) < $tetto && !$inCarcere): ?>
    <h3>Chi ti serve</h3>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <tbody>
        <?php foreach ($ruoli as $codice => [$nome, $cosa]): ?>
          <tr>
            <td><strong><?= e($nome) ?></strong><br><span class="minuto"><?= e($cosa) ?></span></td>
            <td style="width:9rem">
              <form method="post" action="<?= e(url('/organico/assumi')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="ruolo" value="<?= e($codice) ?>">
                <button class="bottone--fantasma bottone--minuto">ingaggia</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="minuto">All'ingaggio si anticipano <?= (int) $ingaggio ?> ore di stipendio, in contanti.</p>
  <?php elseif (!$inCarcere): ?>
    <p class="minuto">Più di così non ne reggi. L'organizzazione cresce usandola.</p>
  <?php endif; ?>
</div>

<?php $corrieri = array_filter($uomini, static fn($u) => (string) $u['ruolo'] === 'corriere' && (string) $u['stato'] === 'libero'); ?>
<?php if ($corse !== [] || ($corrieri !== [] && $carico !== [] && !$inCarcere)): ?>
<div class="foglio">
  <span class="occhiello">Le corse</span>
  <h2 style="margin-top:0">La roba che viaggia senza di te</h2>

  <?php if ($corse !== []): ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Chi</th><th>Cosa</th><th>Da</th><th>A</th><th class="num">Arriva</th></tr></thead>
        <tbody>
        <?php foreach ($corse as $c): ?>
          <tr>
            <td><?= e($c['corriere']) ?></td>
            <td class="minuto"><?= e(quantita((int) $c['quantita'])) ?> <?= e($c['bene']) ?></td>
            <td class="minuto"><?= e($c['da_piazza']) ?></td>
            <td class="minuto"><?= e($c['a_piazza']) ?></td>
            <td class="num minuto"><?= e(fmt_dt($c['arrivo_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($corrieri !== [] && $carico !== [] && !$inCarcere): ?>
    <h3>Mandane uno</h3>
    <p class="sommario minuto">Arriva in un <strong>deposito</strong>, non addosso a te: se là non
       hai un posto, non parte. Un corriere poco leale può anche non arrivare.</p>
    <form method="post" action="<?= e(url('/organico/manda')) ?>">
      <?= csrf_field() ?>
      <div class="griglia griglia--3">
        <div class="campo"><label for="uomo">Chi</label>
          <select id="uomo" name="uomo">
            <?php foreach ($corrieri as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= e($u['nome']) ?> (competenza <?= (int) $u['competenza'] ?>)</option>
            <?php endforeach; ?>
          </select></div>
        <div class="campo"><label for="bene">Cosa</label>
          <select id="bene" name="bene">
            <?php foreach ($carico as $c): ?>
              <option value="<?= (int) $c['bene']['id'] ?>"><?= e($c['bene']['nome']) ?> (ne hai <?= e(quantita($c['quantita'])) ?>)</option>
            <?php endforeach; ?>
          </select></div>
        <div class="campo"><label for="quantita">Quanto</label>
          <input type="number" id="quantita" name="quantita" min="1" value="1"></div>
      </div>
      <div class="campo"><label for="piazza">Dove</label>
        <select id="piazza" name="piazza">
          <?php foreach ($piazze as $id => $z): if ($id === (int) $p['piazza_id']) { continue; } ?>
            <option value="<?= (int) $id ?>"><?= e($z['nome']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="azioni"><button>Fallo partire</button></div>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
