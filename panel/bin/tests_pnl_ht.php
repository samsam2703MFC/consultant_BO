#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du P&L hors TVA et de l'onglet Frais (src/pnl_ht.php, src/franchise/frais.php, 16/11/2026) — base SQLite en mémoire, panel en doublure.
 *
 *   php bin/tests_pnl_ht.php
 *
 * La TVA du CA : les tickets du jour, sinon la caisse gravée, sinon le taux habituel, sinon 6 %. Les frais : lus au panel
 * sous plusieurs formes, le choix « TVA comprise » et le taux de chaque poste, la part hors TVA du mois. Le P&L : la
 * journée de la carte du 16/11/2026 (CA 2 628 €) passe de + 501 € à + 382 € une fois la TVA retirée.
 */
$ok = 0; $ko = 0;
// En ligne de commande, le code HTTP d'un refus ne se pose pas : son avertissement n'est pas une erreur du test.
set_error_handler(static fn (int $n, string $m): bool => str_contains($m, 'http_response_code'), E_WARNING);
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function proche(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) <= $eps; }

final class Db {
  static ?PDO $p = null;
  static function pdo(): PDO { if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return self::$p; }
  static function fx(string $q): string {
    $q = str_replace('ON DUPLICATE KEY UPDATE value = VALUES(value)', 'ON CONFLICT(key) DO UPDATE SET value = excluded.value', $q);
    return str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', '', $q);
  }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function setting(string $k, $d = null) { $r = Db::row('SELECT value FROM ceo_app_setting WHERE key = ?', [$k]); return $r ? json_decode($r['value'], true) : $d; }
function analyseListe($x) { return is_array($x) && array_is_list($x) ? $x : []; }
function nombreOuNull(array $d, array $cles): ?float { foreach ($cles as $c) { if (isset($d[$c]) && is_numeric($d[$c])) { return (float) $d[$c]; } } return null; }
function body(): array { return $GLOBALS['corps'] ?? []; }
function accesIdentite(): ?array { return ['nom' => 'Atelier by Halle']; }
final class PanelApi {
  static int $appels = 0; static $reponse = null; static ?array $ds = null;
  /** Un panel en doublure, pour l'ajout de frais : fn (méthode, chemin, corps) => ['code' => …, 'data' => …]. */
  static $faux = null;
  static function configured() { return true; }
  static function getParallele(array $ch, int $f = 4, int $t = 25): array { $o = []; foreach ($ch as $k => $c) { self::$appels++; $o[$k] = self::$faux ? self::get($c) : self::$reponse; } return $o; }
  static function get(string $c) { if (self::$faux) { $r = (self::$faux)('GET', $c, null); return $r['code'] === 200 ? $r['data'] : null; } return self::$ds; }
  static function requete(string $m, string $c, ?array $b = null, int $t = 20): array { $r = (self::$faux)($m, $c, $b); return $r + ['erreur' => $r['code'] >= 200 && $r['code'] < 300 ? null : 'HTTP ' . $r['code'] . (isset($r['data']['description']) ? ' : ' . $r['data']['description'] : '')]; }
  static array $catalogue = [];
  static function produitsDisponibles(int $s): array { return self::$catalogue; }
}

Db::exec('CREATE TABLE ceo_app_setting (key TEXT PRIMARY KEY, value TEXT)');
Db::exec('CREATE TABLE con_verif_ca (shop_id INT, jour TEXT, caisse_ttc REAL, caisse_tva REAL, PRIMARY KEY (shop_id, jour))');
require __DIR__ . '/../../shared/src/pnl_ht.php';
require __DIR__ . '/../src/frais.php';
require __DIR__ . '/../../consultant/src/ventes_pro.php';

echo "La TVA des tickets dans le relevé du jour\n";
$b = vpDuListe([
    ['total_gross_amount_after_discount' => 10.60, 'tax_amount_after_discount' => 0.60, 'insert_timestamp' => '2026-11-16 08:01:00'],
    ['total_gross_amount_after_discount' => 12.10, 'tax_amount_after_discount' => 2.10, 'insert_timestamp' => '2026-11-16 09:12:00'],
    ['total_gross_amount_after_discount' => 5.00, 'insert_timestamp' => '2026-11-16 10:00:00'],   // sans TVA lue
]);
verifie('la TVA des tickets additionnée', proche($b['tva'], 2.70), json_encode($b));
verifie('le TTC des seuls tickets dont la TVA est lue', proche($b['caT'], 22.70));
verifie('le CA du jour reste tous les tickets', proche($b['ca'], 27.70));
verifie('part du relevé = TVA ÷ TTC lus', proche((float) phPartReleve($b), 2.70 / 22.70, 0.0001));
verifie('un relevé d’avant (sans TVA) ne donne rien', phPartReleve(['ca' => 100.0]) === null);
verifie('une part au-delà de 25 % est rejetée', phPartReleve(['tva' => 40.0, 'caT' => 100.0]) === null);

echo "La TVA de chaque jour : tickets, caisse, taux habituel, défaut\n";
Db::exec('INSERT INTO con_verif_ca VALUES (4, ?, ?, ?)', ['2026-11-14', 1000.0, 60.0]);
Db::exec('INSERT INTO con_verif_ca VALUES (4, ?, ?, ?)', ['2026-11-15', 2000.0, 100.0]);
$T = phTvaJours(4, ['2026-11-14', '2026-11-15', '2026-11-16'], ['2026-11-16' => ['tva' => 150.0, 'caT' => 2650.0]]);
verifie('le jour regardé : ses tickets', $T['2026-11-16']['src'] === 'tickets' && proche($T['2026-11-16']['part'], 150 / 2650, 0.0001));
verifie('un jour clos : la caisse gravée', $T['2026-11-14']['src'] === 'caisse' && proche($T['2026-11-14']['part'], 0.06, 0.0001));
$T2 = phTvaJours(4, ['2026-11-16']);
verifie('sans tickets : le taux habituel sur huit semaines', $T2['2026-11-16']['src'] === 'habituel' && proche($T2['2026-11-16']['part'], 160 / 3000, 0.0001), json_encode($T2));
$T3 = phTvaJours(9, ['2026-11-16']);
verifie('un magasin sans caisse gravée : 6 % par défaut', $T3['2026-11-16']['src'] === 'defaut' && proche($T3['2026-11-16']['part'], 6 / 106, 0.0001));
verifie('la source se dit', phTvaSourceLib(['tickets']) === 'lue ticket par ticket' && str_contains(phTvaSourceLib(['tickets', 'habituel']), '1 jour sur 2'));

echo "Les frais du panel, sous plusieurs formes\n";
$L1 = phLignesPanel(['items' => [['name' => 'Loyer', 'type_name' => 'Loyer', 'amount' => '4800.00'], ['name' => 'Électricité et gaz', 'type' => ['name' => 'Énergie'], 'amount' => 1850]], 'total' => 6650]);
verifie('items[] : libellé, type et montant', count($L1) === 2 && $L1[1]['poste'] === 'Électricité et gaz' && $L1[1]['type'] === 'Énergie' && proche($L1[0]['montant'], 4800), json_encode($L1, JSON_UNESCAPED_UNICODE));
$L2 = phLignesPanel([['label' => 'Eau ', 'value' => 60], ['label' => 'eau', 'value' => 60.5], ['id' => 7, 'cost_type' => 'Assurances', 'total' => 410]]);
verifie('liste à la racine : deux lignes du même poste s’additionnent', count($L2) === 2 && proche($L2[0]['montant'], 120.5));
verifie('sans libellé : le type sert de poste', $L2[1]['poste'] === 'Assurances');
verifie('une réponse sans liste : aucun frais', phLignesPanel(['total' => 12]) === []);
// La forme lue au panel le 21/11/2026 (panel/tools/sonde_frais.php) : la catégorie dans cost_name, le libellé dans description.
$L3 = phLignesPanel(['period' => '2026-11', 'total' => 19400, 'items' => [
    ['id' => 240, 'date' => '2026-11-01', 'amount' => '16200.00', 'description' => '', 'cost_name' => 'Charges financières'],
    ['id' => 241, 'date' => '2026-11-01', 'amount' => '2500.00', 'description' => 'royalties', 'cost_name' => 'Rémunérations de tiers'],
    ['id' => 253, 'date' => '2026-11-01', 'amount' => '700.00', 'description' => 'Comptable', 'cost_name' => "Autres charges d'exploitation"],
    ['id' => 260, 'date' => '2026-11-01', 'amount' => '90.00', 'description' => 'Banque', 'cost_name' => "Autres charges d'exploitation"]]]);
verifie('le poste est la description, la catégorie reste en type', count($L3) === 4 && $L3[2]['poste'] === 'Comptable' && $L3[2]['cle'] === 'comptable' && $L3[2]['type'] === "Autres charges d'exploitation", json_encode($L3, JSON_UNESCAPED_UNICODE));
verifie('sans description : la catégorie sert de poste', $L3[0]['poste'] === 'Charges financières' && $L3[0]['type'] === '');
verifie('deux frais de la même catégorie restent deux postes', $L3[3]['poste'] === 'Banque' && proche($L3[2]['montant'], 700));

echo "Le choix de chaque poste et la part hors TVA du mois\n";
$GLOBALS['corps'] = ['shop' => 4, 'poste' => 'Électricité et gaz', 'tva' => true, 'taux' => 21];
$r = wr_exploitation_frais_tva();
verifie('écrire « TVA comprise : oui, 21 % »', ($r['ok'] ?? false) && $r['taux'] === 21.0, json_encode($r));
$GLOBALS['corps'] = ['shop' => 4, 'poste' => 'Loyer', 'tva' => false];
$r = wr_exploitation_frais_tva();
verifie('« non » sans taux : 0 %', $r['taux'] === 0.0);
$GLOBALS['corps'] = ['shop' => 4, 'poste' => 'Loyer', 'tva' => 'oui'];
verifie('une valeur de tva invalide est refusée', isset(wr_exploitation_frais_tva()['error']));
$GLOBALS['corps'] = ['shop' => 4, 'poste' => 'Loyer', 'tva' => true, 'taux' => 55];
verifie('un taux hors bornes est refusé', isset(wr_exploitation_frais_tva()['error']));
$C = phChoix([4])[4] ?? [];
verifie('le choix se relit par sa clé', ($C['électricité et gaz']['tva'] ?? null) === true && ($C['loyer']['tva'] ?? null) === false && ($C['loyer']['par'] ?? '') === 'Atelier by Halle');
$F = phFraisMois(array_merge($L1, [['poste' => 'Entretien', 'cle' => 'entretien', 'type' => '', 'montant' => 520.0]]), $C);
verifie('hors TVA du mois : 4 800 + 1 850 ÷ 1,21 + 520', proche($F['ht'], 4800 + 1850 / 1.21 + 520), json_encode($F['ht']));
verifie('la TVA comprise : 321,07 €', proche($F['tva'], 321.07));
verifie('un poste à indiquer compte tel que saisi', $F['aIndiquer'] === 1 && proche($F['aIndiquerEur'], 520));
verifie('part = hors TVA ÷ saisi', proche((float) $F['part'], $F['ht'] / $F['saisi'], 0.00001));
$GLOBALS['corps'] = ['shop' => 4, 'poste' => 'Électricité et gaz', 'tva' => null];
wr_exploitation_frais_tva();
$Cn = phChoix([4])[4]['électricité et gaz'] ?? [];
verifie('recliquer le choix le remet à indiquer', array_key_exists('tva', $Cn) && $Cn['tva'] === null && $Cn['taux'] === null);
// Un choix fait sur la catégorie (avant le 21/11/2026, le poste était la catégorie) vaut pour ses postes sans choix propre.
$GLOBALS['corps'] = ['shop' => 7, 'poste' => "Autres charges d'exploitation", 'tva' => true, 'taux' => 21];
wr_exploitation_frais_tva();
$GLOBALS['corps'] = ['shop' => 7, 'poste' => 'Banque', 'tva' => false];
wr_exploitation_frais_tva();
$F7 = phFraisMois($L3, phChoix([7])[7] ?? []);
$F7l = array_column($F7['lignes'], null, 'cle');
verifie('le choix de la catégorie vaut pour « Comptable »', $F7l['comptable']['tva'] === true && proche($F7l['comptable']['ht'], 700 / 1.21), json_encode($F7l['comptable'], JSON_UNESCAPED_UNICODE));
verifie('un choix propre au poste l’emporte sur celui de la catégorie', $F7l['banque']['tva'] === false && proche($F7l['banque']['ht'], 90));
verifie('une catégorie sans choix reste à indiquer', $F7l['charges financières']['aIndiquer'] === true);

echo "Les frais du panel gardés une heure\n";
PanelApi::$reponse = ['items' => [['name' => 'Loyer', 'amount' => 4800]]];
PanelApi::$appels = 0;
$P1 = phFraisPanel([4], '2026-11'); $P2 = phFraisPanel([4], '2026-11');
verifie('une seule lecture du panel pour deux appels', PanelApi::$appels === 1 && $P2[4]['lu'] && count($P2[4]['lignes']) === 1);
// Une garde écrite avec l'ancienne règle (sans version : le poste était la catégorie) se relit.
Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['phF:4:2026-10', json_encode(['q' => time(), 'l' => [['poste' => 'Vieux', 'cle' => 'vieux', 'type' => '', 'montant' => 1]]])]);
PanelApi::$appels = 0;
$P4 = phFraisPanel([4], '2026-10');
verifie('une garde d’avant la règle du 21/11 se relit au panel', PanelApi::$appels === 1 && $P4[4]['lignes'][0]['poste'] === 'Loyer');
PanelApi::$reponse = null;
$P3 = phFraisPanel([5], '2026-11');
verifie('panel muet sans lecture gardée : non lus', $P3[5]['lu'] === false);
$FP = phFraisParts([4, 5], ['2026-11']);
verifie('la part du P&L : 1 si rien n’a de TVA retirée, null si non lus', proche((float) $FP[4]['2026-11']['part'], 1.0) && $FP[5]['2026-11']['part'] === null);
verifie('la ligne des frais dit pourquoi', str_contains(phFraisSourceLib($FP[5]['2026-11']), 'non lus'));

