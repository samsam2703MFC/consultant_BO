#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la formation continue (src/formation_continue.php, 22/11/2026) — base SQLite en mémoire.
 *
 *   php formation/bin/tests_formation_continue.php
 *
 * Créer (catégorie, statut brouillon, code FC-nnn, photos par catégorie), régler, diffuser (seulement validée, une fois
 * par boutique), le magasin (Nouveautés, « nouveau » jusqu'à l'ouverture, sa seule boutique), adapter pour une boutique,
 * la grille des photos (manquante, en attente, conforme, jours ajoutés), l'achèvement automatique, la validation (qui,
 * quand, l'archive au coffre-fort), renvoyer, le Professeur (la version de la boutique), les droits.
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
require __DIR__ . '/../src/formation_continue.php';
require __DIR__ . '/../../consultant/src/ca_mail.php';
require __DIR__ . '/../../consultant/src/cq_mail.php';
require __DIR__ . '/../../consultant/src/documents.php';
require __DIR__ . '/../../consultant/src/coffre.php';
$envoie = static function (array $b): void { $GLOBALS['corps'] = $b; };   // le corps JSON de la requête (body())
$code = static fn () => http_response_code();

// Le Manuel tel qu'il est en ligne : for_sop (avec ses colonnes du référentiel), for_sop_version, une SOP déjà là.
Db::pdo()->exec("CREATE TABLE for_sop (id TEXT PRIMARY KEY, code TEXT, type TEXT, dossier TEXT, poste TEXT, partie TEXT, moment TEXT, acces TEXT, statut TEXT, version TEXT,
  version_revalider TEXT, langue_origine TEXT, trad_fr TEXT, trad_nl TEXT, competence_id TEXT, competence_nom TEXT, titre_fr TEXT, titre_nl TEXT, objectif_fr TEXT, objectif_nl TEXT,
  duree TEXT, nb_etapes INT, nb_photos INT, ordre INT, source TEXT, maj_le TEXT, maj_par TEXT, souscat TEXT, phases TEXT, postes TEXT, competences TEXT, formations TEXT, checklists TEXT)");
Db::pdo()->exec('CREATE TABLE for_sop_version (sop_id TEXT, version TEXT, contenu TEXT, note TEXT, revalider INT, publie_le TEXT, publie_par TEXT, PRIMARY KEY (sop_id, version))');
Db::exec("INSERT INTO for_sop (id, code, type, statut, version, titre_fr, ordre, maj_le) VALUES ('ouv-mag', '0.1.01', 'SOP', 'publiee', '1.0', 'Ouverture du magasin', 1, '2026-11-01')");
Db::pdo()->exec('CREATE TABLE shops (id INT, name TEXT, active INT)');
$A = 990201; $B = 990202;
Db::exec('INSERT INTO shops VALUES (?, ?, 1), (?, ?, 1)', [$A, 'Atelier by - Gosselies', $B, 'Atelier by - Corbais']);
ensureFormationContinue();
$auj = date('Y-m-d');
$jour = static fn (int $n) => date('Y-m-d', strtotime($auj . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));

// 1. Créer : catégorie, brouillon, code, photos par catégorie.
$envoie(['titre' => ['fr' => 'Pistolet poulet-curry du traiteur', 'nl' => ''], 'categorie' => 'nouveau_produit', 'objectif' => 'Lancer le pistolet.',
    'contenu' => ['sections' => [['titre' => ['fr' => 'Les étapes', 'nl' => ''], 'etapes' => [
        ['numero' => '1', 'texte' => ['fr' => 'Réception : poulet ≤ 4 °C.', 'nl' => '']],
        ['numero' => '2', 'texte' => ['fr' => 'Cuisson au four à sole, 230 °C, 8 min.', 'nl' => ''], 'critique' => true]]]], 'points_critiques' => [['fr' => 'Allergènes affichés avant la vente.', 'nl' => '']]],
    'formations' => ['FO-10', 'pas-une-fo'], 'postes' => ['P2', 'P6']]);
