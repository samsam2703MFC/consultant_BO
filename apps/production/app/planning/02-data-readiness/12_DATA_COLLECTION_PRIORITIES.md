# Priorytety zbierania danych

Dokument wskazuje, które fakty trzeba zacząć utrwalać możliwie wcześnie, ponieważ późniejszy backfill będzie niemożliwy lub niewiarygodny. Nie jest roadmapą implementacji.

## P0 — zbierać od pierwszego wiarygodnego zdarzenia

| Dane | Dlaczego nie można czekać | Minimalny zapis |
|---|---|---|
| identyfikatory i czasy zdarzeń | czasu biznesowego, kolejności i duplikatu nie da się pewnie odtworzyć | event/correlation/idempotency ID, business/recorded time, timezone, source |
| aktor i zatwierdzający | wspólne konta i późniejsze zmiany zatrudnienia niszczą atrybucję | user, employee, role/assignment snapshot, approver |
| fakt sprzedaży i korekty | nie da się później jednoznacznie rozdzielić sprzedaży, wysyłki i dokumentu | pozycja, typ eventu, źródła, qty, cena, VAT, płatność, correction link |
| snapshot produktu/ceny/VAT/klienta | kartoteki będą się zmieniać | pola z MDR-02/03 i dane dokumentowe klienta |
| każdy ruch magazynowy | saldo nie odtwarza przyczyny ani historii | dokument, produkt/partia/lokalizacja, signed qty, koszt, aktor, reversal |
| wersja receptury użyta w produkcji | bieżącego składu nie można stosować do przeszłości | immutable recipe version/hash i pełny skład |
| wykonanie produkcji | plan nie dowodzi rzeczywistości | start/consume/output/waste/QC z ilością, partią i czasem |
| rzeczywisty czas pracy na zleceniu | grafik nie pozwala odtworzyć roboczogodzin | approved time, osoba, zlecenie/operacja, rola, zmiana |
| źródłowe wpisy kosztowe | późniejsza alokacja bez źródła jest arbitralna | koszt, typ, źródło, okres, obiekt kosztowy, waluta/metoda |
| zdarzenia korekty i reopen | nadpisanie usuwa powód oraz wynik pierwotny | original event, delta, powód, actor/approver, okres |
| komunikaty integracyjne | brak logu uniemożliwia wykrycie utraty i duplikatu | message/event ID, payload hash, status, attempts, external ID |

## P1 — zbierać wcześnie dla jakości i kosztu

| Dane | Uzasadnienie |
|---|---|
| partie, ważność i genealogia | wymagane dla traceability, strat i kosztu partii; historyczny backfill zwykle niemożliwy |
| decyzje kontroli jakości | accepted output i koszt odpadu zależą od decyzji w czasie procesu |
| plan i wersje planu | potrzebne do uczciwego plan-vs-actual, bez przepisywania celu po wykonaniu |
| przestoje, maszyna i przyczyna odchylenia | umożliwiają wyjaśnienie wydajności, ale nie są warunkiem pierwszego faktu produkcji |
| historia assignmentów | chroni analizy sklep/sekcja/rola po reorganizacji |
| kursy walut i źródło | konieczne przed pierwszym zdarzeniem w obcej walucie |
| koszt transportu/opakowania per dostawa | wymagany do kosztu klienta/zamówienia zamiast miesięcznej aproksymacji |
| cena bazowa i promocja | potrzebne do efektu promocji, nie tylko kwoty po rabacie |

## P2 — dane optymalizacyjne

- szczegółowe telemetrie maszyn;
- czasy mikrooperacji i lokalizacji magazynowych;
- parametry tras transportowych;
- dane predykcyjne i cechy modeli;
- zewnętrzne benchmarki.

Nie powinny opóźniać rejestracji podstawowego zdarzenia i jego snapshotu.

## Kolejność wartości dowodowej

Przy ograniczonych zasobach należy preferować: najpierw niezmienny fakt, potem korektę i audyt, następnie snapshot, dalej kontrolę jakości i uzgodnienie, a dopiero na końcu agregat. Agregat bez źródeł nie odzyska brakującej historii.
