-- 0014_un_fascicolo_solo : un fascicolo aperto per persona, garantito dal database (audit del 23/09/2026)
--
-- La regola c'era gia' nel codice (`Legge::prove` apre un fascicolo solo se non
-- ce n'e' uno), ma era un controllo seguito da un inserimento: due fatti nello
-- stesso istante — un controllo in strada mentre il battito apre un fascicolo
-- per troppo calore — passavano entrambi il controllo e ne aprivano due. E due
-- fascicoli maturano due blitz.
--
-- La colonna vale 1 per un fascicolo aperto e NULL per gli altri: un indice
-- unico ignora i NULL, quindi i chiusi possono essere quanti si vuole e gli
-- aperti uno solo.

-- Se ce ne fossero gia' due aperti per qualcuno, resta il piu' avanti.
UPDATE fascicoli f
  JOIN (SELECT personaggio_id, MAX(prove) AS top FROM fascicoli WHERE stato = 'aperto'
         GROUP BY personaggio_id HAVING COUNT(*) > 1) d ON d.personaggio_id = f.personaggio_id
   SET f.stato = 'archiviato', f.chiuso_at = NOW()
 WHERE f.stato = 'aperto' AND f.prove < d.top;

UPDATE fascicoli f
  JOIN (SELECT personaggio_id, MAX(id) AS ultimo FROM fascicoli WHERE stato = 'aperto'
         GROUP BY personaggio_id HAVING COUNT(*) > 1) d ON d.personaggio_id = f.personaggio_id
   SET f.stato = 'archiviato', f.chiuso_at = NOW()
 WHERE f.stato = 'aperto' AND f.id < d.ultimo;

ALTER TABLE fascicoli
  ADD COLUMN IF NOT EXISTS aperto TINYINT AS (IF(stato = 'aperto', 1, NULL)) PERSISTENT,
  ADD UNIQUE KEY IF NOT EXISTS uq_fascicolo_aperto (personaggio_id, aperto);
