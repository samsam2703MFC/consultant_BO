<?php
declare(strict_types=1);

/*
 * TRADUCTION — les noms des produits de l'ERP en néerlandais de Belgique (18/11/2026, Sam : « traduire tout le
 * matériel en néerlandais, flamand de Belgique », par PATCH /products/{id}/aliases).
 *
 * La table tools/traductions/produits_nl.tsv (identifiant produit → nom flamand) est déposée dans /tmp du serveur ;
 * ce script l'applique avec les identifiants du serveur, jamais transmis ailleurs :
 *   1. GET /products/aliases?lang_code=nl — la liste complète des produits et leur nom néerlandais actuel ;
 *   2. PATCH /products/{id}/aliases {lang_code: nl, alias, alias_type: default} pour chaque produit sans nom
 *      néerlandais. Un nom néerlandais déjà saisi n'est JAMAIS écrasé : il est affiché à côté de la proposition ;
 *   3. relecture, puis la liste des produits de l'ERP encore sans nom néerlandais (à ajouter à la table).
 * Compte : celui du consultant (src/panel_api.php) ; s'il est refusé, le compte admin ERP (src/erp_api.php).
 * Le code de langue « nl » est celui des traductions déjà présentes dans l'ERP (catégories de produits).
 *
 * Lancé par .github/workflows/traduction-produits-nl.yml (push de traduction-nl.trigger) :
 *   php -- /var/www/consulant_bo /tmp/traductions_produits_nl.tsv < tools/traduire_produits_nl.php
 */
$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
$tsv = (string) ($argv[2] ?? '/tmp/traductions_produits_nl.tsv');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'panel_api.php', 'erp_api.php'] as $f) { require_once "$dir/src/$f"; }

const LANGUE = 'nl';

// La table : une ligne « identifiant<TAB>nom flamand ».
$table = [];
foreach ((array) @file($tsv, FILE_IGNORE_NEW_LINES) as $l) {
    $c = explode("\t", (string) $l, 2);
    if (count($c) === 2 && ctype_digit(trim($c[0])) && trim($c[1]) !== '') { $table[(int) $c[0]] = trim($c[1]); }
}
echo '=== Table de traduction : ', count($table), " produits\n";
if ($table === []) { echo "  table vide ou illisible ($tsv)\n"; exit(1); }

// Les deux comptes, même forme de réponse {code, corps}.
$appels = [
    'consultant' => static fn (string $m, string $p, ?array $b = null): array => PanelApi::sonde($m, $p, $b, $m === 'GET' ? 60 : 20),
    'admin'      => static fn (string $m, string $p, ?array $b = null): array => $m === 'GET' ? ErpApi::sondeGet($p) : ErpApi::ecrire($m, $p, $b),
];
$detail = static function (array $r): string {
    $c = $r['corps'] ?? null;
    $d = is_array($c) ? (string) ($c['description'] ?? $c['message'] ?? $c['error'] ?? '') : (is_string($c) ? $c : '');
    return 'HTTP ' . (int) ($r['code'] ?? 0) . ($d !== '' ? ' : ' . mb_substr($d, 0, 200) : '') . (($r['erreur'] ?? null) && $d === '' ? ' — ' . $r['erreur'] : '');
};
$refus = static fn (array $r): bool => in_array((int) ($r['code'] ?? 0), [0, 401, 403], true);

/** Les noms néerlandais actuels, par produit (variante « default »). */
$lire = static function (callable $appel) use ($refus): array {
    $r = $appel('GET', '/products/aliases?lang_code=' . LANGUE);
    if ((int) ($r['code'] ?? 0) !== 200 || !is_array($r['corps'] ?? null)) { return [null, $r]; }
    $parId = [];
    foreach ($r['corps'] as $l) {
        if (!is_array($l) || !isset($l['fk_id'])) { continue; }
        $type = (string) ($l['alias_type'] ?? '');
        if ($type !== '' && $type !== 'default') { continue; }
        $id = (int) $l['fk_id'];
        $alias = trim((string) ($l['alias_value'] ?? ''));
        if (!isset($parId[$id]) || $alias !== '') { $parId[$id] = ['base' => trim((string) ($l['base_value'] ?? '')), 'nl' => $alias]; }
    }
    return [$parId, $r];
};

