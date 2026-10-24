# ADR-004 — Niezmienne wersje receptur

**Status:** proponowany do akceptacji

## Kontekst

Edytowalna bieżąca receptura zmienia historyczny koszt i nie dowodzi składu zlecenia.

## Opcje

1. changelog pól bieżącej receptury;
2. kopiowanie składu tylko do zlecenia;
3. jawny aggregate RecipeVersion z lifecycle i snapshotem na zleceniu.

## Decyzja

Receptura ma wersje DRAFT/IN_REVIEW/ACTIVE/SUPERSEDED/WITHDRAWN. Aktywna wersja jest immutable, datowana i wskazywana przez produkcję.

## Uzasadnienie

Zapewnia reprodukcję składu/kosztu, review, bezpieczne półprodukty i brak retroaktywnych zmian.

## Konsekwencje

- zmiana składu tworzy nową wersję;
- graf jest walidowany atomowo i bez cykli;
- legacy receptury otrzymują baseline version z quality flag.

## Ponowne rozważenie

Jeśli domena udowodni potrzebę wielu równolegle aktywnych wariantów, rozszerzyć validity/variant policy bez rezygnacji z immutability.
