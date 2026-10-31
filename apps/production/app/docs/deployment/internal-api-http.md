# Wewnętrzne API przez HTTP

Wewnętrzne endpointy `/api/internal/v1/*` nie wymagają HTTPS. Aplikacja nie wykonuje przekierowań `301`, `302`, `307` ani `308` z HTTP do HTTPS.

## Wymagania serwera WWW / reverse proxy

Konfiguracja wdrożeniowa nie jest przechowywana w tym repozytorium. Administrator serwera musi potwierdzić, że reverse proxy:

1. nasłuchuje w sieci wewnętrznej na porcie HTTP, zwykle `80`;
2. przekazuje ścieżkę `/api/*` do katalogu `public` aplikacji;
3. nie ma reguły `return 301 https://...`, `rewrite ... https://...` ani odpowiednika dla tej ścieżki;
4. nie wymusza uwierzytelnienia urządzenia na warstwie proxy.

W konfiguracji Apache znajdującej się w repozytorium `public/.htaccess` reguła `^api/(.*)$` przekazuje żądania do `api.php` bez zmiany metody, ścieżki ani przekierowania.

## Test po wdrożeniu

Bez poświadczeń odpowiedź musi zostać obsłużona przez endpoint i nie może zawierać nagłówka `Location`:

```bash
curl -i http://<wewnetrzny-host>/api/internal/v1/client-orders
```

Oczekiwana jest odpowiedź `200`:

```bash
curl -i \
  -H 'Accept: application/json' \
  http://<wewnetrzny-host>/api/internal/v1/client-orders
```

Żądania nie wymagają klucza urządzenia.
