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
    ['Consultant Réseaux Sociaux', 'Indépandant', null], ['Interface Franchiseur–Franchisés', 'Indépandant', null],
    ['Contrôleur Qualité & Conformité', 'Animateur Formateur', null], ['Développeur de performance (business coach)', 'Indépandant', null]] as [$po, $ni, $attendu]) {
    verifie("$po · $ni → " . ($attendu ?? 'hors matrice'), pcCodeV25($po, $ni) === $attendu, (string) pcCodeV25($po, $ni));
}
verifie('même métier : vendeuse → chef de section vente', pcGroupe('P4', 'P3') === 'metier');
verifie('autre métier : vendeuse → chef boulangerie exclu', pcGroupe('P5', 'P3') === 'autre');
verifie('franchise : groupe entrepreneur', pcGroupe('P11', 'P3') === 'entrepreneur' && pcGroupe('P9', null) === 'entrepreneur');
verifie('sans poste dans le panel : tous les métiers sont proposés', pcGroupe('P1', null, true) === 'metier' && pcGroupe('P4', null, true) === 'metier');
verifie('candidat (sans poste, hors employés) : pas de métier', pcGroupe('P1', null, false) === 'autre');
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

echo "Parcours : critères d'admission (le panel d'abord, sans doublon)\n";
$manager = ['cle' => 'n:60', 'niveauId' => 60, 'v25' => 'P8', 'competences' => []];
$exig = ['60' => [['id' => 5, 'nom' => '[FO-24] Planification & plannings', 'description' => 'Exigée par le panel']]];
$crit = pcCriteres($manager, $exig);
verifie('l’exigence du panel passe en premier', $crit[0]['cle'] === 'r5' && $crit[0]['source'] === 'panel' && $crit[0]['type'] === 'fo' && $crit[0]['fo'] === ['FO-24'], json_encode($crit[0]));
$adj = array_values(array_filter($crit, static fn ($c) => $c['cle'] === 'adjoint'))[0] ?? null;
verifie('FO-24 exigée par le panel n’est pas répétée par le cockpit', $adj !== null && $adj['fo'] === ['FO-28'], json_encode($adj));
verifie('Manager : poste tenu = Adjoint Manager', in_array(['P7'], array_column($crit, 'codes'), true));
$multi = pcCriteres(['cle' => 'v25:P12', 'niveauId' => null, 'v25' => 'P12', 'competences' => []], $exig);
verifie('multi-boutique : il faut être franchisé, et l’accord du franchiseur', $multi[0]['type'] === 'poste' && $multi[0]['codes'] === ['P11'] && in_array('accord', array_column($multi, 'cle'), true), json_encode(array_column($multi, 'cle')));
verifie('adjoint : d’abord maîtriser les 3 métiers (règle du 31/10/2026)', pcCriteres(['niveauId' => 50, 'v25' => 'P7'], [])[0]['type'] === 'metiers');
verifie('manager : les 3 métiers, puis adjoint en poste', array_column(pcCriteres(['niveauId' => 60, 'v25' => 'P8'], []), 'cle') === ['metiers', 'poste', 'adjoint', 'encadrement', 'entretien']);
verifie('un poste sans critère n’en a pas', pcCriteres(['niveauId' => 11, 'v25' => 'P3'], []) === []);
$ctxA = ['compParFo' => ['FO-24' => [7], 'FO-28' => [8]], 'evals' => []];
$adjointe = ['postes' => [['nom' => 'Adjoint de Manager', 'niveau' => 'Adjoint Manager', 'ordre' => 1]], 'competences' => [7]];
$e = pcEvaluer($crit, $ctxA, $adjointe, []);
$par = array_column($e, null, 'cle');
verifie('poste tenu vérifié dans le panel', $par['poste']['ok'] && $par['poste']['preuve'] === 'panel');
verifie('formation du panel tenue (FO-24) : critère rempli', $par['r5']['ok']);
verifie('FO-28 manque : critère ouvert, avec son nom', !$par['adjoint']['ok'] && $par['adjoint']['manque'][0]['fo'] === 'FO-28' && $par['adjoint']['manque'][0]['nom'] === 'Formation Adjoint Manager', json_encode($par['adjoint']));
verifie('l’entretien reste à valider par une personne', !$par['entretien']['ok'] && $par['entretien']['preuve'] === null);
verifie('formations de l’admission', pcFoAdmission($e) === ['FO-24', 'FO-28']);
[$rq] = pcFormations($manager + ['competences' => []], ['liens' => [], 'evalsParId' => [], 'compParFo' => []], [], [], 'Adjoint Manager', ['FO-28']);
verifie('FO-28 (recommandée pour P8) entre dans le chemin : l’admission l’exige', in_array('FO-28', $rq, true));
verifie('le prérequis passe avant le reste du plan', $rq[0] === 'FO-28', implode(',', array_slice($rq, 0, 4)));
$suivie = pcAdmissionSuivie(['admission' => json_encode(pcAdmissionSortie($e))], [['fo' => 'FO-28', 'statut' => 'validee'], ['fo' => 'FO-05', 'statut' => 'encours']]);
$sp = array_column($suivie, null, 'cle');
verifie('FO-28 validée dans le parcours : le critère se remplit seul', $sp['adjoint']['ok'] && $sp['adjoint']['preuve'] === 'parcours', json_encode($sp['adjoint']));
verifie('parcours d’avant les critères : aucun critère', pcAdmissionSuivie(['admission' => null], []) === []);
$critMulti = pcEvaluer($multi, $ctxA, ['postes' => [['nom' => 'Franchisé', 'niveau' => 'Franchise']], 'competences' => []], []);
verifie('franchisé → multi-boutique : à sa portée', pcHorsAtteinte('P12', $critMulti, 47, 'P11') === null);
verifie('vendeuse → multi-boutique : il faut d’abord être franchisé', str_contains((string) pcHorsAtteinte('P12', pcEvaluer($multi, $ctxA, ['postes' => [['nom' => 'Opérateur Vente', 'niveau' => 'Équipier confirmé']]], []), 41, 'P3'), 'Franchisé en activité'));
verifie('candidat → multi-boutique : non', pcHorsAtteinte('P12', pcEvaluer($multi, $ctxA, ['postes' => []], []), null, null) !== null);
verifie('sans poste dans le panel → tout reste ouvert (le consultant valide)', pcHorsAtteinte('P8', pcEvaluer($crit, $ctxA, ['postes' => []], []), 43, null) === null);
verifie('franchisé → Manager ou candidat : on ne redescend pas', pcHorsAtteinte('P8', [], 47, 'P11') !== null && pcHorsAtteinte('P10', [], 47, 'P11') !== null);
verifie('adjointe → Manager : à sa portée', pcHorsAtteinte('P8', $e, 46, 'P7') === null);

