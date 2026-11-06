# Docelowy model procesów ERP TO-BE

## Założenie przewodnie

Docelowy system powinien być **jednym modularnym ERP z jedną bazą transakcyjną**, a nie zbiorem niezależnych mikroserwisów. Dla jednoosobowego zespołu najbezpieczniejszy jest modularny monolit: wspólna tożsamość, wspólna autoryzacja, jeden audit log, jedna księga magazynowa i proste kolejki outbox dla integracji. Granice domen mają być widoczne w kodzie i danych, ale nie wymagają osobnych deployów.

## Niezmienne zasady biznesowe

1. Każda operacja zmieniająca ilość, wartość, status lub dostęp musi mieć aktora, czas, powód i dokument źródłowy.
2. Statusu nie poprawia się SQL-em. Zmienia go wyłącznie dozwolona komenda domenowa.
3. Dokument zatwierdzony jest niezmienny; poprawka powstaje jako korekta lub dokument odwracający.
4. Stan magazynowy jest projekcją niezmiennej księgi ruchów, nie dowolnie edytowaną liczbą.
5. Sprzedaż, produkcja i zakupy zamrażają istotne wartości historyczne: produkt, jednostkę, VAT, cenę, recepturę i koszt.
6. Receptura jest wersjonowana. Zlecenie produkcyjne zawsze wskazuje konkretną wersję.
7. Integracja jest co najmniej raz dostarczana, idempotentna po kluczu zdarzenia i widoczna w konsoli operatora.
8. Okres zamknięty nie przyjmuje zmian; korekta trafia do otwartego okresu z odniesieniem do źródła.
9. Uprawnienie wynika z roli, organizacji i lokalizacji; samo posiadanie ID rekordu nie daje dostępu.
10. MVP nie zawiera automatycznego planisty, zaawansowanego forecastingu ani pełnego controllingu — najpierw wiarygodne dane operacyjne.

## Docelowe rdzenie systemu

- katalogi i konfiguracja;
- sprzedaż i należności;
- receptury i wersje technologiczne;
- planowanie i zlecenia produkcyjne;
- księga magazynowa z partiami;
- zakupy i dostawy;
- pracownicy, grafik i czas;
- koszty oraz zamknięcie okresu;
- dokumenty, transport i jakość;
- raportowanie, integracje i audyt.

## Najważniejsze rozstrzygnięcia projektowe

- zamówienie nie zmienia magazynu; tworzy popyt i opcjonalną rezerwację;
- produkcja zużywa materiały i przyjmuje wyrób wyłącznie przez ruchy magazynowe;
- dokument logistyczny nie jest automatycznie sprzedażą księgową;
- odpad jest osobnym ruchem i kosztem z powodem, produktem/partią i aktorem;
- koszt standardowy służy planowaniu, a koszt rzeczywisty powstaje po zamknięciu partii/okresu;
- ręczna korekta jest kontrolowaną komendą z dwustopniową akceptacją dla finansów i stanów.

Projekt opisuje 42 rodziny procesów obejmujących wszystkie obowiązkowe operacje z briefu. Nie zawiera roadmapy implementacyjnej.

