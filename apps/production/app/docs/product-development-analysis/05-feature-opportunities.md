# Katalog funkcjonalności

Skala: 1 = niska/mała, 5 = wysoka/duża. Dla trudności, ryzyka i liczby projektów 5 oznacza większy koszt/ryzyko/rozpiętość. Źródła są statyczne; „Agent” wymaga potwierdzenia aktywnego wdrożenia.

### FEAT-001 — Zlecenie produkcyjne z raportem wykonania
**Obszar:** produkcja. **Typ funkcji:** 1, 8, 11. **Użytkownik:** planista, brygadzista, magazynier. **Problem:** plan PDF nie ma właściciela ani wyniku. **Obecny proces:** agregacja zapotrzebowania według daty. **Proponowane rozwiązanie:** z planu utworzyć zlecenie o statusie `zaplanowane → rozpoczęte → zgłoszone → zamknięte/anulowane`, z ilością planowaną, wykonaną, zaakceptowaną i odpadem. **Przebieg użytkownika:** planista tworzy zlecenie; brygadzista startuje i raportuje; planista zatwierdza odchylenie. **Reguły biznesowe:** zamknięte zlecenie nie jest edytowane, korekta jest nowym zdarzeniem z powodem. **Minimalny zakres MVP:** jedno zlecenie dla produktu/daty, ręczny raport ilości i powód odchylenia. **Możliwe rozszerzenia:** etapy, czas, terminal hali, QC. **Dane już dostępne:** plan, receptury, sekcje, pracownicy. **Brakujące dane:** wykonanie, status, odpady, identyfikator partii. **Moduły i projekty:** App/Production, Recipe, Stock. **Zależności:** FEAT-002, FEAT-003. **Ryzyka:** definicja jednostek i momentu przyjęcia na stan. **Źródła:** `ProductionListService.php`, `RequirementListService.php`, `ProductionEmployeeRepository.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 5 / 4 / 4 / 5 |
| Kompletność ERP / dopasowanie | 5 / 5 |
| Trudność / ryzyko / projekty | 4 / 3 / 2 |
| Pewność rekomendacji | 5 |

### FEAT-002 — Rezerwacja materiałów i kontrola dostępności przed startem
**Obszar:** produkcja/magazyn. **Typ funkcji:** 2, 3, 8. **Użytkownik:** planista, magazynier. **Problem:** lista zapotrzebowania nie blokuje materiału ani nie sygnalizuje braku. **Obecny proces:** PDF requirement list. **Proponowane rozwiązanie:** ekran zlecenia pokazuje dostępne, już zarezerwowane i brakujące ilości; akcja „zarezerwuj” tworzy rezerwację, nie wydanie. **Przebieg użytkownika:** planista wybiera zlecenie → system liczy BOM → magazynier rezerwuje lub zgłasza brak. **Reguły:** nie pozwala rozpocząć bez decyzji o niedoborze; zwolnienie po anulacji. **MVP:** rezerwacje na poziomie materiału i daty, alert niedoboru. **Później:** lokalizacje/partie/FEFO, automatyczna propozycja zakupu. **Dane:** receptura, requirement list, materiał. **Braki:** wiarygodny stan materiałów w aktywnym App. **Projekty:** App; Agent inventory tylko po weryfikacji. **Zależności:** FEAT-004, FEAT-001. **Ryzyko:** dwie księgi stanów. **Źródła:** `RequirementListRepository.php`, `Agent/.../MaterialInventoryController.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 5 / 4 / 4 / 5 |
| Kompletność ERP / dopasowanie | 5 / 4 |
| Trudność / ryzyko / projekty | 4 / 4 / 3 |
| Pewność rekomendacji | 5 |

