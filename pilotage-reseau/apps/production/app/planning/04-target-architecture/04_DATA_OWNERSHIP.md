# Własność danych i źródła prawdy

## Zasady

- `System właścicielski` oznacza jedyny moduł uprawniony do semantycznego zapisu.
- Inny moduł może utrzymywać snapshot lub read model, ale nie koryguje źródła prawdy.
- Konflikt rozstrzyga właściciel na podstawie version/expected state; integracja nie stosuje last-write-wins dla faktów.
- Retry komendy mutującej wymaga tego samego idempotency key.

| Dane | System właścicielski i sposób zapisu | Odczytujący | Synchronizacja | Konflikt / retry / idempotencja | Korekta |
|---|---|---|---|---|---|
| produkty | Master Data; komendy create/revise/activate/deactivate z optimistic version | Recipe, Pricing, Sales, Inventory, Purchasing, Production, Reporting | bezpośredni interfejs wewnętrzny; publikacja ProductChanged do read models/klientów | konflikt wersji odrzucany; retry po product command key | nowa rewizja bieżącej kartoteki; snapshot faktu bez zmian |
| receptury | Recipe; zapis całej wersji atomowo, aktywacja osobną komendą | Planning, Production, Costing, Reporting | interfejs snapshot + RecipeVersionActivated | konflikt wersji/overlap blokowany; aktywacja idempotentna per version | nowa wersja lub withdraw; aktywnej wersji nie edytować |
| cenniki | Pricing; wersje z validity/approval | Sales, Documents, Reporting, POS read cache | API quote/snapshot; PricePublished przez outbox do klientów | konflikt zakresu/okresu blokowany; publish key per version | nowa wersja/correction price; fakty zachowują snapshot |
| klienci | Master Data; lifecycle i datowane zmiany | Sales, Documents, Logistics, Reporting | interfejs wewnętrzny; CustomerChanged | optimistic lock; duplikat VAT ID do controlled merge | rewizja/merge z audit; dokument zachowuje snapshot |
| dostawcy | Master Data; approval/status/warunki | Purchasing, Documents, Reporting, portal | API portalu odczytuje scope; SupplierChanged | konflikt danych prawnych wymaga review; retry command key | nowa rewizja; suspension nie zmienia starych PO |
| zamówienia | Sales; customer order aggregate i state machine | Planning, Inventory reservation, Logistics, Documents, Reporting | synchroniczne komendy; OrderConfirmed/Fulfilled przez outbox/read model | expected aggregate version; submit/import idempotency key | revision przed fulfilment; po skutku correction/cancel workflow |
| sprzedaż | Sales; canonical sale line events | Payments, Inventory, Documents, Costing, Reporting | finalizacja synchroniczna w Core; publikacja SaleCompleted | external/source sale ID unique; retry daje ten sam wynik | return/correction event powiązany z oryginałem |
| płatności | Payments; payment attempt + provider reference/status | Sales, Documents, Reporting | sync request do adaptera, status webhook/poll inbox | provider/idempotency key; timeout = UNKNOWN, najpierw status check | refund/chargeback jako nowe zdarzenie, nie zmiana SETTLED |
| stany magazynowe | Inventory; projekcja `inventory_balance` z ruchów | wszystkie operacje i Reporting | synchroniczny query; read models po StockMoved | projection version; retry nie zapisuje salda bez ruchu | wyłącznie przez reversal/adjustment movement |
| ruchy magazynowe | Inventory; immutable `stock_movement` | Production, Sales, Purchasing, Costing, Reporting | interfejs `postMovement`; StockMoved do read models | source type+line+effect unique; ten sam key zwraca movement ID | osobny ruch przeciwny z reason/original ID |
| produkcja | Production; order i immutable execution events | Inventory, Costing, Workforce, Reporting | komendy wewnętrzne; Production* events | order version + operation idempotency key | correction/reopen case i zdarzenia różnicowe |
| koszty | Costing; immutable cost entries i calculation snapshots | Sales margin, Reporting, Period | source events → entries; kalkulacja/rebuild read model | source event+cost type unique; calculation version | adjustment entry; nigdy overwrite posted cost |
| pracownicy | Workforce dla HR detail, IAM dla access link | Production, Sales, Costing, Reporting | interfejs assignment snapshot; EmployeeAssignmentChanged | identity merge tylko kontrolowany; version check | datowana zmiana/termination; historyczne snapshoty bez zmian |
| czas pracy | Workforce; time entries OPEN→APPROVED→LOCKED | Costing, Production, Reporting | synchroniczne approve; TimeApproved event | overlap unique/business validation; submission key | delta/correction entry po lock z approval |
| dokumenty | Documents; immutable issued document, render metadata i correction | Sales, Purchasing, Logistics, Reporting, external adapters | issue sync; render/send async outbox | number unique per scope/type/year; send idempotency by document+version+channel | dokument korekty; draft można anulować, issued nie edytować |
| konfiguracja | Organization/IAM oraz właściciel domeny; typed settings z validity | właściwe moduły i UI admin | odczyt cache z version; ConfigChanged invaliduje cache | key+scope+validity unique; version check | nowa wersja; sekrety nie należą do konfiguracji biznesowej |

## Snapshot kontra kopia konkurencyjna

Snapshot ma identyfikator źródła, czas i wersję, jest niezmienny i służy interpretacji faktu. Nie przyjmuje nowych komend. Kopia konkurencyjna przyjmuje zapis i próbuje synchronizować się dwukierunkowo — tego modelu należy unikać.

## Przejściowe źródła AS-IS

| Obszar | Źródło przejściowe | Rola migracyjna | Warunek przełączenia |
|---|---|---|---|
| sprzedaż B2B | `customer_order*`, `document_wdt*` | backfill/order adapter i reconciliation | canonical sale uzgodniona dla zamkniętego okresu |
| POS | Agent `transaction*` po potwierdzeniu | source adapter z external ID | deployment/data contract potwierdzone; duplicate test PASS |
| wyroby | `carton_stock` | kontrolowane saldo otwarcia/projekcja legacy | ledger i saldo uzgodnione, brak bezpośrednich zapisów legacy |
| materiały | Agent `material_inventory*` po potwierdzeniu | kandydat do backfill ruchów/salda | kompletność logu i mapping jednostek potwierdzone |
| koszty | `cost_month*` | source entries typu monthly/allocated | method/version i reconciliation wdrożone |
| receptury | `product_recipe*`/flattened | wersja startowa oznaczona jako migrated | graf zwalidowany, snapshot/hash utworzony |
