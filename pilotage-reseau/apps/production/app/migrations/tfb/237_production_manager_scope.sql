-- [396] Kierownik produkcji : zakres obowiązków « produkcja » (document du 23.11.2026, 11 obszarów, 68 obowiązków)
-- devient sa karta stanowiska et sa matrice (Administracja › Struktura i obowiązki), décision du 23.11.2026.
--  - matrice : 68 lignes 1.1 → 11.4 (source « Zakres obowiązków — produkcja (23.11.2026) ») : 22 avec un assistant de contrôle existant
--    (TaskWizards::CATALOG), 27 avec un assistant proposé « do utworzenia » (9 noms, distincts des libellés du CATALOG),
--    les autres sans assistant (travail physique, courrier, rien de mesurable dans l'ERP) ;
--  - les anciennes lignes de la karta (1 → 7, migration 224 ou import de la karta) sont désactivées (active = 0, jamais
--    supprimées) ; les lignes « zadanie » dont l'assistant est maintenant dans le zakres (Zakupy tygodnia, faktury, ocena
--    dostawców, ścieżka cen, czas pracy miesiąca) aussi ; les autres (próbki, SOP, prognoza, śledzenie produkcji,
--    wydajność) restent, dans l'obszar 12 « Zadania stanowiska (poza zakresem) » ;
--  - karta : misja et zakres remplacés (version + 1, validation à refaire, comme une modification dans l'ERP).
-- Ni tâches de poste (n_position_task) ni droits (n_page_access) : « + zadanie » dans la matrice, droits au choix.
-- Rejouable : tout est conditionné au premier passage (karta sans le nouveau zakres / pas encore de ligne « Zakres obowiązków — produkcja (23.11.2026) »).

UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.active = 0
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.active = 1
  AND (d.source IN ('sheet', 'Karta stanowiska')
       OR (d.source = 'task' AND d.wizard_code IN ('buy_sent', 'inv_checked', 'suppliers_reviewed', 'price_path_done', 'time_month_closed', 'time_day_validated')))
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id AND s.responsibilities_pl LIKE '%Kompleksowe przygotowywanie profili produktowych w zakresie PPWR.%');

UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.area_no = 12, d.area = '12. Zadania stanowiska (poza zakresem obowiązków)', d.sort_order = 12000 + d.sort_order
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.source = 'task' AND d.sort_order < 12000
  AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id AND s.responsibilities_pl LIKE '%Kompleksowe przygotowywanie profili produktowych w zakresie PPWR.%');

