<?php
declare(strict_types=1);
/**
 * La moisson de l'historique des ventes (18 mois de tickets, heure par heure et produit par produit) : une passe
 * bornée, d'hier vers le passé. Voir src/historique_ventes.php.
 *
 *   php bin/historique_moisson.php                      une passe de nuit (4 000 appels au panel, 8 minutes au plus)
 *   php bin/historique_moisson.php --budget=500 --minutes=2
 *   php bin/historique_moisson.php --etat               dire où en est l'historique, sans rien lire
 *
 * Posé en cron par bin/deploy.sh (/etc/cron.d/cockpit-historique) : toutes les dix minutes de 20 h à 6 h, quand
 * personne ne lit le dashboard — le panel lâche quand on le presse en journée. Un verrou empêche deux passes
 * simultanées. Rien de secret ici : les identifiants du panel sont lus en base (erp_cles), comme pour l'API.
 */
require __DIR__ . '/../src/Db.php';
if (is_file(__DIR__ . '/../src/erp_cles.php')) { require_once __DIR__ . '/../src/erp_cles.php'; }
require __DIR__ . '/../src/endpoints.php';
require __DIR__ . '/../src/writes.php';
require __DIR__ . '/../src/panel_api.php';
require __DIR__ . '/../src/stats_ventes.php';
require __DIR__ . '/../src/historique_ventes.php';

set_time_limit(0);
$args = array_slice($argv, 1);
$opt = static function (string $nom, int $defaut) use ($args): int {
    foreach ($args as $a) { if (str_starts_with($a, '--' . $nom . '=')) { return max(1, (int) substr($a, strlen($nom) + 3)); } }
    return $defaut;
};
$dire = static function (string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n"); };

if (in_array('--etat', $args, true)) {
    $e = ep_historique_etat();
    $dire('historique du ' . $e['depuis'] . ' au ' . $e['jusqua'] . ' : ' . $e['avancement'] . ' % lu');
    foreach ($e['magasins'] as $m) {
        $dire('  ' . $m['magasin'] . ' : ' . $m['joursLus'] . '/' . $e['joursParMagasin'] . ' jours, ' . $m['tickets'] . ' tickets'
            . ($m['depuis'] ? ' (du ' . $m['depuis'] . ' au ' . $m['jusqua'] . ')' : ''));
    }
    exit(0);
}

$verrou = fopen(sys_get_temp_dir() . '/cockpit-historique.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
    $dire('une autre passe est en cours — on laisse la main.');
    exit(0);
}

$r = hvMoisson($opt('budget', HV_BUDGET_NUIT), 60 * $opt('minutes', HV_MINUTES_NUIT));
if (!$r['ok']) { $dire('échec : ' . ($r['motif'] ?? '?')); exit(1); }
$dire($r['joursFaits'] . ' jour(s)-magasin lus, ' . $r['appels'] . ' appels, ' . $r['echecs'] . ' échec(s), ' . $r['secondes'] . ' s — '
    . $r['etat'] . ($r['plusAncienLu'] ? ' (plus ancien lu : ' . $r['plusAncienLu'] . ')' : ''));