echo "Familles de postes (opérationnel, cadre et direction, devenir entrepreneur)\n";
foreach ([['Opérateur Vente', [], 'operationnel'], ['Opérateur Boulangerie – Pâtisserie', [['nom' => "Responsable d'équipe"]], 'operationnel'], ['Adjoint de Manager', [], 'cadre'],
    ['Manager', [], 'cadre'], ['Direction Opérationnelle', [], 'cadre'], ['Consultant Réseaux Sociaux', [['nom' => 'Indépandant']], 'cadre'], ['Client mystère', [], 'cadre'],
    ['Candidat Franchisé', [], 'entrepreneur'], ['Franchisé ', [['nom' => 'Franchise']], 'entrepreneur'], ['Franchisé multi-boutique', [], 'entrepreneur']] as [$po, $nv, $f]) {
    verifie("$po → $f", pcFamillePoste(['nom' => $po, 'niveaux' => $nv]) === $f, pcFamillePoste(['nom' => $po, 'niveaux' => $nv]));
}

echo "Les trois métiers : maîtrise, critère, carte\n";
$refM = ['postes' => [
    ['id' => 4, 'nom' => 'Opérateur Vente', 'niveaux' => [['id' => 19, 'nom' => 'Observation et Introduction', 'competences' => [1, 2]], ['id' => 16, 'nom' => 'Débutant - etudiante', 'competences' => [3]],
        ['id' => 17, 'nom' => 'Équipier confirmé', 'competences' => [4, 5]], ['id' => 18, 'nom' => 'Responsable d’équipe ( Crew Chief )', 'competences' => [6]]]],
    ['id' => 1, 'nom' => 'Opérateur Boulangerie – Pâtisserie', 'niveaux' => [['id' => 1, 'nom' => 'Débutant', 'competences' => [10]], ['id' => 2, 'nom' => 'Équipier confirmé', 'competences' => [11]],
        ['id' => 3, 'nom' => "Responsable d'équipe", 'competences' => [12]]]],
    ['id' => 2, 'nom' => 'Opérateur Traiteur', 'niveaux' => [['id' => 4, 'nom' => 'Débutant', 'competences' => [20]], ['id' => 5, 'nom' => 'Équipier confirmé - Traiteur Froid', 'competences' => [21]],
        ['id' => 23, 'nom' => 'Équipier confirmé - Traiteur Chaud', 'competences' => [22]], ['id' => 6, 'nom' => "Responsable d'équipe", 'competences' => [23]]]],
    ['id' => 5, 'nom' => 'Adjoint de Manager', 'niveaux' => [['id' => 9, 'nom' => 'Adjoint Manager', 'competences' => [30]]]],
    ['id' => 6, 'nom' => 'Manager', 'niveaux' => [['id' => 10, 'nom' => 'Manager', 'competences' => [31, 32]]]],
    ['id' => 7, 'nom' => 'Candidat Franchisé', 'niveaux' => [['id' => 13, 'nom' => 'Candidat Franchisé', 'competences' => [40]]]],
    ['id' => 8, 'nom' => 'Franchisé ', 'niveaux' => [['id' => 21, 'nom' => 'Franchise', 'competences' => []]]],
    ['id' => 18, 'nom' => 'Client mystère', 'niveaux' => []],
], 'competences' => array_map(static fn ($i) => ['id' => $i, 'nom' => 'c' . $i], [1, 2, 3, 4, 5, 6, 10, 11, 12, 20, 21, 22, 23, 30, 31, 32, 40])];
$mt = pcMaitrises($refM);
verifie('maîtrise = jusqu’au dernier niveau avant « Responsable d’équipe »', array_keys($mt) === ['vente', 'boul', 'trait'] && $mt['vente']['niveauId'] === 17 && $mt['trait']['niveauId'] === 23, json_encode(array_map(static fn ($m) => $m['niveauId'], $mt)));
verifie('compétences cumulées de la maîtrise', $mt['vente']['competences'] === [1, 2, 3, 4, 5] && $mt['boul']['competences'] === [10, 11] && $mt['trait']['competences'] === [20, 21, 22]);
$ctxM = ['ref' => $refM, 'compParFo' => ['FO-01' => [1, 2, 3, 4], 'FO-02' => [5], 'FO-03' => [10, 11], 'FO-10' => [20, 21, 22]], 'evals' => []];
$critM = [['cle' => 'metiers', 'type' => 'metiers', 'libelle' => '3 métiers', 'codes' => [], 'fo' => [], 'source' => 'referentiel']];
$ev = pcEvaluer($critM, $ctxM, ['postes' => [], 'competences' => [1, 2, 3, 4, 5, 10, 11, 20]], [])[0];
verifie('2 métiers sur 3 : critère ouvert', !$ev['ok'] && array_column($ev['metiers'], 'manque') === [0, 0, 2], json_encode($ev['metiers']));
verifie('ce qui manque passe par la formation qui l’enseigne (traiteur → FO-10)', array_column($ev['manque'], 'fo') === ['FO-10'], json_encode($ev['manque']));
verifie('… et elle s’ajoute au chemin', pcFoAdmission([$ev]) === ['FO-10']);
$ev3 = pcEvaluer($critM, $ctxM, ['postes' => [], 'competences' => [1, 2, 3, 4, 5, 10, 11, 20, 21, 22]], [])[0];
verifie('les 3 métiers maîtrisés : vérifié dans le panel', $ev3['ok'] && $ev3['preuve'] === 'panel' && pcFoAdmission([$ev3]) === []);
$evS = pcAdmissionSuivie(['admission' => json_encode(pcAdmissionSortie([$ev]))], [['fo' => 'FO-10', 'statut' => 'validee']])[0];
verifie('FO-10 validée dans le parcours : le critère se remplit', $evS['ok'] && $evS['preuve'] === 'parcours');
$liensM = ['1' => ['formations' => [['id' => 1]]], '10' => ['formations' => [['id' => 2]]], '30' => ['formations' => [['id' => 3]], 'procedures' => [['id' => 9]]]];
$equipesM = [[['id' => 1, 'nom' => 'A', 'actif' => true, 'postes' => [['niveauId' => 17]], 'competences' => [1, 2, 3, 4, 5, 10, 11, 20, 21, 22]],
    ['id' => 2, 'nom' => 'B', 'actif' => true, 'postes' => [], 'competences' => [1]], ['id' => 3, 'nom' => 'C', 'actif' => false, 'postes' => [['niveauId' => 17]], 'competences' => []]],
    [['id' => 1, 'nom' => 'A', 'actif' => true, 'postes' => [['niveauId' => 17]], 'competences' => [1, 2, 3, 4, 5, 10, 11, 20, 21, 22]]]];
