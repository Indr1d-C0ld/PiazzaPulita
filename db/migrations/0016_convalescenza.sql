-- 0016_convalescenza : le ferite di una rissa guariscono col tempo (audit del 23/09/2026)
--
-- La salute scendeva negli scontri e tornava a 100 solo uscendo dall'ospedale.
-- Chi le prendeva senza finire a terra — una fuga, una rissa senza vincitori —
-- restava ferito per sempre: più debole in ogni scontro successivo, e senza
-- saperlo, perché la salute non la mostrava nessuna pagina. Adesso guarisce a
-- un ritmo fisso, contato dall'ultima ferita come tutto il resto del gioco:
-- non serve il battito per guarire, basta che passi il tempo.

ALTER TABLE personaggi
  ADD COLUMN IF NOT EXISTS salute_agg_a DATETIME(3) NULL COMMENT 'Da quando corre la guarigione della salute';

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('pvp.guarigione_ora', '10', 'int', 'Punti di salute che tornano ogni ora, fuori dall''ospedale')
ON DUPLICATE KEY UPDATE ckey = ckey;
