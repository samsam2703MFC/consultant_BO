# Wydajność i skalowalność

Status planów wykonania: BLOCKED bez bazy.

## Ryzyka statyczne

- Raporty grupują po `DATE()`/`DATE_FORMAT()` na timestampach, m.in. `SalesAnalyticsRepository.php:55`, `StatisticsRepository.php:121`, `TransactionRepository.php:135`; może to wyłączać indeksy zakresowe.
- `SectionFinanceDashboardRepository` wielokrotnie konwertuje rok/miesiąc przez `STR_TO_DATE`, co jest kosztowne i utrudnia indeksowanie.
- Listy CRUD często korzystają z `findAll()` bez paginacji (`BaseRepository.php:26-31`).
- Raporty wykonują szerokie agregacje WDT, produktów i kosztów bez materializacji.
- `ProductionListRepository` buduje dynamiczne listy ID i kilka podobnych podzapytań per dzień.
- Workery mają limity/retry, ale brak dowodu blokady konkurencyjnej we wszystkich kolejkach.
- Twig cache jest wyłączony i debug włączony (`BaseController.php:73-78`).

## Zalecenia

Najpierw zebrać slow query log i `EXPLAIN ANALYZE`; następnie indeksować tenant+date+status oraz FK zgodnie z rzeczywistymi planami. Dodać paginację, daty sargable (`>= start AND < next_day`), cache/materializowane agregaty i limity eksportu. Nie dodawano indeksów automatycznie.

