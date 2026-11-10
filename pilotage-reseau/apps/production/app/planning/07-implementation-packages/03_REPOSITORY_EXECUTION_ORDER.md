# Kolejność realizacji między repozytoriami

## Potwierdzone i potencjalne repozytoria

W audytowanym workspace znajdują się App, Agent i workery `Bin`. `DSC-001` ma potwierdzić, czy Agent jest osobnym deploymentem/repozytorium w praktyce. Do czasu tego dowodu nie zakładać, że zmiana pliku Agent dociera do aktywnego klienta.

## Zasada sześciu kroków

Dla pakietu wielorepozytoryjnego kolejność jest stała:

1. **Kontrakt:** schema/OpenAPI/message version, invariants, idempotency, error model, compatibility.
2. **API:** server-side command, permission/scope, transaction/outbox, audit.
3. **Klient API:** backward-compatible client, retry/offline rules, feature flag.
4. **UI:** states/loading/error/correction, bez logiki autoryzacji jako jedynej ochrony.
5. **Integracja:** sandbox/config/worker/monitoring, staged enablement.
6. **E2E:** mixed-version compatibility, retry/duplicate/correction/reconciliation i cutover.

Kontrakt i consumer compatibility są wdrażane additive przed przełączeniem. Breaking removal następuje w osobnym `MIG-001`.

## Strategia promptów w następnym kroku

| Klasa pakietów | Repo | Zalecany podział promptów | Uzasadnienie |
|---|---|---|---|
| STB-001 | App/Agent/Worker/Infra | zestaw per repo + prompt koordynujący | rotacja/config wymaga kolejności i walidacji bez ujawniania sekretu |
| STB-002 | App | jeden prompt | jedna ścieżka auth/session, atomiczna migracja credentials |
| DSC-001 | wszystkie read-only | jeden prompt koordynujący discovery | brak implementacji per repo; jeden rejestr dowodów |
| FND-001/002 | App/DB/CI | zestaw DB/runner i CI + koordynator | baseline i test runtime mają osobne rollback/gates |
| FND-003/004 | App+Agent | kontrakt → API → klient → UI, prompty per repo + koordynator | mixed-version auth i scope nie mogą powstać ad hoc |
| FND-005/006 | głównie App | jeden prompt per pakiet | wspólna transakcja i jeden pilot command |
| INT-001 | App/Worker/Agent zależnie od kolejki | per producer/consumer + koordynator | envelope/backward compatibility i worker cutover |
| SS-001 | App + worker/API | dwa prompty + koordynator | read model/UI i mutujący retry endpoint mają różne bramy |
| MD/CFG/REC/PRI/DATA | App + mapping Agent | jeden App prompt; osobny migration prompt jeśli Agent aktywny | nie mieszać canonical feature z backfill/cutover |
| INV/PUR/SAL/DOC/ORD/LOG/PROD/HR | App+Agent/client+workers | zestaw per repo z promptem koordynującym | vertical E2E przecina kontrakt/API/client/UI |
| COST/RPT/PLAN | głównie App | jeden prompt per mały pakiet; osobny backfill prompt | jeden owner danych/read model; backfill ma odrębny risk |
| MIG-001/OPS-001 | właściwe repo+infra | zestaw z koordynatorem | nieodwracalność/restore/operacje środowiskowe |

## Macierz kolejności pierwszej fali

| Pakiet | Kontrakt | API/backend | Klient/UI | Integracja | E2E/cutover |
|---|---|---|---|---|---|
| STB-001 | config keys/secret refs | validators/bootstrap | N/A | rotate per adapter | health + old credential rejected |
| STB-002 | login/session error model | hash verify/migration/session | login/logout form/errors | N/A | valid/invalid/lockout/session tests |
| DSC-001 | evidence schema | N/A | N/A | inventory traffic/cron | signed completeness review |
| FND-001 | migration manifest/version | runner/status/checksum | CLI/status read-only | CI DB provisioning | clean/upgrade/diff |
| FND-002 | test command/fixture contracts | test DB/bootstrap | N/A | CI pipeline | deterministic repeat |
| FND-003 | organization/identity IDs | models/repositories/mapping | conflict report UI/read-only | legacy adapter | count/conflict/reconciliation |
| FND-004 | permission/scope vocabulary | policy/enforcement/shadow log | access denied/admin diagnostic | Agent token/context | deny/cross-scope/revoke |
| FND-005 | command/audit schema | middleware/service + pilot command | object timeline minimal | audit outbox if needed | before/after/failure/denied |
| FND-006 | repair/approval states | request/approve/execute/verify | minimal pilot case UI | domain compensation | no self-approval, original immutable |
| INT-001 | message envelope v1 | producer/outbox/inbox/idempotency | N/A | worker adapter/status migration | duplicate/timeout/dead/restart |
| SS-001 | operations query/action contract | list/detail/retry endpoint | inbox/detail/confirm/result | worker execution | one-effect manual retry |

## Mixed-version deployment

- najpierw server accepts old+new contract;
- potem klient publikuje/odczytuje new fields z feature flag;
- następnie telemetryka potwierdza brak old contract;
- dopiero potem migration/retire usuwa compatibility.

Nie łączyć breaking API, client release, data backfill i old endpoint removal w jednym wdrożeniu.

