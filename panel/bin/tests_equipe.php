#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de Mon équipe (panel/src/equipe.php) — sans base, sans réseau : le panel est une doublure.
 *
 *   php panel/bin/tests_equipe.php
 *
 * La carte des responsabilités reprend les cas des tests du module du panel (paquet panel-team-files,
 * tests/Team/TeamManagementTest.php) : produits uniques, seuls les actifs du magasin, jamais un autre magasin ni un
 * secret, poste général distinct d'un secteur vide ; les postes d'une personne (identifiants validés, verrou 409,
 * personne d'un autre magasin, désactivée) ; puis le planning : les services, les chevauchements, les paliers du budget
 * (17 / 20 / 22 %), l'objectif du jour, les creux habituels des temps morts.
 */

// Les codes HTTP des routes se posent après nos lignes : la sortie attend la fin.
ob_start();

// --- doublures -----------------------------------------------------------------------------------------------------
final class Db
{
    public static array $rows = [];
    public static array $roul = [];          // panel_equipe_roulement : [['employe_id' => …, 'lettre' => …]]
    public static ?string $matrice = null;   // panel_equipe_matrice : le JSON gardé
    public static array $exec = [];
    public static function rows(string $sql, array $a = []): array { return str_contains($sql, 'panel_equipe_roulement') ? self::$roul : []; }
    public static function row(string $sql, array $a = []): ?array
    {
        if (str_contains($sql, 'panel_equipe_matrice')) { return self::$matrice !== null ? ['matrice' => self::$matrice] : null; }
        return self::$rows[($a[0] ?? '') . ':' . ($a[1] ?? '') . '-' . ($a[2] ?? '')] ?? null;
    }
    public static function exec(string $sql, array $a = []): void { self::$exec[] = [$sql, $a]; }
}
$REGLAGES = [];
function setting(string $k, mixed $d = null): mixed { global $REGLAGES; return $REGLAGES[$k] ?? $d; }
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : (is_array($r['data'] ?? null) ? $r['data'] : []); }
$JOURNAL = [];
function journalAdd(string $a, string $k, ?string $p, string $m, ?string $ts = null): void { global $JOURNAL; $JOURNAL[] = $m; }
$CORPS = [];
function body(): array { global $CORPS; return $CORPS; }
function pjPoids(): ?array { return ['poids' => [1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 1.5, 6 => 2.0, 7 => 0.0], 'adopteLe' => '', 'base' => '']; }
final class PanelApi
{
    public static ?string $lastError = null;
    public static array $reponses = [];
    public static array $lus = [];
    public static array $ecrits = [];
    public static int $code = 204;
    public static function configured(): bool { return true; }
    public static function get(string $p): mixed { self::$lus[] = $p; return self::$reponses[$p] ?? null; }
    public static function getParallele(array $paths, int $front = 4, int $timeout = 25, bool $texte = false, bool $reessai = true): array
    {
        $o = [];
        foreach ($paths as $k => $p) { self::$lus[] = $p; $o[$k] = self::$reponses[$p] ?? null; }
        return $o;
    }
    public static function requete(string $m, string $p, ?array $b = null, int $t = 20): array
    {
        self::$ecrits[] = [$m, $p, $b];
        return ['code' => self::$code, 'data' => self::$code === 201 ? ['inserted_id' => 77] : null, 'erreur' => self::$code >= 300 ? 'HTTP ' . self::$code : null];
    }
}

/** Le compte admin de l'ERP : éteint par défaut, allumé pour les tests de la reprise. */
final class ErpApi
{
    public static ?string $lastError = null;
    public static bool $actif = false;
    public static array $reponses = [];
    public static array $lus = [];
    public static array $ecrits = [];
    public static int $code = 204;
    public static function disponible(): bool { return self::$actif; }
    public static function getParallele(array $paths, int $front = 4): array
    {
        $o = [];
        foreach ($paths as $k => $p) { self::$lus[] = $p; $o[$k] = self::$reponses[$p] ?? null; }
        return $o;
    }
    public static function ecrire(string $m, string $p, ?array $b = null): array
    {
        self::$ecrits[] = [$m, $p, $b];
        return ['code' => self::$code, 'corps' => self::$code === 201 ? ['inserted_id' => 88] : null, 'erreur' => self::$code >= 300 ? 'HTTP ' . self::$code : null];
    }
}

require __DIR__ . '/../../shared/src/cibles_reseau.php';
require __DIR__ . '/../../shared/src/resultat.php';
require __DIR__ . '/../src/temps_morts.php';
require __DIR__ . '/../src/equipe.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// Les données du test du panel, traduites.
$postes = [['id' => 1, 'name' => 'Four', 'sector_id' => 7], ['id' => 2, 'name' => 'Pâte', 'sector_id' => 7], ['id' => 3, 'name' => 'Caisse', 'sector_id' => null], ['id' => 4, 'name' => 'Décor', 'sector_id' => 8]];
$employes = [
    ['id' => 10, 'id_shop' => 1, 'name' => 'Anna', 'surname' => 'Lambert', 'workstations' => [['id' => 1], ['id' => 2], ['id' => 1]], 'password' => 'secret', 'refresh_token' => 'private', 'money_per_hour' => 18.5],
    ['id' => 11, 'id_shop' => 1, 'name' => 'Inactive', 'soft_delete' => 1, 'workstations' => [['id' => 4]]],
    ['id' => 12, 'id_shop' => 2, 'name' => 'Ailleurs', 'workstations' => [['id' => 4]]],
    ['id' => 13, 'id_shop' => 1, 'name' => 'Caissière', 'workstations' => [['id' => 3]], 'money_per_hour' => 15.0],
    ['id' => 14, 'id_shop' => 1, 'name' => 'Nouveau', 'workstations' => []],
];
$produits = [['id' => 1, 'name' => 'Pain', 'sector_id' => 7, 'sector_name' => 'Boulangerie'], ['id' => 1, 'name' => 'Doublon', 'sector_id' => 7, 'sector_name' => 'Boulangerie'],
    ['id' => 2, 'name' => 'Tarte', 'sector_id' => 8, 'sector_name' => 'Pâtisserie'], ['id' => 3, 'name' => 'Sans secteur', 'sector_id' => null],
    ['id' => 4, 'name' => 'Inactif', 'sector_id' => 7, 'is_active' => 0], ['id' => 5, 'name' => 'Pas vendu ici', 'sector_id' => 7, 'sale_enabled' => 0]];

