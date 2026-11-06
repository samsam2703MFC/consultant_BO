# Audyty — audyt wag netto produktów

## Zrealizowane elementy

- Nowa sekcja sidebaru **Audyty** z pozycją **Wagi produktów**.
- Widok `/audits/product-weights` z licznikami, filtrowaniem, serwerową paginacją i sortowaniem.
- Dynamiczny audyt bez zapisu wyników: status, powód i masa netto zawartości kartonu są obliczane przy każdym odczycie.
- Modal do poprawy wyłącznie masy netto w gramach; po zapisie lista oraz liczniki odświeżają się przez API bez przeładowania strony.
- Walidacja w przeglądarce oraz po stronie serwera.

## Decyzje techniczne

- Masa netto sztuki pochodzi z `final_product.weight`; eksport produktu nazywa tę wartość `weight_grams`, dlatego audyt interpretuje ją jako gramy.
- Liczba sztuk w kartonie pochodzi z `final_product.ppc`; istniejący cennik wystawia to pole jako `pieces_per_carton`.
- Masa netto zawartości kartonu jest liczona podczas odczytu: `weight × ppc`. Nie jest zapisywana w bazie.
- Status jest wyliczany przez `ProductWeightAuditEvaluator`: `NULL`, pusta, zero, ujemna lub nienumeryczna wartość to `ERROR`; `1 g` to `WARNING`; pozostałe dodatnie wartości to `OK`.
- Prawidłowy typ SQL kolumny `weight` nie jest opisany w migracjach repozytorium. Próba bezpiecznego odczytu metadanych bazy nie powiodła się, ponieważ lokalne środowisko nie rozpoznaje hosta bazy. Implementacja nie zmienia typu i zachowuje istniejącą precyzję wejścia (`step="any"`).
- Zapis używa istniejącego `ProductService::update()` i aktualizuje wyłącznie kolumnę `weight`.
- Nie istnieje w kodzie granularny system uprawnień dla produktów ani ogólny changelog `final_product`; audyt pozostaje dostępny na tym samym poziomie co obecna edycja produktów. Nie dodano pozornych uprawnień ani tabeli historii.

## Endpointy

### `GET /ajax/audits/product-weights`

Parametry: `page`, `per_page`, `search`, `status` (`attention`, `all`, `ERROR`, `WARNING`, `OK`), `activity` (`active`, `inactive`, `all`), `sort` (`status`, `name`, `id`, `weight`), `direction`.

Przykładowa odpowiedź:

```json
{
  "data": [{
    "id_product": 123,
    "sku": "123",
    "name": "Produkt przykładowy",
    "category_name": "Kategoria",
    "is_active": true,
    "unit_weight_g": 250,
    "units_per_carton": 20,
    "carton_net_content_weight_g": 5000,
    "audit_status": "OK",
    "audit_reason": null
  }],
  "meta": { "page": 1, "per_page": 25, "total": 1, "counts": { "all": 1, "error": 0, "warning": 0, "ok": 1 } }
}
```

### `PATCH /ajax/audits/product-weights/{id}`

Body:

```json
{ "weight_g": 250 }
```

Zwraca zaktualizowany wynik audytu. Wartość `0`, ujemna lub nienumeryczna zwraca `422`; brak produktu `404`.

## Zmienione pliki

### Backend/API

- `App/Services/Audit/ProductWeightAuditEvaluator.php`
- `App/Services/Audit/ProductWeightAuditService.php`
- `App/Repositories/Product/FinalProductRepository.php`
- `App/Controllers/Web/Audit/ProductWeightAuditController.php`
- `App/Core/Routes/Audit/AuditRoutes.php`

### Panel

- `App/Views/audit/product_weight/index.view.php`
- `App/Views/page_components/sidebar.twig`

### Dokumentacja i testy

- `docs/product-weight-audit-analysis.md`
- `tests/product_weight_audit_contract_test.php`

## Testy

```bash
php tests/product_weight_audit_contract_test.php
php -l App/Services/Audit/ProductWeightAuditEvaluator.php
php -l App/Services/Audit/ProductWeightAuditService.php
php -l App/Repositories/Product/FinalProductRepository.php
php -l App/Controllers/Web/Audit/ProductWeightAuditController.php
php -l App/Core/Routes/Audit/AuditRoutes.php
php -l App/Views/audit/product_weight/index.view.php
```

Test kontraktowy obejmuje `NULL`, pustą wartość, `0`, wartość ujemną, format nieprawidłowy, `1 g`, poprawną wagę, obliczenie masy zawartości kartonu, brak `ppc` oraz walidację zapisu.

## Rzeczywiste ograniczenie

Nie udało się uruchomić testu integracyjnego z MySQL w bieżącym środowisku, ponieważ skonfigurowany host bazy (`host.docker.internal`) nie jest rozpoznawany. Nie wpływa to na kod, ale przed wdrożeniem należy sprawdzić endpointy na środowisku z bazą.
