# Monitoring crona przez Discord

Konfiguracja korzysta z istniejącej migracji `migrations/029_cron_monitoring.sql` i tych samych tabel logów/outboxa.

1. Na dedykowanym kanale Discord utwórz webhook.
2. Na serwerze dodaj jego URL do pliku środowiskowego, poza repozytorium:

```dotenv
DISCORD_CRON_WEBHOOK_URL=https://discord.com/api/webhooks/...
APP_ENV=production
```

3. Harmonogram powinien uruchamiać monitorowany poller oraz worker Discorda:

```cron
*/5 * * * * /usr/bin/php /home/szymon/projects/apps/slfood_prod/app/Bin/monitored_poller.php >> /var/log/slfood/poller.log 2>&1
* * * * * /usr/bin/php /home/szymon/projects/apps/slfood_prod/app/Bin/cron_discord_notification_worker.php >> /var/log/slfood/cron-discord-notifications.log 2>&1
```

Worker publikuje embed Discorda: nazwę zadania, status, czas wykonania, host, środowisko i — przy błędzie — jego opis. Komunikaty są ponawiane maksymalnie osiem razy z rosnącym odstępem.
