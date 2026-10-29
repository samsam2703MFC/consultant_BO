# Migracja 012: Integracja kalkulatora kosztów z analizą kosztów sekcji

**Data:** 2026-02-26  
**Autor:** System  
**Status:** Zaimplementowane

## Cel

Aktualizacja analizy kosztów sekcji produkcyjnej (`CostAnalysisSectionService`) aby używała nowej struktury alokacji kosztów mediów z modułu `cost_calculator` zamiast starych kolumn w tabeli `production_sections`.

## Problem

Podobnie jak w przypadku analizy rentowności produktów, analiza kosztów sekcji używała starych kolumn:
- `water_cost`, `power_supply_cost`, `fuel_cost`, `other_cost` w tabeli `production_sections`
- Brak historii kosztów zmiennych
- Brak elastycznego przypisywania kosztów mediów przez procentowe udziały

## Rozwiązanie

### Zaktualizowana logika obliczania kosztów

**W `CostAnalysisSectionService::calculate()`:**

```php
// PRZED:
$sectionFixedCost = depreciation + water_cost + power_supply_cost + fuel_cost + other_cost;
$sectionCostPool = $employeeCost + $sectionFixedCost;

// PO:
// 1. Pobierz koszty mediów z nowej struktury
$mediaCosts = $this->mediaAllocationRepo->getTotalAllocatedCostForSection($monthId, $sectionId);

// 2. Fallback do starych kolumn
if ($mediaCosts == 0.0) {
    $mediaCosts = water_cost + power_supply_cost + fuel_cost + other_cost;
}

// 3. Rozdziel na kategorie
$fixedCosts = depreciation;  // tylko amortyzacja
$sectionCostPool = $employeeCost + $fixedCosts + $mediaCosts;
```

### Podział kosztów w wynikach

**`explanation` teraz zawiera:**
- `employee_cost` - koszty pracowników
- `fixed_costs` - koszty stałe (amortyzacja)
- `media_costs` - koszty zmienne (media z alokacji)
- `section_cost_pool` - suma wszystkich kosztów sekcji

## Implementacja techniczna

### Backend

#### 1. Zaktualizowany serwis

**`CostAnalysisSectionService`:**
- Dependency injection: `CostMediaSectionAllocationRepository`, `CostMonthRepository`
- Nowa logika w `calculate()`:
  - Pobiera koszty mediów z `cost_media_section_allocation`
  - Fallback do starych kolumn jeśli brak danych
  - Rozdziela koszty na: employee, fixed, media
- Zwraca `fixed_costs` i `media_costs` zamiast `section_fixed_cost`

### Frontend

#### Widok Twig

**`section_overview.twig`:**
- Zaktualizowano sekcję "Wyjaśnienie" (explanation)
- Zmieniono z:
  ```
  Koszty stałe sekcji: XXX PLN (jeden wiersz)
  ```
- Na:
  ```
  Koszty stałe (amortyzacja): XXX PLN
  Koszty zmienne (media): XXX PLN
  ```

## Jak działa analiza kosztów sekcji

### Algorytm:

```
1. Wybór sekcji i miesiąca rozliczeniowego
   ↓
2. Pobranie danych kosztowych:
   a) Koszty pracowników z cost_month_employee
   b) Koszty stałe (depreciation) z production_sections
   c) Koszty zmienne (media) z cost_media_section_allocation
      → jeśli brak: fallback do water_cost, power_supply_cost, etc.
   ↓
3. Suma kosztów sekcji (section_cost_pool)
   ↓
4. Podział kosztów na produkty:
   - Według workload_points (punkty obciążenia)
   - section_cost_per_point = section_cost_pool / total_weighted_units
   - Koszt produktu = workload_points × section_cost_per_point
   ↓
5. Obliczenie marży:
   Marża = Cena sprzedaży - (Surowce + Koszty sekcji + Koszty wspólne)
```

## Przykład obliczeń

### Sekcja: Produkcja, Miesiąc: 2026-02

**Koszty sekcji:**
```
Pracownicy:  50,000 PLN (z cost_month_employee)
Amortyzacja:  5,000 PLN (depreciation)
Media:       10,000 PLN (z cost_media_section_allocation):
  - Prąd: 6,000 PLN × 70% = 4,200 PLN
  - Woda: 3,000 PLN × 80% = 2,400 PLN
  - Gaz:  4,000 PLN × 85% = 3,400 PLN
───────────────────────────
SUMA:        65,000 PLN
```

