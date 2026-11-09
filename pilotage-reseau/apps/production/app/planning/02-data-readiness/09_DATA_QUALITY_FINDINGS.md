# Ustalenia jakości danych

## Zakres wykonanych kontroli

Wykonano kontrole statyczne kodu, zapytań i wcześniejszych wyników audytu. Nie wykonano zapytań na rekordach: klient MySQL i PHP są niedostępne, a konfiguracja nie została potwierdzona jako bezpieczna kopia read-only. Wszystkie pozycje oznaczone `DO_WERYFIKACJI_RUNTIME` są ryzykami, nie stwierdzonymi wadami konkretnych rekordów.

## Ustalenia potwierdzone statycznie

| ID | Ustalenie | Skutek | Priorytet |
|---|---|---|---|
| DQ-01 | brak bazowego schematu i runnera migracji; migracje są deltami SQL/MD z powtarzanymi numerami | nie można potwierdzić identycznych kolumn, constraintów i indeksów między środowiskami | P0 |
| DQ-02 | brak jednej warstwy faktów sprzedaży | POS, zamówienie, WDT, faktura, płatność i korekta mogą dawać różne wyniki | P0 |
| DQ-03 | współistnienie `carton_stock` i `material_inventory*` bez dowodu uzgodnienia | stan i ruch mogą być niespójne | P0 |
| DQ-04 | brak potwierdzonego procesu wykonania produkcji | KPI wykonania, odpadu i kosztu rzeczywistego są niemożliwe | P0 |
| DQ-05 | brak systemowych snapshotów receptury, kosztu, VAT i wymiarów | raport historyczny może zmieniać wynik po edycji kartoteki | P0 |
| DQ-06 | punktowe changelogi i aktualizacje w miejscu | korekty i usunięcia mogą nie być kompletne w analizach | P0 |
| DQ-07 | brak pełnych constraintów/status machine potwierdzonych schematem | nieznane statusy, duplikaty i orphan records mogą nie być blokowane | P1 |
| DQ-08 | rozproszone `id_user`/`id_employee`, brak globalnego audytu aktora | niepewna atrybucja operacji i korekt | P1 |
| DQ-09 | brak jawnej polityki timezone; kod grupuje po `DATE()`/`HOUR()` i miejscami dodaje offset | granice dnia i sprzedaż godzinowa mogą być błędne | P1 |
| DQ-10 | pojedynczy kurs EUR bez potwierdzonej historii | wyniki walutowe nie są odtwarzalne | P1 |
| DQ-11 | kalkulacja receptury zastępuje niepoprawną wydajność wartością 1 i ujemną cenę zerem | brak danych może zostać ukryty jako pozornie poprawny koszt | P1 |
| DQ-12 | wiele outboxów/workerów bez globalnego monitoringu i potwierdzonych unique keys | retry może powodować duplikat albo cichą utratę | P1 |

## Testy danych oczekujące na wykonanie

| Test | Status | Wymagany wynik |
|---|---|---|
| null w kluczach biznesowych i datach | DO_WERYFIKACJI_RUNTIME | zero null w polach obowiązkowych albo udokumentowany wyjątek |
| duplikaty SKU, numerów dokumentów, kluczy integracyjnych | DO_WERYFIKACJI_RUNTIME | zero duplikatów w zadanym zakresie tenant/typ/rok |
| osierocone pozycje | DO_WERYFIKACJI_RUNTIME | zero pozycji bez nagłówka i referencji domenowej |
| nieznane statusy | DO_WERYFIKACJI_RUNTIME | każdy status należy do zatwierdzonego słownika/maszyny |
| ceny zerowe i ujemne | DO_WERYFIKACJI_RUNTIME | wyłącznie jawnie dozwolone przypadki, nigdy brak danych jako zero |
| ilości ujemne | DO_WERYFIKACJI_RUNTIME | tylko zdarzenia o semantyce signed quantity; brak ujemnych wartości w polach absolutnych |
| sumy nagłówek–pozycje | DO_WERYFIKACJI_RUNTIME | różnica w tolerancji zaokrąglenia i zgodna waluta/VAT |
| rekordy bez daty/autora | DO_WERYFIKACJI_RUNTIME | zero dla zdarzeń krytycznych |
| rozbieżność saldo–ruchy | BLOCKED_BY_MODEL | dokładne uzgodnienie per produkt/magazyn/partia/jednostka |
| modyfikacje po zamknięciu okresu | BLOCKED_BY_PROCESS | brak lub jawne reopen/correction z audytem |

## Kryteria wiarygodności próbki

Testy należy uruchomić co najmniej dla pełnego zamkniętego miesiąca, dnia granicznego zmiany czasu, zamówienia częściowego, sprzedaży ze zwrotem, korekty faktury, dostawy częściowej, inwentaryzacji, retry integracji oraz — po powstaniu procesu — partii produkcyjnej ze stratą. Wyniki muszą zawierać licznik badanych rekordów i licznik naruszeń; samo `0 rows` bez informacji o populacji nie jest dowodem jakości.
