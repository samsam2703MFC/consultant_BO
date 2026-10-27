# Prośba do TFBuddy — endpointy API (zapis) do kodowania artykułów spożywczych na odsprzedaż

Do: zespół deweloperski `atelierby.tfbuddy.com` · Od: L’Atelier by (kokpit CEO) · 27.10.2026  
Bazowy URL: `https://atelierby.tfbuddy.com/api/v1` · Wersje: [français](demande-fr.md), [English](demande-en.md), polski

## Potrzeba

Sieć odsprzedaje w dziale **delikatesów (épicerie)** artykuły kupowane i sprzedawane bez przetwarzania (kawa, dżemy, czekolady…). Proces w kokpicie: skanujemy PDF dostawcy (katalog, cennik), kreator odczytuje każdą pozycję, kodujemy ją i zatwierdzamy krok po kroku, a następnie wszystko musi zostać utworzone w TFBuddy **jako jeden łańcuch**:

```
dostawca ──► pozycja katalogu dostawcy (SKU, opakowanie zbiorcze, cena) ──► surowiec (material) ──► receptura (1 × surowiec) ──► produkt sprzedażowy (kategoria Épicerie)
```

Stwierdzone w Swaggerze 27.10.2026: `material-categories`, `material-suppliers`, `materials`, `product-categories` i `products` są **tylko do odczytu** (GET). Nie można utworzyć żadnego ogniwa łańcucha. Poniższe endpointy zapisu to minimum, aby kreator mógł utworzyć łańcuch bez ponownego wpisywania danych. Nazwy pól to **te, które już zwracają Wasze endpointy GET** — nic nie jest wymyślone.

## Zasady wspólne

- Konto **admin** (realm `/admin/auth/login`); konto konsultanta może pozostać tylko do odczytu.
- `201` z pełnym obiektem (łącznie z `id`); `422` ze wskazaniem błędnego pola, w jednolitej kopercie: `{ "error": { "code": "…", "message": "…", "field": "…" } }`; `409` przy duplikacie (ten sam `sku` u tego samego dostawcy, ten sam `ean`, ta sama `name` w tej samej kategorii) **ze zwróceniem istniejącego id**, aby kreator mógł go użyć zamiast kończyć się błędem.
- `client_ref` w każdym POST (dowolny ciąg, maks. 64 znaki): drugi POST z tym samym `client_ref` niczego nie tworzy i zwraca już utworzony obiekt (`200`). Dzięki temu ponowienie po zerwaniu połączenia jest bezpieczne.
- `OPTIONS` na każdej ścieżce musi zwracać obsługiwane metody w nagłówku `Allow`: właśnie to odczytuje przycisk „Sprawdź API” w kokpicie, niczego nie zapisując.
- `PATCH` częściowy: zmieniane są tylko przesłane pola.

## Endpointy

| # | Endpoint | Tworzy / modyfikuje | Pola (nazwy z Waszych endpointów GET) |
|---|---|---|---|
| 1 | `POST /material-suppliers` · `PATCH /material-suppliers/{id}` | dostawcę | `name`, `type`, `email`, `phone`, `address`, `city`, `country_code`, `tax_number`, `website_url`, `currency`, `notes` |
| 2 | `POST /material-suppliers/{id}/catalog/products` · `PATCH …/catalog/products/{productId}` | pozycję katalogu dostawcy | `sku`, `name`, `ean`, `package_size`, `package_unit`, `portion_size`, `portion_unit`, `weight_grams`, `shelf_life_days`, `vat_rate`, `is_active` |
| 3 | `POST /material-suppliers/{id}/price-lists` | cenę opakowania zbiorczego, z datą | `sku` (lub `catalog_product_id`), `price_net`, `package_size`, `package_unit`, `vat_rate`, `valid_from`, `shop_ids` (puste = cała sieć) |
| 4 | `POST /materials` · `PATCH /materials/{id}` | surowiec (material) | `name`, `code`, `material_category_id`, `unit_name` (jednostka bazowa : `pcs`, `g`, `kg`, `l`), `vat_rate`, `material_type`, `waste_amount_perc` |
| 5 | `POST /material-suppliers/{id}/catalog-mappings` | powiązanie pozycja ↔ surowiec | `material_id`, `catalog_product_id`, `units_per_pack` |
| 6 | `POST /products` · `PATCH /products/{id}` | produkt sprzedażowy | `name`, `id_category`, `suggested_sale_price`, `tax_value_percent`, `single_weight`, `is_divisible`, `portion_size`, `allergene`, `shelf_life_minutes`, `is_active` |
| 7 | `PUT /products/{id}/recipe` | recepturę produktu | `yield_quantity`, `elements: [{ material_id, required_quantity, unit_name }]` |

**Zalecany skrót — jeden endpoint, atomowy:**

```
POST /products/resale
{
  "client_ref": "imp14-l3",
  "supplier":  { "id": 6 },                                   // lub { "name": …, "tax_number": … }, aby go utworzyć
  "catalog_product": { "sku": "CF-250-ARA", "name": "Café Fiesta Arabica 250 g", "ean": "5412345678901",
                       "package_size": 12, "package_unit": "pcs", "vat_rate": 6 },
  "price": { "price_net": 41.40, "valid_from": "2026-11-01", "shop_ids": [] },
  "material": { "id": null, "name": "Café Fiesta Arabica 250 g", "material_category_id": 9,
                "unit_name": "pcs", "vat_rate": 6 },                                    // podane id = istniejący surowiec
  "units_per_pack": 12,
  "product": { "name": "Fiesta Arabica - 250 g.", "id_category": 61, "suggested_sale_price": 6.90,
               "tax_value_percent": 6, "single_weight": 250, "is_active": 0 },
  "recipe_quantity": 1
}
→ 201 { "supplier_id": 6, "catalog_product_id": 812, "material_id": 1544, "product_id": 2210, "recipe_id": 1980 }
```

Wszystko albo nic: jeśli produkt zostanie odrzucony, nie zostaje żaden osierocony surowiec. To jest endpoint, który kreator kokpitu będzie wywoływał pozycja po pozycji; endpointy 1–7 pozostają przydatne do późniejszych korekt (zmiana ceny, wycofany artykuł).

## Do rozstrzygnięcia

1. **Cena**: cenniki są obecnie prowadzone per sklep (`/material-suppliers/{fid}/shops/{sid}/price-lists`). Czy cennik dostawcy obowiązujący w całej sieci ma być zapisany raz (puste `shop_ids`), czy osobno dla każdego sklepu?
2. **EAN**: w pozycji katalogu dostawcy, w surowcu czy w produkcie? Kasa potrzebuje go do skanowania artykułu.
3. **VAT sprzedaży**: na poziomie produktu (`tax_value_percent` występuje dziś tylko w `/shops/{id}/products/available`) czy sklepu?
4. **Aktywacja**: czy produkt utworzony z `is_active: 0` aktywuje się w panelu, czy kokpit ma wywołać `PATCH /products/{id}` `{ "is_active": 1 }` po zatwierdzeniu?
5. **Nazwa niderlandzka**: gdzie ją zapisać (alias nazwy, jak dla okresów dostępności)?
