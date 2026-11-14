#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'historique des ventes et des outils de l'assistant — sans base, sans réseau, sans clé.
 *
 *   php bin/tests_historique.php
 *
 * La lecture d'un jour (hvTickets, hvHeuresDe, hvProduitsDe) est pure. Le classement du réseau et la boucle d'outils
 * de l'assistant tournent sur une base factice : les heures du samedi 14/11/2026 au matin, telles que l'ERP les
 * rendait à 8 h 14 (Corbais, Halle, Gosselies, Sombreffe), et un faux modèle qui appelle un outil puis répond.
 */

// --- doublures -----------------------------------------------------------------------
final class Db
{
    /** [sid => [h => [tickets, ca, tickets_pro, ca_pro]]] : les heures du « jour » factice. */
    public static array $heures = [];
    public static array $shops = [];
    public static array $ecrit = [];

    public static function rows(string $sql, array $a = []): array
    {
        if (str_contains($sql, 'FROM shops')) { return array_map(static fn ($i) => ['id' => $i, 'name' => 'magasin ' . $i], self::$shops); }
        if (str_contains($sql, 'FROM ceo_hist_heure')) {
            [$shops, $hd, $hf] = self::filtres($sql, $a);
            $out = [];
            foreach ($shops as $sid) {
                foreach (self::$heures[$sid] ?? [] as $h => $x) {
                    if (($hd !== null && $h < $hd) || ($hf !== null && $h >= $hf)) { continue; }
                    $y = $out[$h] ?? ['heure' => $h, 't' => 0, 'ca' => 0.0, 'tp' => 0, 'cp' => 0.0];
                    $out[$h] = ['heure' => $h, 't' => $y['t'] + $x[0], 'ca' => $y['ca'] + $x[1], 'tp' => $y['tp'] + $x[2], 'cp' => $y['cp'] + $x[3]];
                }
            }
            return array_values($out);
        }
        return [];
    }

    public static function row(string $sql, array $a = []): ?array
    {
        if (str_contains($sql, 'FROM ceo_hist_jour')) {
            [$shops] = self::filtres($sql, $a);
            $o = count(array_filter($shops, static fn ($s) => (self::$heures[$s] ?? []) !== []));
            return ['n' => count($shops), 'o' => $o];
        }
        return null;
    }

    public static function exec(string $sql, array $a = []): int { self::$ecrit[] = [$sql, $a]; return 0; }

    /** Les magasins et les heures d'une requête de hvFiltre. */
    private static function filtres(string $sql, array $a): array
    {
        preg_match('/shop_id IN \(([?,]+)\)/', $sql, $m);
        $n = substr_count($m[1], '?');
        $shops = array_slice($a, 0, $n);
        $reste = array_slice($a, $n + 2);
        if (preg_match('/WEEKDAY\(jour\) \+ 1 IN \(([?,]+)\)/', $sql, $w)) { $reste = array_slice($reste, substr_count($w[1], '?')); }
        $hd = str_contains($sql, 'heure >= ?') ? array_shift($reste) : null;
        $hf = str_contains($sql, 'heure < ?') ? array_shift($reste) : null;
        return [$shops, $hd, $hf];
    }
}
final class PanelApi
{
    public static function configured(): bool { return false; }
    public static function get(string $p): mixed { return null; }
}
final class Anthropic
{
    public static function config(): array { return ['cle' => 'cle-de-test', 'espace' => '']; }
}
function setting(string $k, mixed $d = null): mixed { return $k === 'assistantSel' ? str_repeat('a', 32) : $d; }
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : (is_array($r['data'] ?? null) ? $r['data'] : []); }
function body(): array { return []; }

