# TOP 10 rekomendacji

| # | Rekomendacja | Dlaczego teraz / problem | Użytkownicy | Minimalny sensowny zakres i zależności |
|---:|---|---|---|---|
| 1 | FEAT-001 zlecenie produkcyjne | Największa przerwa: system zna plan, nie zna wykonania. | planista, brygadzista | jedno zlecenie, statusy, ilości i odchylenie; potrzebna decyzja o statusach |
| 2 | FEAT-004 ledger magazynowy MVP | Warunek wiarygodnych stanów, kosztów i rezerwacji. | magazyn, finanse | PZ z produkcji + WZ z wysyłki, historyczne ruchy; wymaga właściciela danych |
| 3 | FEAT-007 PO→PZ | Domyka zakupy i ogranicza e-mail/arkusze. | zakupy, magazyn | PO, termin, przyjęcie i różnica; zależy od modelu ruchu |
| 4 | FEAT-005 kolejka działań | Mały koszt, codzienna wartość, nie wymaga dużych migracji. | handel, logistyka | 4 reguły i linki do rekordu; wymaga definicji terminów |
| 5 | FEAT-006 konsola outbox | Niedoręczony dokument jest realnym wyjątkiem bez widoku użytkownika. | operator, handel | proforma/e-mail, status/błąd/retry jednej pozycji; idempotencja |
| 6 | FEAT-009 wersje/snapshoty | Bez tego marża historyczna i odtwarzalność receptury są niewiarygodne. | technolog, controller | wersja obowiązująca + snapshot okresu; polityka dat |
| 7 | FEAT-003 odpady i QC partii | Wprowadza dane potrzebne do kontroli produkcji, bez pełnego MES. | produkcja, jakość | ilość dobra/odpad, kod, pass/fail; FEAT-001 |
| 8 | FEAT-010 zamknięcie kosztów | Istniejące kalkulatory zyskują kontrolę okresu i odchyleń. | controller, właściciel | checklist, status okresu, progi; FEAT-009 zalecane |
| 9 | FEAT-012 różnice wysyłkowe | Przekształca już zarejestrowane różnice w decyzje i audyt. | logistyka, księgowość | właściciel, decyzja, status; polityka księgowa |
| 10 | FEAT-011 SLA reklamacji | Szybka poprawa przewidywalności obsługi klienta. | obsługa, kierownik | właściciel, termin, 4 statusy; decyzja SLA |

Równowaga jest celowa: pozycje 4, 5, 9 i 10 poprawiają pracę natychmiast; 1–3 domykają podstawowe procesy; 6–8 budują wiarygodność danych i kontrolę zarządczą.