echo "La carte des responsabilités\n";
$c = eqpCarte(1, $employes, $postes, $produits);
verifie('le résumé : 3 actifs, 3 postes occupés, 3 produits, 1 couvert, 2 non couverts, 1 secteur vide',
    $c['resume']['employes'] === 3 && $c['resume']['postesOccupes'] === 3 && $c['resume']['produits'] === 3 && $c['resume']['couverts'] === 1
    && $c['resume']['nonCouverts'] === 2 && $c['resume']['secteursVides'] === 1, json_encode($c['resume']));
verifie('un inactif ne ferme pas un secteur vide (Pâtisserie, 8)', $c['secteursVides'] === [8], json_encode($c['secteursVides']));
verifie('un produit sans secteur n’est pas un secteur vide', count($c['produitsSansSecteur']) === 1);
verifie('un poste général (Caisse) n’est pas rangé dans un secteur', count($c['postesSansSecteur']) === 1 && $c['postesSansSecteur'][0]['nom'] === 'Caisse');
$anna = array_values(array_filter($c['employes'], static fn ($e) => $e['id'] === 10))[0];
verifie('deux postes du même secteur : un secteur, un produit, deux postes (doublon écarté)', $anna['secteurs'] === [7] && $anna['produits'] === 1 && count($anna['postes']) === 2, json_encode($anna));
$caisse = array_values(array_filter($c['employes'], static fn ($e) => $e['id'] === 13))[0];
verifie('ses « autres postes » : Caisse', $caisse['autres'] === ['Caisse'] && $caisse['secteurs'] === []);
$j = json_encode($c);
verifie('ni secret, ni personne d’un autre magasin', !str_contains($j, 'secret') && !str_contains($j, 'private') && !str_contains($j, 'Ailleurs'));
verifie('une source vide reste vide, pas couverte', eqpCarte(1, [], [], [])['resume']['produits'] === 0);
verifie('un sans-poste compté', $c['resume']['sansPoste'] === 1);

echo "Les postes d'une personne\n";
PanelApi::$reponses = ['/shops/1/employees?include=positions' => $employes, '/operational-framework/workstations' => $postes, '/shops/1/products/available' => $produits];
$CORPS = ['shop' => 1, 'employe' => 10, 'postes' => [4, 4], 'attendus' => [2, 1]];
$r = wr_equipe_postes();
verifie('l’écriture ne porte que des identifiants validés, sans doublon', PanelApi::$ecrits === [['PUT', '/employees/10/workstations', ['workstation_ids' => [4]]]], json_encode(PanelApi::$ecrits));
verifie('la carte relue revient avec ok', !empty($r['ok']) && isset($r['resume']));
verifie('le journal dit les postes', ($JOURNAL[0] ?? '') === 'Postes de Anna Lambert : Décor', json_encode($JOURNAL));
$CORPS = ['shop' => 1, 'employe' => 10, 'postes' => [], 'attendus' => [1, 2]];
wr_equipe_postes();
verifie('une liste vide retire les postes', PanelApi::$ecrits[1][2]['workstation_ids'] === []);
$avant = count(PanelApi::$ecrits);
foreach ([true, '1', 0, -1, 1.5, [], null] as $x) {
    $CORPS = ['shop' => 1, 'employe' => 10, 'postes' => [$x], 'attendus' => [1, 2]];
    $r = wr_equipe_postes();
    verifie('refusé : ' . json_encode($x), ($r['motif'] ?? '') === 'postes_invalides', json_encode($r));
}
$cas = [
    'poste inconnu du panel' => [['shop' => 1, 'employe' => 10, 'postes' => [999], 'attendus' => [1, 2]], 'postes_invalides'],
    'personne d’un autre magasin' => [['shop' => 1, 'employe' => 12, 'postes' => [4], 'attendus' => [4]], 'employe_inconnu'],
    'personne désactivée' => [['shop' => 1, 'employe' => 11, 'postes' => [4], 'attendus' => [4]], 'employe_inactif'],
    'postes changés entre-temps (409)' => [['shop' => 1, 'employe' => 10, 'postes' => [4], 'attendus' => []], 'conflit'],
    'attendus manquants' => [['shop' => 1, 'employe' => 10, 'postes' => [4]], 'postes_invalides'],
];
foreach ($cas as $nom => [$b, $motif]) { $CORPS = $b; $r = wr_equipe_postes(); verifie($nom, ($r['motif'] ?? '') === $motif, json_encode($r)); }
verifie('rien n’est écrit quand c’est refusé', count(PanelApi::$ecrits) === $avant);
PanelApi::$reponses['/operational-framework/workstations'] = [['id' => 1, 'name' => 'Sans migration']];
$_GET = ['shop' => '1'];
$r = ep_equipe_carte();
verifie('des postes sans sector_id : la carte refuse plutôt que de mentir', isset($r['error']) && str_contains($r['error'], 'sector_id'), json_encode($r));
PanelApi::$reponses['/operational-framework/workstations'] = $postes;
unset(PanelApi::$reponses['/shops/1/products/available']);
$r = ep_equipe_carte();
verifie('l’assortiment muet : la carte est masquée', isset($r['error']) && str_contains($r['error'], 'assortiment'), json_encode($r));

