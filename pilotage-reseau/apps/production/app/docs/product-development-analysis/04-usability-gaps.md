# Luki użyteczności

| ID | Obszar | Rola | Obecny problem | Skutek | Obejście stosowane przez użytkownika | Potwierdzenie w kodzie | Pewność |
| -- | ------ | ---- | -------------- | ------ | ------------------------------------ | ---------------------- | ------- |
| GAP-01 | Produkcja | planista/brygadzista | plan można wydrukować, brak zlecenia i wykonania | brak plan-vs-wykonanie, odpady niewidoczne | papier/Excel — hipoteza | `ProductionListService.php`, brak modelu wykonania | wysoka |
| GAP-02 | Materiały | planista/magazyn | zapotrzebowanie nie rezerwuje ani nie wydaje materiału | plan nierealny lub podwójne użycie zapasu | ręczne sprawdzenie stanów | `RequirementListService.php`; brak rezerwacji | wysoka |
| GAP-03 | Magazyn | magazynier | stan wyrobów nie ma pełnego ledgeru ruchów | nie da się wyjaśnić różnicy stanu | Excel/SQL — hipoteza | `FinalProductStockRepository.php`, osobny Agent inventory | wysoka |
| GAP-04 | Sprzedaż | handlowiec | brak widocznego worklistu zamówień po terminie/bez następnego kroku | opóźnienia i pamiętanie poza systemem | notatki/e-mail — hipoteza | `OrderController.php`, brak alertów w trasach | średnia |
| GAP-05 | Dokumenty | handlowiec/operator | outbox i retry są CLI/logami, bez ekranu operacyjnego | niedoręczone dokumenty mogą pozostać niezauważone | log/CLI | `ProformaOutboxRepository.php`, `Bin/worker_outbox.php` | wysoka |
| GAP-06 | Zakupy | zakupy | dokument zakupu nie prowadzi przez zapotrzebowanie, PO i przyjęcie | ceny/terminy/różnice są poza obiegiem | e-mail/arkusz — hipoteza | `PurchaseService.php`, Agent order/delivery | wysoka |
| GAP-07 | Receptury | technolog | brak wersji receptury/ceny użytej w historii | niepewna marża i odtwarzalność | kopie/Excel — hipoteza | `FlattenedRecipeService.php`, `RecipeCostRecalculationService.php` | wysoka |
| GAP-08 | Wysyłka | logistyka | różnice wysyłki nie są kolejką wyjątków z właścicielem | przypadki mogą nie być rozliczone | telefon/e-mail — hipoteza | `OrderShippingDifferenceService.php` | średnia |
| GAP-09 | Reklamacje | obsługa | brak SLA, terminów i eskalacji | klient nie ma przewidywalnego terminu odpowiedzi | kalendarz/e-mail — hipoteza | `ComplaintService.php`, brak alertów | średnia |
| GAP-10 | Koszty | controller | raporty nie oznaczają jakości/historyczności danych | ryzyko decyzji na bieżących cenach | ręczna walidacja | `SalesAnalyticsRepository.php`, `CostCalculatorService.php` | wysoka |
| GAP-11 | Administracja | administrator | korekty procesów, retry i ustawienia wymagają kodu/SQL/CLI | zależność od programisty, ryzyko zmian bez audytu | kontakt z programistą | `Bin/*`, migracje, `15_ADMIN_SELF_SERVICE_GAPS.md` | wysoka |
| GAP-12 | Pracownicy | kierownik produkcji | kompetencje są w recepturze, ale brak przydziału do zlecenia | niekontrolowane wykonanie przez nieuprawnioną osobę | ustne ustalenia — hipoteza | `RecipeCompetencyService.php`; brak zlecenia | wysoka |