echo "=== 1. Lecture des noms néerlandais actuels (GET /products/aliases?lang_code=nl)\n";
$compte = null; $erp = null;
foreach (['consultant', 'admin'] as $nom) {
    if ($nom === 'admin' && !ErpApi::disponible()) { echo "  compte admin ERP : non configuré\n"; continue; }
    [$erp, $r] = $lire($appels[$nom]);
    if ($erp !== null) { $compte = $nom; echo "  compte $nom : ", count($erp), " produits lus\n"; break; }
    echo "  compte $nom refusé : ", $detail($r), "\n";
}
if ($compte === null) { echo "  aucun compte n'a pu lire les traductions — rien n'est écrit\n"; exit(1); }

$aEcrire = []; $dejaLa = []; $inconnus = [];
foreach ($table as $id => $nl) {
    if (!isset($erp[$id])) { $inconnus[] = $id; continue; }
    if ($erp[$id]['nl'] !== '') { $dejaLa[$id] = $nl; continue; }
    $aEcrire[$id] = $nl;
}
printf("  à écrire : %d · déjà en néerlandais (laissés tels quels) : %d · absents de l'ERP : %d\n", count($aEcrire), count($dejaLa), count($inconnus));
foreach ($dejaLa as $id => $nl) {
    if ($erp[$id]['nl'] !== $nl) { printf("    déjà là  %-8d %s → « %s » (proposé : « %s »)\n", $id, $erp[$id]['base'], $erp[$id]['nl'], $nl); }
}
if ($inconnus !== []) { echo '    absents de l’ERP : ', implode(', ', $inconnus), "\n"; }

echo "=== 2. Écriture (PATCH /products/{id}/aliases, compte $compte)\n";
$ok = 0; $echecs = [];
foreach ($aEcrire as $id => $nl) {
    $corps = ['lang_code' => LANGUE, 'alias' => $nl, 'alias_type' => 'default'];
    $r = $appels[$compte]('PATCH', '/products/' . $id . '/aliases', $corps);
    // Le consultant peut lire sans pouvoir écrire : on passe au compte admin, une seule fois.
    if ($refus($r) && $compte === 'consultant' && ErpApi::disponible()) {
        echo '  écriture refusée au compte consultant (', $detail($r), ") — passage au compte admin ERP\n";
        $compte = 'admin';
        $r = $appels[$compte]('PATCH', '/products/' . $id . '/aliases', $corps);
    }
    $code = (int) ($r['code'] ?? 0);
    if ($code >= 200 && $code < 300) { $ok++; continue; }
    $echecs[] = [$id, $detail($r)];
    if ($refus($r)) { echo '  écriture refusée (', $detail($r), ") — arrêt\n"; break; }
}
printf("  écrits : %d / %d · échecs : %d\n", $ok, count($aEcrire), count($echecs));
foreach (array_slice($echecs, 0, 30) as [$id, $d]) { echo "    échec $id : $d\n"; }

echo "=== 3. Relecture\n";
[$apres, $r] = $lire($appels[$compte]);
if ($apres === null) { echo '  relecture impossible : ', $detail($r), "\n"; exit(1); }
$sans = array_filter($apres, static fn (array $p): bool => $p['nl'] === '');
printf("  %d / %d produits ont un nom néerlandais\n", count($apres) - count($sans), count($apres));
$verifies = 0;
foreach ($aEcrire as $id => $nl) { if (($apres[$id]['nl'] ?? '') === $nl) { $verifies++; } }
printf("  noms de la table relus à l'identique : %d / %d\n", $verifies, count($aEcrire));
if ($sans !== []) {
    echo "  produits de l'ERP encore sans nom néerlandais (identifiant<TAB>nom) :\n";
    ksort($sans);
    foreach ($sans as $id => $p) { echo "    SANS\t$id\t", $p['base'], "\n"; }
}
