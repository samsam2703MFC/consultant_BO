# Wejście do analizy luk

## Cel i sposób użycia

Ten dokument jest kontraktem wejściowym do kolejnego etapu. Nie stanowi roadmapy ani estymacji. Analiza luk powinna porównać każdy wymóg TO-BE z dowodem AS-IS, przypisać stan realizacji i wskazać brakujący element bez projektowania rozwiązania technicznego.

## Źródła bazowe

| Źródło | Rola w analizie |
|---|---|
| `audit/erp-system-audit/` | szczegółowe dowody i pierwotne oceny AS-IS |
| `planning/00-audit-synthesis/` | zwalidowany obraz bieżący, 50 znormalizowanych funkcji i ograniczenia pewności |
| `planning/01-tobe-processes/03_TOBE_PROCESS_CATALOG.md` | docelowe procesy i wymagane zachowania |
| `planning/01-tobe-processes/04_PROCESS_STATE_MACHINES.md` | statusy, przejścia oraz skutki biznesowe |
| `planning/01-tobe-processes/05_DATA_OWNERSHIP_MATRIX.md` | źródła prawdy, prawo zapisu i historia danych |
| `planning/01-tobe-processes/07_EXCEPTION_AND_CORRECTION_MODEL.md` | wymagania dla błędów, anulowań i korekt |
| `planning/01-tobe-processes/08_AUDITABILITY_REQUIREMENTS.md` | minimalny dowód audytowy i odtwarzalność |
| `planning/01-tobe-processes/10_BUSINESS_DECISIONS_REQUIRED.md` | nierozstrzygnięte reguły, które mogą zmienić ocenę luki |

## Jednostka porównania

Każda pozycja analizy luk powinna zawierać:

1. identyfikator procesu TO-BE i konkretny wymóg/inwariant;
2. odpowiadającą funkcję lub funkcje AS-IS;
3. dowód: plik, tabela, endpoint, ekran, test albo obserwacja runtime;
4. stan: `spełnione`, `częściowo`, `brak`, `sprzeczne`, `niezweryfikowane`;
5. wpływ biznesowy i dotknięte dane;
6. zależną decyzję biznesową, jeśli występuje;
7. kryterium domknięcia luki możliwe do sprawdzenia.

Brak dowodu nie oznacza automatycznie braku funkcji — oznacza `niezweryfikowane`. Deklaracja w interfejsie lub nazwie klasy nie jest dowodem poprawnego procesu end-to-end.

## Docelowy rejestr procesów do pokrycia

| Domena | Procesy TO-BE | Minimalny przedmiot oceny |
|---|---|---|
| Master Data | MD-001–006 | cykl życia kartotek, unikalność, jednostki, podatki, ceny, kontrahenci |
| HR | HR-001–004 | pracownik, czas, korekty, raport kosztowy |
| Administracja | ADM-001–004 | struktura, role, numeracje, konfiguracja i audyt zmian |
| Receptury | REC-001–002 | wersjonowanie, zatwierdzenie, kalkulacja i użycie właściwej wersji |
| Sprzedaż | SAL-001–005 | zamówienie, rezerwacja, realizacja, detal, płatność i korekta |
| Dokumenty | DOC-001–002 | wystawienie, numeracja, status i korekta bez nadpisania |
| Planowanie | PLN-001–002 | popyt, niedobory i plan produkcji |
| Produkcja | PRD-001–004 | zlecenie, surowce, wyrób/straty, zamknięcie i koszt |
| Magazyn | INV-001–005 | przyjęcie, wydanie, rezerwacja, przesunięcie, inwentaryzacja |
| Zakupy | PUR-001–004 | zapotrzebowanie, zamówienie, dostawa/rozbieżność, dostawca |
| Logistyka | LOG-001 | wydanie/dostawa i śledzenie partii |
| Koszty i raporty | COST-001–003 | koszt zakupu, receptury/produkcji, okres i uzgodnienie raportów |

Łącznie rejestr obejmuje **42 rodziny procesów**. Oceniać należy ich zachowanie, a nie wyłącznie istnienie ekranu lub tabeli.

## Krytyczne inwarianty przekrojowe

