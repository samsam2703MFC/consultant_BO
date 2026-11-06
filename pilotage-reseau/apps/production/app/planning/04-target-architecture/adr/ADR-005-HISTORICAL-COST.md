# ADR-005 — Ledger kosztów i historyczne snapshoty

**Status:** proponowany; metoda wyceny wymaga BD-06

## Kontekst

AS-IS łączy bieżące ceny/receptury z miesięcznymi kosztami, przez co historyczna marża jest niewiarygodna.

## Opcje

1. zawsze przeliczać historię z bieżących danych;
2. zapisywać tylko końcowy koszt na produkcie;
3. immutable cost entries + wersjonowane calculation snapshots i adjustments;
4. zewnętrzny system controllingowy jako jedyne źródło.

## Decyzja

Costing jest właścicielem wpisów kosztowych i snapshotów kalkulacji z metodą, wersją, okresem, walutą i lineage. Korekta tworzy adjustment.

## Uzasadnienie

Pozwala zachować prosty model, odtwarzać raport i zmienić metodę bez przepisywania starych wyników.

## Konsekwencje

- BD-06 wybiera metodę, architektura przechowuje jej version;
- brak kosztu daje INCOMPLETE;
- istniejące `cost_month*` są source entries/estimates.

## Ponowne rozważenie

Gdy księgowość wymaga integracji z zewnętrznym ledgerem finansowym jako formalnym source of truth; ERP nadal zachowuje operational lineage.
