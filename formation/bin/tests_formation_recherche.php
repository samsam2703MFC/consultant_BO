#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la recherche de la formation (src/formation_recherche.php, 22/11/2026) — base SQLite en mémoire.
 *
 *   php formation/bin/tests_formation_recherche.php
 *
 * L'index : les documents publiés et à valider (jamais les brouillons), une formation continue seulement publiée, le
 * catalogue de chaque type, le texte des étapes (pour chercher dedans), les premières étapes, la première photo ; les
 * six catalogues et leurs nombres ; les formations et les domaines du référentiel ; la route ouverte aux magasins.
 */
$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
final class Db {
  static ?PDO $p = null;
  static function config(): array { return []; }
  static function pdo(): PDO { if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return self::$p; }
  static function fx(string $q): string {
    $q = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
    $q = (string) preg_replace('/,UNIQUE KEY \w+ \(([^)]*)\)/', ',UNIQUE ($1)', $q);
    $q = (string) preg_replace('/,KEY \w+ \([^)]*\)/', '', $q); return (string) preg_replace('/\) ENGINE=.*$/', ')', $q);
  }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
$GLOBALS['journal'] = [];
function journalAdd(...$a) { $GLOBALS['journal'][] = $a; }
function setting(string $k, $d = null) { return $d; }
function body(): array { return $GLOBALS['corps'] ?? []; }
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;   // en ligne : accès ouvert (13/11/2026)
function accesMode(): string { return $GLOBALS['mode']; }
function accesIdentite(): ?array { return $GLOBALS['identite']; }
require __DIR__ . '/../src/sop.php';
require __DIR__ . '/../src/formation_recherche.php';
// Le référentiel (src/referentiel.php) : deux formations et un domaine, comme /formation/ref.
function ep_rf_ref(): array
{
    return ['formations' => [['code' => 'FO-12', 'fr' => 'Formation Cuisson & gestion four', 'famille' => 'production', 'postes' => ['cuisson'], 'phases' => ['pre'], 'competences' => ['C1', 'C2']],
        ['code' => 'FO-01', 'fr' => 'Formation Vendeur (initiale + recyclage)', 'famille' => 'vente', 'postes' => ['vente'], 'phases' => [], 'competences' => []]],
        'domaines' => [['code' => 'D5', 'fr' => 'Opérations & Production', 'souscats' => [['fr' => 'Production'], ['fr' => 'Cuisson']]]]];
}

Db::pdo()->exec("CREATE TABLE for_sop (id TEXT PRIMARY KEY, code TEXT, type TEXT, dossier TEXT, poste TEXT, partie TEXT, moment TEXT, acces TEXT, statut TEXT, version TEXT,
  version_revalider TEXT, langue_origine TEXT, trad_fr TEXT, trad_nl TEXT, competence_id TEXT, competence_nom TEXT, titre_fr TEXT, titre_nl TEXT, objectif_fr TEXT, objectif_nl TEXT,
  duree TEXT, nb_etapes INT, nb_photos INT, ordre INT, source TEXT, maj_le TEXT, maj_par TEXT, categorie TEXT)");
Db::pdo()->exec('CREATE TABLE for_sop_version (sop_id TEXT, version TEXT, contenu TEXT, note TEXT, revalider INT, publie_le TEXT, publie_par TEXT, PRIMARY KEY (sop_id, version))');
$t = static fn (string $fr, string $nl = '') => ['fr' => $fr, 'nl' => $nl];
$sop = static function (string $id, string $code, string $type, string $statut, string $titre, array $etapes, array $pc = [], string $poste = 'production', ?string $cat = null) use ($t): void {
    Db::exec('INSERT INTO for_sop (id, code, type, statut, version, titre_fr, poste, moment, acces, ordre, maj_le, categorie) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$id, $code, $type, $statut, '1.0', $titre, $poste, 'journee', 'tous', 1, '2026-11-01', $cat]);
    $c = ['titre' => $t($titre), 'objectif' => $t('Objectif de ' . $titre), 'pour' => $t('Le poste'), 'duree' => '20 min',
        'sections' => [['titre' => $t('Préparer'), 'etapes' => array_map(static fn ($e, $i) => ['numero' => $i + 1, 'titre' => $t($e[0]), 'texte' => $t($e[1]), 'critique' => $e[2] ?? false, 'photo' => $e[3] ?? null], $etapes, array_keys($etapes))]],
        'points_critiques' => array_map($t, $pc), 'quiz' => []];
    Db::exec('INSERT INTO for_sop_version (sop_id, version, contenu, publie_le) VALUES (?,?,?,?)', [$id, '1.0', json_encode($c, JSON_UNESCAPED_UNICODE), '2026-11-01']);
};
$sop('baguettes', '0.2.05', 'SOP', 'publiee', 'Cuisson des baguettes et des pains', [['Vérifier le programme', 'Le programme du four selon la fiche.', false, 'baguettes-1.jpg'], ['Préchauffer', 'Ne jamais enfourner avant la <b>température</b>.', true], ['Enfourner', 'Lancer le programme de cuisson.'], ['Défourner', 'Sur grille.'], ['Ranger', 'En vitrine.']], ['Température atteinte avant d’enfourner']);
$sop('art-haccp', '0.0.13', 'Article', 'publiee', 'HACCP & Hygiène', [['Les températures', 'Les températures de cuisson et de remise en température.']]);
$sop('chk-ouv', '1.1.01', 'Checklist', 'a_valider', 'Ouverture de la boutique', [['Premières cuissons', 'Lancées avant l’ouverture.']], [], 'vente');
$sop('programmes', '0.0.02', 'Fiche', 'publiee', 'Programmes de cuisson et de pousse', [['Le tableau', 'Produit par produit.']]);
$sop('brouillon', '0.2.99', 'SOP', 'brouillon', 'Un brouillon de cuisson', [['x', 'y']]);
$sop('fc001', 'FC-001', 'FC', 'publiee', 'Un nouveau produit arrive', [['La fiche technique', 'Les programmes de cuisson.']], [], 'production', 'nouveau_produit');
$sop('fc002', 'FC-002', 'FC', 'a_valider', 'Une formation continue pas encore publiée', [['x', 'y']]);
Db::exec("INSERT INTO for_sop (id, code, type, statut, version, titre_fr, ordre, maj_le) VALUES ('sans-contenu', '0.0.70', 'Article', 'publiee', '9.9', 'Un article sans version', 1, '2026-11-01')");

$I = ep_formation_recherche();
$par = array_column($I['docs'], null, 'id');
verifie('les documents : publiés et à valider, jamais les brouillons ; une formation continue seulement publiée', isset($par['baguettes'], $par['chk-ouv'], $par['fc001']) && !isset($par['brouillon']) && !isset($par['fc002']), implode(',', array_keys($par)));
verifie('le catalogue de chaque type', $par['baguettes']['cat'] === 'std' && $par['art-haccp']['cat'] === 'man' && $par['chk-ouv']['cat'] === 'chk' && $par['programmes']['cat'] === 'std' && $par['fc001']['cat'] === 'fc');
$b = $par['baguettes'];
verifie('le texte des étapes, sans balise, et les points critiques : on cherche dedans', str_contains($b['texte'], 'Lancer le programme de cuisson') && str_contains($b['texte'], 'avant la température') && !str_contains($b['texte'], '<b>') && str_contains($b['texte'], 'Température atteinte'));
verifie('les quatre premières étapes, le point critique dit', count($b['etapes']) === 4 && $b['etapes'][1]['critique'] === true && $b['nbEtapes'] === 5 && $b['etapes'][0]['titre'] === 'Vérifier le programme');
verifie('la première photo, le titre, le code, l’objectif', $b['photo'] === 'baguettes-1.jpg' && $b['titre'] === 'Cuisson des baguettes et des pains' && $b['code'] === '0.2.05' && $b['objectif'] === 'Objectif de Cuisson des baguettes et des pains');
verifie('la catégorie d’une formation continue', $par['fc001']['categorie'] === 'nouveau_produit');
verifie('un document sans version publiée : gardé, par son titre', ($par['sans-contenu']['titre'] ?? '') === 'Un article sans version' && $par['sans-contenu']['texte'] === '');
$C = array_column($I['catalogues'], null, 'cle');
verifie('les six catalogues, leur couleur de couverture', count($C) === 6 && $C['man']['couleur'] === '#8D1D2C' && $C['std']['couleur'] === '#222222' && $C['chk']['couleur'] === '#78554B' && $C['res']['couleur'] === '#F2C9A0');
verifie('leurs nombres', $C['std']['n'] === 2 && $C['man']['n'] === 2 && $C['chk']['n'] === 1 && $C['fc']['n'] === 1 && $C['fo']['n'] === 2 && $C['res']['n'] === 1, json_encode(array_column($I['catalogues'], 'n', 'cle')));
verifie('les formations du référentiel : titre, famille, postes, compétences', $I['formations'][0]['code'] === 'FO-12' && $I['formations'][0]['titre'] === 'Formation Cuisson & gestion four' && $I['formations'][0]['famille'] === 'production' && $I['formations'][0]['competences'] === 2);
verifie('les domaines et leurs sous-catégories', $I['domaines'][0]['titre'] === 'Opérations & Production' && $I['domaines'][0]['souscats'] === ['Production', 'Cuisson']);
verifie('les nombres par type (l’accueil)', $I['types']['SOP'] === 1 && $I['types']['Article'] === 2 && $I['types']['Checklist'] === 1);
verifie('la route est ouverte aux magasins (ACCES_FRANCHISE)', str_contains((string) file_get_contents(__DIR__ . '/../../shared/src/acces.php'), "'/formation/recherche'"));
verifie('la route est branchée', str_contains((string) file_get_contents(__DIR__ . '/../../shared/public/api/index.php'), "'/formation/recherche'           => ep_formation_recherche()"));
echo "\n$ok ok, $ko KO\n";
exit($ko ? 1 : 0);
