# Ryzyka architektoniczne

| ID | Ryzyko | Prawdopodobieństwo / wpływ | Wczesny sygnał | Ograniczenie | Plan awaryjny / ponowne rozważenie |
|---|---|---|---|---|---|
| AR-01 | nieznany schemat i jakość bazy | wysokie / krytyczny | backfill ma orphan/duplikaty/różne typy | metadata/profile read-only, baseline migracji, quality flags | ograniczyć migrację do controlled opening/new data; ręczne cases przez workflow |
| AR-02 | Agent jest aktywny poza workspace | średnie / krytyczny | ruch/logi/DB writes z nieznanego deploymentu | inventory DNS/cron/traffic/owners przed cutover | utrzymać adapter i wydzielić jawny client/deploy zamiast usuwać |
| AR-03 | system produkcyjny nie może mieć planowanego okna | średnie / wysoki | długie locki/migracje | additive schema, online backfill batches, cutover flags | przerwać batch, read legacy, forward fix |
| AR-04 | dual write się rozjeżdża | wysokie / wysoki | rosnące mismatch/backlog | jeden writer + outbox/projection, automatic reconciliation | zatrzymać cutover, wskazać source of truth, naprawić batch |
| AR-05 | jednoosobowy bus factor i przeciążenie | wysokie / wysoki | wiele równoległych technologii/deploymentów | modularny monolit, jedna baza, standardy, automatyczne testy/runbooki | ograniczyć scope, defer advanced, zewnętrzny review krytycznych migracji |
| AR-06 | scope creep do mikroserwisów/event sourcingu | średnie / wysoki | nowe deploye bez niezależnej potrzeby | ADR-001, extraction criteria i koszt operacyjny w review | wrócić do modułu in-process; usunąć zbędną warstwę sieciową |
| AR-07 | niewłaściwa definicja sprzedaży | wysokie / krytyczny | różne sumy WDT/POS/invoice | BD-01 i reconciliation przed canonical fact | utrzymać źródła oddzielnie, nie publikować wspólnego KPI |
| AR-08 | zła metoda wyceny/kosztu | wysokie / krytyczny | wynik niezgodny z finansami | BD-06, versioned method i source lineage | oznaczyć INCOMPLETE/estimate, przeliczyć nową wersją |
| AR-09 | utrata historii przy snapshot/backfill | średnie / wysoki | current values używane dla dawnych zdarzeń | reconstructed flag, lineage, nie zgadywać braków | ograniczyć wiarygodny zakres od daty kontrolnej |
| AR-10 | saldo otwarcia niezgodne fizycznie | wysokie / wysoki | ledger projection ≠ stocktake | controlled stocktake/opening i owner sign-off | adjustment opening z pełnym audit, nie fałszywe historyczne ruchy |
| AR-11 | offline POS tworzy konflikty/duplikaty | średnie / krytyczny | duplicate local IDs, stara cena/grant | BD-12, bounded offline policy, server dedupe | wyłączyć offline dla ryzykownych operacji; manual conflict queue |
| AR-12 | payment timeout powoduje podwójne obciążenie | średnie / krytyczny | UNKNOWN attempts i różne provider refs | provider key, status check, state machine | manual reconciliation/refund; zablokować blind retry |
| AR-13 | numeracja dokumentów koliduje podczas migracji | średnie / krytyczny | duplicate/gaps across App/Agent | jeden numbering service, ranges/scope, unique constraint | freeze issue w jednej ścieżce, correction/void zgodnie z prawem |
| AR-14 | integracje generują ciche braki | wysokie / wysoki | dead backlog, brak watermark | outbox/inbox, alerts, ops UI, reconciliation | manual retry/partner replay; wstrzymać close/report publish |
| AR-15 | raportowanie obciąża primary | średnie / średni | slow queries/locks/CPU w godzinach pracy | read models, indexes, async exports | read replica/analytics DB po metrykach |
| AR-16 | snapshoty zwiększają rozmiar/PII | średnie / średni | szybki wzrost storage i szeroki access | minimalny schema, compression/JSON tylko gdy właściwe, retencja/pseudonimizacja | archive partitions/analytics DB; ograniczyć pola do obowiązku |
| AR-17 | permission model staje się zbyt skomplikowany | średnie / wysoki | eksplozja ról/wyjątków | atomowe permissions, nieliczne role, scopes i progi | uprościć role, usunąć unused grants, policy test matrix |
| AR-18 | korekty tworzą nieskończone łańcuchy | średnie / średni | wiele korekt tej samej korekty | root original ID, correction sequence, consolidated view | manual case review; blokada po zamknięciu/threshold |
| AR-19 | timezone i business date niespójne | wysokie / średni | różne dzienne sumy | UTC recorded time + business timezone/date, calendar dimension | versioned recalculation i explicit cutoff |
| AR-20 | brak ownerów biznesowych blokuje decyzje | wysokie / wysoki | ADR-y w stanie proposed, sprzeczne UAT | named owners/decision records, defaults tylko jako hypothesis | ograniczyć scope do niekontrowersyjnych faktów i oznaczyć blocker |
| AR-21 | usunięcie legacy narusza retencję | niskie/średnie / krytyczny | nieznani konsumenci/history tables | deprecation evidence, BD-15, read-only archive | restore archive/backup, wstrzymać schema drop |
| AR-22 | testy nie reprezentują produkcji | średnie / wysoki | PASS na pustych fixtures, failure na real volume | anonymized representative datasets, counts and reconciliation | shadow validation i staged cutover |

## Ryzyka zaakceptowane świadomie

- jedna baza jest pojedynczym punktem logicznym, ale upraszcza spójność; ograniczenie: backup/restore, monitoring i później replica, jeśli uzasadniona;
- worker oparty na DB polling ma ograniczony throughput, ale jest wystarczający do czasu pomiaru; brama brokera jest w ADR komunikacji;
- modularny monolit może mieć zależności cykliczne, jeśli granice nie są egzekwowane; ograniczenie: ownership tables, interfaces i architecture tests/static rules;
- część historii przed datą kontrolną pozostanie `reconstructed`/`unknown`; nie wolno poprawiać jej przez zgadywanie.
