# Pakiety self-service

## Wspólny kontrakt

Każda akcja: permission+scope, risk, preconditions, immutable preview/hash, reason, approval policy, idempotency, executor, verifier, audit before/after/outcome i compensation. UI używa wspólnego Admin Workbench; nie tworzy osobnego panelu per wyjątek.

| Pakiet | Eliminowana ingerencja | UI/API | Permission/audit | Zależności | Akceptacja/rollback |
|---|---|---|---|---|---|
| SS-001 | log/CLI/SQL retry jednej kolejki | operations list/detail + `retry` named action | `integration.message.read/retry`; reason, attempt, outcome | INT-001, FND-004/005 | transient/dead retry one-effect; disable action flag i wrócić do automatycznego worker flow |
| DATA-001 | skrypt/SQL import produktu | batch upload/validate/approve/apply/result | `data.import.execute`; manifest/checksum/rows | MD-001, FND-004/005 | invalid rows nie zapisują; compensation/retry failed; stop batch |
| CFG-002/SS-002 | kod/SQL/`.env` dla polityki biznesowej | config registry + effective preview/publish/rollback | `config.*.manage/publish`, maker-checker HIGH | FND-003–005, CFG-001 | previous version published as new; secret values N/A/vault only |
| INV-005 | SQL/current_quantity adjustment | stocktake workflow and result | `inventory.stocktake.*`, `inventory.adjust`; before/after/reason | INV-001/002, FND-006 | difference posts movement; reversing movement, no balance update |
| SS-003 | SQL duplicate/link/missing data fix | quality queue, candidate diff, merge/link case | `data.quality.resolve`; dependencies and outcome | DATA-001, MD packages | no orphan/duplicate; forward correction or unmerge before downstream |
| SS-004 | ad hoc status/link/process fix | shared repair case shell; named actions only | `repair.<domain>.<action>`, approval per risk | FND-006, SS-001, domain commands | case verify PASS; action feature flag off, compensation |
| SS-005 | DB/user CRUD for access/reset | account/role/scope/session/access-review | `iam.*`; no self-elevation, step-up | FND-003/004/005, BD-09 | invite/block/grant/revoke negative tests; revoke grant/session |
| SS-006 | CLI/manual regenerate/resend | document detail actions, attempts/timeline | `document.regenerate/resend`; recipient/artifact preview | DOC-001, SS-001 | regenerated artifact hash; resend delivery attempt; cannot unsend |
| PROD-004 | SQL reopen/status repair | production correction case and deltas | `production.correct`, two-person thresholds | PROD-002/003, COST-003, FND-006 | movements/cost/period reconcile; compensating correction |
| COST-003 | direct unlock period | close checklist/reopen request/reclose | `period.close/reopen`, maker-checker+step-up | COST-001/002, FND-006 | report hash stable; adjustment/reclose; no silent unlock |

## Wymagane testy

Denied/wrong scope, stale version, duplicate click, approval separation, preview changed/expired, partial failure, timeout, audit redaction, compensation and concurrent attempt. Batch ma immutable manifest, limits/throttle, stop unclaimed i wynik per rekord.

## Brama publikacji

Akcja self-service nie może wyprzedzić domenowego source of truth i named command. Można wdrożyć read-only inbox/history/diagnostics wcześniej, ale mutation pozostaje wyłączona do E2E/rollback PASS.

