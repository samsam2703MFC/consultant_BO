# Inwentaryzacja testów

Nie znaleziono plików `*Test.php`, `phpunit.xml`, Pest/Behat ani skryptów testowych w Composer. W `Bin/` istnieje `cost_analysis_section_smoke.php`, lecz to ręczny smoke wymagający runtime i bazy, nie izolowany test produktu. Usunięte w worktree skrypty `recipe_cost_*` i `test_allergen_alias_filtering.sh` nie są dostępne do wykonania i nie zostały przywrócone.

| Typ | Liczba zestawów | Status |
|---|---:|---|
| Jednostkowe | 0 | MISSING |
| Integracyjne | 0 | MISSING |
| Kontraktowe | 0 | MISSING |
| E2E | 0 | MISSING |
| DB/security/performance | 0 | MISSING |
| Ręczny smoke CLI | 1 | BLOCKED |

Najwyższy priorytet: auth/tenant isolation, statusy zamówień, sumy dokumentów, ceny historyczne, recipe flattening, stock reconciliation oraz idempotencja inbox/outbox.

