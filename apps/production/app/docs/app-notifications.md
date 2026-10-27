# Globalne powiadomienia aplikacji

## Model działania

Powiadomienia są globalne i **nie korzystają z sesji ani identyfikacji użytkownika**. Aktywny wpis z `app_notification` pojawia się jako banner na każdej stronie. Kliknięcie **Odczytane** albo `x` globalnie oznacza wpis jako potwierdzony, więc znika we wszystkich otwartych panelach przy następnym odpytywaniu.

Banner pobiera dane z `/ajax/app-notifications` przy załadowaniu strony i co 20 sekund. Dzięki temu nowe zdarzenie utworzone przez cron jest widoczne bez przechodzenia na inną stronę.

## Migracja

Przed wdrożeniem uruchom:

```sql
migrations/034_app_notifications.sql
```

Tabela przechowuje treść, link, JSON kontekstu oraz unikalny `dedup_key`. Nie przechowuje wyników audytów ani danych użytkownika.

## Endpointy

- `GET /ajax/app-notifications` — aktywne, niepotwierdzone wpisy.
- `POST /ajax/app-notifications/{id}/acknowledge` — body `{ "via": "read" }` lub `{ "via": "dismiss" }`.

## Źródła powiadomień

| Zdarzenie | Miejsce utworzenia | Deduplikacja |
| --- | --- | --- |
| Nowe zamówienie i proforma | `OrderService::insertOrderFromCollector()` | franczyza + `order_key` |
| Nowa reklamacja | `ComplaintService::insertFromCollector()` | `complaint_uuid` |
| Rozbieżność katalogu produktów | `FranchiseProductDiscrepancyNotificationService` | SKU + fingerprint danych snapshotu |

Import zamówień i reklamacji jest już uruchamiany przez `Bin/monitored_poller.php`. Monitor rozbieżności jest osobnym zadaniem, aby nie obciążać każdego przebiegu importera:

```cron
*/15 * * * * /usr/bin/php /sciezka/do/aplikacji/Bin/franchise_product_discrepancy_monitor.php
```

Skrypt korzysta z `CronRunService`, blokady plikowej i istniejącego mechanizmu snapshotów katalogu franczyzowego.

## Świadome ograniczenie

Bez sesji lub identyfikatora urządzenia nie można zachować osobnego stanu „odczytane” dla każdego użytkownika. Potwierdzenie jest więc wspólne dla całej aplikacji, zgodnie z przyjętym modelem bez autoryzacji.
