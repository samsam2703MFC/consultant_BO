# Procesy end-to-end

## Klient → zamówienie → proforma → produkcja → wysyłka

Handlowiec zarządza klientem (`ClientRoutes.php`), tworzy zamówienie i pozycje (`OrderController`, `OrderService`, `customer_order*`), może wygenerować proformę i wpis outbox. Lista produkcyjna agreguje potrzeby z zamówień (`ProductionListRepository.php`), a wysyłka korzysta z palet, WDT/WZ/CMR i logów.

Status: **PARTIAL/F3**. Nie udowodniono legalnych przejść statusów, transakcyjności całego procesu, anulowania po wysyłce, ponowienia dokumentów przez operatora ani synchronizacji z klientem. Test dynamiczny BLOCKED.

## Receptura → koszt → cena → rentowność

Technolog tworzy materiał, cenę, recepturę/półprodukt i skład. Spłaszczanie wylicza zależności materiałowe. Kalkulator miesięczny zbiera pracowników, koszty stałe, media i logistykę. Raporty łączą sprzedaż/WDT z kosztami sekcji i produktem.

Status: **PARTIAL**. Brak wersjonowanych snapshotów receptury i ceny na datę sprzedaży; `App/Services/Recipe/FlattenedRecipeService.php:74` wskazuje niedokończony docelowy zapis. Nie potwierdzono idempotencji propagacji ceny.

## Produkcja

Punkt startu to data wysyłki/zamówienia; repozytoria wyliczają listę produkcyjną i zapotrzebowanie, a kontrolery generują PDF. Brakuje potwierdzonego zlecenia, startu, raportu ilości rozpoczętej/wyprodukowanej/zaakceptowanej/odrzuconej, rzeczywistego zużycia, odpadów i zamknięcia.

Status: **FAIL funkcjonalny** dla pełnego procesu. System planuje, ale nie prowadzi wykonania produkcji.

## Magazyn

W App stan wyrobów opiera się na `carton_stock`, dokumentach i logach wysyłki. W Agent istnieje `material_inventory`, `material_inventory_log`, powody ruchów i inwentaryzacja. Brak dowodu, że oba modele są wdrożone i uzgadniane.

Status: **PARTIAL/UNRELIABLE**. Nie da się wyjaśnić każdej zmiany stanu na podstawie potwierdzonej jednej księgi.

## Zakup materiału

App ma `purchase_document` i `purchase_item`; Agent ma zapotrzebowania, zamówienia, dostawy, proformy, cenniki dostawców i webhooki. Punkt wejścia Agent jest niepotwierdzony, a App nie pokazuje pełnego PO→potwierdzenie→PZ→reklamacja.

Status: **PARTIAL/F1-F2**.

## Reklamacja

Panel ma listę, utworzenie/edycję, realizację, dokumenty i dane wysyłkowe; Agent ma załączniki S3 i outbox. Brakuje testu kompensacji stanów/finansów, SLA i dashboardu błędów.

Status: **F3, BLOCKED dynamicznie**.

