<div class="foglio">
  <span class="occhiello">Gioco di commercio e rischio · multigiocatore</span>
  <h1>Compra dove costa poco. Vendi dove costa tanto.<br>Poi vedi chi ti guarda.</h1>
  <p class="sommario">
    Italia, anni ottanta. Nove città, una quarantina di piazze, un listino che cambia
    da un'ora all'altra e non cambia per te soltanto: <strong>il mercato è uno solo</strong>,
    e quando compri il prezzo sale per chi viene dopo. Quando vendi, crolla.
  </p>
  <p class="sommario">
    Il resto è quello che ne consegue: una rete di depositi e corrieri perché un uomo
    solo non regge il volume, denaro da lavare perché il contante non compra niente,
    e un fascicolo che si ingrossa in questura ogni volta che alzi il tiro.
  </p>
  <div class="azioni">
    <a class="bottone" href="<?= e(url('/iscrizione')) ?>">Entra nel giro</a>
    <a class="bottone bottone--fantasma" href="<?= e(url('/regole')) ?>">Come funziona</a>
  </div>
  <p class="minuto" style="margin-top:1.2rem">
    <?= (int) $iscritti ?> <?= (int) $iscritti === 1 ? 'persona iscritta' : 'persone iscritte' ?>.
    Il mondo è in costruzione: vedi <a href="<?= e(url('/regole')) ?>">a che punto siamo</a>.
  </p>
</div>

<div class="griglia griglia--3">
  <div class="foglio">
    <span class="occhiello">Il mercato</span>
    <p class="sommario minuto">Ogni piazza ha una giacenza e una capacità di assorbimento
       finite. Il prezzo non è un dado: è quello che resta dopo che sono passati
       gli altri.</p>
  </div>
  <div class="foglio">
    <span class="occhiello">Il calore</span>
    <p class="sommario minuto">Il rischio non ti capita addosso: te lo procuri. Più grosso
       è il colpo, più scotta la piazza — e questo si vede prima di agire, non dopo.</p>
  </div>
  <div class="foglio">
    <span class="occhiello">Gli altri</span>
    <p class="sommario minuto">Non ci sono avversari finti. Chi ti fa crollare il prezzo,
       chi ti brucia la piazza, chi manda una soffiata: sono tutte persone vere.</p>
  </div>
</div>