### FEAT-003 — Rejestr wykonania, odpadu i jakości partii
**Obszar:** produkcja. **Typ funkcji:** 1, 3, 11, 12. **Użytkownik:** brygadzista, jakość, controller. **Problem:** nie ma danych o uzysku, odpadzie i akceptacji. **Obecny proces:** brak potwierdzonego modelu. **Proponowane rozwiązanie:** w raporcie zlecenia dodać partie wyrobu, ilości dobre/odrzucone, kod odpadu i wynik prostej kontroli jakości. **Przebieg:** brygadzista rejestruje wynik; jakość akceptuje/blokuje; magazyn przyjmuje wyłącznie zaakceptowane. **Reguły:** każda różnica ma ilość i kod; zablokowana partia nie jest dostępna do wysyłki. **MVP:** jedna kontrola pass/fail i powody odpadu. **Później:** parametry QC, CAPA, śledzenie surowiec→partia. **Dane:** produkt, receptura, zlecenie (po FEAT-001). **Braki:** partie/QC. **Projekty:** App Production/Stock. **Zależności:** FEAT-001, FEAT-004. **Ryzyko:** uzgodnienie definicji odpadu. **Źródła:** brak procesu PRD-04; `FinalProductStockService.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 5 / 4 / 3 / 5 |
| Kompletność ERP / dopasowanie | 5 / 4 |
| Trudność / ryzyko / projekty | 4 / 3 / 2 |
| Pewność rekomendacji | 5 |

### FEAT-004 — Jednolita księga ruchów magazynowych MVP
**Obszar:** magazyn. **Typ funkcji:** 10, 3, 11. **Użytkownik:** magazynier, księgowość, logistyka. **Problem:** stan wyrobów i log materiałów nie są jedną odtwarzalną historią. **Obecny proces:** `carton_stock` i dokumenty; osobne inventory w Agent. **Proponowane rozwiązanie:** nieedytowalne ruchy `PZ/WZ/MM/korekta/rezerwacja-zwolnienie`, z dokumentem źródłowym, użytkownikiem, czasem i powodem; stan jest wyliczeniem. **Przebieg:** dokument/zlecenie tworzy proponowany ruch, operator go zatwierdza. **Reguły:** korekta kompensuje ruch, nie zmienia historii; brak stanu ujemnego wymaga uprawnienia i powodu. **MVP:** wyroby gotowe, PZ z produkcji i WZ z wysyłki. **Później:** materiały, lokalizacje, wycena, skanery. **Dane:** WZ/WDT, stan kartonów, logi wysyłki. **Braki:** canonical ledger. **Projekty:** App, ewentualnie Agent migracja. **Zależności:** decyzja właściciela danych. **Ryzyko:** migracja stanów otwarcia. **Źródła:** `FinalProductStockRepository.php`, `WZRepository.php`, `Agent/.../MaterialInventoryController.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 5 / 5 / 4 / 5 |
| Kompletność ERP / dopasowanie | 5 / 5 |
| Trudność / ryzyko / projekty | 5 / 5 / 4 |
| Pewność rekomendacji | 5 |

