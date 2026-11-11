# Lista kontrolna testowania - Edycja zamówienia

## Informacje ogólne
- **Moduł**: Edycja zamówienia klienta
- **Plik**: `App/Views/client/order/edit.view.php`
- **Data**: 2026-03-04
- **Wersja**: 1.0.0

## Przygotowanie do testów

### Wymagania wstępne
- [ ] Aplikacja jest uruchomiona
- [ ] Użytkownik jest zalogowany
- [ ] Istnieje przynajmniej jeden klient z zamówieniem
- [ ] Klient ma przypisany cennik (price_list)
- [ ] Cennik zawiera aktywne produkty
- [ ] Przeglądarka: Chrome/Firefox/Edge (aktualna wersja)
- [ ] JavaScript jest włączony

### Dane testowe
- [ ] Przygotowano klienta testowego
- [ ] Przygotowano zamówienie testowe
- [ ] Cennik zawiera min. 10 produktów
- [ ] Produkty mają różne ceny (0 PLN, małe, średnie, duże)

---

## 1. Testy interfejsu użytkownika

### 1.1 Wyświetlanie strony
- [ ] Strona ładuje się bez błędów
- [ ] Wszystkie style CSS są załadowane
- [ ] Bootstrap Icons są widoczne
- [ ] Header zawiera poprawny breadcrumb
- [ ] Tytuł strony to "Edycja zamówienia"

### 1.2 Karty informacyjne (Header)
- [ ] Karta "Klient" wyświetla nazwę firmy
- [ ] Karta "Klient" wyświetla ID klienta
- [ ] Karta "Zamówienie" wyświetla ID zamówienia
- [ ] Karta "Zamówienie" wyświetla referencję (jeśli istnieje)
- [ ] Karta "Status" wyświetla poprawny status
- [ ] Status ma właściwy kolor badge (żółty/zielony/niebieski)

### 1.3 Formularz szczegółów
- [ ] Pole "Data wysyłki" ma właściwą wartość
- [ ] Pole "Data wysyłki" ma picker kalendarza
- [ ] Checkbox "Standardowy cennik" ma właściwy stan
- [ ] Pole "Komentarz" wyświetla istniejący komentarz
- [ ] Wszystkie pola są edytowalne

### 1.4 Tabela produktów
- [ ] Tabela wyświetla się poprawnie
- [ ] Header tabeli ma wszystkie kolumny: SKU, Nazwa, Ilość, Cena, Wartość, Akcja
- [ ] Istniejące produkty są wyświetlone
- [ ] SKU są wyświetlone w znacznikach `<code>`
- [ ] Ceny są sformatowane z przecinkami
- [ ] Wartości liniowe są obliczone poprawnie
- [ ] Suma zamówienia jest wyświetlona w stopce
- [ ] Przycisk usuwania (🗑) jest widoczny przy każdym produkcie

### 1.5 Sekcja dodawania produktów
- [ ] Select produktów zawiera wszystkie produkty z cennika
- [ ] Produkty w select pokazują: SKU, nazwę i cenę
- [ ] Pole ilości ma wartość domyślną 1
- [ ] Pole ceny jest readonly
- [ ] Przycisk "Dodaj" jest widoczny
- [ ] Sekcja ma jasnoszare tło (card bg-light)

### 1.6 Przyciski akcji
- [ ] Przycisk "Zapisz zmiany" jest widoczny
- [ ] Przycisk "Anuluj" jest widoczny
- [ ] Przyciski mają właściwe ikony

---

## 2. Testy funkcjonalności

### 2.1 Dodawanie produktu

#### Test 2.1.1: Pomyślne dodanie
- [ ] Wybierz produkt z listy
- [ ] Sprawdź czy cena wypełniła się automatycznie
- [ ] Wprowadź ilość (np. 5)
- [ ] Kliknij "Dodaj"
- [ ] **Oczekiwane**: Produkt pojawia się w tabeli
- [ ] **Oczekiwane**: Suma zamówienia aktualizuje się
- [ ] **Oczekiwane**: Toast notification "Produkt dodany"
- [ ] **Oczekiwane**: Formularz dodawania się czyści

#### Test 2.1.2: Dodanie bez wyboru produktu
- [ ] Nie wybieraj produktu
- [ ] Wprowadź ilość
- [ ] Kliknij "Dodaj"
- [ ] **Oczekiwane**: Toast warning "Proszę wybrać produkt"
- [ ] **Oczekiwane**: Produkt nie zostaje dodany

#### Test 2.1.3: Dodanie z ilością 0
- [ ] Wybierz produkt
- [ ] Wprowadź ilość 0
- [ ] Kliknij "Dodaj"
- [ ] **Oczekiwane**: Toast warning "Proszę wprowadzić poprawną ilość"
- [ ] **Oczekiwane**: Pole ilości ma klasę is-invalid (czerwone obramowanie)

