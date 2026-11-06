# Poczta klienta — zadania cykliczne

Wiadomości są najpierw zapisywane jako szkic albo trafiają do kolejki. Wysyłką nie zajmuje się żądanie HTTP.

Uruchamiaj co minutę:

```sh
php /ścieżka/do/app/Bin/communication_email_worker.php 20
```

Synchronizację odpowiedzi z IMAP uruchamiaj co pięć minut:

```sh
php /ścieżka/do/app/Bin/communication_imap_sync.php 100
```

Nie jest potrzebna zmienna środowiskowa `EMAIL_CONFIG_KEY`. Hasła SMTP i IMAP są odczytywane bezpośrednio z konfiguracji zapisanej w bazie.
