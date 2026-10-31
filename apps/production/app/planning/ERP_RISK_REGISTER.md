# ERP Risk Register

## Skale

Prawdopodobieństwo i wpływ: `L/M/H`. Status początkowy: `OPEN`. Ownerzy są rolami do imiennego przypisania w F0/F1.

| ID | Ryzyko | P | I | Wczesny sygnał/trigger | Owner roli | Mitigacja | Contingency |
|---|---|---|---|---|---|---|---|
| R-01 | ujawniony sekret lub niebezpieczny login nadal aktywny | H | H | successful use starego credential/default login | security owner | ERP-001, rotate/revoke, secret scan, auth negative tests | disable endpoint/integration, incident response, rotate dependent credentials |
| R-02 | Agent/runtime jest błędnie uznany za martwy/aktywny | H | H | niezgodne traffic/cron/DB evidence | technical owner | ERP-002, owner+logs+DB reads/writes | wstrzymać retire/cutover, utrzymać read-only adapter |
| R-03 | środowiska mają różny schemat | H | H | schema diff, migration failure, missing column | DBA | baseline/runner/checksum, clean+upgrade tests | restore, forward migration, blokada release |
| R-04 | cross-organization data leak lub privilege escalation | M | H | shadow auth mismatch/negative test fail | security architect | central policy/default deny/scope, SoD, review | disable affected routes/grants, revoke sessions, security incident |
| R-05 | dwa source of truth przyjmują zapis | H | H | mismatch rośnie, ten sam business key w dwóch systemach | domain/data owner | jeden write owner, adapter/outbox, cutover scope | freeze writes, reconcile, wybrać canonical i forward-correct |
| R-06 | opening stock jest błędny | M | H | ledger projection ≠ physical/control total | inventory owner | signed stocktake, lineage, pilot scope | rollback przed cutover; recount/adjustment po cutover |
| R-07 | integracja daje podwójny lub utracony skutek | H | H | duplicate business outcome, message age/backlog | integration owner | stable idempotency, inbox/outbox, retries/dead monitoring | circuit-breaker, stop consumer, reconcile/compensate |
| R-08 | backfill tworzy fałszywą historię | H | H | source lacks version/time; quality flags ignored | data owner | reconstructed flag, lineage, no invented precision | ograniczyć historię do control date, stare okresy unverified/archive |
| R-09 | korekta nadpisuje oryginał | M | H | UPDATE posted/issued record, zmiana zamkniętego raportu | domain/finance owner | correction contract, immutability, period checks | restore evidence, create explicit correction, incident review |
| R-10 | snapshot niekompletny, raport zmienia się po master edit | M | H | historical stability regression | data architect | snapshot contract/test per fact | block KPI/release, backfill only where evidence exists |
| R-11 | nieustalone BD blokują budowę lub powodują rework | H | H | development starts with open gate decision | sponsor/product owner | decision register, deadlines relative to phase | pause dependent slice, select independent work |
| R-12 | dashboard publikuje niewiarygodny KPI | H | H | KPI lacks source/correction/reconciliation | KPI owner | AVAILABLE gate, label unverified, drill-down | unpublish/label estimate, revert to control report |
| R-13 | cost/profitability ma pozorną dokładność | H | H | INCOMPLETE sources shown as zero/full margin | controller | completeness status, source lineage, closed period | withhold publication, use explicit estimate range |
| R-14 | self-service staje się narzędziem obejścia invariants | M | H | generic status/SQL/batch without preview | admin/process owner | action contracts, permission, approval, compensation | disable action, break-glass review, repair affected facts |
| R-15 | retry/batch przeciąża system lub masowo powiela efekt | M | H | error spike, queue growth, repeated external calls | operations owner | limits, throttle, manifest, circuit breaker | kill switch, stop unclaimed jobs, reconcile effects |
| R-16 | brak ownerów/UAT tworzy techniczne „done” | H | H | UAT delayed, no control totals/sign-off | sponsor | book UAT before build, named owner/gates | stop release, replan to discovery/independent slice |
| R-17 | jedna osoba staje się single point of failure | H | H | undocumented cutover, long WIP, no review | sponsor/technical owner | WIP=1, runbooks, CI, critical peer/security review | freeze changes, knowledge transfer, reduce scope |
| R-18 | context switching rozprasza program | H | M | wiele rozpoczętych domen, żaden E2E slice | product/technical lead | vertical milestones, WIP limit, gate reviews | stop lowest-value WIP, finish/close current slice |
| R-19 | wydajność OLTP spada przez read models/backfill | M | H | latency/locks/load budget exceeded | DBA/data owner | incremental jobs, throttle, indexes, measure before DW | pause/reduce batch, replica/separate store only with evidence |
| R-20 | destructive retire/purge usuwa potrzebne dane | M | H | unknown consumer, missing retention/restore | data/legal owner | zero-use evidence, legal hold, archive, restore test | restore archive/backup; incident and retention freeze |
| R-21 | feature flags i legacy nigdy nie są usuwane | H | M | expired flags, both paths active beyond window | technical owner | owner/expiry/telemetry, retire milestone | freeze new flags, dedicated cleanup after proof |
| R-22 | testy integracyjne wywołają realny side effect | M | H | mail/document/payment sent from test | integration/security owner | sandbox/mocks, environment allowlist, disabled real credentials | revoke test credentials, notify recipients/incident response |
| R-23 | wymagania prawne są błędnie założone | M | H | nowa jurysdykcja/channel/legal finding | legal/finance owner | BD-11/15 before document/purge | restrict scope, suspend affected issuance/purge |
| R-24 | forecast/automatyzacja utrwala bias | M | M | backtest worse than naive, drift/override spike | planning/data owner | advisory mode, baseline/backtest, monitoring | disable recommendation, return to manual plan |

## Review

Ryzyka R-01–07 i R-11/16/17 są przeglądane co tydzień w F0–F3 i przed każdym cutoverem. Pozostałe przed odpowiednią fazą i co najmniej raz na milestone. Ryzyko `H/H` bez aktywnej mitigacji blokuje release. Zamknięcie wymaga dowodu, nie deklaracji.

