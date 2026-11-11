# Roadmapa

## Faza A — bezpieczeństwo i integralność

Cel: usunąć P0. Zakres: sekrety, hash haseł, central auth/RBAC/tenant, CSRF, bezpieczne sesje, state machine krytycznych dokumentów, idempotencja, audit log, migracje i testy integralności. Kryterium: brak jawnych sekretów, testy IDOR/CSRF/auth PASS, odtwarzalny schemat. Koszt XL.

## Faza B — zamknięcie istniejących procesów

Cel: domknąć zamówienie→produkcję→magazyn→wysyłkę oraz zakup→dostawę. Zależność: A. Zakres: statusy, transakcje, anulowanie, korekty, dokumenty i kompensacje. Kryterium: E2E w testowej bazie bez SQL. Koszt XL.

## Faza C — administracyjny self-service

Cel: wyeliminować ręczne SQL/env/CLI. Zakres: role, konfiguracja, numeracja, słowniki, migracje operacyjne, outbox/DLQ, korekty i reprint. Kryterium: runbook bez dostępu DB dla standardowych incydentów. Koszt L.

## Faza D — wiarygodne dane analityczne

Cel: stabilna historia. Zakres: snapshoty ceny/kosztu/VAT/receptury, księga ruchów, kalendarz, waluty, jakość i uzgodnienia. Kryterium: raporty uzgadniają się do źródła w tolerancji finansowej. Koszt XL.

## Faza E — analityka operacyjna

Cel: sprzedaż, produkcja, magazyn, zakupy i HR. Zakres: semantyczne KPI, filtry tenant/date/channel, eksport i alerty. Zależność: D. Koszt L.

## Faza F — rentowność i controlling

Cel: koszt rzeczywisty i marża. Zakres: alokacje, koszt partii/zamówienia/klienta/sklepu, odchylenia i budżety. Zależność: produkcja i magazyn z B/D. Koszt XL.

## Faza G — prognozowanie i automatyzacja

Cel: prognozy i rekomendacje. Warunek: stabilne, kompletne dane z co najmniej kilku cykli. Zakres: popyt, produkcja, zakup, min/max i alerty z mierzeniem błędu prognozy. Koszt L/XL.