| ID | Inwariant TO-BE | Dowód akceptowalny w analizie luki |
|---|---|---|
| GI-01 | tylko zatwierdzony ruch magazynowy zmienia saldo | pełna ścieżka zapisu i uzgodnienie dokument–ruch–saldo |
| GI-02 | dokument zatwierdzony nie jest nadpisywany; korekta kompensuje | historia wersji/zdarzeń i powiązany dokument korekty |
| GI-03 | proces ma jawny status i kontrolowane przejścia | reguły domenowe oraz testy przejść dozwolonych i zabronionych |
| GI-04 | wersja receptury użyta w produkcji pozostaje odtwarzalna | trwałe powiązanie zlecenia z konkretną wersją i składnikami |
| GI-05 | cena i koszt mają datę, źródło i wersję | rekordy źródłowe oraz możliwość odtworzenia kalkulacji |
| GI-06 | uprawnienia są egzekwowane po stronie operacji, nie tylko UI | negatywny test autoryzacji dla każdej operacji krytycznej |
| GI-07 | każda operacja krytyczna ma autora, czas, powód i korelację | nieedytowalny wpis audytowy powiązany z obiektem |
| GI-08 | retry integracji jest idempotentny i obserwowalny | powtórzenie tego samego komunikatu bez podwójnego skutku oraz alert błędu |
| GI-09 | raport uzgadnia się z dokumentami źródłowymi | kontrolna suma i ścieżka drill-down do źródła |
| GI-10 | zamknięty okres nie zmienia się bez jawnej procedury | blokada albo audytowane ponowne otwarcie i ponowne przeliczenie |
| GI-11 | partia zachowuje genealogię zakupu, produkcji i sprzedaży | śledzenie wstecz i wprzód na próbie partii |
| GI-12 | błąd częściowy nie pozostaje niewidoczny | stan błędu, właściciel, możliwość bezpiecznego wznowienia |

## Wstępne powiązanie z obrazem AS-IS

| Obszar TO-BE | Obraz AS-IS z syntezy | Punkt startowy analizy |
|---|---|---|
| kartoteki i proste CRUD | część funkcji osiąga F2/F3 | sprawdzić walidacje, historię, role i zależności transakcyjne |
| uwierzytelnienie i użytkownicy | krytyczne niespójności bezpieczeństwa, w tym nadpisanie danych logowania i surowe porównanie hasła | traktować egzekwowanie tożsamości i autoryzacji jako nieudowodnione do testu negatywnego |
| role i administracja | aktywne operacje użytkowników bez widocznej ochrony w zbadanych ścieżkach | zinwentaryzować każdą operację krytyczną i punkt egzekwowania uprawnienia |
| receptury i ceny | istnieją elementy funkcjonalne, lecz brak pełnego dowodu procesowego | ocenić wersjonowanie, obowiązywanie, zatwierdzenie i reprodukcję kalkulacji |
| zamówienia i sprzedaż | obecne fragmenty domeny; dojrzałość nie przekracza F3 | odtworzyć scenariusze happy path, częściową realizację, anulowanie i korektę |
| magazyn | istnieją ruchy/elementy zapasu, ale pełna księga i spójność nie są potwierdzone | udowodnić GI-01, rezerwacje, inwentaryzację oraz brak cichych zmian salda |
| produkcja | fragmenty operacji produkcyjnych istnieją, brak potwierdzonego end-to-end | śledzić zlecenie od wersji receptury przez zużycie do partii i kosztu |
| zakupy i dostawcy | funkcje częściowe | sprawdzić statusy, częściowe dostawy, rozbieżności i skutki magazynowe |
| HR i czas pracy | elementy istnieją, pełny przebieg i korekty niepotwierdzone | zweryfikować zatwierdzanie, prywatność oraz powiązanie z kosztem |
| koszty i raporty | brak potwierdzonego, reprodukowalnego zamknięcia kosztów | zacząć od źródeł kosztu, wersji kalkulacji i uzgodnień |
| integracje | status operacyjny części ścieżek niezweryfikowany | testować idempotencję, retry, outbox/inbox i monitoring |
| audyt | brak dowodu kompletnego, spójnego śladu przekrojowego | porównać zdarzenia z wymaganym schematem i próbą korekty |

## Dowody wymagające pozyskania

- schemat i próbka danych z rzeczywistej bazy, w tym klucze, ograniczenia i historia;
- lista aktywnych tras/endpointów oraz polityk autoryzacji;
- uruchomione scenariusze end-to-end dla sprzedaży, produkcji, zakupu, magazynu i czasu pracy;
- zachowanie przy błędzie, ponowieniu i równoległym zapisie;
- przykładowe dokumenty, raporty i ich uzgodnienie z rekordami źródłowymi;
- konfiguracja oraz obserwacje działających integracji i zadań cyklicznych;
- reguły księgowe, fiskalne, jakościowe i retencyjne zatwierdzone przez właścicieli.

## Bramy decyzyjne przed oceną wybranych luk

Nie należy zamykać oceny procesów zależnych przed rozstrzygnięciem:

- BD-01 dla sprzedaży, dokumentów, płatności i raportów;
- BD-02–05 dla magazynu, partii i produkcji;
- BD-06–07 dla kosztów oraz zamknięcia okresu;
- BD-08–09 dla realizacji zamówień i akceptacji;
- BD-10–12 dla architektury organizacyjnej, fiskalizacji i trybu offline;
- BD-13–15 dla czasu pracy, zakupów oraz retencji.

## Kryterium gotowości materiału do kolejnego etapu

Wejście jest kompletne, gdy wszystkie 42 rodziny procesów mają właściciela biznesowego, nierozstrzygnięte decyzje mają wskazaną osobę decyzyjną, a dla każdego krytycznego inwariantu wiadomo, jaki dowód AS-IS zostanie sprawdzony. Dopiero wtedy ocena luki może być porównywalna i audytowalna.
