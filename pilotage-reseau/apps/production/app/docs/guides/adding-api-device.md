# Dodawanie urządzenia API i integracja z aplikacją produkcyjną

## Dodanie urządzenia

1. W panelu web otwórz **Urządzenia → Dodaj urządzenie**.
2. Podaj rozpoznawalną nazwę, np. `Produkcja WPF — stanowisko 1`; opis jest opcjonalny.
3. Wybierz **Utwórz i wygeneruj klucz**.
4. Skopiuj widoczne jednorazowo wartości `Device ID` i `API key` do bezpiecznego kanału przekazania administratorowi aplikacji produkcyjnej.
5. Po opuszczeniu ekranu klucz nie jest możliwy do odczytania z panelu ani bazy; widoczny pozostaje tylko `Device ID`.

## Utrzymanie dostępu

- **Przegląd urządzeń** pokazuje status, identyfikator, autora, datę utworzenia i ostatnie użycie.
- **Rotuj klucz** natychmiast unieważnia poprzedni klucz. Nowy należy podmienić w aplikacji produkcyjnej przed jej kolejnym żądaniem.
- **Wyłącz** blokuje urządzenie bez usuwania historii. Kolejne żądania otrzymają HTTP `403`.
- Nie przesyłaj klucza przez niezaszyfrowaną wiadomość ani nie zapisuj go w kodzie źródłowym, pliku konfiguracyjnym śledzonym przez Git lub logach.

## Konfiguracja aplikacji produkcyjnej

Każde żądanie do wewnętrznego API w sieci firmowej może korzystać z HTTP i musi przekazywać:

```http
X-Device-Id: <Device ID>
X-Api-Key: <API key>
```

W aplikacji Windows klucz należy przechowywać w magazynie systemowym (Windows Credential Manager lub DPAPI). Test po skonfigurowaniu: pobrać listę zamówień przez `http://<host>/api/internal/v1/client-orders`; status `200` oznacza gotową integrację.

## Co robić w razie problemu

- `401` — brak nagłówka albo nieprawidłowy `Device ID`/klucz.
- `403` — urządzenie jest wyłączone.
- `500` — skontaktuj się z administratorem; nie twórz nowego urządzenia jako obejścia.
- Podejrzenie ujawnienia klucza — natychmiast użyj **Rotuj klucz**.
