# Gotowość danych pracowniczych

## Rozróżnienie pojęć

| Pojęcie | Obecny stan | Ryzyko |
|---|---|---|
| konto użytkownika | `user` w App oraz kilka modeli uwierzytelnienia Agent | konto może nie odpowiadać jednej osobie lub aktywnemu zatrudnieniu |
| pracownik | pracownicy produkcyjni App i `franchisee_employee` Agent | równoległe modele, brak potwierdzonego wspólnego identyfikatora |
| wykonawca operacji | punktowo `id_employee`/`id_user` | brak obowiązkowego aktora przy wszystkich zdarzeniach |
| zatwierdzający | występuje w wybranych procesach | brak globalnego pola i dowodu segregacji obowiązków |
| zmiana | brak potwierdzonego wymiaru wykonanej zmiany produkcyjnej | grafik nie oznacza obecności ani wykonania |
| czas pracy | grafik Agent i koszt miesięczny App | brak potwierdzonego rzeczywistego clock-in/out i akceptacji czasu |
| lokalizacja | `id_shop` w Agent, sekcja w kosztach App | brak historycznego assignmentu na czas operacji |
| stanowisko/rola | modele ról, pozycji, stanowisk i kompetencji Agent | brak potwierdzonego okresu obowiązywania przy fakcie |
| zadanie | moduły zadań Agent | deployment i powiązanie z czasem/produkcją niepotwierdzone |

## Ocena danych

| Zestaw danych | Status | Komentarz |
|---|---|---|
| kartoteka pracownika | PARTIAL | model istnieje, ale aktywny zakres i datowanie zatrudnienia wymagają bazy |
| historia ról/lokalizacji | UNCLEAR | połączenia istnieją, lecz okresy obowiązywania nie są potwierdzone |
| grafik planowany | BLOCKED | model Agent istnieje; runtime niepotwierdzony |
| czas rzeczywisty | MISSING_DATA | nie znaleziono potwierdzonego RCP/zaakceptowanych wpisów czasu |
| czas per zlecenie/zadanie | MISSING_PROCESS | brak połączenia z wykonaniem produkcji |
| autor operacji | UNRELIABLE | rozproszone `id_user`/`id_employee`, brak wspólnego audit logu |
| zatwierdzający | UNRELIABLE | brak konsekwentnej rejestracji |
| miesięczny koszt pracownika | PARTIAL | `cost_month_employee` modeluje kwotę i sekcję, jakość rekordów niezweryfikowana |

## Dopuszczalność KPI pracowniczych

Nie rekomenduje się rankingu, produktywności ani porównania osób. Obecny model nie potwierdza kompletnego czasu, indywidualnych kont, porównywalności stanowisk, kontekstu zmiany ani obciążenia.

| KPI | Status | Warunek zmiany statusu |
|---|---|---|
| czas pracy | MISSING_DATA | zaakceptowane wpisy rzeczywistego czasu z korektami |
| koszt pracy | PARTIAL | uzgodnienie listy pracowników, okresu, sekcji i źródła kwoty |
| sprzedaż na roboczogodzinę | MISSING_DATA | wiarygodny fakt sprzedaży + rzeczywisty czas obsady sklepu |
| produkcja na roboczogodzinę | MISSING_PROCESS | accepted output + czas per zlecenie/zmiana |
| liczba operacji | UNRELIABLE | globalny aktor i kompletne zdarzenia domenowe |
| liczba korekt | UNRELIABLE | jednolity model korekt i wykonawca/approver |
| liczba błędów | MISSING_PROCESS | definicja błędu, severity, źródło i kontekst obciążenia |

## Minimalne zabezpieczenia analityczne

- odrębne identyfikatory `user_id` i `employee_id` z datowanym powiązaniem;
- `performed_by`, `approved_by` i `on_behalf_of` tam, gdzie potrzebne;
- historyczny assignment pracownika do sklepu, sekcji, roli i stanowiska;
- rzeczywisty czas odróżniony od grafiku;
- korekta czasu jako wpis różnicowy z powodem;
- ograniczenie dostępu, retencja i agregacja zgodne z celem przetwarzania;
- KPI zespołowe przed indywidualnymi oraz jawne wykluczenie wspólnych kont.
