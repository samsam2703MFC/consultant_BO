# Bezpieczne zapytania diagnostyczne

## Zasady wykonania

- Uruchamiać wyłącznie na zatwierdzonej kopii lub kontem mającym tylko `SELECT` do właściwego schematu.
- Najpierw wykonać sekcję A i potwierdzić nazwy/kolumny. Zapytania domenowe mogą wymagać dostosowania do faktycznego schematu.
- Nie uruchamiać migracji, procedur, `UPDATE`, `DELETE`, `INSERT`, `ALTER`, `CREATE`, `DROP`, `TRUNCATE`, `LOCK` ani eksportu danych osobowych.
- Zapisywać: środowisko, czas, wersję schematu, tekst zapytania, liczbę badanych rekordów i liczbę naruszeń.

## A. Schemat, liczności i pola audytowe

```sql
SELECT DATABASE() AS database_name, VERSION() AS database_version;

SELECT table_name, engine, table_rows AS estimated_rows, data_length, index_length
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY table_name;

SELECT table_name, column_name, ordinal_position, data_type, column_type,
       is_nullable, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
ORDER BY table_name, ordinal_position;

SELECT tc.table_name, tc.constraint_name, tc.constraint_type,
       kcu.column_name, kcu.referenced_table_name, kcu.referenced_column_name
FROM information_schema.table_constraints tc
LEFT JOIN information_schema.key_column_usage kcu
  ON kcu.constraint_schema = tc.constraint_schema
 AND kcu.table_name = tc.table_name
 AND kcu.constraint_name = tc.constraint_name
WHERE tc.constraint_schema = DATABASE()
ORDER BY tc.table_name, tc.constraint_type, tc.constraint_name, kcu.ordinal_position;

SELECT table_name, index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
ORDER BY table_name, index_name, seq_in_index;

SELECT table_name,
       MAX(column_name IN ('created_at','create_timestamp','insert_timestamp','create_order_date')) AS has_created_time,
       MAX(column_name IN ('updated_at','update_timestamp','update_status_date')) AS has_updated_time,
       MAX(column_name IN ('deleted_at','active')) AS has_deletion_marker,
       MAX(column_name IN ('id_user','user_id','id_employee','employee_id','created_by','updated_by')) AS has_actor
FROM information_schema.columns
WHERE table_schema = DATABASE()
GROUP BY table_name
ORDER BY table_name;
```

`table_rows` jest estymacją. Po zatwierdzeniu listy tabel wykonać dokładne `SELECT COUNT(*)` osobno, aby wynik każdej tabeli był jawny.

## B. Zamówienia i sprzedaż B2B

