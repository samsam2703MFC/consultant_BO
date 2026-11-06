# Luki modelu danych

| ID | Obszar | AS-IS | Wymagany model TO-BE | Skutek i zależności | Gap/MDR |
|---|---|---|---|---|---|
| DM-01 | organizacja/tenant | rozproszone shop/company/franchise fields | jawna hierarchia i obowiązkowy scope każdego rekordu | izolacja, numeracje, raport sklep | GAP-004; MDR-18 |
| DM-02 | user–employee | oddzielne modele bez wspólnej osi czasu | datowane powiązanie konta, osoby, assignmentu i roli | uprawnienia, audit, KPI pracy | GAP-003/042; MDR-18 |
| DM-03 | produkt historyczny | bieżąca kartoteka | snapshot na fakcie + temporalne kategorie/jednostki | stabilna sprzedaż/koszt | GAP-007/048; MDR-02 |
| DM-04 | cena/VAT | cenniki i częściowy changelog | valid_from/to, approval i snapshot pozycji | netto/brutto/marża | GAP-014/048; MDR-03 |
| DM-05 | waluta | pojedyncze ustawienie kursu | kurs z datą, typem i źródłem | raporty walutowe | GAP-048; MDR-04 |
| DM-06 | receptura | edytowalny graf | immutable version, validity, approval, hash i skład | produkcja/koszt | GAP-011/012; MDR-09 |
| DM-07 | fakt sprzedaży | order/WDT/POS/invoice jako różne źródła | sales line event z typem sale/return/correction | wszystkie KPI sprzedaży | GAP-016/020; MDR-01 |
| DM-08 | płatność | pola/struktury Agent | payment attempt/event, idempotency, settlement/refund | cash reconciliation | GAP-019; MDR-01/16 |
| DM-09 | korekta | update, changelog lub lokalna tabela | correction event z original ID i deltami | wszystkie ledgery/okres | GAP-020/031/046; MDR-15 |
| DM-10 | magazyn | `carton_stock` + Agent saldo/log | immutable stock movement + projection balance | as-of, trace, koszt | GAP-032; MDR-05 |
| DM-11 | partia | brak wspólnego modelu | lot, expiry, QC status, parent lots | recall/FEFO/cost lot | GAP-029/036; MDR-06 |
| DM-12 | rezerwacja | brak potwierdzonego modelu | osobny aggregate z qty active/consumed/released | ATP i produkcja | GAP-033 |
| DM-13 | production order | lista/query, brak actual aggregate | order ze snapshotem recipe version i state machine | plan/actual/cost | GAP-026/027; MDR-07 |
| DM-14 | production event | brak consume/output/waste/QC | niezmienne zdarzenia wykonania i lot genealogy | wszystkie KPI produkcji | GAP-028–030; MDR-08 |
| DM-15 | czas pracy | grafik/miesięczny koszt | approved actual time i correction | koszt pracy/roboczogodziny | GAP-043/044; MDR-12/13 |
| DM-16 | zakup/dostawa | dwa modele, częściowe logi | requisition, PO revision, receipt, discrepancy i claim | stock/cost/supplier | GAP-037/038/040 |
| DM-17 | koszt | miesięczne konfiguracje i bieżące kalkulacje | immutable cost entry + method/version/adjustment | rentowność/close | GAP-045/047; MDR-10/11 |
| DM-18 | okres | brak potwierdzonego lifecycle | accounting period, close/reopen, report hash | stabilne raporty | GAP-046; MDR-17 |
| DM-19 | integracja | wiele outboxów o różnych polach | wspólny message contract i unique idempotency key | kompletność/deduplikacja | GAP-050/051; MDR-16 |
| DM-20 | audit | lokalne logi/changelogi | globalny audit event z actor/correlation/before-after/reason | kontrola i diagnoza | GAP-053 |
| DM-21 | schemat | delty bez baseline/runnera | wersjonowany schema lifecycle, FK/unique/check/index | wiarygodność wszystkich relacji | GAP-049 |

## Reguły migracji danych

1. Nie scalać modeli na podstawie podobnej nazwy tabeli; najpierw ustalić semantykę, właściciela i konsumentów.
2. Dane Agent traktować jako kandydatów do migracji, nie automatycznie jako produkcyjne źródło.
3. Zachować techniczne ID źródłowe i mapę `legacy_source + legacy_id → canonical_id`.
4. Backfill oznaczać `reconstructed` z metodą i poziomem jakości.
5. Nie tworzyć fikcyjnych partii, czasu pracy, wersji receptur ani cen przez użycie wartości bieżącej bez flagi.
6. Bieżące saldo może stać się kontrolowanym bilansem otwarcia, jeśli brak historycznego ledgera.
7. Każda migracja ma raport liczności, duplikatów, orphanów, sum i rekordów odrzuconych.
