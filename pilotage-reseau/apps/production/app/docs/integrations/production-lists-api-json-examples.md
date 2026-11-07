# WPF: zanonimizowane odpowiedzi JSON — listy produkcyjne

Ten dokument opisuje bieżący kontrakt odpowiedzi `200` bez zmiany endpointów. Wszystkie nazwy, identyfikatory, ilości i sekcje w przykładach są fikcyjne. Nie zawiera danych produkcyjnych ani poświadczeń.

## Wspólne reguły typów

`status` jest zawsze **string** o wartości `"ok"`. `data` jest zawsze **object**. Wartości liczbowe list produkcyjnych pochodzą z kolumn całkowitych tymczasowej tabeli SQL i są serializowane jako JSON **number**. W aktualnym kontrakcie żadne pole opisane niżej nie jest `null`.

### Typy: standardowa lista produkcyjna

| Ścieżka | Typ JSON | Uwagi |
| --- | --- | --- |
| `status` | string | `"ok"` |
| `data` | object | |
| `data.dates` | array | Zawsze dokładnie 4 elementy. |
| `data.dates[]` | object | |
| `data.dates[].expected_shipping_date` | string | Data `YYYY-MM-DD` albo `"-"`. |
| `data.section_data` | array | Może być pusta. |
| `data.section_data[]` | object | Jedna sekcja produkcyjna. |
| `data.section_data[].section_name` | string | |
| `data.section_data[].section_products` | array | Może być pusta w strukturze, ale obecny serwer nie dodaje sekcji bez pozycji. |
| `data.section_data[].section_products[]` | object | Pełna pozycja produktu. |
| `…id_product` | number | |
| `…name` | string | |
| `…ppc` | number | Sztuk w kartonie. |
| `…stock_minimum_carton`, `…stock_minimum_pcs` | number | |
| `…in_stock_carton`, `…in_stock_pcs` | number | |
| `…day_1_carton`, `…day_1_pcs` | number | Ilość dla `dates[0]`. |
| `…day_2_carton`, `…day_2_pcs` | number | Ilość dla `dates[1]`. |
| `…day_3_carton`, `…day_3_pcs` | number | Ilość dla `dates[2]`. |
| `…day_4_carton`, `…day_4_pcs` | number | Ilość dla `dates[3]`. |
| `…complaint_carton`, `…complaint_pcs` | number | Niewysłane zwroty. |
| `…id_recipe` | number | `0`, gdy produkt nie ma receptury. |
| `data.stock_minimum_flag` | boolean | Zwraca przekazaną flagę `stock_minimum`. |
| `data.pdf` | object | Konfiguracja faktycznie przekazywana do renderera PDF. |
| `data.pdf.orientation` | string | Zawsze `"landscape"`. |
| `data.pdf.hide_stock_flag` | boolean | `true` wyłącznie dla pustego `order_ids` i `stock_minimum: true`. |
| `data.pdf.header` | array | Dokładnie 7 wartości typu string. |
| `data.pdf.section_header` | array | Dokładnie 12 wartości typu string. |

`data.pdf.header` jest zwracane w obecnej kolejności generatora: `["", "W magazynie", "Zamówienie", "Do produkcji", dates[1], dates[2], dates[3]]`. Pierwsza data (`dates[0]`) nie znajduje się w tej tablicy, ponieważ generator PDF obsługuje ją osobną kolumną.

## 1. `POST /api/internal/v1/production-lists/preview`

### Wariant A: wybrane zamówienia, `stock_minimum: false`

Przykładowe body: `{"order_ids":[101,102],"stock_minimum":false}`.

