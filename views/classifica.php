<?php /** @var string $graduatoria @var array $graduatorie @var list $righe @var array $primati */ ?>
<div class="foglio">
  <span class="occhiello">Classifica</span>
  <h1>Chi comanda, e in che cosa</h1>
  <p class="sommario minuto">
    Quattro graduatorie e non una: in un mondo che non riparte mai, una sola premierebbe
    soltanto chi è arrivato per primo, e a chi comincia oggi direbbe che è tardi.
    <strong>Quella che conta è il reddito</strong> — il passato non ci pesa, e si può
    scalare da chiunque, sempre.
  </p>

  <nav class="linguette">
    <?php foreach ($graduatorie as $cod => $g): ?>
      <a href="<?= e(url('/classifica?g=' . $cod)) ?>"
         class="<?= $cod === $graduatoria ? 'attiva' : '' ?>"><?= e($g['nome']) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(url('/albo')) ?>">Albo d'oro</a>
  </nav>

  <p class="sommario"><?= e($graduatorie[$graduatoria]['nota']) ?>
    <?php if ($graduatoria === 'reddito'): ?>
      <span class="minuto">Finestra: <?= (int) $giorni ?> giorni.</span>
    <?php endif; ?>
  </p>

  <?php $pr = $primati[$graduatoria] ?? null; if ($pr !== null && ($pr['username'] ?? null) !== null): ?>
    <p class="minuto">In cima dal <?= e(fmt_date($pr['dal'])) ?>:
       <strong><?= e($pr['username']) ?></strong>. Un mese lassù e finisce nell'albo d'oro.</p>
  <?php endif; ?>

  <?php if ($righe === []): ?>
    <p class="minuto">Questa graduatoria è ancora vuota: nessuno ha fatto abbastanza
       perché ci sia qualcosa da ordinare. Una classifica inventata sarebbe peggio.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr>
          <th style="width:3rem">#</th><th>Nome</th>
          <th class="num"><?= e(ucfirst($graduatorie[$graduatoria]['unita'])) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($righe as $i => $r): ?>
          <tr<?= $i === 0 ? ' style="font-weight:600"' : '' ?>>
            <td class="num"><?= $i + 1 ?></td>
            <td><a href="<?= e(url('/profilo/' . $r['id'])) ?>"><?= e($r['username']) ?></a>
                <?php if (($r['sigla'] ?? null) !== null): ?>
                  <span class="minuto">(<?= e($r['sigla']) ?>)</span><?php endif; ?>
                <?php if (($r['profilo'] ?? null) !== null): ?>
                  <span class="minuto">· pubblico nemico <?= (int) $r['profilo'] ?></span><?php endif; ?></td>
            <td class="num"><?= e($graduatorie[$graduatoria]['unita'] === 'lire'
                  ? lire((int) $r['valore']) : quantita((int) $r['valore'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
