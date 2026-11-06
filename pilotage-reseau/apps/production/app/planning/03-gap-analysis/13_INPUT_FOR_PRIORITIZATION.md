# Wejście do priorytetyzacji

## Cel

Dokument dostarcza kryteria i grupy zależności do późniejszego ustalania kolejności. Nie jest roadmapą, harmonogramem ani promptem implementacyjnym.

## Model oceny

Każdą lukę można ocenić w sześciu wymiarach od 0 do 5:

| Wymiar | 0 | 5 |
|---|---|---|
| ryzyko biznesowe | brak mierzalnego wpływu | zatrzymanie operacji, finanse, bezpieczeństwo lub zgodność |
| odblokowanie zależności | izolowana funkcja | odblokowuje wiele domen/KPI |
| nieodwracalność danych | pełny backfill możliwy | danych nie da się odtworzyć po fakcie |
| częstotliwość/zakres | rzadki wyjątek | codzienny proces wielu użytkowników |
| pewność dowodu | hipoteza | potwierdzony dowód kodu/runtime/danych |
| koszt odwrotny | XL i wysoka migracja | XS i niska migracja |

Priorytet nie powinien być prostą sumą. Bezpieczeństwo P0, utrata nieodtwarzalnych danych i źródła krytycznych łańcuchów mają pierwszeństwo nawet przy wysokim koszcie.

## Grupy zależności do wspólnej oceny

| Grupa | Luki | Wartość odblokowana | Główne ryzyko | Warunek wejścia |
|---|---|---|---|---|
| SEC/IAM | GAP-001–004/042/053 | bezpieczne wszystkie procesy i wiarygodny aktor | naruszenie danych i brak odpowiedzialności | BD-09/10 oraz inventory entrypointów |
| Schema & Test Foundation | GAP-049/054–056 | bezpieczna zmiana wszystkich modułów | niewykryte regresje/migracje | izolowane środowisko i read-only profil danych |
| Recipe–Price–History | GAP-011–014/048 | produkcja, koszt, marża | nieodtwarzalna historia | BD-06, model jednostek |
| Canonical Sale | GAP-016–024 | przychód, płatność, dokumenty, zwroty | sprzeczne finanse | BD-01/11/12 i decyzja Agent/POS/faktury |
| Inventory Ledger | GAP-032–036 | stan, ATP, trace, produkcja, zakup, koszt | niewyjaśnialny zapas | BD-02/03/06 i saldo kontrolne |
| Production Execution | GAP-025–031/044 | actual, waste, QC, cost lot | brak danych nieodtwarzalnych | recipe version + ledger + BD-04/05 |
| Procure-to-Stock | GAP-009/037–040 | dostępność materiałów i koszt zakupu | błędny receipt/cost | supplier model + ledger + BD-14 |
| Cost & Period | GAP-045–047 | stabilna marża/rentowność | raporty zmieniają się wstecz | sale/stock/production source facts + BD-06/07 |
| Workforce Actual | GAP-042–044 | koszt pracy i KPI zespołu | prywatność/fałszywa produktywność | IAM + BD-13/15 |
| Integration Operations | GAP-050–053 | kompletność i self-service awarii | utrata/duplikat | source events i inventory aktywnych adapterów |

## Luki o najwyższym współczynniku odblokowania

| Luka | Dlaczego odblokowuje wiele obszarów |
|---|---|
| GAP-003 | autoryzacja i tenant są wymagane przez każdy proces i UI |
| GAP-011 | wersja receptury jest podstawą planu, wykonania, kosztu i historii |
| GAP-016 | kanoniczny fakt sprzedaży jest podstawą przychodu, korekt i marży |
| GAP-020 | wspólna korekta stabilizuje sprzedaż, stock, koszt i okres |
| GAP-032 | ledger zapasu jest podstawą produkcji, zakupu, sprzedaży i wyceny |
| GAP-045 | cost ledger łączy wszystkie źródła kosztu i raporty |
| GAP-046 | okres zamyka historyczność i publikację KPI |
| GAP-048 | snapshoty chronią wszystkie raporty historyczne |
| GAP-049 | schema lifecycle obniża ryzyko każdej migracji |
| GAP-050 | idempotencja chroni wszystkie procesy integracyjne |
| GAP-053 | audit/trace umożliwia diagnozę i dowód zamknięcia |
| GAP-054/055 | test harness/E2E zamienia deklaracje w dowody |

## Luki o wysokiej nieodwracalności danych

Najwcześniejszego zbierania wymagają zdarzenia, których nie da się wiarygodnie backfillować: GAP-016/020 (sale/correction), GAP-028–030 (production actual/QC/waste), GAP-032/036 (movements/lots), GAP-043/044 (actual time/labor), GAP-045/048 (cost/snapshots) i GAP-050/053 (message/audit trace).

## Szybkie działania o dużej redukcji ryzyka

Bez przesądzania kolejności całego programu, relatywnie mały koszt mają:

- GAP-001 — rotacja/usunięcie sekretów;
- GAP-002 — eliminacja domyślnych danych logowania i bezpieczne hashowanie;
- GAP-024 — status/retry istniejącego outbox proform;
- GAP-049 — pełny eksport metadanych i profil read-only jako dowód wejściowy;
- GAP-051 — podstawowa widoczność błędów integracji po ujednoliceniu kontraktu.

Nie należy jednak uznawać szybkiego działania za zamknięcie całego łańcucha zależności.

## Kryteria decyzji build vs reuse vs retire

- `COMPLETE/EXTEND`, gdy istniejący aggregate ma poprawne ziarno i historię, a brakuje kontrolowanego wariantu.
- `REFACTOR`, gdy UI/algorytm jest wartościowy, lecz zapis lub granice odpowiedzialności są błędne.
- `REBUILD/REPLACE`, gdy source of truth, historia albo semantyka faktu są niezgodne z TO-BE.
- `DEFER`, gdy funkcja nie blokuje MVP ani zbierania nieodtwarzalnych danych.
- `REMOVE` dopiero po spełnieniu dowodów z `09_DEPRECATION_CANDIDATES.md`.

## Dane wymagane do właściwej priorytetyzacji

Przed zatwierdzeniem kolejności potrzebne są: wolumen i częstotliwość procesów, liczba użytkowników, koszt ręcznej pracy/błędów, incydenty, prawne deadline'y, status Agent/deploymentu, profil bazy, złożoność migracji danych, dostępność właścicieli biznesowych i decyzje BD-01–15. Bez nich obecne P0–P3 pozostają priorytetem ryzyka, nie harmonogramem.
