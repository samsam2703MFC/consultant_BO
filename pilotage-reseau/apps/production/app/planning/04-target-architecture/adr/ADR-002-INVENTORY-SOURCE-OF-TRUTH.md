# ADR-002 — Inventory jako źródło prawdy zapasu

**Status:** proponowany do akceptacji

## Kontekst

AS-IS ma `carton_stock` i niepotwierdzony Agent `material_inventory*`. Brak dowodu wspólnego uzgodnienia.

## Opcje

1. pozostawić oba źródła i synchronizować;
2. wybrać jedną istniejącą tabelę salda;
3. ustanowić moduł Inventory z ledgerem ruchów i projekcją salda.

## Decyzja

Moduł Inventory jest jedynym właścicielem ruchów, partii, rezerwacji i projekcji salda. Istniejące tabele służą migracji lub czasowej projekcji.

## Uzasadnienie

Jedno źródło usuwa konflikty i pozwala odtworzyć stan, korekty, koszt oraz traceability.

## Konsekwencje

- potrzebny kontrolowany opening balance i reconciliation;
- sprzedaż, produkcja i zakup nie zapisują salda bezpośrednio;
- legacy UI może czasowo czytać projekcję.

## Ponowne rozważenie

Tylko przy prawnie/organizacyjnie odrębnych zapasach wymagających odrębnej instancji i jawnego intercompany flow.
