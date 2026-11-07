# Model wyjątków i korekt

## Typy sytuacji

| Typ | Przykład | Reakcja systemu | Czego nie robić |
|---|---|---|---|
| Błąd walidacji | brak jednostki, ujemna ilość | odrzucić przed zapisem, wskazać pola | nie tworzyć częściowego rekordu |
| Konflikt współbieżności | zmieniona wersja zamówienia | HTTP 409/case, ponowne odczytanie | nie stosować last-write-wins |
| Brak zasobu | brak stock/capacity | WAITING/BACKORDER i decyzja | nie zejść poniżej zera domyślnie |
| Błąd częściowy | payment OK, wydruk fail | zakończyć sale, ponowić delivery dokumentu | nie pobierać ponownie płatności |
| Błąd integracji techniczny | timeout/webhook 500 | retry z backoff i idempotency | nie edytować payloadu |
| Błąd integracji danych | nieznany external product | DEAD/case i mapping | nie tworzyć „tymczasowego” produktu bez zgody |
| Błąd operacyjny przed zatwierdzeniem | zła ilość w draft | zwykła edycja z audit diff | nie wymagać korekty finansowej |
| Błąd po zatwierdzeniu | zła faktura/ruch | dokument/ruch korekcyjny | nie UPDATE/DELETE źródła |
| Incydent integralności | ledger ≠ projection | zablokować zakres, rebuild/reconciliation case | nie poprawiać salda ręcznie |
| Wyjątek biznesowy | substytut materiału | jawna decyzja, approver, wpływ na jakość/koszt | nie dopisywać if-a dla pojedynczego klienta |

## Uniwersalny wzorzec korekty

1. Użytkownik otwiera CorrectionCase i wskazuje rekord źródłowy.
2. System pokazuje skutki: magazyn, finanse, dokumenty, raporty i integracje.
3. Użytkownik wybiera powód ze słownika i opisuje uzasadnienie.
4. System generuje plan kompensacji; nie wykonuje go jeszcze.
5. Dla operacji ponad próg drugi aktor zatwierdza.
6. Jedna transakcja zapisuje komendy domenowe, audit i outbox.
7. System uruchamia reconciliation i oznacza case VERIFIED albo FAILED.

## Reguły anulowania

- DRAFT można anulować bez skutków, pozostawiając audit.
- Potwierdzone zamówienie można anulować tylko w niewykonanej części; rezerwacje są zwalniane.
- Ruch POSTED jest odwracany ruchem REVERSE.
- Sprzedaż COMPLETED jest korygowana VOID/RETURN zgodnie z prawem i stanem płatności.
- Faktura ISSUED wymaga korekty; numer nigdy nie wraca do puli.
- Zlecenie IN_PROGRESS nie jest kasowane: jest przerwane, rozliczone i zamknięte jako cancelled/scrapped.
- Okres CLOSED wymaga korekty w okresie bieżącym albo formalnego reopen.

## Progi akceptacji

Progi są konfigurowane per organizacja i waluta, ale MVP potrzebuje tylko czterech klas:

- korekta stock poniżej/ponad próg ilości lub wartości;
- zwrot/anulacja sprzedaży poniżej/ponad kwotę;
- nadzużycie/odpad poniżej/ponad procent normy;
- PO i korekta kosztu poniżej/ponad limit roli.

## Retry

Retry jest dozwolony tylko dla operacji technicznych i komend z idempotency key. Nie jest dozwolony dla „spróbuj jeszcze raz” po nieznanym rezultacie płatności, fiskalizacji lub księgowania; najpierw wymagany jest status query/reconciliation.

## Ręczna naprawa

Bezpośredni SQL jest procedurą awaryjną poza normalnym systemem i wymaga maintenance window, backupu, zatwierdzonego skryptu, transakcji, wyniku before/after oraz wpisu do audit. Docelowo każdy powtarzalny przypadek musi mieć RepairCommand w UI.

