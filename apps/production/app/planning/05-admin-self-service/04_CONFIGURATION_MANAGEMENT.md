# Zarządzanie konfiguracją

## Klasy konfiguracji

| Klasa | Przykłady | Właściciel | Model zmiany | Czy self-service? |
|---|---|---|---|---|
| słownik referencyjny | reason codes, typy kosztów, etykiety | data/process owner | draft, validate, activate/deactivate | tak |
| polityka biznesowa | marża, tolerancja, cutoff, approval threshold | business owner | wersja + effective date + approval | tak |
| workflow metadata | etykieta/kolejność statusu | process owner | wersja; bez zmiany dozwolonych przejść | ograniczony |
| numeracja/podatek | sequence, VAT, jurysdykcja | finanse | wersja + maker-checker + preview | tak, wysokie ryzyko |
| konfiguracja integracji | endpoint ref, timeout, mapping, enabled | integration admin | staged + connection test + approval | częściowo |
| sekret | hasło, token, klucz prywatny | security/platform | vault rotation workflow | nie jako wartość w ERP |
| techniczna runtime | DB DSN, queue topology, feature deployment | platform | IaC/pipeline/review | nie w panelu biznesowym |

## Docelowy rekord konfiguracji

`config_key`, typowany `value` albo secret reference, `scope_type/id`, `version`, `valid_from/to`, `status`, schema version, owner, change reason, requester/approver, created/published timestamps, source, checksum. Odczyt efektywnej wartości pokazuje także źródło dziedziczenia: global → organizacja → zakład → sklep/magazyn.

## Workflow publikacji

1. Utworzenie draftu z ticketem/powodem.
2. Walidacja typu, zakresu, nakładania dat i zależności.
3. Preview efektywnej wartości oraz listy obiektów/procesów objętych zmianą.
4. Symulacja tam, gdzie możliwa: numeracja, cena, podatek, cutoff, tolerancja.
5. Approval według ryzyka i rozdziału obowiązków.
6. Publikacja atomowa w zaplanowanym czasie.
7. Audit, obserwacja KPI/błędów i rollback przez publikację poprzedniej wersji jako nowej.

## Katalog wymagany

| Obszar | Minimalne funkcje | Szczególna kontrola |
|---|---|---|
| słowniki | filtr, wersje językowe, użycie, aktywacja | kod użyty historycznie pozostaje czytelny |
| statusy | opis, kolor, terminalność, reason codes | graf przejść jest kodem/polityką wersjonowaną i testowaną |
| numeracje | scope, prefix, reset, rezerwacje/void | issued numerów się nie zmienia; unikalność DB |
| typy dokumentów | szablon, kanał, locale, obowiązkowe pola | sample render, hash, approval publikacji |
| podatki | rate, code, jurisdiction, validity | overlap/gap check i test dokumentu |
| ceny | wersja, scope, waluta, validity | approval, conflict check, snapshot historyczny |
| sklep | locale, cutoff, kanały, mapping | dziedziczenie i preview effective config |
| produkcja | tolerancje, QC, planowanie | brak retroaktywności dla released orders |
| magazyn | lot/reservation/negative stock | wpływ na otwarte ruchy i urządzenia |

## Sekrety i integracje

Panel nigdy nie zwraca bieżącej wartości sekretu. Pokazuje alias, właściciela, datę utworzenia/rotacji, expiry i test status. Rotacja tworzy nową wersję w vault, testuje ją w bezpiecznym kanale, przełącza referencję i umożliwia krótkie okno powrotu. Audit zawiera wyłącznie identyfikator i fingerprint, nie sekret ani payload uwierzytelniający.

## Czego nie udostępniać

- dowolnych key/value bez schematu;
- edycji `.env` lub plików na serwerze;
- arbitralnego SQL i nazw tabel;
- zmiany feature flag bez ownera, scope, expiry i telemetryki;
- edycji historycznej wersji po publikacji;
- kasowania użytej wartości słownikowej — tylko dezaktywacja.

