# STB-002 — Bezpieczne logowanie i sesja App

## Metryka pakietu

| Pole | Wartość |
|---|---|
| typ / faza / rozmiar | Stabilization / F0 / M |
| cel biznesowy | zapewnić, że tylko poprawnie uwierzytelniony użytkownik otrzymuje sesję, bez utraty kontrolowanego dostępu |
| problem | kontroler nadpisuje credentials, hasło jest porównywane surowo, cały rekord trafia do sesji |
| rezultat | bezpieczny login/logout, hash migration, minimalna sesja, jednoznaczne błędy i testy negatywne |

## Zakres i granice

Zakres: usunięcie danych domyślnych, server-side input validation, `password_hash/password_verify`, strategia migracji legacy credentials, session ID regeneration, secure cookie flags/timeouts, minimalny principal, logout/revoke current session, neutralny error. Poza zakresem: role/scopes/MFA/SSO/admin reset — FND-004/SS-005.

## Repozytoria i kolejność

App jako jeden prompt implementacyjny. DSC-001 potwierdza aktywny entrypoint przed wdrożeniem. Kolejność: credential schema compatibility → verifier → controller → session → UI/error → tests → migration/cutover.

## Dane, migracje, endpointy i UI

- Tabele: istniejący `user`; docelowo `password_hash`, `credential_version`, opcjonalnie `password_changed_at`, bez utrwalania plaintext.
- Migracja: additive column; hash przy kontrolowanym reset/migracji lub verified login tylko jeśli security zaakceptuje; raport kont niezmigrowanych; usunięcie legacy odczytu osobno po zero-use.
- Endpointy: istniejące login/logout; brak publicznego reset w tym pakiecie.
- UI: istniejący formularz login, neutralny error, expired session i logout state; bez wyświetlania przyczyny identyfikującej konto.

## Uprawnienia i audyt

Login jest unauthenticated action z rate/abuse policy; logout wymaga sesji. Audit: login success/failure z redakcją, account/session reference, source/time/outcome; nigdy hasło/login input w raw payload. Session principal: user ID, organization context placeholder, auth/session IDs — bez pełnego rekordu/credential.

## Testy i dane testowe

Unit verifier/input/session policy; integration legacy/current hash migration, transaction/failure; E2E valid/invalid/unknown/disabled/logout/session fixation/expiry/replay; security cookies/CSRF baseline odpowiedni dla flow. Fixtures: active, disabled, legacy-unmigrated, new-hash users z syntetycznymi hasłami.

## Kryteria akceptacji

1. Request credentials nie są nadpisywane.
2. Plaintext compare i plaintext session data nie są używane.
3. Valid user otrzymuje nowy session ID; invalid/disabled nie otrzymuje principal.
4. Logout unieważnia sesję; cookie policy jest zgodna ze środowiskiem.
5. Migracja nie blokuje kont bez jawnego recovery path; legacy usage jest mierzone.
6. Wszystkie scenariusze security/E2E PASS.

## Definition of Done

Model/backend/UI/validation/error/audit/tests/migration/runbook complete. Correction `N/A`; recovery to bezpieczny reset/temporary maintenance path opisany, nie raw password.

## Ryzyka, rollback, ręczna weryfikacja

- Ryzyka: lockout wszystkich użytkowników, hash incompatibility, session regression, enumeration.
- Rollback: feature flag do poprzedniego **verifiera tylko jeśli nie przywraca known-vulnerable defaults/plaintext**; preferowany maintenance access i forward fix. Zachować nowe hashes.
- Ręcznie: konto active/disabled, dwa browser sessions, cookie attributes, logout, log redaction, liczba legacy credentials.

## Dokumentacja

Login/session contract, credential migration report, support recovery runbook, security limitations i wejście dla FND-004.

