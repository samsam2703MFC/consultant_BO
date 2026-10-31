# Macierz gotowości KPI

## Znaczenie statusów

- `AVAILABLE` — definicja, fakty, historia, korekty i jakość zostały potwierdzone.
- `PARTIAL` — część źródeł istnieje, lecz zakres lub semantyka jest ograniczona.
- `UNRELIABLE` — KPI da się policzyć, ale wynik może prowadzić do błędnych wniosków.
- `MISSING_DATA` — proces może istnieć, ale nie zapisuje wymaganych pól.
- `MISSING_PROCESS` — brak procesu tworzącego wiarygodny fakt.
- `BLOCKED` — model potencjalnie istnieje, ale runtime/dane są niedostępne do weryfikacji.

W obecnym audycie żaden KPI nie otrzymuje `AVAILABLE`, ponieważ nie wykonano profilu danych ani uzgodnienia z bazą.

## Sprzedaż

| KPI | Status | Podstawa i ograniczenie |
|---|---|---|
| przychód netto | PARTIAL | `document_wdt.total_net`/pozycje; WDT nie jest potwierdzonym faktem sprzedaży i brak pełnych korekt |
| przychód brutto | UNRELIABLE | brak potwierdzonego VAT per pozycja i jednolitego źródła POS/B2B |
| liczba transakcji | BLOCKED | `transaction` istnieje w niepotwierdzonym Agent |
| średni koszyk | BLOCKED | zależy od kompletnego, aktywnego modelu transakcji i zwrotów |
| sprzedaż produktu | PARTIAL | pozycje WDT pozwalają analizować wydania B2B |
| sprzedaż kategorii | PARTIAL | bieżąca kategoria może przepisać historię |
| dynamika okresowa | PARTIAL | zapytania trendów istnieją, lecz porównują nieuzgodnione fakty |
| sprzedaż godzinowa | BLOCKED | timestamp POS w Agent; runtime niepotwierdzony |
| sprzedaż per sklep | BLOCKED | `id_shop` w Agent; App nie daje pełnego wspólnego wymiaru |
| sprzedaż per kanał | MISSING_DATA | brak kanonicznego snapshotu kanału |
| sprzedaż per klient | PARTIAL | zamówienia/WDT mają klienta B2B; POS i korekty nieuzgodnione |
| sprzedaż per pracownik | BLOCKED | `id_employee` w Agent bez potwierdzonego deploymentu i indywidualności kont |
| efekt promocji | MISSING_DATA | brak promocji, ceny bazowej i kosztu rabatu w fakcie sprzedaży |

## Magazyn

| KPI | Status | Podstawa i ograniczenie |
|---|---|---|
| stan aktualny | UNRELIABLE | `carton_stock` i Agent inventory bez potwierdzonego uzgodnienia |
| stan historyczny | MISSING_PROCESS | brak jednej kompletnej, niezmiennej księgi ruchów |
| wartość zapasu | UNRELIABLE | brak historii ilości i wiążącej metody kosztowej |
| rotacja | BLOCKED | wymaga wiarygodnych wydań i średniego stanu historycznego |
| dni pokrycia | MISSING_DATA | brak wiarygodnego ATP/stanu i zatwierdzonej prognozy |
| ujemne stany | BLOCKED | wymaga odczytu rzeczywistych sald i polityki ujemnego stanu |
| przeterminowania | MISSING_DATA | brak potwierdzonych partii i dat ważności |
| różnice inwentaryzacyjne | BLOCKED | model Agent istnieje, lecz brak danych i skutku w ledgerze |
| straty magazynowe | MISSING_PROCESS | brak kanonicznego zdarzenia straty z przyczyną |

## Produkcja

| KPI | Status | Podstawa i ograniczenie |
|---|---|---|
| wykonanie planu | MISSING_DATA | brak ilości wykonanej/zaakceptowanej |
| wydajność | MISSING_PROCESS | brak wykonania i czasu operacji |
| odpad | MISSING_PROCESS | brak zdarzeń odpadu i accepted output |
| odchylenie recepturowe | MISSING_PROCESS | brak rzeczywistego zużycia i wersji receptury |
| koszt partii | MISSING_PROCESS | brak partii i ledgera kosztów zlecenia |
| koszt produktu | UNRELIABLE | istnieje kalkulacja normatywna bez gwarancji historyczności |
| produkcja na godzinę | MISSING_DATA | brak ilości wykonanej i czasu |
| produkcja na zmianę | MISSING_DATA | brak shift ID na zdarzeniu wykonania |

## Pracownicy

| KPI | Status | Podstawa i ograniczenie |
|---|---|---|
| czas pracy | MISSING_DATA | grafik nie jest czasem rzeczywistym |
| koszt pracy | PARTIAL | miesięczny koszt pracownika/sekcji bez czasu operacji |
| sprzedaż na roboczogodzinę | MISSING_DATA | brak czasu rzeczywistego i uzgodnionej sprzedaży |
| produkcja na roboczogodzinę | MISSING_PROCESS | brak wykonania produkcji i czasu zlecenia |
| liczba operacji | UNRELIABLE | brak globalnego audytu wykonawcy |
| liczba korekt | UNRELIABLE | brak jednolitej tabeli korekt i kompletnego aktora |
| liczba błędów | MISSING_PROCESS | brak wspólnej definicji i rejestru błędu |

## Finanse i rentowność

| KPI | Status | Podstawa i ograniczenie |
|---|---|---|
| marża brutto | UNRELIABLE | brak wspólnego faktu przychodu i kosztu historycznego |
| marża produktu | UNRELIABLE | bieżąca receptura/cena materiału może zmieniać przeszłość |
| rentowność produktu | UNRELIABLE | niepełne koszty pośrednie, strat i korekt |
| rentowność sklepu | MISSING_DATA | brak kompletnego przychodu i kosztów przypisanych do sklepu |
| rentowność klienta | MISSING_DATA | brak pełnego kosztu obsługi/dostawy/promocji/zwrotów |
| koszt strat | MISSING_PROCESS | brak ilościowego zdarzenia straty |
| koszt promocji | MISSING_DATA | brak snapshotu rabatu i kosztu kampanii |
| koszt dostawy | PARTIAL | koszty logistyczne miesięczne, brak pełnego przypisania do dostawy |
| koszt zamówienia | MISSING_DATA | brak związania materiału, pracy, opakowania, transportu i korekt z zamówieniem |

## Warunek ponownej klasyfikacji

Status może zostać podniesiony dopiero po zatwierdzeniu definicji biznesowej, wskazaniu jednego faktu źródłowego, potwierdzeniu historii i korekt, przejściu testów jakości oraz uzgodnieniu wyniku z dokumentem kontrolnym. Samo pojawienie się kolumny lub wykresu nie zmienia statusu.
