# Inwentaryzacja repozytorium

## Zakres

W katalogu roboczym wykryto jedno repozytorium Git na gałęzi `master`. Worktree jest bardzo mocno zmodyfikowany (pliki A/M/D/??); audyt nie zmieniał kodu istniejącego. `vendor/`, `public/vendor/`, `public/assets/vendors/`, fonty TCPDF i PHPMailer wyłączono z oceny funkcjonalnej jako zależności trzecie.

## Technologia

- PHP 8+ (typy, constructor promotion, `str_starts_with`), bez pełnego frameworka.
- FastRoute, PHP-DI, Symfony HttpFoundation, Twig.
- PDO/MySQL; DSN w `App/Core/Database.php:10-21`.
- TCPDF, PhpSpreadsheet, AWS SDK, Guzzle/cURL, Firebase JWT (w kodzie Agent).
- Sesyjny panel web i osobny styl API.

## Uruchamianie

Nie ma README głównego, Docker Compose, Dockerfile, Makefile, CI ani skryptów Composer. Typowy model to serwer WWW z document root `public/` oraz rewrite do `index.php`/`api.php`. Brak jednoznacznej instrukcji uruchomienia jest luką P1 operacyjną.

## Punkty wejścia

- `public/index.php:5-13` — sesja, init i `App::loadController()`.
- `public/api.php:3-75` — autoload, DI i dispatch API.
- `Bin/worker_outbox.php`, `pricelist_sync.php`, `orders_collector.php`, `poller.php`, `shipments_retry.php` — procesy okresowe.

## Konfiguracja

- `.env` wskazuje bazę deweloperską o nazwie datowanej; nie ma gwarancji, że jest bezpieczna do zapisu.
- `.prod.env` zawiera jawne poświadczenia produkcyjne — P0.
- Konfiguracje integracji AWS/JWT/RabbitMQ/URL są pobierane ze środowiska; brak administracyjnego UI i walidatora konfiguracji.

## Migracje

Katalog `migrations/` jest zbiorem numerowanych SQL i dokumentacji bez narzędzia migracyjnego, tabeli wersji ani gwarancji kolejności. Numery są powtarzane, a część zmian ma tylko Markdown. To uniemożliwia pewne odtworzenie schematu.

## Aktywny i martwy kod

- `App/Core/Routes/routes.php:7-15` ładuje wyłącznie `*/*Routes.php`; 314 tras web jest potencjalnie aktywnych.
- `App/Core/Routes/ApiRoutes.php` nie pasuje do tego globu, ale jest używany bezpośrednio przez `public/api.php` i ma 3 trasy.
- `Agent/api_app_dir` zawiera setki kontrolerów/repozytoriów, lecz brak potwierdzonego bootstrapa. Klasyfikacja: potencjalnie inny deploy/repo fragment, nie aktywna funkcja obecnego panelu.
- `App/ToRefactor`, `Controllers/ToRefactor`, `Repositories/ToRefactor`, kopie `.backup`, `.tmp` i zakomentowany stary dispatcher są długiem/martwym kodem.

