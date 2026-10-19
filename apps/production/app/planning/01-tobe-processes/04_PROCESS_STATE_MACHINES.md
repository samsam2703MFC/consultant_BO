# Maszyny stanów procesów

## Reguły wspólne

- Status jest enumem domenowym, nie swobodnym tekstem.
- Przejście wykonuje nazwana komenda z kontrolą wersji i uprawnienia.
- Cofnięcie statusu nie oznacza edycji historii; używa przejścia kompensacyjnego albo nowej rewizji.
- Aspekty niezależne mają osobne statusy: np. zamówienie ma fulfillment status, płatność payment status, a dokument invoice status.
- Każda maszyna zapisuje `status_changed_at/by/reason`, correlation ID i poprzedni status.

## Receptura — RecipeVersion

| Status | Znaczenie / kto | Dozwolone przejścia | Zakazane i cofnięcie | Wymagane dane i skutki |
|---|---|---|---|---|
| DRAFT | edytowana przez technologa | IN_REVIEW, WITHDRAWN | bezpośrednio ACTIVE | skład, yield, jednostki; brak wpływu na stock/finanse; raport jako draft |
| IN_REVIEW | zamrożona do kontroli; reviewer | DRAFT, ACTIVE, WITHDRAWN | edycja bez powrotu DRAFT | kompletność, koszt preview; brak ruchów |
| ACTIVE | obowiązuje od daty; approver | SUPERSEDED, WITHDRAWN | powrót do DRAFT | aktywuje koszt standardowy i nowe zlecenia; historyczne zlecenia bez zmian |
| SUPERSEDED | zastąpiona wersją | brak | reaktywacja; utworzyć nową wersję | tylko odczyt/raport/historyczny koszt |
| WITHDRAWN | niedopuszczona/wycofana | brak | reaktywacja | blokuje nowe zlecenia, nie anuluje rozpoczętych |

## Zamówienie klienta — SalesOrder

| Status | Znaczenie / kto | Dozwolone przejścia | Zakazane/cofnięcie | Skutki magazyn/finanse/raport |
|---|---|---|---|---|
| DRAFT | oferta robocza; handlowiec/klient | SUBMITTED, CANCELLED | fulfillment | brak rezerwacji/przychodu; nie raportować jako sprzedaż |
| SUBMITTED | oczekuje walidacji | VALIDATED, DRAFT, REJECTED | CONFIRMED bez ceny/klienta | brak stock; raport popytu miękkiego |
| VALIDATED | ceny, limit i termin sprawdzone | CONFIRMED, REJECTED | edycja bez rewizji | może obliczyć ATP |
| CONFIRMED | zobowiązanie firmy; uprawniony handlowiec | IN_FULFILMENT, CANCELLED | DRAFT | tworzy popyt/rezerwacje; należność jeszcze nie powstaje |
| IN_FULFILMENT | picking/produkcja/wysyłka | FULFILLED, PARTIALLY_FULFILLED, CANCEL_REQUESTED | proste CANCELLED po wydaniu | zużywa rezerwacje; dokumenty logistyczne |
| PARTIALLY_FULFILLED | część dostarczona | IN_FULFILMENT, FULFILLED, CLOSED_PARTIAL | cofnięcie dostaw przez return | część wpływa na dokumenty/raport realizacji |
| FULFILLED | całość dostarczona | CLOSED | CANCELLED | finalne dokumenty; raport OTIF |
| CANCELLED/REJECTED | bez realizacji | brak | reopen jako nowe zamówienie | zwalnia rezerwacje; brak sprzedaży |

## Sprzedaż i płatność — Sale / Payment

