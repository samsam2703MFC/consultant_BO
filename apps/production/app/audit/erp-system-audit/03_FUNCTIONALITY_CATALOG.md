# Katalog funkcjonalności

Legenda: `W` = panel web App, `A` = kod Agent bez potwierdzonego uruchomienia. Szczegółowe oceny są w macierzy.

| ID | Domena | Funkcja | Aktor / aplikacja | Główne dowody | Poziom | Ograniczenie / rekomendacja |
|---|---|---|---|---|---:|---|
| CFG-01 | Konfiguracja | Użytkownicy panelu CRUD | Administrator / W | `UserRoutes.php`, `UserController.php`, tabela `user` | F2 | Hasła jawne, brak dojrzałych ról; wymienić auth |
| CFG-02 | Konfiguracja | Role i uprawnienia | Admin / A | `Repositories/Admin/*`, `Services/Auth/PermissionService.php` | F1 | Brak aktywnego wejścia i spójności z panelem |
| CFG-03 | Konfiguracja | Języki i aliasy | Admin / W+A | `Settings/*Routes`, `supported_language`, alias repositories | F3 | Migracyjny model tłumaczeń; brak testów |
| MD-01 | Produkt | Produkty CRUD i wyszukiwanie | ERP / W | `ProductRoutes.php` (13 tras), Product service/repository/views | F3 | Brak testów i audytu zmian |
| MD-02 | Produkt | Kategorie produktów | ERP / W | `CategoryRoutes.php`, `category_product` | F3 | Usunięty/backup widok sugeruje regresję UI |
| MD-03 | Surowce | Materiały CRUD | ERP / W | `MaterialRoutes.php` (13), `raw_material` | F3 | Brak partii/pełnego stock ledger |
| MD-04 | Surowce | Kategorie, alergeny, wartości odżywcze | ERP / W | odpowiednie routes/controllers/services | F3 | Pokrycie testami 0 |
| MD-05 | Dostawcy | Kartoteka dostawców | ERP / W | Supplier routes/repository/views | F3 | UI sygnalizuje brak obsługi nieaktywnych |
| REC-01 | Receptury | Receptury CRUD | Technolog / W | Recipe routes (19), services, repository, views | F3 | Operacje delete+insert, ograniczone transakcje |
| REC-02 | Receptury | Półprodukty i kompozycje | Technolog / W | SemiProduct/Composition routes | F3 | Brak E2E i wersjonowania receptury |
| REC-03 | Receptury | Kroki przygotowania | Technolog / W | `PreparationStep*`, migracja 021 | F2 | Nowe/niezatwierdzone zmiany, schema niezweryfikowana |
| REC-04 | Receptury | Kompetencje receptur | HR/Technolog / W | RecipeCompetency routes, migracja 023 | F2 | Nowy moduł bez testu DB/UI |
| REC-05 | Receptury | Spłaszczenie i przeliczenie kosztów | ERP / W | akcje settings, FlattenedRecipe services | F2 | Komentarz TODO o docelowym zapisie; operacje masowe |
| PRI-01 | Ceny | Ceny surowców i historia | ERP / W | PriceRoutes, changelog repository | F3 | Wiarygodność historii niepotwierdzona |
| PRI-02 | Ceny | Cennik ogólny i klienta | Handlowiec / W | PriceListRoutes (14), CSV/PDF/JSON | F4 | Dobre UI/eksporty, ale brak testów i historii promocji |
| ORD-01 | Sprzedaż | Klienci CRUD | Handlowiec / W | ClientRoutes (15), customer | F3 | Brak walidacji tenantowej w routerze |
| ORD-02 | Sprzedaż | Sieci franczyzowe/onboarding | Admin / W | FranchiseNetworkRoutes (10), onboarding token | F2 | Integracja bez testów |
| ORD-03 | Sprzedaż | Zamówienia klienta | Handlowiec / W | OrderRoutes (17), customer_order/product | F3 | Częściowo zakomentowana stara ścieżka; supplement nowy |
| ORD-04 | Sprzedaż | Historia zmian zamówienia | Handlowiec / W | migracje 025/027, changelog repo/view | F2 | Nowe pliki, brak pewności wdrożenia migracji |
| SAL-01 | Sprzedaż | Transakcje POS | Sklep / A | Transaction repositories/controllers | F1 | Brak aktywnego front controllera |
| SAL-02 | Sprzedaż | Rabaty/promocody | Sklep / A | Discount/PromoCodeController | F1 | Brak potwierdzonego procesu i UI panelu |
| SAL-03 | Sprzedaż | Zwroty/anulacje/korekty POS | Sklep / A | ślady faktur/korekt | F1 | Brak pełnego, testowalnego procesu |
| DOC-01 | Dokumenty | Proforma | Handlowiec / W | routes, service, products, outbox | F3 | Retry worker bez dashboardu operacyjnego |
| DOC-02 | Dokumenty | Faktury i pozycje | Księgowość / W+A | Invoice routes/services/tables | F3 | Dwie implementacje; zgodność niepotwierdzona |
| DOC-03 | Dokumenty | Korekty faktur | Księgowość / A | CorrectionController, Peppol service | F1 | Niepotwierdzony entrypoint |
| DOC-04 | Dokumenty | WDT/WZ/CMR | Magazyn/wysyłka / W | routes, repos, PDF | F3 | Brak centralnego ledgeru i testów sum |
| SHP-01 | Wysyłka | Planowanie i realizacja wysyłki | Logistyka / W | ShippingRoutes/Documents, logs | F3 | Statusy i retry rozproszone |
| SHP-02 | Reklamacje | Reklamacje i dokumenty | Obsługa / W+A | 15 tras W, complaint services/outbox | F3 | Dwie warstwy, brak E2E kompensacji |
| PRD-01 | Produkcja | Plan/lista produkcyjna | Planista / W | ProductionRoutes, repositories, PDF | F3 | Lista wyliczana, nie zlecenie ze stanami |
| PRD-02 | Produkcja | Zapotrzebowanie materiałowe | Planista / W | RequirementListRepository/PDF | F2 | Brak rezerwacji i wydania surowców |
| PRD-03 | Produkcja | Raport wykonania | Brygadzista | brak potwierdzonego modelu | F0 | Dodać zlecenie i zdarzenia wykonania |
| PRD-04 | Produkcja | Odpady/braki/jakość/partie | Produkcja/Jakość | brak kompletnego procesu | F0 | Wymagany model traceability/QC |
| INV-01 | Magazyn | Stan wyrobów (kartony) | Magazyn / W | FinalProductStock routes, carton_stock | F2 | Stan jako rekordy; brak pełnych ruchów |
| INV-02 | Magazyn | Stan i log ruchu materiałów | Sklep / A | material_inventory/log/reasons | F1 | Kod Agent niepotwierdzony |
| INV-03 | Magazyn | Przyjęcia/wydania/przesunięcia | Magazyn | dokumenty częściowe | F1 | Brak jednego procesu i uzgodnienia |
| INV-04 | Magazyn | Rezerwacje, partie, FEFO, ważność | Magazyn | brak | F0 | Nowy moduł magazynowy |
| INV-05 | Magazyn | Inwentaryzacja i korekty | Sklep / A | Stocktaking controller/repos | F1 | Niepotwierdzony entrypoint, brak panelu W |
| PUR-01 | Zakupy | Dokument zakupu | Zakupy / W | PurchaseRoutes, purchase_document/item | F2 | 4 trasy, ograniczony workflow |
| PUR-02 | Zakupy | Zapotrzebowanie i zamówienie dostawcy | Zakupy / A | requisition/order/delivery services | F1 | Niepotwierdzone wdrożenie |
| PUR-03 | Zakupy | Ocena dostawców/reklamacje dostaw | Zakupy | pojedyncze ślady complaint | F0 | Brak KPI terminowości i jakości |
| HR-01 | HR | Pracownicy, role, kompetencje | HR / A+W | liczne Agent controllers; W production employees | F2 | Rozdzielone modele i auth |
| HR-02 | HR | Grafiki i zadania | Kierownik / A | Schedule/Task services | F1 | Brak wejścia i testów |
| HR-03 | HR | RCP/wejścia/wyjścia/nieobecności | HR | brak wiarygodnego procesu | F0 | Nowy proces czasu pracy |
| COST-01 | Koszty | Koszty miesięczne, media, praca, logistyka | Controller / W | 27 tras CostRoutes, cost_* tables | F4 | Rozbudowane UI, lecz dane/testy niezweryfikowane |
| ANA-01 | Analityka | Sprzedaż i trend | Zarząd / W | SalesAnalytics repo/service/view | F2 | Źródłem WDT, nie pełne POS; korekty nieudowodnione |
| ANA-02 | Analityka | Marża/kategoria/sekcja | Zarząd / W | ProductMargin, SectionDashboard, SalesByDepartment | F2 | Nowe zmiany; ryzyko bieżących kosztów |
| ANA-03 | Analityka | Raporty fiskalne POS PL/BE | Sklep / A | country report services | F1 | Bogaty kod bez potwierdzonego deployu |
| INT-01 | Integracje | Webhook inbox/outbox i retry | System/operator | repositories, workers | F2 | Brak panelu stanu, DLQ i harmonogramu |
| INT-02 | Integracje | S3/R2/e-mail/Peppol | System | services Agent/App | F2 | Brak bezpiecznych testów i panelu konfiguracji |
| OBS-01 | Operacje | Logi, health, audit trail | Administrator | logi fragmentaryczne/changelog | F0 | Brak centralnego monitoringu i health checks |

