# Architektura integracji

## Ocena mechanizmów AS-IS

| Mechanizm | Ocena | Docelowe użycie |
|---|---|---|
| synchroniczne API | właściwe dla interakcji użytkownika i krótkich komend | zachować; mutacje z idempotency key i bez automatycznego blind retry |
| polling | użyteczny do reconciliation lub legacy bez webhooków | ograniczyć; watermark, overlap window i deduplikacja |
| webhooki | dobre dla zdarzeń providerów/partnerów | przyjmować szybko do inbox i przetwarzać po walidacji |
| kolejki/broker | kod/ślady istnieją, ale brak potwierdzonej potrzeby | nie wymagać początkowo; DB outbox worker wystarczy |
| pliki | właściwe dla dokumentów i kontrolowanych importów | zachować z checksum, schema version i raportem błędów |
| bezpośredni dostęp do bazy | szybki, lecz łamie własność i audyt | zakazać klientom; przejściowo tylko read-only legacy adapter |
| lokalna synchronizacja | potrzebna wyłącznie przy potwierdzonym offline POS/urządzeniu | jawna kolejka lokalna, server IDs, idempotencja i ograniczony zakres |

## Najprostszy wariant docelowy

1. Użytkownik/klient wywołuje synchroniczne HTTP API.
2. ERP zapisuje fakt, audit i outbox w jednej transakcji.
3. Worker czyta outbox z MySQL, wywołuje adapter i zapisuje próbę/status.
4. Wejściowy webhook zapisuje inbox po auth/schema/dedupe, zwraca 2xx, a worker uruchamia komendę domenową.
5. Broker pojawia się dopiero, gdy pomiar wykaże, że DB outbox nie spełnia SLA/throughput lub konieczny jest niezależny fan-out.

## Standard kontraktu

Każda komenda/wiadomość zawiera: `message_id`, `idempotency_key`, `correlation_id`, `causation_id`, `schema_version`, `event_type`, `occurred_at`, `organization_id`, źródło, payload i opcjonalny aggregate ID/version. Logi nie przechowują sekretów ani pełnych danych wrażliwych.

## Polityka timeout/retry

| Typ | Timeout domyślny | Retry |
|---|---|---|
| wewnętrzne HTTP query | connect 2 s, całość 10 s | maks. 1 dla bezpiecznego GET po błędzie sieciowym |
| wewnętrzna mutacja | całość 15 s | klient może ponowić tylko z tym samym idempotency key |
| zewnętrzne API | connect 3 s, całość 30 s | exponential backoff z jitter, limit prób i dead state; per adapter można zaostrzyć |
| płatność/fiskalizacja | limit providera, nie dłużej niż UX pozwala | timeout daje UNKNOWN; status lookup przed ponowieniem |
| webhook przychodzący | odpowiedź do 5 s po trwałym inbox | provider retry; dedupe po event/provider ID |
| generowanie/wysyłka dokumentu | async po commit | retry render/send osobno; nie nadaje nowego numeru |
| import pliku | async po upload/validation | retry tego samego batch ID; apply najwyżej raz |

## Katalog integracji

| Integracja i kierunek | Kontrakt / autoryzacja | Idempotencja i retry | Status, log, ręczne wznowienie |
|---|---|---|---|
| Backoffice/shop/mobile/POS → API | versioned JSON/HTTP; session/OIDC-like token + membership/scope | `Idempotency-Key` dla mutacji; konflikt zwraca istniejący rezultat lub 409 | operation/correlation ID w odpowiedzi; audit; retry z klienta tym samym key |
| ERP → POS/shop: katalog/ceny | snapshot/delta z version/watermark; client credentials + shop scope | message/version unique; retry outbox | sync cursor, last success/error; operator może republish zakres |
| POS → ERP: sprzedaż | SaleSubmission v1 z lines/payments/device/local ID; device/user auth | unique `(shop, device, local_sale_id)` + message ID | RECEIVED/VALIDATED/APPLIED/REJECTED; reason i manual resolve bez zmiany payload |
| ERP ↔ dostawca | PO/ack/ASN/price catalog; signed webhook lub OAuth/client credential | partner document ID unique; retry outbox/inbox | status per PO/message, error mapping, resubmit po korekcie nowej wersji |
| ERP ↔ payment provider | payment intent/capture/refund/status; provider secret poza repo, webhook signature | merchant idempotency key + provider reference | INITIATED/UNKNOWN/SETTLED/FAILED/REFUNDED; manual status check, nie blind capture |
| ERP ↔ fiskalizacja | fiscal command/receipt status; device/adapter credential | sale ID + fiscal operation version | pending/issued/failed/unknown; operator check/retry, numer/response immutable |
| ERP ↔ Peppol/e-faktura | document version + attachment/hash; adapter credentials | document UUID/version unique | queued/sent/accepted/rejected; reason, correction/new send |
| ERP → e-mail | template version + recipient + document reference; provider credential | document+version+channel+recipient unique | queued/sent/bounced/failed; resend jawnie nową attempt |
| ERP ↔ S3/R2 | binary by checksum + metadata in DB; scoped service credential | object key + content hash | pending/stored/verified/failed; orphan scan i manual repair |
| partner → webhook inbox | envelope v1 + signature/timestamp | provider event ID unique | received/processed/retryable/dead; payload hash, redacted logs, replay po approval |
| ERP → partner webhook | envelope v1 + signature | message ID stable across attempts | delivery attempts, HTTP class, next try/dead; manual retry |
| urządzenie ↔ local bridge/POS | vendor protocol mapped to normalized adapter result; device identity | operation ID from ERP/POS | local queue/health only if required; operator sees unresolved state |
| plik import/export | versioned template, checksum, encoding and tenant scope | batch ID + file hash | uploaded/validated/approved/applied/rejected; error report; apply once |

## Conflict handling

- ERP-owned entity: zewnętrzna zmiana jest propozycją/komendą, nie last-write-wins.
- Partner-owned reference: ERP zachowuje external version i odrzuca starszą.
- Równoczesna edycja: optimistic concurrency; użytkownik widzi diff i ponawia świadomie.
- Offline POS: serwer rozstrzyga ceny/uprawnienia według zatwierdzonej polityki offline; konflikt nie jest cicho nadpisywany.
- Duplicate: zwracany jest poprzedni rezultat identycznej operacji; inny payload z tym samym key to konflikt bezpieczeństwa/integralności.
