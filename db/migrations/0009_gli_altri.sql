-- 0009_gli_altri : rivalità, batterie, territorio, cronaca (F6)
--
-- Fin qui il multigiocatore c'era già, ma solo come conseguenza: il mercato è
-- condiviso, quindi chi compra prima alza il prezzo a chi viene dopo e chi
-- vende troppo lo fa crollare a tutti. È l'attrito che non costa una riga di
-- codice, ed è il livello principale (docs/DESIGN.md §6.2).
--
-- Qui si aggiunge quello che va scritto: colpire una persona invece di un
-- prezzo. La decisione 4 dice PvP pieno, e il prezzo lo paga il §4.4 — il
-- profilo criminale non scende col tempo. Il bottino è solo ciò che la vittima
-- aveva ADDOSSO: mai il pulito, mai gli immobili, mai i canali. E chi ha poco
-- non ha bottino: cacciare i principianti resta possibile ed è l'attività
-- peggio pagata del gioco.

ALTER TABLE personaggi
  ADD COLUMN IF NOT EXISTS salute          TINYINT UNSIGNED NOT NULL DEFAULT 100,
  ADD COLUMN IF NOT EXISTS ospedale_fino_a DATETIME(3) NULL,
  ADD COLUMN IF NOT EXISTS batteria_id     BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS scontri_vinti   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS scontri_persi   SMALLINT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS scontri (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attaccante_id  BIGINT UNSIGNED NOT NULL,
  difensore_id   BIGINT UNSIGNED NOT NULL,
  piazza_id      SMALLINT UNSIGNED NOT NULL,
  esito          ENUM('vinto','perso','fuga','niente') NOT NULL,
  danno_dato     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  danno_preso    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bottino_sporco BIGINT NOT NULL DEFAULT 0,
  bottino_unita  INT UNSIGNED NOT NULL DEFAULT 0,
  racconto       VARCHAR(255) NOT NULL DEFAULT '',
  fatto_at       DATETIME(3) NOT NULL,
  KEY idx_scontro_attaccante (attaccante_id, id),
  KEY idx_scontro_difensore (difensore_id, id),
  CONSTRAINT fk_scontro_att FOREIGN KEY (attaccante_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_scontro_dif FOREIGN KEY (difensore_id) REFERENCES personaggi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La spia: un tuo uomo dentro casa di un altro. Finché non lo scoprono vedi
-- quello che vede lui. Quattro esiti alla scoperta, come nell'originale.
CREATE TABLE IF NOT EXISTS spie (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  padrone_id   BIGINT UNSIGNED NOT NULL,
  bersaglio_id BIGINT UNSIGNED NOT NULL,
  nome         VARCHAR(48) NOT NULL,
  messa_at     DATETIME(3) NOT NULL,
  agg_a        DATETIME(3) NOT NULL,
  scoperta_at  DATETIME(3) NULL,
  esito        ENUM('dentro','uccisa','scappata','voltafaccia','richiamata') NOT NULL DEFAULT 'dentro',
  UNIQUE KEY uq_spia (padrone_id, bersaglio_id, esito),
  KEY idx_spia_bersaglio (bersaglio_id),
  CONSTRAINT fk_spia_padrone FOREIGN KEY (padrone_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_spia_bersaglio FOREIGN KEY (bersaglio_id) REFERENCES personaggi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soffiate (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  da_id     BIGINT UNSIGNED NOT NULL,
  contro_id BIGINT UNSIGNED NOT NULL,
  costo     BIGINT NOT NULL,
  prove     DECIMAL(6,2) NOT NULL,
  ritorta   TINYINT(1) NOT NULL DEFAULT 0,
  fatto_at  DATETIME(3) NOT NULL,
  KEY idx_soffiata_da (da_id, id),
  KEY idx_soffiata_contro (contro_id, id),
  CONSTRAINT fk_soff_da FOREIGN KEY (da_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_soff_contro FOREIGN KEY (contro_id) REFERENCES personaggi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Le batterie: gruppi di giocatori con una cassa comune.
CREATE TABLE IF NOT EXISTS batterie (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nome      VARCHAR(48) NOT NULL,
  sigla     VARCHAR(5) NOT NULL,
  capo_id   BIGINT UNSIGNED NOT NULL,
  cassa     BIGINT NOT NULL DEFAULT 0,
  motto     VARCHAR(160) NULL,
  creata_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_batteria_nome (nome),
  UNIQUE KEY uq_batteria_sigla (sigla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Il territorio: si controlla stando e lavorando, non premendo un pulsante.
CREATE TABLE IF NOT EXISTS territori (
  piazza_id   SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  batteria_id BIGINT UNSIGNED NULL,
  presenza    DECIMAL(10,3) NOT NULL DEFAULT 0,
  dal         DATETIME NULL,
  agg_a       DATETIME(3) NOT NULL,
  CONSTRAINT fk_terr_piazza FOREIGN KEY (piazza_id) REFERENCES piazze(id) ON DELETE CASCADE,
  CONSTRAINT fk_terr_batteria FOREIGN KEY (batteria_id) REFERENCES batterie(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quanto ogni batteria ha lavorato in ogni piazza. È da qui che esce chi
-- comanda: non da una dichiarazione, da quello che si è fatto lì.
CREATE TABLE IF NOT EXISTS presenze (
  piazza_id   SMALLINT UNSIGNED NOT NULL,
  batteria_id BIGINT UNSIGNED NOT NULL,
  punti       DECIMAL(10,3) NOT NULL DEFAULT 0,
  agg_a       DATETIME(3) NOT NULL,
  PRIMARY KEY (piazza_id, batteria_id),
  CONSTRAINT fk_pres_piazza FOREIGN KEY (piazza_id) REFERENCES piazze(id) ON DELETE CASCADE,
  CONSTRAINT fk_pres_batteria FOREIGN KEY (batteria_id) REFERENCES batterie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La cronaca: il notiziario del mondo, uguale per tutti. Senza, metà di quello
-- che succede sarebbe invisibile — e un mondo condiviso che non si vede è un
-- mondo che tanto vale non avere.
CREATE TABLE IF NOT EXISTS cronaca (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  genere    VARCHAR(24) NOT NULL,
  testo     VARCHAR(255) NOT NULL,
  piazza_id SMALLINT UNSIGNED NULL,
  rilievo   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  fatto_at  DATETIME(3) NOT NULL,
  KEY idx_cronaca_quando (id),
  CONSTRAINT fk_cronaca_piazza FOREIGN KEY (piazza_id) REFERENCES piazze(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE personaggi
  ADD CONSTRAINT fk_personaggio_batteria FOREIGN KEY IF NOT EXISTS (batteria_id) REFERENCES batterie(id) ON DELETE SET NULL;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('pvp.attacco_base',     '80',      'int',   'Punteggio d''attacco di chiunque, a mani nude'),
  ('pvp.per_arma',         '25',      'int',   'Quanto aggiunge ogni arma addosso'),
  ('pvp.difesa_base',      '100',     'int',   'Punteggio di difesa di chiunque'),
  ('pvp.per_guardia',      '20',      'int',   'Quanto aggiunge ogni guardia'),
  ('pvp.fuga',             '0.60',    'float', 'Probabilità di svignarsela, dimezzata per chi attacca'),
  ('pvp.bottino_minimo',   '500000',  'int',   'Sotto questo valore non c''è niente da prendere'),
  ('pvp.ospedale_ore',     '6',       'int',   'Ore di convalescenza a terra'),
  ('pvp.calore',           '60',      'int',   'Gradi di calore per un attacco'),
  ('pvp.spia_prezzo',      '3000000', 'int',   'Costo di infiltrare un uomo, in contanti'),
  ('pvp.spia_scoperta_ora','0.04',    'float', 'Probabilità oraria che una spia venga scoperta'),
  ('pvp.soffiata_prezzo',  '2000000', 'int',   'Costo di una soffiata, in contanti'),
  ('pvp.soffiata_prove',   '22',      'int',   'Prove che una soffiata porta al bersaglio'),
  ('pvp.soffiata_ritorno', '0.25',    'float', 'Probabilità che la soffiata si ritorca contro'),
  ('batteria.fondazione',  '10000000','int',   'Costo di fondare una batteria, in denaro PULITO'),
  ('batteria.pizzo',       '0.04',    'float', 'Quota sulle compravendite altrui in territorio controllato'),
  ('territorio.per_lira',  '0.0000012','float','Punti di presenza per lira movimentata in piazza'),
  ('territorio.dimezzamento_ore','72','int',   'Ogni quante ore la presenza si dimezza'),
  ('territorio.soglia',    '150',     'int',   'Punti sotto i quali una piazza non è di nessuno')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