```json
{
  "status": "ok",
  "data": {
    "dates": [
      { "expected_shipping_date": "2026-08-04" },
      { "expected_shipping_date": "2026-08-05" },
      { "expected_shipping_date": "-" },
      { "expected_shipping_date": "-" }
    ],
    "section_data": [
      {
        "section_name": "Sekcja chłodnicza",
        "section_products": [
          {
            "id_product": 501,
            "name": "Produkt przykładowy A",
            "ppc": 8,
            "stock_minimum_carton": 3,
            "stock_minimum_pcs": 24,
            "in_stock_pcs": 16,
            "in_stock_carton": 2,
            "day_1_carton": 4,
            "day_1_pcs": 32,
            "day_2_carton": 2,
            "day_2_pcs": 16,
            "day_3_carton": 0,
            "day_3_pcs": 0,
            "day_4_carton": 0,
            "day_4_pcs": 0,
            "complaint_carton": 1,
            "complaint_pcs": 8,
            "id_recipe": 71
          }
        ]
      }
    ],
    "stock_minimum_flag": false,
    "pdf": {
      "orientation": "landscape",
      "hide_stock_flag": false,
      "header": ["", "W magazynie", "Zamówienie", "Do produkcji", "2026-08-05", "-", "-"],
      "section_header": ["Produkt", "Kart.", "Szt.", "Kart.", "Szt.", "Szt.", "Kart.", "Szt.", "Kart.", "Szt.", "Kart.", "Szt."]
    }
  }
}
```

### Wariant B: wybrane zamówienia, `stock_minimum: true`

Przykładowe body: `{"order_ids":[103],"stock_minimum":true}`. Struktura pozycji jest identyczna; zmienia się flaga i filtr kwalifikujący produkt do listy (minimum magazynowe uczestniczy w wyliczeniu).

```json
{
  "status": "ok",
  "data": {
    "dates": [
      { "expected_shipping_date": "2026-08-06" },
      { "expected_shipping_date": "-" },
      { "expected_shipping_date": "-" },
      { "expected_shipping_date": "-" }
    ],
    "section_data": [
      {
        "section_name": "Sekcja pakowania",
        "section_products": [
          {
            "id_product": 502,
            "name": "Produkt przykładowy B",
            "ppc": 12,
            "stock_minimum_carton": 5,
            "stock_minimum_pcs": 60,
            "in_stock_pcs": 48,
            "in_stock_carton": 4,
            "day_1_carton": 1,
            "day_1_pcs": 12,
            "day_2_carton": 0,
            "day_2_pcs": 0,
            "day_3_carton": 0,
            "day_3_pcs": 0,
            "day_4_carton": 0,
            "day_4_pcs": 0,
            "complaint_carton": 0,
            "complaint_pcs": 0,
            "id_recipe": 0
          }
        ]
      }
    ],
    "stock_minimum_flag": true,
    "pdf": {
      "orientation": "landscape",
      "hide_stock_flag": false,
      "header": ["", "W magazynie", "Zamówienie", "Do produkcji", "-", "-", "-"],
      "section_header": ["Produkt", "Kart.", "Szt.", "Kart.", "Szt.", "Szt.", "Kart.", "Szt.", "Kart.", "Szt.", "Kart.", "Szt."]
    }
  }
}
```

### Wariant C: puste `order_ids`, `stock_minimum: false`

Przykładowe body: `{"order_ids":[],"stock_minimum":false}`. To tryb kontroli braków względem minimum. Daty są wypełnione `"-"`; ilości dni mają wartość `0`.

```json
{
  "status": "ok",
  "data": {
    "dates": [
      { "expected_shipping_date": "-" },
      { "expected_shipping_date": "-" },
      { "expected_shipping_date": "-" },
      { "expected_shipping_date": "-" }
    ],
    "section_data": [
      {
        "section_name": "Sekcja produkcyjna A",
        "section_products": [
          {
            "id_product": 503,
            "name": "Produkt przykładowy C",
            "ppc": 6,
            "stock_minimum_carton": 4,
            "stock_minimum_pcs": 24,
            "in_stock_pcs": 12,
            "in_stock_carton": 2,
            "day_1_carton": 0,
            "day_1_pcs": 0,
            "day_2_carton": 0,
            "day_2_pcs": 0,
            "day_3_carton": 0,
            "day_3_pcs": 0,
            "day_4_carton": 0,
            "day_4_pcs": 0,
            "complaint_carton": 0,
            "complaint_pcs": 0,
            "id_recipe": 85
          }
        ]
      }
    ],
    "stock_minimum_flag": false,
    "pdf": {
      "orientation": "landscape",
      "hide_stock_flag": false,
      "header": ["", "W magazynie", "Zamówienie", "Do produkcji", "-", "-", "-"],
      "section_header": ["Produkt", "Kart.", "Szt.", "Kart.", "Szt.", "Szt.", "Kart.", "Szt.", "Kart.", "Szt.", "Kart.", "Szt."]
    }
  }
}
```

