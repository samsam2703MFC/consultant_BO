#!/usr/bin/env php
<?php
declare(strict_types=1);
ob_start(); // http_response_code() des écritures, sans en-têtes déjà partis

/**
 * Tests de Vendre › Clients (panel/src/clients_panel.php) — sans base, sans réseau.
 *
 *   php panel/bin/tests_clients.php
 *
 * Les champs secrets retirés, le nom et le téléphone d'un client, ce qui manque à une entreprise, la recherche, le crédit,
 * le relevé des tickets pro réuni par mois et par client (cycle d'achat), les six chiffres de l'analyse, la liste
 * blanche des bureaux, le refus d'accès de TF Buddy, et le relais : le compte consultant, puis le compte admin.
 */

// --- doublures -----------------------------------------------------------------------
final class Db
{
    public static array $exec = [];
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): int { self::$exec[] = [$sql, $a]; return 1; }
}
/** Le compte consultant : des réponses toutes faites, par chemin. */
final class PanelApi
{
    public static ?string $jetonImpose = null;
    public static array $rep = [];
    public static array $repMagasin = [];
    public static function config(): array { return ['base' => 'https://tfb.test/api/v1']; }
    public static array $vus = [];
    public static function configured(): bool { return true; }
    /** Avec un jeton imposé (le magasin), les réponses de $repMagasin ; sinon celles du consultant. */
    private static function rep(string $k): array { if (self::$jetonImpose !== null) { self::$vus[] = 'magasin ' . $k; return self::$repMagasin[$k] ?? [401, ['error' => 'PANEL_SESSION_EXPIRED']]; } self::$vus[] = $k; return self::$rep[$k] ?? [404, ['description' => 'ORDER_NOT_FOUND']]; }
    public static function sondeGet(string $p, int $t = 12): array { $r = self::rep('GET ' . $p); return ['code' => $r[0], 'corps' => $r[1], 'erreur' => null]; }
    public static function requete(string $m, string $p, ?array $b = null, int $t = 20): array { $r = self::rep($m . ' ' . $p); return ['code' => $r[0], 'data' => $r[1], 'erreur' => null]; }
}
/** Le compte admin. */
final class ErpApi
{
    public static array $rep = [];
    public static array $vus = [];
    public static array $corps = [];
    public static function disponible(): bool { return true; }
    public static function sondeGet(string $p): array { self::$vus[] = 'GET ' . $p; $r = self::$rep['GET ' . $p] ?? [404, ['description' => 'ORDER_NOT_FOUND']]; return ['code' => $r[0], 'corps' => $r[1], 'erreur' => null]; }
    public static function ecrire(string $m, string $p, ?array $b = null): array { self::$vus[] = $m . ' ' . $p; self::$corps[$m . ' ' . $p] = $b; $r = self::$rep[$m . ' ' . $p] ?? [404, ['description' => 'ORDER_NOT_FOUND']]; return ['code' => $r[0], 'corps' => $r[1], 'erreur' => null]; }
}
$GLOBALS['corpsTest'] = [];
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglagesTest'][$k] ?? $d; }
function body(): array { return $GLOBALS['corpsTest']; }
function journalAdd(string $a, string $k, ?string $p, string $m): void {}

require __DIR__ . '/../src/tfb_magasin.php';
require __DIR__ . '/../src/clients_panel.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function remettre(): void { PanelApi::$rep = []; PanelApi::$repMagasin = []; PanelApi::$jetonImpose = null; unset($_SERVER['HTTP_X_TFB_JETON']); PanelApi::$vus = []; ErpApi::$rep = []; ErpApi::$vus = []; ErpApi::$corps = []; $GLOBALS['pclGarde'] = []; Db::$exec = []; }

