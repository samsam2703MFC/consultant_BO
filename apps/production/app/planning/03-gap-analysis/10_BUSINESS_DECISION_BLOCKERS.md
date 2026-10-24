# Blokery decyzji biznesowych

Poniższe decyzje pochodzą z projektu TO-BE. Do czasu ich rozstrzygnięcia luka może zostać opisana i technicznie zbadana, ale jej kryterium biznesowe nie jest ostateczne.

| Decyzja | Właściciel rekomendowany | Co trzeba rozstrzygnąć | Blokowane luki/procesy | Skutek braku decyzji | Rekomendacja robocza |
|---|---|---|---|---|---|
| BD-01 moment sprzedaży | finanse + sprzedaż | wydanie, dokument fiskalny czy reguła per kanał | GAP-016–023; SAL-001–005/DOC | brak jednej definicji przychodu i korekty | reguła per kanał, oddzielić sale/payment/document |
| BD-02 ujemny stan | operacje + finanse | blokada, warning czy wyjątek roli/magazynu | GAP-028/032–036; INV/PRD/SAL | nie da się zdefiniować validation i ATP | blokada, audytowany wyjątek tylko jeśli konieczny |
| BD-03 partie/ważność | jakość + operacje | które klasy wymagają lot/expiry | GAP-008/029/032/036/038; INV/PRD/PUR | nieznany zakres modelu i pracy użytkownika | reguła per klasa produktu |
| BD-04 model produkcji | planowanie + produkcja | make-to-order, stock czy hybrid per produkt | GAP-025–028/033; PLN/PRD | niejasne źródło popytu i rezerwacji | hybrid sterowany kartoteką |
| BD-05 QC i release | jakość | które materiały/wyroby trafiają do quarantine | GAP-029/030/036/038; PRD/INV/PUR | brak reguł dostępności partii | kontrola dla klas ryzyka |
| BD-06 wycena i koszt | finanse/księgowość | średnia/FIFO/standard/dual | GAP-013/032/036/040/045/047/048; COST/INV | nie można zamknąć value/cost/margin | decyzja z księgowością, zachować dane źródłowe |
| BD-07 okres i korekta wstecz | finanse/księgowość | current adjustment, reopen lub próg | GAP-020/022/031/035/045/046; COST | raporty nie mają stabilnej polityki | próg: adjustment domyślnie, kontrolowany reopen |
| BD-08 partial/backorder/substitution | sprzedaż + logistyka | polityka klient/zamówienie | GAP-017/033/041; SAL/LOG | niejasne statusy i obietnica dostawy | polityka per klient/order, zamiennik jawnie zatwierdzony |
| BD-09 progi zatwierdzeń | finanse + właściciele procesów | role i kwoty/odchylenia | GAP-003/005/006/017/020/031/035/037/046/051 | brak closure dla approvals i SoD | mała liczba progów zależnych od skutku |
| BD-10 struktura organizacyjna | zarząd + finanse + IT | spółki/zakłady/sklepy/magazyny i izolacja | GAP-003/004/010/042/048 | niemożliwy tenant model i numeracje | wspólna baza tylko przy wspólnej administracji/polityce |
| BD-11 jurysdykcje/fiskalizacja | księgowość + sprzedaż | kraje, urządzenia, Peppol/e-faktury | GAP-014–022/052 | niejasny model VAT/dokumentu/integracji | ograniczyć zakres do potwierdzonych kanałów/krajów |
| BD-12 offline POS | operacje sklepowe + IT | brak offline, kolejka lokalna czy autonomia | GAP-016/018/019/050/052 | architektura idempotencji i numeracji nieustalona | tylko kolejka ograniczona, jeśli potrzeba potwierdzona |
| BD-13 czas i prywatność | HR + legal + produkcja | obecność, czas per zlecenie czy aktywność | GAP-042–044/047/048 | nie można ustalić danych i KPI pracy | czas per zlecenie, minimalizacja danych |
| BD-14 tolerancje dostawy | zakupy + jakość + finanse | progi ilości/ceny/jakości i match faktury | GAP-037–040 | niejasny receipt/claim/approval | progi per typ rozbieżności |
| BD-15 retencja/RODO | legal + właściciele danych | okres per kategoria i anonimizacja | GAP-001/010/042/048/049/053 oraz deprecations | nie można bezpiecznie usuwać/archiwizować | retencja per kategoria/jurysdykcja |

## Minimalny zapis decyzji

Każda decyzja powinna mieć właściciela, datę, wybraną opcję, uzasadnienie, zakres organizacyjny, datę obowiązywania, procesy dotknięte i warunek ponownego przeglądu. Ustalenie w rozmowie bez zapisu nie zamyka blokera.
