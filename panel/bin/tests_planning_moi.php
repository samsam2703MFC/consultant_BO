#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du planning de l'app employé (31/10/2026) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_planning_moi.php
 *
 * Ce qui se vérifie : les services de la personne lus à la route du planning du panel (GET /shops/{id}/schedule),
 * magasin par magasin, renfort comprise, sans les autres ni leurs noms ; la fenêtre gardée cinq minutes ; un magasin
 * muet complété par la table locale, ou par sa dernière lecture ; un service effacé au panel qui ne revient pas de la
 * table ; les contrôles de la route ; côté app, ScheduleService qui lit le cockpit et dit « indisponible » sans lui.
 */

// La sortie est gardée jusqu'à la fin : sans cela, http_response_code() ne se pose plus après le premier echo.
ob_start();

// --- doublures ---------------------------------------------------------------------------
$GLOBALS['CACHE'] = [];
function motCache(string $cle, mixed $defaut = null): mixed { return $GLOBALS['CACHE'][$cle] ?? $defaut; }
function motCacheEcrire(string $cle, mixed $valeur): void { $GLOBALS['CACHE'][$cle] = $valeur; }
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : (is_array($r['data'] ?? null) ? $r['data'] : []); }
$GLOBALS['APPELANT'] = null;
function profilAppelant(): ?int { return $GLOBALS['APPELANT']; }
function profilDate(string $d): ?string { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : null; }

/** Le panel : sid → réponse de /shops/{sid}/schedule (null = muet). */
final class PanelApi
{
    public static array $planning = [];
    public static int $appels = 0;
    public static function configured(): bool { return true; }
    public static function getParallele(array $paths, int $front = 4, int $timeout = 25): array
    {
        self::$appels++;
        $out = [];
        foreach ($paths as $sid => $p) { $out[$sid] = self::$planning[$sid] ?? null; }
        return $out;
    }
}
/** La table locale (la synchro horaire) et les magasins actifs. */
$GLOBALS['TABLE'] = [];
final class Db
{
    public static function rows(string $sql, array $a = []): array
    {
        if (str_contains($sql, 'FROM shops WHERE active = 1')) { return [['id' => 4], ['id' => 5]]; }
        if (str_contains($sql, 'SELECT id, name FROM shops')) { return [['id' => 4, 'name' => 'Atelier by - Halle'], ['id' => 5, 'name' => 'Atelier by Max & Sandra - Namur']]; }
        // Les absences (27/11/2026) : la table de Mon équipe, en doublure.
        if (str_contains($sql, 'FROM panel_equipe_absence WHERE employe_id')) { return $GLOBALS['ABS'] ?? []; }
        if (str_contains($sql, 'remplacant_service IN')) { return $GLOBALS['RP'] ?? []; }
        if (str_contains($sql, 'FROM franchisee_employee_schedule')) {
            return array_values(array_filter($GLOBALS['TABLE'], static fn ($r) => (int) $r['id_employee'] === (int) $a[0] && $r['work_date'] >= $a[1] && $r['work_date'] <= $a[2]));
        }
        return [];
    }
}

require __DIR__ . '/../src/planning_moi.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
/** Une ligne telle que le panel la rend : avec le nom et le téléphone de la personne. */
function svc(int $id, int $emp, int $shop, string $date, string $de, string $a): array
{
    return ['id_schedule' => $id, 'id_employee' => $emp, 'id_shop' => $shop, 'work_date' => $date, 'start_hour' => $de, 'end_hour' => $a,
        'name' => 'Prénom' . $emp, 'surname' => 'Nom' . $emp, 'display_name' => 'P. ' . $emp, 'phone' => '+3247000000' . $emp, 'create_timestamp' => '2026-10-01 10:00:00'];
}

$T0 = strtotime('2026-10-28 12:00');          // un mercredi
// Halle (4) : Bram (12) lundi et mardi, une collègue (11) ; Namur (5) : Bram en renfort mercredi.
PanelApi::$planning = [
    4 => ['data' => [svc(1, 12, 4, '2026-10-26', '07:00:00', '15:00:00'), svc(2, 12, 4, '2026-10-27', '12:00:00', '20:00:00'),
        svc(3, 11, 4, '2026-10-26', '07:00:00', '15:00:00'), svc(4, 12, 4, '2025-01-06', '07:00:00', '15:00:00')]],
    5 => [svc(9, 12, 5, '2026-10-28', '09:00:00', '17:00:00')],
];
// La table locale : un service du 29/10 que le panel n'a plus (effacé), et un vieux service de l'an dernier.
$GLOBALS['TABLE'] = [
    ['id_schedule' => 5, 'id_employee' => 12, 'id_shop' => 4, 'work_date' => '2026-10-29', 'start_hour' => '07:00:00', 'end_hour' => '15:00:00'],
    ['id_schedule' => 4, 'id_employee' => 12, 'id_shop' => 4, 'work_date' => '2025-01-06', 'start_hour' => '07:00:00', 'end_hour' => '15:00:00'],
];