echo "Les services du planning\n";
$sch = [['id_schedule' => 5, 'id_employee' => 10, 'work_date' => '2026-11-23', 'start_hour' => '04:00:00', 'end_hour' => '12:00:00', 'id_shop' => 1],
    ['id_schedule' => 6, 'id_employee' => 13, 'work_date' => '2026-11-23', 'start_hour' => '12:30', 'end_hour' => '18:30', 'id_shop' => 1],
    ['id_schedule' => 7, 'id_employee' => 12, 'work_date' => '2026-11-23', 'start_hour' => '08:00', 'end_hour' => '16:00', 'id_shop' => 2],
    ['id_schedule' => 8, 'id_employee' => 13, 'work_date' => '2026-11-24', 'start_hour' => '08:00', 'end_hour' => '16:00', 'id_shop' => 1],
    ['id_schedule' => 9, 'id_employee' => 14, 'work_date' => '2026-11-23', 'start_hour' => '22:00', 'end_hour' => '02:00', 'id_shop' => 1]];
$sv = eqpServices($sch, 1, '2026-11-23');
verifie('le jour, le magasin, sans le service qui passe minuit', array_column($sv, 'id') === [5, 6], json_encode($sv));
verifie('les heures en HH:MM', $sv[0]['de'] === '04:00' && $sv[0]['a'] === '12:00');
verifie('chevauchement repéré', eqpChevauche($sv, 10, 11 * 60, 13 * 60) !== null);
verifie('pas de chevauchement bord à bord', eqpChevauche($sv, 10, 12 * 60, 14 * 60) === null);
verifie('le service lui-même ne se chevauche pas', eqpChevauche($sv, 10, 5 * 60, 11 * 60, 5) === null);

echo "Modifier un service\n";
$d = date('Y-m-d');
PanelApi::$reponses['/shops/1/schedule?date=' . $d] = array_map(static fn ($x) => ['work_date' => $d] + $x, $sch);
PanelApi::$reponses['/shops/1/employees'] = $employes;
PanelApi::$ecrits = []; PanelApi::$code = 201;
$CORPS = ['shop' => 1, 'employe' => 14, 'date' => $d, 'de' => '07:00', 'a' => '11:00'];
$r = wr_equipe_service_creer();
verifie('ajout : POST /schedule avec les champs du panel', !empty($r['ok']) && (PanelApi::$ecrits[0] ?? null) === ['POST', '/schedule', ['id_employee' => 14, 'work_date' => $d, 'start_hour' => '07:00:00', 'end_hour' => '11:00:00']], json_encode([$r, PanelApi::$ecrits]));
verifie('ajout : le numéro rendu par le panel', ($r['id'] ?? null) === 77);
$CORPS = ['shop' => 1, 'employe' => 10, 'date' => $d, 'de' => '11:00', 'a' => '13:00'];
verifie('ajout refusé : chevauche', (wr_equipe_service_creer()['motif'] ?? '') === 'chevauchement');
$CORPS = ['shop' => 1, 'employe' => 12, 'date' => $d, 'de' => '07:00', 'a' => '11:00'];
verifie('ajout refusé : personne d’un autre magasin', isset(wr_equipe_service_creer()['error']));
$CORPS = ['shop' => 1, 'employe' => 14, 'date' => $d, 'de' => '11:00', 'a' => '07:00'];
verifie('ajout refusé : la fin avant le début', isset(wr_equipe_service_creer()['error']));
$CORPS = ['shop' => 1, 'employe' => 14, 'date' => $d, 'de' => '07:03', 'a' => '11:00'];
verifie('ajout refusé : hors du pas de 5 minutes', isset(wr_equipe_service_creer()['error']));
PanelApi::$code = 204; PanelApi::$ecrits = [];
$CORPS = ['shop' => 1, 'id' => 5, 'date' => $d, 'de' => '05:00', 'a' => '12:30'];
$r = wr_equipe_service_modifier();
verifie('modification : PATCH /schedule/5', !empty($r['ok']) && (PanelApi::$ecrits[0] ?? null) === ['PATCH', '/schedule/5', ['start_hour' => '05:00:00', 'end_hour' => '12:30:00']], json_encode([$r, PanelApi::$ecrits]));
$CORPS = ['shop' => 1, 'id' => 7, 'date' => $d, 'de' => '05:00', 'a' => '12:30'];
verifie('modification refusée : le service d’un autre magasin', (wr_equipe_service_modifier()['motif'] ?? '') === 'service_inconnu');
$CORPS = ['shop' => 1, 'id' => 6, 'date' => $d];
$r = wr_equipe_service_supprimer();
verifie('suppression : DELETE /schedule/6', !empty($r['ok']) && end(PanelApi::$ecrits) === ['DELETE', '/schedule/6', null]);
PanelApi::$code = 403;
$CORPS = ['shop' => 1, 'id' => 6, 'date' => $d];
$r = wr_equipe_service_supprimer();
verifie('un refus du panel est rendu tel quel', ($r['error'] ?? '') === 'le panel a refusé la suppression' && ($r['detail'] ?? '') === 'HTTP 403', json_encode($r));
$CORPS = ['shop' => 1, 'id' => 6, 'date' => date('Y-m-d', strtotime('+200 days'))];
verifie('date trop loin refusée', isset(wr_equipe_service_supprimer()['error']));

