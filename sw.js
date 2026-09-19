/*
 * Piazza Pulita — service worker.
 *
 * QUELLO CHE NON FA, e perché: non mette in cache le pagine. Questo è un gioco
 * il cui stato sta sul server ed è autoritativo — prezzi, contanti, dove sei,
 * chi c'è in piazza. Una pagina servita dalla cache mostrerebbe un mondo che
 * non esiste più, e in un gioco di compravendita un listino vecchio di dieci
 * minuti non è «degradazione elegante»: è una bugia su cui qualcuno prende una
 * decisione. Meglio dire che non c'è linea.
 *
 * Quello che fa: tiene da parte le cose immutabili (foglio di stile, script,
 * icone) e una paginetta da mostrare quando la rete manca.
 *
 * Lo scope è la cartella da cui viene servito, quindi l'installazione sotto un
 * sottopercorso (/piazzapulita/) funziona senza che qui dentro ci sia scritto
 * da nessuna parte quale sia.
 */

const VERSIONE = 'piazzapulita-v1';
const BASE = new URL('./', self.location).pathname;
/*
 * Si precarica SOLO la pagina di cortesia. Gli asset no, e non è una svista:
 * `asset()` ci attacca un `?v=<data del file>` per invalidare la cache dei
 * browser a ogni deploy, quindi un URL scritto qui a mano non corrisponderebbe
 * mai a quello che la pagina chiede davvero. Gli asset entrano in cache al
 * primo uso, con il loro `?v` giusto; al deploy successivo l'URL cambia, la
 * cache manca e si va in rete — che è esattamente il comportamento voluto.
 *
 * Le copie vecchie restano finché non si alza VERSIONE, che le butta tutte.
 */
const GUSCIO = [BASE + 'offline.html'];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(VERSIONE)
      // addAll fallisce tutto se un solo file manca: qui si aggiunge uno per
      // uno, perché un'icona assente non deve impedire l'installazione.
      .then((c) => Promise.all(GUSCIO.map((u) => c.add(u).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((chiavi) => Promise.all(chiavi.filter((k) => k !== VERSIONE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') { return; }

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }

  // Roba immutabile: si risponde dalla cache e si ricontrolla in sottofondo.
  if (url.pathname.startsWith(BASE + 'assets/')) {
    e.respondWith(
      caches.match(req).then((ris) => {
        const rete = fetch(req).then((r) => {
          if (r && r.ok) { caches.open(VERSIONE).then((c) => c.put(req, r.clone())); }
          return r;
        }).catch(() => ris);
        return ris || rete;
      })
    );
    return;
  }

  // Tutto il resto: rete, sempre. Se non c'è, la paginetta.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(BASE + 'offline.html')));
  }
});
