<?php
// Kit d'essai MétaDonnée (voir LISEZMOI.md).
// Les leads simulés chez Meta, datés par rapport à maintenant.
$t = time();
$L = [];
$n = 7100;
$add = function (string $form, int $ilya, string $pf, array $r, array $extra = []) use (&$L, &$n, $t) {
    $fd = [];
    foreach ($r as $k => $v) { if ($v !== null) { $fd[] = ['name' => $k, 'values' => [$v]]; } }
    $L[] = ['id' => (string) (++$n), 'form_id' => $form, 'created_time' => gmdate('Y-m-d\TH:i:s+0000', $t - $ilya), 'platform' => $pf,
        'campaign_name' => $extra['camp'] ?? 'Lunch bureau · automne', 'ad_name' => $extra['ad'] ?? 'Carrousel lunch', 'field_data' => $fd,
        'custom_disclaimer_responses' => [['checkbox_key' => 'rgpd', 'is_checked' => ($extra['refus'] ?? false) ? '0' : '1']]];
};
$b = fn ($nom, $mail, $tel, $ent, $fonc, $cp, $pers, $souhait, $quand = null, $mot = null) => ['full_name' => $nom, 'email' => $mail, 'phone_number' => $tel,
    'company_name' => $ent, 'job_title' => $fonc, 'zip' => $cp, 'combien_de_personnes_au_bureau_?' => $pers, 'que_souhaitez-vous_?' => $souhait, 'pour_quand_?' => $quand, 'un_mot_pour_nous_?' => $mot];
