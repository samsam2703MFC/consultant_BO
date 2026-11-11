# Decyzje dla istniejących funkcji

Decyzje obejmują wszystkie 50 pozycji znormalizowanego katalogu AS-IS. `REMOVE` nie jest tu wykonywane; kandydaci wymagający analizy zależności są opisani w `09_DEPRECATION_CANDIDATES.md`.

| Funkcja AS-IS | Decyzja | Uzasadnienie | Docelowe procesy / luki |
|---|---|---|---|
| ADMIN-001 użytkownicy | REBUILD | UI CRUD ma wartość, ale krytyczny mechanizm auth i brak ochrony uniemożliwiają bezpieczne rozszerzenie | HR-001, ADM-001; GAP-001–003/042 |
| ADMIN-002 role i uprawnienia | REPLACE | równoległy RBAC Agent i pole permission App powinny ustąpić jednej polityce | ADM-001; GAP-003/042 |
| ADMIN-003 języki i aliasy | COMPLETE | zachować self-service, dodać datowanie, audit i testy | ADM-003; GAP-005/049/054 |
| MASTERDATA-001 produkty | COMPLETE | zachować CRUD/wyszukiwanie, domknąć lifecycle, historię i walidację | MD-001; GAP-007/048 |
| MASTERDATA-002 kategorie produktów | COMPLETE | potrzebna funkcja, lecz wymaga stabilnego UI i historycznej klasyfikacji | MD-001; GAP-007/048 |
| MASTERDATA-003 materiały | COMPLETE | rdzeń kartoteki jest użyteczny; brakuje przeliczników, pack i parametrów partii | MD-002; GAP-008/036 |
| MASTERDATA-004 alergeny i wartości odżywcze | KEEP | wartościowy, odrębny model domenowy; przed uznaniem za complete potrzebuje testów E2E | MD-002; GAP-054/055 |
| MASTERDATA-005 dostawcy | EXTEND | CRUD zachować, dodać approval, status, historię warunków i ocenę | MD-004, PUR-004; GAP-009/039 |
| RECIPE-001 receptury finalne | REBUILD | edytowalny model bez wersji nie spełnia historyczności TO-BE | REC-001; GAP-011/048 |
| RECIPE-002 półprodukty i kompozycje | REFACTOR | zachować funkcje grafu, przenieść je do atomowej wersji receptury | REC-001; GAP-011/012 |
| RECIPE-003 kroki przygotowania | COMPLETE | model odpowiada potrzebie, ale deployment schematu i testy są niepotwierdzone | REC-001; GAP-049/054 |
| RECIPE-004 kompetencje receptur | DEFER | zachować dane/kod, ale rozwój nie powinien wyprzedzać wersji receptury i wykonania produkcji | HR-004/REC-001; GAP-011/044 |
| RECIPE-005 spłaszczenie i koszt | REBUILD | mutable projection i wartości zastępcze nie dają odtwarzalnej kalkulacji | REC-002/COST-002; GAP-013/045 |
| PRICING-001 cena materiału/changelog | EXTEND | wartościowy punkt startu; wymaga okresów, approval i pełnego lineage | REC-002/PUR-004; GAP-014/040 |
| PRICING-002 cenniki produktu/klienta | EXTEND | zachować UI/eksporty, dodać validity, VAT, reguły konfliktu i snapshot | SAL-001/002; GAP-014/048 |
| SALES-001 klienci | COMPLETE | CRUD działa jako baza; wymagane scope, lifecycle, zgody i snapshot dokumentowy | MD-005; GAP-003/010 |
| SALES-002 sieci i onboarding | DEFER | potwierdzić aktualną potrzebę/deployment; nie blokuje rdzenia sprzedaży B2B | ADM-001/SAL-002; GAP-004/050 |
| SALES-003 zamówienia | REFACTOR | zachować UI i dane, wprowadzić aggregate/state machine, partial i korekty | SAL-002; GAP-017 |
| SALES-004 changelog zamówienia | COMPLETE | potrzebny, lecz migracja i kompletność zdarzeń muszą być potwierdzone | SAL-002; GAP-017/049 |
| SALES-005 transakcje POS | REQUIRES_DECISION | bez dowodu aktywnego deploymentu nie wiadomo, czy kończyć, zastąpić czy wycofać | SAL-001; GAP-016/018 |
| SALES-006 promocje/zwroty/korekty POS | REQUIRES_DECISION | zależy od wyboru POS; obecny workflow i fakt promocji są nieudowodnione | SAL-003/004; GAP-015/018/020 |
| DOCUMENT-001 proforma/outbox | COMPLETE | główna ścieżka ma wartość; brak operatora retry i pełnej obserwowalności | DOC-001; GAP-024/050/051 |
| DOCUMENT-002 faktury | REQUIRES_DECISION | dwie implementacje wymagają wskazania source of truth i jurysdykcji | DOC-001; GAP-021 |
| DOCUMENT-003 korekty faktur | COMPLETE | użyć po wyborze modelu faktury, dodać UI, okres i E2E | DOC-002; GAP-020–022 |
| DOCUMENT-004 WDT/WZ/CMR | REFACTOR | zachować bogaty UI/PDF; skutki zapasu przenieść do ledgeru | DOC-001/LOG-001; GAP-023/032 |
| SHIPPING-001 planowanie wysyłki | REFACTOR | funkcja wartościowa, lecz statusy, POD, zwroty i koszt wymagają ujednolicenia | LOG-001; GAP-041 |
| SHIPPING-002 reklamacje | COMPLETE | zachować UI i dokumenty, domknąć kompensację stock/payment/cost | SAL-004/PUR-003; GAP-020/038 |
| PRODUCTION-001 lista produkcyjna | REFACTOR | zachować kalkulację jako plan/input, nie jako zlecenie ani actual | PLN-002/PRD-001; GAP-026/027 |
| PRODUCTION-002 zapotrzebowanie materiałowe | REFACTOR | wykorzystać po wersjonowaniu receptur i ledgerze; dodać reservation/shortage | PLN-002/INV-003; GAP-026/033 |
| PRODUCTION-003 wykonanie zlecenia | REBUILD | funkcja nie istnieje w potwierdzonym App | PRD-001/002; GAP-027/028 |
| PRODUCTION-004 QC, partie, traceability | REBUILD | brak potwierdzonego procesu i modelu | PRD-003/INV-005; GAP-029/036 |
| PRODUCTION-005 ruchy produktu/odpady | REPLACE | niepotwierdzony model Agent nie powinien konkurować z docelowym stock/production ledger | PRD-002/INV-001; GAP-030/032 |
| INVENTORY-001 stan kartonów | REPLACE | wykorzystać jako źródło migracji/projekcję, nie source of truth | INV-001–005; GAP-032/036 |
| INVENTORY-002 stan/log materiałów | REQUIRES_DECISION | najpierw potwierdzić deployment i jakość; docelowo podporządkować jednemu ledgerowi | INV-001–005; GAP-018/032 |
| INVENTORY-003 przyjęcia/wydania/przesunięcia | REBUILD | fragmenty muszą stać się jednym workflow opartym na ledgerze | INV-001/002; GAP-032/034 |
| INVENTORY-004 rezerwacje/partie/FEFO/wycena | REBUILD | brak funkcji/modelu w potwierdzonym systemie | INV-003/005; GAP-033/036 |
| INVENTORY-005 inwentaryzacja/korekty | REBUILD | wykorzystać idee Agent po potwierdzeniu, zintegrować z ledgerem i UI | INV-004; GAP-035 |
| PURCHASING-001 dokument zakupu | REFACTOR | zachować dane/formularze, osadzić w pełnym workflow PO–receipt | PUR-001–003; GAP-037/038 |
| PURCHASING-002 requisition/PO/dostawa | REQUIRES_DECISION | model Agent może zawierać wartościowy kod, lecz runtime jest nieznany | PUR-001–003; GAP-018/037 |
| PURCHASING-003 ocena dostawców/SLA | REBUILD | proces nie istnieje; prosty score powinien wynikać z dostaw | PUR-004; GAP-039 |
| EMPLOYEE-001 konta/role/kompetencje | REPLACE | dwa modele tożsamości i pracownika trzeba skonsolidować bez utraty historii | HR-001; GAP-003/042 |
| EMPLOYEE-002 grafiki/zadania | REQUIRES_DECISION | potwierdzić deployment; grafik można zachować, ale nie traktować jako actual | HR-002/004; GAP-018/043/044 |
| EMPLOYEE-003 RCP/nieobecności | REBUILD | brak potwierdzonych danych i procesu | HR-003; GAP-043 |
| COST-001 miesiące/praca/media/stałe/logistyka | REFACTOR | zachować wartości miesięczne jako wejścia, dodać ledger, close i wersję alokacji | COST-001–003; GAP-045–047 |
| ANALYTICS-001 sprzedaż/trendy WDT | REFACTOR | zachować zapytania/widoki dopiero po zmianie źródła na kanoniczny fakt | SAL-005; GAP-016/048 |
| ANALYTICS-002 marża/kategoria/sekcja | REBUILD | obecny wynik jest historycznie niewiarygodny | COST-002/003; GAP-013/045–048 |
| ANALYTICS-003 raporty POS PL/BE | REQUIRES_DECISION | zależy od deploymentu POS i wymogów krajowych | SAL-005/DOC-001; GAP-018/021 |
| INTEGRATION-001 inbox/outbox/retry/workery | REFACTOR | zachować sprawdzone fragmenty, ujednolicić kontrakt i dodać konsolę | ADM-002; GAP-050/051 |
| INTEGRATION-002 S3/e-mail/Peppol/MQ | REFACTOR | adaptery mają wartość, ale wymagają bezpiecznej konfiguracji, sandboxu i health | DOC-001; GAP-001/052 |
| OBSERVABILITY-001 monitoring/health/audit | REBUILD | fragmentaryczne logi nie zapewniają śledzenia E2E ani self-service | wszystkie; GAP-053 |

## Bilans decyzji

Najczęściej rekomendowane są `COMPLETE`, `REFACTOR` i `REBUILD`. Brak bezwarunkowego `REMOVE`: stan danych i deploymentu jest zbyt słabo potwierdzony, aby bezpiecznie usuwać moduły. `REQUIRES_DECISION` koncentruje się wokół Agent/POS, faktur i równoległych modeli.
