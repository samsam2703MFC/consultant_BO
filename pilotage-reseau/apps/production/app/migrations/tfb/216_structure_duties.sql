-- [368] Struktura i obowiązki (makiety 70 i 70 B, décision du 22/11/2026) :
--  - postes « Zarząd / Właściciel » (haut de la structure) et « Kierownik operacyjny » (sous Zarząd) ; Administracja
--    (administration et finances) passe sous Kierownik operacyjny ; les autres postes gardent leur supérieur ;
--  - fiche du Kierownik operacyjny : le document « Zakres wykonywanych prac i obowiązków » (21/11/2026), 34 obligations ;
--  - n_position_duty : la matrice obligation → poste → assistant de contrôle (TaskWizards::CATALOG) ou assistant proposé
--    (« do utworzenia ») et rythme ; les 34 obligations du document avec un premier rattachement, à revoir dans l'ERP.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité au premier passage.

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, sort_order, active)
SELECT 'ZARZAD', 'Zarząd / Właściciel', 'Direction / Propriétaire', 'erp', NULL, 1, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'ZARZAD');

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, id_reports_to, sort_order, active)
SELECT 'KIEROWNIK_OPERACYJNY', 'Kierownik operacyjny', 'Responsable des opérations', 'erp', NULL, z.id, 5, 1 FROM n_position z
WHERE z.code = 'ZARZAD' AND NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'KIEROWNIK_OPERACYJNY');

-- Administracja sous Kierownik operacyjny (seulement si elle n'a pas encore de supérieur : un choix fait dans l'ERP reste).
UPDATE n_position a JOIN n_position k ON k.code = 'KIEROWNIK_OPERACYJNY'
SET a.id_reports_to = k.id WHERE a.code = 'ADMINISTRACJA' AND a.id_reports_to IS NULL;

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr)
SELECT p.id,
    'Zapewnia ciągłość działalności : terminową realizację zamówień, płynność finansową, terminowe dostawy i sprawną współpracę z kontrahentami (funkcja koordynacyjno-zarządcza i finansowo-operacyjna : produkcja, finanse, logistyka, zarządzanie).',
    'Assure la continuité de l’activité : commandes livrées à temps, trésorerie, livraisons à l’heure et bonne coopération avec les clients (coordination et gestion : production, finances, logistique, pilotage).',
    'Przyjmowanie i bieżąca weryfikacja zamówień składanych przez odbiorców.
