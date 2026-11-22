#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du coffre-fort du franchisé (src/coffre.php, 22/11/2026) — base SQLite en mémoire, comme tests_documents.php.
 *
 *   php consultant/bin/tests_coffre.php
 *
 * Ce qui entre au coffre (seulement les documents « dans le coffre », jamais ceux d'avant lui), les onglets, l'état
 * d'après l'échéance, « nouveau » tant que le MAGASIN ne l'a pas ouvert, les rapports de visite envoyés, un document
 * d'une autre boutique jamais rendu ni joint à un mail, la session du magasin, le réglage du consultant (PATCH).
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
    $q = (string) preg_replace('/,UNIQUE KEY \w+ (\([^)]*\))/', ',UNIQUE $1', $q);
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
// L'accès (shared/src/acces.php) : le mode et l'identité se règlent par test.
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;   // en ligne : accès ouvert (13/11/2026)
function accesMode(): string { return $GLOBALS['mode']; }
function accesIdentite(): ?array { return $GLOBALS['identite']; }
require __DIR__ . '/../src/ca_mail.php';
require __DIR__ . '/../src/cq_mail.php';
require __DIR__ . '/../src/documents.php';
require __DIR__ . '/../src/coffre.php';

$shop = 990101; $autre = 990102;   // des magasins qui n'existent pas : leurs dossiers s'effacent à la fin
$tmp = sys_get_temp_dir() . '/coffre-banc-' . getmypid();
@mkdir($tmp);
foreach (['dip', 'contrat', 'assurance', 'ancien', 'autre'] as $f) { file_put_contents($tmp . '/' . $f . '.pdf', '%PDF-1.4 ' . $f); }
Db::pdo()->exec('CREATE TABLE shops (id INT, name TEXT, active INT)');
Db::exec('INSERT INTO shops VALUES (?, ?, 1)', [$shop, 'Atelier by - Gosselies']);
docTable();
$auj = date('Y-m-d');
$dans = static fn (int $j) => date('Y-m-d', strtotime($auj . ' ' . ($j >= 0 ? '+' : '') . $j . ' days'));
$par = ['visible' => true, 'par' => 'Nadia K.', 'role' => 'siege'];
$dip = docGarder($shop, 'dip', 'DIP v29', '2024-09-12', null, 'dip.pdf', $tmp . '/dip.pdf', 12, false, $par);
$ctr = docGarder($shop, 'contrat_franchise', 'Contrat de franchise', '2024-10-15', null, 'contrat.pdf', $tmp . '/contrat.pdf', 12, false, $par + ['echeance' => '2033-10-14']);
$ass = docGarder($shop, 'legal', 'Assurance RC', '2026-01-01', null, 'assurance.pdf', $tmp . '/assurance.pdf', 12, false, $par + ['echeance' => $dans(39)]);
$anc = docGarder($shop, 'reunion', 'Réunion d’avant le coffre', '2025-10-28', null, 'ancien.pdf', $tmp . '/ancien.pdf', 12, false);   // déposé avant le coffre
$aut = docGarder($autre, 'legal', 'Statuts d’un autre magasin', '2024-07-02', null, 'autre.pdf', $tmp . '/autre.pdf', 12, false, $par);
// Les rapports de visite (src/suivi.php) : un envoyé et lu, un envoyé pas lu, un pas envoyé.
Db::pdo()->exec('CREATE TABLE con_visite (id INTEGER PRIMARY KEY, shop_id TEXT, prevu_le TEXT, type_code TEXT, consultant_nom TEXT, rapport_envoye_le TEXT, rapport_envoye_par TEXT, rapport_lu_le TEXT)');
Db::exec('INSERT INTO con_visite VALUES (1, ?, ?, ?, ?, ?, ?, ?)', [(string) $shop, '2026-11-18', 'reguliere', 'Nadia K.', '2026-11-19 10:00:00', 'Nadia K.', '2026-11-20 08:00:00']);
Db::exec('INSERT INTO con_visite VALUES (2, ?, ?, ?, ?, ?, ?, ?)', [(string) $shop, '2026-11-22', 'production', 'Youssef B.', '2026-11-22 17:00:00', 'Youssef B.', null]);
Db::exec('INSERT INTO con_visite VALUES (3, ?, ?, ?, ?, ?, ?, ?)', [(string) $shop, '2026-11-25', 'hygiene', 'Nadia K.', null, null, null]);

// 1. Ce qui entre au coffre, les onglets.
$_GET = ['shop' => (string) $shop]; $C = ep_coffre_magasin();
$titres = array_column($C['documents'], 'titre');
verifie('seuls les documents « dans le coffre » de CETTE boutique', count($titres) === 3 && !in_array('Réunion d’avant le coffre', $titres, true) && !in_array('Statuts d’un autre magasin', $titres, true), json_encode($titres, JSON_UNESCAPED_UNICODE));
$parOnglet = array_column($C['onglets'], 'n', 'cle');
verifie('les onglets et leurs nombres (Tout = documents + rapports envoyés)', $parOnglet['tout'] === 5 && $parOnglet['contrat'] === 2 && $parOnglet['legal'] === 1 && $parOnglet['visites'] === 2 && $parOnglet['formation'] === 0, json_encode($parOnglet));
verifie('l’onglet de chaque sorte', array_column($C['documents'], 'onglet', 'titre') === ['Assurance RC' => 'legal', 'Contrat de franchise' => 'contrat', 'DIP v29' => 'contrat'], json_encode(array_column($C['documents'], 'onglet', 'titre'), JSON_UNESCAPED_UNICODE));
verifie('déposé par : le nom gardé au dépôt', ($C['documents'][0]['deposePar'] ?? '') === 'Nadia K.');

// 2. L'état d'après l'échéance.
verifie('à renouveler 60 jours avant l’échéance', cofEtat($dans(39), $auj)['cle'] === 'renouveler' && cofEtat($dans(39), $auj)['texte'] === 'à renouveler · 39 j');
verifie('échue, valide, sans échéance', cofEtat($dans(-3), $auj)['cle'] === 'expiree' && cofEtat($dans(200), $auj)['cle'] === 'valide' && cofEtat(null, $auj)['cle'] === 'depose');
verifie('les repères : l’assurance à renouveler, l’échéance du contrat', count($C['reperes']['aRenouveler']) === 1 && $C['reperes']['aRenouveler'][0]['titre'] === 'Assurance RC' && ($C['reperes']['contrat']['echeance'] ?? '') === '2033-10-14');

// 3. « Nouveau » tant que le magasin ne l'a pas ouvert ; une ouverture du siège n'y change rien.
verifie('quatre nouveaux au départ : trois documents et le rapport pas encore lu', $C['reperes']['nouveaux'] === 4);
cofNoter((int) $dip['id'], $shop, 'ouvert', ['nom' => 'Siège', 'role' => 'siege']);
cofNoter((int) $ctr['id'], $shop, 'ouvert', ['nom' => 'M. Lambert', 'role' => 'franchise']);
$_GET = ['shop' => (string) $shop]; $C = ep_coffre_magasin();
$nouv = array_column($C['documents'], 'nouveau', 'titre');
verifie('ouvert par le magasin : plus nouveau ; ouvert par le siège : toujours nouveau', $nouv['Contrat de franchise'] === false && $nouv['DIP v29'] === true && $C['reperes']['nouveaux'] === 3, json_encode($nouv, JSON_UNESCAPED_UNICODE));
$tr = array_column($C['documents'], 'traces', 'titre')['Contrat de franchise'];
verifie('qui l’a ouvert : le nom, le rôle, comment', ($tr[0]['qui'] ?? '') === 'M. Lambert' && ($tr[0]['comment'] ?? '') === 'ouvert');
$o = cofOuverturesMagasin($shop);
verifie('le cockpit lit les ouvertures du magasin seulement', ($o[(int) $ctr['id']]['n'] ?? 0) === 1 && !isset($o[(int) $dip['id']]));

// 4. Les rapports de visite envoyés, le plus récent d'abord.
verifie('les rapports envoyés seulement, le plus récent d’abord', array_column($C['visites'], 'id') === [2, 1] && $C['visites'][0]['luLe'] === null && $C['visites'][1]['luLe'] === '2026-11-20 08:00');

// 5. Un document d'une autre boutique, ou hors du coffre : jamais rendu, jamais joint.
verifie('le fichier : de cette boutique et dans le coffre', cofDocDuCoffre($shop, (int) $dip['id']) !== null && cofDocDuCoffre($shop, (int) $aut['id']) === null && cofDocDuCoffre($shop, (int) $anc['id']) === null);
$p = cofPiecesMail((string) $shop, [(int) $ass['id']]);
verifie('joint à un mail : le fichier lu au serveur, la pièce jointe notée', count($p) === 1 && $p[0]['contenu'] === '%PDF-1.4 assurance' && $p[0]['type'] === 'application/pdf'
    && Db::row("SELECT id FROM con_coffre_acces WHERE doc_id = ? AND comment = 'mail'", [(int) $ass['id']]) !== null);
$err = '';
try { cofPiecesMail((string) $shop, [(int) $aut['id']]); } catch (RuntimeException $e) { $err = $e->getMessage(); }
verifie('le document d’une autre boutique ne se joint pas', str_contains($err, 'n’est pas dans le coffre de ce magasin'), $err);
verifie('un même document n’est joint qu’une fois', count(cofPiecesMail((string) $shop, [(int) $dip['id'], (int) $dip['id'], (int) $ctr['id']])) === 2);

// 6. La session : accès ouvert (décision de Sam), siège, magasin.
$GLOBALS['mode'] = 'ouvert'; verifie('accès ouvert : le coffre suit le réglage', cofShopOk($shop));
$GLOBALS['mode'] = 'console'; $GLOBALS['identite'] = null; verifie('connexion exigée et personne : refusé', !cofShopOk($shop));
$GLOBALS['identite'] = ['role' => 'franchise', 'shops' => [(string) $shop], 'nom' => 'Gosselies'];
verifie('le magasin connecté : son coffre, pas celui d’un autre', cofShopOk($shop) && !cofShopOk($autre));
$_GET = ['shop' => (string) $autre]; $r = @ep_coffre_magasin();
verifie('le coffre d’une autre boutique : 403', ($r['error'] ?? '') === 'ce magasin n’est pas sur votre session');
$GLOBALS['identite'] = ['role' => 'siege', 'shops' => null, 'nom' => 'Siège'];
verifie('le siège : tous les coffres', cofShopOk($autre));
verifie('qui dépose : le nom de la session', cofQui('franchise', $shop) === ['nom' => 'Siège', 'role' => 'siege']);
$GLOBALS['identite'] = null;
verifie('sans session, une route du magasin : le nom du magasin', cofQui('franchise', $shop) === ['nom' => 'Atelier by - Gosselies', 'role' => 'franchise']);

// 7. Le consultant met au coffre, en retire, règle l'échéance (PATCH /franchises/documents).
$GLOBALS['corps'] = ['id' => (int) $anc['id'], 'coffre' => true, 'echeance' => '2027-01-31']; $m = wr_franchises_document_maj();
verifie('mis au coffre avec une échéance', ($m['ok'] ?? false) && $m['document']['coffre'] === true && $m['document']['echeance'] === '2027-01-31' && cofDocDuCoffre($shop, (int) $anc['id']) !== null);
$GLOBALS['corps'] = ['id' => (int) $anc['id'], 'coffre' => false, 'echeance' => null]; $m = wr_franchises_document_maj();
verifie('retiré du coffre, sans échéance : le fichier reste', $m['document']['coffre'] === false && $m['document']['echeance'] === null && cofDocDuCoffre($shop, (int) $anc['id']) === null && Db::row('SELECT id FROM con_doc WHERE id = ?', [(int) $anc['id']]) !== null);
$GLOBALS['corps'] = ['id' => (int) $anc['id'], 'echeance' => '31/01/2027']; $m = @wr_franchises_document_maj();
verifie('une échéance mal écrite : refusée', ($m['error'] ?? '') === 'échéance : AAAA-MM-JJ');
$GLOBALS['corps'] = ['id' => (int) $anc['id']]; $m = @wr_franchises_document_maj();
verifie('rien à changer : refusé', ($m['error'] ?? '') === 'rien à changer : coffre, echeance ou piece');

// 7 bis. Les pièces attendues (coffre v2, 22/11/2026).
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;
$_GET = ['shop' => (string) $shop]; $C = ep_coffre_magasin(); $P = array_column($C['pieces']['liste'], null, 'cle');
verifie('16 pièces attendues, le DIP et le contrat reconnus par leur sorte', count($C['pieces']['liste']) === 16 && $P['dip']['etat'] === 'recue' && $P['contrat']['etat'] === 'recue' && $P['bail']['etat'] === 'manquante', json_encode($C['pieces']['completude']));
verifie('la complétude : 2 sur 16, Contrat & DIP 2 sur 4', $C['pieces']['completude'] === ['a' => 2, 'b' => 16] && $C['pieces']['parOnglet']['contrat'] === ['a' => 2, 'b' => 4]);
Db::exec('UPDATE con_doc SET piece = ? WHERE id = ?', ['assurance_rc', (int) $ass['id']]);
$_GET = ['shop' => (string) $shop]; $C = ep_coffre_magasin(); $P = array_column($C['pieces']['liste'], null, 'cle');
verifie('une pièce rattachée prend l’état de son document : à renouveler', $P['assurance_rc']['etat'] === 'renouveler' && $P['assurance_rc']['doc']['titre'] === 'Assurance RC' && $C['pieces']['completude']['a'] === 3);
verifie('la ligne du document dit sa pièce', array_column($C['documents'], 'pieceNom', 'titre')['Assurance RC'] === 'Attestation d’assurance RC exploitation');
$GLOBALS['mode'] = 'console'; $GLOBALS['identite'] = ['role' => 'franchise', 'shops' => [(string) $shop], 'nom' => 'Gosselies'];
$GLOBALS['corps'] = ['shop' => $shop, 'piece' => 'permis_env', 'exclue' => true]; $x = wr_coffre_piece();
$P = array_column($x['pieces']['liste'], null, 'cle');
verifie('le magasin écarte une pièce qu’il dépose : elle sort du compte', $P['permis_env']['etat'] === 'exclue' && $x['pieces']['completude']['b'] === 15 && $P['permis_env']['exclue']['par'] === 'Gosselies');
$GLOBALS['corps'] = ['shop' => $shop, 'piece' => 'implantation', 'exclue' => true]; $x = @wr_coffre_piece();
verifie('pas une pièce du consultant', str_contains((string) ($x['error'] ?? ''), 'consultant'));
$GLOBALS['corps'] = ['shop' => $shop, 'piece' => 'inconnue', 'exclue' => true]; $x = @wr_coffre_piece();
verifie('une pièce inconnue : refusée', ($x['error'] ?? '') === 'pièce inconnue');
$GLOBALS['corps'] = ['shop' => $shop, 'piece' => 'permis_env', 'exclue' => false]; $x = wr_coffre_piece();
verifie('réintégrée', array_column($x['pieces']['liste'], 'etat', 'cle')['permis_env'] === 'manquante' && $x['pieces']['completude']['b'] === 16);
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;
$moi = docGarder($shop, 'legal', 'Statuts', '2025-02-12', null, 'statuts.pdf', $tmp . '/autre.pdf', 12, false, ['visible' => true, 'par' => 'M. Lambert', 'role' => 'franchise', 'piece' => 'statuts']);
$_GET = ['shop' => (string) $shop]; $C = ep_coffre_magasin();
verifie('déposé par le magasin : pas « nouveau » pour lui ; la pièce gardée au dépôt', array_column($C['documents'], 'nouveau', 'titre')['Statuts'] === false && $moi['piece'] === 'statuts');
$moi2 = docGarder($shop, 'legal', 'Pièce douteuse', null, null, 'x.pdf', $tmp . '/autre.pdf', 12, false, ['visible' => true, 'piece' => 'pas_une_piece']);
verifie('une pièce inconnue au dépôt n’est pas gardée', $moi2['piece'] === null);
verifie('la pièce de l’archive d’une formation continue', str_contains((string) file_get_contents(__DIR__ . '/../../formation/src/formation_continue.php'), "'formation_continue');"));

// 8. Le préfixe est un écran de magasin.
verifie('/coffre/magasin est ouvert aux magasins (ACCES_FRANCHISE)', str_contains((string) file_get_contents(__DIR__ . '/../../shared/src/acces.php'), "'/coffre/magasin'"));

// Le banc s'efface.
foreach ([$shop, $autre] as $s) { array_map('unlink', glob(docDossier($s) . '/*') ?: []); @rmdir(docDossier($s)); }
array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
echo "\n$ok ok, $ko KO\n";
exit($ko ? 1 : 0);
