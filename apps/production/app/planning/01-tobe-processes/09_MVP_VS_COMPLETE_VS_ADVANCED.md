# MVP vs Complete vs Advanced

## Zasada podziału

- **MVP** zapewnia bezpieczny, audytowalny przebieg podstawowych operacji bez ręcznego omijania systemu.
- **Complete** zamyka typowe warianty operacyjne, automatyzuje kontrole i zwiększa wygodę pracy.
- **Advanced** optymalizuje skalę, prognozowanie i decyzje; nie jest warunkiem uruchomienia ERP.

| Obszar | MVP | Complete | Advanced |
|---|---|---|---|
| Organizacja i uprawnienia | użytkownicy, role, jednostki organizacyjne, jawne uprawnienia do operacji krytycznych, rejestr logowań | zakresy danych per zakład/magazyn, zastępstwa, okresowe przeglądy dostępów | adaptacyjne polityki dostępu, automatyczna analiza konfliktów SoD |
| Kartoteki | produkty, jednostki, kontrahenci, podatki, magazyny, status aktywności, walidacja unikalności | warianty produktu, opakowania, klasyfikacje, masowe kontrolowane zmiany | zarządzanie jakością danych i automatyczne wykrywanie duplikatów |
| Receptury | wersje receptur, składniki, wydajność, okres obowiązywania, zatwierdzenie, niezmienność użytej wersji | zamienniki, półprodukty, wielopoziomowe receptury, planowane straty | optymalizacja składu i symulacja wariantów |
| Ceny i promocje | cennik bazowy, okres obowiązywania, ręczny rabat z uprawnieniem i śladem audytowym | cenniki kanałowe/klienckie, reguły promocji i limity marży | dynamic pricing i automatyczna optymalizacja promocji |
| Zamówienia B2B | rejestracja, potwierdzenie, rezerwacja, częściowa realizacja, anulowanie i historia statusów | backorder, limity kredytowe, harmonogramy dostaw, portal klienta | automatyczne obietnice dostawy i scoring ryzyka klienta |
| Sprzedaż detaliczna i płatności | sprzedaż, pozycje, formy płatności, zwrot/korekta przez dokument kompensujący, zamknięcie zmiany | płatności mieszane, integracja terminalowa i fiskalna, obsługa voucherów | sprzedaż omnichannel i zaawansowane wykrywanie nadużyć |
| Dokumenty handlowe | faktura/rachunek powiązane ze zdarzeniem handlowym, numeracja, status, korekta | automatyzacja wysyłki, zaliczki, pro formy, integracje krajowe | wielokrajowa zgodność podatkowa zarządzana regułami |
| Planowanie popytu | ręczny plan i zapotrzebowanie z potwierdzonych zamówień, widok niedoborów | sezonowość, scenariusze, zatwierdzanie planu | prognozowanie statystyczne/ML i automatyczna optymalizacja |
| Produkcja | zlecenie, wersja receptury, planowana ilość, wydanie surowców, przyjęcie wyrobu, straty, zamknięcie | operacje technologiczne, partie pośrednie, kontrola jakości, częściowe raportowanie | APS, optymalizacja zdolności i automatyczne harmonogramowanie |
| Magazyn | niezmienny rejestr ruchów, salda wyliczane, przyjęcie/wydanie/przesunięcie/korekta, rezerwacje, inwentaryzacja | lokalizacje magazynowe, kompletacja, partie i terminy dla pełnego zakresu | WMS, skanowanie na dużą skalę i optymalizacja rozmieszczenia |
| Identyfikowalność | numer partii dla produktów wskazanych decyzją biznesową, połączenie zakupu–produkcji–sprzedaży | pełna genealogia partii i obsługa wycofania produktu | analiza grafowa wpływu i predykcja ryzyka jakościowego |
| Zakupy i dostawcy | zapotrzebowanie, zamówienie zakupu, przyjęcie, rozbieżności, podstawowa kartoteka dostawcy | akceptacje wielostopniowe, umowy, ocena terminowości/jakości | optymalizacja źródeł dostaw i predykcja ryzyka dostawcy |
| HR i czas pracy | pracownik, status zatrudnienia, ewidencja czasu, korekty zatwierdzane, eksport danych | grafiki, nieobecności, kwalifikacje, wielopoziomowe zatwierdzanie | optymalizacja obsady i predykcja zapotrzebowania kadrowego |
| Koszty | ceny zakupu, zużycie rzeczywiste, koszt receptury, koszt zlecenia, prosty koszt pracy, wersjonowanie kalkulacji | narzuty, koszty pośrednie, odchylenia standard–rzeczywiste, zamknięcie okresu | rentowność scenariuszowa i zaawansowana alokacja działań |
| Raportowanie | sprzedaż, zapasy, produkcja, straty, zakupy, czas pracy, koszty; zgodność z dokumentami źródłowymi | dashboardy rolowe, raporty wyjątków, harmonogram wysyłki | hurtownia danych, self-service BI i analityka predykcyjna |
| Integracje | outbox/inbox, idempotencja, retry, monitoring błędów, ręczne wznowienie z audytem | mapowanie wersji komunikatów, SLA, panel uzgodnień | broker zdarzeń i rozproszona orkiestracja tylko przy potwierdzonej skali |
| Administracja i audyt | konfiguracja słownikowa, numeracje, dziennik zmian, korekty bez nadpisywania historii | delegacje, przeglądy uprawnień, retencja i eksport audytowy | ciągły monitoring kontroli i automatyczna detekcja anomalii |

## Warunki uznania MVP za kompletne biznesowo

MVP nie oznacza prototypu. Jest kompletne dopiero, gdy:

1. każda zmiana stanu zapasu wynika z zatwierdzonego dokumentu/ruchu;
2. żadna korekta sprzedaży, produkcji, zapasu, czasu lub kosztu nie usuwa historii;
3. zamówienie, produkcja, zakup i sprzedaż mają jawne statusy oraz dozwolone przejścia;
4. użytkownik bez właściwego uprawnienia nie wykona ani nie zatwierdzi operacji krytycznej;
5. raport można uzgodnić z dokumentami i zdarzeniami źródłowymi;
6. awaria integracji nie powoduje cichej utraty ani podwójnego przetworzenia operacji;
7. procesy krytyczne mają obsłużone anulowanie, korektę i powtórzenie;
8. zasady oznaczone jako decyzje biznesowe zostały rozstrzygnięte przed odbiorem zależnych procesów.

## Funkcje jawnie poza MVP

Poza MVP pozostają: mikrousługi, pełny event sourcing, osobna hurtownia danych, workflow engine ogólnego przeznaczenia, AI/ML, dynamiczne ceny, automatyczny APS, optymalizacja tras, rozbudowany WMS i wielokrajowy silnik zgodności. Ich dodanie wymaga potwierdzonej potrzeby, danych i zdolności utrzymaniowej.