echo "Les champs secrets\n";
$p = pclPropre(['id' => 4, 'password_hash' => 'x', 'name' => 'A', 'api_key' => 'k', 'pin' => '1234', 'pinned' => true, 'profile' => ['refresh_token' => 'r', 'score' => 'A'], 'iban' => 'BE1', 'l' => [['access_token' => 't', 'v' => 1]]]);
verifie('le hachage, la clé, le PIN, l’IBAN et les jetons ne sortent pas', !isset($p['password_hash']) && !isset($p['api_key']) && !isset($p['pin']) && !isset($p['iban']) && !isset($p['profile']['refresh_token']) && !isset($p['l'][0]['access_token']));
verifie('le reste sort, à toute profondeur (« pinned » n’est pas un PIN)', $p['id'] === 4 && $p['name'] === 'A' && $p['pinned'] === true && $p['profile']['score'] === 'A' && $p['l'][0]['v'] === 1);

echo "Le nom, le téléphone, ce qui manque\n";
verifie('une entreprise : sa société', pclNom(['is_b2b' => 1, 'company_name' => 'Techni-Sol SRL', 'name' => 'Jean']) === 'Techni-Sol SRL');
verifie('un particulier : prénom et nom', pclNom(['is_b2b' => 0, 'name' => 'Isabelle', 'surname' => 'Bertrand']) === 'Isabelle Bertrand');
verifie('ni l’un ni l’autre : son numéro', pclNom(['id' => 9]) === 'client n° 9');
verifie('le téléphone en partie', pclMasqueTel('+32 471 23 45 18') === '+32 4•• •• •• 18' && pclMasqueTel('0498 11 22 07') === '049•• •• •• 07', pclMasqueTel('+32 471 23 45 18'));
verifie('un numéro trop court ne se montre pas', pclMasqueTel('123') === '••' && pclMasqueTel('') === '');
verifie('une entreprise complète', pclManques(['company_name' => 'X', 'street' => 'Rue 1', 'city' => 'Gosselies', 'tax_number' => 'BE0412338901', 'phone' => '1']) === []);
verifie('sans TVA, sans adresse, sans contact', pclManques(['company_name' => 'X', 'street' => 'Rue 1']) === ['adresse', 'tva', 'contact']);
verifie('un e-mail suffit comme contact', !in_array('contact', pclManques(['email' => 'a@b.be']), true));

echo "La recherche des particuliers\n";
$L = array_map('pclCourt', [
    ['id' => 1, 'is_b2b' => 0, 'name' => 'Isabelle', 'surname' => 'Bertrand', 'phone' => '+32 471 23 45 18', 'email' => 'isa@exemple.be', 'personal_discount_percent' => 5, 'create_timestamp' => '2024-03-01 10:00:00'],
    ['id' => 2, 'is_b2b' => 0, 'name' => 'Marc', 'surname' => 'Gilles', 'phone' => '0498 11 22 07', 'create_timestamp' => '2026-11-20 09:00:00'],
    ['id' => 3, 'is_b2b' => 0, 'name' => 'Sophie', 'surname' => 'Bertrand', 'phone' => '+32 485 00 00 52', 'city' => 'Gosselies', 'create_timestamp' => '2025-06-01 09:00:00'],
]);
verifie('par le nom, chaque mot', array_column(pclChercher($L, 'bertrand'), 'id') === [1, 3] && array_column(pclChercher($L, 'sophie bertrand'), 'id') === [3]);
verifie('par le numéro, sans le zéro de tête', array_column(pclChercher($L, '0471'), 'id') === [1] && array_column(pclChercher($L, '0498 11'), 'id') === [2]);
verifie('par l’e-mail, par la ville', array_column(pclChercher($L, 'isa@'), 'id') === [1] && array_column(pclChercher($L, 'gosselies'), 'id') === [3]);
verifie('sans recherche : les derniers créés d’abord', array_column(pclChercher($L, ''), 'id') === [2, 3, 1]);
verifie('le filtre « une remise »', array_column(pclChercher($L, '', 'remise'), 'id') === [1]);
$l1 = pclLigneParticulier($L[0]);
verifie('la ligne de la liste : le téléphone en partie, l’e-mail dit seulement présent', $l1['tel'] === '+32 4•• •• •• 18' && $l1['email'] === true && !isset($l1['prenom']));

