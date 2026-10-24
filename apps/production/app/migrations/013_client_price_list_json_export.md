# Export cennika klienta w formacie JSON

**Data:** 2026-02-26  
**Autor:** System  
**Status:** Zaimplementowane

## Cel

Umożliwienie eksportu cennika klienta w formacie JSON z pełnymi danymi klienta (w tym `franchisee_uid`), datą/godziną exportu oraz listą produktów z cenami.

## Implementacja

### 1. Backend - Serwis

**`ClientProductPriceService::getByClientAndProductJSON()`**

```php
public function getByClientAndProductJSON($idClient): array
{
    // 1. Pobiera dane klienta
    $client = $this->clientRepository->first(['id' => $idClient]);
    
    // 2. Pobiera listę produktów z cenami
    $priceList = $this->finalProductCustomerPriceRepository->selectProductPriceArrByCustomer($idClient);
    
    // 3. Filtruje tylko dostępne produkty z ceną > 0
    $products = []; // tylko is_available = 1 && price > 0
    
    // 4. Przygotowuje strukturę JSON
    return [
        'meta' => [
            'generated_at' => '2026-02-26 14:30:00',
            'currency' => 'EUR',
            'products_count' => 25
        ],
        'client' => [
            'id' => 123,
            'company' => 'Pizza Franchisee Ltd',
            'franchisee_uid' => 'FR-2024-001',  // ← KLUCZOWE dla franczyz
            'franchisee_code' => 'PFR01',
            'franchise_network_id' => 5,
            'name' => 'John',
            'surname' => 'Doe',
            'tax_number' => 'PL1234567890',
            'email_head' => 'john@example.com',
            'phone_head' => '+48123456789',
            'default_currency' => 'EUR',
            'active' => 1
        ],
        'products' => [
            [
                'sku' => '101',
                'product_name' => 'Pizza Margherita',
                'gross_price' => 8.50,
                'net_price' => 8.50,
                'pack_quantity_in_base_unit' => 1,
                'is_available' => 1
            ],
            // ... więcej produktów
        ]
    ];
}
```

### 2. Backend - Kontroler

**`PriceListController::ajaxGetPriceListByClientJSON()`**

- Endpoint: `GET /ajax/clients/{id}/price-list/json`
- Zwraca: JSON z nagłówkiem `Content-Disposition: attachment`
- Nazwa pliku: `price_list_{company}_{YYYYMMDD_HHmmss}.json`

### 3. Routing

```php
$r->addRoute('GET', '/ajax/clients/{id:\d+}/price-list/json', [
    'controller' => App\Controllers\Web\Product\Price\PriceListController::class,
    'method' => 'ajaxGetPriceListByClientJSON'
]);
```

### 4. Frontend

**Przycisk w widoku (`client_price_list.view.php`):**
```html
<button class="btn btn-info" onclick="getPriceListByClientJSON(<?=$id_customer?>, this)">
    Pobierz cennik dostępnych produktów [.json]
</button>
```

**Funkcja JS:**
```javascript
async function getPriceListByClientJSON(idCustomer, btn) {
    // 1. Sprawdza czy są produkty z ceną 0 (ostrzeżenie)
    if(document.getElementById("zero-price-alert").innerText !== "") {
        // Pokaż dialog potwierdzenia
    }
    
    // 2. Pobiera JSON z API
    const response = await fetch(`/ajax/clients/${idCustomer}/price-list/json`);
    
    // 3. Tworzy link do pobrania
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.download = `price_list_${clientName}_${date}.json`;
    a.click();
}
```

## Przykładowy JSON

```json
{
  "meta": {
    "generated_at": "2026-02-26 14:30:15",
    "currency": "EUR",
    "products_count": 25
  },
  "client": {
    "id": 123,
    "company": "Pizza Franchisee Warsaw",
    "franchisee_uid": "FR-2024-WAW-001",
    "franchisee_code": "WAW001",
    "franchise_network_id": 5,
    "name": "Jan",
    "surname": "Kowalski",
    "tax_number": "PL1234567890",
    "email_head": "jan.kowalski@example.com",
    "phone_head": "+48123456789",
    "default_currency": "EUR",
    "active": 1
  },
  "products": [
    {
      "sku": "101",
      "product_name": "Pizza Margherita 30cm",
      "gross_price": 8.5,
      "net_price": 8.5,
      "pack_quantity_in_base_unit": 1,
      "is_available": 1
    },
    {
      "sku": "102",
      "product_name": "Pizza Pepperoni 30cm",
      "gross_price": 10.0,
      "net_price": 10.0,
      "pack_quantity_in_base_unit": 1,
      "is_available": 1
    }
  ]
}
```

## Kluczowe cechy