$carte = pcCarte($refM, $liensM, $equipesM, 2);
verifie('carte : effectifs (une personne dans deux magasins comptée une fois, inactifs exclus)', $carte['effectifs']['actifs'] === 2 && $carte['effectifs']['sansPoste'] === 1 && $carte['effectifs']['places'] === 1, json_encode($carte['effectifs']));
verifie('carte : les trois lignes, leur niveau de maîtrise', array_column($carte['chemin']['metiers'], 'cle') === ['vente', 'boul', 'trait'] && $carte['chemin']['metiers'][0]['maitrise'] === 2 && $carte['chemin']['metiers'][2]['maitrise'] === 2);
verifie('carte : niveaux cumulés et personnes', $carte['chemin']['metiers'][0]['niveaux'][2]['total'] === 5 && $carte['chemin']['metiers'][0]['niveaux'][2]['personnes'] === 1 && $carte['chemin']['metiers'][0]['niveaux'][3]['total'] === 6);
verifie('carte : tronc adjoint → manager, puis l’entrepreneur', array_column($carte['chemin']['tronc'], 'code') === ['P7', 'P8'] && array_column($carte['chemin']['entrepreneur'], 'code') === ['P10', 'P11']);
verifie('carte : les rôles du réseau à part', array_column($carte['chemin']['reseau'], 'nom') === ['Client mystère']);
verifie('carte : polyvalence 10 compétences, 1 personne sur les 3 métiers', $carte['polyvalence']['competences'] === 10 && $carte['polyvalence']['paliers']['3'] === 1 && $carte['polyvalence']['paliers']['0'] === 1, json_encode($carte['polyvalence']));
verifie('carte : aucun nom de personne', !str_contains(json_encode($carte), '"A"') && !str_contains(json_encode($carte, JSON_UNESCAPED_UNICODE), '"nom":"B"'));
verifie('carte : ce qui manque', $carte['manques']['sansFormation'] === 14 && $carte['manques']['sansProcedure'] === 16 && $carte['manques']['niveauxVides'] === ['Franchisé'], json_encode($carte['manques']));

echo "\n$ok ok, $ko KO\n";
exit($ko === 0 ? 0 : 1);
