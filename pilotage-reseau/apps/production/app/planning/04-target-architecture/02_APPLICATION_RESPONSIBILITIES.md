# Odpowiedzialności aplikacji

| Aplikacja/komponent | Docelowa odpowiedzialność | Pozostaje | Przenieść | Usunąć/wycofać po dowodzie | Nie wolno duplikować |
|---|---|---|---|---|---|
| API centralne | jedyny publiczny punkt komend i odczytów domenowych dla klientów | routing, DTO, auth context, validation wejścia | reguły biznesowe z kontrolerów/klientów do modułów | stare endpointy bez konsumentów po telemetry/deprecation | statusy, pricing, stock, permissions |
| panel administratora/backoffice | server-rendered lub lekki klient do master data, sprzedaży B2B, produkcji, zakupu, kosztów, konfiguracji i operacji | wartościowe widoki/formularze App | bezpośrednie repository calls z kontrolera do application services | duplikaty `.backup/.tmp/ToRefactor` po scanie | reguły domenowe, obliczenia raportowe, sekrety |
| panel sklepu/franczyzobiorcy | operacje sklepu, zamówienia, dostawy, stock view i zgłoszenia w swoim scope | UI używane przez sklep po potwierdzeniu | zapisy Agent DB do centralnego API | nieaktywny frontend/deployment po dowodzie | kartoteki, cena, sale fact, stock balance |
| POS | koszyk, interakcja z urządzeniami i lokalna odporność wymagana operacyjnie | UX sprzedaży i adapter urządzeń po potwierdzeniu | finalizacja, pricing, payment state, correction i sync do centralnych kontraktów | niepotwierdzony POS Agent, jeśli wybrany zostanie inny klient | sprzedaż, płatność, numeracja, stock |
| portal dostawcy | katalog/oferta, potwierdzenie PO, awizacja, dokument/claim komunikacja | zweryfikowane UI/adaptery Agent | reguły zakupu i supplier score do ERP Core | nieużywane moduły dostawcy po analizie ruchu | supplier master, PO/receipt, cena zatwierdzona |
| aplikacja mobilna | opcjonalne zatwierdzenia, stocktake, QC lub operacje terenowe | tylko potwierdzone przypadki z wartością | każda reguła i storage do API/Core | aplikację/kod bez właściciela i użycia | offline source of truth, role, słowniki |
| CLI/workers | wykonanie outbox, importów, dokumentów, agregatów, reconciliation i maintenance | istniejące skrypty po ujednoliceniu bootstrapa | ad-hoc logikę do application services | skrypty jednorazowe po zastąpieniu i archiwizacji | reguły biznesowe i osobne konfiguracje statusów |
| scheduler | wyzwalanie nazwanych zadań, lock/lease i monitoring | prosty cron/system scheduler | definicję zadań do jednego rejestru | nieudokumentowane duplikaty cronów | retry logika domenowa |
| generator dokumentów | render z zatwierdzonego snapshotu i zapis metadata/hash | biblioteki PDF/CSV/XLSX i szablony po testach | pobieranie danych do dedykowanych query services | generatory bazujące na własnych obliczeniach kwot | numeracja, VAT, korekta, source of truth |
| integracje urządzeń | adapter terminala/fiskalizacji/skanera/wagi; lokalny bridge tylko gdy sprzęt wymaga | sprawdzone sterowniki/adapters | wynik operacji do centralnego contractu | biblioteki/mostki dla nieużywanego sprzętu | payment/sale state i lokalny ledger bez sync |
| read model/reporting | bezpieczne, odbudowywalne widoki/agregaty i eksporty | użyteczne układy raportów | źródła WDT/current master do kanonicznych faktów/snapshotów | raporty bez wiarygodnej definicji po okresie przejściowym | logika zapisu, alternatywne fakty |

## Docelowy los głównych części AS-IS

### `App`

Pozostaje głównym hostem migracji do ERP Core. Server-rendered panel może być rozwijany bez tworzenia osobnego frontendu SPA. Repozytoria i serwisy są stopniowo porządkowane pod moduły; nie jest wymagane jednorazowe przepisanie UI.

### `Agent/api_app_dir`

Do czasu potwierdzenia deploymentu jest źródłem kandydatów do reuse i danych migracyjnych, nie automatycznie drugą aplikacją docelową. Każdy fragment przechodzi decyzję: aktywny klient API, kod do przeniesienia, adapter legacy albo wycofanie. Nie wolno utrzymywać drugiego modelu POS, magazynu, HR, faktur lub zakupów bez jawnej granicy.

### Bezpośredni dostęp do bazy

Wewnątrz ERP Core repozytoria korzystają z jednej bazy, ale respektują własność modułu. Aplikacje/portale/urządzenia nie uzyskują bezpośredniego zapisu. Tymczasowe legacy reads muszą być read-only, mierzone i mieć warunek zakończenia.

## Właściciel operacyjny

Jednoosobowy zespół techniczny może utrzymywać artefakt, ale każdy moduł potrzebuje biznesowego właściciela decyzji i jakości danych: sprzedaż, magazyn/operacje, produkcja/jakość, zakupy, HR, finanse oraz administracja bezpieczeństwa.
