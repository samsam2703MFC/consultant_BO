#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Le cockpit n'écrit plus les tables du module Marketing (21/11/2026) : le fonds (mouvements, frais récurrents,
 * redevances) et les types de campagne passent par l'API du module (marketingAppel, jeton de service), et les
 * écritures de campagnes, sans écran, sont retirées (410). Il ne les LIT plus non plus pour le fonds : GET /fonds
 * (marketing/src/fonds.php) et les vérifications des écritures lisent le grand livre, les frais récurrents, les
 * boutiques, les leviers et les campagnes au module. Sans base, sans réseau, sans jeton.
 *
 *   php marketing/bin/tests_fonds_relais.php
 *
 * Le module est une doublure : chaque appel est retenu (méthode, chemin, corps) ; une lecture rend la réponse posée
 * pour sa route ($GLOBALS['fixes']), sinon la suivante de la file ($GLOBALS['reponses']), comme une écriture.
 * La base est une doublure qui retient chaque requête : on vérifie qu'aucune ne lit ni n'écrit une table du module.
 */

// La sortie est retenue jusqu'à la fin : en ligne de commande, http_response_code() ne retient plus le code dès qu'un
// premier affichage est parti, et ce sont ces codes (422, 409, 410) qu'on vérifie.
ob_start();

// --- doublures ------------------------------------------------------------------------------------------------
final class Db
{
    /** @var list<array{0:string,1:array}> */
    public static array $sql = [];
    /** @var array<string,?array> motif → ligne rendue par row() */
    public static array $lignes = [];
    public static function rows(string $s, array $a = []): array { self::$sql[] = [$s, $a]; return $GLOBALS['rows'][$s] ?? []; }
    public static function row(string $s, array $a = []): ?array
    {
        self::$sql[] = [$s, $a];
        foreach (self::$lignes as $motif => $ligne) { if (str_contains($s, $motif)) { return $ligne; } }
        return null;
    }
    public static function exec(string $s, array $a = []): int { self::$sql[] = [$s, $a]; return 1; }
}
// body() est celui de writes.php : il lit php://input, vide en ligne de commande. Le flux php:// est donc servi ici
// par un lecteur qui rend le corps préparé ($GLOBALS['corps']) — le code testé reste celui de production.
final class EntreeDeTest
{
    public $context;
    private string $donnees = '';
    private int $pos = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        if ($path !== 'php://input') { return false; }
        $this->donnees = (string) json_encode($GLOBALS['corps'] ?? []);
        return true;
    }
    public function stream_read(int $n): string { $r = (string) substr($this->donnees, $this->pos, $n); $this->pos += strlen($r); return $r; }
    public function stream_eof(): bool { return $this->pos >= strlen($this->donnees); }
    public function stream_stat(): array { return []; }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', EntreeDeTest::class);
function setting(string $k, mixed $d = null): mixed { return $d; }
/** @var list<array{0:string,1:string,2:?array}> */
$GLOBALS['appels'] = [];
$GLOBALS['reponses'] = [];   // file de réponses du module ; vide = 201 {inserted_id: 41}
$GLOBALS['fixes'] = [];      // lectures : chemin → réponse, rendue à chaque GET de ce chemin
function marketingAppel(string $methode, string $chemin, ?array $corps = null): array
{
    $GLOBALS['appels'][] = [$methode, $chemin, $corps];
    $r = ($methode === 'GET' ? ($GLOBALS['fixes'][$chemin] ?? null) : null)
        ?? array_shift($GLOBALS['reponses']) ?? ['code' => 201, 'j' => ['status' => 'success', 'message' => 'ok', 'inserted_id' => 41]];
    $j = $r['j'];
    $donnees = is_array($j) && array_key_exists('data', $j) ? $j['data'] : $j;
    // Comme le vrai : un refus (≥ 400) porte son message ; code 0 = module injoignable.
    $msg = $r['code'] >= 400 || $r['code'] === 0 ? (string) ($j['description'] ?? ($r['code'] === 0 ? 'module injoignable' : 'HTTP ' . $r['code'])) : null;
    return ['code' => $r['code'], 'corps' => $donnees, 'erreur' => $msg, 'brut' => $j];
}
function fondsRoyaltiesCalcul(string $mois): ?array { return $GLOBALS['redevances'] ?? null; }
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : []; }
final class ErpApi { public static function disponible(): bool { return false; } }
final class PanelApi { public static ?string $lastError = null; }

