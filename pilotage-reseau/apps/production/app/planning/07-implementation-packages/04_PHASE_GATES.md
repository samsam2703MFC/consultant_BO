# Bramki między fazami

## Reguła

Pakiet przechodzi bramę tylko na podstawie artefaktów PASS. `BLOCKED` nie jest PASS. Wyjątek wymaga zapisanej decyzji, ownera, expiry i nie może obchodzić bezpieczeństwa, ledger integrity, tax ani reconciliation.

## F0 → F1

| Obszar | Wymaganie |
|---|---|
| testy | secret scan, auth negative i smoke aktywnych entrypointów |
| migracje | plan credentials/session; brak niekontrolowanej domenowej migracji |
| integralność | stary credential revoked; login nie nadpisuje danych |
| E2E | logowanie valid/invalid/logout w kontrolowanym środowisku |
| regresje | brak P0 auth/config regression |
| dokumentacja | runtime inventory, owners, blockers, secret rotation runbook |
| wdrożenie | F0 remediation wdrożona i obserwowana; rollback credential przetestowany w oknie |

## F1 → F2

| Obszar | Wymaganie |
|---|---|
| testy | clean/upgrade/schema diff, deterministic CI, deny/cross-scope/revoke, audit atomicity, duplicate retry |
| migracje | baseline/runner/checksums; canonical org mapping report |
| integralność | default deny; one message→one effect; audit append-only |
| E2E | scoped critical command oraz failed→dead→manual retry→success |
| regresje | ERP-042 core PASS; brak critical security/integration defect |
| dokumentacja | ADR, permission catalog, audit/outbox contracts, runbooks |
| wdrożenie | shadow auth diff zaakceptowany, pierwszy route cutover i jedna kolejka obserwowane |

## F2 → F3

| Obszar | Wymaganie |
|---|---|
| testy | uniqueness/FK, temporal overlaps, recipe cycles, historical stability, import rollback |
| migracje | canonical IDs/legacy map, RecipeVersion baseline, current price baseline z quality flags |
| integralność | brak duplicate canonical, przeliczniki i snapshots zatwierdzone |
| E2E | steward publish product/recipe/price i historyczny fact test nie zmienia wyniku |
| regresje | core F1 plus master/config regression PASS |
| dokumentacja | data owners, source-of-truth map, import template, migration reconciliation |
| wdrożenie | jeden rejestr przełączony z obserwacją; legacy write ograniczony |

## F3 → F4/F5

| Obszar | Wymaganie |
|---|---|
| testy | vertical happy/partial/cancel/correction/retry, concurrency, tax/numbering/ledger invariants |
| migracje | opening/control-date backfills i shadow comparisons signed |
| integralność | saldo=suma ruchów; sale/document/payment rozdzielone; production events rzeczywiste |
| E2E | receipt→stock, sale→document correction i production consume/output dla zakresu pilota |
| regresje | zero P0/P1 defect w auth, stock, tax, idempotency, correction |
| dokumentacja | user docs, support/cutover runbooks, control packs |
| wdrożenie | single writer w pilot scope, observation zakończona |

## F4 → rozszerzenie self-service

| Obszar | Wymaganie |
|---|---|
| testy | permission/scope, preview, maker-checker, double click, batch partial, redaction, compensation |
| migracje | sprawy/manifesty mają lineage; brak raw SQL migration |
| integralność | action używa domain command, immutable original pozostaje |
| E2E | operator kończy wybraną klasę bez programisty i wynik jest zweryfikowany |
| regresje | brak privilege escalation i duplicate effect |
| dokumentacja | admin guide, risk/permission matrix, escalation |
| wdrożenie | ticket baseline porównany, stara ścieżka ograniczona po sukcesie |

## F5 → F6

| Obszar | Wymaganie |
|---|---|
| testy | KPI definitions, rebuild/watermark, totals/drill-down, freshness i performance |
| migracje | read models rebuilt z canonical facts, old periods jakościowo oznaczone |
| integralność | KPI ma fact, correction, snapshot, DQ i reconciliation |
| E2E | użytkownik przechodzi KPI→fact→document/event |
| regresje | raport po master edit/correction zachowuje właściwy wynik |
| dokumentacja | KPI owner/definition/source/status/limitations |
| wdrożenie | co najmniej jeden KPI `AVAILABLE` per wdrażana domena |

## Blokada rentowności

`RPT-007` nie może rozpocząć implementacji/publikacji, dopóki nie przejdą: `COST-001` source entries, `COST-002` completeness/lineage dla wymaganego scope, `COST-003` period close/reopen, `COST-004` allocation totals oraz `RPT-001` reconciliation framework. Dodatkowo `SAL-001` musi dostarczać canonical revenue/corrections. Bieżąca receptura, WDT albo `cost_month` nie spełniają tej bramy.

## F6 → F7

| Obszar | Wymaganie |
|---|---|
| testy | source→entry→allocation totals, close/reopen/hash, margin historical stability |
| migracje | control period ma quality/completeness, estimates jawnie oznaczone |
| integralność | zamknięty raport nie zmienia się bez adjustment/reopen |
| E2E | cost/margin drill-down do sale/receipt/production/time |
| regresje | critical ERP-042 i profitability reconciliation PASS |
| dokumentacja | metoda wyceny/alokacji, close runbook i limitations |
| wdrożenie | controller sign-off i observation pełnego okresu |

## F7 / retire

Forecast wymaga manual baseline, jakościowej historii i backtestu lepszego od naive; najpierw shadow/advisory. Retire wymaga replacement E2E, zero traffic/read/write, retencji/legal hold, archive/restore i feature flag removal. Load/recovery ma zatwierdzone SLO/RTO/RPO, nie arbitralne cele.

