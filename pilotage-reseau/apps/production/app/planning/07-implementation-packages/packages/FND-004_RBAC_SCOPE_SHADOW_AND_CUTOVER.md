# FND-004 — RBAC i organization scope: shadow oraz route cutover

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Foundation / F1 / L |
| cel biznesowy | użytkownik wykonuje tylko dozwolone operacje w swoim zakresie, a revoke działa natychmiast |
| problem | sesje/JWT/pole permission i lokalne checki współistnieją; część CRUD bez widocznej ochrony |
| rezultat | permission catalog, central policy service, shadow diff i enforcement jednej pionowej trasy z testami negatywnymi |

## Zakres i granice

Atomic `resource.action`, Role/Grant/Scope/validity, auth context, server-side middleware/policy, default deny dla migrowanego scope, shadow evaluation/log, route inventory→classification→pilot cutover, revoke/cache invalidation, access-denied UX. Poza zakresem: wszystkie routes w jednym pakiecie, admin grant UI (SS-005), SSO/MFA.

## Repozytoria i strategia

App policy/API first; Agent/API client carries organization/context only if active. **Prompty per repo + koordynujący.** Kontrakt permission/scope → App policy/shadow → client context compatibility → access-denied UI → pilot enforcement → E2E. Kolejne route cutovers są małymi suffix packages `FND-004-<capability>`.

## Tabele i migracje

`role`, `permission`, `role_permission`, `grant` (subject/role/permission/scope/validity/source), opcjonalny `authorization_shadow_decision`. Seed permission catalog jest wersjonowany; mapowanie legacy permission generuje conflict report, nie grant globalny domyślnie. Rollback: policy flag do shadow/legacy dla niezmigrowanej trasy, nowe granty zachować.

## Endpointy i UI

- API: auth context/current permissions; pilot domain route bez zmiany biznesowego kontraktu; read-only shadow mismatch diagnostics.
- UI: access denied bez wycieku istnienia obiektu; bieżący scope widoczny; brak grant editor.
- Klient: nie decyduje o authorization; przekazuje/wybiera tylko dozwolony scope.

## Uprawnienia i audyt

Bootstrap admin jest imienny, czasowy i break-glass. Audit success/denied z permission, effective scope, policy version, session; zmiana grantów dopiero SS-005. Shadow log redagowany i retencjonowany.

## Testy i dane testowe

Unit policy combinations/validity/default deny; integration grants/FK/cache/revoke; E2E allowed/denied/wrong scope/object enumeration/stale session; migration legacy conflict; client mixed version. Fixtures: two orgs, operator/read-only/admin, expired grant, conflicting legacy permission, object in other scope.

## Kryteria akceptacji

1. 100% aktywnych routes ma klasyfikację permission albo jawny blocker.
2. Shadow mismatch dla pilota jest wyjaśniony i zaakceptowany przed enforcement.
3. Pilot route jest default-deny i chroniony server-side.
4. Cross-org oraz insufficient permission zwracają bezpieczny wynik bez data leak.
5. Revoke blokuje następne wywołanie w wymaganym czasie/SLO.
6. UI hiding nie jest jedyną kontrolą.

## Definition of Done

Model/policy/API/client/UI pilot/migration/audit/security tests/docs/cutover complete. Correction `N/A`; rollback do shadow, nie do niekontrolowanego global access.

## Ryzyka, rollback i ręczna weryfikacja

- Ryzyka: globalny lockout, privilege escalation, cross-tenant leak, shadow noise, cache stale.
- Rollback: enforcement flag per route wraca do shadow po incydencie; krytycznie niebezpieczna route może zostać wyłączona. Zachować audit/grants.
- Ręcznie: macierz role×route×scope, revoke active session, URL/object enumeration, Agent mixed-version.

## Dokumentacja

Permission catalog, scope tree, policy rules, pilot route matrix, shadow diff decisions, revoke/security runbook.

