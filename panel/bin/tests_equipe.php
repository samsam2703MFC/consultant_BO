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
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return self::$rows[$a[0] . ':' . $a[1] . '-' . $a[2]] ?? null; }
    public static function exec(string $sql, array $a = []): void {}
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

echo "\n" . $ok . ' ok, ' . $ko . " KO\n";
exit($ko > 0 ? 1 : 0);
