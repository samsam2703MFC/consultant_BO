# ADR-009 — Idempotencja każdej mutacji integracyjnej

**Status:** proponowany do akceptacji

## Kontekst

Sieć i workery dostarczają co najmniej raz; timeout nie rozstrzyga, czy skutek wystąpił.

## Opcje

1. brak retry;
2. retry i wykrywanie duplikatów heurystycznie;
3. wymagany idempotency key + unique constraint + zapis rezultatu;
4. exactly-once transport.

## Decyzja

Każda zewnętrzna lub ponawialna mutacja posiada stabilny idempotency key w zakresie organizacji/operacji. Pierwszy wynik jest zapisywany; identyczny retry go zwraca, inny payload z tym key daje konflikt.

## Uzasadnienie

Zapewnia effectively-once business effect na prostym transporcie at-least-once.

## Konsekwencje

- klucze i hash request wymagają retencji;
- unique constraint jest obowiązkowy;
- klient/adapter musi zachować key przez retry.

## Ponowne rozważenie

Nie rezygnować przy zmianie transportu; można zmienić zakres/retencję po pomiarze wolumenu i ryzyka replay.
