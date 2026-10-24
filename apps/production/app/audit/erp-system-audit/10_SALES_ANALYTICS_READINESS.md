# Gotowość analityki sprzedaży

## Źródła

- Panel App analizuje głównie `document_wdt`, `document_wdt_product`, produkty, kategorie i sekcje (`SalesAnalyticsRepository`, `SalesByDepartmentRepository`, `SectionFinanceDashboardRepository`).
- Agent ma model POS `transaction`/`transaction_product`, płatności, pracowników i raporty krajowe, ale jego uruchomienie nie jest potwierdzone.
- Zamówienia B2B są w `customer_order*`; nie wykazano jednolitej warstwy faktów sprzedażowych łączącej zamówienie, wysyłkę, transakcję, fakturę i korektę.

| KPI | Status | Powód |
|---|---|---|
| Przychód z WDT | PARTIAL | wyliczalny, lecz WDT nie musi oznaczać sprzedaży fiskalnej |
| Sprzedaż netto/brutto/VAT | UNRELIABLE | kod POS istnieje, aktywność i korekty niepotwierdzone |
| Liczba transakcji/średni koszyk | PARTIAL | możliwe tylko z Agent transaction |
| Sprzedaż produktu/kategorii/sekcji | PARTIAL | raporty WDT istnieją |
| Sprzedaż klienta | PARTIAL | zamówienia/WDT pozwalają agregować B2B |
| Sprzedaż sprzedawcy/godziny | PARTIAL | pola POS istnieją; brak potwierdzonego pipeline |
| Promocje/kanały | MISSING_DATA | brak spójnych wymiarów historycznych |
| Dynamika okres do okresu | PARTIAL | trend miesięczny istnieje |
| Marża produktu | UNRELIABLE | koszt historyczny na moment sprzedaży nieudowodniony |

Rekomendacja P1: kanoniczna tabela faktów pozycji sprzedaży z typem zdarzenia (sale/return/correction/cancel), tenantem, dokumentem źródłowym, snapshotem ceny/kosztu/VAT i idempotency key.

