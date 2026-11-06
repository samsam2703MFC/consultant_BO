# Analiza rozwoju użyteczności systemu produkcyjnego w kierunku ERP

Zakres: statyczna analiza panelu `App`, kodu API `Agent`, workerów, tras, serwisów, repozytoriów, migracji i istniejących materiałów audytowych. Nie zmieniono kodu aplikacji ani nie uruchamiano jej.

- Wykryte moduły: **14**.
- Przeanalizowane procesy: **9**.
- Luki użyteczności: **12**.
- Propozycje funkcjonalności: **16**.

Najważniejsze obserwacje:

1. Produkcja planowana jest jako lista, lecz nie jest rejestrowana jako wykonane zlecenie.
2. Magazyn nie ma jednej potwierdzonej księgi ruchów, rezerwacji ani identyfikowalności partii.
3. Zakupy, choć mają dane, nie tworzą pełnego cyklu od potrzeby do przyjęcia.
4. Bogate dane kosztowe i raportowe nie mają jeszcze gwarantowanego historycznego kontekstu.
5. Komunikacja i integracje mają workery, ale brakuje operatorowi widoku wyjątków oraz bezpiecznej samoobsługi.

Pierwsze działania: (1) kolejka wyjątków dokumentów i zamówień, (2) statusy i zlecenie produkcyjne MVP, (3) ruchy/rezerwacje magazynowe MVP, (4) PO→PZ dla zakupów, (5) snapshoty receptury i kosztu przy zamknięciu okresu.

Dokumenty: [mapa systemu](01-system-map.md), [workflowy](02-user-workflows.md), [dojrzałość](03-module-maturity.md), [luki](04-usability-gaps.md), [funkcje](05-feature-opportunities.md), [quick wins](06-quick-wins.md), [roadmapa](07-recommended-roadmap.md), [TOP 10](08-top-recommendations.md), [pytania](09-open-questions.md).
