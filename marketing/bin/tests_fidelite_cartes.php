#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des cartes de fidélité vues par public (marketing/src/fidelite_cartes.php, 28/11/2026, maquette C) — sans base,
 * sans réseau, sans jeton.
 *
 *   php marketing/bin/tests_fidelite_cartes.php
 *
 * L'ERP est une doublure (`$GLOBALS['FC_ERP']`) qui rend les fiches de `/shops/{id}/loyalty/customers` semaine par
 * semaine ; les clients de TF Buddy (`$GLOBALS['FC_LIENS']`) disent qui est une société et qui en dépend. On vérifie : qui
 * est particulier, société ou personne d'une société (par `company_client_id` ou par le bureau) ; la saison du jour et sa
 * semaine en cours ; les cases du bingo (achats de tous les magasins additionnés, seuil, clients du magasin seulement) ;
 * les deux routes, et le refus quand l'ERP ne répond pas.
 */

// --- doublures : pas de base, pas de réseau ------------------------------------------------
final class Db
{
    public static function row(string $sql, array $a = []): ?array { return ['n' => 0]; }
}
function fidMagasins(): array { return [['id' => '2', 'nom' => 'Corbais'], ['id' => '4', 'nom' => 'Halle']]; }

require __DIR__ . '/../src/fidelite_cartes.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, $detail = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== null ? ' — ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n"; }
}

/* Les achats de la doublure : [shop => [lundi de la semaine => [client => €]]]. */
$ACHATS = [
    4 => ['2026-11-23' => [11 => 8.00, 500 => 40.00, 501 => 12.00, 12 => 0.0], '2026-11-30' => [11 => 3.00, 500 => 15.00, 501 => 0.0, 12 => 22.40]],
    2 => ['2026-11-23' => [11 => 4.50, 99 => 30.00], '2026-11-30' => [99 => 11.00]],
];
$NOMS = [11 => ['Annelies', 'Vermeulen'], 12 => ['Marc', 'Dubois'], 500 => ['THE BREWERY', 'TEST'], 501 => ['Bart', 'Vandamme'], 99 => ['Elise', 'Corbais']];
$ERP_EN_PANNE = false;
$GLOBALS['FC_ERP'] = static function (string $chemin) use (&$ACHATS, $NOMS, &$ERP_EN_PANNE): array {
    if ($ERP_EN_PANNE) { return ['code' => 503, 'corps' => null, 'erreur' => 'panne']; }
    $u = parse_url($chemin); parse_str($u['query'] ?? '', $q);
    if ($u['path'] === '/admin/loyalty/cards') {
        return ['code' => 200, 'corps' => ['items' => [['id' => 7, 'name' => 'Carte L’Atelier', 'color_fill' => '#8D1D2C', 'points_config' => ['points_enabled' => 1], 'active' => 1]]]];
    }
    if (preg_match('#^/shops/(\d+)/loyalty/statistics$#', $u['path'], $m)) {
        return ['code' => 200, 'corps' => ['participants' => ['unique_customers' => 4, 'active_in_period' => 3], 'shop_activity' => ['points_earned' => 80]]];
    }
    if (preg_match('#^/shops/(\d+)/loyalty/customers$#', $u['path'], $m)) {
        $s = (int) $m[1];
        // La semaine dont le lundi tombe dans la période ; une période plus longue (la vue des cartes) prend tout.
        $L = [];
        foreach ($ACHATS[$s] ?? [] as $lundi => $C) {
            if ($lundi < $q['from'] || $lundi > $q['to']) { continue; }
            foreach ($C as $id => $e) { $L[$id] = ($L[$id] ?? 0) + $e; }
        }
        $items = [];
        foreach ($L as $id => $e) {
            $items[] = ['id' => $id, 'name' => $NOMS[$id][0], 'surname' => $NOMS[$id][1], 'active' => 1,
                'programs' => [['id' => 7, 'name' => 'Carte L’Atelier', 'global_progress' => ['points' => ['balance' => (int) $e * 3, 'tiers' => [['points' => 100, 'voucher_cents' => 500, 'reached' => $e * 3 >= 100, 'remaining' => max(0, 100 - (int) $e * 3)]]]],
                    'shop_activity' => ['points_earned' => (int) $e]]],
                'shop_activity' => ['transactions' => $e > 0 ? 1 : 0, 'gross_sales_before_corrections' => $e]];
        }
        return ['code' => 200, 'corps' => ['items' => $items, 'pagination' => ['total' => count($items)]]];
    }
    return ['code' => 404, 'corps' => null, 'erreur' => 'inconnu : ' . $chemin];
};
/* Les clients de TF Buddy du magasin : 500 est une société, 501 en dépend par son bureau 30 ; 12, un particulier. */
$GLOBALS['FC_LIENS'] = static fn (int $sid): array => fcLiens([500 => ['nom' => 'Brouwerij Zennevallei', 'ville' => 'Halle'], 600 => ['nom' => 'Garage Lembeek', 'ville' => 'Lembeek']],
    [501 => ['societeId' => 0, 'bureauId' => 30], 12 => ['societeId' => 0, 'bureauId' => 0], 602 => ['societeId' => 600, 'bureauId' => 0]],
    [30 => ['societe' => 500, 'nom' => 'Siège', 'facturation' => 'facturé à la société']]) + ['lu' => true, 'bureauxLus' => true];

