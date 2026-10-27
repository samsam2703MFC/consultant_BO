# Wejście do master planu

## Jednostki planowania

Master plan powinien używać 42 trwałych inicjatyw `ERP-001`–`ERP-042`, lecz wykonanie planować pionowymi slice’ami. Nie planować osobnych strumieni „backend”, „frontend”, „DB” i „testy”. Każdy milestone ma przynieść działający proces z wyjątkiem/korektą, audytem, migracją i reconciliation.

## Obowiązkowe artefakty wejściowe przed estymacją

| Artefakt | Owner | Blokuje |
|---|---|---|
| mapa runtime/deployment/traffic/cron/DB | technical owner | reuse/retire, koszt repozytoriów |
| schema metadata + profil jakości/liczności | DBA/data owner | migracje i estymacje MX |
| decyzje BD-01–15 | business owners | modele domenowe i acceptance |
| wolumeny, peak, SLA, incident/ticket baseline | operations | priorytet, performance, ROI |
| liczba użytkowników/ról/jednostek | business/security | IAM, UU scoring i rollout |
| aktywne integracje/urządzenia/sekrety | integration/security | adapters, sandbox i cutover |
| opening stock i control period/documents | inventory/finance | ledger, cost, reconciliation |
| dostępność ownerów do UAT | sponsor | realny capacity i sekwencja |

## Bramy milestone’u

Każdy milestone przechodzi kolejno:

1. `Decision Ready` — owner, reguła, scope, P/SLO i kryteria korekty.
2. `Data Ready` — source, mapping, profil, backfill i control totals.
3. `Build Ready` — kontrakt, permission, audit, migration/rollback i fixtures.
4. `Release Ready` — happy/exception/correction/retry E2E, security i reconciliation PASS.
5. `Cutover Ready` — feature flag, runbook, monitoring, owner i okno.
6. `Retire Ready` — okres obserwacji, zero traffic/read/write, retencja i restore.

## Minimalny format pakietu w master planie

| Pole | Wymaganie |
|---|---|
| ID | `ERP-nnn` + opcjonalny suffix slice’u |
| outcome | jedna zdolność biznesowa i użytkownik |
| scope pilot | organizacja/site/channel/product class |
| dependencies | hard/soft/BD/evidence gate |
| data change | additive schema, backfill, shadow, cutover, archive |
| controls | permission, approval, audit, idempotency, compensation |
| validation | tests, control document/totals, KPI/data-quality checks |
| operability | monitoring, retry, runbook, support owner, SLO |
| exit | mierzalny wynik i okres obserwacji |
| capacity | implementacja + UAT + migracja + hypercare + maintenance |

## Rejestr ryzyk programu

| Ryzyko | Wczesny sygnał | Reakcja |
|---|---|---|
| utrwalenie dwóch source of truth | oba modele nadal przyjmują nowe rekordy | freeze scope i jeden write owner/adapter |
| migracja bez reconciliation | count bez sum/hash/drill-down | blokada cutoveru |
| platforma bez konsumenta | miesiące pracy bez vertical slice | wymagać pierwszego konsumenta w tym samym milestone |
| dashboard przed danymi | KPI bez ownera/source/correction | oznaczyć unverified i odroczyć |
| samotny developer jako single point of failure | brak runbooków/review/ownerów | dokumentacja, automatyzacja, peer/security review przy krytycznych zmianach |
| niekończące się feature flags | brak expiry/owner | gate retire i alert na przeterminowanie |
| rosnący koszt operacyjny | nowy runtime/repo/queue per funkcja | modularny monolit jako domyślna granica |

## Rebalansowanie priorytetów

Po ERP-002/003 ponownie ocenić IC/MX/UQ/RG; po zebraniu ticket baseline — FU/UU/UR; po decyzjach BD — BV/RR/DI. Priorytet może się zmienić, ale ID i historia decyzji pozostają. Wyjątek prawny/security z potwierdzonym terminem może wejść P0, lecz nadal musi przejść minimalne bramy bezpieczeństwa i danych.

## Pierwszy portfel do planowania

1. `ERP-001A/B` — sekret i logowanie.
2. `ERP-002A` — mapa runtime i danych.
3. `ERP-003A` — schema metadata/profile; `ERP-003B` — baseline/runner.
4. `ERP-004A` — smoke/CI; `ERP-004B` — integration DB fixtures.
5. `ERP-005A` — organization/identity map; `ERP-005B` — policy shadow; `ERP-005C` — route cutover.
6. `ERP-006A` — command/audit context z jednym critical command.
7. `ERP-007A/008A` — jeden outbox adapter i read-only operations view.
8. Pierwszy domenowy slice wybrać dopiero z dowodów: preferowany `receipt → inventory movement → audit → reconciliation`, alternatywnie canonical sale B2B, jeśli ma lepszy control pack.

## Definition of program success

- brak aktywnych znanych luk P0 bezpieczeństwa;
- jeden source of truth per capability i brak rutynowego direct SQL;
- krytyczne procesy obsługują exception/cancel/correction/retry;
- każdy krytyczny fakt ma actor, scope, business time, snapshot i source;
- integracyjny retry daje jeden skutek i jest self-service z audytem;
- raporty opierają się na KPI ze statusem `AVAILABLE` i uzgadniają do źródła;
- koszt zmian oraz liczba interwencji programisty mają mierzalny trend spadkowy;
- legacy jest wycofywane dopiero po dowodzie braku użycia i zachowaniu retencji.

Nie tworzyć promptów implementacyjnych przed zatwierdzeniem pierwszego portfela, ownerów i bram F0–F2.

