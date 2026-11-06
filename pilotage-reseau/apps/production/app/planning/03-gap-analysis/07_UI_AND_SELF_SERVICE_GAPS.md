# Luki UI i self-service

## Zasada

Self-service oznacza wykonanie pełnej, autoryzowanej operacji biznesowej wraz z walidacją, wynikiem, audytem i obsługą błędu. Sam formularz CRUD lub przycisk nie spełnia tej definicji.

| ID | Użytkownik / potrzeba | Obecny stan | Brak w UI/self-service | Zależność backend/data | Pilność |
|---|---|---|---|---|---|
| UI-01 | administrator bezpieczeństwa / role | brak spójnego UI App dla RBAC | role, zakres organizacyjny, przegląd grantów i revoke | GAP-003/042 | P0 |
| UI-02 | administrator / struktura organizacyjna | rozproszone ustawienia | zakład/sklep/magazyn, przypisania i zakresy | GAP-004 | P0 |
| UI-03 | administrator / konfiguracja | część aliasów/ustawień | numeracje, słowniki, datowanie, preview wpływu | GAP-005 | P1 |
| UI-04 | operator wsparcia / naprawa | ręczny SQL lub lokalny CRUD | case, diagnoza, approval, execute, verify | GAP-006/053 | P1 |
| UI-05 | operator danych / import | mechanizmy punktowe | walidacja przed importem, błędy per wiersz, approval i wynik | GAP-006/049 | P2 |
| UI-06 | technolog / wersja receptury | edycja bieżącej receptury | clone version, diff, review, activate/withdraw | GAP-011/012 | P0 |
| UI-07 | controller/technolog / koszt receptury | wynik bieżący | status INCOMPLETE, lineage cen, wersja i approval | GAP-013/014 | P1 |
| UI-08 | handlowiec / zamówienie | bogaty happy path | legalne przejścia, partial/backorder, cancel/revision i powody | GAP-017 | P0 |
| UI-09 | kasjer/kierownik / zwrot | Agent niepotwierdzony | wybór oryginału, partial return, approval/refund outcome | GAP-018–020 | P0 |
| UI-10 | księgowość / korekta faktury | brak potwierdzonego UI App | korekta z linkiem, podatkiem, okresem i wysyłką | GAP-021/022 | P0 |
| UI-11 | operator dokumentów / retry | brak centralnego ekranu | stan outbox, błąd, retry/cancel z powodem | GAP-024/050/051 | P1 |
| UI-12 | planista / prognoza i plan | lista/PDF | wersja, approval, shortage, release do order | GAP-025/026 | P1 |
| UI-13 | operator produkcji / wykonanie | brak | start/pause/consume/output/waste i offline-safe retry | GAP-027/028/030 | P0 |
| UI-14 | kontrola jakości / partia | brak | quarantine, inspection, partial accept/reject/rework | GAP-029/036 | P0 |
| UI-15 | brygadzista/controller / korekta zlecenia | brak | correction case, approval, delty, reclose | GAP-031/046 | P1 |
| UI-16 | magazynier / ruch | fragmenty | receipt/issue/transfer z dokumentem, lotem, jednostką i skanem opcjonalnym | GAP-032/034/036 | P0 |
| UI-17 | magazynier / rezerwacja | brak | podgląd rezerwacji, shortage, release i źródło popytu | GAP-033 | P1 |
| UI-18 | magazyn/kierownik / stocktake | Agent niepotwierdzony | freeze, blind count, recount, approval, discrepancy | GAP-035 | P1 |
| UI-19 | kupiec / requisition–PO | dwa fragmentaryczne modele | jeden inbox potrzeb, approval, revisions, send/status | GAP-037 | P0 |
| UI-20 | magazyn/kupiec / rozbieżność dostawy | fragmenty reklamacji | tolerance, accept/reject/claim/return i evidence | GAP-038 | P1 |
| UI-21 | kupiec / dostawca | CRUD | approval, suspension, warunki i score drill-down | GAP-009/039 | P2 |
| UI-22 | HR/kierownik / czas i nieobecność | grafik Agent | submit/approve/reject/lock/correct actual time i absence | GAP-043 | P1 |
| UI-23 | controller / koszty | kalkulator miesięczny | źródła, completeness, adjustment, method/version | GAP-045/047 | P0 |
| UI-24 | controller / close/reopen | brak | checklist, blockers, approval, report version/hash | GAP-046 | P0 |
| UI-25 | operator integracji / błędy | brak centralnego UI | search by correlation, owner, retry/dead resolution | GAP-051/053 | P1 |
| UI-26 | audytor / ślad operacji | lokalne logi | jedna oś czasu obiektu z aktorem, zmianą i korektą | GAP-053 | P1 |
| UI-27 | użytkownik raportu / jakość KPI | dashboardy bez wspólnej warstwy jakości | źródło, definicja, watermark, status uzgodnienia i drill-down | GAP-016/045/048/055 | P1 |

## Zakazane obejścia docelowe

- edycja statusu dowolną wartością;
- bezpośrednia zmiana salda, kosztu lub zatwierdzonego dokumentu;
- przycisk retry bez idempotency key i wyniku;
- korekta bez oryginału, powodu i uprawnienia;
- eksport jako jedyna możliwość ukończenia procesu w arkuszu;
- ukrywanie rekordu zamiast anulowania/kompensacji;
- techniczny komunikat błędu bez identyfikatora korelacji i ścieżki dalszego działania.
