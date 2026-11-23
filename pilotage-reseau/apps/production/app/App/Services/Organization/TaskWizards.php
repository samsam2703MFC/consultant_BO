<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Repositories\Organization\TaskWizardRepository;
use App\Services\Production\Cost\MonthCloseService;
use Psr\Container\ContainerInterface;

/**
 * Kreatory podpinane pod zadania stanowisk [227] (« zadanie = kreator ») : strona kreatora, kiedy zadanie zalicza się samo
 * i stan w okresie zadania — zakończony (z wynikiem liczbowym na karcie) albo w toku (krok z kroków). Kreatory
 * miesięczne (zamknięcie miesiąca, koszty usług) dotyczą miesiąca poprzedniego (zamykany na początku następnego).
 */
final class TaskWizards
{
    /** kod => [nazwa, link, zalicza się, gdy…, kroki] */
    public const CATALOG = [
        'forecast_approved' => ['Prognoza 13 tygodni', '/production/forecast', 'prognoza zatwierdzona', 1],
        'buy_sent' => ['Zakupy tygodnia', '/workshop/buy', 'zapytania ofertowe wysłane', 3],
        'inv_checked' => ['Asystent faktur', '/workshop/supply#tab=invoices&inv=wizard', 'pozycje faktur sprawdzone', 1],
        'costs_recalc' => ['Asystent cen — koszty i marże', '/finances/price-wizard', 'koszty i marże przeliczone', 3],
        'prices_planned' => ['Asystent cen — nowe ceny', '/finances/price-wizard#step=2', 'nowe ceny zaplanowane', 3],
        // [351] Ścieżka cen (co czwartek) : faktury KSeF → ceny katalogowe ±3 % → cennik → e-mail (makieta 66 B).
        'price_path_done' => ['Ścieżka cen', '/finances/price-wizard#step=2', 'cennik zaplanowany albo ceny sprawdzone bez zmian w tygodniu', 4],
        'week_plan_validated' => ['Plan produkcji tygodnia', '/production/plan-wizard', 'plan tygodnia zatwierdzony', 2],
        'production_day_closed' => ['Zatwierdzenia produkcji', '/production/follow-up', 'dzień zatwierdzony na tabletach', 1],
        'day_shipped' => ['Wysyłka dnia', '/shipping/wizard', 'wszystkie palety dnia z dokumentami', 1],
        'logistics_plan_approved' => ['Plan logistyki 13 tygodni', '/logistics/plan', 'tydzień planu zatwierdzony', 1],
        // [303] Zamówienia dziś / jutro bez transportu → asystent (makieta 56).
        'orders_transport' => ['Transport dla zamówień', '/logistics/transport-wizard', 'każda wysyłka dziś i jutro ma transport', 3],
        'month_closed' => ['Zamknięcie miesiąca', '/month-close', 'miesiąc zamknięty (8 kroków)', 8],
        'services_closed' => ['Koszty usług miesiąca', '/finances/costs', 'miesiąc zamknięty w Finansach', 1],
        // Tablety [229] : zadanie dnia stanowiska pracowni, zaliczane przez zatwierdzenie na tablecie.
        'group_day_closed' => ['Dzień grupy (tablet produkcji)', '/production/follow-up', 'grupa zatwierdziła dzień na tablecie', 1],
        'shift_day_validated' => ['Zmiana (wszystkie grupy)', '/production/follow-up', 'wszystkie grupy z produkcją zatwierdziły dzień', 1],
        'packing_done' => ['Pakowanie dnia (tablet pakowania)', '/logistics/live', 'wszystko spakowane na dzień wysyłki', 1],
        'route_done' => ['Trasa dnia (tablet kierowcy)', '/logistics/deliveries', 'trasa zakończona na tablecie kierowcy', 1],
        // Lot 4 [230] : przyjęcia (tablet pakowania, tryb Przyjęcia ; podsumowanie w ERP), płatności dnia, ocena dostawców.
        'receiving_checked' => ['Kontrola przyjęć dnia', '/workshop/receiving', 'wszystkie dostawy dnia sprawdzone', 3],
        'payments_reviewed' => ['Płatności dnia', '/finances/payments-day', 'wpłaty, przypomnienia i różnice dostaw przejrzane', 3],
        'suppliers_reviewed' => ['Ocena dostawców', '/purchasing/supplier-review', 'ocena kwartału zatwierdzona', 3],
        // Lot « lżej » [247] (makiety 19–23) : zadania, które nie miały kreatora.
        'tomorrow_sent' => ['Plan na jutro', '/production/tomorrow', 'plan jutra wysłany na tablety', 3],
        // [369] Zamówienia dnia (Kierownik operacyjny) : nouvelles et modifiées, à vérifier, validation.
        'orders_day_checked' => ['Zamówienia dnia', '/client-orders/day-check', 'zamówienia dnia sprawdzone', 3],
        // [370] Klienci bez zamówienia (Kierownik operacyjny) : clients réguliers sans la commande attendue, résultat, validation.
        'customers_ordered_checked' => ['Klienci bez zamówienia', '/client-orders/missing', 'stali klienci bez zamówienia wyjaśnieni', 3],
        // [371] Domówienia dnia (Kierownik operacyjny) : domówienia récentes et à venir, état côté production, résultat, validation.
        'supplements_day_checked' => ['Domówienia dnia', '/client-orders/supplements', 'domówienia dnia przekazane do produkcji', 3],
        // [373] Płynność i gotówka (Kierownik operacyjny, co tydzień) : 4 semaines d'entrées et de sorties, solde saisi, décision par signal.
        'liquidity_checked' => ['Płynność i gotówka', '/finances/liquidity', 'płynność 4 tygodni sprawdzona, decyzje zapisane', 3],
        // [374] Opóźnienia dostaw (Kierownik operacyjny) : livraisons du jour, en retard ou en transport trop long, décision par livraison.
        'deliveries_delays_checked' => ['Opóźnienia dostaw', '/logistics/delays', 'opóźnienia i ryzyka dostaw wyjaśnione', 3],
        // [375] Terminowość tygodnia (OTIF) (Kierownik operacyjny, co tydzień) : OTIF de la semaine écoulée, tendance, une action par cause principale.
        'otif_week_reviewed' => ['Terminowość tygodnia (OTIF)', '/client-orders/otif', 'OTIF tygodnia oceniony, działanie dla każdej przyczyny', 3],
        // [377] Partie i kontrola dnia (Specjalista ds. jakości) : journées de groupe closes, trous de traçabilité, un résultat par journée.
        // Libellé distinct du nom proposé dans la matrice (« Partie i kontrola dnia ») : les UPDATE des migrations ne prennent que celui-ci.
        'lots_qc_day_checked' => ['Partie i kontrola dnia (identyfikowalność)', '/workshop/traceability/day-check', 'braki identyfikowalności zatwierdzonych dni grup wyjaśnione', 3],
        // [378] Faktury i KSeF dnia (Administracja i fakturowanie) : expéditions du jour, WDT et KSeF, un résultat par document signalé.
        // Libellé distinct du nom proposé dans la matrice (« Faktury i KSeF dnia ») : les UPDATE des migrations ne prennent que celui-ci.
        'invoices_ksef_checked' => ['Faktury i KSeF dnia (wysyłki)', '/shipments/ksef-day', 'faktury WDT wysyłek dnia przyjęte w KSeF albo wyjaśnione', 3],
        // [379] Reklamacje klientów (Specjalista ds. jakości, co tydzień) : réclamations ouvertes ou mal suivies, semaine écoulée, une décision par réclamation signalée.
        // Libellé distinct du nom proposé dans la matrice (« Reklamacje klientów ») : les UPDATE des migrations ne prennent que celui-ci.
        'complaints_reviewed' => ['Reklamacje klientów (przegląd tygodnia)', '/franchise-complaints/review', 'reklamacje tygodnia przejrzane, decyzja dla każdej z sygnałem', 3],
        // [380] HACCP miesiąca (Jakość, Kierownik produkcji, Brygadzista, Kierowca — co miesiąc) : zdarzenia HACCP, przeglądy i odchylenia SOP, temperatura w dostawach.
        // Libellé distinct du nom proposé dans la matrice (« HACCP miesiąca ») : les UPDATE des migrations ne prennent que celui-ci.
        'haccp_month_reviewed' => ['HACCP miesiąca (przegląd)', '/production/haccp-month', 'zdarzenia HACCP, SOP i temperatura miesiąca wyjaśnione', 3],
        // [381] Palety zwrotne (Logistyka, Kierowca-dostawca — co tydzień) : saldo palet EUR klientów, brakujące i uszkodzone palety, decyzja dla klienta.
        // Libellé distinct du nom proposé dans la matrice (« Palety zwrotne ») : les UPDATE des migrations ne prennent que celui-ci.
        'pallets_returns_reviewed' => ['Palety zwrotne (saldo klientów)', '/logistics/pallets-returns', 'saldo palet EUR klientów przejrzane, decyzja dla każdego z sygnałem', 3],
        // [382] Powiadomienia o dostawie (Logistyka — codziennie) : klienci dnia wysyłki z powiadomieniem, błędem e-mail / SMS albo bez kontaktu, decyzja dla klienta.
        // Libellé distinct du nom proposé dans la matrice (« Powiadomienia o dostawie ») : les UPDATE des migrations ne prennent que celui-ci.
        'delivery_notices_checked' => ['Powiadomienia o dostawie (dzień)', '/logistics/notices-check', 'powiadomienia klientów dnia wysyłki sprawdzone, decyzja dla każdego z sygnałem', 3],
        // [383] Poczta od klientów (Administracja — codziennie) : e-maile od klientów bez odpowiedzi po customer_mail.reply_hours h, stan synchronizacji skrzynki, decyzja dla e-maila.
        // Libellé distinct du nom proposé dans la matrice (« Poczta od klientów ») : les UPDATE des migrations ne prennent que celui-ci.
        'customer_mail_checked' => ['Poczta od klientów (bez odpowiedzi)', '/mail/customer-check', 'e-maile od klientów bez odpowiedzi po terminie wyjaśnione, decyzja dla każdego', 3],
        // [384] Nowe produkty (Kierownik produkcji — co miesiąc) : wprowadzenia ostatnich miesięcy, cele (koszt, cena, marża, wolumen) wobec wyniku, decyzja dla produktu.
        // Libellé distinct du nom proposé dans la matrice (« Nowe produkty ») : les UPDATE des migrations ne prennent que celui-ci.
        'launches_reviewed' => ['Nowe produkty (przegląd miesiąca)', '/products/launches-review', 'nowe produkty przejrzane (cele vs wynik), decyzja dla każdego z sygnałem', 3],
        // [385] Dane klientów (Administracja — co miesiąc) : aktywni klienci z brakami w karcie (e-mail, telefon, język, termin płatności, NIP / VAT UE, adres, cennik), decyzja dla klienta.
        // Libellé distinct du nom proposé dans la matrice (« Dane klientów ») : les UPDATE des migrations ne prennent que celui-ci.
        'customers_data_checked' => ['Dane klientów (kompletność)', '/clients/data-check', 'karty aktywnych klientów sprawdzone, decyzja dla każdego z brakami', 3],
        // [397] PPWR i opakowania (Kierownik produkcji — co miesiąc) : braki PPWR / BDO opakowań i produktów, zmiany, dowody opakowań wysyłek, decyzja dla elementu.
        // Libellé distinct du nom proposé dans la matrice (« PPWR i opakowania (kompletność) ») : les UPDATE des migrations ne prennent que celui-ci.
        'ppwr_month_reviewed' => ['PPWR i opakowania (przegląd miesiąca)', '/ppwr/month', 'dane PPWR i BDO opakowań i produktów sprawdzone, decyzja dla każdego braku', 3],
        'nc_reviewed' => ['Niezgodności miesiąca', '/purchasing/nc-review', 'każda niezgodność ma działanie', 3],
        'material_costs_reviewed' => ['Koszty surowców', '/purchasing/cost-review', 'decyzje miesiąca zapisane', 3],
        'yield_reviewed' => ['Wydajność i straty', '/production/yield-review', 'przegląd kwartału zatwierdzony', 3],
        // [352] Próbki produkcji (makieta 68 · 3) : wybór partii, arkusz A4 z QR, skan przy odłożeniu.
        // [353] SOP dnia : procedura poprawiona albo nowa, zatwierdzona dziś.
        'sop_daily' => ['SOP dnia', '/sop', 'wersja procedury zatwierdzona dziś (poprawiona albo nowa)', 4],
        'samples_done' => ['Próbki produkcji', '/workshop/traceability/samples', 'próbki dnia odłożone (skan QR) albo dzień bez próbek', 3],
        // [262] Czas pracy : zadanie nr 1 kierownika produkcji — odbicia dnia sprawdzone, powody wybrane, dzień zatwierdzony.
        'time_day_validated' => ['Czas pracy dnia', '/time', 'dzień zatwierdzony (odbicia, powody nadgodzin i nieobecności)', 1],
        // [273] Czas pracy miesiąca : poprzedni miesiąc sprawdzony i zamknięty (karty czasu pracy).
        'time_month_closed' => ['Czas pracy miesiąca', '/time/month', 'poprzedni miesiąc zamknięty (karty czasu pracy)', 1],
    ];