$c = wr_fc_creer();
$fc = $c['id'] ?? '';
$r = Db::row('SELECT * FROM for_sop WHERE id = ?', [$fc]);
verifie('créée : type FC, code FC-001, brouillon, partie « formation », nature « continue »', ($c['code'] ?? '') === 'FC-001' && $r['type'] === 'FC' && $r['statut'] === 'brouillon' && $r['partie'] === 'formation' && $r['nature'] === 'continue', json_encode($c));
verifie('un nouveau produit demande 14 jours de photo ; les FO hors format sont écartées', (int) $r['photos_jours'] === 14 && json_decode((string) $r['formations'], true) === ['FO-10'] && (int) $r['nb_etapes'] === 2);
$envoie(['titre' => ['fr' => 'Étiquette allergènes : la règle FIC 2027'], 'categorie' => 'connaissance']);
$c2 = wr_fc_creer();
verifie('une connaissance ne demande pas de photo ; le code suit (FC-002)', ($c2['code'] ?? '') === 'FC-002' && (int) Db::row('SELECT photos_jours FROM for_sop WHERE id = ?', [$c2['id']])['photos_jours'] === 0);
$envoie(['titre' => ['fr' => 'Sans catégorie']]); $x = @wr_fc_creer();
verifie('sans catégorie : refusé', str_starts_with((string) ($x['error'] ?? ''), 'catégorie'));

// 2. La liste du siège.
$L = ep_fc_liste();
$cat = array_column($L['categories'], 'n', 'cle'); $st = array_column($L['statuts'], 'n', 'cle');
verifie('la liste : les catégories et les statuts comptés', $cat['nouveau_produit'] === 1 && $cat['connaissance'] === 1 && $st['brouillon'] === 2 && count($L['formations']) === 2, json_encode([$cat, $st]));

// 3. Diffuser : seulement validée, une fois par boutique.
$envoie([]); $x = @wr_fc_diffuser($fc);
verifie('une formation en brouillon ne part pas chez les franchisés', ($x['error'] ?? '') === 'seule une formation validée part chez les franchisés');
$envoie(['statut' => 'publiee']); $x = wr_fc_regler($fc);
verifie('validée au réseau (statut « publiee », affiché « Validée »)', ($x['formation']['statutLib'] ?? '') === 'Validée');
$envoie(['shops' => [(string) $A, (string) $B], 'echeance' => $jour(10)]); $x = wr_fc_diffuser($fc);
verifie('diffusée à deux boutiques', ($x['diffusees'] ?? []) === [$A, $B]);
$envoie(['shops' => [(string) $A]]); $x = wr_fc_diffuser($fc);
verifie('une boutique qui l’a déjà n’en reçoit pas une seconde', ($x['diffusees'] ?? null) === [] && ($x['deja'] ?? 0) === 1);
$dA = (int) Db::row('SELECT id FROM for_fc_dossier WHERE shop_id = ? AND sop_id = ?', [$A, $fc])['id'];
$dB = (int) Db::row('SELECT id FROM for_fc_dossier WHERE shop_id = ? AND sop_id = ?', [$B, $fc])['id'];

// 4. Le magasin : Nouveautés, « nouveau » jusqu'à l'ouverture.
$_GET = ['shop' => (string) $A]; $M = ep_fc_magasin();
verifie('les Nouveautés : la formation, sa catégorie, sa date, un lien direct', count($M['nouveautes']) === 1 && $M['nouveautes'][0]['categorieLib'] === 'Nouveau produit'
    && $M['nouveautes'][0]['date'] === $auj && $M['nouveautes'][0]['lien'] === '?shop=' . $A . '&vue=formation&fc=' . $dA && $M['nouveautes'][0]['nouveau'] === true, json_encode($M['nouveautes']));
verifie('une formation pas encore validée au réseau n’est jamais chez le magasin', !in_array($c2['id'], array_column($M['dossiers'], 'fc'), true));
$_GET = ['shop' => (string) $A, 'id' => (string) $dA]; $F = ep_fc_magasin_formation();
verifie('ouverte par le magasin : le contenu du modèle, plus « nouveau »', count($F['contenu']['sections'][0]['etapes']) === 2 && Db::row('SELECT ouvert_le FROM for_fc_dossier WHERE id = ?', [$dA])['ouvert_le'] !== null);

