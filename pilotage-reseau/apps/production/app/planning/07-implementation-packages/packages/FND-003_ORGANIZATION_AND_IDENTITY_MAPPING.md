# FND-003 — Canonical organization i identity mapping

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Foundation + Migration / F1 / L |
| cel biznesowy | każda osoba i operacja ma jednoznaczny organization/site/shop scope bez utraty historii |
| problem | App/Agent mają rozproszone user/employee/shop modele i możliwe wspólne konta |
| rezultat | canonical organization/identity/membership IDs oraz raport konfliktów; bez wymuszenia RBAC przed FND-004 |

## Zakres i granice

Organization hierarchy minimalna zgodna z BD-10; User/Identity oddzielony od Employee; Membership/assignment validity; legacy ID aliases; additive mapping; conflict/orphan/shared-account report; read adapter. Poza zakresem: password/auth (STB-002), roles/grants/enforcement (FND-004), pełny HR lifecycle (HR-001), UI admin (SS-005).

## Repozytoria i strategia

App canonical API/data; Agent jako legacy source/client po DSC-001. **Zestaw promptów per repo + prompt koordynujący.** Kolejność: ID/contract → App schema/API → backfill/report → Agent/client mapping → shadow reads → reconciliation. Brak UI mutującego; tylko read-only conflict report.

## Tabele i migracje

Docelowe nazwy do potwierdzenia przez FND-001: `organization`, `organization_unit`, `identity_user`, `employee`, `membership`, `employee_assignment`, `legacy_identity_map`. Klucze: organization scope, validity, unique `(source_system, source_type, source_id)`, brak auto-merge konfliktów.

Migracja: users/shops/employees additive; wspólne/duplikaty `CONFLICT` lub `LEGACY_SHARED`; nie tworzyć fałszywej osoby. Batch z lineage/count/orphan/duplicate i rollback przez wyłączenie read flag, bez kasowania canonical rows.

## Endpointy i UI

- API: read-only `organizations/tree`, `identity-mappings/conflicts`, `users/{id}/memberships` pod przyszłym permission; write API dopiero SS-005.
- UI: diagnostyczny raport mapowania z filtrami source/status/scope i eksportem bez PII ponad permission.
- Mixed version: legacy IDs nadal akceptowane przez adapter; canonical IDs zwracane additive.

## Uprawnienia i audyt

`organization.structure.read`, `iam.identity_mapping.read`; migracja przez technical data role. Audit batch/import/conflict resolution decyzji (jeśli ręcznie zatwierdzona), source/target/checksum/outcome; redakcja PII.

## Testy i dane testowe

Unit hierarchy/validity/alias rules; integration unique/FK/cross-org; migration repeat/restart/conflicts; API permission/redaction; E2E legacy ID→canonical context read. Fixtures: 2 organizations, site/shop/warehouse, one normal user/employee, shared legacy account, duplicate email, orphan employee, terminated assignment.

## Kryteria akceptacji

1. Każdy migrowany aktywny rekord ma canonical ID albo jawny conflict/rejection.
2. Nie ma automatycznego połączenia dwóch osób na podstawie samego loginu/email.
3. Hierarchy i membership validity blokują cross-org relation w DB/service.
4. Source/target counts i sample trace są zatwierdzone przez ownerów.
5. Legacy adapter odczytuje mapping bez drugiego writer ownership.

## Definition of Done

Model, mapping backend, read-only API/UI, permissions, validation, audit, migration/reconciliation, tests, docs i staged deployment complete. Mutujący UI/correction poza zakresem; conflicts pozostają jawne.

## Ryzyka, rollback i ręczna weryfikacja

- Ryzyka: zła hierarchia BD-10, cross-tenant map, PII leak, shared account przypisany osobie.
- Rollback: wyłączyć canonical read/mapping flag; zachować batches i conflicts; poprawić forward mapping.
- Ręcznie: HR/security/operations przeglądają próbki każdej kategorii konfliktu i organization tree.

## Dokumentacja

Identity/organization glossary, source mapping matrix, conflict runbook, privacy/redaction, API contract i input do FND-004.

