# Katalog źródeł danych

## Ograniczenie audytu

Nazwy tabel i kolumn pochodzą z repozytoriów oraz migracji. Klucze fizyczne, typy, nullability, zakresy dat, liczności i rzeczywista częstotliwość zapisu pozostają `RUNTIME_UNVERIFIED`, ponieważ nie uzyskano połączenia read-only z bazą. Relacja widoczna w `JOIN` nie oznacza potwierdzonego klucza obcego.

## Źródła domenowe

| Domena | Tabele/źródła widoczne w kodzie | Kluczowe pola i relacje używane przez kod | Powstanie i korekta | Historia, usuwanie, status, audyt | Dowód |
|---|---|---|---|---|---|
| Sprzedaż B2B | `document_wdt`, `document_wdt_product` | `id`, `id_customer`, `doc_num`, `established_date`, `shipping_date`, `total_net`; pozycje: `id_document_wdt`, `id_product`, `name`, `quantity`, `price_carton` | generowanie dokumentu z wysyłki; ceny pozycji mogą być aktualizowane | snapshot nazwy/ceny w pozycji jest widoczny; brak potwierdzonej tabeli anulowań i globalnego aktora | CONFIRMED_CODE |
| Transakcje POS | `transaction`, `transaction_product`, tabele płatności | transakcja: sklep, klient, pracownik, typ płatności, `insert_timestamp`, faktura; pozycje łączone przez ID transakcji i produktu | zapis checkoutu; repozytorium dopuszcza aktualizację pozycji | aktywny runtime, status anulowania i kompensacja niepotwierdzone | AGENT_UNVERIFIED |
| Zamówienia | `customer_order`, `customer_order_product`, `customer_order_status_type`, `customer_order_changelog` | klient, status, daty utworzenia/wysyłki/zmiany, WDT; pozycje: produkt, ilość, oczekiwana ilość, `purchase_price`; changelog: użytkownik, data | panel/import tworzy zamówienie i pozycje; kod aktualizuje status, ilości i powiązanie WDT | częściowy changelog dodany migracją; brak dowodu kompletności i ochrony przed usunięciem pozycji | CONFIRMED_CODE |
| Produkty | `final_product`, `category_product`, Agent `product` | produkt łączony z kategorią, recepturą, sekcją; używane: nazwa, standardowa cena, waga, opakowanie/karton | CRUD kartoteki | brak pełnego temporalnego wymiaru produktu; pola aktywności występują punktowo | CONFIRMED_CODE + AGENT_UNVERIFIED |
| Ceny sprzedaży | `final_product_customer_price`, `final_product.standard_price`, Agent price lists | klient–produkt–cena–dostępność; Agent używa `valid_from` | ręczna/masowa aktualizacja i synchronizacja | brak potwierdzonej pełnej historii cen klienta; cena jest kopiowana do części pozycji zamówienia/WDT | CONFIRMED_CODE |
| Receptury | `product_recipe`, `product_recipe_connection`, `flattened_recipe_ingredient`, `flattened_recipe_recipe`, kroki przygotowania | rodzic, składnik/podreceptura, ilość, wydajność, jednostka; spłaszczenie łączy materiał i cenę bieżącą | technolog modyfikuje skład; spłaszczenie przelicza zależności | brak potwierdzonej encji wersji i okresu obowiązywania; aktualny graf może zmieniać wynik historyczny | CONFIRMED_CODE |
| Produkcja | wyliczane `production_list_tmp`, listy produkcyjne i zapotrzebowanie | data wysyłki, produkt, ilość zamówiona, stan kartonów, skład receptury | agregacja z zamówień, nie potwierdzony zapis wykonania | brak potwierdzonego zlecenia, statusów, partii i zdarzeń wykonania | CONFIRMED_CODE dla planu; MISSING_PROCESS dla wykonania |
| Magazyn wyrobów | `carton_stock`, palety, WZ/WDT, logi wysyłki | karton, produkt, status; zapytania liczą kartony o statusach 1/2 | pakowanie/wysyłka zmienia status kartonu | model wygląda jak bieżący stan obiektów; nie potwierdzono kompletnej historii ruchów, autora i lokalizacji | CONFIRMED_CODE, RUNTIME_UNVERIFIED |
| Magazyn materiałów | `material_inventory`, `material_inventory_log`, `material_movement_reason` | materiał, sklep, ilość, powód, typ ruchu i pola czasu używane w repozytoriach | operacje Agent | nie potwierdzono aktywności, niezmienności logu ani zgodności saldo–log | AGENT_UNVERIFIED |
| Inwentaryzacja | `stocktaking`, `stocktaking_item`, `stocktaking_summary` | sklep, pracownik, status/data; ilość liczona, cena średnia, VAT | sesja liczenia i pozycje | historia sesji jest modelowana; wpływ na ledger i korekta niepotwierdzone | AGENT_UNVERIFIED |
| Zakupy | App `purchase_document`, `purchase_item`; Agent `material_requisition*`, `material_order*` | dokument/pozycja/materiał/dostawca/sklep; ilości, ceny, VAT, statusy | zapotrzebowanie, zamówienie, dokument zakupu | pełny PO–dostawa–korekta i niezmienność ceny niepotwierdzone | PARTIAL_CODE |
| Dostawcy | `material_supplier`, połączenia sklep–dostawca, kontakty i katalog | dostawca, sklep, materiał, opakowanie, warunki | CRUD i integracja katalogu | część changelogów/cenników; brak potwierdzonego temporalnego score | głównie AGENT_UNVERIFIED |
| Dostawy | `material_order_item_delivery_log`, logi wysyłki i palety | pozycja zamówienia, ilość dostarczona, data; klient/przewoźnik w logistyce | przyjęcie częściowe lub wysyłka | rozbieżność i reklamacja są rozproszone; brak jednego faktu dostawy | PARTIAL_CODE |
| Pracownicy | App pracownicy produkcyjni i `cost_month_employee`; Agent `franchisee_employee`, role, stanowiska | pracownik, sklep, rola/stanowisko, aktywność; koszt miesięczny wiązany z sekcją | HR/admin | datowanie zatrudnienia i historii ról niepotwierdzone; konta i pracownicy mają różne modele | PARTIAL_CODE |
| Czas pracy | Agent `franchisee_employee_schedule`; brak potwierdzonego RCP | pracownik, `work_date`, start/end, sklep | zapis grafiku | grafik jest planem, nie dowodem czasu rzeczywistego; korekty i akceptacje niepotwierdzone | AGENT_UNVERIFIED / MISSING_DATA |
| Straty | brak kanonicznej tabeli; fragmenty reklamacji i stanów kartonów | brak potwierdzonych ilości, powodu, partii i źródła operacji | nieustalone | brak niezmiennego zdarzenia straty | MISSING_PROCESS |
| Odpady | brak potwierdzonej tabeli wykonania produkcji/utylizacji | brak masy/ilości, kategorii odpadu, partii i sposobu zagospodarowania | nieustalone | brak historii | MISSING_PROCESS |
| Dokumenty | `document_invoice*`, `document_wdt*`, `document_wz*`, `document_proforma*`, CMR | nagłówek–pozycje przez ID dokumentu; numery, klient, daty, sumy | generowane z procesów sprzedaży/wysyłki | dokumenty zawierają część snapshotów kontrahenta; korekty istnieją fragmentarycznie, brak wspólnego modelu | CONFIRMED_CODE |
| Koszty | `cost_month*`, `cost_month_employee`, fixed/media/logistics/section/allocation, ustawienia produktu | miesiąc, sekcja, typ kosztu, kwota/alokacja; kalkulacja łączy wolumen WDT | controller wprowadza wartości miesięczne, system agreguje | okres jest modelowany, lecz wersje publikacji i korekty ledgera nie są potwierdzone | CONFIRMED_CODE, jakość RUNTIME_UNVERIFIED |
| Płatności | pola typu płatności w `transaction`, potencjalne tabele Agent | transakcja–forma płatności–kwota/status | checkout/integracja płatnicza | idempotencja, refund i uzgodnienie operatora niepotwierdzone | AGENT_UNVERIFIED |
| Promocje | Agent promo code/marketing; brak kanonicznego wymiaru w faktach App | potencjalny kod, zakres i daty | Agent | brak potwierdzonego snapshotu promocji na pozycji sprzedaży i kosztu promocji | AGENT_UNVERIFIED / MISSING_DATA |
| Korekty | `customer_order_changelog`, invoice correction, reklamacje, logi integracji | obiekt, aktor punktowo, data, opis; brak wspólnego `correction_id` | różne kontrolery/usługi | część rekordów jest aktualizowana w miejscu, część ma osobne wpisy; brak jednej semantyki kompensacji | UNRELIABLE |

