# Edycja zamówień po wystawieniu proformy — implementacja

## Zrealizowany proces

### Nieopłacona proforma

- Edycja zamówienia nie zmienia już `document_proforma_product`; proforma pozostaje snapshotem dokumentu.
- `ProformaContentComparator` porównuje bieżące pozycje zamówienia z pozycjami proformy po identyfikatorze produktu, ilości i cenie transakcyjnej. Dla gratisu cena jest porównywana jako zero.
- Gdy snapshot różni się od zamówienia, `/client-orders` pokazuje badge **Proforma nieaktualna**, tekst wyjaśniający oraz akcję **Wygeneruj nową proformę**.
- Niezależnie od statusu aktywnej proformy lista pokazuje badge **Różnica ilości względem proformy**, gdy suma ilości któregokolwiek produktu odbiega od snapshotu. Dla proformy opłaconej informuje to o rozbieżności, która będzie rozliczona po finalizacji.
- `POST /ajax/client-orders/{idOrder}/proforma/regenerate` ponownie sprawdza stan w transakcji, anuluje wyłącznie nieopłaconą proformę (`status = cancelled`), odpina ją od zamówienia i generuje nową proformę ze snapshotem aktualnego zamówienia.
- Dokument anulowany pozostaje dostępny w `/proforma-documents` jako **Anulowana**. Nie może być ponownie oznaczony jako opłacony.

### Opłacona proforma

- Formularz edycji informuje, że proforma jest niezmienna, a różnice zostaną rozliczone po finalizacji.
- Zmniejszenia ilości i usunięcia produktów pozostają zmianą zamówienia głównego; snapshot opłaconej proformy nie jest zmieniany.
- Przy zwiększeniach oraz nowych produktach formularz pokazuje podsumowanie i pozwala wybrać:
  - zapis w bieżącym zamówieniu — dodatnia różnica zostanie później rozliczona w dokumencie różnic;
  - utworzenie domówienia — zwiększenia/nowe produkty trafiają wyłącznie do domówienia, a zmniejszenia pozostają w zamówieniu głównym.
- Decyzja z formularza nie jest źródłem prawdy: backend ponownie dzieli pozycje względem bieżącego stanu zamówienia.

### Domówienia

Wykorzystano istniejący typ `customer_order.order_type = SUPPLEMENT` oraz `OrderSupplementPusherService`. Dodano jedynie nullable `customer_order.parent_order_id`, aby automatycznie utworzone domówienie miało jednoznacznego rodzica.

Przy wyborze domówienia jedna transakcja:

1. zapisuje zmniejszenia w zamówieniu głównym;
2. tworzy zamówienie typu `SUPPLEMENT` z dodatnimi różnicami;
3. przypisuje `parent_order_id`;
4. tworzy osobną proformę i jej snapshot dla domówienia.

Po commicie istniejący pusher przekazuje domówienie do zewnętrznej integracji. Zewnętrzne HTTP celowo nie jest częścią transakcji bazy.

## Zmiany techniczne

- `App/Services/Client/Order/OrderService.php`
  - dynamiczny stan proformy na liście;
  - brak synchronizacji snapshotu przy edycji;
  - transakcyjna regeneracja nieopłaconej proformy;
  - podział zmian na zamówienie główne i domówienie.
- `App/Services/Document/ProformaContentComparator.php` — czysty, testowalny comparator treści dokumentu.
- `App/Services/Document/ProformaService.php` — nowe proformy otrzymują `issued`; dokument anulowany nie może być opłacony ani zmodyfikowany.
- `App/Repositories/Client/Order/OrderRepository.php` — obsługa `parent_order_id`.
- `App/Repositories/Document/Proforma/ProformaRepository.php` — lista dokumentów zawiera także anulowane dokumenty historyczne.
- `App/Controllers/Web/Client/Order/OrderController.php`, `App/Controllers/Web/Document/ProformaController.php`, `App/Core/Routes/Document/ProformaRoutes.php` — kontekst formularza, endpoint regeneracji i wysłanie nowo utworzonego domówienia.
- `App/Views/client/order/edit.view.php`, `App/Views/client/order/overview.view.php`, `App/Views/document/proforma/overview.view.php` — komunikaty, wybór ścieżki oraz statusy.
- `migrations/033_customer_order_supplement_parent.sql` — relacja do zamówienia głównego.

## Endpoint

`POST /ajax/client-orders/{idOrder}/proforma/regenerate`

Zwraca `200` z `data` równym identyfikatorowi nowej proformy, jeżeli aktywna proforma jest nieopłacona i nieaktualna. Dla opłaconej, nieistniejącej albo aktualnej proformy zwraca błąd bez modyfikacji danych.

## Testy i walidacja

Wykonano jedną iterację implementacyjno-testową.

```bash
php tests/order_proforma_edit_contract_test.php
php tests/proforma_difference_contract_test.php
php -l App/Services/Document/ProformaContentComparator.php
php -l App/Services/Client/Order/OrderService.php
php -l App/Services/Document/ProformaService.php
php -l App/Controllers/Web/Document/ProformaController.php
php -l App/Controllers/Web/Client/Order/OrderController.php
php -l App/Views/client/order/edit.view.php
php -l App/Views/client/order/overview.view.php
php -l App/Core/Routes/Document/ProformaRoutes.php
```

Wynik: oba testy kontraktowe oraz lint wszystkich zmienionych plików zakończone powodzeniem.

Test kontraktowy obejmuje równe snapshoty, zmianę ilości, zmianę ceny i gratis. Istniejący test rozliczenia różnic nadal potwierdza zachowanie opłaconych i anulowanych proform oraz kwot zwrotu/dopłaty.

Nie wykonano testu end-to-end na bazie danych, ponieważ repozytorium nie zawiera lokalnej, izolowanej bazy testowej ani runnera integracyjnego. Przed wdrożeniem należy uruchomić migrację 033 i wykonać ręcznie przepływy A–D z wymagania na środowisku testowym.

## Elementy niezmienione

- Layout, generator i numeracja PDF proformy.
- Layout oraz źródło finalnej realizacji w dokumencie różnic.
- Dotychczasowy typ domówienia i jego pusher; rozszerzono go tylko o powiązanie z rodzicem oraz automatyczne utworzenie z edycji.

## Pozostałe ryzyko

Migracja `033_customer_order_supplement_parent.sql` musi zostać zastosowana przed użyciem ścieżki domówienia. Bez niej zapis domówienia zostanie bezpiecznie wycofany przez transakcję.