echo "La route du planning\n";
$r = planningPersonne(12, '2026-10-26', '2026-11-01', $T0);
$dates = array_column($r['services'], 'work_date');
verifie('ses services, renfort comprise, dans l’ordre', $dates === ['2026-10-26', '2026-10-27', '2026-10-28'], json_encode($dates));
verifie('pas ceux des autres, ni noms ni téléphones', !in_array(11, array_column($r['services'], 'id_employee'), true)
    && array_keys($r['services'][0]) === ['id_schedule', 'id_employee', 'work_date', 'start_hour', 'end_hour', 'id_shop', 'shop_name']);
verifie('le nom court du magasin sur chaque service (« Halle », « Namur »)', array_column($r['services'], 'shop_name') === ['Halle', 'Halle', 'Namur'], json_encode(array_column($r['services'], 'shop_name')));
verifie('un service effacé au panel ne revient pas de la table', !in_array('2026-10-29', $dates, true));
verifie('le magasin de la renfort est gardé', $r['services'][2]['id_shop'] === 5 && $r['source'] === 'panel /shops/{id}/schedule' && $r['muets'] === []);
verifie('seule la fenêtre est gardée (l’historique de 2025 non)', !in_array('2025-01-06', array_column($GLOBALS['CACHE']['planShop:4']['lignes'], 'work_date'), true)
    && $GLOBALS['CACHE']['planShop:4']['du'] === '2026-09-16' && $GLOBALS['CACHE']['planShop:4']['au'] === '2027-01-06');

echo "\nGardé cinq minutes\n";
$n = PanelApi::$appels;
planningPersonne(12, '2026-10-26', '2026-11-01', $T0 + 120);
verifie('relu dans les cinq minutes : pas d’appel au panel', PanelApi::$appels === $n);
PanelApi::$planning[4]['data'][] = svc(6, 12, 4, '2026-10-31', '08:00:00', '16:00:00');
$r = planningPersonne(12, '2026-10-26', '2026-11-01', $T0 + PLAN_TTL + 1);
verifie('après cinq minutes : relu, le nouveau service du samedi paraît', PanelApi::$appels === $n + 1 && in_array('2026-10-31', array_column($r['services'], 'work_date'), true));

echo "\nUn magasin muet, une date ancienne\n";
$r = planningPersonne(12, '2025-01-06', '2025-01-12', $T0 + PLAN_TTL + 2);
verifie('hors de la fenêtre : la table locale', array_column($r['services'], 'work_date') === ['2025-01-06'] && str_contains($r['source'], 'table locale'));
$GLOBALS['CACHE'] = [];
PanelApi::$planning[5] = null;
$GLOBALS['TABLE'][] = ['id_schedule' => 9, 'id_employee' => 12, 'id_shop' => 5, 'work_date' => '2026-10-28', 'start_hour' => '09:00:00', 'end_hour' => '17:00:00'];
$r = planningPersonne(12, '2026-10-26', '2026-11-01', $T0 + 1000);
verifie('Namur muet, sans lecture récente : sa ligne vient de la table', $r['muets'] === [5] && in_array('2026-10-28', array_column($r['services'], 'work_date'), true)
    && str_contains($r['source'], 'table locale'));
PanelApi::$planning[4] = null;
$r = planningPersonne(12, '2026-10-26', '2026-11-01', $T0 + 1000 + PLAN_TTL + 1);
verifie('Halle muet, lu il y a cinq minutes : sa dernière lecture sert (« garde »)', $r['garde'] === [4] && $r['muets'] === [5]
    && in_array('2026-10-27', array_column($r['services'], 'work_date'), true));

echo "\nLa route\n";
$GLOBALS['APPELANT'] = null;
$_GET = ['du' => '2026-10-26', 'au' => '2026-11-01'];
$r = ep_planning_moi();
verifie('sans identité : 401', http_response_code() === 401 && isset($r['error']));
$GLOBALS['APPELANT'] = 12;
foreach ([['du' => '2026-10-26'], ['du' => '2026-11-01', 'au' => '2026-10-26'], ['du' => '2026-02-30', 'au' => '2026-03-01'], ['du' => '2026-01-01', 'au' => '2026-06-01']] as $q) {
    http_response_code(200); $_GET = $q; ep_planning_moi();
    verifie('400 : ' . json_encode($q), http_response_code() === 400);
}
http_response_code(200);
$_GET = ['du' => '2026-10-26', 'au' => '2026-11-01'];
$r = ep_planning_moi();
verifie('identité signée : ses services', http_response_code() === 200 && $r['emp'] === 12 && $r['services'] !== []);
$index = (string) file_get_contents(__DIR__ . '/../../shared/public/api/index.php');
verifie('route branchée avant la session', str_contains($index, "require __DIR__ . '/../../../panel/src/planning_moi.php'")
    && strpos($index, "\$path === '/ventes/moi/planning' && primesVoieApp()") !== false
    && strpos($index, "\$path === '/ventes/moi/planning' && primesVoieApp()") < strpos($index, 'function routeGardee'));

