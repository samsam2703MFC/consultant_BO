# Analiza przedwdrożeniowa: niezmienne proformy i różnice wysyłkowe

Data analizy: 2026-07-26.

## Aktualna architektura i przepływ

| Krok | Kod | Tabele / dane | Obserwacja |
| --- | --- | --- | --- |
| Utworzenie zamówienia | `App\Services\Client\Order\OrderService::insertNewOrder()` lub `insertOrderFromCollector()` | `customer_order`, `customer_order_product` | Pozycja zawiera `id_product`, ilość kartonów i `purchase_price`. |
| Utworzenie proformy | `App\Services\Document\ProformaService::insertFromOrder()` | `document_proforma`, `document_proforma_product` | W transakcji tworzy nagłówek i kopiuje pozycje przez `ProformaProductRepository::insertFromOrder()`. |
| PDF proformy | `ProformaService::buildDocumentFromId()` / `renderDocument()` | `document_proforma_product` | PDF już korzysta z własnych pozycji proformy, nie z żywych pozycji zamówienia. Layout nie wymaga zmiany. |
| Edycja zamówienia | `OrderService::updateOrderWithProducts()` | `customer_order_product`, `document_proforma_product` | Usuwa i odtwarza wszystkie pozycje zamówienia, po czym **bezwarunkowo usuwa i odtwarza pozycje proformy**. To niszczy historię. |
| Domówienie | `OrderService::insertNewOrder()` i `OrderSupplementPusherService` | `customer_order.order_type = SUPPLEMENT` | Typ i wysyłanie domówienia istnieją, ale nie ma relacji rodzic–dziecko między zamówieniami. Przy wysyłce grupowanie wynika obecnie z klienta i daty. |
| Finalizacja wysyłki | `ShippingService::insertNewShipping()` | `document_wdt`, `document_wdt_product`, `shipment_log`, `customer_order.id_wdt` | WDT jest źródłem finalnie wydanych pozycji; zamówieniom danego klienta i daty przypisywany jest `id_wdt`. |
| Dokument różnic | `OrderShippingDifferenceProductRepository::insertDiffProductsBasedOnWDT()` | `document_order_shipping_difference_products` | Łączy WDT z aktualnym `customer_order_product`, a nie z opłaconą proformą. To drugi błąd źródła danych. |

## Stan statusów proform

`document_proforma.status` jest rozpoznawany przez model (`issued`, `paid`), a kolumna jest dopuszczona w `ProformaRepository`. Przed wdrożeniem nie było kontrolera ani endpointu oznaczającego płatność; `markNotified()` ustawia odrębny status `notified`. Implementacja dodaje ręczne oznaczenie opłacenia z listy proform. Zasada statusu:

- rekord o statusie, który po normalizacji ma wartość `paid`, jest opłacony i niezmienny;
- każdy inny status, w tym `NULL`, `issued`, `notified` lub status anulowania, nie jest traktowany jako opłacona wpłata;
- nie dodajemy nowego endpointu ani nie zmieniamy istniejącego procesu płatności.

To wymaga podłączenia istniejącego zewnętrznego procesu płatności do metody domenowej oznaczenia proformy jako opłaconej, jeśli taki proces dziś aktualizuje bazę bezpośrednio.

## Główna przyczyna i ryzyko

1. Snapshot pozycji proformy **istnieje** i zawiera produkt, nazwę, ilość, cenę, VAT, wagę, PPC oraz wartości pozycji.
2. `OrderService::updateOrderWithProducts()` kasuje ten snapshot także dla proformy opłaconej.
3. Generator PDF proformy jest poprawnie oparty na snapshotach, lecz po edycji odczytuje już nadpisane dane.
4. Generator różnic opiera „zamówione” wartości na aktualnych pozycjach zamówienia przypisanych do WDT, więc nie widzi pierwotnej opłaconej kwoty.
5. Obecna tabela różnic nie posiada osobnych pól sumy opłaconej i sumy wydanej. Cena średnia przy kilku proformach mogłaby zniekształcić podsumowanie przez zaokrąglenie.