require __DIR__ . '/../../shared/src/writes.php';
require __DIR__ . '/../src/fonds.php';
/** Un grand livre du module (GET /funds/ledger) fait de ces lignes, une période par mois. */
function livre(array $lignes): array
{
    $p = [];
    foreach ($lignes as $l) {
        $k = substr((string) $l['movement_date'], 0, 7) . '-01';
        $p[$k] ??= ['period_key' => $k, 'entries' => [], 'exits' => [], 'entries_total' => 0, 'exits_total' => 0, 'opening_balance' => 0, 'closing_balance' => 0];
        $p[$k][$l['direction'] === 'IN' ? 'entries' : 'exits'][] = $l;
    }
    return ['code' => 200, 'j' => ['granularity' => 'month', 'periods' => array_values($p), 'closing_balance' => -12.5]];
}

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
/** Une requête SQL qui écrit une table du module Marketing (le cockpit n'en a plus le droit). */
function ecrituresModule(): array
{
    return array_values(array_filter(Db::$sql, static fn ($q) =>
        preg_match('/(INSERT( IGNORE)? INTO|UPDATE|DELETE FROM|REPLACE INTO)\s+`?mar_(campaign|fund|lever|shop|brand|offer_item)/i', $q[0]) === 1));
}
/** Une requête SQL qui lit une table du fonds ou ses rattachements (le cockpit les lit désormais au module). */
function lecturesModule(): array
{
    return array_values(array_filter(Db::$sql, static fn ($q) =>
        preg_match('/(FROM|JOIN)\s+`?mar_(fund_movement|fund_recurrence|shop|campaign|lever)\b/i', $q[0]) === 1));
}
function remettre(array $corps = [], array $reponses = [], array $fixes = []): void
{
    Db::$sql = []; Db::$lignes = []; $GLOBALS['appels'] = []; $GLOBALS['corps'] = $corps; $GLOBALS['reponses'] = $reponses;
    $GLOBALS['fixes'] = $fixes;
    http_response_code(200);
}
$ecritures = static fn (): array => array_values(array_filter($GLOBALS['appels'], static fn ($x) => $x[0] !== 'GET'));
$dernier = static fn (): ?array => $GLOBALS['appels'][count($GLOBALS['appels']) - 1] ?? null;

echo "Le fonds : mouvements\n";
remettre(['direction' => 'OUT', 'amount' => '250', 'movement_date' => '2026-07-01', 'label' => 'Four', 'is_investment' => true,
    'source' => 'production', 'shop_id' => '3', 'supplier_name' => 'Fours Dupont']);
$r = wr_fonds_mouvement(null);
$a = $dernier();
verifie('une dépense part au module : POST /funds/movements, investissement compris',
    $a !== null && $a[0] === 'POST' && $a[1] === '/funds/movements' && $a[2]['is_investment'] === 1 && $a[2]['amount'] === 250.0
        && $a[2]['source'] === 'PRODUCTION' && $a[2]['shop_id'] === 3 && $a[2]['supplier_name'] === 'Fours Dupont', json_encode($a));
verifie('l’écran reçoit ok, id et inserted_id (ceux du module)', ($r['ok'] ?? false) === true && $r['id'] === 41 && $r['inserted_id'] === 41, json_encode($r));
verifie('rien n’est écrit dans les tables du module ; le journal et le fournisseur, oui',
    ecrituresModule() === [] && (bool) array_filter(Db::$sql, static fn ($q) => str_contains($q[0], 'ceo_journal_entry'))
        && (bool) array_filter(Db::$sql, static fn ($q) => str_contains($q[0], 'INSERT INTO con_supplier')), json_encode(ecrituresModule()));