echo "Le compte en ligne (28/11/2026)\n";
$C = array_map('pclCourt', [
    ['id' => 11, 'is_b2b' => 0, 'name' => 'Jan', 'surname' => 'Vermeulen', 'phone' => '0478 12 34 40', 'last_login_at' => '2026-11-26 18:02:11', 'create_timestamp' => '2026-04-01 10:00:00'],
    ['id' => 12, 'is_b2b' => 0, 'name' => 'Gabrielle', 'surname' => 'Dumont', 'phone' => '0476 00 00 12', 'last_login_at' => null, 'create_timestamp' => '2026-05-01 10:00:00'],
    ['id' => 13, 'is_b2b' => 0, 'name' => 'Mehdi', 'surname' => 'Benali', 'phone' => '0485 00 00 63', 'email' => 'm@exemple.be', 'create_timestamp' => '2026-06-01 10:00:00'],
]);
verifie('la date de la dernière connexion au webshop, sans l’heure ; rien sans connexion', $C[0]['connexion'] === '2026-11-26' && $C[1]['connexion'] === '' && $C[2]['connexion'] === '');
$lc = pclLigneParticulier($C[0]); $lc2 = pclLigneParticulier($C[2]);
verifie('la ligne : en ligne, et la date ; sinon pas de compte (et l’e-mail connu le dit)', $lc['enLigne'] === true && $lc['connexion'] === '2026-11-26' && $lc2['enLigne'] === false && $lc2['connexion'] === null && $lc2['email'] === true);
verifie('le filtre du compte : en ligne · pas de compte', array_column(pclChercher($C, '', '', 'en_ligne'), 'id') === [11] && array_column(pclChercher($C, '', '', 'sans_compte'), 'id') === [13, 12]);
verifie('le filtre du compte se combine avec la recherche', array_column(pclChercher($C, 'mehdi', '', 'sans_compte'), 'id') === [13] && pclChercher($C, 'mehdi', '', 'en_ligne') === []);
verifie('une ligne sans « connexion » (gardée avant la colonne) : pas de compte, sans erreur', pclLigneParticulier(['id' => 1, 'nom' => 'X', 'tel' => '', 'email' => '', 'ville' => '', 'cp' => '', 'remise' => null, 'cree' => ''])['enLigne'] === false);

remettre();
PanelApi::$rep['GET /shops/4/clients?type=b2c'] = [200, [
    ['id' => 11, 'is_b2b' => 0, 'name' => 'Jan', 'surname' => 'Vermeulen', 'phone' => '0478 12 34 40', 'last_login_at' => '2026-11-26 18:02:11', 'password_hash' => 'x'],
    ['id' => 12, 'is_b2b' => 0, 'name' => 'Gabrielle', 'surname' => 'Dumont', 'phone' => '0476 00 00 12', 'last_login_at' => null],
    ['id' => 13, 'is_b2b' => 0, 'name' => 'Mehdi', 'surname' => 'Benali', 'phone' => '0485 00 00 63'],
]];
$_GET = ['shop' => '4', 'compte' => 'en_ligne'];
$rp = ep_clients_particuliers();
verifie('GET /dashboard/clients/particuliers : combien ont un compte en ligne, et le filtre « compte »', ($rp['enLigne'] ?? null) === 1 && ($rp['compte'] ?? null) === 'en_ligne' && array_column($rp['clients'], 'id') === [11] && $rp['total'] === 3, json_encode($rp));
verifie('le mot de passe ne sort jamais, même haché', strpos(json_encode($rp), 'password') === false && strpos(json_encode($rp), '"x"') === false);
$_GET = ['shop' => '4', 'compte' => 'nimporte'];
verifie('un filtre « compte » inconnu : tous', ep_clients_particuliers()['compte'] === '' );