require __DIR__ . '/../src/stats_ventes.php';
require __DIR__ . '/../src/historique_ventes.php';
require __DIR__ . '/../src/assistant_magasin.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "La liste des tickets d'un jour\n";
$liste = ['data' => [
    ['id' => 11, 'insert_timestamp' => '2026-11-13 06:12:00', 'total_gross_amount_after_discount' => '4.40'],
    ['id' => 12, 'insert_timestamp' => '2026-11-13 06:40:00', 'total_gross_amount_after_discount' => '93.10', 'is_client_b2b' => 1],
    ['id' => 13, 'insert_timestamp' => '2026-11-13 07:05:00', 'total_value_after_discount' => 12],
    ['id' => 14, 'insert_timestamp' => '', 'total_gross_amount_after_discount' => null],
    ['id' => 0, 'insert_timestamp' => '2026-11-13 07:10:00'],
]];
$T = hvTickets($liste);
verifie('quatre tickets, l’identifiant nul écarté', count($T) === 4, json_encode($T));
verifie('heure, total, pro lus', $T[12] === [6, 93.1, true] && $T[13] === [7, 12.0, false], json_encode($T));
verifie('un ticket sans heure ni total reste, à null', $T[14] === [null, null, false], json_encode($T[14]));
$H = hvHeuresDe($T);
verifie('6 h : deux tickets, 97,50 €, dont un pro à 93,10 €', $H[6] === [2, 97.5, 1, 93.1], json_encode($H));
verifie('le ticket sans heure n’a pas d’heure', !isset($H[0]) && count($H) === 2, json_encode($H));

echo "Les produits, heure par heure\n";
$details = [
    11 => ['products' => [
        ['id_product' => 7, 'product_name' => 'Chocoladebroodje', 'quantity' => 2, 'total_gross_value_after_discount' => 3.6],
        ['id_product' => 0, 'product_name' => 'Divers', 'quantity' => 1, 'total_gross_value_after_discount' => 0.8],
    ]],
    12 => ['products' => [['id_product' => 9, 'product_name' => 'Kaasplank', 'quantity' => 7, 'total_gross_value_after_discount' => 93.1]]],
    13 => ['products' => [
        ['id_product' => 7, 'quantity' => 1, 'total_gross_value_after_discount' => 1.8],
        ['id_product' => 20, 'product_name' => 'Brownies', 'quantity' => 1, 'total_gross_value_after_discount' => 10.2, 'id_product_portion' => 3, 'product_portion_type' => 'ONE_QUARTER', 'product_portion_label' => '1/4'],
    ]],
    14 => ['insert_timestamp' => '2026-11-13 08:30:00', 'products' => [['id_product' => 7, 'quantity' => 3, 'total_gross_value_after_discount' => 5.4]]],
];
$cat = [7 => ['nom' => 'Pain au Chocolat', 'cat' => 'Viennoiserie Ind.'], 9 => ['nom' => 'Plateau de fromage', 'cat' => 'Fromages & Salaisons']];
$P = hvProduitsDe($T, $details, $cat);
verifie('le nom réseau, pas celui de la caisse', $P['6|7|0'][3] === 'Pain au Chocolat', json_encode($P['6|7|0']));
verifie('la ligne sans produit n’est pas un produit', !array_filter($P, static fn ($x) => $x[1] === 0));
verifie('le plateau pro : 7 pièces, 93,10 €, tout en pro', $P['6|9|0'] === [6, 9, 0, 'Plateau de fromage', 7.0, 93.1, 7.0, 93.1], json_encode($P['6|9|0']));
verifie('une portion garde sa clé et son libellé', isset($P['7|20|3']) && $P['7|20|3'][3] === 'Brownies — 1/4', json_encode($P));
verifie('le ticket sans heure la prend du détail (8 h)', isset($P['8|7|0']) && $T[14][0] === 8, json_encode($T[14]));
verifie('et son total, de ses lignes (5,40 €)', $T[14][1] === 5.4, json_encode($T[14]));
verifie('tolérance : deux tickets, ou un pour cent', hvTolerance(50) === 2 && hvTolerance(450) === 4);
verifie('jours attendus : les samedis d’un mois de novembre 2026', hvJoursAttendus('2026-11-01', '2026-11-30', [6]) === 4);