echo "Qui est qui (particulier, société, personne d'une société)\n";
$L = fcLiens([500 => ['nom' => 'Brouwerij', 'ville' => 'Halle']], [501 => ['societeId' => 500], 502 => ['bureauId' => 30], 503 => ['bureauId' => 31], 504 => ['societeId' => 777]],
    [30 => ['societe' => 500, 'nom' => 'Siège', 'facturation' => 'facturé à la société'], 31 => ['societe' => 0, 'nom' => 'Sans société']]);
verifie('une société B2B est une société', ($L['type'][500] ?? '') === 'societe', $L['type']);
verifie('une personne rattachée par company_client_id', ($L['type'][501] ?? '') === 'personne' && $L['societe'][501] === 500, $L);
verifie('une personne rattachée par son bureau', ($L['type'][502] ?? '') === 'personne' && $L['societe'][502] === 500, $L);
verifie('un bureau sans société, ou une société hors du magasin : la personne reste un particulier', !isset($L['type'][503]) && !isset($L['type'][504]), $L['type']);
verifie('la société compte ses personnes, ses bureaux et sa facturation', $L['societes'][500]['personnes'] === 2 && $L['societes'][500]['bureaux'] === ['Siège'] && $L['societes'][500]['facturation'] === ['facturé à la société'], $L['societes'][500]);

echo "La saison du jour\n";
$s = fcSaison('2026-11-28');
verifie('le 28/11/2026 : l’hiver, semaine 1 sur 13 en cours', $s !== null && $s['id'] === 'H27' && $s['enCours'] === 1 && $s['closes'] === 0 && count($s['semaines']) === 13, $s ? array_diff_key($s, ['semaines' => 1]) : null);
verifie('la semaine 1 va du lundi 23 au dimanche 29/11', $s['semaines'][0]['du'] === '2026-11-23' && $s['semaines'][0]['au'] === '2026-11-29' && $s['semaines'][0]['titre'] === 'Le cougnou est là', $s['semaines'][0]);
$s = fcSaison('2026-12-02');
verifie('le 02/12 : semaine 2 en cours, une close', $s['enCours'] === 2 && $s['closes'] === 1 && $s['semaines'][0]['etat'] === 'passee', array_diff_key($s, ['semaines' => 1]));
$s = fcSaison('2026-11-20');
verifie('avant le début : la saison à venir, aucune semaine en cours', $s['etat'] === 'avenir' && $s['enCours'] === 0, array_diff_key($s, ['semaines' => 1]));

echo "Les cases du bingo\n";
$B = fcBingo([1 => [4 => [11 => 8.0, 12 => 0.0], 2 => [11 => 4.5, 99 => 30.0]], 2 => [4 => [11 => 3.0, 12 => 22.4], 2 => [99 => 11.0]]], 4, 10.0, 2);
verifie('les achats s’additionnent d’un magasin à l’autre (8 € + 4,50 € : la case est cochée)', ($B[11]['euros'] ?? null) === [12.5, 3.0] && $B[11]['cases'] === 1, $B[11] ?? null);
verifie('sous le seuil, la case reste vide', $B[12]['euros'] === [0.0, 22.4] && $B[12]['cases'] === 1, $B[12]);
verifie('un client d’un autre magasin n’est pas rendu', !isset($B[99]), array_keys($B));
$B = fcBingo([1 => [4 => [5 => 9.995]]], 4, 10.0, 1);
verifie('9,995 € arrondis à 10,00 € cochent la case', $B[5]['cases'] === 1, $B[5]);