echo "Le budget d'heures : 17 / 20 / 22 %\n";
$p = personnelPaliers();
verifie('les paliers de départ', $p['cible'] === 17.0 && $p['rouge'] === 20.0 && $p['noir'] === 22.0, json_encode($p));
verifie('17 % : dans la cible', personnelNiveau(17.0, $p) === 'ok');
verifie('17,1 % : orange', personnelNiveau(17.1, $p) === 'orange');
verifie('20 % : orange', personnelNiveau(20.0, $p) === 'orange');
verifie('20,6 % : rouge', personnelNiveau(20.6, $p) === 'rouge');
verifie('22,1 % : noir', personnelNiveau(22.1, $p) === 'noir');
$REGLAGES['personnelPaliers'] = ['rouge' => 19, 'noir' => 24];
verifie('les paliers se règlent', personnelPaliers()['rouge'] === 19.0 && personnelPaliers()['noir'] === 24.0);
$REGLAGES['personnelPaliers'] = ['rouge' => 16, 'noir' => 24];
verifie('un réglage incohérent (rouge sous la cible) est ignoré', personnelPaliers()['rouge'] === 20.0 && personnelPaliers()['source'] === 'départ');
unset($REGLAGES['personnelPaliers']);

echo "Le CA prévu de chaque jour\n";
Db::$rows = ['9:2026-11' => ['revenue_budget' => 31000, 'ca_theorique' => null]];
$REGLAGES['resWdOuv:9'] = ['le' => date('Y-m-d'), 'wd' => [1, 2, 3, 4, 5, 6]];
$o = resObjectifsJours(9, ['2026-11-23', '2026-11-28', '2026-11-29']);
// Novembre 2026 : 5 lundis, 4 mardis… dimanches fermés ; poids 1 du lundi au jeudi, 1,5 le vendredi, 2 le samedi.
$den = 5 * 1 + 4 * 1 + 4 * 1 + 4 * 1 + 4 * 1.5 + 4 * 2;
verifie('lundi : budget × poids ÷ somme des poids du mois', abs(($o['jours']['2026-11-23'] ?? 0) - round(31000 / $den, 2)) < 0.01, json_encode($o));
verifie('samedi : deux fois le lundi', abs(($o['jours']['2026-11-28'] ?? 0) - round(31000 * 2 / $den, 2)) < 0.01);
verifie('dimanche fermé : pas d’objectif', $o['jours']['2026-11-29'] === null && $o['source'] === 'budget');
verifie('sans budget du mois : le motif', resObjectifsJours(9, ['2027-03-01'])['motif'] === 'aucun budget pour ces mois');

echo "Les temps morts du linéaire\n";
$s = static fn (string $hm) => 3600 * (int) substr($hm, 0, 2) + 60 * (int) substr($hm, 3, 2);
// Trois lundis avec un trou de 14:10 à 15:00, un quatrième sans ; des tickets toutes les 5 minutes sinon.
$jourType = static function (bool $trou) use ($s): array {
    $t = [];
    for ($x = $s('07:00'); $x <= $s('18:30'); $x += 300) { if (!$trou || $x <= $s('14:10') || $x >= $s('15:00')) { $t[] = $x; } }
    return $t;
};
$mes = [tmMesure($jourType(true), 10, true), tmMesure($jourType(true), 10, true), tmMesure($jourType(true), 10, true), tmMesure($jourType(false), 10, true)];
$tm = eqpTempsMortsCalcul(['2026-11-16', '2026-11-09', '2026-11-02', '2026-10-26'], $mes, tmMesure($jourType(true), 10, true), true);
verifie('le creux habituel : 14:15 à 15:00 (les quarts d’heure morts trois lundis sur quatre)', $tm['habitude']['creux'] === [[14 * 60 + 15, 15 * 60]], json_encode($tm['habitude']['creux']));
verifie('les 4 jours mesurés', $tm['habitude']['jours'] === 4);
verifie('les tickets moyens de 10 h : 12', ((array) $tm['habitude']['ticketsHeure'])['h10'] === 12.0, json_encode($tm['habitude']['ticketsHeure']));
verifie('le jour lui-même : son trou de 14:10 à 15:00', ($tm['jour']['trous'] ?? null) === [[850, 900]] && $tm['jour']['etat'] === 'encours', json_encode($tm['jour'] ?? null));
$un = eqpTempsMortsCalcul(['2026-11-16'], [tmMesure($jourType(true), 10, true)], null, false);
verifie('un seul jour mesuré : pas de creux habituel', $un['habitude']['creux'] === [] && !isset($un['jour']));

echo "Les heures d'ouverture\n";
$o = eqpOuverturesCalcul([1 => [[7 * 60 + 2, 18 * 60 + 25], [6 * 60 + 58, 18 * 60 + 31], [7 * 60 + 5, 18 * 60 + 20]], 7 => [[8 * 60, 12 * 60 + 10]]]);
verifie('lundi : 07:00 à 18:30 (médiane 07:02 et 18:25, au quart d’heure)', ($o['1'] ?? null) === [7 * 60, 18 * 60 + 30], json_encode($o));
verifie('dimanche : 08:00 à 12:15', ($o['7'] ?? null) === [8 * 60, 12 * 60 + 15], json_encode($o));
verifie('un jour sans ticket : pas d’ouverture', !isset($o['2']));

