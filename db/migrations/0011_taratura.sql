-- 0011_taratura : il tetto di reddito sceso a 16 milioni (taratura del 19/09/2026)
--
-- PERCHE'. Corretto il baco della prima mano del generatore (src/Sim/Rng.php),
-- il mercato ha cominciato a respirare come progettato e il principiante e'
-- risultato molto sopra il bersaglio del §2.6: mediana 841.000 lire nella prima
-- ora contro le 250.000 di progetto. Il numero vecchio (160-300 k) era falsato
-- da due bachi: i carichi che arrivavano quattro volte troppo spesso, e lo
-- strumento di misura che si avvelenava il mercato da solo.
--
-- PERCHE' ANCHE LA BANDA. Abbassare R da solo NON funziona, ed e' stato
-- misurato: la potatura di `mercato:semina` toglie i nodi che scendono sotto
-- l'assorbimento minimo e ne ridistribuisce la quota sui superstiti, quindi il
-- mercato diventa piu' magro ma i nodi rimasti restano grassi e il giocatore
-- guadagna come prima — a R = 6.000.000 con la banda a 3 restavano 24 nodi su
-- 273 e il principiante guadagnava di PIU'. A R = 16.000.000 con la banda
-- ferma a 3 la potatura lascia addirittura cinque piazze senza nemmeno un bene
-- e il tetto reale scende a 12,16 milioni invece dei 16 chiesti.
-- La banda scende con R: 2 unita' l'ora invece di 3.
--
-- COSA SI OTTIENE (mediana della prima ora su tutte e nove le citta'):
--   R = 24 M, banda 3 : 273 nodi, mediana 841 k, minimo 295 k
--   R = 16 M, banda 2 : 273 nodi, mediana 584 k, minimo 214 k   <- questa
--   R = 10 M, banda 1 : 327 nodi, mediana 411 k, minimo -1 k, tre citta' morte
--
-- QUELLO CHE RESTA APERTO: la mediana non arriva a 250 k e non ci puo' arrivare
-- con R, perche' il reddito del principiante dipende dalla CITTA' molto piu'
-- che dal tetto (Roma 1,55 M contro Catania 214 k nella stessa taratura). Un
-- taglio abbastanza profondo da portare Roma sul bersaglio ammazza Bari,
-- Palermo e Bologna. La leva giusta per quello e' la composizione delle piazze,
-- non il tetto — e' scritto nel §2.6.

INSERT INTO game_config (ckey, cvalue, ctype, note) VALUES
  ('mondo.reddito_orario', '16000000', 'int', 'Tetto: lire che il mondo intero produce in un''ora'),
  ('mercato.banda_min',    '2',        'int', 'Assorbimento minimo per piazza, unita'' l''ora')
ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), ctype = VALUES(ctype), note = VALUES(note);