echo "Le P&L de la carte du 16/11/2026, hors TVA\n";
// CA 2 628 € TTC, TVA à 6 %, matière 1 035 €, poubelle 77 €, main-d'œuvre 567 €, frais 447 € saisis dont 6,33 % de TVA.
$P = phPnl(2628.0, 6 / 106, 1035.0, 77.0, 567.0, 447.0, 0.93674);
verifie('CA hors TVA 2 479,25 €', proche($P['caHt'], 2479.25), (string) $P['caHt']);
verifie('TVA 148,75 €', proche($P['tva'], 148.75));
verifie('coût matière 41,7 % (39,4 % sur le TTC)', $P['coutMatierePct'] === 41.7);
verifie('marge brute 1 444,25 €', proche($P['margeBrute'], 1444.25));
verifie('frais hors TVA 418,72 €', proche($P['overhead'], 418.72) && proche($P['overheadTva'], 28.28));
verifie('résultat + 381,53 €, 15,4 %', proche($P['net'], 381.53) && $P['netPct'] === 15.4, json_encode([$P['net'], $P['netPct']]));
$P0 = phPnl(2628.0, 6 / 106, null, 77.0, 567.0, 447.0, null);
verifie('sans coût matière : ni marge, ni résultat', $P0['margeBrute'] === null && $P0['net'] === null && $P0['overhead'] === 447.0);
verifie('un jour de série : marge − TVA − main-d’œuvre − frais hors TVA', proche((float) phNetJour(1593.0, 2628.0, 6 / 106, 567.0, 447.0, 0.93674), 1593 - 148.75 - 567 - 418.72));
verifie('un jour de série sans main-d’œuvre : rien', phNetJour(1593.0, 2628.0, 0.05, null, 447.0, 1.0) === null);

