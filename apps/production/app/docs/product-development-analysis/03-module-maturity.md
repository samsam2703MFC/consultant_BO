# Dojrzałość modułów

| Moduł | Poziom | Uzasadnienie | Największa luka | Następny poziom | Funkcje potrzebne do przejścia wyżej |
| ----- | -----: | ------------ | --------------- | --------------: | ------------------------------------ |
| Kartoteki produktów/surowców | 2 | rozbudowany CRUD, wyszukiwanie i eksport | brak partii, audytu zmian i użycia w stanach | 3 | aktywność, wersje/zmiany z uzasadnieniem, zależności z recepturą |
| Receptury i koszt receptury | 2 | receptury, półprodukty, kroki i przeliczenia istnieją | brak wersjonowania „obowiązuje od” | 3 | publikacja wersji, blokada edycji użytej wersji, snapshot kosztu |
| Klienci/cenniki/zamówienia | 3 | CRUD, zamówienie, proforma, changelog i snapshoty | niepełny workflow realizacji/anulacji | 4 | legalne statusy, działania zależne od statusu, wyjątki i alerty |
| Produkcja | 2 | plan i zapotrzebowanie są wyliczane i drukowane | brak wykonania zlecenia | 3 | zlecenie, start/raport/akceptacja/odpad/zamknięcie |
| Magazyn wyrobów | 1 | jest podgląd stanu i dokumenty | brak pojedynczej księgi ruchów | 2 | ruchy PZ/WZ/MM/korekta, powód i użytkownik |
| Magazyn materiałów | 1 | logi/inwentaryzacja tylko w Agent | niepotwierdzone wdrożenie i powiązanie z App | 2 | aktywny ekran/API i uzgodnienie z zakupem/produkcją |
| Wysyłka i dokumenty logistyczne | 3 | WZ/WDT/CMR, palety, logi i PDF | brak rezerwacji/pickingu/statusu wyjątków | 4 | lista pobrań, potwierdzenie wydania, alert różnic |
| Zakupy | 1 | dokument zakupu i pozycje w App | brak pełnego PO→dostawa→PZ | 2 | zapotrzebowanie, zamówienie, potwierdzenie dostawcy, przyjęcie |
| Reklamacje | 2 | rejestr, dokumenty, komunikacja | brak SLA i spójnych korekt | 3 | statusy, właściciel, termin, decyzja i kompensacja |
| Finanse/koszty | 3 | rozbudowany kalkulator okresowy i alokacje | brak potwierdzonej historii danych źródłowych | 4 | zamknięcie okresu, snapshot i kontrola odchyleń |
| Raportowanie | 2 | raporty sprzedaży/marż/działów | brak wiarygodności historycznej i działań z raportu | 3 | definicje KPI, drill-down, oznaczenie jakości danych |
| Pracownicy | 1 | pracownik produkcji; kompetencje/grafik w Agent | brak potwierdzonego obiegu z produkcją | 2 | przypisania do zleceń, zadania/kompetencje, wykonanie |
| Komunikacja/integracje | 2 | e-mail, outbox, workery, sync cenników | brak konsoli błędów i potwierdzonego runtime Agent | 3 | inbox błędów, retry, właściciel i historia dostarczenia |
| Administracja | 2 | użytkownicy/słowniki/języki | ustawienia procesów i uprawnienia nie są pełnym self-service | 3 | RBAC, szablony, reguły alertów, bezpieczne korekty |
