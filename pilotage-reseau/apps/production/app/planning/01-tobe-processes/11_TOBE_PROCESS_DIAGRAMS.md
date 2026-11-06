# Diagramy procesów TO-BE

Diagramy pokazują granice odpowiedzialności i kluczowe punkty kontroli. Szczegółowe statusy i wyjątki pozostają wiążące zgodnie z `04_PROCESS_STATE_MACHINES.md` oraz `07_EXCEPTION_AND_CORRECTION_MODEL.md`.

## 1. Krajobraz domen i wspólny rdzeń kontrolny

```mermaid
flowchart LR
    C["Klient / kanał sprzedaży"] --> S["Sprzedaż i zamówienia"]
    S --> P["Planowanie popytu"]
    P --> M["Produkcja"]
    P --> B["Zakupy"]
    B --> W["Magazyn"]
    W --> M
    M --> W
    W --> S
    E["Pracownicy i czas"] --> M
    R["Receptury i kartoteki"] --> S
    R --> M
    R --> B
    S --> K["Koszty i raportowanie"]
    M --> K
    B --> K
    E --> K
    A["Tożsamość, konfiguracja i audyt"] -. "kontrola" .-> S
    A -. "kontrola" .-> M
    A -. "kontrola" .-> W
    A -. "kontrola" .-> B
    I["Integracje: outbox / inbox"] <--> S
    I <--> B
    I <--> K
```

## 2. Order-to-Cash B2B

```mermaid
sequenceDiagram
    actor Klient
    participant Sprzedaż
    participant Magazyn
    participant Dokumenty
    participant Płatności
    participant Raporty
    Klient->>Sprzedaż: Zamówienie
    Sprzedaż->>Sprzedaż: Walidacja ceny, limitu i warunków
    Sprzedaż->>Magazyn: Rezerwacja zapasu
    alt Zapasu wystarcza
        Magazyn-->>Sprzedaż: Rezerwacja potwierdzona
    else Niedobór
        Magazyn-->>Sprzedaż: Niedobór / częściowa dostępność
        Sprzedaż->>Sprzedaż: Backorder, częściowa realizacja lub anulowanie
    end
    Sprzedaż->>Magazyn: Dyspozycja wydania
    Magazyn-->>Sprzedaż: Zatwierdzony ruch wydania
    Sprzedaż->>Dokumenty: Podstawa dokumentu sprzedaży
    Dokumenty-->>Klient: Faktura / rachunek
    Klient->>Płatności: Zapłata
    Płatności-->>Sprzedaż: Rozliczenie należności
    Sprzedaż->>Raporty: Zdarzenia sprzedaży i kosztu własnego
```

## 3. Plan-to-Produce i genealogia partii

```mermaid
flowchart TD
    D["Popyt: zamówienia + plan"] --> N["Bilans potrzeb i dostępności"]
    N --> Z["Zlecenie produkcyjne z wersją receptury"]
    Z --> R["Rezerwacja surowców"]
    R --> Q{"Komplet materiałów?"}
    Q -- "Nie" --> X["Niedobór: zakup, zmiana planu lub zatwierdzony zamiennik"]
    X --> R
    Q -- "Tak" --> I["Wydanie partii surowców"]
    I --> W["Wykonanie i rejestracja czasu / strat"]
    W --> O["Przyjęcie partii wyrobu"]
    O --> J{"Kontrola jakości wymagana?"}
    J -- "Tak" --> K["Kwarantanna i decyzja jakościowa"]
    J -- "Nie" --> G["Partia dostępna"]
    K -- "Zwolnienie" --> G
    K -- "Odrzucenie" --> U["Utylizacja, przerób lub korekta"]
    G --> C["Rozliczenie zużycia i kosztu zlecenia"]
```

## 4. Procure-to-Stock

