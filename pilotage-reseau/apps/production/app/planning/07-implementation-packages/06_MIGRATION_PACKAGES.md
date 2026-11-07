# Pakiety migracyjne

## Reguła

Pakiet migracyjny ma jeden source, jeden target, jeden scope pilota i jeden cutover. Każdy batch zapisuje source/table/ID, target ID, transform version, quality status, rejection i checksum. Po write cutover stosuje się forward correction, nie powrót do dwóch writerów.

| Pakiet | Source → target | Migracja/backfill | Cutover i test | Rollback/verification manual |
|---|---|---|---|---|
| FND-001 | rozproszone SQL/MD → baseline+runner | metadata/checksum bez zmiany danych, potem ordered manifest | clean/upgrade/repeat/schema diff | restore/forward fix; DBA porównuje środowiska |
| FND-003 | App/Agent user/shop/employee → canonical org/identity map | additive IDs/memberships; conflicts bez auto-merge | najpierw read mapping, bez auth cutover | wyłączyć mapping flag; owner rozstrzyga shared accounts |
| INT-001 | jeden legacy outbox → envelope/message_attempt | pending/dead zachowuje original ID/payload hash/status | adapter shadow, duplicate/timeout/restart E2E | worker flag do legacy przed final cutover; ręcznie uzgodnić backlog |
| MD-001 | product variants/IDs → canonical product/revisions | aliases, duplicates do queue, snapshot fields | read/write per product class | legacy read adapter; steward sample review |
| MD-002 | client models → canonical customer/lifecycle | identity/tax/address map z PII controls | new/changed clients first | legacy adapter; business sample documents |
| MD-003 | supplier models → canonical supplier | status/terms/history mapping | new supplier then active set | revert read flag; purchasing owner sign-off |
| MD-004 | units/conversions → temporal unit model | baseline conversions z quality flag | product class scope | block publish/revert projection; physical-unit sample |
| MD-005 | packs/defaults → canonical package relation | supplier/shop mappings | selected supplier/site | read legacy defaults; receipt sample |
| REC-001 | current recipe graph → RecipeVersion v1 | reconstructed baseline/hash/cycle rejects | no write cutover; prerequisite to REC-002 | retain current read, no fabricated history; technologist review |
| PRI-001 | current prices/VAT/changelog → temporal versions | current baseline; history only verified | future effective date/channel | publish previous value as new version; sample tax docs |
| DATA-001 | file/manual scripts → staged import batch | no direct canonical write before validation | one template/entity | compensate only records without downstream effects; row sample |
| INV-001 | `carton_stock`/Agent inventory → opening+movement ledger | signed opening per product/location/lot | scope by site/product/control time | before cutover revert; after cutover adjustment; physical count sign-off |
| INV-002 | legacy lot fragments → canonical lot/expiry | verified lots only, unknown stays unknown | risk class/site | disable class flag; quality owner sample |
| SAL-001 | WDT/POS/order facts → canonical sale chain | control periods reconstructed only if reconciled | channel/control time/new sale | before cutover endpoint flag; after cutover correction; finance totals |
| DOC-001 | two document models/files → canonical metadata/snapshot | number/tax/hash/correction links | document type/channel | renderer/channel rollback; issued metadata immutable; accounting review |
| HR-001 | App/Agent employees → canonical assignment | user link, site/role validity, conflicts | active population/site | legacy read; HR privacy/sample review |
| COST-001 | receipt/month cost inputs → CostEntry | one source type at a time; old period reconstructed | new period/source adapter | disable adapter before posting; posted adjustment after; controller totals |
| MIG-001 | zastąpiony capability → archive/remove | brak backfill; retention/export if needed | zero-use window after replacement | restore archive/code before destructive step; owners confirm no consumer |

## Pakiety z migracją jako częścią vertical feature

`PUR-001`, `SAL-002`, `DOC-002`, `ORD-001`, `PROD-001`, `HR-002` i `COST-003` mogą migrować tylko otwarte obiekty o jednoznacznym stanie; pozostałe kończą się w legacy lub są read-only reference. Nie dodawać osobnego globalnego migration package, jeśli granica nowego obiektu/czasu eliminuje ryzyko backfillu.

## Brama każdego cutoveru

Inventory konsumentów, additive target i constraints, deterministyczny batch, rejects=zaakceptowane, count/sum/hash/sample trace, shadow mismatch w tolerancji, E2E/correction PASS, feature flag owner/expiry, monitoring, backup/restore i business sign-off. Destructive cleanup zawsze osobnym `MIG-001`.

