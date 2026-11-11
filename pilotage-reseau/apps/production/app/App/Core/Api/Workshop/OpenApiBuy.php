<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Services\Workshop\Supply\BuyService;

/**
 * OpenAPI : Zakupy tygodnia — co kupić (stan → produkcja → surowce, zapas 0 / 5 / 10 %), zapytania ofertowe do 1–3 dostawców
 * na pozycję (link bez logowania), oferty z minimum zamówienia, zamówienie u wybranych, wyniki operatora i zespołu.
 */
final class OpenApiBuy
{
    public static function operations(): array
    {
        $t = 'supply';
        $units = 'Quantités en unité de base (g, ml, szt) ; unit / factor donnent l\'unité affichée (kg, l…) ; prix par unité de base.';
        return [
            'GET /supply/buy/week' => ['tag' => $t, 'id' => 'getBuyWeek', 'summary' => 'Zakupy tygodnia : co kupić / Achats de la semaine : quoi acheter', 'response' => 'BuyWeek', 'errors' => [422],
                'query' => ['date' => ['type' => 'string', 'format' => 'date', 'description' => 'un jour de la semaine de production (défaut : dans 7 jours)'],
                    'safety' => ['type' => 'integer', 'enum' => BuyService::SAFETY, 'description' => 'réserve en % du besoin (défaut 5)']],
                'description' => "Semaine de production : produits à faire (commandes + réapprovisionnement des stocks, en cartons) ; achats de la semaine s'il y en a (round), sinon proposition : "
                    . "besoin × (1 + réserve) contre stock + en commande, mode stock (jusqu'au max quand on passe sous le min) ou commande, arrondi aux emballages entiers ; dernier prix le moins cher et son fournisseur ; arrivée au prochain jour de livraison habituel. " . $units],
            'POST /supply/buy/rounds' => ['tag' => $t, 'id' => 'createBuyRound', 'summary' => 'Krok 1 : utwórz zakupy tygodnia / Étape 1 : créer les achats', 'body' => 'BuyRoundCreateInput', 'response' => 'BuyRound', 'ok' => 201, 'errors' => [400, 404, 409, 422],
                'description' => "Brouillon avec les lignes choisies ; conditions par défaut : paiement 7 jours, livraison chez nous, réponse sous buy.reply_hours heures. BUY_ROUND_EXISTS (409) : achats déjà ouverts pour la semaine. " . $units],
            'GET /supply/buy/rounds/{id:\d+}' => ['tag' => $t, 'id' => 'getBuyRound', 'summary' => 'Zakupy : pozycje, zapytania, oferty / Achats : lignes, demandes, offres', 'response' => 'BuyRound', 'errors' => [404],
                'description' => "Par ligne : fournisseurs demandés (ou fournisseurs de la ligne, 3 au plus), candidats (fournisseurs de la ligne, historique de prix, même catégorie), offres triées par prix, "
                    . "best_offer_id = la moins chère dont le minimum de commande est atteint, hint = la moins chère sous le minimum (quantité pour l'atteindre, semaines couvertes, gain), décision et commande fournisseur."],
            'PATCH /supply/buy/rounds/{id:\d+}' => ['tag' => $t, 'id' => 'updateBuyRound', 'summary' => 'Krok 1–2 : pozycje, dostawcy (1–3), warunki / Lignes, fournisseurs (1 à 3), conditions', 'body' => 'BuyRoundUpdateInput', 'response' => 'BuyRound', 'errors' => [400, 404, 409, 422],
                'description' => "Brouillon seulement (BUY_ROUND_NOT_DRAFT, 409). suppliers : {id de ligne → 1 à 3 fournisseurs} — une demande par fournisseur avec ses lignes (le lien reste le même). "
                    . "Conditions : paiement 7 / 14 / 30 jours ou proforma, livraison ou enlèvement, livraison au plus tard, réponse avant, prix pour le double de la quantité."],
            'DELETE /supply/buy/rounds/{id:\d+}' => ['tag' => $t, 'id' => 'deleteBuyRound', 'summary' => 'Usuń szkic zakupów / Supprimer le brouillon', 'response' => 'SupplierOrderDeleted', 'errors' => [404, 409]],
            'GET /supply/buy/rounds/{id:\d+}/preview' => ['tag' => $t, 'id' => 'previewBuyRequest', 'summary' => 'Krok 3 : podgląd e-maila do dostawcy / Aperçu du mail au fournisseur', 'response' => 'BuyPreview', 'errors' => [404, 422],
                'query' => ['supplier' => ['type' => 'integer', 'minimum' => 1, 'description' => 'fournisseur de la demande']],
                'description' => 'Modèle buy_request dans la langue du fournisseur (pl, fr, nl, en ; repli pl) : ses lignes seulement, conditions, lien pour saisir les prix sans compte.'],
            'POST /supply/buy/rounds/{id:\d+}/send' => ['tag' => $t, 'id' => 'sendBuyRound', 'summary' => 'Krok 3 : wyślij zapytania / Envoyer les demandes', 'response' => 'BuyRound', 'errors' => [404, 409, 422],
                'description' => "Un e-mail par fournisseur (file de la Communication). BUY_NO_LINES, BUY_LINE_NO_SUPPLIER (une ligne sans fournisseur), BUY_SUPPLIER_NO_EMAIL (422). "
                    . "Les fournisseurs demandés deviennent fournisseurs de la ligne (jusqu'à 3)."],
            'POST /supply/buy/rounds/{id:\d+}/remind' => ['tag' => $t, 'id' => 'remindBuyRound', 'summary' => 'Przypomnij dostawcom bez odpowiedzi / Relancer les fournisseurs sans réponse', 'response' => 'BuyRound', 'errors' => [404, 409],
                'description' => 'Même lien ; reminded = nombre de relances. BUY_ROUND_NOT_SENT (409).'],
            'POST /supply/buy/rounds/{id:\d+}/offers' => ['tag' => $t, 'id' => 'addBuyOffer', 'summary' => 'Wpisz ofertę (e-mail, telefon) / Saisir une offre', 'body' => 'BuyOfferInput', 'response' => 'BuyRound', 'ok' => 201, 'errors' => [400, 404, 409, 422],
                'description' => "Prix par unité affichée (zł/kg…), minimum de commande (quantité dans l'unité affichée, montant en zł ou texte, ex. « 1 paleta »), date de livraison. "
                    . "Une offre par fournisseur et ligne (une nouvelle saisie la corrige). BUY_LINE_ORDERED : ligne déjà commandée."],
            'POST /supply/buy/rounds/{id:\d+}/order' => ['tag' => $t, 'id' => 'orderBuyRound', 'summary' => 'Zamów u wybranych / Commander chez les retenus', 'body' => 'BuyOrderInput', 'response' => 'BuyOrderResult', 'ok' => 201, 'errors' => [400, 404, 409, 422],
                'description' => "Par ligne : offer_id (ou null = dernier fournisseur au dernier prix) et quantité. Une commande fournisseur par fournisseur (conditions dans la note) envoyée par e-mail (modèle supplier_order) ; "
                    . "sans adresse elle reste en brouillon dans Zamówienia. Décision : gain = (dernier prix − prix) × quantité. BUY_BELOW_MINIMUM (409) : quantité sous le minimum de l'offre ; BUY_NO_PRICE (422)."],
            'POST /supply/buy/rounds/{id:\d+}/close' => ['tag' => $t, 'id' => 'closeBuyRound', 'summary' => 'Zakończ zakupy / Clôturer les achats', 'response' => 'BuyRound', 'errors' => [404, 409],
                'description' => 'Le lien des fournisseurs se ferme ; les lignes non commandées restent sans décision.'],
            'GET /supply/buy/results' => ['tag' => $t, 'id' => 'getBuyResults', 'summary' => 'Wyniki zakupów : operator i zespół / Résultats : opérateur et équipe', 'response' => 'BuyResults', 'errors' => [422],
                'query' => ['period' => ['type' => 'string', 'enum' => BuyService::PERIODS, 'description' => 'défaut : month (mois, trimestre, année en cours)']],
                'description' => "Achats envoyés, e-mails (demandes, relances, commandes), offres, décisions, réussies (moins cher que le dernier prix), gain, marge (gain ÷ ventes WDT en zł, en points), "
                    . "heures dans l'assistant (enregistreur d'activité) × coût horaire (Aktywność) = coût, ROI = gain ÷ coût. team : toute l'équipe avec la permission prod.finance.read (directeur de site)."],
            'GET /supply/buy/suppliers' => ['tag' => $t, 'id' => 'listBuyMaterialSuppliers', 'summary' => 'Dostawcy pozycji (1–3) / Fournisseurs par article (1 à 3)', 'response' => 'BuyMaterialSuppliers',
                'query' => ['missing' => ['type' => 'string', 'enum' => ['0', '1'], 'description' => '1 : seulement les articles avec moins de 3 fournisseurs']],
                'description' => 'Articles consommés les buy.supplier_weeks dernières semaines, avec leurs fournisseurs (le moins fourni d\'abord) et les fournisseurs actifs.'],
            'PUT /supply/buy/materials/{id:\d+}/suppliers' => ['tag' => $t, 'id' => 'saveBuyMaterialSuppliers', 'summary' => 'Ustaw dostawców pozycji / Fournisseurs de l\'article', 'body' => 'BuyMaterialSuppliersInput', 'response' => 'BuyMaterialSuppliersSaved', 'errors' => [400, 404, 422],
                'description' => '1 à 3 fournisseurs actifs, dans l\'ordre (BUY_LINE_SUPPLIERS).'],
        ];
    }