echo "Recopier la semaine précédente\n";
$eqR = [10 => ['id' => 10, 'nom' => 'Anna', 'actif' => true], 13 => ['id' => 13, 'nom' => 'Caissière', 'actif' => true], 11 => ['id' => 11, 'nom' => 'Inactive', 'actif' => false]];
$sv = static fn (int $id, int $e, string $de, string $a) => ['id' => $id, 'employe' => $e, 'de' => $de, 'a' => $a, 'nom' => ''];
$src = ['2026-11-16' => [$sv(1, 10, '04:00', '12:00'), $sv(2, 13, '12:30', '18:30'), $sv(3, 11, '08:00', '16:00'), $sv(4, 99, '08:00', '12:00')],
    '2026-11-17' => [$sv(5, 10, '04:00', '12:00'), $sv(6, 13, '07:00', '11:00'), $sv(7, 13, '10:00', '14:00')]];
$cib = ['2026-11-23' => [$sv(50, 13, '12:30', '18:30')], '2026-11-24' => [$sv(51, 10, '06:00', '14:00')]];
$rc = eqpRecopieCalcul($src, $cib, $eqR);
verifie('reporté de sept jours : le lundi 16 va au lundi 23', ($rc['propositions'][0] ?? null) === ['source' => 1, 'employe' => 10, 'date' => '2026-11-23', 'de' => '04:00', 'a' => '12:00'], json_encode($rc['propositions'][0] ?? null));
$motifs = array_column($rc['ecartes'], 'motif', 'source');
verifie('déjà au planning à la même heure : écarté', ($motifs[2] ?? '') === 'déjà au planning', json_encode($motifs));
verifie('une personne désactivée : écartée', ($motifs[3] ?? '') === 'désactivé');
verifie('une personne qui n’est plus dans l’équipe : écartée', ($motifs[4] ?? '') === 'plus dans l’équipe');
verifie('chevauche un service déjà prévu : écarté', ($motifs[5] ?? '') === 'chevauche 06:00–14:00');
verifie('chevauche un service recopié juste avant : écarté', ($motifs[7] ?? '') === 'chevauche 07:00–11:00');
verifie('les autres sont proposés : 1 et 6', array_column($rc['propositions'], 'source') === [1, 6], json_encode(array_column($rc['propositions'], 'source')));

$lun = date('Y-m-d', strtotime('monday next week'));
$lunAvant = date('Y-m-d', strtotime($lun . ' -7 days'));
PanelApi::$reponses = ['/shops/1/employees' => $employes];
for ($i = 0; $i < 7; $i++) {
    $d0 = date('Y-m-d', strtotime($lunAvant . ' +' . $i . ' days')); $d1 = date('Y-m-d', strtotime($lun . ' +' . $i . ' days'));
    PanelApi::$reponses['/shops/1/schedule?date=' . $d0] = $i === 0 ? [['id_schedule' => 701, 'id_employee' => 10, 'work_date' => $d0, 'start_hour' => '04:00:00', 'end_hour' => '12:00:00', 'id_shop' => 1],
        ['id_schedule' => 702, 'id_employee' => 13, 'work_date' => $d0, 'start_hour' => '12:30:00', 'end_hour' => '18:30:00', 'id_shop' => 1],
        ['id_schedule' => 703, 'id_employee' => 12, 'work_date' => $d0, 'start_hour' => '08:00:00', 'end_hour' => '16:00:00', 'id_shop' => 2]] : [];
    PanelApi::$reponses['/shops/1/schedule?date=' . $d1] = [];
}
$_GET = ['shop' => '1', 'du' => $lun];
$ap = ep_equipe_recopie();
verifie('l’aperçu : deux services à recopier, celui d’un autre magasin ignoré', count($ap['propositions'] ?? []) === 2 && ($ap['source']['services'] ?? 0) === 2, json_encode($ap));
verifie('l’aperçu porte le nom', ($ap['propositions'][0]['nom'] ?? '') === 'Anna Lambert');
PanelApi::$ecrits = []; PanelApi::$code = 201;
$CORPS = ['shop' => 1, 'du' => $lun, 'sources' => [702]];
$r = wr_equipe_recopie();
verifie('seul le service coché est créé', ($r['crees'] ?? 0) === 1 && PanelApi::$ecrits === [['POST', '/schedule', ['id_employee' => 13, 'work_date' => $lun, 'start_hour' => '12:30:00', 'end_hour' => '18:30:00']]], json_encode([$r, PanelApi::$ecrits]));
PanelApi::$ecrits = []; PanelApi::$code = 403;
$CORPS = ['shop' => 1, 'du' => $lun, 'sources' => [701, 702]];
$r = wr_equipe_recopie();
verifie('un refus de droits arrête tout au premier service', count(PanelApi::$ecrits) === 1 && isset($r['error']) && str_contains($r['error'], '403'), json_encode($r));
$CORPS = ['shop' => 1, 'du' => $lun, 'sources' => [703]];
verifie('un service hors de l’aperçu (autre magasin) n’est jamais créé', isset(wr_equipe_recopie()['error']));
$CORPS = ['shop' => 1, 'du' => $lun, 'sources' => ['701']];
verifie('des numéros en texte : refusés', isset(wr_equipe_recopie()['error']));
PanelApi::$reponses['/shops/1/schedule?date=' . $lun] = null;
$_GET = ['shop' => '1', 'du' => $lun];
verifie('le planning cible muet : rien n’est recopié à l’aveugle', isset(ep_equipe_recopie()['error']));
PanelApi::$code = 204;


