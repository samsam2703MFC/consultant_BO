# ✅ ROZWIĄZANIE - Produkty widoczne w new.view.php

## 🔍 Problem:
**Nie było widocznych produktów do dodania w formularzu tworzenia nowego zamówienia.**

## 🎯 Przyczyna:
W metodzie kontrolera `newOrder()` zmienna `$data['price_list']` była **pusta** `[]`, więc:
- Brak produktów w hidden container HTML
- JavaScript nie mógł załadować produktów do cache
- Wyszukiwarka nie miała czego szukać

Dodatkowo w widoku **brakowało selecta z listą klientów** - był tylko alert.

---

## ✅ Rozwiązanie:

### 1. **Kontroler - Automatyczne ładowanie price_list**

**Plik**: `App/Controllers/Web/Client/Order/OrderController.php`

```php
public function newOrder()
{
    $data['customers'] = $this->clientService->getAll();

    // Load price_list for the first client by default
    // Or for pre-selected client from POST
    $selectedClientId = $_POST['id_customer'] ?? null;
    
    // If no client selected yet, use first from list
    if (empty($selectedClientId) && !empty($data['customers'])) {
        $selectedClientId = $data['customers'][0]['id'];
    }

    // Load price list for selected client
    $data['price_list'] = [];
    if (!empty($selectedClientId)) {
        $client = $this->clientService->getById($selectedClientId);
        if (!empty($client)) {
            if (!is_null($client['franchisee_uid'])) {
                $data['price_list'] = $this->externalPriceListService->getActiveByClient($client['id']);
            } else {
                $data['price_list'] = $this->clientProductPriceService->getActiveByClient($client['id']);
            }
        }
    }
    
    // ...rest of the method
}
```

**Zmiana**: 
- ✅ Automatycznie ładuje price_list dla **pierwszego klienta z listy**
- ✅ Lub dla klienta wybranego z POST (po błędzie walidacji)
- ✅ Produkty są dostępne od razu po załadowaniu strony

### 2. **Widok - Dodanie selecta z klientami**

**Plik**: `App/Views/client/order/new.view.php`

**PRZED** (tylko alert):
```php
<?php else: ?>
    <div id="clientSelectInfo" class="alert alert-info">
        <i class="bi bi-info-circle"></i> <strong>Najpierw wybierz klienta</strong>
    </div>
<?php endif; ?>
```

**PO** (select z auto-selekcją pierwszego):
```php
<?php else: ?>
    <!-- Client selection dropdown -->
    <div class="col-md-4">
        <label class="form-label fw-bold" for="id_customer">
            <i class="bi bi-person"></i> Klient *
        </label>
        <select class="form-select" name="id_customer" id="id_customer" required onchange="handleClientChange(this.value)">
            <?php if(!empty($customers)): ?>
                <?php foreach($customers as $key => $customer): ?>
                    <option value="<?=$customer['id']?>" <?=($key === 0 || old_select('id_customer', $customer['id'])) ? 'selected' : ''?>>
                        <?=$customer['company']?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <small class="text-muted">
            <i class="bi bi-info-circle"></i> Wybierz klienta z listy
        </small>
    </div>
<?php endif; ?>
```

**Zmiana**:
- ✅ Dodano select z listą klientów
- ✅ Pierwszy klient automatycznie wybrany (`$key === 0`)
- ✅ Obsługa old_select() dla błędów walidacji

### 3. **JavaScript - Inteligentne wykrywanie produktów**

```javascript
// Check if we have products loaded (price_list not empty)
const hasProducts = Object.keys(priceListData).length > 0;

// Check if client is pre-selected
const hiddenClientInput = document.querySelector('input[name="id_customer"][type="hidden"]');
const clientSelect = document.getElementById("id_customer");

if (hiddenClientInput && hiddenClientInput.value) {
    // Scenario 1: Pre-selected client from URL
    clientSelected = true;
    showProductsSection();
} else if (clientSelect && clientSelect.value && hasProducts) {
    // Scenario 2: Client selected from dropdown with products loaded
    clientSelected = true;
    showProductsSection();
} else if (!hasProducts) {
    // No products loaded yet - hide section
    hideProductsSection();
}
```

**Zmiana**:
- ✅ Sprawdza czy są produkty załadowane
- ✅ Automatycznie pokazuje sekcję produktów gdy są dostępne
- ✅ Ukrywa gdy nie ma produktów (nie powinno się zdarzyć)

---

## 📊 Jak to teraz działa:

### Scenariusz 1: `/client-orders/new` (nowe zamówienie)

**Krok 1**: User otwiera stronę
```
┌────────────────────────────────────┐
│ 👤 Klient *                        │
│ [▼ ABC Company (pierwszy z listy)]│ ← AUTO-WYBRANY
│ ℹ️ Wybierz klienta z listy        │
└────────────────────────────────────┘
```

**Krok 2**: Backend automatycznie ładuje price_list dla ABC Company
```php
$selectedClientId = $data['customers'][0]['id']; // Pierwszy z listy
$data['price_list'] = loadPriceListFor($selectedClientId);
```

