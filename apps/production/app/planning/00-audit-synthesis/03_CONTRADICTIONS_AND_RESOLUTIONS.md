# Sprzeczności i rozstrzygnięcia

| # | Konkurujące twierdzenia | Dowód | Rozstrzygnięcie | Pewność | Wpływ |
|---:|---|---|---|---|---|
| 1 | Podsumowanie: 42 funkcje; macierz: 50 | `00_EXECUTIVE_SUMMARY.md:7`, `04...:58` | baseline=50; nota 42 nie ma reprodukowalnej listy | HIGH | używać 50 |
| 2 | PRI-02 i COST-01 F4; testy=0/BLOCKED | macierz `:21,:50`; definicja F4 z master prompt | obie funkcje maks. F3 | HIGH | brak funkcji potwierdzonych complete |
| 3 | „PASS” w wynikach testów; brak test suites | `09...:5-9`, `08...:3-12` | PASS oznacza kontrolę statyczną, nie test produktu | HIGH | testy produktu=0 |
| 4 | Odpady/QC/partie F0 | Agent `ProductMovementService.php:82-104`, Waste repo/controller | odpady mają ślad backendowy F1/LOW; QC/partie nadal F0 | HIGH | rozdzielić zakres |
| 5 | Sekret opisany tylko w `.prod.env` | `App/Core/config.php:29-32`, Git initial commit | potwierdzony sekret także w śledzonym kodzie | HIGH | P0 jest silniejsze |
| 6 | Ryzyko logowania opisane jako plaintext compare | `LoginController.php:26-27` | dodatkowo aktywny login ignoruje input i używa `admin/admin` | HIGH | osobne P0 |
| 7 | Brak centralnego middleware jako ryzyko | `UserRoutes.php`, `UserController.php`, `App.php` | aktywny CRUD użytkowników nie ma lokalnej ochrony; to konkretny P0 | HIGH | pilna blokada dostępu |
| 8 | Jedna konfiguracja DB przez env | `Database.php:11-18` vs `config.php:6-39` | istnieją dwa modele konfiguracji; stałe wyglądają na legacy i ich aktywne użycie nie znalezione | HIGH | nie projektować TO-BE na stałych |
| 9 | „Niespójne stany magazynowe” | brak danych DB | potwierdzona jest wielość modeli, nie faktyczna rozbieżność rekordów | HIGH | traktować jako ryzyko |
| 10 | „Hasła są jawne w produkcji” | tylko raw comparison i pole `password` | kod wymaga porównywalnej wartości, ale zawartości żywej DB nie odczytano | HIGH | storage state UNVERIFIED |
| 11 | Agent „nieaktywny” | brak front controllera w workspace | potwierdzony jest brak entrypointu lokalnie; osobny deployment pozostaje możliwy | HIGH | status deployment LOW |
| 12 | 314 tras „aktywnych” | glob routes + brak runtime | 314 deklaracji jest ładowalnych statycznie; ich działanie niepotwierdzone | HIGH | używać „potencjalnie aktywne” |

