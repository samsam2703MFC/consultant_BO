# Wejście do priorytetyzacji

## Teza

Priorytetyzować należy zdolności i redukcję ryzyka, nie liczbę ekranów. P0 to fundament bezpieczeństwa oraz operacje, których obecne obejście może zmienić krytyczny fakt bez audytu. Read-only diagnostyka może wejść wcześnie, ale akcje mutujące są blokowane przez IAM/audit/idempotency.

## Pakiety zależności

| Pakiet | Zawartość | Blokuje / odblokowuje | Gate |
|---|---|---|---|
| PF-1 Trust foundation | auth, RBAC, scope, policy enforcement, audit, correlation | wszystkie mutacje admin | deny-by-default i test cross-scope PASS |
| PF-2 Operations platform | case/inbox, action contract, approval, async jobs | repair, batch, import, retry | lifecycle, idempotency, maker-checker PASS |
| PF-3 Integration control | envelope, outbox/inbox inventory, attempts, adapter health | retry, resend, collectors | duplicate-effect=0 w testach |
| PF-4 Data foundation | canonical IDs, constraints, lineage, quality rules | merge, link repair, import, reconciliation | source/reconciliation uzgodnione |
| PF-5 Versioned configuration | registry, effective date, hierarchy, vault refs | config, price, tax, numbering | preview/publish/rollback PASS |
| PF-6 Domain correction | state machines, ledger, history, compensation | status/stock/production/period repair | invariants i correction E2E PASS |

## Rekomendowany scoring

`Score = 3×Risk reduction + 3×Frequency + 2×Programmer hours + 2×Capabilities unlocked + Compliance urgency − 2×Cost − 2×Migration uncertainty`, każda składowa 0–5. Dodatkowy gate, nie punkty: nie wolno realizować mutacji bez ownera, permission, audit i compensation. Częstotliwość oraz godziny pochodzą z ticketów/telemetrii, nie z intuicji.

## Kandydaci do pierwszego koszyka

| Kandydat | Dlaczego teraz | Warunek wejścia | Kryterium wyniku |
|---|---|---|---|
| IAM + audit foundation (SS-001/002) | zamyka P0 i umożliwia bezpieczne delegowanie | decyzje role/scope/SoD | deny tests, audit completeness, zero self-approval |
| read-only Operations Inbox/diagnostics (SS-007/025/026) | szybka redukcja czasu diagnozy bez mutacji | correlation i inventory źródeł | większość incydentów znajdowalna bez serwera/SQL |
| pojedynczy integration retry (SS-003) | istnieją workery i endpointy do konsolidacji | wspólny envelope/idempotency | retry E2E bez podwójnego skutku |
| import staging (SS-008) | usuwa powtarzalne skrypty i daje jakość danych | canonical ID/schema | raport per wiersz, rollback/compensation test |
| versioned config registry (SS-019/020/024) | usuwa migracje słowników i edycję config | PF-1 i ownerzy | publish/rollback/audit oraz zero sekretów w UI |
| close/reopen workflow (SS-005) | obecny unlock ma krytyczne ryzyko | cost reconciliation + SoD | checklist, approval, immutable report, reclose |

## Pozycje odroczone do fundamentów domenowych

- stock adjustment przed canonical ledger i jednostkami/lotami;
- korekta produkcji przed realnym execution i recipe version;
- arbitralna naprawa statusu przed zatwierdzonymi state machines;
- merge klientów/produktów przed canonical ID i dependency inventory;
- KPI rebuild/reconciliation przed definicją canonical facts i lineage;
- purge/retencja przed decyzją prawną i potwierdzeniem konsumentów.

## Decyzje biznesowe wymagane

1. Kto jest ownerem każdej kartoteki, polityki i kolejki błędów?
2. Jakie role i scope organizacyjne obowiązują; które pary uprawnień naruszają SoD?
3. Jakie progi wymagają drugiego zatwierdzenia (wartość, liczba rekordów, okres)?
4. Które statusy/przejścia i korekty są legalne w każdej domenie?
5. Czy korektę zamkniętego okresu realizować reopen czy adjustment w następnym okresie?
6. Jakie są retencja, legal hold, PII masking i odbiorcy eksportów?
7. Które drzewo Agent jest aktywne i które outboxy/endpointy obsługują produkcję?
8. Jakie są aktualne wolumeny ticketów, ręcznych CLI/SQL i godziny programistów?

## Kryteria zamknięcia programu self-service

- każda operacja katalogowa ma ownera, permission, scope, risk, audit i runbook;
- `HIGH/CRITICAL` ma przetestowane preview, approval, before/after i compensation;
- brak rutynowych zapisów SQL i zmian plików config poza kontrolowanymi pipeline/vault;
- stare endpointy/skrypty są zinwentaryzowane, odłączone dopiero po braku ruchu i retencji;
- KPI redukcji ingerencji są mierzone przez co najmniej dwa pełne cykle biznesowe;
- support i ownerzy potwierdzają E2E, a nie tylko istnienie przycisku;
- security, audit i reconciliation gates mają wynik PASS.

## Przekazanie do następnego etapu

Do priorytetyzacji przekazać: 51 zdolności z katalogu, 38 interwencji z poziomem dowodu, 34 pozycje backlogu, sześć pakietów zależności oraz osiem otwartych decyzji. Nie tworzyć promptów implementacyjnych przed wyborem source of truth, potwierdzeniem runtime Agent i ustanowieniem PF-1.

