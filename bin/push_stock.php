<?php
declare(strict_types=1);
/**
 * Surveille le stock des magasins abonnés et envoie une notification quand une
 * référence PASSE sous son minimum.
 *
 *   php bin/push_stock.php            les magasins qui ont au moins un abonné
 *   php bin/push_stock.php --tout     tous les magasins actifs, abonnés ou non
 *   php bin/push_stock.php --sec      ne rien envoyer, dire seulement ce qui partirait
 *
 * L'état retenu d'un passage à l'autre vit dans `ceo_app_setting`, une entrée
 * par magasin (`pushStock:<id>`) : la liste des références en alerte à la
 * dernière lecture. On ne notifie QUE les nouvelles — sans cela, chaque
 * passage rappellerait les quatre-vingt-quinze ruptures de la veille.
 *
 * Le premier passage d'un magasin n'envoie rien : il n'a rien à comparer, il
 * se contente de poser l'état.
 *
 * Posé en cron par bin/deploy.sh, toutes les quinze minutes aux heures
 * d'ouverture. Un verrou empêche deux passages simultanés.
 */
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/endpoints.php';
require __DIR__ . '/../src/writes.php';
require __DIR__ . '/../src/panel_api.php';
require __DIR__ . '/../src/ventes.php';
require __DIR__ . '/../src/push.php';

set_time_limit(0);
$args = array_slice($argv, 1);
$tout = in_array('--tout', $args, true);
$sec  = in_array('--sec', $args, true);
$dire = static function (string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n"); };

$verrou = fopen(sys_get_temp_dir() . '/cockpit-push-stock.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
    $dire('un autre passage est en cours — on laisse la main.');
    exit(0);
}

pushTable();

// Les magasins à regarder : ceux qui ont un abonné, sauf si l'on force.
$shops = [];
if ($tout) {
    foreach (Db::rows('SELECT id FROM shops WHERE active = 1') as $r) { $shops[] = (string) $r['id']; }
} else {
    foreach (Db::rows('SELECT DISTINCT shop_id FROM ceo_push_abonnement') as $r) { $shops[] = (string) $r['shop_id']; }
}
if (!$shops) { $dire('aucun magasin abonné — rien à faire.'); exit(0); }

foreach ($shops as $shop) {
    $_GET['shop'] = $shop;
    $d = ep_ventes_stock();
    if (!empty($d['indispo']) || !isset($d['lignes'])) {
        $dire("magasin $shop : inventaire illisible (" . ($d['motif'] ?? 'sans motif') . ')');
        continue;
    }
    $enAlerte = [];
    foreach ($d['lignes'] as $l) { if (!empty($l['alerte'])) { $enAlerte[] = (string) $l['ref']; } }
    sort($enAlerte);

    $cle = 'pushStock:' . $shop;
    $avant = setting($cle, null);
    $connu = is_array($avant) && isset($avant['refs']) && is_array($avant['refs']) ? $avant['refs'] : null;

    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$cle, json_encode(['refs' => $enAlerte, 'le' => date('c')], JSON_UNESCAPED_UNICODE)]);

    if ($connu === null) {
        $dire("magasin $shop : premier passage, " . count($enAlerte) . ' référence(s) en alerte — état posé, rien d’envoyé.');
        continue;
    }
    $neuves = array_values(array_diff($enAlerte, $connu));
    if (!$neuves) { $dire("magasin $shop : rien de neuf (" . count($enAlerte) . ' en alerte).'); continue; }

    $n = count($neuves);
    $corps = $n === 1
        ? $neuves[0] . ' passe sous son minimum.'
        : $n . ' références passent sous leur minimum : ' . implode(', ', array_slice($neuves, 0, 3)) . ($n > 3 ? '…' : '');
    $nom = (string) (Db::row('SELECT name FROM shops WHERE id = ?', [$shop])['name'] ?? ('Magasin ' . $shop));

    if ($sec) { $dire("magasin $shop : $n nouvelle(s) — $corps  [essai à sec, rien envoyé]"); continue; }
    $r = pushDiffuser($shop, [
        'titre' => $nom . ' — stock',
        'corps' => $corps,
        'url'   => 'dashboard/?shop=' . rawurlencode($shop),
        'tag'   => 'stock-' . $shop,
    ]);
    $dire("magasin $shop : $n nouvelle(s) · envoyé à {$r['envoyes']}/{$r['abonnements']} appareil(s)"
        . ($r['retires'] ? ", {$r['retires']} abonnement(s) périmé(s) retiré(s)" : '')
        . ($r['erreurs'] ? ' · erreurs : ' . implode(' | ', array_slice($r['erreurs'], 0, 2)) : ''));
}
$dire('fin.');