echo "Ventes par catégorie : le hors TVA de chaque produit\n";
// Le catalogue : le croissant à 6 %, le jus à 21 % ; un produit inconnu prend la part habituelle du magasin.
PanelApi::$catalogue = [['id' => 11, 'tax_value_percent' => 6], ['id' => 12, 'tax_val_perc' => '21'], ['id' => 13]];
$H = phHtProduit(4);
verifie('un produit à 6 % : TTC ÷ 1,06', proche($H(11, 10.60), 10.00));
verifie('une portion « 12:moitié » prend le taux de son produit', proche($H('12:moitié', 12.10), 10.00));
verifie('un produit sans taux : la part habituelle du magasin', proche($H(13, 100.0), 100 * (1 - 160 / 3000), 0.001));
verifie('le taux du catalogue se garde', is_array(setting('phTvaP:4')) && (setting('phTvaP:4')['t'][11] ?? null) == 6);

echo "Ajouter un frais : les mois, les montants, le corps\n";
verifie('ce mois seulement', phMoisCibles('2026-11', 'mois') === ['2026-11']);
verifie('jusqu’en décembre', phMoisCibles('2026-11', 'fin') === ['2026-11', '2026-12']);
verifie('toute l’année : janvier → décembre', ($A = phMoisCibles('2026-11', 'annee')) && count($A) === 12 && $A[0] === '2026-01' && $A[11] === '2026-12');
verifie('un montant par mois : le même chaque mois', phMontants(250.0, 'mois', 3) === [250.0, 250.0, 250.0]);
verifie('un total réparti : 1 200 € sur 12 mois, 100 € par mois', phMontants(1200.0, 'total', 12) === array_fill(0, 12, 100.0));
$R3 = phMontants(1000.0, 'total', 3);
verifie('un total réparti au centime, le dernier mois prend l’arrondi', $R3 === [333.33, 333.33, 333.34] && proche(array_sum($R3), 1000.0), json_encode($R3));
$G = ['id' => 9, 'id_shop' => 5, 'id_cost' => 3, 'amount' => '120.00', 'name' => 'Eau', 'period' => '2026-10', 'created_at' => '2026-10-02 10:00:00', 'cost' => ['id' => 3, 'name' => 'Énergie'], 'note' => 'x'];
$C1 = phCorpsFrais($G, ['shop' => 5, 'type' => '4', 'nom' => 'Assurance incendie', 'montant' => 50.0, 'mois' => '2026-11']);
verifie('le corps reprend les champs du gabarit, remplis', $C1 === ['id_shop' => 5, 'id_cost' => 4, 'amount' => 50.0, 'name' => 'Assurance incendie', 'period' => '2026-11'], json_encode($C1, JSON_UNESCAPED_UNICODE));
$C2 = phCorpsFrais(null, ['shop' => 5, 'type' => '8', 'nom' => 'Loyer', 'montant' => 1200.0, 'mois' => '2026-12']);
verifie('sans gabarit : la forme lue au panel', $C2 === ['id_shop' => 5, 'id_cost' => 8, 'date' => '2026-12-01', 'amount' => 1200.0, 'description' => 'Loyer'], json_encode($C2, JSON_UNESCAPED_UNICODE));
$C5 = phCorpsFrais(['id' => 253, 'id_shop' => 5, 'id_cost' => 9, 'date' => '2026-11-01', 'amount' => '16200.00', 'description' => '', 'cost_name' => 'Charges financières'],
    ['shop' => 5, 'type' => '8', 'nom' => 'Comptable', 'montant' => 700.0, 'mois' => '2026-03']);
