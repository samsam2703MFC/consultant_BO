<?php
declare(strict_types=1);

/*
 * TRADUCTION — les noms de l'ERP en néerlandais de Belgique (18/11/2026, Sam : « traduire tout le matériel en
 * néerlandais, flamand de Belgique », par les routes /{ressource}/{id}/aliases ; « ok pour écrire, fais le tout »).
 *
 * Le dossier tools/traductions/ (une table « identifiant<TAB>nom flamand » par liste) est déposé dans /tmp du
 * serveur ; ce script l'applique avec les identifiants du serveur, jamais transmis ailleurs. Pour chaque liste :
 *   1. GET /{ressource}/aliases?lang_code=nl — tous les éléments et leur nom néerlandais actuel ;
 *   2. PATCH /{ressource}/{id}/aliases {lang_code: nl, alias, alias_type: default} pour chaque élément de la table
 *      sans nom néerlandais. Un nom néerlandais déjà saisi n'est JAMAIS écrasé (sauf un bouche-trou sans lettre,
 *      « ??? ») : il est affiché à côté de la proposition ;
 *   3. relecture, puis les éléments encore sans nom néerlandais (lignes SANS, à traduire au passage suivant).
 * Une table vide ne fait que lister. Compte : celui du consultant (src/panel_api.php) ; s'il est refusé, le compte
 * admin ERP (src/erp_api.php). Le code de langue « nl » est celui des traductions déjà présentes dans l'ERP.
 *
 * Lancé par .github/workflows/traduction-produits-nl.yml (push de traduction-nl.trigger) :
 *   php -- /var/www/consulant_bo /tmp/traductions_nl < tools/traduire_produits_nl.php
 */
$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
$tables = rtrim((string) ($argv[2] ?? '/tmp/traductions_nl'), '/');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'panel_api.php', 'erp_api.php'] as $f) { require_once "$dir/src/$f"; }

const LANGUE = 'nl';

// Les listes traduites : libellé, ressource de l'API, table.
$listes = [
    ['produits',                 '/products',                'produits_nl.tsv'],
    ['catégories de produits',   '/product-categories',      'categories_produits_nl.tsv'],
    ['groupes de catégories',    '/product-category-groups', 'groupes_categories_nl.tsv'],
    ['matières premières',       '/materials',               'matieres_nl.tsv'],
    ['catégories de matières',   '/material-categories',     'categories_matieres_nl.tsv'],
];

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
// « ??? » et autres bouche-trous sans lettre ne comptent pas comme une traduction.
$traduit = static fn (string $s): bool => preg_match('/\p{L}/u', $s) === 1;

