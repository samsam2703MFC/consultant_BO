# Stabilne wejście do analizy TO-BE

Poniżej są wyłącznie fakty potwierdzone w workspace albo jawnie ograniczone fakty negatywne. Nie jest to architektura docelowa ani roadmapa.

## Granice systemu

1. Workspace zawiera jedno repo Git z panelem `App`, publicznym API App, drzewem `Agent/api_app_dir` i workerami `Bin/*`.
2. `public/index.php` uruchamia sesyjny panel przez `App/Core/App.php` i FastRoute.
3. `public/api.php` rejestruje dokładnie 3 endpointy przez `App/Core/Routes/ApiRoutes.php`.
4. 314 deklaracji tras panelu znajduje się w plikach pasujących do globu `App/Core/Routes/*/*Routes.php`; ich runtime nie został sprawdzony.
5. Dla Agent nie znaleziono w workspace front controllera ani rejestru tras; osobny deployment pozostaje możliwy.
6. Persistence App używa PDO/MySQL z konfiguracją env; schema i dane nie zostały odczytane.

## Potwierdzone możliwości

- App zawiera backend i UI dla produktów, materiałów, dostawców, receptur, klientów, zamówień, proform, dokumentów logistycznych, wysyłek, reklamacji, cenników i kalkulatora kosztów.
- App wylicza listę produkcyjną i zapotrzebowanie, lecz nie wykazano modelu wykonania zlecenia produkcyjnego.
- App raportuje sprzedaż głównie z WDT i łączy ją z miesięcznymi kosztami.
- Agent zawiera kod POS, HR, zakupów, inventory, product movement/waste, webhooków, Peppol i integracji; status operacyjny jest nieznany.
- Istnieją liczne inbox/outbox/queue repositories i workery CLI; nie znaleziono centralnej konsoli operacyjnej.

## Ograniczenia dowodowe

- testów produktu: 0 znalezionych i 0 uruchomionych;
- baza: brak dowodów struktury oraz jakości danych;
- E2E: 0 procesów uruchomionych;
- wydajność: brak planów wykonania;
- integracje: nie wywoływano usług zewnętrznych;
- deployment: brak potwierdzonej topologii i schedulerów.

## Niezmienne wymagania problemowe dla TO-BE

- usunąć wielość mechanizmów tożsamości i egzekwować autoryzację/tenant context przed kontrolerem;
- ustalić kanoniczne źródła prawdy sprzedaży, magazynu, produkcji i kosztu;
- zachować historię ceny, VAT, receptury, kosztu i korekt;
- modelować wykonanie produkcji, ruchy magazynowe i zakupy jako audytowalne procesy;
- umożliwić bezpieczne korekty i retry bez bezpośredniego SQL;
- zapewnić odtwarzalny schema lifecycle i testowalne środowisko;
- oddzielić fakty sprzedażowe od dokumentów logistycznych, jeśli biznes nie uznaje ich za równoważne.

## Zakazy interpretacyjne

- nie traktować F3 jako dowodu działania;
- nie traktować Agent jako aktywnej aplikacji bez deployment evidence;
- nie traktować braku wpisu w repo jako dowodu braku mechanizmu infrastrukturalnego;
- nie używać obecnych raportów marży jako benchmarku finansowego bez uzgodnienia;
- nie zakładać rozbieżności danych magazynowych bez query reconciliation.

