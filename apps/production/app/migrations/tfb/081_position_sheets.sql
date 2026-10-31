-- Fiches de poste [141] : chaque poste (bureau et atelier) a sa fiche — mission, responsabilités, compétences,
-- exigences, conditions, indicateurs, hygiène et sécurité, relations — en polonais et en français (une ligne = un point).
-- Les postes de l'atelier (kind = atelier) sont ajoutés ; un salarié de production peut être rattaché à un poste.
-- Conditionnel et rejouable (MySQL 8 / MariaDB) ; les fiches existantes ne sont jamais écrasées.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position' AND COLUMN_NAME = 'kind') = 0,
    'ALTER TABLE n_position ADD COLUMN kind ENUM(''erp'',''atelier'') NOT NULL DEFAULT ''erp'' AFTER description, ADD COLUMN id_group INT NULL AFTER kind, ADD COLUMN id_reports_to INT NULL AFTER id_group',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position' AND CONSTRAINT_NAME = 'fk_n_position_reports_to') = 0,
    'ALTER TABLE n_position ADD CONSTRAINT fk_n_position_reports_to FOREIGN KEY (id_reports_to) REFERENCES n_position (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position' AND CONSTRAINT_NAME = 'fk_n_position_group') = 0,
    'ALTER TABLE n_position ADD CONSTRAINT fk_n_position_group FOREIGN KEY (id_group) REFERENCES n_prod_group (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_position_sheet (
    id_position         INT NOT NULL,
    mission_pl          TEXT NULL,
    mission_fr          TEXT NULL,
    responsibilities_pl TEXT NULL,
    responsibilities_fr TEXT NULL,
    skills_pl           TEXT NULL,
    skills_fr           TEXT NULL,
    requirements_pl     TEXT NULL,
    requirements_fr     TEXT NULL,
    conditions_pl       TEXT NULL,
    conditions_fr       TEXT NULL,
    indicators_pl       TEXT NULL,
    indicators_fr       TEXT NULL,
    safety_pl           TEXT NULL,
    safety_fr           TEXT NULL,
    relations_pl        TEXT NULL,
    relations_fr        TEXT NULL,
    version             INT NOT NULL DEFAULT 1,
    validated_at        DATETIME NULL,
    validated_by        INT NULL,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by          INT NULL,
    PRIMARY KEY (id_position),
    CONSTRAINT fk_n_position_sheet_position FOREIGN KEY (id_position) REFERENCES n_position (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_employee' AND COLUMN_NAME = 'id_position') = 0,
    'ALTER TABLE n_prod_employee ADD COLUMN id_position INT NULL AFTER id_primary_group, ADD CONSTRAINT fk_n_prod_employee_position FOREIGN KEY (id_position) REFERENCES n_position (id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Postes de l'atelier

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, sort_order, active)
SELECT 'BRYGADZISTA', 'Brygadzista zmiany', 'Chef d’équipe', 'atelier', NULL, 60, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'BRYGADZISTA');

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, sort_order, active)
SELECT 'PIEKARZ', 'Piekarz', 'Boulanger', 'atelier', (SELECT g.id FROM n_prod_group g WHERE g.code = 'PIEKARNIA'), 70, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'PIEKARZ');

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, sort_order, active)
SELECT 'ROGAL', 'Piekarz wyrobów laminowanych', 'Tourier (viennoiserie)', 'atelier', (SELECT g.id FROM n_prod_group g WHERE g.code = 'ROGAL'), 80, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'ROGAL');

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, sort_order, active)
SELECT 'CUKIERNIK', 'Cukiernik', 'Pâtissier', 'atelier', (SELECT g.id FROM n_prod_group g WHERE g.code = 'CUKIERNIA'), 90, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'CUKIERNIK');

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, sort_order, active)
SELECT 'GARMAZER', 'Kucharz garmażerii', 'Cuisinier traiteur', 'atelier', (SELECT g.id FROM n_prod_group g WHERE g.code = 'GARMAZERIA'), 100, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'GARMAZER');

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, sort_order, active)
SELECT 'PAKOWACZ', 'Pracownik pakowania i wysyłki', 'Préparateur conditionnement et expédition', 'atelier', (SELECT g.id FROM n_prod_group g WHERE g.code = 'PAKOWANIE'), 110, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'PAKOWACZ');

