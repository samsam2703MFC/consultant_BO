#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du fil du franchisé (src/fil_franchise.php, Franchisés › Suivi des franchisés › Fil du franchisé) — base
 * SQLite en mémoire, dossier privé temporaire.
 *
 *   php bin/tests_fil.php
 *
 * Les règles pures (étiquettes, dates, titre d'un fil, type, code d'une semaine, lignes du dossier), l'entrée et ses
 * pièces (rangées dans le dossier privé, refusées sans rien écrire), les fils (un problème de gestion en ouvre un, une
 * suite le ferme, l'effacer le rouvre, un fil vide disparaît), la lecture d'un magasin (entrées, visites, rapports,
 * client mystère, filtre de l'année), la carte des magasins et l'import rétroactif (src/fil_import.php).
 */
$ok = 0; $ko = 0;
// En ligne de commande, le code HTTP ne se pose pas après une sortie : l'avertissement ne dit rien du code testé.
set_error_handler(static fn (int $n, string $m): bool => str_contains($m, 'http_response_code'), E_WARNING);
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
final class Db {
  static ?PDO $p = null;
  static function pdo(): PDO { if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return self::$p; }
  static function fx(string $q): string {
    $q = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
    $q = (string) preg_replace('/,\s*(UNIQUE )?KEY \w+ \([^)]*\)/', '', $q);
    return (string) preg_replace('/\)\s*ENGINE=.*$/s', ')', $q);
  }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function body(): array { return $GLOBALS['corps'] ?? []; }
function viMagasins(): array
{
    return ['5' => ['id' => '5', 'nom' => 'Atelier by Harmonie - Sombreffe', 'court' => 'Sombreffe'],
        '4' => ['id' => '4', 'nom' => 'Atelier by - Halle', 'court' => 'Halle']];
}
$prive = sys_get_temp_dir() . '/fil-banc-' . getmypid();
putenv('COCKPIT_PRIVE=' . $prive);
require __DIR__ . '/../src/fil_franchise.php';

// Une image PNG de 2 × 2, lisible par getimagesizefromstring.
$im = imagecreatetruecolor(2, 2); ob_start(); imagepng($im); $png = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());

echo "Règles\n";
verifie('étiquettes : connues seulement, sans doublon, dans l’ordre du réglage', filTags(['bonne', 'x', 'gestion', 'bonne']) === ['gestion', 'bonne']);
verifie('étiquettes : la forme « a,b » de la base', filTags('produit,gestion') === ['gestion', 'produit']);
verifie('date et heure : la forme du champ datetime-local', filDateHeure('2026-11-19T09:40') === '2026-11-19 09:40:00');
verifie('date seule : midi', filDateHeure('2026-11-19') === '2026-11-19 12:00:00');
verifie('date impossible : refusée', filDateHeure('2026-02-30 10:00') === null && filDateHeure('demain') === null);
verifie('titre d’un fil : la première phrase', filTitreDe('Fond de caisse encodé à 0 € à l’ouverture. Troisième fois.') === 'Fond de caisse encodé à 0 € à l’ouverture');
verifie('titre d’un fil : 120 caractères au plus', mb_strlen(filTitreDe(str_repeat('mot ', 60))) === 120);
verifie('type : « appel » demandé', filTypeDe('appel', []) === 'appel');
verifie('type : une capture l’emporte sur une photo', filTypeDe('note', ['photo', 'capture']) === 'capture' && filTypeDe(null, ['photo']) === 'photo' && filTypeDe(null, []) === 'note');
verifie('semaine : le problème d’abord, puis la visite', filCodeSemaine(['bonne', 'gestion'], 2, true) === 'gv' && filCodeSemaine([], 1, false) === 'n' && filCodeSemaine([], 0, false) === '');
verifie('dossier : date, sens, texte', filLignesDossier([['le' => '2026-11-18 16:05:00', 'type' => 'capture', 'texte' => "Invendus\n deux fois la cible", 'tags' => 'gestion,finances']])
    === ['18/11 · Problème de gestion · Invendus deux fois la cible']);
verifie('lundi d’une semaine', filLundi('2026-11-19') === '2026-11-16' && filLundi('2026-11-16') === '2026-11-16');

echo "Entrées et fils\n";
$GLOBALS['corps'] = ['shop' => '5', 'texte' => 'Fond de caisse encodé à 0 € à l’ouverture.', 'tags' => ['gestion', 'finances'], 'le' => '2026-11-03T06:58', 'epingle' => true, 'auteur' => 'Loic V.'];
$a = wr_fil_entree_creer();
verifie('une note « Problème de gestion » est enregistrée', ($a['ok'] ?? false) && $a['entree']['tags'] === ['gestion', 'finances'] && $a['entree']['epingle'] === true && $a['entree']['auteur'] === 'Loic V.', json_encode($a));
$fil = (int) ($a['filOuvert'] ?? 0);
$f = Db::row('SELECT * FROM con_fil WHERE id = ?', [$fil]);
verifie('… et ouvre un fil, titré par sa première phrase', $f !== null && $f['titre'] === 'Fond de caisse encodé à 0 € à l’ouverture' && $f['resolu_le'] === null);

$GLOBALS['corps'] = ['shop' => '5', 'texte' => 'Encore à 0 €.', 'tags' => ['gestion'], 'fil' => $fil, 'le' => '2026-11-17 07:12', 'pieces' => [['genre' => 'photo', 'data' => $png]]];
$b = wr_fil_entree_creer();
verifie('une suite du fil n’en ouvre pas un autre', ($b['ok'] ?? false) && $b['filOuvert'] === null && $b['entree']['fil'] === $fil && (int) Db::row('SELECT COUNT(*) n FROM con_fil')['n'] === 1);
verifie('une photo fait une entrée « photo »', $b['entree']['type'] === 'photo' && count($b['entree']['pieces']) === 1 && $b['entree']['pieces'][0]['url'] === '/fil/pieces/' . $b['entree']['pieces'][0]['id']);
$p = Db::row('SELECT * FROM con_fil_piece WHERE entree_id = ?', [$b['entree']['num']]);
verifie('la pièce est rangée dans le dossier privé, sous un nom tiré au hasard', $p !== null && preg_match('/^[0-9a-f]{24}\.png$/', (string) $p['fichier']) === 1 && is_file($prive . '/fil/' . $p['fichier']) && (int) $p['largeur'] === 2);

$GLOBALS['corps'] = ['shop' => '5', 'texte' => 'Fond de caisse juste trois jours de suite.', 'tags' => ['bonne'], 'fil' => $fil, 'ferme' => true, 'le' => '2026-11-20 07:00'];
$c = wr_fil_entree_creer();
$f = Db::row('SELECT * FROM con_fil WHERE id = ?', [$fil]);
verifie('une bonne action « ferme » le fil, à sa date', ($c['ok'] ?? false) && $f['resolu_le'] === '2026-11-20 07:00:00' && (int) $f['resolu_par'] === $c['entree']['num']);
$GLOBALS['corps'] = ['fil' => null];
wr_fil_entree_maj($c['entree']['num']);
verifie('la bonne action quitte le fil : il se rouvre', Db::row('SELECT resolu_le FROM con_fil WHERE id = ?', [$fil])['resolu_le'] === null);
$GLOBALS['corps'] = ['fil' => $fil, 'ferme' => true];
wr_fil_entree_maj($c['entree']['num']);
verifie('… et se referme quand elle y revient avec « ferme »', Db::row('SELECT resolu_le FROM con_fil WHERE id = ?', [$fil])['resolu_le'] === '2026-11-20 07:00:00');
wr_fil_entree_suppr($c['entree']['num']);
verifie('effacer l’entrée qui fermait le fil le rouvre', Db::row('SELECT resolu_le FROM con_fil WHERE id = ?', [$fil])['resolu_le'] === null);

$GLOBALS['corps'] = ['texte' => 'Encore à 0 € (vu au rapport).', 'tags' => ['gestion', 'produit', 'inconnue'], 'retirer' => [(int) $p['id']], 'le' => '2026-11-17T07:30'];
$m = wr_fil_entree_maj($b['entree']['num']);
verifie('modifier : texte, étiquettes connues, date', ($m['ok'] ?? false) && $m['entree']['texte'] === 'Encore à 0 € (vu au rapport).' && $m['entree']['tags'] === ['gestion', 'produit'] && $m['entree']['le'] === '2026-11-17 07:30');
verifie('… et retirer une pièce efface aussi son fichier', $m['entree']['pieces'] === [] && !is_file($prive . '/fil/' . $p['fichier']));

$avant = (int) Db::row('SELECT COUNT(*) n FROM con_fil_entree')['n'];
$GLOBALS['corps'] = ['shop' => '5', 'texte' => 'x', 'pieces' => [['genre' => 'photo', 'data' => $png], ['genre' => 'capture', 'data' => 'data:image/png;base64,QUJD']]];
$r = wr_fil_entree_creer();
verifie('une pièce illisible : refus, rien n’est écrit', isset($r['error']) && str_contains($r['error'], 'pièce 2') && (int) Db::row('SELECT COUNT(*) n FROM con_fil_entree')['n'] === $avant && count(glob($prive . '/fil/*') ?: []) === 0);
$GLOBALS['corps'] = ['shop' => '5', 'texte' => 'x', 'pieces' => array_fill(0, 9, ['genre' => 'photo', 'data' => $png])];
verifie('neuf pièces : refus', str_contains((string) (wr_fil_entree_creer()['error'] ?? ''), '8 pièces'));
$GLOBALS['corps'] = ['shop' => '99', 'texte' => 'x'];
verifie('un magasin inconnu : refus', (wr_fil_entree_creer()['error'] ?? '') === 'magasin inconnu');
$GLOBALS['corps'] = ['shop' => '5'];
verifie('ni texte ni pièce : refus', isset(wr_fil_entree_creer()['error']));
$GLOBALS['corps'] = ['shop' => '4', 'texte' => 'x', 'fil' => $fil];
verifie('le fil d’un autre magasin : refus', (wr_fil_entree_creer()['error'] ?? '') === 'fil inconnu pour ce magasin');
$GLOBALS['corps'] = ['shop' => '5', 'texte' => 'Appel d’Harmonie.', 'type' => 'appel', 'tags' => ['surveiller', 'equipe'], 'le' => '2026-11-16 11:30'];
verifie('un appel garde son type', (wr_fil_entree_creer()['entree']['type'] ?? '') === 'appel');

$GLOBALS['corps'] = ['resolu' => true, 'titre' => 'Fond de caisse à 0 €'];
$x = wr_fil_fil($fil);
verifie('un fil se résout et se renomme à la main', ($x['ok'] ?? false) && $x['fil']['ouvert'] === false && $x['fil']['titre'] === 'Fond de caisse à 0 €');
$GLOBALS['corps'] = ['resolu' => false];
verifie('… et se rouvre', wr_fil_fil($fil)['fil']['ouvert'] === true);

$GLOBALS['corps'] = ['shop' => '5', 'texte' => 'Prix à la main en vitrine.', 'tags' => ['gestion'], 'le' => '2026-10-26 10:45'];
$seul = wr_fil_entree_creer();
wr_fil_entree_suppr($seul['entree']['num']);
verifie('un fil dont on efface la seule entrée disparaît', Db::row('SELECT id FROM con_fil WHERE id = ?', [(int) $seul['filOuvert']]) === null);

echo "Lecture d'un magasin\n";
Db::exec('CREATE TABLE con_visite (id INTEGER PRIMARY KEY, shop_id TEXT, consultant_nom TEXT, prevu_le TEXT, debut_h TEXT, duree_min INT, statut TEXT, type_code TEXT, annoncee INT, score TEXT, rapport_envoye_le TEXT, rapport_envoye_par TEXT, rapport_lu_le TEXT)');
Db::exec('CREATE TABLE con_visite_photo (id INTEGER PRIMARY KEY, visite_id INT, shop_id TEXT)');
Db::exec('CREATE TABLE con_visite_action (id INTEGER PRIMARY KEY, visite_id INT, shop_id TEXT, titre TEXT, priorite TEXT, statut TEXT, echeance TEXT)');
Db::exec('CREATE TABLE con_scoring_msp (id INTEGER PRIMARY KEY, shop_id TEXT, obtenu REAL, maximum REAL, commentaire TEXT, le TEXT, passage_le TEXT, montrer INT)');
Db::exec("INSERT INTO con_visite VALUES (1, '5', 'Loic V.', '2026-10-26', '09:00', 160, 'terminee', 'reguliere', 1, '{\"score\":66}', '2026-10-28 17:20:00', 'Sam V.', '2026-10-29 08:00:00')");
Db::exec("INSERT INTO con_visite VALUES (2, '5', 'Loic V.', '2026-11-10', '09:30', 160, 'terminee', 'reguliere', 1, '{\"score\":72}', NULL, NULL, NULL)");
Db::exec("INSERT INTO con_visite VALUES (3, '5', 'Loic V.', '2099-11-26', '09:30', 90, 'planifiee', 'reguliere', 1, NULL, NULL, NULL, NULL)");
Db::exec("INSERT INTO con_visite VALUES (4, '4', 'Szymon M.', '2026-11-12', '10:00', 90, 'terminee', 'reguliere', 0, NULL, NULL, NULL, NULL)");
Db::exec("INSERT INTO con_visite VALUES (5, '5', 'Loic V.', '2025-12-01', '10:00', 90, 'terminee', 'reguliere', 1, '{\"score\":60}', NULL, NULL, NULL)");
Db::exec("INSERT INTO con_visite_photo (visite_id, shop_id) VALUES (2, '5'), (2, '5'), (1, '5')");
Db::exec("INSERT INTO con_visite_action (visite_id, shop_id, titre, priorite, statut, echeance) VALUES (2, '5', 'Réduire la production du soir', 'P1', 'ouvert', '2026-11-19')");
Db::exec("INSERT INTO con_scoring_msp (shop_id, obtenu, maximum, commentaire, le, passage_le, montrer) VALUES ('5', 39, 50, 'vitrine incomplète', '2026-11-04 10:00:00', '2026-11-03', 1)");
$_GET = ['shop' => '5', 'annee' => '2026'];
$F = ep_fil();
$ids = array_column($F['entrees'], 'id');
verifie('le fil mêle les entrées, les visites, les rapports et le client mystère', in_array('v1', $ids, true) && in_array('v2', $ids, true) && in_array('r1', $ids, true) && in_array('m1', $ids, true) && in_array('n2', $ids, true), implode(',', $ids));
verifie('… de l’année seulement (la visite de 2025 n’y est pas)', !in_array('v5', $ids, true));
verifie('… du plus récent au plus ancien', $F['entrees'][0]['le'] >= $F['entrees'][count($F['entrees']) - 1]['le']);
$v2 = $F['entrees'][array_search('v2', $ids, true)];
verifie('une visite : score figé, écart à la précédente, photos, points décidés', $v2['score'] === 72.0 && $v2['delta'] === 6.0 && $v2['photos'] === 2 && $v2['points'][0]['titre'] === 'Réduire la production du soir' && str_contains($v2['titre'], 'annoncée'));
$v1 = $F['entrees'][array_search('v1', $ids, true)];
verifie('la première visite de l’année se compare à celle d’avant (2025)', $v1['delta'] === 6.0);
$r1 = $F['entrees'][array_search('r1', $ids, true)];
verifie('un rapport : envoyé, lu', $r1['type'] === 'rapport' && $r1['le'] === '2026-10-28 17:20' && $r1['lu'] === '2026-10-29 08:00');
$m1 = $F['entrees'][array_search('m1', $ids, true)];
verifie('le client mystère, ramené sur 100, à la date du passage', $m1['score'] === 78 && $m1['le'] === '2026-11-03 12:00');
verifie('les fils du magasin, ouverts d’abord par date', count($F['fils']) === 1 && $F['fils'][0]['ouvert'] === true && $F['fils'][0]['entrees'] === 2);
verifie('la prochaine visite', ($F['prochaine']['id'] ?? 0) === 3);
$_GET = ['shop' => '99'];
verifie('un magasin inconnu : refus', (ep_fil()['error'] ?? '') === 'magasin inconnu');

echo "La carte des magasins\n";
$C = ep_fil_magasins();
$som = array_values(array_filter($C['magasins'], static fn ($m) => $m['id'] === '5'))[0];
$hal = array_values(array_filter($C['magasins'], static fn ($m) => $m['id'] === '4'))[0];
verifie('les magasins, par nom court', array_column($C['magasins'], 'court') === ['Halle', 'Sombreffe']);
verifie('douze semaines', count($som['semaines']) === 12 && count($hal['semaines']) === 12);
verifie('le consultant : celui des visites', $som['consultant'] === 'Loic V.' && $hal['consultant'] === 'Szymon M.');
verifie('les fils ouverts', $som['filsOuverts'] === 1 && $hal['filsOuverts'] === 0);
verifie('la prochaine visite', ($som['prochaine']['le'] ?? '') === '2099-11-26' && $hal['prochaine'] === null);

echo "Le dossier de la visite\n";
$ep = filEpinglees('5', '2026-11-01', '2026-11-30');
verifie('les épinglées du magasin, entre deux dates', count($ep) === 1 && str_contains(filLignesDossier($ep)[0], 'Problème de gestion'));

echo "Au cockpit seulement\n";
require __DIR__ . '/../../shared/src/acces.php';
verifie('aucune route du fil n’est ouverte au franchisé', !array_filter(['/fil', '/fil/magasins', '/fil/entrees', '/fil/entrees/3', '/fil/fils/2', '/fil/pieces/3'], 'accesRouteFranchise'));
verifie('la table ne garde aucun drapeau « visible »', !in_array('visible', array_column(Db::rows('PRAGMA table_info(con_fil_entree)'), 'name'), true));
$GLOBALS['corps'] = ['shop' => '5', 'texte' => 'Note interne.', 'visible' => true];
verifie('« visible » envoyé quand même : ignoré, l’entrée reste interne', !array_key_exists('visible', wr_fil_entree_creer()['entree'] ?? ['visible' => 1]));

echo "L'import rétroactif\n";
require __DIR__ . '/../src/fil_import.php';
$doc = ['shop' => '4', 'magasin' => 'Halle', 'auteur' => 'Import courrier',
    'fils' => ['loyer' => ['titre' => 'Loyer du bâtiment en retard']],
    'entrees' => [
        ['le' => '2026-10-21 10:05', 'texte' => 'Conciliation demandée à l’avocat.', 'tags' => ['finances'], 'fil' => 'loyer',
            'source' => ['type' => 'gmail', 'titre' => 'Demande de mission de conciliation', 'url' => 'https://mail.example/x'], 'confiance' => 'haute'],
        ['le' => '2026-10-02 09:12', 'texte' => 'Paiement du loyer en retard, relance du bailleur.', 'tags' => ['gestion', 'finances'], 'fil' => 'loyer',
            'source' => ['type' => 'gmail', 'titre' => 'Achterstallige betaling']],
        ['le' => '2026-11-03 16:00', 'texte' => 'Arriéré réglé.', 'tags' => ['bonne', 'finances'], 'fil' => 'loyer', 'ferme' => true],
        ['le' => '2026-05-07 12:16', 'type' => 'appel', 'texte' => 'Trois nouveaux clients B2B.', 'tags' => ['bonne', 'client']],
    ]];
[$lu, $err] = filImportLire($doc);
verifie('un fichier correct se lit', $lu !== null && $err === [], implode(' ; ', $err));
verifie('les entrées dans l’ordre des dates', array_column($lu['entrees'], 'le') === ['2026-05-07 12:16:00', '2026-10-02 09:12:00', '2026-10-21 10:05:00', '2026-11-03 16:00:00']);
verifie('la source termine le texte, sans son adresse', str_ends_with($lu['entrees'][1]['texte'], "\n\nSource : Gmail — « Achterstallige betaling »")
    && !str_contains(implode(' ', array_column($lu['entrees'], 'texte')), 'mail.example'));
$faux = static fn (array $e): array => filImportLire(['shop' => '4', 'entrees' => [$e]])[1];
verifie('une étiquette inconnue : refus', (bool) $faux(['le' => '2026-03-01', 'texte' => 'x', 'tags' => ['gestionn']]));
verifie('deux étiquettes de sens : refus', (bool) $faux(['le' => '2026-03-01', 'texte' => 'x', 'tags' => ['gestion', 'bonne']]));
verifie('une photo sans pièce : refus', (bool) $faux(['le' => '2026-03-01', 'type' => 'photo', 'texte' => 'x']));
verifie('une date illisible : refus', (bool) $faux(['le' => 'mars', 'texte' => 'x']));
verifie('un fil ouvert sans « gestion » : refus', (bool) $faux(['le' => '2026-03-01', 'texte' => 'x', 'tags' => ['finances'], 'fil' => 'k']));
verifie('« ferme » sans fil : refus', (bool) $faux(['le' => '2026-03-01', 'texte' => 'x', 'ferme' => true]));
$avant = (int) Db::row('SELECT COUNT(*) n FROM pil_fil_entree')['n'];
$blanc = filImportAppliquer($lu, false);
verifie('à blanc : rien n’est écrit', (int) Db::row('SELECT COUNT(*) n FROM pil_fil_entree')['n'] === $avant
    && $blanc['nouvelles'] === 4 && $blanc['filsOuverts'] === ['loyer'] && $blanc['filsFermes'] === ['loyer']);
$cr = filImportAppliquer($lu, true);
$loyer = Db::row('SELECT * FROM pil_fil WHERE shop_id = ? AND titre = ?', ['4', 'Loyer du bâtiment en retard']);
$lignes = Db::rows('SELECT * FROM pil_fil_entree WHERE shop_id = ? AND auteur = ? ORDER BY le', ['4', 'Import courrier']);
verifie('écrit : quatre entrées, à leur date, avec leur auteur', $cr['erreur'] === null && $cr['nouvelles'] === 4 && count($lignes) === 4
    && $lignes[0]['le'] === '2026-05-07 12:16:00' && $lignes[0]['type'] === 'appel');
verifie('le fil : ouvert par le problème, sous le titre du fichier', $loyer !== null && $loyer['ouvert_le'] === '2026-10-02 09:12:00');
verifie('les suites sont dans le fil, la dernière le ferme', count(array_filter($lignes, static fn ($l) => (int) $l['fil_id'] === (int) $loyer['id'])) === 3
    && $loyer['resolu_le'] === '2026-11-03 16:00:00' && (int) $loyer['resolu_par'] === (int) $lignes[3]['id']);
$encore = filImportAppliquer($lu, true);
verifie('rejouer : rien n’est écrit deux fois', $encore['nouvelles'] === 0 && $encore['deja'] === 4
    && (int) Db::row('SELECT COUNT(*) n FROM pil_fil WHERE titre = ?', ['Loyer du bâtiment en retard'])['n'] === 1);
verifie('un magasin inconnu : arrêt', filImportAppliquer(['shop' => '99', 'magasin' => 'x', 'titres' => [], 'entrees' => []], true)['erreur'] !== null);

// Le banc s'efface.
foreach (glob($prive . '/fil/*') ?: [] as $x) { @unlink($x); }
@rmdir($prive . '/fil'); @rmdir($prive);
echo "\n$ok réussi(s), $ko en échec\n";
exit($ko > 0 ? 1 : 0);
