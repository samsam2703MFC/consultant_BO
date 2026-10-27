# Niepotwierdzone założenia

| # | Założenie | Dlaczego niepotwierdzone | Potrzebny dowód |
|---:|---|---|---|
| 1 | sekret DB jest nadal aktywny | nie wykonywano połączenia | rotacja/secret inventory, kontrola przez właściciela |
| 2 | hasła w produkcyjnej tabeli `user` są jawne | brak odczytu DB | typ/format próbek hash bez ujawniania wartości |
| 3 | aplikacja jest publicznie dostępna bez dodatkowej ochrony | infrastruktura poza repo nieznana | reverse proxy/IAM/network config |
| 4 | Agent nie jest wdrożony | brak lokalnego entrypointu nie wyklucza innego repo/deployu | manifest deploymentu i routing |
| 5 | baza `.env` jest bezpieczną bazą dev | nazwa nie dowodzi klasy środowiska | potwierdzenie właściciela i konto read-only |
| 6 | istnieją realne rozbieżności magazynowe | brak danych i reconciliation | zapytania uzgadniające |
| 7 | dokumenty mają błędne sumy | brak testu nagłówek–pozycje | read-only SQL na danych |
| 8 | raporty marży są liczbowo błędne | istnieje ryzyko modelowe, brak benchmarku | uzgodnienie na zamkniętym okresie |
| 9 | procesy wymagają ręcznego SQL w praktyce | część luk self-service to wniosek z braku UI | runbooki i wywiad operacyjny |
| 10 | wszystkie 314 tras można uruchomić | brak startu/DI/schema | route smoke na test env |
| 11 | workery są uruchamiane cyklicznie | brak cron/systemd/CI | konfiguracja schedulerów i logi |
| 12 | model `product_movement` obsługuje realne odpady | kod istnieje tylko w Agent | aktywny endpoint, tabela i dane |

