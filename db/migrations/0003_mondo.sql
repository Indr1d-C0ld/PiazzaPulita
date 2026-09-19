-- 0003_mondo : la geografia e il personaggio nel mondo (F1)
--
-- Due livelli: le citta' (nodi di viaggio, ore reali) e le piazze (nodi di
-- mercato, minuti reali). Il carattere economico sta gia' qui anche se il
-- mercato arriva in F2: e' una proprieta' del mondo, non del motore dei prezzi.

CREATE TABLE IF NOT EXISTS citta (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codice     VARCHAR(8)  NOT NULL,
  nome       VARCHAR(48) NOT NULL,
  lat        DECIMAL(9,6)  NOT NULL,
  lon        DECIMAL(9,6)  NOT NULL,
  carattere  ENUM('porto','consumo','snodo') NOT NULL DEFAULT 'consumo',
  aeroporto  TINYINT(1) NOT NULL DEFAULT 1,
  nota       VARCHAR(160) NULL,
  UNIQUE KEY uq_citta_codice (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS piazze (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  citta_id   SMALLINT UNSIGNED NOT NULL,
  codice     VARCHAR(24) NOT NULL,
  nome       VARCHAR(48) NOT NULL,
  lat        DECIMAL(9,6) NOT NULL,
  lon        DECIMAL(9,6) NOT NULL,
  tipo       ENUM('periferia','popolare','centro','stazione','benestante','universitaria')
             NOT NULL DEFAULT 'popolare',
  polizia    TINYINT UNSIGNED NOT NULL DEFAULT 30,   -- presenza, in percento
  UNIQUE KEY uq_piazza_codice (codice),
  KEY idx_piazza_citta (citta_id),
  CONSTRAINT fk_piazza_citta FOREIGN KEY (citta_id) REFERENCES citta(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Il personaggio. Uno per account: in questo mondo non si azzera niente, quindi
-- non c'e' da ricrearlo mai (decisione 3). `arrivo_at` non nullo significa che
-- si e' in viaggio VERSO `piazza_id`: lo stato sta su una riga sola, senza join.
CREATE TABLE IF NOT EXISTS personaggi (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  piazza_id   SMALLINT UNSIGNED NOT NULL,
  arrivo_at   DATETIME(3) NULL,
  contante    BIGINT NOT NULL DEFAULT 0,             -- lire sporche in tasca
  creato_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  avanzato_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_personaggio_utente (user_id),
  KEY idx_personaggio_piazza (piazza_id),
  KEY idx_personaggio_arrivo (arrivo_at),
  CONSTRAINT fk_personaggio_utente FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_personaggio_piazza FOREIGN KEY (piazza_id) REFERENCES piazze(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Il diario degli spostamenti. Lo stato vivo sta su `personaggi`; questa e' la
-- storia, e serve alle statistiche e a ricostruire chi era dove.
CREATE TABLE IF NOT EXISTS spostamenti (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  personaggio_id BIGINT UNSIGNED NOT NULL,
  da_piazza_id   SMALLINT UNSIGNED NOT NULL,
  a_piazza_id    SMALLINT UNSIGNED NOT NULL,
  mezzo          VARCHAR(16) NOT NULL,
  km             DECIMAL(8,2) NOT NULL,
  costo          BIGINT NOT NULL DEFAULT 0,
  partito_at     DATETIME(3) NOT NULL,
  arrivo_at      DATETIME(3) NOT NULL,
  arrivato_at    DATETIME(3) NULL,
  KEY idx_spost_personaggio (personaggio_id, id),
  KEY idx_spost_aperti (arrivato_at, arrivo_at),
  CONSTRAINT fk_spost_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('mondo.compressione_viaggio', '3',       'int', 'Minuti di viaggio per minuto reale'),
  ('mondo.fattore_percorso',     '1.25',    'float', 'Quanto strade e binari allungano la linea d''aria'),
  ('mondo.contante_iniziale',    '2000000', 'int', 'Lire in tasca a chi comincia'),
  ('mondo.volo_km_minimi',       '300',     'int', 'Sotto questa distanza non si vola')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