    public static function schemas(): array
    {
        $int = ['type' => 'integer'];
        $num = ['type' => 'number'];
        $nNum = ['type' => 'number', 'nullable' => true];
        $nStr = ['type' => 'string', 'nullable' => true];
        $bool = ['type' => 'boolean'];
        $free = ['type' => 'object', 'additionalProperties' => true];
        $supplier = ['type' => 'object', 'properties' => ['id' => $int, 'name' => ['type' => 'string'], 'email' => $nStr, 'active' => $bool]];
        $material = ['type' => 'object', 'properties' => ['id' => $int, 'name' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'factor' => $num, 'base_unit' => ['type' => 'string'],
            'packaging' => $bool, 'category' => $nStr]];
        $last = ['type' => 'object', 'nullable' => true, 'properties' => ['price' => $num, 'supplier' => $supplier + ['nullable' => true], 'day' => $nStr]];
        $offer = ['type' => 'object', 'properties' => ['id' => $int, 'supplier' => $supplier, 'price' => $num, 'price_double' => $nNum, 'min_qty' => $nNum + ['description' => 'unité affichée'],
            'min_value' => $nNum + ['description' => 'zł'], 'min_note' => $nStr, 'delivery_date' => $nStr, 'note' => $nStr, 'status' => ['type' => 'string', 'enum' => ['received', 'accepted', 'rejected']],
            'manual' => $bool, 'below_min' => $bool, 'min_quantity' => $nNum + ['description' => 'quantité pour atteindre le minimum (emballages entiers)']]];
        $line = ['type' => 'object', 'properties' => [
            'id' => $int, 'material' => $material, 'need' => $num, 'stock' => $nNum, 'on_order' => $num, 'quantity' => $num, 'package_size' => $nNum, 'packages' => ['type' => 'integer', 'nullable' => true],
            'last' => $last, 'suppliers' => ['type' => 'array', 'items' => $supplier], 'assigned' => ['type' => 'integer', 'description' => 'fournisseurs de l\'article (objectif 3)'],
            'candidates' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => ['type' => 'string'], 'email' => $nStr, 'active' => $bool, 'last_price' => $nNum,
                'last_day' => $nStr, 'source' => ['type' => 'string', 'enum' => ['assigned', 'history', 'category']]]]],
            'offers' => ['type' => 'array', 'items' => $offer], 'best_offer_id' => ['type' => 'integer', 'nullable' => true],
            'hint' => ['type' => 'object', 'nullable' => true, 'properties' => ['offer_id' => $int, 'quantity' => $nNum, 'extra' => $nNum, 'weeks' => $nNum, 'saving' => $nNum]],
            'saving' => $nNum, 'order' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true]]];
        $round = ['type' => 'object', 'properties' => [
            'id' => $int, 'code' => ['type' => 'string', 'example' => 'ZT-2026-44'], 'status' => ['type' => 'string', 'enum' => BuyService::STATUSES],
            'week_start' => ['type' => 'string', 'format' => 'date'], 'week_end' => ['type' => 'string', 'format' => 'date'], 'safety_pct' => ['type' => 'integer', 'enum' => BuyService::SAFETY],
            'pay_terms' => ['type' => 'string', 'enum' => BuyService::PAY], 'delivery' => ['type' => 'string', 'enum' => BuyService::DELIVERY], 'deliver_by' => $nStr, 'reply_by' => $nStr, 'ask_double' => $bool,
            'user' => $free, 'created_at' => ['type' => 'string'], 'sent_at' => $nStr, 'ordered_at' => $nStr, 'closed_at' => $nStr,
            'lines' => ['type' => 'array', 'items' => $line],
            'requests' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'supplier' => $supplier, 'email' => $nStr, 'language' => ['type' => 'string'], 'lines' => $int,
                'sent_at' => $nStr, 'opened_at' => $nStr, 'responded_at' => $nStr, 'reminded_at' => $nStr, 'link' => $nStr]]],
            'totals' => ['type' => 'object', 'properties' => ['lines' => $int, 'value' => $num, 'potential' => $num, 'savings' => $num, 'ordered' => $int, 'with_offers' => $int, 'requests' => $int, 'responded' => $int]],
            'can_write' => $bool, 'reminded' => ['type' => 'integer', 'description' => 'POST …/remind seulement']]];
        $resultRow = ['type' => 'object', 'properties' => ['user' => $free, 'rounds' => $int, 'emails' => $int, 'offers' => $int, 'decisions' => $int, 'successful' => $int, 'savings' => $num,
            'margin_pp' => $nNum, 'hours' => $num, 'hourly_cost' => $nNum, 'cost' => $nNum, 'roi' => $nNum]];
        $offerFields = ['price' => ['type' => 'number', 'exclusiveMinimum' => true, 'minimum' => 0, 'description' => 'zł par unité affichée (kg, l, szt)'],
            'price_double' => ['type' => 'number', 'nullable' => true, 'description' => 'zł par unité affichée pour le double de la quantité'],
            'min_qty' => ['type' => 'number', 'nullable' => true, 'description' => 'minimum de commande, unité affichée'], 'min_value' => ['type' => 'number', 'nullable' => true, 'description' => 'minimum de commande, zł'],
            'min_note' => ['type' => 'string', 'maxLength' => 60, 'nullable' => true, 'example' => '1 paleta'], 'delivery_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
            'note' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true]];
        $lineInput = ['type' => 'array', 'maxItems' => 200, 'items' => ['type' => 'object', 'required' => ['material_id', 'quantity'], 'properties' => ['material_id' => $int, 'quantity' => ['type' => 'number', 'description' => 'unité de base']]]];
        return [
            'BuyWeek' => ['type' => 'object', 'properties' => [
                'week' => $free, 'weeks' => ['type' => 'array', 'items' => $free], 'can_write' => $bool,
                'missing_suppliers' => ['type' => 'integer', 'description' => 'articles consommés avec moins de 3 fournisseurs'],
                'products' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => ['type' => 'string'], 'to_produce' => $num, 'stock' => $nNum, 'min' => $nNum, 'refill' => $num, 'orders' => $num],
                    'description' => 'en cartons']],
                'round' => ['allOf' => [['$ref' => '#/components/schemas/BuyRound']], 'nullable' => true],
                'proposal' => ['type' => 'object', 'nullable' => true, 'properties' => ['safety_pct' => $int, 'totals' => $free, 'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'material' => $free, 'need' => $num, 'stock' => $nNum, 'on_order' => $num, 'quantity' => $num, 'packages' => ['type' => 'integer', 'nullable' => true], 'package_size' => $nNum,
                    'last' => ['type' => 'object', 'nullable' => true, 'properties' => ['price' => $num, 'supplier_id' => $int, 'supplier' => $nStr, 'day' => ['type' => 'string']]], 'selected' => $bool, 'value' => $nNum, 'arrives' => $nStr]]]]],
            ]],
            'BuyRound' => $round,
            'BuyRoundCreateInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['week_start', 'lines'], 'properties' => [
                'week_start' => ['type' => 'string', 'format' => 'date', 'description' => 'un jour de la semaine de production'], 'safety_pct' => ['type' => 'integer', 'enum' => BuyService::SAFETY], 'lines' => $lineInput]],
            'BuyRoundUpdateInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'safety_pct' => ['type' => 'integer', 'enum' => BuyService::SAFETY], 'lines' => $lineInput, 'pay_terms' => ['type' => 'string', 'enum' => BuyService::PAY],
                'delivery' => ['type' => 'string', 'enum' => BuyService::DELIVERY], 'deliver_by' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'reply_by' => ['type' => 'string', 'example' => '2026-10-28 12:00'], 'ask_double' => $bool,
                'suppliers' => ['type' => 'object', 'description' => 'id de ligne → 1 à 3 id de fournisseurs', 'additionalProperties' => ['type' => 'array', 'minItems' => 1, 'maxItems' => BuyService::MAX_SUPPLIERS, 'items' => $int]]]],
            'BuyPreview' => ['type' => 'object', 'properties' => ['supplier' => $free, 'recipient' => $nStr, 'language' => ['type' => 'string'], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string'],
                'link' => ['type' => 'string'], 'template_id' => ['type' => 'integer', 'nullable' => true]]],
            'BuyOfferInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['line_id', 'supplier_id', 'price'], 'properties' => ['line_id' => $int, 'supplier_id' => $int] + $offerFields],
            'BuyOrderInput' => ['type' => 'object', 'required' => ['lines'], 'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['line_id', 'quantity'], 'properties' => [
                'line_id' => $int, 'offer_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'null : dernier fournisseur au dernier prix'], 'quantity' => ['type' => 'number', 'description' => 'unité de base']]]]]],
            'BuyOrderResult' => ['allOf' => [['$ref' => '#/components/schemas/BuyRound'], ['type' => 'object', 'properties' => ['orders' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'id' => $int, 'code' => $nStr, 'supplier' => ['type' => 'string'], 'sent' => ['type' => 'boolean', 'description' => 'false : sans adresse e-mail, brouillon dans Zamówienia']]]]]]]],
            'BuyResults' => ['type' => 'object', 'properties' => ['period' => ['type' => 'string', 'enum' => BuyService::PERIODS], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'revenue' => $num,
                'me' => $resultRow, 'team' => ['type' => 'array', 'nullable' => true, 'items' => $resultRow], 'team_total' => $free + ['nullable' => true],
                'months' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['month' => ['type' => 'string'], 'savings' => $num]]], 'year_savings' => $num]],
            'BuyMaterialSuppliers' => ['type' => 'object', 'properties' => ['weeks' => $int, 'missing' => $int, 'max' => $int, 'can_write' => $bool,
                'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => ['type' => 'string'], 'category' => $nStr, 'packaging' => $bool, 'unit' => ['type' => 'string'],
                    'factor' => $num, 'weekly_use' => $num, 'suppliers' => ['type' => 'array', 'items' => $supplier]]]],
                'suppliers' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => ['type' => 'string'], 'email' => $nStr]]]]],
            'BuyMaterialSuppliersInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['supplier_ids'], 'properties' => [
                'supplier_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => BuyService::MAX_SUPPLIERS, 'items' => $int]]],
            'BuyMaterialSuppliersSaved' => ['type' => 'object', 'properties' => ['id' => $int, 'suppliers' => ['type' => 'array', 'items' => $supplier]]],
        ];
    }
}
