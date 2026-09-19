/* Il conto alla rovescia del viaggio.
 *
 * Scala in locale ogni secondo e chiede al server solo ogni quindici: il
 * cronometro non deve costare una richiesta al secondo, ma nemmeno fidarsi
 * solo del proprio conteggio, che su una scheda sospesa resta indietro.
 */
(function () {
  const box = document.querySelector('[data-viaggio]');
  if (!box) return;

  const testo = box.querySelector('[data-mancano-testo]');
  let mancano = parseInt(box.dataset.mancano || '0', 10);

  function durata(sec) {
    const m = Math.max(0, Math.ceil(sec / 60));
    if (m < 60) return m + ' min';
    const h = Math.floor(m / 60), r = m % 60;
    return r === 0 ? h + ' h' : h + ' h ' + String(r).padStart(2, '0');
  }

  function mostra() { if (testo) testo.textContent = durata(mancano); }

  const tic = setInterval(function () {
    mancano -= 1;
    if (mancano <= 0) { clearInterval(tic); location.reload(); return; }
    mostra();
  }, 1000);

  setInterval(function () {
    fetch(box.dataset.statoUrl, { headers: { Accept: 'application/json' } })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) return;
        if (!d.in_viaggio) { location.reload(); return; }
        mancano = d.mancano_sec;
        mostra();
      })
      .catch(function () {});
  }, 15000);
})();