/** Les noms néerlandais actuels d'une liste, par identifiant (variante « default »). */
$lire = static function (callable $appel, string $res): array {
    $r = $appel('GET', $res . '/aliases?lang_code=' . LANGUE);
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

$adminDispo = ErpApi::disponible();
$bilan = [];
foreach ($listes as [$libelle, $res, $fichier]) {
    echo "\n######## ", mb_strtoupper($libelle), " ($res)\n";
    $table = [];
    foreach ((array) @file("$tables/$fichier", FILE_IGNORE_NEW_LINES) as $l) {
        $c = explode("\t", (string) $l, 2);
        if (count($c) === 2 && ctype_digit(trim($c[0])) && trim($c[1]) !== '') { $table[(int) $c[0]] = trim($c[1]); }
    }
    echo '=== Table : ', count($table), " noms ($fichier)\n";

    echo "=== 1. Lecture (GET $res/aliases?lang_code=nl)\n";
    $compte = null; $erp = null;
    foreach (['consultant', 'admin'] as $nom) {
        if ($nom === 'admin' && !$adminDispo) { echo "  compte admin ERP : non configuré\n"; continue; }
        [$erp, $r] = $lire($appels[$nom], $res);
        if ($erp !== null) { $compte = $nom; echo "  compte $nom : ", count($erp), " éléments lus\n"; break; }
        echo "  compte $nom refusé : ", $detail($r), "\n";
    }
    if ($compte === null) { echo "  aucun compte n'a pu lire cette liste — rien n'est écrit\n"; $bilan[] = "$libelle : illisible"; continue; }

    $aEcrire = []; $dejaLa = []; $inconnus = [];
    foreach ($table as $id => $nl) {
        if (!isset($erp[$id])) { $inconnus[] = $id; continue; }
        if ($traduit($erp[$id]['nl'])) { $dejaLa[$id] = $nl; continue; }
        $aEcrire[$id] = $nl;
    }
    printf("  à écrire : %d · déjà en néerlandais (laissés tels quels) : %d · absents de l'ERP : %d\n", count($aEcrire), count($dejaLa), count($inconnus));
    foreach ($dejaLa as $id => $nl) {
        if ($erp[$id]['nl'] !== $nl) { printf("    déjà là  %-8d %s → « %s » (proposé : « %s »)\n", $id, $erp[$id]['base'], $erp[$id]['nl'], $nl); }
    }
    if ($inconnus !== []) { echo '    absents de l’ERP : ', implode(', ', $inconnus), "\n"; }

    $ok = 0; $echecs = [];
    if ($aEcrire !== []) {
        echo "=== 2. Écriture (PATCH $res/{id}/aliases, compte $compte)\n";
        foreach ($aEcrire as $id => $nl) {
            $corps = ['lang_code' => LANGUE, 'alias' => $nl, 'alias_type' => 'default'];
            $r = $appels[$compte]('PATCH', "$res/$id/aliases", $corps);
            // Le consultant peut lire sans pouvoir écrire : on passe au compte admin, une seule fois.
            if ($refus($r) && $compte === 'consultant' && $adminDispo) {
                echo '  écriture refusée au compte consultant (', $detail($r), ") — passage au compte admin ERP\n";
                $compte = 'admin';
                $r = $appels[$compte]('PATCH', "$res/$id/aliases", $corps);
            }
            $code = (int) ($r['code'] ?? 0);
            if ($code >= 200 && $code < 300) { $ok++; continue; }
            $echecs[] = [$id, $detail($r)];
            if ($refus($r)) { echo '  écriture refusée (', $detail($r), ") — arrêt de cette liste\n"; break; }
        }
        printf("  écrits : %d / %d · échecs : %d\n", $ok, count($aEcrire), count($echecs));
        foreach (array_slice($echecs, 0, 30) as [$id, $d]) { echo "    échec $id : $d\n"; }
    }

    echo "=== 3. Relecture\n";
    [$apres, $r] = $lire($appels[$compte], $res);
    if ($apres === null) { echo '  relecture impossible : ', $detail($r), "\n"; $bilan[] = "$libelle : relecture impossible"; continue; }
    $sans = array_filter($apres, static fn (array $p): bool => !$traduit($p['nl']));
    $verifies = 0;
    foreach ($aEcrire as $id => $nl) { if (($apres[$id]['nl'] ?? '') === $nl) { $verifies++; } }
    printf("  %d / %d ont un nom néerlandais · écrits ce passage et relus à l'identique : %d / %d\n",
        count($apres) - count($sans), count($apres), $verifies, count($aEcrire));
    $bilan[] = sprintf('%s : %d / %d en néerlandais (%d écrits ce passage, %d échecs)', $libelle, count($apres) - count($sans), count($apres), $ok, count($echecs));
    if ($sans !== []) {
        echo "  encore sans nom néerlandais (SANS<TAB>ressource<TAB>identifiant<TAB>nom) :\n";
        ksort($sans);
        foreach ($sans as $id => $p) { echo "    SANS\t$res\t$id\t", $p['base'], "\n"; }
    }
}

echo "\n######## BILAN\n";
foreach ($bilan as $l) { echo "  $l\n"; }
