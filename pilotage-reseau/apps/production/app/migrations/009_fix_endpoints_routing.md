# Poprawa endpointów w show.view.php

**Data:** 2026-02-26  
**Typ:** Fix  

## Problem

Endpointy w pliku `show.view.php` nie odpowiadały rzeczywistym routom zdefiniowanym w `App/Core/Routes/Production/CostRoutes.php`.

## Poprawione endpointy

### 1. **deleteMediaCost**
**Przed:**
```javascript
api(`/ajax/cost-calculator/${MONTH_ID}/media-costs/${id}`, {method: "DELETE"})
```
- Użycie nieistniejącej funkcji `api()`
- Routing wspiera POST z `/delete` na końcu

**Po:**
```javascript
postJson("/ajax/cost-calculator/" + MONTH_ID + "/media-costs/" + id + "/delete", {})
```
✅ Endpoint: `POST /ajax/cost-calculator/{monthId:\d+}/media-costs/{id:\d+}/delete`

### 2. **deleteMediaAllocation**
**Przed:**
```javascript
postJson("/ajax/cost-calculator/" + MONTH_ID + "/media-allocations/" + id + "/delete", {})
```
- Używał POST z `/delete`, ale routing wspiera DELETE bez `/delete`

**Po:**
```javascript
fetch("/ajax/cost-calculator/" + MONTH_ID + "/media-allocations/" + id, {
    method: 'DELETE',
    headers: {'Content-Type': 'application/json'}
})
```
✅ Endpoint: `DELETE /ajax/cost-calculator/{monthId:\d+}/media-allocations/{id:\d+}`

### 3. **Usunięte nieużywane funkcje**
- ❌ `updateAllocationPreview()` - skomplikowana, nie używana w uproszczonym UI
- ❌ `setRemainingPercent()` - przycisk "Pozostałe" usunięty z UI
- ✅ `setQuickPercent()` - uproszczona (bez wywoływania updateAllocationPreview)

## Routing summary (z CostRoutes.php)

### Pracownicy
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/employees/add`
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/employees/{id:\d+}/delete`

### Koszty stałe
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/fixed-costs/add`
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/fixed-costs/{id}/delete`

### Koszty mediów
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/media-costs/add`
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/media-costs/{id:\d+}/delete`
- ✅ `DELETE /ajax/cost-calculator/{monthId:\d+}/media-costs/{id:\d+}` (alternatywny)

### Alokacje mediów
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/media-allocations/upsert`
- ✅ `DELETE /ajax/cost-calculator/{monthId:\d+}/media-allocations/{id:\d+}`

### Inne
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/copy-prev`
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/sync-cartons`
- ✅ `POST /ajax/cost-calculator/{monthId:\d+}/set-manual-cartons`
- ✅ `GET /ajax/cost-calculator/{monthId:\d+}/results`

## Zmiany w kodzie

### Pliki zmienione:
- `App/Views/production/cost_calculator/show.view.php`

### Funkcje zaktualizowane:
1. `deleteMediaCost()` - zmieniona na POST z `/delete`
2. `deleteMediaAllocation()` - zmieniona na DELETE bez `/delete`

### Funkcje usunięte:
1. `updateAllocationPreview()` - ~100 linii
2. `setRemainingPercent()` - ~35 linii
3. Uproszczono `setQuickPercent()` - tylko ustawia wartość

## Benefity

1. ✅ **Zgodność z routingiem** - wszystkie endpointy odpowiadają rzeczywistym routom
2. ✅ **Mniej kodu** - usunięto ~135 linii nieużywanego kodu
3. ✅ **Lepszy UX** - uproszczony modal bez skomplikowanych funkcji
4. ✅ **Konsystencja** - jednolite użycie `postJson()` dla POST, `fetch()` dla DELETE

## Testy

Sprawdź:
1. ✅ Usuwanie kosztów mediów - POST endpoint
2. ✅ Usuwanie alokacji - DELETE endpoint  
3. ✅ Szybkie przyciski % - działają bez updateAllocationPreview
4. ✅ Brak błędów 404 w konsoli przeglądarki