remettre(['direction' => 'IN', 'amount' => 0, 'movement_date' => '2026-07-01', 'label' => 'X']);
$r = wr_fonds_mouvement(null);
verifie('un montant nul est refusé avant d’appeler le module (422)', isset($r['error']) && http_response_code() === 422 && $GLOBALS['appels'] === []);

remettre(['direction' => 'OUT', 'amount' => 10, 'movement_date' => '2026-07-01', 'label' => 'Agence'],
    [['code' => 422, 'j' => ['status' => 'error', 'description' => 'Boutique introuvable.']]]);
$r = wr_fonds_mouvement(null);
verifie('un refus du module est rendu tel quel, avec son code', ($r['error'] ?? '') === 'Boutique introuvable.' && http_response_code() === 422, json_encode($r));

$ligne12 = ['id' => 12, 'movement_date' => '2026-07-01', 'direction' => 'OUT', 'label' => 'Agence', 'amount' => 10, 'recurrence_id' => 7];
remettre(['direction' => 'OUT', 'amount' => 10, 'movement_date' => '2026-07-01', 'label' => 'Agence'], [], ['/funds/ledger' => livre([$ligne12])]);
$r = wr_fonds_mouvement(12);
verifie('une échéance de frais récurrent ne se corrige pas (409) : lue au grand livre du module, sans écriture',
    http_response_code() === 409 && $ecritures() === [] && lecturesModule() === [], json_encode($r));
remettre(['direction' => 'OUT', 'amount' => 10, 'movement_date' => '2026-07-01', 'label' => 'Agence'], [], ['/funds/ledger' => livre([])]);
verifie('une correction d’une ligne absente du grand livre : 404', isset(wr_fonds_mouvement(12)['error']) && http_response_code() === 404 && $ecritures() === []);
remettre(['direction' => 'OUT', 'amount' => 10, 'movement_date' => '2026-07-01', 'label' => 'Agence'], [], ['/funds/ledger' => ['code' => 0, 'j' => ['description' => 'module injoignable']]]);
verifie('grand livre illisible : 502, rien n’est écrit', isset(wr_fonds_mouvement(12)['error']) && http_response_code() === 502 && $ecritures() === []);

remettre(['direction' => 'OUT', 'amount' => 10, 'movement_date' => '2026-07-01', 'label' => 'Agence'], [['code' => 200, 'j' => ['status' => 'success', 'message' => 'Mouvement corrigé.']]],
    ['/funds/ledger' => livre([['recurrence_id' => null] + $ligne12])]);
$r = wr_fonds_mouvement(12);
$a = $dernier();
verifie('une correction part en PATCH /funds/movements/12, l’écran garde l’id', $a[0] === 'PATCH' && $a[1] === '/funds/movements/12' && ($r['id'] ?? null) === 12 && ($r['ok'] ?? false) === true, json_encode([$a, $r]));

remettre([], [['code' => 200, 'j' => ['status' => 'success', 'message' => 'Mouvement supprimé.']]], ['/funds/ledger' => livre([$ligne12])]);
$r = wr_fonds_mouvement_suppr(12);
verifie('une suppression part en DELETE /funds/movements/12, le libellé du journal lu au module', $dernier() === ['DELETE', '/funds/movements/12', null] && ($r['ok'] ?? false) === true
    && ecrituresModule() === [] && lecturesModule() === [] && (bool) array_filter(Db::$sql, static fn ($q) => str_contains($q[0], 'ceo_journal_entry') && in_array('Agence', $q[1], true)));

echo "Le fonds : frais récurrents\n";
remettre(['direction' => 'OUT', 'frequency' => 'month', 'label' => 'Agence', 'amount' => 2000, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'lever_id' => 4],
    [['code' => 201, 'j' => ['inserted_id' => 9, 'occurrences' => 12, 'total_amount' => 24000.0]]]);
$r = wr_fonds_recurrence();
$a = $dernier();
verifie('un frais récurrent part au module : POST /funds/recurrences, le module écrit ses échéances',
    $a[0] === 'POST' && $a[1] === '/funds/recurrences' && $a[2]['frequency'] === 'month' && $a[2]['starts_on'] === '2026-01-01' && $a[2]['ends_on'] === '2026-12-31'
        && $a[2]['lever_id'] === 4 && ecrituresModule() === [], json_encode($a));
