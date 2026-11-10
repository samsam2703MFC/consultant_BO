# Model historii, zdarzeń i audytu

## Rozdzielenie pojęć

| Warstwa | Cel | Mutowalność | Przykład |
|---|---|---|---|
| stan aktualny | obsługa bieżącego workflow i szybkich decyzji | zmienia się tylko kontrolowanymi komendami | status zamówienia, aktualna kartoteka, balance projection |
| log audytowy | dowód kto/co/kiedy/skąd/dlaczego | append-only | zmiana roli, activation recipe version, ręczny retry |
| zdarzenie biznesowe | niezmienny fakt o skutku domenowym | append-only, korekta kompensuje | SaleCompleted, StockMoved, ProductionOutputReported |
| dokument | prawny/operacyjny snapshot z numerem i treścią | draft edytowalny; issued immutable | invoice, WDT, CMR, correction document |
| snapshot historyczny | wartości interpretacyjne obowiązujące w chwili faktu | immutable | ProductSnapshot, PriceSnapshot, RecipeVersion, CostSnapshot |
| rekord integracyjny | stan dostarczenia/odbioru komunikatu | payload immutable; status/attempt append/update kontrolowane | outbox message, inbox message, delivery attempt |

To nie jest pełny event sourcing. Aggregate ma normalny stan bieżący; zdarzenia zapisują tylko fakty potrzebne do audytu, integracji, korekty, historii i raportowania.

## Minimalny audit event

| Pole | Znaczenie |
|---|---|
| `audit_id` | monotoniczny/UUID identyfikator wpisu |
| `occurred_at_utc` | czas operacji |
| `organization_id`, opcjonalnie site/shop/warehouse | zakres danych |
| `actor_user_id`, `actor_employee_id` | konto i osoba, jeśli dotyczy |
| `actor_role_snapshot`, `auth_session_id` | kontekst uprawnienia, bez sekretów |
| `action`, `permission` | wykonana operacja i wymagane uprawnienie |
| `aggregate_type`, `aggregate_id`, `aggregate_version` | obiekt zmiany |
| `before_json`, `after_json` lub `change_json` | zmienione pola; redakcja danych wrażliwych |
| `reason_code`, `reason_text`, `approved_by` | obowiązkowe dla korekt/wyjątków |
| `correlation_id`, `causation_id`, `idempotency_key_hash` | śledzenie procesu/retry |
| `source_app`, `device_id`, `ip/user_agent` gdy legalne | źródło techniczne |
| `outcome` | success/rejected/failed; błędy bez sekretów |

## Minimalny business event

Business event posiada `event_id`, typ i wersję kontraktu, aggregate ID/version, business time, recorded time, organization, correlation/causation, actor reference, immutable payload i hash. Nie musi zawierać całego stanu aggregate; zawiera fakt i snapshoty wymagane przez konsumentów.

## Zdarzenia wymagane przekrojowo

- master data: Activated, Deactivated, ClassificationChanged;
- recipe/pricing: VersionActivated, PricePublished, PromotionActivated;
- sales/payments: OrderConfirmed/Fulfilled/Cancelled, SaleCompleted/Corrected, PaymentSettled/Refunded;
- documents: Issued/Sent/Corrected;
- inventory: MovementPosted/Reversed, ReservationChanged, LotStatusChanged, StocktakePosted;
- production: Released/Started/Consumed/OutputReported/WasteReported/QCDecided/Closed/Corrected;
- purchasing/logistics: POApproved/Sent, GoodsReceived, DiscrepancyRaised, ShipmentDispatched/Delivered/Returned;
- workforce/cost: TimeApproved/Corrected, CostPosted/Adjusted, PeriodClosed/Reopened;
- integration: MessageReceived/Delivered/Dead/ManuallyRetried.

## Snapshoty

| Fakt | Obowiązkowy snapshot |
|---|---|
| pozycja sprzedaży | produkt/SKU/nazwa/kategoria/jednostka/opakowanie, cena bazowa/końcowa, rabat, VAT, waluta/kurs, klient/sklep/kanał/promocja, koszt gdy metoda przewiduje moment sale |
| pozycja zamówienia | produkt, jednostka, cena/VAT, warunki klienta, expected date |
| dokument issued | strony dokumentu, pozycje, podatki, waluta, numer, daty i źródła |
| receipt/stock movement | produkt/jednostka/przelicznik, lot, lokalizacja, cena/koszt/metoda, dokument źródłowy |
| production order | recipe version/hash, planowana ilość/jednostka, zakład i strategia produkcji |
| cost calculation | metoda/version, źródła cen/kosztów, alokacje, waluta/kurs i completeness |
| time approved | employee assignment/role/location i źródło czasu |

## Korekty i historia

Oryginał nie jest nadpisywany. `correction_event` wskazuje original event/document/movement, typ, reason, delty ilości/kwot/VAT, actor, approver, business date i period. Skutki domenowe mają własne identyfikatory i wspólny correlation ID. Raport sumuje oryginał i kompensacje.

## Retencja i prywatność

Retencja zależy od BD-15. Domyślnie zdarzenia finansowe, magazynowe, produkcyjne, dokumenty i audit operacji krytycznych mają retencję zgodną z obowiązkiem prawnym. Dane osobowe niepotrzebne do dowodu są anonimizowane/pseudonimizowane, lecz fakt biznesowy pozostaje. Audit ma odrębne uprawnienie od zwykłego odczytu domeny.

## Niezmienność

- aplikacja nie udostępnia update/delete dla eventów, posted movements, issued documents i posted costs;
- DB user aplikacji może zapisywać przez kontrolowane repozytoria; techniczne ograniczenia/trigger tylko jeśli proste i testowalne;
- backup/binlog nie zastępuje audytu aplikacyjnego;
- korekta logu audytowego jest nowym wpisem opisującym błąd, nigdy edycją starego.

## Odtwarzalność

Raport historyczny przechowuje definicję/version, okres, watermark, snapshot/hash źródeł i status reconciliation. Musi umożliwiać drill-down do business events/dokumentów. Odtworzenie pełnego stanu aggregate z eventów nie jest wymagane, chyba że przyszła potrzeba wykaże wartość pełnego event sourcingu.
