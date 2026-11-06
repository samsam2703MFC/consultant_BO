# Kontrole operacji wysokiego ryzyka

## Kontrole bazowe

Każda operacja `HIGH` lub `CRITICAL` ma: confirmation step z konkretnym skutkiem, obowiązkowy reason code i opis, dedykowane permission, preview, log before/after, scope ograniczony do obiektu/organizacji oraz rollback lub opisaną kompensację. Sam modal „czy na pewno?” nie jest kontrolą wystarczającą.

## Matryca

| Operacja | Ryzyko | Confirmation | Approval/step-up | Preview | Ograniczenie | Kompensacja |
|---|---|---|---|---|---|---|
| grant privileged role/scope | CRITICAL | wpisanie identyfikatora użytkownika/roli | druga osoba + step-up | effective access diff i SoD | czasowy grant, jedna organizacja | revoke jako nowy event |
| reset/reaktywacja konta | CRITICAL | wskazanie kanału i revoke sesji | step-up; approval przy privileged | konto, owner, ostatnie logowanie | jedna tożsamość | block/revoke |
| zmiana podatku/numeracji | CRITICAL | wersja i data wejścia | finanse + druga osoba | sample documents/next numbers | scope i future effective date | publikacja poprzedniej wersji; issued bez zmian |
| publish ceny/receptury | CRITICAL | wersja/hash | maker-checker | diff, produkty/klienci, koszt/marża | przyszła data i scope | withdraw/new version |
| status repair/unlock | CRITICAL | nazwana komenda, nie wartość statusu | process owner | skutki downstream i preconditions | jeden aggregate/version | domenowa komenda odwrotna |
| stock adjustment | CRITICAL | wpisanie ilości/jednostki i powodu | kierownik, próg wartości | saldo before/after, lot, wartość | location/product/limit | reversing movement |
| production correction | CRITICAL | correction case | produkcja + controller | ruchy, koszt, QC, dokumenty | jedno zlecenie, okres | compensating events |
| period close | CRITICAL | akceptacja checklisty/hash | controller + owner według polityki | blockers, reconciliation, report version | jeden okres/jednostka | formal reopen |
| period reopen | CRITICAL | wpisanie okresu i wpływu | dwie osoby + step-up | zależne raporty/exports | okno czasowe, jedna jednostka | adjustment + reclose |
| integration batch retry | HIGH/CRITICAL | liczba i filtr manifestu | approval ponad limit | targets, attempts, dedup, throttle | limit/rate/scope | stop pending; efekty kompensować |
| merge duplikatu | HIGH | canonical survivor | steward approver | dependency graph/field conflicts | jedna para/grupa | alias/forward correction |
| import apply | HIGH | checksum i liczby batcha | approval ponad próg | accepted/rejected/diffs | template/scope/limit | rollback bez dependencji lub correction batch |
| eksport PII/finansów | HIGH | cel i odbiorca | step-up, czasem approval | fields/count/scope | masking, row limit, expiry | revoke link; wycieku nie cofa |
| secret rotation | CRITICAL | alias celu, nigdy wartość | security + owner | fingerprint/expiry/test target | jedna integracja/environment | switch to prior version in window |

## Confirmation step

Potwierdzenie prezentuje skutki w języku biznesowym, liczbę obiektów, zakres i nieodwracalne efekty. Dla masowych/CRITICAL użytkownik wpisuje krótki token kontekstowy (np. numer okresu), nie ogólne słowo. Ponowne potwierdzenie jest wymagane po zmianie preview lub wygaśnięciu optimistic version.

## Limity i kill switch

- maksymalny batch per permission i środowisko;
- rate limit oraz concurrency limit per integracja/typ joba;
- scheduled execution poza krytycznym oknem, jeśli operacja obciąża system;
- kill switch zatrzymuje nieclaimowane elementy i zachowuje pełny wynik częściowy;
- circuit breaker blokuje dalsze retry po wzroście tego samego błędu;
- alert do ownera dla przekroczenia progu wartości, liczby lub czasu.

## Kontrole techniczne

Optimistic locking, transakcja/outbox, idempotency, constraints DB, policy enforcement po stronie serwera, CSRF/session hardening, redakcja logów, signed/expiring export URLs i test rollback/restore. UI nie może przesłać dowolnej nazwy tabeli, statusu, permission ani handlera.

