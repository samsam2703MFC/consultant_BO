#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des grands prix de l'année (23/10/2026) — sans base, sans réseau.
 *
 *   php bin/tests_grands_prix.php
 *
 * Ce qui se vérifie : la saison (à venir, en cours, finie, la part écoulée), un grand prix nettoyé (défi, intitulé,
 * valeur, qui paie, sponsor, saison en mois entiers, le magasin de l'année réservé au réseau), et chaque classement :
 * la meilleure vendeuse au CA par heure avec un minimum d'heures au prorata de la saison, la progression face à l'an
 * dernier, les ventes croisées, le B2B et le plus gros ticket (la base partagée), le magasin de l'année, la portée d'un
 * grand prix de magasin.
 */

// --- doublures ---------------------------------------------------------------------------
$GLOBALS['CACHE'] = [];
$GLOBALS['SQL'] = [];
final class Db
{
    public static array $tickets = [];
    /** Les lignes de mot_grand_prix. */
    public static array $gps = [];
    public static function rows(string $sql, array $a = []): array
    {
        $GLOBALS['SQL'][] = $sql;
        if (str_contains($sql, 'FROM mot_grand_prix')) {
            return array_values(array_filter(self::$gps, static fn ($r) => $a === [] || $r['portee'] === 'reseau' || $r['shop'] === $a[0]));
        }
        if (str_contains($sql, 'MAX(t.total_gross_amount_after_discount)')) { return self::$tickets; }
        return [];
    }
    public static function row(string $sql, array $a = []): ?array
    {
        if (preg_match('/FROM mot_(reglage|cache) WHERE cle = \?/', $sql) && array_key_exists($a[0], $GLOBALS['CACHE'])) { return ['valeur' => json_encode($GLOBALS['CACHE'][$a[0]])]; }
        if (str_contains($sql, "WHERE cle = ?") && ($a[0] ?? '') === MOT_REPRISE) { return ['valeur' => '{}']; }
        return null;
    }
    public static function exec(string $sql, array $a = []): int
    {
        if (preg_match('/^INSERT INTO mot_(reglage|cache) \(cle, valeur\) VALUES/', $sql)) { $GLOBALS['CACHE'][$a[0]] = json_decode($a[1], true); }
        return 1;
    }
}
function journalAdd(string $actor, string $kind, ?string $project, string $message, ?string $ts = null): void {}
function body(): array { return []; }
function primesMagasins(): array { return ['4' => 'Halle', '5' => 'Namur', '6' => 'Wavre']; }
function venteEmployes(): array { return [11 => ['nom' => 'Léa M.', 'shop' => '4'], 12 => ['nom' => 'Inès D.', 'shop' => '5'], 13 => ['nom' => 'Chloé B.', 'shop' => '4'], 14 => ['nom' => 'Nora V.', 'shop' => '6']]; }
/** Les mois par vendeuse : [mois => [id => [ca, heures, magasin]]]. */
$GLOBALS['MOIS'] = [];
function venteMoisCache(string $m, array $nomDe): array
{
    $l = [];
    foreach ($GLOBALS['MOIS'][$m] ?? [] as $id => [$ca, $h, $s]) { $l[] = ['id' => $id, 'nom' => venteEmployes()[$id]['nom'], 'shopId' => $s, 'ca' => $ca, 'heures' => $h]; }
    return ['lignes' => $l, 'motif' => null];
}
/** Les ventes croisées : [mois => [id => [tickets, croisées]]]. */
$GLOBALS['CROISEES'] = [];
function pvCroiseesMois(string $m): ?array
{
    $e = [];
    foreach ($GLOBALS['CROISEES'][$m] ?? [] as $id => [$t, $c]) { $e[$id] = ['t' => $t, 'c' => $c, 'l' => 0]; }
    return ['e' => $e];
}
function primeMagasinReglages(): array { return ['heuresMin' => 20, 'paliers' => []]; }
function primesBudgets(string $m): array { return ['4' => ['montant' => 100000.0], '5' => ['montant' => 50000.0]]; }
function primesMagasinMois(int $sid, string $m, array $reg, ?float $hv, float $he, ?array $budget = null, int $p = 0): array
{
    return ['ca' => ['4' => 90000.0, '5' => 55000.0, '6' => 70000.0][(string) $sid], 'objectif' => $budget['montant'] ?? null];
}
/** Les tickets pro : [magasin => [{c, e, j, ht}]]. */
$GLOBALS['PRO'] = [];
function b2bTickets(int $sid, string $du, string $au): ?array { return $GLOBALS['PRO'][(string) $sid] ?? []; }

require __DIR__ . '/../src/motivation.php';
require __DIR__ . '/../src/grands_prix.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$gp = static fn (array $x) => $x + ['id' => 0, 'portee' => 'reseau', 'shop' => null, 'defi' => 'vendeuse', 'titre' => 'Refaire ta terrasse', 'lot' => '', 'valeur' => 5000,
    'payeur' => 'sponsor', 'sponsor' => 'Jardins Lemaire', 'sponsorSite' => '', 'photo' => '', 'seuil' => 600, 'seuilSorte' => 'heures', 'du' => '2025-01-01', 'au' => '2025-12-31', 'actif' => true, 'resultat' => null];