### Wariant D: puste `order_ids`, `stock_minimum: true`

Przykładowe body: `{"order_ids":[],"stock_minimum":true}`. Dane sekcji są wyliczane w tym samym trybie kontroli minimum co w wariancie C. Flagi poniżej są jedyną różnicą kontraktową: generator PDF ukrywa w tym trybie stan magazynowy.

```json
{
  "status": "ok",
  "data": {
    "dates": [
      { "expected_shipping_date": "-" },
      { "expected_shipping_date": "-" },
      { "expected_shipping_date": "-" },
      { "expected_shipping_date": "-" }
    ],
    "section_data": [
      {
        "section_name": "Sekcja produkcyjna B",
        "section_products": [
          {
            "id_product": 504,
            "name": "Produkt przykładowy D",
            "ppc": 10,
            "stock_minimum_carton": 2,
            "stock_minimum_pcs": 20,
            "in_stock_pcs": 10,
            "in_stock_carton": 1,
            "day_1_carton": 0,
            "day_1_pcs": 0,
            "day_2_carton": 0,
            "day_2_pcs": 0,
            "day_3_carton": 0,
            "day_3_pcs": 0,
            "day_4_carton": 0,
            "day_4_pcs": 0,
            "complaint_carton": 0,
            "complaint_pcs": 0,
            "id_recipe": 0
          }
        ]
      }
    ],
    "stock_minimum_flag": true,
    "pdf": {
      "orientation": "landscape",
      "hide_stock_flag": true,
      "header": ["", "W magazynie", "Zamówienie", "Do produkcji", "-", "-", "-"],
      "section_header": ["Produkt", "Kart.", "Szt.", "Kart.", "Szt.", "Szt.", "Kart.", "Szt.", "Kart.", "Szt.", "Kart.", "Szt."]
    }
  }
}
```

Jeżeli żadna pozycja nie spełnia warunku listy, sukces ma nadal status `200`, a `data.section_data` to pusta **array**: `[]`.

## 2. `GET /api/internal/v1/production-lists/complaint`

### Typy: lista zwrotów

| Ścieżka | Typ JSON | Uwagi |
| --- | --- | --- |
| `status` | string | `"ok"` |
| `data` | object | |
| `data.section_data` | array | Może być pusta. |
| `data.section_data[]` | object | |
| `data.section_data[].section_name` | string | |
| `data.section_data[].section_products` | array | |
| `data.section_data[].section_products[].id_product` | number | |
| `…name` | string | |
| `…ppc`, `…complaint_carton`, `…complaint_pcs` | number | |
| `data.pdf` | object | |
| `data.pdf.orientation` | string | Zawsze `"portrait"`. |
| `data.pdf.header` | array | Dokładnie 2 wartości typu string. |
| `data.pdf.section_header` | array | Dokładnie 3 wartości typu string. |

### Wariant E: są niewysłane zwroty

```json
{
  "status": "ok",
  "data": {
    "section_data": [
      {
        "section_name": "Sekcja zwrotów",
        "section_products": [
          {
            "id_product": 601,
            "name": "Produkt zwrotny przykładowy",
            "ppc": 8,
            "complaint_carton": 3,
            "complaint_pcs": 24
          }
        ]
      }
    ],
    "pdf": {
      "orientation": "portrait",
      "header": ["", "Zwrot"],
      "section_header": ["Produkt", "Kart.", "Szt."]
    }
  }
}
```

### Wariant F: brak niewysłanych zwrotów

Sukces nadal zwraca `200`; jedyną pustą wartością jest `section_data` (**array**).

```json
{
  "status": "ok",
  "data": {
    "section_data": [],
    "pdf": {
      "orientation": "portrait",
      "header": ["", "Zwrot"],
      "section_header": ["Produkt", "Kart.", "Szt."]
    }
  }
}
```

## Wskazówki dla WPF

Traktuj `dates` i kolejność `section_data`/`section_products` jako kolejność prezentacji zwróconą przez serwer. Nie dodawaj, nie usuwaj i nie przeliczaj pozycji. Dane `pdf` można wykorzystać do opisania widoku, ale przy wydruku należy nadal pobrać binarny dokument z dedykowanego endpointu `/pdf`; WPF nie tworzy własnego PDF.
