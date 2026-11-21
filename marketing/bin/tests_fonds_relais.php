#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Le cockpit n'écrit plus les tables du module Marketing (21/11/2026) : le fonds (mouvements, frais récurrents,
 * redevances) et les types de campagne passent par l'API du module (marketingAppel, jeton de service), et les
 * écritures de campagnes, sans écran, sont retirées (410). Sans base, sans réseau, sans jeton.
 *
 *   php marketing/bin/tests_fonds_relais.php
 *
 * Le module est une doublure : chaque appel est retenu (méthode, chemin, corps) et rend la réponse préparée.
 * La base est une doublure qui retient chaque requête : on vérifie qu'aucune n'écrit une table du module.
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
function marketingAppel(string $methode, string $chemin, ?array $corps = null): array
{
    $GLOBALS['appels'][] = [$methode, $chemin, $corps];
    $r = array_shift($GLOBALS['reponses']) ?? ['code' => 201, 'j' => ['status' => 'success', 'message' => 'ok', 'inserted_id' => 41]];
    $j = $r['j'];
    $donnees = is_array($j) && array_key_exists('data', $j) ? $j['data'] : $j;
    $msg = $r['code'] >= 400 ? (string) ($j['description'] ?? 'HTTP ' . $r['code']) : null;
    return ['code' => $r['code'], 'corps' => $donnees, 'erreur' => $msg, 'brut' => $j];
}
function fondsRoyaltiesCalcul(string $mois): ?array { return $GLOBALS['redevances'] ?? null; }

require __DIR__ . '/../../shared/src/writes.php';

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
function remettre(array $corps = [], array $reponses = []): void
{
    Db::$sql = []; Db::$lignes = []; $GLOBALS['appels'] = []; $GLOBALS['corps'] = $corps; $GLOBALS['reponses'] = $reponses;
    http_response_code(200);
}
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

remettre(['direction' => 'OUT', 'amount' => 10, 'movement_date' => '2026-07-01', 'label' => 'Agence']);
Db::$lignes = ['FROM mar_fund_movement' => ['label' => 'Agence', 'recurrence_id' => 7]];
$r = wr_fonds_mouvement(12);
verifie('une échéance de frais récurrent ne se corrige pas (409), sans appel au module', http_response_code() === 409 && $GLOBALS['appels'] === [], json_encode($r));

remettre(['direction' => 'OUT', 'amount' => 10, 'movement_date' => '2026-07-01', 'label' => 'Agence'], [['code' => 200, 'j' => ['status' => 'success', 'message' => 'Mouvement corrigé.']]]);
Db::$lignes = ['FROM mar_fund_movement' => ['label' => 'Agence', 'recurrence_id' => null]];
$r = wr_fonds_mouvement(12);
$a = $dernier();
verifie('une correction part en PATCH /funds/movements/12, l’écran garde l’id', $a[0] === 'PATCH' && $a[1] === '/funds/movements/12' && ($r['id'] ?? null) === 12 && ($r['ok'] ?? false) === true, json_encode([$a, $r]));

remettre([], [['code' => 200, 'j' => ['status' => 'success', 'message' => 'Mouvement supprimé.']]]);
Db::$lignes = ['FROM mar_fund_movement' => ['label' => 'Agence']];
$r = wr_fonds_mouvement_suppr(12);
verifie('une suppression part en DELETE /funds/movements/12', $dernier() === ['DELETE', '/funds/movements/12', null] && ($r['ok'] ?? false) === true && ecrituresModule() === []);

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
remettre([], [['code' => 200, 'j' => ['status' => 'success', 'message' => 'Frais récurrent supprimé.']]]);
Db::$lignes = ['FROM mar_fund_recurrence' => ['label' => 'Agence']];
$r = wr_fonds_recurrence_suppr(9);
verifie('la suppression d’un frais récurrent part en DELETE /funds/recurrences/9', $dernier() === ['DELETE', '/funds/recurrences/9', null] && ($r['ok'] ?? false) === true);

