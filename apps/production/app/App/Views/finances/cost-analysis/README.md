# Cost Analysis - Section View (MVP)

Ten moduł prezentuje rentowność **sekcji produkcyjnej** na podstawie sprzedaży WDT oraz alokacji kosztów po `workload_points`.

## Widok

- URL: `/finances/cost-analysis/section`
- Filtry: sekcja + miesiąc kosztowy
- KPI: przychód (PLN), koszt (PLN), marża (PLN i %)
- Tabela: lista produktów sprzedanych w okresie z kosztem/marżą
- Sekcja "Jak policzono": pulę kosztów działu, koszt 1 punktu, kurs EUR

## Dane wejściowe

- `document_wdt` + `document_wdt_product` (sprzedaż)
- `final_product` (ppc, workload_points, koszty surowca)
- `cost_month_employee`, `production_sections` (koszty działu)
- `cost_month_fixed_cost` (koszty wspólne)
- `erp_company_other_settings.exchange_rate_euro` (kurs EUR)

## Smoke test (CLI)

```bash
php Bin/cost_analysis_section_smoke.php <section_id> [month_id]
```

> Jeśli nie podasz `month_id`, zostanie użyty najnowszy miesiąc z `cost_month`.

