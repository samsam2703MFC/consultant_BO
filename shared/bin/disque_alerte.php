<?php
declare(strict_types=1);
/**
 * Alerte « disque presque plein » (24/10/2026) : une notification aux abonnés « admin » du cockpit
 * et une ligne au journal, au plus une fois toutes les 6 heures.
 *
 *   php bin/disque_alerte.php <pourcentage> <Mo libres>
 *
 * Appelé par bin/menage_disque.sh --alerte quand / reste à 90 % ou plus après le ménage. Tant que le
 * disque n'est pas plein à 100 %, MySQL répond encore : c'est le moment de prévenir, avant la panne.
 */
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/endpoints.php';
require __DIR__ . '/../src/writes.php';
require __DIR__ . '/../src/push.php';

$pct = (int) ($argv[1] ?? 0);
$libre = (int) ($argv[2] ?? 0);
$dire = static function (string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n"); };

try {
    $dernier = (int) setting('disqueAlerteLe', 0);
    if ($dernier > time() - 6 * 3600) { $dire('alerte déjà envoyée il y a moins de 6 h.'); exit(0); }
    $titre = 'Serveur : disque à ' . $pct . ' %';
    $corps = $libre . ' Mo libres après le ménage. Au-delà, MySQL n’écrit plus et le cockpit tombe : libérer de la place (docs/DEPLOIEMENT.md › Disque plein).';
    $r = pushDiffuser('admin', ['titre' => $titre, 'corps' => $corps, 'url' => '#/resultat', 'tag' => 'disque-plein']);
    journalAdd('Serveur', 'Disque', null, $titre . ' — ' . $libre . ' Mo libres après le ménage');
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['disqueAlerteLe', json_encode(time())]);
    $dire('alerte envoyée : ' . $r['envoyes'] . ' / ' . $r['abonnements'] . ' abonnement(s) admin.');
} catch (Throwable $e) {
    $dire('alerte impossible : ' . $e->getMessage());
    exit(1);
}
