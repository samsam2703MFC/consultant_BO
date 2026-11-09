# Katalog pakietów

## Konwencje

Rozmiar: XS/S/M/L. `Repo` oznacza przewidywany zakres: `App`, `Agent`, `Worker` (`Bin`/scheduler), `Infra/CI`; potwierdza go `DSC-001`. Każdy pakiet dziedziczy pełny ERP Definition of Done. Pierwsza fala ma kompletne specyfikacje w `packages/`; dla późniejszych pakietów ten katalog określa minimalny outcome, zależności i granicę.

## F0–F1: stabilizacja i fundamenty

| ID | Typ | Faza | S | Cel/problem | Zakres i rezultat | Poza zakresem | Repo | Zależności |
|---|---|---|---|---|---|---|---|---|
| STB-001 | Stabilization | F0 | S | usunąć aktywne ryzyko ujawnionych sekretów | revoke/rotate, secret references, walidacja config, scan i runbook | pełny vault UI, IAM | App/Agent/Worker/Infra | owner credentials |
| STB-002 | Stabilization | F0 | M | login App nie uwierzytelnia wiarygodnie | usunąć defaults, hash migration, session hardening, login/logout UI i testy | RBAC/scopes | App | STB-001, DSC-001 |
| DSC-001 | Foundation/discovery | F0 | S | nieznany runtime Agent i konsumenci | signed inventory App/Agent/workers/DB/routes/traffic/owners | zmiana produkcyjna | wszystkie read-only | — |
| FND-001 | Foundation/Migration | F1 | M | brak odtwarzalnego schematu | metadata/profile, baseline, ordered runner, checksums, dry-run/status | migracje domenowe | App/Agent DB/Infra | DSC-001 |
| FND-002 | Foundation | F1 | M | brak test runnera i CI | izolowana DB/fixtures, jedna komenda, smoke+integration CI | pełne E2E domen | App/CI | FND-001 |
| FND-003 | Foundation/Migration | F1 | L | niespójne organization/user/employee IDs | canonical organization, identity map, membership; conflict report | role enforcement | App+Agent data | FND-001/002, BD-10 |
| FND-004 | Foundation | F1 | L | brak centralnego RBAC/scope | permission catalog, policy service, shadow diff, route cutover, revoke | admin UI | App, Agent client/API | STB-002, FND-003, BD-09/10 |
| FND-005 | Foundation | F1 | M | brak wspólnego aktora/trace/audytu | command context, audit schema/outbox, jedna krytyczna komenda i timeline API | generic repair UI | App | FND-002/004, BD-15 |
| FND-006 | Foundation | F1 | M | korekty/approval są ad hoc | repair case/approval contract i jedna domenowa kompensacja pilotażowa | wszystkie domenowe korekty | App | FND-005, BD-07/09 |
| INT-001 | Foundation/Migration | F1 | L | wiele outboxów/statusów i ryzyko duplikatu | envelope/attempt/idempotency, adapter jednego aktywnego outboxu | migracja wszystkich kolejek | App/Worker/Agent wg źródła | STB-001, DSC-001, FND-001/002/005 |
| SS-001 | Self-service | F1/F4 | M | retry wymaga logu/CLI/SQL | read-only inbox, detail, single retry/dead resolution, outcome | batch retry i config fix | App + Worker API | FND-004/005, INT-001 |

## F2: master data, historia i konfiguracja

| ID | Typ | Faza | S | Cel | Zależności | Działający rezultat | Repo |
|---|---|---|---|---|---|---|---|
| MD-001 | Vertical/Migration | F2 | L | canonical produkt i revision history | FND-003–005 | steward publikuje produkt, legacy ID map działa, historyczny snapshot stabilny | App+Agent mapping |
| MD-002 | Vertical/Migration | F2 | L | canonical klient i lifecycle | FND-003–005, BD-11/15 | onboard/suspend/merge-safe klienta z dokumentowym snapshotem | App+Agent mapping |
| MD-003 | Vertical/Migration | F2 | M | canonical dostawca i lifecycle | FND-003–005 | approve/activate/suspend dostawcę z historią | App+Agent mapping |
| MD-004 | Vertical/Migration | F2 | M | jednostki i konwersje | FND-001/005 | wersjonowany przelicznik bez zmiany faktów historycznych | App+Agent mapping |
| MD-005 | Vertical/Migration | F2 | M | opakowania i default packs | MD-003/004 | przypisanie pack/supplier z validity i kontrolą użycia | App+Agent mapping |
| CFG-001 | Vertical | F2 | S | wspólne słowniki/reason codes | FND-004/005 | owner aktywuje/dezaktywuje kod z usage history | App |
| CFG-002 | Foundation/Self-service | F2/F4 | L | versioned config i scope inheritance | FND-003–005, CFG-001 | draft→validate→approve→publish→rollback jednej polityki | App |
| REC-001 | Migration | F2 | M | baseline bieżących receptur | MD-001/004, FND-001/002/005 | current graph→RecipeVersion v1 z hash/quality flag | App+Agent data |
| REC-002 | Vertical | F2 | L | publikacja RecipeVersion | REC-001, FND-004/005 | clone/diff/review/activate/withdraw bez cykli | App |
| PRI-001 | Vertical/Migration | F2 | L | temporal price/VAT/margin | MD-001/002, CFG-002, BD-06/11 | publikacja wersji z effective date i snapshot test | App+Agent mapping |
| DATA-001 | Self-service/Migration | F2/F4 | M | import produktu bez skryptu | MD-001, FND-004/005 | stage→validate→approve→apply→report/compensate jednego template | App |

