# Priorytetyzacja programu modernizacji ERP — podsumowanie

## Werdykt

Z 56 luk, 34 pozycji self-service, wymagań danych, procesów TO-BE i decyzji architektonicznych utworzono **42 trwałe inicjatywy `ERP-001`–`ERP-042`**. Kolejność nie wynika z prostej sumy punktów. Najpierw stosowane są bramy bezpieczeństwa, integralności i dowodowości, następnie zależności danych i dopiero później wartość użytkowa.

Program ma jedną główną ścieżkę krytyczną:

`rozpoznanie runtime/danych → schema i test harness → IAM/audit → snapshot/correction → ledgery/fakty źródłowe → procesy E2E → read models → controlling → planowanie/prognoza`.

## Klasy priorytetów

| Klasa | Liczba | Znaczenie w tym programie |
|---|---:|---|
| P0 | 18 | aktywne ryzyko bezpieczeństwa/integralności albo fundament bez którego zmiana nie jest bezpieczna |
| P1 | 18 | domknięcie krytycznych procesów i eliminacja częstych interwencji technicznych |
| P2 | 5 | raportowanie, efektywność i rozszerzenie dojrzałego core |
| P3 | 1 | predykcja/zaawansowana optymalizacja po zebraniu danych |

## Pierwsze decyzje i działania

1. Natychmiast zabezpieczyć sekret i wadliwe logowanie (`ERP-001`).
2. Potwierdzić aktywny runtime Agent, deploymenty, schemat i konsumentów (`ERP-002`); bez tego decyzje reuse/retire są pozorne.
3. Ustanowić baseline schematu, test environment i runner migracji (`ERP-003`) oraz minimalny harness CI (`ERP-004`).
4. Wprowadzić organization scope/RBAC (`ERP-005`) i wspólny command/audit/correction context (`ERP-006`).
5. Następnie dostarczać pionowe fragmenty, zaczynając od niezawodności integracji, inventory ledger/opening balance i canonical sale.

## Wąskie gardła

- dostęp do metadanych/profilu bazy oraz decyzja o aktywnym Agent;
- decyzje BD-01–15, szczególnie źródło sprzedaży, wycena zapasu, korekty okresu i scope organizacyjny;
- migracja dwóch/równoległych źródeł inventory, faktur, auth i outboxów;
- brak danych kontrolnych: opening stock, zamknięty okres, dokumenty referencyjne;
- jednoosobowa realizacja przy wielu domenach i konieczności UAT z właścicielami.

## Co nie jest quick winem

Dashboard sprzedaży, rentowności, zapasu, produkcji ani forecast. Żaden z 46 badanych KPI nie był `AVAILABLE`; wykres bez kanonicznego faktu, korekt, snapshotu i reconciliation utrwaliłby niewiarygodny wynik.

## Strategia dla jednej osoby

Preferowane są pionowe pakiety 2–6 tygodni, jeden aktywny strumień mutujący dane i maksymalnie jeden mały strumień read-only. Każdy pakiet obejmuje model, komendę, uprawnienie, historię/korektę, migrację/feature flag, test E2E i minimalny read model. Rozdzielanie prac na „cały backend”, „cały frontend” lub mikrousługi zwiększa przełączanie kontekstu i koszt utrzymania.

## Ograniczenia pewności

Scoring jest relatywną hipotezą na podstawie repozytorium i dokumentów, nie estymacją harmonogramową. Nie uruchomiono bazy, PHP ani integracji i nie potwierdzono runtime Agent, liczby użytkowników, wolumenów, incydentów ani prawnych deadline’ów. Po `ERP-002/003` wyniki trzeba skalibrować, zachowując identyfikatory.

