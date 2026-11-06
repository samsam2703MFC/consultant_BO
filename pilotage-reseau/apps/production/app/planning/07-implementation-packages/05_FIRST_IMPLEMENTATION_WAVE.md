# Pierwsza fala implementacyjna

## Outcome

Pierwsza fala realizuje MS-01–MS-03: system ma wiarygodną bazę zmiany, scoped/audytowaną operację i jedną integrację odzyskiwaną przez operatora bez programisty. Nie obejmuje przebudowy domen biznesowych.

## Kolejność i release units

| Krok | Pakiet | Release unit | Warunek rozpoczęcia | Wynik |
|---:|---|---|---|---|
| 1A | STB-001 | RU-01 Security hotfix | owner credential | stare sekrety revoked, config references bez wartości w repo |
| 1B | DSC-001 | RU-02 Evidence pack | dostęp read-only | potwierdzony runtime/source/consumer inventory |
| 2 | STB-002 | RU-03 Auth stabilization | RU-01 + aktywny App path | bezpieczny login/logout/session, plan migracji haseł |
| 3 | FND-001 | RU-04 Reproducible schema | RU-02 | clean/upgrade/diff i status migracji |
| 4 | FND-002 | RU-05 Quality baseline | RU-04 | jedna deterministyczna komenda testowa w CI |
| 5 | FND-003 | RU-06 Org/identity mapping | RU-04/05 + BD-10 | canonical IDs i conflict report, bez enforcement |
| 6 | FND-004 | RU-07 Scoped authorization | RU-03/06 + BD-09/10 | shadow diff i cutover jednej trasy |
| 7 | FND-005 | RU-08 Audited command | RU-05/07 | jedna komenda zapisuje actor/scope/reason/outcome atomicznie |
| 8 | FND-006 | RU-09 Controlled correction | RU-08 + BD-07/09 | request/approve/execute/verify jednej kompensacji |
| 9 | INT-001 | RU-10 Reliable envelope | RU-02/04/05/08 | jedna aktywna kolejka na envelope/idempotency/attempts |
| 10 | SS-001 | RU-11 Operator recovery | RU-07/08/10 | failed→manual retry→one outcome z UI/auditem |

`STB-001` i `DSC-001` mogą biec równolegle, jeśli pracują różne osoby; dla jednej osoby najpierw aktywna rotacja, potem discovery. FND-006 i INT-001 nie powinny mutować tego samego procesu pilota.

## WIP i rozmiar

Pakiety L (`FND-003`, `FND-004`, `INT-001`) dzielą się na małe kroki deploymentowe opisane w swoich plikach, ale mają jeden outcome i cutover. Nie rozpoczynać kolejnego mutującego RU przed obserwacją i PASS poprzedniego. Read-only design/fixtures mogą być przygotowane równolegle.

## Dane kontrolne

- jedno konto aktywne, zablokowane i legacy shared/conflict;
- co najmniej dwie organizations/scopes do testu negatywnego;
- jedna aktywna trasa krytyczna o odwracalnym skutku pilotażowym;
- jedna aktywna kolejka z success, transient failure, permanent/dead i duplicate;
- testowe credentials wyłącznie w izolowanym środowisku;
- control DB snapshot/metadata bez kopiowania PII do fixtures.

## Wspólna brama akceptacji fali

- wszystkie pakiety spełniają własne acceptance i ERP DoD;
- clean install/upgrade/schema diff oraz CI PASS;
- valid/invalid login, cross-scope deny, revoke, audit failure/denied PASS;
- retry duplicate test daje dokładnie jeden business outcome;
- sekrety/payload/PII nie występują w logach/audicie/fixtures;
- migration/cutover/rollback runbooks przetestowane;
- dokumentacja operatora i mapa ownerów aktualne;
- brak otwartego critical defect.

## Wykluczenia

Master data redesign, inventory ledger, sale/document rewrite, dashboardy, batch retry, uniwersalny Repair Workbench, usunięcie Agent, broker/mikrousługi i finalne prompty dla kolejnych faz.

## Pliki pakietów

Szczegółowe specyfikacje znajdują się w `packages/STB-001_*.md` do `packages/SS-001_*.md`. Są strukturą wejściową dla następnego etapu generowania promptów; nie zawierają finalnych instrukcji wykonawczych.

