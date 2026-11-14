#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du plan de trésorerie — sans base, sans réseau.
 *
 *   php bin/tests_tresorerie.php
 *
 * Les dates du plan, la répartition du budget au profil de l'an passé, les semaines (N-1, budget, réalisé), les mois,
 * la tendance, et l'écriture du plan avec sa version (une base en mémoire tient lieu de MySQL).
 */

// --- doublures -----------------------------------------------------------------------
final class Db
{
    /** @var array<string, array{donnees: string, version: int, maj_le: string}> */
    public static array $plans = [];
    public static array $perf = [];
    public static function rows(string $sql, array $a = []): array
    {
        if (str_contains($sql, 'ceo_shop_month_perf')) { return array_values(array_filter(self::$perf, static fn ($r) => (string) $r['shop_id'] === (string) $a[0])); }
        return [];
    }
    public static function row(string $sql, array $a = []): ?array
    {
        if (str_contains($sql, 'FROM pil_tresorerie_plan')) { return self::$plans[$a[0]] ?? null; }
        return null;
    }
    public static function exec(string $sql, array $a = []): int
    {
        if (str_starts_with($sql, 'CREATE TABLE')) { return 0; }
        if (str_starts_with($sql, 'INSERT INTO pil_tresorerie_plan')) {
            if (isset(self::$plans[$a[0]])) { throw new PDOException('doublon', 23000); }
            self::$plans[$a[0]] = ['donnees' => $a[1], 'version' => 1, 'maj_le' => '2026-11-14 18:00:00'];
            return 1;
        }
        if (str_starts_with($sql, 'UPDATE pil_tresorerie_plan')) {
            [$json, $shop, $v] = $a;
            if (!isset(self::$plans[$shop]) || self::$plans[$shop]['version'] !== $v) { return 0; }
            self::$plans[$shop] = ['donnees' => $json, 'version' => $v + 1, 'maj_le' => '2026-11-14 18:05:00'];
            return 1;
        }
        if (str_starts_with($sql, 'DELETE FROM pil_tresorerie_plan')) {
            [$shop, $v] = $a;
            if (!isset(self::$plans[$shop]) || self::$plans[$shop]['version'] !== $v) { return 0; }
            unset(self::$plans[$shop]);
            return 1;
        }
        return 0;
    }
}
$CORPS = [];
function body(): array { global $CORPS; return $CORPS; }
$REGLAGES = ['budgetCharges' => ['categories' => [
    ['nom' => 'Matière', 'lignes' => [['poste' => 'Matières premières', 'pct' => 38], ['poste' => 'Emballages', 'pct' => 0, 'pctTheo' => 2]]],
    ['nom' => 'Frais d’occupation', 'lignes' => [['poste' => 'Loyer', 'pct' => 6], ['poste' => 'Énergie', 'pct' => 0, 'pctTheo' => 5], ['poste' => 'Vide', 'pct' => 0]]],
], 'paliers' => [['nom' => 'Marge brute', 'droite' => 'cat:Matière']]]];
function setting(string $k, mixed $d = null): mixed { global $REGLAGES; return $REGLAGES[$k] ?? $d; }

require __DIR__ . '/../src/tresorerie.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; fwrite(STDOUT, "  ok  $nom\n"); }
    else { $ko++; fwrite(STDOUT, "  KO  $nom" . ($detail !== "" ? " — $detail" : "") . "\n"); }
}

fwrite(STDOUT, "Les dates du plan\n");
verifie('un samedi, le plan part du lundi qui suit', trDebutDefaut('2026-11-14') === '2026-11-16');
verifie('un dimanche aussi', trDebutDefaut('2026-11-15') === '2026-11-16');
verifie('un mercredi, du lundi de la semaine', trDebutDefaut('2026-11-18') === '2026-11-16');
verifie('le lundi lui-même', trDebutDefaut('2026-11-16') === '2026-11-16');
verifie('ouvert le 16/11/2026, il va jusqu’au lundi 27/12/2027', trFin('2026-11-16') === '2027-12-27', trFin('2026-11-16'));
verifie('59 semaines, comme le modèle', count(trLundis('2026-11-16', trFin('2026-11-16'))) === 59);
verifie('ouvert en janvier, il couvre l’année', trFin('2027-01-04') === '2027-12-27' && count(trLundis('2027-01-04', '2027-12-27')) === 52);
verifie('ouvert en juillet, jusqu’à la fin de l’année suivante (26 semaines au moins)', trFin('2027-07-05') === '2028-12-25', trFin('2027-07-05'));
verifie('le lundi d’un dimanche', trLundi('2026-11-15') === '2026-11-09');

