# Elementy odłożone

Odłożenie nie oznacza odrzucenia. Każdy wpis ma warunek powrotu; bez niego nie powinien trafić do aktywnego WIP.

| ID / zakres | Powód odłożenia | Ryzyko rozpoczęcia teraz | Warunek powrotu |
|---|---|---|---|
| ERP-020 pełne lot/expiry dla wszystkich indeksów | zakres BD-03 nieustalony; wysoki koszt operacyjny | niepotrzebny ciężar na wszystkich przyjęciach | klasy ryzyka i wymogi prawne zatwierdzone; ledger działa dla pilota |
| ERP-024 zaawansowane QC/rework | brak execution/lot/ledger | decyzje jakościowe bez śledzenia skutków | ERP-020/023 PASS i BD-05 zatwierdzone |
| ERP-030 pełna alokacja wielowymiarowa | brak źródłowego cost ledger i czasu | pozorna precyzja rentowności | ERP-016/027–029 uzgodnione; metoda alokacji zatwierdzona |
| ERP-034 automatyzacja planowania | brak wiarygodnego ATP, wykonania i baseline planu | automatyzuje błędne dane | manualny plan/version działa min. 2 cykle; ERP-017/019/023/031 PASS |
| ERP-035 supplier score | brak receipt/discrepancy facts | ręczny/arbitralny score | ERP-022 zbiera kompletne dostawy min. 2 okresy; definicja KPI podpisana |
| ERP-036 promocje/vouchery | canonical sale i baseline price niegotowe | koszt rabatu niewiarygodny | ERP-015/016 PASS; właściciel reguł i korekt |
| ERP-037 dashboardy przekrojowe | żaden KPI nie jest AVAILABLE | utrwalenie niewiarygodnych wskaźników | KPI ma canonical fact, correction, snapshot, DQ i reconciliation |
| ERP-038 controlling/rentowność | brak uzgodnionego przychodu i kosztu | błędne decyzje cenowe/produktowe | ERP-016/028–031 PASS dla kontrolnego okresu |
| ERP-039 testy dużej skali (poza restore smoke) | brak wolumenów/SLA i stabilnej semantyki | optymalizacja niewłaściwego modelu | runtime metrics, budżety SLO i krytyczne E2E stabilne |
| ERP-040 destructive purge | retencja i konsumenci niepotwierdzeni | nieodwracalna utrata danych | BD-15, legal hold, backup/restore PASS, zero reads przez ustalony okres |
| ERP-041 forecast statystyczny | brak jakościowej historii i zatwierdzonego planu | model „uczy się” artefaktów | ERP-031/034, min. 12–24 miesięcy jakościowych danych zależnie od sezonowości, baseline lepszy od naive |
| WMS/APS/route optimization | poza MVP, brak skali i stabilnego core | osobny produkt utrzymaniowy | pomiary pokażą, że prosty proces nie spełnia SLA/throughput |
| osobna hurtownia danych | modularny monolit/read models nie zostały wyczerpane | nowe ETL, opóźnienia i drugi source of truth | pomiar obciążenia/izolacji uzasadni osobny workload |

## Review cadence

Przegląd po każdym zamknięciu fazy oraz kwartalnie. Powrót wymaga dowodu spełnienia warunku, ownera, nowego scoringu UQ/MX i dostępnego capacity; sama presja na dashboard nie jest dowodem.