echo "La matrice A B C D\n";
$md = eqpMatriceDepart('2026-11-23');
verifie('la matrice de départ est valide', eqpMatriceValide($md) === $md, json_encode(eqpMatriceValide($md)));
// Chaque jour des deux semaines : au moins un matin et un après-midi, avec une personne par lettre.
$couvert = true;
for ($w = 0; $w < 2; $w++) { for ($d = 0; $d < 7; $d++) { $col = array_map(static fn ($l) => $md['lettres'][$l][$w][$d], EQP_LETTRES); if (!in_array('M', $col, true) || !in_array('P', $col, true)) { $couvert = false; } } }
verifie('de départ : chaque jour a un matin et un après-midi', $couvert);
$we = static fn ($l, $w) => $md['lettres'][$l][$w][5] !== 'R';
verifie('de départ : un week-end sur deux (A et C la semaine 1, B et D la semaine 2)', $we('A', 0) && $we('C', 0) && !$we('B', 0) && !$we('D', 0) && $we('B', 1) && $we('D', 1) && !$we('A', 1) && !$we('C', 1));
$jt = static fn ($l, $w) => count(array_filter($md['lettres'][$l][$w], static fn ($c) => $c !== 'R'));
verifie('de départ : cinq jours par semaine pour chacun', array_sum(array_map(static fn ($l) => $jt($l, 0) + $jt($l, 1), EQP_LETTRES)) === 40);
$mv = $md; $mv['reference'] = '2026-11-26';
verifie('la référence est ramenée à son lundi', eqpMatriceValide($mv)['reference'] === '2026-11-23');
$mk = $md; $mk['lettres']['B'][1][3] = 'X';
verifie('un code inconnu : refusée', eqpMatriceValide($mk) === null);
$mk = $md; $mk['creneaux']['M'] = ['de' => '14:00', 'a' => '07:00'];
verifie('un créneau à l’envers : refusée', eqpMatriceValide($mk) === null);
$mk = $md; unset($mk['lettres']['D']);
verifie('une lettre manquante : refusée', eqpMatriceValide($mk) === null);
verifie('la semaine de référence est la semaine 1', eqpCycle('2026-11-23', '2026-11-23') === 0);
verifie('la suivante, la semaine 2', eqpCycle('2026-11-23', '2026-11-30') === 1);
verifie('dans quinze jours, la semaine 1', eqpCycle('2026-11-23', '2026-12-07') === 0);
verifie('la semaine d’avant, la semaine 2', eqpCycle('2026-11-23', '2026-11-16') === 1);

echo "Le shift maker\n";
$eqS = [10 => ['id' => 10, 'nom' => 'Anna', 'actif' => true], 13 => ['id' => 13, 'nom' => 'Caissière', 'actif' => true], 14 => ['id' => 14, 'nom' => 'Nouveau', 'actif' => true], 11 => ['id' => 11, 'nom' => 'Inactive', 'actif' => false]];
$cibS = ['2026-11-25' => [['id' => 9, 'employe' => 10, 'de' => '10:00', 'a' => '12:00', 'nom' => '']], '2026-11-26' => [['id' => 8, 'employe' => 10, 'de' => '07:00', 'a' => '14:00', 'nom' => '']]];
$g = eqpShiftMaker($md, [10 => 'A', 13 => 'B', 11 => 'C'], $eqS, $cibS, '2026-11-23');
verifie('semaine 1 du roulement', $g['cycle'] === 1);
$deA = array_values(array_filter($g['propositions'], static fn ($p) => $p['employe'] === 10));
verifie('A en semaine 1 : matin du mercredi au dimanche, sauf ce qui est déjà là', array_column($deA, 'date') === ['2026-11-27', '2026-11-28', '2026-11-29'] && $deA[0]['de'] === '07:00' && $deA[0]['a'] === '14:00', json_encode($deA));
$motS = []; foreach ($g['ecartes'] as $x) { $motS[$x['employe'] . $x['date']] = $x['motif']; }
verifie('mercredi : chevauche son service de 10:00', ($motS['102026-11-25'] ?? '') === 'chevauche 10:00–12:00', json_encode($motS));
verifie('jeudi : déjà au planning', ($motS['102026-11-26'] ?? '') === 'déjà au planning');
verifie('une personne désactivée : écartée', ($motS['112026-11-25'] ?? '') === 'désactivé');
$deB = array_values(array_filter($g['propositions'], static fn ($p) => $p['employe'] === 13));
verifie('B en semaine 1 : après-midi du lundi au vendredi', array_column($deB, 'date') === ['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27'] && $deB[0]['de'] === '12:30', json_encode(array_column($deB, 'date')));
verifie('sans lettre : Nouveau', $g['sansLettre'] === [14], json_encode($g['sansLettre']));
verifie('la clé d’une proposition', $deB[0]['cle'] === 'e13-2026-11-23-12:30');
$g2 = eqpShiftMaker($md, [10 => 'A'], $eqS, [], '2026-11-30');
verifie('A en semaine 2 : après-midi du lundi au vendredi', $g2['cycle'] === 2 && count($g2['propositions']) === 5 && $g2['propositions'][0]['de'] === '12:30');

