-- 0007_legge : il calore, i fascicoli, il carcere (F4)
--
-- Il principio, da docs/DESIGN.md §4: **il rischio è una conseguenza, non un
-- dado**. Nel gioco del 1984 il poliziotto compariva a caso; nel door BBS del
-- 1993 arrivava se superavi una di quattro soglie misurabili, ed è quella
-- l'idea buona che nessuna versione successiva ha ripreso. Qui la soglia
-- diventa continua: ogni operazione scalda te e la piazza in proporzione
-- SUPERLINEARE al suo valore, il calore si vede sempre, e la legge reagisce a
-- quello. Operare piccolo è quasi gratis; il colpo grosso si paga.

ALTER TABLE personaggi
  ADD COLUMN calore        DECIMAL(9,3) NOT NULL DEFAULT 0 COMMENT 'Quanto scotti tu. Ti segue ovunque',
  ADD COLUMN calore_agg_a  DATETIME(3) NULL,
  ADD COLUMN profilo       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Pubblico nemico numero N: non scende col tempo',
  ADD COLUMN carcere_fino_a DATETIME(3) NULL,
  ADD COLUMN arresti       SMALLINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE piazze
  ADD COLUMN calore       DECIMAL(9,3) NOT NULL DEFAULT 0 COMMENT 'Quanto scotta la piazza, per colpa di chiunque',
  ADD COLUMN calore_agg_a DATETIME(3) NULL;

-- Il fascicolo: un inquirente con un nome, che accumula prove nel tempo vero.
-- Non è un tiro di dado che decide se ti prendono: è un processo che matura,
-- che si vede maturare, e contro cui si può fare qualcosa.
CREATE TABLE IF NOT EXISTS fascicoli (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  personaggio_id BIGINT UNSIGNED NOT NULL,
  inquirente     VARCHAR(64) NOT NULL,
  corpo          ENUM('questura','carabinieri','finanza') NOT NULL DEFAULT 'questura',
  prove          DECIMAL(6,2) NOT NULL DEFAULT 0,
  stato          ENUM('aperto','archiviato','eseguito') NOT NULL DEFAULT 'aperto',
  aperto_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  agg_a          DATETIME(3) NOT NULL,
  chiuso_at      DATETIME NULL,
  KEY idx_fasc_personaggio (personaggio_id, stato),
  CONSTRAINT fk_fasc_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quello che il giocatore VEDE succedere intorno a sé: l'auto sempre uguale
-- sotto casa, il cliente che fa troppe domande. Senza i segnali il fascicolo
-- sarebbe una sorpresa, e una sorpresa non è una meccanica: è un dado lento.
CREATE TABLE IF NOT EXISTS segnali (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  personaggio_id BIGINT UNSIGNED NOT NULL,
  genere         VARCHAR(24) NOT NULL,
  testo          VARCHAR(220) NOT NULL,
  gravita        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  letto          TINYINT(1) NOT NULL DEFAULT 0,
  fatto_at       DATETIME(3) NOT NULL,
  KEY idx_segn_personaggio (personaggio_id, id),
  CONSTRAINT fk_segn_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('calore.soglia_valore',    '1000000', 'int',   'Il valore di operazione che vale un grado di calore'),
  ('calore.esponente',        '1.5',     'float', 'Quanto il calore cresce più in fretta del valore'),
  ('calore.dimezzamento_ore', '12',      'int',   'Ogni quante ore il calore personale si dimezza'),
  ('calore.dimezzamento_piazza', '6',    'int',   'Ogni quante ore il calore di una piazza si dimezza'),
  ('legge.soglia_fascicolo',  '40',      'int',   'Calore personale oltre il quale si apre un fascicolo'),
  ('legge.prove_ora',         '2.0',     'float', 'Prove raccolte in un''ora a calore 100'),
  ('legge.prove_blitz',       '100',     'int',   'Prove oltre le quali scatta il blitz'),
  ('legge.controllo_base',    '0.020',   'float', 'Probabilità di base di un controllo, per azione'),
  ('legge.blocco_base',       '0.035',   'float', 'Probabilità di base di un posto di blocco, per viaggio'),
  ('legge.sequestro_quota',   '0.60',    'float', 'Quanta parte del contante sporco se ne va in un blitz'),
  ('legge.carcere_base_ore',  '4',       'int',   'Ore di carcere di base'),
  ('legge.carcere_per_prova', '0.04',    'float', 'Ore di carcere per punto di prova'),
  ('legge.carcere_per_profilo','3',      'int',   'Ore di carcere in più per ogni arresto precedente'),
  ('legge.avvocato_prezzo',   '4000000', 'int',   'Parcella, in denaro PULITO'),
  ('legge.avvocato_prove',    '30',      'int',   'Quante prove smonta un avvocato'),
  ('legge.bustarella_prezzo', '2500000', 'int',   'Quanto costa comprare qualcuno, in contanti'),
  ('legge.bustarella_prove',  '18',      'int',   'Quante prove fa sparire'),
  ('legge.bustarella_rischio','0.22',    'float', 'Probabilità che ti denunci invece')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
