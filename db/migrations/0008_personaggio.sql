-- 0008_personaggio : attributi, reputazione, organico, fornitori (F5)
--
-- Da qui il personaggio smette di essere un portafoglio con una posizione.
-- Gli attributi crescono con l'uso e non con punti da spendere: si diventa
-- bravi a trattare trattando, non scegliendolo da un elenco. La reputazione ha
-- due assi ortogonali — quanto ti rispettano e quanto ti temono — e si possono
-- giocare in modi opposti.
--
-- L'organico porta con sé le due cose rimaste indietro: i corrieri (i «carichi
-- in transito» di F3, che senza qualcuno a cui affidarli non esistevano) e i
-- pentiti (che in F4 non potevano esistere perché non c'era nessuno che
-- potesse parlare).

ALTER TABLE personaggi
  ADD COLUMN trattativa    DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Stringe lo spread di piazza',
  ADD COLUMN fiuto         DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Quanto vedi del mercato',
  ADD COLUMN sangue_freddo DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Controlli, posti di blocco, interrogatori',
  ADD COLUMN organizzazione DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Quanti uomini reggi',
  ADD COLUMN credito       DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Condizioni con usurai e fornitori',
  ADD COLUMN rispetto      DECIMAL(5,2) NOT NULL DEFAULT 0,
  ADD COLUMN timore        DECIMAL(5,2) NOT NULL DEFAULT 0;

-- Gli uomini. Hanno un nome perché perderli deve costare qualcosa.
CREATE TABLE IF NOT EXISTS uomini (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  personaggio_id BIGINT UNSIGNED NOT NULL,
  nome           VARCHAR(48) NOT NULL,
  ruolo          ENUM('corriere','vedetta','contabile','riciclatore','basista','guardia') NOT NULL,
  competenza     TINYINT UNSIGNED NOT NULL DEFAULT 50,
  lealta         DECIMAL(5,2) NOT NULL DEFAULT 70,
  stipendio_ora  BIGINT NOT NULL,
  piazza_id      SMALLINT UNSIGNED NULL COMMENT 'Dove sta, per chi sta in un posto',
  stato          ENUM('libero','in_viaggio','dentro','sparito') NOT NULL DEFAULT 'libero',
  assunto_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pagato_fino_a  DATETIME(3) NOT NULL,
  agg_a          DATETIME(3) NOT NULL,
  KEY idx_uomo_personaggio (personaggio_id, stato),
  KEY idx_uomo_piazza (piazza_id),
  CONSTRAINT fk_uomo_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_uomo_piazza FOREIGN KEY (piazza_id) REFERENCES piazze(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un corriere per strada con la roba: è il «carico in transito» che in F3 non
-- si poteva fare. Arriva in un deposito, non addosso a te: se non hai un posto
-- dove scaricare, non parte.
CREATE TABLE IF NOT EXISTS corse (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uomo_id        BIGINT UNSIGNED NOT NULL,
  personaggio_id BIGINT UNSIGNED NOT NULL,
  da_piazza_id   SMALLINT UNSIGNED NOT NULL,
  a_piazza_id    SMALLINT UNSIGNED NOT NULL,
  bene_id        TINYINT UNSIGNED NOT NULL,
  quantita       INT UNSIGNED NOT NULL,
  costo_totale   BIGINT NOT NULL,
  partito_at     DATETIME(3) NOT NULL,
  arrivo_at      DATETIME(3) NOT NULL,
  esito          ENUM('in_corso','arrivata','sequestrata','sparita') NOT NULL DEFAULT 'in_corso',
  chiusa_at      DATETIME(3) NULL,
  KEY idx_corsa_personaggio (personaggio_id, id),
  KEY idx_corsa_aperte (esito, arrivo_at),
  CONSTRAINT fk_corsa_uomo FOREIGN KEY (uomo_id) REFERENCES uomini(id) ON DELETE CASCADE,
  CONSTRAINT fk_corsa_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- I fornitori: si sbloccano col rispetto, e vendono sottocosto rispetto alla
-- piazza. È la progressione di lungo periodo — quella che non si compra.
CREATE TABLE IF NOT EXISTS fornitori (
  codice      VARCHAR(16) NOT NULL PRIMARY KEY,
  nome        VARCHAR(48) NOT NULL,
  descrizione VARCHAR(190) NOT NULL,
  fascia      ENUM('bassa','media','alta','armi') NOT NULL,
  rispetto_min SMALLINT UNSIGNED NOT NULL,
  sconto      DECIMAL(4,3) NOT NULL COMMENT 'Quanto meno della piazza',
  lotto_min   INT UNSIGNED NOT NULL COMMENT 'Sotto questa quantità non ti parla',
  ordine      TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fornitori (codice, nome, descrizione, fascia, rispetto_min, sconto, lotto_min, ordine) VALUES
  ('zio',      'Lo zio del bar',      'Vende quello che gli capita, e si fida di pochi.',                    'bassa',  10, 0.08,  20, 0),
  ('camionist','Il camionista',       'Scarica alle quattro del mattino e non chiede niente.',               'bassa',  30, 0.14,  80, 1),
  ('marsiglia','Quelli di Marsiglia', 'Passano dal confine una volta a settimana. Vanno pagati puntuali.',   'media',  40, 0.12,  60, 2),
  ('porto',    'Il gancio in porto',  'Sa quale container non viene aperto. Chiede molto e consegna molto.', 'media',  65, 0.18, 200, 3),
  ('calabria', 'La gente di Gioia',   'Non trattano con chi non conoscono. Poi trattano solo con te.',       'alta',   70, 0.15, 120, 4),
  ('sudameric','Il canale diretto',   'Una telefonata, una data, e una quantità che non puoi rifiutare.',    'alta',   90, 0.22, 400, 5)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descrizione = VALUES(descrizione), fascia = VALUES(fascia),
  rispetto_min = VALUES(rispetto_min), sconto = VALUES(sconto), lotto_min = VALUES(lotto_min), ordine = VALUES(ordine);

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('attributi.crescita',     '1.5',   'float', 'Quanto cresce un attributo per azione, prima dei rendimenti calanti'),
  ('attributi.scala',        '25',    'int',   'Più è alto, più a lungo un attributo continua a crescere'),
  ('organico.base',          '1',     'int',   'Uomini che si reggono con organizzazione a zero'),
  ('organico.per_grado',     '10',    'int',   'Ogni quanti gradi di organizzazione si regge un uomo in più'),
  ('organico.stipendio_min', '18000', 'int',   'Stipendio orario di un uomo qualunque'),
  ('organico.ingaggio',      '8',     'int',   'Ore di stipendio da anticipare all''assunzione'),
  ('organico.lealta_calo',   '0.35',  'float', 'Lealtà persa in un''ora da chi non viene pagato'),
  ('organico.lealta_calo_arresti', '12', 'int', 'Lealtà persa quando un collega finisce dentro'),
  ('organico.pentito_soglia','35',    'int',   'Sotto questa lealtà un uomo preso può parlare'),
  ('organico.pentito_prove', '35',    'int',   'Quante prove porta un pentito')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