### FEAT-005 — Kolejka operacyjna zamówień i wysyłek wymagających działania
**Obszar:** sprzedaż/wysyłka. **Typ funkcji:** 4, 5, 7. **Użytkownik:** handlowiec, logistyka. **Problem:** statusy i terminy nie tworzą osobistej listy następnych działań. **Obecny proces:** listy zamówień i logów oddzielnie. **Proponowane rozwiązanie:** widok „Do obsłużenia dziś”: zamówienie bez proformy, termin bliski/przekroczony, wysyłka z różnicą, dokument niewysłany; akcje bezpośrednie. **Przebieg:** użytkownik filtruje po roli i terminie, otwiera rekord i wykonuje akcję. **Reguły:** reguła tylko wskazuje wyjątek, nie zmienia statusu automatycznie. **MVP:** cztery reguły, link do źródła, filtry. **Później:** przypisanie właściciela/escalacja. **Dane:** order, proforma, shipping log, różnice. **Braki:** uzgodnione SLA/statusy. **Projekty:** App. **Zależności:** FEAT-006 pomaga w dokumentach. **Ryzyko:** fałszywe alarmy bez definicji terminu. **Źródła:** `OrderController.php`, `ShippingLogRepository.php`, `OrderShippingDifferenceService.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 4 / 5 / 4 / 3 |
| Kompletność ERP / dopasowanie | 3 / 5 |
| Trudność / ryzyko / projekty | 2 / 2 / 1 |
| Pewność rekomendacji | 4 |

### FEAT-006 — Konsola statusu dokumentów i pojedynczego retry
**Obszar:** dokumenty/integracje. **Typ funkcji:** 5, 9, 12. **Użytkownik:** handlowiec, operator, administrator. **Problem:** dostarczenie proform i e-maili obserwuje się przez workery/logi. **Obecny proces:** outbox + CLI worker. **Proponowane rozwiązanie:** ekran pozycji outbox ze statusem, błędem, próbami, odbiorcą i akcją „ponów” dla jednej pozycji. **Przebieg:** operator filtruje failed/pending, sprawdza kontekst, ponawia lub oznacza jako rozwiązane z powodem. **Reguły:** bez „retry all”; idempotency key i ograniczenie prób zostają zachowane. **MVP:** proforma/e-mail, read-only log i retry pojedyncze. **Później:** DLQ, alert i inne integracje. **Dane:** `ProformaOutboxRepository`, delivery worker. **Braki:** owner/statusy wszystkich kolejek. **Projekty:** App + Bin. **Zależności:** identyfikacja workerów. **Ryzyko:** duplikat wysyłki. **Źródła:** `ProformaOutboxRepository.php`, `Bin/worker_outbox.php`, `CommunicationEmailDeliveryWorker.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 4 / 3 / 4 / 5 |
| Kompletność ERP / dopasowanie | 3 / 5 |
| Trudność / ryzyko / projekty | 2 / 3 / 2 |
| Pewność rekomendacji | 5 |

### FEAT-007 — Pełny cykl zakupu PO→potwierdzenie→PZ
**Obszar:** zakupy. **Typ funkcji:** 1, 8, 10, 12. **Użytkownik:** zakupy, magazynier. **Problem:** dokument zakupu nie obsługuje decyzji, terminu ani różnicy dostawy. **Obecny proces:** App rejestruje dokument/pozycje; Agent sugeruje requisition/order/delivery. **Proponowane rozwiązanie:** potrzeba zakupowa → zamówienie dostawcy → potwierdzenie ceny/terminu → przyjęcie z różnicą. **Przebieg:** zakupowiec akceptuje propozycję, wysyła PO, magazyn przyjmuje faktyczne ilości/ceny. **Reguły:** różnica tworzy wyjątek i nie nadpisuje PO. **MVP:** ręczne PO, statusy i PZ z różnicą. **Później:** RFQ, portal dostawcy, EDI. **Dane:** materiały, dostawcy, ceny, purchase document. **Braki:** zapotrzebowanie/PO/PZ w App. **Projekty:** App; Agent do konsolidacji po weryfikacji. **Zależności:** FEAT-004. **Ryzyko:** decyzja o źródle prawdy. **Źródła:** `PurchaseService.php`, `DocumentItemRepository.php`, `Agent/.../MaterialOrderController.php`, `DeliveryController.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 5 / 4 / 4 / 5 |
| Kompletność ERP / dopasowanie | 5 / 4 |
| Trudność / ryzyko / projekty | 4 / 4 / 3 |
| Pewność rekomendacji | 5 |

### FEAT-008 — Propozycja zakupu z niedoborów planu
**Obszar:** zakupy/produkcja. **Typ funkcji:** 2, 7, 8. **Użytkownik:** planista, zakupy. **Problem:** requirement list nie przekłada się na zakup. **Obecny proces:** użytkownik porównuje plan i stany ręcznie. **Proponowane rozwiązanie:** lista materiałów z `zapotrzebowanie − dostępne − rezerwacje`, pogrupowana według dostawcy, z akcją „utwórz potrzeby zakupowe”. **Przebieg:** planista sprawdza niedobory → zatwierdza potrzeby → zakupy tworzą PO. **Reguły:** system proponuje, człowiek zatwierdza; uwzględnia minimalną ilość, gdy dane istnieją. **MVP:** niedobór i ręczne zatwierdzenie. **Później:** lead time, MOQ, ceny dostawców. **Dane:** BOM, plan, materiał. **Braki:** wiarygodny stan/rezerwacje. **Projekty:** App. **Zależności:** FEAT-002, FEAT-007. **Ryzyko:** błędna propozycja przy niepełnym stanie. **Źródła:** `RequirementListService.php`, `MaterialSupplierService.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 5 / 4 / 4 / 4 |
| Kompletność ERP / dopasowanie | 4 / 5 |
| Trudność / ryzyko / projekty | 3 / 3 / 2 |
| Pewność rekomendacji | 4 |

