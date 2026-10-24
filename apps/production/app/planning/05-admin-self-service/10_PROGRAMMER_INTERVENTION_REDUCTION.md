# Redukcja ingerencji programisty

## Cel operacyjny

Programista nie powinien wykonywać rutynowych zmian biznesowych ani „naprawiać danych” SQL-em. Jego rola pozostaje przy zmianie produktu, awarii infrastruktury, migracji schematu i przypadkach bez bezpiecznego playbooka. Self-service przenosi decyzję do właściciela procesu, ale nie przenosi ryzyka bez kontroli.

## Model pomiaru bazowego

Przez minimum 8 tygodni każde zgłoszenie oznaczyć: domena, przyczyna, metoda (SQL/server/config/script/code), czas aktywny i oczekiwania, ryzyko, liczba rekordów, recydywa, czy istniał playbook, oraz outcome. Telemetria obejmuje także ręczne uruchomienia CLI, retry endpointów, unlock, eksporty i bezpośrednie dostępy uprzywilejowane. Bez tego nie ma wiarygodnego ROI.

## Cele redukcji

| Klasa interwencji | Stan docelowy | Docelowy właściciel | Oczekiwana redukcja po wdrożeniu |
|---|---|---|---|
| retry/collector/outbox | monitor + idempotentny retry + wynik | support L2/integration owner | 60–80% |
| słownik/config biznesowy | versioned registry + approval | admin konfiguracji/process owner | 60–90% |
| import i przypisanie braków | staging + quality queue | data operator/steward | 60–80% |
| konto/role/scope/reset | bezpieczny IAM workflow | IAM admin/helpdesk | 70–90% |
| dokument regenerate/resend | action contract + outbox | operator dokumentów | 70–90% |
| korekta statusu/linku | repair case + domain command | process owner/steward | 50–70% |
| stan/okres/koszt | reconciliation + compensation + maker-checker | magazyn/controller | 50–80% |
| raport ad hoc | semantic export + lineage | analityk biznesowy | 40–60% |
| diagnoza | correlation + safe checks + audit timeline | support L1/L2 | 40–70% |

## KPI

- `programmer_interventions_per_1000_transactions` i per domena;
- udział ticketów wymagających SQL/server/config/code;
- median/95p lead time interwencji oraz czas programisty;
- first-contact resolution supportu;
- udział self-service zakończony sukcesem i zweryfikowany E2E;
- retry duplicate-effect rate (cel 0) i repair rollback/compensation rate;
- liczba operacji privileged bez powodu/approval (cel 0);
- liczba bezpośrednich zapisów DB poza aplikacją/pipeline (cel 0, poza break-glass);
- recydywa tej samej przyczyny w 30 dniach;
- audit completeness i liczba naruszeń scope.

## Co nadal wymaga specjalisty technicznego

- projekt i deployment zmian schematu, restore i disaster recovery;
- awaria bazy/kolejki/sieci, degradacja wydajności i security incident;
- nowy typ korekty bez zatwierdzonej semantyki domenowej;
- zmiana kontraktu integracji, kodu state machine albo algorytmu kosztowego;
- migracja/deprecacja źródła danych i legal purge;
- incydent łamiący invariants, którego nie obejmuje bezpieczna komenda.

Panel może dla tych przypadków zebrać diagnostykę i utworzyć sprawę, lecz nie oferuje arbitralnego execute.

## Break-glass

Awaryjna ingerencja wymaga imiennego, czasowego dostępu, ticketu, approval, rejestrowanej sesji/komend, backupu lub planu kompensacji, peer review rezultatu i obowiązkowego post-incident review. Każdy break-glass tworzy kandydat do nowego playbooka/self-service albo świadomą decyzję, dlaczego pozostaje techniczny.

## Model wdrożenia i adopcji

Pilotaż na jednej integracji i jednym scope; równoległy pomiar manual vs self-service; szkolenie o reason codes i kompensacji; runbook dla supportu; owner/SLA każdej kolejki. Po osiągnięciu kryteriów wyłącza się starą ścieżkę, ogranicza DB/serwer access i monitoruje próby obejścia. Nie uznawać sukcesu, jeśli praca przeniosła się do arkusza lub ukrytego endpointu.