fwrite(STDOUT, "Le budget réparti au profil de l’an passé\n");
// Novembre 2025 : fermé le lundi, 100 € les autres jours, 400 € les samedis.
$jours = [];
foreach (trJoursDuMois('2025-11') as $d) {
    $n = (int) (new DateTimeImmutable($d))->format('N');
    if ($n === 1) { continue; }
    $jours[$d] = $n === 6 ? 400.0 : 100.0;
}
// Le profil de novembre 2026 se lit 364 jours plus tôt : 2/11/2025 → 1/12/2025 (le 1er décembre manque → 0).
$bj = trBudgetJours(['2026-11' => ['ca' => 30000.0, 'source' => 'budget']], $jours, '2026-11-14');
// Le dernier jour du profil (364 jours avant le 30/11/2026, le 01/12/2025) est passé : le profil N-1 sert.
verifie('chaque jour du mois a un budget', count($bj) === 30);
verifie('la somme des jours vaut le budget du mois', abs(array_sum(array_column($bj, 'v')) - 30000.0) < 0.01, (string) array_sum(array_column($bj, 'v')));
verifie('le profil vient de l’an passé', $bj['2026-11-16']['profil'] === 'n1');
verifie('un lundi (fermé l’an passé) ne reçoit rien', $bj['2026-11-16']['v'] === 0.0);
verifie('un samedi pèse quatre fois un mardi', abs($bj['2026-11-21']['v'] - 4 * $bj['2026-11-17']['v']) < 0.01);
$bj2 = trBudgetJours(['2027-12' => ['ca' => 31000.0, 'source' => 'theorique']], [], '2026-11-14');
verifie('sans an passé lisible, à parts égales', abs($bj2['2027-12-10']['v'] - 1000.0) < 0.01 && $bj2['2027-12-10']['profil'] === 'egal');
verifie('la source suit le budget (CA théorique de l’étude)', $bj2['2027-12-10']['source'] === 'theorique');
// Décembre 2027 : un an plus tôt (décembre 2026) n'est pas passé → deux ans plus tôt (décembre 2025).
$jours2 = [];
foreach (trJoursDuMois('2025-12') as $d) { $jours2[$d] = 100.0; }
foreach (['2025-11-29', '2025-11-30'] as $d) { $jours2[$d] = 100.0; }
$bj3 = trBudgetJours(['2027-12' => ['ca' => 3100.0, 'source' => 'budget']], $jours2, '2026-11-14');
verifie('à défaut de l’an passé, le profil d’il y a deux ans', $bj3['2027-12-15']['profil'] === 'n2');

fwrite(STDOUT, "Les semaines du plan\n");
$jours3 = [];
for ($d = '2025-11-10'; $d <= '2025-11-30'; $d = trJour($d, 1)) { $jours3[$d] = 1000.0; }       // N-1
for ($d = '2026-11-02'; $d <= '2026-11-14'; $d = trJour($d, 1)) { $jours3[$d] = 1200.0; }       // réalisé
$bm = ['2026-11' => ['ca' => 30000.0, 'source' => 'budget']];
$bjs = trBudgetJours($bm, $jours3, '2026-11-14');
$S = trSemaines(['2026-11-09', '2026-11-16', '2026-11-30'], $jours3, $bjs, '2026-11-14');
verifie('la semaine en cours : réalisé à ce jour, pas close', $S[0]['realise'] === 7200.0 && $S[0]['complet'] === false, json_encode($S[0]));
verifie('son N-1 : la semaine décalée de 364 jours', $S[0]['n1'] === 7000.0, json_encode($S[0]));
verifie('une semaine à venir n’a pas de réalisé', $S[1]['realise'] === null);
verifie('une semaine dans le mois budgété porte son budget', $S[1]['budget'] !== null && $S[1]['budgetSource'] === 'budget');
verifie('une semaine à cheval avec un seul jour budgété n’en porte pas', $S[2]['budget'] === null && $S[2]['budgetJours'] === 1, json_encode($S[2]));
$S3 = trSemaines(['2026-11-30'], $jours3, trBudgetJours(['2026-11' => ['ca' => 3000.0, 'source' => 'budget'], '2026-12' => ['ca' => 3100.0, 'source' => 'budget']], [], '2026-11-14'), '2026-11-14');
verifie('à cheval sur deux mois budgétés : les sept jours', $S3[0]['budget'] === 700.0 && $S3[0]['budgetJours'] === 7, json_encode($S3[0]));
$S4 = trSemaines(['2027-12-27'], [], trBudgetJours(['2027-12' => ['ca' => 3100.0, 'source' => 'budget']], [], '2026-11-14'), '2026-11-14');
verifie('cinq jours budgétés sur sept : la semaine extrapolée', $S4[0]['budget'] === 700.0 && $S4[0]['budgetJours'] === 5, json_encode($S4[0]));
$S2 = trSemaines(['2026-11-02'], $jours3, $bjs, '2026-11-14');
verifie('une semaine passée est close', $S2[0]['complet'] === true && $S2[0]['realise'] === 8400.0);

