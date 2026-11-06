# Funkcje pozornie kompletne

| ID | Funkcja wyglądająca na kompletną | Co daje wrażenie kompletności | Ukryty brak | Skutek | Powiązane luki |
|---|---|---|---|---|---|
| FC-01 | CRUD użytkowników | trasy i widoki | nieskuteczne logowanie i brak ochrony części operacji | krytyczne ryzyko bezpieczeństwa | GAP-001–003 |
| FC-02 | produkty | rozbudowany CRUD/wyszukiwanie | brak historii istotnych pól i stabilnej klasyfikacji | raporty zmieniają przeszłość | GAP-007/048 |
| FC-03 | materiały | kartoteka, ceny, alergeny | brak partii, ledgera i pewnych przeliczników | ilości/koszty nieuzgadnialne | GAP-008/032/036 |
| FC-04 | dostawcy | CRUD i widoki | brak approval, lifecycle i source-based score | brak kontroli źródła zakupu | GAP-009/039 |
| FC-05 | receptury | UI składu, półprodukty i kroki | brak wersji, dat obowiązywania i snapshotu | niewiarygodna produkcja/koszt | GAP-011–013 |
| FC-06 | kalkulacja receptury | wynik kosztu i spłaszczenie | bieżące ceny/graf, TODO zapisu, wartości zastępcze | pozornie poprawny koszt | GAP-013/048 |
| FC-07 | cenniki | UI, eksporty i ceny klienta | brak pełnego validity/history/VAT snapshot | wynik historyczny się zmienia | GAP-014/048 |
| FC-08 | zamówienia B2B | rozbudowane ekrany i dokumenty | brak jawnej maszyny stanu, partial/cancel/correction E2E | ręczne statusy i niespójna realizacja | GAP-017 |
| FC-09 | changelog zamówienia | osobny widok historii | migracja/deployment i kompletność wszystkich mutacji niepotwierdzone | fałszywe poczucie audytu | GAP-017/049/053 |
| FC-10 | sprzedaż POS | wiele kontrolerów/repozytoriów Agent | brak entrypointu i dowodu użycia przez aktualny frontend | możliwy martwy system | GAP-018 |
| FC-11 | promocje/zwroty POS | nazwy modułów i kontrolery | brak kanonicznego faktu i pełnej kompensacji | błędny przychód/rabat | GAP-015/020 |
| FC-12 | faktury | UI i dwie rodziny tabel | dwie implementacje, brak jednego source of truth | duplikaty/różne semantyki | GAP-021/022 |
| FC-13 | WDT/WZ/CMR | dokumenty i PDF | brak dowodu dokładnie jednego ruchu stock | dokument nie gwarantuje stanu | GAP-023/032 |
| FC-14 | proforma/outbox | generowanie i kolejka | brak konsoli retry, alertu i E2E | ciche niedostarczenie | GAP-024/050/051 |
| FC-15 | wysyłka | planowanie, palety, logi | rozproszone statusy, brak pełnego POD/return/cost | niewyjaśnialna realizacja | GAP-041 |
| FC-16 | reklamacje | UI, załączniki i dokumenty | brak kompensacji stock/payment/cost | reklamacja nie zamyka skutków | GAP-020/038 |
| FC-17 | lista produkcyjna | raport/PDF i agregacja potrzeb | lista nie jest zleceniem ani wykonaniem | plan może być raportowany jako actual | GAP-026/027 |
| FC-18 | zapotrzebowanie materiałowe | wyliczenie z receptur | brak rezerwacji, wersji receptury i rozchodu | brak kontroli materiałów | GAP-011/026/033 |
| FC-19 | stan kartonów | ekran bieżących ilości | status obiektu bez kompletnego ledgera/as-of | brak wyjaśnialności zmiany | GAP-032/036 |
| FC-20 | inventory materiałów Agent | saldo, log, powody, stocktake w kodzie | brak aktywnego deploymentu i reconciliation | model nie może być uznany za source of truth | GAP-018/032/035 |
| FC-21 | zakup | dokument zakupu App i bogaty Agent | brak potwierdzonego jednego PO→receipt→claim | niespójna ilość/cena | GAP-037/038 |
| FC-22 | pracownicy/kompetencje | liczne modele i ekrany | user≠employee, brak historycznego assignmentu | niewiarygodny aktor | GAP-042 |
| FC-23 | grafik | daty/godziny pracownika w Agent | plan nie jest rzeczywistym czasem | fałszywe roboczogodziny | GAP-043 |
| FC-24 | kalkulator kosztów miesięcznych | UI, koszty sekcji, mediów, pracy, logistyki | brak ledgera, close, wersji metody i kosztu partii | niewiarygodna marża | GAP-045–047 |
| FC-25 | dashboard sprzedaży | wykresy i trendy WDT | WDT nie jest pełną sprzedażą; korekty i POS nieuzgodnione | błędne decyzje handlowe | GAP-016/048/055 |
| FC-26 | dashboard rentowności | połączenie WDT, receptury i kosztów | brak snapshotu kosztu i reprodukcji okresu | zmienna rentowność historyczna | GAP-013/045–048 |
| FC-27 | outbox/workery | wiele kolejek i skryptów | brak wspólnej idempotencji, DLQ UI, schedulera i trace | duplikat/utrata | GAP-050–053 |
| FC-28 | audyt | lokalne changelogi/logi | brak globalnego, niezmiennego audit event | brak dowodu kto/co/dlaczego | GAP-053 |

## Reguła oceny

Funkcja nie jest kompletna, jeśli istnieje ekran lub kontroler, ale nie ma dowodu statusów, wyjątku, anulowania, korekty, audytu, uprawnień, historii, raportowania i działania E2E. Zgodnie z baseline F3 oznacza jedynie bogaty happy path, nie produkcyjną kompletność.
