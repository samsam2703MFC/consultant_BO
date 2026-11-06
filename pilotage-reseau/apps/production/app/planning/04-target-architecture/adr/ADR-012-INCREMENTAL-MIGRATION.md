# ADR-012 — Etapowa migracja strangler z reconciliation

**Status:** proponowany do akceptacji

## Kontekst

Część systemu działa, dane/runtime są nie w pełni znane, a big-bang jest zbyt ryzykowny.

## Opcje

1. pełne przepisanie i jednorazowe przełączenie;
2. utrzymanie legacy bez zmian;
3. migracja capability-by-capability z adapterami, shadow i feature flags;
4. długotrwały niekontrolowany dual write.

## Decyzja

Migrować pojedyncze zdolności metodą strangler. Preferować cutover nowych obiektów/datę kontrolną. Dual write tylko przez jeden writer/outbox i zawsze z reconciliation.

## Uzasadnienie

Minimalizuje przestój i pozwala zachować rollback/read legacy bez utrwalania dwóch źródeł prawdy.

## Konsekwencje

- czasowo istnieją adaptery/feature flags i koszt porównania;
- potrzebna mapa legacy ID oraz quality flags;
- cleanup jest osobną zmianą po obserwacji.

## Ponowne rozważenie

Gdy konkretny mały moduł nie ma danych/konsumentów i bezpieczniej jest go zastąpić jednorazowo; decyzja musi mieć dowód.
