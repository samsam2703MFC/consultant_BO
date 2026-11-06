# Integracja WPF: listy produkcyjne — API wewnętrzne v1

## Cel

Aplikacja produkcyjna ma odtworzyć ekran `/production/planning` aplikacji webowej, bez bezpośredniego dostępu do bazy. Wszystkie obliczenia pochodzą z tych samych serwisów co widok webowy i generowane przez nie pliki PDF używają tych samych szablonów.

Wszystkie endpointy opisane w tym dokumencie są dostępne bez poświadczeń urządzenia. Odpowiedzi JSON zawierają `{ "status": "ok", "data": ... }`; błędy mają `{ "status": "error", "description": "KOD" }`.

## Jak działa obecny ekran webowy

Ekran `/production/planning` najpierw pobiera wszystkie zamówienia oczekujące. Użytkownik zaznacza zamówienia, opcjonalnie włącza `stock_minimum`, a następnie wybiera jedną z trzech akcji: standardową listę produkcyjną, listę zapotrzebowania materiałowego albo listę dla zwrotów. Frontend webowy nie oblicza ilości — wysyła wyłącznie identyfikatory zamówień i flagę minimum, a serwer wylicza całą listę.

### Standardowa lista produkcyjna

Serwer wyznacza maksymalnie cztery różne daty wysyłki z zaznaczonych zamówień. Dla każdego produktu gotowego pobiera: liczbę kartonów na stanie, minimalny stan, ilości zamówione na każdą z czterech dat oraz ilość wynikającą z niewysłanych zwrotów. Produkty są grupowane według sekcji produkcyjnej i filtrowane do pozycji, dla których produkcja jest potrzebna.

Gdy flaga `stock_minimum` jest włączona, minimum magazynowe uczestniczy w wyliczeniu potrzeby produkcji. Gdy nie zaznaczono żadnego zamówienia, dostępny jest tryb kontroli minimum: zwracane są wyłącznie produkty, dla których stan magazynowy jest niższy od minimum. PDF jest tworzony przez aplikację webową w orientacji poziomej, osobno dla każdej sekcji.

### Lista zapotrzebowania materiałowego

Serwer rozwija receptury produktów z wybranych zamówień do składników materiałowych i sumuje ich wymagane ilości. Jeśli `stock_minimum` jest włączone, do obliczenia dodawany jest wkład wynikający z minimalnych stanów produktów. Wyniki są grupowane według materiału. Obecny PDF formatuje jednostki i wartości, ale dane API zwraca jako wartości obliczeniowe — WPF nie powinien ich przeliczać ani sumować ponownie.

### Lista dla zwrotów

Ta lista nie wymaga wyboru zamówień. Serwer pobiera niewysłane pozycje zwrotów, łączy je z produktami gotowymi, sumuje liczbę kartonów i grupuje je według sekcji produkcyjnej. PDF jest generowany w orientacji pionowej, osobno dla każdej sekcji.

### Co implementuje WPF, a czego nie implementuje

WPF implementuje wyłącznie UI: pobranie i zaznaczenie zamówień, widok sekcji/tabel oraz świadome uruchomienie wydruku. Nie implementuje zapytań do bazy, rozwijania receptur, stanów magazynowych, agregacji produktów ani własnych szablonów PDF. Do prezentacji używa `preview`; do wydruku wysyła to samo body do odpowiedniego endpointu `/pdf`.

## Ekran planowania i wybór zamówień

Pobierz oczekujące zamówienia przez istniejący endpoint:

`GET /api/internal/v1/client-orders`

Wyświetl je w grupach `expected_shipping_date`, z możliwością zaznaczenia. Do endpointów list przekazuj identyfikatory w polu `order_ids`. Serwer akceptuje wyłącznie zamówienia nadal oczekujące i maksymalnie cztery różne daty wysyłki — tak jak obecny ekran webowy. Przy większej liczbie dat zwraca `422 TOO_MANY_SHIPPING_DATES`.

Wartość `stock_minimum` jest opcjonalnym booleanem. `true` uwzględnia minimalne stany magazynowe; brak pola oznacza `false`.

```json
{
  "order_ids": [123, 124],
  "stock_minimum": true
}
```

## Standardowa lista produkcyjna

### Dane wejściowe generatora PDF i do widoku

`POST /api/internal/v1/production-lists/preview`

Pozycje `data.section_data` są gotowe do wyświetlenia w sekcjach produkcyjnych. Każda sekcja zawiera `section_name` i `section_products`. Produkt zawiera między innymi:

