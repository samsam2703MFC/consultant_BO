# ADR-001 — Modularny monolit jako rdzeń ERP

**Status:** proponowany do akceptacji

## Kontekst

System rozwija jedna osoba, ma wiele fragmentów aplikacji i wymaga silnej spójności sprzedaży, zapasu, produkcji i kosztu. Mikroserwisy zwiększyłyby liczbę deploymentów, kontraktów i awarii.

## Opcje

1. utrzymać niezależne aplikacje współdzielące bazę;
2. modularny monolit z klientami/API;
3. mikroserwisy domenowe;
4. pełne przepisanie na nowy system.

## Decyzja

Wybrać modularny monolit z jedną bazą operacyjną, modułami posiadającymi tabele i publiczne interfejsy oraz osobnymi klientami UI/POS/portal.

## Uzasadnienie

Zapewnia transakcje ACID, najniższy koszt operacyjny i etapowe wykorzystanie istniejącego kodu bez utraty granic domenowych.

## Konsekwencje

- wymagane są reguły zależności i ownership tabel;
- jeden deployment ma większy blast radius, więc potrzebuje testów/recovery;
- moduł można wydzielić później tylko po potwierdzonej potrzebie.

## Ponowne rozważenie

Gdy moduł wymaga niezależnej skali/SLA/security boundary, ma niezależny zespół lub mierzalnie destabilizuje resztę systemu.
