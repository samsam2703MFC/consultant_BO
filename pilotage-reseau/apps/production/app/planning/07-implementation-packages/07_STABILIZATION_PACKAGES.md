# Pakiety stabilizacyjne

| Pakiet | Problem i cel | Zakres | Test/akceptacja | Rollback i manual verification |
|---|---|---|---|---|
| STB-001 | tracked secret/config exposure; zamknąć aktywne ryzyko | rotate/revoke, secret references, config validation, scan, runbook | old credential rejected; active integrations health PASS; brak wartości w repo/logu | krótko przełączyć na poprzednią wersję tylko jeśli nie skompromitowana; ręcznie potwierdzić provider logs |
| STB-002 | default/raw-password/session weakness; wiarygodne logowanie | login input, password hash migration, session regenerate/cookie, logout/errors/rate policy | valid/invalid/legacy-migrate/logout/replay/session tests | feature flag/maintenance access; nie przywracać raw passwords; manual locked/active account test |
| OPS-001 | nieznane concurrency/load/recovery | measured SLO, load profiles, worker recovery, backup/restore | no lost update, queue recovery, RTO/RPO PASS | stop load, restore verified backup, reduce batch; manual operations sign-off |

## Stabilizacja, nie quick fix

Nie klasyfikować jako stabilizacji: ręcznego update statusu, delete outbox row, zerowania salda, wyłączenia constraint lub ukrycia dashboardu bez ownera. To maskuje skutek i powinno stać się correction/recovery package albo zostać zablokowane.

## Kolejność

STB-001 przed zmianą konfiguracji adapterów; STB-002 po potwierdzeniu aktywnej ścieżki z DSC-001. OPS-001 rozwija restore smoke od F1, ale pełny load/recovery dopiero po stabilnych procesach i realnych SLO.