echo "Le classement du réseau, samedi 14/11/2026 à 8 h 14 (heures de l’ERP)\n";
Db::$shops = [2, 3, 4, 5];
Db::$heures = [
    2 => [6 => [3, 44.80, 0, 0.0], 7 => [17, 307.72, 0, 0.0], 8 => [7, 118.00, 0, 0.0]],     // Corbais
    3 => [6 => [4, 68.60, 0, 0.0], 7 => [8, 81.70, 0, 0.0], 8 => [4, 37.70, 0, 0.0]],       // Gosselies
    4 => [6 => [8, 155.30, 0, 0.0], 7 => [25, 273.90, 0, 0.0], 8 => [3, 34.80, 0, 0.0]],    // Halle
    5 => [7 => [9, 90.50, 0, 0.0], 8 => [4, 48.40, 0, 0.0]],                                // Sombreffe
];
$hier = date('Y-m-d', strtotime('-1 day'));
$C = amOutilClassement(4, ['du' => $hier, 'au' => $hier, 'indicateur' => 'panier', 'heure_par_heure' => true]);
$refCorbais = amRef(4, 2);
verifie('Corbais premier au panier : 17,43 €', $C['magasins'][0]['ref'] === $refCorbais && $C['magasins'][0]['panier'] === 17.43, json_encode($C['magasins'][0]));
verifie('Halle deuxième : 12,89 €', $C['magasins'][1]['ref'] === 'moi' && $C['magasins'][1]['panier'] === 12.89, json_encode($C['magasins'][1]));
$hh = array_column($C['heureParHeure'], null, 'a');
verifie('à 7 h, Halle menait (19,41 €)', $hh['7 h']['premier'] === 'moi' && $hh['7 h']['maValeur'] === 19.41, json_encode($hh['7 h']));
verifie('à 8 h, un autre magasin passe devant ; Halle 2e', $hh['8 h']['premier'] === $refCorbais && $hh['8 h']['monRang'] === 2, json_encode($hh['8 h']));
$json = json_encode($C, JSON_UNESCAPED_UNICODE);
verifie('aucun nom ni identifiant de magasin dans le résultat', !preg_match('/magasin [0-9]|"sid"|"shop/', $json), $json);
verifie('une ref : M + six caractères, stable, propre au demandeur', preg_match('/^M[0-9A-F]{6}$/', $refCorbais) === 1 && amRef(4, 2) === $refCorbais && amRef(3, 2) !== $refCorbais);
verifie('la ref se résout pour celui qui l’a reçue', amResoudre(4, $refCorbais) === [2] && amResoudre(3, $refCorbais) === null);
$C2 = amOutilClassement(4, ['du' => $hier, 'au' => $hier, 'indicateur' => 'panier', 'heure_fin' => 8]);
verifie('arrêté avant 8 h : Corbais 17,63 €, Halle 13,01 €', $C2['magasins'][0]['panier'] === 17.63 && $C2['magasins'][1]['panier'] === 13.01, json_encode($C2['magasins']));

echo "Les ventes heure par heure d’un autre magasin, par sa ref\n";
$V = amOutilHeures(4, ['magasin' => $refCorbais, 'du' => $hier, 'au' => $hier, 'heure_fin' => 9]);
verifie('trois heures, 27 tickets, 470,52 €', count($V['heures']) === 3 && $V['total']['tickets'] === 27 && $V['total']['ca'] === 470.52, json_encode($V['total']));
verifie('une ref inconnue est une erreur pour le modèle', isset(amOutilHeures(4, ['magasin' => 'MZZZZZZ', 'du' => $hier, 'au' => $hier])['erreur']));
verifie('une date au-delà de 18 mois est coupée, et dit', (amPeriode(['du' => '2020-01-01', 'au' => $hier])['note'] ?? '') !== '');
verifie('heure_fin avant heure_debut : refusé', is_string(amPeriode(['du' => $hier, 'au' => $hier, 'heure_debut' => 9, 'heure_fin' => 7])));

