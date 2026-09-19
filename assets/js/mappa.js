/* Piazza Pulita — lo schema della rete.
 *
 * Non è una carta geografica: è un diagramma, nello spirito degli orari
 * ferroviari. Le posizioni sono quelle vere (il server proietta le coordinate
 * in chilometri, qui si scala e basta), la costa non c'è perché non serve a
 * niente e disegnarla male è peggio che non disegnarla.
 */
(function () {
  const tela = document.getElementById('mappa');
  if (!tela) return;

  let citta;
  try { citta = JSON.parse(tela.dataset.citta || '[]'); } catch (e) { return; }
  if (!citta.length) return;

  const stile = getComputedStyle(document.documentElement);
  const col = (n, d) => (stile.getPropertyValue(n) || d).trim();

  function disegna() {
    const inchiostro = col('--inchiostro', '#1c1a16');
    const tenue      = col('--inchiostro-3', '#7b7568');
    const riga       = col('--riga', '#b9b0a0');
    const rosso      = col('--rosso', '#a5281d');
    const carta      = col('--carta-2', '#f2eee4');

    // Il Canvas va ridimensionato ai pixel veri dello schermo, o su un
    // telefono il disegno esce sfocato e le scritte illeggibili.
    const dpr = window.devicePixelRatio || 1;
    const larghezza = tela.clientWidth || 640;
    const altezza = Math.round(larghezza * 1.25);
    tela.width = Math.round(larghezza * dpr);
    tela.height = Math.round(altezza * dpr);
    tela.style.height = altezza + 'px';

    const c = tela.getContext('2d');
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    c.clearRect(0, 0, larghezza, altezza);

    const bordo = 60;
    const xs = citta.map(v => v.x), ys = citta.map(v => v.y);
    const minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
    const minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);
    // Una scala sola per i due assi: schiacciare l'Italia la renderebbe
    // irriconoscibile, ed è l'unica cosa che questo disegno deve dare.
    const scala = Math.min(
      (larghezza - bordo * 2) / Math.max(1, maxX - minX),
      (altezza - bordo * 2) / Math.max(1, maxY - minY)
    );
    const offX = (larghezza - (maxX - minX) * scala) / 2;
    const offY = (altezza - (maxY - minY) * scala) / 2;
    const px = v => offX + (v.x - minX) * scala;
    const py = v => altezza - (offY + (v.y - minY) * scala); // nord in alto

    // Le tratte: ogni città con le tre più vicine. Non è la rete ferroviaria
    // vera, è il grafo delle rotte che un giocatore usa davvero.
    c.strokeStyle = riga;
    c.lineWidth = 1;
    citta.forEach(a => {
      citta.filter(b => b !== a)
        .sort((b1, b2) => Math.hypot(b1.x - a.x, b1.y - a.y) - Math.hypot(b2.x - a.x, b2.y - a.y))
        .slice(0, 3)
        .forEach(b => {
          c.beginPath();
          c.moveTo(px(a), py(a));
          c.lineTo(px(b), py(b));
          c.stroke();
        });
    });

    citta.forEach(v => {
      const x = px(v), y = py(v);
      const r = 4 + v.piazze;

      c.beginPath();
      c.arc(x, y, r, 0, Math.PI * 2);
      c.fillStyle = v.qui ? rosso : carta;
      c.fill();
      c.strokeStyle = v.qui ? rosso : inchiostro;
      c.lineWidth = v.qui ? 2.5 : 1.5;
      c.stroke();

      if (v.qui) {
        c.beginPath();
        c.arc(x, y, r + 6, 0, Math.PI * 2);
        c.strokeStyle = rosso;
        c.lineWidth = 1;
        c.stroke();
      }

      c.fillStyle = v.qui ? rosso : inchiostro;
      c.font = (v.qui ? '700 ' : '') + '13px Helvetica, Arial, sans-serif';
      c.textBaseline = 'middle';
      // Le città a est scrivono a sinistra del punto, e viceversa: altrimenti
      // Bari e Catania finiscono fuori dalla tela.
      const aDestra = v.x < (minX + maxX) / 2;
      c.textAlign = aDestra ? 'left' : 'right';
      c.fillText(v.nome, x + (aDestra ? r + 7 : -r - 7), y);

      c.fillStyle = tenue;
      c.font = '10px Helvetica, Arial, sans-serif';
      c.fillText(v.piazze + ' piazze', x + (aDestra ? r + 7 : -r - 7), y + 13);
    });
  }

  disegna();
  window.addEventListener('resize', disegna);
  // Il tema si può cambiare senza ricaricare: i colori vanno ripresi.
  const mq = window.matchMedia('(prefers-color-scheme: dark)');
  if (mq.addEventListener) mq.addEventListener('change', disegna);
})();
