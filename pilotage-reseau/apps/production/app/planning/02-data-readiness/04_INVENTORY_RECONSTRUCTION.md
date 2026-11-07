# Odtwarzanie stanu magazynowego

## Werdykt

Nie potwierdzono możliwości odtworzenia stanu materiałów ani wyrobów na dowolny dzień. Kod wskazuje dwa różne mechanizmy:

1. `carton_stock` — ewidencja bieżącego statusu kartonu/wyrobu używana przez App;
2. `material_inventory` + `material_inventory_log` — saldo i log ruchów w niepotwierdzonym runtime Agent.

Nie wykazano, że te modele obejmują wszystkie operacje, używają tej samej jednostki ani są uzgadniane.

## Ocena wymaganych cech

| Pytanie | Ocena | Uzasadnienie |
|---|---|---|
| czy stan jest obliczany z ruchów | UNCLEAR | Agent ma log, ale aktywność i kompletność nie są potwierdzone |
| czy stan jest przechowywany bez pełnej historii | YES/PARTIAL | `carton_stock` jest odczytywany przez bieżący status kartonu |
| czy występują oba mechanizmy | YES w kodzie | brak dowodu, że oba działają produkcyjnie |
| stan na dowolny dzień | UNRELIABLE | brak jednej kompletnej osi czasu ruchów i salda otwarcia |
| źródło każdego ruchu | UNVERIFIED | powody ruchów istnieją w Agent, ale dokument źródłowy nie jest potwierdzony dla każdego wpisu |
| dokument każdego ruchu | UNVERIFIED | dokumenty magazynowo-wysyłkowe istnieją, brak obowiązkowej relacji |
| autor | UNVERIFIED | część operacji ma pracownika/użytkownika, brak globalnego wymogu |
| lokalizacja | PARTIAL | sklep występuje dla materiałów; pełna lokalizacja/strefa niepotwierdzona |
| niezmienność ruchu | UNVERIFIED | brak potwierdzonych constraintów i zakazu update/delete |
| korekta jako osobny ruch | UNVERIFIED | nie potwierdzono systemowej kompensacji |
| partia i ważność | MISSING_DATA | brak potwierdzonego modelu dla głównych zapasów |
| wartość ruchu | MISSING/UNCLEAR | ceny występują, ale koszt historyczny i metoda wyceny nie są związane z każdym ruchem |

## Równanie uzgodnienia

Docelowa kontrola dla produktu, magazynu, lokalizacji, partii i jednostki bazowej:

```text
stan początkowy
+ przyjęcia zakupowe
+ przesunięcia przychodzące
+ przyjęcia z produkcji
- wydania sprzedażowe
- przesunięcia wychodzące
- zużycie produkcyjne
- straty i odpady
+/- korekty inwentaryzacyjne i kompensacje
= stan końcowy
```

Obecnie nie można wykonać tej kontroli przekrojowo, ponieważ brak potwierdzonego wspólnego ledgera i pełnych zdarzeń produkcji/strat.

## Minimalne dane ruchu konieczne do rekonstrukcji

| Grupa | Pola wymagane |
|---|---|
| identyfikacja | `movement_id`, `movement_type`, `source_document_type`, `source_document_id`, `source_line_id` |
| zakres zapasu | `organization_id`, `warehouse_id`, `location_id`, `product_id`, `lot_id` |
| ilość | ilość ze znakiem, jednostka źródłowa, przelicznik, ilość w jednostce bazowej |
| czas | business timestamp, posted timestamp, accounting period, timezone |
| odpowiedzialność | actor user, employee, approver, device/source system |
| wartość | unit cost, total value, currency, valuation method, cost snapshot ID |
| niezawodność | idempotency key, correlation ID, reversal movement ID |
| audyt | created_at; brak update/delete po zaksięgowaniu; powód korekty |

## Test rekonstrukcji

Po udostępnieniu kopii bazy należy:

1. wybrać zamknięty dzień i ograniczony magazyn;
2. ustalić potwierdzone saldo otwarcia;
3. sklasyfikować każdy ruch z dnia;
4. policzyć saldo z ledgera;
5. porównać z tabelą bieżącego salda i fizycznym/inwentaryzacyjnym zamknięciem;
6. wyjaśnić każdą różnicę dokumentem i aktorem;
7. powtórzyć dla materiału, wyrobu, przesunięcia, produkcji, straty i korekty.

Do czasu zaliczenia testu KPI stanu historycznego, rotacji, wartości, strat i różnic inwentaryzacyjnych pozostają `UNRELIABLE` albo `MISSING_DATA`.
