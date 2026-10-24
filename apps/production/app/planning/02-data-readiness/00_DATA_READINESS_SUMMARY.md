# Podsumowanie gotowości danych i analityki

## Werdykt

Obecny system jest **częściowo gotowy do prostych analiz operacyjnych, ale nie jest gotowy do wiarygodnej, przekrojowej analityki ERP**. Kod potwierdza istnienie wielu tabel, relacji i zapytań raportowych, natomiast nie potwierdzono schematu działającej bazy, liczby rekordów, zakresu dat, kompletności ani uzgodnienia danych.

Nie należy obecnie publikować jako miar zarządczych:

- historycznej marży i rentowności produktu, klienta lub sklepu;
- wartości i stanu magazynu na wybrany dzień;
- kosztu rzeczywistego partii i produkcji;
- wydajności produkcji ani KPI pracowniczych;
- efektu promocji;
- raportów łączących POS, zamówienie, wysyłkę, fakturę, płatność i korektę jako jeden fakt sprzedaży.

## Skala gotowości

| Obszar | Ocena | Uzasadnienie |
|---|---|---|
| Sprzedaż B2B / WDT | PARTIAL | pozycje, daty, ilości i ceny są modelowane, lecz WDT nie jest jednoznacznie potwierdzonym faktem sprzedaży, a korekty nie są uzgodnione |
| POS / transakcje | BLOCKED | bogaty model `transaction*` istnieje w Agent, ale nie potwierdzono aktywnego wdrożenia ani danych |
| Zamówienia | PARTIAL | `customer_order*` ma ceny pozycji i daty; brak potwierdzonej maszyny stanów, pełnego changelogu i częściowych realizacji |
| Produkty, ceny i receptury | PARTIAL | istnieją kartoteki, cenniki, receptury i część changelogów; snapshot wersji i kosztu na moment zdarzenia jest nieudowodniony |
| Magazyn | UNRELIABLE | współistnieją `carton_stock` oraz Agent `material_inventory*`; brak jednej potwierdzonej księgi ruchów i uzgodnienia salda |
| Produkcja | MISSING_PROCESS | system potwierdza plan/listy, ale nie potwierdza wykonania, partii, zużycia rzeczywistego, jakości ani zamknięcia zlecenia |
| Zakupy i dostawy | PARTIAL | istnieją fragmenty App i bogatszy model Agent, którego runtime jest niepotwierdzony |
| Pracownicy | PARTIAL | istnieją osoby, role, grafiki i koszty miesięczne; brak potwierdzonego rzeczywistego czasu i wykonania operacji przez osobę |
| Koszty | PARTIAL/UNRELIABLE | koszty miesięczne i kalkulacje receptur istnieją, lecz brak spójnego historycznego ledgera kosztów i kosztu partii |
| Korekty i audyt | UNRELIABLE | changelogi występują punktowo; brak jednolitego modelu kompensacji, globalnego aktora i kontroli okresu |

## Poziom dowodu

W dokumentach stosowane są trzy poziomy:

- **CONFIRMED_CODE** — tabela, pole lub zachowanie jest bezpośrednio używane przez aktywny kod App albo migrację;
- **AGENT_UNVERIFIED** — model istnieje w `Agent/api_app_dir`, lecz brak potwierdzonego punktu wejścia i wdrożenia;
- **RUNTIME_UNVERIFIED** — nie odczytano schematu ani rekordów z bazy.

W środowisku audytu próby uruchomienia `mysql --version` i `php -v` nie powiodły się z powodu braku plików wykonywalnych. Nie wykonano żadnego zapytania do bazy ani modyfikacji danych. Z tego powodu:

- zakres dat: **nieustalony**;
- liczba rekordów: **nieustalona**;
- wartości null, duplikaty i rekordy osierocone: **nieprzetestowane runtime**;
- zgodność środowiskowego schematu z kodem: **niepotwierdzona**.

## Najważniejsze blokery analityczne

1. Brak potwierdzonego bazowego schematu, wersji migracji i kopii bazy do audytu read-only.
2. Brak kanonicznego faktu sprzedaży łączącego zamówienie, wydanie, POS, dokument, płatność i korektę.
3. Brak jednej niezmiennej księgi ruchów magazynowych dla materiałów i wyrobów.
4. Brak rejestru wykonania produkcji: zlecenia, wersji receptury, zużycia, wyrobu, odpadu, partii, jakości i czasu.
5. Brak obowiązkowych snapshotów danych historycznych użytych w transakcji.
6. Brak jednolitego modelu korekt i anulowań jako zdarzeń kompensujących.
7. Brak rozdzielenia użytkownika, pracownika, wykonawcy i zatwierdzającego w całym systemie.
8. Brak zamknięcia okresu i dowodu, że opublikowany raport jest odtwarzalny.

## Co można robić bezpiecznie teraz

Można używać istniejących zestawień jako narzędzi operacyjnych, o ile są opisane źródłem i ograniczeniami, np. wolumen WDT, bieżąca lista zamówień czy skonfigurowane koszty miesięczne. Nie należy nazywać ich bez dodatkowego uzgodnienia przychodem księgowym, stanem historycznym, kosztem rzeczywistym ani rentownością.

## Konkluzja

Pierwszym celem nie powinien być dashboard, lecz zapewnienie niezmiennych faktów, snapshotów i kluczy łączących. Minimalny model opisany w `11_TARGET_ANALYTICAL_DATA_MODEL.md` może działać w tej samej bazie aplikacyjnej i nie wymaga na początku rozbudowanej hurtowni danych.