echo "Les redevances versées au fonds\n";
$GLOBALS['redevances'] = ['du' => '2026-10-01', 'au' => '2026-10-31', 'fin' => '2026-10-31', 'shops' => [
    ['shop_id' => 3, 'shop_name' => 'Gosselies', 'enabled' => true, 'ca' => 50000.0, 'sortes' => [
        ['code' => 'MARKETING', 'label' => 'Marketing', 'taux' => 0.02, 'du' => 1000.0], ['code' => 'MARQUE', 'label' => 'Marque', 'taux' => 0.04, 'du' => 2000.0]]],
    ['shop_id' => 5, 'shop_name' => 'Namur', 'enabled' => true, 'ca' => 30000.0, 'sortes' => [['code' => 'MARKETING', 'label' => 'Marketing', 'taux' => 0.02, 'du' => 600.0]]],
    ['shop_id' => 9, 'shop_name' => 'Halle', 'enabled' => true, 'ca' => 20000.0, 'sortes' => [['code' => 'MARKETING', 'label' => 'Marketing', 'taux' => 0.02, 'du' => 400.0]]],
]];
$GLOBALS['rows'] = [
    "SELECT document_ref FROM mar_fund_movement\n                       WHERE source = 'ROYALTY' AND period_from = ?" => [['document_ref' => 'ROY 2026-10 MARKETING #5']],
    'SELECT id FROM mar_shop' => [['id' => 3], ['id' => 5]],
];
remettre();
$r = fondsRoyaltiesEcrire('2026-10', false);
$posts = array_values(array_filter($GLOBALS['appels'], static fn ($x) => $x[0] === 'POST' && $x[1] === '/funds/movements'));
verifie('la redevance marketing seule, ce qui manque seulement : deux écritures au module, Namur déjà passée',
    count($posts) === 2 && ($r['ecrites'] ?? null) === 2 && ($r['dejaPassees'] ?? null) === 1 && ecrituresModule() === [], json_encode([$r['ecrites'] ?? null, count($posts)]));
verifie('chaque écriture porte la période, la pièce canonique et la source ROYALTY ; un magasin hors mar_shop part sans rattachement',
    ($posts[0][2]['period_from'] ?? '') === '2026-10-01' && ($posts[0][2]['period_to'] ?? '') === '2026-10-31' && ($posts[0][2]['document_ref'] ?? '') === 'ROY 2026-10 MARKETING #3'
        && ($posts[0][2]['source'] ?? '') === 'ROYALTY' && ($posts[0][2]['shop_id'] ?? 0) === 3 && ($posts[0][2]['amount'] ?? 0) === 1000.0
        && array_key_exists('shop_id', $posts[1][2] ?? []) && $posts[1][2]['shop_id'] === null && ($posts[1][2]['document_ref'] ?? '') === 'ROY 2026-10 MARKETING #9',
    json_encode($posts));
remettre();
$r = fondsRoyaltiesEcrire('2026-10', true);
verifie('l’aperçu n’appelle pas le module', $GLOBALS['appels'] === [] && ($r['apercu'] ?? false) === true && count($r['lignes'] ?? []) === 3);
remettre([], [['code' => 201, 'j' => ['inserted_id' => 1]], ['code' => 422, 'j' => ['status' => 'error', 'description' => 'Boutique introuvable.']]]);
$r = fondsRoyaltiesEcrire('2026-10', false);
verifie('un refus arrête la génération et dit ce qui est déjà parti', http_response_code() === 422 && ($r['ecrites'] ?? null) === 1
    && str_contains((string) ($r['error'] ?? ''), 'Boutique introuvable.') && count($GLOBALS['appels']) === 2, json_encode($r));

echo "Les types de campagne\n";
remettre(['nom' => 'Fêtes de fin d’année', 'couleur' => '#AA3344', 'icone' => 'cadeau', 'levierId' => 2, 'badge' => 'Trafic', 'kpi' => 'Tickets', 'description' => '']);
$r = wr_mkt_type(null);
$a = $dernier();
verifie('un type nouveau part au module, dans son vocabulaire : POST /campaign-types',
    $a[0] === 'POST' && $a[1] === '/campaign-types' && $a[2] === ['label' => 'Fêtes de fin d’année', 'description' => '', 'color_hex' => '#AA3344',
        'icon_key' => 'cadeau', 'lever_badge_label' => 'Trafic', 'default_kpi_label' => 'Tickets', 'lever_id' => 2], json_encode($a));
verifie('l’écran reçoit l’identifiant du module', ($r['ok'] ?? false) === true && ($r['id'] ?? null) === 41 && ecrituresModule() === [], json_encode($r));
remettre(['actif' => false], [['code' => 200, 'j' => ['status' => 'success']], ['code' => 200, 'j' => [['id' => 6, 'label' => 'Ouverture']]]]);
$r = wr_mkt_type(6);
verifie('désactiver un type : PATCH /campaign-types/6 {is_active: false}, et rien d’autre',
    $GLOBALS['appels'][0] === ['PATCH', '/campaign-types/6', ['is_active' => false]] && ($r['ok'] ?? false) === true, json_encode($GLOBALS['appels']));
remettre([], [['code' => 200, 'j' => [['id' => 6, 'label' => 'Ouverture']]],
    ['code' => 409, 'j' => ['status' => 'error', 'description' => '3 campagnes utilisent ce type : désactivez-le pour le retirer des choix sans toucher à leur historique.']]]);
$r = wr_mkt_type_suppr(6);
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

echo "\n$ok ok, $ko KO\n";
ob_end_flush();
exit($ko === 0 ? 0 : 1);
