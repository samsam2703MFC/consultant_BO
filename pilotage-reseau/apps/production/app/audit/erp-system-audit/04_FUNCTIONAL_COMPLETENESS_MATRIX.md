# Macierz kompletności funkcjonalnej

Oceny 0–5 są ocenami statycznymi. `Sec` oznacza bezpieczeństwo, `Self` samodzielną obsługę, `Rep` raportowanie. Wszystkie testy dynamiczne są BLOCKED albo NOT_TESTED.

| ID | Funkcja | BE | UI | DB | Int | Test | Sec | Self | Rep | F | Status | Najważniejsza luka |
|---|---|--:|--:|--:|--:|--:|--:|--:|--:|--:|---|---|
| CFG-01 | Użytkownicy panelu | 2 | 3 | 2 | 0 | 0 | 0 | 3 | 0 | 2 | BLOCKED | jawne hasła |
| CFG-02 | Role/uprawnienia | 3 | 1 | 3 | 1 | 0 | 2 | 1 | 0 | 1 | NOT_TESTED | tylko nieaktywny Agent |
| CFG-03 | Języki/aliasy | 3 | 3 | 3 | 2 | 0 | 2 | 3 | 0 | 3 | BLOCKED | brak testów |
| MD-01 | Produkty | 4 | 4 | 3 | 2 | 0 | 1 | 4 | 2 | 3 | BLOCKED | auth/audit |
| MD-02 | Kategorie produktów | 3 | 2 | 3 | 1 | 0 | 1 | 3 | 2 | 3 | BLOCKED | regresja widoku |
| MD-03 | Materiały | 4 | 4 | 3 | 2 | 0 | 1 | 4 | 2 | 3 | BLOCKED | brak partii |
| MD-04 | Alergeny/żywienie | 3 | 3 | 3 | 1 | 0 | 1 | 3 | 1 | 3 | BLOCKED | brak E2E |
| MD-05 | Dostawcy | 3 | 3 | 3 | 2 | 0 | 1 | 3 | 1 | 3 | BLOCKED | cykl aktywności |
| REC-01 | Receptury | 4 | 4 | 3 | 2 | 0 | 1 | 4 | 3 | 3 | BLOCKED | wersjonowanie |
| REC-02 | Półprodukty/kompozycje | 4 | 4 | 3 | 2 | 0 | 1 | 4 | 3 | 3 | BLOCKED | transakcyjność |
| REC-03 | Kroki przygotowania | 3 | 3 | 2 | 1 | 0 | 1 | 3 | 1 | 2 | BLOCKED | migracja niepotwierdzona |
| REC-04 | Kompetencje receptur | 3 | 3 | 2 | 2 | 0 | 1 | 3 | 1 | 2 | BLOCKED | nowy moduł |
| REC-05 | Spłaszczanie/koszt | 3 | 2 | 2 | 2 | 0 | 1 | 2 | 3 | 2 | BLOCKED | masowe przebudowy |
| PRI-01 | Ceny materiałów | 3 | 3 | 3 | 2 | 0 | 1 | 3 | 3 | 3 | BLOCKED | historia nieuzgodniona |
| PRI-02 | Cenniki | 4 | 4 | 3 | 3 | 0 | 1 | 4 | 4 | 4 | BLOCKED | brak testów |
| ORD-01 | Klienci | 4 | 4 | 3 | 2 | 0 | 1 | 4 | 3 | 3 | BLOCKED | izolacja tenantów |
| ORD-02 | Sieci/onboarding | 3 | 2 | 3 | 3 | 0 | 1 | 2 | 1 | 2 | BLOCKED | test kontraktu |
| ORD-03 | Zamówienia | 4 | 4 | 3 | 3 | 0 | 1 | 4 | 3 | 3 | BLOCKED | statusy/korekty |
| ORD-04 | Changelog zamówień | 3 | 3 | 2 | 2 | 0 | 2 | 3 | 2 | 2 | BLOCKED | deployment schema |
| SAL-01 | POS transakcje | 3 | 0 | 3 | 1 | 0 | 1 | 0 | 3 | 1 | NOT_TESTED | brak entrypointu |
| SAL-02 | Promocje | 2 | 0 | 2 | 1 | 0 | 1 | 0 | 1 | 1 | NOT_TESTED | brak procesu |
| SAL-03 | Zwroty/korekty POS | 2 | 0 | 2 | 1 | 0 | 1 | 0 | 2 | 1 | NOT_TESTED | brak E2E |
| DOC-01 | Proforma | 4 | 3 | 3 | 3 | 0 | 1 | 3 | 2 | 3 | BLOCKED | obsługa outbox |
| DOC-02 | Faktury | 4 | 3 | 3 | 3 | 0 | 1 | 3 | 3 | 3 | BLOCKED | dwie implementacje |
| DOC-03 | Korekty faktur | 3 | 0 | 2 | 2 | 0 | 1 | 0 | 2 | 1 | NOT_TESTED | Agent nieaktywny |
| DOC-04 | WDT/WZ/CMR | 4 | 4 | 3 | 2 | 0 | 1 | 4 | 3 | 3 | BLOCKED | brak ledgeru |
| SHP-01 | Wysyłka | 4 | 4 | 3 | 3 | 0 | 1 | 4 | 2 | 3 | BLOCKED | rozproszone statusy |
| SHP-02 | Reklamacje | 4 | 4 | 3 | 3 | 0 | 1 | 4 | 2 | 3 | BLOCKED | kompensacje |
| PRD-01 | Lista produkcyjna | 4 | 3 | 2 | 2 | 0 | 1 | 3 | 2 | 3 | BLOCKED | brak zlecenia |
| PRD-02 | Zapotrzebowanie | 3 | 2 | 2 | 1 | 0 | 1 | 2 | 2 | 2 | BLOCKED | brak rezerwacji |
| PRD-03 | Wykonanie produkcji | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | NOT_APPLICABLE | brak procesu |
| PRD-04 | Odpady/QC/partie | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | NOT_APPLICABLE | brak procesu |
| INV-01 | Stan wyrobów | 3 | 3 | 2 | 2 | 0 | 1 | 3 | 2 | 2 | BLOCKED | rekordy zamiast ledgeru |
| INV-02 | Materiały/log ruchu | 3 | 0 | 3 | 1 | 0 | 1 | 0 | 2 | 1 | NOT_TESTED | Agent nieaktywny |
| INV-03 | PZ/WZ/przesunięcia | 2 | 1 | 2 | 1 | 0 | 1 | 1 | 1 | 1 | BLOCKED | niespójny workflow |
| INV-04 | Rezerwacje/partie/FEFO | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | NOT_APPLICABLE | brak |
| INV-05 | Inwentaryzacja | 3 | 0 | 3 | 1 | 0 | 1 | 0 | 2 | 1 | NOT_TESTED | Agent nieaktywny |
| PUR-01 | Dokument zakupu | 3 | 2 | 3 | 1 | 0 | 1 | 2 | 1 | 2 | BLOCKED | płytki workflow |
| PUR-02 | Requisition/PO/dostawa | 3 | 0 | 3 | 2 | 0 | 1 | 0 | 2 | 1 | NOT_TESTED | Agent nieaktywny |
| PUR-03 | Ocena dostawców | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | NOT_APPLICABLE | brak danych |
| HR-01 | Pracownicy/kompetencje | 3 | 2 | 3 | 1 | 0 | 1 | 2 | 1 | 2 | BLOCKED | podwójny model |
| HR-02 | Grafik/zadania | 3 | 0 | 3 | 1 | 0 | 1 | 0 | 2 | 1 | NOT_TESTED | Agent nieaktywny |
| HR-03 | Czas pracy | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | NOT_APPLICABLE | brak RCP |
| COST-01 | Kalkulator kosztów | 4 | 4 | 4 | 3 | 0 | 1 | 4 | 4 | 4 | BLOCKED | dane/testy |
| ANA-01 | Sprzedaż/trend | 3 | 3 | 2 | 2 | 0 | 1 | 3 | 3 | 2 | BLOCKED | źródło WDT |
| ANA-02 | Marża/sekcja | 4 | 3 | 3 | 3 | 0 | 1 | 3 | 4 | 2 | BLOCKED | historia kosztów |
| ANA-03 | Raporty POS PL/BE | 4 | 0 | 3 | 1 | 0 | 1 | 0 | 4 | 1 | NOT_TESTED | brak entrypointu |
| INT-01 | Inbox/outbox/retry | 3 | 0 | 3 | 3 | 0 | 2 | 1 | 1 | 2 | BLOCKED | brak konsoli |
| INT-02 | S3/e-mail/Peppol | 3 | 0 | 2 | 3 | 0 | 1 | 0 | 1 | 2 | NOT_TESTED | konfiguracja/sekrety |
| OBS-01 | Monitoring/audyt | 1 | 0 | 1 | 0 | 0 | 1 | 0 | 1 | 0 | NOT_APPLICABLE | brak platformy |

Rozkład katalogu: **F0=6, F1=11, F2=14, F3=17, F4=2, F5=0 (50 funkcji)**.

