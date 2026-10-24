# Model uprawnień

## Encje

| Encja | Znaczenie |
|---|---|
| User | konto uwierzytelniające człowieka albo jawne service/device account |
| Employee | osoba w procesie HR; nie jest automatycznie kontem |
| Membership | datowane członkostwo User w Organization, opcjonalnie powiązane z Employee |
| Role | nazwany zestaw uprawnień w kontekście biznesowym |
| Permission | atomowa operacja, np. `stock.movement.post`, `recipe.version.activate` |
| Grant | przypisanie roli/permission do membership w określonym scope |
| Scope | organization oraz opcjonalnie site/shop/warehouse/supplier; brak scope nie oznacza global |
| ApprovalPolicy | operacja, próg, required role, liczba zatwierdzeń i SoD |
| Session/Credential | bezpieczna sesja/token/device credential z expiry/revoke |

## Ocena dostępu

```text
authenticated user
AND active membership at business time
AND resource belongs to allowed organization
AND requested scope is within grant scope
AND permission is granted
AND record state allows operation
AND approval policy is satisfied when required
```

Każdy brak daje odmowę. Frontend może ukryć akcję dla UX, ale backend zawsze wykonuje pełną kontrolę.

## Zakresy

| Scope | Przykład użycia | Reguła |
|---|---|---|
| organization | konfiguracja, finanse centralne | grant jawnie organizacyjny |
| site/plant | produkcja i koszty zakładu | dostęp tylko do przypisanych zakładów |
| shop | sprzedaż, czas i operacje sklepu | POS/user/device ograniczone do jednego lub listy sklepów |
| warehouse | ruchy, stocktake, lot release | permission + warehouse grant; transfer wymaga źródła i celu |
| supplier | konto portalu dostawcy | wyłącznie własne PO/katalog/dokumenty, bez danych innych dostawców |
| self | własny czas/zadania | osoba może submit, ale nie approve własnej operacji, jeśli SoD wymaga rozdziału |

## Minimalne role

Role powinny być nieliczne i składane z permissions: administrator bezpieczeństwa, opiekun danych, technolog, handlowiec, kasjer, kierownik sprzedaży, magazynier, kierownik magazynu, planista, operator produkcji, brygadzista, kontrola jakości, kupiec, approver zakupów, logistyk, HR, kierownik pracownika, controller/finanse, audytor i operator integracji. Organizacja może nazwać role inaczej bez zmiany permissions.

## Działania wrażliwe

| Działanie | Ochrona minimalna |
|---|---|
| grant/revoke roli, reset credential | security admin, pełny audit, ewentualne ponowne uwierzytelnienie |
| aktywacja receptury/ceny/promocji | maker-reviewer lub jawny self-approval przy małej organizacji i audit |
| ręczny rabat/zwrot/refund | permission + próg + reason; approver powyżej progu |
| korekta stock/stocktake post | warehouse scope, reason, drugi approver powyżej tolerancji |
| reopen produkcji/okresu | odrębne permission, approval i incident/correction reference |
| supplier/PO approval | buyer nie zatwierdza własnego PO powyżej progu |
| manual integration retry/skip | operator integration, immutable payload, reason/outcome audit |
| eksport danych osobowych/finansowych | purpose/scope, watermark i audit pobrania |
| zmiana numeracji/status dictionary | admin + owner domeny, versioned config |

## Separacja obowiązków dla małego zespołu

Architektura nie zakłada dużej liczby pracowników. Dla operacji krytycznej można dopuścić tę samą osobę technicznie, jeśli właściciel biznesowy zatwierdzi wyjątek, próg jest niski, a audit i okresowy przegląd są obowiązkowe. Nie wolno jednak zaszyć wyjątku jako globalnego obejścia uprawnień.

## Konta techniczne i urządzenia

- osobne service/device identity, bez współdzielonego konta człowieka;
- minimalne scope i permissions;
- credential rotation/expiry/revoke;
- device ID w sale/payment/audit;
- brak interaktywnego logowania dla service account;
- webhook partner identyfikowany przez podpis/client credential i partner scope.

## Cache i propagacja

Permission cache może żyć krótko i jest kluczowany membership/version. Revoke/termination podnosi version i unieważnia sesje/cache. Klient offline otrzymuje czasowo ograniczony grant snapshot; serwer ponownie waliduje przy synchronizacji i stosuje zatwierdzoną politykę konfliktu.

## Audyt

Logować udane i odrzucone działania wrażliwe, zmianę grantów, zmianę scope, approval/rejection, impersonation/on-behalf-of oraz użycie trybu awaryjnego. Audit nie zapisuje haseł/tokenów.
