/*
 * Registrazione del service worker.
 *
 * Il percorso si ricava dal foglio di stile già caricato invece di scriverlo a
 * mano: l'installazione può stare sotto qualsiasi sottopercorso, e un percorso
 * assoluto scritto qui dentro funzionerebbe solo sulla nostra.
 */
(function () {
  if (!('serviceWorker' in navigator)) { return; }
  var base = document.documentElement.getAttribute('data-base') || './';
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(function () {
      /* Senza service worker il gioco funziona uguale: non si avvisa nessuno. */
    });
  });
})();
