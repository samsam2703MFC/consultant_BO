# Analiza: audyt wag netto produktów

## Stan istniejący

- Produkt: tabela `final_product`, repozytorium `App\Repositories\Product\FinalProductRepository`.
- Masa netto jednej sztuki: `final_product.weight`. `ProductExportService` eksportuje ją jako `weight_grams`, a widoki produktów opisują ją jako wagę produktu — jednostką audytu są gramy.
- Liczba sztuk w kartonie: `final_product.ppc`; istniejący cennik wystawia ją jako `pieces_per_carton`.
- Aktualizacja produktu: `ProductService::update()` używa `FinalProductRepository::update()` i whitelisty kolumn, obejmującej `weight`.
- Aktualne endpointy produktów: edycja formularzowa `/products/{id}/edit` i częściowa aktualizacja `/ajax/products/{id}`.
- UI: Bootstrap, SweetAlert2 i globalny moduł `api()` dla JSON. W projekcie występuje serwerowa paginacja w repozytoriach.

## Uprawnienia i historia

W aktualnym kodzie nie ma egzekwowanego, granularnego mechanizmu uprawnień dla modułu produktów: sidebar nie filtruje pozycji według uprawnień, a kontrolery produktów nie sprawdzają ról/permission. Dostęp do panelu jest oparty na istniejącej sesji aplikacji. Z tego powodu audyt stosuje ten sam poziom dostępu co obecna edycja produktu; nie wprowadza pozornych, niepodpiętych uprawnień.

Nie znaleziono ogólnego changelogu zmian `final_product`. Nie powstaje osobna historia ani tabela wyników audytu.

## Plan

1. Dodać evaluator audytu jako czystą klasę oraz metody repozytorium z serwerowym filtrowaniem, sortowaniem i paginacją.
2. Dodać serwis audytu, który wykorzystuje `ProductService::update()` wyłącznie do aktualizacji `weight`.
3. Dodać stronę `/audits/product-weights`, endpoint odczytu oraz endpoint PATCH do zmiany masy.
4. Dodać sekcję sidebaru „Audyty” i dynamiczny widok tabeli z modalem edycji.
5. Dodać testy kontraktowe reguł wag i walidacji.
