# Migracja 018: Zmiana formatu przesyłanych danych produktów

**Data**: 2026-03-04  
**Status**: ✅ Zaimplementowana  
**Typ**: Refactoring - Data Structure Change  

## 🎯 Cel zmiany

Zmiana formatu przesyłanych danych produktów z **dwóch osobnych tablic** (indeksowanych przez ID produktu) na **jedną listę obiektów** (z bezpośrednim dostępem do właściwości).

---

## 📊 Format PRZED zmianą:

```php
$_POST = [
    'id_customer' => 123,
    'expected_shipping_date' => '2026-03-15',
    'order_description' => 'Komentarz...',
    'standard_price_list' => 1,
    
    // DWA OSOBNE POLA:
    'product' => [
        0 => [1001 => 5],    // index => [id_product => quantity]
        1 => [1002 => 10],
        2 => [1003 => 3]
    ],
    'price' => [
        0 => [1001 => 31.12],  // index => [id_product => price]
        1 => [1002 => 42.50],
        2 => [1003 => 15.00]
    ]
]
```

**Problem**:
- ❌ Dwa osobne pola dla tego samego produktu
- ❌ Trzeba używać `key()` i `array_values()[0]` do dostępu
- ❌ Trudna iteracja - nie można bezpośrednio przejść przez tablicę
- ❌ Index nie ma znaczenia biznesowego (tylko techniczne)

---

## 📊 Format PO zmianie:

```php
$_POST = [
    'id_customer' => 123,
    'expected_shipping_date' => '2026-03-15',
    'order_description' => 'Komentarz...',
    'standard_price_list' => 1,
    
    // JEDNA LISTA OBIEKTÓW:
    'products' => [
        0 => [
            'id_product' => 1001,
            'quantity' => 5,
            'price' => 31.12
        ],
        1 => [
            'id_product' => 1002,
            'quantity' => 10,
            'price' => 42.50
        ],
        2 => [
            'id_product' => 1003,
            'quantity' => 3,
            'price' => 15.00
        ]
    ]
]
```

**Korzyści**:
- ✅ Jeden obiekt dla jednego produktu
- ✅ Bezpośredni dostęp do właściwości: `$product['quantity']`
- ✅ Łatwa iteracja: `foreach($products as $product) { ... }`
- ✅ Czytelny i intuicyjny kod
- ✅ Łatwiejsze testowanie i debugging

---

## 🔧 Zmienione pliki:

### 1. **Widoki (Views)**

#### `App/Views/client/order/new.view.php`

**HTML - Old products (PHP)**:
```php
// PRZED:
<input type="number" name="product[][<?=$old_product['id']?>]" 
       value="<?=$old_product['quantity_carton']?>">
<input type="hidden" name="price[][<?=$old_product['id']?>]" 
       value="<?=$old_product['purchase_price']?>">

// PO:
<input type="hidden" name="products[<?=$index?>][id_product]" 
       value="<?=$old_product['id_product']?>">
<input type="number" name="products[<?=$index?>][quantity]" 
       value="<?=$old_product['quantity_carton']?>">
<input type="hidden" name="products[<?=$index?>][price]" 
       value="<?=$old_product['price_net']?>">
```

**JavaScript - New products**:
```javascript
// PRZED:
newRow.innerHTML = `
    <td>
        <input type="number" name="product[][${selectedProduct.id}]" value="${quantity}">
    </td>
    <td>
        <input type="hidden" name="price[][${selectedProduct.id}]" value="${price}">
    </td>
`;

// PO:
const currentRowCount = productsList.querySelectorAll('[data-product-row]').length;

newRow.innerHTML = `
    <td>
        <input type="hidden" name="products[${currentRowCount}][id_product]" value="${selectedProduct.id}">
        <input type="number" name="products[${currentRowCount}][quantity]" value="${quantity}">
    </td>
    <td>
        <input type="hidden" name="products[${currentRowCount}][price]" value="${price}">
    </td>
`;
```

**JavaScript - Sprawdzanie duplikatów**:
```javascript
// PRZED:
const existingRow = Array.from(productsList.querySelectorAll('[data-product-row]'))
    .find(row => {
        const input = row.querySelector('input[name^="product"]');
        return input && input.name.includes(`[${selectedProduct.id}]`);
    });

// PO:
const existingRow = Array.from(productsList.querySelectorAll('[data-product-row]'))
    .find(row => {
        const idInput = row.querySelector('input[name$="[id_product]"]');
        return idInput && idInput.value == selectedProduct.id;
    });
```

