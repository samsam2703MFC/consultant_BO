# Analiza przepływu edycji zamówienia po wystawieniu proformy

## Stan zastany

| Element | Implementacja | Wniosek |
| --- | --- | --- |
| Zamówienie i pozycje | `customer_order`, `customer_order_product`, `OrderService` | Edycja zastępuje komplet pozycji zamówienia w jednej transakcji. |
| Proforma i snapshot | `document_proforma`, `document_proforma_product`, `ProformaService::insertFromOrder()` | Pozycje dokumentu są już własnym snapshotem i są źródłem PDF. |
| Statusy | `document_proforma.status`; używane `issued`, `paid` | `paid` jest już blokowany przez `ProformaSnapshotPolicy`. Potrzebny jest status anulowania dla zastąpionej, nieopłaconej proformy. |
| Edycja | `OrderController::editOrder()` → `OrderService::updateOrderWithProducts()` | Aktualna implementacja synchronizuje snapshot nieopłaconej proformy; to trzeba usunąć. |
| Przegląd | `OrderController::overview()` → `OrderService::getPending()` → `client/order/overview.view.php` | Lista ma już status proformy i akcję oznaczenia jako opłaconej. Brakuje wyliczenia nieaktualności oraz regeneracji. |
| Domówienia | `customer_order.order_type = SUPPLEMENT`, `OrderSupplementPusherService` | Typ oraz wysyłka do integracji istnieją. Nie ma relacji do zamówienia głównego i nie ma utworzenia proformy dla domówienia z edycji. |

## Aktualny przepływ i miejsce problemu

`OrderController::editOrder()` przekazuje formularz do `OrderService::updateOrderWithProducts()`. Serwis usuwa pozycje zamówienia, zapisuje nowe i dotychczas dla nieopłaconej proformy usuwał oraz kopiował jej pozycje z zamówienia. To zacierało dokumentowy snapshot. Dla proformy opłaconej wcześniejsze wdrożenie zatrzymuje tę synchronizację.

`ProformaService::insertFromOrder()` tworzy nagłówek i zapisuje pozycje w `document_proforma_product`, po czym przypisuje dokument w `customer_order.id_proforma`. Generator PDF odczytuje pozycje dokumentu, więc nie wymaga zmiany layoutu ani generatora.

## Plan minimalnych zmian

1. Zatrzymać synchronizację pozycji proformy również dla nieopłaconej proformy.
2. Dodać serwisowe, znormalizowane porównanie bieżących pozycji zamówienia ze snapshotem aktywnej proformy; wynik wyliczać dynamicznie dla listy i formularza.
3. Dodać transakcyjną regenerację: ponowna weryfikacja statusu i różnicy, anulowanie wyłącznie nieopłaconego dokumentu, odpięcie go od zamówienia i utworzenie nowej proformy ze snapshotem.
4. Dla opłaconej proformy umożliwić standardową edycję oraz ostrzec w formularzu. W przypadku dodatnich zmian udostępnić wybór: pozostawienie ich w zamówieniu albo utworzenie istniejącego typu `SUPPLEMENT`.
5. Dodać najmniejszą relację `customer_order.parent_order_id` dla domówienia utworzonego z edycji. Wykorzysta ona istniejący `order_type = SUPPLEMENT` i pusher; nie tworzy nowego modelu domówień.
6. Dla domówienia utworzyć osobną proformę w tej samej transakcji z pozycjami dodatniej różnicy. Zmniejszenia pozostają w zamówieniu głównym.

## Wykrywanie nieaktualności

Porównanie nie opiera się na `updated_at`. Normalizuje pozycje po `id_product`, ilości i cenie transakcyjnej; dla gratisów cena jest efektywnie zerowa. Dla nieopłaconej aktywnej proformy rozbieżność jest pokazywana jako **Proforma nieaktualna**. Proforma anulowana nie jest uznawana za aktywną.

## Pliki przewidziane do zmiany

- `App/Services/Client/Order/OrderService.php`
- `App/Services/Document/ProformaService.php`
- `App/Controllers/Web/Client/Order/OrderController.php`
- `App/Controllers/Web/Document/ProformaController.php`
- `App/Core/Routes/Document/ProformaRoutes.php`
- `App/Repositories/Client/Order/OrderRepository.php`
- `App/Views/client/order/edit.view.php`
- `App/Views/client/order/overview.view.php`
- migracja SQL relacji domówienia
- test kontraktowy procesu

## Założenia i ryzyka

- Brak dotychczasowej relacji domówienie–zamówienie główne potwierdzono w migracji `026_customer_order_supplement_type.sql` i kodzie. Dodanie nullable `parent_order_id` jest konieczne, aby automatycznie utworzone domówienie było jednoznacznie powiązane.
- Status anulowania będzie przechowywany jako `cancelled`, bez usuwania historii dokumentu. Aplikacja używa pola tekstowego statusu, a nie zamkniętego enumu w warstwie PHP.
- Zewnętrzne wysłanie domówienia pozostaje akcją po zakończeniu transakcji danych (tak jak obecnie), aby nie wiązać zewnętrznego HTTP z rollbackiem bazy.
- Nie zmieniamy layoutu PDF proformy ani dokumentu różnic.

## Plan testów

- testy porównania snapshotu z zamówieniem;
- regeneracja nieopłaconej proformy, w tym odmowa bez różnicy i po zmianie statusu na `paid`;
- edycja przy opłaconej proformie bez modyfikacji snapshotu;
- podział zmniejszeń i dodatnich różnic na zamówienie główne/domówienie;
- lint zmienionych plików i istniejący test różnic proform.
