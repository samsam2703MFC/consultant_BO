# Migracja 011: Integracja kalkulatora kosztów z analizą rentowności produktów

**Data:** 2026-02-26  
**Autor:** System  
**Status:** Zaimplementowane

## Cel

Aktualizacja analizy rentowności produktów aby używała nowej struktury alokacji kosztów mediów z modułu `cost_calculator` zamiast starych kolumn w tabeli `production_sections`.

## Problem

Stara implementacja:
- Koszty zmienne (media) przechowywane w kolumnach `water_cost`, `power_supply_cost`, `fuel_cost`, `other_cost` w tabeli `production_sections`
- Brak historii kosztów - tylko aktualna wartość
- Brak elastycznego przypisywania kosztów mediów do działów (procentowe udziały)
- Niemożność analizy rentowności dla różnych miesięcy rozliczeniowych

Nowa struktura:
- `cost_month` - miesiące rozliczeniowe
- `cost_media_month` - koszty mediów per miesiąc
- `cost_media_section_allocation` - procentowe udziały działów w kosztach mediów

## Rozwiązanie

### 1. **Parametr `cost_month_id` w kalkulacjach**

Wszystkie metody obliczające rentowność przyjmują teraz opcjonalny parametr `cost_month_id`:
- `null` → używany **najnowszy** miesiąc rozliczeniowy
- `123` → używany konkretny miesiąc (analiza historyczna)

### 2. **Nowa logika obliczania kosztów zmiennych**

#### W `ProductProfitabilityService::calculateVariableCostPerUnit()`:

```php
// 1. Określ miesiąc rozliczeniowy
if ($costMonthId === null) {
    $latestMonth = $this->costMonthRepo->getLatest();
    $costMonthId = $latestMonth['id'] ?? null;
}

// 2. Pobierz koszty z nowej struktury (cost_media_section_allocation)
$totalVariableCosts = $this->mediaAllocationRepo->getTotalAllocatedCostForSection(
    $costMonthId, 
    $sectionId
);

// 3. Fallback do starych kolumn jeśli brak danych
if ($totalVariableCosts == 0.0) {
    $sectionCosts = $this->profitabilityRepository->getSectionCosts($sectionId);
    $totalVariableCosts = water_cost + power_supply_cost + fuel_cost + other_cost;
}

// 4. Rozdziel proporcjonalnie między produkty
$productProportion = $monthlyQty / $totalSectionProduction;
$allocatedVariableCost = $totalVariableCosts * $productProportion;
return $allocatedVariableCost / $monthlyQty;
```

### 3. **UI z wyborem miesiąca rozliczeniowego**

#### Widok listy (`/products/profitability`):
- Dropdown z listą miesięcy rozliczeniowych
- Auto-submit przy zmianie miesiąca
- Pokazanie aktualnie wybranego miesiąca
- Ikona 🔒 dla zamkniętych miesięcy

#### Widok szczegółów (`/products/{id}/profitability-details`):
- Kompaktowy dropdown na górze strony
- Tooltip: "Koszty zmienne (media) z wybranego miesiąca"
- Zachowanie parametru w URL

#### API `/ajax/products/{id}/profitability/calculate`:
- Nowy parametr w JSON: `"cost_month_id": 123`
- Kompatybilność wsteczna - brak parametru = najnowszy miesiąc

## Implementacja techniczna

### Backend

#### 1. Nowe metody w repozytoriach

**`CostMediaSectionAllocationRepository`:**
```php
// Pobierz szczegółowe koszty dla działu
getAllocatedCostsForSection(int $costMonthId, int $sectionId): array

// Pobierz sumę kosztów dla działu  
getTotalAllocatedCostForSection(int $costMonthId, int $sectionId): float
```

**`CostMonthRepository`:**
```php
// Najnowszy miesiąc (default)
getLatest(): array

// Po roku i miesiącu
getByYearMonth(int $year, int $month): array

// Lista dla dropdown (ostatnie 24 miesiące)
listAll(int $limit = 24): array
```

#### 2. Zaktualizowane serwisy

**`ProductProfitabilityService`:**
- Dependency injection: `CostMediaSectionAllocationRepository`, `CostMonthRepository`
- `calculateProfitability($id, $price, $qty, $costMonthId)` - nowy parametr
- `calculateVariableCostPerUnit($product, $costMonthId)` - nowa logika z fallbackiem
- `getProductListWithKPIs($search, $costMonthId)` - nowy parametr

#### 3. Zaktualizowane kontrolery

**`ProfitabilityController`:**
- Dependency injection: `CostMonthRepository`
- `list()` - obsługa `?cost_month_id=X`
- `details($id)` - obsługa `?cost_month_id=X`
- `ajaxCalculate($id)` - obsługa `"cost_month_id": X` w JSON

### Frontend

#### Widoki Twig
- `product/profitability/list.twig` - dropdown wyboru miesiąca
- `product/profitability/details.twig` - dropdown wyboru miesiąca

## Przykład użycia

### Scenariusz 1: Domyślna analiza (najnowszy miesiąc)
```
GET /products/profitability
→ używa najnowszego cost_month (np. 2026-02)
→ koszty zmienne z alokacji dla lutego 2026
```

### Scenariusz 2: Analiza historyczna
```
GET /products/profitability?cost_month_id=45
→ używa cost_month ID=45 (np. 2025-12)
→ koszty zmienne z alokacji dla grudnia 2025
→ porównanie rentowności między miesiącami
```

