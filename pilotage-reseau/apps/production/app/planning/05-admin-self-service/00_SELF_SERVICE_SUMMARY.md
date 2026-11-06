# Audyt administracyjnego self-service — podsumowanie

## Wniosek zarządczy

System nie ma dziś spójnego administracyjnego self-service. Istnieją punktowe CRUD-y, eksporty, akcje synchronizacji, przeliczenia oraz lock/unlock miesiąca kosztowego, ale działają obok skryptów CLI, ręcznych migracji SQL, konfiguracji środowiskowej i rozproszonych retry. Największym problemem nie jest sam brak ekranów, lecz brak wspólnej kontroli: RBAC i scope, preview, uzasadnienie, maker-checker, idempotencja, audit before/after oraz kompensacja.

Docelowo należy zbudować jeden **Admin Workbench**, a nie kolekcję wyjątkowych ekranów. Workbench składa się z rejestrów danych i konfiguracji, kolejki spraw operacyjnych, monitora integracji, centrum uzgodnień, administracji dostępem i eksploratora audytu. Każda akcja mutująca wywołuje komendę domenową; panel nie może wykonywać dowolnego SQL ani nadpisywać zaksięgowanych faktów.

## Ocena

| Obszar | Stan | Najważniejszy dowód | Ocena |
|---|---|---|---|
| dane podstawowe | liczne lokalne CRUD-y, brak potwierdzonej historii/approval | trasy i kontrolery produktów, klientów, użytkowników; GAP-007–010 | częściowy |
| konfiguracja | kod, migracje, `.env`, punktowe ustawienia | migracje `003/005/006`, konfiguracja integracji, aliasy Agent | niski |
| naprawa i recovery | kilka retry/collectorów, brak centralnej kolejki spraw | `Bin/worker_outbox.php`, `Bin/shipments_retry.php`, ręczne collectory | niski |
| dane i raporty | eksporty istnieją; import, jakość i reconciliation są punktowe | eksport produktu/materiału/cennika; brak warstwy jakości KPI | częściowy |
| użytkownicy i bezpieczeństwo | CRUD użytkownika istnieje, lecz model App jest niebezpieczny | nadpisanie loginu, jawne porównanie hasła, pole `permission` | krytyczny |
| audyt | lokalne changelogi/logi, brak przekrojowej osi czasu | GAP-053, brak wspólnego audit event | niski |

## Najważniejsze ustalenia

1. **P0 bezpieczeństwa:** self-service nie może być udostępniony na obecnym mechanizmie App. Najpierw trzeba zamknąć GAP-001–004: uwierzytelnienie, centralną autoryzację, RBAC i zakres organizacyjny.
2. **Największa redukcja pracy programisty:** wspólna kolejka błędów integracji z bezpiecznym retry, zarządzanie słownikami/konfiguracją, kontrolowany import oraz diagnostyka read-only.
3. **Najwyższe ryzyko biznesowe:** bezpośrednia korekta statusu, salda, dokumentu, kosztu albo zamkniętego okresu. Zamiast edycji rekordu wymagane są komendy korekty lub kompensacji.
4. **Istniejący fragment wart zachowania:** mechanizmy idempotentnego collectora, retry outbox i blokady miesiąca są zalążkami backendu. Wymagają konsolidacji, nie prostego przepisywania na UI.
5. **Niepewność:** runtime Agent nie został potwierdzony, a baza nie była dostępna. Operacje wywnioskowane z kodu oznaczono `potwierdzone w kodzie`, a ręczne SQL bez runbooka — `prawdopodobne`.

## Docelowy model odpowiedzialności

| Rola | Zakres |
|---|---|
| administrator danych | kartoteki, słowniki, import i duplikaty w swoim zakresie |
| administrator konfiguracji | wersjonowana konfiguracja biznesowa, bez sekretów produkcyjnych |
| operator wsparcia | diagnoza i bezpieczne retry; bez arbitralnej edycji danych |
| właściciel procesu | zatwierdzanie korekt, odblokowań i wyjątków domenowych |
| administrator IAM | konta, role, scope, sesje; bez zatwierdzania własnych grantów krytycznych |
| controller/księgowość | uzgodnienia, zamknięcie i kontrolowane ponowne otwarcie okresu |
| audytor | read-only audit i eksport dowodów |
| SRE/DBA/programista | deployment, schemat, awarie infrastrukturalne; nie codzienna administracja biznesowa |

## Guardrails

- brak dowolnego edytora tabel i konsoli SQL w panelu;
- brak uniwersalnego „ustaw status”;
- issued document, posted movement, koszt i audit są niezmienne; korekta tworzy nowy fakt;
- retry zachowuje ten sam klucz idempotencji i pokazuje wynik;
- sekretów nie wyświetla się ani nie przechowuje w audycie; panel może inicjować rotację przez dedykowany vault;
- operacje `HIGH` i `CRITICAL` wymagają preview, powodu, ograniczonego scope i logu before/after; wybrane także drugiej osoby;
- operacja masowa najpierw waliduje pełny batch i zwraca artefakt wynikowy.

## Kolejność uruchamiania zdolności

1. IAM, centralna autoryzacja, audit event, correlation ID i katalog uprawnień.
2. Read-only diagnostyka, historia zmian i monitor integracji.
3. Retry/recovery z idempotencją oraz kontrolowany import.
4. Konfiguracja i kartoteki z wersjonowaniem.
5. Uzgodnienia, korekty domenowe, zamknięcie/reopen i masowe operacje.

## Ograniczenia audytu

Nie uruchomiono PHP, bazy ani integracji; nie potwierdzono ruchu produkcyjnego i entrypointu Agent. Rejestr nie jest instrukcją wykonywania SQL. Przed wdrożeniem każdej operacji trzeba potwierdzić właściciela, liczność, aktywnych konsumentów, politykę retencji i zachowanie E2E.

