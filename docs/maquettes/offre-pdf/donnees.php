<?php
declare(strict_types=1);

/**
 * L'OFFRE D'EXEMPLE — ILLUSTRÉE : le client, l'adresse du magasin, les
 * produits et les montants sont inventés. Elle a la forme exacte de ce que
 * lit OfferService::getPDF() : getById($id, true, true) (l'offre, ses
 * products, son client) et ShopRepository::getById() (le magasin).
 *
 *   livraison — un lunch d'entreprise livré, sept lignes dont trois remisées ;
 *   retrait   — la même offre, retirée au comptoir ;
 *   long      — un brunch de 30 lignes : le passage à la deuxième page.
 */

function offre_exemple(string $variante = 'livraison'): array
{
    $tva = 1.06; // alimentation et boissons sans alcool, Belgique
    // [produit, quantité, prix unitaire TVAC, type de remise (1 = %, 2 = montant sur la ligne), valeur]
    $lignes = [
        ['Mini-viennoiseries assorties (croissant, pain au chocolat, pain aux raisins)', 60, 1.20, 1, 10],
        ['Sandwich demi-baguette — jambon-fromage, poulet-curry, végétarien', 40, 4.90, 1, 0],
        ['Wrap saumon fumé et fromage frais', 20, 4.50, 1, 0],
        ['Quiche lorraine individuelle', 24, 3.80, 1, 5],
        ['Tarte aux pommes, 8 parts', 5, 18.00, 2, 6.00],
        ['Plateau de mignardises, 24 pièces', 2, 32.00, 1, 0],
        ["Jus d'orange pressé, 1 L", 8, 4.20, 1, 0],
    ];
    if ($variante === 'long') {
        $lignes = [];
        $carte = [
            ['Croissant au beurre', 1.10], ['Pain au chocolat', 1.30], ['Pain aux raisins', 1.40], ['Couque suisse', 1.60],
            ['Chausson aux pommes', 1.70], ['Mini-croissant', 0.60], ['Mini-pain au chocolat', 0.70], ['Brioche tressée, 500 g', 6.50],
            ['Pain de campagne au levain, 800 g', 4.20], ['Baguette tradition', 1.60], ['Pain aux noix, 400 g', 3.90], ['Pistolet nature', 0.45],
            ['Pistolet aux céréales', 0.55], ['Mini-quiche aux légumes', 1.20], ['Mini-pizza tomate-mozzarella', 1.10], ['Feuilleté saucisse', 1.50],
            ['Wrap poulet-curry', 4.50], ['Wrap végétarien houmous-légumes grillés', 4.50], ['Salade de fruits frais, coupe individuelle', 3.20],
            ['Yaourt, granola maison et fruits rouges', 3.40], ['Tarte au sucre, 8 parts', 16.00], ['Tarte au riz, 8 parts', 17.50],
            ['Cake citron-pavot, tranché', 9.80], ['Cookies chocolat-noisette', 1.40], ['Macarons assortis, boîte de 12', 14.00],
            ['Café, thermos de 3 L', 21.00], ['Thé et infusions, thermos de 3 L', 18.00], ["Jus d'orange pressé, 1 L", 4.20],
            ['Jus de pomme artisanal, 1 L', 3.60], ['Eau pétillante, 1 L', 1.80],
        ];
        foreach ($carte as $i => [$nom, $pu]) {
            $q = $pu > 10 ? 2 + $i % 3 : ($pu > 3 ? 10 + $i % 4 * 5 : 30 + $i % 5 * 10);
            $lignes[] = [$nom, $q, $pu, $i % 7 === 3 ? 2 : 1, $i % 7 === 3 ? 5.00 : ($i % 5 === 0 ? 10 : 0)];
        }
    }

    $products = [];
    foreach ($lignes as $i => [$nom, $q, $pu, $type, $valeur]) {
        $brut = round($q * $pu, 2);
        $final = round($type === 1 ? $brut * (1 - $valeur / 100) : $brut - $valeur, 2);
        $products[] = [
            'id' => 9100 + $i, 'id_offer' => 187, 'product_name' => $nom, 'quantity' => $q,
            'unit_price' => $pu, 'total_price_gross' => $brut,
            'id_discount_type' => $type, 'discount_value' => $valeur,
            'final_total_price' => $final, 'final_total_price_net' => round($final / $tva, 2),
        ];
    }

    $client = [
        'id' => 5120, 'is_b2b' => 1, 'company_name' => 'Studio Marelle SRL', 'tax_number' => 'BE 0712.345.678',
        'firstname' => 'Julie', 'lastname' => 'Lambert',
        'street' => "Zoning de l'Industrie", 'street_number' => '12', 'zip' => '1500', 'city' => 'Halle',
        'phone' => '02 356 12 34', 'email' => 'evenements@studio-marelle.be',
    ];
    $offre = [
        'id' => 187, 'id_shop' => 4, 'id_client' => 5120, 'status' => 0,
        'name' => "Séminaire d'équipe — lunch pour 40 personnes",
        'event_day' => '2026-11-12',
        'in_store_pickup' => 0,
        'delivery_street' => "Zoning de l'Industrie 12", 'delivery_zip' => '1500', 'delivery_city' => 'Halle',
        'delivery_cost' => 15.00,
        'conditions' => "Livraison le jeudi 12 novembre entre 11 h 30 et 11 h 45, à l'accueil (2e étage). Plateaux et thermos repris le lendemain matin. Acompte de 30 % à la confirmation, solde à la livraison.",
        'description' => "Dont 10 sandwichs végétariens, étiquetés. Une personne est allergique aux fruits à coque : prévoir son lunch à part, emballé séparément.",
        'products' => $products,
        'client' => $client,
    ];
    if ($variante === 'retrait') {
        $offre['in_store_pickup'] = 1;
        $offre['delivery_cost'] = 0.00;
        $offre['conditions'] = "Retrait le jeudi 12 novembre à partir de 11 h, au comptoir. Acompte de 30 % à la confirmation, solde au retrait.";
    }
    if ($variante === 'long') {
        $offre['name'] = 'Brunch de mariage — 120 personnes';
        $offre['event_day'] = '2026-12-05';
        $offre['delivery_cost'] = 35.00;
        $offre['conditions'] = "Livraison le samedi 5 décembre à 9 h 30, salle des fêtes. Mise en place par nos soins, reprise du matériel à 15 h. Acompte de 30 % à la confirmation, solde à la livraison.";
    }

    $magasin = [
        'id' => 4, 'name' => 'Atelier by - Halle', 'representative_name' => 'Atelier Halle SRL',
        'street' => 'Basiliekstraat', 'street_num' => '42', 'zip' => '1500', 'city' => 'Halle',
        'tax_number_prefixe' => 'BE', 'tax_number' => '0798.765.432', 'phone' => '02 361 42 42',
        'bank_iban' => 'BE68 5390 0754 7034', 'bank_name' => 'Belfius',
    ];
    return [$offre, $magasin];
}

