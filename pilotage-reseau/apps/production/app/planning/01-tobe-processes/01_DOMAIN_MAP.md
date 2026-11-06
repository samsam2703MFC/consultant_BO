# Mapa domen TO-BE

| Domena | Odpowiedzialność | Główne agregaty | Publikowane zdarzenia | Nie jest właścicielem |
|---|---|---|---|---|
| Organizacja | firmy, zakłady, sklepy, waluty, okresy | Organization, Site, Shop, FiscalPeriod | SiteActivated, PeriodClosed | pracowników i magazynu |
| Identity & Access | konta, role, zakres organizacyjny | User, Role, PermissionGrant | AccessGranted/Revoked | danych HR |
| HR & Work | pracownicy, przypisania, grafik, czas, zadania | Employee, Assignment, Schedule, TimeEntry | ShiftCompleted, TimeApproved | loginów i płac |
| Master Data | produkty, warianty, materiały, jednostki, opakowania, kategorie | Product, Material, Unit, Package | MasterDataActivated | cen i stanów |
| Recipe/Technology | receptury, wersje, półprodukty, operacje | Recipe, RecipeVersion | RecipeVersionActivated | rzeczywistego zużycia |
| Pricing | cenniki sprzedaży i zakupu, promocje, voucher rules | PriceList, Price, Promotion, Voucher | PriceActivated | faktów sprzedaży |
| CRM & Orders | klienci, zamówienia i zobowiązania | Customer, SalesOrder | OrderConfirmed/Cancelled | płatności i stock ledger |
| Sales | transakcje detaliczne, zwroty, korekty | Sale, SaleLine, Return | SaleCompleted/Corrected | dokumentów magazynowych |
| Payments | płatności, alokacje, zwroty środków | Payment, PaymentAllocation | PaymentSettled/Refunded | faktur |
| Documents | faktury, korekty, proformy, WZ/WDT/CMR | BusinessDocument | DocumentIssued/Corrected | zamówień i ruchów |
| Demand Planning | prognozy, popyt, plan | Forecast, Demand, ProductionPlan | PlanApproved | wykonania produkcji |
| Production | zlecenia, wykonanie, QC i partie produkcyjne | ProductionOrder, ProductionBatch | ProductionStarted/Completed | receptur i księgi ruchów |
| Inventory | magazyny, lokalizacje, partie, rezerwacje, ruchy | Warehouse, Location, Lot, Reservation, StockMovement | StockMoved/Reserved | zamówień i kosztów okresu |
| Purchasing | zapotrzebowania, PO, dostawy i reklamacje | PurchaseRequest, PurchaseOrder, SupplierClaim | POApproved, DeliveryAccepted | kartoteki dostawcy |
| Supplier Management | dostawcy, warunki, ocena | Supplier, SupplierTerms, SupplierScore | SupplierApproved | zamówień zakupowych |
| Logistics | przesyłki, trasy, przewoźnicy, transport | Shipment, TransportOrder | ShipmentDispatched/Delivered | sprzedaży księgowej |
| Quality & Waste | kontrola jakości, niezgodności, straty | QualityInspection, Nonconformity, WasteRecord | LotReleased/Rejected, WasteRecorded | stanów — zleca ruch Inventory |
| Costing | koszty standardowe/rzeczywiste, alokacje, okres | CostEntry, CostSnapshot, CostingPeriod | CostPeriodClosed | faktów operacyjnych |
| Reporting | odczytowe KPI i eksporty | ReportDefinition, SemanticMeasure | ReportGenerated | zapisów transakcyjnych |
| Integration | inbox/outbox, mapowania, retry | IntegrationEvent, ExternalMapping | IntegrationFailed/Recovered | logiki domenowej |
| Administration | numeracja, słowniki, konfiguracja i naprawy | NumberSequence, Dictionary, RepairCase | ConfigurationChanged, RepairExecuted | bezpośredniej edycji tabel domenowych |
| Audit & Observability | niezmienny audit, health, trace i alert | AuditEvent, ProcessTrace | AlertRaised | wykonywania komend biznesowych |

## Zależności kierunkowe

```text
Master Data -> Recipe -> Production -> Inventory -> Costing -> Reporting
Pricing -> Orders/Sales -> Payments/Documents -> Reporting
Supplier -> Purchasing -> Inventory -> Costing
HR -> Production/Sales/Tasks -> Costing/Reporting
Organization + Identity -> wszystkie domeny
Integration + Audit -> otaczają wszystkie procesy, nie przejmują ich logiki
```

## Reguła źródła prawdy

Każdy agregat ma dokładnie jedną domenę zapisującą. Inne domeny przechowują identyfikator i historyczny snapshot tylko wtedy, gdy wymaga tego dokument lub rozliczenie. Raportowanie nigdy nie aktualizuje tabel operacyjnych.

