# Backlog administracyjnego self-service

## Skale

Priorytet `P0–P3`; wartość `H/M/L`; koszt `XS–XL`; ryzyko wskazuje ryzyko operacji, nie realizacji. Redukcja oznacza oczekiwany udział przypadków danej klasy bez programisty po ustabilizowaniu i jest hipotezą do pomiaru.

## Operacje krytyczne

| ID | Pozycja | Priorytet | Wartość | Koszt | Ryzyko | Zależności | Redukcja |
|---|---|---|---|---|---|---|---|
| SS-001 | bezpieczne IAM: konta, role, scope, sesje | P0 | H | XL | CRITICAL | GAP-001–004, canonical org/user | 70–90% administracji dostępem |
| SS-002 | audit platform i centralna autoryzacja akcji | P0 | H | L | CRITICAL | IAM, audit store/outbox | warunek dla wszystkich kolejnych |
| SS-003 | Integration Monitor + pojedynczy retry/dead resolution | P0 | H | L | HIGH | wspólny envelope, idempotency, inventory outboxów | 60–80% incydentów retry |
| SS-004 | stock adjustment przez ruch kompensujący | P0 | H | XL | CRITICAL | canonical ledger, units/lots, approval | 50–70% ręcznych korekt stanu |
| SS-005 | close/reopen okresu z checklistą | P0 | H | L | CRITICAL | cost ledger, reconciliation, audit | 70–90% technicznych unlocków |
| SS-006 | repair case + legalne korekty statusu | P1 | H | XL | CRITICAL | state machines, history, approval | 50–70% status SQL/tickets |

## Operacje częste

| ID | Pozycja | Priorytet | Wartość | Koszt | Ryzyko | Zależności | Redukcja |
|---|---|---|---|---|---|---|---|
| SS-007 | Operations Inbox, owner, SLA, correlation | P1 | H | L | MEDIUM | observability, audit | 40–60% czasu diagnozy |
| SS-008 | kontrolowany import z stagingiem i raportem | P1 | H | L | HIGH | canonical IDs, validation schemas | 60–80% importów/skryptów |
| SS-009 | master-data registry produktu/klienta/dostawcy | P1 | H | XL | HIGH | history/version, dedup | 50–70% zmian danych |
| SS-010 | wspólny eksport z maskingiem i manifestem | P2 | M | M | MEDIUM | permission scopes, async jobs | 40–60% raportów ad hoc |
| SS-011 | regenerate/resend dokumentu | P1 | H | M | HIGH | canonical document/snapshot/outbox | 70–90% ticketów dokumentowych |
| SS-012 | recalculation job center | P1 | H | M | HIGH | versioned sources, read models | 60–80% ręcznych przeliczeń |
| SS-013 | data-quality queue: missing/duplicate/link | P1 | H | L | HIGH | canonical IDs, lineage | 50–70% poprawek danych |

## Operacje rzadkie, ale ryzykowne

| ID | Pozycja | Priorytet | Wartość | Koszt | Ryzyko | Zależności | Redukcja |
|---|---|---|---|---|---|---|---|
| SS-014 | merge/unmerge-safe duplikatów | P2 | M | L | HIGH | dependency graph, aliases | 50–70% merge SQL |
| SS-015 | korekta/reopen produkcji | P1 | H | XL | CRITICAL | production execution/ledger/cost | 60–80% korekt zleceń |
| SS-016 | rotacja sekretu inicjowana z panelu | P2 | M | L | CRITICAL | vault, IAM, deployment adapters | 40–60% zmian config przez dev |
| SS-017 | lifecycle archive/purge/legal hold | P3 | M | L | CRITICAL | retention decisions, inventory consumers | 30–50% skryptów porządkowych |
| SS-018 | naprawa numeracji/void workflow | P1 | H | L | CRITICAL | numbering service, document model | 60–80% incydentów numeracji |

## Konfiguracja

| ID | Pozycja | Priorytet | Wartość | Koszt | Ryzyko | Zależności | Redukcja |
|---|---|---|---|---|---|---|---|
| SS-019 | versioned config registry i dziedziczenie scope | P1 | H | L | HIGH | organization model, audit | 60–80% zmian kod/config |
| SS-020 | słowniki/reason codes z usage check | P2 | M | M | MEDIUM | config registry | 70–90% seedów słownikowych |
| SS-021 | ceny/marże/podatki effective-date publish | P1 | H | XL | CRITICAL | pricing/history, approval | 60–80% migracji/SQL |
| SS-022 | numeracje i typy dokumentów | P1 | H | L | CRITICAL | document service | 60–80% zmian technicznych |
| SS-023 | konfiguracja sklepu/produkcji/magazynu | P2 | H | L | HIGH | config registry, policy owners | 50–70% zmian config |
| SS-024 | integration config metadata + connection test | P1 | H | L | CRITICAL | adapters, vault references | 50–70% interwencji deploy |

## Diagnostyka

| ID | Pozycja | Priorytet | Wartość | Koszt | Ryzyko | Zależności | Redukcja |
|---|---|---|---|---|---|---|---|
| SS-025 | allowlisted diagnostics i health inventory | P1 | H | M | LOW | telemetry, ownership registry | 50–70% pytań do programisty |
| SS-026 | audit explorer i timeline obiektu | P1 | H | L | MEDIUM | audit/business events | 40–60% czasu dochodzeń |
| SS-027 | reconciliation center: sales/stock/cost | P1 | H | XL | CRITICAL | canonical facts/ledger/KPI definitions | 50–70% analiz SQL/arkuszy |
| SS-028 | KPI freshness/quality/lineage panel | P2 | H | L | MEDIUM | semantic/read model layer | 40–60% sporów o raport |
| SS-029 | schema/config drift read-only report | P1 | M | M | HIGH | schema baseline/runner/IaC | 30–50% diagnoz środowiska |

## Narzędzia utrzymaniowe

| ID | Pozycja | Priorytet | Wartość | Koszt | Ryzyko | Zależności | Redukcja |
|---|---|---|---|---|---|---|---|
| SS-030 | async job center: postęp, cancel, result, retry failed | P1 | H | L | HIGH | job/outbox platform | wspólna baza dla import/rebuild/batch |
| SS-031 | read-model rebuild z shadow comparison | P2 | M | L | HIGH | projection version/watermark | 50–70% skryptów rebuild |
| SS-032 | migration status visibility | P1 | M | M | HIGH | runner/baseline | widoczność 100%; wykonanie nadal DevOps |
| SS-033 | feature/config change calendar i expiry | P2 | M | M | MEDIUM | config registry | 30–50% koordynacji ręcznej |
| SS-034 | maintenance evidence bundle | P2 | M | M | LOW | audit, diagnostics, export | 40–60% zbierania dowodów |

## Definition of Ready backlogu

Pozycja wchodzi do realizacji dopiero po wskazaniu ownera procesu, wolumenu/ticket baseline, obiektów i source of truth, polityki uprawnień/approval, invariants, strategii kompensacji, danych testowych i kryteriów E2E. Procenty redukcji nie są obietnicą; muszą zostać zastąpione pomiarem w pilotażu.

