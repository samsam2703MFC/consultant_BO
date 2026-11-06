# Audyt struktury bazy

## Status

**BLOCKED dla żywej bazy.** PDO konfiguruje MySQL (`App/Core/Database.php:10-21`), ale środowisko audytowe nie ma PHP ani klienta MySQL. Nie ma też potwierdzenia, że baza z `.env` jest testowa. Nie wykonano zapytań ani migracji.

## Fakty z kodu

- Repozytoria odnoszą się m.in. do `customer_order*`, `document_*`, `raw_material*`, `final_product`, `product_recipe*`, `carton_stock`, `cost_*`, `purchase_*`, `complaint*`, `shipment_log`, `outbox*`, `user`.
- Kod Agent dodaje `transaction*`, `material_inventory*`, HR, sklepy, Peppol, marketing, szkolenia i framework operacyjny.
- Migracje są deltami, nie bazowym schematem. Nie można z nich odtworzyć pełnej bazy.
- Migracja 020 dodaje FK kosztów, 021 kroków receptury, 023 kompetencji; definicji większości tabel brak.

## Ryzyka

1. Brak runnera migracji i tabeli wersji — P1.
2. Powtarzane numery migracji i SQL przeplatany Markdown — P1.
3. Brak dowodu FK, unique i indeksów dla głównych relacji, idempotency keys i statusów.
4. `ProductionListRepository.php:139-164` interpoluje daty i listy ID; wymaga walidacji źródeł.
5. Pola finansowe i collations nie mogły być zweryfikowane.

Bezpieczny zestaw read-only znajduje się w `queries/read_only_database_audit.sql`.

