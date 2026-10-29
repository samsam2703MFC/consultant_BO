<?php

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;

/**
 * Ustawienia (audyt 2, N6) : jedna strona z kartami zamiast siedmiu podmenu w railu — system, organizacja, klienci
 * i dokumenty, produkty, pracownia, logistyka, zakupy, alerty i cele, narzędzia administratora. Każda pozycja to
 * istniejąca strona ; widok pokazuje tylko strony, do których funkcja użytkownika ma dostęp (can_see).
 */
class SettingsHubController extends BaseController
{
    /** karta => [ikona, opis, [ścieżka, etykieta, ikona, opis]] */
    private const CARDS = [
        'System' => ['bi-gear', 'Poczta, stopki e-maili, KSeF, dostęp API', [
            ['/communication/email-settings', 'Konto wysyłkowe ERP', 'bi-envelope-at', 'Serwer poczty, z którego wychodzą dokumenty i przypomnienia'],
            ['/settings/mail-footers', 'Stopki e-maili i tłumacz', 'bi-translate', 'Stopka firmy w językach klientów, tłumacz DeepL (Poczta, Komunikacja)'],
            ['/settings/ksef', 'KSeF', 'bi-file-earmark-check', 'Certyfikat, środowisko, wysyłka faktur'],
            ['/devices', 'Urządzenia (dostęp API)', 'bi-display', 'Skanery i aplikacje z kluczem API'],
            ['/settings/panel-api', 'API cen w sklepach', 'bi-shop', 'Konto panelu franczyzy (ceny w sklepach w Belgii)'],
            ['/aliases', 'Aliasy', 'bi-book', 'Nazwy zamienne produktów i surowców'],
        ]],
        'Organizacja' => ['bi-diagram-3', 'Kto co robi i co widzi', [
            ['/organization/positions', 'Funkcje i zadania', 'bi-diagram-3', 'Funkcje (stanowiska pracy biura), karty funkcji, zadania cykliczne'],
            ['/organization/access', 'Uprawnienia funkcji', 'bi-shield-lock', 'Które strony widzi każda funkcja'],
            ['/users', 'Użytkownicy systemu', 'bi-people', 'Konta ERP, funkcja, hasło'],
        ]],
        'Klienci i dokumenty' => ['bi-file-earmark-richtext', 'Ceny, sieci, wzory i dystrybucja dokumentów', [
            ['/clients?type=active', 'Klienci', 'bi-people', 'Karta klienta, język, dostawa'],
            ['/price-lists/general', 'Cennik ogólny', 'bi-tags-fill', 'Ceny HTVA'],
            ['/client-franchise-networks', 'Sieci franczyzowe', 'bi-diagram-3-fill', 'Sieci i ich sklepy'],
            ['/communication/document-templates', 'Wzory dokumentów i e-maili', 'bi-file-earmark-pdf', 'PDF i szablony e-mail w językach klientów'],
            ['/communication/distribution', 'Dystrybucja dokumentów', 'bi-diagram-3', 'Kto dostaje jakie dokumenty i kiedy'],
        ]],
        'Produkty' => ['bi-box-seam', 'Kategorie, marże docelowe, etykiety', [
            ['/workshop/products#/categories', 'Kategorie produktów i marże docelowe', 'bi-tags', 'Kategorie, cele marży'],
            ['/settings/languages', 'Języki etykiet', 'bi-fonts', 'Języki tłumaczeń na etykietach'],
        ]],
        'Pracownia' => ['bi-gear-wide-connected', 'Tablety, stanowiska robocze, dane pracowni', [
            ['/workshop/execution', 'Tablety, PIN i czas pracy', 'bi-tablet', 'Tablety grup, kody PIN, pytania przy wejściu'],
            ['/workshop/workstations', 'Stanowiska robocze i coboty', 'bi-robot', 'Maszyny i stanowiska grup, pojemność, koszt godziny'],
            ['/workshop/data-sheet', 'Tabela do wypełnienia', 'bi-file-earmark-spreadsheet', 'Excel : zmiany, obsada, trasy, zamrażarka → import'],
        ]],
        'Logistyka' => ['bi-signpost-split', 'Trasy, pojazdy, przewoźnicy', [
            ['/logistics/tours', 'Trasy', 'bi-signpost-split', 'Trasy dostaw i klienci na trasie'],
            ['/logistics/vehicles', 'Pojazdy i pojemność', 'bi-truck', 'Ciężarówki, palety, kierowca'],
            ['/product-carriers', 'Przewoźnicy', 'bi-truck', 'Przewoźnicy zewnętrzni'],
        ]],
        'Zakupy' => ['bi-cart', 'Dostawcy i ceny surowców', [
            ['/material-suppliers', 'Dostawcy', 'bi-truck', 'NIP, dni dostaw, język zamówień'],
            ['/materials/prices', 'Ceny surowców', 'bi-currency-euro', 'Ceny i ich historia (oferta, faktura, zapytanie)'],
        ]],
        'Alerty i cele' => ['bi-bell', 'Parametry bez własnej strony', [
            ['/other-settings', 'Pozostałe : zapasy, alerty i terminy', 'bi-sliders', 'Tygodnie zapasu, alerty reklamacji, terminy kosztów'],
            ['/other-settings#params', 'Wszystkie parametry', 'bi-list-check', 'Każdy parametr modułu produkcji (wartość, jednostka, opis)'],
            ['/workshop/manager', 'Kierownik produkcji : cele i premia', 'bi-bullseye', 'Cele miesięczne i premia'],
        ]],
        'Narzędzia administratora' => ['bi-tools', 'Aktywność, licznik stron', [
            ['/organization/activity', 'Aktywność w ERP', 'bi-activity', 'Sesje, strony, czas pracy w ERP'],
            ['/settings/page-usage', 'Licznik stron', 'bi-bar-chart', 'Które strony są używane'],
        ]],
    ];

    public function index()
    {
        $this->view('settings/hub', ['cards' => self::CARDS]);
    }
}
