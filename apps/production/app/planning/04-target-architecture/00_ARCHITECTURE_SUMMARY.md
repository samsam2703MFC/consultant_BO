# Podsumowanie architektury docelowej

## Decyzja przewodnia

Docelowym rdzeniem jest **modularny monolit ERP** wdrażany jako jeden produkt z jedną relacyjną bazą operacyjną. Moduły mają własne dane i interfejsy aplikacyjne, ale nie są osobnymi mikroserwisami. Panel centralny, POS/sklep, portal dostawcy i ewentualna aplikacja mobilna są klientami tych samych reguł domenowych — nie implementują własnych źródeł prawdy.

## Dlaczego ten wariant

- minimalizuje liczbę deploymentów, kontraktów sieciowych i miejsc awarii;
- pozwala jednej osobie rozwijać system bez kosztu platformy mikroserwisowej;
- umożliwia transakcje ACID dla sprzedaży, zapasu, produkcji i kosztu;
- pozwala stopniowo przejmować istniejące funkcje App i zweryfikowane fragmenty Agent;
- pozostawia możliwość późniejszego wydzielenia modułu tylko na podstawie realnej skali lub niezależnego cyklu życia.

## Docelowe komponenty

1. **Central ERP Core** — moduły domenowe, API, server-side panel administracyjny/backoffice i jedna baza MySQL.
2. **POS / panel sklepu** — cienki klient API; lokalna kolejka tylko po potwierdzeniu wymogu offline.
3. **Portal dostawcy** — ograniczony klient API dla katalogu, potwierdzeń i dokumentów; nie zapisuje bezpośrednio do bazy ERP.
4. **Aplikacja mobilna** — opcjonalny klient dla zatwierdzonych przypadków; brak własnej logiki domenowej i źródła prawdy.
5. **Workers/CLI** — ten sam kod i model domenowy co ERP Core; outbox, importy, dokumenty, uzgodnienia i zadania okresowe.
6. **Adaptery zewnętrzne** — płatności, fiskalizacja, e-mail, S3/R2, Peppol, webhooki i urządzenia.
7. **Read models raportowe** — widoki i odbudowywalne agregaty w tej samej bazie; oddzielna baza analityczna dopiero po potwierdzonym wzroście.

## Niezmienne zasady

- jedna funkcja biznesowa ma jedno źródło prawdy;
- aplikacje zewnętrzne nie zapisują bezpośrednio do tabel domenowych;
- zatwierdzone fakty nie są edytowane ani usuwane — korekta kompensuje;
- stan magazynowy jest projekcją niezmiennych ruchów;
- produkcja wskazuje niezmienną wersję receptury;
- raporty korzystają z faktów i snapshotów, nie z bieżących kartotek do interpretacji historii;
- zapis domenowy i outbox odbywają się w tej samej transakcji;
- odbiorcy są idempotentni;
- brak danych daje stan `INCOMPLETE` lub błąd, nie wartość zero;
- każde działanie krytyczne ma organizację, aktora, uprawnienie, korelację i audyt.

## Model wdrożenia

Wariant początkowy może składać się z procesu web/API, procesu worker i jednego MySQL oraz istniejącego storage dokumentów. Panel server-rendered może pozostać częścią tego samego artefaktu. Nie ma potrzeby wprowadzania Kubernetes, service mesh, osobnego brokera, event sourcingu ani hurtowni danych.

## Strategia zmiany

Architektura zakłada wzorzec strangler wewnątrz istniejącego systemu: nowe moduły przejmują operacje po jednej zdolności, stare mechanizmy pozostają chwilowo w trybie read-only/adaptera, dane są backfillowane z oznaczeniem jakości, a przełączenie odczytu następuje po uzgodnieniu. Dual write jest wyjątkiem ograniczonym czasowo i musi mieć automatyczne reconciliation.

## Najważniejsze decyzje zależne od biznesu

Przed zamrożeniem części modeli wymagane są: moment sprzedaży, polityka ujemnego stanu, zakres partii/QC, model produkcji, metoda wyceny, polityka okresu, struktura organizacyjna, fiskalizacja/kraje, offline POS i zakres czasu pracy. Architektura nie zastępuje tych decyzji przypadkowymi założeniami.

## Efekt docelowy

System ma obsługiwać procesy bez ręcznego SQL i arkuszy, pozwalać bezpiecznie ponowić integrację, odtworzyć stan i raport na datę, prześledzić partię oraz wyjaśnić kto i dlaczego wykonał operację. Zaawansowana automatyzacja pozostaje opcjonalna.
