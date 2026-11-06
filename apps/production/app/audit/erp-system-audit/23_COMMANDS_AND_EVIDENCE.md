# Polecenia i dowody

Wszystkie polecenia były read-only poza utworzeniem katalogu raportu. Sekrety nie są cytowane.

| Cel | Polecenie (skrót) | Wynik |
|---|---|---|
| Workspace | `rg --files -g '!audit/**' -g '!bin/**' -g '!obj/**'` | rozbudowane repo PHP, vendor i assets |
| Git | `git status --short`, `git branch --show-current`, `git log -5 --oneline` | master; bardzo brudny worktree; zachowany |
| Technologia | `rg ... composer.json public/composer.json` | FastRoute, PHP-DI, Symfony, Twig, PDO, AWS, TCPDF, Spreadsheet |
| DB config | `rg ... .env .prod.env` | wykryto konfigurację dev i jawny sekret prod; wartości wyłączone z raportu |
| DB bootstrap | `rg -n '^' App/Core/Database.php` | PDO MySQL i charset utf8mb4 |
| Routing | `rg -n 'addRoute' App/Core/Routes --count-matches` | 317 deklaracji łącznie; 314 web w plikach ładowanych globem oraz 3 API |
| API | `rg -n '^' public/api.php App/Core/Routes/ApiRoutes.php` | osobny dispatcher, 3 endpointy |
| Auth | `rg ... password_hash/password_verify/session/JWT` | web porównuje plaintext; Agent ma nowszy JWT/RBAC |
| CSRF | `rg ... csrf/SameSite/session_regenerate_id` | brak mechanizmu CSRF i hardeningu sesji App |
| Domeny | `rg --files App/Controllers App/Services App/Repositories App/Views Agent/...` | katalog domen opisany w 03 |
| DB model | `rg ... protected $table` oraz SQL patterns | częściowa lista tabel i query objects |
| Migracje | `rg ... CREATE TABLE/ALTER/FK/INDEX migrations Agent -g '*.sql'` | fragmentaryczne migracje, brak baseline |
| Testy | `rg --files -g '*Test.php' -g 'phpunit*.xml*' ...` | brak test suites |
| Dług | `rg ... TODO/FIXME/ToRefactor/die/exit` | liczne ślady migracji i debug/zakomentowany auth |
| Integracje | `rg ... outbox/webhook/RabbitMQ/S3/Peppol` | kilka outboxów, workerów i integracji |
| Runtime | próby `php -v`, klienta DB | BLOCKED: binaria niedostępne |

## Najważniejsze referencje

- `public/index.php:5-13` — wejście web.
- `App/Core/App.php:20-57` — dispatch bez centralnego auth.
- `App/Core/Routes/routes.php:7-15` — automatyczne ładowanie tras.
- `App/Core/Routes/ApiRoutes.php:13-28` — 3 trasy API.
- `App/Services/Auth/LoginService.php:21-25` — porównanie hasła.
- `App/Repositories/User/UserRepository.php:47-50` — cały rekord w sesji.
- `App/Core/Web/BaseController.php:73-79,163-165` — debug/cache i ujawnianie wyjątku.
- `App/Services/Recipe/FlattenedRecipeService.php:74` — niedokończony zapis flattened.
- `App/Repositories/Production/ProductionListRepository.php:139-164` — dynamiczne SQL planowania.
- `.prod.env:11-14` — lokalizacja sekretu, bez reprodukcji.