verifie('le gabarit du panel : la description porte le libellé, la catégorie ne part pas', $C5 === ['id_shop' => 5, 'id_cost' => 8, 'date' => '2026-03-01', 'amount' => 700.0, 'description' => 'Comptable'], json_encode($C5, JSON_UNESCAPED_UNICODE));
$C6 = phCorpsFrais(['cost_name' => 'x', 'name' => 'y', 'description' => 'z', 'amount' => 1], ['shop' => 5, 'type' => null, 'nom' => 'Eau', 'montant' => 5.0, 'mois' => '2026-03']);
verifie('le libellé va dans description avant name', $C6['description'] === 'Eau' && !isset($C6['name']) && !isset($C6['cost_name']), json_encode($C6));
$C3 = phCorpsFrais(['shop_id' => 5, 'cost_type_id' => 2, 'value' => 10, 'label' => 'a', 'date_from' => '2026-10-01 00:00:00', 'date_to' => '2026-10-31 23:59:59'], ['shop' => 5, 'type' => '2', 'nom' => 'Leasing', 'montant' => 300.0, 'mois' => '2026-02']);
verifie('des dates avec l’heure : la ligne couvre son mois, février compris', $C3['date_from'] === '2026-02-01 00:00:00' && $C3['date_to'] === '2026-02-28 23:59:59' && $C3['value'] === 300.0 && $C3['label'] === 'Leasing' && !isset($C3['period']), json_encode($C3));
$C4 = phCorpsFrais(['amount' => 1, 'name' => 'b', 'month' => 10, 'year' => 2026], ['shop' => 5, 'type' => null, 'nom' => 'Eau', 'montant' => 60.0, 'mois' => '2027-03']);
verifie('un mois en nombre et une année', $C4['month'] === 3 && $C4['year'] === 2027, json_encode($C4));
verifie('l’identifiant créé : à la racine, sous data, sous data.item', phIdCree(['id' => 77]) === '77' && phIdCree(['data' => ['id' => 'a1']]) === 'a1' && phIdCree(['data' => ['item' => ['id' => 5]]]) === '5' && phIdCree(['status' => 'ok']) === null && phIdCree(['data' => 12]) === '12');