### FEAT-009 — Wersjonowanie receptury i snapshot kosztu
**Obszar:** receptury/finanse. **Typ funkcji:** 3, 6, 11. **Użytkownik:** technolog, controller. **Problem:** późniejsza zmiana receptury/ceny może zmienić wyjaśnienie starego wyniku. **Obecny proces:** przeliczenie/spłaszczenie bieżących zależności. **Proponowane rozwiązanie:** wersja robocza/publikowana z datą obowiązywania oraz snapshot BOM i kosztu przy zatwierdzeniu zlecenia/zamknięciu okresu. **Przebieg:** technolog kopiuje wersję, zatwierdza; raport pokazuje wersję źródłową. **Reguły:** publikowanej wersji nie edytuje się; nakładające się daty są blokowane. **MVP:** wersja i snapshot przy zamknięciu okresu. **Później:** workflow akceptacji, pełny lineage marży. **Dane:** receptury, ceny materiałów, koszty. **Braki:** version/snapshot. **Projekty:** App Recipe/Finances. **Zależności:** decyzja polityki dat. **Ryzyko:** jednorazowa migracja danych. **Źródła:** `FlattenedRecipeService.php`, `RecipeCostRecalculationService.php`, `CostCalculatorService.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 4 / 3 / 3 / 5 |
| Kompletność ERP / dopasowanie | 5 / 5 |
| Trudność / ryzyko / projekty | 4 / 4 / 2 |
| Pewność rekomendacji | 5 |

### FEAT-010 — Zamknięcie miesiąca kosztowego i odchylenia
**Obszar:** finanse. **Typ funkcji:** 1, 6, 11. **Użytkownik:** controller, właściciel. **Problem:** kalkulator jest rozbudowany, lecz nie ma potwierdzonego zamknięcia i sygnałów odchylenia. **Obecny proces:** koszty miesięczne/alokacje/analizy. **Proponowane rozwiązanie:** status okresu `otwarty → do weryfikacji → zamknięty`, kontrola kompletności i lista odchyleń cena/zużycie/marża. **Przebieg:** controller uzupełnia dane, rozwiązuje wyjątki, zamyka i eksportuje. **Reguły:** zamknięty okres zmieniany tylko korektą z powodem. **MVP:** checklist + blokada zmian + trzy progi odchyleń. **Później:** budżet/cash flow. **Dane:** cost month, media, pracownicy, logistyka, marże. **Braki:** definicje progów i owner okresu. **Projekty:** App Finances/Production. **Zależności:** FEAT-009 zwiększa wiarygodność. **Ryzyko:** niekompletne dane historyczne. **Źródła:** `CostMonthService.php`, `CostCalculatorService.php`, `SectionFinanceDashboardService.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 4 / 2 / 3 / 4 |
| Kompletność ERP / dopasowanie | 4 / 5 |
| Trudność / ryzyko / projekty | 3 / 3 / 2 |
| Pewność rekomendacji | 4 |