### 1. **Pełne dane klienta**
- ✅ `franchisee_uid` - kluczowy identyfikator dla franczyz
- ✅ `franchisee_code` - kod franczyzobiorcy
- ✅ `franchise_network_id` - ID sieci franczyzowej
- ✅ Dane kontaktowe (email, telefon)
- ✅ Status aktywności

### 2. **Metadane exportu**
- ✅ `generated_at` - dokładna data i godzina (Y-m-d H:i:s)
- ✅ `currency` - waluta cennika
- ✅ `products_count` - liczba produktów w cenniku

### 3. **Filtrowanie produktów**
Eksportowane są tylko produkty:
- `is_available = 1` (dostępne dla klienta)
- `price > 0` (mają przypisaną cenę)

### 4. **Format JSON**
- Pretty print (`JSON_PRETTY_PRINT`)
- Polskie znaki (`JSON_UNESCAPED_UNICODE`)
- Czytelny dla człowieka i łatwy do parsowania

## Użycie

### Scenariusz 1: Export dla franczyzobiorcy
```
1. Wejdź na /clients/123/price-list
2. Kliknij "Pobierz cennik dostępnych produktów [.json]"
3. Plik zostaje pobrany: price_list_Pizza_Franchisee_20260226_143015.json
4. Franczyzobiorca może zaimportować ten cennik do swojego systemu
```

### Scenariusz 2: Integracja z systemem franczyzobiorcy
```javascript
// Po stronie franczyzobiorcy:
fetch('price_list_Pizza_Franchisee_20260226_143015.json')
  .then(r => r.json())
  .then(data => {
    const clientUID = data.client.franchisee_uid; // 'FR-2024-WAW-001'
    
    // Aktualizuj ceny w lokalnym systemie
    data.products.forEach(product => {
      updateLocalPrice(product.sku, product.gross_price);
    });
  });
```

### Scenariusz 3: Ostrzeżenie o produktach z ceną 0
```
Jeśli w cenniku są produkty z ceną 0:
→ Alert: "Produkty z ceną 0 nie będą zawarte w pliku. Czy chcesz kontynuować?"
→ Użytkownik może anulować lub kontynuować
```

## Różnice: CSV vs JSON

| Cecha | CSV | JSON |
|-------|-----|------|
| **Dane klienta** | Metadane na górze | Pełna struktura `client` |
| **franchisee_uid** | Wiersz meta | Pole w obiekcie |
| **Czytelność** | Excel/CSV editor | Przeglądarki, IDE |
| **Parsowanie** | Złożone (meta + dane) | Proste (natywny JSON) |
| **Integracja** | Trudniejsza | Łatwiejsza (API) |
| **Format daty** | Y-m-d H:i | Y-m-d H:i:s |

## Kompatybilność

✅ **Spójność danych:**
- Te same źródło danych co CSV (`selectProductPriceArrByCustomer`)
- Te same filtry (available = 1, price > 0)
- Ta sama logika biznesowa

✅ **Backward compatibility:**
- Istniejący CSV export nie zmieniony
- Nowy JSON jako dodatkowa opcja

## Testy

### Manualne
1. ✅ Otwórz `/clients/123/price-list`
2. ✅ Kliknij "Pobierz cennik [.json]"
3. ✅ Sprawdź czy plik się pobiera
4. ✅ Otwórz JSON i sprawdź strukturę:
   - `meta.generated_at` - obecna data/czas
   - `client.franchisee_uid` - poprawny UID
   - `products` - tylko dostępne z ceną > 0
5. ✅ Sprawdź alert gdy są produkty z ceną 0

### Weryfikacja SQL
```sql
-- Sprawdź ile produktów powinno być w eksporcie
SELECT COUNT(*) 
FROM final_product_customer_price fpcp
JOIN final_product fp ON fpcp.id_final_product = fp.id
WHERE fpcp.id_customer = 123 
  AND fpcp.available = 1 
  AND fpcp.price > 0;
```

## Pliki zmienione

- `App/Services/Finances/ClientProductPriceService.php` - nowa metoda `getByClientAndProductJSON()`
- `App/Controllers/Web/Product/Price/PriceListController.php` - nowa metoda `ajaxGetPriceListByClientJSON()`
- `App/Core/Routes/Price/PriceListRoutes.php` - nowy routing
- `App/Views/product/price/client_price_list.view.php` - przycisk + funkcja JS

## Następne kroki (opcjonalnie)

1. **API dla franczyz** - dedykowany endpoint z tokenem
2. **Historia exportów** - logowanie kto i kiedy pobrał
3. **Webhook** - powiadomienie po zmianie cennika
4. **Diff JSON** - porównanie dwóch wersji cennika
5. **Batch export** - export dla wielu klientów naraz