/**
 * Un panel en doublure, à la forme lue le 21/11/2026 : les lignes {id, id_shop, id_cost, date, amount, description,
 * cost_name} ; /shops/5/costs en liste, /owner/costs sous items. La création ne rend pas d'identifiant (`$avecId` : si).
 * `$reporte` : une ligne créée vaut aussi pour les mois suivants.
 */
function fauxPanel(bool $reporte = false, ?string $refuseMois = null, bool $avecId = false): array
{
    $etat = ['lignes' => [], 'n' => 100, 'posts' => [], 'deletes' => []];
    $GLOBALS['panel'] = &$etat;
    $types = [3 => 'Frais de Bureau', 4 => 'Frais de Vente', 8 => "Autres charges d'exploitation"];
    PanelApi::$faux = static function (string $m, string $c, ?array $b) use (&$etat, $reporte, $refuseMois, $avecId, $types): array {
        if ($m === 'GET' && $c === '/costs') { return ['code' => 200, 'data' => array_map(static fn ($k, $v) => ['id' => $k, 'name' => $v], array_keys($types), $types)]; }
        if ($m === 'GET' && preg_match('#^/shops/5/(owner/)?costs\?period=(\d{4}-\d{2})$#', $c, $x)) {
            $l = array_values(array_filter($etat['lignes'], static fn ($r) => substr($r['date'], 0, 7) === $x[2] || ($reporte && substr($r['date'], 0, 7) < $x[2])));
            return ['code' => 200, 'data' => $x[1] === '' ? $l : ['period' => $x[2], 'total' => array_sum(array_column($l, 'amount')), 'items' => $l]];
        }
        if ($m === 'POST' && $c === '/shops/5/costs') {
            $etat['posts'][] = $b;
            if ($refuseMois !== null && substr((string) ($b['date'] ?? ''), 0, 7) === $refuseMois) { return ['code' => 422, 'data' => ['status' => 'error', 'description' => 'Pole amount jest wymagane']]; }
            $id = $etat['n']++;
            $etat['lignes'][$id] = ['id' => $id, 'id_shop' => 5, 'id_cost' => $b['id_cost'] ?? null, 'date' => $b['date'], 'amount' => number_format((float) $b['amount'], 2, '.', ''),
                'description' => $b['description'] ?? '', 'cost_name' => $types[$b['id_cost'] ?? 0] ?? ''];
            return ['code' => 200, 'data' => $avecId ? ['status' => 'success', 'data' => ['id' => $id]] : ['status' => 'success']];
        }
        if ($m === 'DELETE' && preg_match('#^/shops/costs/(\d+)$#', $c, $x)) { $etat['deletes'][] = (int) $x[1]; unset($etat['lignes'][(int) $x[1]]); return ['code' => 200, 'data' => ['status' => 'success']]; }
        return ['code' => 404, 'data' => null];
    };
    return $etat;
}

