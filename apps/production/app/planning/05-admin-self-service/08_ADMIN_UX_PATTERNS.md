# Wzorce UX administracyjnego

## Architektura doświadczenia

Jeden **Admin Workbench** wykorzystuje sześć spójnych obszarów na wspólnych komponentach:

1. Rejestry — kartoteki, słowniki, wersje konfiguracji.
2. Operations Inbox — błędy, sprawy naprawcze, approvals i zadania.
3. Integration Monitor — komunikaty, próby, correlation i retry.
4. Data Quality & Reconciliation — braki, duplikaty, uzgodnienia i import batches.
5. Access Administration — konta, role, scope, sesje i access review.
6. Audit Explorer — read-only timeline i pakiet dowodowy.

To są widoki na wspólne mechanizmy listy, szczegółu, historii, akcji i joba; nie sześć odrębnych aplikacji.

## Lista i filtry

Każda lista ma filtry zapisane i udostępnialne, scope widoczny w nagłówku, ownera, risk/status, czas/SLA, źródło i correlation/business key. Sortowanie i paginacja są serwerowe. Filtr masowej operacji zostaje zamrożony jako manifest; system pokazuje dokładną liczbę rekordów, nie „wszystkie spełniające później”. Puste i niepełne dane są odróżnione od wartości zero.

## Podgląd rekordu

Standardowa karta zawiera:

- tożsamość, status, wersję i scope;
- najważniejsze pola oraz status jakości;
- relacje i procesy otwarte;
- oś czasu audit/business/integration;
- dostępne akcje wynikające z permission i preconditions;
- panel diagnostyczny bez stack trace dla użytkownika biznesowego.

Nieaktywna akcja wyjaśnia konkretny blocker i wymagane permission/warunek. Ukrycie akcji nie zastępuje autoryzacji backendu.

## Historia zmian

Diff pokazuje wartości before/after, aktora, reason, approver, policy/version i correlation. Dla wartości wersjonowanych jest timeline effective date; dla zdarzeń finansowych/magazynowych widoczne są oryginał i kompensacje. Sekrety i PII są redagowane zgodnie z permission.

## Akcje kontekstowe

Akcja jest zdefiniowana kontraktem: nazwa biznesowa, permission, preconditions, risk, fields, preview provider, approval policy, executor, verifier i compensation. Ten sam kontrakt zasila przycisk w szczególe, Operations Inbox i API; nie powstają ad hoc endpointy „fix”.

## Masowe operacje

Wzorzec: wybór/filtr → snapshot manifestu → walidacja → preview totals i błędów → approval → job → live progress → wynik per rekord → eksport wyniku → retry tylko failed. Batch ma limit, możliwość anulowania przed wykonaniem, partial-failure policy i nie zmienia znaczenia przy ponownym kliknięciu.

## Potwierdzenia i walidacja

- `LOW/MEDIUM`: inline summary i zwykłe potwierdzenie, jeśli mutacja;
- `HIGH`: reason, preview, impact i dedykowane permission;
- `CRITICAL`: maker-checker, step-up, token kontekstowy i wersja obiektu;
- walidacja syntaktyczna działa od razu, a domenowa po stronie serwera;
- konflikty prezentują bieżącą wersję i diff, bez cichego overwrite.

## Prezentacja błędów

Komunikat zawiera: co się nie udało, czy coś zostało wykonane, identyfikator sprawy/correlation, klasyfikację (validation/conflict/transient/permanent/security), bezpieczny next action i link do szczegółu. Nie pokazuje raw SQL, sekretu ani pełnego stack trace. Dla partial batch podaje counts i plik wynikowy.

## Retry i diagnostyka

Retry jest dostępny tylko dla błędu retryable i pokazuje poprzednie próby. Diagnostyka korzysta z allowlisty bezpiecznych checków: zdrowie adaptera, backlog, wiek najstarszej wiadomości, konfiguracja effective (z redakcją), schema/version, ostatni sukces i synthetic test w sandboxie. Wynik można dołączyć do sprawy i wyeksportować.

## Dostępność i bezpieczeństwo użytkowe

Status nie opiera się wyłącznie na kolorze; daty pokazują strefę; kwoty walutę, ilości jednostkę. Krytyczne akcje nie są domyślną akcją wiersza, a batch nie używa niejednoznacznego „zaznacz wszystko”. Dane kopiowane i eksportowane dziedziczą masking oraz oznaczenie poufności.

## Antywzorce

- osobny ekran/endpoint dla każdego incydentu;
- edytowalny JSON lub tabela bazy jako „elastyczny panel”;
- `retry all` bez preview i limitu;
- dropdown dowolnego statusu;
- delete zamiast deactivate/cancel/compensate;
- toast „sukces” przed weryfikacją asynchronicznego skutku;
- eksport do Excela jako jedyna ścieżka dokończenia procesu;
- permission sprawdzane tylko przez widoczność przycisku.

