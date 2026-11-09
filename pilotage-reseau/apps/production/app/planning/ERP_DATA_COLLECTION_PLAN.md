# ERP Data Collection Plan

## Zasada wartości dowodowej

Najpierw utrwalać niezmienny fakt, potem korektę i audyt, następnie snapshot, kontrolę jakości i reconciliation, a na końcu agregat. Danych nie wolno „uzupełniać” domyślnym zerem/jedynką, jeśli są nieznane; używać `INCOMPLETE`, `UNKNOWN` i quality flags.

## Dane zbierane natychmiast

| Od kiedy | Dane | Minimalny zapis | Dlaczego nie można czekać |
|---|---|---|---|
| F0 | deployment/runtime/config metadata | app/version/environment/entrypoint/cron/owner/last seen; secret tylko reference/fingerprint | później nie da się dowieść aktywnych konsumentów i właściwego cutoveru |
| F1 | identity/audit context | user/employee/service, role/scope snapshot, action, reason/approver, correlation, business/recorded UTC | wspólne konta i późniejsze zmiany niszczą atrybucję |
| F1 | integration message history | message/event ID, business key, payload version/hash, attempts, external ID, outcome | utraty/duplikatu i retry nie da się później wiarygodnie ustalić |
| F2 | master/config revisions | canonical/legacy IDs, valid dates, version, actor, changed fields | kartoteki, ceny, VAT i polityki zmieniają interpretację faktów |
| F2 | RecipeVersion | hash/graf/skład/yield/unit/status/validity | bieżącej receptury nie można przypisać wstecz do produkcji |
| od pierwszego canonical sale w F3 | sale/return/correction | line, qty/unit, price/VAT/currency, payment links, source IDs, correction link, snapshots | późniejsza rekonstrukcja z WDT/invoice/payment jest niejednoznaczna |
| od pierwszego ledger movement w F3 | każdy ruch stock | product/lot/location, signed qty/unit, source document/event, actor, cost reference, reversal | saldo nie zachowuje przyczyny ani historii |
| od pierwszego receipt w F3 | zakup/dostawa | PO/receipt line, supplier, price/VAT/currency, expected/received, discrepancy/claim | koszt zakupu i supplier SLA są nieodtwarzalne bez faktu w czasie |
| od pierwszego production order w F3 | wykonanie produkcji | order/RecipeVersion, start/pause, consume/output/waste/QC, qty/lot/time/actor | plan nie dowodzi wykonania |
| od pierwszego time pilot w F3 | actual time | employee assignment snapshot, order/operation/role/shift, approved duration, correction | grafik nie odtwarza roboczogodzin |
| od pierwszego źródłowego kosztu w F3/F6 | CostEntry/Adjustment | source/type/object/amount/currency/method/period/completeness | późniejsza alokacja bez źródła jest arbitralna |
| od pierwszej korekty | correction/reopen facts | original ID, delta, reason, actor/approver, business date/period | overwrite usuwa pierwotny wynik i odpowiedzialność |

## Dane do backfillu

