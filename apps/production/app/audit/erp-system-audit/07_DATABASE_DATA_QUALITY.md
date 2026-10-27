# Jakość danych

Status: **BLOCKED** — nie odczytano rekordów. Poniższe są ryzykami i planem kontroli, nie stwierdzonymi wadami danych.

- suma pozycji zamówienia kontra nagłówek;
- proforma/faktura/WDT/WZ kontra ich pozycje;
- anulowane i reklamowane dokumenty w raportach;
- `carton_stock` kontra dokumenty i log wysyłki;
- `material_inventory` kontra `material_inventory_log`;
- unikalność event ID w webhook inbox/outbox;
- duplikaty zamówień integracyjnych i retry;
- koszt/cena/VAT/receptura historyczne na datę dokumentu;
- osierocone pozycje i relacje tenantowe;
- wartości ujemne, ceny zerowe, nieznane statusy i daty przyszłe.

Bez tych uzgodnień KPI finansowe są PARTIAL/UNRELIABLE.

