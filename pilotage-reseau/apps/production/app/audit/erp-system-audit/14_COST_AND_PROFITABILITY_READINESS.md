# Koszty i rentowność

## Dane zapisane lub modelowane

- ceny surowców i changelog;
- koszt receptury i spłaszczone składniki;
- standardowa cena produktu;
- miesięczne koszty pracowników, stałe, mediów i logistyki;
- alokacje kosztów na sekcje;
- kurs EUR w pojedynczym ustawieniu;
- WDT jako źródło wolumenu/przychodu.

## Macierz

| Miara | Status |
|---|---|
| Koszt materiałów/receptury | PARTIAL |
| Koszt opakowania | PARTIAL — pola/ślady, brak pełnej historii |
| Koszt pracy/mediów/logistyki sekcji | AVAILABLE dla konfiguracji miesięcznej, wiarygodność danych niezweryfikowana |
| Energia/transport/dostawa | PARTIAL |
| Magazynowanie/straty/odpady | MISSING_DATA |
| Promocje/prowizje | MISSING_DATA |
| Koszt produktu | PARTIAL/UNRELIABLE |
| Koszt partii/zamówienia/klienta | MISSING_PROCESS/PARTIAL |
| Marża brutto produktu | UNRELIABLE historycznie |
| Rentowność sklepu/kanału | MISSING_DATA |

Ryzyko: raporty używają funkcji datowych i miesięcznych alokacji, lecz bez snapshotu kosztu jednostkowego i receptury przy zdarzeniu sprzedaży wynik historyczny może zmieniać się po aktualizacji kartotek.