// --- 1. la saison -------------------------------------------------------------------------
echo "La saison\n";
$s = gpSaison('2026-01-01', '2026-12-31', '2026-10-23');
verifie('en cours le 23/10, 70 jours restants', $s['etat'] === 'en_cours' && $s['joursRestants'] === 70, json_encode($s));
verifie('la part écoulée : 295 jours sur 365', abs($s['part'] - 295 / 365) < 0.001, (string) $s['part']);
verifie('à venir, finie', gpSaison('2027-01-01', '2027-12-31', '2026-10-23')['etat'] === 'a_venir' && gpSaison('2025-01-01', '2025-12-31', '2026-10-23')['etat'] === 'finie');
verifie('les mois d’une saison passée : douze', count(gpMois('2025-01-01', '2025-12-31')) === 12 && gpMois('2025-01-01', '2025-12-31')[11] === '2025-12');

// --- 2. un grand prix nettoyé ----------------------------------------------------------------
echo "\nUn grand prix nettoyé\n";
$m = primesMagasins();
$p = gpPropre(['portee' => 'reseau', 'defi' => 'vendeuse', 'titre' => '  Refaire   ta terrasse ', 'valeur' => '5000', 'payeur' => 'sponsor', 'sponsor' => 'Jardins Lemaire', 'sponsorSite' => 'jardins-lemaire.be', 'du' => '2026-01-15', 'au' => '2026-12-02'], $m);
verifie('l’intitulé ramassé, la saison en mois entiers, le site complété', ($p['titre'] ?? '') === 'Refaire ta terrasse' && $p['du'] === '2026-01-01' && $p['au'] === '2026-12-31' && $p['sponsor_site'] === 'https://jardins-lemaire.be', json_encode($p));
verifie('le minimum d’heures par défaut : 600', $p['seuil'] === 600);
verifie('un sponsor sans nom est refusé', isset(gpPropre(['defi' => 'ticket', 'titre' => 'Trottinette', 'valeur' => 600, 'payeur' => 'sponsor', 'du' => '2026-01-01', 'au' => '2026-12-31'], $m)['error']));
verifie('le magasin de l’année est réservé au réseau', isset(gpPropre(['portee' => 'magasin', 'shop' => '4', 'defi' => 'magasin', 'titre' => 'Soirée', 'valeur' => 500, 'du' => '2026-01-01', 'au' => '2026-12-31'], $m)['error']));
$pm = gpPropre(['portee' => 'magasin', 'shop' => '4', 'defi' => 'ticket', 'titre' => 'Un week-end', 'valeur' => 300, 'payeur' => 'marque', 'du' => '2026-01-01', 'au' => '2026-12-31'], $m);
verifie('un grand prix de magasin : payé par le magasin (pas par la marque), sans minimum pour un ticket', ($pm['payeur'] ?? '') === 'magasin' && $pm['seuil'] === null && $pm['shop'] === '4', json_encode($pm));
verifie('un magasin inconnu est refusé', isset(gpPropre(['portee' => 'magasin', 'shop' => '99', 'defi' => 'ticket', 'titre' => 'Un week-end', 'valeur' => 300, 'du' => '2026-01-01', 'au' => '2026-12-31'], $m)['error']));
verifie('une photo hors du dossier des primes est refusée', isset(gpPropre(['defi' => 'ticket', 'titre' => 'Trottinette', 'valeur' => 600, 'photo' => '../config/config.php', 'du' => '2026-01-01', 'au' => '2026-12-31'], $m)['error']));
verifie('une saison de plus de deux ans est refusée', isset(gpPropre(['defi' => 'ticket', 'titre' => 'Trottinette', 'valeur' => 600, 'du' => '2026-01-01', 'au' => '2028-01-31'], $m)['error']));

