-- [295] Interrupteur des envois automatiques (Ustawienia › Parametry) : e-maile automatiques (règles Komunikacja),
--   rappels de paiement, rapport mensuel, statuts KSeF, avis aux boutiques, domówienia, publications de prix datées,
--   récupération des commandes et réclamations des boutiques (poller), e-mails HR du soir. 0 = rien ne part tout seul ;
--   les envois faits à la main depuis l'ERP partent toujours. Mode cohabitation : mis à 0 à l'installation
--   (deploy/server.sh) — l'ERP d'origine reste le seul à envoyer.
INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('system.auto_send', '1', '1', 'bool', NULL,
 'Wysyłki automatyczne : e-maile automatyczne, przypomnienia o płatności, raport miesięczny, KSeF, powiadomienia sklepów, publikacja cen, pobieranie zamówień ze sklepów (0 = nic nie wychodzi samo ; wysyłki ręczne działają)',
 'Envois automatiques : e-mails automatiques, rappels de paiement, rapport mensuel, KSeF, avis aux boutiques, publication des prix, récupération des commandes des boutiques (0 = rien ne part tout seul ; les envois manuels partent)');
