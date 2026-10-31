#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du module Formation — sans base, sans réseau, sans compte panel.
 *
 *   php bin/tests_formation.php
 *
 * Ce qui doit rester vrai quoi que rende le panel : aucune donnée personnelle
 * ni aucun secret ne sort vers le navigateur ; la sonde de l'écran API ne lit
 * que les routes du référentiel formation ; les lignes de liaison (niveau ↔
 * compétence, compétence ↔ formation) donnent l'identifiant de la CIBLE, pas
 * celui de la liaison.
 */

// --- doublures : le module n'a besoin que de PanelApi::liste ---------------------
final class PanelApi
{
    public static function liste(array $d): array
    {
        if (array_is_list($d)) { return array_values(array_filter($d, 'is_array')); }
        foreach (['data', 'items', 'results'] as $k) {
            if (isset($d[$k]) && is_array($d[$k])) { return self::liste($d[$k]); }
        }
        return [];
    }
}

require __DIR__ . '/../src/formation.php';
require __DIR__ . '/../src/parcours.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "Données sensibles\n";
foreach (['password', 'pin', 'refresh_token', 'access_token', 'phone', 'email', 'money_per_hour', 'login', 'iban', 'session_id', 'password_hash'] as $k) {
    verifie("« $k » est retenu", fmSecret($k));
}
foreach (['id', 'name', 'description', 'competency_id', 'level_name', 'shipping', 'position_id', 'training_id', 'title'] as $k) {
    verifie("« $k » passe", !fmSecret($k));
}
$s = fmScrub(['id' => 4, 'name' => 'Julie', 'phone' => '+32', 'positions' => [['id' => 1, 'email' => 'x@y.be', 'level_name' => 'Confirmée']], 'money_per_hour' => 14]);
verifie('nettoyage récursif', !isset($s['phone'], $s['money_per_hour'], $s['positions'][0]['email']) && $s['positions'][0]['level_name'] === 'Confirmée', json_encode($s));
$b = fmScalaires(['id' => 1, 'name' => 'Four', 'password' => 'x', 'tags' => ['a'], 'note' => null]);
verifie('champs bruts : scalaires sans secret', $b === ['id' => 1, 'name' => 'Four', 'note' => null], json_encode($b));

echo "Sonde de l'écran API (liste blanche)\n";
foreach (['/positions', '/positions/12/levels', '/position-levels/3/competencies', '/competencies/7', '/competencies?section_id=2',
    '/career-stages/1/competencies', '/operational-framework/trainings/4/trainers', '/operational-procedures/9', '/knowledge-base/resources',
    '/product-competences/name-aliases', '/packages', '/employees/41', '/franchisee-employee/5/competences', '/course-commissions'] as $c) {
    verifie("accepte $c", (bool) preg_match(FM_SONDE, $c));
}
foreach (['/consultant/auth/login', '/admin/auth/login', '/competencies/../admin', '/employees', '/shops/4/employees', '/positions/1/delete?x=<s>',
    '/competencies/1/trainings', 'https://evil.example/competencies', '/competencies#x', '/attachments/4/presigned-url'] as $c) {
    verifie("refuse $c", !preg_match(FM_SONDE, $c));
}

echo "Normalisation\n";
$c = fmCompetence(['id' => 9001, 'competency_id' => 104, 'name' => 'Relever les températures', 'category_name' => 'HACCP']);
verifie('liaison niveau ↔ compétence : id de la compétence', $c['id'] === 104 && $c['nom'] === 'Relever les températures' && $c['categorie'] === 'HACCP', json_encode($c));
$c = fmCompetence(['id' => 7, 'base_name' => 'Base', 'subcategory_id' => '11', 'subcategory_name' => 'Accueil']);
verifie('nom de base en repli, entiers lus dans des chaînes', $c['nom'] === 'Base' && $c['sousCategorieId'] === 11);
$l = fmLiens(['trainings' => [['id' => 60001, 'training_id' => 1, 'name' => 'HACCP']], 'operational_procedures' => [['id' => 70001, 'id_operational_procedure' => 501, 'title' => 'Relevé']],
    'knowledge_resources' => [['id' => 50001, 'knowledge_resource_id' => 801, 'title' => 'Vidéo']]]);
