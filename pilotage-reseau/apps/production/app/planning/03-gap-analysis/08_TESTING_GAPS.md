# Luki testowania

## Stan bazowy

W katalogu AS-IS jest 50 funkcji, z których żadna nie ma statusu testu produktu `PASS`. Nie znaleziono testów produktu, nie ma zdefiniowanego runnera Composer/CI, a w środowisku audytu brakuje PHP i klienta MySQL. Nie uruchomiono żadnego procesu E2E ani zapytania jakościowego na rekordach.

## Rejestr luk testowych

| ID | Zakres | Stan obecny | Minimalny wymagany dowód | Kryterium zamknięcia | Powiązane luki |
|---|---|---|---|---|---|
| TG-01 | środowisko testowe | brak instrukcji, kont, seedów i izolowanej DB | deterministyczne uruchomienie bez usług produkcyjnych | jedna komenda tworzy środowisko i fixture set bez danych osobowych | GAP-049/054 |
| TG-02 | schema/migrations | brak baseline i runnera | build czystej bazy oraz upgrade z poprzedniej wersji | schema diff zero, migracje jednokrotne i rollback/recovery udokumentowane | GAP-049 |
| TG-03 | unit domeny | brak | test reguł ceny, receptury, statusów, korekty, jednostek | wszystkie inwarianty TO-BE mają test pozytywny i negatywny | GAP-011–017/020/027/032/045 |
| TG-04 | repozytoria/DB | brak | test transakcji, constraintów, FK, unique i concurrency | błędny zapis odrzucony bez częściowego skutku | GAP-012/017/028/032/037/049 |
| TG-05 | auth | brak | poprawne/błędne hasło, lockout, session rotation, revoke | nieautoryzowany użytkownik nie uzyskuje sesji ani danych | GAP-001/002 |
| TG-06 | RBAC/tenant | brak | macierz ról i negatywne testy cross-tenant | każda operacja krytyczna blokuje brak permission i obcy scope | GAP-003/004/042 |
| TG-07 | zamówienie B2B | brak E2E | draft→confirm→partial→fulfilled oraz cancel/backorder/revision | status, rezerwacje, dokumenty i audit są zgodne po każdym kroku | GAP-017/023/033/041 |
| TG-08 | sprzedaż POS/płatność | Agent nieuruchomiony | sale, failed payment, retry, mixed/refund jeśli w zakresie | dokładnie jeden fakt/płatność/skutek stock na idempotency key | GAP-016/018–020 |
| TG-09 | dokument/korekta | brak E2E | issue/send/correct i błąd integracji | numeracja unikalna, original immutable, korekta i raport netto zgodne | GAP-021/022/024/050 |
| TG-10 | receptura | brak | create version, cycle, activate, supersede, withdraw | aktywna wersja immutable, cykl/overlap zablokowany | GAP-011/012 |
| TG-11 | kalkulacja kosztu | brak | kompletne/brakujące ceny, półprodukt, zmiana wersji | brak danych daje INCOMPLETE; ten sam snapshot daje ten sam wynik | GAP-013/014/045/048 |
| TG-12 | stock movement | brak | receipt/issue/reversal oraz duplicate/concurrent request | saldo = ledger; brak lost update i podwójnego skutku | GAP-032 |
| TG-13 | reservation/transfer | brak | reserve/partial consume/release/expiry; issue/transit/receive/discrepancy | on-hand/available/transit poprawne w każdym stanie | GAP-033/034 |
| TG-14 | stocktake | Agent nieuruchomiony | freeze/count/recount/approve/post/correct | dokładnie jeden adjustment, actor/approver i raport różnicy | GAP-035 |
| TG-15 | lot/QC/expiry | brak | quarantine/release/block/reject, FEFO i recall trace | partia niedozwolona nie jest wydawana; trace w obie strony pełny | GAP-029/036 |
| TG-16 | produkcja | brak procesu | release→consume→output→QC→close oraz pause/waste/correct | ilości/ruchy/koszty/partie i statusy uzgodnione | GAP-027–031/044/045 |
| TG-17 | zakup | fragmenty | requisition→approval→PO→partial receipt→discrepancy/claim→close | PO, receipt, stock, cena i supplier facts są spójne | GAP-037–040 |
| TG-18 | czas pracy | brak actual | submit/approve/reject/lock/correction/absence/overlap | tylko approved zasila koszt; korekta nie nadpisuje historii | GAP-042–044 |
| TG-19 | okres kosztowy | brak | close z blockerem, poprawny close, korekta bieżąca, reopen | opublikowany hash stabilny, niedozwolony backdate zablokowany | GAP-045–047 |
| TG-20 | outbox/inbox | częściowe workery | transient/permanent failure, duplicate, worker crash, manual retry | jeden skutek, backoff/dead state, recovery i audit | GAP-050/051/053 |
| TG-21 | kontrakty zewnętrzne | brak sandboxu | S3/e-mail/Peppol/MQ/webhook z mock/sandbox | brak realnego side effectu; wersje komunikatów kompatybilne | GAP-052 |
| TG-22 | jakość danych | zapytania przygotowane, nieuruchomione | null/duplicate/orphan/status/value/date/author | raport populacji i naruszeń dla wszystkich krytycznych tabel | GAP-049/055 |
| TG-23 | reconciliation sprzedaży | brak | order–shipment–sale–invoice–payment–correction | różnica ilości/kwot wyjaśniona i w tolerancji | GAP-016/019/020/023 |
| TG-24 | reconciliation magazynu | brak wspólnego ledgeru | opening + movements = closing | zero niewyjaśnionych różnic per produkt/lokalizacja/partia | GAP-032/035/036 |
| TG-25 | reconciliation kosztu | brak | source entries→allocation→snapshot→report | sumy i rounding uzgodnione, brak ceny nie staje się zerem | GAP-013/045–048 |
| TG-26 | wydajność zapytań | brak planów wykonania | EXPLAIN/benchmark krytycznych list i raportów na reprezentatywnym wolumenie | zatwierdzone budżety czasu i brak pełnych skanów bez uzasadnienia | GAP-056 |
| TG-27 | concurrency | brak | równoległa rezerwacja, stock issue, numeracja, payment, retry | brak oversell/lost update/duplicate number/effect | GAP-017/019/032/033/049/050/056 |
| TG-28 | backup/restore/recovery | brak dowodu | restore DB/files, restart worker i recovery partial failure | RPO/RTO spełnione, integralność po odtworzeniu uzgodniona | GAP-050/052/056 |
| TG-29 | bezpieczeństwo aplikacji | tylko statyczne ustalenia | secrets, authz, session, CSRF, input, upload, dependency scan | krytyczne scenariusze PASS, brak sekretów i znanych krytycznych podatności | GAP-001–003/052 |
| TG-30 | regresja raportów | brak golden/reconciliation | kontrolny zamknięty okres i expected totals | zmiana kodu nie zmienia raportu bez jawnej zmiany wersji definicji | GAP-046/048/055 |

## Kolejność zależności testowej

Test E2E nie zastępuje testu inwariantu, a dashboard nie zastępuje reconciliation. Najpierw potrzebny jest uruchamialny schemat i fixture, następnie reguły i repozytoria, potem scenariusze E2E i dane, a na końcu obciążenie/recovery. Jest to zależność dowodowa, nie plan wdrożenia.

## Minimalny raport wyniku

Każdy test danych lub E2E powinien zapisywać wersję kodu/schematu, środowisko, seed/dataset, business case, oczekiwany i rzeczywisty wynik, liczbę rekordów, correlation IDs oraz artefakty uzgodnienia. `PASS` bez tych informacji nie jest wystarczającym dowodem audytowym.
