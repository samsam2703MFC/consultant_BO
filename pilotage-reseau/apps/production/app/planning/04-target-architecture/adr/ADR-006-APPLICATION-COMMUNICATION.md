# ADR-006 — HTTP + DB outbox/inbox bez obowiązkowego brokera

**Status:** proponowany do akceptacji

## Kontekst

Istnieją HTTP, polling, webhooki, wiele outboxów i ślady RabbitMQ. Jedna osoba nie powinna utrzymywać brokera bez korzyści.

## Opcje

1. wyłącznie synchroniczne HTTP;
2. HTTP dla komend + DB outbox/inbox workers;
3. broker dla wszystkich zdarzeń;
4. współdzielona baza między aplikacjami.

## Decyzja

Użyć HTTP dla interakcji wymagających odpowiedzi, DB outbox/inbox dla skutków asynchronicznych i webhooków, polling tylko do reconciliation/legacy. Zakazać bezpośrednich zapisów aplikacji do DB ERP.

## Uzasadnienie

Zapewnia odporność i niski koszt operacyjny, wykorzystując istniejącą bazę.

## Konsekwencje

- worker, lease, backoff/dead state i ops UI są obowiązkowe;
- envelope/idempotency wspólne dla adapterów;
- latency zależy od częstotliwości pollingu.

## Ponowne rozważenie

Gdy zmierzony throughput/fan-out/SLA przewyższa DB outbox albo niezależne systemy wymagają trwałej subskrypcji.
