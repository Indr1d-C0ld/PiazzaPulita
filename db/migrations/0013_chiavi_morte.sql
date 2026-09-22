-- 0013_chiavi_morte : via tre parametri che non legge nessuno (audit del 22/09/2026)
--
-- Una chiave di configurazione che il codice non legge è peggio di una chiave
-- che non esiste: chi la trova la cambia, non succede niente, e ci mette mezza
-- giornata a capire perché. Un audit le ha trovate confrontando le chiavi
-- presenti in tabella con quelle lette davvero dal codice.
--
--   mondo.espansione_max     Il moltiplicatore che allarga la torta del mondo
--                            (docs/DESIGN.md §2.6, «R_eff = R_base × (1+espansione)»).
--                            Il progetto stesso la dichiarava «facoltativa in F2;
--                            se costa, si rimanda» — ed è stata rimandata. La
--                            meccanica NON esiste: resta scritta nel progetto
--                            come cosa da fare, non come manopola da girare.
--
--   mercato.margine_viaggio  Il 30% per viaggio del 1984. È l'ÀNCORA da cui sono
--                            stati DERIVATI gli spread, non un valore che si
--                            applica: gli spread veri stanno in `beni.spread_frazione`,
--                            uno per merce. Cambiare questa non avrebbe spostato niente.
--
--   mondo.valuta             'lira'. Il formato dei numeri è in `lire()`, che scrive
--                            «L.» perché il gioco sta in Italia negli anni ottanta
--                            e non è previsto che stia altrove.

DELETE FROM game_config
 WHERE ckey IN ('mondo.espansione_max', 'mercato.margine_viaggio', 'mondo.valuta');
