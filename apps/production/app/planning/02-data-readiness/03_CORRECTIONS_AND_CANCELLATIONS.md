# Korekty i anulacje

## Ocena mechanizmów AS-IS

| Zdarzenie | Obecny ślad | Sposób korekty widoczny w kodzie | Ryzyko analityczne | Status |
|---|---|---|---|---|
| anulowana transakcja POS | model Agent `transaction*` | niepotwierdzony status/akt kompensujący | anulowane rekordy mogą wejść do sum sprzedaży albo zniknąć bez śladu | BLOCKED |
| anulowane zamówienie | `customer_order.status`, słownik statusów | aktualizacja statusu; punktowy changelog | brak pewności, czy pozycje i rezerwacje pozostają w historii oraz jak raport filtruje status | PARTIAL |
| zwrot sprzedaży | kontrolery korekt/reklamacji, Agent POS | brak potwierdzonego wspólnego zdarzenia return powiązanego z oryginałem | przychód, VAT, płatność i zapas mogą być korygowane niespójnie | UNRELIABLE |
| korekta faktury | moduł invoice correction | osobny obiekt istnieje, lecz pełne powiązanie i raportowanie nieprzetestowane | możliwość zliczenia oryginału bez korekty lub podwójnego skutku | PARTIAL |
| zmiana WDT | repozytorium dopuszcza update pozycji/ceny | nadpisanie rekordu | opublikowany wynik historyczny może się zmienić bez zdarzenia różnicowego | UNRELIABLE |
| częściowa realizacja | oczekiwana i rzeczywista ilość w zamówieniu, logi dostaw Agent | aktualizacja ilości/log dostawy | brak kanonicznego faktu każdej częściowej dostawy | PARTIAL |
| duplikat integracyjny | `event_id` i outboxy występują punktowo | retry/status w outbox | brak potwierdzonego unique i wspólnego klucza idempotencji | UNRELIABLE |
| ponowna synchronizacja | wiele workerów/outboxów | ponowienie komunikatu | możliwość powtórnego skutku domenowego, jeśli odbiorca nie deduplikuje | UNRELIABLE |
| usunięta pozycja | repozytoria mają operacje delete/update | hard delete możliwy w części CRUD | utrata faktu oraz zmiana sumy bez śladu | UNRELIABLE |
| zmiana po zamknięciu okresu | brak potwierdzonej blokady okresu | aktualizacja danych źródłowych | opublikowane KPI mogą zmieniać się wstecz | MISSING_PROCESS |

## Rozpoznane wzorce

- **Nadpisanie:** ceny/ilości/statusy mogą być zmieniane metodami update; WDT ma jawne operacje aktualizacji pozycji.
- **Nowy rekord:** changelog zamówienia, korekta faktury i część logów tworzą osobne wpisy.
- **Zdarzenie kompensujące:** nie potwierdzono uniwersalnego wzorca dla sprzedaży, zapasu, produkcji i kosztów.
- **Osobna tabela:** występuje dla części changelogów, reklamacji i korekt dokumentów.
- **Uwzględnienie w raportach:** nie ma jednego filtra/semantyki; wymaga testu każdego raportu.

## Reguła interpretacji danych przed naprawą modelu

Raport nie może zakładać, że `status != cancelled` wystarcza. Dla każdego faktu trzeba ustalić:

1. czy oryginał pozostaje w bazie;
2. czy korekta ma kwotę/ilość ze znakiem przeciwnym;
3. czy istnieje jednoznaczne `original_id`;
4. czy korekta dotyczy sprzedaży, płatności, dokumentu i magazynu równocześnie;
5. czy retry nie utworzył duplikatu;
6. czy data biznesowa korekty różni się od czasu technicznego zapisu;
7. czy okres był otwarty, a jeśli nie — kto zatwierdził zmianę.

## Minimalny model korekty analitycznej

Każda korekta powinna mieć własny identyfikator, typ, `original_event_id`, czas biznesowy, czas zapisu, aktora, zatwierdzającego, przyczynę, okres księgowy i oddzielne skutki ilościowe/finansowe. Raport sumuje oryginał i zdarzenia kompensujące; nie odczytuje „ostatniej wersji” nadpisanego rekordu jako całej historii.

## Kontrole konieczne w bazie

- duplikaty kluczy integracyjnych i numerów dokumentów;
- dokumenty korekty bez oryginału i oryginały oznaczone jako skorygowane bez korekty;
- zamówienia anulowane z późniejszym WDT/wysyłką;
- pozycje dokumentu po dacie modyfikacji nagłówka/okresu;
- suma oryginał + korekty względem płatności i ruchu magazynowego;
- hard delete w tabelach pozycji na podstawie binlog/audytu, jeśli dostępny.