$h = 3600; $j = 86400;
// Gosselies
$add('9001', 4 * 60, 'ig', $b('Claire Mertens', 'C.Mertens@fiduciaire-delvaux.be', '+32476123456', 'Fiduciaire Delvaux & associés', 'Office manager', '6041', '35', 'Un lunch tous les jours', 'Dès le 4 novembre', 'Nous avons 3 végétariens'), ['camp' => 'Lunch Charleroi · oct.']);
$add('9001', 3 * $h, 'fb', $b('Julie Hanse', 'julie.hanse@creche-leslutins.be', '0471 22 33 44', 'Crèche Les Lutins', 'Directrice', '6041', '14', 'Un lunch tous les jours', 'Lundi prochain'));
$add('9001', $j - 2 * $h, 'fb', $b('Tom Wauters', 'tom.wauters@hainaut-conseil.be', '0479 55 66 77', 'Assurances Hainaut Conseil', 'Gérant', '6040', '60', 'Un lunch tous les jours', 'Dès que possible'), ['camp' => 'Lunch Charleroi · oct.']);
$add('9001', 2 * $j, 'ig', $b('Yves Fontaine', 'yves@ateliers-fontaine.be', '0486 10 20 30', 'Ateliers Fontaine', 'Administrateur', '6041', '22', 'Des plateaux pour nos réunions', '12/11/2026'));
$add('9001', 4 * $j, 'ig', $b('Inès Lambert', 'ines.lambert@centremedicalsud.be', '0472 98 76 54', 'Centre médical Sud', 'Coordinatrice', '6040', '18', 'Un lunch tous les jours'));
$add('9001', 6 * $j, 'fb', $b('Pierre Delhaye', 'p.delhaye@delhaye-conseil.be', '0475 11 22 33', 'SPRL Delhaye Conseil', 'Associé', '6041', '9', 'Un lunch tous les jours'));
$add('9001', 9 * $j, 'fb', $b('Sarah Collin', 's.collin@immo-collin.be', '0474 44 55 66', 'Immo Collin', 'Gérante', '6041', '6', 'Un petit-déjeuner d’équipe'));
// Halle
$add('9002', 37 * 60, 'fb', $b('Marc Dubois', 'm.dubois@dierenartsencentrum.be', '0485 33 44 55', 'Clinique vétérinaire du Centre', 'Vétérinaire', '1500', '12', 'Des plateaux pour nos réunions'));
$add('9002', 101 * 60, 'fb', $b('Lise Coppens', 'lise@notaris-coppens.be', '0478 12 12 12', 'Étude notariale Coppens', 'Notaire', '1500', '15', 'Des plateaux pour nos réunions', 'jeudi'));
$add('9002', $j + 3 * $h, 'fb', $b('K. Janssens', 'k.janssens@hotmail.be', 'pas de gsm', null, null, '1500', '1', 'Autre'));
$add('9002', 2 * $j + 15 * $h, 'fb', $b('Bart Vandevelde', 'bart@drukkerij-vandevelde.be', '0477 88 99 00', 'Imprimerie Vandevelde', 'Zaakvoerder', '1500', '22', 'Un lunch tous les jours'));
$add('9002', 5 * $j, 'ig', $b('An Peeters', 'an.peeters@kinehalle.be', '0473 31 31 31', 'Kiné Halle', 'Kinésithérapeute', '1500', '7', 'Un petit-déjeuner d’équipe'));
$add('9002', 8 * $j, 'fb', $b('Wim De Smet', 'wim@desmet-bouw.be', '0476 76 76 76', 'De Smet Bouw', 'Directeur', '1501', '40', 'Un lunch tous les jours', null, 'Livraison sur chantier possible ?'));
// Plateaux réunion — réseau (par code postal)
$add('9003', 76 * 60, 'ig', $b('Sophie Lenoir', 'sophie@atelier9.be', '0479 09 09 09', 'Atelier 9 architectes', 'Associée', '1435', '18', 'Un petit-déjeuner d’équipe'), ['camp' => 'Plateaux réunion · BW', 'ad' => 'Story plateaux']);
$add('9003', $j - 30 * 60, 'ig', $b('Ana Ribeiro', 'ana@garage-ribeiro.be', '0470 45 45 45', 'Garage Ribeiro', 'Gérante', '1340', '9', 'Des plateaux pour nos réunions'), ['camp' => 'Plateaux réunion · BW']);
$add('9003', $j + 2 * $h, 'ig', $b('Paul Henrard', 'p.henrard@lestilleuls.be', '0472 20 20 20', 'Résidence Les Tilleuls', 'Directeur', '1360', '25', 'Un lunch tous les jours'), ['camp' => 'Plateaux réunion · BW']);
$add('9003', 2 * $j + 4 * $h, 'ig', $b('Luc Mertens', 'luc@boulangerie-mertens.be', '0471 71 71 71', 'Boulangerie Mertens', 'Patron', '5000', '4', 'Des plateaux pour nos réunions'), ['camp' => 'Plateaux réunion · BW']);
$add('9003', 3 * $j, 'ig', $b('Eva Martin', 'eva@kineplus.be', '0475 50 50 50', 'Kiné Plus Corbais', 'Gérante', '1435', '6', 'Des plateaux pour nos réunions'), ['camp' => 'Plateaux réunion · BW']);
$add('9003', 7 * $j, 'fb', $b('Hugo Dumont', 'hugo@dumont-avocats.be', '0477 07 07 07', 'Dumont Avocats', 'Avocat', '1348', '11', 'Des plateaux pour nos réunions'), ['camp' => 'Plateaux réunion · BW']);
// Sombreffe
$add('9004', 20 * $h, 'fb', $b('Nathalie Gérard', 'n.gerard@dentaire-ligny.be', '0478 40 40 40', 'Cabinet dentaire de Ligny', 'Dentiste', '5140', '8', 'Un petit-déjeuner d’équipe'));
$add('9004', 26 * $h, 'fb', $b('Eric Dumont', 'eric@pharmacieducentre.be', '0479 60 60 60', 'Pharmacie du Centre', 'Pharmacien', '5140', '6', 'Des plateaux pour nos réunions'));
$add('9004', 10 * $j, 'fb', $b('Chloé Remy', 'chloe@remy-compta.be', '0474 14 14 14', 'Remy Comptabilité', 'Comptable', '5140', '5', 'Un petit-déjeuner d’équipe'), ['refus' => true]);
if (!is_dir(__DIR__ . '/etat')) { mkdir(__DIR__ . '/etat', 0775, true); }
file_put_contents(__DIR__ . '/etat/leads_meta.json', json_encode($L, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo count($L) . " leads\n";