remettre();
// La forme d'une ligne gardée avant le 28/11/2026 : celle de pclCourt, sans « connexion », et sans numéro de version.
$GLOBALS['reglagesTest'] = ['pclClients:4:b2c' => ['lu' => time(), 'l' => [array_diff_key(pclCourt(['id' => 12, 'is_b2b' => 0, 'name' => 'Gabrielle', 'surname' => 'Dumont']), ['connexion' => 1])]]];
PanelApi::$rep['GET /shops/4/clients?type=b2c'] = [200, [['id' => 11, 'is_b2b' => 0, 'name' => 'Jan', 'surname' => 'Vermeulen', 'last_login_at' => '2026-11-26 18:02:11']]];
$_GET = ['shop' => '4'];
$rg = ep_clients_particuliers();
verifie('une liste gardée sans le compte en ligne se relit dans TF Buddy', array_column($rg['clients'], 'id') === [11] && $rg['enLigne'] === 1, json_encode($rg));
$GLOBALS['reglagesTest'] = []; $_GET = [];

remettre();
PanelApi::$rep['GET /shops/4/clients/11'] = [200, ['client' => ['id' => 11, 'id_main_shop' => 4, 'is_b2b' => 0, 'name' => 'Jan', 'surname' => 'Vermeulen', 'last_login_at' => '2026-11-26 18:02:11', 'password_hash' => 'x'], 'profile' => null]];
PanelApi::$rep['GET /shops/4/clients/12'] = [200, ['client' => ['id' => 12, 'id_main_shop' => 4, 'is_b2b' => 0, 'name' => 'Gabrielle', 'last_login_at' => null], 'profile' => null]];
$_GET = ['shop' => '4', 'id' => '11']; $fc = ep_clients_fiche();
$_GET = ['shop' => '4', 'id' => '12']; $fc2 = ep_clients_fiche();
verifie('la fiche : la dernière connexion au webshop (le jour), sinon rien ; jamais le mot de passe', ($fc['client']['connexion'] ?? 'absent') === '2026-11-26' && array_key_exists('connexion', $fc2['client'] ?? []) && $fc2['client']['connexion'] === null && !isset($fc['client']['password_hash']), json_encode($fc['client'] ?? $fc));
$_GET = [];

echo "Le crédit\n";
verifie('les seuils du panel', pclAlerteCredit(100.0) === 'danger' && pclAlerteCredit(80.0) === 'attention' && pclAlerteCredit(50.0) === 'moyen' && pclAlerteCredit(10.0) === 'ok' && pclAlerteCredit(null) === null);
$k = pclCredit(true, 2500.0, 1550.0);
verifie('1 550 € sur 2 500 € : 62 %', $k['pct'] === 62.0 && $k['alerte'] === 'moyen');
verifie('pas de crédit : rien', pclCredit(false, null, 100.0) === null && pclCredit(false, 0.0, 0.0) === null);

echo "L’entreprise de la liste du panel\n";
$e = pclEntreprisePanel(['id' => 12, 'company_name' => 'Hainaut Assurances', 'tax_number' => 'BE0412338901', 'city' => 'Charleroi', 'street' => 'bd Tirou', 'phone' => '1', 'orders_12m' => 46, 'ca_12m' => 5040.4,
    'profile' => ['score' => 'a', 'client_type' => 'standard', 'service_mode' => 'delivery'], 'recurrence' => ['status' => 'regulier', 'median_days' => 7],
    'credit' => ['enabled' => true, 'limit' => 2500, 'used' => 1550, 'percent_used' => 62], 'password_hash' => 'x']);
verifie('score, type, mode, 12 mois, cycle, crédit', $e['score'] === 'A' && $e['type'] === 'standard' && $e['mode'] === 'delivery' && $e['n12'] === 46 && $e['ca12'] === 5040.4 && $e['cycle'] === 7.0 && $e['credit']['pct'] === 62.0);
verifie('complète', $e['manques'] === [] && !isset($e['password_hash']));

