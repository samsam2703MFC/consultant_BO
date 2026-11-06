# Administracja użytkownikami i uprawnieniami

## Warunek wejścia P0

Obecny App nie jest bezpieczną bazą dla panelu administracyjnego: login nadpisuje dane wejściowe, hasło jest porównywane bez bezpiecznego hashowania, cały rekord trafia do sesji, a trasy CRUD użytkownika nie pokazują centralnej autoryzacji. Funkcji IAM nie wolno wystawić przed wymianą uwierzytelnienia, centralnym enforcementem i testami deny-by-default.

## Model docelowy

- `Identity/User` — konto, status, metoda logowania, bez domenowych danych pracownika.
- `Employee` — osoba biznesowa; połączenie z userem jest jawne i opcjonalne.
- `Membership` — organizacja i daty ważności.
- `Role` — nazwany pakiet permissions.
- `Grant` — role/permission + scope + validity + źródło decyzji.
- `ServicePrincipal/Device` — osobny typ podmiotu, krótkie credentials, właściciel i expiry.

Efektywne uprawnienie = permission ∩ scope organizacyjny ∩ ważność ∩ kontekst operacji. UI nie może być jedynym enforcementem; decyzja zapada w application service/policy.

## Funkcje administracyjne

| Funkcja | Kontrola | Audit | Cofnięcie |
|---|---|---|---|
| invite/create | weryfikacja tożsamości, bez hasła przekazywanego przez admina | requester, recipient, method, result | expire invite/disable account |
| block/reactivate | reason, zależne zadania i sesje | before/after, sessions revoked | nowa decyzja reactivate |
| assign/revoke role | preview efektywnego dostępu, SoD, scope | grant version, approver, validity | revoke/restore jako nowy grant |
| assign scope | drzewo organizacji, brak cross-tenant | old/new scope and affected permissions | datowane zakończenie |
| reset access | one-time token, MFA policy, revoke sessions | bez tokenu/sekretu | ponowny reset/revoke |
| session review/revoke | rozpoznanie device, privacy | session ID, actor, reason | użytkownik loguje się ponownie |
| service/device account | owner, minimal scope, expiry, rotation | fingerprint i użycie | revoke/rotate |
| access review | kampania, owner attestation, overdue escalation | decyzja per grant | ponowne nadanie w nowym workflow |

## Permissions dla panelu

Permissions są atomowe (`resource.action`), np. `integration.message.read`, `integration.message.retry`, `period.close`, `period.reopen`, `audit.read`, `audit.export`. `manage` dopuszczalne jest tylko dla wąskich kartotek; nie ma globalnego `admin.*`. Odczyt danych wrażliwych, eksport oraz wykonanie akcji to odrębne permissions.

## Separation of duties

- requester nie zatwierdza własnej operacji `CRITICAL`;
- IAM admin nie zatwierdza własnego podniesienia uprawnień;
- osoba publikująca cenę/podatek nie zatwierdza własnej wersji;
- controller przygotowujący korektę okresu nie jest jedynym approverem reopen;
- support diagnozuje integrację, lecz nie zmienia mapowania bez integration ownera;
- audytor jest read-only i nie może wykonywać retry/korekt.

## Lifecycle i kontrole

Konto ma stany invited/active/blocked/disabled i daty. Offboarding natychmiast blokuje logowanie, revokuje sesje/tokeny, kończy granty i zachowuje actor reference w historii. Kwartalne access review obejmuje privileged roles, konta bez właściciela, inactive users, scope conflicts, long-lived service credentials i nieskończone granty czasowe.

## Operacje wrażliwe

Step-up authentication wymagają: grant uprzywilejowany, reveal/rotate secret workflow, period reopen, stock/cost correction, konfiguracja podatku/numeracji oraz masowy eksport PII. Powiadomienia trafiają do właściciela zakresu i security; rate limit i alert obejmują nietypową liczbę zmian.

