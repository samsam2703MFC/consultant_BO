# Gotowość danych kosztowych

## Macierz kosztów

| Rodzaj kosztu | Przechowywany / wyliczany | Historyczny i okres | Przypisanie produkt / partia / sklep / klient | Wiarygodność |
|---|---|---|---|---|
| materiały | cena `raw_material`, changelog i koszt receptury; głównie wyliczany | changelog częściowy, brak snapshotu pełnej kalkulacji | produkt: PARTIAL; partia: brak; sklep/klient: brak | PARTIAL/UNRELIABLE historycznie |
| cena zakupu | pozycje zakupu i cenniki dostawcy | okresy występują w części modeli Agent; runtime niepotwierdzony | materiał/dostawca/sklep częściowo; partia/klient brak | PARTIAL |
| opakowania | pola i modele opakowań/cenników | brak potwierdzonego kosztu na datę zdarzenia | produkt/sklep częściowo; partia/klient brak | PARTIAL |
| praca | `cost_month_employee`, alokacja miesięczna | miesiąc i sekcja | sekcja: tak modelowo; produkt przez alokację; partia/klient brak | PARTIAL, nie koszt rzeczywisty zlecenia |
| transport/logistyka | `cost_month_logistics`, typy kosztu | miesiąc | sekcja/sklep częściowo; produkt przez alokację; partia/klient brak | PARTIAL |
| energia/media | `cost_media_month`, alokacje na sekcje | miesiąc | sekcja tak modelowo; produkt przez wolumen; partia/klient brak | PARTIAL |
| magazynowanie | brak kanonicznego źródła | brak | brak | MISSING_DATA |
| straty | brak zdarzenia ilościowo-kosztowego | brak | brak | MISSING_PROCESS |
| odpady | brak potwierdzonego rejestru | brak | brak | MISSING_PROCESS |
| promocje | brak snapshotu rabatu/kosztu kampanii w sprzedaży | brak | promocja/produkt/klient brak | MISSING_DATA |
| prowizje | brak potwierdzonego modelu | brak | brak | MISSING_DATA |
| koszty stałe | `cost_month_fixed_cost` i miesięczne struktury | miesiąc | sekcja/sklep możliwe przez alokację; produkt po kluczu alokacji | PARTIAL |
| koszty zmienne | materiały/wolumen/logistyka fragmentarycznie | mieszane okresy | produkt częściowo; partia/klient brak | PARTIAL/UNRELIABLE |

## Problemy metodologiczne

1. Nie ustalono obowiązującej metody wyceny zapasu i kosztu produkcji.
2. Koszt receptury korzysta z bieżących cen materiałów; nie potwierdzono snapshotu kalkulacji.
3. Miesięczne alokacje kosztów nie zastępują kosztu rzeczywistego partii.
4. WDT jest używany jako wolumen/przychód, choć nie musi odpowiadać uznanemu faktowi sprzedaży.
5. Brak historycznych kursów walut z datą i źródłem.
6. Brak spójnego ledgera korekt kosztowych oraz zamknięcia okresu.
7. W kodzie kalkulacji występują wartości zastępcze dla niepoprawnej wydajności/ceny, co może ukryć brak danych zamiast zatrzymać publikację wyniku.

## Ocena obiektów kosztowych

| Obiekt | Status | Powód |
|---|---|---|
| koszt receptury na dziś | PARTIAL | możliwy do wyliczenia, lecz braki mogą być traktowane jako zero i brak walidacji danych runtime |
| koszt produktu historyczny | UNRELIABLE | brak stabilnej wersji receptury, cen i metody |
| koszt partii | MISSING_PROCESS | brak partii, rzeczywistego zużycia i czasu |
| koszt sklepu | PARTIAL | miesięczne koszty i Agent sklep; brak kompletnego przychodu/kosztów przypisanych |
| koszt klienta | MISSING_DATA | brak pełnego kosztu dostawy, obsługi, zwrotów i rabatów per klient |
| koszt zamówienia | MISSING_DATA | brak spójnego przypisania materiałów, pracy, pakowania i transportu |

## Zasada publikacji

Kalkulacja kosztowa może otrzymać status opublikowany tylko z wersją metody, datą, walutą/kursem, listą źródeł, kompletnością, autorem/approverem i niezmiennym snapshotem. Brak ceny lub składnika nie może być interpretowany jako koszt zero.
