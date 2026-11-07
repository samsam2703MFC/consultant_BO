# Implementacja: niezmienne proformy i rozliczenie różnic

## Zrealizowany model biznesowy

- `document_proforma_product` jest istniejącym, kompletnym snapshotem pozycji proformy. PDF proformy nadal odczytuje wyłącznie te pozycje.
- Po uzyskaniu statusu `paid` proforma jest zamrożona. Edycja ilości lub cen w `customer_order_product` jest nadal możliwa zgodnie z obecnym procesem, ale nie może synchronizować pozycji opłaconej proformy.
- Proforma bez statusu `paid` zachowuje obecne zachowanie: edycja zamówienia odtwarza jej pozycje z aktualnego zamówienia.
- Pracownik oznacza proformę jako opłaconą z listy `/proforma-documents` albo bezpośrednio z `/client-orders`. Przycisk wymaga świadomego potwierdzenia i wywołuje `ProformaService::markPaid(int $id)` w transakcji. Metoda jest idempotentna i wymaga istniejących pozycji proformy.
- Nowy dokument różnic porównuje sumę pozycji z opłaconych proform przypisanych do danego WDT z faktycznymi pozycjami `document_wdt_product`.
- Proformy nieopłacone, anulowane i bez statusu `paid` nie zwiększają stanu opłaconego. Nie ma automatycznej realokacji płatności pomiędzy produktami.
- Domówienia są objęte przez istniejące grupowanie wysyłki: wszystkie zamówienia przypisane do tego samego `customer_order.id_wdt` są analizowane raz. Nie wprowadzono nowego modelu domówień ani relacji rodzic–dziecko.

## Zmiany techniczne

| Element | Zmiana |
| --- | --- |
| `OrderService::updateOrderWithProducts()` | Zatrzymuje synchronizację pozycji proformy, gdy `ProformaSnapshotPolicy::isPaid()` zwraca `true`. Zamówienie pozostaje edytowalne. |
| `ProformaService` i `ProformaController` | Dodano `markPaid()`, endpoint `POST /ajax/proforma-documents/{id}/paid`, status oraz przycisk potwierdzenia na liście proform; aktualizacja opłaconej proformy jest odrzucana. |
| `ProformaSnapshotPolicy` | Jedno źródło definicji statusu opłaconego, z normalizacją wielkości liter i białych znaków. |
| `DifferenceSettlementService` | Buduje wiersze dokumentu różnic z opłaconych snapshotów proform oraz WDT. |
| `OrderShippingDifferenceProductRepository` | Pobiera opłacone pozycje proform i pozycje WDT osobno, a następnie zapisuje wynik rozliczenia. Stara metoda oparta o żywe pozycje zamówień została zastąpiona. |
| `OrderShippingDifferenceService` / `TCPDFService` | Nadal używają tego samego layoutu PDF; podsumowanie wartości wykorzystuje zapisane sumy w centach. |
| `OrderShippingDifferenceRepository` | Lista przypomnień korzysta z zapisanych sum, z bezpiecznym fallbackiem dla dokumentów historycznych. |

### Migracja

Plik [032_proforma_snapshot_difference_amounts.sql](</home/szymon/projects/apps/slfood_prod/app/migrations/032_proforma_snapshot_difference_amounts.sql>) dodaje nullable pola:

- `ordered_total_amount DECIMAL(14,2)`;
- `invoiced_total_amount DECIMAL(14,2)`.

Są wypełniane wyłącznie dla nowych dokumentów różnic. Pozwalają zachować dokładne saldo, również gdy kilka opłaconych proform tego samego produktu ma różne ceny. Dla starych dokumentów odczyt stosuje poprzednie pola ilość × cena.

Migracja nie odtwarza historycznych opłaconych proform. Ich pierwotnego składu nie można wiarygodnie ustalić z obecnego stanu zamówienia, więc nie wolno nadpisywać pozycji udawaną historią. Ewentualne wcześniejsze przypadki wymagają kontroli ręcznej.

## Źródła danych różnic

1. **Stan opłacony:** `customer_order.id_wdt` → `customer_order.id_proforma` → `document_proforma.status = 'paid'` → `document_proforma_product`.
2. **Stan finalnie wydany:** `document_wdt_product` danego WDT.
3. **Kwota różnicy:** `invoiced_total_amount - ordered_total_amount`, liczona jako liczba centów w PHP dla PDF. Ujemna wartość oznacza zwrot, dodatnia dopłatę.

Produkt jest agregowany według stabilnego `id_product`, jak w istniejącym dokumencie. Przy wielu opłaconych cenach zapisana suma jest autorytatywna; prezentowana cena jest średnią ważoną, dlatego nie służy do obliczania salda.

## Testy

Wykonane polecenia:

```bash
php -l App/Services/Document/DifferenceAmountCalculator.php
php -l App/Services/Document/DifferenceSettlementService.php
php -l App/Services/Document/ProformaSnapshotPolicy.php
php -l App/Services/Client/Order/OrderService.php
php -l App/Services/Document/ProformaService.php
php -l App/Repositories/Shipping/Difference/OrderShippingDifferenceProductRepository.php
php -l App/Repositories/Shipping/Difference/OrderShippingDifferenceRepository.php
php -l App/Services/Shipping/ShippingService.php
php -l App/Services/Document/OrderShippingDifferenceService.php
php -l App/Services/Utils/TCPDFService.php
php tests/proforma_difference_contract_test.php
```

Wynik: wszystkie sprawdzenia składni oraz 11 asercji testu biznesowego przeszły. Test obejmuje status `paid`, statusy nieopłacone/anulowane, zwrot 35,00, dopłatę 45,00, saldo zerowe oraz scenariusz różnych cen opłaconych proform.

Nie uruchamiano migracji ani testu integracyjnego na skonfigurowanej bazie produkcyjnej. Repozytorium nie zawiera frameworka testowego ani izolowanej konfiguracji bazy testowej.

## Elementy niezmienione

- Layout, tekst i numeracja PDF proformy.
- Layout, nagłówki i układ tabeli PDF dokumentu różnic.
- Tworzenie oraz układ dokumentów WDT/WZ/CMR.
- Istniejąca obsługa nieopłaconych proform i domówień poza opisanym źródłem danych rozliczenia.

## Pozostałe ograniczenia

Oznaczenie płatności jest celowo ręczne: nie ma integracji bankowej ani automatycznego uzgadniania wpłat. Bezpośrednie aktualizacje bazy wykonane poza aplikacją mogą ominąć warstwę serwisową i nie powinny być stosowane.
