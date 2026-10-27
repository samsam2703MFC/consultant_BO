# Prośba do TFBuddy — endpointy API (zapis) do kodowania artykułów spożywczych na odsprzedaż (v2)

Do: zespół deweloperski `atelierby.tfbuddy.com` · Od: L’Atelier by (kokpit CEO) · 27.10.2026, wersja 2 (z decyzjami)  
Bazowy URL: `https://atelierby.tfbuddy.com/api/v1` · Wersje: [français](demande-fr.md), [English](demande-en.md), polski

## Potrzeba

Sieć odsprzedaje w dziale **delikatesów (épicerie)** artykuły kupowane i sprzedawane bez przetwarzania (kawa, dżemy, czekolady…). Proces w kokpicie: skanujemy PDF dostawcy (katalog, cennik), kreator odczytuje każdą pozycję, kodujemy ją i zatwierdzamy krok po kroku — cena, kod kreskowy, VAT, okresy sprzedaży, nazwa, składniki i alergeny po francusku i po niderlandzku — a następnie wszystko musi zostać utworzone w TFBuddy **jako jeden łańcuch**:

```
dostawca ──► pozycja katalogu dostawcy (SKU, EAN, opakowanie zbiorcze, cena) ──► surowiec (alergeny) ──► receptura (1 × surowiec) ──► produkt sprzedażowy (kategoria, cena maks., okresy, FR/NL)
```

Stwierdzone w Swaggerze 27.10.2026: `material-categories`, `material-suppliers`, `materials`, `product-categories`, `product-availability-periods` i `products` są **tylko do odczytu** (GET). Nie można utworzyć żadnego ogniwa łańcucha. Poniższe endpointy zapisu to minimum, aby kreator mógł utworzyć łańcuch bez ponownego wpisywania danych. Nazwy pól to te, które już zwracają Wasze endpointy GET; **pola pogrubione są nowe** — jeśli nazwiecie je inaczej, dajcie nam znać, a kokpit się dostosuje.

## Decyzje L’Atelier by

1. **Cena**: jeden cennik dostawcy, zapisany raz dla **całej sieci** (bez cenników per sklep dla artykułów spożywczych). Cena sprzedaży jest **ceną maksymalną, taką samą dla wszystkich sklepów** (`max_sale_price`, brutto): sklep może sprzedawać taniej, nigdy drożej.
2. **Kod kreskowy**: EAN jest przypisany do **produktu** (`ean`, skanowany w kasie) oraz do pozycji katalogu dostawcy. Jest wprowadzany i weryfikowany (cyfra kontrolna EAN-13 / EAN-8) w kreatorze.
3. **VAT sprzedaży**: na poziomie **produktu** (`tax_value_percent`), taki sam we wszystkich sklepach.
4. **Aktywacja**: przez **okresy dostępności** (`availability_period_ids`, identyfikatory z `GET /product-availability-periods`). Produkt jest sprzedawany w swoich okresach — „Permanent” przez cały rok. Endpoint 8 pozwala je później zmieniać.
5. **Dwujęzyczność**: nazwa (`name` / `name_nl`) i składniki (`ingredients_fr` / `ingredients_nl`) po francusku i po niderlandzku, w produkcie.
6. **Alergeny**: zaznaczane pojedynczo w kreatorze, wysyłane jako kody z `GET /allergens` w **surowcu** — `allergens` (zawiera) i `may_contain` (może zawierać śladowe ilości). Produkt dziedziczy je przez recepturę, tak jak Wasze pozostałe produkty. **Istniejący** surowiec nigdy nie jest zmieniany przez kokpit: korzystają z niego inne receptury, więc jego alergeny pozostają takie, jak u Was.

## Zasady wspólne

- Konto **admin** (realm `/admin/auth/login`); konto konsultanta może pozostać tylko do odczytu.
- `201` z pełnym obiektem (łącznie z `id`); `422` ze wskazaniem błędnego pola, w jednolitej kopercie: `{ "error": { "code": "…", "message": "…", "field": "…" } }`; `409` przy duplikacie (ten sam `sku` u tego samego dostawcy, ten sam `ean`, ta sama `name` w tej samej kategorii) **ze zwróceniem istniejącego id**, aby kreator mógł go użyć zamiast kończyć się błędem.
- `client_ref` w każdym POST (dowolny ciąg, maks. 64 znaki): drugi POST z tym samym `client_ref` niczego nie tworzy i zwraca już utworzony obiekt (`200`). Dzięki temu ponowienie po zerwaniu połączenia jest bezpieczne.
- `OPTIONS` na każdej ścieżce musi zwracać obsługiwane metody w nagłówku `Allow`: właśnie to odczytuje przycisk „Sprawdź API” w kokpicie, niczego nie zapisując.
- `PATCH` częściowy: zmieniane są tylko przesłane pola.