#### Test 2.1.4: Dodanie z ujemną ilością
- [ ] Wybierz produkt
- [ ] Wprowadź ilość -5
- [ ] Kliknij "Dodaj"
- [ ] **Oczekiwane**: Toast warning lub browser validation

#### Test 2.1.5: Duplikat produktu
- [ ] Dodaj produkt do zamówienia
- [ ] Spróbuj dodać ten sam produkt ponownie
- [ ] **Oczekiwane**: Toast warning "Ten produkt jest już w zamówieniu"

#### Test 2.1.6: Ctrl+Enter shortcut
- [ ] Wybierz produkt
- [ ] Wprowadź ilość
- [ ] Naciśnij Ctrl+Enter
- [ ] **Oczekiwane**: Produkt zostaje dodany (jak po kliknięciu "Dodaj")

### 2.2 Modyfikacja ilości produktu

#### Test 2.2.1: Zmiana na poprawną wartość
- [ ] Kliknij w pole ilości istniejącego produktu
- [ ] Zmień wartość (np. z 5 na 10)
- [ ] **Oczekiwane**: Wartość liniowa aktualizuje się natychmiast
- [ ] **Oczekiwane**: Suma zamówienia aktualizuje się natychmiast
- [ ] **Oczekiwane**: Wartości są poprawnie sformatowane

#### Test 2.2.2: Zmiana na 0
- [ ] Zmień ilość na 0
- [ ] **Oczekiwane**: Pole otrzymuje czerwone obramowanie
- [ ] **Oczekiwane**: Walidacja przed zapisem wykryje błąd

#### Test 2.2.3: Zmiana na liczbę dziesiętną
- [ ] Wprowadź 5.5
- [ ] **Oczekiwane**: Browser validation (step="1") lub zaokrąglenie

#### Test 2.2.4: Usunięcie wartości
- [ ] Wyczyść pole ilości
- [ ] **Oczekiwane**: Wartość liniowa = 0
- [ ] **Oczekiwane**: Walidacja przed zapisem wykryje błąd

### 2.3 Usuwanie produktu

#### Test 2.3.1: Pomyślne usunięcie
- [ ] Kliknij przycisk 🗑 przy produkcie
- [ ] **Oczekiwane**: Dialog potwierdzenia
- [ ] Potwierdź usunięcie
- [ ] **Oczekiwane**: Wiersz znika
- [ ] **Oczekiwane**: Suma zamówienia aktualizuje się
- [ ] **Oczekiwane**: Toast notification "Produkt usunięty"

#### Test 2.3.2: Anulowanie usunięcia
- [ ] Kliknij przycisk 🗑
- [ ] Anuluj w dialogu
- [ ] **Oczekiwane**: Produkt pozostaje w zamówieniu

#### Test 2.3.3: Usunięcie wszystkich produktów
- [ ] Usuń wszystkie produkty
- [ ] **Oczekiwane**: Tabela jest pusta
- [ ] **Oczekiwane**: Suma = 0,00 PLN
- [ ] Spróbuj zapisać
- [ ] **Oczekiwane**: Błąd walidacji

### 2.4 Obliczenia

#### Test 2.4.1: Obliczenia wartości linii
- [ ] Produkt: cena 10,00 PLN, ilość 5
- [ ] **Oczekiwane**: Wartość = 50,00 PLN

#### Test 2.4.2: Obliczenia sumy zamówienia
- [ ] Produkt 1: 100,00 PLN wartość
- [ ] Produkt 2: 50,50 PLN wartość
- [ ] Produkt 3: 25,25 PLN wartość
- [ ] **Oczekiwane**: Suma = 175,75 PLN

#### Test 2.4.3: Precyzja obliczeń
- [ ] Produkt: cena 10,33 PLN, ilość 3
- [ ] **Oczekiwane**: Wartość = 30,99 PLN (nie 31,00 PLN)

#### Test 2.4.4: Formatowanie liczb
- [ ] Sprawdź produkty o cenach: 1234.56, 0.00, 99.99
- [ ] **Oczekiwane**: Format polski: 1 234,56 PLN, 0,00 PLN, 99,99 PLN

### 2.5 Walidacja formularza

#### Test 2.5.1: Puste pole daty
- [ ] Wyczyść pole "Data wysyłki"
- [ ] Spróbuj zapisać
- [ ] **Oczekiwane**: Browser validation (required field)

#### Test 2.5.2: Brak produktów
- [ ] Usuń wszystkie produkty
- [ ] Spróbuj zapisać
- [ ] **Oczekiwane**: Alert "Zamówienie musi zawierać przynajmniej jeden produkt"

#### Test 2.5.3: Ilość 0 przy zapisie
- [ ] Ustaw ilość produktu na 0
- [ ] Spróbuj zapisać
- [ ] **Oczekiwane**: Alert o niepoprawnych ilościach

