# Przewodnik użytkownika - Edycja zamówienia

## Przegląd interfejsu

Nowy interfejs edycji zamówienia został zaprojektowany z myślą o prostocie i intuicyjności użytkowania.

## Sekcje interfejsu

### 1. Header informacyjny
Na górze strony znajdują się trzy karty z kluczowymi informacjami:
- **Klient**: Nazwa firmy i ID klienta
- **Numer zamówienia**: ID zamówienia i referencja (jeśli istnieje)
- **Status**: Aktualny status zamówienia (kolorowy)
  - 🟡 Żółty - Oczekujące
  - 🟢 Zielony - Zrealizowane
  - 🔵 Niebieski - W transporcie

### 2. Szczegóły zamówienia

#### Data wysyłki
- Wybierz przewidywaną datę wysyłki z kalendarza
- Pole jest wymagane

#### Cennik standardowy
- Zaznacz checkbox aby użyć standardowego cennika
- Odznacz aby użyć cennika klienta

#### Komentarz
- Dodaj dodatkowe informacje o zamówieniu
- Pole opcjonalne

### 3. Lista produktów

Tabela pokazuje wszystkie produkty w zamówieniu:
- **SKU**: Kod produktu
- **Nazwa**: Pełna nazwa produktu z informacjami o opakowaniu
- **Ilość**: Liczba kartonów (można edytować)
- **Cena**: Cena netto za karton
- **Wartość**: Automatycznie obliczana (ilość × cena)
- **Akcja**: Przycisk usuwania (🗑️)

#### Suma zamówienia
Na dole tabeli wyświetlana jest **łączna wartość zamówienia** w czasie rzeczywistym.

### 4. Dodawanie produktów

Aby dodać nowy produkt do zamówienia:

1. **Wybierz produkt** z listy rozwijanej
   - Lista zawiera produkty z cennika
   - Każdy produkt pokazuje: SKU, nazwę i cenę

2. **Wprowadź ilość**
   - Domyślnie ustawiona na 1
   - Musi być liczbą całkowitą > 0

3. **Sprawdź cenę**
   - Automatycznie wyświetlana po wybraniu produktu

4. **Kliknij "Dodaj"**
   - Produkt zostanie dodany do tabeli
   - Suma zamówienia zostanie zaktualizowana

## Operacje na produktach

### Zmiana ilości
- Kliknij w pole "Ilość" przy produkcie
- Wprowadź nową wartość
- Wartość i suma zamówienia zaktualizują się automatycznie

### Usuwanie produktu
- Kliknij przycisk 🗑️ przy produkcie
- Potwierdź operację w oknie dialogowym
- Produkt zostanie usunięty, suma zaktualizowana

## Walidacja

System automatycznie sprawdza:
- ✅ Czy data wysyłki została wybrana
- ✅ Czy zamówienie zawiera przynajmniej jeden produkt
- ✅ Czy wszystkie ilości są większe od 0
- ✅ Czy produkt nie jest już w zamówieniu (przy dodawaniu)

Błędy są wyświetlane:
- Na górze strony (czerwony alert)
- Przy konkretnych polach (czerwone obramowanie)
- W powiadomieniach (toast w prawym górnym rogu)

## Powiadomienia

System wyświetla powiadomienia o:
- ✅ Pomyślnym dodaniu produktu
- ℹ️ Usunięciu produktu
- ⚠️ Błędach walidacji
- ⚠️ Duplikatach produktów

Powiadomienia znikają automatycznie po 5 sekundach.

## Zapisywanie zmian

1. Sprawdź czy wszystkie dane są poprawne
2. Zweryfikuj sumę zamówienia
3. Kliknij przycisk **"Zapisz zmiany"**
4. System wykona walidację
5. Po pomyślnym zapisie zostaniesz przekierowany do listy zamówień

## Anulowanie zmian

Kliknij przycisk **"Anuluj"** aby wrócić do listy zamówień bez zapisywania.

## Skróty klawiszowe

- **Ctrl + Enter**: Dodaj wybrany produkt do zamówienia (gdy focus jest w sekcji dodawania)

## Wskazówki

### 💡 Dobre praktyki

1. **Przed zapisem**:
   - Sprawdź wszystkie ilości
   - Zweryfikuj sumę zamówienia
   - Upewnij się że data wysyłki jest poprawna

2. **Przy dodawaniu produktów**:
   - Możesz wpisać część nazwy w polu wyboru aby szybko znaleźć produkt
   - Sprawdź czy produkt nie jest już w zamówieniu

3. **Cennik**:
   - Jeśli klient ma indywidualny cennik, zostanie użyty automatycznie
   - Checkbox "Standardowy cennik" pozwala wymusić użycie cennika bazowego

### ⚠️ Ostrzeżenia

- Nie odświeżaj strony podczas edycji - stracisz niezapisane zmiany
- Jeśli widzisz cenę 0.00 PLN, skontaktuj się z administratorem
- Zamówienia w statusie "Zrealizowane" powinny być edytowane ostrożnie

## Rozwiązywanie problemów

### Produkt nie pojawia się w liście
- Sprawdź czy produkt jest aktywny w cenniku
- Zweryfikuj czy klient ma przypisany cennik
- Skontaktuj się z administratorem

### Nie mogę zapisać zamówienia
- Upewnij się że wszystkie wymagane pola są wypełnione
- Sprawdź czy zamówienie zawiera produkty
- Zobacz komunikaty błędów na górze strony

### Cena się nie wyświetla
- Odśwież stronę
- Sprawdź czy produkt ma przypisaną cenę w cenniku
- Spróbuj wybrać inny produkt

### Suma się nie oblicza
- Odśwież stronę
- Sprawdź konsolę przeglądarki (F12)
- Upewnij się że JavaScript jest włączony

## Kontakt

W przypadku problemów technicznych skontaktuj się z działem IT.

---

**Wersja**: 1.0.0  
**Data**: 2026-03-04  
**Autor**: SLFood Development Team

