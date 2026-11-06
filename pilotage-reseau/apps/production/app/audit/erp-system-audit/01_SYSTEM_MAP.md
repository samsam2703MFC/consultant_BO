# Mapa systemu

## Komponenty

| Komponent | Rola | Wejście | Stan |
|---|---|---|---|
| Panel web `App` | ERP produkcyjny/admin: kartoteki, receptury, klienci, zamówienia, dokumenty, wysyłka, koszty, raporty | `public/index.php` → `App/Core/App.php` | Aktywny w kodzie; runtime niezweryfikowany |
| API `App` | Integracja klientów i zamówień | `public/api.php` → `App/Core/Routes/ApiRoutes.php` | Tylko 3 trasy potwierdzone |
| `Agent/api_app_dir` | Bogaty API/POS/sklepy/dostawcy/HR/Peppol/webhooki | Brak front controllera w badanym drzewie | Niepotwierdzone wdrożenie; kod wtórny/staging |
| Workery CLI | outbox, ceny, zamówienia, retry wysyłek, poller | `Bin/*.php` | Harmonogram i uruchomienie nieudokumentowane |
| MySQL | Główny persistence store | PDO, zmienne `DB_*` | Schemat i dane BLOCKED |
| S3/R2 | Załączniki i dokumenty | AWS SDK | Konfiguracja w env; nie testowano |
| Webhook/RabbitMQ | Synchronizacja zamówień/cenników/reklamacji | HTTP, inbox/outbox, AMQP | Częściowo zaimplementowane; część kodu RabbitMQ zakomentowana |
| Peppol | Dokumenty e-fakturowe | usługi i repozytoria Agent | Punkt wejścia niepotwierdzony |

## Przepływ danych

```text
Użytkownik panelu
  -> public/index.php -> FastRoute (314 tras web)
  -> Controller -> Service -> Repository -> MySQL
  -> Twig/PHP view / CSV / XLSX / PDF

Klient integracyjny
  -> public/api.php -> 3 trasy API -> App Controller -> Service -> MySQL

Sklep/POS/dostawca (kod niepotwierdzony)
  -> Agent controllers -> Agent services/repositories -> MySQL
  -> webhook inbox/outbox -> panel/partner
  -> S3/R2 / e-mail / Peppol / RabbitMQ

Cron/operator
  -> Bin/* -> outbox/queue tables -> HTTP integracje
```

## Granice zaufania

Panel korzysta z sesji PHP, API Agent z JWT/cookies i starszego `LoginToken`. Te mechanizmy współistnieją. Brak wspólnego gatewaya i wspólnej polityki tenantów zwiększa ryzyko różnic autoryzacyjnych.