echo "Le relevé des tickets pro\n";
$jours = [
    '2026-11-02' => ['ca' => 1000.0, 'n' => 80, 't' => [['09:10', 'c12', 100.0, 1, 0], ['10:00', 'c40', 50.0, 0, 1, 45.0]]],
    '2026-11-09' => ['ca' => 900.0, 'n' => 70, 't' => [['09:10', 'c12', 120.0, 1, 0]]],
    '2026-11-16' => ['ca' => 800.0, 'n' => 60, 't' => [['09:10', 'c12', 80.0, 0, 0], ['11:00', 'h1a2b', 30.0, 0, 0]]],
];
$m = pclAgregerMois($jours);
verifie('le CA du magasin et le pro après remise', $m['ca'] === 2700.0 && $m['n'] === 210 && $m['pro'] === 375.0 && $m['tk'] === 5, json_encode([$m['ca'], $m['pro']]));
verifie('les factures', $m['fac'] === 2 && $m['facCa'] === 220.0);
verifie('chaque compte : CA, tickets, jours', $m['cli']['c12'] === [300.0, 3, ['2026-11-02', '2026-11-09', '2026-11-16']] && $m['cli']['c40'][0] === 45.0);
$par = pclParClient(['2026-11' => $m]);
verifie('par client : les clés « c{id} » seules, le cycle d’achat', isset($par[12]) && !isset($par['h1a2b']) && $par[12]['n'] === 3 && $par[12]['cycle'] === 7.0 && $par[12]['dernier'] === '2026-11-16' && $par[40]['cycle'] === null);
verifie('le cycle : la médiane des écarts', pclCycle(['2026-01-01', '2026-01-03', '2026-01-10', '2026-01-11']) === 2.0 && pclCycle(['2026-01-01', '2026-01-05']) === null);
$c = pclChiffresMois($m);
verifie('les chiffres du mois', $c['ventes'] === 375.0 && $c['tickets'] === 5 && $c['panier'] === 75.0 && $c['clients'] === 3 && $c['part'] === 13.9 && $c['factures'] === 2);
verifie('un mois sans jour lu : rien', pclChiffresMois(['lus' => 0]) === null && pclChiffresMois(null) === null);
$K = pclKpis($c, ['ventes' => 300.0, 'tickets' => 5, 'panier' => 60.0, 'clients' => 3, 'part' => 10.0, 'factures' => 1], null);
verifie('face au mois d’avant, en % ; la part en points', $K['ventes']['mom'] === 25.0 && $K['part']['mom'] === 3.9 && $K['ventes']['yoy'] === null && $K['factures']['ca'] === 220.0);
verifie('un écart sur zéro n’existe pas', pclEcart(10.0, 0.0) === null);
$KP = pclKpisPanel(['b2b_sales' => ['value' => 7012, 'mom_pct' => 9, 'yoy_pct' => 14], 'b2b_share_pct' => ['value' => 21.4]]);
verifie('les KPI du tableau de bord de TF Buddy', $KP['ventes']['v'] === 7012.0 && $KP['ventes']['yoy'] === 14.0 && $KP['part']['v'] === 21.4 && $KP['tickets']['v'] === null);
verifie('les douze derniers mois', pclDouzeMois('2026-11-25')[0] === '2025-12' && pclDouzeMois('2026-11-25')[11] === '2026-11' && count(pclDouzeMois('2026-03-31')) === 12 && pclDouzeMois('2026-03-31')[10] === '2026-02');

