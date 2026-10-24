#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * Données de démonstration du module « Gestion de production atelier » (phase P1) :
 * 25 postes (dont 4 robots, 3 fours, 2 chambres de pousse, 1 cellule de surgélation),
 * équipes par groupe et par shift, profils des employés de l'atelier, et 30 articles
 * planifiables pris parmi les produits et semi-finis EXISTANTS de l'ERP, avec leurs réglages
 * et leur gamme opératoire.
 *
 *   php Bin/tfb_seed_demo.php [--grant-admin=<login ERP>]
 *
 * Rejouable : rien n'est écrasé. Un poste, une équipe ou un article déjà présent est laissé
 * tel quel ; une gamme n'est créée que pour un article qui n'en a pas.
 * Les produits sont congelés (décision du 19/10/2026) : never_frozen = 0 partout.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../public/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}
$options = getopt('', ['grant-admin::']);
$pdo = (new App\Core\Database())->getConnection();

$id = function (string $table, string $code) use ($pdo): ?int {
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE code = ?");
    $stmt->execute([$code]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
};

// --- Postes : code, groupe, nom PL, nom FR, type, mode robot, capacité, unité, coût/h, dispo nuit
$workstations = [
    ['PETRIN_1', 'LEVAIN', 'Miesiarka spiralna 1', 'Pétrin spirale 1', 'machine', null, 120, 'kg', 25, false],
    ['PETRIN_2', 'LEVAIN', 'Miesiarka spiralna 2', 'Pétrin spirale 2', 'machine', null, 120, 'kg', 25, false],
    ['FERMENTEUR_LEVAIN', 'LEVAIN', 'Fermentor zakwasu', 'Fermenteur à levain', 'machine', null, 200, 'kg', 10, true],
    ['DIVISEUSE', 'FACONNAGE', 'Dzielarka-zaokrąglarka', 'Diviseuse-bouleuse', 'machine', null, 30, 'piece', 30, false],
    ['FACONNEUSE', 'FACONNAGE', 'Formierka bagietek', 'Façonneuse à baguettes', 'machine', null, 220, 'piece', 35, false],
    ['TABLE_FACONNAGE', 'FACONNAGE', 'Stół formowania', 'Table de façonnage', 'manuel', null, 1, 'piece', 0, false],
    ['CHAMBRE_POUSSE_1', 'FACONNAGE', 'Komora rozrostu 1', 'Chambre de pousse 1', 'fermentation', null, 12, 'chariot', 12, true],
    ['CHAMBRE_POUSSE_2', 'FACONNAGE', 'Komora rozrostu 2', 'Chambre de pousse 2', 'fermentation', null, 12, 'chariot', 12, true],
    ['FOUR_ROTATIF', 'FACONNAGE', 'Piec rotacyjny', 'Four rotatif', 'four', null, 18, 'plaque', 45, false],
    ['LAMINOIR', 'VIENNOISERIE', 'Wałkownica', 'Laminoir', 'machine', null, 25, 'kg', 20, false],
    ['ROBOT_LAMINAGE', 'VIENNOISERIE', 'Linia laminowania (robot)', 'Ligne de laminage (robot)', 'robot', 'autonome', 60, 'kg', 85, true],
    ['ROBOT_CROISSANT', 'VIENNOISERIE', 'Robot zwijający rogale', 'Robot enrouleur de croissants', 'robot', 'avec_operateur', 2000, 'piece', 70, false],
    ['TABLE_VIENNOISERIE', 'VIENNOISERIE', 'Stół wiennoiserie', 'Table viennoiserie', 'manuel', null, 1, 'piece', 0, false],
    ['BATTEUR', 'PATISSERIE', 'Mikser planetarny 60 l', 'Batteur-mélangeur 60 L', 'machine', null, 40, 'kg', 18, false],
    ['ROBOT_BOUDINEUSE', 'PATISSERIE', 'Wytłaczarka wałków (robot)', 'Boudineuse (robot)', 'robot', 'autonome', 1000, 'piece', 75, true],
    ['FOUR_VENTILE_2', 'PATISSERIE', 'Piec konwekcyjny 2', 'Four ventilé 2', 'four', null, 10, 'plaque', 40, false],
    ['TABLE_PATISSERIE', 'PATISSERIE', 'Stół cukierniczy', 'Table pâtisserie', 'manuel', null, 1, 'piece', 0, false],
    ['CUISEUR_MELANGEUR', 'TRAITEUR', 'Kocioł warzelny z mieszadłem 150 l', 'Cuiseur-mélangeur 150 L', 'machine', null, 120, 'kg', 30, false],
    ['FOUR_VENTILE_1', 'TRAITEUR', 'Piec konwekcyjny 1', 'Four ventilé 1', 'four', null, 10, 'plaque', 40, false],
    ['TABLE_TRAITEUR', 'TRAITEUR', 'Stół garmażeryjny', 'Table traiteur', 'manuel', null, 1, 'piece', 0, false],
    ['CELLULE_SURGELATION', 'CONDITIONNEMENT', 'Komora szokowego mrożenia', 'Cellule de surgélation', 'surgelation', null, 20, 'chariot', 35, true],
    ['CHAMBRE_FROIDE', 'CONDITIONNEMENT', 'Chłodnia', 'Chambre froide', 'froid', null, 40, 'chariot', 8, true],
    ['ENSACHEUSE', 'CONDITIONNEMENT', 'Pakowarka', 'Ensacheuse', 'machine', null, 1, 'piece', 22, false],
    ['ROBOT_PALETTISEUR', 'CONDITIONNEMENT', 'Robot paletyzujący', 'Robot palettiseur', 'robot', 'autonome', 6, 'piece', 60, true],
    ['TABLE_CONDITIONNEMENT', 'CONDITIONNEMENT', 'Stół pakowania', 'Table de conditionnement', 'manuel', null, 1, 'piece', 0, false],
];

// --- Équipes : code, groupe, shift, nom, effectif prévu, active (seul le jour tourne aujourd'hui)
$teams = [
    ['LEVAIN_JOUR', 'LEVAIN', 'JOUR', 'Levain & pétrissage — jour', 2, true],
    ['FACONNAGE_JOUR', 'FACONNAGE', 'JOUR', 'Façonnage — jour', 4, true],
    ['VIENNOISERIE_JOUR', 'VIENNOISERIE', 'JOUR', 'Viennoiserie — jour', 3, true],
    ['PATISSERIE_JOUR', 'PATISSERIE', 'JOUR', 'Pâtisserie & biscuiterie — jour', 4, true],
    ['TRAITEUR_JOUR', 'TRAITEUR', 'JOUR', 'Traiteur — jour', 2, true],
    ['CONDITIONNEMENT_JOUR', 'CONDITIONNEMENT', 'JOUR', 'Conditionnement — jour', 2, true],
    ['FACONNAGE_AM', 'FACONNAGE', 'APRES_MIDI', 'Façonnage — après-midi (Q2 2027)', 3, false],
    ['VIENNOISERIE_AM', 'VIENNOISERIE', 'APRES_MIDI', 'Viennoiserie — après-midi (Q2 2027)', 3, false],
    ['CONDITIONNEMENT_AM', 'CONDITIONNEMENT', 'APRES_MIDI', 'Conditionnement — après-midi (Q2 2027)', 2, false],
    ['NUIT_ROBOTS', 'VIENNOISERIE', 'NUIT', 'Nuit — robots + superviseur (Q3 2027)', 1, false],
];

// Section ERP de l'employé → groupe principal
$sectionToGroup = ['Piekarnia' => 'FACONNAGE', 'Rogal' => 'VIENNOISERIE', 'Cukiernia' => 'PATISSERIE', 'Tarty' => 'PATISSERIE',
    'Czekolada - Herbatniki' => 'PATISSERIE', 'Garmazeria' => 'TRAITEUR', 'Pakowanie/wysyłka' => 'CONDITIONNEMENT'];

// --- Gammes types : [nom PL, nom FR, poste, fixe s, variable s/unité, capacité/passage, opérateurs, délai s, poste du délai, nettoyage s]
$bread = [
    ['Miesienie', 'Pétrissage', 'PETRIN_1', 1200, 0, 220, 1, 2700, null, 0],
    ['Dzielenie i formowanie', 'Division et façonnage', 'FACONNEUSE', 600, 4, 220, 2, 3600, 'CHAMBRE_POUSSE_1', 0],
    ['Mrożenie szokowe', 'Surgélation', 'CELLULE_SURGELATION', 2400, 0, 600, 0.2, 0, null, 0],
    ['Pakowanie', 'Conditionnement', 'ENSACHEUSE', 300, 1.5, 1000, 1, 0, null, 0],
];
$viennoiserie = [
    ['Zwijanie', 'Façonnage', 'ROBOT_CROISSANT', 900, 0.6, 2000, 1, 0, null, 0],
    ['Mrożenie szokowe', 'Surgélation', 'CELLULE_SURGELATION', 2400, 0, 1500, 0.2, 0, null, 0],
    ['Pakowanie', 'Conditionnement', 'ENSACHEUSE', 300, 1.0, 2000, 1, 0, null, 0],
];
$biscuit = [
    ['Mieszanie', 'Mélange', 'BATTEUR', 900, 0, 300, 1, 0, null, 600],
    ['Formowanie wałków', 'Boudinage', 'ROBOT_BOUDINEUSE', 600, 1.2, 1000, 0.5, 0, null, 0],
    ['Mrożenie szokowe', 'Surgélation', 'CELLULE_SURGELATION', 2400, 0, 2000, 0.2, 0, null, 0],
    ['Pakowanie', 'Conditionnement', 'ENSACHEUSE', 300, 2, 1000, 1, 0, null, 0],
];
$quiche = [
    ['Wykładanie ciasta', 'Fonçage', 'TABLE_TRAITEUR', 300, 45, 60, 2, 0, null, 0],
    ['Nadziewanie', 'Garnissage', 'TABLE_TRAITEUR', 120, 30, 60, 2, 0, null, 0],
    ['Pieczenie', 'Cuisson', 'FOUR_VENTILE_1', 2700, 0, 30, 0.5, 3600, 'CHAMBRE_FROIDE', 0],
    ['Mrożenie szokowe', 'Surgélation', 'CELLULE_SURGELATION', 2400, 0, 300, 0.2, 0, null, 0],
    ['Pakowanie', 'Conditionnement', 'TABLE_CONDITIONNEMENT', 120, 20, 300, 1, 0, null, 0],
];
$soup = [
    ['Gotowanie', 'Cuisson', 'CUISEUR_MELANGEUR', 3600, 0, 150, 1, 5400, 'CHAMBRE_FROIDE', 1800],
    ['Pakowanie', 'Conditionnement', 'TABLE_CONDITIONNEMENT', 120, 15, 300, 1, 0, null, 0],
];
$granola = [
    ['Mieszanie', 'Mélange', 'BATTEUR', 600, 0, 200, 1, 0, null, 600],
    ['Pieczenie', 'Cuisson', 'FOUR_VENTILE_2', 1800, 0, 150, 0.5, 1800, null, 0],
    ['Pakowanie', 'Conditionnement', 'TABLE_CONDITIONNEMENT', 120, 12, 300, 1, 0, null, 0],
];

// --- Articles : [type, id ERP, groupe, mode, base, stock min, stock max, couverture j, batch min, multiple, DLC atelier j, DLC boutique j, gamme]
$items = [
    ['product', 1003021, 'FACONNAGE', 'MTO', 'szt', null, null, null, 36, 18, 60, 120, $bread],      // Baguette Tradition 500 g
    ['product', 2580047, 'FACONNAGE', 'MTO', 'szt', null, null, null, 36, 18, 60, 120, $bread],      // Baguette Poivre & Sel
    ['product', 1002112, 'FACONNAGE', 'MTO', 'szt', null, null, null, 160, 80, 60, 120, $bread],     // Pistolet à l'Ancienne
    ['product', 1003275, 'LEVAIN', 'MTO', 'szt', null, null, null, 22, 11, 60, 120, array_merge(
        [['Odświeżenie zakwasu', 'Rafraîchi du levain', 'FERMENTEUR_LEVAIN', 600, 0, 400, 1, 14400, null, 0]], $bread)], // Fermier Seigle & Levain
    ['product', 1010177, 'FACONNAGE', 'MTO', 'szt', null, null, null, 22, 11, 60, 120, $bread],      // Pain Doré 600 g
    ['product', 1003064, 'FACONNAGE', 'MTO', 'szt', null, null, null, 22, 11, 60, 120, $bread],      // Pain Demi-Gris 600 g
    ['product', 1002292, 'FACONNAGE', 'MTO', 'szt', null, null, null, 20, 10, 60, 120, $bread],      // Pain 4 Céréales Tradition
    ['product', 1002020, 'FACONNAGE', 'MTO', 'szt', null, null, null, 20, 10, 60, 120, $bread],      // Pain Tradition Olives
    ['product', 1003350, 'FACONNAGE', 'MTO', 'szt', null, null, null, 32, 16, 60, 120, [
        ['Miesienie brioszki', 'Pétrissage brioche', 'PETRIN_2', 1500, 0, 200, 1, 0, null, 0],
        ['Formowanie', 'Façonnage', 'TABLE_FACONNAGE', 300, 20, 200, 2, 7200, 'CHAMBRE_POUSSE_2', 0],
        ['Mrożenie szokowe', 'Surgélation', 'CELLULE_SURGELATION', 2400, 0, 400, 0.2, 0, null, 0],
        ['Pakowanie', 'Conditionnement', 'ENSACHEUSE', 300, 2, 1000, 1, 0, null, 0]]],                   // Brioche 400 g (Cru)
    ['product', 1001958, 'VIENNOISERIE', 'MTO', 'szt', null, null, null, 128, 64, 60, 120, $viennoiserie], // Croissant 75
    ['product', 1002343, 'VIENNOISERIE', 'MTO', 'szt', null, null, null, 128, 64, 60, 120, $viennoiserie], // Pain au chocolat 85
    ['product', 1001994, 'VIENNOISERIE', 'MTO', 'szt', null, null, null, 128, 64, 60, 120, $viennoiserie], // Croissant Amandes 85
    ['product', 1002006, 'VIENNOISERIE', 'MTO', 'szt', null, null, null, 128, 64, 60, 120, $viennoiserie], // Suisse 95
    ['product', 1002086, 'VIENNOISERIE', 'MTO', 'szt', null, null, null, 200, 100, 60, 120, $viennoiserie], // Mini croissant
    ['product', 1002067, 'VIENNOISERIE', 'MTO', 'szt', null, null, null, 200, 100, 60, 120, $viennoiserie], // Mini pain au chocolat
    ['product', 1009782, 'PATISSERIE', 'MTS', 'szt', 240, 1440, 14, 240, 24, 90, 120, $biscuit],    // Sablé en boudin Damier
    ['product', 1009781, 'PATISSERIE', 'MTS', 'szt', 240, 1440, 14, 240, 24, 90, 120, $biscuit],    // Leopard
    ['product', 1009783, 'PATISSERIE', 'MTS', 'szt', 240, 1440, 14, 240, 24, 90, 120, $biscuit],    // Swirl
    ['product', 1009793, 'PATISSERIE', 'MTS', 'szt', 240, 1440, 14, 240, 24, 90, 120, $biscuit],    // Amandes & Canelle
    ['product', 1010210, 'PATISSERIE', 'MTS', 'szt', 216, 1080, 14, 216, 54, 90, 120, $biscuit],    // Cookie Chocolat Blanc
    ['product', 1008756, 'TRAITEUR', 'MTO', 'szt', null, null, null, 12, 6, 30, 120, $quiche],       // Quiche Lorraine 26ø
    ['product', 1008758, 'TRAITEUR', 'MTO', 'szt', null, null, null, 12, 6, 30, 120, $quiche],       // Quiche Champignon 26ø
    ['product', 1002294, 'TRAITEUR', 'MTO', 'szt', null, null, null, 36, 6, 30, 120, $soup],         // Soupe Carotte & Gingembre
    ['product', 2580082, 'PATISSERIE', 'MTS', 'szt', 100, 600, 30, 100, 20, 120, 180, $granola],    // Granola Chocolat & Noisette (épicerie)
    ['product', 2580085, 'PATISSERIE', 'MTS', 'szt', 100, 600, 30, 100, 20, 120, 180, $granola],    // Granola Noisettes & Raisins (épicerie)
    ['semi', 301, 'VIENNOISERIE', 'MTS', 'kg', 50, 300, 3, 60, 6, 3, null, [                        // Pâte feuilletée (Ciasto Rogal + masło klepane)
        ['Miesienie', 'Pétrissage', 'PETRIN_2', 1200, 0, 120, 1, 3600, 'CHAMBRE_FROIDE', 0],
        ['Laminowanie', 'Laminage', 'ROBOT_LAMINAGE', 600, 30, 60, 0.3, 1800, 'CHAMBRE_FROIDE', 0]]],
    ['semi', 137, 'PATISSERIE', 'MTS', 'kg', 40, 200, 7, 20, 5, 7, null, [                           // Pâte sablée (Ciasto sable)
        ['Mieszanie', 'Mélange', 'BATTEUR', 900, 0, 40, 1, 7200, 'CHAMBRE_FROIDE', 600]]],
    ['semi', 193, 'TRAITEUR', 'MTS', 'kg', 30, 120, 2, 30, 10, 2, null, [                            // Appareil à quiche (Zalewa Quiche)
        ['Przygotowanie zalewy', 'Préparation de l\'appareil', 'CUISEUR_MELANGEUR', 900, 0, 120, 1, 0, null, 1200]]],
    ['semi', 356, 'TRAITEUR', 'MTS', 'kg', 20, 80, 2, 20, 10, 2, null, [                             // Garniture bœuf (Farsz Quiche boeufs)
        ['Gotowanie farszu', 'Cuisson de la garniture', 'CUISEUR_MELANGEUR', 3600, 0, 100, 1, 5400, 'CHAMBRE_FROIDE', 1800]]],
    ['semi', 237, 'LEVAIN', 'MTO', 'kg', null, null, null, 50, 10, 1, null, [                        // Pâte tradition (Pain Tradition)
        ['Miesienie', 'Pétrissage', 'PETRIN_1', 1200, 0, 150, 1, 2700, null, 0]]],
];

$summary = ['workstations' => 0, 'teams' => 0, 'profiles' => 0, 'members' => 0, 'items' => 0, 'settings' => 0, 'steps' => 0, 'skipped_items' => []];

$pdo->beginTransaction();
try {
    $groups = array_column($pdo->query('SELECT code, id FROM n_prod_group')->fetchAll(), 'id', 'code');
    $shifts = array_column($pdo->query('SELECT code, id FROM n_prod_shift_template')->fetchAll(), 'id', 'code');
    if (count($groups) < 6 || count($shifts) < 3) {
        throw new RuntimeException('Default groups and shifts are missing: run php Bin/tfb_migrate.php apply first.');
    }

    $insertWs = $pdo->prepare('INSERT IGNORE INTO n_prod_workstation (code, id_group, name_pl, name_fr, type, robot_mode, capacity_per_pass, capacity_unit, hourly_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $availability = $pdo->prepare('INSERT IGNORE INTO n_prod_workstation_shift (id_workstation, id_shift_template, available) VALUES (?, ?, ?)');
    foreach ($workstations as [$code, $group, $pl, $fr, $type, $mode, $capacity, $unit, $cost, $night]) {
        $insertWs->execute([$code, $groups[$group], $pl, $fr, $type, $mode, $capacity, $unit, $cost]);
        $summary['workstations'] += $insertWs->rowCount();
        $wsId = $id('n_prod_workstation', $code);
        $availability->execute([$wsId, $shifts['NUIT'], (int) $night]);
    }

    $insertTeam = $pdo->prepare('INSERT IGNORE INTO n_prod_team (code, id_group, id_shift_template, name, planned_headcount, active) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($teams as [$code, $group, $shift, $name, $headcount, $active]) {
        $insertTeam->execute([$code, $groups[$group], $shifts[$shift], $name, $headcount, (int) $active]);
        $summary['teams'] += $insertTeam->rowCount();
    }

    // Employés de l'atelier : profil + équipe de jour de leur groupe principal
    $employees = $pdo->query('SELECT pe.id, ps.name AS section FROM production_employee pe LEFT JOIN production_sections ps ON ps.id = pe.id_section')->fetchAll();
    $insertProfile = $pdo->prepare('INSERT IGNORE INTO n_prod_employee (id_production_employee, id_primary_group) VALUES (?, ?)');
    $insertMember = $pdo->prepare('INSERT IGNORE INTO n_prod_team_member (id_team, id_employee, is_lead) VALUES (?, ?, 0)');
    foreach ($employees as $employee) {
        $group = $sectionToGroup[$employee['section']] ?? null;
        if ($group === null) continue; // administration : pas d'équipe de production
        $insertProfile->execute([$employee['id'], $groups[$group]]);
        $summary['profiles'] += $insertProfile->rowCount();
        $profile = $pdo->prepare('SELECT id, id_primary_group FROM n_prod_employee WHERE id_production_employee = ?');
        $profile->execute([$employee['id']]);
        $p = $profile->fetch();
        $teamCode = array_search((int) $p['id_primary_group'], $groups, false) . '_JOUR';
        $teamId = $id('n_prod_team', $teamCode);
        if ($teamId !== null) {
            $insertMember->execute([$teamId, $p['id']]);
            $summary['members'] += $insertMember->rowCount();
        }
    }

    // Articles, réglages, gammes
    $exists = ['product' => $pdo->prepare('SELECT 1 FROM final_product WHERE id = ?'), 'semi' => $pdo->prepare('SELECT 1 FROM product_recipe WHERE id = ? AND id_type IN (1, 2)')];
    $insertItem = $pdo->prepare('INSERT IGNORE INTO n_prod_item (item_type, id_final_product, id_recipe, base_unit) VALUES (?, ?, ?, ?)');
    $findItem = ['product' => $pdo->prepare('SELECT id FROM n_prod_item WHERE id_final_product = ?'), 'semi' => $pdo->prepare('SELECT id FROM n_prod_item WHERE id_recipe = ?')];
    $insertSettings = $pdo->prepare('INSERT IGNORE INTO n_prod_product_settings
        (id_item, mode, never_frozen, stock_min, stock_max, coverage_days, batch_min, batch_multiple, shelf_life_workshop_days, shelf_life_shop_days, id_primary_group, status, wizard_step, validated_at)
        VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, \'validated\', 7, NOW())');
    $hasSteps = $pdo->prepare('SELECT COUNT(*) FROM n_prod_routing_step WHERE id_item = ?');
    $insertStep = $pdo->prepare('INSERT INTO n_prod_routing_step
        (id_item, step_no, name_pl, name_fr, id_workstation, fixed_time_s, variable_time_s, capacity_per_pass, operators_required, delay_after_s, delay_id_workstation, cleaning_required, cleaning_time_s)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insertAlt = $pdo->prepare('INSERT IGNORE INTO n_prod_routing_alternative (id_step, id_workstation, fixed_time_s, variable_time_s, capacity_per_pass, priority) VALUES (?, ?, ?, ?, ?, 1)');

    foreach ($items as [$type, $refId, $group, $mode, $unit, $min, $max, $coverage, $batchMin, $multiple, $dlcWorkshop, $dlcShop, $routing]) {
        $exists[$type]->execute([$refId]);
        if (!$exists[$type]->fetchColumn()) {
            $summary['skipped_items'][] = "{$type} {$refId}";
            continue;
        }
        $insertItem->execute([$type, $type === 'product' ? $refId : null, $type === 'semi' ? $refId : null, $unit]);
        $summary['items'] += $insertItem->rowCount();
        $findItem[$type]->execute([$refId]);
        $itemId = (int) $findItem[$type]->fetchColumn();

        $insertSettings->execute([$itemId, $mode, $min, $max, $coverage, $batchMin, $multiple, $dlcWorkshop, $dlcShop, $groups[$group]]);
        $summary['settings'] += $insertSettings->rowCount();

        $hasSteps->execute([$itemId]);
        if ((int) $hasSteps->fetchColumn() > 0) continue;
        foreach ($routing as $n => [$pl, $fr, $ws, $fixed, $variable, $capacity, $operators, $delay, $delayWs, $cleaning]) {
            $insertStep->execute([$itemId, $n + 1, $pl, $fr, $id('n_prod_workstation', $ws), $fixed, $variable, $capacity, $operators,
                $delay, $delayWs === null ? null : $id('n_prod_workstation', $delayWs), (int) ($cleaning > 0), $cleaning]);
            $summary['steps']++;
            $stepId = (int) $pdo->lastInsertId();
            // Postes alternatifs : façonnage manuel si le robot est pris, laminoir si la ligne est prise
            if ($ws === 'ROBOT_CROISSANT') $insertAlt->execute([$stepId, $id('n_prod_workstation', 'TABLE_VIENNOISERIE'), 300, 6, 200]);
            if ($ws === 'ROBOT_BOUDINEUSE') $insertAlt->execute([$stepId, $id('n_prod_workstation', 'TABLE_PATISSERIE'), 300, 10, 100]);
            if ($ws === 'ROBOT_LAMINAGE') $insertAlt->execute([$stepId, $id('n_prod_workstation', 'LAMINOIR'), 300, 90, 25]);
            if ($ws === 'FOUR_VENTILE_1') $insertAlt->execute([$stepId, $id('n_prod_workstation', 'FOUR_VENTILE_2'), 2700, 0, 30]);
        }
    }

    if (!empty($options['grant-admin'])) {
        $user = $pdo->prepare('SELECT id FROM user WHERE login = ?');
        $user->execute([$options['grant-admin']]);
        $userId = $user->fetchColumn();
        if ($userId !== false) {
            $has = $pdo->prepare("SELECT 1 FROM n_prod_user_role WHERE id_user = ? AND role_code = 'prod_admin' AND id_group IS NULL");
            $has->execute([$userId]);
            if (!$has->fetchColumn()) {
                $pdo->prepare("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (?, 'prod_admin')")->execute([$userId]);
            }
            $summary['admin'] = $options['grant-admin'];
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'tfb_seed_demo: ' . $e->getMessage() . "\n");
    exit(1);
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
