# SS-001 — Operations Inbox i retry jednej kolejki

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Self-service / F1–F4 / M |
| cel biznesowy | operator rozwiązuje jedną klasę błędów integracji bez logowania na serwer, SQL i pomocy programisty |
| problem | obecne retry/collectory/workery są rozproszone; brak context, ownera, permission, reason i zweryfikowanego outcome |
| rezultat | role-based inbox/list/detail umożliwia diagnozę i pojedynczy retry/dead resolution z jednym skutkiem E2E |

## Zakres i granice

Jedna kolejka z INT-001; read model/list filters, detail timeline, redacted payload summary, error classification, owner/SLA, action eligibility, preview, confirmation/reason, retry request, async progress/result i export evidence. Poza: batch retry, payload edit, config/secret fix, wszystkie kolejki, generic Repair Workbench.

## Repozytoria i kolejność

App API/UI oraz Worker action handling; **dwa prompty repozytoryjne + prompt koordynujący**. Kontrakt query/action → read API → UI read-only → retry command → worker → E2E/cutover. Read-only część może zostać wdrożona wcześniej; przycisk retry feature-flagged do PASS.

## Tabele i migracje

Czyta `integration_message/message_attempt`; opcjonalne `operations_case`/owner annotation tylko jeśli nie mieści się w audit. Nie kopiuje payload. Additive indexes na status/type/business key/correlation/available time na podstawie EXPLAIN. Brak backfill poza INT-001.

## Endpointy i UI

- `GET operations/messages` z pagination/filter/scope.
- `GET operations/messages/{id}` z attempts/correlation/redacted context.
- `POST operations/messages/{id}/retry` z expected version/reason/idempotency.
- Opcjonalne named `resolve/cancel` tylko jeśli semantyka pilota jest zatwierdzona; nie dowolny status.
- UI: lista, saved filter opcjonalnie, detail/timeline, disabled action z blockerem, confirmation/preview, progress/outcome/correlation.

## Uprawnienia i audyt

`integration.message.read`, `integration.message.payload.read` osobno, `integration.message.retry`; organization/integration scope. Audit view/export/retry denied/requested/executed/outcome z reason, actor, original ID i attempt. Brak secret/raw stack trace dla business operatora.

## Testy i dane testowe

Unit eligibility/error presentation; integration filters/indexes/permissions/idempotency; contract retry request→worker; E2E transient fail→dead/eligible→manual retry→success→one effect, permanent/noneligible, wrong scope, double click, stale version, worker timeout, redaction. Fixtures z INT-001 plus actors read-only/retry/wrong scope.

## Kryteria akceptacji

1. Operator wyszukuje po correlation/business key/status i rozumie next action.
2. Retry jest dostępny wyłącznie dla eligible state i wymaga reason.
3. Double click/concurrent retry tworzy jeden aktywny attempt/skutek.
4. Wynik jest `success/failed` dopiero po worker outcome, nie po przyjęciu żądania.
5. Wrong scope/payload permission nie ujawnia danych.
6. Wybrana klasa ticketów może zostać obsłużona bez serwera/SQL.

## Definition of Done

Model/index/backend/API/UI/permissions/validation/errors/retry/audit/tests/test data/migration notes/docs/deployment/monitoring complete. Compensation external effect opisana; unsend/undo nie jest obiecywane.

## Ryzyka, rollback i ręczna weryfikacja

- Ryzyka: retry niewłaściwego permanent error, duplicate effect, payload leak, overload.
- Rollback: wyłączyć mutating action flag pozostawiając read-only inbox; zatrzymać unclaimed attempts; zewnętrzny skutek kompensować domenowo.
- Ręcznie: operator wykonuje pełny scenariusz w sandboxie, support potwierdza komunikaty, security sprawdza payload redaction, operations sprawdza metrics/backlog.

## Dokumentacja

Operator guide, eligibility/error matrix, permission/runbook, retry/circuit-breaker/escalation, known limitations i baseline redukcji ticketów.

