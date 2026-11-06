# Synteza stanu obecnego

## Werdykt

Istniejący audyt jest **umiarkowanie wiarygodny (MEDIUM, 65/100)** jako statyczna mapa kodu, ale nie jako potwierdzenie działania systemu. Dobrze rozróżnia kod App od niepotwierdzonego drzewa Agent, jawnie dokumentuje brak bazy i testów oraz obejmuje główne domeny. Nie daje jednak dowodów z żywej bazy, nie uruchamia aplikacji, miejscami używa poziomów F4 sprzecznych z własną definicją i przeoczył krytyczne elementy bezpieczeństwa w aktywnym kodzie.

Autorytatywny baseline tej syntezy zawiera **50 znormalizowanych funkcjonalności**. Ponieważ nie uruchomiono żadnego procesu produktu, żadnej funkcji nie uznano za potwierdzone F4/F5. Rozkład po normalizacji: **F0=5, F1=12, F2=15, F3=18, F4=0, F5=0**. F3 oznacza tu „statycznie spójna główna ścieżka”, a nie potwierdzone działanie produkcyjne.

## Potwierdzone ryzyka

- **4 P0**: sekret w śledzonym kodzie, wymuszone `admin/admin` w aktywnym logowaniu, porównanie hasła wprost oraz brak ochrony aktywnych tras administracyjnych (w tym CRUD użytkowników).
- **8 P1**: debug i ujawnianie błędów, brak wykrytego CSRF, brak odtwarzalnych migracji, brak test harness/runtime verification, niejasny status Agent, niedomknięta produkcja, niejednoznaczne źródło prawdy magazynu oraz nieudowodniona historyczna wiarygodność rentowności.

## Stan obszarów

| Obszar | Baseline | Pewność |
|---|---|---|
| Kartoteki i receptury App | szeroki CRUD, kalkulacje i eksporty; brak testu działania | MEDIUM |
| Zamówienia/dokumenty/wysyłka App | główne ścieżki obecne statycznie; korekty/statusy nieudowodnione | MEDIUM |
| Produkcja | plan/lista/zapotrzebowanie obecne; wykonanie zlecenia i QC brak w App | MEDIUM |
| Magazyn | `carton_stock` w App oraz inventory/movement w Agent; źródło prawdy nierozstrzygnięte | MEDIUM |
| POS/HR/zakupy/odpady Agent | istotny backend istnieje, ale brak punktu wejścia w workspace | LOW |
| Koszty i analityka | rozbudowane zapytania i UI; dane oraz uzgodnienie finansowe niezweryfikowane | MEDIUM |
| Bezpieczeństwo App | krytycznie niewystarczające w kodzie | HIGH |
| Testy i baza | brak dowodów dynamicznych i danych | HIGH |

## Liczniki końcowe

- znormalizowane funkcjonalności: **50**;
- sprzeczności i istotne korekty raportu: **12**;
- niepotwierdzone twierdzenia/założenia: **12**;
- potwierdzone ryzyka: **4 P0, 8 P1**;
- wiarygodność audytu: **MEDIUM (65/100)**.

Kolejne analizy TO-BE powinny używać wyłącznie faktów z `07_INPUT_FOR_TOBE_ANALYSIS.md`, a nie bezpośrednio ocen z poprzedniej macierzy.

