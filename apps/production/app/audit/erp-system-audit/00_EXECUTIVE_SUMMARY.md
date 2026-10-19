# Podsumowanie wykonawcze

## Ocena ogólna

System jest rozbudowanym, monolitycznym ERP produkcyjno-handlowym w trakcie migracji architektonicznej. Ma szeroki zakres kartotek, receptur, zamówień, dokumentów, produkcji, kosztów, wysyłek i raportów, ale nie jest obecnie systemem operacyjnie domkniętym ani audytowalnym na poziomie wymaganym dla samodzielnego ERP. Ogólna ocena dojrzałości: **F2/F3**. Najlepiej rozwinięte są kartoteki produktu/receptury, obsługa zamówień B2B, dokumenty wysyłkowe i kalkulator kosztów. Najsłabsze są bezpieczeństwo panelu, spójność modelu wdrożeniowego API, magazyn oparty o księgę ruchów, pełne wykonanie produkcji, zakupy, śledzenie czasu pracy i wiarygodna rentowność historyczna.

Audyt wykrył **42 funkcjonalności**: F0: 8, F1: 7, F2: 13, F3: 12, F4: 2, F5: 0. Oceny są konserwatywne, bo nie można było uruchomić PHP ani połączyć się z bazą. Żadna funkcja nie dostała F5 bez testów, historii, korekt, self-service i obserwowalności.

## Najpoważniejsze ryzyka

1. **P0 — sekrety produkcyjne w pliku środowiskowym.** `.prod.env:11-14` zawiera host, nazwę użytkownika i jawne hasło bazy. Sekretu nie powtórzono w raporcie. Należy natychmiast obrócić poświadczenia, usunąć je z historii Git i wdrożyć secret manager.
2. **P0 — hasła panelu w postaci jawnej/porównywane wprost.** `App/Services/Auth/LoginService.php:21-25` porównuje `$password == $row['password']`; `App/Repositories/User/UserRepository.php:14-22` pozwala przechowywać pole `password`, a cały rekord trafia do sesji (`:47-50`).
3. **P0 — brak centralnej ochrony tras webowych i CSRF.** `App/Core/App.php:20-57` uruchamia kontroler po dopasowaniu trasy, bez wspólnego middleware autoryzacji. W repozytorium nie znaleziono mechanizmu CSRF. Trasy mutujące używają POST/PATCH/DELETE.
4. **P0 — niepotwierdzona izolacja tenantów/sklepów.** Duża część metod przyjmuje identyfikatory z URL, lecz webowy router nie egzekwuje kontekstu organizacji. Bez bazy i testów nie można wykluczyć IDOR.
5. **P1 — dwie równoległe architektury API.** `public/api.php` korzysta z `App/Core/Routes/ApiRoutes.php`, który rejestruje tylko 3 endpointy. Setki kontrolerów pod `Agent/api_app_dir` nie mają w badanym repo potwierdzonego aktywnego front controllera.
6. **P1 — brak pełnego modelu magazynowego.** Istnieją stany kartonów i ruchy materiałowe po stronie Agent, ale nie wykazano jednej księgi ruchów obejmującej przyjęcia, wydania, przesunięcia, rezerwacje, korekty, partie, daty ważności i wycenę.
7. **P1 — produkcja jest planem/listą, nie pełnym zleceniem.** Są kalkulacje zapotrzebowania i PDF-y, ale brak potwierdzonego cyklu zlecenia z planem, startem, raportem wykonania, zużyciem, odpadem, brakami, kontrolą jakości i zamknięciem.
8. **P1 — brak automatycznych testów produktu.** Nie znaleziono PHPUnit/config/test suites. Build, testy i baza są BLOCKED przez brak runtime.

## Gotowość analityczna

| Obszar | Ocena | Uzasadnienie |
|---|---|---|
| Sprzedaż | PARTIAL | Są transakcje POS w Agent i dokumenty WDT/zamówienia w App, lecz aktywność Agent i zgodność źródeł niepotwierdzona; brak testu korekt/anulacji. |
| Produkcja | MISSING_PROCESS/PARTIAL | Można policzyć planowane zapotrzebowanie, brak wiarygodnych danych wykonania, odpadów i czasu. |
| Magazyn | UNRELIABLE | Równoległe stany/logi i brak potwierdzonej księgi głównej oraz uzgodnień. |
| Pracownicy | PARTIAL | Konta, role, grafiki, zadania i kompetencje istnieją w kodzie Agent; czas wejścia/wyjścia i roboczogodziny niepotwierdzone. |
| Koszty | PARTIAL | Koszty miesięczne, pracownicze, media, logistyka i receptury są modelowane, ale historia cen i alokacja nie zostały zweryfikowane na danych. |
| Rentowność | UNRELIABLE | Raporty istnieją, lecz łączą WDT, bieżące ceny/receptury i miesięczne koszty bez dowodu pełnego snapshotu historycznego. |

## Testy i blokady

- Statyczne kontrole inwentaryzacyjne: PASS (5 grup poleceń `rg`).
- Testy jednostkowe/integracyjne/E2E produktu: 0 znalezionych, NOT_APPLICABLE dla wykonania.
- Start aplikacji, lint PHP, smoke CLI, połączenie DB: BLOCKED (4 grupy) — brak `php`, `composer`, klienta MySQL oraz bezpiecznej bazy testowej.
- Integracje zewnętrzne: NOT_TESTED ze względów bezpieczeństwa.

## Najważniejsze trzy fazy

1. Faza A: rotacja sekretów, hashowanie haseł, autoryzacja per trasa/tenant, CSRF, log audytowy i testy integralności.
2. Faza B: domknięcie produkcji, magazynu, zakupów i korekt jako jednoznacznych procesów statusowych.
3. Faza D/E: historyczne snapshoty cen/kosztów, księga ruchów i wiarygodne warstwy analityczne.

