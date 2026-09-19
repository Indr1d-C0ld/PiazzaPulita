-- 0006_denaro_logistica : il secondo sistema economico (F3)
--
-- Qui nasce la distinzione che l'originale del 1984 non aveva e che è la vera
-- differenza fra un gioco di arbitraggio e un gioco di criminalità: il denaro
-- che fai è SPORCO. Ingombra, si perde nei sequestri, non compra niente di
-- durevole e non conta in classifica finché non passa da un canale che si
-- prende la sua percentuale e ha una capacità oraria.
--
-- È anche il pozzo che tiene in piedi la decisione 3 (mondo eterno): una
-- commissione sul reddito è proporzionale al reddito, quindi non si diluisce
-- mai come farebbe un costo fisso.

ALTER TABLE personaggi
  ADD COLUMN pulito       BIGINT NOT NULL DEFAULT 0 COMMENT 'Lire pulite: comprano ciò che dura, e contano in classifica',
  ADD COLUMN debito       BIGINT NOT NULL DEFAULT 0,
  ADD COLUMN debito_agg_a DATETIME(3) NULL COMMENT 'Da quando non si calcolano gli interessi',
  ADD COLUMN debito_tetto BIGINT NOT NULL DEFAULT 0 COMMENT 'Oltre qui il debito non cresce: cominciano le conseguenze',
  ADD COLUMN mezzo        VARCHAR(16) NULL COMMENT 'Il veicolo posseduto, se c''è';