echo "Les bureaux\n";
verifie('les lectures permises', pclCheminBureau('GET', '') && pclCheminBureau('GET', '/options') && pclCheminBureau('GET', '/12/delivery-settings') && pclCheminBureau('GET', '/12/members/search'));
verifie('les écritures permises', pclCheminBureau('POST', '/onboard') && pclCheminBureau('PUT', '/12/delivery-settings') && pclCheminBureau('PATCH', '/12/sites/4') && pclCheminBureau('DELETE', '/12/invite') && pclCheminBureau('PUT', '/12/offers/3/products'));
verifie('le reste non', !pclCheminBureau('GET', '/../clients') && !pclCheminBureau('DELETE', '') && !pclCheminBureau('GET', '/12/poster') && !pclCheminBureau('POST', '/12/settings') && !pclCheminBureau('GET', '/12?x=1') && !pclCheminBureau('TRACE', ''));
verifie('les paramètres transmis : la liste seule', pclParams(['query' => 'dup', 'shop' => '3', 'chemin' => '/x']) === '?query=dup');
verifie('les paramètres des adresses (geo/place, geo/autocomplete)', pclParams(['id' => 'ChIJabc', 'lang' => 'fr', 'shop' => '3']) === '?id=ChIJabc&lang=fr' && pclCheminBureau('GET', '/geo/place') && pclCheminBureau('GET', '/geo/postcodes'));
$b = pclBureau(['id' => '7', 'name' => 'Lunchroom', 'company_name' => 'Hainaut Assurances', 'id_company_client' => '12', 'primary_city' => 'Charleroi', 'site_count' => 1, 'offer_count' => 2, 'status' => 'pending', 'delivery_days' => ['1', '2']]);
verifie('une ligne de bureau', $b['id'] === 7 && $b['idSociete'] === 12 && $b['sites'] === 1 && $b['offres'] === 2 && $b['statut'] === 'pending' && $b['jours'] === [1, 2]);

echo "Le refus de TF Buddy\n";
verifie('401, 403, le 404 ORDER_NOT_FOUND', pclRefus(401, null) && pclRefus(403, []) && pclRefus(404, ['description' => 'ORDER_NOT_FOUND']) && pclRefus(404, null));
verifie('un 404 d’une chose qui n’existe pas, un 422 : pas un refus', !pclRefus(404, ['description' => 'CLIENT_NOT_FOUND']) && !pclRefus(422, ['errors' => ['email' => ['invalide']]]));
verifie('le motif, avec les champs refusés', pclMotif(['code' => 422, 'corps' => ['message' => 'Invalid', 'errors' => ['email' => ['invalide']]]]) === 'HTTP 422 : Invalid — email : invalide');

echo "Le relais : le consultant, puis l’admin\n";
remettre();
PanelApi::$rep['GET /x'] = [200, ['a' => 1]];
$r = pclTf('GET', '/x');
verifie('le consultant répond : l’admin n’est pas appelé', $r['code'] === 200 && $r['compte'] === 'consultant' && ErpApi::$vus === []);
remettre();
ErpApi::$rep['GET /x'] = [200, ['a' => 2]];
$r = pclTf('GET', '/x');
verifie('le consultant refusé : l’admin', $r['code'] === 200 && $r['compte'] === 'admin' && $r['corps']['a'] === 2);
remettre();
PanelApi::$rep['PATCH /clients/5'] = [422, ['message' => 'Invalid']];
$r = pclTf('PATCH', '/clients/5', ['email' => 'x']);
verifie('une écriture refusée pour une autre raison qu’un droit : pas reprise par l’admin', $r['code'] === 422 && ErpApi::$vus === [] && !$r['refus']);
remettre();
$r = pclTf('GET', '/panel/shops/3/offices');
verifie('les deux refusés : un refus, les deux comptes dits', $r['refus'] && $r['compte'] === 'consultant (404) puis admin (404)');

