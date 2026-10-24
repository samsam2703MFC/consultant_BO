# Dług techniczny

- Równoległe drzewa `App` i `Agent/api_app_dir` oraz stare/nowe mechanizmy auth.
- Katalogi `ToRefactor`, backupy, pliki `.tmp`, zakomentowany stary dispatcher i debug exits.
- Dwie instalacje Composer/vendor i trzy kopie/biblioteki PDF/mail.
- Brak README, CI, testów, standardu kodu i deterministycznego setupu.
- Kontrolery czytają bezpośrednio `$_GET`/`$_POST`; walidacja i odpowiedzi są niespójne.
- Repozytoria mieszają CRUD, SQL raportowy i dynamiczne interpolacje.
- Niekompletne migracje i dokumentacja implementacyjna zastępująca automatyzację.
- Obsługa błędów często zwraca puste tablice lub ujawnia wyjątek, utrudniając odróżnienie braku danych od awarii.
- Worker logs są w repo (`Bin/outbox_worker.log`), co grozi ujawnieniem danych i puchnięciem repo.

Priorytet: najpierw bezpieczeństwo i granice wdrożeniowe, potem test harness i migracje; refaktoryzacja architektury bez tych zabezpieczeń byłaby ryzykowna.