**JavaScript - Reindeksacja po usunięciu**:
```javascript
// NOWA FUNKCJA:
function reindexProducts() {
    const productsList = document.getElementById('productsList');
    const rows = productsList.querySelectorAll('[data-product-row]');
    
    rows.forEach((row, index) => {
        const idInput = row.querySelector('input[name$="[id_product]"]');
        const quantityInput = row.querySelector('input[name$="[quantity]"]');
        const priceInput = row.querySelector('input[name$="[price]"]');
        
        if (idInput) idInput.name = `products[${index}][id_product]`;
        if (quantityInput) quantityInput.name = `products[${index}][quantity]`;
        if (priceInput) priceInput.name = `products[${index}][price]`;
    });
}

function removeRow(btn) {
    // ...existing code...
    row.remove();
    reindexProducts(); // ← Dodane!
    calculateOrderTotal();
}
```

#### `App/Views/client/order/edit.view.php`

**Analogiczne zmiany jak w new.view.php**:
- ✅ Zmiana formatów HTML inputs
- ✅ Zmiana JavaScript generowania produktów
- ✅ Zmiana sprawdzania duplikatów
- ✅ Dodanie reindeksacji

**Uwaga**: W `edit.view.php` produkty są już załadowane z bazy, więc trzeba uwzględnić `$startIndex`:

```php
<?php if(!empty($old_products)): ?>
    <?php 
    $startIndex = !empty($row_products) ? count($row_products) : 0;
    foreach($old_products as $i => $old_product): 
    $index = $startIndex + $i;
    ?>
        <input type="hidden" name="products[<?=$index?>][id_product]" value="<?=$old_product['id_product']?>">
        <!-- ... -->
    <?php endforeach; ?>
<?php endif; ?>
```

---

### 2. **Kontroler**

#### `App/Controllers/Web/Client/Order/OrderController.php`

**Metoda `getOldDataFromPostRequest()`**:

```php
// PRZED:
private function getOldDataFromPostRequest($data)
{
    $newData = [];
    if (!empty($_POST['product'])) {
        $newData['old_products'] = [];
        $parsedProduct = json_decode(json_encode($data['price_list']), true);
        $newData['old_products'] = old_values_with_same_name(
            $_POST['product'], 
            $parsedProduct, 
            "id_product", 
            "product_name", 
            "quantity_carton"
        );
    }
    return $newData;
}

// PO:
private function getOldDataFromPostRequest($data)
{
    $newData = [];
    if (!empty($_POST['products'])) {
        $newData['old_products'] = [];
        
        foreach ($_POST['products'] as $product) {
            $id_product = $product['id_product'];
            
            // Find product details from price_list
            $productDetails = null;
            if (!empty($data['price_list'])) {
                foreach ($data['price_list'] as $item) {
                    $itemId = is_object($item) ? $item->getIdProduct() : $item['id_product'];
                    if ($itemId == $id_product) {
                        $productDetails = $item;
                        break;
                    }
                }
            }
            
            if ($productDetails) {
                $newData['old_products'][] = [
                    'id_product' => $id_product,
                    'product_name' => is_object($productDetails) ? $productDetails->getProductName() : $productDetails['product_name'],
                    'quantity_carton' => $product['quantity'],
                    'price_net' => $product['price'] ?? (is_object($productDetails) ? $productDetails->getPriceNet() : $productDetails['price_net'])
                ];
            }
        }
    }
    return $newData;
}
```

**Korzyść**: Możemy bezpośrednio iterować przez `$_POST['products']` używając `foreach`.

---

### 3. **Serwis**

#### `App/Services/Client/Order/OrderService.php`

**Walidacja**:
```php
// PRZED:
if (empty($data['product'])) {
    $errors['empty_data'] = "Wprowadź przynajmniej jeden produkt";
}

// PO:
if (empty($data['products'])) {
    $errors['empty_data'] = "Wprowadź przynajmniej jeden produkt";
}
```

