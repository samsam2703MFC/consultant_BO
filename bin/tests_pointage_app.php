#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du pointage des vendeuses depuis l'app (01/11/2026, src/pointage.php) — base SQLite en mémoire, sans réseau.
 *
 *   php bin/tests_pointage_app.php
 *
 * Ce qui se vérifie : le QR signé (sa tranche de 30 secondes, 90 secondes de validité, une signature fausse, un lien
 * qui le porte), le code à six chiffres ; l'arrivée face au service (30 minutes avant, 5 minutes de grâce, trop tôt,
 * hors planning), le départ, le double scan, l'arrivée oubliée de la veille ; le départ déclaré puis validé ou refusé ;
 * la liste des jours ; les bornes (jeton haché, page de la borne, retrait) ; les routes ; côté app, la semaine prête à
 * montrer et l'état du jour de l'Accueil.
 */

// La sortie est gardée jusqu'à la fin : sans cela, http_response_code() ne se pose plus après le premier echo.
ob_start();
date_default_timezone_set('Europe/Brussels');
$prive = sys_get_temp_dir() . '/tests_pointage_' . bin2hex(random_bytes(4));
mkdir($prive, 0700, true);
putenv('COCKPIT_PRIVE=' . $prive);

// --- doublures ---------------------------------------------------------------------------
final class Db
{
    public static ?PDO $p = null;
    public static function pdo(): PDO
    {
        if (self::$p === null) {
            self::$p = new PDO('sqlite::memory:');
            self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$p->exec('CREATE TABLE mot_pointage (id INTEGER PRIMARY KEY AUTOINCREMENT, emp INT, shop INT, jour TEXT, entree TEXT, sortie TEXT NULL,
                entree_par TEXT, sortie_par TEXT NULL, sortie_validee_par TEXT NULL, sortie_validee_le TEXT NULL, prevu_debut TEXT NULL, prevu_fin TEXT NULL,
                hors_planning INT DEFAULT 0, cree TEXT DEFAULT CURRENT_TIMESTAMP, maj TEXT DEFAULT CURRENT_TIMESTAMP)');
            self::$p->exec('CREATE TABLE mot_borne (id INTEGER PRIMARY KEY AUTOINCREMENT, shop INT, nom TEXT, jeton_hash TEXT UNIQUE, actif INT DEFAULT 1,
                cree TEXT DEFAULT CURRENT_TIMESTAMP, vue_le TEXT NULL)');
            self::$p->exec('CREATE TABLE shops (id INT, active INT)');
            self::$p->exec('INSERT INTO shops VALUES (2,1),(4,1),(5,1),(9,0)');
        }
        return self::$p;
    }
    public static function rows(string $sql, array $a = []): array { $s = self::pdo()->prepare($sql); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
    public static function row(string $sql, array $a = []): ?array { return self::rows($sql, $a)[0] ?? null; }
    public static function exec(string $sql, array $a = []): int
    {
        if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS mot_')) { return 0; }
        $s = self::pdo()->prepare($sql); $s->execute($a); return $s->rowCount();
    }
}
$GLOBALS['JOURNAL'] = [];
function journalAdd(string $actor, string $kind, ?string $project, string $message, ?string $ts = null): void { $GLOBALS['JOURNAL'][] = $message; }
$GLOBALS['CORPS'] = [];
function body(): array { return $GLOBALS['CORPS']; }
$GLOBALS['IDENT'] = null;
function primesIdentiteSignee(): ?array { return $GLOBALS['IDENT']; }
function primesJetonRecu(): ?string { return null; }
function primesEmployeDuJeton(string $j): ?int { return null; }
function primesResoudreEmploye(int $emp, string $nom): ?int { return null; }
function venteEmployes(): array { return [12 => ['nom' => 'Bram Claes', 'shop' => '4'], 11 => ['nom' => 'Anna Peeters', 'shop' => '4']]; }
/** Le planning : emp → date → services (comme planningPersonne). */
$GLOBALS['PLAN'] = [];
function planningPersonne(int $emp, string $du, string $au, ?int $maintenant = null): array
{
    $out = [];
    foreach ($GLOBALS['PLAN'][$emp] ?? [] as $d => $svcs) {
        if ($d < $du || $d > $au) { continue; }
        foreach ($svcs as [$de, $a, $shop]) { $out[] = ['id_schedule' => 0, 'id_employee' => $emp, 'work_date' => $d, 'start_hour' => $de . ':00', 'end_hour' => $a . ':00', 'id_shop' => $shop, 'shop_name' => [4 => 'Halle', 5 => 'Namur'][$shop] ?? '']; }
    }
    return ['services' => $out];
}
function planNomsMagasins(): array { return [2 => 'Corbais', 4 => 'Halle', 5 => 'Namur']; }

require __DIR__ . '/../src/profil_employe.php';
require __DIR__ . '/../src/pointage.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$t = static fn (string $quand) => strtotime($quand);

echo "Le QR signé et le code\n";
$T0 = $t('2026-10-31 07:58:10');
$tr = ptgTranche($T0);
$qr = ptgQr(4, $tr);
verifie('le QR : « ATP1:<magasin>:<tranche>:<signature> »', (bool) preg_match('/^ATP1:4:\d+:[0-9a-f]{24}$/', $qr), $qr);
verifie('lu dans sa tranche : Halle, par QR', ptgLire($qr, [2, 4, 5], $T0) === ['shop' => 4, 'par' => 'qr']);
verifie('encore bon 60 s plus tard (90 s en tout)', ptgLire($qr, [2, 4, 5], $T0 + 60) === ['shop' => 4, 'par' => 'qr']);
verifie('expiré après trois tranches', ptgLire($qr, [2, 4, 5], $T0 + 3 * PTG_PERIODE) === ['motif' => 'expire']);
verifie('une signature changée : refusé', ptgLire(substr($qr, 0, -1) . (substr($qr, -1) === 'a' ? 'b' : 'a'), [2, 4, 5], $T0) === ['motif' => 'faux']);
verifie('un autre magasin dans un QR copié : refusé', ptgLire(str_replace('ATP1:4:', 'ATP1:5:', $qr), [2, 4, 5], $T0) === ['motif' => 'faux']);
verifie('le lien de l’appareil photo (« …/pointage?c=… »)', ptgLire('https://185.180.206.46/consulant_bo/employee/pointage?c=' . rawurlencode($qr), [2, 4, 5], $T0) === ['shop' => 4, 'par' => 'qr']);
verifie('n’importe quoi : illisible', ptgLire('https://exemple.be/menu', [2, 4, 5], $T0) === ['motif' => 'illisible']);
$code = ptgCode(4, $tr);
verifie('le code : six chiffres', (bool) preg_match('/^\d{6}$/', $code), $code);
verifie('le code tapé « 123 456 » : Halle, par code', ptgLire(substr($code, 0, 3) . ' ' . substr($code, 3), [2, 4, 5], $T0 + 20) === ['shop' => 4, 'par' => 'code']);
$faux = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
verifie('un mauvais code : refusé', (ptgLire($faux, [2, 4, 5], $T0)['motif'] ?? '') === 'faux');
verifie('la clé : dans le dossier privé, 0600', is_file($prive . '/pointage.key') && (fileperms($prive . '/pointage.key') & 0777) === 0600);

echo "\nLe service du jour\n";
$svc = [['start_hour' => '08:00:00', 'end_hour' => '16:00:00']];
verifie('07:20 : trop tôt, à partir de 07:30', ptgServiceDuJour($svc, $t('2026-10-31 07:20')) === ['service' => null, 'tropTot' => '07:30']);
verifie('07:35 et 15:59 : le service', ptgServiceDuJour($svc, $t('2026-10-31 07:35'))['service'] !== null && ptgServiceDuJour($svc, $t('2026-10-31 15:59'))['service'] !== null);
verifie('17:00 : hors planning', ptgServiceDuJour($svc, $t('2026-10-31 17:00')) === ['service' => null, 'tropTot' => null]);
$deux = [['start_hour' => '07:00:00', 'end_hour' => '11:00:00'], ['start_hour' => '15:00:00', 'end_hour' => '19:00:00']];
verifie('journée coupée : 12:00 trop tôt pour le service de 15 h', ptgServiceDuJour($deux, $t('2026-10-31 12:00'))['tropTot'] === '14:30');

echo "\nArrivée, départ\n";
$GLOBALS['PLAN'] = [12 => ['2026-10-30' => [['07:00', '15:00', 4]], '2026-10-31' => [['08:00', '16:00', 4]]], 11 => []];
$r = ptgPointer(12, 4, 'qr', $t('2026-10-31 07:10'));
verifie('07:10 : trop tôt, rien d’enregistré', $r === ['motif' => 'trop_tot', 'a_partir_de' => '07:30'] && Db::row('SELECT COUNT(*) n FROM mot_pointage')['n'] == 0);
$r = ptgPointer(12, 4, 'qr', $T0);
verifie('07:58 : l’arrivée, à l’heure, le service figé', $r['sens'] === 'arrivee' && $r['pointage']['entree'] === '07:58' && $r['pointage']['arrivee'] === 'a_l_heure'
    && $r['pointage']['prevu'] === ['debut' => '08:00', 'fin' => '16:00'] && $r['pointage']['shop_name'] === 'Halle' && $r['pointage']['etat'] === 'en_service', json_encode($r));
$r = ptgPointer(12, 4, 'qr', $T0 + 50);
verifie('un second scan dans les deux minutes : « déjà »', $r['sens'] === 'deja' && Db::row('SELECT COUNT(*) n FROM mot_pointage')['n'] == 1);
$r = ptgPointer(12, 4, 'qr', $t('2026-10-31 16:04'));
verifie('16:04 : le départ, 8 h 06', $r['sens'] === 'depart' && $r['pointage']['sortie'] === '16:04' && $r['pointage']['duree_min'] === 486 && $r['pointage']['etat'] === 'fait');
$r = ptgPointer(12, 4, 'qr', $t('2026-10-31 16:05'));
verifie('le départ scanné deux fois : « déjà », pas de nouvelle journée', $r['sens'] === 'deja' && Db::row('SELECT COUNT(*) n FROM mot_pointage')['n'] == 1);
$GLOBALS['PLAN'][11] = ['2026-10-31' => [['08:00', '16:00', 4]]];
verifie('08:04 : encore à l’heure (5 min de grâce)', ptgPointer(11, 4, 'qr', $t('2026-10-31 08:04'))['pointage']['arrivee'] === 'a_l_heure');
Db::exec('DELETE FROM mot_pointage WHERE emp = 11');
$r = ptgPointer(11, 4, 'code', $t('2026-10-31 08:12'));
verifie('08:12 : 12 min de retard, par le code', $r['pointage']['arrivee'] === 'retard' && $r['pointage']['retard_min'] === 12
    && Db::row('SELECT entree_par FROM mot_pointage WHERE emp = 11')['entree_par'] === 'code');
$r = ptgPointer(11, 5, 'qr', $t('2026-11-01 10:00'));      // dimanche, rien au planning ; la veille reste ouverte depuis plus de 16 h
verifie('sans service : hors planning ; la veille ouverte reste « départ non pointé »', $r['sens'] === 'arrivee' && $r['pointage']['arrivee'] === 'hors_planning'
    && Db::row('SELECT COUNT(*) n FROM mot_pointage WHERE emp = 11 AND sortie IS NULL')['n'] == 2);

echo "\nUn départ oublié\n";
Db::exec("INSERT INTO mot_pointage (emp, shop, jour, entree, entree_par, prevu_debut, prevu_fin) VALUES (12, 4, '2026-10-30', '2026-10-30 06:57:00', 'qr', '07:00', '15:00')");
$id = (int) Db::pdo()->lastInsertId();
$GLOBALS['IDENT'] = ['emp' => 12, 'nom' => 'Bram Claes'];
$GLOBALS['CORPS'] = ['id' => $id, 'heure' => '06:00'];
wr_pointage_depart($t('2026-10-31 18:00'));
verifie('06:00 (avant l’arrivée : le lendemain, plus de 16 h) : refusé', http_response_code() === 422);
http_response_code(200);
$GLOBALS['CORPS'] = ['id' => $id, 'heure' => '15:05'];
$r = wr_pointage_depart($t('2026-10-31 18:00'));
verifie('15:05 : déclaré, à valider', http_response_code() === 200 && $r['pointage']['sortie'] === '15:05' && $r['pointage']['etat'] === 'a_valider');
wr_pointage_depart($t('2026-10-31 18:00'));
verifie('une seconde fois : refusé (déjà)', http_response_code() === 422);
http_response_code(200);
$GLOBALS['IDENT'] = ['emp' => 11, 'nom' => 'Anna Peeters'];
wr_pointage_depart($t('2026-10-31 18:00'));
verifie('le pointage d’une autre : introuvable', http_response_code() === 404);
http_response_code(200);

echo "\nLa liste\n";
$GLOBALS['IDENT'] = ['emp' => 12, 'nom' => 'Bram Claes'];
$_GET = ['du' => '2026-10-26', 'au' => '2026-11-01'];
$L = ep_pointages_moi($t('2026-10-31 18:00'));
$jours = array_column($L['jours'], null, 'date');
verifie('sept jours, le planning et les pointages', count($L['jours']) === 7 && count($jours['2026-10-31']['pointages']) === 1 && $jours['2026-10-31']['prevus'][0]['debut'] === '08:00');
verifie('totaux : 8 h 06 + 8 h 08 pointées, 16 h au planning, 1 à vérifier', $L['totaux'] === ['pointe_min' => 486 + 488, 'prevu_min' => 960, 'a_verifier' => 1], json_encode($L['totaux']));
$_GET = ['du' => '2026-10-26', 'au' => '2027-01-26'];
ep_pointages_moi();
verifie('plus de deux mois : 400', http_response_code() === 400);
http_response_code(200);

echo "\nLe cockpit : la semaine d'un magasin, valider\n";
$_GET = ['shop' => '4', 'du' => '2026-10-26', 'au' => '2026-11-01'];
$E = ep_pointages_equipe($t('2026-10-31 18:00'));
verifie('Halle : ses pointages avec les noms, les magasins à choisir', count($E['lignes']) === 3 && in_array('Bram Claes', array_column($E['lignes'], 'nom'), true)
    && array_column($E['magasins'], 'nom') === ['Corbais', 'Halle', 'Namur'], json_encode(array_column($E['lignes'], 'nom')));
$_GET = ['du' => '2026-10-26', 'au' => '2026-11-01'];
verifie('sans magasin : le premier', ep_pointages_equipe($t('2026-10-31 18:00'))['shop'] === 2);
$GLOBALS['CORPS'] = ['id' => $id];
wr_pointages_valider();
$v = Db::row('SELECT * FROM mot_pointage WHERE id = ?', [$id]);
verifie('validé : au journal, plus « à valider »', !empty($v['sortie_validee_le']) && ptgVueLigne($v, [], $t('2026-10-31 18:00'))['etat'] === 'fait' && count($GLOBALS['JOURNAL']) === 1);
wr_pointages_valider();
verifie('valider deux fois : 404', http_response_code() === 404);
http_response_code(200);
Db::exec("UPDATE mot_pointage SET sortie = '2026-10-30 14:00:00', sortie_par = 'declare', sortie_validee_le = NULL WHERE id = ?", [$id]);
$GLOBALS['CORPS'] = ['id' => $id, 'refuser' => true];
wr_pointages_valider();
verifie('refusé : redevient « départ non pointé »', Db::row('SELECT sortie FROM mot_pointage WHERE id = ?', [$id])['sortie'] === null);

echo "\nLes bornes\n";
$GLOBALS['CORPS'] = ['shop' => 4, 'nom' => 'Tablette du comptoir'];
$b = wr_pointage_borne_creer();
verifie('créée : un jeton, jamais gardé en clair', strlen($b['jeton']) >= 30 && Db::row('SELECT jeton_hash FROM mot_borne')['jeton_hash'] === hash('sha256', $b['jeton'])
    && Db::row('SELECT COUNT(*) n FROM mot_borne WHERE jeton_hash = ?', [$b['jeton']])['n'] == 0);
$_GET = ['b' => $b['jeton']];
$B = ep_pointage_borne($T0);
verifie('la page de la borne : le QR et le code du moment, le magasin', $B['qr'] === ptgQr(4, $tr) && $B['code'] === ptgCode(4, $tr) && $B['magasin'] === 'Halle'
    && $B['reste'] >= 1 && $B['reste'] <= 30 && Db::row('SELECT vue_le FROM mot_borne')['vue_le'] !== null);
$_GET = ['b' => 'pas-un-jeton-valable-du-tout-123'];
ep_pointage_borne($T0);
verifie('un autre jeton : 404', http_response_code() === 404);
http_response_code(200);
$_GET = ['shop' => '4'];
verifie('la liste des bornes, sans jeton', ep_pointage_bornes()['bornes'][0]['nom'] === 'Tablette du comptoir' && !isset(ep_pointage_bornes()['bornes'][0]['jeton']));
$GLOBALS['CORPS'] = ['id' => (int) Db::row('SELECT id FROM mot_borne')['id']];
wr_pointage_borne_retirer();
$_GET = ['b' => $b['jeton']];
ep_pointage_borne($T0);
verifie('retirée : son lien ne marche plus', http_response_code() === 404);
http_response_code(200);

echo "\nLa route de l'app\n";
Db::exec('DELETE FROM mot_pointage');
$GLOBALS['IDENT'] = null;
$GLOBALS['CORPS'] = ['lu' => $qr];
wr_pointage_moi($T0);
verifie('sans identité : 401', http_response_code() === 401);
http_response_code(200);
$GLOBALS['IDENT'] = ['emp' => 12, 'nom' => 'Bram Claes'];
$GLOBALS['CORPS'] = ['lu' => 'ATP1:4:1:000000000000000000000000'];
$r = wr_pointage_moi($T0);
verifie('un code faux : 422 et son motif', http_response_code() === 422 && $r['motif'] === 'faux');
http_response_code(200);
$GLOBALS['CORPS'] = ['lu' => ptgQr(9, $tr)];
$r = wr_pointage_moi($T0);
verifie('le QR d’un magasin fermé : accepté pour son magasin (la signature fait foi)', http_response_code() === 200 && $r['pointage']['shop'] === 9);
Db::exec('DELETE FROM mot_pointage');
$GLOBALS['CORPS'] = ['lu' => $qr];
$r = wr_pointage_moi($T0);
verifie('le QR de Halle : arrivée', http_response_code() === 200 && $r['sens'] === 'arrivee' && $r['pointage']['shop'] === 4);
$GLOBALS['CORPS'] = ['lu' => $code];
$r = wr_pointage_moi($t('2026-10-31 07:58:40'));
verifie('le code dans la même minute : « déjà »', $r['sens'] === 'deja');
$index = (string) file_get_contents(__DIR__ . '/../public/api/index.php');
$avant = strpos($index, 'function routeGardee');
verifie('routes branchées : l’app et la borne avant la session, le cockpit derrière',
    strpos($index, "str_starts_with(\$path, '/ventes/moi/pointage') && primesVoieApp()") < $avant && strpos($index, "\$path === '/pointage/borne')") < $avant
    && str_contains($index, "'/ventes/equipe/pointages'      => ep_pointages_equipe()") && str_contains($index, "=> wr_pointages_valider()") === false
    && str_contains($index, "return wr_pointages_valider();") && str_contains($index, "return wr_pointage_borne_creer();") && str_contains($index, "return wr_pointage_borne_retirer();"));

echo "\nL'app : la semaine et l'Accueil\n";
require_once __DIR__ . '/../apps/employee/vendor/autoload.php';
use App\Employee\app\Services\Pointage\PointageService;
$jour = static fn (string $d, array $prevus, array $pts) => ['date' => $d, 'prevus' => $prevus, 'pointages' => $pts];
$pv = static fn (string $a, string $b, string $s = 'Halle') => ['debut' => $a, 'fin' => $b, 'shop' => 4, 'shop_name' => $s];
$pt = static fn (string $e, ?string $s, string $etat, string $arr = 'a_l_heure', int $duree = 480) => ['id' => 1, 'entree' => $e, 'sortie' => $s, 'etat' => $etat, 'arrivee' => $arr, 'retard_min' => 0, 'duree_min' => $duree];
$liste = ['jours' => [
    $jour('2026-10-26', [$pv('07:00', '15:00')], [$pt('06:58', '15:04', 'fait', 'a_l_heure', 486)]),
    $jour('2026-10-27', [$pv('12:00', '20:00')], []),
    $jour('2026-10-28', [$pv('09:00', '17:00', 'Namur')], [$pt('08:55', '17:01', 'fait')]),
    $jour('2026-10-29', [], []),
    $jour('2026-10-30', [$pv('07:00', '15:00')], [$pt('06:57', null, 'non_pointe', 'a_l_heure', 0)]),
    $jour('2026-10-31', [$pv('08:00', '16:00')], [$pt('07:58', null, 'en_service', 'a_l_heure', 242)]),
    $jour('2026-11-01', [$pv('08:00', '12:00')], []),
], 'totaux' => ['pointe_min' => 0, 'prevu_min' => 0, 'a_verifier' => 1]];
$V = PointageService::semaineVue($liste, '2026-10-31', $t('2026-10-31 12:00'));
verifie('les statuts : ok, pas pointé, ok, repos, à vérifier, en service, à venir', array_column($V['jours'], 'statut') === ['ok', 'pas_pointe', 'ok', 'repos', 'a_verifier', 'en_service', 'a_venir'], json_encode(array_column($V['jours'], 'statut')));
verifie('la barre du prévu (7 h – 15 h sur 6 h – 22 h) et celle du pointé', $V['jours'][0]['prevus'][0]['gauche'] == 6.25 && $V['jours'][0]['prevus'][0]['largeur'] == 50.0
    && $V['jours'][5]['pointages'][0]['largeur'] > 20 && $V['axe'] === ['de' => 6, 'a' => 22]);
verifie('un autre magasin que d’habitude est signalé (Namur)', $V['jours'][2]['prevus'][0]['autreShop'] === true && $V['jours'][0]['prevus'][0]['autreShop'] === false);
$GLOBALS['REP'] = null;
$cockpit = new class extends \App\Employee\app\Services\Cockpit\CockpitClient {
    public function __construct() {}
    public function appel(string $methode, string $chemin, ?array $corps = null): array { return $GLOBALS['REP']; }
};
$GLOBALS['REP'] = ['ok' => true, 'code' => 200, 'data' => ['jours' => [$jour('2026-10-31', [$pv('08:00', '16:00')], [])], 'ouvert' => null]];
$A = (new PointageService($cockpit))->aujourdhui($t('2026-10-31 07:56'));
verifie('l’Accueil avant le service : le service, dans 4 min', $A['prevu']['debut'] === '08:00' && $A['dans'] === 4 && $A['ouvert'] === null && $A['fait'] === null);
$GLOBALS['REP'] = ['ok' => true, 'code' => 200, 'data' => ['jours' => [$jour('2026-10-31', [$pv('08:00', '16:00')], [$pt('07:58', null, 'en_service')])], 'ouvert' => $pt('07:58', null, 'en_service')]];
$A = (new PointageService($cockpit))->aujourdhui($t('2026-10-31 11:48'));
verifie('pendant le service : ouvert, encore 4 h 12, 47 %', $A['ouvert'] !== null && $A['reste'] === 252 && abs($A['avance'] - 47.5) < 0.1, json_encode($A));
$GLOBALS['REP'] = ['ok' => false, 'code' => 502, 'erreur' => 'injoignable'];
verifie('cockpit muet : pas de carte', (new PointageService($cockpit))->aujourdhui() === null);

// --- ménage --------------------------------------------------------------------------------
foreach (glob($prive . '/*') ?: [] as $x) { is_dir($x) ? @rmdir($x) : @unlink($x); }
@rmdir($prive);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
