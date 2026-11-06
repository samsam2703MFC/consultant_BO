# Wyniki wykonania testów

| Próba | Status | Wynik |
|---|---|---|
| Wyszukanie test suites | PASS | brak testów produktu |
| Inwentaryzacja tras web | PASS | 314 deklaracji `addRoute` w ładowanych podkatalogach |
| Inwentaryzacja API App | PASS | 3 trasy w `ApiRoutes.php` |
| Audyt wzorców auth/CSRF/TODO | PASS | wykryto ryzyka opisane w raporcie |
| Audyt migracji i tabel z repozytoriów | PASS | częściowy model statyczny |
| `php -v` / lint | BLOCKED | binarium `php` niedostępne |
| Composer scripts | BLOCKED | `composer` niedostępny i brak sekcji scripts |
| `Bin/cost_analysis_section_smoke.php` | BLOCKED | brak PHP i bazy |
| Połączenie MySQL read-only | BLOCKED | brak klienta/runtime; status bazy niepotwierdzony |
| Integracje S3/e-mail/Peppol/webhook | NOT_TESTED | ryzyko kontaktu z systemami rzeczywistymi |

Nie zmieniano logiki aplikacji w celu uzyskania PASS.

