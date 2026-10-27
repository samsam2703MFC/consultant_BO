# Prośba o rozszerzenie Swaggera TFBuddy — endpointy zapisu do kodowania artykułów spożywczych na odsprzedaż (v3)

Do: zespół deweloperski `atelierby.tfbuddy.com` · Od: L’Atelier by (kokpit CEO) · 27.10.2026, wersja 3  
Bazowy URL: `https://atelierby.tfbuddy.com/api/v1` · Wersje: [français](demande-fr.md), [English](demande-en.md), polski

## Plik do włączenia

**`openapi-ecritures-epicerie.yaml`** (w tym katalogu) opisuje wszystko, o co prosimy, w formacie **OpenAPI 3.0.3, zwalidowanym**: 14 operacji, przypisanych do **Waszych** sekcji i **Waszych** kontrolerów, ze schematami, przykładami i odpowiedziami błędów. Otwiera się bez zmian w <https://editor.swagger.io> i można go scalić z Waszym `/swagger/openapi.json`. Nowe pola mają oznaczenie `x-nouveau: true`. Podgląd: `apercu-swagger-1-sections.png` i `apercu-swagger-2-products-resale.png`.

## Potrzeba

Sieć odsprzedaje w dziale **delikatesów (épicerie)** artykuły kupowane i sprzedawane bez przetwarzania. Kokpit skanuje PDF dostawcy; kreator koduje i zatwierdza każdą pozycję — cena, kod kreskowy, VAT, okresy sprzedaży, nazwa, składniki i alergeny po francusku i po niderlandzku — a następnie tworzy ją w TFBuddy **jako jeden łańcuch**:

```
dostawca ──► pozycja katalogu dostawcy (SKU, EAN, opakowanie zbiorcze, cena) ──► surowiec (alergeny) ──► receptura (1 × surowiec) ──► produkt sprzedażowy (kategoria, cena maks., okresy, FR/NL)
```

**Obecny Swagger (stan na 27.10.2026) pozwala tylko na odczyt** tych zasobów: `GET /material-categories`, `GET /material-suppliers`, `GET /materials`, `GET /product-categories`, `GET /product-category-groups`, `GET /product-availability-periods`, `GET /products`, `GET /shops/{id}/products/available`. Jedyny istniejący zapis produktu, `POST /product-recipe/{id}/photo`, wymaga już istniejącego produktu.

## Co dodać — sekcja po sekcji Waszego Swaggera

### Products — ProductController

| Metoda | Ścieżka | Przeznaczenie |
|---|---|---|
| POST | `/products/resale` ★ | **Priorytet** — tworzy cały łańcuch w jednym, atomowym wywołaniu (wszystko albo nic). To ten endpoint kreator wywołuje pozycja po pozycji. |
| POST | `/products` | Tworzy produkt sprzedażowy. |
| PATCH | `/products/{id}` | Modyfikuje produkt (częściowo). |

### Material suppliers — MaterialController supplier

| Metoda | Ścieżka | Przeznaczenie |
|---|---|---|
| POST | `/material-suppliers` | Tworzy dostawcę. |
| PATCH | `/material-suppliers/{id}` | Modyfikuje dostawcę (częściowo). |
| POST | `/material-suppliers/{id}/catalog/products` | Dodaje pozycję do katalogu dostawcy. |
| PATCH | `/material-suppliers/{id}/catalog/products/{productId}` | Modyfikuje pozycję katalogu (częściowo). |
| POST | `/material-suppliers/{id}/price-lists` | Ustala datowaną cenę opakowania, **raz dla całej sieci**. |
| POST | `/material-suppliers/{id}/catalog-mappings` | Łączy pozycję katalogu z surowcem. |

### Materials — MaterialController

| Metoda | Ścieżka | Przeznaczenie |
|---|---|---|
| POST | `/materials` | Tworzy surowiec wraz z alergenami. |
| PATCH | `/materials/{id}` | Modyfikuje surowiec (częściowo). |

### Product recipes — ProductController recipe

| Metoda | Ścieżka | Przeznaczenie |
|---|---|---|
| PUT | `/products/{id}/recipe` | Ustala (zastępuje) recepturę produktu — dla artykułów spożywczych 1 × jego surowiec. |

### Product availability periods — ProductController availability periods

| Metoda | Ścieżka | Przeznaczenie |
|---|---|---|
| POST | `/product-availability-periods/{id}/products` | Udostępnia produkt w danym okresie (aktywacja). |
| DELETE | `/product-availability-periods/{id}/products/{productId}` | Usuwa produkt z okresu. |

★ = do dostarczenia w pierwszej kolejności: z `POST /products/resale` i jego `OPTIONS` kreator kokpitu działa od początku do końca. Pozostałe operacje służą do późniejszych korekt (zmiana ceny, okres sprzedaży, wycofany artykuł).

## Nowe pola (`x-nouveau: true`)

