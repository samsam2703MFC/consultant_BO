# Wymagania audytowalności

## Minimalny AuditEvent

Każda operacja mutująca zapisuje:

- `event_id`, `occurred_at_utc`, `recorded_at_utc`;
- organization/site/shop oraz typ i ID agregatu;
- komendę, poprzedni i nowy status, wersję przed/po;
- user/employee/service/device ID oraz rolę i scope;
- correlation/causation/idempotency ID;
- źródło: UI, API, import, worker, system;
- reason code, komentarz i ticket dla wyjątku;
- bezpieczny diff bez haseł, tokenów i danych kartowych;
- identyfikatory dokumentów, ruchów i zdarzeń pobocznych;
- wynik SUCCESS/REJECTED/FAILED oraz kod błędu.

## Wymagania domenowe

| Domena | Obowiązkowa historia |
|---|---|
| Identity | login success/failure, grant/revoke, scope, impersonation, session revoke |
| Master Data | diff, data obowiązywania, aktywacja/dezaktywacja, merge |
| Recipe | pełny snapshot wersji, reviewer/approver, koszt preview, powód wycofania |
| Orders/Sales | każda rewizja linii, cena/VAT/promo snapshot, status timeline, correction link |
| Payment | provider reference, attempt, kwota/waluta, status query, refund; bez sekretów |
| Documents | numer, hash pliku, issuer, delivery attempts, correction chain, reprint |
| Production | recipe version, plan/actual quantities, lots, consumption, waste, labor, QC |
| Inventory | każda linia ruchu, lot, from/to, qty/UOM, value, source, reversal link |
| Purchasing | revision PO, approval, supplier ack, delivery/QC discrepancy i claim |
| HR/Time | assignment validity, clock source, approval, correction; minimalizacja danych |
| Costing | źródła, metoda i wersja algorytmu, alokacja, period close/reopen |
| Integration | payload hash, mapping, attempts, response code, operator retry |

## Odtwarzalność

- Stan magazynowy na dowolny moment musi być obliczalny z ledgeru; snapshot służy tylko przyspieszeniu.
- Status procesu musi być odtwarzalny z timeline lub zdarzeń.
- Wydany dokument musi być odtwarzalny binarnie przez przechowanie pliku/hashu i snapshotu danych.
- KPI musi wskazywać definicję miary, wersję, filtry, timezone, walutę i lineage.
- Koszt musi wskazywać wszystkie CostEntry i reguły alokacji.

## Retencja i dostęp

- Audit jest append-only; zwykły administrator nie może go edytować.
- Retencja zależy od prawa i decyzji biznesowej; finansowe i traceability zwykle wymagają długiego okresu.
- Dane osobowe w audit są minimalizowane i pseudonimizowane po wymaganym okresie, bez niszczenia dowodu operacji.
- Dostęp do audit i eksportów jest osobnym uprawnieniem i sam podlega audytowi.

## Obserwowalność

Każdy długi proces ma process trace z ostatnim krokiem, statusem, czasem, liczbą prób i akcją operatora. Minimalne alerty MVP: rosnący DEAD outbox, reconciliation mismatch, ujemny/nielegalny stock, niezamknięta produkcja, payment unknown, dokument niewysłany i okres z blockerami.