| Obszar | Strategia | Minimalna walidacja | Oznaczenie jakości |
|---|---|---|---|
| identity/org | map user/employee/shop/site; raport konfliktów i kont wspólnych | counts, duplicate identity, orphan scope | `MAPPED`, `CONFLICT`, `LEGACY_SHARED` |
| master data | canonical record + legacy ID alias; dedup tylko po review | count, uniqueness, orphan links | source/confidence/transform version |
| receptury | bieżący graf jako reconstructed RecipeVersion 1 | cycle/hash/yield/ingredient compare | `RECONSTRUCTED_BASELINE` |
| ceny/VAT | bieżąca wartość jako first version; changelog tylko jeśli spójny | overlap/gap, sample document compare | `CURRENT_BASELINE` lub `HISTORICAL_VERIFIED` |
| sprzedaż | wybrane zamknięte okresy z WDT/order/POS/document mappings | line/tax/payment/correction totals i trace sample | `RECONSTRUCTED`, nie pełna sprzedaż bez dowodu |
| inventory | zatwierdzony opening balance w control date; historyczne ruchy tylko jeśli kompletne | physical/control stock, value, lot/location | `OPENING_BALANCE`; lineage do stocktake |
| otwarte PO/orders/production | migrować tylko obiekty o jednoznacznym stanie; reszta kończy się w legacy | status/qty/value and dependency check | `MIGRATED_OPEN` lub `LEGACY_REFERENCE` |
| documents | metadata, number, issue snapshot/file hash, correction links | uniqueness, tax totals, accessible artifact | `ISSUED_VERIFIED`/`ARCHIVE_ONLY` |
| costs/periods | `cost_month` jako source/reconstructed period, nie canonical ledger | source totals, completeness, closed report | `ESTIMATED_RECONSTRUCTED` |
| read models | rebuild od control date z canonical facts | totals, watermark, drill-down, performance | status KPI per period/scope |

## Dane niemożliwe lub niewiarygodne do odtworzenia

- dokładna historyczna RecipeVersion, jeśli bieżący graf był nadpisywany bez changelogu;
- przyczyna i czas historycznego ruchu wyprowadzone tylko z bieżącego salda;
- historyczna partia/expiry/genealogia bez zapisów w chwili zdarzenia;
- rzeczywiste zużycie/output/waste/QC z samego planu produkcji;
- actual labor per order z grafiku lub miesięcznego kosztu;
- cena bazowa/promocja/VAT snapshot, jeśli zachowano tylko kwotę końcową;
- prawdziwy moment sprzedaży/zwrotu z samego WDT, faktury lub płatności bez polityki/linków;
- reason/actor/approval korekty po bezpośrednim update;
- pełny koszt partii/klienta bez source cost and allocation lineage.

Takie okresy pozostają `UNVERIFIED/RECONSTRUCTED/INCOMPLETE`; nie wolno tworzyć pozornej precyzji.

## Dane wymagające migracji

Najwyższy priorytet: users/employees/scopes, canonical IDs i units; recipe/current prices jako baseline; inventory opening; aktywne integrations/messages; otwarte orders/PO; documents metadata; następnie wybrane sale/cost periods. Każdy batch zapisuje source system/table/ID, canonical ID, transform version, timestamp, status/rejection, checksum i quality flag.

## Moment rozpoczęcia kosztów historycznych

Zbieranie kosztu historycznego zaczyna się **przed Fazą 6**: od pierwszego canonical receipt/movement/sale/production/time w F3 oraz od pierwszego CostEntry adaptera. Faza 6 konsoliduje, alokuje i zamyka te dane; nie może odzyskać źródeł, których F3 nie zapisała. Datę kontrolną kosztu zatwierdza controller wraz z metodą BD-06.

## Moment uruchomienia wiarygodnych KPI

KPI otrzymuje `AVAILABLE` osobno dla zakresu/okresu dopiero gdy ma:

1. zatwierdzoną definicję i ownera;
2. jeden canonical fact;
3. historię/snapshot i correction chain;
4. quality rules i watermark;
5. reconciliation do dokumentu/control total;
6. drill-down i permission;
7. stabilność po zmianie kartoteki i zamknięciu okresu, jeśli dotyczy.

Pierwsze operacyjne KPI mogą wejść w F5 po slice’ach F3. Marża/rentowność dopiero w F6 po cost ledger/period. Forecast metrics dopiero w F7 po jakościowej historii i baseline.

## Odpowiedzialność i monitoring

Każdy dataset ma business ownera, technical ownera, DQ rules, freshness SLA, lineage i incident path. Monitorować count/null/duplicate/orphan, sums, min/max time, late-arriving facts, correction rate, source mismatch i udział `INCOMPLETE/RECONSTRUCTED`. Status jakości jest widoczny użytkownikowi raportu.

