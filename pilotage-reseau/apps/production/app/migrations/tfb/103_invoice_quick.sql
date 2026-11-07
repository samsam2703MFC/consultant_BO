-- Factures d'achat en un clic : « Zatwierdź fakturę » (prix + stock + acceptation), traitement automatique à l'import
-- d'une facture entièrement reconnue (fournisseur connu, toutes les lignes depuis les correspondances mémorisées,
-- sans écart avec la commande, sans hausse de prix anormale, totaux cohérents).
-- supply.invoice_auto_prices (1) : prix de la facture → fiche matière automatiquement ;
-- supply.invoice_auto_full (0) : aussi quantités en stock et acceptation ;
-- supply.invoice_price_alert_pct (15) : écart prix / kg vs fiche matière au-delà duquel la ligne est signalée (pas d'automatique).
-- L'historique des prix utilise l'index existant idx_rmpc_material_time. Rejouable (MySQL 8 / MariaDB).

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'supply.invoice_auto_prices', '1', '1', 'bool', NULL,
    'Faktura zakupowa w pełni rozpoznana (dostawca, zapamiętane przypisania, bez rozbieżności) : ceny do kartoteki surowców automatycznie przy imporcie',
    'Facture d''achat entièrement reconnue (fournisseur, correspondances mémorisées, sans écart) : prix vers les fiches matières automatiquement à l''import'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'supply.invoice_auto_prices');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'supply.invoice_auto_full', '0', '0', 'bool', NULL,
    'Faktura w pełni rozpoznana : także przyjęcie ilości na stan i akceptacja automatycznie (bez kliknięcia « Zatwierdź fakturę »)',
    'Facture entièrement reconnue : aussi entrée des quantités en stock et acceptation automatiques (sans « Valider la facture »)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'supply.invoice_auto_full');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'supply.invoice_price_alert_pct', '15', '15', 'decimal', '%',
    'Faktura : różnica ceny za kg wobec kartoteki, od której linia jest oznaczona « skok ceny » (bez automatu, potwierdzenie)',
    'Facture : écart de prix au kg vs fiche matière à partir duquel la ligne est signalée « hausse de prix » (pas d''automatique, confirmation)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'supply.invoice_price_alert_pct');
