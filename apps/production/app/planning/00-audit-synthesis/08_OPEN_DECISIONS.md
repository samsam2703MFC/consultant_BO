# Otwarte decyzje

## Decyzje biznesowe

1. Co jest zdarzeniem sprzedaży i źródłem przychodu: POS, WDT, faktura czy kombinacja?
2. Jakie są dozwolone statusy, anulacje i korekty zamówienia, wysyłki, faktury i reklamacji?
3. Jak definiowane są plan, wykonanie, odpad, strata, brak, nadprodukcja i akceptacja jakościowa?
4. Czy wymagane są partie, daty ważności, FIFO/FEFO i traceability?
5. Jak definiować koszt rzeczywisty, marżę i alokacje wspólne?
6. Jakie KPI pracownicze są dopuszczalne i w jakim kontekście?

## Decyzje systemowe wymagające właściciela

7. Czy Agent jest aktywną aplikacją, stagingiem czy importem z innego repo?
8. Który model użytkownika i uprawnień jest docelowo źródłem prawdy?
9. Który model magazynu jest obowiązujący dla materiałów i wyrobów?
10. Czy WDT i transaction mają być scalone, czy reprezentują osobne kanały?
11. Które workery są wymagane operacyjnie i jaki jest ich scheduler/SLA?
12. Która baza może zostać użyta read-only do dalszej walidacji?

## Decyzje bezpieczeństwa wymagające natychmiastowego potwierdzenia

13. Czy sekret z `App/Core/config.php` został już obrócony?
14. Czy aplikacja ma ochronę sieciową poza repo i czy jest osiągalna publicznie?
15. Czy konto `admin/admin` istnieje w którymkolwiek środowisku?