echo "\nL'app : ScheduleService\n";
require_once __DIR__ . '/../apps/employee/vendor/autoload.php';
$GLOBALS['REP'] = null; $GLOBALS['DEMANDES'] = [];
$cockpit = new class extends \App\Employee\app\Services\Cockpit\CockpitClient {
    public function __construct() {}
    public function appel(string $methode, string $chemin, ?array $corps = null): array { $GLOBALS['DEMANDES'][] = $methode . ' ' . $chemin; return $GLOBALS['REP']; }
};
$svc = new \App\Employee\app\Services\Schedule\ScheduleService($cockpit);
$GLOBALS['REP'] = ['ok' => true, 'code' => 200, 'data' => ['services' => [
    ['id_schedule' => 1, 'id_employee' => 12, 'work_date' => '2026-10-26', 'start_hour' => '07:00:00', 'end_hour' => '15:00:00', 'id_shop' => 4]]]];
$l = $svc->getForMe('2026-10-26', '2026-11-02');
verifie('lit le cockpit (du, au) et rend des ScheduleModel', $GLOBALS['DEMANDES'] === ['GET /ventes/moi/planning?du=2026-10-26&au=2026-11-02']
    && count($l) === 1 && $l[0]->getStartHour() === '07:00:00' && !$svc->indisponible());
$s = $svc->semaine('2026-10-28', $T0);
verifie('la semaine : lundi 7 h – 15 h, pas « indisponible »', $s['jours'][0]['services'][0]['debut'] === '07:00' && $s['indisponible'] === false);
$GLOBALS['REP'] = ['ok' => true, 'code' => 200, 'data' => ['services' => [
    ['id_schedule' => 1, 'id_employee' => 12, 'work_date' => '2026-10-26', 'start_hour' => '07:00:00', 'end_hour' => '15:00:00', 'id_shop' => 4, 'shop_name' => 'Halle'],
    ['id_schedule' => 2, 'id_employee' => 12, 'work_date' => '2026-10-27', 'start_hour' => '07:00:00', 'end_hour' => '15:00:00', 'id_shop' => 4, 'shop_name' => 'Halle'],
    ['id_schedule' => 9, 'id_employee' => 12, 'work_date' => '2026-10-28', 'start_hour' => '09:00:00', 'end_hour' => '17:00:00', 'id_shop' => 5, 'shop_name' => 'Namur']]]];
$s = (new \App\Employee\app\Services\Schedule\ScheduleService($cockpit))->semaine('2026-10-28', $T0);
$mer = $s['jours'][2]['services'][0];
verifie('la renfort porte le nom de son magasin', $mer['autreShop'] === true && $mer['shopNom'] === 'Namur' && $s['jours'][0]['services'][0]['autreShop'] === false);
$GLOBALS['REP'] = ['ok' => false, 'code' => 502, 'erreur' => 'injoignable'];
$svc2 = new \App\Employee\app\Services\Schedule\ScheduleService($cockpit);
$s = $svc2->semaine('2026-10-28', $T0);
verifie('cockpit muet : « indisponible », aucune semaine inventée', $s['indisponible'] === true && $s['nbServices'] === 0);
verifie('l’app ne lit plus /employees/{id}/schedule', !is_file(__DIR__ . '/../apps/employee/src/app/Repositories/Schedule/ScheduleRepository.php')
    && !preg_match('#/employees/[^"\']*/schedule#', (string) shell_exec('grep -rh "apiClient->" ' . escapeshellarg(__DIR__ . '/../apps/employee/src/app') . ' 2>/dev/null')));

echo "\nLes absences dans l'app (27/11/2026)\n";
$SV = [['id_schedule' => 31, 'id_employee' => 12, 'work_date' => '2026-10-26', 'start_hour' => '07:00:00', 'end_hour' => '15:00:00', 'id_shop' => 4, 'shop_name' => 'Halle'],
    ['id_schedule' => 32, 'id_employee' => 12, 'work_date' => '2026-10-27', 'start_hour' => '07:00:00', 'end_hour' => '15:00:00', 'id_shop' => 4, 'shop_name' => 'Halle'],
    ['id_schedule' => 33, 'id_employee' => 12, 'work_date' => '2026-10-29', 'start_hour' => '12:00:00', 'end_hour' => '18:00:00', 'id_shop' => 4, 'shop_name' => 'Halle']];
