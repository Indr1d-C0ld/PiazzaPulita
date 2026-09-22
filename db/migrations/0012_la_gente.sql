-- 0012_la_gente : chiacchiera di piazza, baratto, messaggi dell'amministrazione (F8)
--
-- Fin qui gli altri giocatori esistevano nel motore — il mercato è condiviso,
-- ci si può menare, si tiene il territorio — ma quasi non si VEDEVANO. Qui si
-- aggiungono i due modi civili di incontrarsi.
--
-- LA CHIACCHIERA È DI PIAZZA, e non è una limitazione tecnica: è la stessa
-- regola che regge tutto il resto. In questo gioco l'informazione sui prezzi
-- altrove si paga (il basista, docs/DESIGN.md §6.3); una posta privata a
-- distanza zero la regalerebbe a chiunque. Per dire qualcosa a qualcuno devi
-- essere dove sta lui, e quello che dici lo sentono tutti quelli che sono lì.
-- I messaggi durano poche ore e spariscono: è parlato, non scritto.
--
-- IL BARATTO È MERCE CONTRO MERCE, mai denaro. Uno scambio con dentro le lire
-- sarebbe un tubo che aggira due invarianti in un colpo solo: il tetto di
-- reddito del mondo (§2.6) e la capacità oraria dei canali di riciclaggio
-- (§5), che è il freno vero dell'economia. Merce contro merce no: sposta roba
-- fra due carichi senza creare una lira, e resta utile davvero — ti libera di
-- quello che qui non assorbe nessuno.

CREATE TABLE IF NOT EXISTS chiacchiere (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  piazza_id      SMALLINT UNSIGNED NOT NULL,
  personaggio_id BIGINT UNSIGNED NULL,
  autore         VARCHAR(64) NOT NULL,
  testo          VARCHAR(220) NOT NULL,
  genere         ENUM('voce','avviso') NOT NULL DEFAULT 'voce',
  fatto_at       DATETIME(3) NOT NULL,
  KEY idx_chiac_piazza (piazza_id, id),
  KEY idx_chiac_quando (fatto_at),
  CONSTRAINT fk_chiac_piazza FOREIGN KEY (piazza_id) REFERENCES piazze(id) ON DELETE CASCADE,
  CONSTRAINT fk_chiac_autore FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS baratti (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  da_id         BIGINT UNSIGNED NOT NULL,
  a_id          BIGINT UNSIGNED NOT NULL,
  piazza_id     SMALLINT UNSIGNED NOT NULL,
  bene_dato     TINYINT UNSIGNED NOT NULL,
  quanto_dato   INT UNSIGNED NOT NULL,
  bene_chiesto  TINYINT UNSIGNED NOT NULL,
  quanto_chiesto INT UNSIGNED NOT NULL,
  stato         ENUM('proposto','accettato','rifiutato','scaduto','ritirato') NOT NULL DEFAULT 'proposto',
  proposto_at   DATETIME(3) NOT NULL,
  scade_at      DATETIME(3) NOT NULL,
  chiuso_at     DATETIME(3) NULL,
  KEY idx_baratto_a (a_id, stato),
  KEY idx_baratto_da (da_id, stato),
  KEY idx_baratto_scadenza (stato, scade_at),
  CONSTRAINT fk_baratto_da FOREIGN KEY (da_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_baratto_a  FOREIGN KEY (a_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_baratto_piazza FOREIGN KEY (piazza_id) REFERENCES piazze(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('chiacchiera.ore',      '8',   'int', 'Dopo quante ore una voce di piazza si dimentica'),
  ('chiacchiera.al_minuto','4',   'int', 'Quante voci può dire una persona in un minuto'),
  ('chiacchiera.quante',   '40',  'int', 'Quante voci si leggono stando in piazza'),
  ('baratto.minuti',       '30',  'int', 'Per quanto resta in piedi una proposta di baratto'),
  ('baratto.max_aperti',   '5',   'int', 'Quante proposte può avere in giro una persona')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