echo "Les écritures\n";
remettre();
$GLOBALS['corpsTest'] = ['shop' => 3, 'action' => 'creer', 'champs' => ['name' => 'Laura', 'surname' => '', 'phone' => '0470']];
$r = wr_clients_client();
verifie('un particulier sans nom : refusé avant TF Buddy', ($r['error'] ?? '') === 'le nom et le téléphone sont obligatoires' && PanelApi::$vus === []);
remettre();
PanelApi::$rep['POST /shops/3/clients'] = [201, ['success' => true, 'client_created' => true, 'shop_assignment_created' => true, 'data' => ['id' => 77]]];
$GLOBALS['corpsTest'] = ['shop' => 3, 'action' => 'creer', 'champs' => ['name' => 'Laura', 'surname' => 'Martin', 'phone' => '0470 12 34 56', 'email' => '', 'is_b2b' => 1, 'shop' => 9]];
$r = wr_clients_client();
verifie('un particulier créé ; la liste gardée oubliée', ($r['ok'] ?? false) && $r['id'] === 77 && $r['cree'] && count(Db::$exec) === 2);
remettre();
$GLOBALS['corpsTest'] = ['shop' => 3, 'action' => 'supprimer', 'id' => 5];
PanelApi::$rep['GET /shops/3/clients/5'] = [200, ['client' => ['id' => 5, 'id_main_shop' => 2, 'is_b2b' => 0], 'profile' => null]];
$r = wr_clients_client();
verifie('un client né dans un autre magasin ne s’efface pas', str_contains($r['error'] ?? '', 'autre magasin') && !in_array('DELETE /clients/5', PanelApi::$vus, true));
remettre();
$GLOBALS['corpsTest'] = ['shop' => 3, 'action' => 'modifier', 'id' => 6, 'champs' => ['email' => 'a@b.be']];
$r = wr_clients_client();
verifie('un client d’un autre magasin ne se modifie pas d’ici', str_contains($r['error'] ?? '', 'pas un client de ce magasin') && !in_array('PATCH /clients/6', PanelApi::$vus, true));
remettre();
$GLOBALS['corpsTest'] = ['shop' => 3, 'action' => 'profil', 'id' => 8, 'champs' => ['score' => 'E']];
PanelApi::$rep['GET /shops/3/clients/8'] = [200, ['client' => ['id' => 8, 'is_b2b' => 1], 'profile' => []]];
$r = wr_clients_client();
verifie('un score hors A–D : refusé', ($r['error'] ?? '') === 'score A, B, C ou D');
remettre();
$GLOBALS['corpsTest'] = ['shop' => 3, 'action' => 'entreprise', 'id' => 9, 'champs' => ['company_name' => 'Atelier Martin', 'tax_number' => 'be 0412.338.901', 'differe' => true, 'delai' => '14']];
PanelApi::$rep['GET /shops/3/clients/9'] = [200, ['client' => ['id' => 9, 'is_b2b' => 0, 'id_main_shop' => 3], 'profile' => null]];
ErpApi::$rep['PATCH /clients/9'] = [200, ['ok' => true]];
$r = wr_clients_client();
$cb = ErpApi::$corps['PATCH /clients/9'] ?? [];
verifie('passer en entreprise : le geste du panel, par l’admin si le consultant est refusé', ($r['ok'] ?? false) && $cb['is_b2b'] === 1 && $cb['tax_number'] === 'BE0412338901' && $cb['name'] === null && $cb['can_deferral'] === 1 && $cb['payment_terms'] === 14, json_encode($cb));
remettre();
$GLOBALS['corpsTest'] = ['shop' => 3, 'methode' => 'POST', 'chemin' => '/../../clients', 'corps' => []];
$r = wr_clients_bureaux();
verifie('un chemin des bureaux hors liste : refusé avant TF Buddy', ($r['error'] ?? '') === 'écriture des bureaux non permise' && PanelApi::$vus === []);

