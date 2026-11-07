# Rejestr ręcznych interwencji

## Skala dowodu

- `POTWIERDZONE` — znaleziono konkretny skrypt, trasę, usługę lub instrukcję.
- `POŚREDNIE` — konstrukcja systemu wymusza obsługę przez log/konfigurację/kod, ale nie znaleziono runbooka.
- `DO WALIDACJI` — potrzeba wynika z TO-BE/luki; częstotliwość i aktualne obejście muszą potwierdzić użytkownicy.

| ID | Interwencja / wyzwalacz | Obecna ścieżka | Typ ingerencji | Dowód | Pewność | Ryzyko | Cel self-service |
|---|---|---|---|---|---|---|---|
| MI-001 | rotacja sekretu lub zmiana endpointu integracji | edycja środowiska/konfiguracji i restart | serwer/config/programista | audit 15; konfiguracja integracji w collectorach | POTWIERDZONE | CRITICAL | kontrolowana rotacja przez vault i test połączenia |
| MI-002 | zastosowanie zmiany schematu | ręczne uruchomienie SQL/wybór pliku | SQL/serwer | mieszane `migrations/*.sql` i `.md`, brak runnera | POTWIERDZONE | CRITICAL | pipeline migracji z dry-run; pozostaje DevOps, nie panel biznesowy |
| MI-003 | utknięty ogólny outbox | analiza logu i worker/SQL | CLI/log/SQL | `Bin/worker_outbox.php`, `outbox_worker.log` | POTWIERDZONE | HIGH | monitor, diagnoza i idempotentny retry/dead resolution |
| MI-004 | niedostarczona wysyłka | uruchomienie retry/analiza kolejki | CLI | `Bin/shipments_retry.php` | POTWIERDZONE | HIGH | retry pojedynczy/batch z preview i wynikiem |
| MI-005 | brak nowych zamówień z sieci | ręczny collector | akcja aplikacyjna/CLI | `runManualCollector`, `Bin/orders_collector.php` | POTWIERDZONE | HIGH | integracyjny run z correlation ID, dry-run i statystyką |
| MI-006 | brak aktualizacji reklamacji | ręczny pull/collector | akcja aplikacyjna | `ComplaintCollectorService::runManualCollector` | POTWIERDZONE | HIGH | retry według sklepu/UUID z ochroną duplikatu |
| MI-007 | failed material-order outbox | endpoint retry Agent | API | `MaterialOrderWebhookController::retry` | POTWIERDZONE | HIGH | wspólny monitor; ograniczenie scope i stały idempotency key |
| MI-008 | failed complaint outbox | ponowny insert do outbox | API/lokalna akcja | `ComplaintController::retryOutbox` | POTWIERDZONE | HIGH | retry istniejącego komunikatu, bez tworzenia drugiego skutku |
| MI-009 | błąd generacji/wysyłki proformy | komentarz przewiduje retry/DLQ, rozproszone kolejki | log/kod/CLI | `ProformaService`; `enqueueProformaForRetry` | POTWIERDZONE | HIGH | regenerate/resend jako dwie jawne akcje z historią |
| MI-010 | nieaktualny koszt po cenie materiału | ręczne przeliczenie | akcja HTTP | trasa `recalculate-related-recipe-costs` | POTWIERDZONE | MEDIUM | kontrolowane przeliczenie z preview zakresu i lineage |
| MI-011 | nieaktualne kalkulacje Agent | endpointy recalculate all/per shop | API | `RecipePrecalculationController` | POTWIERDZONE | HIGH | job asynchroniczny z zakresem, postępem i raportem różnic |
| MI-012 | ręczna wartość kartonów w kosztach | wpis manualny chroniony przed sync | UI | `set-manual-cartons`, źródło `MANUAL` | POTWIERDZONE | HIGH | override z powodem, źródłem, approval i historią |
| MI-013 | zamknięcie/odblokowanie miesiąca kosztowego | akcje lock/unlock | UI/API | `CostRoutes.php`, `CostMonthService` | POTWIERDZONE | CRITICAL | checklist close; reopen tylko maker-checker i adjustment |
| MI-014 | ustawienie marż kategorii | SQL według nazwy/ID | SQL | migracje `003/005/006` | POTWIERDZONE | HIGH | wersjonowana polityka marż z effective date |
| MI-015 | dodanie typu kosztu logistycznego | seed/migracja SQL | SQL/kod | `020_cost_logistics.sql` | POTWIERDZONE | MEDIUM | słownik z kodem immutable, aktywacją i kontrolą użycia |
| MI-016 | dodanie kompetencji receptury/przypisań | SQL lub punktowa synchronizacja | SQL/UI | migracje `023/024`, trasa sync | POTWIERDZONE | MEDIUM | rejestr kompetencji i kontrolowane przypisania |
| MI-017 | eksport produktu/materiału/cennika | osobne ekrany i formaty | UI | `ProductExportController`, `MaterialExportController`, PriceList | POTWIERDZONE | MEDIUM | wspólny eksport z zakresem, maskowaniem i manifestem |
| MI-018 | import danych podstawowych/cennika | mechanizmy punktowe lub nowa implementacja | plik/kod | GAP-006; dokument formatu eksport/import | POŚREDNIE | HIGH | staged import: validate → approve → apply → report/rollback |
| MI-019 | korekta statusu zamówienia/reklamacji/dokumentu | brak wspólnej komendy; prawdopodobny SQL/lokalny CRUD | SQL/kod | audit 15, GAP-017/020/024 | POŚREDNIE | CRITICAL | legalna komenda przejścia/korekty z reason i skutkami |
| MI-020 | naprawa brakującego powiązania | zapytanie lub poprawka kodu | SQL/programista | GAP-006/049, brak wspólnej kolejki jakości | POŚREDNIE | HIGH | repair case z wyborem canonical target i preview |
| MI-021 | rozwiązanie duplikatu produktu/klienta | ręczne rozpoznanie i SQL | SQL/programista | brak canonical IDs/merge; GAP-007/010/049 | DO WALIDACJI | HIGH | merge z analizą zależności, aliasem i możliwością kompensacji |
| MI-022 | korekta stanu magazynowego | punktowy update/stocktake; równoległe modele | SQL/lokalne UI | Agent inventory updates; GAP-032–036 | POŚREDNIE | CRITICAL | movement adjustment/stocktake, nigdy zapis salda wprost |
| MI-023 | uzgodnienie salda magazynu | ręczne porównanie źródeł | SQL/arkusz | audit 15; równoległe inventory source of truth | POŚREDNIE | CRITICAL | reconciliation run i zatwierdzony adjustment |
| MI-024 | ponowne wygenerowanie dokumentu | rozproszone akcje/CLI | aplikacja/CLI | audit 15; GAP-021/024 | POŚREDNIE | HIGH | render z immutable snapshot; wynik i hash |
| MI-025 | naprawa numeracji dokumentu | zmiana konfiguracji/SQL | SQL/config | GAP-005/021 | DO WALIDACJI | CRITICAL | rezerwacja/void numeru z kontrolą ciągłości, bez renumeracji issued |
| MI-026 | reset hasła lub odblokowanie konta | CRUD użytkownika/DB | UI/SQL | `/users`, brak bezpiecznego reset flow | POŚREDNIE | CRITICAL | token reset, MFA/revoke sessions, bez poznawania hasła |
| MI-027 | zmiana uprawnień | edycja pola `permission` lub Agent RBAC | UI/SQL | `UserRepository`, równoległe modele | POTWIERDZONE | CRITICAL | role + scope + approval + access review |
| MI-028 | przypisanie użytkownika/pracownika do sklepu | rozproszone dane | SQL/UI | GAP-004/041/042 | DO WALIDACJI | HIGH | datowane membership/assignment z zakresem |
| MI-029 | dodanie słownika/statusu/aliasu | migracja lub kod, punktowe CRUD-y Agent | SQL/kod/API | liczne alias controllers; GAP-005 | POTWIERDZONE | HIGH | wspólny rejestr słowników; systemowych statusów nie edytuje się dowolnie |
| MI-030 | zmiana podatku/ceny z datą obowiązywania | bieżący rekord/punktowe UI | UI/SQL | GAP-008/015; eksporty cennika | POŚREDNIE | CRITICAL | wersja z effective date, overlap check i approval |
| MI-031 | korekta receptury użytej historycznie | edycja bieżącej receptury | UI/SQL | brak RecipeVersion, GAP-011/012 | POŚREDNIE | CRITICAL | nowa wersja; nigdy nadpisanie wersji użytej |
| MI-032 | odblokowanie procesu produkcji | brak centralnego workflow | SQL/programista | GAP-027–031 | DO WALIDACJI | CRITICAL | pause/reopen/correct przez komendę domenową |
| MI-033 | korekta zamkniętego kosztu/okresu | unlock i ponowna edycja | UI | istniejący unlock miesiąca; GAP-046/047 | POTWIERDZONE | CRITICAL | reopen case lub adjustment period z drugim zatwierdzeniem |
| MI-034 | diagnoza błędu integracji | przegląd wielu logów/outboxów | serwer/log/SQL | wiele workerów/outboxów, brak centralnej konsoli | POTWIERDZONE | HIGH | wyszukiwanie correlation/business key, timeline prób |
| MI-035 | backfill/rebuild danych raportowych | skrypt lub nowe repo/query | kod/SQL | brak wspólnej projekcji i lineage; GAP-048/055 | POŚREDNIE | HIGH | wersjonowany rebuild job z watermark i reconciliation |
| MI-036 | raport ad hoc/uzgodnienie | SQL/arkusz/nowy widok | SQL/kod | audit 15, dashboardy bez warstwy jakości | POŚREDNIE | MEDIUM | zatwierdzony eksport semantyczny i zapis definicji |
| MI-037 | usunięcie historycznych/legacy danych | ręczna decyzja/SQL | SQL | kandydaci deprecacji, nieznana retencja | DO WALIDACJI | CRITICAL | wyłącznie lifecycle archive/purge po legal hold i approval |
| MI-038 | sprawdzenie driftu schematu/konfiguracji | ręczne porównanie środowisk | CLI/serwer | brak baseline/runnera | POŚREDNIE | HIGH | read-only diagnostics i raport driftu; naprawa w pipeline |

## Wnioski z rejestru

- 14 z 38 pozycji ma ryzyko `CRITICAL`; żadna nie powinna dostać prostego przycisku bez polityki kontroli.
- Operacje MI-002, MI-037 i część MI-038 pozostają techniczne. Self-service ma pokazywać stan i inicjować kontrolowany workflow, nie zastępować DBA/DevOps.
- Przed estymacją należy przeprowadzić warsztat operacyjny i zebrać częstotliwość z ticketów/logów. Wartości częstotliwości w katalogu są klasami projektowymi, nie pomiarem produkcyjnym.