- `id_product`, `name`, `ppc`, `id_recipe`;
- stan: `in_stock_carton`, `in_stock_pcs`;
- minimum: `stock_minimum_carton`, `stock_minimum_pcs`;
- wymagania dla maksymalnie czterech dat: `day_1_carton` / `day_1_pcs` … `day_4_carton` / `day_4_pcs`;
- zwroty: `complaint_carton`, `complaint_pcs`.

`data.dates` zawiera cztery pozycje `expected_shipping_date`; brakujące pozycje mają wartość `"-"`. Kolumny `day_1`–`day_4` odpowiadają tej kolejności. Odpowiedź zawiera również `stock_minimum_flag` i `pdf`: konfigurację przekazywaną do istniejącego renderera PDF (`orientation`, `hide_stock_flag`, `header`, `section_header`). To ten sam przygotowany zestaw danych, z którego bezpośrednio korzysta endpoint PDF.

Gdy `order_ids` jest pustą tablicą, endpoint odtwarza dostępny w web tryb „tylko braki względem minimum” — zwraca produkty, dla których stan magazynowy nie osiąga minimum. Ten tryb jest dostępny tylko dla standardowej listy produkcyjnej.

### PDF

`POST /api/internal/v1/production-lists/pdf`

Użyj identycznego body jak dla `preview`. Sukces zwraca `application/pdf`; plik jest generowany istniejącym szablonem listy produkcyjnej. Nie buduj własnego PDF po stronie WPF.

Ten endpoint nie wymaga `X-Device-Id` ani `X-Api-Key`.

## Lista zapotrzebowania materiałowego

### Dane wejściowe generatora PDF i do widoku

`POST /api/internal/v1/requirement-lists/preview`

Body wymaga co najmniej jednego `order_ids`; opcjonalne `stock_minimum` ma to samo znaczenie co powyżej. Odpowiedź `data` zawiera `order_id`, `stock_minimum` i `rows`. Każdy element `rows` zawiera `id`, `material_name`, `required`, `in_stock`, `price_per_unit`, `id_unit` oraz `approx_value`.

Wartości są surowymi wartościami obliczeniowymi używanymi przez obecny PDF. WPF powinien do wyświetlenia stosować te same zasady formatowania jednostek co PDF; nie zmieniaj ilości ani nie sumuj ich ponownie.

### PDF

`POST /api/internal/v1/requirement-lists/pdf`

Użyj tego samego body. Odpowiedź to `application/pdf` z obecnym szablonem listy zapotrzebowania.

## Lista produkcyjna dla zwrotów

### Dane do widoku

`GET /api/internal/v1/production-lists/complaint`

Nie wymaga body. Dane `section_data` mają strukturę sekcji jak standardowa lista; produkty zawierają `id_product`, `name`, `ppc`, `complaint_carton` i `complaint_pcs`. Odpowiedź `pdf` zawiera używane przez renderer ustawienia `orientation`, `header` i `section_header`. Dane obejmują wyłącznie niewysłane zwroty, tak jak widok webowy.

### PDF

`POST /api/internal/v1/production-lists/complaint/pdf`

Nie wymaga body. Zwraca istniejący PDF listy produkcyjnej dla zwrotów.

Ten endpoint nie wymaga `X-Device-Id` ani `X-Api-Key`.

## Obsługa błędów i UX

- `401` — brak lub nieprawidłowe poświadczenia urządzenia; zatrzymaj operację i pokaż komunikat operatorowi.
- `403` — urządzenie wyłączone; nie ponawiaj automatycznie.
- `400 MALFORMED_JSON` — nieprawidłowe body żądania.
- `422 ORDER_IDS_REQUIRED`, `INVALID_ORDER_ID`, `ORDER_NOT_PENDING_OR_NOT_FOUND`, `TOO_MANY_SHIPPING_DATES` lub `INVALID_STOCK_MINIMUM` — popraw wybór w UI i pozwól użytkownikowi wykonać świadomą próbę ponownie.
- `500` — błąd obliczenia lub generowania PDF; pokaż kod błędu. Nie zakładaj poprawności częściowych danych.

## Zalecany przepływ WPF

1. Pobierz `client-orders`, pogrupuj po dacie wysyłki i zaznacz zamówienia.
2. Przed pokazaniem listy wywołaj właściwy endpoint `preview`.
3. Renderuj sekcje i wiersze w UI bez własnego przeliczania danych z API.
4. Na wyraźne żądanie operatora wywołaj odpowiadający endpoint `/pdf` z tym samym body i przekaż odebrane bajty do istniejącego mechanizmu drukowania PDF.
5. Po pobraniu podglądu wykonaj go ponownie tuż przed wydrukiem, jeśli operator długo pracował na ekranie — zamówienie mogło przestać być oczekujące.
