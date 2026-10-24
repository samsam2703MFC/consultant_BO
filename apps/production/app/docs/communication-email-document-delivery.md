# E-mail dokumentów i przypomnienia o płatności

## Wdrożenie

1. Uruchom migracje komunikacji kolejno: `030_communication_email_delivery.sql`, `035_communication_document_email.sql`, `036_communication_email_plaintext_secrets.sql`, `037_communication_proforma_payment_reminder_templates.sql` oraz `038_communication_email_legacy_secret_nullable.sql`.
2. W panelu otwórz **Komunikacja → Ustawienia e-mail** i skonfiguruj aktywne konto SMTP/IMAP. Hasło aplikacji nie jest zwracane przez panel.
3. Utwórz aktywne szablony dla wymaganych typów: `Faktura`, `WDT`, `Proforma`, `Przypomnienie` i `Stanowcze przypomnienie`, w językach `fr` i/lub `nl`.
4. Upewnij się, że worker działa cyklicznie:

```cron
* * * * * /usr/local/bin/php /var/www/html/Bin/communication_email_worker.php >> /var/log/slfood/email-worker.log 2>&1
```

## Obsługa

* W przeglądzie faktur i dokumentów WDT użyj przycisku **E-mail**.
* Proformę wyślesz z istniejącej akcji e-mail przy proformie.
* W **Komunikacja → Przypomnienia o płatności** wybierz dodatni dokument różnic albo nieopłaconą proformę.
* Na ekranie kompozytora wybierz język i szablon, ewentualnie włącz edycję treści, a następnie użyj **Przekaż do wysyłki**. Do wiadomości zostanie dołączona niezmienna kopia wygenerowanego PDF.
* Po dodaniu wiadomości do kolejki modal potwierdzenia zawiera odnośnik do historii wiadomości. Dla proform po zamknięciu modala następuje powrót do przeglądu proform.
* Status oraz próby dostarczenia są dostępne w **Komunikacja → Historia wiadomości**. Przy wiadomości ze statusem „W kolejce” użyj **Wyślij teraz**, aby wykonać jedną natychmiastową próbę SMTP bez oczekiwania na cron. W razie błędu standardowy mechanizm retry cron pozostaje aktywny.

Przypomnienie nie może zostać utworzone dla proformy opłaconej/anulowanej ani dla dokumentu różnic będącego zwrotem lub zerowego.
