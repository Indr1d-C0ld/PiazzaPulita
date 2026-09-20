/* Il riquadro di centratura della fotografia del profilo.
 *
 * L'immagine si trascina e si ingrandisce dentro un quadrato; al momento
 * dell'invio il riquadro calcola il RETTANGLO DI RITAGLIO IN PIXEL
 * DELL'IMMAGINE ORIGINALE e lo mette in tre campi nascosti.
 *
 * È il contratto giusto fra pagina e server: «zoom e spostamento» sarebbero
 * numeri che significano qualcosa solo conoscendo la misura del riquadro sullo
 * schermo di chi carica, e il server non la conosce e non deve fidarsene. Un
 * rettangolo in pixel dell'originale il server lo sa controllare da solo — e
 * infatti lo riporta dentro i bordi qualunque cosa arrivi.
 *
 * Senza JavaScript il modulo funziona lo stesso: i tre campi restano a zero e
 * il server ritaglia centrato sul lato corto, un po' più in alto del centro
 * perché in un ritratto la testa sta in alto.
 */
(function () {
  const box = document.querySelector('[data-ritaglio]');
  if (!box) return;

  const scelta  = box.querySelector('input[type=file]');
  const cornice = box.querySelector('.cornice');
  const img     = box.querySelector('.cornice img');
  const zoom    = box.querySelector('input[type=range]');
  const campoX  = box.querySelector('input[name=sx]');
  const campoY  = box.querySelector('input[name=sy]');
  const campoL  = box.querySelector('input[name=lato]');
  const invia   = box.querySelector('button[type=submit]');
  if (!scelta || !cornice || !img) return;

  // Il pulsante nasce ATTIVO nel markup, perché senza JavaScript il modulo deve
  // poter partire lo stesso (il server ritaglia centrato). Lo disabilita questo
  // script, che se c'è si prende la responsabilità di riabilitarlo quando
  // l'anteprima è pronta.
  if (invia) invia.disabled = true;

  function avvisa(testo) {
    const p = box.querySelector('[data-avviso]');
    if (p) p.textContent = testo || '';
  }

  let nw = 0, nh = 0;       // misure vere dell'immagine
  let base = 1;             // scala minima perché copra la cornice
  let ox = 0, oy = 0;       // posizione dell'immagine dentro la cornice

  const lato = () => cornice.clientWidth;

  function limita() {
    const F = lato();
    const s = base * parseFloat(zoom.value || '1');
    // Guardia: senza misure valide non si scrive niente. Serve perché
    // `campoL` vale `F / s`, e con `s` a zero ci finirebbe dentro `Infinity`
    // — che il server legge come zero e ritaglia centrato, ignorando in
    // silenzio il riquadro che l'utente ha appena regolato.
    if (!nw || !nh || F <= 0 || !(s > 0)) { return; }
    const w = nw * s, h = nh * s;
    ox = Math.min(0, Math.max(F - w, ox));
    oy = Math.min(0, Math.max(F - h, oy));
    img.style.width = w + 'px';
    img.style.height = h + 'px';
    img.style.left = ox + 'px';
    img.style.top = oy + 'px';

    // Il ritaglio, in pixel dell'originale.
    campoX.value = Math.round(-ox / s);
    campoY.value = Math.round(-oy / s);
    campoL.value = Math.round(F / s);
  }

  /** Rimette l'immagine al suo posto: scala minima che copre la cornice, e
   *  inquadratura di partenza un po' più in alto del centro, perché in un
   *  ritratto la testa sta in alto. Va chiamata a cornice VISIBILE. */
  function sistema() {
    const F = lato();
    if (!nw || !nh) { return; }
    if (F <= 0) {
      // La cornice non ha ancora una misura: può succedere se la pagina sta
      // ancora impaginando. Si riprova al disegno successivo invece di
      // rassegnarsi a una scala sbagliata.
      requestAnimationFrame(sistema);
      return;
    }
    base = F / Math.min(nw, nh);
    zoom.value = '1';
    ox = (F - nw * base) / 2;
    oy = Math.min(0, -(nh * base - F) * 0.18);
    limita();
  }

  scelta.addEventListener('change', function () {
    const f = scelta.files && scelta.files[0];
    if (!f) return;
    if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
      avvisa('Serve un JPEG, un PNG o un WebP.');
      if (invia) invia.disabled = true;
      return;
    }
    avvisa('');
    const url = URL.createObjectURL(f);

    // Se l'anteprima non si carica non si resta muti. Prima era così, e il
    // modulo sembrava semplicemente non funzionare: nessun riquadro, nessun
    // messaggio, il pulsante spento per sempre. Si dice cos'è successo e si
    // lascia comunque partire l'invio — il server sa ritagliare da solo.
    img.onerror = function () {
      URL.revokeObjectURL(url);
      cornice.hidden = true;
      const comandi = box.querySelector('[data-comandi]');
      if (comandi) comandi.hidden = true;
      avvisa('Non riesco a mostrarti l\'anteprima di questa immagine. '
           + 'Puoi caricarla lo stesso: verrà ritagliata quadrata e centrata.');
      if (invia) invia.disabled = false;
    };

    img.onload = function () {
      nw = img.naturalWidth; nh = img.naturalHeight;

      // SI SCOPRE LA CORNICE PRIMA DI MISURARLA. Finché ha l'attributo
      // `hidden` vale `display: none`, e `clientWidth` di un elemento non
      // disegnato è ZERO: misurandola prima, la scala dell'anteprima veniva
      // zero e l'immagine finiva larga zero pixel. Il riquadro compariva
      // vuoto, con dentro la foto invisibile. Il sintomo era tanto più
      // confondente perché ridimensionando la finestra si sistemava da sé —
      // il gestore del `resize` ricalcola la scala a cornice ormai visibile.
      cornice.hidden = false;
      const comandi = box.querySelector('[data-comandi]');
      if (comandi) comandi.hidden = false;

      sistema();
      if (invia) invia.disabled = false;
      URL.revokeObjectURL(url);
    };
    img.src = url;
  });

  zoom.addEventListener('input', limita);
  window.addEventListener('resize', function () {
    if (!nw) { return; }
    const F = lato();
    if (F > 0) { base = F / Math.min(nw, nh); limita(); }
  });

  // Trascinamento, col dito o col mouse: pointer events li coprono tutti e due.
  let trascino = false, px = 0, py = 0;
  cornice.addEventListener('pointerdown', function (e) {
    if (!nw) return;
    trascino = true; px = e.clientX; py = e.clientY;
    cornice.setPointerCapture(e.pointerId);
    e.preventDefault();
  });
  cornice.addEventListener('pointermove', function (e) {
    if (!trascino) return;
    ox += e.clientX - px; oy += e.clientY - py;
    px = e.clientX; py = e.clientY;
    limita();
  });
  const molla = function (e) {
    if (!trascino) return;
    trascino = false;
    try { cornice.releasePointerCapture(e.pointerId); } catch (x) {}
  };
  cornice.addEventListener('pointerup', molla);
  cornice.addEventListener('pointercancel', molla);
})();