INSERT INTO n_position (code, name_pl, name_fr, kind, id_group, sort_order, active)
SELECT 'KIEROWCA', 'Kierowca-dostawca', 'Chauffeur-livreur', 'atelier', NULL, 120, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_position WHERE code = 'KIEROWCA');

-- Rattachement hiérarchique (seulement s'il n'est pas déjà renseigné)

UPDATE n_position p JOIN n_position b ON b.code = 'KIEROWNIK_PRODUKCJI' SET p.id_reports_to = b.id WHERE p.code = 'BRYGADZISTA' AND p.id_reports_to IS NULL;

UPDATE n_position p JOIN n_position b ON b.code = 'BRYGADZISTA' SET p.id_reports_to = b.id WHERE p.code = 'PIEKARZ' AND p.id_reports_to IS NULL;

UPDATE n_position p JOIN n_position b ON b.code = 'BRYGADZISTA' SET p.id_reports_to = b.id WHERE p.code = 'ROGAL' AND p.id_reports_to IS NULL;

UPDATE n_position p JOIN n_position b ON b.code = 'BRYGADZISTA' SET p.id_reports_to = b.id WHERE p.code = 'CUKIERNIK' AND p.id_reports_to IS NULL;

UPDATE n_position p JOIN n_position b ON b.code = 'BRYGADZISTA' SET p.id_reports_to = b.id WHERE p.code = 'GARMAZER' AND p.id_reports_to IS NULL;

UPDATE n_position p JOIN n_position b ON b.code = 'LOGISTYKA' SET p.id_reports_to = b.id WHERE p.code = 'PAKOWACZ' AND p.id_reports_to IS NULL;

UPDATE n_position p JOIN n_position b ON b.code = 'LOGISTYKA' SET p.id_reports_to = b.id WHERE p.code = 'KIEROWCA' AND p.id_reports_to IS NULL;