```mermaid
stateDiagram-v2
    [*] --> Zapotrzebowanie
    Zapotrzebowanie --> Do_zatwierdzenia: wybór dostawcy i warunków
    Do_zatwierdzenia --> Zamówione: zatwierdzenie
    Do_zatwierdzenia --> Odrzucone: odrzucenie
    Zamówione --> Częściowo_przyjęte: dostawa częściowa
    Zamówione --> Przyjęte: dostawa kompletna
    Częściowo_przyjęte --> Przyjęte: kolejna dostawa
    Częściowo_przyjęte --> Zamknięte: rezygnacja z reszty
    Przyjęte --> Zamknięte: uzgodnienie rozbieżności
    Zamówione --> Anulowane: anulowanie nieprzyjętej części
    Odrzucone --> [*]
    Anulowane --> [*]
    Zamknięte --> [*]
```

## 5. Ruch magazynowy jako jedyne źródło zmiany salda

```mermaid
flowchart LR
    D["Dokument źródłowy"] --> V["Walidacja statusu, uprawnień i okresu"]
    V --> T{"Operacja poprawna?"}
    T -- "Nie" --> E["Odrzucenie bez zmiany salda"]
    T -- "Tak" --> M["Niezmienny ruch magazynowy"]
    M --> L["Aktualizacja / wyliczenie salda"]
    M --> H["Historia partii i kosztu"]
    M --> A["Zdarzenie audytowe"]
    M --> O["Outbox dla integracji"]
    C["Błąd po zatwierdzeniu"] --> K["Dokument korekty / ruch przeciwny"]
    K --> M
```

## 6. Uniwersalny model korekty

```mermaid
stateDiagram-v2
    [*] --> Zgłoszona
    Zgłoszona --> Oceniana: przypisanie właściciela
    Oceniana --> Odrzucona: brak podstaw
    Oceniana --> Zatwierdzona: uprawniona akceptacja
    Zatwierdzona --> Wykonywana: dokument kompensujący
    Wykonywana --> Zakończona: uzgodnione skutki
    Wykonywana --> Błąd: częściowe niepowodzenie
    Błąd --> Wykonywana: bezpieczne wznowienie
    Odrzucona --> [*]
    Zakończona --> [*]
```

Każda korekta wskazuje obiekt pierwotny, przyczynę, autora, zatwierdzającego, skutki ilościowe i finansowe oraz identyfikatory zdarzeń kompensujących.

## 7. Zamknięcie okresu i odtwarzalność raportu

```mermaid
flowchart TD
    S["Okres otwarty"] --> V["Walidacje kompletności"]
    V --> Q{"Nierozliczone wyjątki?"}
    Q -- "Tak" --> F["Lista błędów: brak kosztu, ruchu, dokumentu lub czasu"]
    F --> V
    Q -- "Nie" --> C["Zatwierdzenie zamknięcia"]
    C --> Z["Migawka parametrów i wersji kalkulacji"]
    Z --> R["Raporty uzgodnione ze źródłami"]
    R --> L["Okres zamknięty"]
    L --> E{"Korekta wstecz?"}
    E -- "Bieżący okres" --> K["Korekta kompensująca"]
    E -- "Ponowne otwarcie" --> A["Autoryzacja właściciela finansowego"]
    A --> S
```

## 8. Niezawodna integracja

```mermaid
sequenceDiagram
    participant Domena
    participant Outbox
    participant Worker
    participant SystemZewnętrzny
    participant Monitoring
    Domena->>Domena: Zapis transakcji biznesowej
    Domena->>Outbox: Zapis komunikatu w tej samej transakcji
    Worker->>Outbox: Pobranie komunikatu
    Worker->>SystemZewnętrzny: Wysłanie z kluczem idempotencji
    alt Sukces lub duplikat rozpoznany
        SystemZewnętrzny-->>Worker: Potwierdzenie
        Worker->>Outbox: Oznaczenie jako dostarczony
    else Błąd przejściowy
        SystemZewnętrzny-->>Worker: Błąd
        Worker->>Outbox: Retry z backoff
    else Błąd trwały
        Worker->>Outbox: Status wymagający interwencji
        Worker->>Monitoring: Alert z kontekstem
    end
```
