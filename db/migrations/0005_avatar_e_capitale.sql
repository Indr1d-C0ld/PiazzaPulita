-- 0005_avatar_e_capitale : la vetrina del giocatore, e il gradino fra le fasce
--
-- CAPITALE INIZIALE. Portato da 2.000.000 a 300.000 lire. Non è un ritocco: è
-- il ripristino di un rapporto che l'originale del 1984 aveva e che la nostra
-- prima tabella dei beni aveva perso. Là si cominciava con 2.000 dollari e la
-- cocaina ne costava 15.000: non potevi permettertene NEMMENO UNA, e quello era
-- il motivo per cui esisteva la fascia bassa. Da noi, con due milioni in tasca e
-- la cocaina a 200.000 al grammo, il principiante saltava direttamente in cima e
-- sigarette e anfetamine non servivano a niente. Con 300.000 lire la cocaina
-- resta fuori portata (due grammi), l'eroina è simbolica, e la fascia bassa
-- torna a essere la strada da cui si comincia.

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('mondo.contante_iniziale', '300000', 'int', 'Lire in tasca a chi comincia')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), note = VALUES(note);

-- AVATAR. Il volto del giocatore, che sta sul profilo pubblico. Non si serve
-- mai il file caricato così com'è: viene riaperto, ritagliato e riscritto in
-- WebP. Quello che finisce sul disco è un'immagine costruita da noi.
ALTER TABLE users
  ADD COLUMN avatar_file VARCHAR(80) NULL COMMENT 'Nome del file WebP, che è l''impronta del contenuto',
  ADD COLUMN avatar_hash CHAR(64) NULL COMMENT 'sha256 dell''immagine prodotta',
  ADD COLUMN avatar_at   DATETIME NULL;
