# ADR-010 — Membership–role–permission–scope

**Status:** proponowany; szczegóły zależą od BD-09/10

## Kontekst

Wiele modeli auth/role i niejasny tenant context tworzą krytyczne ryzyko.

## Opcje

1. proste pole permission na user;
2. role globalne;
3. membership w organization + role/permissions + resource scopes;
4. zewnętrzny policy engine.

## Decyzja

Użyć wbudowanego modelu membership–role–permission–scope z default deny, backend enforcement i ApprovalPolicy dla działań wrażliwych.

## Uzasadnienie

Pokrywa sklepy/magazyny/dostawców bez kosztu osobnego policy engine.

## Konsekwencje

- potrzebna migracja ról i negatywne testy;
- role pozostają nieliczne, permissions atomowe;
- revoke unieważnia sesje/cache.

## Ponowne rozważenie

Gdy liczba reguł dynamicznych/klientów wymaga niezależnego policy engine lub federation z korporacyjnym IdP.