| Status | Znaczenie / kto | Przejścia | Niedozwolone/cofnięcie | Skutki |
|---|---|---|---|---|
| Sale OPEN | koszyk; kasjer | PRICED, CANCELLED | COMPLETED | brak stock/finance |
| Sale PRICED | snapshot cen/VAT | PAYMENT_PENDING, OPEN | zmiana linii bez ponownej wyceny | opcjonalna krótka rezerwacja |
| Sale PAYMENT_PENDING | trwa płatność | COMPLETED, PAYMENT_FAILED | drugi capture z innym key | po SETTLED finalizuje sale |
| Sale COMPLETED | fakt sprzedaży | CORRECTED/PARTIALLY_RETURNED/RETURNED | CANCELLED/edit | wydanie, przychód, fiskalizacja i raport |
| Payment INITIATED | zlecono płatność | AUTHORIZED, SETTLED, FAILED, EXPIRED | ponowne INITIATED z innym key | brak przychodu kasowego do SETTLED |
| Payment SETTLED | środki potwierdzone | PARTIALLY_REFUNDED, REFUNDED | FAILED | rozrachunek i raport płatności |
| Payment FAILED/EXPIRED | brak środków | nowa próba jako nowy attempt | SETTLED bez potwierdzenia providera | brak skutków finansowych |

## Dokument finansowy — Invoice

| Status | Znaczenie / kto | Przejścia | Niedozwolone/cofnięcie | Skutki |
|---|---|---|---|---|
| DRAFT | roboczy; księgowość/system | VALIDATED, CANCELLED | ISSUED bez danych | brak numeru i księgowania |
| VALIDATED | dane/sumy poprawne | ISSUED, DRAFT | edycja linii bez DRAFT | rezerwacja numeru dopiero przy issue |
| ISSUED | prawny dokument | SENT, CORRECTED | delete/edit/reuse numeru | należność/VAT/raport; brak ruchu stock sam w sobie |
| SENT | dostarczony | PARTIALLY_PAID, PAID, OVERDUE, CORRECTED | ISSUED | delivery audit |
| PAID/PARTIALLY_PAID/OVERDUE | stan rozrachunku | PAID, CORRECTED | zmiana kwoty dokumentu | wpływ należności i cash reporting |
| CORRECTED | ma dokument korekty | brak | przywrócenie | raportuje oryginał+korektę, nigdy nadpisanie |

## Plan i zlecenie produkcyjne

| Status | Znaczenie / kto | Przejścia | Niedozwolone/cofnięcie | Skutki |
|---|---|---|---|---|
| DRAFT | tworzone; planista | RELEASED, CANCELLED | start | brak rezerwacji |
| RELEASED | zatwierdzony snapshot receptury | WAITING_MATERIAL, MATERIAL_RESERVED, CANCELLED | edit snapshotu | generuje potrzeby/rezerwacje; brak kosztu actual |
| WAITING_MATERIAL | braki | MATERIAL_RESERVED, CANCELLED | IN_PROGRESS | shortage KPI |
| MATERIAL_RESERVED | zapas chroniony | READY, WAITING_MATERIAL, CANCELLED | CLOSED | rezerwacje aktywne |
| READY | można wykonać | IN_PROGRESS, CANCELLED | DRAFT | opcjonalne wydanie staging |
| IN_PROGRESS | rozpoczęte; operator | PAUSED, REPORTED | CANCELLED bez kompensacji | rzeczywiste wydania, praca, WIP |
| PAUSED | kontrolowany postój | IN_PROGRESS, CANCEL_REQUESTED | READY | WIP bez nowych operacji |
| REPORTED | ilości wykonania zgłoszone | QC_PENDING, IN_PROGRESS przez korektę | CLOSED | provisional output, odchylenia |
| QC_PENDING | partia w kwarantannie | ACCEPTED, PARTIALLY_ACCEPTED, REJECTED | wydanie do sprzedaży | stock quarantine |
| ACCEPTED/PARTIALLY_ACCEPTED | QC zakończone | CLOSED | REPORTED | release accepted, scrap/rework rejected |
| REJECTED | brak akceptacji | REWORK, CLOSED_AS_SCRAP | ACCEPTED bez nowej inspekcji | waste/scrap cost |
| CLOSED | rozliczone | REOPEN_REQUESTED | edit | actual cost i KPI finalne |

## Ruch magazynowy i rezerwacja

