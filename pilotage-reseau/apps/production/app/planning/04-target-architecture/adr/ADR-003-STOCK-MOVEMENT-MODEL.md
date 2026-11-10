# ADR-003 — Niezmienny model ruchów magazynowych

**Status:** proponowany do akceptacji

## Kontekst

Bieżący stan bez kompletnej historii nie pozwala wyjaśnić zmiany ani odtworzyć as-of.

## Opcje

1. aktualizować tylko saldo;
2. saldo + edytowalny log;
3. append-only signed movements + odbudowywalna projekcja salda;
4. pełny event sourcing wszystkich aggregate.

## Decyzja

Każda zmiana zapasu tworzy immutable `stock_movement` z source line, jednostką bazową, lokalizacją/partią, aktorem, czasem i wartością. Korekta jest reversal/adjustment. Saldo jest projekcją.

## Uzasadnienie

To minimalny ledger zapewniający spójność i historię bez pełnego event sourcingu.

## Konsekwencje

- posted movement nie ma update/delete;
- source+effect/idempotency jest unique;
- raporty i saldo muszą mieć reconciliation/rebuild.

## Ponowne rozważenie

Gdy wolumen wymaga partycjonowania/archiwizacji lub odrębnego silnika ledgerowego potwierdzonego testami skali.