## Profil fizyczny wymagający odczytu bazy

Poniższe atrybuty mają status `UNCLEAR` dla każdej tabeli do czasu wykonania zapytań z `13_DIAGNOSTIC_QUERIES.md`:

| Atrybut | Wymagany dowód |
|---|---|
| kolumny i typy | `information_schema.columns` |
| PK, FK, unique i indeksy | `information_schema.table_constraints`, `key_column_usage`, `statistics` |
| zakres dat | `MIN/MAX` po potwierdzonej kolumnie zdarzenia |
| liczba rekordów | dokładny `COUNT(*)`, nie estymacja `table_rows` |
| częstotliwość zapisu | rozkład dzienny/miesięczny według czasu utworzenia |
| null/duplikaty/orphans | zapytania domenowe po zatwierdzeniu schematu |
| soft delete | istnienie i użycie `deleted_at`/`active` oraz kontrola zapytań raportowych |
| możliwość hard delete | kod `DELETE`, FK oraz log audytowy |

## Źródła prawdy — stan obecny

Nie można dziś wskazać jednego źródła prawdy dla sprzedaży, magazynu, produkcji i kosztu rzeczywistego. Najbliższe źródła operacyjne to `customer_order*` dla zamówień B2B, `document_wdt*` dla wydań analizowanych przez panel oraz miesięczne `cost_*` dla konfiguracji controllingu. `transaction*` i `material_inventory*` pozostają niepotwierdzonymi źródłami Agent.