    /** Stanowisko pracowni → grupa produkcji (dzień grupy na tablecie). */
    public const POSITION_GROUP = ['PIEKARZ' => 'PIEKARNIA', 'ROGAL' => 'ROGAL', 'CUKIERNIK' => 'CUKIERNIA', 'GARMAZER' => 'GARMAZERIA', 'TARTY' => 'TARTY'];

    /** Zalicza się samo, gdy… (lista do planu stanowiska). */
    public const AUTO = [
        'inv_checked' => 'gdy pozycje faktur są sprawdzone (asystent faktur)',
        'costs_recalc' => 'gdy koszty i marże są przeliczone (asystent cen, krok 1)',
        'prices_planned' => 'gdy nowe ceny są zaplanowane (asystent cen, krok 3)',
        'price_path_done' => 'gdy w tygodniu zaplanowano zmianę cennika albo zapisano « Sprawdzone — bez zmian w tym tygodniu » (ścieżka cen, krok 2)',
        'buy_sent' => 'gdy zapytania ofertowe są wysłane (zakupy tygodnia)',
        'forecast_approved' => 'gdy prognoza 13 tygodni jest zatwierdzona (Produkcja › Prognoza)',
        'week_plan_validated' => 'gdy plan produkcji tygodnia jest zatwierdzony (asystent planu)',
        'production_day_closed' => 'gdy dzień jest zatwierdzony na tabletach (zatwierdzenia produkcji)',
        'day_shipped' => 'gdy wszystkie palety dnia mają dokumenty (wysyłka dnia)',
        'logistics_plan_approved' => 'gdy tydzień planu logistyki jest zatwierdzony',
        'orders_transport' => 'gdy każda wysyłka dziś i jutro ma transport (asystent « Transport dla zamówień »)',
        'month_closed' => 'gdy poprzedni miesiąc jest zamknięty (zamknięcie miesiąca, 8 kroków)',
        'services_closed' => 'gdy koszty usług poprzedniego miesiąca są zamknięte (Finanse)',
        'group_day_closed' => 'gdy grupa stanowiska zatwierdzi dzień na tablecie produkcji',
        'shift_day_validated' => 'gdy wszystkie grupy z produkcją zatwierdzą dzień na tabletach',
        'packing_done' => 'gdy wszystkie produkty dnia wysyłki są spakowane (tablet pakowania)',
        'route_done' => 'gdy trasy dnia są zakończone na tablecie kierowcy',
        'receiving_checked' => 'gdy wszystkie dostawy dnia są sprawdzone (tablet pakowania › Przyjęcia)',
        'payments_reviewed' => 'po kroku 3 asystenta płatności (wpłaty, przypomnienia, różnice dostaw)',
        'suppliers_reviewed' => 'gdy ocena dostawców kwartału jest zatwierdzona (asystent oceny)',
        'tomorrow_sent' => 'gdy plan na jutro jest wysłany na tablety (asystent « Plan na jutro », krok 3)',
        'orders_day_checked' => 'gdy nowe i zmienione zamówienia są sprawdzone, każde do wyjaśnienia z wynikiem (asystent « Zamówienia dnia », krok 3)',
        'customers_ordered_checked' => 'gdy każdy stały klient bez oczekiwanego zamówienia ma wynik (kontakt, zamówienie przyszło, przerwa) — asystent « Klienci bez zamówienia », krok 3',
        'supplements_day_checked' => 'gdy każde domówienie z sygnałem (nie w produkcji, po godzinie granicznej, data, produkt nieaktywny) ma wynik — asystent « Domówienia dnia », krok 3',
        'deliveries_delays_checked' => 'gdy każda dostawa z sygnałem (po terminie, nie wyjechała, nic gotowego, bez pojazdu lub kierowcy, przeładowany pojazd, za długo w transporcie, problem przy dostawie, data zmieniana wiele razy) ma decyzję — asystent « Opóźnienia dostaw », krok 3',
        'liquidity_checked' => 'gdy saldo tygodnia jest wpisane i każdy sygnał (zaległości klienta, duża płatność dostawcy, tydzień poniżej poduszki) ma decyzję — asystent « Płynność i gotówka », krok 3',
        'otif_week_reviewed' => 'gdy OTIF poprzedniego tygodnia (na czas, w całości) jest oceniony i każda główna przyczyna zamówień nie-OTIF ma działanie (akceptowane, korygujące, do obserwacji) — asystent « Terminowość tygodnia (OTIF) », krok 3',
        'lots_qc_day_checked' => 'gdy każdy zatwierdzony dzień grupy z brakiem (partia surowca bez numeru albo z powodem, partia bez kontroli jakości, partia surowca po DLC lub bez numeru w rejestrze) albo z notatką z tabletu ma wynik (uzupełnione, NC, zaakceptowane) — asystent « Partie i kontrola dnia », krok 3',
        'invoices_ksef_checked' => 'gdy każda wysyłka z problemem (bez faktury WDT, WDT bez daty faktury, faktura niewysłana do KSeF albo bez odpowiedzi KSeF od ksef_day.pending_days dni, odrzucona, błąd wysyłki) ma wynik (wysłane ponownie / poprawione — sprawdzone, w toku, bez faktury — uzasadnione) — asystent « Faktury i KSeF dnia », krok 3',
        'complaints_reviewed' => 'gdy każda reklamacja z sygnałem (bez decyzji od complaints_review.open_days dni, przyjęta bez odpowiedzi do magazynu, przyjęta i niewidziana przez grupę produktu, produkt bez grupy) ma decyzję (odpowiedź / zamknięta — sprawdzone, niezgodność NC, do obserwacji) — asystent « Reklamacje klientów », krok 3',
        'haccp_month_reviewed' => 'gdy każdy element z sygnałem poprzedniego miesiąca (i bieżącego do dziś) — nieobsłużone przerwanie łańcucha chłodniczego, zdarzenie HACCP z wynikiem negatywnym albo audyt poniżej progu, procedura SOP po terminie przeglądu, odchylenie przy wykonaniu SOP, problem z temperaturą w dostawie — ma decyzję (wyjaśnione / zamknięte, działanie korygujące, NC) — asystent « HACCP miesiąca », krok 3',
        'delivery_notices_checked' => 'gdy każdy klient dnia wysyłki (i następnego dnia roboczego, gdy automat wysyła dzień wcześniej) z sygnałem — błąd e-mail albo SMS, brak powiadomienia, brak e-maila i telefonu — ma decyzję (wysłane ponownie — sprawdzone, kontakt telefoniczny, bez powiadomienia — uzasadnione) — asystent « Powiadomienia o dostawie », krok 3',
        'customer_mail_checked' => 'gdy każdy e-mail od klienta (skrzynka z Poczty) bez odpowiedzi po customer_mail.reply_hours h liczonych w dniach roboczych albo z odpowiedzią po terminie wysłaną dziś ma decyzję (odpowiedziane — sprawdzone w skrzynce, bez odpowiedzi — nie wymaga, przekazane), przy działającej skrzynce — asystent « Poczta od klientów », krok 3',
        'pallets_returns_reviewed' => 'gdy każdy klient z sygnałem poprzedniego tygodnia — saldo palet EUR (dostarczone − zwrócone z tabletu kierowcy) powyżej pallets_returns.threshold, brakujące palety bez uzasadnienia, uszkodzona paleta — ma decyzję (przypomnienie / odbiór zaplanowany, OK — saldo uzgodnione, spisane / do faktury) — asystent « Palety zwrotne », krok 3',
        'customers_data_checked' => 'gdy każdy aktywny klient (zamówienie w ostatnich customers_data.active_months miesiącach) z brakiem w karcie — brak e-maila albo e-mail błędny lub z błędem wysyłki, brak lub nieczytelny telefon, brak języka komunikacji, brak terminu płatności, brak NIP albo VAT UE bez prefiksu kraju przy WDT, niepełny adres, brak cennika albo produkt zamówiony bez ceny klienta — ma decyzję (poprawione — karta już bez braków, brak danych od klienta, nie dotyczy) — asystent « Dane klientów », krok 3',
        'launches_reviewed' => 'gdy każdy nowy produkt (wprowadzony albo utworzony w ERP w ostatnich launches_review.months miesiącach) poza celem — koszt surowców powyżej celu, cena poniżej celu, marża poniżej minimum, sprzedaż na sklep / tydzień poniżej oczekiwanej albo brak sprzedaży — albo bez decyzji w module Kierownik produkcji ma decyzję (zostaje, korekta, wycofać) — asystent « Nowe produkty », krok 3',
        'ppwr_month_reviewed' => 'gdy każdy element z sygnałem — opakowanie używane w aktywnym produkcie z brakiem w Rejestrze PPWR (numer, masa, poziom, rodzaj, materiał, recyklat, recykling, minimalizacja, wymiary) albo w danych BDO, aktywny produkt bez kartonu sprzedażowego, PPC albo poprawnej masy, opakowanie lub produkt zmienione od ostatniego przeglądu, wysyłka poprzedniego miesiąca bez kompletnego dowodu opakowań — ma decyzję (uzupełnione — sprawdzone, zapytanie do dostawcy wysłane, nie dotyczy) — asystent « PPWR i opakowania », krok 3',
        'nc_reviewed' => 'gdy niezgodności miesiąca są przejrzane, każda z działaniem (asystent, krok 3)',
        'material_costs_reviewed' => 'gdy decyzje o surowcach miesiąca są zapisane (asystent kosztów surowców)',
        'yield_reviewed' => 'gdy przegląd wydajności i strat kwartału jest zatwierdzony (asystent)',
        'sop_daily' => 'gdy dziś zatwierdzono wersję procedury SOP — poprawionej albo nowej (Procedury SOP, krok « Zatwierdź »)',
        'samples_done' => 'gdy wszystkie próbki dnia są odłożone i zeskanowane albo dzień zaliczony bez próbek (asystent « Próbki produkcji »)',
        'time_day_validated' => 'gdy dzień jest zatwierdzony w Czasie pracy (odbicia, powody nadgodzin i nieobecności)',
        'time_month_closed' => 'gdy poprzedni miesiąc jest zamknięty w Czasie pracy › Miesiąc (karty czasu pracy)',
    ];