## Endpointy

| # | Endpoint | Tworzy / modyfikuje | Pola (pogrubione: nowe) |
|---|---|---|---|
| 1 | `POST /material-suppliers` · `PATCH /material-suppliers/{id}` | dostawcę | `name`, `type`, `email`, `phone`, `address`, `city`, `country_code`, `tax_number`, `website_url`, `currency`, `notes` |
| 2 | `POST /material-suppliers/{id}/catalog/products` · `PATCH …/catalog/products/{productId}` | pozycję katalogu dostawcy | `sku`, `name`, `ean`, `package_size`, `package_unit`, `portion_size`, `portion_unit`, `weight_grams`, `shelf_life_days`, `vat_rate`, `is_active` |
| 3 | `POST /material-suppliers/{id}/price-lists` | cenę opakowania zbiorczego, z datą | `sku` (lub `catalog_product_id`), `price_net`, `package_size`, `package_unit`, `vat_rate`, `valid_from` — obowiązuje we **wszystkich sklepach** |
| 4 | `POST /materials` · `PATCH /materials/{id}` | surowiec i jego alergeny | `name`, `code`, `material_category_id`, `unit_name` (jednostka bazowa : `pcs`, `g`, `kg`, `l`), `vat_rate`, `material_type`, `waste_amount_perc`, **`allergens`**, **`may_contain`** (kody z `GET /allergens`) |
| 5 | `POST /material-suppliers/{id}/catalog-mappings` | powiązanie pozycja ↔ surowiec | `material_id`, `catalog_product_id`, `units_per_pack` |
| 6 | `POST /products` · `PATCH /products/{id}` | produkt sprzedażowy | `name`, **`name_nl`**, `id_category`, **`max_sale_price`**, `tax_value_percent`, **`ean`**, `single_weight`, `is_divisible`, `portion_size`, **`ingredients_fr`**, **`ingredients_nl`**, `shelf_life_minutes`, **`availability_period_ids`** |
| 7 | `PUT /products/{id}/recipe` | recepturę produktu | `yield_quantity`, `elements: [{ material_id, required_quantity, unit_name }]` |
| 8 | `POST /product-availability-periods/{id}/products` · `DELETE …/products/{productId}` | okresy sprzedaży produktu | `product_id` |

**Zalecany skrót — jeden endpoint, atomowy** (to on będzie wywoływany przez kreator, pozycja po pozycji):

```
POST /products/resale
{
  "client_ref": "ep14-l7",
  "supplier":  { "id": 6 },                                   // lub { "name": …, "tax_number": … }, aby go utworzyć
  "catalog_product": { "sku": "CS-CHOC-72", "name": "Chocolat noir 72 % 100 g", "ean": "5412345000075",
                       "package_size": 20, "package_unit": "pcs", "vat_rate": 6 },
  "price": { "price_net": 34.00, "valid_from": "2026-11-01" },                   // cennik sieciowy: wszystkie sklepy
  "material": { "id": null, "name": "Chocolat noir 72 % 100 g", "material_category_id": 11,
                "unit_name": "pcs", "vat_rate": 6,
                "allergens": ["milk", "soybeans"], "may_contain": ["nuts"] },   // podane id = istniejący surowiec, bez zmian
  "units_per_pack": 20,
  "product": { "name": "Chocolat noir 72 % - 100 g", "name_nl": "Pure chocolade 72 % - 100 g",
               "id_category": 61, "max_sale_price": 3.20, "tax_value_percent": 6,
               "ean": "5412345000075", "single_weight": 100,
               "ingredients_fr": "Pâte de cacao, sucre, beurre de cacao, émulsifiant : lécithine de soja.",
               "ingredients_nl": "Cacaomassa, suiker, cacaoboter, emulgator: sojalecithine.",
               "availability_period_ids": [1] },                                 // 1 = „Permanent”
  "recipe_quantity": 1
}
→ 201 { "supplier_id": 6, "catalog_product_id": 812, "material_id": 1544, "product_id": 2210, "recipe_id": 1980 }
```

Wszystko albo nic: jeśli produkt zostanie odrzucony, nie zostaje żaden osierocony surowiec. Endpointy 1–8 pozostają przydatne do późniejszych korekt (zmiana ceny, okres sprzedaży, wycofany artykuł).

## Czego potrzebujemy w odpowiedzi

1. Nazwy, które wybierzecie dla nowych pól, jeśli będą inne (`max_sale_price`, `name_nl`, `ingredients_fr`, `ingredients_nl`, `ean` produktu, `allergens` / `may_contain` surowca, `availability_period_ids`).
2. Zachowanie kasy powyżej `max_sale_price`: blokada czy ostrzeżenie?
3. Termin udostępnienia `POST /products/resale` (nawet na środowisku testowym): kokpit jest gotowy i od razu go przetestuje.