fwrite(STDOUT, "Les mois\n");
$M = trMois('2026-10', '2026-12', $jours3, $bm, '2026-11-14');
verifie('trois mois', count($M) === 3 && $M[0]['ym'] === '2026-10' && $M[2]['ym'] === '2026-12');
verifie('novembre : réalisé à ce jour, pas clos', $M[1]['realise'] === 13 * 1200.0 && $M[1]['complet'] === false, json_encode($M[1]));
verifie('novembre N-1 : le mois civil entier un an plus tôt', $M[1]['n1'] === 21000.0, json_encode($M[1]));
verifie('novembre : son budget', $M[1]['budget'] === 30000.0 && $M[1]['budgetSource'] === 'budget');
verifie('décembre : ni réalisé, ni budget, ni vente lue en décembre 2025', $M[2]['realise'] === null && $M[2]['budget'] === null && $M[2]['n1'] === null);
$M2 = trMois('2026-10', '2026-12', $jours3, $bm, '2026-11-14', '2026-11-09');
verifie('le réalisé avant le plan : du 1er au 8 novembre', $M2[1]['avantPlan'] === 7 * 1200.0, json_encode($M2[1]));
verifie('octobre, avant le plan mais sans vente lue : rien', $M2[0]['avantPlan'] === null, json_encode($M2[0]));
verifie('un mois après le début du plan : rien avant', $M2[2]['avantPlan'] === null);
$M3 = trMois('2026-11', '2026-11', $jours3, $bm, '2026-11-14', '2026-12-07');
verifie('un mois tout entier avant le plan : tout son réalisé à ce jour', $M3[0]['avantPlan'] === 13 * 1200.0, json_encode($M3[0]));
verifie('sans premier lundi : pas de réalisé avant le plan', $M[1]['avantPlan'] === null);

fwrite(STDOUT, "La tendance des 13 dernières semaines\n");
$jt = [];
for ($k = 1; $k <= 13; $k++) {
    $m = trJour('2026-11-09', -7 * $k);
    for ($i = 0; $i < 7; $i++) { $jt[trJour($m, $i)] = 110.0; $jt[trJour($m, $i - 364)] = 100.0; }
}
$t = trTendance($jt, '2026-11-14');
verifie('13 semaines face à leur N-1 : + 10 %', $t !== null && $t['semaines'] === 13 && $t['pct'] === 10.0, json_encode($t));
verifie('moins de 4 semaines comparables : pas de tendance', trTendance([], '2026-11-14') === null);

fwrite(STDOUT, "Le budget lu en base\n");
Db::$perf = [
    ['shop_id' => '4', 'year' => 2027, 'month' => 1, 'revenue_budget' => '52000.00', 'ca_theorique' => '50000.00'],
    ['shop_id' => '4', 'year' => 2027, 'month' => 2, 'revenue_budget' => null, 'ca_theorique' => '48000.00'],
    ['shop_id' => '4', 'year' => 2027, 'month' => 3, 'revenue_budget' => '0.00', 'ca_theorique' => null],
];
$B = trBudgetMois('4', [2027]);
verifie('le budget encodé d’abord', $B['2027-01'] === ['ca' => 52000.0, 'source' => 'budget']);
verifie('à défaut, le CA théorique de l’étude', $B['2027-02'] === ['ca' => 48000.0, 'source' => 'theorique']);
verifie('un mois à zéro n’est pas un budget', !isset($B['2027-03']));
$E = trBudgetsExercices('4', [2027], $B);
verifie('l’exercice compte ses mois budgétés et théoriques', $E[0]['moisBudget'] === 1 && $E[0]['moisTheorique'] === 1 && $E[0]['total'] === 100000.0, json_encode($E));

