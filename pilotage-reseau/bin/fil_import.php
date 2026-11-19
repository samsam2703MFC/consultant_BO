<?php
declare(strict_types=1);
/**
 * L'import rétroactif du fil du franchisé (src/fil_import.php) : un fichier JSON par magasin, préparé et relu hors du
 * cockpit (courrier, Drive, agenda, WhatsApp).
 *
 *   php bin/fil_import.php fichier.json [autre.json …]             à blanc : ce qui serait écrit, rien n'est écrit
 *   php bin/fil_import.php --ecrire fichier.json [autre.json …]    écrit les entrées et ouvre ou ferme les fils
 *
 * Les fichiers sont du courrier : ils ne passent pas par Git. On les dépose dans le dossier privé du cockpit (par
 * exemple /var/lib/consulant_bo/prive/fil-import/) et on les efface une fois l'import fait. Rejouer un fichier n'écrit
 * rien deux fois.
 */
require __DIR__ . '/../src/Db.php';
if (is_file(__DIR__ . '/../src/erp_cles.php')) { require_once __DIR__ . '/../src/erp_cles.php'; }
require __DIR__ . '/../src/endpoints.php';
require __DIR__ . '/../src/writes.php';
require __DIR__ . '/../src/fil_franchise.php';
require __DIR__ . '/../src/fil_import.php';

// Les magasins ouverts, comme l'écran du fil les connaît (viMagasins, src/visites.php) : la table des magasins du panel.
if (!function_exists('viMagasins')) {
    function viMagasins(): array
    {
        static $m = null;
        if ($m !== null) { return $m; }
        $m = [];
        foreach (ep_stores() as $s) {
            if (($s['status'] ?? '') !== 'Ouvert') { continue; }
            $m[(string) $s['id']] = ['id' => (string) $s['id'], 'nom' => (string) $s['nom'], 'court' => (string) $s['nom']];
        }
        return $m;
    }
}

$args = array_slice($argv, 1);
$ecrire = in_array('--ecrire', $args, true);
$fichiers = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')));
if ($fichiers === []) {
    fwrite(STDERR, "usage : php bin/fil_import.php [--ecrire] fichier.json [autre.json …]\n");
    exit(2);
}

$echec = false;
foreach ($fichiers as $f) {
    echo "== $f\n";
    $brut = is_file($f) ? file_get_contents($f) : false;
    $doc = $brut !== false ? json_decode($brut, true) : null;
    [$lu, $err] = filImportLire($doc);
    if ($lu === null) {
        foreach ($err as $e) { echo "  refusé : $e\n"; }
        $echec = true;
        continue;
    }
    $cr = filImportAppliquer($lu, $ecrire);
    echo '  ' . $cr['magasin'] . ' (shop ' . $cr['shop'] . ') : ' . $cr['nouvelles'] . ($ecrire ? ' entrée(s) écrite(s)' : ' entrée(s) à écrire')
        . ', ' . $cr['deja'] . " déjà présente(s)\n";
    if ($cr['filsOuverts'] !== []) { echo '  fils ' . ($ecrire ? 'ouverts' : 'à ouvrir') . ' : ' . implode(', ', array_unique($cr['filsOuverts'])) . "\n"; }
    if ($cr['filsFermes'] !== []) { echo '  fils ' . ($ecrire ? 'fermés' : 'à fermer') . ' : ' . implode(', ', array_unique($cr['filsFermes'])) . "\n"; }
    if ($cr['erreur'] !== null) { echo '  arrêté : ' . $cr['erreur'] . "\n"; $echec = true; }
}
if (!$ecrire && !$echec) { echo "\nÀ blanc : rien n'est écrit. Relancer avec --ecrire pour importer.\n"; }
exit($echec ? 1 : 0);