echo "Ajouter un frais : toute l’année, le panel ne reporte pas\n";
$e = fauxPanel();
Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?)', ['phF:5:2026-11', json_encode(['q' => time(), 'l' => []])]);
$GLOBALS['corps'] = ['shop' => 5, 'nom' => 'Assurance incendie', 'type' => '4', 'montant' => 1200, 'base' => 'total', 'mois' => '2026-11', 'repeter' => 'annee', 'tva' => false, 'taux' => null];
$r = wr_exploitation_frais_ajout();
verifie('douze lignes créées au panel, de janvier à décembre', ($r['ok'] ?? false) && $r['crees'] === 12 && count($GLOBALS['panel']['posts']) === 12 && $GLOBALS['panel']['posts'][0]['date'] === '2026-01-01', json_encode($r, JSON_UNESCAPED_UNICODE));
verifie('100 € par mois, le type au panel', $GLOBALS['panel']['posts'][5] === ['id_shop' => 5, 'id_cost' => 4, 'date' => '2026-06-01', 'amount' => 100.0, 'description' => 'Assurance incendie'], json_encode($GLOBALS['panel']['posts'][5], JSON_UNESCAPED_UNICODE));
verifie('à partir du 2e mois, le corps suit la ligne du 1er (le gabarit)', $GLOBALS['panel']['posts'][11] === ['id_shop' => 5, 'id_cost' => 4, 'date' => '2026-12-01', 'amount' => 100.0, 'description' => 'Assurance incendie'], json_encode($GLOBALS['panel']['posts'][11], JSON_UNESCAPED_UNICODE));
verifie('le panel ne rend pas l’identifiant : la relecture le retrouve', count(array_filter($r['mois'], static fn ($x) => $x['id'] !== null)) === 12 && $r['mois'][0]['id'] === '100' && $r['mois'][11]['id'] === '111', json_encode(array_column($r['mois'], 'id')));
$L5 = phLignesPanel(PanelApi::get('/shops/5/owner/costs?period=2026-11'));
verifie('l’onglet montre le libellé saisi, la catégorie dessous', ($L5[0]['poste'] ?? '') === 'Assurance incendie' && $L5[0]['type'] === 'Frais de Vente', json_encode($L5, JSON_UNESCAPED_UNICODE));
$F5 = phFraisMois($L5, phChoix([5])[5] ?? []);
verifie('la TVA choisie dans le formulaire s’applique au poste', $F5['lignes'][0]['tva'] === false && $F5['lignes'][0]['aIndiquer'] === false, json_encode($F5['lignes'][0], JSON_UNESCAPED_UNICODE));
verifie('chaque mois relu : le panel le montre', count(array_filter($r['mois'], static fn ($x) => $x['vu'] === true)) === 12 && !$r['reporteParLePanel']);
verifie('annulable : le panel a rendu chaque identifiant', $r['annulable'] === true && preg_match('/^[a-f0-9]{16}$/', (string) $r['lot']));
verifie('les frais gardés du mois se relisent', setting('phF:5:2026-11') === null);
verifie('la TVA du nouveau poste est indiquée', (phChoix([5])[5]['assurance incendie']['tva'] ?? 'x') === false);
$AJ = phAjoutsRecents(5);
verifie('l’ajout se relit : 12 mois, 1 200 €, janvier → décembre', count($AJ) === 1 && $AJ[0]['n'] === 12 && proche($AJ[0]['montant'], 1200) && $AJ[0]['du'] === '2026-01' && $AJ[0]['au'] === '2026-12' && $AJ[0]['par'] === 'Atelier by Halle', json_encode($AJ, JSON_UNESCAPED_UNICODE));
$lot = $r['lot'];
$GLOBALS['corps'] = ['shop' => 4, 'lot' => $lot];
verifie('un autre magasin ne défait pas l’ajout', isset(wr_exploitation_frais_annuler()['error']) && count($GLOBALS['panel']['deletes']) === 0);
$GLOBALS['corps'] = ['shop' => 5, 'lot' => $lot];
$a = wr_exploitation_frais_annuler();
verifie('annuler : les douze lignes supprimées au panel', ($a['ok'] ?? false) && count($a['annules']) === 12 && count($GLOBALS['panel']['lignes']) === 0, json_encode($a));
verifie('annulé : il ne se relit plus, et ne s’annule pas deux fois', phAjoutsRecents(5) === [] && isset(wr_exploitation_frais_annuler()['error']));