**Metoda `insertNewOrder()`**:
```php
// PRZED:
if (!isset($data['product']) || empty($data['product'])) {
    throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
}

if ($data['standard_price_list'] == 1) {
    $res = $this->orderProductRepository->insertForOrderWithStandardPrice($idOrder, $data['product']);
} else {
    $res = $this->orderProductRepository->insertForOrderWithClientPrice($idOrder, $data['id_customer'], $data['product']);
}

// PO:
if (!isset($data['products']) || empty($data['products'])) {
    throw new CustomException("INSERT_ORDER_PRODUCT_ERROR", 'errors', 500);
}

if ($data['standard_price_list'] == 1) {
    $res = $this->orderProductRepository->insertForOrderWithStandardPrice($idOrder, $data['products']);
} else {
    $res = $this->orderProductRepository->insertForOrderWithClientPrice($idOrder, $data['id_customer'], $data['products']);
}
```

**Metoda `updateOrderWithProducts()`**:
```php
// PRZED:
$res = $this->orderProductRepository->insertForOrderWithStandardPrice($id, $data['product']);
$res = $this->orderProductRepository->insertForOrderWithClientPrice($id, $data['id_customer'], $data['product']);

// PO:
$res = $this->orderProductRepository->insertForOrderWithStandardPrice($id, $data['products']);
$res = $this->orderProductRepository->insertForOrderWithClientPrice($id, $data['id_customer'], $data['products']);
```

---

### 4. **Repository**

#### `App/Repositories/Client/Order/OrderProductRepository.php`

**Metoda `insertForOrderWithStandardPrice()`**:

```php
// PRZED:
public function insertForOrderWithStandardPrice($idOrder, $products)
{
    if(!empty($products)){
        foreach($products as $product){
            $sql = "INSERT INTO customer_order_product (...) 
                    SELECT :insertedId, id, :quantity, :exp_quantity, standard_price
                    FROM final_product WHERE id = :id_product";
            
            $productId = key($product); // ← Trzeba wyciągnąć klucz
            
            $res = $this->customInsert($sql, [
                "insertedId" => $idOrder,
                "quantity" => array_values($product)[0], // ← Trudny dostęp
                "exp_quantity" => array_values($product)[0],
                "id_product" => key($product) // ← Powtórzenie
            ]);
        }
    }
    return true;
}

// PO:
public function insertForOrderWithStandardPrice($idOrder, $products)
{
    if(!empty($products)){
        foreach($products as $product){
            $sql = "INSERT INTO customer_order_product (...) 
                    SELECT :insertedId, id, :quantity, :exp_quantity, standard_price
                    FROM final_product WHERE id = :id_product";
            
            $res = $this->customInsert($sql, [
                "insertedId" => $idOrder,
                "quantity" => $product['quantity'], // ← Bezpośredni dostęp!
                "exp_quantity" => $product['quantity'],
                "id_product" => $product['id_product'] // ← Czytelne!
            ]);
        }
    }
    return true;
}
```

**Metoda `insertForOrderWithClientPrice()`**:

```php
// PRZED:
$productId = key($product);
$res = $this->customInsert($sql, [
    "insertedId" => $idOrder,
    "quantity" => array_values($product)[0],
    "exp_quantity" => array_values($product)[0],
    "id_product" => key($product),
    "id_customer" => $idClient
]);

// PO:
$res = $this->customInsert($sql, [
    "insertedId" => $idOrder,
    "quantity" => $product['quantity'],
    "exp_quantity" => $product['quantity'],
    "id_product" => $product['id_product'],
    "id_customer" => $idClient
]);
```

---

## 🧪 Przykłady użycia:

### Iteracja przez produkty (Backend):

```php
// PRZED:
foreach($data['product'] as $product) {
    $id = key($product);           // Musimy wyciągać klucz
    $qty = array_values($product)[0]; // Musimy używać array_values
    
    echo "Product $id: $qty szt.";
}

// PO:
foreach($data['products'] as $product) {
    $id = $product['id_product'];  // Bezpośredni dostęp!
    $qty = $product['quantity'];   // Czytelne!
    $price = $product['price'];    // Wszystko w jednym miejscu!
    
    echo "Product $id: $qty szt. @ $price EUR";
}
```

### Dodawanie produktu (Frontend):

```javascript
// PRZED:
// Produkt 1001, ilość 5
<input name="product[][1001]" value="5">
<input name="price[][1001]" value="31.12">

// Produkt 1002, ilość 10
<input name="product[][1002]" value="10">
<input name="price[][1002]" value="42.50">

// PO:
// Produkt 1001, ilość 5
<input name="products[0][id_product]" value="1001">
<input name="products[0][quantity]" value="5">
<input name="products[0][price]" value="31.12">

// Produkt 1002, ilość 10
<input name="products[1][id_product]" value="1002">
<input name="products[1][quantity]" value="10">
<input name="products[1][price]" value="42.50">
```

