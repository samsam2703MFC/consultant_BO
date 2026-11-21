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
$mats = $decrire('/materials', 1200);
$prods = $decrire('/products', 900);
$decrire('/products/aliases', 900);

// 2e passage (21/11) : les allergènes d'une matière s'écrivent par PATCH /materials/{id} {allergens: [ids]} — forme
// d'une fiche matière, et correspondance entre le plan d'envoi et les matières / produits de l'API.
$plan = json_decode((string) @file_get_contents("$dir/consultant/docs/etiquettes-nl/plan_envoi.json"), true);
$norm = static function (string $s): string {
    $s = mb_strtolower(strtr(trim($s), ['Ø' => '⌀', 'ø' => '⌀', '–' => '-', '—' => '-', '’' => "'"]));
    if (class_exists('Normalizer')) { $d = Normalizer::normalize($s, Normalizer::FORM_D); if (is_string($d)) { $s = preg_replace('/\p{Mn}+/u', '', $d) ?? $s; } }
    return trim(preg_replace('/[\s.]+/u', ' ', $s) ?? $s);
};
$parNom = static function ($l) use ($norm): array { $i = []; foreach ((array) $l as $o) { if (is_array($o) && isset($o['id'], $o['name'])) { $i[$norm((string) $o['name'])][] = (int) $o['id']; } } return $i; };
$iMat = $parNom($mats); $iProd = $parNom($prods);
$idsProd = []; foreach ((array) $prods as $o) { if (isset($o['id'])) { $idsProd[(int) $o['id']] = (string) ($o['name'] ?? ''); } }
if (!is_array($plan)) { echo "\nplan d'envoi illisible sur le serveur\n"; exit(0); }

echo "\n=== Allergènes du plan : ingrédient → matière du même nom\n";
$trouve = 0; $absents = []; $exemple = null;
foreach ($plan['allergenes'] as $l) {
    $ids = $iMat[$norm($l['ingredient'])] ?? [];
    if (count($ids) >= 1) { $trouve++; $exemple ??= $ids[0]; if (count($ids) > 1) { echo '  en double : « ' . $l['ingredient'] . ' » → ' . implode(', ', $ids) . "\n"; } }
    else { $absents[] = $l['ingredient']; }
}
echo '  ' . $trouve . ' / ' . count($plan['allergenes']) . " retrouvés\n  absents : " . implode(' | ', array_slice($absents, 0, 40)) . "\n";
if ($exemple !== null) { $decrire("/materials/$exemple", 2500); }

echo "\n=== Traductions du plan : ID ERP = ID produit de l'API ? et noms\n";
$memeId = 0; $memeNom = 0; $matNom = 0; $ex = [];
foreach ($plan['traductions'] as $t) {
    foreach ($t['ids_erp'] as $id) { if (isset($idsProd[$id])) { $memeId++; if (count($ex) < 12) { $ex[] = $id . ' : ERP « ' . $t['noms_erp'][0] . ' » / API « ' . $idsProd[$id] . ' »'; } } }
    foreach ($t['noms_erp'] as $n) { if (isset($iProd[$norm($n)])) { $memeNom++; break; } }
    foreach ($t['noms_erp'] as $n) { if (isset($iMat[$norm($n)])) { $matNom++; break; } }
}
echo "  ID ERP présents parmi les produits de l'API : $memeId / " . count($plan['traductions']) . "\n  " . implode("\n  ", $ex) . "\n";
echo "  nom ERP = nom d'un produit de l'API : $memeNom ; = nom d'une matière : $matNom (sur " . count($plan['traductions']) . ")\n";
$idsPlanTous = []; foreach ($plan['traductions'] as $t) { foreach ($t['ids_erp'] as $id) { $idsPlanTous[] = $id; } }
echo '  fourchette des ID produits API : ' . min(array_keys($idsProd)) . ' – ' . max(array_keys($idsProd)) . "\n";
echo "\nfin de la sonde (lecture seule)\n";