verifie('l’écran lit le nombre d’échéances dans `reponse`, et au premier niveau comme avant',
    ($r['reponse']['occurrences'] ?? null) === 12 && ($r['occurrences'] ?? null) === 12 && ($r['inserted_id'] ?? null) === 9 && ($r['total_amount'] ?? null) === 24000.0, json_encode($r));
remettre(['direction' => 'OUT', 'frequency' => 'fortnight', 'label' => 'A', 'amount' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-02-01']);
verifie('un rythme inconnu est refusé sans appel', isset(wr_fonds_recurrence()['error']) && $GLOBALS['appels'] === []);
remettre([], [['code' => 200, 'j' => ['status' => 'success', 'message' => 'Frais récurrent supprimé.']]], ['/funds/recurrences' => ['code' => 200, 'j' => [['id' => 9, 'label' => 'Agence']]]]);
$r = wr_fonds_recurrence_suppr(9);
verifie('la suppression d’un frais récurrent part en DELETE /funds/recurrences/9 (le frais lu au module)', $dernier() === ['DELETE', '/funds/recurrences/9', null] && ($r['ok'] ?? false) === true && lecturesModule() === []);
remettre([], [], ['/funds/recurrences' => ['code' => 200, 'j' => []]]);
verifie('un frais récurrent inconnu du module : 404, rien n’est supprimé', isset(wr_fonds_recurrence_suppr(9)['error']) && http_response_code() === 404 && $ecritures() === []);

echo "Les redevances versées au fonds\n";
$GLOBALS['redevances'] = ['du' => '2026-10-01', 'au' => '2026-10-31', 'fin' => '2026-10-31', 'shops' => [
    ['shop_id' => 3, 'shop_name' => 'Gosselies', 'enabled' => true, 'ca' => 50000.0, 'sortes' => [
        ['code' => 'MARKETING', 'label' => 'Marketing', 'taux' => 0.02, 'du' => 1000.0], ['code' => 'MARQUE', 'label' => 'Marque', 'taux' => 0.04, 'du' => 2000.0]]],
    ['shop_id' => 5, 'shop_name' => 'Namur', 'enabled' => true, 'ca' => 30000.0, 'sortes' => [['code' => 'MARKETING', 'label' => 'Marketing', 'taux' => 0.02, 'du' => 600.0]]],
    ['shop_id' => 9, 'shop_name' => 'Halle', 'enabled' => true, 'ca' => 20000.0, 'sortes' => [['code' => 'MARKETING', 'label' => 'Marketing', 'taux' => 0.02, 'du' => 400.0]]],
]];
$fixesRoy = [
    '/funds/ledger' => livre([['id' => 50, 'movement_date' => '2026-10-31', 'direction' => 'IN', 'label' => 'Redevance Namur', 'amount' => 600,
        'source' => 'ROYALTY', 'period_from' => '2026-10-01', 'document_ref' => 'ROY 2026-10 MARKETING #5', 'shop_id' => 5]]),
    '/shops' => ['code' => 200, 'j' => [['id' => 5, 'name' => 'Namur'], ['id' => 3, 'name' => 'Gosselies']]],
];
remettre([], [], $fixesRoy);
$r = fondsRoyaltiesEcrire('2026-10', false);
$posts = array_values(array_filter($GLOBALS['appels'], static fn ($x) => $x[0] === 'POST' && $x[1] === '/funds/movements'));
verifie('ce que le mois porte déjà et les boutiques connues se lisent au module, pas en base', lecturesModule() === [], json_encode(lecturesModule()));
verifie('la redevance marketing seule, ce qui manque seulement : deux écritures au module, Namur déjà passée',
    count($posts) === 2 && ($r['ecrites'] ?? null) === 2 && ($r['dejaPassees'] ?? null) === 1 && ecrituresModule() === [], json_encode([$r['ecrites'] ?? null, count($posts)]));
verifie('chaque écriture porte la période, la pièce canonique et la source ROYALTY ; un magasin hors mar_shop part sans rattachement',
    ($posts[0][2]['period_from'] ?? '') === '2026-10-01' && ($posts[0][2]['period_to'] ?? '') === '2026-10-31' && ($posts[0][2]['document_ref'] ?? '') === 'ROY 2026-10 MARKETING #3'
        && ($posts[0][2]['source'] ?? '') === 'ROYALTY' && ($posts[0][2]['shop_id'] ?? 0) === 3 && ($posts[0][2]['amount'] ?? 0) === 1000.0
        && array_key_exists('shop_id', $posts[1][2] ?? []) && $posts[1][2]['shop_id'] === null && ($posts[1][2]['document_ref'] ?? '') === 'ROY 2026-10 MARKETING #9',
    json_encode($posts));
remettre([], [], $fixesRoy);
$r = fondsRoyaltiesEcrire('2026-10', true);
verifie('l’aperçu n’écrit rien au module', array_values(array_filter($GLOBALS['appels'], static fn ($x) => $x[0] !== 'GET')) === [] && ($r['apercu'] ?? false) === true && count($r['lignes'] ?? []) === 3);
remettre([], [['code' => 201, 'j' => ['inserted_id' => 1]], ['code' => 422, 'j' => ['status' => 'error', 'description' => 'Boutique introuvable.']]], $fixesRoy);
$r = fondsRoyaltiesEcrire('2026-10', false);
verifie('un refus arrête la génération et dit ce qui est déjà parti', http_response_code() === 422 && ($r['ecrites'] ?? null) === 1
    && str_contains((string) ($r['error'] ?? ''), 'Boutique introuvable.') && count(array_filter($GLOBALS['appels'], static fn ($x) => $x[0] === 'POST')) === 2, json_encode($r));
remettre([], [], ['/funds/ledger' => ['code' => 0, 'j' => ['description' => 'module injoignable']]] + $fixesRoy);
$r = fondsRoyaltiesEcrire('2026-10', false);
verifie('grand livre illisible : 502, aucune redevance écrite (on ne saurait pas ce qui est déjà passé)', http_response_code() === 502 && $ecritures() === [], json_encode($r));

echo "Les types de campagne\n";
remettre(['nom' => 'Fêtes de fin d’année', 'couleur' => '#AA3344', 'icone' => 'cadeau', 'levierId' => 2, 'badge' => 'Trafic', 'kpi' => 'Tickets', 'description' => '']);
$r = wr_mkt_type(null);
$a = $dernier();
verifie('un type nouveau part au module, dans son vocabulaire : POST /campaign-types',
    $a[0] === 'POST' && $a[1] === '/campaign-types' && $a[2] === ['label' => 'Fêtes de fin d’année', 'description' => '', 'color_hex' => '#AA3344',
        'icon_key' => 'cadeau', 'lever_badge_label' => 'Trafic', 'default_kpi_label' => 'Tickets', 'lever_id' => 2], json_encode($a));
verifie('l’écran reçoit l’identifiant du module', ($r['ok'] ?? false) === true && ($r['id'] ?? null) === 41 && ecrituresModule() === [], json_encode($r));
remettre(['actif' => false], [['code' => 200, 'j' => ['status' => 'success']], ['code' => 200, 'j' => ['types' => [['id' => 6, 'label' => 'Ouverture']], 'icons' => []]]]);
$r = wr_mkt_type(6);
verifie('désactiver un type : PATCH /campaign-types/6 {is_active: false}, et rien d’autre',
    $GLOBALS['appels'][0] === ['PATCH', '/campaign-types/6', ['is_active' => false]] && ($r['ok'] ?? false) === true, json_encode($GLOBALS['appels']));
remettre([], [['code' => 200, 'j' => ['types' => [['id' => 6, 'label' => 'Ouverture']], 'icons' => []]],
    ['code' => 409, 'j' => ['status' => 'error', 'description' => '3 campagnes utilisent ce type : désactivez-le pour le retirer des choix sans toucher à leur historique.']]]);
$r = wr_mkt_type_suppr(6);
verifie('le nom d’un type se lit au module ({types: […]}) pour le journal', mktTypeLibelle(6) === null && ($GLOBALS['reponses'] = [['code' => 200, 'j' => ['types' => [['id' => 6, 'label' => 'Ouverture']], 'icons' => []]]]) && mktTypeLibelle(6) === 'Ouverture');
verifie('un type porté par des campagnes : 409, le message du module et leur nombre (l’écran propose de désactiver)',
    http_response_code() === 409 && ($r['campagnes'] ?? null) === 3 && str_starts_with((string) ($r['error'] ?? ''), '3 campagnes'), json_encode($r));
remettre(['ids' => ['4', 2, 'x', 7]], [['code' => 200, 'j' => ['reordered' => 3]]]);
$r = wr_mkt_types_ordre();
verifie('l’ordre part en PUT /campaign-types/order', $dernier() === ['PUT', '/campaign-types/order', ['ids' => [4, 2, 7]]] && ($r['n'] ?? null) === 3, json_encode($r));

echo "Les campagnes\n";
remettre(['nom' => 'Noël']);
$r = wr_mkt_campagne(null);
verifie('les écritures de campagnes sont retirées (410), sans base ni module', http_response_code() === 410 && isset($r['error']) && Db::$sql === [] && $GLOBALS['appels'] === []);
remettre();
verifie('la suppression d’une campagne aussi', isset(wr_mkt_campagne_suppr(3)['error']) && http_response_code() === 410 && Db::$sql === []);

echo "GET /fonds — le grand livre lu au module\n";
$GLOBALS['redevances'] = ['du' => '2026-10-01', 'au' => '2026-10-31', 'fin' => '2026-10-31', 'shops' => [
    ['shop_id' => 3, 'shop_name' => 'Gosselies', 'city' => 'Gosselies', 'enabled' => true, 'ca' => 50000.0, 'billing_frequency' => 'month', 'sortes' => [
        ['cle' => 'royalty_marketing_percentage', 'code' => 'MARKETING', 'label' => 'Marketing', 'taux' => 0.02, 'du' => 1000.0]]]]];
$_GET['mois'] = '2026-10';
$fixesFonds = [
    '/funds/ledger' => livre([
        ['id' => 61, 'movement_date' => '2026-10-31', 'direction' => 'IN', 'label' => 'Redevance Gosselies', 'amount' => '1000.00', 'source' => 'ROYALTY',
            'shop_id' => 3, 'shop_name' => 'Gosselies', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'is_public' => true, 'signed_amount' => 1000.0,
            'campaign_id' => null, 'campaign_name' => null, 'lever_id' => null, 'lever_label' => null, 'lever_color_hex' => null,
            'supplier_name' => null, 'document_ref' => 'ROY 2026-10 MARKETING #3', 'is_investment' => false, 'recurrence_id' => null],
        ['id' => 62, 'movement_date' => '2026-11-03', 'direction' => 'OUT', 'label' => 'Four', 'amount' => 250, 'source' => 'PRODUCTION',
            'shop_id' => null, 'shop_name' => null, 'lever_id' => '2', 'lever_label' => 'Trafic', 'lever_color_hex' => '#123456', 'is_investment' => true, 'recurrence_id' => '9'],
    ]),
    '/references' => ['code' => 200, 'j' => ['levers' => [['id' => 2, 'code' => 'TRAFIC', 'label' => 'Trafic', 'color_hex' => '#123456'], ['id' => 1, 'code' => 'PANIER', 'label' => 'Panier', 'color_hex' => '#654321']]]],
    '/funds/levers' => ['code' => 200, 'j' => [['lever_id' => 1, 'spent_amount' => 0.0, 'roi_value' => 3.2], ['lever_id' => 2, 'spent_amount' => 250.0, 'roi_value' => 1.1], ['lever_id' => 9, 'spent_amount' => 40.0]]],
    '/funds/recurrences' => ['code' => 200, 'j' => [['id' => 9, 'direction' => 'OUT', 'frequency' => 'month', 'label' => 'Agence', 'amount' => '2000', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'shop_name' => null, 'occurrences' => 12]]],
    '/shops' => ['code' => 200, 'j' => [['id' => 5, 'name' => 'Namur', 'city' => 'Namur'], ['id' => 7, 'name' => 'Écaussinnes', 'city' => null], ['id' => 3, 'name' => 'Gosselies', 'city' => 'Gosselies']]],
    '/campaigns' => ['code' => 200, 'j' => [['id' => 8, 'name' => 'Noël', 'starts_on' => '2026-12-01'], ['id' => 4, 'name' => 'Rentrée', 'starts_on' => '2026-09-01']]],
];
remettre([], [], $fixesFonds);
$GLOBALS['rows'] = ['SELECT id, name FROM con_supplier ORDER BY name' => [['id' => 'f1', 'name' => 'Fours Dupont']]];
$f = ep_fonds();
verifie('aucune table du module n’est lue en base : seul le référentiel des fournisseurs (con_supplier) l’est',
    lecturesModule() === [] && array_column(Db::$sql, 0) === ['SELECT id, name FROM con_supplier ORDER BY name'], json_encode(array_column(Db::$sql, 0)));
verifie('les six lectures partent au module', array_column($GLOBALS['appels'], 1) === ['/funds/ledger', '/references', '/funds/levers', '/funds/recurrences', '/shops', '/campaigns'], json_encode(array_column($GLOBALS['appels'], 1)));
$sortie = $f['ledger']['periods'][1]['exits'][0] ?? [];
verifie('le grand livre garde la forme de l’écran : ses clés seules, typées (investissement, levier, récurrence)',
    count($f['ledger']['periods']) === 2 && array_keys($sortie) === FONDS_LIGNE_CLES && $sortie['is_investment'] === true && $sortie['lever_id'] === 2
        && $sortie['recurrence_id'] === 9 && $sortie['amount'] === 250.0 && $f['ledger']['periods'][0]['entries'][0]['amount'] === 1000.0
        && !isset($f['ledger']['periods'][0]['entries'][0]['signed_amount']) && $f['ledger']['closing_balance'] === -12.5, json_encode($sortie));
verifie('les leviers actifs, dans l’ordre du réseau, avec leur dépense ; le ROI reste absent',
    array_column($f['leviers'], 'lever_id') === [2, 1] && $f['leviers'][0]['spent_amount'] === 250.0 && $f['leviers'][1]['spent_amount'] === 0.0
        && $f['leviers'][0]['lever_code'] === 'TRAFIC' && $f['leviers'][0]['roi_value'] === null, json_encode($f['leviers']));
verifie('frais récurrents, magasins (par nom, accents compris) et campagnes du module',
    ($f['recurrences'][0]['amount'] ?? null) === 2000.0 && ($f['recurrences'][0]['label'] ?? '') === 'Agence'
        && array_column($f['magasins'], 'name') === ['Écaussinnes', 'Gosselies', 'Namur'] && $f['magasins'][0]['city'] === ''
        && $f['campagnes'] === [['id' => 8, 'name' => 'Noël'], ['id' => 4, 'name' => 'Rentrée']], json_encode([$f['magasins'], $f['campagnes']]));
verifie('les redevances du mois rapprochent les écritures ROYALTY lues au module', ($f['royalties']['shops'][0]['movements'] ?? null) === [['amount' => 1000.0, 'label' => 'Redevance Gosselies']]
    && ($f['royalties']['shops'][0]['due_marketing'] ?? null) === 1000.0 && $f['erreurs'] === [] && $f['fournisseurs'] === [['id' => 'f1', 'nom' => 'Fours Dupont']], json_encode([$f['royalties']['shops'][0] ?? null, $f['erreurs']]));
remettre([], [], ['/funds/ledger' => ['code' => 0, 'j' => ['description' => 'module injoignable']]]);
$f = ep_fonds();
verifie('module injoignable : l’écran le dit, sans grand livre inventé', $f['ledger'] === null && count($f['erreurs']) === 1 && str_contains($f['erreurs'][0], 'module injoignable') && Db::$sql === [], json_encode($f['erreurs']));

echo "\n$ok ok, $ko KO\n";
ob_end_flush();
exit($ko === 0 ? 0 : 1);
