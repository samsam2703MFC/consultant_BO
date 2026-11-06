<?php
declare(strict_types=1);

namespace App\Controllers\Web\Supply;

use App\Core\Api\Workshop\ApiException;
use App\Core\Web\BaseController;
use App\Services\Workshop\Supply\BuyService;

/**
 * Strona dostawcy z zapytania ofertowego (Zakupy tygodnia) : bez logowania, link z tokenem z e-maila. Dostawca wpisuje
 * cenę, cenę za podwójną ilość, minimum zamówienia (ilość, kwota albo opis) i datę dostawy dla swoich pozycji.
 * Zwykły formularz (bez JavaScriptu) w języku dostawcy (pl, fr, nl, en).
 */
final class OfferController extends BaseController
{
    private const TEXT = [
        'pl' => ['title' => 'Zapytanie ofertowe', 'intro' => 'Prosimy o ceny na poniższe pozycje. Wystarczy wpisać cenę — reszta jest opcjonalna.', 'conditions' => 'Warunki',
            'deadline' => 'Odpowiedź do', 'qty' => 'Ilość', 'pack' => 'opakowanie', 'price' => 'Cena netto zł / %s', 'double' => 'Cena przy podwójnej ilości (zł / %s)',
            'min_qty' => 'Minimum zamówienia (%s)', 'min_value' => 'lub minimum (zł)', 'min_note' => 'Inne minimum / uwagi (np. 1 paleta)', 'delivery' => 'Dostawa możliwa od',
            'send' => 'Wyślij ofertę', 'saved' => 'Dziękujemy, oferta zapisana (%d poz.). Można ją poprawić tym samym linkiem do terminu odpowiedzi.',
            'expired' => 'Termin odpowiedzi minął — link jest zamknięty. W razie pytań prosimy odpowiedzieć na e-mail.', 'unknown' => 'Ten link jest nieprawidłowy.',
            'closed' => 'zamówione', 'errors' => 'Sprawdź pola zaznaczone na czerwono (liczby, data).', 'none' => 'Nie wpisano żadnej ceny.', 'answered' => 'Oferta otrzymana', 'optional' => 'opcjonalnie'],
        'fr' => ['title' => 'Demande d\'offre', 'intro' => 'Merci d\'indiquer vos prix pour les articles ci-dessous. Seul le prix est nécessaire — le reste est facultatif.', 'conditions' => 'Conditions',
            'deadline' => 'Réponse avant le', 'qty' => 'Quantité', 'pack' => 'emballage', 'price' => 'Prix net zł / %s', 'double' => 'Prix pour le double (zł / %s)',
            'min_qty' => 'Minimum de commande (%s)', 'min_value' => 'ou minimum (zł)', 'min_note' => 'Autre minimum / remarque (ex. 1 palette)', 'delivery' => 'Livraison possible dès le',
            'send' => 'Envoyer l\'offre', 'saved' => 'Merci, offre enregistrée (%d art.). Vous pouvez la corriger avec ce même lien jusqu\'à la date de réponse.',
            'expired' => 'Le délai de réponse est passé — le lien est fermé. Pour toute question, répondez simplement à l\'e-mail.', 'unknown' => 'Ce lien n\'est pas valable.',
            'closed' => 'commandé', 'errors' => 'Vérifiez les champs en rouge (nombres, date).', 'none' => 'Aucun prix saisi.', 'answered' => 'Offre reçue', 'optional' => 'facultatif'],
        'nl' => ['title' => 'Offerteaanvraag', 'intro' => 'Graag uw prijzen voor de onderstaande artikelen. Alleen de prijs is nodig — de rest is optioneel.', 'conditions' => 'Voorwaarden',
            'deadline' => 'Antwoord vóór', 'qty' => 'Hoeveelheid', 'pack' => 'verpakking', 'price' => 'Nettoprijs zł / %s', 'double' => 'Prijs bij dubbele hoeveelheid (zł / %s)',
            'min_qty' => 'Minimale bestelling (%s)', 'min_value' => 'of minimum (zł)', 'min_note' => 'Ander minimum / opmerking (bv. 1 pallet)', 'delivery' => 'Levering mogelijk vanaf',
            'send' => 'Offerte versturen', 'saved' => 'Dank u, offerte opgeslagen (%d art.). U kunt ze met dezelfde link aanpassen tot de antwoorddatum.',
            'expired' => 'De antwoordtermijn is verstreken — de link is gesloten. Bij vragen kunt u gewoon op de e-mail antwoorden.', 'unknown' => 'Deze link is ongeldig.',
            'closed' => 'besteld', 'errors' => 'Controleer de rode velden (getallen, datum).', 'none' => 'Geen prijs ingevuld.', 'answered' => 'Offerte ontvangen', 'optional' => 'optioneel'],
        'en' => ['title' => 'Request for quotation', 'intro' => 'Please quote your prices for the items below. Only the price is required — the rest is optional.', 'conditions' => 'Conditions',
            'deadline' => 'Reply by', 'qty' => 'Quantity', 'pack' => 'pack', 'price' => 'Net price PLN / %s', 'double' => 'Price for double quantity (PLN / %s)',
            'min_qty' => 'Minimum order (%s)', 'min_value' => 'or minimum (PLN)', 'min_note' => 'Other minimum / note (e.g. 1 pallet)', 'delivery' => 'Delivery possible from',
            'send' => 'Send quotation', 'saved' => 'Thank you, quotation saved (%d items). You can correct it with the same link until the reply date.',
            'expired' => 'The reply date has passed — the link is closed. For any question, simply reply to the e-mail.', 'unknown' => 'This link is not valid.',
            'closed' => 'ordered', 'errors' => 'Please check the fields in red (numbers, date).', 'none' => 'No price entered.', 'answered' => 'Quotation received', 'optional' => 'optional'],
    ];

    public function __construct(private BuyService $buy)
    {
    }

    public function show(string $token)
    {
        $this->render($token, $this->buy->publicView($token), isset($_GET['saved']) ? max(0, (int) $_GET['saved']) : null, [], null);
    }

    public function submit(string $token)
    {
        $offers = is_array($_POST['offers'] ?? null) ? $_POST['offers'] : [];
        try {
            $result = $this->buy->publicSubmit($token, $offers);
        } catch (ApiException $e) {
            $this->render($token, $this->buy->publicView($token), null, [], $e->errorCode() === 'BUY_LINK_UNKNOWN' ? 'unknown' : 'expired');
            return;
        }
        if ($result['errors']) {
            $this->render($token, $this->buy->publicView($token), null, ['lines' => $result['errors'], 'values' => $offers], $result['saved'] ? null : 'errors');
            return;
        }
        if ($result['saved'] === 0) {
            $this->render($token, $this->buy->publicView($token), null, ['values' => $offers], 'none');
            return;
        }
        header('Location: ' . ROOT . '/offer/' . $token . '?saved=' . $result['saved'], true, 303);
    }

    private function render(string $token, ?array $view, ?int $saved, array $errors, ?string $message): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        if ($view === null) http_response_code(404);
        $lang = $view['lang'] ?? 'pl';
        $t = self::TEXT[$lang] ?? self::TEXT['pl'];
        $this->view('offer/supplier', ['offer' => $view, 'token' => $token, 'lang' => $lang, 't' => $t, 'saved' => $saved,
            'error_lines' => array_map('intval', array_keys($errors['lines'] ?? [])), 'values' => $errors['values'] ?? [], 'message' => $message]);
    }
}