/**
 * Les libellés : ceux d'aujourd'hui (groupe « offer ») et, plus bas, ceux
 * que les propositions ajoutent — à créer dans les traductions du panel.
 */
function libelles_offre(): array
{
    return [
        // Existants
        'client_information' => 'Informations client', 'tax_number_short' => 'TVA', 'phone_short' => 'Tél.',
        'email' => 'E-mail', 'commercial_offer_no' => 'Offre commerciale n°', 'date' => 'Date',
        'detailed_product_information' => 'Détail des produits', 'product' => 'Produit', 'quantity_short' => 'Qté',
        'unit_price_short' => 'P.U.', 'total' => 'Total', 'gross' => 'Brut', 'discount' => 'Remise',
        'after_discount' => 'Après remise', 'net' => 'Net', 'total_general' => 'Total général',
        'delivery_information' => 'Informations de livraison', 'in_store_pickup' => 'Retrait en magasin',
        'delivery' => 'Livraison', 'delivery_address' => 'Adresse de livraison', 'shipping_cost' => 'Frais de livraison',
        'conditions' => 'Conditions', 'comment' => 'Commentaire',
        'general_terms_available_upon_request' => 'Conditions générales de vente disponibles sur demande.',
        'document_generated_on' => 'Document généré le', 'offer' => 'Offre',
        // Nouveaux
        'commercial_offer' => 'Offre commerciale', 'offer_no' => 'N°', 'issued_on' => 'Émise le',
        'from' => 'De', 'for' => 'Pour', 'attention_of' => "À l'attention de", 'prepared_by' => 'Préparé par',
        'event_date' => "Date de l'événement", 'the_day' => 'Le jour J', 'unit_price' => 'Prix unitaire',
        'before_discount' => 'Avant remise', 'excl_vat' => 'HTVA', 'incl_vat' => 'TVAC', 'vat' => 'TVA',
        'including_vat' => 'dont TVA', 'total_to_pay' => 'Total à payer', 'your_selection' => 'Votre sélection',
        'your_notes' => 'Vos remarques', 'agreement' => 'Bon pour accord', 'agreement_date' => 'Date',
        'agreement_signature' => 'Nom et signature', 'payment' => 'Règlement', 'continued' => 'suite',
        'at_the_shop' => 'Au magasin', 'prepared_for_you' => 'Préparé avec soin, pour vous',
    ];
}
