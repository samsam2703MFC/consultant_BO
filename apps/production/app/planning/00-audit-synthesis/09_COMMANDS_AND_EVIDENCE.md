# Polecenia i dowody walidacji

Wykonano wyłącznie odczyty kodu/Git oraz utworzenie dokumentacji. Nie łączono się z bazą i nie wywoływano integracji.

| Cel | Polecenie / metoda | Wynik |
|---|---|---|
| Odczyt pełnego audytu | `rg -n '^' audit/erp-system-audit ...` w grupach | wszystkie artefakty przejrzane; wykryto 42/50 i F4/test=0 |
| Stan repo | `git status --short`, `git log` | jeden repo; duży dirty worktree; config pochodzi z initial commit |
| Login App | odczyt `LoginController`, `LoginService`, `UserRepository` | stałe admin/admin, raw compare, cały rekord w sesji |
| Ochrona tras | odczyt `App.php`, User routes/controller; search auth | brak centralnego check; aktywny CRUD użytkowników bez lokalnego check |
| Sekrety/debug | odczyt `App/Core/config.php`, entrypoints | śledzony sekret, DEBUG=true i display_errors |
| CSRF/session | wyszukiwanie csrf/xsrf/nonce/SameSite/regenerate | brak trafień w kodzie własnym App |
| Routing | `rg addRoute --count-matches`, odczyt globu | 314 deklaracji potencjalnie web + 3 App API |
| Agent entrypoint | `rg --files Agent` + search route/bootstrap/public | nie znaleziono front controllera/routingu w workspace |
| Produkcja/odpady | search production/waste/batch/quality + odczyt Movement/Waste | w App brak wykonania; w Agent jest `product_movement` i raporty waste |
| Modele magazynu | odczyt `$table` repositories | `carton_stock`, `material_inventory*`, `product_movement` |
| Analityka | odczyt SalesAnalytics/ProductMargin/Transaction repos | WDT revenue, miesięczne koszty, POS ceny/VAT; snapshot kosztu nieudowodniony |
| Testy/runtime/DB | ponowna ocena artefaktów | 0 testów produktu, runtime i DB nadal BLOCKED |

## Kluczowe referencje

- `App/Core/config.php:29-38,56` — sekret, adresy i DEBUG.
- `App/Controllers/Web/Auth/LoginController.php:24-32` — wymuszone dane logowania.
- `App/Services/Auth/LoginService.php:21-25` — porównanie hasła.
- `App/Repositories/User/UserRepository.php:47-50` — rekord w sesji.
- `App/Core/App.php:20-57` — dispatch bez centralnego auth.
- `App/Core/Routes/User/UserRoutes.php:6-38` i `UserController.php:19-71` — niechroniony CRUD.
- `App/Core/Routes/routes.php:7-15` — glob tras.
- `App/Core/Routes/ApiRoutes.php:13-28` — trzy endpointy API.
- `Agent/api_app_dir/Repositories/Product/Movement/ProductMovementRepository.php:8-29` — model ruchu.
- `Agent/api_app_dir/Services/Product/Movement/ProductMovementService.php:82-104` — typy produkcja/sprzedaż/odpad/zwrot/korekta.
- `Agent/api_app_dir/Repositories/Product/Evidence/WasteRepository.php:176-196` — raport odpadów.
- `App/Repositories/Analysis/SalesAnalyticsRepository.php:29-103` — przychód WDT.
- `App/Repositories/Analysis/ProductMarginRepository.php:93-642` — miesięczne alokacje i WDT.

## Bezpieczeństwo dowodów

Wartości sekretów nie są reprodukowane w syntezie. Potwierdzono jedynie ich lokalizację i obecność w historii. Brak wyników z bazy oznacza, że nie potwierdzono żadnych rekordów, sum ani realnej aktywności kont.

