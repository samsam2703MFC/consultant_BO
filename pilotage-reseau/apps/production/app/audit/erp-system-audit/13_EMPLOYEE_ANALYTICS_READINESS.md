# Gotowość analityki pracowników

Kod Agent posiada pracowników, role, pozycje, stanowiska, kompetencje, grafiki i zadania; panel App posiada uproszczonych pracowników produkcyjnych i miesięczne koszty pracy. Brak potwierdzonego RCP, wejść/wyjść, przerw, nieobecności i przypisania rzeczywistego wykonania produkcji do osoby/zmiany.

| KPI | Status |
|---|---|
| Sprzedaż na pracownika | PARTIAL — POS ma `id_employee`, deployment niepotwierdzony |
| Sprzedaż na roboczogodzinę | MISSING_DATA |
| Produkcja na pracownika/zmianę | MISSING_PROCESS |
| Produkcja na roboczogodzinę | MISSING_PROCESS |
| Koszt pracy miesięczny/sekcja | PARTIAL |
| Koszt pracy produktu | PARTIAL/UNRELIABLE — alokacja miesięczna, brak czasu operacji |
| Punktualność/nieobecności | MISSING_DATA |
| Błędy/korekty pracownika | UNRELIABLE — brak pełnego audytu aktora |
| Obciążenie zadaniami | PARTIAL — kod zadań Agent |

Nie należy budować rankingu osób. Najpierw trzeba zapewnić kompletność zdarzeń, porównywalność ról i kontekst zmian.

