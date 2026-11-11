# Znormalizowany katalog funkcjonalności

Legenda: `App` = panel i public API w aktywnym drzewie; `Agent?` = kod bez potwierdzonego punktu wejścia. Test `BLOCKED` oznacza brak runtime/DB, `NOT_TESTED` — brak potwierdzonego wdrożenia. Self-service: 0 brak, 1 techniczny, 2 częściowy, 3 główna ścieżka w UI. Wiarygodność danych: `N/A`, `LOW`, `MEDIUM`, `UNVERIFIED`.

| ID | Domena / nazwa i opis | Aplikacje | Tabele | Endpointy / widoki | Zależności | F | Test | Self | Dane | Główna luka | Pewność |
|---|---|---|---|---|---|---:|---|---:|---|---|---|
| ADMIN-001 | Admin: użytkownicy — CRUD kont panelu | App | `user` | `/users*`; `Views/user/*` | auth, DB | F2 | BLOCKED | 3 | UNVERIFIED | krytycznie niebezpieczny auth | HIGH |
| ADMIN-002 | Admin: role i uprawnienia — RBAC | Agent? | `admin_panel_user_role*`, permissions | kontrolery Admin/Auth; brak UI App | Agent deploy | F1 | NOT_TESTED | 1 | UNVERIFIED | brak spójności z polem `user.permission` | MEDIUM |
| ADMIN-003 | Admin: języki i aliasy | App+Agent? | `supported_language`, alias tables | `/aliases`, settings; alias views | tłumaczenia | F3 | BLOCKED | 3 | UNVERIFIED | brak testów i wersji słowników | MEDIUM |
| MASTERDATA-001 | Dane podstawowe: produkty — CRUD/wyszukiwanie | App | `final_product` | `/products*`; product views | kategorie, sekcje | F3 | BLOCKED | 3 | UNVERIFIED | brak audytu zmian | MEDIUM |
| MASTERDATA-002 | Dane podstawowe: kategorie produktów | App | `category_product` | `/product-categories*`; widok backup | produkty | F2 | BLOCKED | 2 | UNVERIFIED | stan widoku i regresja niezweryfikowane | MEDIUM |
| MASTERDATA-003 | Dane podstawowe: materiały | App | `raw_material` | `/materials*`; material views | jednostki, ceny | F3 | BLOCKED | 3 | UNVERIFIED | brak partii i ledgeru | MEDIUM |
| MASTERDATA-004 | Dane podstawowe: alergeny i wartości odżywcze | App | `allergen`, connections | `/ajax/allergens`, nutrition routes/views | materiały | F3 | BLOCKED | 3 | UNVERIFIED | brak E2E | MEDIUM |
| MASTERDATA-005 | Dane podstawowe: dostawcy | App | `supplier` | supplier routes/views | zakupy, materiały | F3 | BLOCKED | 3 | UNVERIFIED | niepełny lifecycle aktywności | MEDIUM |
| RECIPE-001 | Receptury: receptury finalne CRUD | App | `product_recipe*` | `/recipes*`; recipe views | materiały, jednostki | F3 | BLOCKED | 3 | UNVERIFIED | brak wersjonowania | MEDIUM |
| RECIPE-002 | Receptury: półprodukty i kompozycje | App | recipe connections | `/semi-products*`, `/compositions*` | receptury | F3 | BLOCKED | 3 | UNVERIFIED | transakcyjność i cykle | MEDIUM |
| RECIPE-003 | Receptury: kroki przygotowania | App | `product_recipe_preparation_step` | `/recipes/*/preparation-steps` | migracja 021 | F2 | BLOCKED | 2 | UNVERIFIED | schema deployment nieznany | MEDIUM |
| RECIPE-004 | Receptury/HR: kompetencje receptur | App | `recipe_competency*` | `/hr/recipe-competencies`, AJAX | migracja 023 | F2 | BLOCKED | 2 | UNVERIFIED | nowy moduł bez testu | MEDIUM |
| RECIPE-005 | Receptury: spłaszczanie i kalkulacja kosztu | App | flattened/recipe cost tables | `/ajax/settings/flat-*`, recalc | ceny materiałów | F2 | BLOCKED | 2 | LOW | TODO docelowego zapisu | HIGH |
| PRICING-001 | Ceny: cena materiału i changelog | App | `raw_material_price_changelog` | `/materials/prices`, PATCH/recalc | materiały, receptury | F3 | BLOCKED | 3 | UNVERIFIED | historia nieuzgodniona z DB | MEDIUM |
| PRICING-002 | Ceny: cennik ogólny i klienta, eksporty | App | product/customer price tables | `/price-lists*`; CSV/PDF/JSON | klient, produkt | F3 | BLOCKED | 3 | UNVERIFIED | brak testu i pełnej historii | MEDIUM |
| SALES-001 | Sprzedaż: klienci CRUD i podsumowanie | App | `customer` | `/clients*`; client views | zamówienia, WDT | F3 | BLOCKED | 3 | UNVERIFIED | auth/tenant context | MEDIUM |
| SALES-002 | Sprzedaż: sieci franczyzowe i onboarding | App | `customer_franchise_network`, token | franchise routes/views | integracja klienta | F2 | BLOCKED | 2 | UNVERIFIED | brak testu kontraktu | MEDIUM |
| SALES-003 | Sprzedaż: zamówienia klienta | App | `customer_order*` | `/orders*`; order views | klient, produkt | F3 | BLOCKED | 3 | UNVERIFIED | state machine/korekty | MEDIUM |
| SALES-004 | Sprzedaż: changelog zamówienia | App | `customer_order_changelog` | changelog view/routes | migracje 025/027 | F2 | BLOCKED | 2 | UNVERIFIED | migracja niepotwierdzona | MEDIUM |
| SALES-005 | Sprzedaż: transakcje POS | Agent? | `transaction*` | Transaction controllers; brak wejścia | sklep, pracownik | F1 | NOT_TESTED | 1 | UNVERIFIED | deploy Agent | LOW |
| SALES-006 | Sprzedaż: promocje, zwroty i korekty POS | Agent? | promo/invoice/transaction tables | PromoCode/Correction controllers | POS, faktury | F1 | NOT_TESTED | 1 | UNVERIFIED | pełny workflow nieudowodniony | LOW |
| DOCUMENT-001 | Dokumenty: proforma i outbox | App | `document_proforma*`, `proforma_outbox` | proforma routes/views | zamówienia, worker | F3 | BLOCKED | 3 | UNVERIFIED | konsola retry brak | MEDIUM |
| DOCUMENT-002 | Dokumenty: faktury | App+Agent? | `document_invoice*`, `client_invoice*` | invoice routes/views/controllers | klient, Peppol? | F3 | BLOCKED | 3 | UNVERIFIED | dwie implementacje | MEDIUM |
| DOCUMENT-003 | Dokumenty: korekty faktur | Agent? | invoice correction structures | CorrectionController | faktura, Peppol | F1 | NOT_TESTED | 1 | UNVERIFIED | brak wejścia/UI App | LOW |
| DOCUMENT-004 | Dokumenty logistyczne: WDT/WZ/CMR | App | `document_wdt*`, `document_wz*`, `document_cmr*` | document routes/views/PDF | zamówienia, wysyłka | F3 | BLOCKED | 3 | UNVERIFIED | brak uzgodnienia ze stock | MEDIUM |
| SHIPPING-001 | Wysyłka: planowanie i dokumenty | App | pallet/shipment log tables | `/shipping*`; shipping views | WDT/WZ/CMR | F3 | BLOCKED | 3 | UNVERIFIED | rozproszone statusy | MEDIUM |
| SHIPPING-002 | Reklamacje: realizacja, załączniki, dokumenty | App+Agent? | `complaint*`, outbox | complaint routes/views/controllers | wysyłka, S3 | F3 | BLOCKED | 3 | UNVERIFIED | kompensacja stanów/finansów | MEDIUM |
| PRODUCTION-001 | Produkcja: lista/plan produkcyjny | App | query over orders/stock | `/production/planning`, PDF | zamówienia, receptury | F3 | BLOCKED | 3 | UNVERIFIED | to lista, nie zlecenie | MEDIUM |
| PRODUCTION-002 | Produkcja: zapotrzebowanie materiałowe | App | query object | requirement PDF endpoint | plan, receptury | F2 | BLOCKED | 2 | UNVERIFIED | brak rezerwacji/rozchodu | MEDIUM |
| PRODUCTION-003 | Produkcja: wykonanie zlecenia | brak potwierdzony | brak modelu App | brak UI/endpointu App | zlecenie, magazyn | F0 | NOT_APPLICABLE | 0 | N/A | brak procesu | MEDIUM |
| PRODUCTION-004 | Produkcja: QC, partie i traceability | brak potwierdzony | brak modelu App | brak UI/endpointu App | wykonanie | F0 | NOT_APPLICABLE | 0 | N/A | brak procesu | MEDIUM |
| PRODUCTION-005 | Produkcja/sklep: ruchy produktu i odpady | Agent? | `product_movement` | Movement/Waste controllers, PDF reports | POS/sklep | F1 | NOT_TESTED | 1 | UNVERIFIED | brak entrypointu i atomowości batch | MEDIUM |
| INVENTORY-001 | Magazyn: stan wyrobów/kartonów | App | `carton_stock` | `/products/stock*`; stock views | dokumenty/wysyłka | F2 | BLOCKED | 2 | UNVERIFIED | brak jednolitego ledgeru | MEDIUM |
| INVENTORY-002 | Magazyn: stan/log materiałów | Agent? | `material_inventory*` | inventory controllers | sklep, materiał | F1 | NOT_TESTED | 1 | UNVERIFIED | deploy Agent | LOW |
| INVENTORY-003 | Magazyn: przyjęcia, wydania, przesunięcia | App+Agent? | document/movement tables | fragmentaryczne kontrolery | ledger | F1 | BLOCKED | 1 | UNVERIFIED | brak jednego workflow | MEDIUM |
| INVENTORY-004 | Magazyn: rezerwacje, partie, FEFO i wycena | brak | brak potwierdzone | brak | zakup/produkcja | F0 | NOT_APPLICABLE | 0 | N/A | brak modelu | MEDIUM |
| INVENTORY-005 | Magazyn: inwentaryzacja i korekty | Agent? | stocktaking tables | StocktakingController | inventory | F1 | NOT_TESTED | 1 | UNVERIFIED | brak App UI/deployu | LOW |
| PURCHASING-001 | Zakupy: dokument zakupu i pozycje | App | `purchase_document`, `purchase_item` | purchase routes/views | dostawca, materiał | F2 | BLOCKED | 2 | UNVERIFIED | płytki workflow | MEDIUM |
| PURCHASING-002 | Zakupy: requisition, PO i dostawa | Agent? | requisition/order/delivery tables | Material controllers | dostawca, inventory | F1 | NOT_TESTED | 1 | UNVERIFIED | deploy Agent | LOW |
| PURCHASING-003 | Zakupy: ocena dostawców i SLA | brak | brak | brak | zakup/dostawa | F0 | NOT_APPLICABLE | 0 | N/A | brak procesu/KPI | MEDIUM |
| EMPLOYEE-001 | Pracownicy: konta, role, kompetencje | App+Agent? | employee/role/competency tables | W employee routes; Agent HR | auth, produkcja | F2 | BLOCKED | 2 | UNVERIFIED | dwa modele | MEDIUM |
| EMPLOYEE-002 | Pracownicy: grafiki i zadania | Agent? | schedule/todo tables | Schedule/Task controllers | sklep | F1 | NOT_TESTED | 1 | UNVERIFIED | deploy Agent | LOW |
| EMPLOYEE-003 | Pracownicy: RCP/nieobecności | brak | brak potwierdzone | brak | HR | F0 | NOT_APPLICABLE | 0 | N/A | brak danych czasu | MEDIUM |
| COST-001 | Koszty: miesiące, praca, media, stałe, logistyka | App | `cost_month*`, media/allocation | `/cost-calculator*`; cost views | sekcje, pracownicy | F3 | BLOCKED | 3 | UNVERIFIED | wartości i locking nieprzetestowane | MEDIUM |
| ANALYTICS-001 | Analityka: sprzedaż i trendy WDT | App | `document_wdt*` | `/finances/sales-analytics` | produkty, kategorie | F2 | BLOCKED | 2 | LOW | WDT ≠ pełna sprzedaż | HIGH |
| ANALYTICS-002 | Analityka: marża/kategoria/sekcja | App | WDT + `cost_month*` + recipe | finance dashboards | koszty, ceny | F2 | BLOCKED | 2 | LOW | historia kosztu nieudowodniona | HIGH |
| ANALYTICS-003 | Analityka: raporty POS PL/BE | Agent? | `transaction*`, invoices | country report services/PDF | POS, VAT | F1 | NOT_TESTED | 1 | UNVERIFIED | deploy i uzgodnienie | LOW |
| INTEGRATION-001 | Integracje: inbox/outbox, retry i workery | App+Agent? | multiple inbox/outbox/queue | `Bin/*`, webhook controllers | scheduler, HTTP | F2 | BLOCKED | 1 | UNVERIFIED | brak status UI/DLQ | MEDIUM |
| INTEGRATION-002 | Integracje: S3/R2, e-mail, Peppol/RabbitMQ | App+Agent? | file/email/Peppol tables | services/controllers | sekrety, sieć | F2 | NOT_TESTED | 1 | UNVERIFIED | brak sandbox/config UI | MEDIUM |
| OBSERVABILITY-001 | Operacje: monitoring, health i global audit | App+Agent? | fragmentaryczne logs/changelog | brak centralnego UI | wszystkie domeny | F1 | NOT_TESTED | 1 | LOW | brak health/trace/audit platformy | HIGH |

## Rozkład

F0=5, F1=12, F2=15, F3=18, F4=0, F5=0. Żaden wiersz nie ma statusu testu `PASS` produktu.

