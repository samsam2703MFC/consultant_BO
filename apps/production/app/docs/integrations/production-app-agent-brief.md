# Przekazanie dla agenta: aplikacja produkcyjna WPF

## Cel i granice

Aplikacja produkcyjna jest klientem wewnętrznego API aplikacji webowej. Nie łączy się bezpośrednio z bazą. W pierwszej wersji integracja jest wyłącznie odczytowa: pobiera oczekujące zamówienia i dla wybranych zamówień pobiera PDF istniejącej listy kompletacyjnej. Nie wolno zmieniać statusów zamówień ani stanów magazynowych.

## Konfiguracja i autoryzacja

Administrator tworzy urządzenie w panelu web i jednorazowo przekazuje `Device ID` oraz `API key`. WPF przechowuje je poza repozytorium, wykorzystując DPAPI lub Windows Credential Manager. Każde żądanie HTTP w sieci firmowej przekazuje `X-Device-Id` oraz `X-Api-Key`.

Brak/błędny klucz daje `401`; wyłączone urządzenie `403`. Na te kody aplikacja ma przerwać operację, wskazać komunikat operatorowi i nie wykonywać automatycznych prób z innymi poświadczeniami.

## Kontrakt API

### Pobranie zamówień

`GET /api/internal/v1/client-orders`

Odpowiedź `200` ma postać `{ "status": "ok", "data": [...] }`. Elementy `data` są tym samym zbiorem danych co tabela `/client-orders`: `id`, klient/firma, opis, data i czas utworzenia, data wysyłki, masa, status, proforma/WDT oraz flagi `is_freebie`, `order_type`, `order_batch_key`, `batch_index`, `batch_total`. Lista obejmuje tylko zamówienia oczekujące i jest sortowana według daty wysyłki.

WPF powinien odtworzyć oznaczenia: GRATIS z `is_freebie`, DOMÓWIENIE z `order_type === "SUPPLEMENT"` oraz część zamówienia z `order_batch_key`, `batch_index`, `batch_total`.

### Pobranie danych listy kompletacyjnej

`POST /api/internal/v1/picking-lists/preview`

```json
{"order_ids":[123,124]}
```

Odpowiedź `200` ma postać `{ "status": "ok", "data": { "orders": [...] } }`. Każdy element `orders` odpowiada jednemu wskazanemu zamówieniu i zawiera `order_id`, `customer`, `shipping_date`, `is_freebie` oraz `products`. Elementy `products` zawierają `category_name`, `product_name` i `quantity_carton`.

Nie łącz ani nie sumuj produktów z różnych elementów `orders`: to celowy odpowiednik osobnych stron istniejącego PDF. Zachowaj kolejność zaznaczonych zamówień. Endpoint służy do zasilenia widoku/listy roboczej w WPF i nie powoduje wpisu w archiwum wydruków.

### Pobranie listy kompletacyjnej

`POST /api/internal/v1/picking-lists`

```json
{"order_ids":[123,124]}
```

Odpowiedź sukcesu ma `Content-Type: application/pdf` i `Content-Disposition` z nazwą pliku. Zapisz body jako `byte[]`, a następnie przekaż do istniejącego procesu wydruku PDF w aplikacji WPF. Nie twórz własnego szablonu: PDF jest generowany przez aplikację webową z obowiązującego szablonu.

Serwer akceptuje wyłącznie istniejące zamówienia oczekujące. `400` oznacza niepoprawny JSON, `422` pustą/nieprawidłową listę albo zamówienie nieoczekujące, a `500` błąd generowania lub zapisu audytu. Każde udane żądanie PDF jest rejestrowane w historii list kompletacyjnych po stronie web.

## Przepływ UI WPF

1. Przy starcie/odświeżeniu pobierz listę zamówień.
2. Pozwól operatorowi zaznaczyć co najmniej jedno zamówienie.
3. Wyślij identyfikatory zaznaczonych wierszy do endpointu `preview` i wyświetl osobne zamówienia oraz ich produkty bez sumowania między zamówieniami.
4. Gdy operator wybierze wydruk, wyślij tę samą listę ID do endpointu PDF.
5. Przyjmij binarny PDF, wyświetl wybór drukarki lub przekaż go do kolejki wydruku.
6. Po błędzie nie zakładaj, że wydruk się nie zapisał w audycie; pokaż kod i komunikat. Nie wykonuj automatycznego ponowienia generacji PDF bez świadomej decyzji operatora.

Pełniejszy przykład `HttpClient` znajduje się w `docs/integrations/production-app-api.md`.
