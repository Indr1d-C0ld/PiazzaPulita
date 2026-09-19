-- 0002_tetto_reddito : le chiavi di bilanciamento del mercato
--
-- Entrano prima del mercato stesso (F2) perche' il tetto e' una DECISIONE, non un
-- dettaglio d'implementazione: sta scritta in docs/DESIGN.md §2.6, va versionata
-- con lo schema, e dev'essere visibile nel pannello fin da subito.
--
-- mondo.reddito_orario e' l'unica manopola dell'economia: giacenze e assorbimenti
-- di ogni piazza si DERIVANO da qui (A* = quota x R / spread_unitario). Non si
-- scrivono a mano, mai: e' l'unico modo perche' l'invariante non marcisca.

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('mondo.reddito_orario',    '24000000', 'int',   'Tetto: lire che il mondo intero produce in un''ora'),
  ('mondo.espansione_max',    '0.30',     'float', 'Quanto la comunita'' puo'' allargare la torta, al massimo'),
  ('mercato.margine_viaggio', '0.30',     'float', 'Margine di riferimento per viaggio (il ritmo del 1984)'),
  ('mercato.banda_min',       '3',        'int',   'Assorbimento minimo per piazza, unita'' l''ora'),
  ('mercato.banda_max',       '30',       'int',   'Assorbimento massimo per piazza, unita'' l''ora')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
