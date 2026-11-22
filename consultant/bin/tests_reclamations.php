#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Réclamations fournisseur (21/11/2026) : les photos de la fiche, la part du livré, le tableau annuel réclamé / livré,
 * et la photo obligatoire. Sur une base SQLite en mémoire, sans réseau : le panel est une doublure.
 *
 *   php consultant/bin/tests_reclamations.php
 *
 * Ce qui doit rester vrai : la fiche rend les liens signés des pièces jointes du panel et les photos du dashboard ; la
 * part du livré additionne toutes les réclamations du magasin sur la matière dans la semaine, le mois et l'année, face
 * aux entrées du grand livre liées à une commande ; le tableau annuel range réclamé, accepté (hors refusées) et livré
 * (au prix d'achat, matières du fournisseur seulement) par magasin et par mois, sans mois à venir ; une réclamation
 * sans photo est refusée (422) et rien ne part au panel ; depuis le contrôle, la photo de la tâche est reprise.
 */

set_error_handler(static fn (int $n, string $m): bool => str_contains($m, 'http_response_code'), E_WARNING);

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- doublures : la base, le corps, le journal, le panel ------------------------------------------------------
final class Db
{
    public static ?PDO $p = null;
    public static function pdo(): PDO
    {
        if (!self::$p) {
            self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$p->sqliteCreateFunction('DATE_FORMAT', fn ($d, $f) => $d === null ? null : date(str_replace(['%Y', '%m', '%d'], ['Y', 'm', 'd'], $f), strtotime((string) $d)), 2);
        }
        return self::$p;
    }
    public static function fx(string $q): string
    {
        $q = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
        $q = (string) preg_replace('/,\s*(UNIQUE )?KEY \w+ \([^)]*\)/', '', $q);
        $q = str_replace(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', ')', $q);
        return $q;
    }
    public static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
    public static function rows(string $q, array $a = []): array
    {
        // information_schema → PRAGMA (SQLite) : les colonnes d'une table.
        if (str_contains($q, 'information_schema.COLUMNS')) {
            return array_map(fn ($c) => ['COLUMN_NAME' => $c['name']], self::pdo()->query('PRAGMA table_info(' . preg_replace('/\W/', '', (string) $a[0]) . ')')->fetchAll(PDO::FETCH_ASSOC));
        }
        $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function body(): array { return $GLOBALS['corps'] ?? []; }
$JOURNAL = [];
function journalAdd(string $actor, string $kind, ?string $project, string $message): void { $GLOBALS['JOURNAL'][] = $message; }
function rapBaseUrl(): string { return 'https://cockpit.exemple/consulant_bo'; }

final class PanelApi
{
    public static ?string $lastError = null;
    public static array $complaints = [];
    public static array $posts = [];
    public static array $gets = [];
    public static function configured(): bool { return true; }
    public static function liste(mixed $r): array { return is_array($r) && isset($r['data']) ? $r['data'] : (is_array($r) && array_is_list($r) ? $r : []); }
    public static function get(string $path): mixed
    {
        self::$gets[] = $path;
        if ($path === '/consultant/shops/material-complaints') { return ['shops' => self::$complaints]; }
        if (preg_match('#^/shops/\d+/materials$#', $path)) { return [['id' => 10, 'base_unit_price_net' => 0.8, 'material_category_name' => 'Viennoiserie'],
            ['id' => 11, 'base_unit_price_net' => 2.0, 'id_material_category' => 7], ['id' => 99, 'base_unit_price_net' => 5.0], ['id' => 12, 'base_unit_price_net' => 1.0, 'material_category_name' => 'Viennoiserie']]; }
        if ($path === '/material-categories') { return [['id' => 7, 'name' => 'Pâtisserie']]; }
        if ($path === '/material-suppliers/1/connected-materials') { return [['id' => 10], ['id' => 11]]; }
        return null;
    }
    public static function getParallele(array $paths): array
    {
        $out = [];
        foreach ($paths as $k => $p) { $out[$k] = preg_match('#^/attachments/(\d+)/presigned-url$#', $p, $m) ? ($m[1] === '13' ? null : ['url' => 'https://s3.exemple/pj-' . $m[1] . '.jpg?sig=x']) : null; }
        return $out;
    }
    public static function post(string $path, array $body): array { self::$posts[] = [$path, $body]; return [true, ['inserted_id' => 777]]; }
    public static function shopChecklists(int $s, string $d): array { return [['id' => 5]]; }
    public static function checklistProgress(int $s, int $c, string $d): array { return [['task_id' => 42, 'attachment_id' => 900]]; }
    public static function attachmentUrl(int $id): ?string { return 'https://s3.exemple/tache-' . $id . '.jpg'; }
}

require __DIR__ . '/../../shared/src/magasins.php';
require __DIR__ . '/../../panel/src/reclamations_magasin.php';
require __DIR__ . '/../src/reclamations.php';

// La photo de la tâche : la doublure rend une vraie image (le téléchargement réseau est remplacé).
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$PNG = $PNG . str_repeat("\0", 80);   // au-dessus du minimum de 64 octets ; getimagesize lit l'en-tête

Db::exec('CREATE TABLE shops (id INTEGER PRIMARY KEY, name TEXT, active INT)');
Db::exec("INSERT INTO shops VALUES (3, 'Gosselies', 1), (4, 'Halle', 1)");
Db::exec('CREATE TABLE material_inventory_log (id INTEGER PRIMARY KEY AUTOINCREMENT, id_shop INT, id_material INT, id_order INT NULL,
    change_quantity_in_base_unit REAL, created_at TEXT)');
$liv = static fn (int $s, int $m, ?int $o, float $q, string $le) => Db::exec('INSERT INTO material_inventory_log (id_shop, id_material, id_order, change_quantity_in_base_unit, created_at) VALUES (?,?,?,?,?)', [$s, $m, $o, $q, $le]);
// Gosselies, pain au chocolat (10) : 280 livrés la semaine du 05/10 (lundi), 840 de plus en octobre, 1 000 en mars.
$liv(3, 10, 1, 280, '2026-10-05 06:00:00');
$liv(3, 10, 2, 840, '2026-10-20 06:00:00');
$liv(3, 10, 3, 1000, '2026-03-10 06:00:00');
$liv(3, 10, null, 500, '2026-10-06 06:00:00');    // sans commande (inventaire) : pas du livré
$liv(3, 10, 4, -50, '2026-10-07 06:00:00');       // sortie : pas du livré
$liv(4, 10, 5, 1880, '2026-06-01 06:00:00');      // Halle
$liv(4, 99, 6, 100, '2026-06-01 06:00:00');       // une matière d'un autre fournisseur : hors du livré SLFood

// Les FACTURES (lignes poussées par l'ERP sur les commandes du panel) : packs × pièces par pack, prix net du pack.
Db::exec('CREATE TABLE material_order (id INTEGER PRIMARY KEY, id_shop INT, id_supplier INT, status TEXT, order_date TEXT, delivered_on TEXT)');
Db::exec('CREATE TABLE material_order_item (id INTEGER PRIMARY KEY AUTOINCREMENT, id_order INT, id_material INT, pack_quantity REAL, package_size_in_base_unit REAL, pack_price_net REAL)');
$fac = static function (int $id, int $shop, int $fourn, string $le, array $lignes, string $statut = 'DELIVERED') {
    Db::exec('INSERT INTO material_order VALUES (?,?,?,?,?,?)', [$id, $shop, $fourn, $statut, $le, $le]);
    foreach ($lignes as [$m, $packs, $taille, $prix]) { Db::exec('INSERT INTO material_order_item (id_order, id_material, pack_quantity, package_size_in_base_unit, pack_price_net) VALUES (?,?,?,?,?)', [$id, $m, $packs, $taille, $prix]); }
};
$fac(1, 3, 1, '2026-10-06', [[10, 5, 56, 42.0], [12, 10, 10, 9.0]]);    // semaine 41 : 280 pains au chocolat (et 100 d'un produit jamais réclamé)
$fac(2, 3, 1, '2026-10-21', [[10, 15, 56, 42.0]]);                     // octobre : + 840
$fac(3, 3, 1, '2026-03-11', [[11, 50, 10, 18.0], [10, 1, 56, 42.0]]);   // mars : 500 riz, 56 pains au chocolat
$fac(4, 3, 1, '2026-10-22', [[10, 99, 56, 42.0]], 'CANCELLED');        // annulée : ne compte pas
$fac(5, 4, 1, '2026-06-02', [[10, 20, 56, 42.0], [99, 10, 10, 50.0]]); // Halle : 1 120 pains au chocolat ; la matière 99 est d'un autre fournisseur
$fac(6, 3, 2, '2026-04-02', [[99, 4, 10, 50.0]]);                      // l'autre fournisseur : 40 pièces

PanelApi::$complaints = [
    ['shop_id' => 3, 'complaints' => [
        ['id' => 1, 'id_material' => 10, 'material_name' => 'Pain au Chocolat', 'supplier_sku' => '1002343', 'unit_name' => 'pcs', 'qty' => 56, 'reported_at' => '2026-10-05 08:00:00', 'supplier_name' => 'SLFood', 'id_supplier' => 1,
         'status' => 'REJECTED', 'accepted_qty' => 0, 'reason_code' => 'wrong_quantity_in_package', 'reason_name' => 'Quantité incorrecte de produits dans l’emballage', 'attachments' => [['id' => 12], ['attachment_id' => 13]]],
        ['id' => 2, 'id_material' => 10, 'qty' => 4, 'reported_at' => '2026-10-28 08:00:00', 'supplier_name' => 'SLFood', 'id_supplier' => 1,
         'status' => 'ACCEPTED', 'accepted_qty' => 4, 'reason_code' => 'product_quality', 'reason_name' => 'Problème de qualité du produit', 'attachments' => []],
        ['id' => 3, 'id_material' => 11, 'material_name' => 'Riz Croustillant', 'qty' => 10, 'reported_at' => '2026-03-12 08:00:00', 'supplier_name' => 'SLFood', 'id_supplier' => 1,
         'status' => 'ACCEPTED', 'accepted_qty' => 5, 'attachments' => [14]],
        ['id' => 4, 'id_material' => 99, 'qty' => 1, 'reported_at' => '2026-04-01 08:00:00', 'supplier_name' => 'Autre', 'id_supplier' => 2, 'attachments' => []],
        ['id' => 5, 'id_material' => 10, 'qty' => 9, 'reported_at' => '2025-12-01 08:00:00', 'supplier_name' => 'SLFood', 'id_supplier' => 1, 'attachments' => []],
    ]],
    ['shop_id' => 4, 'complaints' => [
        ['id' => 6, 'id_material' => 10, 'qty' => 25, 'reported_at' => '2026-06-03 08:00:00', 'supplier_name' => 'SLFood', 'id_supplier' => 1,
         'status' => 'ACCEPTED', 'accepted_qty' => 25, 'attachments' => []],
    ]],
];
ensureReclamationPhotos();
Db::exec("INSERT INTO panel_reclamation_photo (reclamation_id, shop_id, chemin, cree_le) VALUES (1, 3, 'uploads/reclamations/3-a.jpg', '2026-10-05 08:00:00')");

echo "Les pièces jointes\n";
verifie('entiers et objets {id | attachment_id}', rcPjIds([12, ['id' => 13], ['attachment_id' => 14], 'x', 0, 12]) === [12, 13, 14]);
verifie('la semaine va du lundi au dimanche', rcPeriodes('2026-10-07')['semaine']['du'] === '2026-10-05' && rcPeriodes('2026-10-07')['semaine']['au'] === '2026-10-11');

echo "La fiche\n";
$_GET = ['shop' => '3', 'id' => '1'];
$f = ep_reclamation_fiche();
$urls = array_column($f['photos'], 'url');
verifie('les liens signés du panel', in_array('https://s3.exemple/pj-12.jpg?sig=x', $urls, true), json_encode($urls));
verifie('un lien non rendu est signalé, pas inventé', count(array_filter($f['photos'], fn ($p) => $p['url'] === null)) === 1);
verifie('la photo du dashboard, en adresse complète', in_array('https://cockpit.exemple/consulant_bo/uploads/reclamations/3-a.jpg', $urls, true));
$P = $f['part'];
verifie('la part se lit sur les factures', $P['source'] === 'factures', (string) $P['source']);
verifie('semaine : 56 réclamés / 280 livrés = 20 %', $P['periodes']['semaine']['reclame'] == 56 && $P['periodes']['semaine']['livre'] == 280 && $P['periodes']['semaine']['part'] == 20.0, json_encode($P['periodes']['semaine']));
verifie('mois : 60 réclamés (deux réclamations) / 1 120 livrés, sans la commande annulée', $P['periodes']['mois']['reclame'] == 60 && $P['periodes']['mois']['livre'] == 1120, json_encode($P['periodes']['mois']));
verifie('année : 1 176 livrés', $P['periodes']['annee']['livre'] == 1176, json_encode($P['periodes']['annee']));
verifie('réseau, année : 85 / 2 296', $P['reseau']['reclame'] == 85 && $P['reseau']['livre'] == 2296, json_encode($P['reseau']));
$_GET = ['shop' => '4', 'id' => '1'];
verifie('une réclamation d’un autre magasin : introuvable', isset(ep_reclamation_fiche()['error']));

echo "Le tableau annuel (produits réclamés, d'après les factures)\n";
$_GET = ['annee' => '2026'];
$A = ep_reclamations_annuel();
verifie('le fournisseur par défaut : le plus réclamé (SLFood)', $A['fournisseur'] === 'SLFood', (string) $A['fournisseur']);
verifie('le livré vient des factures', $A['livreSource'] === 'factures', (string) $A['livreSource'] . ' ' . $A['livreDetail']);
$E = $A['ensemble']['total'];
verifie('ensemble : 4 réclamations, 95 pièces réclamées', $E['n'] === 4 && $E['rq'] == 95, json_encode($E));
verifie('ensemble : livré = produits réclamés seulement (2 296 pains au chocolat + 500 riz), pas le produit jamais réclamé', $E['lq'] == 2796, json_encode($E));
verifie('ensemble : montant livré au prix facturé (packs × prix du pack)', abs($E['livre'] - (41 * 42.0 + 50 * 18.0)) < 0.01, json_encode($E));
$G = array_column($A['magasins'], null, 'nom')['Gosselies'];
verifie('Gosselies, octobre : 48 € réclamés / 840 € facturés (5,71 %)', abs($G['mois'][9]['reclame'] - 48.0) < 0.001 && abs($G['mois'][9]['livre'] - 840.0) < 0.001 && $G['mois'][9]['part'] == 5.71, json_encode($G['mois'][9]));
verifie('l’accepté ne compte pas la réclamation refusée', abs($G['mois'][9]['accepte'] - 3.2) < 0.001, json_encode($G['mois'][9]));
verifie('la réclamation de 2025 n’entre pas', $G['total']['n'] === 3, (string) $G['total']['n']);
$C = array_column($A['categories'], null, 'nom');
verifie('les catégories : Viennoiserie (nom) et Pâtisserie (identifiant)', isset($C['Viennoiserie'], $C['Pâtisserie']), implode(', ', array_keys($C)));
verifie('Viennoiserie : 85 / 2 296 pièces = 3,7 %', $C['Viennoiserie']['total']['rq'] == 85 && $C['Viennoiserie']['total']['lq'] == 2296 && $C['Viennoiserie']['total']['partQ'] == 3.7, json_encode($C['Viennoiserie']['total']));
$Pr = $C['Pâtisserie']['produits'][0] ?? [];
verifie('le produit : Riz Croustillant, 10 / 500 = 2 % en mars', ($Pr['nom'] ?? '') === 'Riz Croustillant' && $Pr['mois'][2]['partQ'] == 2.0, json_encode($Pr['mois'][2] ?? null));
$moisCourant = (int) date('n');
verifie('pas de mois à venir', date('Y') !== '2026' || $moisCourant === 12 || $G['mois'][$moisCourant] === null);
$_GET = ['annee' => '2026', 'shop' => '4'];
$H = ep_reclamations_annuel();
verifie('un magasin : ses seules réclamations et ses seules factures', $H['ensemble']['total']['n'] === 1 && $H['ensemble']['total']['lq'] == 1120, json_encode($H['ensemble']['total']));
$_GET = ['annee' => '2026', 'fournisseur' => 'Autre'];
$A2 = ep_reclamations_annuel();
verifie('un autre fournisseur : ses réclamations, ses factures', $A2['ensemble']['total']['n'] === 1 && $A2['ensemble']['total']['lq'] == 40, json_encode($A2['ensemble']['total']));

echo "Produit ou livraison\n";
verifie('le motif « quantité incorrecte » est un problème de livraison', rcTypeReclamation(['reason_code' => 'wrong_quantity_in_package']) === 'livraison');
verifie('« non-conformité avec la commande » : livraison', rcTypeReclamation(['reason_name' => 'Non-conformité avec la commande']) === 'livraison');
verifie('« qualité » et « taille ou poids » : produit', rcTypeReclamation(['reason_name' => 'Problème de qualité du produit']) === 'produit' && rcTypeReclamation(['reason_name' => 'Problème de taille ou de poids']) === 'produit');
$_GET = ['annee' => '2026'];
$T = ep_reclamations_annuel()['types'];
verifie('les deux comptes : 1 de livraison, 3 de produit', $T['livraison']['n'] === 1 && $T['produit']['n'] === 3, json_encode($T));
$_GET = ['annee' => '2026', 'type' => 'livraison'];
$L = ep_reclamations_annuel();
verifie('le filtre livraison : la seule réclamation de livraison', $L['ensemble']['total']['n'] === 1 && $L['type'] === 'livraison', json_encode($L['ensemble']['total']));
$M = array_column($L['motifs'], null, 'nom');
verifie('la vue Motifs : le motif, son type, son % sur le livré de l’année', isset($M['Quantité incorrecte de produits dans l’emballage']) && $M['Quantité incorrecte de produits dans l’emballage']['type'] === 'livraison'
    && $M['Quantité incorrecte de produits dans l’emballage']['total']['lq'] == $L['ensemble']['total']['lq'], json_encode($L['motifs']));

echo "Sans factures lisibles : le grand livre\n";
Db::exec('ALTER TABLE material_order_item RENAME TO material_order_item_x');
$_GET = ['annee' => '2026'];
$A3 = ep_reclamations_annuel();
verifie('la source le dit', $A3['livreSource'] === 'grand livre', (string) $A3['livreSource']);
verifie('le grand livre : entrées liées à une commande, produits réclamés', $A3['ensemble']['total']['lq'] == 4000, json_encode($A3['ensemble']['total']));
Db::exec('ALTER TABLE material_order_item_x RENAME TO material_order_item');

echo "La photo obligatoire\n";
$base = ['shopId' => 3, 'idMatiere' => 10, 'sku' => '1002343', 'quantite' => 5, 'idLivraison' => 33, 'idUnite' => 1, 'motif' => 'product_quality', 'texte' => 'pâte crue'];
$GLOBALS['corps'] = $base;
PanelApi::$posts = [];
$r = wr_reclamation_creer();
verifie('sans photo : refusée', isset($r['error']) && str_contains($r['error'], 'photo'), json_encode($r));
verifie('sans photo : rien ne part au panel', PanelApi::$posts === []);
$GLOBALS['corps'] = $base + ['photos' => ['data:image/png;base64,' . base64_encode($PNG)]];
$r = wr_reclamation_creer();
verifie('avec une photo : déposée', ($r['ok'] ?? false) === true && count($r['photos']) === 1, json_encode($r));
verifie('le lien de la photo part dans la description', str_contains((string) (PanelApi::$posts[0][1]['description'] ?? ''), 'https://cockpit.exemple/consulant_bo/uploads/reclamations/'));
$poses = $r['photos'] ?? [];

// Depuis le contrôle : la photo de la tâche, relue au panel — jamais une adresse venue du navigateur.
$vu = null;
$bin = rcPhotoTache(3, 42, '2026-11-20', function (string $url) use (&$vu, $PNG) { $vu = $url; return $PNG; });
verifie('la photo de la tâche : la pièce jointe de l’avancement de sa checklist', $vu === 'https://s3.exemple/tache-900.jpg' && $bin === $PNG, (string) $vu);
verifie('une autre tâche, sans pièce jointe : pas de photo', rcPhotoTache(3, 41, '2026-11-20', fn ($u) => $PNG) === null);
$GLOBALS['corps'] = $base + ['photoTache' => ['taskId' => 41, 'date' => '2026-11-20']];
PanelApi::$posts = [];
$r = wr_reclamation_creer();
verifie('contrôle sans photo : refusée, rien ne part', isset($r['error']) && str_contains($r['error'], 'contrôle') && PanelApi::$posts === [], json_encode($r));

foreach ($poses as $c) { @unlink(__DIR__ . '/../../public/' . $c); }
echo "\n" . ($ko ? "$ko échec(s), $ok réussi(s)\n" : "tout passe ($ok)\n");
exit($ko ? 1 : 0);