```sql
SELECT COUNT(*) AS order_count,
       MIN(create_order_date) AS first_order,
       MAX(create_order_date) AS last_order,
       SUM(create_order_date IS NULL) AS missing_created_date,
       SUM(id_customer IS NULL) AS missing_customer,
       SUM(status IS NULL) AS missing_status
FROM customer_order;

SELECT cop.id_order, COUNT(*) AS orphan_lines
FROM customer_order_product cop
LEFT JOIN customer_order co ON co.id = cop.id_order
WHERE co.id IS NULL
GROUP BY cop.id_order;

SELECT co.status, COUNT(*) AS records
FROM customer_order co
LEFT JOIN customer_order_status_type st ON st.id = co.status
WHERE st.id IS NULL
GROUP BY co.status;

SELECT COUNT(*) AS tested_lines,
       SUM(purchase_price IS NULL) AS missing_price,
       SUM(purchase_price = 0) AS zero_price,
       SUM(purchase_price < 0) AS negative_price,
       SUM(quantity_carton < 0 OR expected_quantity_carton < 0) AS negative_quantity
FROM customer_order_product;

SELECT id_order, id_product, COUNT(*) AS duplicate_count
FROM customer_order_product
GROUP BY id_order, id_product
HAVING COUNT(*) > 1;

SELECT COUNT(*) AS wdt_count,
       MIN(established_date) AS first_wdt,
       MAX(established_date) AS last_wdt,
       SUM(doc_num IS NULL OR doc_num = '') AS missing_number,
       SUM(id_customer IS NULL) AS missing_customer,
       SUM(total_net IS NULL) AS missing_total
FROM document_wdt;

SELECT doc_num, COUNT(*) AS duplicate_count
FROM document_wdt
WHERE doc_num IS NOT NULL AND doc_num <> ''
GROUP BY doc_num
HAVING COUNT(*) > 1;

SELECT dwp.id_document_wdt, COUNT(*) AS orphan_lines
FROM document_wdt_product dwp
LEFT JOIN document_wdt wdt ON wdt.id = dwp.id_document_wdt
WHERE wdt.id IS NULL
GROUP BY dwp.id_document_wdt;

SELECT wdt.id, wdt.doc_num, wdt.total_net,
       ROUND(SUM(dwp.quantity * dwp.price_carton), 2) AS calculated_net,
       ROUND(wdt.total_net - SUM(dwp.quantity * dwp.price_carton), 2) AS difference
FROM document_wdt wdt
JOIN document_wdt_product dwp ON dwp.id_document_wdt = wdt.id
GROUP BY wdt.id, wdt.doc_num, wdt.total_net
HAVING ABS(difference) > 0.01;

SELECT COUNT(*) AS tested_lines,
       SUM(quantity IS NULL) AS missing_quantity,
       SUM(quantity < 0) AS negative_quantity,
       SUM(price_carton IS NULL) AS missing_price,
       SUM(price_carton = 0) AS zero_price,
       SUM(price_carton < 0) AS negative_price,
       SUM(name IS NULL OR name = '') AS missing_product_snapshot
FROM document_wdt_product;
```

## C. Zmienność danych podstawowych i snapshotów

Różnica nazwy nie jest automatycznie błędem — wskazuje rekordy, dla których raport musi świadomie wybrać snapshot albo bieżącą kartotekę.

```sql
SELECT COUNT(*) AS compared_lines,
       SUM(dwp.name <> fp.name) AS name_changed,
       SUM(fp.id IS NULL) AS missing_current_product
FROM document_wdt_product dwp
LEFT JOIN final_product fp ON fp.id = dwp.id_product;

SELECT COUNT(*) AS tested_products,
       SUM(standard_price IS NULL) AS missing_standard_price,
       SUM(standard_price = 0) AS zero_standard_price,
       SUM(standard_price < 0) AS negative_standard_price
FROM final_product;

SELECT id_customer, id_final_product, COUNT(*) AS duplicate_count
FROM final_product_customer_price
GROUP BY id_customer, id_final_product
HAVING COUNT(*) > 1;

SELECT COUNT(*) AS tested_connections,
       SUM(quantity IS NULL) AS missing_quantity,
       SUM(quantity <= 0) AS non_positive_quantity,
       SUM(child_recipe_id IS NULL AND child_ingredient_id IS NULL) AS missing_child,
       SUM(child_recipe_id IS NOT NULL AND child_ingredient_id IS NOT NULL) AS ambiguous_child
FROM product_recipe_connection;
```

## D. Dokumenty i integracje

Po sekcji A potwierdzić dokładne nazwy kolumn tabel faktur i korekt.

```sql
SELECT doc_num, COUNT(*) AS duplicate_count
FROM document_invoice
WHERE doc_num IS NOT NULL AND doc_num <> ''
GROUP BY doc_num
HAVING COUNT(*) > 1;

SELECT dip.id_document_invoice, COUNT(*) AS orphan_lines
FROM document_invoice_product dip
LEFT JOIN document_invoice di ON di.id = dip.id_document_invoice
WHERE di.id IS NULL
GROUP BY dip.id_document_invoice;

SELECT event_id, COUNT(*) AS duplicate_count
FROM webhook_outbox_shipments
WHERE event_id IS NOT NULL AND event_id <> ''
GROUP BY event_id
HAVING COUNT(*) > 1;

SELECT status, COUNT(*) AS records,
       MIN(created_at) AS first_seen, MAX(updated_at) AS last_update,
       MAX(attempts) AS max_attempts
FROM webhook_outbox_shipments
GROUP BY status;
```

