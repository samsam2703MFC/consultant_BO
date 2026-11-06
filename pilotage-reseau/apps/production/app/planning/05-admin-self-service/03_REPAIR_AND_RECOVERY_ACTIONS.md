# Operacje naprawcze i recovery

## Jeden mechanizm sprawy naprawczej

Każda operacja naprawcza przechodzi wspólny lifecycle:

`DETECTED → TRIAGED → PREVIEWED → REQUESTED → APPROVED (jeśli wymagane) → EXECUTING → VERIFIED → CLOSED`

Stany końcowe alternatywne to `REJECTED`, `FAILED_RETRYABLE`, `FAILED_FINAL` i `CANCELLED`. System zapisuje correlation ID, obiekt, diagnozę, proponowaną komendę, preview, aktora, approvera, wynik i dowód weryfikacji. Ponowienie tworzy attempt tej samej sprawy, nie ukrywa poprzedniego błędu.

## Katalog akcji

| Akcja | Preconditions | Preview | Skutek | Weryfikacja | Cofnięcie/kompensacja |
|---|---|---|---|---|---|
| retry komunikatu | istnieje failed/dead; błąd usunięty lub retry dozwolony | payload zredagowany, próby, cel, klucz biznesowy | nowy attempt, ten sam idempotency key | jedno zdarzenie domenowe, delivery receipt | cancel attempt przed claim; efekt zewnętrzny tylko domenową kompensacją |
| regenerate dokumentu | dokument i immutable snapshot istnieją | wersja szablonu, hash wejścia, różnica artefaktu | nowy artefakt powiązany z dokumentem | hash, render success | przywrócenie poprzedniego artefaktu jako aktywnego, bez zmiany issued snapshot |
| resend dokumentu | ważny artefakt i odbiorca | kanał, zamaskowany adres, załączniki | delivery attempt | receipt/bounce/status | brak cofnięcia wysyłki; ewentualne sprostowanie |
| naprawa statusu | wykazana niespójność i legalne przejście | bieżący stan, oczekiwany stan, skutki downstream | jawna komenda/zdarzenie korekty | invariants i projekcje zgodne | komenda odwrotna tylko jeśli domena pozwala |
| merge duplikatu | kandydaci potwierdzeni; survivorship policy | wszystkie FK, otwarte procesy, pola konfliktowe | canonical ID + aliases + relink | zero orphanów i zachowany trace | unmerge wyłącznie przed skutkami downstream; potem forward correction |
| korekta linku | target istnieje i jest w tym samym scope | rekordy before/after i skutki raportowe | correction event + relink | constraint/reconciliation | odwrotna korekta |
| rebuild kalkulacji | dane źródłowe kompletne i wersjonowane | zakres, watermark, spodziewana liczba | odbudowana projekcja | porównanie totals/diff | powrót read pointera do poprzedniej wersji |
| odblokowanie procesu | brak aktywnego wykonawcy; root cause udokumentowany | lock owner/age, bieżący stan i ryzyko | release lock lub domenowe resume | optimistic version i brak podwójnego workera | ponowne pause; nigdy ręczny update lock bez CAS |
| korekta stanu | źródło różnicy i dokument odniesienia | saldo systemowe/fizyczne, lot, wartość | compensating stock movement | ledger = projection i approval | ruch odwrotny, nie delete/update ruchu |
| reopen okresu | zamknięty okres, zależne raporty znane | lista raportów/dokumentów i użytkowników | PeriodReopened | ponowne uzgodnienie i reclose | reclose po adjustment; historia obu decyzji |
| retry/rollback importu | manifest i per-row status | zaakceptowane/odrzucone, zależności | retry tylko failed lub kompensacje | count/sums/checksum | tylko dla rekordów bez dalszych skutków; inaczej correction batch |
| dead resolution | sklasyfikowana przyczyna | retry/cancel/map/correct opcje | resolution event | brak zaległości i uzgodniony skutek | nowa sprawa korygująca |

## Zasady retry

- Retry nie zmienia immutable payload. Jeśli dane wejściowe są błędne, tworzy się nową skorygowaną komendę z linkiem do oryginału.
- Idempotency key jest stabilny dla tego samego zamierzonego skutku; „retry jako nowa wiadomość” jest zakazane.
- Batch retry wymaga filtra zamrożonego w manifeście, limitu, throttlingu, dry-run i możliwości zatrzymania.
- Błąd pokazuje klasyfikację, ostatnią bezpieczną treść, correlation ID i rekomendowane next action; stack trace tylko dla roli technicznej.

## Automatyczne versus ręczne recovery

Automatycznie wolno ponawiać błędy przejściowe w limitowanym backoffie. Ręcznego zatwierdzenia wymagają: dead po wyczerpaniu prób, konflikt duplikatu, zmiana mapowania, operacja po zamknięciu okresu, możliwy podwójny skutek zewnętrzny i każde przekroczenie scope. System nie może automatycznie „naprawić” faktu biznesowego bez jednoznacznej reguły.

## Warunki gotowości E2E

Każda akcja ma test: success, duplicate click, concurrent attempt, stale version, insufficient permission, wrong scope, approval separation, external timeout, partial failure, audit completeness oraz domain reconciliation. Dla obecnych workerów i collectorów najpierw należy zinwentaryzować wszystkie statusy, producentów i konsumentów.