Utrzymywanie stałego kontaktu z kontrahentami w zakresie składanych zamówień oraz ich realizacji.
Monitorowanie terminowości składania zamówień i kontaktowanie się z odbiorcami w przypadku ich braku.
Przekazywanie informacji o zamówieniach do osób odpowiedzialnych za produkcję.
Nadzorowanie prawidłowego i terminowego informowania pracowników o zakresie prac do wykonania zgodnie z otrzymanymi zamówieniami.
Kontrolowanie zgodności planowanych zadań produkcyjnych z zapotrzebowaniem odbiorców.
Koordynowanie przepływu informacji pomiędzy odbiorcami a produkcją (domówienia).
Reagowanie na zmiany w zamówieniach oraz przekazywanie aktualnych ustaleń osobom odpowiedzialnym za ich realizację.
Monitorowanie sytuacji finansowej przedsiębiorstwa w zakresie niezbędnym do zapewnienia ciągłości produkcji.
Planowanie i zabezpieczanie środków finansowych potrzebnych do bieżącej działalności operacyjnej.
Nadzorowanie terminowości regulowania należności przez kontrahentów.
Kontrolowanie rozrachunków z odbiorcami oraz identyfikowanie zaległych płatności.
Prowadzenie kontaktów z kontrahentami w sprawach dotyczących płatności, niedopłat i zaległości.
Podejmowanie działań zmierzających do terminowego uzyskiwania należności.
Koordynowanie zobowiązań i wpływów finansowych z uwzględnieniem potrzeb produkcyjnych.
Przeciwdziałanie sytuacjom, w których brak środków finansowych mógłby zakłócić ciągłość produkcji.
Planowanie i organizowanie transportów zgodnie z harmonogramem realizacji zamówień.
Ustalanie terminów dostaw z uwzględnieniem możliwości produkcyjnych i logistycznych.
Utrzymywanie stałego kontaktu z kierowcą w zakresie realizowanych przewozów.
Koordynowanie terminów załadunków, wyjazdów i dostaw.
Informowanie odbiorców o planowanych terminach i godzinach dostaw.
Bieżące monitorowanie realizacji transportów.
Reagowanie na opóźnienia, zmiany harmonogramów oraz problemy organizacyjne.
Zapewnianie sprawnego przepływu informacji w trakcie transportu.
Dbanie o terminowość dostaw i zachowanie ciągłości obsługi kontrahentów.
Zarządzanie procesem obsługi zamówień od momentu ich przyjęcia do realizacji i dostawy.
Nadzorowanie prawidłowości przyjmowanych zamówień oraz zgodności ich realizacji z ustaleniami z odbiorcami.
Kontrolowanie terminowości realizacji zamówień i dostaw.
Nadzorowanie terminowości płatności oraz podejmowanie działań w przypadku powstawania zaległości.
Koordynowanie współpracy pomiędzy produkcją, logistyką i odbiorcami.
Ustalanie priorytetów operacyjnych w zależności od bieżących potrzeb przedsiębiorstwa.
Rozwiązywanie problemów związanych z realizacją zamówień, transportem i rozliczeniami.
Dbanie o prawidłowy obieg informacji i sprawną komunikację pomiędzy poszczególnymi obszarami działalności.
Podejmowanie bieżących decyzji organizacyjnych mających na celu zapewnienie sprawnego funkcjonowania przedsiębiorstwa.', NULL
FROM n_position p WHERE p.code = 'KIEROWNIK_OPERACYJNY' AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