| Status | Znaczenie / kto | Przejścia | Niedozwolone/cofnięcie | Skutki |
|---|---|---|---|---|
| Movement DRAFT | przygotowany | POSTED, CANCELLED | wpływ na saldo | nie raportować w stock |
| Movement POSTED | zaksięgowany | REVERSED | edit/delete | wpływa na saldo, wartość i historię as-of |
| Movement REVERSED | odwrócony osobnym ruchem | brak | ponowne POSTED | saldo netto skorygowane, oba wpisy widoczne |
| Reservation REQUESTED | żądanie | ACTIVE, REJECTED | consume | brak zmiany on-hand |
| Reservation ACTIVE | chroni qty | PARTIALLY_CONSUMED, CONSUMED, RELEASED, EXPIRED | zwiększenie bez command | zmniejsza available-to-promise |
| PARTIALLY_CONSUMED | część użyta | CONSUMED, RELEASED | ACTIVE bez korekty | balance reservation widoczny |
| CONSUMED/RELEASED/EXPIRED | terminalny | brak | reaktywacja | zwalnia lub kończy dostępność |

## Partia — Lot

| Status | Znaczenie / kto | Przejścia | Zakazane | Skutki |
|---|---|---|---|---|
| CREATED | po receipt/production | QUARANTINED, RELEASED | sprzedaż jeśli wymaga QC | genealogy start |
| QUARANTINED | czeka na QC | RELEASED, BLOCKED, REJECTED | issue komercyjne | on-hand, ale niedostępne |
| RELEASED | dopuszczona | BLOCKED, DEPLETED, EXPIRED | edycja lot identity po użyciu | available stock i FEFO |
| BLOCKED | wstrzymana/recall | RELEASED po nowej decyzji, REJECTED | issue | blokada rezerwacji i recall trace |
| REJECTED | do zwrotu/scrap | DEPLETED | release bez nowej inspekcji | waste/claim cost |
| DEPLETED/EXPIRED | terminalny | brak | dodatnie qty bez nowego receipt/correction | raport rotacji/expiry |

## Zakup i dostawa

| Status | Znaczenie / kto | Przejścia | Niedozwolone/cofnięcie | Skutki |
|---|---|---|---|---|
| PO DRAFT | kupiec | PENDING_APPROVAL, CANCELLED | SENT | brak zobowiązania |
| PENDING_APPROVAL | approver | SENT, DRAFT, REJECTED | edycja bez DRAFT | budżet/approval audit |
| SENT | wysłane | ACKNOWLEDGED, CANCEL_REQUESTED | DRAFT | zobowiązanie i expected delivery |
| ACKNOWLEDGED | potwierdzone przez dostawcę | PARTIALLY_RECEIVED, RECEIVED, CANCELLED_REMAINDER | zmiana ceny bez revision | SLA start |
| PARTIALLY_RECEIVED | część przyjęta | RECEIVED, CLOSED_PARTIAL | pełne cancel | stock/cost tylko przyjętej części |
| RECEIVED/CLOSED | zakończone | korekta dostawy/claim | edit PO | KPI kompletności i ceny |
| Delivery ARRIVED/COUNTED/QC_PENDING | odbiór | ACCEPTED, PARTIAL, REJECTED | stock released przed QC | receipt quarantine |

## Pracownik, czas i okres kosztowy

| Maszyna | Statusy i przejścia | Uprawnienia i skutki |
|---|---|---|
| Employee | PLANNED→ACTIVE→SUSPENDED→ACTIVE/TERMINATED | HR; termination kończy assignment, IAM revokuje dostęp; historia pozostaje |
| TimeEntry | OPEN→SUBMITTED→APPROVED→LOCKED; REJECTED→OPEN; LOCKED→CORRECTED przez wpis różnicowy | pracownik submit, kierownik approve; approved zasila koszt pracy |
| CostPeriod | OPEN→CLOSING→CLOSED; CLOSED→REOPEN_REQUESTED→REOPENED→CLOSING | controller + drugi approver; CLOSED zamraża raporty i zakazuje backdate |

## Integracja

| Status | Przejścia | Znaczenie i skutki |
|---|---|---|
| PENDING | PROCESSING | event oczekuje; brak ponownej logiki domenowej |
| PROCESSING | SUCCEEDED, FAILED_RETRYABLE, DEAD | claim z lease i attempt |
| FAILED_RETRYABLE | RETRY_SCHEDULED, DEAD | błąd techniczny; exponential backoff |
| RETRY_SCHEDULED | PROCESSING | ten sam idempotency key |
| SUCCEEDED | terminalny | external ID i response hash zapisane |
| DEAD | RETRY_SCHEDULED po ręcznej decyzji | wymaga powodu/operatora; payload immutable |