-- Fiches pré-remplies, à relire et valider dans l'ERP

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Zapewnia surowce i opakowania w wymaganej jakości, na czas i po najlepszej cenie, tak aby produkcja nigdy nie stanęła z braku materiału.', 'Garantit des matières premières et des emballages de la qualité requise, à temps et au meilleur prix, pour que la production ne s’arrête jamais faute de matière.', 'Zamówienia surowców i opakowań na podstawie zapotrzebowania z planu produkcji (Zaopatrzenie)
Kontrola faktur zakupowych z zamówieniami i przyjęciami (ilości, ceny)
Aktualizacja cen surowców i analiza wzrostów cen
Zapytania ofertowe i negocjacje z dostawcami
Stany minimalne i maksymalne surowców, brak przestojów
Niezgodności dostaw i reklamacje do dostawców', 'Commandes de matières et d’emballages à partir des besoins du plan de production (Zaopatrzenie)
Contrôle des factures d’achat avec les commandes et les réceptions (quantités, prix)
Mise à jour des prix des matières et analyse des hausses
Appels d’offres et négociation avec les fournisseurs
Stocks minimum et maximum des matières, aucune rupture
Non-conformités de livraison et réclamations aux fournisseurs', 'Znajomość rynku surowców piekarniczych i cukierniczych
Negocjacje i analiza cen
ERP, Excel
Polski ; francuski lub angielski mile widziany', 'Connaissance du marché des matières de boulangerie-pâtisserie
Négociation et analyse des prix
ERP, Excel
Polonais ; français ou anglais apprécié', 'Wykształcenie średnie lub wyższe (handel, logistyka)
2 lata doświadczenia w zakupach, najlepiej w branży spożywczej', 'Formation commerce ou logistique
2 ans d’expérience en achats, de préférence en agroalimentaire', 'Biuro w pracowni centralnej (Wrocław), pon.–pt.
Stały kontakt z produkcją i magazynem', 'Bureau à l’atelier central (Wrocław), lun.–ven.
Contact permanent avec la production et le magasin', 'Zero przestojów produkcji z braku surowca
Koszt surowców w % sprzedaży (cel w Rentowność)
Faktury sprawdzone w terminie
Dostawy na czas i zgodne', 'Zéro arrêt de production faute de matière
Coût matières en % des ventes (objectif dans Rentowność)
Factures contrôlées dans les délais
Livraisons à l’heure et conformes', 'Certyfikaty, specyfikacje i alergeny dostawców sprawdzone przed pierwszym zakupem', 'Certificats, fiches techniques et allergènes des fournisseurs vérifiés avant le premier achat', 'Kierownik produkcji, jakość, księgowość, dostawcy', 'Responsable production, qualité, comptabilité, fournisseurs'
FROM n_position p WHERE p.code = 'ZAKUPY'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Pilnuje bezpieczeństwa żywności i zgodności produktów (HACCP, alergeny, etykiety), prowadzi niezgodności i reklamacje aż do ich zamknięcia.', 'Veille à la sécurité des aliments et à la conformité des produits (HACCP, allergènes, étiquettes), pilote les non-conformités et les réclamations jusqu’à leur clôture.', 'Plan HACCP, jego aktualizacja i kontrole na hali
Rejestr niezgodności : przyczyny i działania korygujące
Reklamacje klientów : ocena, odpowiedź, działania
Karty techniczne, alergeny i etykiety produktów
Identyfikowalność partii (surowce → produkty)
Szkolenia z higieny dla zespołów', 'Plan HACCP, sa mise à jour et les contrôles en atelier
Registre des non-conformités : causes et actions correctives
Réclamations clients : analyse, réponse, actions
Fiches techniques, allergènes et étiquettes des produits
Traçabilité des lots (matières → produits)
Formations hygiène des équipes', 'HACCP ; BRC / IFS mile widziane
Analiza przyczyn (5 dlaczego, Ishikawa)
Rzetelność i stanowczość', 'HACCP ; BRC / IFS apprécié
Analyse des causes (5 pourquoi, Ishikawa)
Rigueur et fermeté', 'Wykształcenie : technologia żywności
2 lata w dziale jakości zakładu spożywczego', 'Formation en technologie alimentaire
2 ans en qualité dans une entreprise agroalimentaire', 'Biuro i hala produkcyjna, pon.–pt. ; kontrole także na zmianach', 'Bureau et atelier, lun.–ven. ; contrôles aussi pendant les équipes', 'Liczba i koszt reklamacji (raport miesięczny)
Niezgodności zamknięte w terminie
Wyniki audytów
Zero incydentów bezpieczeństwa żywności', 'Nombre et coût des réclamations (rapport mensuel)
Non-conformités clôturées dans les délais
Résultats des audits
Zéro incident de sécurité des aliments', 'Wzorowe przestrzeganie higieny
Prawo wstrzymania partii', 'Respect exemplaire de l’hygiène
Droit de bloquer un lot', 'Produkcja, zakupy, klienci i franczyzobiorcy, Sanepid', 'Production, achats, clients et franchisés, services sanitaires'
FROM n_position p WHERE p.code = 'JAKOSC'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Prowadzi produkcję pracowni : plan tygodnia, zespoły, koszty i jakość, a także rozwój nowych produktów. Pełna karta z celami i premią : Administracja › Kierownik produkcji.', 'Dirige la production de l’atelier : plan de la semaine, équipes, coûts et qualité, ainsi que le développement des nouveaux produits. Fiche complète avec objectifs et prime : Administracja › Kierownik produkcji.', 'Plan produkcji tygodnia i plan zespołów
Organizacja zmian i obsady
Koszty : surowce, personel, straty
Jakość i HACCP razem z działem jakości
Nowe produkty i karty produktów
Zebrania produkcyjne i raport miesięczny', 'Plan de production de la semaine et plan des équipes
Organisation des équipes et des effectifs
Coûts : matières, personnel, pertes
Qualité et HACCP avec la qualité
Nouveaux produits et fiches produits
Réunions de production et rapport mensuel', 'Zarządzanie zespołem
Technologia piekarnicza i cukiernicza
Planowanie i analiza kosztów', 'Management d’équipe
Technologie boulangère et pâtissière
Planification et analyse des coûts', '5 lat w produkcji spożywczej, w tym 2 lata kierowania zespołem', '5 ans en production alimentaire, dont 2 ans à la tête d’une équipe', 'Pracownia centralna, pełny etat ; obecność na zmianach według potrzeb', 'Atelier central, temps plein ; présence sur les équipes selon les besoins', 'Rentowność grup względem celu
Koszt personelu w % sprzedaży
Straty w %
Dostawy na czas (OTIF)
Reklamacje', 'Rentabilité des groupes par rapport à l’objectif
Coût du personnel en % des ventes
Pertes en %
Livraisons à l’heure (OTIF)
Réclamations', 'Odpowiada za HACCP i BHP na hali', 'Responsable de l’HACCP et de la sécurité en atelier', 'Dyrekcja, jakość, zakupy, logistyka, brygadziści', 'Direction, qualité, achats, logistique, chefs d’équipe'
FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Organizuje wysyłki : załadunek dnia, trasy i pojazdy, dokumenty dostawy (WZ, CMR, WDT) i informację dla klientów, tak aby każdy klient dostał towar na czas.', 'Organise les expéditions : chargement du jour, tournées et véhicules, documents de livraison (WZ, CMR, WDT) et information des clients, pour que chaque client soit livré à l’heure.', 'Załadunek dnia i przydział klientów do pojazdów
Trasy i godziny wyjazdu
Dokumenty wysyłkowe (WZ, CMR, WDT)
Informacja o dostawie dla klientów
Śledzenie dostaw z tabletu kierowcy : potwierdzenia i problemy
Zwroty palet i opakowań
Współpraca z przewoźnikami', 'Chargement du jour et affectation des clients aux véhicules
Tournées et heures de départ
Documents d’expédition (WZ, CMR, WDT)
Information de livraison aux clients
Suivi des livraisons depuis la tablette chauffeur : confirmations et problèmes
Retours de palettes et d’emballages
Coordination des transporteurs', 'Organizacja i transport
Dokumenty CMR i WDT
Komunikacja z klientem', 'Organisation et transport
Documents CMR et WDT
Communication avec les clients', 'Doświadczenie w logistyce lub spedycji', 'Expérience en logistique ou transport', 'Biuro i magazyn wysyłek, popołudnie i wieczór (wyjazd ciężarówek)', 'Bureau et quai d’expédition, après-midi et soir (départ des camions)', 'Dostawy na czas
Wypełnienie ciężarówek (%)
Koszt logistyki w % sprzedaży
Problemy w dostawie', 'Livraisons à l’heure
Remplissage des camions (%)
Coût logistique en % des ventes
Incidents de livraison', 'Zabezpieczenie ładunku, łańcuch chłodniczy dla mrożonek', 'Arrimage du chargement, chaîne du froid pour les surgelés', 'Kierowcy, przewoźnicy, klienci, pakowanie', 'Chauffeurs, transporteurs, clients, conditionnement'
FROM n_position p WHERE p.code = 'LOGISTYKA'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Prowadzi obsługę administracyjną : zamówienia klientów, proformy i faktury, płatności i dokumenty, aby każda sprzedaż była poprawnie zafakturowana i zapłacona.', 'Assure l’administration : commandes clients, proformas et factures, paiements et documents, pour que chaque vente soit correctement facturée et payée.', 'Rejestracja zamówień klientów
Proformy i faktury (KSeF)
Przypomnienia płatności
Dane klientów i cenniki
Dokumenty dla księgowości
Korespondencja z klientami', 'Saisie des commandes clients
Proformas et factures (KSeF)
Relances de paiement
Données clients et tarifs
Documents pour la comptabilité
Correspondance avec les clients', 'ERP i KSeF
Dokładność
Francuski mile widziany', 'ERP et KSeF
Rigueur
Français apprécié', 'Wykształcenie ekonomiczne lub administracyjne', 'Formation en gestion ou administration', 'Biuro, pon.–pt.', 'Bureau, lun.–ven.', 'Faktury bez błędów i w terminie
Należności przeterminowane
Czas obsługi zamówienia', 'Factures sans erreur et à temps
Créances échues
Délai de traitement d’une commande', 'Poufność danych klientów (RODO)', 'Confidentialité des données clients (RGPD)', 'Klienci i franczyzobiorcy, księgowość, logistyka', 'Clients et franchisés, comptabilité, logistique'
FROM n_position p WHERE p.code = 'ADMINISTRACJA'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Prowadzi zespół na zmianie : wykonanie planu dnia, organizacja stanowisk, jakość i higiena, zapisy na tablecie produkcji.', 'Dirige l’équipe pendant le poste : réalisation du plan du jour, organisation des postes, qualité et hygiène, saisies sur la tablette de production.', 'Podział zadań i stanowisk na początku zmiany
Kontrola planu dnia i postępu
Zatwierdzenie produkcji dnia na tablecie (plan → zrobione, straty)
Kontrola higieny i zapisów HACCP
Wdrażanie nowych osób
Zgłaszanie awarii i niezgodności', 'Répartition des tâches et des postes en début d’équipe
Suivi du plan du jour et de l’avancement
Validation de la production du jour sur la tablette (plan → réalisé, pertes)
Contrôle de l’hygiène et des enregistrements HACCP
Accueil et formation des nouveaux
Signalement des pannes et des non-conformités', 'Przywództwo i organizacja
Technologia produkcji
Obsługa tabletu produkcji', 'Leadership et organisation
Technologie de production
Utilisation de la tablette de production', '3 lata w produkcji, w tym doświadczenie z zespołem', '3 ans en production, dont une expérience d’encadrement', 'Hala produkcyjna, zmiana według grafiku', 'Atelier, équipe selon le planning', 'Plan dnia wykonany
Straty w %
Obecność i punktualność zespołu
Dzień zatwierdzony na tablecie', 'Plan du jour réalisé
Pertes en %
Présence et ponctualité de l’équipe
Journée validée sur la tablette', 'Odzież robocza, czepek, mycie rąk ; badania sanitarno-epidemiologiczne aktualne
Odpowiada za BHP i higienę zespołu na zmianie', 'Tenue de travail, charlotte, lavage des mains ; certificat médical d’aptitude à jour
Responsable de la sécurité et de l’hygiène de l’équipe pendant le poste', 'Kierownik produkcji, jakość, logistyka', 'Responsable production, qualité, logistique'
FROM n_position p WHERE p.code = 'BRYGADZISTA'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Wytwarza pieczywo (zakwasy, ciasta, formowanie, wypiek) zgodnie z recepturami i planem dnia, w wymaganej jakości i ilości.', 'Fabrique le pain (levains, pâtes, façonnage, cuisson) selon les recettes et le plan du jour, dans la qualité et la quantité demandées.', 'Przygotowanie zakwasów i ciast według receptur
Ważenie surowców
Formowanie, fermentacja i wypiek
Kontrola wyrobów : wygląd, waga
Zapis produkcji, partii surowców i strat na tablecie produkcji
Sprzątanie stanowiska', 'Préparation des levains et des pâtes selon les recettes
Pesée des matières
Façonnage, fermentation et cuisson
Contrôle des produits : aspect, poids
Saisie de la production, des lots de matières et des pertes sur la tablette de production
Nettoyage du poste', 'Technologia piekarnicza
Obsługa pieców, miesiarek i dzielarek
Praca zespołowa', 'Technologie boulangère
Utilisation des fours, pétrins et diviseuses
Travail en équipe', 'Zawód piekarz albo rok doświadczenia', 'CAP boulanger ou un an d’expérience', 'Hala produkcyjna, praca zmianowa (także w nocy i w soboty)
Praca na stojąco, wysoka temperatura przy piecach', 'Atelier, travail en équipes (aussi la nuit et le samedi)
Travail debout, chaleur près des fours', 'Plan dnia wykonany
Straty w %
Czas względem normy
Brak reklamacji jakościowych', 'Plan du jour réalisé
Pertes en %
Temps par rapport à la norme
Aucune réclamation qualité', 'Odzież robocza, czepek, mycie rąk ; badania sanitarno-epidemiologiczne aktualne
Alergeny : rozdzielenie i oznaczenie
Rękawice termiczne przy piecach, osłony maszyn', 'Tenue de travail, charlotte, lavage des mains ; certificat médical d’aptitude à jour
Allergènes : séparation et identification
Gants thermiques aux fours, carters des machines', 'Brygadzista, pakowanie, jakość', 'Chef d’équipe, conditionnement, qualité'
FROM n_position p WHERE p.code = 'PIEKARZ'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Wytwarza wyroby laminowane (croissanty, pains au chocolat, rogale) : laminowanie, formowanie, fermentacja, wypiek albo mrożenie.', 'Fabrique la viennoiserie feuilletée (croissants, pains au chocolat) : tourage, façonnage, pousse, cuisson ou surgélation.', 'Przygotowanie ciasta i tłuszczu
Laminowanie na wałkarce
Cięcie i formowanie, także z cobotem
Fermentacja lub mrożenie
Wypiek
Zapis produkcji, partii surowców i strat na tablecie produkcji', 'Préparation de la détrempe et du beurre de tourage
Tourage au laminoir
Détaillage et façonnage, aussi avec le cobot
Pousse ou surgélation
Cuisson
Saisie de la production, des lots de matières et des pertes sur la tablette de production', 'Laminowanie (tourage)
Obsługa wałkarki i komory fermentacyjnej', 'Tourage
Utilisation du laminoir et de la chambre de pousse', 'Doświadczenie w viennoiserie mile widziane', 'Expérience en viennoiserie appréciée', 'Hala, zmiana dzienna (popołudniowa od Q2 2027) ; chłodnia i mroźnia', 'Atelier, équipe de jour (après-midi à partir du T2 2027) ; chambre froide et surgélateur', 'Plan dnia wykonany
Straty w %
Regularny kształt i waga', 'Plan du jour réalisé
Pertes en %
Forme et poids réguliers', 'Odzież robocza, czepek, mycie rąk ; badania sanitarno-epidemiologiczne aktualne
Temperatura ciasta i tłuszczu
Osłony wałkarki', 'Tenue de travail, charlotte, lavage des mains ; certificat médical d’aptitude à jour
Température de la pâte et du beurre
Protections du laminoir', 'Brygadzista, pakowanie', 'Chef d’équipe, conditionnement'
FROM n_position p WHERE p.code = 'ROGAL'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Wytwarza wyroby cukiernicze (tarty, ciastka, kremy, dekoracje) zgodnie z recepturami, w jakości L’Atelier.', 'Fabrique la pâtisserie (tartes, gâteaux, crèmes, décors) selon les recettes, dans la qualité L’Atelier.', 'Kremy, masy, ciasta kruche i biszkopty
Montaż i dekoracja
Schładzanie i mrożenie według zasad
Zapis produkcji, partii surowców i strat na tablecie produkcji
Kontrola wyglądu i wagi', 'Crèmes, appareils, pâtes sucrées et biscuits
Montage et décoration
Refroidissement et surgélation selon les règles
Saisie de la production, des lots de matières et des pertes sur la tablette de production
Contrôle de l’aspect et du poids', 'Technologia cukiernicza, techniki francuskie
Estetyka i precyzja', 'Technologie pâtissière, techniques françaises
Sens esthétique et précision', 'Zawód cukiernik albo rok doświadczenia', 'CAP pâtissier ou un an d’expérience', 'Hala, zmiana dzienna, praca w chłodni', 'Atelier, équipe de jour, travail en chambre froide', 'Plan dnia wykonany
Straty w %
Jakość dekoracji', 'Plan du jour réalisé
Pertes en %
Qualité des décors', 'Odzież robocza, czepek, mycie rąk ; badania sanitarno-epidemiologiczne aktualne
Łańcuch chłodniczy : temperatury
Alergeny (orzechy, mleko, jaja)', 'Tenue de travail, charlotte, lavage des mains ; certificat médical d’aptitude à jour
Chaîne du froid : températures
Allergènes (fruits à coque, lait, œufs)', 'Brygadzista, jakość, pakowanie', 'Chef d’équipe, qualité, conditionnement'
FROM n_position p WHERE p.code = 'CUKIERNIK'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Przygotowuje wyroby garmażeryjne (quiche, kanapki, dania) zgodnie z recepturami i zasadami bezpieczeństwa żywności.', 'Prépare les produits traiteur (quiches, sandwichs, plats) selon les recettes et les règles de sécurité des aliments.', 'Przygotowanie składników (mięso, warzywa, sery)
Gotowanie i pieczenie
Szybkie schładzanie
Porcjowanie
Zapis temperatur i partii', 'Préparation des ingrédients (viandes, légumes, fromages)
Cuisson
Refroidissement rapide
Portionnage
Enregistrement des températures et des lots', 'Kuchnia
HACCP dla wyrobów wrażliwych
Organizacja', 'Cuisine
HACCP des produits sensibles
Organisation', 'Zawód kucharz albo doświadczenie w kuchni', 'CAP cuisine ou expérience en cuisine', 'Hala, zmiana dzienna', 'Atelier, équipe de jour', 'Plan dnia wykonany
Straty w %
Temperatury w normie : 100 %', 'Plan du jour réalisé
Pertes en %
Températures conformes : 100 %', 'Odzież robocza, czepek, mycie rąk ; badania sanitarno-epidemiologiczne aktualne
Rozdział surowe / gotowe
Łańcuch chłodniczy i alergeny', 'Tenue de travail, charlotte, lavage des mains ; certificat médical d’aptitude à jour
Séparation cru / cuit
Chaîne du froid et allergènes', 'Brygadzista, jakość', 'Chef d’équipe, qualité'
FROM n_position p WHERE p.code = 'GARMAZER'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Pakuje i etykietuje wyroby, kompletuje zamówienia klientów na paletach i przygotowuje je do załadunku.', 'Conditionne et étiquette les produits, prépare les commandes des clients sur palettes et les prépare au chargement.', 'Pakowanie w kartony i etykietowanie
Skanowanie kartonów i palet
Kompletacja zamówień według listy
Palety i folia
Pomoc przy załadunku
Porządek w magazynie wysyłek', 'Mise en cartons et étiquetage
Scan des cartons et des palettes
Préparation des commandes selon la liste
Palettisation et filmage
Aide au chargement
Ordre du quai d’expédition', 'Dokładność
Obsługa skanera
Wózek paletowy', 'Rigueur
Utilisation du scanner
Transpalette', 'Uprawnienia na wózek mile widziane', 'CACES apprécié', 'Magazyn wysyłek, chłodnia i mroźnia, popołudnie i wieczór', 'Quai d’expédition, chambre froide et surgélateur, après-midi et soir', 'Zamówienia bez błędów
Palety gotowe przed godziną wyjazdu
Braki i nadwyżki', 'Commandes sans erreur
Palettes prêtes avant l’heure de départ
Manquants et surplus', 'Odzież robocza, czepek, mycie rąk ; badania sanitarno-epidemiologiczne aktualne
Odzież ochronna w mroźni
BHP przy wózkach', 'Tenue de travail, charlotte, lavage des mains ; certificat médical d’aptitude à jour
Équipement de protection au surgélateur
Sécurité des chariots', 'Logistyka, kierowcy, brygadzista', 'Logistique, chauffeurs, chef d’équipe'
FROM n_position p WHERE p.code = 'PAKOWACZ'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr, skills_pl, skills_fr, requirements_pl, requirements_fr, conditions_pl, conditions_fr, indicators_pl, indicators_fr, safety_pl, safety_fr, relations_pl, relations_fr)
SELECT p.id, 'Dostarcza zamówienia klientom zgodnie z trasą i godzinami, dba o towar i dokumenty, potwierdza każdą dostawę na tablecie kierowcy.', 'Livre les commandes aux clients selon la tournée et les horaires, prend soin de la marchandise et des documents, confirme chaque livraison sur la tablette chauffeur.', 'Odbiór dokumentów i kontrola załadunku (palety według listy)
Jazda według trasy i kolejności rozładunku
Rozładunek i przekazanie towaru
Potwierdzenie dostawy na tablecie : odbiorca, podpis, zdjęcie, palety zwrotne
Zgłaszanie problemów : zamknięte, odmowa, uszkodzenie, brak
Rozliczenie palet EUR i dokumentów
Dbałość o pojazd i temperaturę w chłodni', 'Prise des documents et contrôle du chargement (palettes selon la liste)
Conduite selon la tournée et l’ordre de déchargement
Déchargement et remise de la marchandise
Confirmation de livraison sur la tablette : réceptionnaire, signature, photo, palettes rendues
Signalement des problèmes : fermé, refus, casse, manquant
Retour des palettes EUR et des documents
Entretien du véhicule et température du frigo', 'Znajomość tras
Kontakt z klientem
Podstawy francuskiego lub niderlandzkiego mile widziane', 'Connaissance des itinéraires
Relation client
Bases de français ou de néerlandais appréciées', 'Prawo jazdy odpowiedniej kategorii (C / C+E), karta kierowcy, kod 95
Badania lekarskie', 'Permis adapté (C / CE), carte conducteur, FIMO / FCO
Visite médicale', 'Praca w trasie, wyjazd wieczorem (godzina według trasy) ; możliwe noce w trasie', 'Travail sur la route, départ le soir (heure selon la tournée) ; nuits possibles en déplacement', 'Dostawy na czas
100 % dostaw potwierdzonych na tablecie
Palety zwrotne rozliczone
Zero szkód', 'Livraisons à l’heure
100 % des livraisons confirmées sur la tablette
Palettes rendues suivies
Zéro casse', 'Czas pracy kierowcy, zabezpieczenie ładunku
Temperatura w chłodni, higiena przy towarze', 'Temps de conduite, arrimage du chargement
Température du frigo, hygiène au contact de la marchandise', 'Logistyka, klienci, przewoźnik', 'Logistique, clients, transporteur'
FROM n_position p WHERE p.code = 'KIEROWCA'
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);
