<?php
declare(strict_types=1);
/**
 * Rafraîchit le cache OpenStreetMap du scouting commercial (ceo_scouting_tile).
 *
 *   php bin/scouting_refresh.php            secteurs absents ou relus il y a plus de six jours
 *   php bin/scouting_refresh.php --force    les neuf secteurs, quel que soit leur âge
 *   php bin/scouting_refresh.php 2 5        seulement ces secteurs (0 à 8), quel que soit leur âge
 *
 * Posé en cron hebdomadaire par bin/deploy.sh (/etc/cron.d/cockpit-scouting),
 * et lancé en arrière-plan à chaque livraison pour les secteurs manquants :
 * personne n'attend plus Overpass à l'ouverture de l'écran, et les données ont
 * au plus une semaine. Un verrou empêche deux passages simultanés. Utilise
 * config/config.php, comme l'application ; à exécuter depuis le déploiement servi.
 */
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/scouting_osm.php';

@ini_set('memory_limit', '512M');
set_time_limit(0);

$args  = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$only  = array_values(array_map('intval', array_filter($args, static fn ($a) => ctype_digit((string) $a))));

$dire = static function (string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n"); };

$verrou = fopen(sys_get_temp_dir() . '/cockpit-scouting-refresh.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
    $dire('un rafraîchissement est déjà en cours — rien à faire');
    exit(0);
}

$ages = ScoutingOsm::ages();
$maintenant = time();
$cibles = [];
foreach (ScoutingOsm::SECTEURS as $i => [$bbox, $label]) {
    if ($only !== [] && !in_array($i, $only, true)) { continue; }
    $age = $ages[$i] ?? 0;
    if (!$force && $only === [] && $age > 0 && ($maintenant - $age) < ScoutingOsm::FRAICHEUR_S) { continue; }
    $cibles[] = $i;
}
if ($cibles === []) {
    $dire('cache complet et récent (' . count($ages) . '/' . count(ScoutingOsm::SECTEURS) . ' secteurs, aucun de plus de six jours) — rien à relire');
    exit(0);
}

$dire('relecture de ' . count($cibles) . ' secteur(s) : ' . implode(', ', $cibles) . ($force ? ' (forcée)' : ''));
$echecs = 0;
foreach ($cibles as $i) {
    $label = ScoutingOsm::SECTEURS[$i][1];
    $t0 = microtime(true);
    $d = ScoutingOsm::rafraichir($i);
    $duree = round(microtime(true) - $t0);
    if ($d === null) {
        $echecs++;
        $dire(sprintf('  secteur %d (%s) : ÉCHEC après %d s — %s', $i, $label, $duree, ScoutingOsm::$lastError ?? 'sans détail'));
        continue;
    }
    $dire(sprintf('  secteur %d (%s) : %d communes, %d commerces, %d lieux — %d s', $i, $label, count($d['c']), count($d['b']), count($d['p']), $duree));
    unset($d);
}
$dire($echecs === 0 ? 'terminé, tout est en cache' : 'terminé avec ' . $echecs . ' échec(s) — le prochain passage réessaiera');
exit($echecs === 0 ? 0 : 1);
