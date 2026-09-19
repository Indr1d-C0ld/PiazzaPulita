-- 0004_mercato : il cuore del gioco (F2)
--
-- Il mercato è condiviso: una riga per coppia (piazza, bene), e quella riga la
-- vedono e la muovono tutti. È la differenza strutturale con l'originale del
-- 1984 e con dopewars, dove i prezzi erano generati per giocatore.
--
-- Giacenze e assorbimenti NON si scrivono a mano: si derivano dal tetto di
-- reddito orario del mondo (`mondo.reddito_orario`, docs/DESIGN.md §2.6) con
--     A*(p,b) = quota(p,b) x R / spread_unitario(b)
-- e le quote sommano a 1. Ritoccare una piazza a mano fa marcire l'invariante:
-- si tocca la sua QUOTA, che è relativa, e qualcun altro perde ciò che lei guadagna.

ALTER TABLE citta ADD COLUMN peso DECIMAL(4,2) NOT NULL DEFAULT 1.00
  COMMENT 'Quanto mercato assorbe questa città rispetto alle altre';

ALTER TABLE personaggi ADD COLUMN capienza SMALLINT UNSIGNED NOT NULL DEFAULT 80
  COMMENT 'Spazi disponibili: addosso più borsone. I mezzi arrivano in F3';

CREATE TABLE IF NOT EXISTS beni (
  id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codice          VARCHAR(16) NOT NULL,
  nome            VARCHAR(32) NOT NULL,
  unita           VARCHAR(16) NOT NULL,
  fascia          ENUM('bassa','media','alta','armi') NOT NULL,
  prezzo_min      BIGINT NOT NULL,
  prezzo_max      BIGINT NOT NULL,
  quota           DECIMAL(6,5) NOT NULL,        -- frazione del tetto di reddito
  spread_frazione DECIMAL(4,3) NOT NULL,        -- quanto del prezzo è margine di rotta
  ingombro        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  rischio         TINYINT UNSIGNED NOT NULL DEFAULT 10,   -- servirà in F4
  ordine          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_bene_codice (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Il prezzo di riferimento di ogni bene: uno solo per tutto il paese, che
-- cammina a passi discreti con ritorno alla media. Le differenze fra piazze
-- non stanno qui: stanno in mercati.mult_d.
CREATE TABLE IF NOT EXISTS prezzi (
  bene_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  p0      BIGINT NOT NULL,
  passo   BIGINT NOT NULL,          -- indice del passo cui p0 si riferisce
  CONSTRAINT fk_prezzo_bene FOREIGN KEY (bene_id) REFERENCES beni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mercati (
  piazza_id    SMALLINT UNSIGNED NOT NULL,
  bene_id      TINYINT UNSIGNED NOT NULL,
  quota        DECIMAL(9,8) NOT NULL,           -- frazione del tetto assegnata a questa coppia
  mult_d       DECIMAL(5,3) NOT NULL,           -- carattere strutturale: fonte o sbocco
  offerta_eq   DECIMAL(12,3) NOT NULL,          -- giacenza di equilibrio
  domanda_eq   DECIMAL(12,3) NOT NULL,          -- assorbimento di equilibrio, unità l'ora
  offerta      DECIMAL(12,3) NOT NULL,
  domanda      DECIMAL(12,3) NOT NULL,
  shock        DECIMAL(5,3) NOT NULL DEFAULT 0,
  shock_fino_a DATETIME NULL,
  agg_a        DATETIME(3) NOT NULL,
  PRIMARY KEY (piazza_id, bene_id),
  KEY idx_mercato_bene (bene_id),
  CONSTRAINT fk_mercato_piazza FOREIGN KEY (piazza_id) REFERENCES piazze(id) ON DELETE CASCADE,
  CONSTRAINT fk_mercato_bene   FOREIGN KEY (bene_id)   REFERENCES beni(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quello che porti addosso. `costo_totale` serve al conto economico: senza,
-- non si può dire se una vendita è stata un guadagno o una perdita.
CREATE TABLE IF NOT EXISTS carico (
  personaggio_id BIGINT UNSIGNED NOT NULL,
  bene_id        TINYINT UNSIGNED NOT NULL,
  quantita       INT UNSIGNED NOT NULL DEFAULT 0,
  costo_totale   BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (personaggio_id, bene_id),
  CONSTRAINT fk_carico_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_carico_bene        FOREIGN KEY (bene_id)        REFERENCES beni(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Il registro delle compravendite. È da qui che `balance:report` legge
-- l'estrazione reale: quanto i giocatori hanno davvero tirato fuori dal mondo.
-- Se quella somma supera il tetto orario, c'è un baco o un exploit.
CREATE TABLE IF NOT EXISTS transazioni (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  personaggio_id BIGINT UNSIGNED NOT NULL,
  piazza_id      SMALLINT UNSIGNED NOT NULL,
  bene_id        TINYINT UNSIGNED NOT NULL,
  verso          ENUM('acquisto','vendita') NOT NULL,
  quantita       INT UNSIGNED NOT NULL,
  prezzo_medio   BIGINT NOT NULL,
  totale         BIGINT NOT NULL,
  margine        BIGINT NULL,          -- solo sulle vendite: ricavo meno costo della merce
  fatto_at       DATETIME(3) NOT NULL,
  KEY idx_trans_personaggio (personaggio_id, id),
  KEY idx_trans_quando (fatto_at),
  KEY idx_trans_piazza (piazza_id, bene_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('mercato.elasticita_offerta', '0.60', 'float', 'Esponente e: quanto il prezzo sale se la giacenza cala'),
  ('mercato.elasticita_domanda', '0.60', 'float', 'Esponente z: quanto il prezzo cala se l''assorbimento si esaurisce'),
  ('mercato.spread',             '0.03', 'float', 'Margine denaro-lettera del venditore di piazza'),
  ('mercato.banda_bassa',        '0.20', 'float', 'Prezzo minimo, in frazione del riferimento'),
  ('mercato.banda_alta',         '5.00', 'float', 'Prezzo massimo, in frazione del riferimento'),
  ('mercato.ore_rifornimento',   '6',    'int',   'Tempo di dimezzamento del ritorno della giacenza all''equilibrio'),
  ('mercato.ore_assorbimento',   '3',    'int',   'Tempo di dimezzamento del recupero dell''assorbimento'),
  ('mercato.ore_giacenza',       '5',    'int',   'Quante ore di assorbimento una piazza tiene in giacenza'),
  ('prezzi.passo_minuti',        '5',    'int',   'Durata di un passo della passeggiata dei prezzi'),
  ('prezzi.ritorno',             '0.02', 'float', 'Quanto ogni passo tira il prezzo verso la media'),
  ('prezzi.volatilita_base',     '0.010','float', 'Scarto per passo dei beni ricchi (i poveri ballano di più)')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
