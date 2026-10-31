# STB-001 — Rotacja sekretów i granica konfiguracji

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Stabilization / F0 / S |
| cel biznesowy | uniemożliwić użycie ujawnionych poświadczeń i przywrócić bezpieczne działanie aktywnych integracji |
| problem | sekret występuje w śledzonej konfiguracji; config/rotacja wymaga dostępu technicznego |
| rezultat | aktywne komponenty używają nowych secret references, stare credentials są revoked, repo/logi nie zawierają wartości |

## Zakres i granice

W zakresie: inventory wszystkich referencji danego sekretu, rotacja u providera/DB, rozdzielenie config per environment, walidacja wymaganych keys bez wyświetlania wartości, bezpieczny startup failure, skan repo i runbook. Poza zakresem: pełny vault UI, IAM użytkowników, przepisywanie historii Git bez osobnej zgody, rotacja niepowiązanych credentials.

## Repozytoria i kolejność

App → aktywny Agent/Worker potwierdzony przez DSC-001 → deployment/secret store. Strategia: **zestaw promptów per repozytorium z promptem koordynującym**. Kolejność: kontrakt nazw/reference → provider rotation → server config → workers/clients → health → revoke old. Nie wolno wkleić sekretu do promptu, logu ani diffu.

## Dane, migracje i kontrakty

- Tabele: brak planowanej tabeli domenowej; ewentualny config metadata zapisuje wyłącznie alias/fingerprint/rotated_at/owner, nigdy wartość.
- Migracja: nie SQL; staged credential rotation z krótkim dual-valid window tylko jeśli provider i ryzyko pozwalają.
- Endpointy: bez nowego publicznego endpointu; istniejący lub minimalny authenticated health check bez danych wrażliwych.
- UI: `N/A` — operacja bezpieczeństwa wykonywana przez provider/deployment; użytkownik widzi co najwyżej status reference/health w przyszłym SS-002.

## Kontrole

- Uprawnienie: deployment/security role; brak prawa dla zwykłego administratora ERP.
- Audit: kto/kiedy/jaki alias/environment/result; tylko fingerprint, bez credential/payload.
- Walidacja: fail closed dla brakującego config, allowlist host/environment, redaction exceptions.

## Testy i dane testowe

Unit config validator/redaction; integration bootstrap z missing/invalid reference; contract health dla aktywnego adaptera; negative old credential; smoke każdego aktywnego deploymentu. Test data: syntetyczne credentials wyłącznie sandbox, test endpoint/provider, bez produkcyjnego sekretu w fixtures.

## Kryteria akceptacji

1. Stary credential jest odrzucony/revoked.
2. Każdy potwierdzony aktywny komponent działa z nowym reference.
3. Repo/CI artifacts/logi/audit nie zawierają wartości ani rozpoznawalnego secret fragmentu.
4. Brak config powoduje bezpieczny, diagnostyczny failure bez fallback default.
5. Runbook rotation/rollback i owner są zatwierdzone.

## Definition of Done

ERP DoD w zakresie security/config/test/deployment/documentation; UI/migracja DB/correction oznaczone `N/A` z powyższym uzasadnieniem. Security owner potwierdza revoke i redaction.

## Ryzyka, rollback i ręczna weryfikacja

- Ryzyka: outage po rotacji, nieznany konsument, sekret w logu, niebezpieczne cofnięcie.
- Rollback: przełączenie na poprzednią wersję tylko w kontrolowanym oknie i tylko jeśli nie była ujawniona; w przeciwnym razie nowa rotacja/naprawa config.
- Ręcznie: provider audit, wszystkie deployment health, scheduler/worker last success, old credential rejected, secret scan wynik.

## Dokumentacja

Config key catalog, owner/environment map, rotation/recovery runbook, incident note bez wartości sekretu i aktualizacja risk register.