#### Test 2.5.4: Wszystkie pola poprawne
- [ ] Data wysyłki: wypełniona
- [ ] Min. 1 produkt z ilością > 0
- [ ] Spróbuj zapisać
- [ ] **Oczekiwane**: Formularz zostaje wysłany

### 2.6 Zapisywanie zamówienia

#### Test 2.6.1: Pomyślne zapisanie
- [ ] Wypełnij wszystkie wymagane pola
- [ ] Dodaj produkty
- [ ] Kliknij "Zapisz zmiany"
- [ ] **Oczekiwane**: Przekierowanie do listy zamówień
- [ ] **Oczekiwane**: Zamówienie jest zaktualizowane w bazie

#### Test 2.6.2: Zapisanie z komentarzem
- [ ] Dodaj komentarz do zamówienia
- [ ] Zapisz
- [ ] Otwórz zamówienie ponownie
- [ ] **Oczekiwane**: Komentarz jest zachowany

#### Test 2.6.3: Zapisanie ze standardowym cennikiem
- [ ] Zaznacz checkbox "Standardowy cennik"
- [ ] Zapisz
- [ ] **Oczekiwane**: Flaga standard_price_list = 1

#### Test 2.6.4: Błąd serwera przy zapisie
- [ ] (Symuluj błąd serwera - opcjonalnie)
- [ ] **Oczekiwane**: Odpowiedni komunikat błędu

### 2.7 Anulowanie zmian

#### Test 2.7.1: Anulowanie bez zmian
- [ ] Kliknij "Anuluj"
- [ ] **Oczekiwane**: Przekierowanie do listy zamówień

#### Test 2.7.2: Anulowanie ze zmianami
- [ ] Zmodyfikuj zamówienie
- [ ] Kliknij "Anuluj"
- [ ] **Oczekiwane**: Przekierowanie bez zapisywania
- [ ] Otwórz zamówienie ponownie
- [ ] **Oczekiwane**: Zmiany nie zostały zapisane

---

## 3. Testy responsywności

### 3.1 Desktop (>1200px)
- [ ] Layout 3-kolumnowy dla kart informacyjnych
- [ ] Pełna szerokość tabeli
- [ ] Wszystkie kolumny widoczne
- [ ] Sekcja dodawania: 1 wiersz

### 3.2 Tablet (768px - 1199px)
- [ ] Layout dostosowuje się
- [ ] Tabela jest scrollowalna poziomo (jeśli potrzeba)
- [ ] Wszystkie funkcje działają

### 3.3 Mobile (<768px)
- [ ] Karty informacyjne jeden pod drugim
- [ ] Tabela jest scrollowalna
- [ ] Przyciski są klikalne (nie za małe)
- [ ] Select jest użyteczny (nie przycinany)

### 3.4 Różne rozdzielczości
- [ ] 1920x1080 (Full HD)
- [ ] 1366x768 (Laptop)
- [ ] 768x1024 (Tablet pionowo)
- [ ] 375x667 (iPhone SE)

---

## 4. Testy przeglądarek

### 4.1 Google Chrome (zalecana)
- [ ] Wszystkie funkcje działają
- [ ] Style są poprawne
- [ ] Animacje są płynne

### 4.2 Mozilla Firefox
- [ ] Wszystkie funkcje działają
- [ ] Style są poprawne
- [ ] Animacje są płynne

### 4.3 Microsoft Edge
- [ ] Wszystkie funkcje działają
- [ ] Style są poprawne
- [ ] Animacje są płynne

### 4.4 Safari (jeśli dostępne)
- [ ] Wszystkie funkcje działają
- [ ] Style są poprawne
- [ ] Animacje są płynne

---

## 5. Testy wydajności

### 5.1 Czas ładowania
- [ ] Strona ładuje się w <2s (sieć LAN)
- [ ] Brak błędów w konsoli

### 5.2 Duża liczba produktów
- [ ] Dodaj 50+ produktów do cennika
- [ ] Sprawdź czy select działa płynnie
- [ ] Sprawdź czy dodawanie produktów jest szybkie

### 5.3 Duża liczba produktów w zamówieniu
- [ ] Dodaj 20+ produktów do zamówienia
- [ ] Sprawdź prędkość przeliczania sumy
- [ ] Sprawdź płynność scrollowania tabeli

---

## 6. Testy kompatybilności danych

### 6.1 price_list jako obiekty (PriceListItemModel)
- [ ] Klient z ExternalPriceListService
- [ ] Produkty wyświetlają się poprawnie
- [ ] Ceny są poprawne
- [ ] Dodawanie działa

### 6.2 price_list jako tablice
- [ ] Klient z ClientProductPriceService
- [ ] Produkty wyświetlają się poprawnie
- [ ] Ceny są poprawne
- [ ] Dodawanie działa

