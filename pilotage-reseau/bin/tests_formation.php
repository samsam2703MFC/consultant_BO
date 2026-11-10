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
require __DIR__ . '/../src/formation_postes.php';

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

echo "Postes : correspondances (les noms du panel font foi)\n";
foreach ([['Opérateur Vente', 'Équipier confirmé', 'P3'], ['Opérateur Vente', 'Responsable d’équipe ( Crew Chief )', 'P4'], ['Opérateur Boulangerie – Pâtisserie', 'Débutant', 'P1'],
    ['Opérateur Boulangerie – Pâtisserie', "Responsable d'équipe", 'P5'], ['Opérateur Traiteur', 'Équipier confirmé - Traiteur Froid', 'P2'], ['Adjoint de Manager', 'Adjoint Manager', 'P7'],
    ['Manager', 'Manager', 'P8'], ['Candidat Franchisé', 'Candidat Franchisé', 'P10'], ['Franchisé', 'Franchise', 'P11'], ['Client mystère', '', null],
    ['Consultant Réseaux Sociaux', 'Indépandant', null], ['Interface Franchiseur–Franchisés', 'Indépandant', null],
    ['Contrôleur Qualité & Conformité', 'Animateur Formateur', null], ['Développeur de performance (business coach)', 'Indépandant', null]] as [$po, $ni, $attendu]) {
    verifie("$po · $ni → " . ($attendu ?? 'hors matrice'), pcCodeV25($po, $ni) === $attendu, (string) pcCodeV25($po, $ni));
}
verifie('code FO d’une évaluation du panel', pcCodeFo('[FO-01] Évaluation théorique vente') === 'FO-01' && pcCodeFo('Formation libre') === null);

echo "Familles de postes : dans la franchise (opérationnel, cadre et direction, devenir entrepreneur), dans la marque\n";
foreach ([['Opérateur Vente', [], 'operationnel'], ['Opérateur Boulangerie – Pâtisserie', [['nom' => "Responsable d'équipe"]], 'operationnel'], ['Adjoint de Manager', [], 'cadre'],
    ['Manager', [], 'cadre'], ['Direction Opérationnelle', [], 'marque'], ['Consultant Réseaux Sociaux', [['nom' => 'Indépandant']], 'marque'], ['Client mystère', [], 'marque'],
    ['Contrôleur Qualité & Conformité', [['nom' => 'Animateur Formateur']], 'marque'], ['Interface Franchiseur–Franchisés', [['nom' => 'Indépandant']], 'marque'],
    ['Candidat Franchisé', [], 'entrepreneur'], ['Franchisé ', [['nom' => 'Franchise']], 'entrepreneur'], ['Franchisé multi-boutique', [], 'entrepreneur']] as [$po, $nv, $f]) {
    verifie("$po → $f", pcFamillePoste(['nom' => $po, 'niveaux' => $nv]) === $f, pcFamillePoste(['nom' => $po, 'niveaux' => $nv]));
}

echo "Les trois métiers : maîtrise, carte\n";
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

echo "\nManuel opératoire : parties et vidéos\n";
verifie('les quatre parties, dans l’ordre de la vie du magasin', MN_PARTIES === ['preouverture', 'ouverture', 'operationnel', 'formation']);
verifie('partie valable ou null', mnPartie('ouverture') === 'ouverture' && mnPartie('Ouverture') === null && mnPartie(null) === null && mnPartie(3) === null);
$vids = mnVideos([['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'titre' => '  Vitrine  '], ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'],
    ['url' => 'javascript:alert(1)'], ['url' => 'http://exemple.be/v.mp4'], ['url' => 'https://exemple.be/a b.mp4'], 'https://vimeo.com/76979871', ['url' => 'https://x.be/"onerror="']]);