echo "Le shift maker, de bout en bout\n";
$lunG = date('Y-m-d', strtotime('monday next week'));
PanelApi::$reponses = ['/shops/1/employees' => $employes];
for ($i = 0; $i < 7; $i++) { PanelApi::$reponses['/shops/1/schedule?date=' . date('Y-m-d', strtotime($lunG . ' +' . $i . ' days'))] = []; }
Db::$roul = [['employe_id' => 10, 'lettre' => 'D'], ['employe_id' => 12, 'lettre' => 'A']];
Db::$matrice = json_encode(eqpMatriceDepart($lunG));
$_GET = ['shop' => '1', 'du' => $lunG];
$ap = ep_equipe_generer();
verifie('l’aperçu : D en semaine 1, matin du lundi au vendredi ; la personne d’un autre magasin est écartée', ($ap['cycle'] ?? 0) === 1 && count($ap['propositions'] ?? []) === 5 && count(array_filter($ap['ecartes'], static fn ($x) => $x['motif'] === 'plus dans l’équipe')) === 5, json_encode($ap));
verifie('les actifs sans lettre sont nommés', array_column($ap['sansLettre'], 'nom') === ['Caissière', 'Nouveau'], json_encode($ap['sansLettre']));
PanelApi::$ecrits = []; PanelApi::$code = 201;
$CORPS = ['shop' => 1, 'du' => $lunG, 'cles' => [$ap['propositions'][0]['cle'], $ap['propositions'][1]['cle']]];
$r = wr_equipe_generer();
verifie('seuls les services cochés sont créés', ($r['crees'] ?? 0) === 2 && count(PanelApi::$ecrits) === 2 && PanelApi::$ecrits[0][2] === ['id_employee' => 10, 'work_date' => $lunG, 'start_hour' => '07:00:00', 'end_hour' => '14:00:00'], json_encode([$r, PanelApi::$ecrits]));
$CORPS = ['shop' => 1, 'du' => $lunG, 'cles' => ['e12-' . $lunG . '-07:00']];
verifie('une clé hors de l’aperçu n’est jamais créée', isset(wr_equipe_generer()['error']));
$CORPS = ['shop' => 1, 'du' => $lunG, 'cles' => ['n’importe quoi']];
verifie('une clé mal formée : refusée', isset(wr_equipe_generer()['error']));
PanelApi::$code = 204;

echo "La lettre d'un employé\n";
Db::$exec = [];
$CORPS = ['shop' => 1, 'employe' => 13, 'lettre' => 'B'];
$r = wr_equipe_roulement();
verifie('enregistrée', !empty($r['ok']) && str_contains(Db::$exec[count(Db::$exec) - 1][0], 'INSERT INTO panel_equipe_roulement') && Db::$exec[count(Db::$exec) - 1][1][2] === 'B', json_encode([$r, Db::$exec]));
$CORPS = ['shop' => 1, 'employe' => 13, 'lettre' => null];
wr_equipe_roulement();
verifie('retirée', str_contains(Db::$exec[count(Db::$exec) - 1][0], 'DELETE FROM panel_equipe_roulement'));
$CORPS = ['shop' => 1, 'employe' => 12, 'lettre' => 'A'];
verifie('refusée pour la personne d’un autre magasin', isset(wr_equipe_roulement()['error']));
$CORPS = ['shop' => 1, 'employe' => 13, 'lettre' => 'E'];
verifie('refusée : une lettre hors de A B C D', isset(wr_equipe_roulement()['error']));
$CORPS = ['shop' => 1, 'matrice' => $mk];
verifie('une matrice invalide n’est pas enregistrée', isset(wr_equipe_matrice()['error']));
$CORPS = ['shop' => 1, 'matrice' => $md];
$r = wr_equipe_matrice();
verifie('une matrice valide est enregistrée', !empty($r['ok']) && str_contains(Db::$exec[count(Db::$exec) - 1][0], 'INSERT INTO panel_equipe_matrice'), json_encode($r));

echo "Le compte admin quand le compte du cockpit est refusé\n";
PanelApi::$reponses = ['/a' => [['id' => 1]]]; PanelApi::$lus = [];
ErpApi::$actif = true; ErpApi::$reponses = ['/b' => [['id' => 2]]]; ErpApi::$lus = [];
$r = eqpGetParallele(['a' => '/a', 'b' => '/b', 'c' => '/c']);
verifie('ce que le cockpit obtient vient du cockpit, le reste de l’admin', $r['a'] === [['id' => 1]] && $r['b'] === [['id' => 2]] && $r['c'] === null, json_encode($r));
verifie('l’admin ne relit que ce qui manque', ErpApi::$lus === ['/b', '/c'], json_encode(ErpApi::$lus));
PanelApi::$ecrits = []; ErpApi::$ecrits = []; PanelApi::$code = 403; ErpApi::$code = 201;
$w = eqpEcrire('POST', '/schedule', ['id_employee' => 10]);
verifie('une écriture refusée au cockpit (403) repart avec l’admin', count(PanelApi::$ecrits) === 1 && ErpApi::$ecrits === [['POST', '/schedule', ['id_employee' => 10]]] && $w['code'] === 201 && ($w['data']['inserted_id'] ?? 0) === 88, json_encode([$w, ErpApi::$ecrits]));
PanelApi::$code = 422; ErpApi::$ecrits = [];
$w = eqpEcrire('POST', '/schedule', ['id_employee' => 10]);
verifie('une écriture refusée pour une autre raison (422) ne repart pas', ErpApi::$ecrits === [] && $w['code'] === 422);
ErpApi::$actif = false; ErpApi::$lus = [];
$r = eqpGetParallele(['b' => '/b']);
verifie('sans compte admin : pas de reprise', $r['b'] === null && ErpApi::$lus === []);
PanelApi::$code = 204;