**Produkcja sekcji:**
```
Total weighted units: 10,000 punktów
Cost per point: 65,000 / 10,000 = 6.50 PLN/punkt
```

**Produkty:**
```
Produkt A (workload: 3 pkt, 1000 kartonów):
→ Koszt sekcji: 3 × 6.50 = 19.50 PLN/karton

Produkt B (workload: 5 pkt, 500 kartonów):
→ Koszt sekcji: 5 × 6.50 = 32.50 PLN/karton
```

## Backward Compatibility

✅ **Pełna kompatybilność:**

### Fallback do starych kolumn
- Brak danych w `cost_media_section_allocation` → używa `water_cost`, etc.
- System sprawdza najpierw nową strukturę, potem stare kolumny

### Stopniowa migracja
- Można mieć dane w obu strukturach
- Nowa struktura ma priorytet
- Stara struktura jako backup

## Korzyści

1. ✅ **Dokładność** - rzeczywiste koszty mediów z % udziałami
2. ✅ **Historia** - koszty zmienne per miesiąc rozliczeniowy
3. ✅ **Przejrzystość** - rozdzielenie fixed vs media costs
4. ✅ **Spójność** - ta sama logika co w analizie rentowności produktów
5. ✅ **Fallback** - bezpieczne przejście ze starej struktury

## Różnice vs analiza rentowności produktów

| Cecha | Rentowność produktów | Analiza kosztów sekcji |
|-------|---------------------|------------------------|
| **Parametr miesiąca** | `?cost_month_id=X` w URL | Dropdown wyboru miesiąca |
| **Podział kosztów** | Proporcjonalnie do produkcji | Według workload_points |
| **Koszty wspólne** | Brak | Tak (shared_cost_unit) |
| **Użycie** | Analiza pojedynczego produktu | Analiza całej sekcji |

## Testy

### Manualne
1. ✅ Otwórz `/finances/cost-analysis/section`
2. ✅ Wybierz sekcję i miesiąc
3. ✅ Sprawdź czy koszty mediów są pobierane z nowej struktury
4. ✅ Usuń dane z `cost_media_section_allocation` - sprawdź fallback
5. ✅ Sprawdź sekcję "Wyjaśnienie" - powinny być dwie linie (fixed + media)

### Weryfikacja SQL
```sql
-- Sprawdź różnicę między starą i nową strukturą
SELECT 
    ps.id,
    ps.name as section_name,
    -- Stare koszty mediów
    (ps.water_cost + ps.power_supply_cost + ps.fuel_cost + ps.other_cost) as old_media_costs,
    -- Nowe koszty mediów (dla ostatniego miesiąca)
    (SELECT COALESCE(SUM(cmm.amount * (csa.allocation_percent / 100)), 0)
     FROM cost_media_section_allocation csa
     JOIN cost_media_month cmm ON csa.cost_month_id = cmm.cost_month_id 
         AND csa.media_type_id = cmm.media_type_id
     WHERE csa.section_id = ps.id 
     AND csa.cost_month_id = (SELECT id FROM cost_month ORDER BY period_year DESC, period_month DESC LIMIT 1)
    ) as new_media_costs
FROM production_sections ps
WHERE ps.id IN (SELECT DISTINCT section_id FROM cost_media_section_allocation);
```

## Pliki zmienione

### Backend
- `App/Services/Finances/CostAnalysisSectionService.php` - nowa logika kosztów mediów

### Frontend
- `App/Views/finances/cost-analysis/section_overview.twig` - rozdzielone fixed/media costs

## Następne kroki (opcjonalnie)

1. **Porównanie między miesiącami** - wykres trendów kosztów
2. **Alert** - powiadomienie gdy brak alokacji mediów dla sekcji
3. **Export do Excel** - z rozdzieleniem kosztów fixed/media
4. **Dashboard** - karta z kosztami mediów per sekcja

## Podsumowanie zmian w całym module profitability

### Zaktualizowane moduły:
1. ✅ **Rentowność produktów** (`ProductProfitabilityService`) - migracja 011
2. ✅ **Analiza kosztów sekcji** (`CostAnalysisSectionService`) - migracja 012

### Pozostałe do rozważenia:
- `CategoryProfitabilityService` - rentowność kategorii
- `ProductionSectionController::profitability*` - stare widoki sekcji
- Inne raporty używające kosztów zmiennych

### Zalecenie:
Wszystkie moduły analizy kosztów teraz używają tej samej, spójnej struktury danych z `cost_calculator`.

