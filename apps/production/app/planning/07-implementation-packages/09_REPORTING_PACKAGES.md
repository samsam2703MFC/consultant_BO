# Pakiety raportowe

## Data-ready gate

Pakiet raportowy może wejść do implementacji dopiero, gdy KPI ma ownera/definicję, canonical fact, correction, snapshot, quality rules, watermark, reconciliation i permission. W przeciwnym razie dozwolone są wyłącznie definicja i oznaczenie istniejącego raportu `UNVERIFIED`.

| Pakiet | Fakty wymagane | KPI/outcome | Testy | Rollback |
|---|---|---|---|---|
| RPT-001 | co najmniej jeden fact domenowy + FND-005 | registry/version, read model job, freshness/quality/lineage/drill-down | rebuild deterministic, watermark, totals/sample trace, permission, performance | read flag do poprzedniego modelu; projection rebuild |
| RPT-002 | SAL-001/002, DOC-002 | revenue net/gross, sales/returns per product/client/channel/scope | tax/correction totals do control docs, historical stability | unpublish/new read flag; canonical facts nietknięte |
| RPT-003 | INV-001/002/005 | current/as-of/value/differences/expiry | ledger sum, opening, movement drill, as-of boundary | projection rebuild/old verified view |
| RPT-004 | PROD-002/003 | output/yield/waste/plan-vs-actual | movements/lot/time totals, corrections | projection rebuild; facts immutable |
| RPT-005 | PUR-001 | receipt completeness/discrepancy/OTIF; supplier score po historii | PO/receipt/claim trace i period compare | score hidden/unpublished until data complete |
| RPT-006 | HR-002/003 | approved time, labor productivity/cost input | privacy scope, overlap, correction totals | disable view/export; facts retained |
| RPT-007 | SAL-001, COST-001–004, COST-003 closed, RPT-001/002 | margin/profit product/lot/shop/client | source→entry→allocation→margin sums, period hash, drill-down | unpublish; return to last closed verified report |

## Blokada rentowności

RPT-007 jest zablokowany, jeśli cost completeness nie jest pełny/zaakceptowany, okres nie jest zamknięty, allocation nie sumuje się do CostEntry, canonical sale/corrections nie są uzgodnione lub historyczny raport zmienia się po master-data edit. `current recipe cost`, WDT i miesięczna suma nie omijają bramy.

## UI i operacyjność

Każdy raport pokazuje definition version, scope, period, freshness, quality status, last reconciliation i drill-down. Eksport ma manifest/checksum/masking/expiry oraz osobny audit. Brak danych różni się od zera; `INCOMPLETE/RECONSTRUCTED` są widoczne.

