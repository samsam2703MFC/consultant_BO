# ERP Decision Register

## Zasady

`OPEN` oznacza brak potwierdzonej decyzji właściciela; rekomendacja z analiz nie jest decyzją. `ACCEPTED` oznacza kierunek techniczny przyjęty w docelowej architekturze, nadal podlegający weryfikacji dowodami F0. Termin jest bramą roadmapy, nie datą kalendarzową. Właściciel roli musi zostać przypisany imiennie przez sponsora.

## Decyzje biznesowe

| ID | Decyzja | Status | Właściciel roli | Wymagana przed | Blokowane procesy/inicjatywy | Rekomendacja robocza |
|---|---|---|---|---|---|---|
| BD-01 | moment uznania sprzedaży i canonical source per kanał | OPEN | właściciel sprzedaży + finanse | projekt ERP-016 / F3 | SAL/DOC/COST; ERP-016/018/030/038 | polityka per kanał; nie utożsamiać sale z payment ani document |
| BD-02 | ujemny stan magazynowy | OPEN | właściciel magazynu + finanse | projekt ERP-019/021 | sale issue, production, stocktake | blokada dla magazynu/produkcji; audytowany wyjątek tylko dla wskazanego kanału/roli |
| BD-03 | zakres partii i expiry | OPEN | quality + magazyn | model ERP-020 / F2 | receipt, inventory, production, recall | klasy ryzyka/obowiązek, nie wszystkie indeksy automatycznie |
| BD-04 | make-to-order/make-to-stock | OPEN | produkcja + planowanie | ERP-023/034 | plan, reservation, production order | hybryda per produkt z jawną strategią replenishment |
| BD-05 | QC/quarantine/release | OPEN | quality owner | ERP-020/024 | receipt, production, available stock | kontrola dla klas ryzyka; quarantine blokuje użycie |
| BD-06 | metoda wyceny stock/production | OPEN | CFO/controller + księgowość | ERP-019/028 | inventory value, cost, margin, close | zatwierdzić księgową i zarządczą; przechowywać źródła umożliwiające reprodukcję |
| BD-07 | korekta zamkniętego okresu | OPEN | CFO/controller | ERP-006 contract, najpóźniej ERP-029 | document/stock/production/cost correction | progi: adjustment current period lub controlled reopen; owner finansowy |
| BD-08 | partial/backorder/substitution | OPEN | sprzedaż + logistyka | ERP-017/025 | order, reservation, shipment, planning | polityka per klient/zamówienie; zamiennik zawsze jawny |
| BD-09 | approval thresholds i SoD | OPEN | risk/finance owner | ERP-006/005 policy | purchase, discount, correction, stocktake, period | mała liczba progów wartości/odchylenia; no self-approval |
| BD-10 | organization/tenant/isolation | OPEN | sponsor + security/data owner | ERP-005 / F1 | wszystkie permissions, numbering, reporting | jedna baza z organization scope tylko przy wspólnej administracji; inaczej instancje |
| BD-11 | jurysdykcje, fiskalizacja, e-faktury | OPEN | finanse/legal | ERP-015/018 | sale correction, tax, numbering, Peppol | jawnie ograniczyć MVP do potwierdzonych krajów/kanałów |
| BD-12 | tryb offline POS | OPEN | operations/sales owner | ERP-007/016 | POS sync, numbering, inventory, payment | kolejka lokalna tylko jeśli wymóg potwierdzony; inaczej online |
| BD-13 | cel i granularność czasu pracy | OPEN | HR + produkcja/controller | ERP-026/027 | labor cost, productivity, privacy | approved time per order/operation, jeśli ma zasilać cost; minimum danych |
| BD-14 | tolerancje delivery/invoice discrepancy | OPEN | purchasing + finance/quality | ERP-022 | receipt, claim, cost, supplier KPI | progi per typ różnicy, z approval powyżej tolerancji |
| BD-15 | retencja, legal hold i anonimizacja | OPEN | legal/privacy + data owner | ERP-006 audit; przed ERP-040 purge | audit, HR, documents, sales, retirement | polityka per kategoria/jurysdykcja; anonimizować PII bez niszczenia faktu |

## Decyzje techniczne

| ID | Decyzja | Status | Właściciel roli | Potwierdzić do | Blokowane inicjatywy | Kierunek przyjęty |
|---|---|---|---|---|---|---|
| TD-01 | styl systemu | ACCEPTED | główny architekt | po F0 | granice wszystkich modułów | modularny monolit, bez usług per domena |
| TD-02 | inventory source of truth | ACCEPTED | architekt + inventory owner | przed ERP-019 | ERP-019–025/028 | immutable movement ledger + balance projection |
| TD-03 | model ruchu/korekty stock | ACCEPTED | architekt domeny | przed ERP-019 | transfer/stocktake/production/receipt | signed movement, reversal zamiast update/delete |
| TD-04 | receptury | ACCEPTED | architekt + technolog | przed ERP-013 | production/cost/history | immutable RecipeVersion, graph validation, snapshot na zleceniu |
| TD-05 | koszt historyczny | ACCEPTED | architekt + controller | przed ERP-028 | close/profitability | source CostEntry/Adjustment + method/version + snapshots |
| TD-06 | komunikacja modułów | ACCEPTED | główny architekt | F1 | wszystkie cross-domain effects | application command + domain/public event; brak zapisów cudzych tabel |
| TD-07 | reporting | ACCEPTED | data architect | przed ERP-031 | KPI/dashboard/controlling | rebuildable read models w tej samej DB na start |
| TD-08 | audit | ACCEPTED | security/data architect | ERP-006 | self-service/corrections | append-only audit, oddzielny od business events/logów |
| TD-09 | idempotency | ACCEPTED | integration architect | ERP-007 | integrations/retry/POS | stable key + unique constraint + recorded outcome |
| TD-10 | permission model | ACCEPTED | security architect | ERP-005 | każdy moduł/admin | resource.action + org scope + validity, server-side default deny |
| TD-11 | dokumenty | ACCEPTED | document/finance architect | ERP-018 | numbering/correction/send | canonical metadata + immutable issued snapshot + renderer/channel adapters |
| TD-12 | sposób migracji | ACCEPTED | główny architekt/DBA | ERP-003 | wszystkie replacement/retire | incremental capability slices, shadow/reconcile/cutover/observe |

## Zasady zarządzania decyzją

Każda decyzja ma datę, osobę, dowody, wybraną opcję, konsekwencje, zakres pilota i inicjatywy do aktualizacji. Brak decyzji przed gate powoduje odroczenie zależnego milestone’u, nie ciche przyjęcie rekomendacji. Zmiana decyzji po write cutover wymaga impact analysis i domenowej migracji/korekty, nie edycji historii.