## F3: inventory, zakup, sprzedaż i dokumenty

| ID | Typ | Faza | S | Cel | Zależności | Działający rezultat | Repo |
|---|---|---|---|---|---|---|---|
| INV-001 | Migration/Vertical | F3 | L | ledger i opening balance pilota | MD-001/004, FND-005/006, BD-02/06 | movement ledger jednej lokalizacji uzgadnia signed opening | App+Agent mapping |
| PUR-001 | Vertical | F3 | L | receipt tworzy zapas/koszt | INV-001, MD-003/005, PRI-001, INT-001, BD-14 | partial receipt→movement→discrepancy→audit | App+Agent client |
| INV-002 | Vertical/Migration | F3 | L | lot/expiry/FEFO dla klasy pilota | INV-001, BD-03/05 | przyjęcie/issue zachowuje lot i status jakości | App+Agent client |
| INV-003 | Vertical | F3 | M | reservation niezależna od on-hand | INV-001, FND-006 | reserve/release/consume idempotentnie | App+Agent client |
| INV-004 | Vertical | F3 | M | transfer z custody | INV-001/002 | issue→transit→receive/discrepancy | App+Agent client |
| INV-005 | Vertical/Self-service | F3/F4 | L | stocktake i adjustment bez SQL | INV-001/002, FND-006, BD-07/09 | freeze/count/recount/approve/post movement | App+Agent UI/API |
| SAL-001 | Vertical/Migration | F3 | L | canonical sale/return/correction | PRI-001, FND-005/006, INT-001, BD-01/11/12 | sprzedaż kanału pilota i korekta dają jeden fact chain | App+Agent/POS client |
| SAL-002 | Vertical | F3 | M | payment/refund reconciliation | SAL-001, INT-001 | payment/refund idempotentnie wiąże sale | App+Agent/POS+adapter |
| DOC-001 | Vertical/Migration | F3 | L | canonical document/numbering/issue/send | SAL-001, CFG-002, INT-001, BD-11 | issued immutable snapshot, renderer i delivery attempt | App+adapters |
| DOC-002 | Vertical | F3 | M | correction document | DOC-001, SAL-001/002, FND-006, BD-07 | korekta linkuje oryginał i kompensuje tax/payment | App |
| ORD-001 | Vertical | F3 | L | B2B order lifecycle/partial/cancel | MD-002, PRI-001, INV-003, BD-08 | order→confirm→reserve→partial/cancel z historią | App+Agent client |
| LOG-001 | Vertical | F3 | L | shipment custody/POD/return/cost | ORD-001, DOC-001, INV-001, INT-001 | dispatch→POD/failed/return z jednym stanem | App+Agent/adapters |

## F3: produkcja i workforce

| ID | Typ | Faza | S | Cel | Zależności | Działający rezultat | Repo |
|---|---|---|---|---|---|---|---|
| PROD-001 | Vertical | F3 | L | production order lifecycle | REC-002, INV-003, FND-004/005, BD-04 | draft→release→start→close na RecipeVersion | App+Agent UI/client |
| PROD-002 | Vertical | F3 | L | rzeczywiste consume/output | PROD-001, INV-001/002 | raport wykonania tworzy signed movements | App+Agent UI/client |
| PROD-003 | Vertical | F3 | L | waste/QC dla pilota | PROD-002, CFG-001, BD-05 | waste reason + quarantine/accept/reject z kosztem | App+Agent UI/client |
| PROD-004 | Vertical/Self-service | F3/F4 | L | correction/reopen production | PROD-002/003, FND-006, COST-003, BD-07/09 | approved delta i wszystkie kompensacje/reclose | App |
| HR-001 | Vertical/Migration | F3 | M | employee lifecycle/assignment | FND-003–005, BD-13/15 | active assignment z datami i offboarding | App+Agent mapping |
| HR-002 | Vertical | F3 | L | actual time/absence | HR-001, FND-006 | submit→approve→lock→correct actual | App+Agent UI/client |
| HR-003 | Vertical | F3 | M | labor time per order | HR-002, PROD-001 | approved minutes wiążą order/operation/role | App+Agent UI/client |

