# Architektura raportowania

## Decyzja

Na obecnej skali raportowanie korzysta z tej samej bazy MySQL, ale przez dedykowane query services, widoki i odbudowywalne tabele agregacyjne. Domena nie zna dashboardów, a dashboard nie wykonuje zapisu. Oddzielna baza analityczna i hurtownia są opcją wzrostową, nie wymaganiem MVP.

## Wariant etapowy

| Wariant | Architektura | Dopuszczalne użycie | Ograniczenia i brama przejścia |
|---|---|---|---|
| obecny — stabilizacja | istniejące SELECT-y/WDT/cost views z opisem definicji i flagą jakości | operacyjne zestawienia, po potwierdzeniu źródła | nie publikować niewiarygodnej marży; wykonać profil danych/reconciliation |
| najbliższy rozwój — rekomendowany | kanoniczne facts/snapshots + SQL views i incremental aggregate tables w tej samej DB | sprzedaż, stock, produkcja, koszt i okres; drill-down | read model rebuildable, watermark/version/quality; indeksy i EXPLAIN |
| znaczący wzrost | read replica lub osobna analytics DB zasilana outbox/CDC; model wymiarowy | wysokie wolumeny, wielu użytkowników BI, długie retencje, wiele źródeł | dopiero po metrykach obciążenia, SLA i właścicielu danych |
| hurtownia danych | osobny program governance/ETL/dimensions | wiele systemów i self-service BI w skali | nie wprowadzać bez uzasadnienia; nie naprawi złych faktów źródłowych |

## Read modele w tej samej bazie

| Read model | Ziarno | Źródło | Odświeżanie |
|---|---|---|---|
| `sales_daily` | dzień–organization/shop/channel/product/customer/promotion | sales line events + corrections | incremental po event watermark, rebuild okresu możliwy |
| `inventory_daily_balance` | dzień–warehouse/location/product/lot | stock movements + controlled opening | zamknięcie dnia i on-demand rebuild |
| `production_order_summary` | production order/operation/lot | production events + movements + time + QC | incremental; final snapshot przy close |
| `purchase_delivery_summary` | PO/receipt/supplier | PO/receipt/discrepancy facts | incremental |
| `cost_object_period` | period–cost center/product/lot/shop/customer | cost entries + allocation version | po walidacji/close; każda publikacja versioned |
| `reconciliation_status` | process/date/source set | reguły kontrolne | po batchu i przed period close |

## Zasady zapytania

- proste operacyjne listy mogą czytać stan aggregate przez query service;
- raporty historyczne czytają fakty/snapshoty/read models, nie bieżącą kartotekę do wartości „wtedy”;
- widoki SQL są właściwe dla lekkich joinów i jednej definicji;
- tabela agregacyjna jest właściwa dla drogich, powtarzalnych obliczeń i musi być odbudowywalna;
- period snapshot jest właściwy dla opublikowanego kosztu/raportu;
- brak N+1 i nieindeksowanych filtrów na krytycznych ekranach;
- eksport dużego zakresu działa async i zapisuje watermark/query definition.

## Publikacja KPI

Każda definicja KPI posiada: nazwę, opis biznesowy, ziarno, licznik/mianownik, źródła, event types, filtr statusów/korekt, timezone, walutę, ownera, calculation version i status jakości. KPI może być `DRAFT`, `RECONCILED`, `PUBLISHED` lub `WITHDRAWN`.

## Quality gate

Raport nie jest publikowany, jeśli:

- źródłowy okres jest niekompletny;
- istnieją dead messages wpływające na zakres;
- reconciliation dokument–sale–payment–stock lub ledger–balance ma niewyjaśnioną różnicę;
- koszt ma `INCOMPLETE`;
- brak required snapshotu;
- okres został ponownie otwarty i nie przeliczono wersji raportu.

## Wydajność i izolacja

Najpierw stosować indeksy, ograniczenie zakresów, pagination, incremental aggregates i plan wykonania. Read replica ma sens, gdy raporty konkurują z zapisem mimo optymalizacji. Analytics DB ma sens, gdy potrzebna jest niezależna retencja, wielu konsumentów lub integracja wielu źródeł. Decyzję opierać na metrykach, nie przewidywaniach.

## Migracja istniejących dashboardów

Układ i UX można zachować. Każdy raport otrzymuje mapę starej i nowej definicji, równoległe obliczenie na zamkniętym okresie, wyjaśnienie różnic, akceptację właściciela i dopiero wtedy przełączenie. Stary wynik pozostaje oznaczony jako legacy/estimate, jeśli nie można go reprodukować.