| Sekcja | Pole | Typ | Znaczenie |
|---|---|---|---|
| Products | `name_nl` | `string` | Nazwa po niderlandzku. |
| Products | `max_sale_price` | `number` | **Maksymalna** cena sprzedaży brutto, **taka sama dla wszystkich sklepów**: sklep może sprzedawać taniej, nigdy drożej. |
| Products | `ean` | `string` | Kod kreskowy produktu, skanowany w kasie (cyfra kontrolna EAN weryfikowana przez kokpit). |
| Products | `ingredients_fr` | `string` | Lista składników po francusku. |
| Products | `ingredients_nl` | `string` | Lista składników po niderlandzku. |
| Products | `availability_period_ids` | `integer[]` | Okresy dostępności (identyfikatory z `GET /product-availability-periods`): produkt jest aktywny w tych okresach. |
| Materials | `allergens` | `AllergenCode[]` | Alergeny, które surowiec **zawiera** (kody z `GET /allergens`); produkt dziedziczy je przez recepturę. |
| Materials | `may_contain` | `AllergenCode[]` | Możliwe śladowe ilości („może zawierać”). |

## Decyzje L’Atelier by

1. **Cena**: jeden cennik dostawcy dla **całej sieci**; cena sprzedaży jest **maksymalna, wspólna dla wszystkich sklepów** (`max_sale_price`).
2. **Kod kreskowy** w produkcie (`ean`) i w pozycji katalogu dostawcy.
3. **VAT sprzedaży** na poziomie produktu (`tax_value_percent`), wszędzie taki sam.
4. **Aktywacja** przez okresy dostępności (`availability_period_ids`).
5. **Dwujęzyczność**: nazwa i składniki po francusku i po niderlandzku.
6. **Alergeny** w surowcu (`allergens`, `may_contain`), dziedziczone przez produkt. **Istniejący** surowiec nigdy nie jest zmieniany przez kokpit: korzystają z niego inne receptury.

## Zasady wspólne dla wszystkich zapisów

- Realm **admin** (`POST /admin/auth/login`), `Authorization: Bearer <token>`; konto konsultanta może pozostać tylko do odczytu.
- `201` z pełnym obiektem (łącznie z `id`); `422` ze wskazaniem błędnego pola, w jednolitej kopercie `{ "error": { "code", "message", "field" } }`.
- `409` przy duplikacie (ten sam `sku` u tego samego dostawcy, ten sam `ean`, ta sama `name` w tej samej kategorii) **z istniejącym id** (`error.existing_id`): kreator użyje go zamiast kończyć się błędem.
- `client_ref` w każdym POST: drugi POST z tym samym `client_ref` niczego nie tworzy i zwraca już utworzony obiekt (`200`).
- `OPTIONS` na każdej ścieżce zwraca obsługiwane metody w nagłówku `Allow`: właśnie to odczytuje przycisk „Sprawdź API” w kokpicie, niczego nie zapisując.
- `PATCH` częściowy: zmieniane są tylko przesłane pola.

## Wywołanie priorytetowe — przykład

```
POST /products/resale
{
  "client_ref": "ep14-l7",
  "supplier":  { "id": 6 },
  "catalog_product": { "sku": "CS-CHOC-72", "name": "Chocolat noir 72 % 100 g", "ean": "5412345000075",
                       "package_size": 20, "package_unit": "pcs", "vat_rate": 6 },
  "price": { "price_net": 34.00, "valid_from": "2026-11-01" },
  "material": { "name": "Chocolat noir 72 % 100 g", "material_category_id": 11, "unit_name": "pcs", "vat_rate": 6,
                "allergens": ["milk", "soybeans"], "may_contain": ["nuts"] },
  "units_per_pack": 20,
  "product": { "name": "Chocolat noir 72 % - 100 g", "name_nl": "Pure chocolade 72 % - 100 g",
               "id_category": 61, "max_sale_price": 3.20, "tax_value_percent": 6, "ean": "5412345000075",
               "single_weight": 100,
               "ingredients_fr": "Pâte de cacao, sucre, beurre de cacao, lait entier en poudre, émulsifiant : lécithine de soja.",
               "ingredients_nl": "Cacaomassa, suiker, cacaoboter, volle melkpoeder, emulgator: sojalecithine.",
               "availability_period_ids": [1] },
  "recipe_quantity": 1
}
→ 201 { "supplier_id": 6, "catalog_product_id": 812, "material_id": 1544, "product_id": 2210, "recipe_id": 1980 }
```

## Czego potrzebujemy w odpowiedzi

1. Nazwy wybrane dla nowych pól, jeśli będą inne: kokpit się dostosuje.
2. Zachowanie kasy powyżej `max_sale_price`: blokada czy ostrzeżenie?
3. Termin udostępnienia `POST /products/resale`, nawet na środowisku testowym: kokpit jest gotowy i od razu go przetestuje.