// --- 3. les classements (une saison finie : 2025) ------------------------------------------
echo "\nLes classements\n";
foreach (['2025-01', '2025-06'] as $mm) {
    $GLOBALS['MOIS'][$mm] = [11 => [16000.0, 320.0, '4'], 12 => [15000.0, 300.0, '5'], 13 => [9000.0, 120.0, '4'], 14 => [12000.0, 310.0, '6']];
}
$GLOBALS['MOIS']['2024-01'] = [11 => [14000.0, 320.0, '4'], 12 => [10000.0, 300.0, '5'], 14 => [12500.0, 310.0, '6']];
$GLOBALS['MOIS']['2024-06'] = $GLOBALS['MOIS']['2024-01'];
$c = gpClassement($gp(['defi' => 'vendeuse']), '2026-10-23');
verifie('meilleure vendeuse : Inès (50 € / h) devant Léa (50 €… 16000/320 = 50) — à égalité le nom tranche', array_column($c['liste'], 'nom') === ['Inès D.', 'Léa M.', 'Nora V.'], json_encode(array_column($c['liste'], 'txt', 'nom')));
verifie('Chloé hors classement : 240 h pour 600 au minimum', $c['horsSeuil'] === 1 && $c['seuilCourant'] === 600);
$c = gpClassement($gp(['defi' => 'vendeuse', 'du' => '2025-01-01', 'au' => '2025-12-31']), '2025-07-02');
verifie('à mi-saison (2 juillet), le minimum est au prorata : 299 h — Chloé (240 h) dehors, les autres dedans', $c['seuilCourant'] === 299 && $c['enCourse'] === 3, json_encode([$c['seuilCourant'], $c['enCourse']]));
$c = gpClassement($gp(['defi' => 'progression', 'seuil' => 300]), '2026-10-23');
verifie('progression : Inès + 50 %, Léa + 14,3 %, Nora − 4 %', array_column($c['liste'], 'txt') === ['+50,0 %', '+14,3 %', '−4,0 %'], json_encode(array_column($c['liste'], 'txt', 'nom')));
$GLOBALS['CROISEES']['2025-01'] = [11 => [500, 200], 12 => [400, 220], 14 => [100, 90]];
$GLOBALS['CACHE'] = [];   // les mois relus avec la moisson
$c = gpClassement($gp(['defi' => 'croisees', 'seuil' => 300]), '2026-10-23');
verifie('ventes croisées : Inès 55 % devant Léa 40 % ; Nora (100 tickets) sous le minimum', array_column($c['liste'], 'nom') === ['Inès D.', 'Léa M.'] && $c['horsSeuil'] === 1, json_encode(array_column($c['liste'], 'txt', 'nom')));
$GLOBALS['PRO'] = ['4' => [['c' => 'c1', 'e' => 11, 'j' => '2025-03-02', 'ht' => 1200.0], ['c' => 'c2', 'e' => 13, 'j' => '2025-03-05', 'ht' => 400.0], ['c' => 'c1', 'e' => 11, 'j' => '2025-04-02', 'ht' => 800.0]],
    '5' => [['c' => 'c3', 'e' => 12, 'j' => '2025-05-02', 'ht' => 1500.0]]];
$c = gpClassement($gp(['defi' => 'b2b', 'seuil' => null]), '2026-10-23');
verifie('B2B : Léa 2 000 € HT, Inès 1 500, Chloé 400', array_column($c['liste'], 'valeur') === [2000.0, 1500.0, 400.0] && $c['liste'][0]['txt'] === '2 000 € HT', json_encode(array_column($c['liste'], 'txt', 'nom')));
Db::$tickets = [['e' => 11, 'mx' => '182.40', 'dernier' => '2025-12-30 18:02:00'], ['e' => 14, 'mx' => '96.10', 'dernier' => '2025-12-31 11:00:00'], ['e' => 12, 'mx' => '240.00', 'dernier' => '2025-12-29 10:00:00']];
$GLOBALS['CACHE'] = [];
$c = gpClassement($gp(['defi' => 'ticket', 'seuil' => null]), '2026-10-23');
verifie('plus gros ticket : Inès 240 €, Léa 182,40 €, Nora 96,10 € ; lu jusqu’au 31/12', array_column($c['liste'], 'txt') === ['240,00 €', '182,40 €', '96,10 €'] && $c['jusqua'] === '2025-12-31', json_encode([array_column($c['liste'], 'txt', 'nom'), $c['jusqua']]));
$sqlTk = implode("\n", array_filter($GLOBALS['SQL'], static fn ($q) => str_contains($q, 'MAX(t.total_gross_amount_after_discount)')));
verifie('le plus gros ticket écarte les clients pro', str_contains($sqlTk, 'COALESCE(k.is_b2b, 0) = 0') && str_contains($sqlTk, 't.id_shop IN (4,5,6)'));
$c = gpClassement($gp(['defi' => 'ticket', 'seuil' => null, 'portee' => 'magasin', 'shop' => '4']), '2026-10-23');
verifie('un grand prix de magasin ne classe que ses vendeuses', array_column($c['liste'], 'nom') === ['Léa M.'], json_encode(array_column($c['liste'], 'nom')));
$c = gpClassement($gp(['defi' => 'magasin', 'seuil' => null]), '2026-10-23');
verifie('magasin de l’année : Namur 110 %, Halle 90 % ; Wavre sans objectif hors classement', array_column($c['liste'], 'txt', 'nom') === ['Namur' => '110,0 %', 'Halle' => '90,0 %'] && $c['horsSeuil'] === 1, json_encode(array_column($c['liste'], 'txt', 'nom')));
$c = gpClassement($gp(['du' => '2027-01-01', 'au' => '2027-12-31']), '2026-10-23');
verifie('une saison à venir : personne de classé', $c['liste'] === [] && $c['saison']['etat'] === 'a_venir');
verifie('les caches des grands prix vont dans mot_cache', motCle('grandsPrixMois2025-01') === 'cache' && motCle('gpDayparts') === null);

