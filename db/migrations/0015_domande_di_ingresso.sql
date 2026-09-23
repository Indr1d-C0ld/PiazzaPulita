-- 0015_domande_di_ingresso : in una batteria si entra se il capo dice sì (audit del 23/09/2026)
--
-- Fino a qui chiunque entrava in qualunque batteria con un clic, e nessuno
-- poteva mandarlo via. Sembrava accoglienza, era una falla con due facce:
--   - il pizzo si evitava entrando nella batteria che comanda la piazza, e il
--     territorio — il motivo per cui si tiene una piazza — non rendeva niente;
--   - chi entrava diventava intoccabile per tutti i membri («È uno dei tuoi»).
-- Adesso si fa domanda, e decide il capo. Una domanda per persona alla volta:
-- chi ne fa un'altra ritira la prima.

CREATE TABLE IF NOT EXISTS batteria_domande (
  personaggio_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  batteria_id    BIGINT UNSIGNED NOT NULL,
  fatta_at       DATETIME(3) NOT NULL,
  KEY idx_domande_batteria (batteria_id),
  CONSTRAINT fk_domanda_personaggio FOREIGN KEY (personaggio_id) REFERENCES personaggi(id) ON DELETE CASCADE,
  CONSTRAINT fk_domanda_batteria FOREIGN KEY (batteria_id) REFERENCES batterie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
