# Kontrole nieudane lub zablokowane

## BLOCKED-01 Runtime PHP

Próba: `php -v`. Błąd środowiska: executable not found. Skutek: brak lint, startu panelu/API, smoke CLI i testów usług. Wymaganie: PHP zgodne z lockfile i rozszerzenia PDO MySQL, cURL, mbstring.

## BLOCKED-02 Composer/test runner

Composer niedostępny, a manifest nie definiuje scripts ani require-dev. Skutek: brak deterministycznej komendy build/test. Wymaganie: runtime Composer i jawny test harness.

## BLOCKED-03 Baza

Nie wykonano połączenia. Powody: brak klienta/runtime oraz brak potwierdzenia, że skonfigurowana baza jest testowa. Wymaganie: osobny endpoint/test schema, konto read-only i zgoda na transakcje rollback dla testów zapisu.

## BLOCKED-04 Aplikacja E2E

Brak serwera, danych testowych i kont. Wymaganie: izolowane środowisko, seed bez danych osobowych, testowe integracje i opis ról.

## NOT_TESTED-05 Integracje

S3/R2, e-mail, webhooki, RabbitMQ i Peppol nie zostały wywołane, aby nie wysłać danych ani dokumentów do rzeczywistych systemów. Wymaganie: sandbox/mocks i wyłączone side effects.

## NOT_VERIFIABLE-06 Agent

Nie znaleziono aktywnego front controllera/routingu dla `Agent/api_app_dir`. Wymaganie: wskazanie repo/deploymentu, bootstrapa i konfiguracji tras.