echo "GET /fidelite/cartes/bingo\n";
$GLOBALS['FC_AUJOURDHUI'] = '2026-12-02';
$_GET = ['shop' => '4']; $r = ep_fidelite_cartes_bingo(); $_GET = [];
verifie('la semaine 2 de l’hiver, les règles du module', ($r['semaine'] ?? 0) === 2 && $r['saison']['id'] === 'H27' && $r['regles']['seuil'] === 10.0 && $r['regles']['tousLes'] === 3, array_diff_key($r, ['clients' => 1, 'saison' => 1]));
verifie('un client de Halle, ses achats de Corbais compris', ($r['clients'][11]['euros'] ?? null) === [12.5, 3.0] && $r['clients'][11]['cases'] === 1, $r['clients'][11] ?? null);
verifie('la société et la personne ont leurs cases', $r['clients'][500]['cases'] === 2 && $r['clients'][501]['cases'] === 1, $r['clients']);
verifie('un client de Corbais seulement n’est pas dans le bingo de Halle', !isset($r['clients'][99]), array_keys($r['clients']));
$ERP_EN_PANNE = true;
$_GET = ['shop' => '4']; $r = ep_fidelite_cartes_bingo(); $_GET = [];
verifie('l’ERP ne répond pas : une erreur, pas de cases inventées', isset($r['error']) && !isset($r['clients']), $r);
$ERP_EN_PANNE = false;
$GLOBALS['FC_AUJOURDHUI'] = '2026-11-20';
$_GET = ['shop' => '4']; $r = ep_fidelite_cartes_bingo(); $_GET = [];
verifie('avant la saison : aucune semaine, aucun client', $r['semaine'] === 0 && $r['clients'] === [] && $r['saison']['etat'] === 'avenir', $r);
$_GET = [];
$r = @ep_fidelite_cartes_bingo();
verifie('sans magasin : refusé', str_contains((string) ($r['error'] ?? ''), 'magasin manquant'), $r);

echo "GET /fidelite/cartes : particuliers, sociétés, personnes\n";
$ACHATS[4]['2026-11-02'] = [11 => 10.0, 500 => 20.0, 501 => 5.0, 12 => 3.0];   // dans le mois en cours de la vue
$ACHATS[4][date('Y-m-01')] = [11 => 10.0, 500 => 20.0, 501 => 5.0, 12 => 3.0];
$_GET = ['shop' => '4', 'periode' => 'annee']; $r = ep_fidelite_cartes(); $_GET = [];
$par = []; foreach ($r['clients'] ?? [] as $c) { $par[$c['id']] = $c; }
verifie('un particulier', ($par[11]['type'] ?? '') === 'particulier' && $par[11]['societe'] === null, $par[11] ?? $r);
verifie('le compte de la société, au nom que TF Buddy lui donne', ($par[500]['type'] ?? '') === 'societe' && $par[500]['societe'] === 500 && $par[500]['nom'] === 'Brouwerij Zennevallei', $par[500] ?? null);
verifie('une personne de la société (par son bureau)', ($par[501]['type'] ?? '') === 'personne' && $par[501]['societe'] === 500, $par[501] ?? null);
$E = $r['entreprises'] ?? [];
verifie('une seule entreprise montrée : celle qui a une carte ou une personne inscrite', count($E) === 1 && $E[0]['id'] === 500 && $E[0]['compte'] === true && $E[0]['inscrites'] === 1, $E);
verifie('le nombre de personnes, les bureaux et la facturation de la société', $E[0]['personnes'] === 1 && $E[0]['bureaux'] === ['Siège'] && $E[0]['facturation'] === ['facturé à la société'], $E[0]);
verifie('les sociétés du magasin sans carte sont comptées', ($r['liens']['sansCarte'] ?? null) === 1 && $r['liens']['bureaux'] === true, $r['liens'] ?? null);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