// --- 4. dans l'app des vendeuses (GET /ventes/moi) ------------------------------------------
echo "\nDans l’app des vendeuses\n";
$ligne = static fn (array $x) => $x + ['id' => 1, 'portee' => 'reseau', 'shop' => null, 'defi' => 'vendeuse', 'titre' => 'Refaire ta terrasse', 'lot' => null, 'valeur' => 5000,
    'payeur' => 'marque', 'sponsor' => null, 'sponsor_site' => null, 'photo' => null, 'seuil' => 600, 'du' => '2025-01-01', 'au' => '2025-12-31', 'actif' => 1, 'resultat' => null];
Db::$gps = [$ligne(['id' => 1]), $ligne(['id' => 2, 'portee' => 'magasin', 'shop' => '4', 'defi' => 'ticket', 'titre' => 'Un dîner', 'valeur' => 150, 'seuil' => null]),
    $ligne(['id' => 3, 'portee' => 'magasin', 'shop' => '5', 'defi' => 'ticket', 'titre' => 'Un resto', 'valeur' => 100, 'seuil' => null]),
    $ligne(['id' => 4, 'defi' => 'magasin', 'titre' => 'Soirée d’équipe', 'valeur' => 3500, 'seuil' => null]),
    $ligne(['id' => 5, 'titre' => 'Éteint', 'actif' => 0])];
$GLOBALS['CACHE'] = [];
$v = gpPourVendeuse(11, '2026-01-20');
verifie('Léa (Halle) : les grands prix de la marque et ceux de son magasin, pas ceux de Namur ni les éteints ; le plus gros lot en tête', array_column($v, 'id') === [1, 4, 2], json_encode(array_column($v, 'id')));
$vV = array_values(array_filter($v, static fn ($x) => $x['id'] === 1))[0];
verifie('son rang à la meilleure vendeuse : 2e (à égalité avec Inès, le nom tranche), sa valeur, le top 3 avec « moi »', ($vV['moi']['rang'] ?? null) === 2 && $vV['top'][1]['moi'] === true && $vV['top'][0]['nom'] === 'Inès D.' && $vV['unite'] === 'eur_h', json_encode($vV['moi']));
$vM = array_values(array_filter($v, static fn ($x) => $x['id'] === 4))[0];
verifie('le magasin de l’année : le rang de son magasin (Halle 2e), les noms des magasins sans préfixe', ($vM['moi']['rang'] ?? null) === 2 && $vM['top'][0]['nom'] === 'Namur', json_encode([$vM['moi'], $vM['top']]));
verifie('fini depuis moins de 45 jours : encore là, état « finie »', $vV['etat'] === 'finie');
verifie('fini depuis plus de 45 jours : plus montré', gpPourVendeuse(11, '2026-03-01') === []);
$GLOBALS['CACHE'] = [];
$c = gpPourVendeuse(13, '2026-01-20');
$cV = array_values(array_filter($c, static fn ($x) => $x['id'] === 1))[0];
verifie('Chloé sous le minimum : pas de rang, il lui manque 360 h', $cV['moi'] === null && $cV['manque'] === ['n' => 360, 'sorte' => 'heures'], json_encode([$cV['moi'], $cV['manque']]));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
