<?php
/** @var array $stato @var list $vicine @var list $altri */
use App\Sim\Viaggio;
$piazza = $stato['piazza'];
$citta  = $stato['citta'];
$carico_per_bene = [];
foreach ($carico ?? [] as $c) { $carico_per_bene[(int) $c['bene']['id']] = (int) $c['quantita']; }
?>

<?php if ($stato['in_viaggio']): ?>
  <div class="foglio" data-viaggio data-stato-url="<?= e(url('/api/stato')) ?>"
       data-mancano="<?= (int) $stato['mancano_sec'] ?>">
    <span class="occhiello">In viaggio<?= $stato['da'] !== null ? ' · ' . e($stato['da']['mezzo']) : '' ?></span>
    <h1>Verso <?= e($piazza['nome']) ?></h1>
    <p class="sommario">
      <?php if ($stato['da'] !== null): ?>
        Sei partito da <?= e($stato['da']['piazza']['nome']) ?>.
      <?php endif; ?>
      A <?= e($citta['nome']) ?> ci arrivi fra <strong data-mancano-testo><?= e($stato['mancano']) ?></strong>.
    </p>
    <p class="minuto">Durante il viaggio non si tratta. La pagina si aggiorna da sola quando arrivi.</p>
  </div>
<?php else: ?>
  <div class="foglio">
    <span class="occhiello"><?= e($citta['nome']) ?> · <?= e($piazza['tipo']) ?></span>
    <h1><?= e($piazza['nome']) ?></h1>

    <dl class="griglia griglia--3" style="margin:0 0 1rem">
      <div class="dato"><dt>Polizia</dt><dd><?= (int) $piazza['polizia'] ?>%</dd></div>
      <div class="dato"><dt>Quanto scotti</dt>
        <dd style="font-size:.95rem;color:<?= $calore > 90 ? 'var(--rosso)' : ($calore > 40 ? 'var(--ambra)' : 'inherit') ?>">
          <a href="<?= e(url('/fascicolo')) ?>" style="border:0"><?= e(App\Sim\Calore::aParole($calore)) ?></a></dd></div>
      <div class="dato"><dt>In tasca</dt><dd style="font-size:1.05rem"><?= e(lire($stato['contante'])) ?></dd></div>
      <div class="dato"><dt>Gente qui</dt><dd><?= count($altri) ?></dd></div>
    </dl>

    <p class="minuto">
      <?= e(match ($piazza['tipo']) {
          'periferia'    => 'Palazzoni, poche divise, tutti si conoscono. Qui la roba arriva prima che altrove.',
          'popolare'     => 'Strada viva, gente che passa, occhi dappertutto ma nessuno che parla.',
          'stazione'     => 'Transito puro: chiunque, a qualsiasi ora — e una pattuglia ogni venti metri.',
          'benestante'   => 'Vetrine e portinerie. Si paga bene e si viene guardati male.',
          'universitaria'=> 'Giovani, poca paura, e prezzi che reggono solo il piccolo.',
          default        => 'Il centro.',
      }) ?>
    </p>

  </div>

  <div class="foglio">
    <span class="occhiello">Il listino · <?= e($piazza['nome']) ?></span>
    <h2 style="margin-top:0">Quello che gira qui</h2>
    <p class="sommario minuto">
      I prezzi sono di tutti: quando compri salgono per chi viene dopo, quando vendi
      scendono. <strong>Assorbe</strong> è quanto la piazza è ancora disposta a comprare
      adesso — oltre quella soglia non c'è più nessuno, e il prezzo lo dice prima.
    </p>

    <?php if ($listino === []): ?>
      <p class="minuto">Qui non gira niente. Capita: prova un'altra piazza.</p>
    <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr>
          <th>Merce</th><th class="num">Compri a</th><th class="num">Vendi a</th>
          <th class="num">Giacenza</th><th class="num">Assorbe</th><th>Ingombro</th><th>Ordine</th>
        </tr></thead>
        <tbody>
        <?php foreach ($listino as $v): $b = $v['bene']; $ho = $carico_per_bene[(int) $b['id']] ?? 0; ?>
          <tr>
            <td><strong><?= e($b['nome']) ?></strong><br>
                <span class="minuto"><?= e($b['unita']) ?><?= $ho > 0 ? ' · ne hai ' . e(quantita($ho)) : '' ?></span>
                <?php if (($v['fornitore'] ?? null) !== null): ?>
                  <br><span class="minuto" style="color:var(--verde)"><?= e($v['fornitore']['nome']) ?>:
                    −<?= e(number_format((float) $v['fornitore']['sconto'] * 100, 0)) ?>% da
                    <?= e(quantita((int) $v['fornitore']['lotto_min'])) ?></span>
                <?php endif; ?></td>
            <td class="num"><?= e(lire($v['acquisto'])) ?></td>
            <td class="num"><?= e(lire($v['vendita'])) ?></td>
            <td class="num"><?= e(quantita($v['offerta'])) ?></td>
            <td class="num"><?= e(quantita($v['domanda'])) ?></td>
            <td class="num minuto"><?= (int) $b['ingombro'] ?></td>
            <td>
              <form method="post" action="<?= e(url('/ordina')) ?>" class="ordine">
                <?= csrf_field() ?>
                <input type="hidden" name="bene" value="<?= (int) $b['id'] ?>">
                <input type="number" name="quantita" min="1" step="1" value="1" aria-label="quantità">
                <button name="verso" value="acquisto" class="bottone--minuto"
                        <?= $v['offerta'] < 1 ? 'disabled' : '' ?>>compra</button>
                <button name="verso" value="vendita" class="bottone--fantasma bottone--minuto"
                        <?= $ho < 1 ? 'disabled' : '' ?>>vendi</button>
                <button name="azione" value="compra_tutto" class="bottone--fantasma bottone--minuto"
                        title="Il massimo che denaro e spazio consentono"
                        <?= $v['offerta'] < 1 ? 'disabled' : '' ?>>tutto</button>
                <button name="azione" value="vendi_tutto" class="bottone--fantasma bottone--minuto"
                        title="Tutto quello che ne hai addosso"
                        <?= $ho < 1 ? 'disabled' : '' ?>>svuota</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="foglio">
    <span class="occhiello">Il deposito</span>
    <h2 style="margin-top:0"><?= $deposito === null ? 'Qui non hai un posto' : 'Qui hai un posto' ?></h2>
    <?php if ($deposito === null): ?>
      <p class="sommario minuto">
        Un deposito toglie il limite dell'ora: compri quando costa poco e aspetti che la
        piazza si riprenda. Si affitta di persona, e si paga a ore in contanti — l'anticipo
        è <?= e(lire($affitto)) ?>.
      </p>
      <form method="post" action="<?= e(url('/deposito/apri')) ?>">
        <?= csrf_field() ?>
        <div class="azioni" style="margin:0">
          <button class="bottone--fantasma" <?= $conti['sporco'] < $affitto ? 'disabled' : '' ?>>
            Affitta un posto qui
          </button>
        </div>
      </form>
    <?php else: ?>
      <p class="sommario minuto">
        <?= e(quantita(array_sum(array_column($inDeposito, 'ingombro')))) ?> spazi su
        <?= e(quantita((int) $deposito['capienza'])) ?>, affitto pagato fino al
        <?= e(fmt_dt($deposito['pagato_fino_a'])) ?>.
      </p>
      <div class="tabella-avvolgi">
        <table class="tabella">
          <thead><tr><th>Merce</th><th class="num">Addosso</th><th class="num">In deposito</th><th>Sposta</th></tr></thead>
          <tbody>
          <?php
          $tutte = [];
          foreach ($carico as $c) { $tutte[(int) $c['bene']['id']] = ['bene' => $c['bene'], 'addosso' => (int) $c['quantita'], 'dentro' => 0]; }
          foreach ($inDeposito as $m) {
              $id = (int) $m['bene']['id'];
              $tutte[$id] ??= ['bene' => $m['bene'], 'addosso' => 0, 'dentro' => 0];
              $tutte[$id]['dentro'] = (int) $m['quantita'];
          }
          foreach ($tutte as $id => $r): ?>
            <tr>
              <td><?= e($r['bene']['nome']) ?> <span class="minuto">(<?= e($r['bene']['unita']) ?>)</span></td>
              <td class="num"><?= e(quantita($r['addosso'])) ?></td>
              <td class="num"><?= e(quantita($r['dentro'])) ?></td>
              <td>
                <form method="post" action="<?= e(url('/deposito/sposta')) ?>" class="ordine">
                  <?= csrf_field() ?>
                  <input type="hidden" name="bene" value="<?= (int) $id ?>">
                  <input type="number" name="quantita" min="1" value="1" aria-label="quantità">
                  <button name="verso" value="deposito" class="bottone--fantasma bottone--minuto"
                          <?= $r['addosso'] < 1 ? 'disabled' : '' ?>>metti giù</button>
                  <button name="verso" value="carico" class="bottone--fantasma bottone--minuto"
                          <?= $r['dentro'] < 1 ? 'disabled' : '' ?>>riprendi</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($tutte === []): ?><tr><td colspan="4" class="minuto">Vuoto, e non hai niente addosso.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="foglio">
    <span class="occhiello">Quello che porti</span>
    <h2 style="margin-top:0">Addosso</h2>
    <p class="sommario minuto">
      <?= e(quantita($ingombro)) ?> spazi su <?= e(quantita($capienza)) ?>.
      I mezzi — l'auto, il furgone, i depositi — arrivano con la fase tre.
    </p>
    <?php if ($carico === []): ?>
      <p class="minuto">Niente. Tasche vuote e nessuno che ti guarda storto.</p>
    <?php else: ?>
      <div class="tabella-avvolgi">
        <table class="tabella">
          <thead><tr><th>Merce</th><th class="num">Quantità</th><th class="num">Pagata</th>
                     <th class="num">Spazi</th><th class="num">Qui vale</th></tr></thead>
          <tbody>
          <?php foreach ($carico as $c):
              $qui_vale = null;
              foreach ($listino as $v) {
                  if ((int) $v['bene']['id'] === (int) $c['bene']['id']) { $qui_vale = $v['vendita']; }
              }
          ?>
            <tr>
              <td><?= e($c['bene']['nome']) ?> <span class="minuto">(<?= e($c['bene']['unita']) ?>)</span></td>
              <td class="num"><?= e(quantita($c['quantita'])) ?></td>
              <td class="num"><?= e(lire($c['medio'])) ?></td>
              <td class="num minuto"><?= e(quantita($c['ingombro'])) ?></td>
              <td class="num"><?php if ($qui_vale === null): ?><span class="minuto">non si vende qui</span>
                  <?php else: $d = $qui_vale - $c['medio']; ?>
                    <?= e(lire($qui_vale)) ?>
                    <span class="minuto" style="color:<?= $d >= 0 ? 'var(--verde)' : 'var(--rosso)' ?>">
                      <?= $d >= 0 ? '+' : '−' ?><?= e(lire(abs($d), false)) ?>
                    </span>
                  <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($altri !== []): ?>
    <div class="foglio">
      <span class="occhiello">Chi c'è</span>
      <p class="sommario minuto">Fermi in questa piazza adesso. Da F2 sono concorrenti sul prezzo.</p>
      <p><?php foreach ($altri as $a): ?><a href="<?= e(url('/profilo/' . $a['id'])) ?>"><?= e($a['username']) ?></a>&nbsp; <?php endforeach; ?></p>
    </div>
  <?php endif; ?>

  <div class="foglio">
    <span class="occhiello">Qui intorno · <?= e($citta['nome']) ?></span>
    <h2 style="margin-top:0">Dove si va da qui</h2>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>Piazza</th><th>Tipo</th><th class="num">Polizia</th><th class="num">km</th><th>Come</th></tr></thead>
        <tbody>
        <?php foreach ($vicine as $v): ?>
          <tr>
            <td><strong><?= e($v['piazza']['nome']) ?></strong></td>
            <td class="minuto"><?= e($v['piazza']['tipo']) ?></td>
            <td class="num"><?= (int) $v['piazza']['polizia'] ?>%</td>
            <td class="num"><?= e(number_format($v['km'], 1, ',', '.')) ?></td>
            <td>
              <?php foreach ($v['opzioni'] as $o): ?>
                <form method="post" action="<?= e(url('/parti')) ?>" style="display:inline-block;margin:0 .3rem .3rem 0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="piazza" value="<?= (int) $v['piazza']['id'] ?>">
                  <input type="hidden" name="mezzo" value="<?= e($o['mezzo']) ?>">
                  <input type="hidden" name="torna" value="/strada">
                  <button class="bottone--fantasma bottone--minuto" title="<?= e($o['nota']) ?>">
                    <?= e($o['mezzo']) ?> · <?= e(Viaggio::durata($o['minuti'])) ?>
                    <?= $o['costo'] > 0 ? ' · ' . e(lire($o['costo'])) : '' ?>
                  </button>
                </form>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="azioni">
      <a class="bottone" href="<?= e(url('/mappa')) ?>">Andare in un'altra città</a>
    </div>
  </div>
<?php endif; ?>
