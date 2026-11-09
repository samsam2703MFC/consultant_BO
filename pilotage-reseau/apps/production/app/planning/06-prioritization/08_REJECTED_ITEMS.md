# Elementy odrzucone

| Pomysł | Decyzja | Uzasadnienie | Kiedy ponownie rozważyć |
|---|---|---|---|
| big-bang rewrite App i Agent | REJECT | brak potwierdzonego runtime, wysoki cutover risk i brak równoległego dowodu | tylko gdy incremental strangler okaże się technicznie niemożliwy i istnieje finansowany plan migracji |
| mikrousługa per domena | REJECT | jedna osoba poniosłaby koszt wielu deploymentów, auth, sieci, telemetryki i danych | niezależne zespoły/SLA/skala lub twarda izolacja bezpieczeństwa potwierdzona metryką |
| pełny event sourcing | REJECT | nie jest potrzebny do audytu i korekt; zwiększa migrację i mental load | domena wykaże potrzebę odtwarzania pełnego stanu i zespół utrzyma kontrakty zdarzeń |
| broker/RabbitMQ jako obowiązkowy fundament | REJECT | DB outbox/inbox wystarcza bez potwierdzonej skali | throughput/izolacja wielu konsumentów przekroczy zmierzone możliwości DB outbox |
| uniwersalny edytor tabel/SQL w adminie | REJECT | omija invariants, scope, audit i kompensację | nigdy dla biznesu; break-glass pozostaje kontrolowanym narzędziem technicznym |
| uniwersalny „ustaw status” | REJECT | status ma skutki domenowe i preconditions | zastąpić nazwanymi komendami per domena; bez warunku powrotu |
| osobny ekran retry dla każdego outboxu | REJECT | duplikuje UX i utrwala różne kontrakty | tylko specyficzny detail panel w wspólnym Operations Inbox |
| dashboard jako naprawa jakości danych | REJECT | wizualizacja nie tworzy faktu ani korekty | dashboard wraca jako ERP-037 po gate KPI |
| delete posted movement/issued document/audit | REJECT | niszczy historię i uzgodnienie | nigdy; używać reversal/correction/retention workflow |
| dual write z kontrolera do dwóch modeli | REJECT | partial failure i podwójny skutek | tylko jeden application service + transactional outbox/adapter i reconciliation |
| automatyczne ML/forecast przed manual baseline | REJECT NOW | brak danych i sposobu oceny wartości | wraca wyłącznie jako ERP-041 po spełnieniu warunków odroczenia |
| natychmiastowe usunięcie Agent/ToRefactor/tabel historycznych | REJECT | brak dowodu braku konsumentów i decyzji retencji | ERP-040 po traffic/data/retention evidence |
| general-purpose workflow engine | REJECT | state machines domenowe są prostsze i testowalne | wiele stabilnych procesów wykaże identyczną, dynamiczną potrzebę konfiguracyjną |
| osobne repozytorium dla każdego modułu | REJECT | zwiększa context switching i utrzymanie bez niezależnych zespołów | dopiero po spełnieniu kryteriów wydzielenia komponentu |

Odrzucenie dotyczy wariantu rozwiązania, nie potrzeby biznesowej. Przykładowo potrzeba korekty statusu pozostaje, ale realizuje ją komenda domenowa i kompensacja, nie edytor statusu.

