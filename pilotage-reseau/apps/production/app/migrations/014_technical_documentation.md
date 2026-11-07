# Dokumentacja techniczna - Modernizacja widoku edycji zamówienia

## Przegląd zmian

### Zmienione pliki
- `App/Views/client/order/edit.view.php` - kompletne przepisanie

### Niezmienione pliki (już kompatybilne)
- `App/Controllers/Web/Client/Order/OrderController.php`
- `App/Services/Client/Order/OrderService.php`
- `App/Repositories/Client/Order/OrderRepository.php`

## Architektura danych

### Price List Flow

```
OrderController::editOrder()
    ↓
ExternalPriceListService::getActiveByClient() 
  lub
ClientProductPriceService::getActiveByClient()
    ↓
$data['price_list'] = Array<PriceListItemModel> | Array<array>
    ↓
edit.view.php
    ↓
JavaScript: priceListData{}
```

### Data Structure

#### PriceListItemModel (Object)
```php
class PriceListItemModel {
    private $id_product;      // int
    private $sku;             // string
    private $product_name;    // string
    private $price_net;       // float
    private $vat_rate;        // float
    private $package_size;    // float
    private $package_unit;    // string
    private $is_active;       // int (0|1)
    private $valid_from;      // string|null (date)
    
    // Getters...
}
```

#### Price List (Array)
```php
[
    'id_product' => 1001,
    'sku' => '1001',
    'product_name' => 'Baguette Tradition',
    'price_net' => 31.12,
    'vat_rate' => 0.00,
    'package_size' => 18.000,
    'package_unit' => 'pcs',
    'is_active' => 1,
    'valid_from' => '2026-02-27'
]
```

### Kompatybilność w widoku

Widok obsługuje obydwa formaty:

```php
<?php 
    $priceNet = is_object($item) ? $item->getPriceNet() : $item['price_net'];
    $idProduct = is_object($item) ? $item->getIdProduct() : $item['id_product'];
    // etc...
?>
```

## Frontend Architecture

### JavaScript Modules

#### 1. Data Cache
```javascript
const priceListData = {
    [productId]: {
        id: number,
        price: float,
        sku: string,
        name: string,
        package: string
    }
}
```

#### 2. Event Handlers
- `DOMContentLoaded` - Inicjalizacja
- `change` - Aktualizacja ceny przy wyborze produktu
- `input` - Real-time obliczenia przy zmianie ilości
- `submit` - Walidacja przed wysłaniem

#### 3. Core Functions

**updatePriceDisplay()**
- Triggerowane: Zmiana selecta produktu
- Akcja: Wypełnia pole ceny, resetuje ilość do 1

**addProductToOrder()**
- Triggerowane: Klik "Dodaj" lub Ctrl+Enter
- Walidacja:
  - Produkt wybrany
  - Ilość > 0
  - Brak duplikatu
- Akcja: Dodaje wiersz do tabeli, czyści formularz

**removeRow(button)**
- Triggerowane: Klik przycisk 🗑
- Akcja: Usuwa wiersz z potwierdzeniem, przelicza sumę

**updateLineTotal(input)**
- Triggerowane: Zmiana ilości
- Akcja: Przelicza wartość linii, waliduje

**calculateOrderTotal()**
- Triggerowane: Każda zmiana w produktach
- Akcja: Sumuje wszystkie wartości linii

**formatPrice(value)**
- Format: Polski (przecinek dziesiętny, spacja tysiące)
- Przykład: 1234.56 → "1 234,56"

**showNotification(message, type)**
- Typy: success, info, warning, danger
- Czas: Auto-hide po 5s
- Pozycja: Prawy górny róg

### Form Data Structure (POST)

```php
$_POST = [
    'expected_shipping_date' => '2026-03-15',
    'order_description' => 'Komentarz...',
    'standard_price_list' => 1,  // lub brak klucza
    'product' => [
        0 => [1001 => 5],    // id_product => quantity
        1 => [1002 => 10],
        2 => [1003 => 3]
    ],
    'price' => [             // tylko dla referencji, disabled w submit
        0 => [1001 => 31.12],
        1 => [1002 => 42.50],
        2 => [1003 => 38.00]
    ]
]
```

## CSS Customization

### Key Classes

