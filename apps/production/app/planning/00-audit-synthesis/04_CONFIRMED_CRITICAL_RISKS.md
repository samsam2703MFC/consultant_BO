# Potwierdzone ryzyka krytyczne

## P0

| ID | Ryzyko | Dowód | Fakt potwierdzony | Granica wniosku |
|---|---|---|---|---|
| P0-01 | Sekret DB w repo | `App/Core/config.php:29-32`; `git log` wskazuje initial commit | poświadczenie jest zapisane w śledzonym pliku | nie sprawdzono, czy nadal działa |
| P0-02 | Wymuszone konto `admin/admin` | `LoginController.php:24-32`, aktywna trasa POST `/` | input użytkownika jest nadpisywany stałymi | nie sprawdzono, czy konto istnieje w DB |
| P0-03 | Niebezpieczne uwierzytelnianie | `LoginService.php:21-25` | hasło jest porównywane operatorem `==`, bez `password_verify` | zawartość żywej kolumny nieznana |
| P0-04 | Niechronione akcje administracyjne | `App.php:20-57`, `UserRoutes.php:6-38`, `UserController.php:19-71` | dispatcher i CRUD użytkowników nie wykonują auth/permission check | reverse proxy/WAF poza repo nieznany |

## P1

| ID | Ryzyko | Dowód i kwalifikacja |
|---|---|---|
| P1-01 | Debug i wycieki błędów | `config.php:56`, `public/index.php:9`, `BaseController.php:163-165`, `App.php:43-46`; HIGH |
| P1-02 | Brak wykrytego CSRF | brak trafień w kodzie własnym przy wielu mutujących trasach; MEDIUM (dowód negatywny) |
| P1-03 | Nieodtwarzalne migracje | brak runnera/baseline, powtarzane numery i SQL/MD; HIGH |
| P1-04 | Brak test harness i wykonania | brak PHPUnit/Pest/Behat, brak PHP/runtime; HIGH |
| P1-05 | Nierozstrzygnięty deploy Agent | setki klas, brak front controllera/routingu w workspace; HIGH dla braku lokalnego wejścia |
| P1-06 | Niedomknięta produkcja App | listy/zapotrzebowanie istnieją, brak modelu wykonania zlecenia/QC w App; MEDIUM |
| P1-07 | Wiele kandydatów na stan magazynu | `carton_stock`, Agent `material_inventory*`, `product_movement`; MEDIUM, rozbieżność danych nieudowodniona |
| P1-08 | Rentowność bez udowodnionej historii | WDT i miesięczne koszty są agregowane, brak dowodu snapshotu receptury/kosztu na sprzedaż; MEDIUM |

Ryzyka P0 wymagają reakcji niezależnie od dalszych decyzji TO-BE. Ten dokument nie implementuje napraw.

