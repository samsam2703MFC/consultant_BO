#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'article du Manuel opératoire « Les chiffres clés du réseau 2026 » (src/manuel_chiffres_cles.php) — sans
 * base, sans réseau, sans clé.
 *
 *   php bin/tests_manuel_chiffres.php
 *
 * Chaque cas tourne dans son propre processus (l'article se synchronise une fois par requête) : une base factice tient
 * la ligne de l'article et ses versions, le Catalogue et le réglage des coachs sont des doublures.
 */

if (($argv[1] ?? '') === 'cas') {
    $cas = $argv[2];
    final class Db
    {
        public static ?array $ligne = null;
        public static array $versions = [];
        public static array $ecrit = [];
        public static function row(string $q, array $a = []): ?array
        {
            if (str_contains($q, 'FROM ceo_sop WHERE id = ?')) { return self::$ligne; }
            if (str_contains($q, 'MAX(ordre)')) { return ['o' => 42]; }
            return null;
        }
        public static function rows(string $q, array $a = []): array { return str_contains($q, 'SELECT code FROM ceo_sop') ? [['code' => '0.0.15'], ['code' => '0.0.01']] : []; }
        public static function exec(string $q, array $a = []): int
        {
            if (str_starts_with(trim($q), 'CREATE') || str_starts_with(trim($q), 'ALTER')) { return 0; }
            self::$ecrit[] = trim(substr(trim($q), 0, 40));
            if (str_starts_with(trim($q), 'INSERT INTO ceo_sop (')) { self::$ligne = ['id' => $a[0], 'code' => $a[1], 'type' => $a[2], 'dossier' => $a[3], 'acces' => $a[5], 'statut' => $a[6], 'version' => $a[7], 'trad_nl' => $a[11], 'source' => $a[20]]; }
            if (str_starts_with(trim($q), 'UPDATE ceo_sop SET version')) { self::$ligne['version'] = $a[0]; self::$ligne['source'] = $a[9]; }
            if (str_starts_with(trim($q), 'INSERT INTO ceo_sop_version')) { self::$versions[] = ['version' => $a[1], 'contenu' => json_decode($a[2], true), 'note' => $a[3]]; }
            return 1;
        }
    }
    final class PanelApi { public static function configured(): bool { return false; } public static function get(string $p): mixed { return null; } }
    final class Anthropic { public static function config(): array { return ['cle' => '', 'espace' => '']; } }
    $REGLAGES = [];
    function setting(string $k, mixed $d = null): mixed { global $REGLAGES; return $REGLAGES[$k] ?? $d; }
    function body(): array { return []; }
    function journalAdd(string $a, string $b, ?string $c, string $m, ?string $t = null): void { $GLOBALS['JOURNAL'][] = $m; }
    $GLOBALS['K'] = ['food-cost-pct' => ['seuil_alerte' => $cas === 'change' ? 38.0 : 40.0], 'labour-pct' => ['seuil_alerte' => 17.0],
        'panier-moyen' => ['seuil_alerte' => 13.0], 'articles-ticket' => ['seuil_alerte' => 3.6]];
    function kpiDefs(): array { return $GLOBALS['K']; }
    require __DIR__ . '/../src/assistant_magasin.php';
    require __DIR__ . '/../src/sop.php';
    $empreinte = ['gabarit' => MC_GABARIT, 'chiffres' => ['foodCost' => 40.0, 'personnel' => 17.0, 'clientsScannes' => 25.0, 'menus' => 15.0, 'articles' => 3.6, 'panier' => 13.0]];
    if ($cas === 'pareil' || $cas === 'change') {
        Db::$ligne = ['id' => MC_ID, 'version' => '1.0', 'source' => json_encode(['genere' => 'x', 'empreinte' => $empreinte])];
    }
    if ($cas === 'sansScan') { $REGLAGES['assistantCoachs'] = ['vente' => ['objectifs' => ['foodCost' => 40, 'personnel' => 17, 'clientsScannes' => null, 'menus' => 15, 'articles' => 3.6, 'panier' => 13]]]; }
    $r1 = mcSynchroniser(); $r2 = mcSynchroniser();
    $v = end(Db::$versions) ?: null;
    echo json_encode(['r1' => $r1, 'r2' => $r2, 'ligne' => Db::$ligne, 'ecrit' => Db::$ecrit, 'version' => $v,
        'erreur' => $v ? foContenuErreur(foContenu($v['contenu'])) : null, 'journal' => $GLOBALS['JOURNAL'] ?? []], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$lancer = static fn (string $cas): array => json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' cas ' . escapeshellarg($cas) . ' 2>&1'), true) ?? [];
$titres = static fn (array $R) => array_map(static fn ($e) => $e['titre']['fr'] ?? '', $R['version']['contenu']['sections'][0]['etapes'] ?? []);
// « vaut null » sans le piège de ?? (qui remplace aussi un null) ; les écritures du manuel seules (pas celles de l'assistant).
$nul = static fn (array $R, string $k) => array_key_exists($k, $R) && $R[$k] === null;
$manuel = static fn (array $R) => array_values(array_filter($R['ecrit'] ?? [], static fn ($q) => str_contains($q, 'ceo_sop')));

echo "Première lecture du manuel après la livraison\n";
$R = $lancer('creer');
verifie('l’article est créé, version 1.0, une seule fois', str_starts_with((string) ($R['r1'] ?? ''), 'créé 1.0') && $nul($R, 'r2'), (string) ($R['r1'] ?? ''));
verifie('Article, famille 04, réservé au responsable, publié, NL à relire, code 0.0.02', ($R['ligne']['type'] ?? '') === 'Article' && $R['ligne']['dossier'] === '04' && $R['ligne']['acces'] === 'responsable'
    && $R['ligne']['statut'] === 'publiee' && $R['ligne']['trad_nl'] === 'a_relire' && $R['ligne']['code'] === '0.0.02', json_encode($R['ligne'] ?? null));
verifie('les six chiffres clés, dans l’ordre', $titres($R) === ['Food cost : 40 % du CA au plus', 'Frais de personnel : 17 % du CA au plus', 'Clients scannés : 25 % des tickets au moins',
    'Menus et formules : 15 % du CA au moins', 'Articles par ticket : 3,6 au moins', 'Panier moyen : 13 € au moins'], json_encode($titres($R), JSON_UNESCAPED_UNICODE));
verifie('FR et NL complets (le contrôle de l’éditeur passe)', $nul($R, 'erreur'), (string) ($R['erreur'] ?? 'absent'));
verifie('classé en D7.1 « Pilotage de l’activité », trois écritures du manuel', count(array_filter($manuel($R), static fn ($q) => str_starts_with($q, 'UPDATE ceo_sop SET souscat'))) === 1 && count($manuel($R)) === 3, json_encode($manuel($R)));
verifie('le quiz porte la cible de food cost (40 %) en bonne réponse', ($R['version']['contenu']['quiz'][0]['choix']['fr'][1] ?? '') === '40 %' && ($R['version']['contenu']['quiz'][0]['bonne'] ?? -1) === 1, json_encode($R['version']['contenu']['quiz'][0] ?? null, JSON_UNESCAPED_UNICODE));

echo "Rien n'a changé\n";
$R = $lancer('pareil');
verifie('aucune écriture dans le manuel', $nul($R, 'r1') && $manuel($R) === [], json_encode($manuel($R)));

echo "Le Catalogue passe le food cost à 38 %\n";
$R = $lancer('change');
verifie('version 1.1, la note dit ce qui a changé', ($R['ligne']['version'] ?? '') === '1.1' && ($R['version']['note'] ?? '') === 'Chiffres clés mis à jour : food cost 40 → 38', json_encode($R));
verifie('le texte suit : « Food cost : 38 % du CA au plus »', ($titres($R)[0] ?? '') === 'Food cost : 38 % du CA au plus', json_encode($titres($R), JSON_UNESCAPED_UNICODE));
verifie('au journal', str_contains(implode(' ', $R['journal'] ?? []), 'Version 1.1 publiée'), json_encode($R['journal'] ?? null));

echo "Un objectif du Coach Vente vidé\n";
$R = $lancer('sansScan');
verifie('les clients scannés sortent de l’article, le reste y est', !in_array('Clients scannés : 25 % des tickets au moins', $titres($R), true) && count($titres($R)) === 5 && $nul($R, 'erreur'), json_encode($titres($R), JSON_UNESCAPED_UNICODE));

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
