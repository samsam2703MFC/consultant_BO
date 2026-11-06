# Mapa systemu

## Zakres i pewność

Analiza jest statyczna: przejrzano aktywny panel `App`, trasowanie, kontrolery, serwisy, repozytoria, widoki, migracje, workery i kod `Agent/api_app_dir`. Nie uruchomiono aplikacji ani bazy. **Potwierdzone** oznacza widoczne w panelu/trasy `App`; **sugerowane** oznacza kod `Agent`, którego aktywnego entrypointu nie potwierdzono.

## Projekty i odpowiedzialności

| Projekt/obszar | Rola | Stan |
|---|---|---|
| `App` + `public/index.php` | panel ERP: operacje, dokumenty, produkcja, koszty | potwierdzony |
| `App/Core/Routes` | trasy panelu i ograniczone API wewnętrzne | potwierdzony |
| `Agent/api_app_dir` | API sklepów, POS, zakupy, HR, magazyn materiałów | szeroki kod, uruchomienie niepotwierdzone |
| `Bin` | kolektory zamówień, kolejki dokumentów/e-maili, retry wysyłek, monitoring | potwierdzony jako kod CLI; harmonogram niepotwierdzony |

## Moduły, encje i ekrany

| Moduł | Główne encje/dane | Najważniejsze działania użytkownika | Dowody |
|---|---|---|---|
| Kartoteki | produkt, kategoria, surowiec, alergen, dostawca | CRUD, wyszukiwanie, eksport | `App/Core/Routes/{Product,Material,Supplier}/*Routes.php`, `App/Views/{product,material}` |
| Receptury | receptura, składnik, półprodukt, kompozycja, krok przygotowania, kompetencja | budowa receptury, przeliczenie/spłaszczenie, karta techniczna | `RecipeRoutes.php`, `RecipeService.php`, `FlattenedRecipeService.php` |
| Ceny i klienci | klient, cennik, cena materiału, snapshot franczyzy | klient, zamówienie, cennik PDF/CSV/JSON, synchronizacja | `ClientRoutes.php`, `PriceListRoutes.php`, `PricelistSyncService.php` |
| Zamówienia | `customer_order`, pozycje, supplement, changelog | tworzenie/edycja/archiwizacja, proforma | `OrderRoutes.php`, `OrderController.php`, `OrderChangelogRepository.php` |
| Produkcja | lista produkcyjna, zapotrzebowanie, sekcja, pracownik produkcji | podgląd/druk planu i zapotrzebowania | `ProductionListController.php`, `RequirementListService.php` |
| Magazyn i wysyłka | stan kartonów, paleta, WZ/WDT/CMR, log wysyłki, różnica | stan, dokumenty, wysyłka, reklamacja | `FinalProductRoutes.php`, `ShippingRoutes.php`, `WZService.php` |
| Zakupy | dokument zakupu i pozycje | rejestr dokumentu zakupu | `PurchaseRoutes.php`, `PurchaseService.php` |
| Dokumenty | proforma, faktura, WZ, WDT, CMR, PDF, archiwum | generowanie PDF, wysłanie, porównanie proform | `Document/*Routes.php`, `ProformaService.php`, `DocumentArchiveController.php` |
| Koszty i analityka | miesiąc kosztowy, media, praca, logistyka, marża, sprzedaż | kalkulator kosztów, marże, dashboard sekcji | `CostRoutes.php`, `CostCalculatorService.php`, `App/Repositories/Analysis/*` |
| Reklamacje | reklamacja, pozycje, załączniki, przesyłka, kompensacja | obsługa, dokumenty, komunikat do klienta | `ComplaintRoutes.php`, `ComplaintService.php` |
| Komunikacja/integracje | konto e-mail, szablon, outbox, urządzenie API, log | synchronizacja e-maili, wysyłka, retry | `CommunicationRoutes.php`, `CommunicationEmailDeliveryWorker.php`, `Bin/*worker*.php` |
| Pracownicy | pracownik produkcji, sekcja; kompetencje/grafiki/zadania w Agent | przypisanie pracownika/sekcji | `ProductionEmployeeRoutes.php`; `Agent/.../Employee/*` |
| Administracja | użytkownik, język, aliasy, ustawienia | CRUD słowników i użytkowników | `UserRoutes.php`, `SettingsRoutes.php` |

## Role biznesowe

Potwierdzone przez nazwy ekranów/tras: administrator, handlowiec, technolog, planista produkcji, pracownik/kierownik produkcji, magazynier/logistyka, zakupy, księgowość, obsługa reklamacji, właściciel/controlling, klient/franczyzobiorca. Role sklepu, dostawcy i HR są **silnie sugerowane** przez kontrolery Agent, ale ich panel i wdrożenie nie są potwierdzone.

## Główne przepływy i zależności

`klient → zamówienie → proforma → lista produkcyjna/zapotrzebowanie → wysyłka → WZ/WDT/CMR`; `surowiec + receptura → koszt → cena/cennik → marża`; `miesięczne koszty + sprzedaż/WDT → analityka`. Istnieje również `reklamacja → dokumenty/wysyłka → komunikacja`.

Najważniejsze rozłączenia: plan produkcji nie ma potwierdzonego zlecenia wykonawczego; stany wyrobów (`carton_stock`) i log materiałów w Agent nie tworzą jednej potwierdzonej księgi ruchów; zakup App nie domyka cyklu PO/PZ; dane kosztowe i raporty nie mają potwierdzonego historycznego snapshotu.

## Dokumenty, raporty, powiadomienia, integracje

- Dokumenty: proforma, faktura, WZ, WDT, CMR, dokumenty reklamacyjne, lista kompletacyjna/produkcyjna/zapotrzebowania, karta techniczna i cenniki PDF.
- Raporty: sprzedaż, marża produktu/kategorii, rentowność klienta, analiza kosztów, dashboard sekcji, różnice wysyłki i audyt masy produktu (`App/Views/finances`, `App/Repositories/Analysis`).
- Powiadomienia: e-mail dla proform i reklamacji, IMAP sync, Discord cron oraz kolejki/outbox; brak potwierdzonej skrzynki zadań dla operatora.
- Integracje: urządzenia API, onboarding franczyzy, synchronizacja cenników, e-mail/SMTP, Peppol/S3 głównie w Agent. Każda integracja wymaga potwierdzenia runtime i właściciela procesu.
