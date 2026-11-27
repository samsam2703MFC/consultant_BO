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
    public static ?string $options = '{"assistant":true,"taches":true,"matrice":true,"recopie":true}';   // panel_equipe_options
    public static array $exec = [];
    public static function rows(string $sql, array $a = []): array { return str_contains($sql, 'panel_equipe_roulement') ? self::$roul : []; }
    public static function row(string $sql, array $a = []): ?array
    {
        if (str_contains($sql, 'panel_equipe_matrice')) { return self::$matrice !== null ? ['matrice' => self::$matrice] : null; }
        if (str_contains($sql, 'panel_equipe_options')) { return self::$options !== null ? ['options' => self::$options] : null; }
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
require __DIR__ . '/../../shared/src/checklists_codes.php';
require __DIR__ . '/../src/equipe.php';
require __DIR__ . '/../src/equipe_fiche.php';
require __DIR__ . '/../src/equipe_absences.php';

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
verifie('tout le magasin, groupé par section : Boulangerie puis Comptoir, un titre par section', substr_count($h, 'class="sec"') === 4 && strpos($h, 'Boulangerie') < strpos($h, 'Anna Lambert') && strpos($h, 'Anna Lambert') < strpos($h, '>Comptoir') && strpos($h, '>Comptoir') < strpos($h, 'Caissière'), $h);
verifie('le titre d’une section dit ses personnes et ses heures', str_contains($h, 'Boulangerie <span>· 1 personne · 8 h</span>') && str_contains($h, 'Comptoir <span>· 1 personne · 8 h</span>'));
$hd = eqpPlanningPdfHtml($Ppdf, 'departements', 'Sombreffe');
verifie('par département : une page par secteur ou poste et par semaine (Boulangerie, Comptoir)', substr_count($hd, 'class="pl-page"') === 4 && str_contains($hd, 'Boulangerie') && str_contains($hd, 'Comptoir'), (string) substr_count($hd, 'class="pl-page"'));

echo "La durée d'un service\n";
verifie('4 h et 8 h par défaut', eqpDurees() === ['min' => 4.0, 'max' => 8.0, 'source' => 'départ'], json_encode(eqpDurees()));
$REGLAGES['equipeDureesService'] = ['min' => 3, 'max' => 9];
verifie('le réglage', eqpDurees()['min'] === 3.0 && eqpDurees()['max'] === 9.0);
$REGLAGES['equipeDureesService'] = ['min' => 8, 'max' => 4];
verifie('un réglage incohérent est ignoré', eqpDurees()['source'] === 'départ');
unset($REGLAGES['equipeDureesService']);

echo "Les ETP et le CA par ETP\n";
verifie('168 h par mois, cible 13 000 €, critique 10 000 € par défaut', eqpEtp() === ['heuresMois' => 168.0, 'caCible' => 13000.0, 'caCritique' => 10000.0], json_encode(eqpEtp()));
$REGLAGES['heuresEtpMois'] = 164;
verifie('le réglage heuresEtpMois', eqpEtp()['heuresMois'] === 164.0);
$REGLAGES['heuresEtpMois'] = 0;
verifie('un réglage nul est ignoré', eqpEtp()['heuresMois'] === 168.0);
unset($REGLAGES['heuresEtpMois']);

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

echo "L'assistant de la semaine : créer ce qu'il a préparé\n";
$lunA = date('Y-m-d', strtotime('monday next week')); $marA = date('Y-m-d', strtotime($lunA . ' +1 day'));
PanelApi::$reponses = ['/shops/1/employees' => $employes];
for ($i = 0; $i < 7; $i++) { PanelApi::$reponses['/shops/1/schedule?date=' . date('Y-m-d', strtotime($lunA . ' +' . $i . ' days'))] = []; }
PanelApi::$reponses['/shops/1/schedule?date=' . $marA] = [['id_schedule' => 801, 'id_employee' => 13, 'work_date' => $marA, 'start_hour' => '07:00:00', 'end_hour' => '11:00:00', 'id_shop' => 1]];
PanelApi::$ecrits = []; PanelApi::$code = 201;
$CORPS = ['shop' => 1, 'du' => $lunA, 'services' => [['employe' => 10, 'date' => $lunA, 'de' => '04:00', 'a' => '12:00'], ['employe' => 13, 'date' => $marA, 'de' => '12:30', 'a' => '18:30']]];
$r = wr_equipe_semaine_creer();
verifie('les services préparés sont créés tels quels', ($r['crees'] ?? 0) === 2 && PanelApi::$ecrits === [['POST', '/schedule', ['id_employee' => 10, 'work_date' => $lunA, 'start_hour' => '04:00:00', 'end_hour' => '12:00:00']], ['POST', '/schedule', ['id_employee' => 13, 'work_date' => $marA, 'start_hour' => '12:30:00', 'end_hour' => '18:30:00']]], json_encode([$r, PanelApi::$ecrits]));
$refus = static function (array $services) use ($lunA): array { global $CORPS; PanelApi::$ecrits = []; $CORPS = ['shop' => 1, 'du' => $lunA, 'services' => $services]; $r = wr_equipe_semaine_creer(); return [isset($r['error']) && PanelApi::$ecrits === [], $r]; };
[$x, $r] = $refus([['employe' => 13, 'date' => $marA, 'de' => '10:00', 'a' => '14:00']]);
verifie('chevauche ce qui est déjà au planning : rien n’est créé', $x && str_contains($r['error'], 'déjà au planning'), json_encode($r));
[$x] = $refus([['employe' => 10, 'date' => $lunA, 'de' => '04:00', 'a' => '12:00'], ['employe' => 10, 'date' => $lunA, 'de' => '11:00', 'a' => '15:00']]);
verifie('deux services de la liste qui se chevauchent : rien n’est créé', $x);
[$x] = $refus([['employe' => 12, 'date' => $lunA, 'de' => '08:00', 'a' => '12:00']]);
verifie('une personne d’un autre magasin : refusée', $x);
[$x] = $refus([['employe' => 11, 'date' => $lunA, 'de' => '08:00', 'a' => '12:00']]);
verifie('une personne désactivée : refusée', $x);
[$x] = $refus([['employe' => 10, 'date' => date('Y-m-d', strtotime($lunA . ' +7 days')), 'de' => '08:00', 'a' => '12:00']]);
verifie('un jour hors de la semaine : refusé', $x);
[$x] = $refus([['employe' => 10, 'date' => $lunA, 'de' => '12:00', 'a' => '08:00']]);
verifie('la fin avant le début : refusée', $x);
[$x] = $refus([['employe' => 10, 'date' => $lunA, 'de' => '8:00', 'a' => '12:00']]);
verifie('une heure mal écrite : refusée', $x);
[$x] = $refus([['employe' => '10', 'date' => $lunA, 'de' => '08:00', 'a' => '12:00']]);
verifie('un numéro en texte : refusé', $x);
PanelApi::$reponses['/shops/1/schedule?date=' . $lunA] = null;
[$x, $r] = $refus([['employe' => 10, 'date' => $marA, 'de' => '13:00', 'a' => '17:00']]);
verifie('un jour de la semaine muet : rien n’est créé à l’aveugle', $x && str_contains($r['error'], 'rien n’est créé'), json_encode($r));
PanelApi::$code = 204;

echo "Les tâches dans les temps morts\n";
$cfgT = array_column(eqpTachesConfig(), null, 'code');
verifie('les tâches qui peuvent bouger : le facing chaque jour, 15 min', ($cfgT['1.2.01']['duree'] ?? 0) === 15 && ($cfgT['1.2.01']['jours'] ?? []) === [1, 2, 3, 4, 5, 6, 7] && ($cfgT['1.2.01']['zone'] ?? 0) === 1, json_encode($cfgT['1.2.01'] ?? null));
verifie('le planogramme le lundi, le nettoyage approfondi le mercredi', ($cfgT['1.5.01']['jours'] ?? []) === [1] && ($cfgT['4.5.01']['jours'] ?? []) === [3] && ($cfgT['4.5.01']['zone'] ?? 0) === 4);
$REGLAGES['equipeTachesMobiles'] = ['1.2.01' => ['duree' => 25, 'jours' => [5, 1, 1]], '1.2.02' => null, '9.9' => ['duree' => 5, 'jours' => [1]], '4.2.01' => ['duree' => 2, 'jours' => [1]]];
$cfgT = array_column(eqpTachesConfig(), null, 'code');
verifie('le réglage : une durée et des jours changés, une tâche retirée, un code faux ignoré, une durée absurde ignorée', ($cfgT['1.2.01']['duree'] ?? 0) === 25 && $cfgT['1.2.01']['jours'] === [1, 5] && !isset($cfgT['1.2.02']) && !isset($cfgT['9.9']) && ($cfgT['4.2.01']['duree'] ?? 0) === 20, json_encode($cfgT));
unset($REGLAGES['equipeTachesMobiles']);
$lunT = date('Y-m-d', strtotime('monday next week')); $merT = date('Y-m-d', strtotime($lunT . ' +2 days'));
Db::$exec = [];
$CORPS = ['shop' => 1, 'du' => $lunT, 'places' => [['date' => $lunT, 'code' => '1.2.01', 'de' => 580, 'a' => 595, 'employe' => 13], ['date' => $merT, 'code' => '4.5.01', 'de' => 855, 'a' => 900, 'employe' => null]]];
$r = wr_equipe_taches();
$ins = array_values(array_filter(Db::$exec, static fn ($x) => str_starts_with($x[0], 'INSERT INTO panel_equipe_taches')));
$del = array_values(array_filter(Db::$exec, static fn ($x) => str_starts_with($x[0], 'DELETE FROM panel_equipe_taches')));
verifie('la semaine est remplacée : les tâches d’avant retirées, les deux gardées', ($r['places'] ?? 0) === 2 && count($del) === 1 && $del[0][1] === ['1', $lunT, date('Y-m-d', strtotime($lunT . ' +6 days'))] && count($ins) === 2 && $ins[0][1] === ['1', $lunT, '1.2.01', 580, 595, 13], json_encode([$r, Db::$exec]));
$refusT = static function (array $places) use ($lunT): bool { global $CORPS; Db::$exec = []; $CORPS = ['shop' => 1, 'du' => $lunT, 'places' => $places]; $r = wr_equipe_taches(); return isset($r['error']) && !array_filter(Db::$exec, static fn ($x) => str_starts_with($x[0], 'INSERT') || str_starts_with($x[0], 'DELETE')); };
verifie('une checklist à heure fixe : refusée', $refusT([['date' => $lunT, 'code' => '1.1.01', 'de' => 600, 'a' => 615, 'employe' => null]]));
verifie('un jour hors de la semaine : refusé', $refusT([['date' => date('Y-m-d', strtotime($lunT . ' +7 days')), 'code' => '1.2.01', 'de' => 600, 'a' => 615, 'employe' => null]]));
verifie('plus de 3 h : refusé', $refusT([['date' => $lunT, 'code' => '1.2.01', 'de' => 600, 'a' => 800, 'employe' => null]]));
verifie('la même checklist deux fois le même jour : refusée', $refusT([['date' => $lunT, 'code' => '1.2.01', 'de' => 600, 'a' => 615, 'employe' => null], ['date' => $lunT, 'code' => '1.2.01', 'de' => 900, 'a' => 915, 'employe' => null]]));
verifie('des minutes en texte : refusées', $refusT([['date' => $lunT, 'code' => '1.2.01', 'de' => '600', 'a' => 615, 'employe' => null]]));
$CORPS = ['shop' => 1, 'du' => $lunT, 'places' => []]; Db::$exec = [];
verifie('une liste vide retire les tâches de la semaine', (wr_equipe_taches()['places'] ?? -1) === 0 && count(array_filter(Db::$exec, static fn ($x) => str_starts_with($x[0], 'DELETE'))) === 1);
verifie('les tâches à heure fixe, pour la ligne du jour', array_column(eqpTachesFixes(), 'code') === ['1.1.01', '1.1.02', '1.3.01', '1.4.01'] && eqpTachesFixes()[3]['heure'] === '18:00', json_encode(eqpTachesFixes()));

echo "Les options (éteintes par défaut)\n";
Db::$options = null;
verifie('sans réglage, toutes éteintes', eqpOptions(1) === ['assistant' => false, 'taches' => false, 'matrice' => false, 'recopie' => false], json_encode(eqpOptions(1)));
$_GET = ['shop' => '1', 'du' => date('Y-m-d', strtotime('monday next week'))];
$r = eqpRoute('GET', '/dashboard/equipe/semaine/recopie');
verifie('éteinte, la recopie est refusée (403, motif option)', ($r['motif'] ?? '') === 'option' && ($r['option'] ?? '') === 'recopie', json_encode($r));
verifie('éteinte, la matrice est refusée', (eqpRoute('GET', '/dashboard/equipe/matrice')['motif'] ?? '') === 'option');
verifie('éteintes, les tâches des creux sont refusées', (eqpRoute('GET', '/dashboard/equipe/semaine/taches')['motif'] ?? '') === 'option');
$CORPS = ['shop' => 1, 'du' => $_GET['du'], 'services' => [['employe' => 10, 'date' => $_GET['du'], 'de' => '08:00', 'a' => '12:00']]]; $_GET = [];
verifie('éteint, l’assistant ne crée rien', (eqpRoute('POST', '/dashboard/equipe/semaine/creer')['option'] ?? '') === 'assistant');
Db::$options = '{"assistant":true}';
$_GET = ['shop' => '1', 'du' => date('Y-m-d', strtotime('monday next week'))];
verifie('l’assistant allumé ouvre l’aperçu de la recopie (il en a besoin), pas la recopie elle-même', !isset(eqpRoute('GET', '/dashboard/equipe/semaine/recopie')['motif']) && (eqpRoute('POST', '/dashboard/equipe/semaine/recopie')['option'] ?? '') === 'recopie');
Db::$options = null; Db::$exec = [];
$CORPS = ['shop' => 1, 'options' => ['taches' => true, 'assistant' => true]];
$r = wr_equipe_options();
$up = array_values(array_filter(Db::$exec, static fn ($x) => str_starts_with($x[0], 'INSERT INTO panel_equipe_options')));
verifie('allumer deux options : gardé, les autres restent éteintes', ($r['options'] ?? null) === ['assistant' => true, 'taches' => true, 'matrice' => false, 'recopie' => false] && count($up) === 1 && json_decode($up[0][1][1], true)['taches'] === true, json_encode([$r, Db::$exec]));
$CORPS = ['shop' => 1, 'options' => ['inconnue' => true]];
verifie('une option inconnue : refusée', isset(wr_equipe_options()['error']));
$CORPS = ['shop' => 1, 'options' => ['taches' => 'oui']];
verifie('une valeur qui n’est pas vrai ou faux : refusée', isset(wr_equipe_options()['error']));
$_GET = ['shop' => '1'];
verifie('la liste des options pour l’écran : quatre, avec leur nom', count(ep_equipe_options()['liste'] ?? []) === 4 && ep_equipe_options()['liste'][0]['nom'] === 'Assistant de la semaine');
Db::$options = '{"assistant":true,"taches":true,"matrice":true,"recopie":true}';

echo "Retirer quelqu'un de l'horaire de la semaine\n";
$lunR = date('Y-m-d', strtotime('monday next week')); $jeuR = date('Y-m-d', strtotime($lunR . ' +3 days'));
PanelApi::$reponses = [];
for ($i = 0; $i < 7; $i++) { PanelApi::$reponses['/shops/1/schedule?date=' . date('Y-m-d', strtotime($lunR . ' +' . $i . ' days'))] = []; }
PanelApi::$reponses['/shops/1/schedule?date=' . $lunR] = [['id_schedule' => 901, 'id_employee' => 10, 'work_date' => $lunR, 'start_hour' => '04:00:00', 'end_hour' => '12:00:00', 'id_shop' => 1],
    ['id_schedule' => 902, 'id_employee' => 13, 'work_date' => $lunR, 'start_hour' => '07:00:00', 'end_hour' => '11:00:00', 'id_shop' => 1]];
PanelApi::$reponses['/shops/1/schedule?date=' . $jeuR] = [['id_schedule' => 903, 'id_employee' => 10, 'work_date' => $jeuR, 'start_hour' => '06:00:00', 'end_hour' => '14:00:00', 'id_shop' => 1],
    ['id_schedule' => 904, 'id_employee' => 10, 'work_date' => $jeuR, 'start_hour' => '15:00:00', 'end_hour' => '18:00:00', 'id_shop' => 2]];
PanelApi::$ecrits = []; PanelApi::$code = 204;
$CORPS = ['shop' => 1, 'du' => $lunR, 'employe' => 10];
$r = wr_equipe_semaine_retirer();
verifie('ses services de la semaine, dans ce magasin, sont retirés ; ceux des autres et d’un autre magasin restent', ($r['retires'] ?? 0) === 2 && array_column(PanelApi::$ecrits, 1) === ['/schedule/901', '/schedule/903'], json_encode([$r, PanelApi::$ecrits]));
PanelApi::$ecrits = []; $CORPS = ['shop' => 1, 'du' => $lunR, 'employe' => 77];
verifie('personne au planning de la semaine : rien n’est retiré', (wr_equipe_semaine_retirer()['retires'] ?? -1) === 0 && PanelApi::$ecrits === []);
$CORPS = ['shop' => 1, 'du' => $lunR, 'employe' => '10'];
verifie('un identifiant en texte : refusé', isset(wr_equipe_semaine_retirer()['error']));
PanelApi::$ecrits = []; PanelApi::$code = 403; $CORPS = ['shop' => 1, 'du' => $lunR, 'employe' => 10];
$r = wr_equipe_semaine_retirer();
verifie('un refus de droits arrête au premier service', count(PanelApi::$ecrits) === 1 && isset($r['error']), json_encode($r));
PanelApi::$reponses['/shops/1/schedule?date=' . $jeuR] = null; PanelApi::$ecrits = []; PanelApi::$code = 204;
verifie('un jour muet : rien n’est retiré à l’aveugle', isset(wr_equipe_semaine_retirer()['error']) && PanelApi::$ecrits === []);

echo "La fiche d'une personne\n";
ErpApi::$actif = false; ErpApi::$reponses = [];
$fiche = ['id' => 10, 'id_shop' => 1, 'name' => 'Anna', 'surname' => 'Lambert', 'display_name' => 'Anna L.', 'lang_code' => 'fr', 'money_per_hour' => 18.5,
    'employment_type' => 'employment_contract', 'franchise_role' => 'employee', 'phone_e164' => '+32470123456', 'pin_set' => true, 'login_ready' => true,
    'password' => 'secret', 'competencies' => [['name' => 'Encaissement']], 'positions' => [['name' => 'Vendeuse', 'level' => ['name' => 'Niveau 2']]]];
PanelApi::$reponses = ['/shops/1/employees?include=positions' => $employes, '/employees/10?include=competencies,positions' => $fiche];
$f = ep_equipe_personne(1, 10);
verifie('la fiche : relue dans l’équipe du magasin, avec son détail', ($f['personne']['id'] ?? 0) === 10 && $f['detailLu'] === true && $f['personne']['taux'] === 18.5 && $f['personne']['contrat'] === 'employment_contract', json_encode($f));
verifie('ni secret ni téléphone en clair : deux chiffres seulement', !str_contains(json_encode($f), 'secret') && !str_contains(json_encode($f), '470123456') && $f['personne']['telephone'] === '+32 ·· ·· 56', json_encode($f['personne']['telephone']));
verifie('compétences et poste avec son niveau', $f['personne']['competences'] === ['Encaissement'] && $f['personne']['positions'] === [['nom' => 'Vendeuse', 'niveau' => 'Niveau 2']], json_encode($f['personne']));
verifie('une personne d’un autre magasin : 404', isset(ep_equipe_personne(1, 12)['error']));
unset(PanelApi::$reponses['/employees/10?include=competencies,positions']);
verifie('sans le détail : la fiche de la liste', ep_equipe_personne(1, 10)['detailLu'] === false);
$act = eqfFiche($employes[0]);
verifie('le corps : seulement les champs changés, aux noms du panel', eqfCorpsPatch(['taux' => '19,0' === '19,0' ? 19 : 0, 'prenom' => ' Anna ', 'contrat' => 'b2b'], $act + ['contrat' => 'employment_contract'], [])[0] === ['money_per_hour' => 19.0, 'employment_type' => 'b2b'], json_encode(eqfCorpsPatch(['taux' => 19, 'prenom' => ' Anna ', 'contrat' => 'b2b'], $act, [])));
verifie('un taux hors bornes, un contrat inconnu, un prénom vide : refusés', eqfCorpsPatch(['taux' => 0], $act, [])[1] !== null && eqfCorpsPatch(['contrat' => 'cdi'], $act, [])[1] !== null && eqfCorpsPatch(['prenom' => '  '], $act, [])[1] !== null);
verifie('un champ inconnu (le PIN) : refusé', eqfCorpsPatch(['pin' => '1234'], $act, [])[1] !== null);
verifie('changé au panel entre-temps : conflit', eqfCorpsPatch(['taux' => 20], $act, ['taux' => 17])[1] === 'conflit');
verifie('le même taux, écrit autrement : pas de conflit', eqfCorpsPatch(['taux' => 20], $act, ['taux' => '18.50'])[1] === null);
PanelApi::$reponses = ['/shops/1/employees?include=positions' => $employes]; PanelApi::$ecrits = []; PanelApi::$code = 204;
$CORPS = ['shop' => 1, 'id' => 10, 'champs' => ['taux' => 19.25, 'affiche' => 'Anna'], 'avant' => ['taux' => 18.5]];
$r = wr_equipe_personne();
verifie('PATCH /employees/10 : le taux et le nom affiché, rien d’autre', ($r['ok'] ?? false) && PanelApi::$ecrits === [['PATCH', '/employees/10', ['money_per_hour' => 19.25, 'display_name' => 'Anna']]], json_encode([$r, PanelApi::$ecrits]));
PanelApi::$ecrits = []; $CORPS = ['shop' => 1, 'id' => 12, 'champs' => ['taux' => 20]];
verifie('une personne d’un autre magasin : rien n’est écrit', isset(wr_equipe_personne()['error']) && PanelApi::$ecrits === []);
$CORPS = ['shop' => 1, 'id' => 11, 'champs' => ['taux' => 20]];
verifie('une personne désactivée : rien n’est écrit', isset(wr_equipe_personne()['error']) && PanelApi::$ecrits === []);
PanelApi::$code = 403; ErpApi::$actif = true; ErpApi::$ecrits = []; ErpApi::$code = 204; $CORPS = ['shop' => 1, 'id' => 10, 'champs' => ['taux' => 19.25]];
verifie('refusé au compte du cockpit : le compte admin l’écrit', (wr_equipe_personne()['ok'] ?? false) && ErpApi::$ecrits === [['PATCH', '/employees/10', ['money_per_hour' => 19.25]]], json_encode(ErpApi::$ecrits));
ErpApi::$actif = false; PanelApi::$code = 204;

echo "Les évaluations\n";
$hier = date('Y-m-d', strtotime('-1 day')); $auj = date('Y-m-d');
PanelApi::$reponses = ['/shops/1/employees?include=positions' => $employes, '/shops/1/employees' => $employes,
    '/employees/10/evaluations' => [['id' => 5, 'id_schedule' => 701, 'id_employee' => 10, 'evaluated_date' => $hier, 'start_hour' => '06:00:00', 'end_hour' => '12:00:00', 'rating' => 4, 'note' => 'Bien', 'evaluated_by' => 13]]];
for ($i = 0; $i < EQF_A_NOTER_JOURS; $i++) { PanelApi::$reponses['/shops/1/schedule?date=' . date('Y-m-d', strtotime('-' . $i . ' days'))] = []; }
PanelApi::$reponses['/shops/1/schedule?date=' . $hier] = [['id_schedule' => 701, 'id_employee' => 10, 'work_date' => $hier, 'start_hour' => '06:00:00', 'end_hour' => '12:00:00', 'id_shop' => 1],
    ['id_schedule' => 702, 'id_employee' => 10, 'work_date' => $hier, 'start_hour' => '13:00:00', 'end_hour' => '17:00:00', 'id_shop' => 1],
    ['id_schedule' => 703, 'id_employee' => 13, 'work_date' => $hier, 'start_hour' => '07:00:00', 'end_hour' => '11:00:00', 'id_shop' => 1]];
PanelApi::$reponses['/shops/1/schedule?date=' . $auj] = [['id_schedule' => 704, 'id_employee' => 10, 'work_date' => $auj, 'start_hour' => '23:00:00', 'end_hour' => '23:59:00', 'id_shop' => 1]];
$E = ep_equipe_personne_evaluations(1, 10);
verifie('ses notes, avec qui a noté', count($E['evaluations']) === 1 && $E['evaluations'][0]['note'] === 4 && $E['evaluations'][0]['par'] === 'Caissière' && $E['moyenne'] === 4.0, json_encode($E['evaluations']));
verifie('à noter : son service non noté, fini ; ni celui d’un autre, ni un service pas fini', array_column($E['aNoter'], 'service') === [702], json_encode($E['aNoter']));
PanelApi::$ecrits = []; $CORPS = ['shop' => 1, 'id' => 10, 'service' => 702, 'date' => $hier, 'note' => 5, 'mot' => 'Top'];
$r = wr_equipe_personne_evaluation();
verifie('POST /schedule/702/evaluation {rating, note}', ($r['ok'] ?? false) && PanelApi::$ecrits === [['POST', '/schedule/702/evaluation', ['rating' => 5, 'note' => 'Top']]], json_encode([$r, PanelApi::$ecrits]));
PanelApi::$ecrits = []; $CORPS = ['shop' => 1, 'id' => 10, 'service' => 703, 'date' => $hier, 'note' => 5];
verifie('le service d’une autre personne : refusé, rien n’est écrit', isset(wr_equipe_personne_evaluation()['error']) && PanelApi::$ecrits === []);
$CORPS = ['shop' => 1, 'id' => 10, 'service' => 704, 'date' => $auj, 'note' => 5];
verifie('un service pas encore fini : refusé', isset(wr_equipe_personne_evaluation()['error']) && PanelApi::$ecrits === []);
$CORPS = ['shop' => 1, 'id' => 10, 'service' => 702, 'date' => $hier, 'note' => 6];
verifie('une note hors de 1 à 5 : refusée', isset(wr_equipe_personne_evaluation()['error']) && PanelApi::$ecrits === []);

echo "Les ventes des 12 dernières semaines\n";
$sem = eqfSemaines('2026-11-24');
verifie('12 semaines complètes, du lundi 31 août au lundi 16 novembre', count($sem) === 12 && $sem[0] === '2026-08-31' && $sem[11] === '2026-11-16', json_encode($sem));
$V = []; $Lg = [];
foreach ($sem as $du) { for ($k = 0; $k < 7; $k++) { $j = date('Y-m-d', strtotime($du . ' +' . $k . ' days'));
    $V[$j] = [10 => ['t' => 20, 'ca' => 300.0], 13 => ['t' => 10, 'ca' => 120.0]] + ($j === '2026-08-31' ? [14 => ['t' => 2, 'ca' => 100.0]] : []); $Lg[$j] = [10 => ['l' => 50, 't' => 20], 13 => ['l' => 20, 't' => 10]]; } }
$V['2026-11-22'] = null; $Lg['2026-11-22'] = null;
$A = eqfVentesAgreger(10, $sem, $V, $Lg);
verifie('ses totaux : vendu, tickets, panier, pièces par ticket', $A['total']['ca'] === 83 * 300.0 && $A['total']['tickets'] === 83 * 20 && $A['total']['panier'] === 15.0 && $A['total']['pieces'] === 2.5, json_encode($A['total']));
verifie('l’équipe : panier et pièces de tout le magasin', $A['equipe']['panier'] === round((83 * 420 + 100) / (83 * 30 + 2), 2) && $A['equipe']['pieces'] === round(70 / 30, 2), json_encode($A['equipe']));
verifie('le rang au panier : 30 tickets au moins pour compter', $A['rang'] === ['position' => 1, 'sur' => 2], json_encode($A['rang']));
verifie('un jour pas lu : compté dans la couverture, pas inventé', $A['couverture'] === ['jours' => 84, 'ventes' => 83, 'lignes' => 83] && $A['semaines'][11]['jours'] === 6, json_encode($A['couverture']));
verifie('une semaine sans ticket : panier nul, pas zéro', eqfVentesAgreger(99, $sem, $V, $Lg)['semaines'][0]['panier'] === null);

echo "Les absences, les congés et les remplacements\n";
verifie('maladie sous contrat de travail : payée (le coût reste)', eqaPayee('maladie', 'employment_contract'));
verifie('congé payé, contrat à préciser : payé', eqaPayee('cp', null));
verifie('intérim, stage, B2B : une absence ne coûte rien', !eqaPayee('maladie', 'temporary_work') && !eqaPayee('cp', 'internship') && !eqaPayee('circ', 'b2b'));
verifie('absence non payée : le coût sort, même sous contrat', !eqaPayee('nonpaye', 'employment_contract'));
$EQ = [201 => ['id' => 201, 'nom' => 'Camille R', 'actif' => true], 203 => ['id' => 203, 'nom' => 'Chloé V', 'actif' => true],
    208 => ['id' => 208, 'nom' => 'Inès F', 'actif' => true], 210 => ['id' => 210, 'nom' => 'Lucie B', 'actif' => false]];
$PJ = ['2026-11-18' => [['id' => 11, 'employe' => 201, 'de' => '12:45', 'a' => '19:15'], ['id' => 12, 'employe' => 203, 'de' => '05:30', 'a' => '11:00']],
    '2026-11-19' => [],
    '2026-11-20' => [['id' => 13, 'employe' => 201, 'de' => '13:00', 'a' => '19:15'], ['id' => 14, 'employe' => 208, 'de' => '14:45', 'a' => '19:15']]];
$Pl = eqaPlan($PJ, 201, true, [], ['2026-11-18' => ['employe' => 208]], $EQ);
verifie('payée : ses deux services marqués, rien retiré du panel', count($Pl['lignes']) === 2 && $Pl['retirer'] === [] && $Pl['erreurs'] === [], json_encode($Pl));
verifie('le remplaçant aux mêmes heures, lié au service absent', $Pl['creer'] === [['date' => '2026-11-18', 'employe' => 208, 'de' => '12:45', 'a' => '19:15', 'pour' => 11]], json_encode($Pl['creer']));
$Pl = eqaPlan($PJ, 201, true, [], ['2026-11-20' => ['employe' => 208]], $EQ);
verifie('un remplaçant qui travaille déjà à ces heures : refusé', $Pl['erreurs'] !== [] && str_contains($Pl['erreurs'][0], 'travaille déjà'), json_encode($Pl['erreurs']));
verifie('un remplaçant désactivé, ou la personne elle-même : refusés',
    eqaPlan($PJ, 201, true, [], ['2026-11-18' => ['employe' => 210]], $EQ)['erreurs'] !== [] && eqaPlan($PJ, 201, true, [], ['2026-11-18' => ['employe' => 201]], $EQ)['erreurs'] !== []);
verifie('un jour sans service absent : pas de remplacement', eqaPlan($PJ, 201, true, [], ['2026-11-19' => ['employe' => 203]], $EQ)['erreurs'] !== []);
$Pl = eqaPlan($PJ, 201, false, [], ['2026-11-20' => ['externe' => ['nom' => 'Intérim', 'taux' => 24.5]]], $EQ);
verifie('non payée : ses services quittent le panel ; un extérieur noté au cockpit', $Pl['retirer'] === [11, 13] && $Pl['externes'] === [['date' => '2026-11-20', 'service' => 13, 'nom' => 'Intérim', 'taux' => 24.5]], json_encode($Pl));
$EX = [13 => ['id' => 5, 'service' => 13, 'date' => '2026-11-20', 'de' => '13:00', 'a' => '19:15', 'payee' => true, 'remplacantService' => 14, 'externe' => null]];
verifie('un service déjà remplacé (le service du remplaçant est au planning) : refusé', eqaPlan($PJ, 201, true, $EX, ['2026-11-20' => ['employe' => 203]], $EQ)['erreurs'] !== []);
$EX = [13 => ['id' => 5, 'service' => 13, 'date' => '2026-11-20', 'de' => '13:00', 'a' => '19:15', 'payee' => true, 'remplacantService' => 99, 'externe' => null]];
verifie('son remplaçant retiré du planning depuis : on peut en mettre un autre', eqaPlan($PJ, 201, true, $EX, ['2026-11-20' => ['employe' => 203]], $EQ)['erreurs'] === []);
$EX = [77 => ['id' => 6, 'service' => 77, 'date' => '2026-11-19', 'de' => '07:00', 'a' => '12:00', 'payee' => false, 'remplacantService' => null, 'externe' => null]];
verifie('une absence non payée déjà posée, puis une payée : annuler d’abord', eqaPlan($PJ, 201, true, $EX, [], $EQ)['erreurs'] !== []);
verifie('personne au planning : rien à marquer', eqaPlan(['2026-11-19' => []], 201, true, [], [], $EQ)['erreurs'] !== []);

// La route, le panel en doublure : la personne malade demain et après-demain, un remplaçant demain.
$j1 = date('Y-m-d', strtotime('+1 day')); $j2 = date('Y-m-d', strtotime('+2 days'));
PanelApi::$reponses = [
    '/shops/1/employees' => [['id' => 201, 'id_shop' => 1, 'name' => 'Camille', 'surname' => 'R', 'employment_type' => 'employment_contract', 'money_per_hour' => 15.8],
        ['id' => 208, 'id_shop' => 1, 'name' => 'Inès', 'surname' => 'F', 'employment_type' => 'internship', 'money_per_hour' => 12.4],
        ['id' => 300, 'id_shop' => 2, 'name' => 'Autre', 'surname' => 'Magasin']],
    '/shops/1/schedule?date=' . $j1 => [['id_schedule' => 21, 'id_employee' => 201, 'work_date' => $j1, 'start_hour' => '12:45:00', 'end_hour' => '19:15:00', 'id_shop' => 1]],
    '/shops/1/schedule?date=' . $j2 => [['id_schedule' => 22, 'id_employee' => 201, 'work_date' => $j2, 'start_hour' => '13:00:00', 'end_hour' => '19:15:00', 'id_shop' => 1]],
];
PanelApi::$ecrits = []; PanelApi::$code = 201; Db::$exec = [];
$CORPS = ['shop' => 1, 'employe' => 201, 'du' => $j1, 'au' => $j2, 'motif' => 'maladie', 'certificat' => true, 'remplacements' => [['date' => $j1, 'employe' => 208]]];
$r = wr_equipe_absence();
$ins = array_values(array_filter(Db::$exec, static fn ($x) => str_starts_with($x[0], 'INSERT INTO panel_equipe_absence')));
$upd = array_values(array_filter(Db::$exec, static fn ($x) => str_starts_with($x[0], 'UPDATE panel_equipe_absence SET remplacant_id')));
verifie('malade sous contrat : deux absences payées, le service reste au panel', ($r['ok'] ?? false) && $r['absences'] === 2 && $r['payee'] === true && count($ins) === 2 && !array_filter(PanelApi::$ecrits, static fn ($e) => $e[0] === 'DELETE'), json_encode($r));
verifie('le remplaçant : un service au panel aux mêmes heures, noté sur l’absence',
    PanelApi::$ecrits === [['POST', '/schedule', ['id_employee' => 208, 'work_date' => $j1, 'start_hour' => '12:45:00', 'end_hour' => '19:15:00']]] && count($upd) === 1 && $upd[0][1][0] === 208 && $upd[0][1][1] === 77 && $upd[0][1][4] === 21, json_encode([PanelApi::$ecrits, $upd]));
PanelApi::$ecrits = []; PanelApi::$code = 204; Db::$exec = [];
$CORPS = ['shop' => 1, 'employe' => 201, 'du' => $j1, 'au' => $j1, 'motif' => 'nonpaye'];
$r = wr_equipe_absence();
verifie('non payée : le service quitte le panel, l’absence garde ses heures', ($r['ok'] ?? false) && $r['payee'] === false && PanelApi::$ecrits === [['DELETE', '/schedule/21', null]]
    && count(array_filter(Db::$exec, static fn ($x) => str_starts_with($x[0], 'INSERT INTO panel_equipe_absence') && $x[1][4] === '12:45' && $x[1][7] === 0)) === 1, json_encode([$r, PanelApi::$ecrits]));
PanelApi::$ecrits = []; Db::$exec = [];
$CORPS = ['shop' => 1, 'employe' => 208, 'du' => $j1, 'au' => $j1, 'motif' => 'maladie'];
verifie('quelqu’un sans service ces jours-là : rien n’est écrit', isset(wr_equipe_absence()['error']) && PanelApi::$ecrits === [] && Db::$exec === []);
$CORPS = ['shop' => 1, 'employe' => 300, 'du' => $j1, 'au' => $j1, 'motif' => 'maladie'];
verifie('une personne d’un autre magasin : 404', isset(wr_equipe_absence()['error']) && http_response_code() === 404);
foreach ([['motif' => 'vacances'], ['au' => date('Y-m-d', strtotime('+200 days'))], ['du' => $j2, 'au' => $j1], ['remplacements' => [['date' => $j1, 'externe' => ['nom' => 'Intérim', 'taux' => 0]]]],
    ['remplacements' => [['date' => date('Y-m-d', strtotime('+9 days')), 'employe' => 208]]]] as $k => $x) {
    $CORPS = array_merge(['shop' => 1, 'employe' => 201, 'du' => $j1, 'au' => $j2, 'motif' => 'maladie'], $x);
    verifie('refusé avant toute écriture : ' . ['un motif inconnu', 'trop loin', 'une période à l’envers', 'un extérieur sans taux', 'un remplacement hors période'][$k], isset(wr_equipe_absence()['error']) && PanelApi::$ecrits === []);
}

// Le planning imprimé : l'absente porte son motif (pas ses heures), le remplaçant « remplace … ».
$jrs = [];
foreach (range(0, 6) as $i) { $jrs[] = ['date' => date('Y-m-d', strtotime('2026-11-16 +' . $i . ' days')), 'services' => [], 'absences' => []]; }
$jrs[2]['services'] = [['id' => 11, 'employe' => 201, 'de' => '12:45', 'a' => '19:15'], ['id' => 31, 'employe' => 208, 'de' => '12:45', 'a' => '19:15']];
$jrs[2]['absences'] = [['id' => 1, 'employe' => 201, 'service' => 11, 'motif' => 'maladie', 'payee' => true, 'remplacant' => 208, 'remplacantService' => 31]];
$html = eqpPlanningPdfHtml(['jours' => $jrs, 'employes' => [['id' => 201, 'nom' => 'Camille Roussel', 'actif' => true, 'postes' => [], 'secteurs' => []], ['id' => 208, 'nom' => 'Inès Fontaine', 'actif' => true, 'postes' => [], 'secteurs' => []]], 'secteurs' => []], 'magasin', 'Magasin démo');
verifie('le planning imprimé : « Maladie » à la place de ses heures, son remplaçant le dit', str_contains($html, '<span class="sv abs">Maladie</span>') && str_contains($html, 'remplace Camille') && substr_count($html, '6,5 h') >= 1 && !str_contains($html, '13 h'), '');

echo "\n" . $ok . ' ok, ' . $ko . " KO\n";
exit($ko > 0 ? 1 : 0);
