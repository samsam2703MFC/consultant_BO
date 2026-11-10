# Pakiety odłożone

| Kandydat | Powód | Warunek powrotu | Potencjalny typ/rozmiar | Roadmapa |
|---|---|---|---|---|
| LOT-ADV pełne lot/FEFO | BD-03 i koszt operacyjny nieznane | INV-002 pilot PASS, klasy ryzyka/legal owner | vertical L per class | po F3 |
| QC-ADV rework/recall graph | brak complete execution/genealogy | PROD-002/003 i INV-002 history, BD-05 | vertical L | po F3/F5 |
| COST-ADV ABC/pełna alokacja | brak complete source/time facts | COST-001–004 i closed period PASS | vertical/reporting L per object | po F6 |
| SUP-SCORE advanced | brak historii receipt/discrepancy | RPT-005 AVAILABLE min. 2 okresy | reporting M | F5+ |
| PROMO-ADV vouchers/rules | brak canonical sale/baseline price | SAL-001/002, PRI-001 i refund rules PASS | vertical L | F5+ |
| DASH-X cross-domain dashboards | 0 KPI AVAILABLE dziś | odpowiednie RPT packages PASS | reporting M per role | F5/F6 |
| PERF-ADV duża skala | brak SLO/wolumenów | telemetry + critical E2E, approved budgets | stabilization M per profile | F7 |
| PURGE destructive | retencja/konsumenci nieznani | MIG-001 zero-use, BD-15, restore PASS | migration M | F7 |
| PLAN/AUTO before facts | brak ATP/execution/baseline | RPT-002/003/004 i dwa plan cycles | PLAN-001/002/AUTO-001 | F7 |
| DYN-PRICE | brak wiarygodnej profitability | RPT-007 AVAILABLE i experiment guardrails | vertical L | po F7 |
| WMS/APS/route optimization | brak core/skali/business case | measured SLA/throughput/error uzasadnia produkt | osobny program, nie pojedynczy XL | poza MVP |
| Data warehouse/lakehouse | read models nie wyczerpane | measured OLTP load/isolation/retention | migration+reporting, zestaw packages | po F5 |
| Broker eventowy | DB outbox nie wyczerpany | measured throughput/consumer isolation | foundation+migration, zestaw | po F7 |
| Microservices | jedna osoba, wysoki ops cost | niezależne zespoły/SLA/security boundaries | osobny transformation plan | poza roadmapą |
| Full event sourcing | brak potrzeby full replay | konkretna domena i owner dowiodą konieczności | osobny ADR/program | poza roadmapą |

## Pakiety niedopuszczalne

Nie tworzyć pakietów: „uniwersalny edytor SQL”, „ustaw dowolny status”, „usuń posted record”, „retry all bez manifestu”, „przepisz App/Agent big-bang”, „zbuduj dashboard i popraw dane później”. Nie mają bezpiecznej Definition of Done.

## Powrót

Każdy deferred package wymaga ownera, dowodu spełnienia warunku, aktualnego dependency graph/scoring, podziału bez XL, maintenance estimate i capacity bez wypychania otwartego P0/P1.

