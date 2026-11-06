# INT-001 — Wspólny envelope i migracja jednego outboxu

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Foundation + Migration / F1 / L |
| cel biznesowy | zagwarantować, że retry jednego aktywnego procesu nie gubi ani nie powiela skutku |
| problem | wiele outboxów/workers/statusów; brak wspólnego message contract, attempts i centralnej obserwowalności |
| rezultat | jedna potwierdzona aktywna kolejka działa na versioned envelope, stable idempotency, attempts/dead i restart-safe worker |

## Scope i wybór pilota

DSC-001 wybiera kolejkę o realnym wolumenie, bezpiecznym sandboxie i mierzalnym skutku; preferowana wysyłka dokumentu/proformy lub collector w test double. Zakres: envelope v1, outbox/inbox/idempotency, claim/lease, attempts/backoff/dead, payload redaction, adapter legacy i metrics. Poza: wszystkie outboxy, broker, batch retry UI, zmiana domenowego payloadu bez wersji.

## Repozytoria i kolejność

App producer/API → Worker consumer → Agent/client jeśli jest stroną kontraktu → E2E. **Prompty per repo z promptem koordynującym.** Najpierw additive server accepts old/new; potem adapter/worker; następnie producer; na końcu legacy status retire osobnym MIG-001.

## Tabele i migracje

`integration_message`/`outbox_message`: message_id, type/version, aggregate/business key, correlation/causation, idempotency key hash, immutable redacted payload/reference, status, available_at, timestamps. `message_attempt`: attempt, worker, started/finished, outcome/error class/external ID. Opcjonalny `inbox_receipt` unique consumer+message/idempotency.

Backfill wybranej kolejki zachowuje original ID, status, attempts jeśli wiarygodne i payload hash; pending/dead nie są duplikowane. Shadow adapter i backlog reconciliation przed cutover.

## Endpointy i UI

- API internal: enqueue/claim/ack/fail; public webhook/inbox contract versioned.
- Health/metrics read endpoints dla SS-001.
- UI: `N/A` w tym pakiecie; SS-001 dostarcza inbox. Techniczny CLI może istnieć tylko do worker start/status, nie arbitralnego payload edit.

## Uprawnienia i audyt

Service principal per producer/consumer i scope; manual mutation niedostępna. Audit message state/attempt z correlation; payload/secret/PII redaction. Business effect audit pozostaje w domenie.

## Testy i dane testowe

Unit state/backoff/classification/envelope version; integration atomic domain+outbox, claim lease, crash/restart, unique inbox; contract old/new producer/consumer, timeout/4xx/5xx; E2E duplicate same ID/key→one effect, dead and automatic retry. Fixtures: synthetic payload v1, transient/permanent sandbox responses, duplicate, poison message, stale lease.

## Kryteria akceptacji

1. Domain commit i enqueue są atomowe albo używają potwierdzonego inbox boundary.
2. Ten sam intended effect przy duplicate/retry występuje raz.
3. Worker restart nie gubi processing item; stale lease jest odzyskiwany.
4. Attempts/backoff/dead i last error class są widoczne bez raw secret.
5. Backlog/count/business outcomes starej i nowej ścieżki są uzgodnione.
6. Mixed-version deployment oraz rollback flag są przetestowane.

## Definition of Done

Contract/schema/backend/worker/client compatibility/permission/audit/tests/migration/reconciliation/monitoring/runbook complete; UI jawnie SS-001. Pakiet L kończy się jednym adapterem, nie platformą bez konsumenta.

## Ryzyka, rollback i ręczna weryfikacja

- Ryzyka: duplicate external effect, lost claim, poison loop, payload incompatibility, backlog during cutover.
- Rollback: przed producer cutover wrócić worker flag do legacy; po nowych messages nie zmieniać IDs — adapter legacy musi je obsłużyć albo forward-fix. Zatrzymać consumer circuit breakerem.
- Ręcznie: worker kill/restart, backlog counts, external sandbox receipts, logs redaction, old/new version compatibility.

## Dokumentacja

Envelope/schema/versioning, producer/consumer ownership, retry matrix, status transitions, metrics/alerts, migration/cutover/recovery runbooks.