```css
/* Validation */
.quantity-input.is-invalid { border-color: #dc3545; }

/* Hover effects */
[data-product-row]:hover { background-color: #f8f9fa; }

/* Typography */
.line-total { color: #198754; font-size: 1.05em; }
#orderTotal { font-size: 1.5rem; color: #0d6efd; }

/* Cards */
.card-header.bg-primary { background-color: #0d6efd !important; }

/* SKU display */
code { 
    background-color: #e9ecef; 
    padding: 0.2rem 0.4rem; 
    border-radius: 0.25rem; 
}
```

## Backend Integration

### Controller Method
```php
public function editOrder($id)
{
    // 1. Load order data
    $data['row'] = $this->orderService->getById($id);
    
    // 2. Load client
    $data['client'] = $this->clientService->getById($data['row']['id_customer']);
    
    // 3. Load products
    $data['row_products'] = $this->orderService->getProducts($id);
    
    // 4. Load price list (KEY CHANGE)
    if (!is_null($data['client']['franchisee_uid'])) {
        $data['price_list'] = $this->externalPriceListService->getActiveByClient($data['client']['id']);
    } else {
        $data['price_list'] = $this->clientProductPriceService->getActiveByClient($data['client']['id']);
    }
    
    // 5. Handle POST
    if ($_SERVER['REQUEST_METHOD'] == "POST") {
        if ($this->orderService->validate($_POST)) {
            $this->orderService->updateOrderWithProducts($id, $_POST);
            header('Location: ' . ROOT . '/client-orders');
            exit;
        } else {
            // Reload with errors
        }
    }
    
    // 6. Render view
    $this->view("client/order/edit", $data);
}
```

### Service Method (bez zmian)
```php
public function updateOrderWithProducts($id, $data)
{
    return $this->transactionManager->execute(function () use ($id, $data) {
        // 1. Update order
        $this->orderRepository->update($id, [...]);
        
        // 2. Delete old products
        $this->orderProductRepository->delete($id, 'id_order');
        
        // 3. Insert new products
        if ($data['standard_price_list'] == 1) {
            $this->orderProductRepository->insertForOrderWithStandardPrice($id, $data['product']);
        } else {
            $this->orderProductRepository->insertForOrderWithClientPrice($id, $data['id_customer'], $data['product']);
        }
        
        // 4. Update proforma if exists
        // ...
        
        return true;
    });
}
```

## Performance Considerations

### Optimizations
1. **Price List Cache**: Dane cennika są cachowane w JavaScript przy inicjalizacji
2. **Minimal DOM Operations**: Tylko konieczne aktualizacje DOM
3. **Event Delegation**: Jeden handler na input events zamiast wielu
4. **Debouncing**: Nie zastosowano - nie potrzebne dla prostych obliczeń

### Bottlenecks
1. **Duży cennik (>1000 produktów)**: Select może być wolny
   - Rozwiązanie: Implementacja Choices.js z searchem
2. **Dużo produktów w zamówieniu (>100)**: Scrollowanie może być wolniejsze
   - Rozwiązanie: Wirtualizacja tabeli (opcjonalnie)

## Security

### Frontend
- ✅ Input validation (client-side)
- ✅ Type checking (number inputs)
- ✅ XSS prevention (nie używamy innerHTML z user input)

### Backend
- ✅ Input validation (server-side)
- ✅ XSS prevention (htmlspecialchars w widoku)
- ✅ SQL Injection prevention (PDO prepared statements w repository)
- ✅ CSRF protection (framework level)
- ✅ Transaction management (atomic updates)

## Error Handling

### JavaScript Errors
```javascript
try {
    // operation
} catch (error) {
    console.error('Error:', error);
    showNotification('Wystąpił błąd', 'danger');
}
```

### PHP Errors
- Walidacja na poziomie kontrolera
- Exceptions w service layer
- Transaction rollback przy błędzie

## Browser Compatibility

### Wymagane funkcje
- ES6+ (Arrow functions, const/let, template literals)
- Array methods (forEach, find, map)
- DOM API (querySelector, closest, addEventListener)
- Number.toLocaleString()

### Wspierane przeglądarki
- Chrome 60+
- Firefox 60+
- Edge 79+
- Safari 12+

### Fallback dla starszych przeglądarek
Brak - aplikacja wymaga nowoczesnej przeglądarki.

## Testing Strategy