// La carte de bout en bout : le compte du cockpit refusé sur les employés, l'admin les rend.
PanelApi::$reponses = ['/operational-framework/workstations' => $postes, '/shops/1/products/available' => $produits];
ErpApi::$actif = true; ErpApi::$reponses = ['/shops/1/employees?include=positions' => $employes];
$_GET = ['shop' => '1'];
$c = ep_equipe_carte();
verifie('la carte se lit quand seul l’admin rend les employés', ($c['resume']['employes'] ?? 0) === 3, json_encode($c['resume'] ?? $c));
ErpApi::$actif = false;

echo "Le planning imprimé\n";
$Ppdf = ['secteurs' => [['id' => 7, 'nom' => 'Boulangerie']], 'employes' => [
        ['id' => 10, 'nom' => 'Anna Lambert', 'actif' => true, 'postes' => [['id' => 1, 'nom' => 'Four', 'secteur' => 7]], 'secteurs' => [7]],
        ['id' => 13, 'nom' => 'Caissière <b>', 'actif' => true, 'postes' => [['id' => 3, 'nom' => 'Vente', 'secteur' => null]], 'secteurs' => []],
        ['id' => 14, 'nom' => 'Ancien', 'actif' => false, 'postes' => [], 'secteurs' => []]],
    'jours' => array_map(static fn ($i) => ['date' => date('Y-m-d', strtotime('2026-11-23 +' . $i . ' days')), 'services' => $i === 0 ? [['id' => 1, 'employe' => 10, 'de' => '04:00', 'a' => '12:00'], ['id' => 2, 'employe' => 13, 'de' => '07:00', 'a' => '11:00'], ['id' => 3, 'employe' => 13, 'de' => '14:30', 'a' => '18:30']] : []], range(0, 13))];
$h = eqpPlanningPdfHtml($Ppdf, 'magasin', 'Sombreffe');
verifie('deux semaines : deux pages', substr_count($h, 'class="pl-page"') === 2, (string) substr_count($h, 'class="pl-page"'));
verifie('les heures de chacun', str_contains($h, '04:00–12:00') && str_contains($h, '14:30–18:30') && str_contains($h, '8 h'));
verifie('la ligne par jour : 3 services, 16 h le lundi', str_contains($h, '3 serv. · 16 h'), $h);
verifie('un inactif sans service n’est pas imprimé', !str_contains($h, 'Ancien'));
verifie('les noms sont échappés', str_contains($h, 'Caissière &lt;b&gt;') && !str_contains($h, 'Caissière <b>'));
verifie('le titre de la semaine', str_contains($h, 'Semaine du 23 au 29 novembre 2026') && str_contains($h, 'Semaine du 30 novembre au 6 décembre 2026'), $h);
$hd = eqpPlanningPdfHtml($Ppdf, 'departements', 'Sombreffe');
verifie('par département : une page par secteur ou poste et par semaine (Boulangerie, Comptoir)', substr_count($hd, 'class="pl-page"') === 4 && str_contains($hd, 'Boulangerie') && str_contains($hd, 'Comptoir'), (string) substr_count($hd, 'class="pl-page"'));

echo "La durée d'un service\n";
verifie('4 h et 8 h par défaut', eqpDurees() === ['min' => 4.0, 'max' => 8.0, 'source' => 'départ'], json_encode(eqpDurees()));
$REGLAGES['equipeDureesService'] = ['min' => 3, 'max' => 9];
verifie('le réglage', eqpDurees()['min'] === 3.0 && eqpDurees()['max'] === 9.0);
$REGLAGES['equipeDureesService'] = ['min' => 8, 'max' => 4];
verifie('un réglage incohérent est ignoré', eqpDurees()['source'] === 'départ');
unset($REGLAGES['equipeDureesService']);

echo "Le CA du jour pour les % (comptoir + B2B)\n";
$auj = date('Y-m-d'); $hier = date('Y-m-d', strtotime('-1 day')); $demain = date('Y-m-d', strtotime('+1 day'));
verifie('un jour passé : la caisse', eqpCaDuJour(1, $hier, $auj, 2247.5, 1510.68) === [2247.5, 'reel']);
verifie('un jour passé sans caisse : rien (l’objectif)', eqpCaDuJour(1, $hier, $auj, null, 1510.68) === [null, null]);
verifie('un jour à venir : rien (l’objectif)', eqpCaDuJour(1, $demain, $auj, null, 1663.38) === [null, null]);
verifie('aujourd’hui sans projection, sous l’objectif : rien', eqpCaDuJour(1, $auj, $auj, 900.0, 1510.68) === [null, null]);
verifie('aujourd’hui sans projection, au-delà de l’objectif : le fait', eqpCaDuJour(1, $auj, $auj, 2247.0, 1510.68) === [2247.0, 'encours']);
$REGLAGES['exJour:' . $auj] = ['le' => time() - 60, 'r' => ['magasins' => [['shopId' => 2, 'projection' => 4002.77], ['shopId' => 1, 'projection' => 2274.4]]]];
verifie('aujourd’hui : la projection de fin de journée du Résultat du jour', eqpCaDuJour(1, $auj, $auj, 2247.0, 1510.68) === [2274.4, 'projection'], json_encode(eqpCaDuJour(1, $auj, $auj, 2247.0, 1510.68)));
$REGLAGES['exJour:' . $auj]['le'] = time() - 7200;
verifie('une projection de plus d’une heure ne sert plus', eqpCaDuJour(1, $auj, $auj, 2247.0, 1510.68) === [2247.0, 'encours']);
unset($REGLAGES['exJour:' . $auj]);

echo "\n" . $ok . ' ok, ' . $ko . " KO\n";
exit($ko > 0 ? 1 : 0);