### Scenariusz 3: What-if z konkretnym miesiącem
```
POST /ajax/products/123/profitability/calculate
Body: {
    "sales_price": 12.50,
    "monthly_quantity": 10000,
    "cost_month_id": 45
}
→ symulacja ze starymi kosztami mediów
```

## Backward Compatibility

✅ **Pełna kompatybilność wsteczna:**

### Fallback do starych kolumn
Jeśli brak danych w `cost_media_section_allocation`:
- System używa starych kolumn z `production_sections`
- `water_cost`, `power_supply_cost`, `fuel_cost`, `other_cost`
- Ostrzeżenie w logu: "Using legacy cost columns for section X"

### Parametr opcjonalny
- `cost_month_id` = null → automatycznie najnowszy miesiąc
- Istniejące wywołania API działają bez zmian

### Stare widoki
- Można stopniowo migrować dane
- System działa z obiema strukturami jednocześnie

## Korzyści

1. ✅ **Analiza historyczna** - rentowność dla każdego miesiąca osobno
2. ✅ **Dokładność** - rzeczywiste koszty mediów z podziałem procentowym
3. ✅ **Elastyczność** - zmiana alokacji nie wpływa na historię
4. ✅ **Porównywalność** - łatwe porównanie rentowności między miesiącami
5. ✅ **Audyt** - pełna historia zmian kosztów zmiennych
6. ✅ **Fallback** - bezpieczne przejście ze starej struktury

## Przykład obliczeń

### Stara metoda (przed):
```
Sekcja Produkcja:
- water_cost = 500 zł (stała wartość w tabeli)
- power_supply_cost = 1000 zł
- fuel_cost = 200 zł
- other_cost = 300 zł
SUMA: 2000 zł

Produkt A (1000 szt, 50% produkcji sekcji):
→ 1000 zł kosztów zmiennych (50% z 2000 zł)
→ 1.00 zł/szt
```

### Nowa metoda (teraz):
```
Miesiąc: 2026-02 (cost_month_id = 50)

Koszty mediów:
- Prąd: 1200 zł → Produkcja 70% = 840 zł
- Woda: 400 zł → Produkcja 80% = 320 zł  
- Gaz: 600 zł → Produkcja 50% = 300 zł
SUMA: 1460 zł (dla działu Produkcja)

Produkt A (1000 szt, 50% produkcji sekcji):
→ 730 zł kosztów zmiennych (50% z 1460 zł)
→ 0.73 zł/szt

Różnica: -0.27 zł/szt (dokładniejsza alokacja)
```

## Testy

### Manualne
1. ✅ Otwórz `/products/profitability` - sprawdź dropdown miesięcy
2. ✅ Zmień miesiąc - sprawdź czy koszty się zmieniają
3. ✅ Otwórz szczegóły produktu - sprawdź dropdown
4. ✅ Sprawdź what-if kalkulator z `cost_month_id`
5. ✅ Usuń dane z `cost_media_section_allocation` - sprawdź fallback

### Automatyczne (TODO)
- Unit testy dla `calculateVariableCostPerUnit()`
- Integration testy dla kontrolerów
- Test fallback do starych kolumn

## Pliki zmienione

### Backend
- `App/Repositories/Production/Cost/CostMediaSectionAllocationRepository.php` - nowe metody
- `App/Repositories/Production/Cost/CostMonthRepository.php` - nowe metody
- `App/Services/Product/ProductProfitabilityService.php` - nowa logika
- `App/Controllers/Web/Product/ProfitabilityController.php` - obsługa parametru

### Frontend
- `App/Views/product/profitability/list.twig` - dropdown miesiąca
- `App/Views/product/profitability/details.twig` - dropdown miesiąca

## Następne kroki (opcjonalnie)

1. **CategoryProfitabilityService** - analogiczna aktualizacja dla kategorii
2. **ProductionSectionController** - aktualizacja widoków sekcji produkcyjnych
3. **Export do Excel** - dodać kolumnę z miesiącem rozliczeniowym
4. **Dashboard** - porównanie rentowności między miesiącami
5. **Migracja danych** - skrypt do przeniesienia starych kosztów do nowej struktury

## SQL do weryfikacji

```sql
-- Sprawdź czy są dane w nowej strukturze
SELECT cm.period_year, cm.period_month, COUNT(*) as allocations
FROM cost_month cm
LEFT JOIN cost_media_section_allocation csa ON cm.id = csa.cost_month_id
GROUP BY cm.id, cm.period_year, cm.period_month
ORDER BY cm.period_year DESC, cm.period_month DESC;

-- Porównaj koszty zmienne (stara vs nowa struktura)
SELECT 
    ps.id,
    ps.name,
    -- Stara struktura
    (ps.water_cost + ps.power_supply_cost + ps.fuel_cost + ps.other_cost) as old_costs,
    -- Nowa struktura (dla ostatniego miesiąca)
    (SELECT COALESCE(SUM(cmm.amount * (csa.allocation_percent / 100)), 0)
     FROM cost_media_section_allocation csa
     JOIN cost_media_month cmm ON csa.cost_month_id = cmm.cost_month_id AND csa.media_type_id = cmm.media_type_id
     WHERE csa.section_id = ps.id 
     AND csa.cost_month_id = (SELECT id FROM cost_month ORDER BY period_year DESC, period_month DESC LIMIT 1)
    ) as new_costs
FROM production_sections ps;
```

