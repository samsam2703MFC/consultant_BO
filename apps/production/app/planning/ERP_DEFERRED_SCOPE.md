# ERP Deferred Scope

## Zasada

Odłożenie chroni program przed budową funkcji bez źródłowych danych, skali lub ownera. Powrót wymaga spełnienia mierzalnego warunku, aktualizacji scoringu i capacity; presja na ekran/raport nie wystarcza.

| Zakres | Status | Powód | Warunek powrotu | Najwcześniejsza faza |
|---|---|---|---|---|
| pełne lot/expiry/FEFO dla wszystkich indeksów | DEFER | BD-03 i koszt operacyjny nieustalone | klasy ryzyka/legal requirements + ledger pilot PASS | F3 rozszerzenie |
| advanced QC/rework/recall graph | DEFER | brak execution/lot facts | ERP-020/023 PASS, BD-05, UAT quality | F3/F4 |
| pełna alokacja kosztów wielowymiarowa/ABC | DEFER | brak cost/time/source facts | zamknięty okres, source completeness, zatwierdzona metoda | F6 |
| supplier score/SLA | DEFER | brak receipt/discrepancy history | min. 2 uzgodnione okresy ERP-022 + KPI owner | F5 |
| promotions/vouchers advanced | DEFER | brak canonical sale/baseline price | ERP-015/016 PASS, correction/refund rules | F5+ |
| dashboardy przekrojowe | DEFER | 0 KPI AVAILABLE | każdy KPI przejdzie data-ready/reconciliation gate | F5 |
| duże testy load/scalability | DEFER częściowo | brak SLO/wolumenów/stabilnej semantyki | runtime metrics, SLO i krytyczne E2E; restore smoke wcześniej | F7 |
| destructive purge tabel/historii | DEFER | BD-15/konsumenci/restore nieznane | retention/legal hold, zero-use evidence, restore PASS | F7 |
| versioned manual planning automation | DEFER do facts | brak ATP/execution/baseline | ERP-017/019/023/031 PASS, dwa cykle plan-vs-actual | F7 |
| forecast statystyczny/ML | DEFER | brak jakościowej historii i baseline | 12–24 mies. danych zależnie od sezonowości, backtest lepszy od naive | F7 |
| automatyczne requisitions/orders | DEFER | ryzyko automatyzacji błędnego forecast/stock | planning/forecast gates, approval i shadow outcomes | F7 |
| dynamic pricing | DEFER | brak wiarygodnej marży/promocji | F6 profitability AVAILABLE, owner/guardrails/experiment | po F7 |
| pełny WMS/skanowanie/slotting | DEFER | ledger/unit/lot i skala niegotowe | measured throughput/error uzasadnia produkt WMS | po F3/F7 |
| APS/automatic scheduling | DEFER | brak execution/capacity history | stable production facts, bottleneck/benefit measurement | po F7 |
| route optimization | DEFER | brak shipment/route facts i business case | ERP-025 history + measurable logistics objective | po F5 |
| osobna hurtownia/lakehouse | DEFER | read models w OLTP niewypróbowane | measured load/isolation/retention wymaga osobnego workload | po F5 |
| broker zdarzeń jako fundament | DEFER/REJECT NOW | DB outbox wystarcza bez skali | throughput/consumer isolation przekracza measured limits | po F7 |
| mikrousługi per domena | DEFER/REJECT NOW | jedna osoba i wysoki ops cost | niezależne zespoły/SLA/deployment/security boundary | poza obecną roadmapą |
| pełny event sourcing | REJECT NOW | niepotrzebny do audytu/korekt | konkretna domena wykaże konieczność full replay i ownership | poza obecną roadmapą |
| wielokrajowy silnik compliance | DEFER | BD-11 nie potwierdza zakresu | zatwierdzona ekspansja, legal owner i wymagania jurysdykcji | oddzielny program |
| general-purpose workflow engine | REJECT NOW | state machines domenowe są prostsze | wiele stabilnych procesów wymaga dynamicznej identycznej konfiguracji | poza MVP |

## Nigdy jako rozwiązanie docelowe

Arbitralny SQL/table editor, uniwersalny set-status, delete posted/issued/audit, dwa niezależne writery i dashboard jako zamiennik jakości danych nie mają warunku powrotu. Potrzeby biznesowe realizują odpowiednio domenowe komendy, korekty/reversal, single source of truth i reconciled read models.

## Review

Deferred scope jest przeglądany po każdej fazie i kwartalnie. Każdy powracający element musi mieć ownera, evidence gate, zaktualizowane zależności/ryzyka, wpływ utrzymaniowy oraz miejsce w WIP bez wypychania niezakończonego P0/P1.

