# Baseline możliwości systemu

Klasyfikacja dotyczy dowodów w workspace, nie deklarowanej produkcji. Kategorie mogą się nakładać.

## 1. Funkcje kompletne i działające

**Brak funkcji potwierdzonych dynamicznie.** Nie uruchomiono aplikacji, testów ani bazy. Funkcje F3 są najbardziej rozwinięte statycznie, ale nie spełniają kryterium „działające”.

## 2. Funkcje statycznie rozwinięte, wymagające obsługi technicznej

ADMIN-003, MASTERDATA-001/003/004/005, RECIPE-001/002, PRICING-001/002, SALES-001/003, DOCUMENT-001/002/004, SHIPPING-001/002, PRODUCTION-001, COST-001. Mają backend i UI, lecz wymagają runtime, migracji, danych, bezpieczeństwa i testów.

## 3. Funkcje powierzchowne

ADMIN-002, SALES-005/006, DOCUMENT-003, PRODUCTION-005, INVENTORY-002/003/005, PURCHASING-002, EMPLOYEE-002, ANALYTICS-003, OBSERVABILITY-001. Główny powód: kod Agent bez potwierdzonego wejścia albo fragment bez pełnego UI/procesu.

## 4. Funkcje niedokończone

RECIPE-003/004/005, SALES-002/004, PRODUCTION-002, INVENTORY-001, PURCHASING-001, EMPLOYEE-001, ANALYTICS-001/002, INTEGRATION-001/002. Widoczny jest realny fragment procesu, lecz brakuje elementów zamykających.

## 5. Funkcje nieużywane lub niepotwierdzone jako używane

Całe `Agent/api_app_dir`, starsze `ToRefactor`, backupy i `.tmp` należy uznać za **status usage unknown**, nie automatycznie za martwy kod. Potwierdzenie wymaga manifestu deploymentu, telemetryki lub route coverage.

## 6. Funkcje zduplikowane

- auth: sesyjny App, nowy JWT/RBAC Agent i stary `LoginToken`;
- faktury: `document_invoice*` App oraz `client_invoice*` Agent;
- magazyn: `carton_stock`, `material_inventory*`, `product_movement`;
- sprzedaż: zamówienie/WDT App i transaction POS Agent;
- zależności: root/public Composer oraz wielokrotne biblioteki PDF/mail.

## 7. Funkcje oparte na niewiarygodnych lub niezweryfikowanych danych

ANALYTICS-001/002/003, COST-001 (wartości), INVENTORY-001/002/005, PRODUCTION-001/002, EMPLOYEE KPI. Nie stwierdzono błędnych rekordów; nie wykonano uzgodnień.

## 8. Funkcje potencjalnie niebezpieczne

ADMIN-001 i mechanizm logowania App są krytycznie niebezpieczne. Ryzykowne są także mutujące trasy bez wykrytego CSRF, debug/error disclosure, masowe recipe recalculation, workery integracyjne bez potwierdzonej konsoli i wielotabelowe operacje o niepełnej transakcyjności.

## 9. Funkcje wymagające decyzji biznesowej

- źródło prawdy sprzedaży i moment uznania przychodu;
- znaczenie WDT wobec sprzedaży/faktury;
- statusy i reguły anulacji zamówień/dokumentów;
- definicja zlecenia produkcyjnego, odpadu, braku i QC;
- wycena magazynu i metoda FIFO/FEFO;
- alokacja kosztów i definicja marży;
- zakres tenantów: firma/sklep/zakład/dostawca;
- polityka KPI pracowniczych.

## 10. Funkcje wymagające przebudowy modelu danych

PRODUCTION-003/004, INVENTORY-003/004, PURCHASING-003, EMPLOYEE-003 oraz historyczna analityka kosztu/marży. Wymagają zdarzeń, historii, jednoznacznych kluczy źródłowych i reguł integralności, a nie tylko nowych ekranów.

