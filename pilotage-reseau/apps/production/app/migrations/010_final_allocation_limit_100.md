# Migracja 008: Edycja wpisów alokacji mediów + Twardy limit 100%

**Data:** 2026-02-26  
**Autor:** System  
**Status:** Zaimplementowane

## Cel

1. Umożliwienie **łatwej edycji istniejących wpisów** alokacji mediów
2. Wprowadzenie **twardego limitu suma ≤ 100%** dla udziałów kosztów mediów w działach

## Problem

Poprzednie iteracje:
1. Brakowało możliwości edycji wpisów - trzeba było je usuwać i dodawać od nowa
2. Wymaganie dokładnie 100% było trudne do wyegzekwowania
3. Próba z automatyczną normalizacją okazała się zbyt skomplikowana dla użytkowników

## Ostateczne rozwiązanie

### 1. **Twardy limit 100% (bez automatycznej normalizacji)**

System **blokuje** dodanie/edycję udziału, jeśli suma przekroczy 100%:

```
Medium: Prąd (1000 zł/miesiąc)
- Produkcja: 60%  → 600 zł
- Pakowanie: 30%  → 300 zł
Suma: 90% ✅ (100 zł nieprzypisane)

Próba dodania Logistyka: 20% ❌
→ Błąd: "Przekroczenie 100%! Aktualna suma: 90%. Możesz przypisać max: 10%"
```

**Dozwolone scenariusze:**
- ✅ Suma = 100% (idealnie - cały koszt rozdzielony)
- ✅ Suma < 100% (ok - część kosztu nieprzypisana do działów)
- ❌ Suma > 100% (ZABLOKOWANE - system odmawia zapisu)

### 2. **Edycja wpisów** ⭐

- Przycisk **"Edytuj"** (ikona ołówka) przy każdym wpisie
- Modal otwiera się z wypełnionymi danymi
- Tytuł: "Edycja udziału" zamiast "Dodawanie"
- Hidden input `ma_edit_id` przechowuje ID edytowanego wpisu
- Walidacja pomija edytowany wpis przy obliczaniu sumy
- Po zapisie - ten sam wiersz się aktualizuje (nie duplikuje)
- Animacja: wiersz miga na zielono po zapisie

## Implementacja techniczna

### Backend

#### `CostMonthService.php`
```php
// Przywrócona walidacja sumy ≤ 100%
$sumOther = $this->mediaAllocationRepo->getSumByMonthAndMediaType(
    $monthId, 
    $mediaTypeId, 
    $excludeId  // pomija edytowany wpis
);
$total = $sumOther + $percent;

if ($total > 100.01) { // tolerancja 0.01% na zaokrąglenia
    return [
        'id' => 0, 
        'error' => 'ALLOCATION_SUM_EXCEEDED',
        'current_sum' => $sumOther,
        'max_allowed' => max(0, 100 - $sumOther)
    ];
}
```

#### `CostCalculatorController.php`
- Obsługuje błąd `ALLOCATION_SUM_EXCEEDED`
- Zwraca JSON z `current_sum` i `max_allowed`

#### `CostCalculatorService.php`
- Rozdziela koszty mediów proporcjonalnie:
  - Jeśli suma = 100% → `koszt * (procent / 100)`
  - Jeśli suma < 100% → również `koszt * (procent / 100)` (reszta nieprzypisana)
- Dodano kolumnę `media_per_carton` w wynikach

### Frontend

#### Walidacja JS (przed wysłaniem)
```javascript
// Oblicz aktualną sumę (bez edytowanego wpisu)
let sumOther = 0;
body.querySelectorAll('tr[data-media-id]').forEach(tr => {
    if(rowId === editId) return; // pomijamy edytowany
    sumOther += val;
});

// Sprawdź czy nowa suma przekroczy 100%
if(sumOther + percent > 100.01){
    toast(`Przekroczenie 100%! Aktualna suma: ${sumOther}%. Max: ${100 - sumOther}%`);
    return;
}
```