### 6.3 Produkty z ceną 0
- [ ] Produkt z price_net = 0.00
- [ ] Wyświetla się "0,00 PLN"
- [ ] Można dodać do zamówienia
- [ ] Wartość liniowa = 0,00 PLN

### 6.4 Produkty bez danych package
- [ ] Produkt bez package_size/unit
- [ ] Wyświetla się bez błędów
- [ ] Można dodać do zamówienia

---

## 7. Testy bezpieczeństwa

### 7.1 XSS Protection
- [ ] Wprowadź `<script>alert('xss')</script>` w komentarz
- [ ] Zapisz i odśwież
- [ ] **Oczekiwane**: Tekst wyświetlony jako plain text (nie wykonany)

### 7.2 SQL Injection (backend)
- [ ] Wprowadź `'; DROP TABLE orders; --` w komentarz
- [ ] Zapisz
- [ ] **Oczekiwane**: Zapisane jako tekst, baza nie uszkodzona

### 7.3 CSRF Protection
- [ ] Sprawdź czy formularz ma token CSRF (jeśli implementowany)

---

## 8. Testy użyteczności (UX)

### 8.1 Intuicyjność
- [ ] Nowy użytkownik rozumie co robić
- [ ] Etykiety są czytelne
- [ ] Przyciski mają jasne nazwy

### 8.2 Feedback dla użytkownika
- [ ] Każda akcja ma feedback (toast lub zmiana UI)
- [ ] Błędy są wyraźnie komunikowane
- [ ] Sukces jest potwierdzony

### 8.3 Dostępność (a11y)
- [ ] Możliwa nawigacja Tab
- [ ] Wszystkie przyciski są osiągalne z klawiatury
- [ ] Pola mają właściwe etykiety (label)

---

## 9. Testy regresji

### 9.1 Istniejące zamówienia
- [ ] Otwórz stare zamówienie (sprzed zmiany)
- [ ] Wszystkie produkty są widoczne
- [ ] Można edytować
- [ ] Można zapisać

### 9.2 Różne typy klientów
- [ ] Klient z franchisee_uid (ExternalPriceList)
- [ ] Klient bez franchisee_uid (ClientProductPrice)
- [ ] Obydwa typy działają poprawnie

---

## 10. Testy Edge Cases

### 10.1 Bardzo duże ilości
- [ ] Wprowadź ilość 999999
- [ ] Sprawdź czy wartość jest poprawnie obliczona
- [ ] Sprawdź czy formatowanie działa

### 10.2 Bardzo wysokie ceny
- [ ] Produkt z ceną 99999,99 PLN
- [ ] Sprawdź formatowanie
- [ ] Sprawdź obliczenia

### 10.3 Pusty cennik
- [ ] Klient bez produktów w cenniku
- [ ] Select jest pusty (tylko placeholder)
- [ ] Komunikat dla użytkownika?

### 10.4 Zamówienie bez produktów (nowe)
- [ ] Otwórz zamówienie bez produktów
- [ ] Tabela jest pusta
- [ ] Suma = 0,00 PLN
- [ ] Można dodawać produkty

---

## 11. Testy konsoli przeglądarki

- [ ] Brak błędów JavaScript w konsoli
- [ ] Brak ostrzeżeń o brakujących zasobach
- [ ] Brak błędów 404
- [ ] Brak błędów CORS

---

## 12. Testy integracji z backendem

### 12.1 OrderController
- [ ] GET request ładuje dane poprawnie
- [ ] POST request zapisuje zmiany
- [ ] Przekierowanie po zapisie działa

### 12.2 OrderService
- [ ] updateOrderWithProducts() obsługuje nowe dane
- [ ] Walidacja po stronie serwera działa
- [ ] Transakcje działają poprawnie

### 12.3 Price List Service
- [ ] getActiveByClient() zwraca poprawne dane
- [ ] Obiekty PriceListItemModel są poprawne
- [ ] Tablice są poprawnie strukturyzowane

---

## Podsumowanie testów

### Statystyki
- **Łącznie testów**: _____ / _____
- **Testy zaliczone**: _____ 
- **Testy niezaliczone**: _____
- **Testy pominięte**: _____

### Krytyczne błędy
- [ ] Brak krytycznych błędów
- [ ] Lista błędów krytycznych:
  1. ___________________________
  2. ___________________________

### Uwagi testera
```
___________________________________________
___________________________________________
___________________________________________
```

### Rekomendacje
- [ ] Gotowe do wdrożenia produkcyjnego
- [ ] Wymaga poprawek (lista powyżej)
- [ ] Wymaga dodatkowych testów

---

**Tester**: ___________________  
**Data**: _____________________  
**Czas testowania**: _________ godzin  
**Podpis**: ___________________