fwrite(STDOUT, "Les postes du budget\n");
$C = trChargesBudget();
verifie('quatre postes à taux (le poste sans taux est écarté)', count($C) === 4, json_encode($C));
verifie('le % validé d’abord', $C[0] === ['categorie' => 'Matière', 'poste' => 'Matières premières', 'pct' => 38.0, 'matiere' => true]);
verifie('à défaut, le % réseau', $C[1]['pct'] === 2.0 && $C[3]['pct'] === 5.0);
verifie('la matière est la catégorie que retranche la marge brute', $C[1]['matiere'] === true && $C[2]['matiere'] === false);

fwrite(STDOUT, "Le plan : validation\n");
$plan = ['v' => 2, 'debut' => '2026-11-16', 'ventes' => [], 'ponct' => [], 'reel' => ['2026-11-16' => 4200],
    'fournisseurs' => [['id' => 'a', 'nom' => 'Fournisseur A', 'pct' => 0.35, 'montants' => []]], 'paiements' => [], 'hors' => []];
verifie('un plan complet passe', trPlanInvalide($plan) === null);
verifie('une liste n’est pas un plan', trPlanInvalide([1, 2]) !== null);
verifie('un autre format est refusé', trPlanInvalide(['v' => 1] + $plan) !== null);
verifie('un premier jour qui n’est pas un lundi est refusé', trPlanInvalide(['debut' => '2026-11-17'] + $plan) !== null);
verifie('16 fournisseurs, c’est trop', trPlanInvalide(['fournisseurs' => array_fill(0, 16, $plan['fournisseurs'][0])] + $plan) !== null);
$j = json_encode(trPlanTables($plan));
verifie('les tables vides restent des objets JSON', str_contains($j, '"ventes":{}') && str_contains($j, '"montants":{}'), $j);

fwrite(STDOUT, "Le plan : écriture et version\n");
$_GET = ['shop' => '4'];
$CORPS = ['shop' => '4', 'version' => 0, 'donnees' => $plan];
$r = wr_tresorerie_plan();
verifie('premier enregistrement : version 1', ($r['ok'] ?? false) === true && $r['version'] === 1, json_encode($r));
$CORPS = ['shop' => '4', 'version' => 1, 'donnees' => ['coussin' => 5000] + $plan];
$r = wr_tresorerie_plan();
verifie('enregistré sur la version lue : version 2', ($r['ok'] ?? false) === true && $r['version'] === 2, json_encode($r));
http_response_code(200);
$CORPS = ['shop' => '4', 'version' => 1, 'donnees' => $plan];
$r = wr_tresorerie_plan();
verifie('sur une version dépassée : 409, rien d’écrit, le plan tel qu’il est', http_response_code() === 409 && $r['version'] === 2 && ($r['plan']['coussin'] ?? null) === 5000, json_encode($r));
http_response_code(200);
$CORPS = ['shop' => '4', 'version' => 0, 'donnees' => $plan];
$r = wr_tresorerie_plan();
verifie('un second « premier enregistrement » : 409', http_response_code() === 409, json_encode($r));
http_response_code(200);
$CORPS = ['shop' => '4', 'version' => 2, 'donnees' => ['debut' => '2026-11-18'] + $plan];
$r = wr_tresorerie_plan();
verifie('un plan invalide : 422', http_response_code() === 422, json_encode($r));
http_response_code(200);
$_GET = ['shop' => '4'];
$l = ep_tresorerie_plan();
verifie('la lecture rend le plan et sa version', $l['version'] === 2 && $l['plan']['coussin'] === 5000 && is_string($l['debutPropose']), json_encode($l));
$CORPS = ['shop' => '4', 'version' => 2, 'donnees' => null];
$r = wr_tresorerie_plan();
verifie('effacer sur la bonne version', ($r['ok'] ?? false) === true && !isset(Db::$plans['4']), json_encode($r));
$CORPS = ['shop' => 'x', 'version' => 0, 'donnees' => $plan];
$r = wr_tresorerie_plan();
verifie('un magasin qui n’est pas un identifiant : 400', http_response_code() === 400);

fwrite(STDOUT, "\n$ok ok, $ko KO\n");
exit($ko === 0 ? 0 : 1);
