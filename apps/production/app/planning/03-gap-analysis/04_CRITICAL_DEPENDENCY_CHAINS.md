# Krytyczne łańcuchy zależności

## CH-01 — Tożsamość i odpowiedzialność

```text
GAP-001/002 niebezpieczne sekrety i logowanie
→ GAP-003 brak wspólnej autoryzacji i tenant context
→ GAP-042 niewiarygodne powiązanie user–employee
→ GAP-053 brak wiarygodnego aktora w audycie
→ niewiarygodne korekty, zatwierdzenia i KPI pracownicze
```

- **Element źródłowy:** bezpieczeństwo uwierzytelnienia i jedna tożsamość.
- **Funkcje zależne:** wszystkie operacje krytyczne, HR, naprawy, okres close/reopen.
- **Raporty zależne:** sprzedaż/operacje/korekty per pracownik, audyt dostępu.
- **Koszt odłożenia:** każda nowa funkcja powiela niebezpieczny mechanizm i tworzy dane bez wiarygodnego aktora.
- **Kolejność logiczna:** sekrety → auth → tenant/RBAC → user–employee → audit.

## CH-02 — Receptura do rentowności

```text
GAP-011 brak wersji receptury
→ GAP-013 brak odtwarzalnej kalkulacji
→ GAP-048 brak snapshotu kosztu
→ GAP-045/047 niewiarygodny koszt produktu i partii
→ niewiarygodna marża i rentowność
```

- **Element źródłowy:** immutable recipe version.
- **Funkcje zależne:** planowanie, zlecenie, zużycie, koszt standardowy/rzeczywisty.
- **Raporty zależne:** koszt produktu/partii, odchylenie recepturowe, marża i rentowność.
- **Koszt odłożenia:** kolejne okresy nie będą reprodukowalne; historyczny backfill może być niemożliwy.
- **Kolejność logiczna:** wersja → aktywacja/snapshot → kalkulacja z lineage → koszt entry/snapshot → raport.

## CH-03 — Fakt sprzedaży do raportów

```text
GAP-016 brak kanonicznego faktu sprzedaży
→ GAP-019/020 brak uzgodnionej płatności i korekty
→ GAP-048 brak snapshotu ceny/VAT/wymiarów
→ raport sprzedaży bez jednego źródła (GAP-016/048/055)
→ niewiarygodny przychód, koszyk i dynamika
```


- **Element źródłowy:** zdefiniowany moment sprzedaży i ziarno pozycji.
- **Funkcje zależne:** POS, B2B, faktura, płatność, stock, zwroty.
- **Raporty zależne:** wszystkie 13 KPI sprzedaży oraz marża.
- **Koszt odłożenia:** rozwój dashboardów utrwala sprzeczne definicje i wymaga późniejszego przepisywania.
- **Kolejność logiczna:** BD-01 → fakt → snapshot → correction/payment links → reconciliation → agregaty.

## CH-04 — Ledger magazynowy do produkcji i zakupów

```text
GAP-032 brak jednego stock ledgeru
→ GAP-033/034/035 brak wiarygodnych rezerwacji, transferów i inwentaryzacji
→ GAP-028/037 niespójne zużycie produkcji i przyjęcie zakupu
→ GAP-036 brak stanu historycznego i wyceny
→ niewiarygodny koszt, rotacja i dostępność
```

- **Element źródłowy:** niezmienny ruch z dokumentem, aktorem i jednostką bazową.
- **Funkcje zależne:** sprzedaż, zakup, produkcja, transfer, stocktake, loss.
- **Raporty zależne:** wszystkie KPI magazynu, koszt materiałowy/partii, dni pokrycia.
- **Koszt odłożenia:** narasta luka historyczna i trudność uzgodnienia istniejących sald.
- **Kolejność logiczna:** BD-02/03/06 → ledger contract → saldo kontrolne → operacje ruchowe → lot/value/as-of.

## CH-05 — Zlecenie produkcyjne do wydajności

```text
GAP-027 brak production order
→ GAP-028 brak rzeczywistego consume/output
→ GAP-029/030 brak QC, partii i strat
→ GAP-044 brak czasu na zleceniu
→ brak plan-vs-actual, wydajności, odpadu i kosztu partii
```