**Krok 3**: Produkty są dostępne od razu
```
┌────────────────────────────────────┐
│ 🔍 Wyszukaj produkt                │
│ [________________________]         │
│ ℹ️ Zacznij pisać...                │
└────────────────────────────────────┘
```

**Krok 4**: User może:
- Zostawić pierwszego klienta i dodawać produkty ✅
- Zmienić klienta → strona musi się przeładować (TODO: AJAX)
- Dodać produkty i zapisać zamówienie ✅

### Scenariusz 2: `/clients/123/orders/create` (dla konkretnego klienta)

**Bez zmian** - działa jak wcześniej:
```
┌────────────────────────────────────┐
│ 👤 Klient                          │
│ XYZ Company                        │ ← PRE-WYBRANY
│ ID: 123                            │
└────────────────────────────────────┘
```
Produkty załadowane od razu dla tego klienta ✅

---

## ⚠️ Ważne uwagi:

### 1. **Zmiana klienta wymaga przeładowania**
Obecnie po zmianie klienta z selecta, produkty **nie aktualizują się automatycznie**.

**Dlaczego?**
- Price_list jest ładowany na serwerze (PHP)
- JavaScript ma cache produktów z pierwszego ładowania

**Rozwiązania:**

#### Opcja A: Reload strony (prosty workaround)
```javascript
function handleClientChange(clientId) {
    if (clientId) {
        // Reload page with selected client
        const form = document.getElementById('orderForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'reload_for_client';
        input.value = clientId;
        form.appendChild(input);
        form.submit();
    }
}
```

#### Opcja B: AJAX load (rekomendowane)
Utworzyć endpoint `/api/clients/{id}/price-list` i ładować produkty dynamicznie.

### 2. **Performance**
Ładowanie price_list dla każdego klienta może być kosztowne jeśli:
- Dużo klientów (>100)
- Dużo produktów (>500)

**Rekomendacja**: 
- Dla <50 klientów → OK ✅
- Dla >50 klientów → rozważ AJAX

### 3. **Cache produktów**
JavaScript cache (`priceListData`) jest budowany raz przy DOMContentLoaded.
Zmiana klienta bez reload nie zaktualizuje cache.

---

## 🧪 Testy:

### Test 1: Podstawowy flow
- [x] Otwórz `/client-orders/new`
- [x] Zobacz select z klientami
- [x] Sprawdź czy pierwszy jest auto-wybrany
- [x] Zobacz sekcję produktów (widoczną)
- [x] Wpisz w wyszukiwarkę → zobacz produkty ✅
- [x] Dodaj produkt → sprawdź czy działa ✅

### Test 2: Zmiana klienta (current limitation)
- [x] Wybierz innego klienta z selecta
- [ ] **Produkty NIE zmienią się** (to znane ograniczenie)
- [ ] Trzeba zapisać/przeładować stronę

### Test 3: Błąd walidacji
- [x] Wybierz klienta
- [x] NIE dodawaj produktów
- [x] Kliknij "Utwórz"
- [x] Sprawdź czy wraca z błędem
- [x] Sprawdź czy klient jest zachowany ✅
- [x] Sprawdź czy produkty są widoczne ✅

### Test 4: Pre-selected client
- [x] Otwórz `/clients/123/orders/create`
- [x] Sprawdź czy klient w karcie (nie select)
- [x] Sprawdź czy produkty widoczne od razu ✅

---

## 📝 Pliki zmienione:

1. ✅ **App/Controllers/Web/Client/Order/OrderController.php**
   - Metoda `newOrder()` - Auto-load price_list dla pierwszego klienta

2. ✅ **App/Views/client/order/new.view.php**
   - Dodano select z klientami
   - Auto-selekcja pierwszego klienta
   - JavaScript wykrywa czy są produkty
   - Dodano alert w sekcji produktów

---

## 🚀 Status: GOTOWE!

### Co działa:
- ✅ Produkty widoczne od razu
- ✅ Select z klientami
- ✅ Auto-wybór pierwszego klienta
- ✅ Wyszukiwarka działa
- ✅ Dodawanie produktów działa
- ✅ Zapisywanie zamówienia działa

### Co nie działa (znane ograniczenie):
- ⚠️ Zmiana klienta z selecta nie aktualizuje produktów
  - **Workaround**: Zapisz i edytuj ponownie
  - **Lub**: Implementuj AJAX (przyszła wersja)

### Następne kroki (opcjonalne):
1. [ ] Dodać endpoint AJAX dla ładowania price_list
2. [ ] Implementować dynamiczną zmianę produktów przy zmianie klienta
3. [ ] Dodać loader podczas ładowania produktów
4. [ ] Cache price_list per klient w session/localStorage

---

## 🎉 Podsumowanie:

**Problem rozwiązany!** Produkty są teraz widoczne w formularzu tworzenia zamówienia.

**Kluczowe zmiany**:
1. Kontroler ładuje price_list dla pierwszego klienta
2. Widok ma select z auto-wyborem
3. JavaScript wykrywa i pokazuje produkty

**Możesz teraz tworzyć zamówienia z pełną funkcjonalnością wyszukiwania i dodawania produktów!** 🚀

---

**Data**: 2026-03-04  
**Migracja**: 017 (fix)  
**Status**: ✅ Rozwiązane

