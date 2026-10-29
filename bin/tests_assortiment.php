#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'assortiment obligatoire — sans base, sans réseau.
 *
 *   php bin/tests_assortiment.php
 *
 * « Nécessaire » fondu dans « Obligatoire » (18/10/2026) : une référence cochée « nécessaire » devient obligatoire
 * toute l'année (une obligatoire de saison reste ce qu'elle est), la marque « nécessaire » s'efface, une fois pour toutes.
 */

// --- doublures : pas de base ------------------------------------------------------------------
final class Db
{
    public static array $necessaires = [];
    public static array $produits = [];     // ref => ['must' => 0|1, 'saison_id' => ?int]
    public static array $ecrit = [];
    public static function rows(string $sql, array $a = []): array
    {
        if (str_contains($sql, 'FROM ceo_prod_revue WHERE necessaire = 1')) { return array_map(static fn ($r) => ['id_produit' => $r], self::$necessaires); }
        if (str_contains($sql, 'FROM product p')) { return [['name' => 'Produit ' . $a[0], 'cat' => 'Pâtisserie']]; }
        return [];
    }
    public static function row(string $sql, array $a = []): ?array
    {
        if (str_contains($sql, 'SELECT must FROM ceo_prod_product')) { return isset(self::$produits[$a[0]]) ? ['must' => self::$produits[$a[0]]['must']] : null; }
        return null;
    }
    public static function exec(string $sql, array $a = []): void
    {
        self::$ecrit[] = [preg_replace('/\s+/', ' ', trim($sql)), $a];
        if (str_starts_with(trim($sql), 'INSERT INTO ceo_prod_product')) {
            $ref = $a[0];
            self::$produits[$ref] = ['must' => 1, 'saison_id' => null];
        }
        if (str_starts_with($sql, 'UPDATE ceo_prod_revue SET necessaire = 0')) { self::$necessaires = array_values(array_diff(self::$necessaires, [$a[0]])); }
    }
}
$GLOBALS['journal'] = [];
function journalAdd(string $qui, string $type, string $objet, string $quoi): void { $GLOBALS['journal'][] = [$type, $objet, $quoi]; }
function setting(string $k, mixed $d = null): mixed { return $d; }
function body(): array { return []; }

require __DIR__ . '/../src/assortiment.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "« Nécessaire » fondu dans « Obligatoire »\n";
Db::$necessaires = ['1610004', '1610007', '1610020', 'pas une ref !'];
Db::$produits = ['1610007' => ['must' => 1, 'saison_id' => 12], '1610020' => ['must' => 0, 'saison_id' => 3]];
$n = aoFusionNecessaire();
$inserts = array_values(array_filter(Db::$ecrit, static fn ($e) => str_starts_with($e[0], 'INSERT INTO ceo_prod_product')));
verifie('deux références deviennent obligatoires (l’absente du catalogue de production et la non obligatoire)', $n === 2 && count($inserts) === 2
    && array_column($inserts, 1)[0][0] === '1610004' && array_column($inserts, 1)[1][0] === '1610020', json_encode([$n, $inserts], JSON_UNESCAPED_UNICODE));
verifie('toute l’année, sans minimum : la saison d’une non obligatoire est effacée', str_contains($inserts[0][0], 'VALUES (?,?,?,?,1,0,NULL,1)') && str_contains($inserts[0][0], 'ON DUPLICATE KEY UPDATE saison_id = NULL, must = 1'));
verifie('une obligatoire de saison reste ce qu’elle est', Db::$produits['1610007'] === ['must' => 1, 'saison_id' => 12]);
verifie('la marque « nécessaire » s’efface partout, la référence illisible ignorée', Db::$necessaires === ['pas une ref !'], json_encode(Db::$necessaires));
verifie('chaque passage noté au journal, avec le nom du produit', count($GLOBALS['journal']) === 2 && $GLOBALS['journal'][0][1] === 'Produit 1610004' && str_contains($GLOBALS['journal'][0][2], 'toute l’année'), json_encode($GLOBALS['journal'], JSON_UNESCAPED_UNICODE));
verifie('les lignes de revue vides nettoyées', count(array_filter(Db::$ecrit, static fn ($e) => str_starts_with($e[0], 'DELETE FROM ceo_prod_revue WHERE note IS NULL AND necessaire = 0'))) === 1);
Db::$necessaires = []; Db::$ecrit = []; $GLOBALS['journal'] = [];
verifie('une fois pour toutes : sans « nécessaire », rien n’est écrit', aoFusionNecessaire() === 0 && Db::$ecrit === [] && $GLOBALS['journal'] === []);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
