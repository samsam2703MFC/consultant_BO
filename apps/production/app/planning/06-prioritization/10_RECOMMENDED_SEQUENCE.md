# Rekomendowana sekwencja

Sekwencja jest oparta na bramach, nie datach kalendarzowych. Faza kończy się dowodem działania, nie procentem kodu.

## F0 — zatrzymanie aktywnego ryzyka i rozpoznanie

**Zakres:** ERP-001, ERP-002, decyzje BD-10/11/12/15, QW-01–04/10.

**Gate:** sekret zrotowany; wadliwa ścieżka logowania wyłączona/naprawiona; mapa runtime i danych ma ownera oraz dowód; raporty niewiarygodne oznaczone.

## F1 — bezpieczna zmiana

**Zakres:** ERP-003, ERP-004, pierwszy szkielet ERP-042.

**Gate:** czysta baza/upgrade/schema diff; jedna komenda testowa; izolowane fixtures; CI PASS; control documents wybrane.

## F2 — granica zaufania i dowodowość

**Zakres:** ERP-005, ERP-006.

**Gate:** deny-by-default, cross-scope/revoke PASS; critical command zapisuje aktora, scope, reason, before/after i correlation; korekta nie nadpisuje oryginału.

## F3 — niezawodność integracji i operacje

**Zakres:** ERP-007, potem read-only i single-message slice ERP-008.

**Gate:** duplicate/retry = jeden skutek; dead state i attempts widoczne; pojedynczy retry ma permission, reason, outcome; adapter zewnętrzny ma sandbox/mock.

## F4 — modele podstawowe i historia

**Zakres:** ERP-009, ERP-010, ERP-011, ERP-012; następnie ERP-032 dla jednej kartoteki.

**Gate:** canonical IDs/mapping, constraints, datowane lifecycle; użyty unit/price/product snapshot nie zmienia historii; staged import jednej encji jest uzgodniony.

## F5 — pierwszy pionowy łańcuch inventory–purchase

**Zakres:** ERP-019 pilot → ERP-020 zakres minimalny → ERP-022 receipt slice → ERP-021 stocktake/adjustment slice.

**Gate:** zatwierdzony opening balance; saldo = suma ruchów; receipt tworzy jeden ruch i snapshot ceny; stocktake tworzy adjustment; reconciliation zero/zaakceptowana różnica.

## F6 — sprzedaż, ceny i dokumenty

**Zakres:** ERP-015 → ERP-016 kanał pilotażowy → ERP-018 → ERP-017/025 według potrzeb operacyjnych.

**Gate:** sale, payment i document są odrębnymi, powiązanymi faktami; return/correction kompensuje skutki; numeracja i VAT PASS; raport kontrolny uzgodniony.

## F7 — produkcja i dane rzeczywiste

**Zakres:** ERP-013 → ERP-023 pilot → ERP-024 minimal waste/QC → ERP-026/027 w zakresie kosztu pracy.

**Gate:** zlecenie wskazuje immutable recipe version; consume/output są ruchami ledger; waste/QC/correction mają aktora i powód; approved time wiąże się ze zleceniem.

## F8 — koszt, okres i self-service naprawczy

**Zakres:** ERP-028 source po source → ERP-029 → ERP-014 → ERP-030; rozszerzanie ERP-033 tylko o gotowe komendy domenowe.

**Gate:** koszt odtwarzalny do źródła; close blockers i report hash; reopen/adjustment maker-checker; Repair Workbench nie ma arbitralnego SQL/status edit.

## F9 — dane, reporting i controlling

**Zakres:** ERP-031 per domena → ERP-037 → ERP-038, plus ERP-035/036 po spełnieniu warunków.

**Gate:** KPI ma ownera, definicję, canonical fact, correction, snapshot, DQ, freshness i reconciliation; drill-down kończy się dokumentem/zdarzeniem; zamknięty raport jest stabilny.

## F10 — planowanie i odporność

**Zakres:** ERP-034 manual/versioned plan; ERP-039 według realnego SLA; ERP-040 retire per capability.

**Gate:** plan-vs-actual działa przez dwa cykle; recovery/restore i concurrency PASS; legacy ma zero traffic/read/write oraz retencję rozstrzygniętą.

## F11 — prognozowanie

**Zakres:** ERP-041 wyłącznie po gate danych.

**Gate:** forecast pokonuje zatwierdzony naive baseline w backteście, ma zakres błędu i działa najpierw advisory/shadow; decyzja człowieka pozostaje audytowalna.

## Zasada kolejności domen

Kolejność F5/F6 można zamienić tylko po potwierdzeniu, który błąd obecnie powoduje większe straty i który kanał ma lepsze dane kontrolne. Nie wolno jednak ominąć F0–F4 ani budować F9 przed faktami odpowiedniej domeny. ERP-042 rozwija się w każdej fazie F3–F10.

