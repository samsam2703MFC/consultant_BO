# Kryteria zamknięcia luk

## Definicja `CLOSED`

Luka może zostać zamknięta tylko wtedy, gdy spełnione są jednocześnie właściwe kryteria biznesowe, procesowe, danych, bezpieczeństwa, operacyjne i testowe. Zaimplementowany ekran, utworzona tabela lub pozytywny happy path nie wystarcza.

Każda zamknięta luka musi posiadać pakiet dowodowy:

1. zatwierdzoną definicję biznesową i właściciela;
2. wskazanie procesów/aktorów/statusów;
3. kontrakt danych i źródło prawdy;
4. dowód uprawnień i audytu;
5. test happy path, wyjątku, anulowania/korekty i retry, jeśli dotyczy;
6. wynik migracji/profilu danych, jeśli zmieniono model;
7. uzgodnienie raportów/KPI dotkniętych zmianą;
8. instrukcję operacyjną błędu i obserwowalność;
9. brak otwartych blockerów biznesowych wpływających na semantykę;
10. akceptację właściciela biznesowego i technicznego.

## Kryteria według kategorii luki

| Kod | Kategoria | Kryterium zamknięcia | Minimalny dowód |
|---|---|---|---|
| CC-01 | brak funkcjonalności | użytkownik kończy proces oraz wyjątek bez SQL/arkusza | E2E z danymi i audytem |
| CC-02 | funkcja powierzchowna | obsłużone statusy, wyjątki, partial, cancel/correction i reporting | macierz przejść + scenariusze |
| CC-03 | niedokończony backend | reguły w domenie, atomowość i constraints | unit/integration + failure rollback |
| CC-04 | niedokończony frontend | wszystkie uprawnione role wykonują pełną operację i rozumieją wynik | UAT + negatywny permission test |
| CC-05 | brak integracji | kontrakt/version/idempotency/retry/dead state są sprawdzone | contract test + duplicate/failure test |
| CC-06 | brak/niewłaściwy model danych | ziarno, klucze, historia, constraints i właściciel są jawne | schema review + migration reconciliation |
| CC-07 | brak historii | raport sprzed i po zmianie kartoteki jest stabilny | temporal/snapshot regression test |
| CC-08 | brak korekt/anulowania | oryginał immutable, delta powiązana, skutki uzgodnione | correction E2E + ledger reconciliation |
| CC-09 | brak self-service | operator rozwiązuje standardowy incydent bez DB/code | UAT przypadku błędu z audytem |
| CC-10 | brak uprawnień | default deny, tenant scope i SoD są egzekwowane backendowo | pełna macierz testów negatywnych |
| CC-11 | brak audytu | kto/co/kiedy/skąd/dlaczego/before-after/correlation jest trwałe | audit query i próba nieedytowalności |
| CC-12 | brak testów | test działa deterministycznie w izolowanym środowisku/CI | powtarzalny raport PASS |
| CC-13 | brak raportowania | definicja i ziarno KPI zatwierdzone, drill-down i uzgodnienie działają | reconciliation z dokumentem kontrolnym |
| CC-14 | niewiarygodne dane | profil ma znaną populację, naruszenia są zero/w tolerancji lub obsłużone | data quality report z ownerem |
| CC-15 | wydajność | budżet SLA spełniony na reprezentatywnym wolumenie i concurrency | benchmark/EXPLAIN/load report |
| CC-16 | bezpieczeństwo | zagrożenie usunięte, sekrety zrotowane, regresja niemożliwa testem/policy | security test/scan i review |
| CC-17 | duplikacja/legacy | jeden source of truth, konsumenci zmigrowani, brak ruchu legacy | dependency/traffic/data evidence |
| CC-18 | brak decyzji biznesowej | decyzja zapisana przez właściciela z zakresem i datą | decision record |

## Kryteria dla fundamentów P0

| Fundament | Zamknięcie |
|---|---|
| bezpieczeństwo i auth | brak sekretów w repo, poprawny hash/session/revoke, default deny i tenant isolation PASS |
| receptura i historia | immutable recipe version + snapshot; zmiana bieżącej wersji nie zmienia starego zlecenia/kosztu |
| fakt sprzedaży | jedna pozycja sprzedaży/korekty uzgadnia order/shipment/invoice/payment/stock bez duplikatu |
| magazyn | stan od daty kontrolnej = suma niezmiennych ruchów; każdy ruch ma źródło, jednostkę, aktora i reversal |
| produkcja | production order przechodzi release–consume–output–QC–close/correct, z partiami i kosztami |
| koszt/okres | każdy koszt ma źródło/metodę; close jest reprodukowalny, reopen/adjustment audytowany |
| dane/schemat | czysta baza odtwarzalna, constraints aktywne, profil jakości i migracja uzgodnione |
| testy | runner/CI, izolowana DB, krytyczne E2E/reconciliation oraz recovery mają dowód |

## Statusy pośrednie

- `OPEN` — luka potwierdzona.
- `BLOCKED_DECISION` — brakuje decyzji biznesowej.
- `BLOCKED_EVIDENCE` — nie można potwierdzić schematu/runtime/konsumentów.
- `IN_VALIDATION` — rozwiązanie istnieje, ale pakiet dowodowy nie jest kompletny.
- `CLOSED` — wszystkie właściwe kryteria spełnione.
- `ACCEPTED_RISK` — luka świadomie pozostawiona z właścicielem, terminem przeglądu i zabezpieczeniem; nie jest to `CLOSED`.

## Zakaz samozatwierdzania

Autor zmiany nie powinien samodzielnie zatwierdzać dowodu biznesowego dla operacji o skutku finansowym, zapasowym, bezpieczeństwa lub retencyjnym. W jednoosobowym zespole rolę akceptującą pełni właściciel procesu, finanse, HR/legal albo osoba odpowiedzialna za operacje — zależnie od luki.
