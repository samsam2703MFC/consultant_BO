# ADR-008 — Wspólny append-only audit log

**Status:** proponowany do akceptacji

## Kontekst

AS-IS ma lokalne changelogi i logi, ale nie jeden dowód aktora, przyczyny i korelacji.

## Opcje

1. logi aplikacyjne tekstowe;
2. changelog per moduł;
3. wspólny strukturalny audit event + domenowe business events;
4. pełny event sourcing.

## Decyzja

Każda operacja krytyczna zapisuje wspólny append-only audit event w transakcji domenowej. Business events pozostają odrębne i zawierają semantykę procesu.

## Uzasadnienie

Zapewnia spójny dowód i wyszukiwanie bez narzucania event sourcingu.

## Konsekwencje

- wymagane redaction/retention/access permissions;
- audit zwiększa storage;
- aplikacja nie może ignorować błędu zapisu audytu operacji krytycznej.

## Ponowne rozważenie

Gdy regulacje wymagają zewnętrznego WORM/SIEM; wtedy audit może być dodatkowo eksportowany, lecz lokalny zapis transakcyjny pozostaje.
