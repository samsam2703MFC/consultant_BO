# Ocena jakości istniejącego audytu

## Ocena kryteriów

| Kryterium | Ocena | Uzasadnienie |
|---|---|---|
| Wszystkie repozytoria | PARTIAL | wykryto jedno repo Git; nie sprawdzono zewnętrznego deployu Agent ani innych katalogów poza workspace |
| Wszystkie aplikacje | PARTIAL | App, public API, Agent i workery opisano; status Agent pozostał nieweryfikowalny |
| Dowody z kodu | PARTIAL/PASS | są ścieżki i linie dla kluczowych wniosków, lecz wiele funkcji ma tylko nazwę klasy/repozytorium |
| Dowody z bazy | FAIL/BLOCKED | brak połączenia, schematu i danych; SQL jest wyłącznie planem kontroli |
| UI | PARTIAL | widoki i trasy uwzględniono, ale nie zmapowano formularzy/akcji dla każdej funkcji |
| Integracje | PARTIAL | S3/R2, webhook, outbox, RabbitMQ, e-mail i Peppol wykryte statycznie; bez testów |
| E2E | PARTIAL | sześć procesów opisanych statycznie, żaden nieuruchomiony |
| Istnienie kontra kompletność | PASS | konserwatywne F0–F3 dla większości, Agent oznaczony jako niepotwierdzony |
| Faktycznie uruchomione testy | FAIL | „PASS” dotyczył wyszukiwań `rg`, nie testów produktu; testów produktu 0 |
| Funkcje wymagające SQL | PARTIAL | luki self-service wskazane, lecz część obejść oznaczono „prawdopodobnie SQL” bez dowodu operacyjnego |
| Hipotezy | PARTIAL/PASS | blokady są jawne, ale niektóre ryzyka integralności zapisano zbyt kategorycznie |
| Spójność ocen | FAIL | 42/50 funkcji oraz F4 przy testach=0 naruszają definicję skali |

## Mocne strony

- zachowano rozdział fakt/ryzyko w części DB i nie wykonywano niebezpiecznych zapisów;
- szeroka inwentaryzacja domen, tras, repozytoriów, UI i integracji;
- poprawne rozpoznanie dwóch architektur App/Agent;
- dobry rejestr blokad i poleceń;
- trafna ostrożność wobec rentowności, magazynu i KPI pracowniczych.

## Najważniejsze braki

1. Przeoczono `App/Core/config.php:29-38`: śledzony sekret i adresy środowiska.
2. Przeoczono `LoginController.php:26-27`: nadpisanie danych logowania `admin/admin`.
3. Nie wskazano, że aktywny `UserController` i jego trasy nie mają ochrony auth/permission.
4. F4 przypisano cennikom i kalkulatorowi kosztów mimo test score 0; według definicji F4 wymaga podstawowych testów i procesu E2E.
5. Odpady oceniono zbiorczo F0, mimo modelu `product_movement` i raportów Waste w Agent.
6. Nie przeprowadzono pełnego mapowania endpoint–kontroler–widok–tabela per funkcja.
7. Nie zbadano historii Git sekretu; obecność w initial commit została dopiero potwierdzona w walidacji.

## Wniosek

Raport nadaje się jako materiał orientacyjny, ale oceny do TO-BE muszą pochodzić ze znormalizowanego katalogu tej syntezy. Wszystkie stwierdzenia o działaniu, danych, wydajności i realnym wdrożeniu Agent pozostają poza zakresem potwierdzonych faktów.

