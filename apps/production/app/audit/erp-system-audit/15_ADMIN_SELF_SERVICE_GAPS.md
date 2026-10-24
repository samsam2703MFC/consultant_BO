# Luki administracyjnego self-service

| Czynność | Obecne obejście | Ryzyko | Cel | Priorytet/koszt |
|---|---|---|---|---|
| Rotacja sekretów i konfiguracja integracji | edycja `.env` przez programistę | P0 | secret manager + walidator config | P0/M |
| Wdrożenie migracji | ręczne SQL i dokumentacja Markdown | niespójny schemat | runner, wersje, dry-run | P1/M |
| Obsługa błędów outbox | worker/log/SQL | duplikaty lub utknięcie | konsola retry/DLQ | P1/M |
| Korekta statusu procesu | prawdopodobnie SQL | utrata audytu | komendy domenowe z powodem | P1/L |
| Uzgodnienie magazynu | ręczne porównania | błędny stan | ekran reconciliation | P1/L |
| Ponowne generowanie dokumentu | rozproszone akcje/CLI | brak obsługi | reprint/regenerate z historią | P2/M |
| Role panelu | uproszczone pole permission | nadmiarowy dostęp | RBAC UI i matryca | P0/L |
| Status integracji Agent | brak wspólnego panelu | niewidoczne awarie | dashboard integracji | P1/M |
| Konfiguracja statusów/słowników | migracje/kod | zależność od dewelopera | admin CRUD z ochroną | P2/M |
| Raporty ad hoc | nowe repozytorium/widok | koszt rozwoju | warstwa semantyczna/eksport | P2/L |

