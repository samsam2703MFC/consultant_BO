# Audyt danych historycznych

## Klasyfikacja pól wymaganych przez brief

Klasyfikacja opisuje dominujący, potwierdzony sposób użycia. `SNAPSHOT` oznacza wartość skopiowaną do rekordu zdarzenia/dokumentu; nie potwierdza jeszcze kompletności rekordów runtime.

| Wartość historyczna | Klasyfikacja | Dowód i ryzyko |
|---|---|---|
| nazwa produktu | SNAPSHOT dla WDT; CURRENT_REFERENCE w części raportów | `document_wdt_product.name` zachowuje nazwę, lecz wiele zapytań łączy pozycję z `final_product.name` |
| SKU | UNCLEAR | brak potwierdzonego snapshotu SKU w każdej pozycji sprzedaży/zamówienia/produkcji |
| cena sprzedaży | SNAPSHOT w `customer_order_product.purchase_price` i WDT `price_carton`; UNCLEAR dla całej sprzedaży | ceny są kopiowane przy tworzeniu części pozycji, lecz możliwe są aktualizacje WDT i niepotwierdzone korekty |
| stawka VAT | MISSING/UNCLEAR w faktach sprzedażowych | dokumenty mogą zawierać sumy podatkowe, lecz brak dowodu snapshotu stawki per pozycja we wszystkich kanałach |
| koszt produktu | CURRENT_REFERENCE/DERIVED | raporty kalkulują z bieżącej receptury/cen albo konfiguracji miesięcznej; brak kosztu jednostkowego sprzedaży na moment zdarzenia |
| cena zakupu | PARTIAL SNAPSHOT | pozycje zakupu/cenniki dostawców modelują cenę; okres obowiązywania i korekty dostawy nie są potwierdzone end-to-end |
| wersja receptury | MISSING | potwierdzono recepturę, ale nie encję niezmiennej wersji powiązanej ze zleceniem/sprzedażą |
| skład receptury | CURRENT_REFERENCE | `product_recipe_connection` opisuje bieżący graf; spłaszczone tabele są projekcją, nie dowodem historycznej wersji |
| koszt składników | CURRENT_REFERENCE/DERIVED | kalkulacja używa bieżącego `raw_material.price_per_unit`; brak snapshotu źródeł kalkulacji |
| opakowanie | UNCLEAR | dane opakowaniowe istnieją, lecz nie potwierdzono snapshotu typu/ilości/kosztu na pozycji zdarzenia |
| jednostka | CURRENT_REFERENCE/UNCLEAR | relacje wskazują `id_unit`; nazwa i przelicznik historyczny nie są konsekwentnie kopiowane |
| kategoria | CURRENT_REFERENCE | raporty sprzedaży łączą bieżącą kategorię produktu, więc przeniesienie produktu może przepisać historię kategorii |
| pracownik | CURRENT_REFERENCE/PARTIAL | transakcja Agent ma `id_employee`; brak snapshotu roli, stanowiska i przypisania do zmiany |
| lokalizacja | CURRENT_REFERENCE/PARTIAL | `id_shop` występuje w Agent; brak historycznej lokalizacji dla wszystkich zdarzeń App |
| promocja | MISSING | brak potwierdzonego `promotion_id`, reguły, wartości rabatu i kosztu na pozycji faktu sprzedaży |
| kurs waluty | CURRENT_REFERENCE | pojedyncze ustawienie EUR; brak tabeli kursów z datą i źródłem |
| dane klienta dla dokumentu | SNAPSHOT w części dokumentów | WDT przechowuje nazwę/adres/NIP odbiorcy i nadawcy; spójność z fakturą/korektą wymaga testu |

## Raporty podatne na zmianę wyniku historycznego

| Raport/miara | Zmienna referencja | Możliwy skutek |
|---|---|---|
| sprzedaż według kategorii/sekcji | bieżąca kategoria i sekcja `final_product` | historyczna sprzedaż przesuwa się po zmianie klasyfikacji |
| koszt i marża produktu | bieżąca receptura, cena materiału, standardowa cena produktu | marża za zamknięty miesiąc może zmienić się bez nowej sprzedaży |
| zapotrzebowanie materiałowe historyczne | bieżące `flattened_recipe_ingredient` | ponowne przeliczenie starego planu daje inny skład |
| koszt receptury | bieżąca cena materiału i bieżący graf | brak reprodukcji kalkulacji na wcześniejszy dzień |
| sprzedaż per pracownik/rola | bieżąca kartoteka pracownika i brak historii roli | błędna atrybucja po transferze lub zmianie stanowiska |
| sprzedaż per sklep/kanał | brak pełnego snapshotu kanału/lokalizacji | część faktów może być niemożliwa do porównania |
| VAT i brutto/netto | brak potwierdzonej stawki per pozycja | zmiana kartoteki podatkowej lub odmienne źródła sum zmieniają wynik |
| wartość zapasu | bieżąca cena/koszt i brak ledgera wartości | brak wiarygodnego as-of oraz zmiana wyceny wstecz |
| rentowność klienta | bieżące dane klienta/cennika, brak kosztu dostawy i korekt | wynik nie odzwierciedla warunków z momentu transakcji |
| efekt promocji | brak snapshotu promocji i ceny bazowej | nie można zbudować grupy sprzedaży promowanej ani kosztu rabatu |

## Minimalna zasada historyzacji

Każdy fakt sprzedaży, zakupu, ruchu i produkcji powinien przechowywać identyfikatory źródeł oraz niezmienny snapshot wartości wpływających na interpretację: nazwę/SKU, jednostkę i przelicznik, kategorię, cenę netto/brutto, VAT, walutę i kurs, koszt jednostkowy wraz z metodą, klienta dokumentowego, lokalizację, pracownika/rolę, promocję oraz wersję receptury. Zmiana kartoteki nie może przepisywać opublikowanego wyniku.

## Wniosek

Historyczność jest punktowa, a nie systemowa. Dokumenty handlowe zawierają część poprawnych snapshotów, ale analityka często wraca do bieżących kartotek. Bez testu danych i uzupełnienia brakujących snapshotów raporty historyczne powinny otrzymywać status `PARTIAL` albo `UNRELIABLE`.
