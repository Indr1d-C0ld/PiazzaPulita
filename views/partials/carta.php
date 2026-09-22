<?php
/**
 * La cartina d'Italia, disegnata.
 *
 * @var array $disegno      da Carta::disegno()
 * @var array $presenze     (facoltativo) citta_id => quante persone ci sono
 * @var array $collegamenti (facoltativo) citta_id => indirizzo da aprire
 * @var string|null $titolo (facoltativo) quello che c'è scritto nel cartiglio
 */
$presenze     = $presenze ?? [];
$collegamenti = $collegamenti ?? [];
$titolo       = $titolo ?? 'Carta d\'Italia';
$w = $disegno['vista']['w'];
$h = $disegno['vista']['h'];
?>
<svg class="carta" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img"
     aria-label="Carta d'Italia con le nove città del gioco" preserveAspectRatio="xMidYMid meet">
  <defs>
    <!-- Il mare a tratteggio: è così che si campiva l'acqua quando colorare
         costava, e si legge bene anche in bianco e nero. -->
    <pattern id="onde" width="14" height="9" patternUnits="userSpaceOnUse">
      <path d="M0 4.5 q3.5 -3 7 0 q3.5 3 7 0" fill="none" class="onda" stroke-width="0.7"/>
    </pattern>
    <!-- La grana della carta. Tenuta bassissima: deve sporcare, non decorare. -->
    <filter id="grana" x="0" y="0" width="100%" height="100%">
      <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="3" result="rumore"/>
      <feColorMatrix in="rumore" type="saturate" values="0"/>
      <feComponentTransfer><feFuncA type="linear" slope="0.055"/></feComponentTransfer>
      <feComposite operator="in" in2="SourceGraphic"/>
    </filter>
    <filter id="rilievo" x="-8%" y="-8%" width="116%" height="116%">
      <feDropShadow dx="1.5" dy="2.5" stdDeviation="2.2" flood-opacity="0.22"/>
    </filter>
  </defs>

  <rect x="0" y="0" width="<?= $w ?>" height="<?= $h ?>" class="mare"/>
  <rect x="0" y="0" width="<?= $w ?>" height="<?= $h ?>" fill="url(#onde)"/>

  <!-- I paralleli e i meridiani, appena accennati: danno il tono da carta
       senza chiedere di essere letti. -->
  <g class="reticolo">
    <?php for ($x = 95; $x < $w; $x += 95): ?><line x1="<?= $x ?>" y1="0" x2="<?= $x ?>" y2="<?= $h ?>"/><?php endfor; ?>
    <?php for ($y = 95; $y < $h; $y += 95): ?><line x1="0" y1="<?= $y ?>" x2="<?= $w ?>" y2="<?= $y ?>"/><?php endfor; ?>
  </g>

  <g filter="url(#rilievo)">
    <?php foreach ($disegno['terra'] as $d): ?>
      <path d="<?= e($d) ?>" class="terra"/>
    <?php endforeach; ?>
  </g>

  <!-- Le pieghe: una carta che sta nel cruscotto è piegata in quattro. -->
  <g class="pieghe">
    <line x1="<?= round($w / 2) ?>" y1="0" x2="<?= round($w / 2) ?>" y2="<?= $h ?>"/>
    <line x1="0" y1="<?= round($h / 3) ?>" x2="<?= $w ?>" y2="<?= round($h / 3) ?>"/>
    <line x1="0" y1="<?= round($h * 2 / 3) ?>" x2="<?= $w ?>" y2="<?= round($h * 2 / 3) ?>"/>
  </g>

  <?php foreach ($disegno['citta'] as $c):
      $dx     = $c['lato'] === 'destra' ? 13 : -13;
      $ancora = $c['lato'] === 'destra' ? 'start' : 'end';
      $quante = (int) ($presenze[$c['id']] ?? 0);
      $dove   = $collegamenti[$c['id']] ?? null;
  ?>
    <g class="citta <?= $c['qui'] ? 'citta--qui' : '' ?>">
      <?php if ($dove !== null): ?><a href="<?= e($dove) ?>"><?php endif; ?>
        <?php if ($c['qui']): ?>
          <circle cx="<?= $c['x'] ?>" cy="<?= $c['y'] ?>" r="11" class="alone"/>
        <?php endif; ?>
        <circle cx="<?= $c['x'] ?>" cy="<?= $c['y'] ?>" r="5.2" class="segno"/>
        <circle cx="<?= $c['x'] ?>" cy="<?= $c['y'] ?>" r="1.9" class="occhio"/>
        <text x="<?= $c['x'] + $dx ?>" y="<?= $c['y'] + 1.5 ?>" text-anchor="<?= $ancora ?>"
              class="nome"><?= e(mb_strtoupper($c['nome'])) ?></text>
        <text x="<?= $c['x'] + $dx ?>" y="<?= $c['y'] + 14 ?>" text-anchor="<?= $ancora ?>" class="sotto">
          <?= (int) $c['piazze'] ?> piazze<?= $quante > 0 ? ' · ' . $quante . ($quante === 1 ? ' persona' : ' persone') : '' ?>
        </text>
      <?php if ($dove !== null): ?></a><?php endif; ?>
    </g>
  <?php endforeach; ?>

  <!-- La rosa dei venti. -->
  <g class="rosa" transform="translate(<?= $w - 74 ?>, 74)">
    <circle r="27" class="rosa-cerchio"/>
    <path d="M0 -30 L6 -6 L0 0 L-6 -6 Z" class="rosa-nord"/>
    <path d="M0 30 L6 6 L0 0 L-6 6 Z" class="rosa-sud"/>
    <path d="M-30 0 L-6 6 L0 0 L-6 -6 Z" class="rosa-sud"/>
    <path d="M30 0 L6 6 L0 0 L6 -6 Z" class="rosa-sud"/>
    <text y="-34" text-anchor="middle" class="rosa-lettera">N</text>
  </g>

  <!-- La scala grafica. -->
  <g class="scala" transform="translate(40, <?= $h - 52 ?>)">
    <line x1="0" y1="0" x2="<?= $disegno['scala']['px'] ?>" y2="0"/>
    <line x1="0" y1="-5" x2="0" y2="5"/>
    <line x1="<?= round($disegno['scala']['px'] / 2, 1) ?>" y1="-3.5" x2="<?= round($disegno['scala']['px'] / 2, 1) ?>" y2="3.5"/>
    <line x1="<?= $disegno['scala']['px'] ?>" y1="-5" x2="<?= $disegno['scala']['px'] ?>" y2="5"/>
    <text x="0" y="18" class="scala-testo"><?= (int) $disegno['scala']['km'] ?> chilometri</text>
  </g>

  <!-- Il cartiglio. -->
  <g class="cartiglio" transform="translate(40, 44)">
    <rect x="-10" y="-26" width="238" height="52" rx="2"/>
    <text x="0" y="-6" class="cartiglio-titolo">PIAZZA PULITA</text>
    <text x="0" y="12" class="cartiglio-sotto"><?= e($titolo) ?></text>
  </g>

  <rect x="0" y="0" width="<?= $w ?>" height="<?= $h ?>" filter="url(#grana)" class="grana"/>
  <rect x="2" y="2" width="<?= $w - 4 ?>" height="<?= $h - 4 ?>" class="bordo"/>
</svg>