verifie('vidéos : https seulement, sans doublon, sans caractère qui sortirait d’un attribut', array_column($vids, 'url') === ['https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'https://vimeo.com/76979871'], json_encode($vids));
verifie('titre rogné, vide permis', $vids[0]['titre'] === 'Vitrine' && $vids[1]['titre'] === '');
verifie('dix vidéos au plus', count(mnVideos(array_map(static fn ($i) => 'https://vimeo.com/1000000' . $i, range(0, 14)))) === 10);
verifie('rien de lisible : liste vide', mnVideos('https://vimeo.com/1') === [] && mnVideos(null) === []);
$defFo = mnFormationsDefaut(__DIR__ . '/../src/data/manuel_formations.json');
$v25Fo = array_column(json_decode((string) file_get_contents(__DIR__ . '/../src/data/referentiel_v25.json'), true)['formations'], 'code');
// Plus celles que le réseau ajoute hors de l'Excel (src/data/formations_reseau.json).
$v25Fo = array_merge($v25Fo, array_column(json_decode((string) file_get_contents(__DIR__ . '/../src/data/formations_reseau.json'), true)['formations'], 'code'));
verifie('formations : chacune des ' . count($v25Fo) . ' formations du référentiel a sa partie par défaut, et rien d’autre', array_keys($defFo) === $v25Fo || (count($defFo) === count($v25Fo) && !array_diff($v25Fo, array_keys($defFo))), json_encode(array_diff($v25Fo, array_keys($defFo))));
$tmp = tempnam(sys_get_temp_dir(), 'fores');
file_put_contents($tmp, json_encode(['formations' => [['code' => 'FO-54', 'nom' => 'Trésorerie'], ['code' => 'FO-01', 'nom' => 'Doublon'], ['code' => 'X-1', 'nom' => 'Mauvais code'], ['code' => 'FO-55', 'nom' => ' ']]]));
verifie('formations du réseau : ajoutées après le v25, sans doublon ni code invalide ni nom vide', array_column(pcFormationsReseau(['FO-01'], $tmp), 'code') === ['FO-54']);
unlink($tmp);
verifie('formations du réseau : FO-54 « Gestion de trésorerie » dans le référentiel chargé', in_array('FO-54', array_column(pcV25()['formations'], 'code'), true));
$parPartie = array_count_values($defFo);
verifie('formations : les quatre parties sont garnies', count($parPartie) === 4 && min($parPartie) >= 1, json_encode($parPartie));
verifie('formations : celles du candidat franchisé avant l’ouverture, la stratégie d’ouverture à l’ouverture', $defFo['FO-31'] === 'preouverture' && $defFo['FO-37'] === 'preouverture' && $defFo['FO-38'] === 'ouverture' && $defFo['FO-01'] === 'operationnel' && $defFo['FO-39'] === 'formation');
verifie('formations : fichier absent = aucune répartition (rien d’inventé)', mnFormationsDefaut('/nulle/part.json') === []);
$sup = mnSupports([['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'titre' => ' Vidéo ', 'etape' => 0], ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'etape' => 0],
    ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'etape' => null], ['url' => 'https://x.be/guide.pdf', 'etape' => '3'], ['url' => 'https://x.be/a.pdf', 'etape' => 12],
    ['url' => 'javascript:alert(1)'], ['url' => 'http://x.be/b.pdf'], 'https://x.be/c.pdf']);
verifie('supports : https seulement, une même adresse par étape une seule fois, étape 0 à 9 sinon la formation entière',
    array_map(static fn ($x) => [$x['url'], $x['etape']], $sup) === [['https://www.youtube.com/watch?v=aqz-KE-bpKQ', 0], ['https://www.youtube.com/watch?v=aqz-KE-bpKQ', null], ['https://x.be/guide.pdf', 3], ['https://x.be/a.pdf', null]], json_encode($sup));
verifie('supports : titre rogné, vingt au plus', $sup[0]['titre'] === 'Vidéo' && count(mnSupports(array_map(static fn ($i) => ['url' => 'https://x.be/' . $i . '.pdf'], range(1, 30)))) === 20);

echo "\n$ok ok, $ko KO\n";
exit($ko === 0 ? 0 : 1);
