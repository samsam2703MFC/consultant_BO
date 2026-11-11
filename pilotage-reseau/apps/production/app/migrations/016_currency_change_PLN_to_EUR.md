# Migracja 016: Zmiana waluty z PLN na EUR

**Data**: 2026-03-04  
**Status**: ✅ Zaimplementowana  
**Typ**: Configuration Change  

## Opis zmiany

Globalna zamiana wyświetlanej waluty z **PLN** (Polski Złoty) na **EUR** (Euro) w formularzu edycji zamówienia.

## Zakres zmian

### Pliki zmienione:
- `App/Views/client/order/edit.view.php`

### Lokalizacje zmian:

#### 1. PHP - Wyświetlanie cen produktów
**Linie: 149, 156, 182, 189**
```php
// PRZED:
<span class="text-muted"><?=number_format($price, 2, ',', ' ')?> PLN</span>
<strong class="line-total"><?=number_format($total, 2, ',', ' ')?> PLN</strong>

// PO:
<span class="text-muted"><?=number_format($price, 2, ',', ' ')?> EUR</span>
<strong class="line-total"><?=number_format($total, 2, ',', ' ')?> EUR</strong>
```

#### 2. HTML - Suma zamówienia
**Linia: 202**
```html
<!-- PRZED: -->
<h5 class="mb-0 text-primary" id="orderTotal">0,00 PLN</h5>

<!-- PO: -->
<h5 class="mb-0 text-primary" id="orderTotal">0,00 EUR</h5>
```

#### 3. HTML - Input group suffix
**Linia: 263**
```html
<!-- PRZED: -->
<span class="input-group-text">PLN</span>

<!-- PO: -->
<span class="input-group-text">EUR</span>
```

#### 4. JavaScript - Wyświetlanie wyników wyszukiwania
**Linia: 498**
```javascript
// PRZED:
${formatPrice(product.price)} PLN

// PO:
${formatPrice(product.price)} EUR
```

#### 5. JavaScript - Wybrany produkt
**Linia: 572**
```javascript
// PRZED:
<span class="badge bg-success">${formatPrice(product.price)} PLN</span>

// PO:
<span class="badge bg-success">${formatPrice(product.price)} EUR</span>
```

#### 6. JavaScript - Dodawanie produktu do tabeli
**Linie: 673, 677**
```javascript
// PRZED:
<span class="text-muted">${formatPrice(price)} PLN</span>
<strong class="line-total">${formatPrice(lineTotal)} PLN</strong>

// PO:
<span class="text-muted">${formatPrice(price)} EUR</span>
<strong class="line-total">${formatPrice(lineTotal)} EUR</strong>
```

#### 7. JavaScript - Aktualizacja sumy linii
**Linia: 736**
```javascript
// PRZED:
lineTotalElement.textContent = formatPrice(lineTotal) + ' PLN';

// PO:
lineTotalElement.textContent = formatPrice(lineTotal) + ' EUR';
```

#### 8. JavaScript - Obliczanie sumy zamówienia
**Linia: 763**
```javascript
// PRZED:
orderTotalElement.textContent = formatPrice(total) + ' PLN';

// PO:
orderTotalElement.textContent = formatPrice(total) + ' EUR';
```

## Liczba zmian

**Łącznie zamieniono**: ~15 wystąpień PLN → EUR

### Kategorie:
- **PHP templates**: 4 wystąpienia
- **HTML static**: 2 wystąpienia  
- **JavaScript dynamiczny**: 9 wystąpień

## Metoda implementacji

Użyto globalnej zamiany za pomocą `sed`:
```bash
sed -i 's/PLN/EUR/g' App/Views/client/order/edit.view.php
```

## Weryfikacja

### Test points:
1. ✅ Wyświetlanie cen produktów w tabeli
2. ✅ Suma zamówienia w stopce
3. ✅ Input suffix przy polu ceny
4. ✅ Wyniki wyszukiwania produktów
5. ✅ Wybrany produkt (badge)
6. ✅ Nowo dodany produkt do tabeli
7. ✅ Aktualizacja wartości linii
8. ✅ Przeliczanie sumy całkowitej

### Przykład wyświetlania:
```
PRZED:
Baguette Tradition: 31,12 PLN
Suma zamówienia: 580,60 PLN

PO:
Baguette Tradition: 31,12 EUR
Suma zamówienia: 580,60 EUR
```

## Backward Compatibility

### Uwaga ⚠️
Ta zmiana dotyczy **tylko warstwy prezentacji** (view layer).