INSERT INTO n_position_duty (id_position, number, area_no, area, text_pl, wizard_code, proposed_wizard, rhythm, sort_order, source)
SELECT p.id, d.number, d.area_no, d.area, d.text_pl, d.wizard_code, d.proposed_wizard, d.rhythm, d.sort_order, 'Zakres obowiązków — produkcja (23.11.2026)'
FROM n_position p
JOIN (
SELECT '1.1' AS number, 1 AS area_no, '1. OBSŁUGA SYSTEMU INFORMATYCZNEGO I BAZY PRODUKTÓW' AS area, 'Wprowadzanie nowych produktów do systemu.' AS text_pl, 'launches_reviewed' AS wizard_code, NULL AS proposed_wizard, 'monthly' AS rhythm, 1010 AS sort_order
    UNION ALL SELECT '1.2', 1, '1. OBSŁUGA SYSTEMU INFORMATYCZNEGO I BAZY PRODUKTÓW', 'Tworzenie i aktualizowanie receptur produktów.', NULL, 'Receptury i dane produktów', 'monthly', 1020
    UNION ALL SELECT '1.3', 1, '1. OBSŁUGA SYSTEMU INFORMATYCZNEGO I BAZY PRODUKTÓW', 'Dodawanie surowców wykorzystywanych w produkcji.', NULL, 'Kartoteka surowców (kompletność)', 'monthly', 1030
    UNION ALL SELECT '1.4', 1, '1. OBSŁUGA SYSTEMU INFORMATYCZNEGO I BAZY PRODUKTÓW', 'Bieżące wprowadzanie i aktualizowanie cen surowców.', 'inv_checked', NULL, 'weekly', 1040
    UNION ALL SELECT '1.5', 1, '1. OBSŁUGA SYSTEMU INFORMATYCZNEGO I BAZY PRODUKTÓW', 'Kategoryzacja surowców i produktów.', NULL, 'Kartoteka surowców (kompletność)', 'monthly', 1050
    UNION ALL SELECT '1.6', 1, '1. OBSŁUGA SYSTEMU INFORMATYCZNEGO I BAZY PRODUKTÓW', 'Aktualizacja danych produktowych oraz kontrola poprawności wprowadzonych informacji.', NULL, 'Receptury i dane produktów', 'monthly', 1060
    UNION ALL SELECT '1.7', 1, '1. OBSŁUGA SYSTEMU INFORMATYCZNEGO I BAZY PRODUKTÓW', 'Bieżąca obsługa i aktualizacja danych niezbędnych do prawidłowego funkcjonowania systemu.', NULL, NULL, 'none', 1070
    UNION ALL SELECT '2.1', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Kompleksowe przygotowywanie profili produktowych w zakresie PPWR.', NULL, 'PPWR i opakowania (kompletność)', 'monthly', 2010
    UNION ALL SELECT '2.2', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Pozyskiwanie od dostawców niezbędnych informacji i dokumentacji dotyczących opakowań.', NULL, 'PPWR i opakowania (kompletność)', 'monthly', 2020
    UNION ALL SELECT '2.3', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Występowanie do dostawców o dane dotyczące składu, rodzaju i parametrów opakowań.', NULL, 'PPWR i opakowania (kompletność)', 'monthly', 2030
    UNION ALL SELECT '2.4', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Wprowadzanie danych do systemu PPWR i BDO.', NULL, 'PPWR i opakowania (kompletność)', 'monthly', 2040
    UNION ALL SELECT '2.5', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Nadawanie numerów oraz przypisywanie odpowiednich kategorii opakowań.', NULL, 'PPWR i opakowania (kompletność)', 'monthly', 2050
    UNION ALL SELECT '2.6', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Określanie i wprowadzanie wag opakowań.', NULL, 'PPWR i opakowania (kompletność)', 'monthly', 2060
    UNION ALL SELECT '2.7', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Klasyfikowanie materiałów opakowaniowych według rodzaju, m.in. karton, papier, plastik, tworzywa sztuczne i inne materiały.', NULL, 'PPWR i opakowania (kompletność)', 'monthly', 2070
    UNION ALL SELECT '2.8', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Kompletowanie, weryfikacja i archiwizacja dokumentacji dotyczącej produktów oraz opakowań.', NULL, 'Teczka do audytu i kontroli', 'quarterly', 2080
    UNION ALL SELECT '2.9', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Przygotowywanie dokumentacji papierowej i elektronicznej na potrzeby audytów oraz ewentualnych kontroli zewnętrznych.', NULL, 'Teczka do audytu i kontroli', 'quarterly', 2090
    UNION ALL SELECT '2.10', 2, '2. PPWR, BDO, AUDYTY I DOKUMENTACJA ŚRODOWISKOWA', 'Dbanie o kompletność i prawidłowość dokumentacji związanej z obowiązkami środowiskowymi firmy.', NULL, 'PPWR i opakowania (kompletność)', 'monthly', 2100
    UNION ALL SELECT '3.1', 3, '3. OBSŁUGA ZAMÓWIEŃ I WSPARCIE PRODUKCJI', 'Przyjmowanie i wprowadzanie zamówień od kontrahentów do systemu.', 'orders_day_checked', NULL, 'daily', 3010
    UNION ALL SELECT '3.2', 3, '3. OBSŁUGA ZAMÓWIEŃ I WSPARCIE PRODUKCJI', 'Edytowanie, aktualizowanie i analizowanie zamówień.', 'supplements_day_checked', NULL, 'daily', 3020
    UNION ALL SELECT '3.3', 3, '3. OBSŁUGA ZAMÓWIEŃ I WSPARCIE PRODUKCJI', 'Przygotowywanie dokumentów i druków niezbędnych do realizacji produkcji.', 'tomorrow_sent', NULL, 'daily', 3030
    UNION ALL SELECT '3.4', 3, '3. OBSŁUGA ZAMÓWIEŃ I WSPARCIE PRODUKCJI', 'Bieżąca współpraca z produkcją w zakresie realizowanych zamówień.', NULL, NULL, 'none', 3040
    UNION ALL SELECT '3.5', 3, '3. OBSŁUGA ZAMÓWIEŃ I WSPARCIE PRODUKCJI', 'Analizowanie reklamacji klientów.', 'complaints_reviewed', NULL, 'weekly', 3050
    UNION ALL SELECT '3.6', 3, '3. OBSŁUGA ZAMÓWIEŃ I WSPARCIE PRODUKCJI', 'Przyjmowanie i weryfikowanie zgłoszeń reklamacyjnych.', 'complaints_reviewed', NULL, 'weekly', 3060
    UNION ALL SELECT '3.7', 3, '3. OBSŁUGA ZAMÓWIEŃ I WSPARCIE PRODUKCJI', 'Podejmowanie decyzji o zasadności reklamacji w ramach powierzonych kompetencji, w tym ich uznawanie lub odrzucanie.', 'complaints_reviewed', NULL, 'weekly', 3070
    UNION ALL SELECT '3.8', 3, '3. OBSŁUGA ZAMÓWIEŃ I WSPARCIE PRODUKCJI', 'Generowanie i przygotowywanie dokumentacji reklamacyjnej.', 'complaints_reviewed', NULL, 'weekly', 3080
    UNION ALL SELECT '4.1', 4, '4. DOKUMENTACJA TRANSPORTOWA I WYSYŁKOWA', 'Prowadzenie i przechowywanie dokumentacji dotyczącej wywozu produktów oraz wykorzystanych do ich pakowania opakowań.', NULL, 'Dokumenty wysyłek miesiąca', 'monthly', 4010
    UNION ALL SELECT '4.2', 4, '4. DOKUMENTACJA TRANSPORTOWA I WYSYŁKOWA', 'Systematyczne kompletowanie dokumentów na potrzeby kontroli i audytów.', NULL, 'Dokumenty wysyłek miesiąca', 'monthly', 4020
    UNION ALL SELECT '4.3', 4, '4. DOKUMENTACJA TRANSPORTOWA I WYSYŁKOWA', 'Sporadyczne przygotowywanie dokumentacji dla kierowców w trakcie realizacji wysyłek, w ramach zastępstwa.', 'day_shipped', NULL, 'none', 4030
    UNION ALL SELECT '4.4', 4, '4. DOKUMENTACJA TRANSPORTOWA I WYSYŁKOWA', 'Kontrola poprawności i kompletności dokumentów związanych z transportem.', NULL, 'Dokumenty wysyłek miesiąca', 'monthly', 4040
    UNION ALL SELECT '5.1', 5, '5. ZAKUPY, ZAOPATRZENIE I LOGISTYKA', 'Przyjmowanie i wysyłanie zamówień do dostawców.', 'buy_sent', NULL, 'weekly', 5010
    UNION ALL SELECT '5.2', 5, '5. ZAKUPY, ZAOPATRZENIE I LOGISTYKA', 'Organizowanie transportu surowców niezbędnych do produkcji (zakupy + pakowacz).', 'buy_sent', NULL, 'weekly', 5020
    UNION ALL SELECT '5.3', 5, '5. ZAKUPY, ZAOPATRZENIE I LOGISTYKA', 'Poszukiwanie nowych kontrahentów i dostawców.', 'suppliers_reviewed', NULL, 'quarterly', 5030
    UNION ALL SELECT '5.4', 5, '5. ZAKUPY, ZAOPATRZENIE I LOGISTYKA', 'Analiza ofert oraz porównywanie cen surowców i materiałów.', 'suppliers_reviewed', NULL, 'quarterly', 5040
    UNION ALL SELECT '5.5', 5, '5. ZAKUPY, ZAOPATRZENIE I LOGISTYKA', 'Prowadzenie negocjacji handlowych.', 'suppliers_reviewed', NULL, 'quarterly', 5050
    UNION ALL SELECT '5.6', 5, '5. ZAKUPY, ZAOPATRZENIE I LOGISTYKA', 'Bieżąca komunikacja i współpraca z polskimi dostawcami.', NULL, NULL, 'none', 5060
    UNION ALL SELECT '5.7', 5, '5. ZAKUPY, ZAOPATRZENIE I LOGISTYKA', 'Analiza cenników oraz kalkulacja marż.', 'price_path_done', NULL, 'weekly', 5070
    UNION ALL SELECT '5.8', 5, '5. ZAKUPY, ZAOPATRZENIE I LOGISTYKA', 'Monitorowanie kosztów zakupowych i cen surowców.', 'material_costs_reviewed', NULL, 'monthly', 5080
    UNION ALL SELECT '6.1', 6, '6. DOKUMENTACJA, REJESTRY I GOSPODARKA MAGAZYNOWA', 'Prowadzenie, porządkowanie i archiwizacja dokumentacji firmowej.', NULL, NULL, 'none', 6010
    UNION ALL SELECT '6.2', 6, '6. DOKUMENTACJA, REJESTRY I GOSPODARKA MAGAZYNOWA', 'Układanie rejestrów w porządku chronologicznym.', NULL, NULL, 'none', 6020
    UNION ALL SELECT '6.3', 6, '6. DOKUMENTACJA, REJESTRY I GOSPODARKA MAGAZYNOWA', 'Weryfikowanie dokumentów pod względem poprawności i kompletności.', NULL, NULL, 'none', 6030
    UNION ALL SELECT '6.4', 6, '6. DOKUMENTACJA, REJESTRY I GOSPODARKA MAGAZYNOWA', 'Sporządzanie miesięcznych zestawień stanów magazynowych na koniec miesiąca i przekazywanie ich do Asi.', NULL, 'Stany magazynowe na koniec miesiąca', 'monthly', 6040
    UNION ALL SELECT '6.5', 6, '6. DOKUMENTACJA, REJESTRY I GOSPODARKA MAGAZYNOWA', 'Przygotowywanie oraz prowadzenie dokumentacji Intrastat dla firm Samloc, Slfood sp. z o.o. oraz Atelier.', NULL, 'Intrastat miesiąca', 'monthly', 6050
    UNION ALL SELECT '6.6', 6, '6. DOKUMENTACJA, REJESTRY I GOSPODARKA MAGAZYNOWA', 'Dbanie o prawidłowy obieg i przechowywanie dokumentacji.', NULL, NULL, 'none', 6060
    UNION ALL SELECT '7.1', 7, '7. DOKUMENTACJA SANITARNA I HACCP', 'Przygotowywanie i prowadzenie dokumentacji wymaganej przez Sanepid.', 'haccp_month_reviewed', NULL, 'monthly', 7010
    UNION ALL SELECT '7.2', 7, '7. DOKUMENTACJA SANITARNA I HACCP', 'Sporządzanie i aktualizowanie dokumentacji HACCP.', 'haccp_month_reviewed', NULL, 'monthly', 7020
    UNION ALL SELECT '7.3', 7, '7. DOKUMENTACJA SANITARNA I HACCP', 'Kompletowanie dokumentów związanych z bezpieczeństwem i higieną produkcji.', 'haccp_month_reviewed', NULL, 'monthly', 7030
    UNION ALL SELECT '7.4', 7, '7. DOKUMENTACJA SANITARNA I HACCP', 'Przygotowywanie dokumentacji na potrzeby kontroli sanitarnych.', NULL, 'Teczka do audytu i kontroli', 'quarterly', 7040
    UNION ALL SELECT '8.1', 8, '8. OBSŁUGA URZĘDÓW I INSTYTUCJI ZEWNĘTRZNYCH', 'Przygotowywanie pism i korespondencji kierowanej do urzędów, gmin oraz instytucji kontrolnych (zatwierdza Asia).', NULL, NULL, 'none', 8010
    UNION ALL SELECT '8.2', 8, '8. OBSŁUGA URZĘDÓW I INSTYTUCJI ZEWNĘTRZNYCH', 'Prowadzenie bieżącej korespondencji z instytucjami zewnętrznymi (zatwierdza Asia).', NULL, NULL, 'none', 8020
    UNION ALL SELECT '8.3', 8, '8. OBSŁUGA URZĘDÓW I INSTYTUCJI ZEWNĘTRZNYCH', 'Wyjazdy do Urzędu Skarbowego oraz realizacja wpłat i innych bieżących spraw administracyjnych.', NULL, NULL, 'none', 8030
    UNION ALL SELECT '8.4', 8, '8. OBSŁUGA URZĘDÓW I INSTYTUCJI ZEWNĘTRZNYCH', 'Przygotowywanie i kompletowanie dokumentów na potrzeby instytucji oraz organów kontrolnych.', NULL, 'Teczka do audytu i kontroli', 'quarterly', 8040
    UNION ALL SELECT '9.1', 9, '9. OBSŁUGA KADROWA I ADMINISTRACJA PRACOWNICZA', 'Prowadzenie i zakładanie akt osobowych pracowników.', NULL, NULL, 'none', 9010
    UNION ALL SELECT '9.2', 9, '9. OBSŁUGA KADROWA I ADMINISTRACJA PRACOWNICZA', 'Porządkowanie i archiwizacja dokumentacji kadrowej.', NULL, NULL, 'none', 9020
    UNION ALL SELECT '9.3', 9, '9. OBSŁUGA KADROWA I ADMINISTRACJA PRACOWNICZA', 'Rozliczanie list obecności.', 'time_day_validated', NULL, 'daily', 9030
    UNION ALL SELECT '9.4', 9, '9. OBSŁUGA KADROWA I ADMINISTRACJA PRACOWNICZA', 'Przygotowywanie miesięcznych oraz rocznych zestawień obecności pracowników.', 'time_month_closed', NULL, 'monthly', 9040
    UNION ALL SELECT '9.5', 9, '9. OBSŁUGA KADROWA I ADMINISTRACJA PRACOWNICZA', 'Umawianie badań lekarskich i szkoleń pracowniczych.', NULL, 'Badania i szkolenia (terminy)', 'monthly', 9050
    UNION ALL SELECT '9.6', 9, '9. OBSŁUGA KADROWA I ADMINISTRACJA PRACOWNICZA', 'Monitorowanie terminów badań i szkoleń.', NULL, 'Badania i szkolenia (terminy)', 'monthly', 9060
    UNION ALL SELECT '9.7', 9, '9. OBSŁUGA KADROWA I ADMINISTRACJA PRACOWNICZA', 'Bieżące prowadzenie dokumentacji związanej z zatrudnieniem pracowników ; ogłoszenia i ich analiza.', NULL, NULL, 'none', 9070
    UNION ALL SELECT '10.1', 10, '10. ORGANIZACJA SERWISÓW, PRZEGLĄDÓW I USŁUG ZEWNĘTRZNYCH', 'Umawianie i koordynowanie serwisów urządzeń i maszyn.', NULL, 'Serwisy i przeglądy maszyn', 'monthly', 10010
    UNION ALL SELECT '10.2', 10, '10. ORGANIZACJA SERWISÓW, PRZEGLĄDÓW I USŁUG ZEWNĘTRZNYCH', 'Zgłaszanie i organizowanie usuwania awarii.', NULL, 'Serwisy i przeglądy maszyn', 'monthly', 10020
    UNION ALL SELECT '10.3', 10, '10. ORGANIZACJA SERWISÓW, PRZEGLĄDÓW I USŁUG ZEWNĘTRZNYCH', 'Umawianie wymaganych pomiarów i przeglądów.', NULL, 'Serwisy i przeglądy maszyn', 'monthly', 10030
    UNION ALL SELECT '10.4', 10, '10. ORGANIZACJA SERWISÓW, PRZEGLĄDÓW I USŁUG ZEWNĘTRZNYCH', 'Organizowanie transportów i kontakt z firmami świadczącymi usługi zewnętrzne.', NULL, NULL, 'none', 10040
    UNION ALL SELECT '10.5', 10, '10. ORGANIZACJA SERWISÓW, PRZEGLĄDÓW I USŁUG ZEWNĘTRZNYCH', 'Bieżąca komunikacja z serwisantami i podwykonawcami.', NULL, NULL, 'none', 10050
    UNION ALL SELECT '10.6', 10, '10. ORGANIZACJA SERWISÓW, PRZEGLĄDÓW I USŁUG ZEWNĘTRZNYCH', 'Nadzorowanie terminów realizacji zgłoszonych usług.', NULL, 'Serwisy i przeglądy maszyn', 'monthly', 10060
    UNION ALL SELECT '11.1', 11, '11. BEZPOŚREDNIE WSPARCIE PRODUKCJI', 'Wykonywanie bieżących prac pomocniczych na potrzeby produkcji.', NULL, NULL, 'none', 11010
    UNION ALL SELECT '11.2', 11, '11. BEZPOŚREDNIE WSPARCIE PRODUKCJI', 'Pomoc przy pakowaniu produktów.', NULL, NULL, 'none', 11020
    UNION ALL SELECT '11.3', 11, '11. BEZPOŚREDNIE WSPARCIE PRODUKCJI', 'Wsparcie pracowników produkcji w sytuacjach wymagających dodatkowej pomocy.', NULL, NULL, 'none', 11030
    UNION ALL SELECT '11.4', 11, '11. BEZPOŚREDNIE WSPARCIE PRODUKCJI', 'Realizowanie bieżących zadań wynikających z potrzeb organizacyjnych zakładu.', NULL, NULL, 'none', 11040
) d ON 1 = 1
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id AND s.responsibilities_pl LIKE '%Kompleksowe przygotowywanie profili produktowych w zakresie PPWR.%')
  AND NOT EXISTS (SELECT 1 FROM n_position_duty x WHERE x.id_position = p.id AND x.source = 'Zakres obowiązków — produkcja (23.11.2026)');

