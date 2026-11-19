<?php
declare(strict_types=1);

/**
 * Les migrations de la réorganisation (shared/src/migrations.php).
 *
 *   php shared/bin/migrer.php statut          ce qui est passé, ce qui attend
 *   php shared/bin/migrer.php up              passe ce qui attend, dans l'ordre (arrêt à la première erreur)
 *   php shared/bin/migrer.php down <nom>      défait la DERNIÈRE migration passée, nommée (retour arrière)
 *
 * Utilise la connexion de l'application (shared/config/config.php, sinon COCKPIT_DB_*). Code de sortie : 0 si tout
 * est passé, 1 sinon. N'affiche jamais une valeur de clé.
 */

require __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/erp_cles.php';
require_once __DIR__ . '/../src/writes.php';      // journalAdd()
require_once __DIR__ . '/../src/migrations.php';

$action = $argv[1] ?? 'statut';
$cfg = Db::config()['db'];
echo "Base : {$cfg['host']}:{$cfg['port']} / " . (string) Db::row('SELECT DATABASE() AS b')['b'] . "\n";
$faites = migrationsFaites();
if ($action === 'statut') {
    foreach (migrationsListe() as $nom => $f) { echo (isset($faites[$nom]) ? '  passée   ' . $faites[$nom]['le'] : '  attend   ') . "  $nom\n"; }
    exit(0);
}
if ($action === 'up') {
    $r = migrationsAppliquer();
    foreach ($r['faites'] as $nom => $b) { echo "  passée  $nom : " . migrationResume($b) . "\n"; }
    if ($r['erreur'] !== null) { echo "  ARRÊT   {$r['erreur']}\n"; exit(1); }
    echo $r['faites'] ? '' : "  rien en attente\n";
    exit(0);
}
if ($action === 'down') {
    $nom = (string) ($argv[2] ?? '');
    try { $b = migrationDefaire($nom); } catch (Throwable $e) { echo "  refusé : " . $e->getMessage() . "\n"; exit(1); }
    echo "  défaite  $nom : " . migrationResume($b) . "\n";
    exit(0);
}
fwrite(STDERR, "action inconnue : $action (statut | up | down <nom>)\n");
exit(1);
