# Znormalizowany backlog programu

## Zasady normalizacji

- Jeden wpis opisuje zdolność z mierzalnym wynikiem, nie warstwę techniczną ani objaw.
- UI, backend, dane, migracja, audit i test są częścią tej samej pionowej inicjatywy, jeśli służą jednemu celowi.
- Self-service retry połączono z niezawodnością integracji i Operations Inbox; osobne ekrany retry odrzucono.
- Korekty statusu, salda, dokumentu i okresu korzystają ze wspólnego kontraktu korekty, ale domenowe skutki pozostają w inicjatywach domenowych.
- Identyfikatory `ERP-nnn` są trwałe. Podział realizacyjny używa sufiksów, np. `ERP-019A`, bez zmiany znaczenia rodzica.

| ID | Inicjatywa / wynik | Źródła scalone | P | Typ | Twarde zależności |
|---|---|---|---|---|---|
| ERP-001 | zabezpieczenie sekretów i naprawa uwierzytelniania App | GAP-001/002, MI-001/026 | P0 | remediation | — |
| ERP-002 | inventory runtime/deploymentów, Agent, integracji, schematu i konsumentów | GAP-018/049/052, deprecation audit | P0 | discovery/gate | — |
| ERP-003 | schema baseline, profil danych, constraints i runner migracji | GAP-049, SS-029/032 | P0 | foundation/migration | ERP-002 |
| ERP-004 | test harness, izolowana DB, fixtures i CI | GAP-054 | P0 | foundation | ERP-003 |
| ERP-005 | canonical organization, identity, RBAC, scope i administracja dostępu | GAP-003/004/042, SS-001 | P0 | foundation/migration | ERP-001/002/003/004, BD-10 |
| ERP-006 | command context, append-only audit, approval i wspólny kontrakt korekty | GAP-006/020/053, SS-002/026 | P0 | foundation | ERP-003/004/005, BD-07/09/15 |
| ERP-007 | wspólny outbox/inbox, idempotencja i wersjonowane adaptery | GAP-050/052 | P0 | foundation/migration | ERP-001/002/003/004/006 |
| ERP-008 | Operations Inbox, health, correlation, retry/DLQ i async job center | GAP-024/051, SS-003/007/025/030 | P1 | self-service | ERP-005/006/007 |
| ERP-009 | canonical master data: produkt, klient, dostawca i lifecycle/historia | GAP-007/009/010, SS-009 | P1 | data/domain | ERP-003/005/006 |
| ERP-010 | jednostki, opakowania i wersjonowane przeliczniki | GAP-008 | P0 | data/domain | ERP-003/006 |
| ERP-011 | temporal snapshot contract dla faktów i wymiarów | GAP-048 | P0 | foundation/data | ERP-003/004/006/009/010 |
| ERP-012 | wersjonowana konfiguracja, słowniki, reason codes i vault references | GAP-005, SS-019/020/023/024 | P1 | self-service | ERP-005/006/009 |
| ERP-013 | wersjonowanie receptur i spójny graf bez cykli | GAP-011/012 | P0 | domain/data | ERP-003/004/006/009/010/011 |
| ERP-014 | koszt normatywny receptury z lineage i stanem INCOMPLETE | GAP-013, SS-012 | P1 | domain/data | ERP-013/015/028 |
| ERP-015 | temporalne ceny, VAT, marże i publikacja cenników | GAP-014, część GAP-040, SS-021 | P1 | domain/data | ERP-006/009/010/011/012, BD-06/11 |
| ERP-016 | canonical sale/payment/return/correction fact | GAP-015/016/019/020 | P0 | domain/migration | ERP-005/006/007/009/010/011/015, BD-01/11/12 |
| ERP-017 | zamówienie B2B: state machine, partial/backorder, cancel i reservation link | GAP-017 | P1 | domain | ERP-005/006/009/010/011/015/019/021, BD-08 |
| ERP-018 | canonical dokument, numeracja, issue/send i korekta faktury | GAP-021/022/024, SS-011/018/022 | P0 | domain/migration | ERP-005/006/007/011/012/016, BD-01/07/11 |
| ERP-019 | immutable inventory ledger, opening balance i reconciliation | GAP-023/032 | P0 | domain/migration | ERP-002/003/004/005/006/009/010/011, BD-02/06 |
| ERP-020 | partie, expiry, FEFO i genealogia | GAP-029/036 | P1 | domain/data | ERP-009/010/011/019, BD-03/05 |
| ERP-021 | rezerwacje, transfery, stocktake i adjustment self-service | GAP-033/034/035, SS-004 | P1 | domain/self-service | ERP-005/006/019/020, BD-02/07/09 |
| ERP-022 | requisition–PO–receipt, cena dostawy i rozbieżności | GAP-037/038/040 | P0 | domain/migration | ERP-005/006/007/009/010/011/015/019, BD-14 |
| ERP-023 | production order i rzeczywiste consume/output events | GAP-026/027/028 | P0 | domain/data | ERP-005/006/009/010/011/013/019/020/021, BD-04 |
| ERP-024 | QC, waste, production correction/reopen i traceability | GAP-029/030/031, SS-015 | P1 | domain | ERP-006/020/023/028/029, BD-05/07/09 |
| ERP-025 | shipment state, custody, POD, return i koszt dostawy | GAP-023/041 | P1 | domain | ERP-006/007/016/017/018/019, BD-08 |
| ERP-026 | employee identity/assignment, actual time, absence i korekty | GAP-042/043 | P1 | domain/data | ERP-005/006/011, BD-13/15 |
| ERP-027 | czas pracy przypisany do zlecenia/operacji | GAP-044 | P1 | domain/data | ERP-023/026, BD-13 |
| ERP-028 | cost ledger: source entries, metoda, waluta i adjustments | GAP-045 | P0 | domain/data | ERP-003/006/011/015/019/022, BD-06 |
| ERP-029 | period close/reopen, completeness i immutable report hash | GAP-046, SS-005 | P0 | domain/self-service | ERP-006/018/019/021/028, BD-07/09 |
| ERP-030 | alokacja kosztu produktu/partii/sklepu/klienta i profitability facts | GAP-047 | P1 | controlling/data | ERP-016/020/023/025/027/028/029 |
| ERP-031 | read models, semantic KPI registry, freshness, lineage i reconciliation | GAP-055 część danych, SS-027/028/031 | P1 | reporting | ERP-006/011 i odpowiednie fakty domenowe |
| ERP-032 | staged import, data-quality queue, merge/link repair i eksport kontrolowany | SS-008/010/013/014/034 | P1 | self-service/data | ERP-003/005/006/009/010/011 |
| ERP-033 | wspólny Repair Workbench dla legalnych komend i kompensacji | SS-006, MI-019–025/032–033 | P1 | self-service | ERP-005/006/008 i domenowe correction commands |
| ERP-034 | plan popytu i wersjonowany plan produkcji | GAP-025/026 | P2 | planning | ERP-009/013/017/019/021/023/031, BD-04/08 |
| ERP-035 | supplier score i SLA ze zdarzeń dostaw | GAP-039 | P2 | analytics | ERP-009/022/031 |
| ERP-036 | promocje/vouchery i koszt rabatu | GAP-015 | P2 | domain | ERP-015/016/031 |
| ERP-037 | raportowanie rolowe sprzedaży, stock, produkcji, zakupów i czasu | wymagania TO-BE/reporting | P2 | reporting | ERP-031 + facts danej domeny |
| ERP-038 | controlling: marża i rentowność uzgodniona do okresu | KPI readiness, GAP-047 | P2 | controlling | ERP-016/028/029/030/031 |
| ERP-039 | load, concurrency, recovery i restore tests | GAP-056 | P1 | resilience | ERP-003/004/007/019/042 |
| ERP-040 | kontrolowana deprecacja legacy, konsolidacja modeli i retencja/purge | deprecation candidates, SS-017 | P1 | migration/maintenance | ERP-002/003/006, replacement per capability, BD-15 |
| ERP-041 | forecast statystyczny/scenariusze | TO-BE advanced, GAP-025 | P3 | forecasting | ERP-031/034 + min. 2 cykle jakościowych danych |
| ERP-042 | krytyczny pakiet E2E/reconciliation: sale→stock→cost→document→correction | GAP-055 | P0 | quality/gate | ERP-004; rozwijany wraz z ERP-016/018/019/022/023/028/029 |

## Mapowanie objawów do problemów źródłowych

| Objaw/usunięta pozycja | Właściciel w backlogu |
|---|---|
| ręczny SQL do statusu | ERP-006 + komenda w odpowiedniej domenie + ERP-033 |
| osobne retry proformy, wysyłki, zamówienia, reklamacji | ERP-007/008, nie cztery panele |
| „napraw stan” przez update salda | ERP-019/021 jako ruch kompensujący |
| dashboard rentowności | ERP-028–031/038, nie poprawka zapytania |
| eksport jako obejście procesu | ERP-032; proces źródłowy pozostaje domenowy |
| osobny audit per moduł | ERP-006 z osiami czasu domenowymi |
| odrębny IAM App i Agent | ERP-005 z migracją obu modeli |