$GLOBALS['ABS'] = [['service_id' => 31, 'shop_id' => 4, 'jour' => '2026-10-26', 'de' => '07:00', 'a' => '15:00', 'motif' => 'maladie', 'payee' => 1],
    ['service_id' => 40, 'shop_id' => 4, 'jour' => '2026-10-28', 'de' => '09:00', 'a' => '13:00', 'motif' => 'nonpaye', 'payee' => 0],
    ['service_id' => 41, 'shop_id' => 4, 'jour' => '2026-10-30', 'de' => '09:00', 'a' => '13:00', 'motif' => 'cp', 'payee' => 1]];
$GLOBALS['RP'] = [['remplacant_service' => 33]];
$m = planAbsences(12, '2026-10-26', '2026-11-01', $SV, [4 => 'Halle']);
verifie('un service d’absence payée porte son motif', $m[0]['absence'] === 'maladie' && !isset($m[1]['absence']), json_encode($m[0]));
verifie('une absence non payée (service sorti du panel) revient, marquée, à sa place', count($m) === 4 && $m[2]['work_date'] === '2026-10-28' && $m[2]['absence'] === 'nonpaye' && $m[2]['shop_name'] === 'Halle');
verifie('une absence payée dont le service a quitté le panel : oubliée', !array_filter($m, static fn ($x) => $x['work_date'] === '2026-10-30'));
verifie('le service d’un remplaçant : « remplacement », sans qui ni pourquoi', ($m[3]['remplacement'] ?? false) === true && !isset($m[3]['absence']) && array_keys($m[3]) === ['id_schedule', 'id_employee', 'work_date', 'start_hour', 'end_hour', 'id_shop', 'shop_name', 'remplacement'], json_encode($m[3]));
$GLOBALS['ABS'] = []; $GLOBALS['RP'] = [];
verifie('sans absence : les services tels quels', planAbsences(12, '2026-10-26', '2026-11-01', $SV) === $SV);
$GLOBALS['REP'] = ['ok' => true, 'code' => 200, 'data' => ['services' => [
    ['id_schedule' => 31, 'id_employee' => 12, 'work_date' => '2026-10-26', 'start_hour' => '07:00:00', 'end_hour' => '15:00:00', 'id_shop' => 4, 'absence' => 'maladie'],
    ['id_schedule' => 32, 'id_employee' => 12, 'work_date' => '2026-10-29', 'start_hour' => '07:00:00', 'end_hour' => '15:00:00', 'id_shop' => 4, 'absence' => 'cp'],
    ['id_schedule' => 33, 'id_employee' => 12, 'work_date' => '2026-10-30', 'start_hour' => '12:00:00', 'end_hour' => '18:00:00', 'id_shop' => 4, 'remplacement' => true]]]];
$s = (new \App\Employee\app\Services\Schedule\ScheduleService($cockpit))->semaine('2026-10-28', $T0);
verifie('l’app : une absence n’est pas une heure travaillée ni un service', $s['minutes'] === 360 && $s['nbServices'] === 1 && $s['absences'] === 2, json_encode([$s['minutes'], $s['nbServices']]));
verifie('l’app : le prochain service saute l’absence', $s['prochain'] !== null && $s['prochain']['date'] === '2026-10-30', json_encode($s['prochain']));
verifie('l’app : un jour d’absence n’est pas « repos »', $s['repos'] === 4 && $s['jours'][3]['services'][0]['absence'] === 'cp' && $s['jours'][4]['services'][0]['remplacement'] === true);
// Le gabarit du planning de l'app se compile (les fonctions de l'app en doublure).
$tw = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__ . '/../apps/employee/src/app/Views'));
foreach (['asset', 'jour', 'mois'] as $fn) { $tw->addFunction(new \Twig\TwigFunction($fn, static fn (...$a) => '')); }
$src = $tw->getLoader()->getSourceContext('schedule/schedule.twig');
$okTw = true; try { $tw->parse($tw->tokenize($src)); } catch (\Throwable $e) { $okTw = $e->getMessage(); }
verifie('le gabarit du planning de l’app se compile, et montre l’absence et le remplacement', $okTw === true && str_contains($src->getCode(), 'data-absence') && str_contains($src->getCode(), 'data-remplacement'), is_string($okTw) ? $okTw : '');
foreach (['fr', 'en', 'nl', 'pl', 'it'] as $lg) {
    $j = json_decode((string) file_get_contents(__DIR__ . '/../apps/employee/src/core/I18n/translations/page/' . $lg . '/schedule.json'), true);
    verifie('les mots des absences en ' . $lg, is_array($j) && !array_diff(['absence_maladie', 'absence_cp', 'absence_circ', 'absence_nonpaye', 'replacement'], array_keys($j)));
}

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
