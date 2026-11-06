# Przepływy międzydomenowe

## F-01 Order-to-Cash B2B

1. CRM aktywuje klienta i zakres kredytowy.
2. Pricing wyznacza cenę i VAT; Order zapisuje snapshot.
3. Potwierdzenie zamówienia publikuje popyt.
4. Inventory rezerwuje dostępne wyroby; Planning pokrywa brak produkcją.
5. Production przyjmuje gotowy wyrób do partii RELEASED.
6. Inventory wykonuje picking i issue; Logistics tworzy shipment.
7. Documents wystawia WZ/WDT/CMR, a fakturę według reguły biznesowej.
8. Payment rozlicza należność; Reporting uzgadnia order→delivery→invoice→payment.

Kompensacje: anulowanie zwalnia rezerwację; po issue wymagany return; po invoice korekta dokumentu; retry integracji nie powtarza komendy biznesowej.

## F-02 Retail Sale-to-Cash

POS pobiera wersję cennika → tworzy koszyk → Pricing zwraca snapshot → Payment potwierdza środki → Sales finalizuje sale → Inventory księguje issue → Documents fiskalizuje/drukuje → Reporting uzgadnia. Offline POS kolejkowuje transakcję z UUID urządzenia; konflikt ceny/duplikat trafia do case, nie jest cicho nadpisywany.

## F-03 Forecast/Order-to-Production

Forecast i zamówienia tworzą Demand → Planista zatwierdza ProductionPlan → ProductionOrder zamraża recipe version i ilość → Inventory rezerwuje materiały → shortage generuje PurchaseRequest → po dostępności order jest READY → wykonanie rejestruje consumption, labor i waste → QC zwalnia accepted lot → Inventory receipt → Costing zamyka batch → Reporting plan-vs-actual.

## F-04 Procure-to-Stock

Shortage/manual request → PurchaseRequest approval → PO approval/send/ack → Delivery counted → QC/quarantine → Inventory receipt lot → price/quantity discrepancy → claim albo adjustment → invoice matching poza minimalnym zakresem, jeśli finansowo wymagane → Supplier score i CostEntry.

## F-05 Inventory Transfer and Traceability

Transfer request → source reservation/pick → issue to IN_TRANSIT → custody → destination receive → lot genealogy zachowana. Brak po odbiorze tworzy discrepancy i claim/correction, a nie edycję pierwotnej ilości.

## F-06 Recipe-to-Cost-to-Price

Technolog aktywuje RecipeVersion → Costing liczy standard snapshot z cen materiałów i opakowań → reviewer zatwierdza → Pricing może użyć kosztu jako informacji do decyzji cenowej, ale nie zmienia ceny automatycznie w MVP → sprzedaż zamraża cenę i dostępny koszt referencyjny → actual cost z partii/okresu służy późniejszej analizie wariancji.

## F-07 Employee-to-Labor-Cost

HR aktywuje employee/assignment → kierownik publikuje schedule → pracownik rejestruje time → kierownik zatwierdza → Production/Task linkuje godziny do obiektu → Costing tworzy labor CostEntry → period close zamraża alokację. Korekta czasu po lock generuje różnicę w otwartym okresie.

## F-08 Complaint/Return-to-Correction

Klient zgłasza reklamację → Customer Service kwalifikuje ilość i powód → opcjonalny return shipment → Inventory przyjmuje do QUARANTINE → Quality decyduje restock/scrap → Sales/Documents tworzą zwrot/korektę → Payment refund → Costing rejestruje koszt jakości → Supplier/Production otrzymuje root-cause link.

## F-09 Period Close-to-Reporting

Reconciliation sales/payments/documents → reconciliation stock ledger/projection → zamknięcie production orders i deliveries → walidacja cost suspense → obliczenie cost snapshots → controller zatwierdza period close → Reporting publikuje wersjonowane KPI. Błąd po close prowadzi do correction lub formalnego reopen.

## F-10 Integration Failure-to-Recovery

Event outbox → delivery → inbox deduplication → domain command → success mapping. Błąd techniczny ma backoff; błąd danych tworzy case i wymaga korekty źródła; DEAD wymaga decyzji operatora. Każdy retry zachowuje event ID, attempt i correlation trace.

