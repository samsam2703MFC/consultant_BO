<?php
declare(strict_types=1);

/*
 * SONDE — la forme réelle des réponses de l'API TF Buddy dont se sert consultant/bin/envoi_etiquettes_nl.php
 * (21/11/2026). Premier essai à blanc sur le serveur : `/allergens` lu sans erreur, mais aucun allergène reconnu, même
 * pas « gluten » — la réponse n'a pas la forme supposée. La sonde montre, pour chaque route lue par le script, le type
 * de la réponse, ses clés, le nombre d'éléments et le premier élément (tronqué) :
 *   /allergens (en entier : 14 allergènes), /material-suppliers, les matières premières des deux premiers fournisseurs
 *   et les allergènes de la première, /materials, /products, /products/aliases.
 *
 * Lancée sur le serveur du cockpit par `.github/workflows/sonde-etiquettes-nl.yml` :
 *     php -- /var/www/consulant_bo < consultant/tools/sonde_etiquettes_nl.php
 *
 * LECTURE SEULE : des GET à l'API avec le compte admin du cockpit. Coordonnées et secrets masqués.
 */

$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['shared/src/Db.php', 'shared/src/endpoints.php', 'shared/src/erp_api.php'] as $f) {
    if (!is_file("$dir/$f")) { fwrite(STDERR, "fichier absent : $dir/$f\n"); exit(2); }
    require_once "$dir/$f";
}
if (!ErpApi::disponible()) { fwrite(STDERR, "compte admin de l'ERP non configuré\n"); exit(2); }

$masque = static function ($v) use (&$masque) {
    if (!is_array($v)) { return $v; }
    $o = [];
    foreach ($v as $k => $x) {
        $o[$k] = is_string($k) && preg_match('/pass|secret|token|iban|swift|bic|bank|e?mail|phone|tel|first_?name|last_?name|street|address|zip|postal|nip|vat|birth/i', $k)
            ? '***' : $masque($x);
    }
    return $o;
};
$court = static fn ($v, int $n = 1200): string => mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, $n);
$decrire = static function (string $chemin, int $n = 1200, bool $entier = false) use ($masque, $court): mixed {
    $r = ErpApi::brut($chemin, 30_000_000);
    echo "\n=== GET $chemin → HTTP {$r['code']}" . ($r['erreur'] ? ' (' . $r['erreur'] . ')' : '') . "\n";
    $j = json_decode((string) $r['corps'], true);
    if (!is_array($j)) { echo '  corps non JSON : ' . mb_substr((string) $r['corps'], 0, 200) . "\n"; return null; }
    echo '  racine : ' . (array_is_list($j) ? 'liste de ' . count($j) : 'objet, clés ' . implode(', ', array_keys($j))) . "\n";
    // La première liste trouvée, à la racine ou sous une clé (data, data.items…).
    $liste = null; $ou = 'racine';
    if (array_is_list($j)) { $liste = $j; }
    else {
        foreach ($j as $k => $v) {
            if (is_array($v) && array_is_list($v)) { $liste = $v; $ou = $k; break; }
            if (is_array($v)) { foreach ($v as $k2 => $v2) { if (is_array($v2) && array_is_list($v2) && $v2 !== []) { $liste = $v2; $ou = "$k.$k2"; break 2; } } }
        }
        foreach (['meta', 'pagination', 'links', 'total', 'last_page', 'current_page', 'per_page'] as $k) {
            if (isset($j[$k])) { echo "  $k : " . $court($j[$k], 300) . "\n"; }
        }
    }
    if ($liste === null) { echo '  aucune liste ; objet : ' . $court($masque($j), $n) . "\n"; return $j; }
    echo "  liste sous « $ou » : " . count($liste) . " éléments\n";
    if ($liste !== []) {
        echo '  clés du 1er : ' . implode(', ', array_keys((array) $liste[0])) . "\n";
        echo '  ' . ($entier ? 'tous' : '1er') . ' : ' . $court($masque($entier ? $liste : $liste[0]), $entier ? 6000 : $n) . "\n";
    }
    return $liste;
};

$decrire('/allergens', 1200, true);
$f = $decrire('/material-suppliers', 600);
$ids = [];
foreach ((array) $f as $x) { if (is_array($x) && (int) ($x['id'] ?? 0) > 0) { $ids[] = (int) $x['id']; } }
echo '  fournisseurs (id) : ' . implode(', ', array_slice($ids, 0, 40)) . "\n";
foreach (array_slice($ids, 0, 2) as $i => $fid) {
    $m = $decrire("/material-suppliers/$fid/raw-materials", 1500);
    if ($i === 0 && is_array($m) && isset($m[0]['id'])) { $decrire("/material-suppliers/$fid/raw-materials/" . (int) $m[0]['id'] . '/allergens', 1200); }
}
$decrire('/materials', 1200);
$decrire('/products', 900);
$decrire('/products/aliases', 900);
echo "\nfin de la sonde (lecture seule)\n";