CREATE TABLE IF NOT EXISTS n_position_duty (
    id              INT NOT NULL AUTO_INCREMENT,
    id_position     INT NOT NULL COMMENT 'n_position.id : właściciel obowiązku',
    number          VARCHAR(12) NULL COMMENT 'numer w zakresie obowiązków (np. 2.3)',
    area_no         SMALLINT NULL COMMENT 'obszar (1, 2…) ',
    area            VARCHAR(160) NULL COMMENT 'nazwa obszaru',
    text_pl         TEXT NOT NULL,
    text_fr         TEXT NULL,
    wizard_code     VARCHAR(40) NULL COMMENT 'asystent kontroli : TaskWizards::CATALOG',
    proposed_wizard VARCHAR(160) NULL COMMENT 'asystent do utworzenia (nazwa) — gdy brak istniejącego',
    rhythm          ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none',
    source          VARCHAR(200) NULL COMMENT 'dokument / karta stanowiska',
    sort_order      INT NOT NULL DEFAULT 0,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    updated_by      INT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_position_duty_position (id_position, sort_order),
    KEY idx_position_duty_wizard (wizard_code),
    CONSTRAINT fk_position_duty_position FOREIGN KEY (id_position) REFERENCES n_position (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Matryca obowiązków : stanowisko → asystent kontroli [368]';

INSERT INTO n_position_duty (id_position, number, area_no, area, text_pl, wizard_code, proposed_wizard, rhythm, sort_order, source)
SELECT p.id, d.number, d.area_no, d.area, d.text_pl, d.wizard_code, d.proposed_wizard, d.rhythm, d.sort_order, 'Zakres wykonywanych prac i obowiązków (21.11.2026)'
FROM n_position p
JOIN (
SELECT '1.1' AS number, 1 AS area_no, 'PRODUKCJA — koordynacja i nadzór nad realizacją zamówień' AS area, 'Przyjmowanie i bieżąca weryfikacja zamówień składanych przez odbiorców.' AS text_pl, NULL AS wizard_code, 'Zamówienia dnia' AS proposed_wizard, 'daily' AS rhythm, 10 AS sort_order
    UNION ALL SELECT '1.2' AS number, 1 AS area_no, 'PRODUKCJA — koordynacja i nadzór nad realizacją zamówień' AS area, 'Utrzymywanie stałego kontaktu z kontrahentami w zakresie składanych zamówień oraz ich realizacji.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 20 AS sort_order
    UNION ALL SELECT '1.3' AS number, 1 AS area_no, 'PRODUKCJA — koordynacja i nadzór nad realizacją zamówień' AS area, 'Monitorowanie terminowości składania zamówień i kontaktowanie się z odbiorcami w przypadku ich braku.' AS text_pl, NULL AS wizard_code, 'Klienci bez zamówienia' AS proposed_wizard, 'daily' AS rhythm, 30 AS sort_order
    UNION ALL SELECT '1.4' AS number, 1 AS area_no, 'PRODUKCJA — koordynacja i nadzór nad realizacją zamówień' AS area, 'Przekazywanie informacji o zamówieniach do osób odpowiedzialnych za produkcję.' AS text_pl, 'tomorrow_sent' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 40 AS sort_order
    UNION ALL SELECT '1.5' AS number, 1 AS area_no, 'PRODUKCJA — koordynacja i nadzór nad realizacją zamówień' AS area, 'Nadzorowanie prawidłowego i terminowego informowania pracowników o zakresie prac do wykonania zgodnie z otrzymanymi zamówieniami.' AS text_pl, 'tomorrow_sent' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 50 AS sort_order
    UNION ALL SELECT '1.6' AS number, 1 AS area_no, 'PRODUKCJA — koordynacja i nadzór nad realizacją zamówień' AS area, 'Kontrolowanie zgodności planowanych zadań produkcyjnych z zapotrzebowaniem odbiorców.' AS text_pl, 'week_plan_validated' AS wizard_code, NULL AS proposed_wizard, 'weekly' AS rhythm, 60 AS sort_order
    UNION ALL SELECT '1.7' AS number, 1 AS area_no, 'PRODUKCJA — koordynacja i nadzór nad realizacją zamówień' AS area, 'Koordynowanie przepływu informacji pomiędzy odbiorcami a produkcją (domówienia).' AS text_pl, NULL AS wizard_code, 'Domówienia dnia' AS proposed_wizard, 'daily' AS rhythm, 70 AS sort_order
    UNION ALL SELECT '1.8' AS number, 1 AS area_no, 'PRODUKCJA — koordynacja i nadzór nad realizacją zamówień' AS area, 'Reagowanie na zmiany w zamówieniach oraz przekazywanie aktualnych ustaleń osobom odpowiedzialnym za ich realizację.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 80 AS sort_order
    UNION ALL SELECT '2.1' AS number, 2 AS area_no, 'FINANSE — zabezpieczenie płynności i ciągłości produkcji' AS area, 'Monitorowanie sytuacji finansowej przedsiębiorstwa w zakresie niezbędnym do zapewnienia ciągłości produkcji.' AS text_pl, NULL AS wizard_code, 'Płynność i gotówka' AS proposed_wizard, 'weekly' AS rhythm, 90 AS sort_order
    UNION ALL SELECT '2.2' AS number, 2 AS area_no, 'FINANSE — zabezpieczenie płynności i ciągłości produkcji' AS area, 'Planowanie i zabezpieczanie środków finansowych potrzebnych do bieżącej działalności operacyjnej.' AS text_pl, NULL AS wizard_code, 'Płynność i gotówka' AS proposed_wizard, 'weekly' AS rhythm, 100 AS sort_order
    UNION ALL SELECT '2.3' AS number, 2 AS area_no, 'FINANSE — zabezpieczenie płynności i ciągłości produkcji' AS area, 'Nadzorowanie terminowości regulowania należności przez kontrahentów.' AS text_pl, 'payments_reviewed' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 110 AS sort_order
    UNION ALL SELECT '2.4' AS number, 2 AS area_no, 'FINANSE — zabezpieczenie płynności i ciągłości produkcji' AS area, 'Kontrolowanie rozrachunków z odbiorcami oraz identyfikowanie zaległych płatności.' AS text_pl, 'payments_reviewed' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 120 AS sort_order
    UNION ALL SELECT '2.5' AS number, 2 AS area_no, 'FINANSE — zabezpieczenie płynności i ciągłości produkcji' AS area, 'Prowadzenie kontaktów z kontrahentami w sprawach dotyczących płatności, niedopłat i zaległości.' AS text_pl, 'payments_reviewed' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 130 AS sort_order
    UNION ALL SELECT '2.6' AS number, 2 AS area_no, 'FINANSE — zabezpieczenie płynności i ciągłości produkcji' AS area, 'Podejmowanie działań zmierzających do terminowego uzyskiwania należności.' AS text_pl, 'payments_reviewed' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 140 AS sort_order
    UNION ALL SELECT '2.7' AS number, 2 AS area_no, 'FINANSE — zabezpieczenie płynności i ciągłości produkcji' AS area, 'Koordynowanie zobowiązań i wpływów finansowych z uwzględnieniem potrzeb produkcyjnych.' AS text_pl, 'month_closed' AS wizard_code, NULL AS proposed_wizard, 'monthly' AS rhythm, 150 AS sort_order
    UNION ALL SELECT '2.8' AS number, 2 AS area_no, 'FINANSE — zabezpieczenie płynności i ciągłości produkcji' AS area, 'Przeciwdziałanie sytuacjom, w których brak środków finansowych mógłby zakłócić ciągłość produkcji.' AS text_pl, NULL AS wizard_code, 'Płynność i gotówka' AS proposed_wizard, 'weekly' AS rhythm, 160 AS sort_order
    UNION ALL SELECT '3.1' AS number, 3 AS area_no, 'LOGISTYKA — planowanie i koordynacja transportów' AS area, 'Planowanie i organizowanie transportów zgodnie z harmonogramem realizacji zamówień.' AS text_pl, 'logistics_plan_approved' AS wizard_code, NULL AS proposed_wizard, 'weekly' AS rhythm, 170 AS sort_order
    UNION ALL SELECT '3.2' AS number, 3 AS area_no, 'LOGISTYKA — planowanie i koordynacja transportów' AS area, 'Ustalanie terminów dostaw z uwzględnieniem możliwości produkcyjnych i logistycznych.' AS text_pl, 'orders_transport' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 180 AS sort_order
    UNION ALL SELECT '3.3' AS number, 3 AS area_no, 'LOGISTYKA — planowanie i koordynacja transportów' AS area, 'Utrzymywanie stałego kontaktu z kierowcą w zakresie realizowanych przewozów.' AS text_pl, 'route_done' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 190 AS sort_order
    UNION ALL SELECT '3.4' AS number, 3 AS area_no, 'LOGISTYKA — planowanie i koordynacja transportów' AS area, 'Koordynowanie terminów załadunków, wyjazdów i dostaw.' AS text_pl, 'orders_transport' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 200 AS sort_order
    UNION ALL SELECT '3.5' AS number, 3 AS area_no, 'LOGISTYKA — planowanie i koordynacja transportów' AS area, 'Informowanie odbiorców o planowanych terminach i godzinach dostaw.' AS text_pl, 'day_shipped' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 210 AS sort_order
    UNION ALL SELECT '3.6' AS number, 3 AS area_no, 'LOGISTYKA — planowanie i koordynacja transportów' AS area, 'Bieżące monitorowanie realizacji transportów.' AS text_pl, 'route_done' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 220 AS sort_order
    UNION ALL SELECT '3.7' AS number, 3 AS area_no, 'LOGISTYKA — planowanie i koordynacja transportów' AS area, 'Reagowanie na opóźnienia, zmiany harmonogramów oraz problemy organizacyjne.' AS text_pl, NULL AS wizard_code, 'Opóźnienia dostaw' AS proposed_wizard, 'daily' AS rhythm, 230 AS sort_order
    UNION ALL SELECT '3.8' AS number, 3 AS area_no, 'LOGISTYKA — planowanie i koordynacja transportów' AS area, 'Zapewnianie sprawnego przepływu informacji w trakcie transportu.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 240 AS sort_order
    UNION ALL SELECT '3.9' AS number, 3 AS area_no, 'LOGISTYKA — planowanie i koordynacja transportów' AS area, 'Dbanie o terminowość dostaw i zachowanie ciągłości obsługi kontrahentów.' AS text_pl, 'day_shipped' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 250 AS sort_order
    UNION ALL SELECT '4.1' AS number, 4 AS area_no, 'ZARZĄDZANIE — nadzór i koordynacja działalności operacyjnej' AS area, 'Zarządzanie procesem obsługi zamówień od momentu ich przyjęcia do realizacji i dostawy.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 260 AS sort_order
    UNION ALL SELECT '4.2' AS number, 4 AS area_no, 'ZARZĄDZANIE — nadzór i koordynacja działalności operacyjnej' AS area, 'Nadzorowanie prawidłowości przyjmowanych zamówień oraz zgodności ich realizacji z ustaleniami z odbiorcami.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 270 AS sort_order
    UNION ALL SELECT '4.3' AS number, 4 AS area_no, 'ZARZĄDZANIE — nadzór i koordynacja działalności operacyjnej' AS area, 'Kontrolowanie terminowości realizacji zamówień i dostaw.' AS text_pl, NULL AS wizard_code, 'Terminowość tygodnia (OTIF)' AS proposed_wizard, 'weekly' AS rhythm, 280 AS sort_order
    UNION ALL SELECT '4.4' AS number, 4 AS area_no, 'ZARZĄDZANIE — nadzór i koordynacja działalności operacyjnej' AS area, 'Nadzorowanie terminowości płatności oraz podejmowanie działań w przypadku powstawania zaległości.' AS text_pl, 'payments_reviewed' AS wizard_code, NULL AS proposed_wizard, 'daily' AS rhythm, 290 AS sort_order
    UNION ALL SELECT '4.5' AS number, 4 AS area_no, 'ZARZĄDZANIE — nadzór i koordynacja działalności operacyjnej' AS area, 'Koordynowanie współpracy pomiędzy produkcją, logistyką i odbiorcami.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 300 AS sort_order
    UNION ALL SELECT '4.6' AS number, 4 AS area_no, 'ZARZĄDZANIE — nadzór i koordynacja działalności operacyjnej' AS area, 'Ustalanie priorytetów operacyjnych w zależności od bieżących potrzeb przedsiębiorstwa.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 310 AS sort_order
    UNION ALL SELECT '4.7' AS number, 4 AS area_no, 'ZARZĄDZANIE — nadzór i koordynacja działalności operacyjnej' AS area, 'Rozwiązywanie problemów związanych z realizacją zamówień, transportem i rozliczeniami.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 320 AS sort_order
    UNION ALL SELECT '4.8' AS number, 4 AS area_no, 'ZARZĄDZANIE — nadzór i koordynacja działalności operacyjnej' AS area, 'Dbanie o prawidłowy obieg informacji i sprawną komunikację pomiędzy poszczególnymi obszarami działalności.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 330 AS sort_order
    UNION ALL SELECT '4.9' AS number, 4 AS area_no, 'ZARZĄDZANIE — nadzór i koordynacja działalności operacyjnej' AS area, 'Podejmowanie bieżących decyzji organizacyjnych mających na celu zapewnienie sprawnego funkcjonowania przedsiębiorstwa.' AS text_pl, NULL AS wizard_code, NULL AS proposed_wizard, 'none' AS rhythm, 340 AS sort_order
) d ON 1 = 1
WHERE p.code = 'KIEROWNIK_OPERACYJNY' AND NOT EXISTS (SELECT 1 FROM n_position_duty x WHERE x.id_position = p.id);
