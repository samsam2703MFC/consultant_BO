# Macierz własności danych

`ERP Core` oznacza modularny monolit i jedną bazę transakcyjną. Systemy zewnętrzne nie zapisują bezpośrednio tabel ERP; wysyłają komendy lub zdarzenia przez Integration Inbox.

| Dane | System źródłowy / zapisujący | Odczytujący | Źródło prawdy | Synchronizacja | Korekta | Historia |
|---|---|---|---|---|---|---|
| Organizacje, zakłady, sklepy | ERP Organization | wszystkie moduły | ERP | event po aktywacji | nowa wersja danych/adresu | temporal validity + audit |
| Produkty i warianty | ERP Master Data | Pricing, Sales, Recipe, Inventory, Reporting | ERP | ProductActivated/Changed | edycja draft; aktywne pola istotne wersjonowane | wersje i snapshoty na dokumentach |
| Materiały | ERP Master Data | Recipe, Purchasing, Inventory, Quality | ERP | MaterialActivated/Changed | kontrolowana zmiana; brak delete po użyciu | ważność cech i audit |
| Jednostki/konwersje | ERP Master Data | wszystkie ilościowe domeny | ERP | cache read model | nowa reguła od daty; użyte konwersje niezmienne | wersja konwersji w linii dokumentu |
| Opakowania | ERP Master Data | Product, Pricing, Production, Costing | ERP | event | wersja od daty | snapshot ilości/kosztu |
| Receptury | ERP Recipe | Production, Costing, Quality | konkretna RecipeVersion | tylko event aktywacji | nowa wersja; nigdy edit active | pełny immutable version graph |
| Ceny sprzedaży | ERP Pricing | Orders/POS/Sales | PriceList version | publikacja wersji do read model/POS | nowa wersja od daty | snapshot na order/sale line |
| Ceny zakupu | ERP Purchasing/Pricing | PO, Costing | zatwierdzona SupplierPrice + snapshot PO | event po approval | nowa cena; retro adjustment osobno | okres obowiązywania i źródło |
| Klienci | ERP CRM | Orders, Documents, Reporting | ERP CRM | portal/API przez komendy | merge/correction case | audit zgód, limitów i danych prawnych |
| Dostawcy | ERP Supplier Management | Purchasing, Quality, Costing | ERP | mapping zewnętrzny | wersja danych/warunków | status i score history |
| Zamówienia klientów | ERP Orders | Planning, Inventory, Documents, Reporting | SalesOrder aggregate | event outbox, external ID mapping | revision/cancel/correction command | status timeline i line revisions |
| Sprzedaż | ERP Sales | Payments, Documents, Inventory, Reporting | Sale + Correction events | POS online/offline inbox z idempotency key | correction/return, bez edit | immutable sale lines i snapshots |
| Płatności | provider + ERP Payment ledger | Sales, Documents, Reporting | provider confirmation odwzorowane w ERP | webhook inbox + reconciliation | refund/chargeback event | attempts, provider IDs, raw hashes |
| Prognoza | ERP Demand Planning | Planowanie/Reporting | zatwierdzona ForecastVersion | batch/version event | override z powodem/nowa wersja | forecast vs actual |
| Plan produkcji | ERP Demand Planning | Production, Purchasing, Reporting | approved ProductionPlan | release event | replan jako rewizja | plan versions |
| Zlecenia/wykonanie produkcji | ERP Production | Inventory, Quality, Costing, Reporting | ProductionOrder/Batch | domenowe events w tej samej transakcji outbox | reopen/correction events | status, quantities, recipe snapshot, genealogy |
| Magazyny/lokalizacje | ERP Inventory | wszystkie procesy stock | ERP Inventory | event/cache | transfer + deactivate, bez delete | audit struktury |
| Ruchy magazynowe | ERP Inventory | Stock projection, Costing, Reporting | immutable StockMovement ledger | projekcja synchroniczna; event outbox | reversal + nowy ruch | pełna księga as-of |
| Stany magazynowe | projekcja ERP Inventory | Orders, Planning, Purchasing, Reporting | suma zaksięgowanych ruchów; cache jest pochodny | transakcyjna projekcja + okresowe reconciliation | tylko przez ruch | snapshoty okresowe + ledger |
| Rezerwacje | ERP Inventory | Orders/Production/Planning | Reservation aggregate | event po zmianie | release/expire/consume | timeline |
| Partie i daty ważności | ERP Inventory + źródło Supplier/Production | Quality, Sales, Recall, Reporting | Lot record powiązany z receipt/batch | scan/import przez command | formalna correction przed/po użyciu | genealogy, status i daty |
| PO i dostawy | ERP Purchasing | Inventory, Costing, Supplier Mgmt | PO/Delivery aggregates | supplier portal/EDI przez Integration | revision, delivery correction, claim | status i ack history |
| Dokumenty | ERP Documents | Sales, Accounting export, Customer portal | issued immutable document | PDF/XML generated once; delivery retry | correction document | numer, hash, versions, delivery log |
| Transport | ERP Logistics | Orders, Inventory, Costing | Shipment/TransportOrder | carrier webhook | reschedule/return/correction | custody timeline, POD |
| Odpady i straty | ERP Quality/Production, ruch w Inventory | Costing, Reporting | WasteRecord + linked StockMovement | event atomowo z ruchem | reversal/corrected waste | reason, lot, actor, qty, value |
| Pracownicy | ERP HR | IAM, Schedule, Production, Costing | Employee | access event do IAM | HR correction | employment/assignment history |
| Uprawnienia | ERP IAM | gateway i UI | PermissionGrant | cache z krótkim TTL/event invalidation | grant/revoke | actor, scope, approver |
| Czas pracy | ERP HR Time | Costing, Reporting | approved TimeEntry | event po approval | differential correction | urządzenie, approver, revision |
| Koszty źródłowe | ERP Cost Ledger z eventów operacyjnych | Costing/Reporting | posted CostEntry | synchronous/event ingestion | CostAdjustment | lineage do źródła |
| Koszt produktu/partii/sklepu | ERP Costing | Pricing, Reporting | approved CostSnapshot | wersja po batch/period close | superseding snapshot | metoda, wersja, składniki |
| Raporty/KPI | ERP Reporting read models | użytkownicy/BI | dane źródłowe + definicja miary | rebuildable ETL/read model | poprawić źródło lub wersję definicji | lineage, generated_at, filters |
| Integracje | ERP Integration | operatorzy/domeny | Inbox/Outbox + ExternalMapping | at-least-once, idempotent consumers | retry/cancel mapping case | attempts, payload hash, response/error |

## Zasady synchronizacji

1. Lokalna transakcja zapisuje agregat, audit i outbox atomowo.
2. Odbiorca zapisuje inbox/idempotency key przed wykonaniem komendy.
3. System zewnętrzny otrzymuje identyfikator ERP oraz własny external mapping; identyfikatorów nie zgaduje się z numerów dokumentów.
4. Read model może być odbudowany. Nie jest źródłem prawdy.
5. Import nie omija walidacji domenowej.