echo "Annuler un ajout dont l'identifiant n'a pas été relu (l'essai du 21/11/2026)\n";
fauxPanel();
// Une ligne saisie à la main au panel, même catégorie, autre libellé : elle ne doit pas partir.
PanelApi::requete('POST', '/shops/5/costs', ['id_shop' => 5, 'id_cost' => 8, 'date' => '2026-11-01', 'amount' => 700.0, 'description' => 'Loyer du dépôt']);
$GLOBALS['corps'] = ['shop' => 5, 'nom' => 'Comptable', 'type' => '8', 'montant' => 700, 'mois' => '2026-11', 'repeter' => 'fin', 'tva' => true, 'taux' => 21];
$r = wr_exploitation_frais_ajout();
Db::exec('UPDATE panel_frais_ajout SET panel_id = NULL WHERE lot = ?', [$r['lot']]);
$GLOBALS['corps'] = ['shop' => 5, 'lot' => $r['lot']];
$a = wr_exploitation_frais_annuler();
verifie('les lignes retrouvées au panel par libellé et montant, puis supprimées', ($a['ok'] ?? false) && $a['annules'] === ['2026-11', '2026-12'] && $GLOBALS['panel']['deletes'] === [101, 102], json_encode([$a, $GLOBALS['panel']['deletes']], JSON_UNESCAPED_UNICODE));
verifie('la ligne saisie à la main reste', isset($GLOBALS['panel']['lignes'][100]) && count($GLOBALS['panel']['lignes']) === 1);

