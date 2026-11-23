-- [402] Asystent « Dokumenty wysyłek miesiąca » (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji 4.1
-- « Prowadzenie i przechowywanie dokumentacji dotyczącej wywozu produktów », 4.2 « Systematyczne kompletowanie dokumentów
-- na potrzeby kontroli i audytów », 4.4 « Kontrola poprawności i kompletności dokumentów związanych z transportem »,
-- proposed_wizard = 'Dokumenty wysyłek miesiąca') : chaque mois, les wysyłki du mois écoulé (shipment_log.shipment_date)
-- dont les documents ne sont pas complets, d'après ce que l'ERP crée et imprime pour une wysyłka (ShippingService::
-- insertNewShipping, ShippingDocumentService::buildPaper) — rien de nouveau n'est inventé :
--   - WZ (document_wz, lignes document_wz_product), CMR (document_cmr : przewoźnik et numer rejestracyjny ; colis
--     document_cmr_packages), WDT (document_wdt, lignes document_wdt_product) absente, introuvable ou vide ; quantités par
--     produit WZ ≠ WDT (sauf WDT remplacée, n_wdt_superseded) ;
--   - document de różnice avec écart (document_order_shipping_difference_products) dont l'affaire n'est pas close dans
--     « Różnice i problemy dostaw » (n_shipping_issue), problème de livraison non clos ;
--   - potwierdzenie odbioru : les jours où la tablette chauffeur a servi (n_delivery_run), arrêt du client absent ou non
--     terminé (n_delivery_stop), ou livré sans podpis odbiorcy (n_delivery_media).
-- Le dowód opakowań (BDO / PPWR : shipment_packaging_record) n'est PAS un signal ici : il est déjà contrôlé par l'assistant
-- « PPWR i opakowania » (238) — compté et lié seulement. KSeF des WDT : assistant « Faktury i KSeF dnia ».
-- Une décision par wysyłka signalée : « Uzupełnione — sprawdzone » (acceptée seulement sans brak au moment de
-- l'enregistrement), « Dokument papierowy kompletny » (note obligatoire : où il est classé), « Nie dotyczy » (note
-- obligatoire) ; « Zakończ » écrit n_wizard_done (code shipping_docs_reviewed) et la tâche du mois se valide seule.
-- L'assistant NE MODIFIE AUCUN document et n'envoie rien (ni e-mail ni KSeF).
--  - n_shipping_docs_review : une décision par (mois examiné, wysyłka) ;
--  - matrice des obligations (237) : les lignes KIEROWNIK_PRODUKCJI « Dokumenty wysyłek miesiąca » → shipping_docs_reviewed ;
--  - tâche du poste KIEROWNIK_PRODUKCJI (co miesiąc, 5e jour ouvré) ;
--  - droits du KIEROWNIK_PRODUKCJI seulement : shipping_docs_month (l'assistant) et — pour que la liste des wysyłki et de
--    leurs documents s'ouvre (« Wysyłki i dokumenty », « Różnice i problemy dostaw », /shipments) — shipping. Les
--    Potwierdzenia dostaw (logistics) ne sont pas donnés : le lien n'apparaît qu'à qui a déjà ce droit.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_shipping_docs_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień ocenianego miesiąca (wysyłki)',
    item_key     VARCHAR(80) NOT NULL COMMENT 'element : shipment:<id>',
    source_id    INT NOT NULL COMMENT 'shipment_log.id (bez klucza obcego)',
    decision     ENUM('checked','paper_ok','not_applicable') NOT NULL COMMENT 'checked = uzupełnione — sprawdzone ; paper_ok = dokument papierowy kompletny ; not_applicable = nie dotyczy',
    note         VARCHAR(500) NULL COMMENT 'wymagana dla paper_ok i not_applicable',
    flags        VARCHAR(255) NULL COMMENT 'kody braków w chwili zapisu (przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shipping_docs_review (month_start, item_key),
    KEY idx_shipping_docs_review_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Dokumenty wysyłek miesiąca : decyzja dla wysyłki [402]';

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 4.1, 4.2 et 4.4 (monthly).
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.wizard_code = 'shipping_docs_reviewed', d.proposed_wizard = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.proposed_wizard = 'Dokumenty wysyłek miesiąca';

-- Tâche du poste (co miesiąc, 5e jour ouvré) : ne double pas une tâche existante (même code ou même lien actif).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'LOGISTYKA' LIMIT 1),
    'Dokumenty wysyłek miesiąca : WZ, CMR, WDT, różnice i potwierdzenia odbioru poprzedniego miesiąca → decyzja (asystent)',
    'Documents des expéditions du mois : WZ, CMR, WDT, écarts et confirmations de réception du mois écoulé → décision (assistant)',
    'monthly', 30, 'wd5', '/shipments/documents-month', 'shipping_docs_reviewed',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'shipping_docs_reviewed')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/shipments/documents-month');

-- Droit de l'assistant : Kierownik produkcji seulement (l'administrateur voit tout).
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'shipping_docs_month' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';

-- Écrans des documents (Wysyłki i dokumenty, Różnice i problemy dostaw) : même poste seulement.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'shipping' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';
