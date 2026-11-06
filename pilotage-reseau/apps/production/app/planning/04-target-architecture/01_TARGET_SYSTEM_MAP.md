# Docelowa mapa systemu

## Kontekst

```mermaid
flowchart LR
    Backoffice["Panel centralny / backoffice"] --> Core["ERP Core — modularny monolit"]
    Shop["Panel sklepu / franczyzobiorcy"] --> Api["API centralne"]
    POS["POS"] --> Api
    Supplier["Portal dostawcy"] --> Api
    Mobile["Aplikacja mobilna — opcjonalna"] --> Api
    Api --> Core
    Core --> DB[("MySQL — jedna baza operacyjna")]
    Core --> Outbox[("Outbox / Inbox")]
    Worker["Worker i zadania okresowe"] --> DB
    Worker --> Outbox
    Outbox --> Ext["Płatności, fiskalizacja, Peppol, e-mail, storage, webhooki"]
    Core --> Read["Read models i agregaty"]
    Read --> DB
    Device["Urządzenie / lokalny bridge — gdy wymagany"] --> POS
```

## Granice zaufania

| Granica | Zasada |
|---|---|
| klient → API | uwierzytelnienie, organization membership, scope i permission sprawdzane przed komendą |
| moduł → moduł | wyłącznie publiczny interfejs aplikacyjny lub opublikowane zdarzenie; brak zapisu do cudzych tabel |
| ERP → system zewnętrzny | adapter + outbox, timeout, retry i idempotency; sekret z vault/env poza repo |
| system zewnętrzny → ERP | inbox, podpis/token, schema version, deduplikacja przed skutkiem domenowym |
| raport → dane | read-only do read modelu; drill-down do faktu, brak mutacji domeny |
| operator wsparcia → naprawa | kontrolowana komenda korekty z approval; brak bezpośredniego SQL jako normalnej operacji |

## Topologia minimalna

| Element | Liczba logiczna | Skalowanie |
|---|---:|---|
| artefakt ERP web/API | 1 | pionowo; wiele instancji dopiero przy potrzebie, bez sticky state poza sesją |
| worker | 1 typ procesu z różnymi kolejkami logicznymi | konkurencja przez claim/lease; zwiększać liczbę instancji po metrykach |
| MySQL | 1 primary | backup/replica read-only dopiero dla recovery/raportów przy potrzebie |
| storage dokumentów | 1 adapter logiczny | provider wymienny, metadata w ERP |
| broker wiadomości | 0 początkowo | dodać tylko gdy polling outbox nie spełnia SLA/throughput |
| baza analityczna | 0 początkowo | dodać po udowodnionym obciążeniu lub wielu źródłach |

## Przepływ żądania użytkownika

```mermaid
sequenceDiagram
    actor U as Użytkownik
    participant UI as Klient / panel
    participant API as API centralne
    participant IAM as IAM i policy
    participant MOD as Moduł domenowy
    participant DB as MySQL
    participant W as Worker
    U->>UI: Operacja biznesowa
    UI->>API: Command + idempotency key
    API->>IAM: Tożsamość, membership, scope, permission
    IAM-->>API: Dozwolone / odmowa
    API->>MOD: Komenda z CommandContext
    MOD->>DB: Transakcja: aggregate + audit + outbox
    DB-->>MOD: Commit
    MOD-->>API: Wynik i correlation ID
    API-->>UI: Status operacji
    W->>DB: Claim outbox po commit
    W->>W: Adapter zewnętrzny + retry
```

## Zakazane zależności docelowe

- POS, portal, aplikacja mobilna i Agent nie zapisują do tabel ERP;
- raport nie aktualizuje stanu operacyjnego;
- dokument nie jest jedynym źródłem zmiany zapasu;
- moduł nie używa repozytorium tabel innego modułu;
- worker nie odtwarza logiki biznesowej przez ad-hoc SQL;
- integracja nie uznaje timeoutu za pewny brak skutku — sprawdza idempotency/status;
- UI nie przechowuje własnych słowników statusów ani reguł cenowych.