// 5. Les droits : un magasin ne lit que son dossier.
$GLOBALS['mode'] = 'console'; $GLOBALS['identite'] = ['role' => 'franchise', 'shops' => [(string) $A], 'nom' => 'Gosselies'];
$_GET = ['shop' => (string) $B]; $x = @ep_fc_magasin();
verifie('le magasin A ne lit pas le dossier de B (403)', ($x['error'] ?? '') === 'ce magasin n’est pas sur votre session');
$_GET = ['shop' => (string) $A, 'id' => (string) $dB]; $x = @ep_fc_magasin_formation();
verifie('ni une formation de B par son id (404)', ($x['error'] ?? '') === 'formation inconnue pour ce magasin');
verifie('le Professeur de A ne lit pas la formation de B', fcProfesseurLire($A, 'dossier-' . $dB) === null && fcProfesseurLire($A, 'dossier-' . $dA) !== null);
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;

// 6. Le consultant adapte pour une boutique ; le modèle ne bouge pas.
$envoie(['id' => $dA, 'contenu' => ['sections' => [['titre' => ['fr' => 'Les étapes', 'nl' => ''], 'etapes' => [
        ['numero' => '1', 'texte' => ['fr' => 'Réception : poulet ≤ 4 °C.', 'nl' => '']],
        ['numero' => '2', 'texte' => ['fr' => 'Cuisson au four ventilé, programme P4, 7 min.', 'nl' => ''], 'critique' => true]]]]],
    'assignes' => ['Julie R.', 'Karim B.', 'Julie R.'], 'echeance' => $jour(14), 'note' => 'pas de four à sole au traiteur', 'par' => 'Nadia K.']);
$x = wr_fc_adapter();
verifie('adaptée : la version de la boutique, qui la suit, l’échéance, la note', str_contains(json_encode($x['dossier']['contenu'], JSON_UNESCAPED_UNICODE), 'programme P4')
    && $x['dossier']['assignes'] === ['Julie R.', 'Karim B.'] && $x['dossier']['echeance'] === $jour(14) && $x['dossier']['adaptation']['par'] === 'Nadia K.');
verifie('le modèle du réseau et l’autre boutique gardent le four à sole', str_contains(json_encode(fcContenuModele(fcSops($fc)[$fc]), JSON_UNESCAPED_UNICODE), 'four à sole')
    && str_contains(json_encode(fcContenuDossier(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [$dB]), fcSops($fc)[$fc]), JSON_UNESCAPED_UNICODE), 'four à sole'));
$P = fcProfesseurLire($A, 'dossier-' . $dA);
verifie('le Professeur lit la version de la boutique et sait qu’elle est adaptée', str_contains($P['texte'], 'programme P4') && str_contains((string) $P['adaptation'], 'Nadia K.') && str_contains((string) $P['adaptation'], 'four à sole'));
verifie('le dossier du Professeur : la référence, la catégorie, l’avancement', fcProfesseurDossier($A)[0]['ref'] === 'dossier-' . $dA && fcProfesseurDossier($A)[0]['categorie'] === 'Nouveau produit');