echo "La boucle d’outils de l’assistant (faux modèle)\n";
$appels = [];
$faux = static function (array $corps, array $c) use (&$appels, $hier): array {
    $appels[] = $corps;
    if (count($appels) === 1) {
        $raw = json_encode(['model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'classement_reseau', 'input' => ['du' => $hier, 'au' => $hier, 'indicateur' => 'panier', 'heure_par_heure' => true]],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'outil_absent', 'input' => new stdClass()]]]);
    } else {
        $raw = json_encode(['model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 300, 'cache_read_input_tokens' => 50, 'output_tokens' => 80],
            'content' => [['type' => 'text', 'text' => json_encode(['titre' => 'Le panier', 'resume' => 'Un des magasins vous passe à 8 h.', 'chiffres' => [], 'points' => [], 'priorites' => [], 'conclusion' => 'Allez-y.', 'suites' => []])]]]);
    }
    return [json_decode($raw, true), json_decode($raw), null];
};
$faits = ['magasin' => 'magasin 4', 'luLe' => date('Y-m-d H:i'), 'aujourdhui' => date('Y-m-d') . ' (samedi)'];
$R = amDemander($faits, 'C’est quoi qu’ils vendent bcp au matin ? J’étais 1er jusqu’à 8 h', [], 'vente', 4, $faux);
verifie('une réponse au deuxième appel', ($R['reponse']['titre'] ?? '') === 'Le panier' && count($appels) === 2, json_encode($R));
verifie('les trois outils déclarés', array_column($appels[0]['tools'], 'name') === ['classement_reseau', 'ventes_par_heure', 'produits_vendus']);
$m = $appels[1]['messages'];
$tourModele = json_encode($m[count($m) - 2]['content']);
verifie('le tour du modèle rejoué tel quel, réflexion comprise, « {} » gardé', str_contains($tourModele, '"signature":"sig"') && str_contains($tourModele, '"input":{}'), $tourModele);
$res = $m[count($m) - 1]['content'];
verifie('deux résultats dans un seul message', count($res) === 2 && $res[0]['tool_use_id'] === 'toolu_1' && $res[1]['tool_use_id'] === 'toolu_2');
verifie('l’outil inconnu revient en erreur au modèle', ($res[1]['is_error'] ?? false) === true && !isset($res[0]['is_error']));
verifie('le résultat du classement est anonyme', !str_contains($res[0]['content'], 'magasin 2') && str_contains($res[0]['content'], '"ref":"moi"'), $res[0]['content']);
verifie('les jetons s’additionnent sur les deux appels', $R['usage']['input_tokens'] === 400 && $R['usage']['output_tokens'] === 100 && $R['usage']['cache_read_input_tokens'] === 50, json_encode($R['usage']));
verifie('les outils appelés sont dits', $R['outils'] === ['classement_reseau', 'outil_absent']);
verifie('le personnage du Coach Vente est passé', str_contains($appels[0]['system'][1]['text'], 'Coach Vente'));

echo "Le dernier tour se fait sans outil\n";
$n = 0;
$tetu = static function (array $corps, array $c) use (&$n): array {
    $n++;
    if (($corps['tool_choice']['type'] ?? '') === 'none') {
        $raw = json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"titre":"fin"}']]]);
    } else {
        $raw = json_encode(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'toolu_' . $n, 'name' => 'ventes_par_heure', 'input' => ['magasin' => 'moi', 'du' => date('Y-m-d', strtotime('-1 day')), 'au' => date('Y-m-d', strtotime('-1 day'))]]]]);
    }
    return [json_decode($raw, true), json_decode($raw), null];
};
$R = amDemander($faits, 'test', [], 'pierre', 4, $tetu);
verifie('au plus ' . AM_TOURS . ' appels, le dernier répond', $n === AM_TOURS && ($R['reponse']['titre'] ?? '') === 'fin', $n . ' ' . json_encode($R));

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
