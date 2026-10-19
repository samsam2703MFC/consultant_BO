# Strategia wycofywania mechanizmów

## Stany lifecycle legacy

| Status | Znaczenie |
|---|---|
| DISCOVERED | mechanizm znaleziony, użycie nieustalone |
| OWNED | ma właściciela i opis konsumentów/danych |
| FROZEN | brak nowych funkcji; tylko krytyczne poprawki |
| READ_ONLY | zapis wyłączony, dane dostępne do migracji/audytu |
| SHADOWED | nowy mechanizm działa równolegle i jest uzgadniany |
| DEPRECATED | konsumenci powiadomieni, telemetry potwierdza przejście |
| ARCHIVED | kod/dane zabezpieczone zgodnie z retencją, brak runtime |
| REMOVED | usunięty runtime/code/schema po spełnieniu dowodów |

## Warunki wycofania

Mechanizm można przejść do REMOVED tylko, gdy:

1. znane są wszystkie trasy, DI, cron, workery, zapytania DB i zewnętrzni konsumenci;
2. telemetry wykazuje brak użycia przez uzgodniony okres;
3. replacement ma E2E, reconciliation i rollback;
4. pending/dead queues są obsłużone;
5. dane są zmigrowane, zarchiwizowane lub objęte zatwierdzoną retencją;
6. owner biznesowy i techniczny zatwierdzili zakończenie;
7. dokumentacja/operacje nie wskazują starej ścieżki;
8. usunięcie następuje oddzielnie od cutover.

## Kandydaci i strategia

| Mechanizm | Docelowy los | Strategia |
|---|---|---|
| stare auth/token/permission | zastąpić IAM | shadow authz → cutover tras → revoke sesji/tokenów → archive danych audytowych |
| równoległe modele App/Agent | per capability: migrate/reuse/retire | najpierw potwierdzić deployment; zakazać dalszej duplikacji; mapować legacy IDs |
| dwie rodziny faktur | jedno source of truth lub jawna granica jurysdykcji | ADR/BD-11 → migrate links/numbering → freeze legacy write → retain documents |
| `carton_stock` jako source | projection/archive po ledger cutover | controlled opening → reconcile → stop direct writes → optional projection for old UI → retire UI |
| `material_inventory*` równoległe | merge do Inventory lub archive | profil log/saldo → map movements/opening → one writer → read-only |
| `production_list_tmp` jako pseudo-proces | rebuildable read model planu | production plan/order przejmuje semantykę; tmp nie przechowuje source of truth |
| flattened recipe tables jako historia | rebuildable projection per recipe version | dodać version association/rebuild; zakazać użycia jako jedynego snapshotu |
| lokalne outboxy | adapter do wspólnego contractu, potem konsolidacja | migrate status/backlog per queue; worker feature flags; zachować delivery audit |
| stare dashboard sources | nowy read model, UI możliwe do zachowania | parallel calculation → owner sign-off → switch → legacy label/archive |
| `.backup`, `.tmp`, `new_old`, `ToRefactor` | usunąć po scanie użycia | static refs + route/DI + runtime telemetry + smoke replacement |
| zakomentowany/stary RabbitMQ | usunąć lub jawnie reaktywować jako adapter | potwierdzić brak queue traffic; ADR communication nie wymaga brokera początkowo |
| historyczne changelogi bez UI | zachować do decyzji retencji | nie usuwać z powodu braku konsumenta; ocenić legal/audit/PII |
| ad-hoc skrypty SQL/CLI | zastąpić komendą/admin workflow | inventory użycia → named command → audit/retry → archive skryptu i wyniku |

## Zakaz big-bang cleanup

Porządkowanie katalogów nie może wyprzedzać stabilizacji źródeł prawdy. Usunięcie starego kodu w tej samej zmianie co migracja uniemożliwia szybki rollback i utrudnia analizę danych. Cleanup jest osobną, małą zmianą po obserwacji.

## Dane historyczne

Wycofanie aplikacji nie oznacza usunięcia danych. Dokumenty, ledgery, korekty, audit i mapy legacy ID zachowują dostęp read-only zgodnie z retencją. Tabele można później przenieść do schematu/archive, ale raport i support muszą nadal wskazywać lineage.
