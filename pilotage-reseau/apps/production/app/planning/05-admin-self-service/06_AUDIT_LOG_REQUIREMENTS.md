# Wymagania logu audytowego

## Zakres

Audit jest append-only i niezależny od logu technicznego. Rejestruje zarówno sukces, odrzucenie, błąd, approval, preview i eksport. Backup/binlog, access log oraz tabela changelog pojedynczego modułu nie zastępują audytu aplikacyjnego.

## Minimalny wpis

| Pole | Wymaganie |
|---|---|
| identyfikacja | `audit_id`, `occurred_at_utc`, `correlation_id`, `causation_id` |
| aktor | user/employee/service principal, snapshot roli, session/device, source app |
| zakres | organization/site/shop/warehouse i tenant boundary |
| decyzja | action, wymagane permission, policy version, outcome |
| obiekt | aggregate type/id/version, business key bez zbędnego PII |
| zmiana | zredagowane before/after albo field-level diff |
| uzasadnienie | reason code/text, ticket/case, requester, approver(s) |
| niezawodność | idempotency key hash, attempt, error class, result reference |
| dowód | preview hash, import/export manifest, report/document hash |

## Matryca zdarzeń

| Obszar | Obowiązkowe zdarzenia |
|---|---|
| IAM | invite, login success/failure, block, reset request/complete, grant/revoke, scope change, session revoke |
| master data | create, edit draft, activate/deactivate, merge, relation correction |
| konfiguracja | draft, validate, approve/reject, publish, rollback, connection test |
| repair | case open/assign, preview, approval, execute, verify, fail, close |
| integracje | received, attempt, delivered, dead, manual retry/cancel/map correction |
| dokumenty | generate/regenerate, issue, send/resend, void/correct |
| finanse/magazyn/produkcja | post, reverse/correct, close/reopen, adjustment |
| dane | import validate/apply/rollback, export request/download/expire, rebuild/reconcile |

## Redakcja i dostęp

Nigdy nie zapisuje się haseł, tokenów, kluczy, pełnych danych kartowych ani sekretów integracji. Payload integracji jest redagowany według schematu; pola PII są maskowane lub szyfrowane z oddzielnym permission. `audit.read` nie daje automatycznie prawa do payloadu, a `audit.export` jest oddzielne, wymaga powodu i tworzy ślad pobrania.

## Niezmienność, retencja, czas

- brak update/delete przez konto aplikacyjne; sprostowanie jest nowym wpisem;
- time source UTC, zsynchronizowany; business time przechowywany osobno;
- hash/łańcuch lub WORM dla operacji prawnie krytycznych po decyzji infrastrukturalnej;
- retencję ustala właściciel danych z legal/security; legal hold blokuje purge;
- partycjonowanie/archiwizacja nie może przerwać wyszukiwania po correlation i aggregate.

## Wyszukiwanie i eksport dowodowy

Filtry: czas, aktor, rola, permission, organizacja, typ/ID obiektu, akcja, outcome, risk, reason, correlation, approver. Widok osi czasu łączy audit, business events, dokumenty i próby integracji bez mieszania ich semantyki. Eksport zawiera kryteria, strefę czasową, watermark, liczbę rekordów, checksum i czas wygaśnięcia pliku.

## SLO i monitoring

Operacja `HIGH/CRITICAL` nie może zostać uznana za sukces, jeśli nie zapisano audytu w tej samej transakcji lub niezawodnym outboxie. Alerty: luka w sekwencji/ingest, spadek wolumenu do zera, failed audit delivery, nietypowe granty, wiele reopen/retry, masowe eksporty i działania poza normalnym scope.

## Testy akceptacyjne

Każdy typ akcji potwierdza actor/scope/permission, before/after, reason/approval, correlation i outcome; testy obejmują failure i denied. Test redakcji próbuje wstrzyknąć sekret/PII do reason, payload i wyjątku. Test retencji oraz restore dowodzi dostępności dowodu po archiwizacji.