### FEAT-011 — SLA i eskalacja reklamacji
**Obszar:** reklamacje. **Typ funkcji:** 5, 11, 12. **Użytkownik:** obsługa, kierownik. **Problem:** sprawa ma dokumenty, lecz nie widoczny termin/właściciela. **Obecny proces:** utworzenie, dokumenty, aktualizacja do klienta. **Proponowane rozwiązanie:** statusy, odpowiedzialny, termin odpowiedzi i alerty `dziś/przeterminowane`; decyzja o kompensacji z powodem. **Przebieg:** zgłoszenie otrzymuje właściciela, operator prowadzi status, kierownik widzi wyjątki. **Reguły:** zamknięcie wymaga decyzji i komunikatu; zmiana terminu wymaga powodu. **MVP:** właściciel, termin, cztery statusy, alert listowy. **Później:** portal klienta, analiza przyczyn. **Dane:** reklamacja, log wysyłki, e-mail. **Braki:** SLA i role. **Projekty:** App Client/Shipping/Communication. **Zależności:** FEAT-005. **Ryzyko:** ustalenie polityki. **Źródła:** `ComplaintService.php`, `ComplaintDocumentService.php`, `ComplaintShipmentLogRepository.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 4 / 3 / 3 / 4 |
| Kompletność ERP / dopasowanie | 3 / 5 |
| Trudność / ryzyko / projekty | 2 / 2 / 2 |
| Pewność rekomendacji | 4 |

### FEAT-012 — Rejestr różnic wysyłkowych z decyzją
**Obszar:** wysyłka. **Typ funkcji:** 1, 3, 12. **Użytkownik:** logistyka, handlowiec, księgowość. **Problem:** różnica jest dokumentowana, ale nie jest domkniętą kolejką decyzji. **Obecny proces:** order shipping difference i PDF. **Proponowane rozwiązanie:** status `otwarta → wyjaśniana → rozliczona`, właściciel oraz akcje: dosyłka, korekta dokumentu, anulowanie z powodem. **Przebieg:** logistyka wybiera decyzję, system pokazuje powiązane zamówienie/dokumenty. **Reguły:** brak automatycznej korekty finansowej; decyzja jest audytowana. **MVP:** rejestr i trzy decyzje z linkami. **Później:** automatyczne utworzenie reklamacji/korekty. **Dane:** różnica, WZ/WDT, zamówienie. **Braki:** decyzje i statusy. **Projekty:** App Shipping/Document/Client. **Zależności:** FEAT-004. **Ryzyko:** polityka księgowa. **Źródła:** `OrderShippingDifferenceService.php`, `DifferenceController.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 4 / 3 / 3 / 5 |
| Kompletność ERP / dopasowanie | 4 / 5 |
| Trudność / ryzyko / projekty | 2 / 2 / 2 |
| Pewność rekomendacji | 4 |

### FEAT-013 — Kompletny pakiet dokumentów procesu
**Obszar:** dokumenty. **Typ funkcji:** 4, 8, 9. **Użytkownik:** handlowiec, logistyka, księgowość. **Problem:** dokumenty są generowane w wielu modułach, a kompletność trzeba sprawdzać ręcznie. **Obecny proces:** osobne serwisy PDF i archiwum. **Proponowane rozwiązanie:** zakładka przy zamówieniu/wysyłce z listą wymaganych dokumentów, stanem wygenerowania/wysyłki i linkiem do archiwum. **Przebieg:** użytkownik otwiera proces, widzi braki, generuje dozwolony dokument. **Reguły:** zestaw zależy od typu/destynacji wysyłki; nie usuwa źródłowych PDF. **MVP:** WZ/WDT/CMR/proforma/faktura oraz linki. **Później:** wersjonowanie, podpis. **Dane:** dokument repositories, archive, shipping. **Braki:** konfiguracja wymagalności. **Projekty:** App Document/Shipping. **Zależności:** FEAT-006. **Ryzyko:** dokumenty mogą mieć różne identyfikatory. **Źródła:** `ShippingDocumentService.php`, `DocumentArchiveController.php`, `WDTService.php`, `CMRService.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 4 / 4 / 3 / 4 |
| Kompletność ERP / dopasowanie | 4 / 5 |
| Trudność / ryzyko / projekty | 2 / 2 / 2 |
| Pewność rekomendacji | 4 |

### FEAT-014 — Dashboard KPI z jakością danych i przejściem do źródła
**Obszar:** raportowanie. **Typ funkcji:** 6, 7, 4. **Użytkownik:** właściciel, controller, kierownicy. **Problem:** raporty istnieją, ale nie prowadzą konsekwentnie do działania ani nie ostrzegają o niepełnych danych. **Obecny proces:** osobne analizy sprzedaży/marży/sekcji. **Proponowane rozwiązanie:** role-based dashboard: terminowość, wyjątki zamówień, wydajność/odpad po FEAT-003, stany krytyczne, marża; każda karta ma okres, definicję, jakość danych i drill-down. **Reguły:** dane bez zamknięcia okresu oznaczone „szacunek”. **MVP:** 5 KPI i linki źródłowe. **Później:** cele/trendy/prognoza. **Dane:** istniejące repositories analityczne. **Braki:** quality flags, dane wykonania. **Projekty:** App Finances/Dashboard. **Zależności:** FEAT-010 dla finalnych kosztów. **Ryzyko:** błędna interpretacja niespójnych źródeł. **Źródła:** `SalesAnalyticsRepository.php`, `ProductMarginRepository.php`, `SectionFinanceDashboardRepository.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 4 / 4 / 2 / 3 |
| Kompletność ERP / dopasowanie | 4 / 4 |
| Trudność / ryzyko / projekty | 3 / 3 / 1 |
| Pewność rekomendacji | 4 |