echo "Ajouter un frais : le panel le reporte de lui-même\n";
fauxPanel(true, null, true);
$GLOBALS['corps'] = ['shop' => 5, 'nom' => 'Loyer', 'type' => '3', 'montant' => 900, 'base' => 'mois', 'mois' => '2026-11', 'repeter' => 'fin'];
$r = wr_exploitation_frais_ajout();
verifie('une seule ligne créée : décembre la porte déjà', ($r['ok'] ?? false) && $r['reporteParLePanel'] && count($GLOBALS['panel']['posts']) === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
verifie('décembre dit « par le panel », et vu', count($r['mois']) === 2 && ($r['mois'][1]['parLePanel'] ?? false) && $r['mois'][1]['vu'] === true);

echo "Ajouter un frais : un refus du panel\n";
fauxPanel(false, '2026-11');
$GLOBALS['corps'] = ['shop' => 5, 'nom' => 'Eau', 'montant' => '60,5', 'mois' => '2026-11', 'repeter' => 'mois'];
verifie('un montant illisible est refusé avant le panel', isset(wr_exploitation_frais_ajout()['error']) && $GLOBALS['panel']['posts'] === []);
$GLOBALS['corps'] = ['shop' => 5, 'nom' => 'Eau', 'type' => '3', 'montant' => 60.5, 'mois' => '2026-11', 'repeter' => 'mois'];
$r = wr_exploitation_frais_ajout();
verifie('refusé : le message du panel remonte, rien n’est gardé', $r['crees'] === 0 && str_contains((string) $r['error'], 'Pole amount jest wymagane') && $r['lot'] === null, json_encode($r, JSON_UNESCAPED_UNICODE));
fauxPanel(false, '2026-12');
$GLOBALS['corps'] = ['shop' => 5, 'nom' => 'Eau', 'type' => '3', 'montant' => 60.5, 'mois' => '2026-10', 'repeter' => 'fin'];
$r = wr_exploitation_frais_ajout();
verifie('refusé en décembre : octobre et novembre créés, décembre dit pourquoi', !$r['ok'] && $r['crees'] === 2 && count($r['mois']) === 3 && !$r['mois'][2]['ok'] && str_contains((string) $r['mois'][2]['erreur'], '422'), json_encode($r['mois'], JSON_UNESCAPED_UNICODE));
foreach ([['repeter' => 'toujours'], ['mois' => '2030-01'], ['montant' => -4], ['type' => '99'], ['nom' => '  '], ['tva' => 'oui']] as $mauvais) {
    $GLOBALS['corps'] = $mauvais + ['shop' => 5, 'nom' => 'Eau', 'type' => '3', 'montant' => 60, 'mois' => '2026-11', 'repeter' => 'mois'];
    verifie('refusé : ' . json_encode($mauvais, JSON_UNESCAPED_UNICODE), isset(wr_exploitation_frais_ajout()['error']));
}
verifie('les types de frais du panel, gardés un jour', phTypesFrais() === [['id' => '3', 'nom' => 'Frais de Bureau'], ['id' => '4', 'nom' => 'Frais de Vente'], ['id' => '8', 'nom' => "Autres charges d'exploitation"]] && is_array(setting('phTypes')), json_encode(phTypesFrais(), JSON_UNESCAPED_UNICODE));
PanelApi::$faux = null;

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
