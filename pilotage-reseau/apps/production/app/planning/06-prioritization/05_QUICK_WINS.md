# Quick wins

## Kryterium

Quick win ma wysoką wartość lub redukcję ryzyka, koszt XS/S, małe ryzyko regresji i nie wymaga ukrytej migracji danych. Poniższe elementy są **pierwszymi slice’ami** inicjatyw, nie zamiennikami pełnego rozwiązania.

| Slice | Rodzic | Wartość | Koszt | Zależności | Wynik i kryterium zamknięcia |
|---|---|---|---|---|---|
| QW-01 rotacja ujawnionych sekretów i usunięcie ich z bieżącej konfiguracji | ERP-001 | krytyczna redukcja ekspozycji | XS/S | owner sekretu i dostęp do dostawcy | stare credential revoked, nowe poza repo, skan bez znanego sekretu; historia Git oceniona osobno |
| QW-02 usunięcie nadpisywania danych logowania i wyłączenie niebezpiecznej ścieżki do czasu migracji hasła | ERP-001 | blokuje fałszywe uwierzytelnianie | S | plan awaryjnego dostępu | brak danych domyślnych, test negatywny; nie nazywać pełnym IAM |
| QW-03 mapa deploymentów, cronów, entrypointów, baz i ownerów | ERP-002 | usuwa największą niepewność | S | dostęp read-only/wywiad | każdy App/Agent/worker ma status active/inactive/unknown i dowód |
| QW-04 inventory outboxów i workerów z mapą statusów | ERP-002/007 | zapobiega błędnej konsolidacji | S | repo + runtime metadata | producent, konsument, tabela, dedup, retry i owner dla każdej kolejki |
| QW-05 jedna komenda smoke/test statyczny uruchamiana w CI | ERP-004 | szybka wykrywalność regresji składni/wiring | S | runtime w CI | deterministyczny wynik i failure blokuje merge; nie zastępuje testów produktu |
| QW-06 read-only health page/raport bez sekretów | ERP-008 | skraca diagnozę | S | ERP-001; allowlista checków | wersja, DB/queue reachability i last worker success; zero mutacji/payloadów |
| QW-07 katalog permissions i macierz route→permission bez enforcement cutover | ERP-005 | przygotowuje bezpieczną migrację | S | decyzja ról/scopes | 100% aktywnych tras sklasyfikowanych, braki jawne |
| QW-08 reason-code catalog i polityka użycia, bez edytora produkcyjnego | ERP-012 | ujednolica korekty/audit | S | ownerzy procesów | zatwierdzone kody dla retry/cancel/correction; wersja w repo |
| QW-09 wybór okresu/dokumentów kontrolnych i definicji reconciliation | ERP-042 | umożliwia późniejszy dowód E2E | S | finanse/operacje | signed control pack z sumami i oczekiwanymi wyjątkami |
| QW-10 oznaczenie niewiarygodnych dashboardów i raportów jako estimate/unverified | ERP-031/037 | zmniejsza ryzyko złych decyzji | XS | owner raportu | każdy raport bez source/reconciliation ma widoczną jakość i datę |

## Elementy świadomie niewłączone

- „nowy dashboard” — wymaga faktów, korekt i reconciliation;
- „UI retry all” — wymaga wspólnego envelope/idempotencji;
- „formularz korekty stanu” — wymaga inventory ledger i ruchu kompensującego;
- „import uniwersalny” — wymaga canonical IDs, schematów i rollbacku;
- „przelicz wszystkie koszty” — wymaga temporalnych cen i lineage;
- „przenieś Agent do App” — to migracja wielu źródeł, nie quick win.

