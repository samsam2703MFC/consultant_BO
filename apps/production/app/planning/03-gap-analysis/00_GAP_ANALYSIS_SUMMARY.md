# Podsumowanie analizy luk AS-IS vs TO-BE

## Werdykt

System ma szeroki zakres kodu i wiele użytecznych ekranów, ale nie stanowi jeszcze spójnego, audytowalnego ERP end-to-end. Analiza identyfikuje **56 konkretnych luk zdolności biznesowych**. Największy problem nie polega na braku CRUD, lecz na braku wspólnych reguł stanu, historii, korekt i źródeł prawdy.

## Obraz ilościowy

| Element | Wynik |
|---|---:|
| znormalizowane funkcje AS-IS | 50 |
| procesy TO-BE | 42 |
| KPI ocenione w audycie danych | 46 |
| luki zdolności | 56 |
| funkcje AS-IS z testem produktu PASS | 0 |
| funkcje AS-IS o dojrzałości F4/F5 | 0 |

Liczba luk nie oznacza 56 osobnych projektów. Wiele z nich powinno zostać zamkniętych przez wspólny fundament, np. jedna księga ruchów zamyka kilka luk magazynu i odblokowuje wiele KPI.

## Luki krytyczne P0

1. jawne sekrety i nieskuteczne uwierzytelnienie;
2. brak spójnej autoryzacji, tenant context i rozdziału user–employee;
3. brak kanonicznego faktu sprzedaży oraz wspólnego modelu korekt;
4. brak wersjonowanych receptur i snapshotów historycznych;
5. brak jednej niezmiennej księgi ruchów magazynowych;
6. brak procesu wykonania i rozliczenia produkcji;
7. brak wiarygodnego schematu/migracji i profilu danych;
8. brak zamknięcia okresu oraz odtwarzalnego kosztu;
9. brak testów produktu i uruchamialnego środowiska walidacyjnego.

## Rekomendowane typy decyzji

- `KEEP` tylko dla elementów o jasnej wartości, które mogą pozostać bez zmiany semantyki.
- `COMPLETE` dla funkcji z poprawnym rdzeniem, ale bez wyjątków, historii lub domknięcia E2E.
- `FIX` dla błędów lokalnych, zwłaszcza bezpieczeństwa i integralności.
- `EXTEND` dla działających funkcji wymagających dodatkowej zdolności.
- `REFACTOR` dla wartościowego kodu o złych granicach lub duplikacji.
- `REBUILD` dla procesów, których model nie odpowiada TO-BE.
- `REPLACE` dla mechanizmu, który powinien zostać zastąpiony jednym źródłem prawdy.
- `REMOVE` wyłącznie po analizie danych i konsumentów.
- `DEFER` dla zdolności zaawansowanych niepotrzebnych w MVP.
- `REQUIRES_DECISION` tam, gdzie semantyka zależy od właściciela biznesowego.

## Najważniejsza zasada modernizacji

Nie należy rozbudowywać raportów ani automatyzacji przed ustaleniem faktów źródłowych. Kolejność logiczna zależności to:

```text
bezpieczeństwo i tożsamość
→ jawne procesy i statusy
→ niezmienne fakty oraz korekty
→ historia i uzgodnienia
→ raporty i KPI
→ automatyzacja zaawansowana
```

To zależność architektoniczna, nie roadmapa implementacyjna.

## Ograniczenia pewności

Nie odczytano schematu ani rekordów działającej bazy, nie uruchomiono aplikacji i nie potwierdzono deploymentu `Agent/api_app_dir`. Dlatego:

- istnienie kodu Agent nie jest dowodem aktywnej funkcji;
- brak kodu w repo nie wyklucza infrastruktury poza workspace;
- luki jakości rekordów są oznaczone jako niezweryfikowane, a nie jako stwierdzone anomalie;
- decyzji `REMOVE` nie wolno wykonać bez inwentaryzacji konsumentów i danych.

## Wynik dla architektury

Najbardziej racjonalnym kierunkiem pozostaje modularny monolit z jedną bazą operacyjną, wspólną tożsamością, niezmiennymi ledgerami sprzedaży/zapasu/kosztu, kontrolowanymi maszynami stanów i outbox/inbox. Rozbudowana hurtownia, mikrousługi i workflow engine nie są wymagane do zamknięcia obecnych luk.
