# Wymagania brakujących danych

## Zasada

Poniższe wymagania opisują dane konieczne do wiarygodnego procesu i KPI. Nie przesądzają technologii ani kolejności implementacji. Pola faktów powinny być niezmienne; korekta tworzy powiązane zdarzenie kompensujące.

| ID | Brakujący zbiór / ziarno | Minimalne pola | Proces tworzący i korygujący | Odblokowane analizy | Backfill |
|---|---|---|---|---|---|
| MDR-01 | kanoniczna pozycja sprzedaży / jedna pozycja jednego zdarzenia | event, typ sale/return/correction, order/shipment/POS/invoice/payment IDs, produkt, ilość, cena, rabat, VAT, waluta/kurs, sklep, kanał, klient, pracownik, czasy | finalizacja sprzedaży; korekta/zwrot kompensuje | przychód, koszyk, kanał, klient, produkt | częściowy z WDT/POS; bez pełnych korekt nie oznaczać jako complete |
| MDR-02 | snapshot produktu na zdarzeniu | nazwa, SKU, kategoria, jednostka/przelicznik, opakowanie, status podatkowy | sprzedaż/zakup/ruch/produkcja; korekta nie zmienia snapshotu oryginału | stabilne raporty produktu/kategorii | częściowy z dokumentów; reszta tylko best-effort z datowanych źródeł |
| MDR-03 | snapshot ceny i podatku | cena bazowa, końcowa, rabat, VAT, brutto/netto, źródło cennika | pricing + finalizacja; korekta osobnym eventem | netto/brutto/VAT/promocje | częściowy; brakującej stawki nie zgadywać |
| MDR-04 | historyczny kurs waluty | para, kurs, typ, źródło, valid_from/to, pobranie | import kursu; korekta jako nowa wersja | raport walutowy i rentowność | możliwy z oficjalnego źródła, jeśli znana data biznesowa |
| MDR-05 | księga ruchów / jeden zaksięgowany ruch | źródło i linia, typ, produkt, magazyn/lokalizacja/partia, signed quantity, jednostka bazowa, czas, aktor, koszt, idempotency | przyjęcie/wydanie/produkcja/transfer/strata/inwentaryzacja; reversal | stan as-of, rotacja, wartość, straty | ograniczony; saldo początkowe wymaga kontrolowanej migawki |
| MDR-06 | partia i genealogia | lot, produkt, źródło, daty produkcji/ważności, status QC, parent lots | zakup/produkcja/QC/recall | przeterminowania, traceability, koszt partii | zwykle niemożliwy wiarygodnie bez historycznych etykiet/dokumentów |
| MDR-07 | zlecenie produkcyjne / zlecenie | numer, produkt, ilość planowana, zakład, daty, recipe_version, status | plan/release/close/correct | plan vs wykonanie, WIP | brak dla okresów bez zleceń; nie rekonstruować z samej listy planu jako faktu |
| MDR-08 | wykonanie produkcji / jedno zdarzenie operacji | order, operation, typ start/pause/consume/output/waste/QC, ilość, lot, czas, osoba, zmiana, maszyna | operator/QC; korekta różnicowa | wydajność, odpad, zużycie rzeczywiste | zasadniczo niemożliwy bez pierwotnych zapisów |
| MDR-09 | wersja receptury / wersja | skład, wydajność, jednostki, valid_from/to, approval, hash | aktywacja receptury; nowa wersja | odchylenie recepturowe, reprodukcja kosztu | tylko jeśli istnieją historyczne eksporty/changelogi; inaczej od dnia wdrożenia |
| MDR-10 | ledger kosztów / wpis kosztowy | typ, źródło, kwota, waluta, okres, centrum, produkt/partia/sklep/klient, metoda, status | zakup, praca, media, transport, strata, close; adjustment | koszt produktu/partii/sklepu/klienta | częściowy z `cost_*`; alokacje oznaczyć jako estimated |
| MDR-11 | snapshot kosztu sprzedaży | jednostkowy koszt, metoda, wersja kalkulacji, komponenty/trace | finalizacja sprzedaży albo kontrolowane zamknięcie | marża i rentowność historyczna | ostrożny backfill per zamknięty okres, oznaczony jako reconstructed |
| MDR-12 | rzeczywisty czas pracy / wpis czasu | pracownik, lokalizacja, rola, start/end, przerwy, źródło, approval, correction | RCP/time entry; korekta różnicowa | roboczogodziny, koszt pracy | nie rekonstruować z grafiku jako actual |
| MDR-13 | czas produkcyjny / osoba-zlecenie-okres | employee, order/operation, start/end lub minutes, shift, role | raport wykonania; approval/correction | produkcja na roboczogodzinę, koszt partii | brak wiarygodnego backfillu bez kart pracy |
| MDR-14 | promocja na pozycji sprzedaży | promotion, rule version, base price, discount, funded_by, campaign cost | pricing/checkout; zwrot odwraca wykorzystanie | efekt i koszt promocji | tylko gdy istnieją logi/kody źródłowe |
| MDR-15 | korekta / jedno zdarzenie korekty | original event, typ, powód, delta qty/value/tax, business time, actor/approver, period | wszystkie procesy korekcyjne | wszystkie KPI netto po korektach | częściowy z changelogów i correction tables; braków nie inferować |
| MDR-16 | zdarzenie integracji / jedna wiadomość | event/idempotency/correlation IDs, source/target, type/version, attempts, status, timestamps, payload hash, external ID | outbox/inbox; manual retry | kompletność i deduplikacja danych | częściowy z istniejących outboxów |
| MDR-17 | okres raportowy i publikacja | period, status, close/reopen times, approvers, calculation version, report hash | close/reopen | stabilne raporty i korekty po zamknięciu | od dnia wdrożenia; stare okresy oznaczyć ungoverned |
| MDR-18 | historyczny wymiar organizacyjny | employee/product/customer/supplier assignment, valid_from/to | zmiana kartoteki | poprawna atrybucja do sklepu/sekcji/roli | częściowy tylko z changelogów i dokumentów |

## Zasady braków i estymacji

- brak wartości finansowej nie może być zamieniany na zero;
- brak wersji receptury nie może być zastępowany wersją bieżącą bez flagi `reconstructed`;
- grafik nie może zastępować czasu rzeczywistego;
- WDT nie może automatycznie zastępować faktu sprzedaży we wszystkich kanałach;
- saldo bieżące bez ledgera może być jedynie bilansem otwarcia od kontrolowanej daty;
- każdy backfill musi mieć metodę, autora, czas, źródło, jakość i flagę odróżniającą go od danych pierwotnych.
