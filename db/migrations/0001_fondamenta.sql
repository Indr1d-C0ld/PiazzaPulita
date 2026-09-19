-- 0001_fondamenta : account, verifica e-mail, freni, registro, parametri, coda posta
-- (schema_migrations e' gestita dal Migratore, non qui)

CREATE TABLE IF NOT EXISTS users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username          VARCHAR(32)  NOT NULL,
  email             VARCHAR(190) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  status            ENUM('pending','active','suspended','banned') NOT NULL DEFAULT 'pending',
  role              ENUM('player','moderator','admin') NOT NULL DEFAULT 'player',
  nota              VARCHAR(500) NULL,
  luce              ENUM('auto','carta','notte') NOT NULL DEFAULT 'auto',
  email_verified_at DATETIME NULL,
  verify_sent_at    DATETIME NULL,
  verify_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  admin_notified_at DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at     DATETIME NULL,
  last_login_ip     VARBINARY(16) NULL,
  last_seen_at      DATETIME NULL,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gettoni monouso: verifica e-mail e reimpostazione password.
-- In tabella finisce solo l'hash del gettone: chi legge il DB non puo' usarlo.
CREATE TABLE IF NOT EXISTS user_tokens (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  kind       ENUM('verify_email','reset_password') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_ip VARBINARY(16) NULL,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_token_user (user_id, kind),
  KEY idx_token_expires (expires_at),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rkey     VARCHAR(190) NOT NULL PRIMARY KEY,
  hits     INT UNSIGNED NOT NULL DEFAULT 0,
  reset_at DATETIME NOT NULL,
  KEY idx_rate_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action        VARCHAR(64) NOT NULL,
  target_type   VARCHAR(32) NULL,
  target_id     BIGINT UNSIGNED NULL,
  meta          JSON NULL,
  ip            VARBINARY(16) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_actor (actor_user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parametri di gioco modificabili a caldo, senza deploy.
CREATE TABLE IF NOT EXISTS game_config (
  ckey       VARCHAR(64) NOT NULL PRIMARY KEY,
  cvalue     TEXT NOT NULL,
  ctype      ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
  note       VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Diario dei battiti: serve a capire se il mondo sta girando.
CREATE TABLE IF NOT EXISTS tick_runs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  started_at  DATETIME(3) NOT NULL,
  finished_at DATETIME(3) NULL,
  ok          TINYINT(1) NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  tasks       JSON NULL,
  note        VARCHAR(255) NULL,
  KEY idx_tick_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coda della posta in uscita: nessun messaggio si perde perche' l'SMTP
-- non rispondeva in quel momento. Senza conferma dell'indirizzo non si entra,
-- quindi questa e' letteralmente la porta d'ingresso al gioco.
CREATE TABLE IF NOT EXISTS mail_queue (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  destinatario  VARCHAR(190) NOT NULL,
  oggetto       VARCHAR(255) NOT NULL,
  corpo         MEDIUMTEXT NOT NULL,
  genere        VARCHAR(32) NOT NULL DEFAULT 'generico',
  priorita      TINYINT NOT NULL DEFAULT 5,       -- 1 = prima di tutto (verifica), 9 = ultima
  tentativi     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  prossimo_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  inviato_at    DATETIME NULL,
  rinunciato_at DATETIME NULL,
  ultimo_errore VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_coda_da_fare (inviato_at, rinunciato_at, prossimo_at, priorita),
  KEY idx_coda_inviati (inviato_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('auth.min_password_length', '9',   'int',  'Lunghezza minima della password'),
  ('auth.registration_open',   '1',   'bool', 'Iscrizioni aperte'),
  ('auth.verify_ttl_hours',    '48',  'int',  'Validita'' del collegamento di verifica, in ore'),
  ('limits.actions_per_min',   '120', 'int',  'Azioni al minuto consentite a un giocatore'),
  ('mail.max_tentativi',       '6',   'int',  'Quante volte si riprova un messaggio prima di rinunciare'),
  ('mail.tetto_24h',           '280', 'int',  'Tetto di invii nelle ultime 24 ore (Brevo gratuito ne concede 300)'),
  ('mail.per_battito',         '5',   'int',  'Quanti messaggi in coda si tentano a ogni battito'),
  ('mondo.valuta',             'lira','string','Valuta del mondo di gioco')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype);