echo "Le compte TF Buddy du magasin\n";
$jwt = 'eyJhbGciOiJIUzI1NiJ9.' . rtrim(strtr(base64_encode(json_encode(['exp' => 1795600000, 'sub' => 7])), '+/', '-_'), '=') . '.c2lnbmF0dXJl';
verifie('l’échéance d’un JWT', tfbmExpiration($jwt) === 1795600000 && tfbmExpiration('pas-un-jwt') === null);
$J = tfbmJetons(['status' => 200, 'token' => $jwt, 'refresh_token' => 'r1', 'expires_at' => 1795596336]);
verifie('les jetons d’une connexion', $J['acces'] === $jwt && $J['renouvellement'] === 'r1' && $J['expireAcces'] === 1795600000 && $J['expireSession'] === 1795596336);
$Jd = tfbmJetons(['token' => $jwt, 'refresh_token' => 'r', 'expires_at' => 43200]);
$jwtS = 'eyJhbGciOiJIUzI1NiJ9.' . rtrim(strtr(base64_encode(json_encode(['exp' => 1795600000, 'panel_session_expires_at' => 1795640000])), '+/', '-_'), '=') . '.c2lnbmF0dXJl';
verifie('la fin de session portée par le jeton passe avant expires_at', tfbmJetons(['token' => $jwtS, 'expires_at' => 300])['expireSession'] === 1795640000);
verifie('une durée de session devient une date', $Jd['expireSession'] >= time() + 43190 && $Jd['expireSession'] <= time() + 43210);
verifie('des millisecondes deviennent des secondes', tfbmJetons(['token' => $jwt, 'expires_at' => 1795596336000])['expireSession'] === 1795596336);
verifie('une réponse sans jeton : rien', tfbmJetons(['status' => 401, 'error' => 'PANEL_SESSION_EXPIRED']) === null);
verifie('le motif d’un refus, avec le code de TF Buddy', tfbmMotif(401, ['error' => 'PANEL_SESSION_EXPIRED']) === 'la session TF Buddy a expiré : reconnectez-vous (TF Buddy : PANEL_SESSION_EXPIRED)');
verifie('les limites sont dites comme celles de TF Buddy', str_contains(tfbmMotif(429, ['error' => 'PANEL_AUTH_LIMIT']), 'limite de TF Buddy, pas du dashboard') && str_contains(tfbmMotif(429, null), '(TF Buddy : HTTP 429)'));
verifie('un SMS déjà parti : saisir le code', str_starts_with(tfbmMotif(429, ['error' => 'PANEL_AUTH_RESEND_WAIT']), 'un code SMS vient d’être envoyé : saisissez-le'));
remettre();
$_SERVER['HTTP_X_TFB_JETON'] = 'court';
verifie('un en-tête qui n’a pas la forme d’un jeton est ignoré', tfbmJeton() === null);
$_SERVER['HTTP_X_TFB_JETON'] = $jwt;
PanelApi::$repMagasin['GET /panel/shops/3/offices'] = [200, ['offices' => []]];
$r = pclTf('GET', '/panel/shops/3/offices');
verifie('une route du panel : le magasin d’abord, seul', $r['code'] === 200 && $r['compte'] === 'magasin' && PanelApi::$vus === ['magasin GET /panel/shops/3/offices'] && ErpApi::$vus === [] && PanelApi::$jetonImpose === null);
remettre();
$_SERVER['HTTP_X_TFB_JETON'] = $jwt;
PanelApi::$rep['GET /shops/3/clients/5'] = [200, ['client' => ['id' => 5]]];
$r = pclTf('GET', '/shops/3/clients/5');
verifie('une route hors panel : le consultant d’abord, le magasin en dernier', $r['compte'] === 'consultant' && PanelApi::$vus === ['GET /shops/3/clients/5']);
remettre();
$_SERVER['HTTP_X_TFB_JETON'] = $jwt;
$r = pclTf('GET', '/panel/shops/3/b2b/dashboard?year=2026&month=11');
verifie('le jeton du magasin périmé, les comptes du cockpit refusés : « reconnectez-vous »', $r['refus'] && $r['magasinExpire'] && str_starts_with($r['compte'], 'magasin (401)') && str_contains(pclRefusDit($r, 'x')['texte'], 'reconnectez-vous'));
remettre();
verifie('sans jeton du magasin, le refus propose de le connecter', str_contains(pclRefusDit(pclTf('GET', '/panel/shops/3/offices'), 'les bureaux')['texte'], 'Connectez le compte TF Buddy du magasin'));

verifie('« aux bureaux », « au profil », « à son… »', pclA('les bureaux') === 'aux bureaux' && pclA('le profil') === 'au profil' && pclA('son tableau') === 'à son tableau');
echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