UPDATE n_position_sheet s JOIN n_position p ON p.id = s.id_position
SET s.mission_pl = 'Zakres obejmuje wiele obszarów działalności firmy : bieżącą obsługę administracyjną, koordynację procesów produkcyjnych, logistycznych i zakupowych oraz prowadzenie dokumentacji wymaganej przepisami prawa.',
    s.mission_fr = 'Le périmètre couvre de nombreux domaines de l''entreprise : l''administration courante, la coordination de la production, de la logistique et des achats, et la tenue des documents exigés par la loi.',
    s.responsibilities_pl = 'Wprowadzanie nowych produktów do systemu.
Tworzenie i aktualizowanie receptur produktów.
Dodawanie surowców wykorzystywanych w produkcji.
Bieżące wprowadzanie i aktualizowanie cen surowców.
Kategoryzacja surowców i produktów.
Aktualizacja danych produktowych oraz kontrola poprawności wprowadzonych informacji.
Bieżąca obsługa i aktualizacja danych niezbędnych do prawidłowego funkcjonowania systemu.
Kompleksowe przygotowywanie profili produktowych w zakresie PPWR.
Pozyskiwanie od dostawców niezbędnych informacji i dokumentacji dotyczących opakowań.
Występowanie do dostawców o dane dotyczące składu, rodzaju i parametrów opakowań.
Wprowadzanie danych do systemu PPWR i BDO.
Nadawanie numerów oraz przypisywanie odpowiednich kategorii opakowań.
Określanie i wprowadzanie wag opakowań.
Klasyfikowanie materiałów opakowaniowych według rodzaju, m.in. karton, papier, plastik, tworzywa sztuczne i inne materiały.
Kompletowanie, weryfikacja i archiwizacja dokumentacji dotyczącej produktów oraz opakowań.
Przygotowywanie dokumentacji papierowej i elektronicznej na potrzeby audytów oraz ewentualnych kontroli zewnętrznych.
Dbanie o kompletność i prawidłowość dokumentacji związanej z obowiązkami środowiskowymi firmy.
Przyjmowanie i wprowadzanie zamówień od kontrahentów do systemu.
Edytowanie, aktualizowanie i analizowanie zamówień.
Przygotowywanie dokumentów i druków niezbędnych do realizacji produkcji.
Bieżąca współpraca z produkcją w zakresie realizowanych zamówień.
Analizowanie reklamacji klientów.
Przyjmowanie i weryfikowanie zgłoszeń reklamacyjnych.
Podejmowanie decyzji o zasadności reklamacji w ramach powierzonych kompetencji, w tym ich uznawanie lub odrzucanie.
Generowanie i przygotowywanie dokumentacji reklamacyjnej.
Prowadzenie i przechowywanie dokumentacji dotyczącej wywozu produktów oraz wykorzystanych do ich pakowania opakowań.
Systematyczne kompletowanie dokumentów na potrzeby kontroli i audytów.
Sporadyczne przygotowywanie dokumentacji dla kierowców w trakcie realizacji wysyłek, w ramach zastępstwa.
Kontrola poprawności i kompletności dokumentów związanych z transportem.
Przyjmowanie i wysyłanie zamówień do dostawców.
Organizowanie transportu surowców niezbędnych do produkcji (zakupy + pakowacz).
Poszukiwanie nowych kontrahentów i dostawców.
Analiza ofert oraz porównywanie cen surowców i materiałów.
Prowadzenie negocjacji handlowych.
Bieżąca komunikacja i współpraca z polskimi dostawcami.
Analiza cenników oraz kalkulacja marż.
Monitorowanie kosztów zakupowych i cen surowców.
Prowadzenie, porządkowanie i archiwizacja dokumentacji firmowej.
Układanie rejestrów w porządku chronologicznym.
Weryfikowanie dokumentów pod względem poprawności i kompletności.
Sporządzanie miesięcznych zestawień stanów magazynowych na koniec miesiąca i przekazywanie ich do Asi.
Przygotowywanie oraz prowadzenie dokumentacji Intrastat dla firm Samloc, Slfood sp. z o.o. oraz Atelier.
Dbanie o prawidłowy obieg i przechowywanie dokumentacji.
Przygotowywanie i prowadzenie dokumentacji wymaganej przez Sanepid.
Sporządzanie i aktualizowanie dokumentacji HACCP.
Kompletowanie dokumentów związanych z bezpieczeństwem i higieną produkcji.
Przygotowywanie dokumentacji na potrzeby kontroli sanitarnych.
Przygotowywanie pism i korespondencji kierowanej do urzędów, gmin oraz instytucji kontrolnych (zatwierdza Asia).
Prowadzenie bieżącej korespondencji z instytucjami zewnętrznymi (zatwierdza Asia).
Wyjazdy do Urzędu Skarbowego oraz realizacja wpłat i innych bieżących spraw administracyjnych.
Przygotowywanie i kompletowanie dokumentów na potrzeby instytucji oraz organów kontrolnych.
Prowadzenie i zakładanie akt osobowych pracowników.
Porządkowanie i archiwizacja dokumentacji kadrowej.
Rozliczanie list obecności.
Przygotowywanie miesięcznych oraz rocznych zestawień obecności pracowników.
Umawianie badań lekarskich i szkoleń pracowniczych.
Monitorowanie terminów badań i szkoleń.
Bieżące prowadzenie dokumentacji związanej z zatrudnieniem pracowników ; ogłoszenia i ich analiza.
Umawianie i koordynowanie serwisów urządzeń i maszyn.
Zgłaszanie i organizowanie usuwania awarii.
Umawianie wymaganych pomiarów i przeglądów.
Organizowanie transportów i kontakt z firmami świadczącymi usługi zewnętrzne.
Bieżąca komunikacja z serwisantami i podwykonawcami.
Nadzorowanie terminów realizacji zgłoszonych usług.
Wykonywanie bieżących prac pomocniczych na potrzeby produkcji.
Pomoc przy pakowaniu produktów.
Wsparcie pracowników produkcji w sytuacjach wymagających dodatkowej pomocy.
Realizowanie bieżących zadań wynikających z potrzeb organizacyjnych zakładu.',
    s.responsibilities_fr = NULL, s.version = s.version + 1, s.validated_at = NULL, s.validated_by = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND s.responsibilities_pl NOT LIKE '%Kompleksowe przygotowywanie profili produktowych w zakresie PPWR.%';

INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, responsibilities_fr)
SELECT p.id, 'Zakres obejmuje wiele obszarów działalności firmy : bieżącą obsługę administracyjną, koordynację procesów produkcyjnych, logistycznych i zakupowych oraz prowadzenie dokumentacji wymaganej przepisami prawa.', 'Le périmètre couvre de nombreux domaines de l''entreprise : l''administration courante, la coordination de la production, de la logistique et des achats, et la tenue des documents exigés par la loi.', 'Wprowadzanie nowych produktów do systemu.
Tworzenie i aktualizowanie receptur produktów.
Dodawanie surowców wykorzystywanych w produkcji.
Bieżące wprowadzanie i aktualizowanie cen surowców.
Kategoryzacja surowców i produktów.
Aktualizacja danych produktowych oraz kontrola poprawności wprowadzonych informacji.
Bieżąca obsługa i aktualizacja danych niezbędnych do prawidłowego funkcjonowania systemu.
Kompleksowe przygotowywanie profili produktowych w zakresie PPWR.
Pozyskiwanie od dostawców niezbędnych informacji i dokumentacji dotyczących opakowań.
Występowanie do dostawców o dane dotyczące składu, rodzaju i parametrów opakowań.
Wprowadzanie danych do systemu PPWR i BDO.
Nadawanie numerów oraz przypisywanie odpowiednich kategorii opakowań.
Określanie i wprowadzanie wag opakowań.
Klasyfikowanie materiałów opakowaniowych według rodzaju, m.in. karton, papier, plastik, tworzywa sztuczne i inne materiały.
Kompletowanie, weryfikacja i archiwizacja dokumentacji dotyczącej produktów oraz opakowań.
Przygotowywanie dokumentacji papierowej i elektronicznej na potrzeby audytów oraz ewentualnych kontroli zewnętrznych.
Dbanie o kompletność i prawidłowość dokumentacji związanej z obowiązkami środowiskowymi firmy.
Przyjmowanie i wprowadzanie zamówień od kontrahentów do systemu.
Edytowanie, aktualizowanie i analizowanie zamówień.
Przygotowywanie dokumentów i druków niezbędnych do realizacji produkcji.
Bieżąca współpraca z produkcją w zakresie realizowanych zamówień.
Analizowanie reklamacji klientów.
Przyjmowanie i weryfikowanie zgłoszeń reklamacyjnych.
Podejmowanie decyzji o zasadności reklamacji w ramach powierzonych kompetencji, w tym ich uznawanie lub odrzucanie.
Generowanie i przygotowywanie dokumentacji reklamacyjnej.
Prowadzenie i przechowywanie dokumentacji dotyczącej wywozu produktów oraz wykorzystanych do ich pakowania opakowań.
Systematyczne kompletowanie dokumentów na potrzeby kontroli i audytów.
Sporadyczne przygotowywanie dokumentacji dla kierowców w trakcie realizacji wysyłek, w ramach zastępstwa.
Kontrola poprawności i kompletności dokumentów związanych z transportem.
Przyjmowanie i wysyłanie zamówień do dostawców.
Organizowanie transportu surowców niezbędnych do produkcji (zakupy + pakowacz).
Poszukiwanie nowych kontrahentów i dostawców.
Analiza ofert oraz porównywanie cen surowców i materiałów.
Prowadzenie negocjacji handlowych.
Bieżąca komunikacja i współpraca z polskimi dostawcami.
Analiza cenników oraz kalkulacja marż.
Monitorowanie kosztów zakupowych i cen surowców.
Prowadzenie, porządkowanie i archiwizacja dokumentacji firmowej.
Układanie rejestrów w porządku chronologicznym.
Weryfikowanie dokumentów pod względem poprawności i kompletności.
Sporządzanie miesięcznych zestawień stanów magazynowych na koniec miesiąca i przekazywanie ich do Asi.
Przygotowywanie oraz prowadzenie dokumentacji Intrastat dla firm Samloc, Slfood sp. z o.o. oraz Atelier.
Dbanie o prawidłowy obieg i przechowywanie dokumentacji.
Przygotowywanie i prowadzenie dokumentacji wymaganej przez Sanepid.
Sporządzanie i aktualizowanie dokumentacji HACCP.
Kompletowanie dokumentów związanych z bezpieczeństwem i higieną produkcji.
Przygotowywanie dokumentacji na potrzeby kontroli sanitarnych.
Przygotowywanie pism i korespondencji kierowanej do urzędów, gmin oraz instytucji kontrolnych (zatwierdza Asia).
Prowadzenie bieżącej korespondencji z instytucjami zewnętrznymi (zatwierdza Asia).
Wyjazdy do Urzędu Skarbowego oraz realizacja wpłat i innych bieżących spraw administracyjnych.
Przygotowywanie i kompletowanie dokumentów na potrzeby instytucji oraz organów kontrolnych.
Prowadzenie i zakładanie akt osobowych pracowników.
Porządkowanie i archiwizacja dokumentacji kadrowej.
Rozliczanie list obecności.
Przygotowywanie miesięcznych oraz rocznych zestawień obecności pracowników.
Umawianie badań lekarskich i szkoleń pracowniczych.
Monitorowanie terminów badań i szkoleń.
Bieżące prowadzenie dokumentacji związanej z zatrudnieniem pracowników ; ogłoszenia i ich analiza.
Umawianie i koordynowanie serwisów urządzeń i maszyn.
Zgłaszanie i organizowanie usuwania awarii.
Umawianie wymaganych pomiarów i przeglądów.
Organizowanie transportów i kontakt z firmami świadczącymi usługi zewnętrzne.
Bieżąca komunikacja z serwisantami i podwykonawcami.
Nadzorowanie terminów realizacji zgłoszonych usług.
Wykonywanie bieżących prac pomocniczych na potrzeby produkcji.
Pomoc przy pakowaniu produktów.
Wsparcie pracowników produkcji w sytuacjach wymagających dodatkowej pomocy.
Realizowanie bieżących zadań wynikających z potrzeb organizacyjnych zakładu.', NULL
FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND NOT EXISTS (SELECT 1 FROM n_position_sheet s WHERE s.id_position = p.id);
