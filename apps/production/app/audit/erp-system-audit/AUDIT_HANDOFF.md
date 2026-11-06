# Handoff audytu

Audyt statyczny i raport końcowy są kompletne. Do wykonania po udostępnieniu środowiska:

1. obrócić i zweryfikować sekret wskazany w `.prod.env`;
2. uruchomić lint i aplikację na właściwej wersji PHP;
3. wykonać read-only SQL z `queries/read_only_database_audit.sql`;
4. uzupełnić kontrole jakości danych i `EXPLAIN ANALYZE`;
5. przeprowadzić E2E na odizolowanej bazie z rollbackiem;
6. ustalić status wdrożeniowy `Agent/api_app_dir`.

Aktywne blokady są w `24_FAILED_OR_BLOCKED_CHECKS.md`. Najważniejsze ryzyka: sekrety, plaintext auth, brak centralnej autoryzacji/CSRF, brak tenant isolation testów, brak pełnego wykonania produkcji i księgi magazynowej.