// 7. La grille des photos : manquante, en attente, conforme ; les jours ajoutés.
Db::exec('UPDATE for_fc_dossier SET photos_du = ? WHERE id = ?', [$jour(-3), $dA]);
$tmp = sys_get_temp_dir() . '/fc-banc-' . getmypid() . '.jpg'; file_put_contents($tmp, 'JPEG');
$d = Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [$dA]);
$p1 = fcGarderPhoto($d, $tmp, 'jpg', 4, ['nom' => 'M. Lambert', 'role' => 'franchise'], false);   // aujourd'hui
Db::exec("INSERT INTO for_fc_photo (dossier_id, jour, fichier, mime, octets, depose_le, avis) VALUES (?, ?, 'x.jpg', 'image/jpeg', 4, ?, 'conforme')", [$dA, $jour(-3), $jour(-3) . ' 10:00:00']);
Db::exec("INSERT INTO for_fc_photo (dossier_id, jour, fichier, mime, octets, depose_le, avis) VALUES (?, ?, 'y.jpg', 'image/jpeg', 4, ?, 'a_refaire')", [$dA, $jour(-2), $jour(-2) . ' 10:00:00']);
$G = fcPhotosGrille(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [$dA]), 14, $auj);
$etats = array_column($G['grille'], 'etat');
verifie('la grille : conforme, à refaire, manquante, en attente (aujourd’hui)', array_slice($etats, 0, 4) === ['conforme', 'a_refaire', 'manquante', 'en_attente'], json_encode(array_slice($etats, 0, 5)));
verifie('un jour refusé et un jour manqué ajoutent deux cases (14 + 2)', count($G['grille']) === 16 && $G['conformes'] === 1 && $G['refusees'] === 1 && $G['manquantes'] === 1);
$x = fcGarderPhoto(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [$dA]), $tmp, 'jpg', 4, ['nom' => 'M. Lambert', 'role' => 'franchise'], false);
verifie('la photo du jour refaite remplace celle du matin', isset($x['id']) && (int) Db::row('SELECT COUNT(*) AS n FROM for_fc_photo WHERE dossier_id = ? AND jour = ?', [$dA, $auj])['n'] === 1);
$envoie(['photo' => $x['id'], 'avis' => 'conforme', 'par' => 'Nadia K.']); $av = wr_fc_avis();
verifie('le consultant relit : conforme', ($av['dossier']['photos']['conformes'] ?? 0) === 2);
$x = @fcGarderPhoto(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [$dA]), $tmp, 'jpg', 4, ['nom' => 'M. Lambert', 'role' => 'franchise'], false);
verifie('une photo conforme ne se remplace plus', ($x['error'] ?? '') === 'la photo d’aujourd’hui est déjà conforme');
$_POST = ['shop' => (string) $B, 'id' => (string) Db::row('SELECT id FROM for_fc_dossier WHERE shop_id = ? AND sop_id = ?', [$B, $fc])['id']];
Db::exec('INSERT INTO for_fc_dossier (shop_id, sop_id, statut, diffuse_le, maj_le) VALUES (?, ?, ?, ?, ?)', [$A, $c2['id'], 'en_cours', $auj . ' 08:00:00', $auj . ' 08:00:00']);
Db::exec("UPDATE for_sop SET statut = 'publiee' WHERE id = ?", [$c2['id']]);
$_POST = ['shop' => (string) $A, 'id' => (string) Db::row('SELECT id FROM for_fc_dossier WHERE shop_id = ? AND sop_id = ?', [$A, $c2['id']])['id']];
$x = @wr_fc_photo();
verifie('une connaissance refuse la photo (409)', ($x['error'] ?? '') === 'cette formation ne demande pas de photo');

// 8. Tout fait : l'équipe, la mise en place, 14 photos conformes → « à valider », tracé.
Db::exec('DELETE FROM for_fc_photo WHERE dossier_id = ?', [$dA]);
for ($i = 13; $i >= 0; $i--) { Db::exec("INSERT INTO for_fc_photo (dossier_id, jour, fichier, mime, octets, depose_le, avis) VALUES (?, ?, 'z.jpg', 'image/jpeg', 4, ?, 'conforme')", [$dA, $jour(-$i), $jour(-$i) . ' 10:00:00']); }
Db::exec('UPDATE for_fc_dossier SET photos_du = ? WHERE id = ?', [$jour(-13), $dA]);
foreach (['Julie R.', 'Karim B.'] as $p) { $envoie(['shop' => $A, 'id' => $dA, 'personne' => $p, 'lu' => true, 'quiz' => true, 'vu' => true]); $x = wr_fc_avancement(); }
verifie('l’équipe faite, sans la mise en place : toujours en cours', ($x['dossier']['statut'] ?? '') === 'en_cours' && $x['dossier']['avancement']['pct'] < 100, json_encode($x['dossier']['avancement'] ?? null));
$envoie(['shop' => $A, 'id' => $dA]); $x = wr_fc_mise_en_place();
verifie('tout fait : « à valider », 100 %, tracé « terminée »', ($x['dossier']['statut'] ?? '') === 'a_valider' && $x['dossier']['avancement']['pct'] === 100
    && Db::row("SELECT id FROM for_fc_trace WHERE dossier_id = ? AND action = 'terminee'", [$dA]) !== null);