    private const MONTH_STEPS = ['cartons' => 'Kartony', 'staff' => 'Personel', 'media' => 'Media', 'fixed' => 'Koszty stałe i logistyka',
        'inventory' => 'Inwentaryzacja', 'recalc' => 'Przeliczenia', 'manager' => 'Kierownik produkcji', 'close' => 'Zamknięcie i raport'];
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    /** Zamknięcie miesiąca (dużo zależności) — tylko gdy trzeba pokazać krok. */
    public function __construct(private TaskWizardRepository $repo, private ContainerInterface $container)
    {
    }

    /** @return array{code:string, label:string, link:string, event:string, steps:int}|null */
    public static function info(?string $code): ?array
    {
        if ($code === null || !isset(self::CATALOG[$code])) return null;
        [$label, $link, $event, $steps] = self::CATALOG[$code];
        return ['code' => $code, 'label' => $label, 'link' => $link, 'event' => $event, 'steps' => $steps];
    }

    /** Kod kreatora po linku zadania (strona kreatora). */
    public static function byLink(?string $link): ?string
    {
        foreach (self::CATALOG as $code => $w) if ($link !== null && $w[1] === $link) return $code;
        return null;
    }

    /**
     * Stan kreatora w okresie zadania [from, to) (`day` = dzień zadania codziennego, termin dla pozostałych).
     * @return array{done:bool, summary:?string, step:?int, steps:int, step_label:?string}
     */
    public function state(string $code, string $from, string $to, string $day, ?string $positionCode = null): array
    {
        $info = self::info($code) ?? throw new \InvalidArgumentException('Nieznany asystent: ' . $code);
        $out = ['done' => false, 'summary' => null, 'step' => null, 'steps' => $info['steps'], 'step_label' => null];
        $hm = fn(?string $ts) => $ts ? substr($ts, 11, 5) : '';
        switch ($code) {
            case 'forecast_approved':
                if ($r = $this->repo->forecastApproved($from, $to)) {
                    $out = ['done' => true, 'summary' => sprintf('%s kart. · zatwierdzona %s%s', self::num((int) $r['cartons']), $hm($r['approved_at']), $r['by_name'] ? ' — ' . $r['by_name'] : '')] + $out;
                }
                break;
            case 'buy_sent':
                $r = $this->repo->buyRound($from, $to);
                if ($r['sent']) $out = ['done' => true, 'summary' => sprintf('zapytania %s wysłane %s', $r['sent']['code'], $hm($r['sent']['sent_at']))] + $out;
                elseif ($r['draft']) $out = ['step' => 2, 'step_label' => 'Komu — szkic ' . $r['draft']['code']] + $out;
                break;
            case 'inv_checked':
                $r = $this->repo->invChecked($from, $to);
                if ($r['checked'] > 0 && $r['waiting'] === 0) {
                    $out = ['done' => true, 'summary' => $r['checked'] . ' ' . self::pl($r['checked'], 'pozycja sprawdzona', 'pozycje sprawdzone', 'pozycji sprawdzonych')
                        . ($r['disputed'] ? ' · ' . $r['disputed'] . ' sporne' : '')] + $out;
                } elseif ($r['checked'] > 0) {
                    $out = ['step' => 1, 'steps' => 1, 'step_label' => $r['waiting'] . ' ' . self::pl($r['waiting'], 'pozycja czeka', 'pozycje czekają', 'pozycji czeka')] + $out;
                }
                break;
            case 'costs_recalc':
                if ($r = $this->repo->costsRecalc($from, $to)) $out = ['done' => true, 'summary' => 'koszty i marże przeliczone ' . $hm($r['run_at'])] + $out;
                break;
            case 'prices_planned':
                if (($n = $this->repo->pricesPlanned($from, $to)) > 0) $out = ['done' => true, 'summary' => $n . ' ' . self::pl($n, 'publikacja cen', 'publikacje cen', 'publikacji cen')] + $out;
                break;
            case 'week_plan_validated':
                $r = $this->repo->weekPlan($from, $to);
                if ($r['validated']) {
                    $out = ['done' => true, 'summary' => sprintf('plan %s – %s zatwierdzony %s', self::dm($r['validated']['period_start']), self::dm($r['validated']['period_end']), $hm($r['validated']['validated_at']))] + $out;
                } elseif ($r['draft']) {
                    $out = ['step' => 1, 'step_label' => sprintf('propozycja %s – %s', self::dm($r['draft']['period_start']), self::dm($r['draft']['period_end']))] + $out;
                }
                break;
            case 'production_day_closed':
                if (($n = $this->repo->dayClosedGroups($day)) > 0) $out = ['done' => true, 'summary' => $n . ' ' . self::pl($n, 'grupa zatwierdziła dzień', 'grupy zatwierdziły dzień', 'grup zatwierdziło dzień')] + $out;
                break;
            case 'day_shipped':
                $r = $this->repo->dayShipping($day);
                if ($r['pallets'] === 0) $out = ['done' => true, 'summary' => 'brak wysyłek tego dnia'] + $out;
                elseif ($r['waiting'] === 0) $out = ['done' => true, 'summary' => sprintf('%d %s · %d %s z dokumentami', $r['clients'], self::pl($r['clients'], 'klient', 'klientów', 'klientów'), $r['pallets'], self::pl($r['pallets'], 'paleta', 'palety', 'palet'))] + $out;
                elseif ($r['shipped'] > 0) $out = ['step' => 1, 'step_label' => $r['waiting'] . ' ' . self::pl($r['waiting'], 'paleta czeka', 'palety czekają', 'palet czeka')] + $out;
                break;
            case 'logistics_plan_approved':
                if (($n = $this->repo->logisticsApproved($from, $to)) > 0) $out = ['done' => true, 'summary' => $n . ' ' . self::pl($n, 'tydzień zatwierdzony', 'tygodnie zatwierdzone', 'tygodni zatwierdzonych')] + $out;
                break;
            case 'group_day_closed':
                $group = self::POSITION_GROUP[$positionCode ?? ''] ?? null;
                if ($group === null) break;
                $r = $this->repo->groupDay($day, $group);
                if ($r['closed_at']) $out = ['done' => true, 'summary' => 'dzień zatwierdzony ' . $hm($r['closed_at']) . ($r['lines'] ? ' · ' . $r['lines'] . ' ' . self::pl($r['lines'], 'pozycja', 'pozycje', 'pozycji') : '')] + $out;
                elseif ($r['lines'] > 0) $out = ['step' => 1, 'step_label' => $r['lines'] . ' ' . self::pl($r['lines'], 'pozycja wpisana', 'pozycje wpisane', 'pozycji wpisanych') . ', dzień niezatwierdzony'] + $out;
                break;
            case 'shift_day_validated':
                $r = $this->repo->shiftDay($day);
                if ($r['groups'] > 0 && $r['closed'] >= $r['groups']) $out = ['done' => true, 'summary' => $r['groups'] . ' ' . self::pl($r['groups'], 'grupa zatwierdziła', 'grupy zatwierdziły', 'grup zatwierdziło') . ' dzień'] + $out;
                elseif ($r['groups'] > 0) $out = ['step' => $r['closed'] + 1, 'steps' => $r['groups'], 'step_label' => $r['closed'] . ' z ' . $r['groups'] . ' grup zatwierdziło'] + $out;
                break;
            case 'packing_done':
                $r = $this->repo->packingDay($day);
                if ($r['products'] === 0 && $r['pallets'] === 0) $out = ['done' => true, 'summary' => 'brak wysyłek tego dnia'] + $out;
                elseif ($r['products'] > 0 && $r['done'] >= $r['products']) $out = ['done' => true, 'summary' => $r['products'] . ' ' . self::pl($r['products'], 'produkt', 'produkty', 'produktów') . ' · ' . self::num($r['packed']) . ' szt. spakowane'] + $out;
                elseif ($r['packed'] > 0) $out = ['step' => 1, 'step_label' => self::num($r['packed']) . ' z ' . self::num($r['base']) . ' szt. spakowane'] + $out;
                break;
            case 'orders_transport':
                $n = $this->container->get(\App\Services\Logistics\TransportWizardService::class)->uncovered();
                if ($n === 0) $out = ['done' => true, 'summary' => 'każda wysyłka dziś i jutro ma transport'] + $out;
                else $out['step_label'] = $n . ' ' . self::pl($n, 'klient', 'klientów', 'klientów') . ' bez transportu';
                break;
            case 'route_done':
                $r = $this->repo->routeDay($day);
                if ($r['runs'] === 0 && $r['pallets'] === 0) $out = ['done' => true, 'summary' => 'brak trasy tego dnia'] + $out;
                elseif ($r['runs'] > 0 && $r['finished'] >= $r['runs']) $out = ['done' => true, 'summary' => $r['delivered'] . ' ' . self::pl($r['delivered'], 'dostawa', 'dostawy', 'dostaw') . ' · trasa zakończona ' . $hm($r['last'])
                    . ($r['problems'] ? ' · ' . $r['problems'] . ' ' . self::pl($r['problems'], 'problem', 'problemy', 'problemów') : '')] + $out;
                elseif ($r['started'] > 0) $out = ['step' => 1, 'step_label' => $r['delivered'] . ' ' . self::pl($r['delivered'], 'dostawa potwierdzona', 'dostawy potwierdzone', 'dostaw potwierdzonych') . ', trasa w toku'] + $out;
                break;
            case 'receiving_checked':
                $r = $this->repo->receivingDay($day);
                if ($r['due'] === 0) $out = ['done' => true, 'summary' => 'brak dostaw tego dnia'] + $out;
                elseif ($r['checked'] >= $r['due']) $out = ['done' => true, 'summary' => $r['due'] . ' ' . self::pl($r['due'], 'dostawa', 'dostawy', 'dostaw') . ' · ' . $r['ok'] . ' OK · ' . $r['nc'] . ' NC'
                    . ($r['missing'] ? ' · ' . $r['missing'] . ' nie ' . self::pl($r['missing'], 'dotarła', 'dotarły', 'dotarło') : '')] + $out;
                elseif ($r['checked'] > 0) $out = ['step' => 2, 'step_label' => $r['checked'] . ' z ' . $r['due'] . ' dostaw sprawdzone'] + $out;
                break;
            case 'payments_reviewed':
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->paymentsRecorded($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'wpłata zapisana', 'wpłaty zapisane', 'wpłat zapisanych')] + $out;
                break;
            case 'tomorrow_sent':
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                break;
            case 'orders_day_checked':
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->orderChecks($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'zamówienie wyjaśnione', 'zamówienia wyjaśnione', 'zamówień wyjaśnionych')] + $out;
                break;
            case 'customers_ordered_checked':
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->noOrderResults($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'klient wyjaśniony', 'klientów wyjaśnionych', 'klientów wyjaśnionych')] + $out;
                break;
            case 'supplements_day_checked':
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->supplementChecks($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'domówienie wyjaśnione', 'domówienia wyjaśnione', 'domówień wyjaśnionych')] + $out;
                break;
            case 'deliveries_delays_checked':
                // [374] Codziennie : zakończenie dnia ; w toku = decyzje zapisane dziś.
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->deliveryDelayChecks($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'dostawa wyjaśniona', 'dostawy wyjaśnione', 'dostaw wyjaśnionych')] + $out;
                break;
            case 'liquidity_checked':
                // [373] Co tydzień : zakończenie w okresie zadania ; w toku = saldo wpisane albo decyzje w tygodniu.
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($p = $this->repo->liquidityProgress(\App\Services\Organization\Wizards\LiquidityCheckService::monday(substr($from, 0, 10))))['balance'] || $p['decisions'] > 0) {
                    $out = ['step' => 2, 'step_label' => ($p['balance'] ? 'saldo wpisane' : 'saldo niewpisane') . ' · ' . $p['decisions'] . ' ' . self::pl($p['decisions'], 'decyzja', 'decyzje', 'decyzji')] + $out;
                }
                break;
            case 'otif_week_reviewed':
                // [375] Co tydzień : zakończenie w okresie zadania ; w toku = działania zapisane dla ocenianego tygodnia (poprzedni tydzień).
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->otifActions(\App\Services\Organization\Wizards\OtifWeekService::reviewedWeek(substr($from, 0, 10)))) > 0) {
                    $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'przyczyna z działaniem', 'przyczyny z działaniem', 'przyczyn z działaniem')] + $out;
                }
                break;
            case 'lots_qc_day_checked':
                // [377] Codziennie : zakończenie dnia ; w toku = wyniki zapisane dziś.
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->lotsQcChecks($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'dzień grupy wyjaśniony', 'dni grup wyjaśnione', 'dni grup wyjaśnionych')] + $out;
                break;
            case 'invoices_ksef_checked':
                // [378] Codziennie : zakończenie dnia ; w toku = wyniki zapisane dziś.
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->invoiceKsefChecks($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'dokument wyjaśniony', 'dokumenty wyjaśnione', 'dokumentów wyjaśnionych')] + $out;
                break;
            case 'complaints_reviewed':
                // [379] Co tydzień : zakończenie w okresie zadania ; w toku = decyzje zapisane dla ocenianego tygodnia (poprzedni tydzień).
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->complaintReviews(\App\Services\Organization\Wizards\ComplaintsReviewService::reviewedWeek(substr($from, 0, 10)))) > 0) {
                    $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'reklamacja wyjaśniona', 'reklamacje wyjaśnione', 'reklamacji wyjaśnionych')] + $out;
                }
                break;
            case 'haccp_month_reviewed':
                // [380] Co miesiąc : zakończenie w okresie zadania ; w toku = decyzje zapisane dla ocenianego miesiąca (poprzedni miesiąc).
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->haccpMonthReviews(\App\Services\Organization\Wizards\HaccpMonthService::reviewedMonth(substr($from, 0, 10)))) > 0) {
                    $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'element wyjaśniony', 'elementy wyjaśnione', 'elementów wyjaśnionych')] + $out;
                }
                break;
            case 'launches_reviewed':
                // [384] Co miesiąc : zakończenie w okresie zadania ; w toku = decyzje zapisane w miesiącu kontroli (pierwszy dzień okresu).
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->launchReviews(\App\Services\Organization\Wizards\LaunchesReviewService::reviewMonth(substr($from, 0, 10)))) > 0) {
                    $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'produkt wyjaśniony', 'produkty wyjaśnione', 'produktów wyjaśnionych')] + $out;
                }
                break;
            case 'ppwr_month_reviewed':
                // [397] Co miesiąc : zakończenie w okresie zadania ; w toku = decyzje zapisane w miesiącu kontroli (pierwszy dzień okresu).
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->ppwrMonthReviews(\App\Services\Organization\Wizards\PpwrMonthService::reviewMonth(substr($from, 0, 10)))) > 0) {
                    $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'element z decyzją', 'elementy z decyzją', 'elementów z decyzją')] + $out;
                }
                break;
            case 'customers_data_checked':
                // [385] Co miesiąc : zakończenie w okresie zadania ; w toku = decyzje zapisane w miesiącu kontroli (pierwszy dzień okresu).
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->customersDataChecks(\App\Services\Organization\Wizards\CustomersDataCheckService::reviewMonth(substr($from, 0, 10)))) > 0) {
                    $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'klient wyjaśniony', 'klienci wyjaśnieni', 'klientów wyjaśnionych')] + $out;
                }
                break;
            case 'delivery_notices_checked':
                // [382] Codziennie : zakończenie dnia ; w toku = decyzje zapisane dziś.
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->deliveryNoticeChecks($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'klient wyjaśniony', 'klienci wyjaśnieni', 'klientów wyjaśnionych')] + $out;
                break;
            case 'customer_mail_checked':
                // [383] Codziennie : zakończenie dnia ; w toku = decyzje zapisane dziś.
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->customerMailChecks($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'e-mail wyjaśniony', 'e-maile wyjaśnione', 'e-maili wyjaśnionych')] + $out;
                break;
            case 'pallets_returns_reviewed':
                // [381] Co tydzień : zakończenie w okresie zadania ; w toku = decyzje zapisane dla ocenianego tygodnia (poprzedni tydzień).
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->palletReturnReviews(\App\Services\Organization\Wizards\PalletsReturnsService::reviewedWeek(substr($from, 0, 10)))) > 0) {
                    $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'klient wyjaśniony', 'klienci wyjaśnieni', 'klientów wyjaśnionych')] + $out;
                }
                break;
            case 'sop_daily':
                $d = $this->container->get(\App\Services\Workshop\Production\SopService::class)->daily($day);
                if ($d['done']) $out = ['done' => true, 'summary' => $d['summary']] + $out;
                break;
            case 'samples_done':
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->samplesDay($day)) && $n['total'] > 0) $out = ['step' => $n['stored'] > 0 ? 3 : 2, 'step_label' => $n['stored'] . ' z ' . $n['total'] . ' próbek odłożone'] + $out;
                break;
            case 'time_day_validated':
                if ($r = $this->repo->timeDay($day)) $out = ['done' => true, 'summary' => 'zatwierdzony ' . $hm($r['validated_at']) . ($r['summary'] ? ' · ' . $r['summary'] : '')] + $out;
                break;
            case 'time_month_closed':
                $ym = date('Y-m', strtotime(substr($from, 0, 7) . '-01 -1 month'));
                if ($r = $this->repo->timeMonth($ym)) $out = ['done' => true, 'summary' => self::monthLabel($ym) . ' zamknięty ' . date('d.m', strtotime((string) $r['closed_at']))] + $out;
                break;
            case 'nc_reviewed':
            case 'material_costs_reviewed':
            case 'price_path_done':
            case 'yield_reviewed':
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                break;
            case 'suppliers_reviewed':
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->reviewsSaved($from, $to)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'dostawca oceniony', 'dostawcy ocenieni', 'dostawców ocenionych')] + $out;
                break;
            case 'month_closed':
                $ym = date('Y-m', strtotime(substr($from, 0, 7) . '-01 -1 month'));
                $m = $this->repo->costMonth((int) substr($ym, 0, 4), (int) substr($ym, 5, 2));
                if ($m && $m['status'] === 'LOCKED') {
                    $out = ['done' => true, 'summary' => self::monthLabel($ym) . ' zamknięty'] + $out;
                } elseif ($m) {
                    try {
                        $s = $this->container->get(MonthCloseService::class)->status($ym);
                        $next = $s['next'] ?? null;
                        $out = ['step' => min(8, (int) $s['done'] + 1), 'step_label' => ($next ? self::MONTH_STEPS[$next] ?? $next : 'Zamknięcie') . ' — ' . self::monthLabel($ym)] + $out;
                    } catch (\Throwable) {
                        $out = ['step' => 1, 'step_label' => self::monthLabel($ym)] + $out;
                    }
                }
                break;
            case 'services_closed':
                $ym = date('Y-m', strtotime(substr($from, 0, 7) . '-01 -1 month'));
                if ($r = $this->repo->servicesClosed($ym)) $out = ['done' => true, 'summary' => self::monthLabel($ym) . ' · ' . self::num((int) round((float) $r['total'])) . ' zł'] + $out;
                break;
        }
        return $out;
    }

    public static function monthLabel(string $ym): string
    {
        return self::MONTHS[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
    }

    private static function dm(?string $d): string
    {
        return $d ? substr($d, 8, 2) . '.' . substr($d, 5, 2) : '—';
    }

    private static function num(int $v): string
    {
        return number_format($v, 0, ',', "\u{00A0}");
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
