# Quick wins

Poniższe pozycje są celowo małymi, niezależnymi fragmentami wartości. Nie zastępują pełnych modułów, gdy te są zależnością.

| Funkcja | Problem | Rozwiązanie i zakres | Przewidywany efekt | Projekty | Zależności |
|---|---|---|---|---|---|
| QW-01 kolejka „do obsłużenia” | terminy i statusy nie mówią użytkownikowi co zrobić | 4 reguły: brak proformy, termin, różnica, niewysłany dokument | mniej pamiętania i opóźnień | App Order/Shipping | definicje terminów |
| QW-02 status outbox i retry 1 pozycji | błąd e-mailu ukryty w workerze/logu | lista pending/failed + szczegół + retry | szybsze odzyskanie dokumentu | App Communication/Document, Bin | idempotencja istniejącego outboxu |
| QW-03 pakiet dokumentów | komplet dokumentów sprawdzany ręcznie | zakładka zamówienia/wysyłki z linkami i brakami | mniej pominięć WZ/WDT/CMR | App Document/Shipping | mapowanie identyfikatorów |
| QW-04 kolejka różnic wysyłkowych | różnica nie ma właściciela ani decyzji | status, właściciel, dosyłka/korekta/anulowanie | domknięcie wyjątków | App Shipping/Document | polityka decyzji |
| QW-05 SLA reklamacji | brak terminu reakcji i eskalacji | właściciel, data, filtr przeterminowanych | lepsza obsługa klienta | App Client/Communication | SLA biznesowe |
| QW-06 blokada nakładających się wersji receptury | bieżąca edycja zaciera historię | najpierw tylko data obowiązywania i walidacja nakładania | mniej błędów technologicznych | App Recipe | decyzja dat |
| QW-07 zamknięcie okresu kosztowego — checklist | dane kosztowe mogą pozostać niepełne | checklista wymaganych danych i oznaczenie „szacunek” | bezpieczniejsze raporty | App Finances | właściciel okresu |
| QW-08 filtry raportów i drill-down | raport nie prowadzi do źródła | okres/sekcja/klient i link z KPI do listy | szybsza diagnoza | App Finances/Dashboard | repozytoria analityczne |
| QW-09 kody powodów | korekty i wyjątki są niejednolite | słownik dla anulacji/różnicy/odpadu | dane użyteczne analitycznie | App Settings | governance słowników |
| QW-10 ostrzeżenie niedoboru przed drukiem planu | plan może być niewykonalny | informacyjna kontrola wymaganych vs dostępnych danych, bez rezerwacji | wcześniejsze wykrycie ryzyka | App Production | wiarygodny odczyt stanu |
| QW-11 alert cen receptury | zmiana ceny materiału może istotnie zmienić koszt | lista receptur z odchyleniem ponad próg | priorytet aktualizacji cen | App Recipe/Finances | zdefiniowany próg |
| QW-12 archiwizacja z powodem | archiwizacja zamówień bez widocznej decyzji | wymagany powód i wpis changelog | mniej niejasności | App Order | istniejący changelog |
| QW-13 wskaźnik jakości danych raportu | odbiorca nie zna ograniczeń KPI | etykieta: okres otwarty/zamknięty, źródło, data odświeżenia | mniej błędnych decyzji | App Finances | ustalone definicje |
| QW-14 lista braków dokumentów zakupu | dokument zakupu nie wskazuje niepełnych danych | walidacja dostawcy, pozycji, daty, numeru | mniej ręcznych doprecyzowań | App Purchase | brak |
| QW-15 kompetencje przy planowanym przydziale | technologia ma kompetencje, produkcja ich nie używa | ostrzeżenie na przypisaniu do przyszłego zlecenia | mniej ryzyka operacyjnego | App Recipe/Production | FEAT-001 |

Najpierw zalecane są QW-01–QW-05 i QW-12: wykorzystują istniejące obiekty, nie wymagają budowy magazynu ani migracji danych, a bezpośrednio usuwają obserwowalne przerwy w pracy operacyjnej.
