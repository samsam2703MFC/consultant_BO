# Audyt magazynu

## Obecny model

App wykorzystuje `carton_stock` dla wyrobów oraz dokumenty WDT/WZ i logi wysyłkowe. Agent deklaruje `material_inventory`, `material_inventory_log`, `material_movement_reason` i inwentaryzacje. Kod nie dowodzi, że jedna z warstw jest źródłem prawdy ani że są uzgadniane.

## Oceny

- kartoteki: F3;
- aktualne stany wyrobów: F2;
- ruchy materiałowe/inwentaryzacja Agent: F1 niepotwierdzone;
- przyjęcia/wydania/przesunięcia: F1 fragmentaryczne;
- rezerwacje/blokady/partie/ważność/FIFO/FEFO/wycena: F0;
- alerty min/max: ślady `stock_minimum`, brak dojrzałego alertingu;
- wyjaśnialność zmiany stanu: UNRELIABLE.

Rekomendacja P1/L: niezmienna księga ruchów z dokumentem źródłowym, magazynem i lokalizacją, partią, ilością w jednostce bazowej, wartością, aktorem, timestampem i kluczem idempotencji; stan jako projekcja uzgadniana automatycznie.

