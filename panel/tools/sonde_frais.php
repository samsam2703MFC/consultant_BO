<?php
declare(strict_types=1);

/*
 * SONDE — les frais d'un magasin au panel, tels qu'ils sont (21/11/2026). Après le premier essai de « Ajouter un
 * frais » (onglet Frais du dashboard) : le libellé saisi (« Comptable ») s'affiche sous le nom de sa catégorie
 * (« Autres charges d'exploitation ») et la TVA choisie ne s'applique pas. La sonde montre la forme réelle des lignes
 * de frais, pour envoyer et relire les bons champs :
 *   - les types de frais (/costs) ;
 *   - les lignes du magasin (/shops/{id}/costs?period=) et celles de l'onglet (/shops/{id}/owner/costs?period=), pour le
 *     mois en cours, le suivant et janvier ;
 *   - ce que le dashboard a créé (panel_frais_ajout) et les choix de TVA du magasin (panel_frais_tva).
 *
 * Lancée sur le serveur du cockpit par `.github/workflows/sonde-frais.yml` :
 *     php -- /var/www/consulant_bo 5 < tools/sonde_frais.php
 *
 * LECTURE SEULE : des GET au panel et des SELECT. Les secrets et les coordonnées sont masqués.
 */

$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
$shop = (int) ($argv[2] ?? 5);
// Le code est rangé par domaine (réorganisation du 19/11/2026) : un module se cherche dans shared/ puis dans chaque domaine.
$src = static fn (string $f): string => (string) (current(glob("$dir/{shared,marketing,formation,consultant,panel}/src/$f", GLOB_BRACE) ?: []) ?: "$dir/shared/src/$f");
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'panel_api.php'] as $f) {
    if (!is_file($src($f))) { fwrite(STDERR, 'fichier absent : ' . $src($f) . "\n"); exit(2); }
    require_once $src($f);
}

const SF_MASQUE = '/pass|secret|token|iban|swift|bic|bank|account_number|e?mail|phone|first_?name|last_?name|surname|street|address|zip|birth|national/i';

function sfTitre(string $t): void { echo "\n=== $t ===\n"; }

/** Une valeur à montrer : secrets et coordonnées masqués, textes coupés, listes coupées à $n éléments. */
function sfNettoie(mixed $v, string $cle = '', int $n = 40, int $prof = 0): mixed
{
    if ($cle !== '' && !is_array($v) && preg_match(SF_MASQUE, $cle)) { return $v === null || $v === '' ? $v : '•••'; }
    if (!is_array($v)) { return is_string($v) && mb_strlen($v) > 120 ? mb_substr($v, 0, 120) . '…' : $v; }
    if ($prof >= 6) { return '[…]'; }
    if (array_is_list($v)) {
        $out = [];
        foreach (array_slice($v, 0, $n) as $x) { $out[] = sfNettoie($x, '', $n, $prof + 1); }
        if (count($v) > $n) { $out[] = '… ' . count($v) . ' éléments en tout'; }
        return $out;
    }
    $out = [];
    foreach ($v as $k => $x) { $out[$k] = sfNettoie($x, (string) $k, $n, $prof + 1); }
    return $out;
}

/** Un GET montré : code, durée, corps nettoyé. */
function sfMontre(string $lib, string $chemin, int $n = 40): mixed
{
    $t = microtime(true);
    $r = PanelApi::sondeGet($chemin, 30);
    printf("\n-- %s  [%s] HTTP %d · %.1f s\n", $lib, $chemin, $r['code'], microtime(true) - $t);
    if ($r['corps'] !== null) { echo json_encode(sfNettoie($r['corps'], '', $n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n"; }
    elseif ($r['erreur']) { echo '   ', $r['erreur'], "\n"; }
    return $r['corps'];
}

sfTitre('compte');
if (!PanelApi::configured()) { echo "  aucun identifiant TF Buddy\n"; exit(1); }
$ce = date('Y-m');
$mois = array_values(array_unique([$ce, date('Y-m', strtotime($ce . '-01 +1 month')), date('Y') . '-01']));
echo "  magasin $shop · mois " . implode(', ', $mois) . "\n";

sfTitre('1. Les types de frais (/costs)');
sfMontre('types', '/costs');

sfTitre('2. Les lignes du magasin (/shops/{id}/costs)');
foreach ($mois as $m) { sfMontre('mois ' . $m, '/shops/' . $shop . '/costs?period=' . $m); }

sfTitre('3. Les lignes de l\'onglet Frais (/shops/{id}/owner/costs)');
foreach ($mois as $m) { sfMontre('mois ' . $m, '/shops/' . $shop . '/owner/costs?period=' . $m); }

sfTitre('4. Ce que le dashboard a créé (panel_frais_ajout) et les choix de TVA (panel_frais_tva)');
foreach ([
    'panel_frais_ajout' => 'SELECT lot, mois, poste, montant, panel_id, cree_le, annule_le FROM panel_frais_ajout WHERE shop_id = ? ORDER BY cree_le DESC, mois LIMIT 60',
    'panel_frais_tva' => 'SELECT poste, libelle, tva_comprise, taux, maj_le FROM panel_frais_tva WHERE shop_id = ? ORDER BY maj_le DESC LIMIT 40',
] as $t => $q) {
    try { $rows = Db::rows($q, [(string) $shop]); echo "\n-- $t : " . count($rows) . " ligne(s)\n"; foreach ($rows as $r) { echo '   ', json_encode($r, JSON_UNESCAPED_UNICODE), "\n"; } }
    catch (Throwable $e) { echo "\n-- $t : illisible (" . $e->getMessage() . ")\n"; }
}
// Les gardes du dashboard : les lignes de frais telles que l'onglet les a lues en dernier.
foreach ($mois as $m) {
    $g = setting('phF:' . $shop . ':' . $m);
    echo "\n-- garde phF:$shop:$m : " . (is_array($g) ? json_encode(['q' => isset($g['q']) ? date('c', (int) $g['q']) : null, 'l' => $g['l'] ?? null], JSON_UNESCAPED_UNICODE) : 'aucune') . "\n";
}
echo "\nfin de la sonde\n";