- **Element źródłowy:** production order z wersją receptury.
- **Funkcje zależne:** magazyn, QC, czas pracy, koszty, korekty.
- **Raporty zależne:** wszystkie KPI produkcji i część KPI kosztowych.
- **Koszt odłożenia:** danych actual nie da się później odtworzyć z list/PDF.
- **Kolejność logiczna:** order → consume/output → QC/lot/loss → labor → close/cost.

## CH-06 — Zakupy do kosztu i zapasu

```text
GAP-037 niepełny requisition–PO–receipt
→ GAP-038 brak kontrolowanej rozbieżności
→ GAP-040 brak ceny zakupu na przyjęciu
→ GAP-032/045 niepewny zapas i koszt materiału
→ niewiarygodna wartość zapasu i marża
```

- **Element źródłowy:** zatwierdzone PO i receipt jako oddzielne fakty.
- **Funkcje zależne:** inventory, supplier score, cost, complaints.
- **Raporty zależne:** koszt materiałów, wartość zapasu, terminowość/kompletność dostawcy.
- **Koszt odłożenia:** cena i ilość dostawy pozostają nieuzgadnialne.
- **Kolejność logiczna:** supplier lifecycle → requisition/PO → partial receipt/discrepancy → price snapshot → cost/score.

## CH-07 — Czas pracy do kosztu pracy

```text
GAP-042 dwa modele osoby/konta
→ GAP-043 brak actual time
→ GAP-044 brak czasu na zleceniu
→ GAP-045/047 brak kosztu pracy produktu/partii
→ niewiarygodne KPI roboczogodzin i rentowności
```

- **Element źródłowy:** datowane powiązanie employee–assignment i approved actual time.
- **Funkcje zależne:** HR, produkcja, sprzedaż sklepowa, controlling.
- **Raporty zależne:** czas/koszt pracy, sprzedaż i produkcja na roboczogodzinę.
- **Koszt odłożenia:** grafiku nie można wiarygodnie przekształcić w actual po fakcie.
- **Kolejność logiczna:** identity/assignment → actual time → allocation to order → cost entry → KPI zespołowy.

## CH-08 — Korekta do zamknięcia okresu

```text
GAP-020 brak wspólnego correction event
→ GAP-031/035 lokalne korekty produkcji i zapasu
→ GAP-045 brak adjustment kosztowego
→ GAP-046 brak kontrolowanego close/reopen
→ raport opublikowany może zmieniać się wstecz
```

- **Element źródłowy:** uniwersalny model korekty i okresu.
- **Funkcje zależne:** sprzedaż, dokumenty, stock, produkcja, czas i koszt.
- **Raporty zależne:** wszystkie KPI historyczne.
- **Koszt odłożenia:** brak stabilnego benchmarku i ręczne poprawki SQL.
- **Kolejność logiczna:** correction contract → domenowe kompensacje → period state → close/reopen → report version/hash.

## CH-09 — Schemat i testy do bezpiecznej modernizacji

```text
GAP-049 brak odtwarzalnego schematu i profilu danych
→ GAP-054 brak test harnessu
→ GAP-055 brak E2E i reconciliation
→ brak dowodu poprawności każdej funkcji
→ wysokie ryzyko migracji i regresji
```

- **Element źródłowy:** deterministyczny schema lifecycle i izolowana baza testowa.
- **Funkcje zależne:** wszystkie zmiany danych i procesów.
- **Raporty zależne:** wszystkie KPI, ponieważ nieznana jest jakość źródeł.
- **Koszt odłożenia:** każda zmiana zwiększa niepewność i koszt późniejszej migracji.
- **Kolejność logiczna:** schema inventory → migration baseline → fixtures → unit/integration → E2E/reconciliation → recovery/performance.

## CH-10 — Integracje do kompletności procesów

```text
GAP-050 wiele kontraktów outbox/inbox
→ GAP-051 brak self-service retry
→ GAP-052 brak sandboxu/health adapterów
→ GAP-053 brak trace i alertu
→ ciche braki lub duplikaty dokumentów, zamówień i płatności
```

- **Element źródłowy:** idempotentny kontrakt wiadomości.
- **Funkcje zależne:** POS, zamówienia, dokumenty, Peppol, e-mail, dostawcy.
- **Raporty zależne:** kompletność sprzedaży/dokumentów i liczba błędów.
- **Koszt odłożenia:** ręczne naprawy i nierozpoznane duplikaty rosną wraz z wolumenem.
- **Kolejność logiczna:** event/key/version → retry/dead state → ops UI → adapter sandbox → trace/alert.
