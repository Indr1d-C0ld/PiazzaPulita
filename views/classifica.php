<?php /** @var list<array<string,mixed>> $righe */ ?>
<div class="foglio">
  <span class="occhiello">Classifica</span>
  <h1>Chi c'è in giro</h1>
  <p class="sommario minuto">
    Le quattro graduatorie vere — patrimonio pulito, territorio, reddito del mese,
    longevità — arrivano quando ci sarà un'economia da misurare. Finché non c'è,
    qui sotto trovi solo chi si è iscritto: una classifica inventata sarebbe peggio
    di nessuna classifica.
  </p>

  <?php if ($righe === []): ?>
    <p class="minuto">Ancora nessuno.</p>
  <?php else: ?>
    <div class="tabella-avvolgi">
      <table class="tabella">
        <thead><tr><th>#</th><th>Nome</th><th>In giro da</th><th>Visto</th></tr></thead>
        <tbody>
        <?php foreach ($righe as $i => $r): ?>
          <tr>
            <td class="num"><?= $i + 1 ?></td>
            <td><a href="<?= e(url('/profilo/' . $r['id'])) ?>"><?= e($r['username']) ?></a></td>
            <td><?= e(fmt_date($r['created_at'])) ?></td>
            <td><?= e(fmt_dt($r['last_seen_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