-- --- I canali di riciclaggio (catalogo) -------------------------------------
CREATE TABLE IF NOT EXISTS canali (
  codice      VARCHAR(16) NOT NULL PRIMARY KEY,
  nome        VARCHAR(48) NOT NULL,
  descrizione VARCHAR(190) NOT NULL,
  commissione DECIMAL(4,3) NOT NULL,          -- quanto si prende
  capacita    BIGINT NOT NULL,                -- lire sporche lavabili in un'ora
  prezzo      BIGINT NOT NULL,                -- costo di acquisizione, in PULITO
  calore      TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- servirà in F4
  ordine      TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quello che uno possiede, con la coda di denaro in lavorazione.
CREATE TABLE IF NOT EXISTS canali_posseduti (
  personaggio_id BIGINT UNSIGNED NOT NULL,
  canale         VARCHAR(16) NOT NULL,
  coda           BIGINT NOT NULL DEFAULT 0,   -- sporco in attesa di uscire pulito
  lavato         BIGINT NOT NULL DEFAULT 0,   -- quanto ci è passato in tutto
  agg_a          DATETIME(3) NOT NULL,
  PRIMARY KEY (personaggio_id, canale),
  CONSTRAINT fk_canale_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_canale_catalogo    FOREIGN KEY (canale) REFERENCES canali(codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- I mezzi (catalogo) ------------------------------------------------------
CREATE TABLE IF NOT EXISTS mezzi (
  codice      VARCHAR(16) NOT NULL PRIMARY KEY,
  nome        VARCHAR(48) NOT NULL,
  descrizione VARCHAR(190) NOT NULL,
  capienza    SMALLINT UNSIGNED NOT NULL,     -- spazi AGGIUNTI a quelli di base
  prezzo      BIGINT NOT NULL,                -- in PULITO: un'auto si intesta
  kmh_citta   DECIMAL(5,1) NOT NULL,
  kmh_paese   DECIMAL(5,1) NOT NULL,
  costo_km    INT NOT NULL,                   -- benzina, in lire
  vistoso     TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- servirà in F4
  ordine      TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- I depositi --------------------------------------------------------------
-- Uno per piazza al massimo. L'affitto si paga a ore e in contanti sporchi:
-- il padrone di casa non fa fatture.
CREATE TABLE IF NOT EXISTS depositi (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  personaggio_id BIGINT UNSIGNED NOT NULL,
  piazza_id      SMALLINT UNSIGNED NOT NULL,
  capienza       INT UNSIGNED NOT NULL DEFAULT 5000,
  affitto_ora    BIGINT NOT NULL,
  aperto_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pagato_fino_a  DATETIME(3) NOT NULL,
  UNIQUE KEY uq_deposito_piazza (personaggio_id, piazza_id),
  KEY idx_deposito_piazza (piazza_id),
  CONSTRAINT fk_deposito_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_deposito_piazza      FOREIGN KEY (piazza_id) REFERENCES piazze(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deposito_merce (
  deposito_id  BIGINT UNSIGNED NOT NULL,
  bene_id      TINYINT UNSIGNED NOT NULL,
  quantita     INT UNSIGNED NOT NULL DEFAULT 0,
  costo_totale BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (deposito_id, bene_id),
  CONSTRAINT fk_depmerce_deposito FOREIGN KEY (deposito_id) REFERENCES depositi(id) ON DELETE CASCADE,
  CONSTRAINT fk_depmerce_bene     FOREIGN KEY (bene_id) REFERENCES beni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Il registro del denaro --------------------------------------------------
-- Ogni lira che entra o esce lascia una riga. Serve alla pagina di contabilità,
-- e serve a `balance:report`: senza un registro non si può dire se il bilancio
-- quadra, si può solo sperarlo.
CREATE TABLE IF NOT EXISTS movimenti (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  personaggio_id BIGINT UNSIGNED NOT NULL,
  genere         VARCHAR(24) NOT NULL,   -- vendita, acquisto, lavaggio, prestito, ...
  cassa          ENUM('sporco','pulito','debito') NOT NULL,
  importo        BIGINT NOT NULL,        -- con segno: + entra, − esce
  nota           VARCHAR(120) NULL,
  fatto_at       DATETIME(3) NOT NULL,
  KEY idx_mov_personaggio (personaggio_id, id),
  KEY idx_mov_quando (fatto_at),
  CONSTRAINT fk_mov_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Cataloghi ---------------------------------------------------------------
INSERT INTO canali (codice, nome, descrizione, commissione, capacita, prezzo, calore, ordine) VALUES
  ('bar',      'Il bar di un amico',  'Scontrini gonfiati e un registratore di cassa compiacente. Poco, ma non lo guarda nessuno.', 0.350,    150000,          0,  0, 0),
  ('autolav',  'L''autolavaggio',     'Contanti tutto il giorno e nessuno che conta le macchine.',                                   0.300,    400000,    3000000,  2, 1),
  ('giochi',   'La sala giochi',      'Gettoni che entrano, gettoni che escono. Il conto lo fai tu.',                                0.280,   1200000,    9000000,  6, 2),
  ('cantiere', 'Il cantiere',         'Fatture per lavori che nessuno ha visto. Volumi veri, occhi veri addosso.',                   0.220,   4000000,   30000000, 14, 3),
  ('totonero', 'Il totonero',         'Scommesse che vincono sempre le persone giuste.',                                             0.180,  10000000,   80000000, 22, 4),
  ('chiasso',  'Il cambiavalute',     'Una valigia, un''ora di macchina, e il confine. Franchi in cambio di lire.',                   0.120,  30000000,  200000000, 38, 5),
  ('estero',   'La società estera',   'Una ragione sociale a Vaduz e un conto che non risponde a nessuno.',                          0.080, 100000000,  600000000, 55, 6)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descrizione = VALUES(descrizione),
  commissione = VALUES(commissione), capacita = VALUES(capacita), prezzo = VALUES(prezzo),
  calore = VALUES(calore), ordine = VALUES(ordine);

INSERT INTO mezzi (codice, nome, descrizione, capienza, prezzo, kmh_citta, kmh_paese, costo_km, vistoso, ordine) VALUES
  ('utilitaria', 'Una 127 di seconda mano', 'Scassata, anonima, e ci sta dentro molto più di un borsone.',            120,   2500000, 26.0, 75.0, 45, 1, 0),
  ('berlina',    'Una berlina',             'Va forte, non si nota, e in autostrada non la ferma nessuno.',           220,   9000000, 30.0, 90.0, 70, 3, 1),
  ('furgone',    'Un furgone',              'Ci carichi una piazza intera. E ti vedono arrivare da lontano.',         820,  20000000, 24.0, 70.0, 90, 8, 2)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descrizione = VALUES(descrizione),
  capienza = VALUES(capienza), prezzo = VALUES(prezzo), kmh_citta = VALUES(kmh_citta),
  kmh_paese = VALUES(kmh_paese), costo_km = VALUES(costo_km), vistoso = VALUES(vistoso), ordine = VALUES(ordine);

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('denaro.debito_iniziale',   '1500000', 'int',   'Quanto si deve all''usuraio il primo giorno'),
  ('denaro.interesse_giorno',  '0.10',    'float', 'Interesse giornaliero sul debito, composto di continuo'),
  ('denaro.tetto_debito',      '3.0',     'float', 'Oltre questo multiplo del capitale prestato il debito non cresce più'),
  ('denaro.prestito_base',     '2000000', 'int',   'Quanto si può farsi prestare a prescindere da tutto'),
  ('denaro.prestito_su_pulito','2.0',     'float', 'Quante volte il proprio pulito si può farsi prestare in più'),
  ('deposito.affitto_ora',     '12000',   'int',   'Affitto orario di un deposito, in lire sporche'),
  ('deposito.capienza',        '5000',    'int',   'Spazi di un deposito'),
  ('deposito.anticipo_ore',    '24',      'int',   'Quante ore d''affitto si pagano all''apertura')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
