-- 0010_rifinitura : obiettivi, quattro classifiche, albo d'oro (F7)
--
-- In un mondo eterno una classifica sola premia solo chi e' arrivato primo:
-- chi comincia oggi sa gia' che non raggiungera' mai chi gioca da tre mesi, e
-- allora la graduatoria smette di essere un motivo per giocare. Per questo ce
-- ne sono QUATTRO, e quella che conta davvero (il reddito degli ultimi trenta
-- giorni) e' contendibile da chiunque, sempre (docs/DESIGN.md §7.1).
--
-- L'albo d'oro serve alla stessa cosa dal lato opposto: un primato perso
-- sparisce dalla classifica ma resta scritto, cosi' il tempo passato in cima
-- non si cancella quando qualcun altro ti supera.

ALTER TABLE personaggi
  -- Longevita': da quando non ti prendono ne' ti ricoverano. Si riazzera con
  -- l'arresto e con l'ospedale, e non serve nessuna query storica per leggerla.
  ADD COLUMN IF NOT EXISTS pulito_dal DATETIME NULL;

UPDATE personaggi SET pulito_dal = creato_at WHERE pulito_dal IS NULL;

-- Gli obiettivi sbloccati. Il CATALOGO sta nel codice (src/Game/Obiettivi.php)
-- e non nel database: e' fatto di condizioni, e una condizione in una tabella
-- diventa presto una lingua di programmazione scritta male.
CREATE TABLE IF NOT EXISTS obiettivi (
  personaggio_id BIGINT UNSIGNED NOT NULL,
  codice         VARCHAR(32) NOT NULL,
  sbloccato_at   DATETIME(3) NOT NULL,
  PRIMARY KEY (personaggio_id, codice),
  KEY idx_ob_quando (sbloccato_at),
  CONSTRAINT fk_ob_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chi tiene adesso ciascuna graduatoria, e da quando.
CREATE TABLE IF NOT EXISTS primati (
  graduatoria    VARCHAR(16) NOT NULL PRIMARY KEY,
  personaggio_id BIGINT UNSIGNED NULL,
  valore         BIGINT NOT NULL DEFAULT 0,
  dal            DATETIME NOT NULL,
  agg_a          DATETIME(3) NOT NULL,
  CONSTRAINT fk_primato_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- L'albo d'oro: i regni CHIUSI che sono durati abbastanza.
--
-- Il nome ci sta scritto dentro, copiato: l'albo deve sopravvivere alla
-- cancellazione dell'account, come il registro delle azioni. Un albo d'oro che
-- si svuota quando qualcuno se ne va non e' un albo d'oro.
CREATE TABLE IF NOT EXISTS albo (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  graduatoria    VARCHAR(16) NOT NULL,
  personaggio_id BIGINT UNSIGNED NULL,
  nome           VARCHAR(64) NOT NULL,
  valore         BIGINT NOT NULL DEFAULT 0,
  dal            DATETIME NOT NULL,
  al             DATETIME NOT NULL,
  giorni         SMALLINT UNSIGNED NOT NULL,
  KEY idx_albo_graduatoria (graduatoria, giorni),
  CONSTRAINT fk_albo_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('albo.giorni_minimi',   '30',  'int', 'Giorni di primato sotto i quali il regno non entra nell''albo'),
  ('classifica.righe',     '50',  'int', 'Quante posizioni mostra ogni graduatoria'),
  ('classifica.reddito_giorni', '30', 'int', 'Finestra della classifica di reddito, in giorni'),
  ('obiettivi.in_cronaca', '1',   'bool','Se gli obiettivi rari finiscono sul giornale')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
