# Mapa procesów użytkownika

| Proces | Role i cel | Przebieg / statusy | Dokumenty i komunikacja | Zakończenie oraz luki |
|---|---|---|---|---|
| Zamówienie B2B | Handlowiec: przyjąć zlecenie klienta | klient → nowe/edytowane zamówienie → pozycje → archiwizacja; changelog/supplement są obecne | proforma PDF i outbox/e-mail | **Luka:** brak potwierdzonej, wymuszonej maszyny stanów, częściowej realizacji, anulacji po dokumentach i kolejki „wymaga działania”. Dowody: `OrderController.php`, `OrderService.php`, migracje 025, 029, 033. |
| Plan produkcji | Planista: przekształcić popyt w plan | data wysyłki/zamówienia → agregacja listy produkcyjnej → PDF → lista zapotrzebowania | PDF listy produkcyjnej i zapotrzebowania | Proces urywa się po planie: brak zlecenia, startu, przydziału, raportu wykonania, odpadu, QC i zamknięcia. `ProductionListService.php`, `RequirementListService.php`. |
| Receptura i koszt | Technolog/controller: utrzymać produkt i cenę | materiał/cena → receptura/półprodukt/krok → spłaszczenie → kalkulacja kosztu → cena/marża | karta techniczna, CSV/PDF cennika | Brak wersji obowiązującej od daty i snapshotu użytego przy sprzedaży/produkcji; trudno wyjaśnić historyczną marżę. `FlattenedRecipeService.php`, `RecipeCostRecalculationService.php`. |
| Wysyłka | Logistyka: wysłać zamówione wyroby | log wysyłki/palety → komplet dokumentów → WZ/WDT/CMR → wysłanie do franczyzy | WZ, WDT, CMR, pakiet PDF | **Luka:** różnice i statusy są rozproszone, nie ma potwierdzonego pickingu ze skanem, rezerwacji ani jednego potwierdzenia wydania. `ShippingService.php`, `ShippingLogRepository.php`, `OrderShippingDifferenceService.php`. |
| Magazyn wyrobów | Magazynier: znać i zmienić stan | podgląd `carton_stock`, dokumenty wysyłkowe/reklamacyjne | dokument stanu PDF | Brak pełnego procesu PZ/WZ/MM/korekta/inwentaryzacja i śledzenia partii/ważności. Agent ma osobny model materiałów, bez potwierdzonego UI. `FinalProductStockRepository.php`, `Agent/.../MaterialInventoryController.php`. |
| Zakup | Zakupy: zarejestrować zakup | dokument zakupu → pozycje | brak potwierdzonego PZ/PO w App | Brak powiązania zapotrzebowania z zamówieniem do dostawcy, potwierdzeniem terminu, przyjęciem i różnicą. `PurchaseController.php`, `PurchaseService.php`; szerszy przepływ tylko sugerowany w Agent. |
| Reklamacja | Obsługa: rozwiązać problem klienta | zgłoszenie → pozycje/załączniki → wysyłka/kompensacja → komunikat | dokumenty reklamacyjne i e-mail | Brak potwierdzonego SLA, właściciela, statusów eskalacji i spójnej korekty stanu/finansów. `ComplaintService.php`, `ComplaintDocumentService.php`. |
| Koszty i zarządzanie | Controller/właściciel: ocenić wynik | okres kosztowy → koszty stałe, media, praca, logistyka → alokacje → marża/dashboard | analizy i dashboard | Raporty prowadzą do obserwacji, lecz nie do zadań/korekt; źródłem sprzedaży jest co najmniej WDT, nie potwierdzony pełny POS. `CostCalculatorService.php`, `SalesAnalyticsRepository.php`. |
| Komunikacja dokumentowa | Handlowiec/administracja: wysłać i śledzić dokument | wygenerowanie proformy → outbox → worker SMTP → retry | e-mail i załącznik | Praca operatora prawdopodobnie wymaga logów/CLI: brak potwierdzonego panelu statusu, retry pojedynczej pozycji i alertu błędu. `ProformaOutboxRepository.php`, `Bin/worker_outbox.php`, `CommunicationEmailDeliveryWorker.php`. |

### Praca prawdopodobnie poza aplikacją

| Ocena | Obserwacja | Potencjalne obejście |
|---|---|---|
| Potwierdzone | brak modelu wykonania produkcji, odpadu i jakości | papier/Excel/ustne przekazanie na hali |
| Potwierdzone | brak pełnej księgi i uzgodnienia magazynu | Excel/SQL do uzgadniania stanów |
| Silnie sugerowane | worker/retry bez panelu operacyjnego | CLI, logi lub kontakt z programistą |
| Silnie sugerowane | kody powodów/statusy wymagają migracji/kodu | zgłoszenie do administratora/programisty |
| Hipoteza do wywiadu | brak SLA reklamacji i potwierdzeń dostaw | e-mail/telefon/komunikator |