---

## ✅ Korzyści:

### 1. **Czytelność kodu** 📖
```php
// PRZED: Co to robi? 🤔
$quantity = array_values($product)[0];
$id = key($product);

// PO: Jasne i zrozumiałe! ✅
$quantity = $product['quantity'];
$id = $product['id_product'];
```

### 2. **Łatwiejsza walidacja** ✓
```php
// PO:
foreach($products as $product) {
    if ($product['quantity'] <= 0) {
        $errors[] = "Ilość musi być większa niż 0";
    }
    if (empty($product['id_product'])) {
        $errors[] = "Brak ID produktu";
    }
}
```

### 3. **Łatwiejsze debugowanie** 🐛
```php
// PO:
var_dump($products);
// Array
// (
//     [0] => Array
//         (
//             [id_product] => 1001
//             [quantity] => 5
//             [price] => 31.12
//         )
// )

// Wszystko widać od razu!
```

### 4. **Łatwiejsze testowanie** 🧪
```php
// PO:
$testData = [
    'products' => [
        ['id_product' => 1, 'quantity' => 5, 'price' => 10.00],
        ['id_product' => 2, 'quantity' => 3, 'price' => 20.00]
    ]
];

// Czytelne i łatwe do stworzenia!
```

---

## ⚠️ Breaking Changes:

### Kod który przestanie działać:

❌ **Bezpośredni dostęp do `$_POST['product']`**:
```php
// To przestanie działać:
if (!empty($_POST['product'])) { ... }

// Trzeba zmienić na:
if (!empty($_POST['products'])) { ... }
```

❌ **Iteracja z `key()` i `array_values()`**:
```php
// To przestanie działać:
$id = key($product);
$qty = array_values($product)[0];

// Trzeba zmienić na:
$id = $product['id_product'];
$qty = $product['quantity'];
```

---

## 🧪 Testy:

### Test 1: Tworzenie nowego zamówienia
```bash
1. Otwórz /client-orders/new
2. Wybierz klienta
3. Dodaj produkty
4. Sprawdź w DevTools format POST data:
   ✅ products[0][id_product]
   ✅ products[0][quantity]
   ✅ products[0][price]
5. Wyślij formularz
6. Sprawdź czy produkty zapisały się w bazie
```

### Test 2: Edycja zamówienia
```bash
1. Otwórz /client-orders/{id}/edit
2. Sprawdź czy produkty wyświetlają się poprawnie
3. Zmień ilość produktu
4. Usuń produkt (sprawdź reindeksację)
5. Dodaj nowy produkt
6. Zapisz
7. Sprawdź czy zmiany zapisały się w bazie
```

### Test 3: Błąd walidacji
```bash
1. Otwórz /client-orders/new
2. Dodaj produkty
3. Wyczyść datę wysyłki
4. Wyślij formularz
5. Sprawdź czy:
   ✅ Wraca z błędem
   ✅ Produkty są zachowane
   ✅ Format products[] jest prawidłowy
```

---

## 📊 Podsumowanie zmian:

| Plik | Linie zmienione | Typ zmiany |
|------|----------------|------------|
| `new.view.php` | ~50 | HTML + JS |
| `edit.view.php` | ~60 | HTML + JS |
| `OrderController.php` | ~30 | PHP Logic |
| `OrderService.php` | ~10 | PHP Logic |
| `OrderProductRepository.php` | ~15 | SQL Queries |
| **RAZEM** | **~165** | **5 plików** |

---

## 🎯 Status: ✅ KOMPLETNE

### Zmienione:
- ✅ Widok new.view.php (HTML + JavaScript)
- ✅ Widok edit.view.php (HTML + JavaScript)
- ✅ Kontroler OrderController
- ✅ Serwis OrderService
- ✅ Repository OrderProductRepository
- ✅ Reindeksacja po usunięciu produktu
- ✅ Sprawdzanie duplikatów
- ✅ Walidacja

### Gotowe do:
- ✅ Testowania manualnego
- ✅ Testów jednostkowych
- ✅ Wdrożenia na produkcję

---

**Migration**: 018  
**Data**: 2026-03-04  
**Wersja**: 1.0.0  
**Breaking**: Yes (zmiana formatu API)  
**Status**: ✅ Zaimplementowana

