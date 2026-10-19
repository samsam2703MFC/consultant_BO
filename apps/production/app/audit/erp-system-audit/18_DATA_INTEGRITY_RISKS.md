# Ryzyka integralności danych

| Ryzyko | Dowód/obserwacja | Priorytet |
|---|---|---|
| Schematy różne między środowiskami | brak runnera migracji, numerowane SQL/MD | P0/P1 |
| Nieatomowe procesy wielotabelowe | transakcje występują tylko w części serwisów | P1 |
| Niespójne stany magazynowe | `carton_stock` kontra Agent inventory/log | P1 |
| Duplikacja integracji | wiele outboxów/workerów, brak globalnego status UI | P1 |
| Zmienna historia marży | brak dowodu snapshotów koszt/receptura/VAT | P1 |
| Błędy sum dokumentów | brak testów reconciliation nagłówek-pozycje | P1 |
| Dowolne statusy | brak pełnych constraintów i state machine | P1 |
| Utrata audytu aktora | rozproszone changelogi, brak global audit log | P1 |
| Błędy czasu | brak jawnej polityki timezone; grupowanie po datach DB | P2 |
| Waluty | pojedynczy kurs EUR w ustawieniach, brak historii kursu | P2 |

Warunek zamknięcia: constrainty, transakcje domenowe, klucze idempotencji, state machines, snapshoty historyczne i cykliczne uzgodnienia.

