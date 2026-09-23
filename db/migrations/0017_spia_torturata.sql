-- 0017_spia_torturata : il quarto esito della spia scoperta (audit del 23/09/2026)
--
-- Il progetto (§6.2) vuole quattro esiti «come nell'originale» — dopewars:
-- uccisa, torturata, scappata, passata al nemico — e il codice lo diceva nel
-- commento, ma ne tirava a sorte tre. Il quarto è quello che mancava: la spia
-- presa e fatta parlare, che dice a chi spiava il nome di chi l'aveva mandata.
-- 'richiamata' resta nell'elenco per le righe che ci fossero già.

ALTER TABLE spie
  MODIFY COLUMN esito ENUM('dentro','uccisa','torturata','scappata','voltafaccia','richiamata') NOT NULL DEFAULT 'dentro';