$envoie(['shop' => $A, 'id' => $dA, 'personne' => 'Léa V.', 'lu' => true]); $x = wr_fc_avancement();
verifie('une personne nommée par le franchisé s’ajoute à l’équipe', in_array('Léa V.', $x['dossier']['assignes'], true));

// 9. Les repères du consultant, puis la validation : qui, quand, l'archive au coffre.
$_GET = []; $FR = ep_fc_franchises();
$fa = array_values(array_filter($FR['franchises'], static fn ($f) => $f['shop'] === (string) $A))[0] ?? null;
verifie('les repères du consultant : A a une formation à valider', $fa !== null && $fa['aValider'] === 1 && $FR['reperes']['aValider'] === 1, json_encode($fa));
$envoie(['id' => $dB]); $x = @wr_fc_valider();
verifie('un dossier en cours ne se valide pas sans « forcer »', str_starts_with((string) ($x['error'] ?? ''), 'la formation n’est pas terminée'));
$envoie(['id' => $dA, 'mot' => 'Bravo, vitrine conforme.', 'par' => 'Nadia K.']); $x = wr_fc_valider();
$dv = Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [$dA]);
verifie('validée : statut, nom du consultant, date, mot', $dv['statut'] === 'validee' && $dv['valide_par'] === 'Nadia K.' && substr((string) $dv['valide_le'], 0, 10) === $auj && $dv['valide_mot'] === 'Bravo, vitrine conforme.');
$t = array_column(fcTraces($fc, $dA), 'action');
verifie('la validation et l’archive sont tracées', in_array('validee', $t, true) && in_array('archivee', $t, true), json_encode($t));
$doc = Db::row('SELECT * FROM con_doc WHERE id = ?', [(int) ($dv['archive_doc'] ?? 0)]);
verifie('l’archive est au coffre-fort de la boutique, onglet Formation', $doc !== null && $doc['sorte'] === 'formation' && (int) $doc['shop'] === $A && (int) $doc['visible_franchise'] === 1
    && cofDocDuCoffre($A, (int) $doc['id']) !== null && cofDocDuCoffre($B, (int) $doc['id']) === null, json_encode($doc));
$f = docDossier($A) . '/' . ($doc['fichier'] ?? '');
verifie('l’archive dit qui a validé, quand, l’équipe et les photos', is_file($f) && str_contains((string) file_get_contents($f), 'Nadia K.') && str_contains((string) file_get_contents($f), '14 conformes sur 14'));
$envoie(['id' => $dA]); $x = @wr_fc_valider();
verifie('déjà validée : refusé, avec qui et quand', str_starts_with((string) ($x['error'] ?? ''), 'déjà validée le'));
$envoie(['id' => $dA, 'motif' => 'la vitrine a changé']); $x = wr_fc_renvoyer();
verifie('renvoyée en cours : la validation reste au journal', ($x['dossier']['statut'] ?? '') === 'en_cours' && in_array('validee', array_column(fcTraces($fc, $dA), 'action'), true));

// 10. Le consultant : ses seules boutiques (cadre de visite).
function vcCadre(): array { return [['shop' => '990201', 'consultant' => 'u7', 'consultantNom' => 'Nadia K.'], ['shop' => '990202', 'consultant' => 'u9', 'consultantNom' => 'Youssef B.']]; }
$_GET = ['consultant' => 'u7']; $FR = ep_fc_franchises();
verifie('« mes franchisés » : seulement les boutiques de ce consultant', array_column($FR['franchises'], 'shop') === [(string) $A] && count($FR['consultants']) === 2);

// Le banc s'efface.
@unlink($tmp);
foreach ([$A, $B] as $s) { array_map('unlink', glob(fcPhotosDossier($s) . '/*') ?: []); @rmdir(fcPhotosDossier($s)); array_map('unlink', glob(docDossier($s) . '/*') ?: []); @rmdir(docDossier($s)); }
echo "\n$ok ok, $ko KO\n";
exit($ko ? 1 : 0);
