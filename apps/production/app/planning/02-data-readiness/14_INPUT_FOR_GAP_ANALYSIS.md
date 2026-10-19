# Wejście do analizy luk danych

## Cel

Dokument przekazuje do analizy luk wymagania TO-BE, bieżący poziom dowodu i kryteria zamknięcia. Nie jest finalną oceną implementacyjną ani roadmapą.

## Rejestr luk przekrojowych

| Gap ID | Wymóg TO-BE | Stan AS-IS | Dowód do pozyskania | Kryterium zamknięcia |
|---|---|---|---|---|
| DG-01 | jeden kanoniczny fakt sprzedaży | wiele źródeł, brak uzgodnienia | dane POS/WDT/order/invoice/payment/correction dla wspólnej próbki | każda sprzedaż i korekta ma jedno ziarno, relacje i uzgodnione sumy |
| DG-02 | niezmienny stock ledger | `carton_stock` i niepotwierdzony Agent inventory | pełny schemat, rekordy ruchów, saldo i dokumenty | równanie stanu zamyka się per produkt/magazyn/partia |
| DG-03 | stan historyczny | brak kompletnej osi ruchów | saldo otwarcia i ruchy wybranego miesiąca | stan dowolnego dnia od daty kontrolnej jest odtwarzalny |
| DG-04 | fakt wykonania produkcji | potwierdzony plan, brak wykonania | zlecenie, zużycie, output, waste, QC, czas | zamkniętą partię można prześledzić i rozliczyć |
| DG-05 | wersja receptury na zdarzeniu | bieżąca receptura/projekcja | schemat i próbki changelogów | każde zlecenie wskazuje niezmienną wersję |
| DG-06 | snapshot ceny/VAT/kosztu/wymiarów | punktowe snapshoty | próbki dokumentów i zapytań raportowych | zmiana kartoteki nie zmienia opublikowanego raportu |
| DG-07 | korekta kompensująca | mieszane update/changelog/correction | zwrot, korekta faktury, anulowanie i retry | oryginał + korekta są widoczne, powiązane i sumowane bez duplikatu |
| DG-08 | pełny aktor i approver | rozproszone pola | profil null i mapowanie user–employee | każda operacja krytyczna ma wykonawcę i wymagane zatwierdzenie |
| DG-09 | rzeczywisty czas pracy | grafik/koszt miesięczny | dane RCP/time entry | zatwierdzone roboczogodziny z korektami i assignmentem |
| DG-10 | ledger kosztów i metoda | miesięczne koszty oraz kalkulacje | wszystkie `cost_*`, ceny zakupowe i dokument metodologii | koszt ma źródło, okres, obiekt, wersję i adjustment |
| DG-11 | historia walut i promocji | brak pełnych wymiarów | konfiguracja walut/promocji i dane sprzedaży | każde istotne zdarzenie ma kurs oraz promocję/cenę bazową |
| DG-12 | zamknięcie okresu | niepotwierdzone | statusy okresu i rekordy zmienione po close | raport ma wersję/hash; korekta wymaga adjustment/reopen |
| DG-13 | kontrolowana retencja i brak hard delete faktów | niepotwierdzone | schemat, kod delete, audit/binlog | zatwierdzone fakty są nieusuwalne; dane osobowe mają politykę |
| DG-14 | jakość integracji | wiele outboxów | unique keys, retry logs, payload hashes | powtórzenie nie tworzy drugiego skutku i jest obserwowalne |

## Mapowanie do procesów TO-BE

| Obszar danych | Procesy TO-BE | Główne wymagania danych |
|---|---|---|
| sprzedaż i dokumenty | SAL-001–005, DOC-001–002 | MDR-01–04, MDR-14–17 |
| magazyn | INV-001–005 | MDR-05–06, MDR-15–17 |
| produkcja | PLN-001–002, PRD-001–004, REC-001–002 | MDR-06–09, MDR-13, MDR-15 |
| zakupy i dostawy | PUR-001–004, LOG-001 | MDR-02–06, MDR-10, MDR-16 |
| pracownicy | HR-001–004 | MDR-12–13, MDR-18 |
| koszty i raporty | COST-001–003 | MDR-04, MDR-10–11, MDR-15, MDR-17 |
| administracja | ADM-001–004 | MDR-15–18, audyt i retencja |

## Pakiet dowodowy potrzebny w następnym etapie

1. eksport metadanych `information_schema` bez danych wrażliwych;
2. dokładne liczności i zakresy dat głównych tabel;
3. wyniki kontroli z `13_DIAGNOSTIC_QUERIES.md` z populacją i naruszeniami;
4. zanonimizowane ścieżki jednego zamówienia, sprzedaży/zwrotu, dostawy, inwentaryzacji i korekty;
5. dokumentacja słowników statusów i ich znaczeń;
6. potwierdzenie aktywnego lub nieaktywnego runtime `Agent/api_app_dir`;
7. definicje biznesowe przychodu, kosztu, partii, czasu pracy i zamknięcia okresu;
8. wskazanie właściciela każdego źródła i KPI.

## Bramy decyzyjne

Analiza luk zależy od decyzji TO-BE BD-01 (moment sprzedaży), BD-02–05 (stan, partie, produkcja/QC), BD-06–07 (wycena i okres), BD-10–13 (struktura, fiskalizacja, offline i czas) oraz BD-15 (retencja). Bez tych decyzji można potwierdzić istnienie danych, ale nie ich prawidłową semantykę.

## Kryterium gotowości do właściwej gap analysis

Materiał jest gotowy, gdy wszystkie tabele źródłowe mają potwierdzony schemat i właściciela, krytyczne zapytania mają wynik, źródła Agent mają potwierdzony status wdrożenia, a każdy KPI ma zatwierdzoną definicję, ziarno, filtry korekt i kryterium uzgodnienia.
