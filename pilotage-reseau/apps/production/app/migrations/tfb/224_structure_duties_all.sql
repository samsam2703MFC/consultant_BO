-- [376] Struktura i obowiązki : la matrice obligation → poste → assistant de contrôle (n_position_duty, migration 216)
-- pour tous les postes sauf le Kierownik operacyjny (ses 34 obligations restent celles de 216, jamais touchées ici).
--  - source 'sheet' : une ligne par point de la fiche de poste (n_position_sheet.responsibilities_pl, texte semé par 081,
--    155 et 169), numérotée 1, 2… dans l'ordre de la fiche — la même numérotation que StructureService::importSheets() ;
--  - source 'task' : une ligne par tâche semée du poste (n_position_task) dont l'assistant n'est pas déjà sur une ligne
--    de la fiche, numérotée Z1, Z2… (zadanie). Kierownik produkcji : aussi les tâches suspendues par 162 (« petit à
--    petit ») ; pas les tâches fusionnées (147, 155) ni « Koszty i marże » / « Nowe ceny » remplacées par la ścieżka cen (208) ;
--  - wizard_code : assistant existant (TaskWizards::CATALOG) qui contrôle l'obligation ; proposed_wizard : assistant à
--    créer (noms distincts des libellés du CATALOG, pour que les UPDATE de 217 → 222 ne les prennent jamais) ; sinon
--    les deux NULL (travail physique au kiosque, ou rien de mesurable dans l'ERP) ;
--  - Zarząd : ni fiche ni tâche, rien à semer.
-- Seulement les postes qui existent (jointure sur n_position.code), actifs ou non (Zakupy est inactif depuis 140).
-- Rejouable : INSERT … SELECT … WHERE NOT EXISTS sur (id_position, number), et sur (id_position, text_pl) pour ne pas
-- doubler un point déjà chargé depuis la fiche (importSheets) ou supprimé dans l'ERP (active = 0). Rattachements à revoir
-- dans l'ERP (Administracja › Struktura i obowiązki).

INSERT INTO n_position_duty (id_position, number, area_no, area, text_pl, text_fr, wizard_code, proposed_wizard, rhythm, source, sort_order)
SELECT p.id, d.number, IF(d.source = 'sheet', 1, 2), IF(d.source = 'sheet', 'Karta stanowiska — zakres obowiązków', 'Zadania stanowiska'),
    d.text_pl, d.text_fr, d.wizard_code, d.proposed_wizard, d.rhythm, d.source, d.sort_order
FROM n_position p
JOIN (
    -- Specjalista ds. zakupów (inactif : achats tenus par le Kierownik produkcji depuis 140)
    SELECT 'ZAKUPY' AS code, '1' AS number, 'Zamówienia surowców i opakowań na podstawie zapotrzebowania z planu produkcji (Zaopatrzenie)' AS text_pl, 'Commandes de matières et d’emballages à partir des besoins du plan de production (Zaopatrzenie)' AS text_fr, 'buy_sent' AS wizard_code, NULL AS proposed_wizard, 'weekly' AS rhythm, 'sheet' AS source, 10 AS sort_order
    UNION ALL SELECT 'ZAKUPY', '2', 'Kontrola faktur zakupowych z zamówieniami i przyjęciami (ilości, ceny)', 'Contrôle des factures d’achat avec les commandes et les réceptions (quantités, prix)', 'inv_checked', NULL, 'weekly', 'sheet', 20
    UNION ALL SELECT 'ZAKUPY', '3', 'Aktualizacja cen surowców i analiza wzrostów cen', 'Mise à jour des prix des matières et analyse des hausses', 'material_costs_reviewed', NULL, 'monthly', 'sheet', 30
    UNION ALL SELECT 'ZAKUPY', '4', 'Zapytania ofertowe i negocjacje z dostawcami', 'Appels d’offres et négociation avec les fournisseurs', 'suppliers_reviewed', NULL, 'quarterly', 'sheet', 40
    UNION ALL SELECT 'ZAKUPY', '5', 'Stany minimalne i maksymalne surowców, brak przestojów', 'Stocks minimum et maximum des matières, aucune rupture', 'buy_sent', NULL, 'weekly', 'sheet', 50
    UNION ALL SELECT 'ZAKUPY', '6', 'Niezgodności dostaw i reklamacje do dostawców', 'Non-conformités de livraison et réclamations aux fournisseurs', 'nc_reviewed', NULL, 'monthly', 'sheet', 60
    -- Specjalista ds. jakości
    UNION ALL SELECT 'JAKOSC', '1', 'Plan HACCP, jego aktualizacja i kontrole na hali', 'Plan HACCP, sa mise à jour et les contrôles en atelier', NULL, 'HACCP miesiąca', 'monthly', 'sheet', 10
    UNION ALL SELECT 'JAKOSC', '2', 'Rejestr niezgodności : przyczyny i działania korygujące', 'Registre des non-conformités : causes et actions correctives', 'nc_reviewed', NULL, 'monthly', 'sheet', 20
    UNION ALL SELECT 'JAKOSC', '3', 'Reklamacje klientów : ocena, odpowiedź, działania', 'Réclamations clients : analyse, réponse, actions', NULL, 'Reklamacje klientów', 'weekly', 'sheet', 30
    UNION ALL SELECT 'JAKOSC', '4', 'Karty techniczne, alergeny i etykiety produktów', 'Fiches techniques, allergènes et étiquettes des produits', NULL, NULL, 'none', 'sheet', 40
    UNION ALL SELECT 'JAKOSC', '5', 'Identyfikowalność partii (surowce → produkty)', 'Traçabilité des lots (matières → produits)', NULL, 'Partie i kontrola dnia', 'daily', 'sheet', 50
    UNION ALL SELECT 'JAKOSC', '6', 'Szkolenia z higieny dla zespołów', 'Formations hygiène des équipes', NULL, NULL, 'none', 'sheet', 60
    UNION ALL SELECT 'JAKOSC', 'Z1', 'Kontrola przyjęć dnia : dostawy z zamówień, pozycje OK / niezgodność, zdjęcia, decyzja (asystent przyjęć)', 'Contrôle des réceptions du jour : livraisons des commandes, lignes OK / non-conformité, photos, décision (assistant réceptions)', 'receiving_checked', NULL, 'daily', 'task', 510
    -- Kierownik produkcji (fiche + tâches, dont celles suspendues par 162)
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', '1', 'Czas pracy zespołu : weryfikacja odbić i zatwierdzenie dnia (codziennie), zatwierdzenie i zamknięcie miesiąca (co miesiąc)', 'Temps de travail de l''équipe : vérification des pointages et validation du jour (chaque jour), validation et clôture du mois (chaque mois)', 'time_day_validated', NULL, 'daily', 'sheet', 10
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', '2', 'Plan produkcji tygodnia i plan zespołów', 'Plan de production de la semaine et plan des équipes', 'week_plan_validated', NULL, 'weekly', 'sheet', 20
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', '3', 'Organizacja zmian i obsady', 'Organisation des équipes et des effectifs', 'tomorrow_sent', NULL, 'daily', 'sheet', 30
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', '4', 'Koszty : surowce, personel, straty', 'Coûts : matières, personnel, pertes', 'material_costs_reviewed', NULL, 'monthly', 'sheet', 40
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', '5', 'Jakość i HACCP razem z działem jakości', 'Qualité et HACCP avec la qualité', NULL, 'HACCP miesiąca', 'monthly', 'sheet', 50
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', '6', 'Nowe produkty i karty produktów', 'Nouveaux produits et fiches produits', NULL, 'Nowe produkty', 'monthly', 'sheet', 60
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', '7', 'Zebrania produkcyjne i raport miesięczny', 'Réunions de production et rapport mensuel', NULL, NULL, 'none', 'sheet', 70
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z1', 'Czas pracy miesiąca : sprawdź miesiąc wszystkich (godziny, nadgodziny, urlopy), zatwierdź i zamknij miesiąc — karty czasu pracy', 'Temps de travail du mois : contrôler le mois de tous (heures, heures sup., congés), valider et clôturer le mois — fiches de temps', 'time_month_closed', NULL, 'monthly', 'task', 510
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z2', 'Ścieżka cen (czwartek) : ceny z faktur KSeF → ceny katalogowe ±3 % → cennik → e-mail do klientów (asystent)', 'Parcours des prix (jeudi) : prix des factures KSeF → prix catalogue ±3 % → tarif → e-mail aux clients (assistant)', 'price_path_done', NULL, 'weekly', 'task', 520
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z3', 'Próbki produkcji : wybierz partie, wydrukuj arkusz A4 z kodami QR, zeskanuj przy odłożeniu (asystent)', 'Échantillons de production : choisir les lots, imprimer la feuille A4 avec QR, scanner au rangement (assistant)', 'samples_done', NULL, 'daily', 'task', 530
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z4', 'SOP dnia : popraw jedną procedurę albo utwórz nową (etapy, zrzuty ekranu / zdjęcia) i zatwierdź', 'SOP du jour : corriger une procédure ou en créer une nouvelle (étapes, captures / photos) et l''approuver', 'sop_daily', NULL, 'daily', 'task', 540
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z5', 'Zatwierdź prognozę 13 tygodni (sezony z panelu, palety, godziny, zakupy)', 'Valider la prévision 13 semaines (saisons du panel, palettes, heures, achats)', 'forecast_approved', NULL, 'weekly', 'task', 550
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z6', 'Śledzenie produkcji : plan, zrobione, straty, zatwierdzone dni', 'Suivi de la production : plan, réalisé, pertes, journées validées', 'production_day_closed', NULL, 'daily', 'task', 560
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z7', 'Wydajność i straty kwartału : straty, sztuki na godzinę, TRS, działania (asystent)', 'Rendement et pertes du trimestre : pertes, pièces par heure, TRS, actions (assistant)', 'yield_reviewed', NULL, 'quarterly', 'task', 570
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z8', 'Zakupy tygodnia : co kupić → komu → wyślij, oferty dostawców, pozycje z mniej niż 3 dostawcami', 'Achats de la semaine : quoi acheter → à qui → envoyer, offres reçues, articles à moins de 3 fournisseurs', 'buy_sent', NULL, 'weekly', 'task', 580
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z9', 'Faktury tygodnia : wgraj (PDF / KSeF), sprawdź pozycje, nowe ceny surowców (asystent faktur)', 'Factures de la semaine : importer (PDF / KSeF), vérifier les lignes, nouveaux prix matières (assistant factures)', 'inv_checked', NULL, 'weekly', 'task', 590
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Z10', 'Ocena dostawców i zapytanie ofertowe na kluczowe surowce (ceny, terminy, niezgodności)', 'Évaluation des fournisseurs et appel d''offres sur les matières clés (prix, délais, non-conformités)', 'suppliers_reviewed', NULL, 'quarterly', 'task', 600
    -- Logistyka i wysyłki
    UNION ALL SELECT 'LOGISTYKA', '1', 'Załadunek dnia i przydział klientów do pojazdów', 'Chargement du jour et affectation des clients aux véhicules', 'orders_transport', NULL, 'daily', 'sheet', 10
    UNION ALL SELECT 'LOGISTYKA', '2', 'Trasy i godziny wyjazdu', 'Tournées et heures de départ', 'logistics_plan_approved', NULL, 'weekly', 'sheet', 20
    UNION ALL SELECT 'LOGISTYKA', '3', 'Dokumenty wysyłkowe (WZ, CMR, WDT)', 'Documents d’expédition (WZ, CMR, WDT)', 'day_shipped', NULL, 'daily', 'sheet', 30
    UNION ALL SELECT 'LOGISTYKA', '4', 'Informacja o dostawie dla klientów', 'Information de livraison aux clients', NULL, 'Powiadomienia o dostawie', 'daily', 'sheet', 40
    UNION ALL SELECT 'LOGISTYKA', '5', 'Śledzenie dostaw z tabletu kierowcy : potwierdzenia i problemy', 'Suivi des livraisons depuis la tablette chauffeur : confirmations et problèmes', 'deliveries_delays_checked', NULL, 'daily', 'sheet', 50
    UNION ALL SELECT 'LOGISTYKA', '6', 'Zwroty palet i opakowań', 'Retours de palettes et d’emballages', NULL, 'Palety zwrotne', 'weekly', 'sheet', 60
    UNION ALL SELECT 'LOGISTYKA', '7', 'Współpraca z przewoźnikami', 'Coordination des transporteurs', NULL, NULL, 'none', 'sheet', 70
    UNION ALL SELECT 'LOGISTYKA', 'Z1', 'Rozliczenie przewoźników', 'Décompte des transporteurs', 'month_closed', NULL, 'monthly', 'task', 510
    -- Administracja i fakturowanie
    UNION ALL SELECT 'ADMINISTRACJA', '1', 'Rejestracja zamówień klientów', 'Saisie des commandes clients', 'orders_day_checked', NULL, 'daily', 'sheet', 10
    UNION ALL SELECT 'ADMINISTRACJA', '2', 'Proformy i faktury (KSeF)', 'Proformas et factures (KSeF)', NULL, 'Faktury i KSeF dnia', 'daily', 'sheet', 20
    UNION ALL SELECT 'ADMINISTRACJA', '3', 'Przypomnienia płatności', 'Relances de paiement', 'payments_reviewed', NULL, 'daily', 'sheet', 30
    UNION ALL SELECT 'ADMINISTRACJA', '4', 'Dane klientów i cenniki', 'Données clients et tarifs', NULL, 'Dane klientów', 'monthly', 'sheet', 40
    UNION ALL SELECT 'ADMINISTRACJA', '5', 'Dokumenty dla księgowości', 'Documents pour la comptabilité', 'month_closed', NULL, 'monthly', 'sheet', 50
    UNION ALL SELECT 'ADMINISTRACJA', '6', 'Korespondencja z klientami', 'Correspondance avec les clients', NULL, 'Poczta od klientów', 'daily', 'sheet', 60
    UNION ALL SELECT 'ADMINISTRACJA', 'Z1', 'Koszty usług miesiąca (Finanse : zamknij miesiąc)', 'Coûts de services du mois (Finances : clôturer le mois)', 'services_closed', NULL, 'monthly', 'task', 510
    -- Brygadzista zmiany (atelier)
    UNION ALL SELECT 'BRYGADZISTA', '1', 'Podział zadań i stanowisk na początku zmiany', 'Répartition des tâches et des postes en début d’équipe', NULL, NULL, 'none', 'sheet', 10
    UNION ALL SELECT 'BRYGADZISTA', '2', 'Kontrola planu dnia i postępu', 'Suivi du plan du jour et de l’avancement', NULL, NULL, 'none', 'sheet', 20
    UNION ALL SELECT 'BRYGADZISTA', '3', 'Zatwierdzenie produkcji dnia na tablecie (plan → zrobione, straty)', 'Validation de la production du jour sur la tablette (plan → réalisé, pertes)', 'shift_day_validated', NULL, 'daily', 'sheet', 30
    UNION ALL SELECT 'BRYGADZISTA', '4', 'Kontrola higieny i zapisów HACCP', 'Contrôle de l’hygiène et des enregistrements HACCP', NULL, 'HACCP miesiąca', 'monthly', 'sheet', 40
    UNION ALL SELECT 'BRYGADZISTA', '5', 'Wdrażanie nowych osób', 'Accueil et formation des nouveaux', NULL, NULL, 'none', 'sheet', 50
    UNION ALL SELECT 'BRYGADZISTA', '6', 'Zgłaszanie awarii i niezgodności', 'Signalement des pannes et des non-conformités', 'nc_reviewed', NULL, 'monthly', 'sheet', 60
    -- Piekarz (atelier)
    UNION ALL SELECT 'PIEKARZ', '1', 'Przygotowanie zakwasów i ciast według receptur', 'Préparation des levains et des pâtes selon les recettes', NULL, NULL, 'none', 'sheet', 10
    UNION ALL SELECT 'PIEKARZ', '2', 'Ważenie surowców', 'Pesée des matières', NULL, NULL, 'none', 'sheet', 20
    UNION ALL SELECT 'PIEKARZ', '3', 'Formowanie, fermentacja i wypiek', 'Façonnage, fermentation et cuisson', NULL, NULL, 'none', 'sheet', 30
    UNION ALL SELECT 'PIEKARZ', '4', 'Kontrola wyrobów : wygląd, waga', 'Contrôle des produits : aspect, poids', NULL, 'Partie i kontrola dnia', 'daily', 'sheet', 40
    UNION ALL SELECT 'PIEKARZ', '5', 'Zapis produkcji, partii surowców i strat na tablecie produkcji', 'Saisie de la production, des lots de matières et des pertes sur la tablette de production', 'group_day_closed', NULL, 'daily', 'sheet', 50
    UNION ALL SELECT 'PIEKARZ', '6', 'Sprzątanie stanowiska', 'Nettoyage du poste', NULL, NULL, 'none', 'sheet', 60
    -- Pracownik tart (atelier)
    UNION ALL SELECT 'TARTY', '1', 'Ciasto kruche i spody tart', 'Pâte sucrée et fonds de tarte', NULL, NULL, 'none', 'sheet', 10
    UNION ALL SELECT 'TARTY', '2', 'Nadzienia, kremy i owoce', 'Garnitures, crèmes et fruits', NULL, NULL, 'none', 'sheet', 20
    UNION ALL SELECT 'TARTY', '3', 'Pieczenie i schładzanie', 'Cuisson et refroidissement', NULL, NULL, 'none', 'sheet', 30
    UNION ALL SELECT 'TARTY', '4', 'Wykończenie (glazura, dekoracja) i kontrola wyglądu i wagi', 'Finition (nappage, décor) et contrôle de l’aspect et du poids', NULL, 'Partie i kontrola dnia', 'daily', 'sheet', 40
    UNION ALL SELECT 'TARTY', '5', 'Zapis produkcji, partii surowców i strat na tablecie grupy', 'Saisie de la production, des lots de matières et des pertes sur la tablette du groupe', 'group_day_closed', NULL, 'daily', 'sheet', 50
    -- Piekarz wyrobów laminowanych (atelier)
    UNION ALL SELECT 'ROGAL', '1', 'Przygotowanie ciasta i tłuszczu', 'Préparation de la détrempe et du beurre de tourage', NULL, NULL, 'none', 'sheet', 10
    UNION ALL SELECT 'ROGAL', '2', 'Laminowanie na wałkarce', 'Tourage au laminoir', NULL, NULL, 'none', 'sheet', 20
    UNION ALL SELECT 'ROGAL', '3', 'Cięcie i formowanie, także z cobotem', 'Détaillage et façonnage, aussi avec le cobot', NULL, NULL, 'none', 'sheet', 30
    UNION ALL SELECT 'ROGAL', '4', 'Fermentacja lub mrożenie', 'Pousse ou surgélation', NULL, NULL, 'none', 'sheet', 40
    UNION ALL SELECT 'ROGAL', '5', 'Wypiek', 'Cuisson', NULL, NULL, 'none', 'sheet', 50
    UNION ALL SELECT 'ROGAL', '6', 'Zapis produkcji, partii surowców i strat na tablecie produkcji', 'Saisie de la production, des lots de matières et des pertes sur la tablette de production', 'group_day_closed', NULL, 'daily', 'sheet', 60
    -- Cukiernik (atelier)
    UNION ALL SELECT 'CUKIERNIK', '1', 'Kremy, masy, ciasta kruche i biszkopty', 'Crèmes, appareils, pâtes sucrées et biscuits', NULL, NULL, 'none', 'sheet', 10
    UNION ALL SELECT 'CUKIERNIK', '2', 'Montaż i dekoracja', 'Montage et décoration', NULL, NULL, 'none', 'sheet', 20
    UNION ALL SELECT 'CUKIERNIK', '3', 'Schładzanie i mrożenie według zasad', 'Refroidissement et surgélation selon les règles', NULL, NULL, 'none', 'sheet', 30
    UNION ALL SELECT 'CUKIERNIK', '4', 'Zapis produkcji, partii surowców i strat na tablecie produkcji', 'Saisie de la production, des lots de matières et des pertes sur la tablette de production', 'group_day_closed', NULL, 'daily', 'sheet', 40
    UNION ALL SELECT 'CUKIERNIK', '5', 'Kontrola wyglądu i wagi', 'Contrôle de l’aspect et du poids', NULL, 'Partie i kontrola dnia', 'daily', 'sheet', 50
    -- Kucharz garmażerii (atelier) : la fiche ne parle pas de la tablette, la tâche de 148 est ajoutée (Z1)
    UNION ALL SELECT 'GARMAZER', '1', 'Przygotowanie składników (mięso, warzywa, sery)', 'Préparation des ingrédients (viandes, légumes, fromages)', NULL, NULL, 'none', 'sheet', 10
    UNION ALL SELECT 'GARMAZER', '2', 'Gotowanie i pieczenie', 'Cuisson', NULL, NULL, 'none', 'sheet', 20
    UNION ALL SELECT 'GARMAZER', '3', 'Szybkie schładzanie', 'Refroidissement rapide', NULL, NULL, 'none', 'sheet', 30
    UNION ALL SELECT 'GARMAZER', '4', 'Porcjowanie', 'Portionnage', NULL, NULL, 'none', 'sheet', 40
    UNION ALL SELECT 'GARMAZER', '5', 'Zapis temperatur i partii', 'Enregistrement des températures et des lots', NULL, 'Partie i kontrola dnia', 'daily', 'sheet', 50
    UNION ALL SELECT 'GARMAZER', 'Z1', 'Dzień zatwierdzony na tablecie — Garmazeria (plan → zrobione → straty → zatwierdź)', 'Journée validée sur la tablette — Garmazeria (plan → fait → pertes → valider)', 'group_day_closed', NULL, 'daily', 'task', 510
    -- Pracownik pakowania i wysyłki (atelier)
    UNION ALL SELECT 'PAKOWACZ', '1', 'Pakowanie w kartony i etykietowanie', 'Mise en cartons et étiquetage', 'packing_done', NULL, 'daily', 'sheet', 10
    UNION ALL SELECT 'PAKOWACZ', '2', 'Skanowanie kartonów i palet', 'Scan des cartons et des palettes', 'packing_done', NULL, 'daily', 'sheet', 20
    UNION ALL SELECT 'PAKOWACZ', '3', 'Kompletacja zamówień według listy', 'Préparation des commandes selon la liste', 'packing_done', NULL, 'daily', 'sheet', 30
    UNION ALL SELECT 'PAKOWACZ', '4', 'Palety i folia', 'Palettisation et filmage', NULL, NULL, 'none', 'sheet', 40
    UNION ALL SELECT 'PAKOWACZ', '5', 'Pomoc przy załadunku', 'Aide au chargement', NULL, NULL, 'none', 'sheet', 50
    UNION ALL SELECT 'PAKOWACZ', '6', 'Porządek w magazynie wysyłek', 'Ordre du quai d’expédition', NULL, NULL, 'none', 'sheet', 60
    -- Kierowca-dostawca (atelier)
    UNION ALL SELECT 'KIEROWCA', '1', 'Odbiór dokumentów i kontrola załadunku (palety według listy)', 'Prise des documents et contrôle du chargement (palettes selon la liste)', 'day_shipped', NULL, 'daily', 'sheet', 10
    UNION ALL SELECT 'KIEROWCA', '2', 'Jazda według trasy i kolejności rozładunku', 'Conduite selon la tournée et l’ordre de déchargement', 'route_done', NULL, 'daily', 'sheet', 20
    UNION ALL SELECT 'KIEROWCA', '3', 'Rozładunek i przekazanie towaru', 'Déchargement et remise de la marchandise', 'route_done', NULL, 'daily', 'sheet', 30
    UNION ALL SELECT 'KIEROWCA', '4', 'Potwierdzenie dostawy na tablecie : odbiorca, podpis, zdjęcie, palety zwrotne', 'Confirmation de livraison sur la tablette : réceptionnaire, signature, photo, palettes rendues', 'route_done', NULL, 'daily', 'sheet', 40
    UNION ALL SELECT 'KIEROWCA', '5', 'Zgłaszanie problemów : zamknięte, odmowa, uszkodzenie, brak', 'Signalement des problèmes : fermé, refus, casse, manquant', 'deliveries_delays_checked', NULL, 'daily', 'sheet', 50
    UNION ALL SELECT 'KIEROWCA', '6', 'Rozliczenie palet EUR i dokumentów', 'Retour des palettes EUR et des documents', NULL, 'Palety zwrotne', 'weekly', 'sheet', 60
    UNION ALL SELECT 'KIEROWCA', '7', 'Dbałość o pojazd i temperaturę w chłodni', 'Entretien du véhicule et température du frigo', NULL, 'HACCP miesiąca', 'monthly', 'sheet', 70
) d ON d.code COLLATE utf8mb4_general_ci = p.code
WHERE p.code <> 'KIEROWNIK_OPERACYJNY'
  AND NOT EXISTS (SELECT 1 FROM n_position_duty x WHERE x.id_position = p.id AND x.number = d.number COLLATE utf8mb4_general_ci)
  AND NOT EXISTS (SELECT 1 FROM n_position_duty x WHERE x.id_position = p.id AND x.text_pl = d.text_pl COLLATE utf8mb4_general_ci);
