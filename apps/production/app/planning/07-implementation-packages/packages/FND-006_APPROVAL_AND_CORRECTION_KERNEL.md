# FND-006 — Approval i correction kernel na jednej komendzie

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Foundation / F1 / M |
| cel biznesowy | skorygować jeden zatwierdzony błąd bez nadpisania oryginału i bez samodzielnego zatwierdzenia |
| problem | status/correction/reopen są rozproszone lub wymagają SQL; brak wspólnego lifecycle sprawy |
| rezultat | request→preview→approve→execute→verify→close dla jednej odwracalnej domenowej kompensacji |

## Zakres i granice

RepairCase, immutable preview hash, reason, approval decision, execution attempt, verification/outcome, SoD/no self-approval, expiry/stale aggregate protection i link do original/correction event. Pilot musi użyć małej komendy istniejącej po FND-005, np. przywrócenie/dezaktywacja konfiguracji, nie stock/period. Poza zakresem: generic workflow engine, dowolny status, Repair Workbench wielu domen, stock/production/period corrections.

## Repozytoria i strategia

App, jeden prompt. Kontrakt lifecycle/policy → schema → application services → minimal case UI → pilot domain compensation → tests. Domeny później implementują własne named action handlers, nie dziedziczą SQL executor.

## Tabele i migracje

`repair_case`, `repair_preview`, `approval_decision`, `repair_execution_attempt`, `correction_link`; exact names po FND-001. Unique execution/idempotency, aggregate version, requester≠approver constraint/policy, no mutable approved preview. Additive migration; brak backfillu starych korekt jako complete.

## Endpointy i UI

- API: create case, generate/refresh preview, approve/reject, execute, verify/close; legal transitions only.
- UI: case detail/timeline, before→after preview, reason, approver, stale/expired status i result.
- Brak pola arbitrary handler/status/table; action type jest allowlisted w kodzie/registry kontraktów.

## Uprawnienia i audyt

`repair.case.create/read`, pilot `repair.<domain>.<action>`, `approval.decide`; scope inherited from aggregate. Audit każdego transition/denied/stale/attempt/outcome z preview hash i correlation.

## Testy i dane testowe

Unit state machine/SoD/expiry/stale/hash; integration transaction/idempotency/concurrent approval/execute; E2E request/approve/execute/verify, reject, self-approval deny, changed preview, double execute, compensation. Fixtures: requester, separate approver, wrong scope, original and reversible pilot object.

## Kryteria akceptacji

1. Oryginał pozostaje niezmienny; correction ma własny ID i link.
2. Requester nie zatwierdza własnej operacji, jeśli policy tego wymaga.
3. Zmiana obiektu po preview blokuje execute i wymaga refresh/reapproval.
4. Double execute daje jeden skutek.
5. Verification dowodzi expected state; failure nie jest `CLOSED`.
6. Nie istnieje generic SQL/status action.

## Definition of Done

Model/backend/minimal UI/permissions/validation/errors/correction/audit/tests/migration/docs/deployment complete. Pilot outcome zatwierdza process owner.

## Ryzyka, rollback i ręczna weryfikacja

- Ryzyka: uniwersalny workflow scope creep, self-approval, stale preview, double compensation.
- Rollback: wyłączyć create/execute action flag; zachować cases/audit; domenowy skutek cofać wyłącznie kolejną legalną compensation.
- Ręcznie: SoD role matrix, case timeline, original/correction diff, concurrent clicks i support runbook.

## Dokumentacja

State machine, action contract, approval policy, compensation rules, user guide pilota i wzorzec dla kolejnych domen.

