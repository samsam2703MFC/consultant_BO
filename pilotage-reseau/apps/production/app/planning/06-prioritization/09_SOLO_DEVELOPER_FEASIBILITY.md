# Wykonalność jednoosobowa

## Założenia

`Tak` oznacza możliwe jako pionowy pakiet przy dostępnych ownerach/UAT. `Warunkowo` wymaga podziału na slice’y i wsparcia biznesowego/operacyjnego. `Nie jako jeden pakiet` oznacza program migracyjny, który jedna osoba może prowadzić etapami, ale nie bezpiecznie wdrożyć jako pojedyncze zadanie. Repo: `1` — głównie App, `2+` — App/Agent/worker/deployment do potwierdzenia.

| ID | Solo | Context | Repo | Testy dominujące | Etapowanie | Utrzymanie |
|---|---|---|---:|---|---|---|
| ERP-001 | Tak | niski | 1/infra | security negative/auth migration | secret→login→session | niskie po konsolidacji |
| ERP-002 | Tak | średni | 2+ | dowód runtime, nie test produktu | app→worker→DB→integracje | niskie |
| ERP-003 | Warunkowo | wysoki | 2+ | clean/upgrade/diff/DQ | metadata→baseline→runner→constraints | średnie |
| ERP-004 | Tak | średni | 1/CI | unit/integration fixtures | smoke→DB→CI | średnie |
| ERP-005 | Nie jako jeden pakiet | bardzo wysoki | 2+ | deny/cross-scope/revoke/session | org→identity→shadow auth→cutover→admin | wysokie |
| ERP-006 | Warunkowo | wysoki | 1 | audit/correction/idempotency | context→audit→approval→correction | średnie |
| ERP-007 | Warunkowo | wysoki | 2+ | duplicate/timeout/dead/recovery | envelope→adapter po adapterze | wysokie |
| ERP-008 | Tak | średni | 1 | read/permission/retry/batch | read-only→single retry→batch/jobs | średnie |
| ERP-009 | Nie jako jeden pakiet | bardzo wysoki | 2+ | uniqueness/history/migration | produkt→klient→dostawca | średnie |
| ERP-010 | Warunkowo | wysoki | 2+ | conversion/property/history | unit→pack→legacy mapping | średnie |
| ERP-011 | Warunkowo | wysoki | 1 | historical stability | contract→sale→stock→prod | niskie/średnie |
| ERP-012 | Tak | średni | 1 | effective config/approval/rollback | dictionary→policy→vault ref | średnie |
| ERP-013 | Warunkowo | wysoki | 1/2 | cycle/version/activation/migration | baseline v1→edit→activate | średnie |
| ERP-014 | Warunkowo | wysoki | 1/2 | lineage/incomplete/rebuild | one recipe family→all | średnie |
| ERP-015 | Nie jako jeden pakiet | bardzo wysoki | 2+ | overlap/tax/snapshot/publish | price→VAT→margin→channels | wysokie |
| ERP-016 | Nie jako jeden pakiet | bardzo wysoki | 2+ | E2E/retry/correction/reconciliation | B2B source→POS→payment/refund | wysokie |
| ERP-017 | Warunkowo | wysoki | 1 | state/partial/cancel/concurrency | state→partial→reservation | średnie |
| ERP-018 | Nie jako jeden pakiet | bardzo wysoki | 2+ | numbering/tax/correction/send | canonical metadata→renderer→send→correction | wysokie |
| ERP-019 | Nie jako jeden pakiet | bardzo wysoki | 2+ | invariant/concurrency/opening/reconcile | one location/product→shadow→cutover | wysokie |
| ERP-020 | Warunkowo | wysoki | 2+ | genealogy/expiry/FEFO | risk classes→full scope | średnie |
| ERP-021 | Nie jako jeden pakiet | bardzo wysoki | 2+ | reservation/transfer/stocktake/adjust | każda zdolność osobny slice | wysokie |
| ERP-022 | Nie jako jeden pakiet | bardzo wysoki | 2+ | partial receipt/discrepancy/price/E2E | receipt→PO→approval→claim | wysokie |
| ERP-023 | Nie jako jeden pakiet | bardzo wysoki | 2+ | state/movement/retry/offline/concurrency | one product/site→expand | wysokie |
| ERP-024 | Nie jako jeden pakiet | bardzo wysoki | 2+ | QC/waste/correction/period | waste→QC→rework/correction | wysokie |
| ERP-025 | Warunkowo | wysoki | 1/2 | state/partial/POD/return | dispatch→POD→return/cost | średnie/wysokie |
| ERP-026 | Nie jako jeden pakiet | bardzo wysoki | 2+ | privacy/time overlap/approval | identity map→actual→absence | wysokie |
| ERP-027 | Warunkowo | wysoki | 1/2 | assignment/time/order/correction | one operation/site→expand | średnie |
| ERP-028 | Nie jako jeden pakiet | bardzo wysoki | 1/2 | source→entry→adjust→reconcile | purchase→inventory→labor/overhead | wysokie |
| ERP-029 | Warunkowo | wysoki | 1 | blockers/close/reopen/hash | checklist→close→reopen | średnie |
| ERP-030 | Nie jako jeden pakiet | bardzo wysoki | 1/2 | allocation/reproducibility/totals | product→lot→shop/client | wysokie |
| ERP-031 | Nie jako jeden pakiet | wysoki | 1 | definition/freshness/drill/reconcile | read model per vertical slice | wysokie |
| ERP-032 | Nie jako jeden pakiet | wysoki | 1 | validate/batch/merge/export/rollback | export→import one entity→DQ→merge | średnie |
| ERP-033 | Warunkowo | wysoki | 1 | permission/approval/compensation | case shell→action per domain | średnie |
| ERP-034 | Warunkowo | bardzo wysoki | 1 | version/shortage/release/plan-vs-actual | manual plan→scenario | wysokie |
| ERP-035 | Tak | średni | 1 | KPI source/period/drill | read-only score→workflow | niskie |
| ERP-036 | Warunkowo | wysoki | 1/2 | rule/redemption/refund/cost | manual rule→voucher/channel | średnie |
| ERP-037 | Warunkowo | wysoki | 1 | KPI/permission/performance/reconcile | report per domain/role | wysokie |
| ERP-038 | Warunkowo | bardzo wysoki | 1 | margin/reconciliation/period stability | product→shop→client | wysokie |
| ERP-039 | Warunkowo | średni | 1/infra | load/concurrency/restore/recovery | restore→critical load profiles | średnie |
| ERP-040 | Nie jako jeden pakiet | bardzo wysoki | 2+ | traffic/no-read/archive/restore | candidate per capability | maleje po każdym retire |
| ERP-041 | Nie solo operacyjnie | bardzo wysoki | 1/data | backtest/baseline/drift | offline experiment→shadow→assist | wysokie |
| ERP-042 | Nie jako osobny finał | wysoki | 1/2 | pełne E2E i reconciliation | scenariusz wraz z każdym slice | średnie |

## Reguły podziału pakietu

1. Jeden owner biznesowy, jedna główna encja/source of truth i jeden zakres pilota.
2. Jedna migracja additive/backfill/cutover na pakiet; destructive cleanup później.
3. Jedna ścieżka happy, co najmniej jeden wyjątek i korekta/retry E2E.
4. Minimalny read model i reconciliation, bez budowania całego dashboardu.
5. Feature flag z ownerem i expiry; obserwacja przed kolejnym cutoverem.

## Potrzebne wsparcie mimo jednego developera

Jednoosobowa implementacja nie oznacza jednoosobowej odpowiedzialności. Wymagani są: właściciel procesu do decyzji/UAT, DBA/infra do bezpiecznego dostępu i restore, security do sekretów/IAM, księgowość do wyceny/okresu/dokumentów oraz operatorzy do danych kontrolnych. Brak tych osób jest blockerem organizacyjnym, nie zadaniem programistycznym.

