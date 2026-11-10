# Migracja 008: Edycja wpisów alokacji mediów + Limit 100%

**Data:** 2026-02-26  
**Autor:** System  
**Status:** Zaimplementowane i uproszczone

## Cel

Umożliwienie użytkownikom **łatwej edycji istniejących wpisów** oraz wprowadzenie **twardego limitu suma ≤ 100%** dla udziałów kosztów mediów w działach. System nie pozwala przekroczyć 100% dla danego medium.

## Problem

Poprzednia implementacja:
1. Brakowało możliwości edycji wpisów - trzeba było je usuwać i dodawać od nowa
2. Wymagała dokładnie 100%, co było trudne do wyegzekwowania
3. Próba z automatyczną normalizacją była zbyt skomplikowana dla użytkowników

## Rozwiązanie

### 1. **Twardy limit 100% (bez normalizacji)**

System **blokuje** dodanie/edycję udziału, jeśli suma przekroczy 100%:

```
Medium: Prąd
- Produkcja: 60%
- Pakowanie: 30%
Suma: 90% ✅

Próba dodania Logistyka: 20% ❌
→ Błąd: "Przekroczenie 100%! Aktualna suma: 90%. Możesz przypisać max: 10%"
```

**Dozwolone scenariusze:**
- ✅ Suma = 100% (idealnie)
- ✅ Suma < 100% (ok, część kosztu nieprzypisana)
- ❌ Suma > 100% (zablokowane!)

Gdy użytkownik przypisuje udziały np.:
- Dział A: 30%
- Dział B: 50%
- **Suma: 80%**

System automatycznie przelicza to na rzeczywiste proporcje:
- Dział A: 37,5% (30/80 * 100)
- Dział B: 62,5% (50/80 * 100)

Dzięki temu koszt medium zostaje poprawnie rozdzielony proporcjonalnie do wag.

### 2. **Zmiany w backendzie**

#### `CostMonthService.php`
- Usunięto walidację sumy > 100%
- Pojedyncza wartość nadal musi być w zakresie 0-100%
- Metoda `getSumByMonthAndMediaType()` pozostała dla ewentualnych przyszłych potrzeb

#### `CostCalculatorService.php`
- Dodano logikę normalizacji w metodzie `calculateResults()`
- Dla każdego medium:
  1. Oblicz sumę wag wszystkich działów
  2. Podziel koszt medium proporcjonalnie: `(waga_działu / suma_wag) * koszt_medium`
- Dodano nową kolumnę `media_per_carton` w wynikach
- Koszty mediów dodane do sumy `media_total` w summary

#### `CostCalculatorController.php`
- Usunięto zwracanie błędu `ALLOCATION_SUM_EXCEEDED`

### 3. **Zmiany w UI**

#### Uproszczony modal alokacji
- **Możliwość edycji** - przycisk "Edytuj" przy każdym wpisie w tabeli
- Kliknięcie edycji otwiera modal z wypełnionymi danymi
- Hidden input `ma_edit_id` określa czy to nowy wpis (0) czy edycja
- Uproszczone podsumowanie - tylko lista działów z procentami (bez zaawansowanych wyjaśnień)
- Większe pole input (input-group-lg) dla lepszej widoczności
- Usunięto skomplikowany "podgląd na żywo" z normalizacją
- Zmniejszono liczbę szybkich przycisków (25%, 33%, 50%, 100%)

#### Podsumowania per medium
- Prosty widok: nazwa medium, liczba działów, suma procentów
- Badge zielony gdy suma ≈ 100%, szary w innych przypadkach
- Usunięto szczegółowe wyjaśnienia normalizacji (mniej chaosu)

#### Tabela alokacji
- Dodano przycisk **"Edytuj"** (ikona ołówka) przy każdym wpisie
- Przycisk **"Usuń"** (ikona kosza)
- Format % bez 4 miejsc po przecinku (np. 35,50% zamiast 35,5000%)
- Wiersz miga na zielono po zapisaniu zmian

### 4. **UX - Jak działa edycja**

1. Użytkownik klika "Edytuj" przy wpisie
2. Modal otwiera się z wypełnionymi danymi
3. Tytuł zmienia się na "Edycja udziału"
4. Przycisk "Zapisz zmiany" zamiast "Zapisz"
5. Po zapisie - ten sam wiersz się aktualizuje (nie powstaje duplikat)

### 4. **Walidacje**

Pozostały tylko podstawowe walidacje:
- ✅ Pojedynczy udział: 0-100%
- ❌ Usunięto: suma musi być ≤ 100%

## Korzyści

1. ✅ **Łatwość użycia** - nie trzeba liczyć do 100%, można edytować wpisy
2. ✅ **Elastyczność** - można dodawać działy stopniowo
3. ✅ **Prostota** - uproszczony interfejs bez skomplikowanych wyjaśnień
4. ✅ **Edycja** - nie trzeba usuwać i dodawać od nowa
5. ✅ **Bezpieczeństwo** - walidacja pojedynczych wartości pozostała
6. ✅ **Czytelność** - format 2 miejsca po przecinku (35,50% zamiast 35,5000%)

## Pliki zmienione

- `App/Services/Production/Cost/CostMonthService.php`
- `App/Services/Production/Cost/CostCalculatorService.php`
- `App/Controllers/Web/Production/Cost/CostCalculatorController.php`
- `App/Views/production/cost_calculator/show.view.php`

## Przyszłe ulepszenia (opcjonalnie)

- Dodać tooltip z wyjaśnieniem normalizacji
- Eksport wyników do Excel z kolumną Media
- Raport porównawczy kosztów mediów między miesiącami
- Alert gdy żaden dział nie ma alokacji dla medium