verifie('liens : identifiants des cibles', $l['formations'][0]['id'] === 1 && $l['procedures'][0]['id'] === 501 && $l['ressources'][0]['id'] === 801, json_encode($l));
verifie('liens absents ⇒ listes vides', fmLiens([]) === ['procedures' => [], 'formations' => [], 'ressources' => []]);
$n = fmNiveau(['level_id' => 3, 'level_name' => 'Confirmée', 'level_order' => '2', 'position_id' => 1], null);
verifie('niveau : noms de champs alternatifs', $n['id'] === 3 && $n['nom'] === 'Confirmée' && $n['ordre'] === 2 && $n['posteId'] === 1, json_encode($n));
verifie('liste enveloppée dans data', count(fmListe(['data' => [['id' => 1], ['id' => 2]]])) === 2);
verifie('réponse non tableau ⇒ liste vide', fmListe('erreur') === [] && fmListe(null) === []);

echo "Parcours : correspondances (les noms du panel font foi)\n";
foreach ([['Opérateur Vente', 'Équipier confirmé', 'P3'], ['Opérateur Vente', 'Responsable d’équipe ( Crew Chief )', 'P4'], ['Opérateur Boulangerie – Pâtisserie', 'Débutant', 'P1'],
    ['Opérateur Boulangerie – Pâtisserie', "Responsable d'équipe", 'P5'], ['Opérateur Traiteur', 'Équipier confirmé - Traiteur Froid', 'P2'], ['Adjoint de Manager', 'Adjoint Manager', 'P7'],
    ['Manager', 'Manager', 'P8'], ['Candidat Franchisé', 'Candidat Franchisé', 'P10'], ['Franchisé', 'Franchise', 'P11'], ['Client mystère', '', null],
    ['Consultant Réseaux Sociaux', 'Indépandant', null]] as [$po, $ni, $attendu]) {
    verifie("$po · $ni → " . ($attendu ?? 'hors matrice'), pcCodeV25($po, $ni) === $attendu, (string) pcCodeV25($po, $ni));
}
verifie('même métier : vendeuse → chef de section vente', pcGroupe('P4', 'P3') === 'metier');
verifie('autre métier : vendeuse → chef boulangerie exclu', pcGroupe('P5', 'P3') === 'autre');
verifie('franchise : groupe entrepreneur', pcGroupe('P11', 'P3') === 'entrepreneur' && pcGroupe('P9', null) === 'entrepreneur');
verifie('code FO d’une évaluation du panel', pcCodeFo('[FO-01] Évaluation théorique vente') === 'FO-01' && pcCodeFo('Formation libre') === null);

echo "Parcours : le chemin d'une destination\n";
$ctx = ['liens' => ['900' => ['formations' => [['id' => 60]]]], 'evalsParId' => [60 => ['FO-06']], 'compParFo' => ['FO-01' => [5, 6], 'FO-02' => [7]]];
$dest = ['v25' => 'P4', 'competences' => [900]];
[$requis, $fac, $plan, $acq] = pcFormations($dest, $ctx, [5, 6], [], 'Équipier confirmé');
verifie('le panel ajoute FO-06 (rattachée aux compétences du niveau)', in_array('FO-06', $requis, true), json_encode($requis));
verifie('la matrice v25 ajoute les obligatoires de P4', in_array('FO-21', $requis, true) && in_array('FO-18', $requis, true));
verifie('FO-01 acquise : la personne a toutes ses compétences du panel', $acq === ['FO-01'], json_encode($acq));
verifie('FO-02 non acquise : une compétence manque', !in_array('FO-02', $acq, true));
verifie('les acquises d’abord (le passé de la frise)', $requis[0] === 'FO-01');
verifie('puis l’ordre du plan Vendeur (B2 Upselling avant B4 Expérience client)', array_search('FO-19', $requis, true) < array_search('FO-04', $requis, true));
verifie('les recommandées restent à part', !array_intersect($fac, $requis) && in_array('FO-05', $fac, true), json_encode($fac));
verifie('plan du vendeur', $plan === 'Plan Vendeur / Vendeuse', (string) $plan);
[$r2] = pcFormations($dest, $ctx, [], ['FO-01'], '');
verifie('une formation validée dans un parcours compte comme acquise', $r2[0] === 'FO-01');

echo "\n$ok ok, $ko KO\n";
exit($ko === 0 ? 0 : 1);