## F6: koszt i okres

| ID | Typ | Faza | S | Cel | Zależności | Działający rezultat | Repo |
|---|---|---|---|---|---|---|---|
| COST-001 | Vertical/Migration | F3/F6 | L | source cost entries purchase/stock | PUR-001, INV-001, PRI-001, BD-06 | receipt/movement generuje odtwarzalny CostEntry/Adjustment | App |
| COST-002 | Vertical | F6 | L | recipe/production cost lineage | REC-002, PROD-002/003, HR-003, COST-001 | koszt normatywny i actual ma completeness/drill-down | App |
| COST-003 | Vertical/Self-service | F6 | L | period close/reopen | COST-001, DOC-002, INV-005, FND-006, BD-07/09 | checklist→close/hash→controlled reopen/reclose | App |
| COST-004 | Vertical | F6 | L | allocation product/lot/shop/client | SAL-001, LOG-001, COST-002/003 | zatwierdzona metoda sumuje się do ledger/period | App |

## F4: self-service przekrojowy

| ID | Typ | Faza | S | Cel | Zależności | Działający rezultat | Repo |
|---|---|---|---|---|---|---|---|
| SS-002 | Self-service | F4 | M | config Workbench | CFG-002, FND-004/005 | administrator publikuje jedną politykę z preview/rollback | App |
| SS-003 | Self-service | F4 | L | DQ queue/import/merge/link | DATA-001, MD-001–003 | steward rozwiązuje missing/duplicate/link z manifestem | App |
| SS-004 | Self-service | F4 | L | Repair Workbench shell i akcje | SS-001, FND-006 + gotowe domain commands | case→preview→approve→execute→verify dla dwóch domen | App |
| SS-005 | Self-service | F4 | L | IAM administration | FND-003/004/005, BD-09 | invite/block/grant/scope/reset/revoke z SoD | App |
| SS-006 | Self-service | F4 | M | document regenerate/resend | DOC-001, SS-001 | oddzielny regenerate artifact i resend attempt | App+adapters |

## F5–F7: reporting, controlling i planowanie

| ID | Typ | Faza | S | Cel | Zależności | Działający rezultat | Repo |
|---|---|---|---|---|---|---|---|
| RPT-001 | Reporting/Foundation | F5 | L | KPI registry/read model/reconciliation framework | FND-005, source facts | definicja/version/watermark/quality/drill-down jednego KPI | App |
| RPT-002 | Reporting | F5 | M | sprzedaż operacyjna | SAL-001/002, DOC-002, RPT-001 | revenue/returns per scope uzgodnione do fact/doc | App |
| RPT-003 | Reporting | F5 | M | inventory operacyjny | INV-001/002/005, RPT-001 | current/as-of/differences/expiry ze statusem jakości | App |
| RPT-004 | Reporting | F5 | M | produkcja operacyjna | PROD-002/003, RPT-001 | plan/actual/yield/waste z drill-down | App |
| RPT-005 | Reporting | F5 | M | purchasing/supplier | PUR-001, RPT-001 | receipt/discrepancy/OTIF; score dopiero po historii | App |
| RPT-006 | Reporting | F5 | M | workforce | HR-002/003, RPT-001 | approved time/labor productivity z privacy scope | App |
| RPT-007 | Reporting | F6 | L | profitability | COST-003/004, RPT-001/002 | margin/profit product/lot/shop/client dla closed period | App |
| PLAN-001 | Vertical | F7 | L | versioned demand/production plan | RPT-002/003/004, ORD-001, PROD-001 | planner tworzy/approve baseline i plan-vs-actual | App |
| PLAN-002 | Vertical | F7 | L | shortage i release orders | PLAN-001, INV-003, PROD-001 | shortage→proposal→approval→orders | App |
| AUTO-001 | Vertical/Reporting | F7 | L | advisory forecast | PLAN-001, min. quality history | backtest vs naive, forecast z błędem i human approval | App/data job |
| OPS-001 | Stabilization/Foundation | F7 | M | load/concurrency/recovery/restore | FND-002, critical E2E, runtime SLO | zatwierdzone budgets i restore/recovery PASS | App/Worker/Infra |
| MIG-001 | Migration | F7/ciągły | M | retire jednego zastąpionego capability | DSC-001, replacement PASS, BD-15 | zero-use→read-only→archive/remove z restore proof | właściwe repo+DB |

## Minimalny kontrakt późniejszego pakietu

Przed wygenerowaniem promptu każdy wpis późniejszy musi zostać rozwinięty do formatu plików pierwszej fali: tabele/FK/indexes; migracja/backfill/cutover; endpointy/commands; UI states; permissions; audit; test matrix/fixtures; acceptance; risks; rollback; manual verification. Jeśli nie da się tego zrobić bez mieszania domen, pakiet trzeba podzielić zachowując identyfikator nadrzędny i sufiksy `A/B`.

