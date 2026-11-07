# Wejście do projektu architektury

## Cel architektury

Architektura ma zamknąć luki procesowe i danych przy zachowaniu realności utrzymania przez jednego programistę. Nie powinna optymalizować hipotetycznej skali kosztem spójności biznesowej.

## Wymagane granice modułów

| Moduł | Odpowiedzialność | Własne źródło prawdy | Kluczowe luki |
|---|---|---|---|
| Organization & IAM | tenant context, user–employee, role/permission, access lifecycle | organizacja, assignment, grant/session | GAP-001–004/042 |
| Master Data | produkty, materiały, jednostki, opakowania, kontrahenci | bieżące kartoteki + historia pól | GAP-007–010/048 |
| Recipe & Pricing | recipe versions, cenniki, VAT, kalkulacje | wersje receptur i cen, cost calculation snapshots | GAP-011–015 |
| Sales | order, sale, payment, correction | sales/order/payment events | GAP-016–020 |
| Documents | numery, invoice/proforma/logistics docs, corrections | prawny dokument i jego korekta | GAP-021–024 |
| Planning & Production | forecast/plan/order/execution/QC | production order/events/lots | GAP-025–031 |
| Inventory | movement ledger, balance, reservation, transfer, stocktake | stock movement i lot | GAP-032–036 |
| Purchasing & Logistics | requisition, PO, receipt, discrepancy, shipment | PO/receipt/shipment facts | GAP-037–041 |
| Workforce | employee lifecycle, schedule, actual time, task labor | time event i assignment | GAP-042–044 |
| Costing & Period | cost ledger, allocation, calculation, close/reopen | cost entry, period, report snapshot | GAP-045–047 |
| Integration & Operations | outbox/inbox, adapters, retry, audit, health | message/audit/incident state | GAP-050–053 |
| Reporting Read Models | rebuildable aggregates and reconciliation | nie jest source of truth | GAP-016/045/048/055 |

## Wspólne mechanizmy obowiązkowe

1. **Command context:** organization, actor user/employee, permission, business timestamp, correlation i idempotency.
2. **State machines:** jawne przejścia w domenie, nie dowolne statusy z UI.
3. **Immutable facts:** zatwierdzone ruchy, sale, production, cost i correction nie są edytowane/usuwane.
4. **Correction contract:** original event, delta, reason, actor, approver i period.
5. **Temporal/snapshot contract:** wartości interpretacyjne zachowane na fakcie.
6. **Outbox/inbox:** zapis wiadomości atomowy z transakcją domenową, idempotentny odbiór.
7. **Audit:** jeden schemat zdarzenia, wyszukiwanie po correlation/aggregate/actor.
8. **Period control:** walidacja backdate i jawne close/reopen.
9. **Schema lifecycle:** jedna kolejność migracji, baseline i constraints.
10. **Data quality/reconciliation:** reguły uruchamialne automatycznie i wynikające z source of truth.

## Wymagania transakcyjne

- zapis aggregate i jego outbox w jednej transakcji;
- stock movement i zmiana projekcji salda atomowe albo projekcja odbudowywalna;
- produkcyjne consume/output/loss muszą zachować spójność z orderem i ledgerem;
- finalizacja sale nie może pozostawić settled payment bez rozpoznawalnego stanu sale;
- numer dokumentu nie może zostać użyty ponownie po błędzie;
- retry zawsze używa tego samego idempotency key;
- optimistic locking/version check dla edytowanych aggregate;
- constrainty tenant/FK/unique/check są drugą linią ochrony, nie zamiennikiem reguł domenowych.

## Kontrakty danych przekrojowych

| Kontrakt | Minimalna treść |
|---|---|
| ProductSnapshot | product ID, SKU/name/category, unit/conversion, package, VAT class |
| PriceSnapshot | base/final/net/gross, discount, VAT, currency/rate, price source/version |
| RecipeSnapshot | recipe version/hash, yield, ingredients/units |
| CostSnapshot | method/version/date/currency, unit/total and component lineage |
| ActorSnapshot | user, employee, role/assignment, organization/location |
| SourceReference | source type/ID/line/version and correlation |
| CorrectionReference | original event, correction type/reason/approval and deltas |

## Niefunkcjonalne wymagania wynikające z luk

- default deny i izolacja danych organizacji;
- brak sekretów w repo i bezpieczna rotacja;
- pełna odtwarzalność od schematu po raport;
- odporność na duplicate/retry/worker crash;
- obserwowalność każdej operacji krytycznej;
- możliwość odtworzenia zapasu i raportu na datę;
- kontrolowana retencja/anonimizacja;
- wydajność krytycznych list i zapisów potwierdzona testem;
- degradacja jawna: brak danych daje błąd/INCOMPLETE, nie zero;
- prostota: modularny monolit i jedna baza do czasu realnego uzasadnienia wydzielenia.

## Elementy AS-IS warte ponownego użycia

- widoki i formularze produktów, materiałów, klientów, receptur, zamówień i dokumentów po wpięciu w nowe reguły;
- generatory PDF/CSV/JSON jako adaptery wyjściowe;
- część repozytoriów/changelogów po dodaniu constraints i transakcji;
- algorytm listy potrzeb jako wejście do planu, nie actual;
- istniejące dane kosztów miesięcznych jako source entries/estimates;
- sprawdzone fragmenty outbox/workers po ujednoliceniu kontraktu;
- modele Agent wyłącznie po potwierdzeniu deploymentu, danych i kompatybilności.

## Czego architektura nie powinna zakładać

- że Agent jest aktywny;
- że WDT jest zawsze sprzedażą;
- że `carton_stock` lub `material_inventory` jest kompletnym ledgerem;
- że bieżąca receptura/cena nadaje się do historii;
- że grafik jest czasem rzeczywistym;
- że brak rekordu oznacza zero;
- że nazwa `changelog`, `outbox` lub `correction` dowodzi pełnego workflow;
- że mikrousługi, event sourcing lub hurtownia danych są wymagane do MVP.

## Decyzje wymagane przed zamrożeniem architektury

BD-01, BD-02, BD-03, BD-04, BD-06, BD-10, BD-11, BD-12 i BD-13 wpływają bezpośrednio na granice aggregate, klucze i transakcje. Pozostałe decyzje wpływają na workflow, progi i retencję. Ich brak powinien być jawnie oznaczony w projekcie, nie zastępowany przypadkowym założeniem.