### Unit Tests (Manual)
- [ ] formatPrice() - różne wartości
- [ ] calculateOrderTotal() - różne scenariusze
- [ ] Walidacja - edge cases

### Integration Tests
- [ ] Dodawanie produktu end-to-end
- [ ] Zapisywanie zamówienia
- [ ] Ładowanie istniejącego zamówienia

### E2E Tests
- [ ] Pełny flow użytkownika
- [ ] Różne typy klientów
- [ ] Różne typy cenników

## Migration Path

### From Old Version
1. Backup bazy danych
2. Backup pliku edit.view.php
3. Deploy nowego pliku
4. Smoke test na środowisku testowym
5. Deploy produkcyjny

### Rollback Procedure
1. Restore edit.view.php z backupu
2. Clear cache (jeśli applicable)
3. Restart web server (opcjonalnie)

## Known Issues

### 1. Choices.js
**Problem**: Biblioteka może sprawiać problemy  
**Workaround**: Używamy natywnego select  
**Future**: Rozważyć Select2 lub Tom Select

### 2. Bootstrap Icons
**Problem**: Wymagają CDN lub local fonts  
**Workaround**: Fallback na emoji jeśli nie załadują się  
**Future**: Bundle icons lokalnie

### 3. Number formatting w różnych locale
**Problem**: toLocaleString może działać inaczej w różnych przeglądarkach  
**Workaround**: Wymuszamy 'pl-PL' locale  
**Future**: Server-side formatting w critical places

## Future Enhancements

### Priority 1 (High)
- [ ] Search/autocomplete dla produktów (Choices.js replacement)
- [ ] Loading states przy zapisywaniu
- [ ] Optimistic UI updates

### Priority 2 (Medium)
- [ ] Historia zmian zamówienia
- [ ] Masowe dodawanie produktów (CSV import)
- [ ] Print view dla zamówienia
- [ ] Export do PDF

### Priority 3 (Low)
- [ ] Drag & drop reordering produktów
- [ ] Kalkulacja marży w czasie rzeczywistym
- [ ] Integracja z stanami magazynowymi
- [ ] Podpowiedzi AI dla podobnych zamówień

## API Reference

### Exposed Global Functions
Brak - wszystkie funkcje są w scope

### Events
- `DOMContentLoaded` - Inicjalizacja aplikacji

### Data Attributes
- `data-product-row` - Identyfikuje wiersz produktu
- `data-price` - Cena produktu na input
- `data-sku` - SKU na option
- `data-package` - Info o opakowaniu na option

## Deployment Checklist

- [ ] Backup bazy danych
- [ ] Backup starego pliku
- [ ] Deploy nowego pliku
- [ ] Test na dev/staging
- [ ] Clear cache przeglądarki
- [ ] Smoke test wszystkich flow
- [ ] Monitor error logs
- [ ] User acceptance testing
- [ ] Deploy na produkcję
- [ ] Monitor na produkcji (24h)

## Support & Maintenance

### Logs do monitorowania
- JavaScript console errors
- PHP error logs
- Database transaction logs
- Network errors (404, 500)

### Common Issues & Solutions

**Problem**: Suma się nie oblicza  
**Rozwiązanie**: Sprawdź console, odśwież stronę

**Problem**: Nie można dodać produktu  
**Rozwiązanie**: Sprawdź czy cennik jest pusty, sprawdź console

**Problem**: Ceny są niepoprawne  
**Rozwiązanie**: Sprawdź dane w price_list, sprawdź ExternalPriceListService

**Problem**: Nie można zapisać  
**Rozwiązanie**: Sprawdź walidację, sprawdź PHP logs

## Contact & Credits

**Autor**: GitHub Copilot Agent  
**Data**: 2026-03-04  
**Wersja**: 1.0.0  
**Framework**: PHP 8.x + Bootstrap 5  

**Dependencies**:
- Bootstrap 5.x
- Bootstrap Icons 1.x
- PHP 8.x
- PDO
- Symfony HttpFoundation (dla Request/Response)

---

Dla pytań technicznych zobacz:
- `migrations/014_order_edit_view_modernization.md` - Opis zmian
- `migrations/014_user_guide_order_edit.md` - Przewodnik użytkownika
- `migrations/014_testing_checklist.md` - Lista testów
- `migrations/014_ui_visualization.md` - Wizualizacja UI

