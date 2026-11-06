# FND-005 — Command context i append-only audit na procesie pilotażowym

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Foundation / F1 / M |
| cel biznesowy | wyjaśnić kto, w jakim scope, dlaczego i z jakim wynikiem wykonał krytyczną zmianę |
| problem | lokalne logi/changelogi nie tworzą wspólnej osi czasu ani atomowego dowodu |
| rezultat | jedna realna command zapisuje stan oraz audit/outbox w kontrolowanej transakcji i udostępnia timeline |

## Zakres i granice

CommandContext (actor/session/scope/correlation/causation/idempotency/reason), AuditEvent append-only, redaction, transaction integration, failure/denied attempt policy, query/timeline dla jednego obiektu oraz jeden pilot command o odwracalnym skutku. Poza zakresem: pełne event sourcing, globalny Audit Explorer, wszystkie commands, approval/correction lifecycle (FND-006).

## Repozytoria i strategia

App; jeśli pilot external effect — outbox dopiero INT-001. Jeden prompt. Najpierw schema/contract, potem middleware/context, pilot application command, minimal timeline API/UI, tests i cutover.

## Tabele i migracje

`audit_event`: audit_id, occurred_at_utc, organization/scope, actor/session/role snapshot, action/permission, aggregate type/id/version, redacted change/before-after, reason, correlation/causation, source/outcome/error class. Additive migration; brak update/delete dla app role. Existing changelog pozostaje, bez backfill udający completeness.

## Endpointy i UI

- Pilot command: wybrany po FND-004; musi być odwracalny i mały, np. activate/deactivate controlled dictionary, nie stock/cost.
- Audit API: `GET object/{type}/{id}/timeline` lub spójny equivalent.
- UI: minimalna sekcja historii przy pilocie, z actor/time/action/reason/outcome/diff; PII redaction.

## Uprawnienia i audyt

Command wymaga domain permission; timeline osobnego `audit.read` i scope. Audit system sam nie zapisuje sekretów/raw payload. Odrzucone/failed attempts są zapisywane poza transakcją domenową niezawodnie, bez fałszywego success.

## Testy i dane testowe

Unit context/redaction/diff; integration atomic state+audit+outbox/rollback; append-only DB permissions; API scope; E2E success/denied/validation/failure/double-click/correlation timeline. Fixtures: two actors/scopes, redacted fields, stale aggregate version, known reason.

## Kryteria akceptacji

1. Successful pilot ma actor/scope/permission/object/version/reason/before-after/correlation/outcome.
2. Rollback nie zostawia domenowego success auditu; failure ma poprawny outcome.
3. Denied/wrong scope jest widoczny security bez ujawnienia obiektu użytkownikowi.
4. Audit nie da się edytować/usunąć przez konto aplikacyjne.
5. Timeline jest dostępny tylko w dozwolonym scope.

## Definition of Done

Schema/backend/pilot UI/permissions/redaction/error/tests/migration/runbook/docs complete. Correction poza zakresem; pilot ma istniejącą legalną akcję odwrotną/deactivate.

## Ryzyka, rollback i ręczna weryfikacja

- Ryzyka: PII/secret leak, audit/state inconsistency, storage growth, fałszywe actor.
- Rollback: wyłączyć timeline/read feature; nie kasować audit events; pilot command może wrócić do starej ścieżki tylko jeśli zachowuje security, a brak audit blokuje critical mutation.
- Ręcznie: porównać stan/timeline/log, denied cases, redaction, DB update/delete denial.

## Dokumentacja

Audit schema/action naming/redaction/retention, command context usage, pilot trace example i support query/runbook.

