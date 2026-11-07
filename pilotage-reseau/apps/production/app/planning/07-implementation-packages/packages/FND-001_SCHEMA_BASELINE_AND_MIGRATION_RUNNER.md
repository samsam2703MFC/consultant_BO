# FND-001 — Baseline schematu i runner migracji

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Foundation + Migration / F1 / M |
| cel biznesowy | każda zmiana danych jest odtwarzalna, wersjonowana i możliwa do zweryfikowania przed produkcją |
| problem | SQL/Markdown mają duplikaty numerów, brak baseline/runnera i potwierdzonej wersji środowiska |
| rezultat | czysta baza i upgrade od zatwierdzonego stanu kończą się identycznym schematem; status migracji jest widoczny |

## Zakres i granice

Schema metadata/profile bez wartości PII, canonical baseline dla potwierdzonego runtime, ordered migration manifest/checksum/lock, dry-run/status, transakcja tam gdzie DDL pozwala, failure/restart policy i dokumentacja. Poza zakresem: nowe modele domenowe, destructive cleanup, automatyczny rollback każdej DDL.

## Repozytoria i strategia

App/DB/Infra; jeśli Agent ma osobny schema owner — osobny prompt repozytoryjny i prompt koordynujący. Runner ma jedno źródło kolejności. Kolejność: metadata → baseline decision → runner → clean/upgrade CI → environment adoption.

## Tabele, migracje, endpointy i UI

- Tabela: `schema_migrations(version, name, checksum, applied_at, duration, status/runner_version)`; dokładny model po profilu DB.
- Migracja: bootstrap metadata nie może oznaczać nieuruchomionych zmian jako wykonane bez schema checksum evidence.
- Endpoint: brak publicznego; CLI `status/plan/apply` i opcjonalny authenticated read-only diagnostics później.
- UI: `N/A`; narzędzie techniczne. SS-002 może później tylko pokazywać status, nie wykonywać DDL.
- Permission/audit: deployment/DB role; audit command/version/result, bez SQL zawierającego dane.

## Testy i dane testowe

Empty→latest, current snapshot→latest, repeat/no-op, checksum mismatch, concurrent runner lock, partial failure/restart, schema diff zero. Test DB ze zredukowanym syntetycznym schematem/danymi; osobna kopia metadata aktualnego środowiska bez PII.

## Kryteria akceptacji

1. Jedna komenda tworzy czysty expected schema.
2. Upgrade z zatwierdzonego baseline daje schema diff zero.
3. Repeat nie zmienia schema/data; checksum drift blokuje apply.
4. Równoległe uruchomienie nie stosuje migracji dwa razy.
5. Każde środowisko ma raport version/drift i ownera.
6. Istnieje backup/restore/forward-fix runbook.

## Definition of Done

Model metadata, runner backend/CLI, brak UI uzasadniony, least-privilege, audit/log, migration tests, CI integration, docs i deployment PASS. Business correction `N/A`.

## Ryzyka, rollback i ręczna weryfikacja

- Ryzyka: błędne oznaczenie baseline, DDL lock, checksum istniejących plików, różnice MySQL.
- Rollback: backup/restore przed adoption albo forward migration; nie automatyzować niebezpiecznego down dla danych.
- Ręcznie: DBA porównuje schema objects/indexes/FK/charset i potwierdza, które legacy migracje są archive-only.

## Dokumentacja

Migration authoring guide, naming/version policy, status/incident runbook, environment baseline matrix i archive map starych SQL/MD.