### FEAT-015 — Operacyjna samoobsługa: słowniki powodów, alerty i korekty
**Obszar:** administracja. **Typ funkcji:** 9, 11, 12. **Użytkownik:** administrator, kierownicy. **Problem:** statusy/kody powodów, retry i naprawy są rozproszone w migracjach/CLI. **Obecny proces:** częściowe CRUD ustawień, workery i SQL/migracje. **Proponowane rozwiązanie:** admin utrzymuje aktywne kody powodów i progi alertów; operator wykonuje wyłącznie dozwolone korekty z uzasadnieniem i audytem. **Przebieg:** administrator publikuje słownik; użytkownik wybiera kod na formularzu; audyt pokazuje kto/co/dlaczego. **Reguły:** niedozwolone usunięcie użytego kodu; krytyczne zmiany wymagają uprawnienia. **MVP:** reason codes dla anulacji/różnicy/odpadu i read-only audit. **Później:** szablony e-mail i reguły. **Dane:** aliasy/settings/changelog. **Braki:** centralny audit/RBAC. **Projekty:** App; Agent roles po potwierdzeniu. **Zależności:** uprawnienia procesowe. **Ryzyko:** zbyt szeroka edycja. **Źródła:** `SettingsRoutes.php`, `OrderChangelogRepository.php`, migracje, `Bin/*`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 4 / 3 / 3 / 4 |
| Kompletność ERP / dopasowanie | 4 / 4 |
| Trudność / ryzyko / projekty | 3 / 3 / 2 |
| Pewność rekomendacji | 4 |

### FEAT-016 — Kompetencja jako warunek przydziału do produkcji
**Obszar:** pracownicy/produkcja. **Typ funkcji:** 8, 11. **Użytkownik:** kierownik produkcji. **Problem:** system przechowuje kompetencje receptur, lecz nie sprawdza ich przy wykonaniu. **Obecny proces:** `RecipeCompetencyService` i pracownicy produkcyjni. **Proponowane rozwiązanie:** przy przypisaniu osoby do zlecenia ostrzec/blokować brak wymaganej kompetencji; pokazać wygasające uprawnienia. **Przebieg:** kierownik wybiera zlecenie, system filtruje kwalifikowanych pracowników. **Reguły:** ostrzeżenie może być nadpisane tylko przez uprawnionego z powodem. **MVP:** check kompetencji przy przypisaniu. **Później:** grafik, szkolenia, RCP. **Dane:** recipe competencies, production employee. **Braki:** przypisanie do zlecenia i ważność kompetencji. **Projekty:** App Production/Recipe; Agent HR do weryfikacji. **Zależności:** FEAT-001. **Ryzyko:** niepełna kartoteka kompetencji. **Źródła:** `RecipeCompetencyService.php`, `ProductionEmployeeRepository.php`, `Agent/.../EmployeeCompetencyConnectionController.php`.

| Kryterium | Ocena 1–5 |
|---|---:|
| Wartość / częstotliwość / czas / błędy | 3 / 3 / 2 / 4 |
| Kompletność ERP / dopasowanie | 3 / 4 |
| Trudność / ryzyko / projekty | 2 / 2 / 2 |
| Pewność rekomendacji | 4 |
