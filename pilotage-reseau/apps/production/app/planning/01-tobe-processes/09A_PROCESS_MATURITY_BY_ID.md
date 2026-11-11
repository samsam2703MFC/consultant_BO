# Dojrzałość każdego procesu

Macierz jest rozwinięciem `09_MVP_VS_COMPLETE_VS_ADVANCED.md`. Poziomy opisują kompletność biznesową procesu, a nie kolejność wdrożenia.

| ID | MVP operacyjne | Wersja kompletna | Wersja zaawansowana |
|---|---|---|---|
| MD-001 | podstawowa kartoteka produktu, jednostka, VAT, aktywacja | warianty, opakowania, kontrolowane rewizje i raport braków | automatyczna kontrola jakości i deduplikacja danych |
| MD-002 | surowiec, jednostka, trwałość i status | tolerancje, alergeny, parametry jakości i historia | automatyczne wzbogacanie/klasyfikacja danych |
| MD-003 | półprodukt z własną recepturą | wielopoziomowość, wykrywanie cykli i historia | symulacja alternatywnych struktur produktu |
| MD-004 | dane prawne dostawcy i dopuszczenie | warunki, dokumenty, zawieszenie i historia oceny | zewnętrzny scoring ryzyka i rekomendacja źródła |
| MD-005 | klient, dane sprzedażowe i status | limity, zgody, cenniki i kontrolowane łączenie duplikatów | scoring oraz segmentacja predykcyjna |
| MD-006 | magazyn i podstawowe lokalizacje | strefy, pojemności i reguły składowania | optymalizacja rozmieszczenia zapasu |
| HR-001 | aktywacja pracownika, przypisanie i odebranie dostępu | pełne datowanie zmian, zastępstwa i przeglądy dostępu | automatyczna analiza konfliktów uprawnień |
| HR-002 | prosty grafik per lokalizacja | rewizje, kolizje, nieobecności i potwierdzenia | optymalizacja obsady względem popytu |
| HR-003 | rejestracja, zatwierdzenie i korekta czasu | integracja RCP, nieobecności, zamknięcie okresu | predykcja anomalii i automatyczne sugestie korekt |
| HR-004 | zadanie, odpowiedzialny, termin i wykonanie | reassignment, odrzucenie, załączniki i raport obciążenia | automatyczne bilansowanie pracy |
| ADM-001 | organizacja, zakład/sklep i zakres danych | relacje między jednostkami, numeracje i delegacje | centralne zarządzanie wieloma podmiotami/instancjami |
| ADM-002 | wniosek, akceptacja i domenowa korekta z uzasadnieniem | progi, weryfikacja efektu i pełna ścieżka incydentu | automatyczna diagnoza i propozycja naprawy |
| ADM-003 | numeracje, słowniki i datowane parametry | konfiguracja per jednostka, zatwierdzanie i wersjonowanie | symulacja wpływu zmian konfiguracji |
| ADM-004 | walidowany import i kontrolowany eksport | zatwierdzanie, raport błędów, retencja i śledzenie pobrań | samoobsługowe mapowanie danych i automatyczne uzgodnienia |
| REC-001 | receptura, wersja, aktywacja i niezmienny snapshot | review, wycofanie, historia obowiązywania, półprodukty i zamienniki | optymalizacja składu i wariantów technologicznych |
| REC-002 | koszt receptury na datę i sygnalizacja braków | wielopoziomowa kalkulacja, wersje i odchylenia | symulacja kosztu/scenariuszy cen surowców |
| SAL-001 | sprzedaż detaliczna, cena, płatność i zakończenie | płatności mieszane, fiskalizacja, faktura i uzgodnienie | omnichannel i automatyczna detekcja nadużyć |
| SAL-002 | zamówienie B2B, potwierdzenie, rezerwacja i realizacja | częściowe dostawy, backorder, limit kredytowy i dokumenty | automatyczne ATP/CTP i obietnica dostawy |
| SAL-003 | promocja/voucher z okresem i kontrolą użycia | priorytety reguł, budżety, zwroty i raport wykorzystania | dynamiczna optymalizacja promocji |
| SAL-004 | anulowanie przed skutkiem oraz zwrot/korekta przez kompensację | częściowe zwroty, refund, fiskalizacja i progi akceptacji | automatyczne wykrywanie wzorców zwrotów |
| SAL-005 | raport dnia i uzgodnienie sprzedaży z płatnościami | wersjonowane raporty, wyjątki, VAT, kanały i marża | analityka predykcyjna sprzedaży |
| DOC-001 | dokument ze źródłem, numerem i statusem | wysyłka, rozrachunek, załączniki i potwierdzenie odbioru | wielokanałowa/wielokrajowa automatyzacja zgodności |
| DOC-002 | korekta powiązana z oryginałem bez nadpisania | łańcuch korekt, akceptacja i uzgodnienie okresu | automatyczna walidacja skutków podatkowych |
| PLN-001 | ręczna prognoza i wersja zatwierdzona | scenariusze, sezonowość i porównanie plan–wykonanie | prognozowanie statystyczne/ML |
| PLN-002 | popyt z zamówień/planu i plan produkcji | bilans materiałów, niedobory, rewizje i akceptacja | optymalizacja zdolności i harmonogramu |
| PRD-001 | zlecenie z wersją receptury i rezerwacją materiału | priorytet, operacje, częściowe zwolnienia i kontrola gotowości | APS i automatyczne harmonogramowanie |
| PRD-002 | start, zużycie, wyrób, czas i strata | postoje, zamienniki, nadzużycie, praca częściowa i odchylenia | automatyczne zbieranie danych maszynowych/OEE |
| PRD-003 | przyjęcie partii wyrobu i podstawowa decyzja jakości | kwarantanna, częściowa akceptacja, przerób i genealogia | predykcyjna kontrola jakości |
| PRD-004 | zamknięcie i korekta różnicowa zlecenia | ponowne otwarcie, akceptacja, pełne rozliczenie i raport odchyleń | automatyczne wyjaśnianie odchyleń |
| INV-001 | przyjęcie z dokumentem, ilością i partią tam, gdzie wymagana | QC, rozbieżność, wycena, częściowe przyjęcia i etykiety | automatyzacja skanowania i awizacji |
| INV-002 | przesunięcie źródło–cel jako spójna para ruchów | tranzyt, custody, częściowy odbiór i rozbieżność | optymalizacja relokacji i tras wewnętrznych |
| INV-003 | rezerwacja i zwolnienie względem źródła popytu | częściowe zużycie, wygasanie, priorytety i ATP | dynamiczna realokacja rezerwacji |
| INV-004 | inwentaryzacja, akceptacja różnicy i ruch korekty | ślepe liczenie, ponowne liczenie, progi i analiza przyczyn | ryzykowe/cykliczne liczenia rekomendowane algorytmicznie |
| INV-005 | partia, ważność, saldo historyczne i śledzenie źródła | pełna genealogia, FEFO, recall i blokady jakości | graf wpływu i predykcja przeterminowań |
| PUR-001 | zapotrzebowanie zakupowe z ilością i terminem | konsolidacja, budżet, akceptacja i historia zmian | automatyczne propozycje uzupełnienia |
| PUR-002 | zamówienie, akceptacja i wysłanie do dostawcy | rewizje, potwierdzenie, częściowe zamknięcie i EDI | optymalizacja warunków i wyboru źródła |
| PUR-003 | przyjęcie całe/częściowe i zapis rozbieżności | kontrola jakości, reklamacja, zwrot i uzgodnienie dokumentów | automatyczna ocena rozbieżności i ryzyka dostawy |
| PUR-004 | datowana cena zakupu i podstawowa ocena dostawcy | wielokryterialny score, komentarz, historia i progi | predykcja ryzyka oraz rekomendacja dostawcy |
| LOG-001 | wysyłka, przewoźnik, status i potwierdzenie dostawy | częściowe dostawy, niepowodzenie, zwrot, POD i koszt | optymalizacja tras i śledzenie w czasie rzeczywistym |
| COST-001 | wpis kosztu ze źródłem, kategorią, okresem i centrum | walidacja, suspense, korekty i uzgodnienie wszystkich kategorii | automatyczna klasyfikacja i detekcja anomalii |
| COST-002 | odtwarzalny koszt produktu i partii | koszt standardowy/rzeczywisty, alokacje, zatwierdzenie i odchylenia | rachunek scenariuszowy i zaawansowane nośniki kosztów |
| COST-003 | rozliczenie, kontrola kompletności i zamknięcie okresu | ponowne otwarcie, korekty, snapshoty i publikacja raportów | ciągłe zamknięcie i automatyczne uzgodnienia |

Funkcja z kolumny Advanced nie powinna wejść do MVP bez odrębnego uzasadnienia biznesowego i potwierdzonej zdolności utrzymania jej przez jednoosobowy zespół.