#### Podsumowania per medium
Wizualne wskaźniki stanu:
- 🟢 **Zielony** (alert-success) gdy suma ≈ 100%
- 🟡 **Żółty** (alert-warning) gdy suma > 90%
- 🔴 **Czerwony** (alert-danger) gdy suma > 100%
  - Komunikat: "⚠️ Przekroczenie! Usuń lub zmniejsz udziały."

#### Uproszczony modal
- Bez skomplikowanych wyjaśnień normalizacji
- Większe pole input (dla lepszej widoczności)
- Proste podsumowanie: lista działów z %
- Szybkie przyciski: 25%, 33%, 50%, 100%

## Walidacje

### Backend
- ✅ Pojedynczy udział: 0-100%
- ✅ Suma udziałów dla medium: ≤ 100.01%
- ✅ Tolerancja 0.01% na błędy zaokrągleń

### Frontend
- ✅ Walidacja przed wysłaniem (natychmiastowy feedback)
- ✅ Czytelne komunikaty: "Aktualna suma X%, możesz przypisać max Y%"
- ✅ Pomija edytowany wpis przy obliczaniu sumy

## Korzyści

1. ✅ **Prostota** - jasna reguła: suma ≤ 100%
2. ✅ **Edycja** - nie trzeba usuwać i dodawać od nowa
3. ✅ **Czytelność** - komunikaty błędów pokazują dokładnie co jest nie tak
4. ✅ **Bezpieczeństwo** - niemożliwe przypisanie więcej niż 100% kosztu
5. ✅ **Elastyczność** - dozwolona suma < 100% (reszta nieprzypisana)
6. ✅ **UX** - kolorowe wskaźniki, animacje, natychmiastowy feedback

## Przykłady użycia

### Przykład 1: Pełne rozdzielenie (100%)
```
Medium: Prąd (1000 zł)
- Produkcja: 70%   → 700 zł
- Pakowanie: 20%   → 200 zł
- Logistyka: 10%   → 100 zł
Suma: 100% ✅ (cały koszt rozdzielony)
```

### Przykład 2: Częściowe rozdzielenie (<100%)
```
Medium: Woda (500 zł)
- Produkcja: 60%   → 300 zł
- Sprzątanie: 20%  → 100 zł
Suma: 80% ✅ (100 zł nieprzypisane do działów)
```

### Przykład 3: Próba przekroczenia (>100%) ❌
```
Medium: Gaz (800 zł)
- Gotowanie: 70%   → 560 zł
- Pieczenie: 40%   → (próba dodania)
Suma: 110% ❌ ZABLOKOWANE

Komunikat: "Przekroczenie 100%! Aktualna suma: 70%. Możesz przypisać max: 30%"
```

### Przykład 4: Edycja wpisu
```
Było:
- Produkcja: 50%
- Pakowanie: 40%
Suma: 90%

Edycja Produkcja → 60%:
- Nowa suma: 100% ✅ (60% + 40%)

Edycja Produkcja → 65%:
- Próba: 105% ❌ ZABLOKOWANE
- Komunikat: "Max: 60%" (bo 40% już zajęte przez Pakowanie)
```

## Backward Compatibility

✅ **Pełna kompatybilność**
- Istniejące alokacje działają bez zmian
- Dane w bazie bez migracji
- Jeśli stare dane mają sumę > 100% - zostaną wyświetlone z czerwonym alertem

## Testy manualne

Sprawdź:
1. ✅ Dodaj nową alokację - sprawdź walidację sumy
2. ✅ Kliknij "Edytuj" - modal wypełnia się danymi
3. ✅ Edytuj % i zapisz - wiersz się aktualizuje (nie duplikuje)
4. ✅ Spróbuj przekroczyć 100% - komunikat błędu z max wartością
5. ✅ Sprawdź kolory podsumowań (zielony/żółty/czerwony)
6. ✅ Sprawdź wyniki kalkulacji - kolumna Media/karton

## Pliki zmienione

- `App/Services/Production/Cost/CostMonthService.php`
- `App/Services/Production/Cost/CostCalculatorService.php`
- `App/Controllers/Web/Production/Cost/CostCalculatorController.php`
- `App/Repositories/Production/Cost/CostMediaSectionAllocationRepository.php`
- `App/Views/production/cost_calculator/show.view.php`