## Minimalny zakres zmian

1. Zachować `document_proforma_product` jako istniejący snapshot; nie tworzyć równoległego modelu.
2. W `OrderService` synchronizować pozycje proformy wyłącznie, gdy proforma nie ma statusu `paid`.
3. Dodać do `ProformaService` jednoznaczną metodę oznaczenia proformy jako opłaconej oraz blokadę jej późniejszej aktualizacji w warstwie serwisu.
4. Zmienić dane wejściowe dokumentu różnic: pozycje opłacone pobierać z `document_proforma_product` połączonego z zamówieniami przypisanymi do WDT i proformami `paid`; finalne wydanie nadal pobierać z `document_wdt_product`.
5. Dodać do wiersza różnic zapisane sumy opłaconej i wydanej wartości w `DECIMAL`, aby zachować poprawne saldo bez obliczeń na `float` i bez zmiany układu PDF.
6. Pozostawić nieopłacone i anulowane proformy poza stanem opłaconym; nie usuwać ich historii.

## Grupowanie i domówienia

Aktualna wysyłka wiąże wszystkie zamówienia klienta o tej samej dacie z jednym WDT. Obejmuje to istniejące domówienia, jeżeli zostały zaplanowane na tę samą wysyłkę. Nowa logika pobierze proformy przez `customer_order.id_wdt`, zatem proforma zamówienia głównego i opłacona proforma domówienia zostaną zsumowane dokładnie raz.

Grupowanie dokumentu różnic pozostaje na poziomie `id_product`, zgodnie z obecnym layoutem i tabelą. W przypadku wielu opłaconych proform tego samego produktu z różnymi cenami zapisujemy oddzielnie sumę ilości oraz dokładną sumę wartości; prezentowana cena jest średnią ważoną wyłącznie informacyjnie. Kwota różnicy pochodzi z zapisanych sum, nie z mnożenia zaokrąglonej ceny średniej.

## Plan migracji danych

- Przyszłe proformy: pozycje już są kopiowane podczas utworzenia; po wdrożeniu przestaną być nadpisywane po statusie `paid`.
- Nieopłacone historyczne proformy: ich bieżące pozycje zachowują dotychczasową semantykę i mogą nadal być synchronizowane przy edycji zamówienia.
- Opłacone historyczne proformy: nie ma wersjonowania ani niezależnego źródła pierwotnych danych. Nie wolno ich odtwarzać z aktualnego zamówienia. Migracja nie nadpisze ich pozycji; rekordy wymagają ręcznej weryfikacji, jeżeli były edytowane przed wdrożeniem.
- Różnice już wygenerowane pozostają bez zmian. Nowe pola wartości są nullable dla zgodności historycznej.

## Pliki przewidziane do zmiany

- `App/Services/Client/Order/OrderService.php`
- `App/Services/Document/ProformaService.php`
- `App/Repositories/Shipping/Difference/OrderShippingDifferenceProductRepository.php`
- `App/Services/Shipping/ShippingService.php`
- `App/Services/Document/OrderShippingDifferenceService.php`
- `App/Services/Utils/TCPDFService.php`
- nowy serwis kalkulacji różnic i migracja SQL
- niezależne testy PHP dla reguł snapshotu i rozliczeń.

## Plan testów i kryteria akceptacyjne

Testy obejmą: zachowanie snapshotu po edycji opłaconej proformy, zachowanie synchronizacji nieopłaconej proformy, różnicę zwrotu, dopłaty, produktową różnicę przy zerowym saldzie oraz pomijanie nieopłaconych/anulowanych proform. Ponieważ repozytorium nie ma skonfigurowanego frameworka testowego ani bezpiecznej testowej bazy, testy biznesowe będą niezależnymi testami PHP bez dostępu do danych produkcyjnych; składnia i integracja tras/klas będą sprawdzone dodatkowo przez CLI.

Nie zmieniamy layoutu PDF proformy ani dokumentu różnic, numeracji dokumentów, statusów zamówień ani sposobu tworzenia WDT.