**NIE zmieniono**:
- ❌ Bazy danych (ceny nadal w oryginalnej walucie)
- ❌ Backendu (PHP services, repositories)
- ❌ API responses
- ❌ Faktycznej logiki biznesowej

**Zmieniono**:
- ✅ Tylko wyświetlanie w interfejsie użytkownika

### Implikacje:
Jeśli system faktycznie operuje na PLN, to:
- Ceny w bazie danych pozostają w PLN
- **Konwersja na EUR musi być wykonana** przed zapisem lub:
- **To jest tylko zmiana wyświetlania** (etykiety)

## Rollback

W przypadku potrzeby powrotu do PLN:
```bash
sed -i 's/EUR/PLN/g' App/Views/client/order/edit.view.php
```

## Considerations for Production

### ⚠️ Ważne pytania przed wdrożeniem:

1. **Czy ceny w bazie są w EUR czy PLN?**
   - Jeśli w PLN → Potrzebna konwersja
   - Jeśli w EUR → OK, tylko zmiana wyświetlania

2. **Czy potrzebna konwersja walut?**
   - Tak → Dodaj logikę konwersji w backe ndzie
   - Nie → OK, to tylko zmiana etykiety

3. **Czy inne moduły też używają PLN?**
   - Tak → Trzeba zmienić wszędzie
   - Nie → OK

4. **Czy kurs EUR/PLN jest stały czy dynamiczny?**
   - Stały → Hardcode w konfiguracji
   - Dynamiczny → Potrzebne API kursu walut

## Rekomendacje

### Jeśli to tylko zmiana wyświetlania:
✅ **OK - można wdrażać**

### Jeśli potrzebna faktyczna konwersja:
⚠️ **Potrzebne dodatkowe zmiany**:

1. **Config/Constants**:
```php
define('CURRENCY_CODE', 'EUR');
define('CURRENCY_SYMBOL', '€');
define('PLN_TO_EUR_RATE', 0.23); // Przykładowy kurs
```

2. **Helper Function**:
```php
function convertToEUR($pln) {
    return $pln * PLN_TO_EUR_RATE;
}
```

3. **W kontrolerze**:
```php
$data['row_products'] = array_map(function($product) {
    $product['purchase_price'] = convertToEUR($product['purchase_price']);
    return $product;
}, $data['row_products']);
```

## Testing Checklist

- [x] Wyświetlanie EUR w tabeli produktów
- [x] Wyświetlanie EUR w sumie zamówienia
- [x] Wyświetlanie EUR w input group
- [x] Wyświetlanie EUR w wynikach wyszukiwania
- [x] Wyświetlanie EUR w wybranym produkcie
- [x] Wyświetlanie EUR w nowo dodanych produktach
- [x] Przeliczanie sum z EUR
- [x] Formatowanie liczb (przecinek dziesiętny)
- [ ] Test z rzeczywistymi cenami
- [ ] Weryfikacja w innych modułach

## Documentation Updates

### Pliki do aktualizacji:
1. ✅ Utworzono: `migrations/016_currency_change_PLN_to_EUR.md`
2. 🔄 TODO: Zaktualizować user guide (jeśli istnieje)
3. 🔄 TODO: Zaktualizować screenshots (jeśli istnieją)
4. 🔄 TODO: Zaktualizować training materials

## Known Issues

### Brak:
- ✅ Brak znanych problemów technicznych

### Potencjalne:
- ⚠️ Confusion dla użytkowników jeśli faktycznie używają PLN
- ⚠️ Niezgodność z dokumentami (faktury, etc.) jeśli te są w PLN

## Related Changes

- Migration 014: Modernizacja interfejsu
- Migration 015: Wyszukiwarka produktów
- **Migration 016**: Zmiana waluty na EUR (ten dokument)

## Sign-off

- [ ] Code Review
- [ ] Business Owner Approval (⚠️ **WAŻNE!**)
- [ ] Finance Team Approval (⚠️ **WAŻNE!**)
- [ ] Legal Team Approval (jeśli dotyczy)
- [ ] QA Testing
- [ ] Ready for Production

---

**Autor**: GitHub Copilot Agent  
**Data**: 2026-03-04  
**Wersja**: 1.0.0  
**Priority**: Medium  
**Risk Level**: Low (tylko UI) / High (jeśli wymaga konwersji)

---

## Quick Reference

### Find all currency mentions:
```bash
grep -rn "PLN\|EUR" app/
```

### Count occurrences:
```bash
grep -c "EUR" App/Views/client/order/edit.view.php
```

### Rollback command:
```bash
sed -i 's/EUR/PLN/g' App/Views/client/order/edit.view.php
```

---

**Status**: ✅ Zmiana wykonana - czeka na approval biznesowe