## E. Magazyn — profil wstępny

Statusy kartonu muszą zostać najpierw opisane słownikiem biznesowym.

```sql
SELECT status, COUNT(*) AS carton_count
FROM carton_stock
GROUP BY status
ORDER BY status;

SELECT id_carton, COUNT(*) AS duplicate_count
FROM carton_stock
GROUP BY id_carton
HAVING COUNT(*) > 1;

SELECT cs.id_product, COUNT(*) AS orphan_cartons
FROM carton_stock cs
LEFT JOIN final_product fp ON fp.id = cs.id_product
WHERE fp.id IS NULL
GROUP BY cs.id_product;
```

Dla Agent uruchomić dopiero po potwierdzeniu kolumn w sekcji A:

```sql
-- Szablon uzgodnienia; nazwy kolumn należy podstawić ze schematu.
SELECT
    l.id_shop,
    l.id_material,
    SUM(l.signed_quantity_in_base_unit) AS ledger_quantity,
    i.quantity_in_base_unit AS stored_quantity,
    SUM(l.signed_quantity_in_base_unit) - i.quantity_in_base_unit AS difference
FROM material_inventory_log l
JOIN material_inventory i
  ON i.id_shop = l.id_shop
 AND i.id_material = l.id_material
GROUP BY l.id_shop, l.id_material, i.quantity_in_base_unit
HAVING ABS(difference) > 0.000001;
```

Powyższy szablon nie jest dowodem, że pola `signed_quantity_in_base_unit` i `quantity_in_base_unit` istnieją — wymusza wcześniej ustalenie semantyki ilości.

## F. Koszty i okresy

Dokładne kolumny kwot i okresów należy potwierdzić w sekcji A. Minimalne kontrole po mapowaniu:

```sql
-- Szablon dla każdej tabeli cost_* po wskazaniu kolumn.
SELECT
    COUNT(*) AS tested_records,
    SUM(<amount_column> IS NULL) AS missing_amount,
    SUM(<amount_column> < 0) AS negative_amount,
    SUM(<period_column> IS NULL) AS missing_period,
    MIN(<period_column>) AS first_period,
    MAX(<period_column>) AS last_period
FROM <cost_table>;

-- Kontrola modyfikacji po zamknięciu wymaga tabeli okresów.
SELECT s.*
FROM <source_table> s
JOIN <period_table> p ON s.<period_key> = p.<period_key>
WHERE p.status = 'CLOSED'
  AND s.updated_at > p.closed_at;
```

## G. Częstotliwość zapisu

Wykonać dla każdej głównej tabeli z właściwym czasem zdarzenia:

```sql
SELECT DATE(create_order_date) AS business_day, COUNT(*) AS records
FROM customer_order
GROUP BY DATE(create_order_date)
ORDER BY business_day;

SELECT DATE(established_date) AS business_day, COUNT(*) AS records
FROM document_wdt
GROUP BY DATE(established_date)
ORDER BY business_day;
```

Należy sprawdzić dni bez rekordów, skoki wolumenu, daty przyszłe, daty przed uruchomieniem systemu i różnicę między czasem biznesowym a technicznym.

## H. Format wyniku audytu

Każda kontrola powinna zwrócić lub zostać opisana następująco:

```text
check_id | table | population_count | violation_count | first_date | last_date | executed_at | schema_version
```

Próbki rekordów należy anonimizować; do raportu wystarczają liczniki i techniczne identyfikatory.
