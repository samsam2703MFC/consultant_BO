# Gotowość danych produkcyjnych

## Werdykt

System potwierdza dane potrzebne do **planowania list produkcyjnych i zapotrzebowania materiałowego**, lecz nie potwierdza rejestru rzeczywistego wykonania produkcji. Z tego powodu nie wolno traktować planu jako produkcji wykonanej ani wyliczać rzeczywistej wydajności i kosztu partii.

## Dostępność wymaganych danych

| Dane | Status | Obecny ślad | Brak do wiarygodności |
|---|---|---|---|
| ilość planowana | PARTIAL | listy produkcyjne agregowane z zamówień i dat wysyłki | identyfikator zatwierdzonego planu/zlecenia i rewizje |
| ilość rozpoczęta | MISSING_DATA | brak potwierdzonego zdarzenia startu | zlecenie, operacja, czas i aktor |
| ilość wyprodukowana | MISSING_DATA | brak potwierdzonego raportu wykonania | zdarzenie output z jednostką i partią |
| ilość zaakceptowana | MISSING_PROCESS | brak potwierdzonej kontroli jakości | inspekcja, decyzja, osoba, ilość accepted/rejected |
| braki | MISSING_DATA | ilości brakujące występują przy zamówieniach, nie jako brak produkcyjny | typ braku, zlecenie, partia, powód |
| straty | MISSING_PROCESS | brak kanonicznego zdarzenia | ilość, przyczyna, etap, koszt i ruch magazynowy |
| odpady | MISSING_PROCESS | brak rejestru utylizacji | rodzaj odpadu, masa, sposób zagospodarowania, partia |
| zużycie teoretyczne | PARTIAL | receptura i spłaszczone składniki | zamrożona wersja receptury i snapshot jednostek |
| zużycie rzeczywiste | MISSING_PROCESS | brak potwierdzonych rozchodów na zlecenie | ruch magazynowy powiązany z materiałem i partią |
| partia | MISSING_DATA | brak potwierdzonej partii zlecenia/wyrobu | lot input/output i genealogia |
| wersja receptury | MISSING | bieżąca receptura bez potwierdzonego wersjonowania | immutable recipe version ID na zleceniu |
| czas pracy | MISSING_DATA | miesięczny koszt i grafik nie są czasem operacji | start/stop lub zatwierdzony czas per zlecenie |
| pracownik | PARTIAL | kartoteki istnieją | wykonawca i zatwierdzający per zdarzenie produkcyjne |
| maszyna | MISSING_DATA | brak potwierdzonego rejestru zasobów i zdarzeń | machine/work-center ID oraz czas pracy/przestoju |
| zmiana produkcyjna | MISSING_DATA | grafik Agent nie dowodzi wykonania | shift ID przypisany do zdarzeń i obsady |
| koszty | PARTIAL/UNRELIABLE | koszt receptury i miesięczne alokacje | rzeczywiste materiały, czas, odpad, energia i snapshot metody |

## Ocena możliwości wyliczenia KPI

| KPI | Status | Uzasadnienie |
|---|---|---|
| realizacja planu | MISSING_DATA | brak ilości wykonanej i zaakceptowanej |
| wydajność | MISSING_PROCESS | brak definicji wykonania, czasu operacji i porównywalnej jednostki |
| odchylenie materiałowe | MISSING_PROCESS | brak rzeczywistego zużycia i wersji receptury zlecenia |
| odpad procentowy | MISSING_PROCESS | brak zdarzeń odpadu/straty i mianownika accepted output |
| koszt partii | MISSING_PROCESS | brak partii i rzeczywistego ledgera kosztów zlecenia |
| koszt jednostkowy | UNRELIABLE | można wyliczać normatywnie, ale nie rzeczywiście i historycznie |
| produkcja na roboczogodzinę | MISSING_DATA | brak accepted output i zatwierdzonego czasu pracy na zlecenie |

## Ryzyko mylenia planu z wykonaniem

`ProductionListRepository` i `RequirementListRepository` przeliczają zamówienia, produkty, bieżący stan kartonów i spłaszczoną recepturę. Wynik jest zapotrzebowaniem/planem. Nie jest dowodem:

- uruchomienia zlecenia;
- wydania konkretnej partii materiału;
- rzeczywistego zużycia;
- przyjęcia wyrobu;
- kontroli jakości;
- zamknięcia i rozliczenia produkcji.

## Minimalny fakt wykonania

Każde zdarzenie produkcyjne powinno wskazywać zlecenie, operację, wersję receptury, produkt, partię wejściową/wyjściową, ilość i jednostkę, typ zdarzenia, czas biznesowy, pracownika, zmianę, lokalizację, maszynę opcjonalnie, przyczynę odchylenia oraz powiązane ruchy i koszty. Bez tych pól produkcja pozostaje analitycznie niegotowa.
