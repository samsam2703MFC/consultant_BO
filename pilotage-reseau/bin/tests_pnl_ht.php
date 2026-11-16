#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du P&L hors TVA et de l'onglet Frais (src/pnl_ht.php, 16/11/2026) — base SQLite en mémoire, panel en doublure.
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
  static function configured() { return true; }
  static function getParallele(array $ch, int $f = 4): array { $o = []; foreach ($ch as $k => $c) { self::$appels++; $o[$k] = self::$reponse; } return $o; }
  static function get(string $c) { return self::$ds; }
}

Db::exec('CREATE TABLE ceo_app_setting (key TEXT PRIMARY KEY, value TEXT)');
Db::exec('CREATE TABLE ceo_verif_ca (shop_id INT, jour TEXT, caisse_ttc REAL, caisse_tva REAL, PRIMARY KEY (shop_id, jour))');
require __DIR__ . '/../src/pnl_ht.php';
require __DIR__ . '/../src/ventes_pro.php';

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
Db::exec('INSERT INTO ceo_verif_ca VALUES (4, ?, ?, ?)', ['2026-11-14', 1000.0, 60.0]);
Db::exec('INSERT INTO ceo_verif_ca VALUES (4, ?, ?, ?)', ['2026-11-15', 2000.0, 100.0]);
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

echo "Les frais du panel gardés une heure\n";
PanelApi::$reponse = ['items' => [['name' => 'Loyer', 'amount' => 4800]]];
PanelApi::$appels = 0;
$P1 = phFraisPanel([4], '2026-11'); $P2 = phFraisPanel([4], '2026-11');
verifie('une seule lecture du panel pour deux appels', PanelApi::$appels === 1 && $P2[4]['lu'] && count($P2[4]['lignes']) === 1);
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

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
