#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des promotions du magasin (/exploitation/promos-magasin) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_promos.php
 *
 * Les prix et coûts de recette du magasin sont une doublure de /analyse/produits/coefficients ;
 * les réglages vivent en mémoire. On vérifie le coefficient du lot (le cas le plus serré, la pièce
 * offerte la moins chère), les règles du magasin (une fin, 14 jours, deux à la fois, ×2) et le
 * parcours d'une demande : brouillon, envoi, décision de la marque, retrait.
 */

// --- doublures : pas de base, pas de réseau ------------------------------------------------
final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void
    {
        if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { $GLOBALS['reglages'][$a[0]] = json_decode($a[1], true); }
    }
}
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function body(): array { return $GLOBALS['corps']; }
// Les produits du magasin 4 : prix HT, coût de recette, TVA.
function ep_analyse_produits_coefficients(): array
{
    $sid = $_GET['shop'] ?? '';
    // Le magasin 5 vend le pain au chocolat moins cher : 1,40 € HT.
    if ($sid === '5') { return ['produits' => [['id' => 2, 'nom' => 'Pain au chocolat', 'catId' => 10, 'prixHt' => 1.40, 'cout' => 0.60, 'tva' => 6]]]; }
    if ($sid !== '4') { return ['produits' => []]; }
    return ['produits' => [
        ['id' => 1, 'nom' => 'Croissant', 'catId' => 10, 'prixHt' => 2.00, 'cout' => 0.50, 'tva' => 6],
        ['id' => 2, 'nom' => 'Pain au chocolat', 'catId' => 10, 'prixHt' => 1.50, 'cout' => 0.60, 'tva' => 6],
        ['id' => 3, 'nom' => 'Tarte sans fiche', 'catId' => 20, 'prixHt' => 3.00, 'cout' => null, 'tva' => 6],
        ['id' => 4, 'nom' => 'Éclair', 'catId' => 20, 'prixHt' => 4.00, 'cout' => 1.00, 'tva' => 6],
        // Pain : pas de coût calculé pour le magasin (le panel rend 0), le réseau en a un.
        ['id' => 6, 'nom' => 'Pain', 'catId' => 30, 'prixHt' => 2.50, 'cout' => 0, 'tva' => 6],
        ['id' => 7, 'nom' => 'Baguette', 'catId' => 30, 'prixHt' => 1.50, 'cout' => null, 'tva' => 6],
        ['id' => 8, 'nom' => 'Sans prix', 'catId' => 30, 'prixHt' => null, 'cout' => 0.30, 'tva' => 6],
    ]];
}

// Le panel, en doublure : il garde les promotions écrites et les rend relues comme sa fiche (17/10/2026).
final class ErpApi
{
    public static bool $actif = false;
    public static ?string $lastError = null;
    public static array $promos = [];
    public static array $appels = [];
    public static ?string $refus = null;
    public static bool $perdCreneaux = false;
    public static bool $sansPut = false;
    public static bool $perdPortion = false;
    public static int $suivant = 30;
    public static ?string $formatHeure = null;   // 'H:i' : le panel refuse l'heure des créneaux avec secondes
    public static function disponible(): bool { return self::$actif; }
    public static function ecrire(string $m, string $path, ?array $b = null): array
    {
        self::$appels[] = $m . ' ' . $path;
        $ok = static fn ($c = []) => ['code' => 200, 'corps' => $c, 'erreur' => null];
        if (self::$formatHeure !== null && in_array($m, ['POST', 'PUT'], true)) {
            foreach ((array) ($b['schedule'] ?? []) as $i => $c) {
                if (preg_match(self::$formatHeure === 'H:i' ? '/^\d\d:\d\d$/' : '/^\d\d:\d\d:\d\d$/', (string) ($c['time_from'] ?? '')) !== 1) {
                    return ['code' => 422, 'corps' => null, 'erreur' => "HTTP 422 : INVALID_PROMOTION_DATA — schedule.$i.time_from : The schedule.$i.time_from field must match the format " . self::$formatHeure . '.'];
                }
            }
        }
        if ($m === 'POST' && $path === '/admin/promotions/buy-x-get-y') {
            if (self::$refus !== null) { return ['code' => 422, 'corps' => null, 'erreur' => self::$refus]; }
            $id = self::$suivant++;
            self::$promos[$id] = self::fiche($id, $b);
            return $ok(['data' => ['id' => $id]]);
        }
        if (preg_match('#^/admin/promotions/buy-x-get-y/(\d+)$#', $path, $x) && isset(self::$promos[(int) $x[1]])) {
            $id = (int) $x[1];
            if ($m === 'PUT') {
                if (self::$sansPut) { return ['code' => 405, 'corps' => null, 'erreur' => 'HTTP 405']; }
                self::$promos[$id] = self::fiche($id, $b);
                return $ok();
            }
            if ($m === 'PATCH') { self::$promos[$id]['status'] = $b['status']; return $ok(); }
        }
        return ['code' => 404, 'corps' => null, 'erreur' => 'HTTP 404'];
    }
    public static function get(string $path): mixed
    {
        return preg_match('#^/admin/promotions/buy-x-get-y/(\d+)$#', $path, $x) ? (self::$promos[(int) $x[1]] ?? null) : null;
    }
    private static function fiche(int $id, array $b): array
    {
        $f = ['id' => $id] + $b;
        if (self::$perdCreneaux) { $f['schedule'] = []; }
        if (self::$perdPortion) { foreach (['trigger', 'reward'] as $k) { foreach (['category_targets', 'product_targets'] as $c) { if (isset($f[$k][$c])) { $f[$k][$c] = array_map(static fn ($t) => is_array($t) ? array_diff_key($t, ['portion_scope' => 1]) : $t, $f[$k][$c]); } } } }
        return $f;
    }
}

// Le coût du réseau (la moyenne des magasins du compte, sinon la recette locale) : le pain seulement.
function catalogueCouts(): array { return [6 => ['mat' => 0.50, 'source' => 'panel (products/available)'], 4 => ['mat' => 9.99]]; }

require __DIR__ . '/../src/promos_magasin.php';
require __DIR__ . '/../src/promos_ventes.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$P = pmProduits(4);
$pp = array_column($P, null, 'id');
verifie('coût du magasin gardé, même quand le réseau en a un autre', $pp[4]['cout'] === 1.0 && $pp[4]['source'] === 'magasin');
verifie('coût à 0 au magasin : celui du réseau, marqué', $pp[6]['cout'] === 0.5 && $pp[6]['source'] === 'reseau', json_encode($pp[6]));
verifie('ni magasin ni réseau : pas de coût', $pp[7]['cout'] === null && $pp[7]['source'] === null && $pp[3]['cout'] === null);
$_GET = ['shop' => '4']; $r = ep_promos_magasin_produits(); $_GET = [];
verifie('la liste de l’assistant compte les sources', $r['nMagasin'] === 4 && $r['nReseau'] === 1 && $r['nSansCout'] === 2, json_encode(array_diff_key($r, ['produits' => 1])));
$cote = static fn (int $q, array $cats = [], array $prods = [], array $ex = []) => ['q' => $q, 'cats' => array_map(static fn ($i) => ['id' => $i, 'nom' => ''], $cats),
    'prods' => array_map(static fn ($i) => ['id' => $i, 'nom' => ''], $prods), 'exclus' => array_map(static fn ($i) => ['id' => $i, 'nom' => ''], $ex)];

echo "Coefficient du lot\n";
$y = $cote(1, [10]) + ['type' => 'FREE', 'valeur' => null];
$c = pmCoef($P, $cote(2, [10]), $y);
// Le cas le plus serré : 3 pains au chocolat, le 3e offert — 3,00 € HT payés pour 1,80 € de matière.
verifie('2 achetés + 1 offert dans la catégorie : le cas le plus serré', $c['lot'] === 1.67 && $c['x'] === 'Pain au chocolat' && $c['y'] === 'Pain au chocolat', json_encode($c));
verifie('sans la promotion, le même lot', $c['sans'] === 2.5);
verifie('la paire impossible en caisse écartée (un croissant offert contre un pain au chocolat acheté)', $c['paires'] === 3, (string) $c['paires']);
$c = pmCoef($P, $cote(2, [10], [], [2]), $y);
verifie('le produit exclu ne compte plus', $c['lot'] === 2.5 && $c['nX'] === 1 && $c['nY'] === 2, json_encode($c));
$c = pmCoef($P, $cote(1, [], [1]), $cote(1, [], [4]) + ['type' => 'FIXED_PRICE', 'valeur' => 2.12]);
verifie('prix fixe saisi TTC, ramené HT (2,12 € → 2,00 €)', $c['lot'] === 2.67, json_encode($c));
$c = pmCoef($P, $cote(1, [], [1]), $cote(1, [], [4]) + ['type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 50]);
verifie('remise de 50 %', $c['lot'] === 2.67);
$c = pmCoef($P, $cote(1, [], [1]), $cote(1, [], [4]) + ['type' => 'FIXED_AMOUNT_DISCOUNT', 'valeur' => 1.06]);
verifie('remise de 1,06 € TTC', $c['lot'] === 3.33, json_encode($c));
$c = pmCoef($P, $cote(1, [20]), $cote(1, [20]) + ['type' => 'FREE', 'valeur' => null]);
verifie('un produit sans coût de recette nommé, pas chiffré', $c['sansCout'] === ['Tarte sans fiche'] && $c['lot'] === 2.0, json_encode($c));
$c = pmCoef($P, $cote(2, [30]), $cote(1, [30]) + ['type' => 'FREE', 'valeur' => null]);
// Le pain, chiffré au réseau : 3 pains, le 3e offert — 5,00 € HT pour 1,50 € de matière.
verifie('chiffré au coût du réseau, nommé ; sans coût et sans prix nommés à part', $c['lot'] === 3.33 && $c['coutReseau'] === ['Pain'] && $c['sansCout'] === ['Baguette'] && $c['sansPrix'] === ['Sans prix'], json_encode($c, JSON_UNESCAPED_UNICODE));
$c = pmCoef($P, $cote(1, [], [7]), $cote(1, [], [8]) + ['type' => 'FREE', 'valeur' => null]);
verifie('rien de chiffrable : la raison dit quels produits et pourquoi', $c['lot'] === null && pmManqueTxt($c) === 'sans coût de recette au panel, ni pour le magasin ni pour le réseau — Baguette ; sans prix au panel — Sans prix', pmManqueTxt($c));
$c = pmCoef($P, $cote(1, [], [3]), $cote(1, [], [3]) + ['type' => 'FREE', 'valeur' => null]);
verifie('rien de chiffrable : pas de coefficient', $c['lot'] === null && $c['paires'] === 0);

echo "Les règles du magasin\n";
$now = date('Y-m-d H:i');
$j = static fn (int $n, string $h = '00:00') => date('Y-m-d', strtotime("+$n day")) . ' ' . $h;
$base = pmPropre(['nom' => 'Viennoiseries 2+1', 'x' => ['q' => 2, 'cats' => [['id' => 10]]], 'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 10]]], 'du' => $j(1), 'au' => $j(7, '23:59')], 4);
$coefOk = ['lot' => 2.4, 'x' => 'a', 'y' => 'b', 'paires' => 1, 'nX' => 1, 'nY' => 1];
verifie('une demande complète passe', pmErreurs($base, [], $coefOk, $now) === [], json_encode(pmErreurs($base, [], $coefOk, $now), JSON_UNESCAPED_UNICODE));
verifie('sans fin : refusée', count(pmErreurs(['au' => null] + $base, [], $coefOk, $now)) === 1);
verifie('15 jours : acceptée (plus de limite à 14 jours, 18/10/2026)', pmErreurs(['au' => $j(15)] + $base, [], $coefOk, $now) === []);
verifie('un an (366 jours) : acceptée', pmErreurs(['au' => $j(366, '23:59')] + $base, [], $coefOk, $now) === []);
$e = pmErreurs(['au' => $j(367)] + $base, [], $coefOk, $now);
verifie('367 jours : refusée, la durée dite', count($e) === 1 && str_contains($e[0], '367 jours') && str_contains($e[0], 'un an'), json_encode($e, JSON_UNESCAPED_UNICODE));
$autres = [['shop' => 4, 'nom' => 'A', 'statut' => 'validee', 'du' => $j(0), 'au' => $j(3)], ['shop' => 4, 'nom' => 'B', 'statut' => 'a_valider', 'du' => $j(2), 'au' => $j(9)], ['shop' => 4, 'nom' => 'C', 'statut' => 'brouillon', 'du' => $j(1), 'au' => $j(5)],
    ['shop' => 7, 'nom' => 'D', 'statut' => 'validee', 'du' => $j(1), 'au' => $j(5)]];
$e = pmErreurs($base, $autres, $coefOk, $now);
verifie('deux autres en même temps : refusée, les deux nommées (pas le brouillon)', count($e) === 1 && str_contains($e[0], '« A », « B »'), json_encode($e, JSON_UNESCAPED_UNICODE));
verifie('une seule autre en même temps : acceptée (celle d’un autre magasin ne compte pas)', pmErreurs($base, array_slice($autres, 1), $coefOk, $now) === []);
$autres[] = ['shop' => 9, 'shops' => [9, 4], 'nom' => 'E', 'statut' => 'validee', 'du' => $j(1), 'au' => $j(2)];
verifie('une promotion d’un autre magasin qui couvre aussi celui-ci compte', count(pmErreurs($base, array_slice($autres, 1), $coefOk, $now)) === 1);
$e = pmErreurs(['shops' => [4, 7]] + $base, [$autres[1], $autres[3], ['shop' => 7, 'nom' => 'F', 'statut' => 'a_valider', 'du' => $j(1), 'au' => $j(3)]], $coefOk, $now);
verifie('deux magasins : la règle des deux à la fois tenue magasin par magasin', count($e) === 1 && str_contains($e[0], '« D », « F »'), json_encode($e, JSON_UNESCAPED_UNICODE));
$d = pmPropre(['shops' => [5, 4, 6, 7, 8, 9]], 4);
verifie('le magasin qui écrit, lui seul : les autres magasins envoyés sont ignorés', $d['shops'] === [4]);
$e = pmErreurs($base, [], ['lot' => 1.67, 'x' => 'Pain au chocolat', 'y' => 'Pain au chocolat'] + $coefOk, $now);
verifie('coefficient sous ×2 : plus de refus, il ne bloque plus (18/10/2026)', $e === [], json_encode($e, JSON_UNESCAPED_UNICODE));
$e = pmErreurs(pmPropre(['y' => ['type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 120]], 4), [], null, $now);
verifie('vide : nom, X, Y, valeur, dates demandés', count($e) === 6, json_encode($e, JSON_UNESCAPED_UNICODE));
$d = pmPropre(['creneaux' => [['jours' => [5, 1, 9], 'de' => '07:00', 'a' => '10:00'], ['jours' => [2], 'de' => '12:00', 'a' => '11:00']], 'maxParTicket' => '3', 'du' => '2026-10-20T08:30'], 4);
verifie('créneaux nettoyés (jours triés, 9 et le créneau à l’envers écartés)', $d['creneaux'] === [['jours' => [1, 5], 'de' => '07:00', 'a' => '10:00']], json_encode($d['creneaux']));
verifie('date du navigateur relue, nombre max par ticket', $d['du'] === '2026-10-20 08:30' && $d['maxParTicket'] === 3);

echo "Le parcours d'une demande\n";
$GLOBALS['reglages'] = [];
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'brouillon', 'nom' => 'Pains au chocolat 2+1', 'x' => ['q' => 2, 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]], 'y' => ['q' => 1, 'type' => 'FREE', 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]]];
$r = wr_promos_magasin();
verifie('un brouillon s’enregistre même incomplet, coefficient calculé', ($r['demande']['statut'] ?? '') === 'brouillon' && $r['demande']['coef']['lot'] === 1.67, json_encode($r));
$id = $r['demande']['id'];
$GLOBALS['corps'] = ['id' => $id, 'action' => 'envoyer', 'du' => $j(1), 'au' => $j(5)] + $GLOBALS['corps'];
$r = @wr_promos_magasin();
verifie('l’envoi d’un lot à ×1,67 : enregistré quand même, le coefficient gardé', ($r['demande']['statut'] ?? '') === 'validee' && $r['demande']['coef']['lot'] === 1.67 && $r['demande']['auto'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$GLOBALS['corps']['y'] = ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 30, 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]];
$GLOBALS['corps']['x'] = ['q' => 3, 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]];
$r = wr_promos_magasin();
verifie('3 achetés + le 4e à −30 % (×2,31, tout chiffré) : enregistrée aussitôt, sans validation', ($r['demande']['statut'] ?? '') === 'validee' && $r['demande']['auto'] === true && $r['demande']['id'] === $id && $r['demande']['coef']['lot'] >= 2 && $r['demande']['motif'] === null, json_encode($r['demande'] ?? $r, JSON_UNESCAPED_UNICODE));
verifie('une seule demande gardée', count(pmLire()) === 1);
$_GET = ['shop' => '4'];
$l = ep_promos_magasin_demandes();
verifie('la liste du magasin la rend, avec les règles', count($l['demandes']) === 1 && $l['regles']['coefMin'] === 2.0);
$_GET = ['shop' => '5']; $l = ep_promos_magasin_demandes();
verifie('un autre magasin ne la voit pas', $l['demandes'] === []);
$_GET = [];
$GLOBALS['corps'] = ['shop' => 4, 'id' => $id, 'action' => 'brouillon', 'nom' => 'Pains au chocolat 3+1'] + $GLOBALS['corps'];
$r = wr_promos_magasin();
verifie('enregistrée, pas encore en caisse : le magasin la reprend (en brouillon)', ($r['demande']['statut'] ?? '') === 'brouillon' && $r['demande']['nom'] === 'Pains au chocolat 3+1', json_encode($r, JSON_UNESCAPED_UNICODE));
$GLOBALS['corps']['action'] = 'envoyer';
$r = wr_promos_magasin();
verifie('et la renvoie : enregistrée de nouveau', ($r['demande']['statut'] ?? '') === 'validee');
// Un produit sans coût (la tarte) : le coefficient ne se vérifie pas — enregistrée quand même, le motif nomme la tarte.
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Tarte 1+1', 'du' => $j(1), 'au' => $j(5),
    'x' => ['q' => 1, 'prods' => [['id' => 3]]], 'y' => ['q' => 1, 'type' => 'FREE', 'prods' => [['id' => 3]]]];
$r = @wr_promos_magasin();
verifie('rien ne se chiffre (la tarte seule, sans coût) : refusée, la tarte nommée', ($r['error'] ?? '') === 'à compléter' && count($r['erreurs']) === 1 && str_contains($r['erreurs'][0], 'ne se chiffre') && str_contains($r['erreurs'][0], 'Tarte sans fiche'), json_encode($r, JSON_UNESCAPED_UNICODE));
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Pâtisserie 1+1', 'du' => $j(11), 'au' => $j(13),
    'x' => ['q' => 1, 'cats' => [['id' => 20]]], 'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 20]]]];
$r = @wr_promos_magasin();
$id2 = $r['demande']['id'] ?? '';
verifie('la catégorie : la tarte sans coût sort d’office (exclusion), l’éclair tient ×2 — vérifiée', ($r['demande']['statut'] ?? '') === 'validee' && $r['demande']['auto'] === true && $r['demande']['coef']['lot'] === 2.0
    && $r['demande']['exclusAuto'] === ['Tarte sans fiche'] && array_column($r['demande']['x']['exclus'], 'id') === [3] && array_column($r['demande']['y']['exclus'], 'id') === [3], json_encode($r['demande'] ?? $r, JSON_UNESCAPED_UNICODE));
// Une promotion « à valider » d'avant (17/10/2026) se lit enregistrée.
$GLOBALS['reglages']['promosMagasin'][] = ['id' => 'pmancienne01', 'shop' => 4, 'shops' => [4], 'statut' => 'a_valider', 'nom' => 'Ancienne', 'du' => $j(20), 'au' => $j(22)];
$tmp = pmLire();
verifie('une ancienne « à valider » se lit enregistrée', end($tmp)['statut'] === 'validee');
$GLOBALS['corps'] = ['decision' => 'refuser'];
$r = @wr_promos_magasin_decision($id2);
verifie('un renvoi sans mot : refusé', isset($r['error']));
$GLOBALS['corps'] = ['decision' => 'valider'];
$r = @wr_promos_magasin_decision($id2);
verifie('plus de décision « valider »', isset($r['error']));
$GLOBALS['corps'] = ['decision' => 'en_caisse'];
$r = wr_promos_magasin_decision($id2);
verifie('la marque la met en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && isset($r['demande']['enCaisse']));
$GLOBALS['corps'] = ['shop' => 4, 'id' => $id2, 'action' => 'brouillon', 'nom' => 'x'];
$r = @wr_promos_magasin();
verifie('en caisse : pas de retour en brouillon (les changements partent au panel)', str_contains((string) ($r['error'] ?? ''), 'en caisse'));
$_GET = ['shop' => '4', 'id' => $id2];
$r = @wr_promos_magasin_retirer();
verifie('en caisse : ne se retire plus d’ici', isset($r['error']));
$GLOBALS['corps'] = ['decision' => 'refuser', 'message' => 'Pas cette semaine.'];
$r = wr_promos_magasin_decision($id);
verifie('la marque renvoie une promotion enregistrée, avec son mot', ($r['demande']['statut'] ?? '') === 'refusee' && $r['demande']['message'] === 'Pas cette semaine.');
array_pop($GLOBALS['reglages']['promosMagasin']);
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'brouillon', 'nom' => 'Essai'];
$r = wr_promos_magasin();
$_GET = ['shop' => '5', 'id' => $r['demande']['id']];
$r2 = @wr_promos_magasin_retirer();
verifie('le brouillon d’un autre magasin ne se retire pas', isset($r2['error']));
$_GET = ['shop' => '4', 'id' => $r['demande']['id']];
verifie('un brouillon se retire', (wr_promos_magasin_retirer()['ok'] ?? false) === true && count(pmLire()) === 2, (string) count(pmLire()));
$GLOBALS['corps'] = ['shop' => 4, 'shops' => [4, 5], 'action' => 'envoyer', 'nom' => 'Pains au chocolat, Halle', 'du' => $j(6), 'au' => $j(10),
    'x' => ['q' => 3, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 30, 'prods' => [['id' => 2]]]];
$r = @wr_promos_magasin();
verifie('un autre magasin demandé : ignoré, la promotion ne vaut que pour Halle (×2,31, ses prix)', ($r['demande']['shops'] ?? null) === [4] && $r['demande']['coef']['lot'] === 2.31 && $r['demande']['coef']['magasin'] === 4, json_encode($r['demande'] ?? $r, JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => '5']; $l = ep_promos_magasin_demandes();
verifie('le magasin 5 ne la voit pas', $l['demandes'] === []);
// Le magasin 5 seul, la remise à 60 % : 4,20 + 0,56 = 4,76 € pour 2,40 € — ×1,98, enregistrée quand même.
$GLOBALS['corps'] = ['shop' => 5, 'action' => 'envoyer', 'nom' => 'Pains au chocolat −60 %', 'du' => $j(6), 'au' => $j(10),
    'x' => ['q' => 3, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 60, 'prods' => [['id' => 2]]]];
$r = @wr_promos_magasin();
verifie('sous ×2 (×1,98) : enregistrée quand même', ($r['demande']['statut'] ?? '') === 'validee' && $r['demande']['coef']['lot'] === 1.98 && count(pmLire()) === 4, json_encode($r, JSON_UNESCAPED_UNICODE));
// Le brouillon de Halle, repris depuis le magasin 5 : introuvable, il n'est pas le sien.
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'brouillon', 'nom' => 'Brouillon de Halle'];
$r = wr_promos_magasin();
$GLOBALS['corps'] = ['shop' => 5, 'id' => $r['demande']['id'], 'action' => 'brouillon', 'nom' => 'Repris ailleurs'];
$r2 = @wr_promos_magasin();
verifie('la promotion d’un autre magasin ne se modifie pas (404)', ($r2['error'] ?? '') === 'promotion introuvable' && pmLire()[4]['nom'] === 'Brouillon de Halle', json_encode($r2, JSON_UNESCAPED_UNICODE));

echo "L'écriture au panel\n";
ErpApi::$actif = true;
$pc = static fn (string $nom, int $de, int $a, array $plus = []) => $plus + ['shop' => 4, 'action' => 'envoyer', 'nom' => $nom, 'du' => $j($de, '07:00'), 'au' => $j($a, '19:00'),
    'x' => ['q' => 3, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 30, 'prods' => [['id' => 2]]]];
$GLOBALS['corps'] = $pc('Panel 3+1', 30, 32);
$r = wr_promos_magasin();
$P30 = ErpApi::$promos[30] ?? [];
verifie('enregistrée → créée au panel, relue, activée : en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && $r['demande']['panelId'] === 30 && $r['demande']['panelErreur'] === null, json_encode($r['demande'] ?? $r, JSON_UNESCAPED_UNICODE));
verifie('créée inactive, puis activée (POST, puis PUT)', ErpApi::$appels === ['POST /admin/promotions/buy-x-get-y', 'PUT /admin/promotions/buy-x-get-y/30'] && ($P30['status'] ?? '') === 'ACTIVE', json_encode(ErpApi::$appels));
verifie('au format de la fiche du panel', ($P30['promotion_type'] ?? '') === 'BUY_X_GET_Y' && $P30['shop_scope_type'] === 'SELECTED_SHOPS' && $P30['shop_ids'] === [4]
    && $P30['trigger'] === ['quantity' => 3, 'product_ids' => [2], 'category_ids' => [], 'excluded_product_ids' => []]
    && $P30['reward']['type'] === 'PERCENTAGE_DISCOUNT' && (float) $P30['reward']['value'] === 30.0 && $P30['reward']['selection_strategy'] === 'CHEAPEST'
    && $P30['valid_from'] === $j(30, '07:00') . ':00' && $P30['valid_to'] === $j(32, '19:00') . ':00' && $P30['activation_mode'] === 'AUTOMATIC' && $P30['priority'] === 100, json_encode($P30, JSON_UNESCAPED_UNICODE));
ErpApi::$appels = []; ErpApi::$refus = 'HTTP 422 : The trigger.product ids field is invalid';
$GLOBALS['corps'] = $pc('Panel refusée', 40, 42);
$r = wr_promos_magasin();
verifie('le panel refuse : enregistrée, l’erreur du panel dite, rien de créé', ($r['demande']['statut'] ?? '') === 'validee' && $r['demande']['panelId'] === null && str_contains((string) $r['demande']['panelErreur'], 'trigger.product ids'), json_encode($r['demande'] ?? $r, JSON_UNESCAPED_UNICODE));
ErpApi::$refus = null; ErpApi::$perdCreneaux = true; ErpApi::$appels = [];
$GLOBALS['corps'] = $pc('Panel créneaux', 50, 52, ['creneaux' => [['jours' => [6, 7], 'de' => '15:00', 'a' => '18:00']]]);
$r = wr_promos_magasin();
$idc = $r['demande']['id'] ?? '';
verifie('le panel ne reprend pas les créneaux : laissée inactive au panel, l’écart dit', ($r['demande']['statut'] ?? '') === 'validee' && $r['demande']['panelId'] === 31 && str_contains((string) $r['demande']['panelErreur'], 'créneaux') && ErpApi::$promos[31]['status'] === 'INACTIVE', json_encode($r['demande'] ?? $r, JSON_UNESCAPED_UNICODE));
ErpApi::$perdCreneaux = false; ErpApi::$appels = [];
$r = wr_promos_magasin_panel($idc);
verifie('renvoyée par la marque : la même promotion du panel mise à jour (PUT), pas une deuxième', ($r['demande']['statut'] ?? '') === 'en_caisse' && $r['demande']['panelId'] === 31 && ErpApi::$appels === ['PUT /admin/promotions/buy-x-get-y/31', 'PUT /admin/promotions/buy-x-get-y/31'] && count(ErpApi::$promos[31]['schedule']) === 2, json_encode([$r['demande'] ?? $r, ErpApi::$appels], JSON_UNESCAPED_UNICODE));
ErpApi::$sansPut = true; ErpApi::$appels = [];
$GLOBALS['corps'] = $pc('Panel sans PUT', 60, 62);
$r = wr_promos_magasin();
verifie('pas de PUT au panel : activée par PATCH status', ($r['demande']['statut'] ?? '') === 'en_caisse' && ErpApi::$appels === ['POST /admin/promotions/buy-x-get-y', 'PUT /admin/promotions/buy-x-get-y/32', 'PATCH /admin/promotions/buy-x-get-y/32'], json_encode(ErpApi::$appels));
$r = @wr_promos_magasin_panel($idc);
verifie('en caisse : ne repart pas au panel', isset($r['error']));
ErpApi::$sansPut = false; ErpApi::$appels = [];
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Pâtisserie, panel', 'du' => $j(70), 'au' => $j(72),
    'x' => ['q' => 1, 'cats' => [['id' => 20]]], 'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 20]]]];
$r = wr_promos_magasin();
$pid = $r['demande']['panelId'] ?? 0;
verifie('la tarte sortie d’office part au panel dans les exclusions : en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && (ErpApi::$promos[$pid]['trigger']['excluded_product_ids'] ?? null) === [3] && ErpApi::$promos[$pid]['reward']['excluded_product_ids'] === [3], json_encode($r['demande'] ?? $r, JSON_UNESCAPED_UNICODE));
// Une promotion enregistrée avant (coefficient non vérifié) : « Réessayer au panel » sort la tarte et la met en caisse.
$GLOBALS['reglages']['promosMagasin'][] = ['id' => 'pmanciennenv', 'shop' => 4, 'shops' => [4], 'statut' => 'validee', 'auto' => false, 'motif' => 'coefficient non vérifié', 'nom' => 'Ancienne pâtisserie',
    'description' => '', 'du' => $j(80, '07:00'), 'au' => $j(82, '19:00'), 'creneaux' => [], 'repetable' => false, 'maxParTicket' => null,
    'x' => ['q' => 1, 'cats' => [['id' => 20, 'nom' => '']], 'prods' => [], 'exclus' => []], 'y' => ['q' => 1, 'type' => 'FREE', 'valeur' => null, 'cats' => [['id' => 20, 'nom' => '']], 'prods' => [], 'exclus' => []]];
ErpApi::$appels = [];
$r = wr_promos_magasin_panel('pmanciennenv');
verifie('réessayée au panel : la tarte sortie, vérifiée, en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && $r['demande']['auto'] === true && $r['demande']['exclusAuto'] === ['Tarte sans fiche'] && ErpApi::$appels[0] === 'POST /admin/promotions/buy-x-get-y', json_encode($r, JSON_UNESCAPED_UNICODE));

echo "Les portions (½, ¼)\n";
$d = pmPropre(['x' => ['q' => 4, 'cats' => [['id' => 20]], 'portion' => 'one_quarter'], 'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 20]], 'portion' => 'TROIS_QUARTS']], 4);
verifie('la portion gardée (¼), une inconnue écartée', $d['x']['portion'] === 'ONE_QUARTER' && $d['y']['portion'] === null);
$q4 = $cote(4, [20]) + ['portion' => 'ONE_QUARTER']; $q1 = $cote(1, [20]) + ['type' => 'FREE', 'valeur' => null, 'portion' => 'ONE_QUARTER'];
$c = pmCoef($P, $q4, $q1);
// 4 quarts d'éclair payés (4 × ¼ × 4,00 € = 4,00 €) pour 5 quarts de matière (5 × ¼ × 1,00 € = 1,25 €) : ×3,20 — le coefficient de la pièce ÷ 1,25.
verifie('4 quarts achetés, le 5e offert : le coefficient de la pièce ÷ 1,25', $c['lot'] === 3.2 && $c['paye'] === 4.0 && $c['cout'] === 1.25, json_encode($c));
$c = pmCoef($P, $q4, $cote(1, [20]) + ['type' => 'FIXED_PRICE', 'valeur' => 0.53, 'portion' => 'ONE_QUARTER']);
verifie('un prix fixe s’applique à la part (le quart à 0,53 € TTC = 0,50 € HT)', $c['lot'] === 3.6, json_encode($c));
ErpApi::$appels = [];
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Quarts 4+1', 'du' => $j(90), 'au' => $j(92), 'x' => ['q' => 4, 'cats' => [['id' => 20]], 'portion' => 'ONE_QUARTER'],
    'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 20]], 'portion' => 'ONE_QUARTER']];
$r = wr_promos_magasin();
$pp = ErpApi::$promos[$r['demande']['panelId'] ?? 0] ?? [];
verifie('au panel : chaque cible porte sa portion, comme « 4 Quarts + 1 Gratuit »', ($r['demande']['statut'] ?? '') === 'en_caisse' && ($pp['trigger']['category_targets'] ?? null) === [['id_category' => 20, 'portion_scope' => 'ONE_QUARTER']]
    && $pp['reward']['category_targets'] === [['id_category' => 20, 'portion_scope' => 'ONE_QUARTER']] && $pp['trigger']['category_ids'] === [20], json_encode([$r['demande'] ?? $r, $pp['trigger'] ?? null], JSON_UNESCAPED_UNICODE));
ErpApi::$perdPortion = true;
$GLOBALS['corps']['nom'] = 'Quarts perdus'; $GLOBALS['corps']['du'] = $j(100); $GLOBALS['corps']['au'] = $j(102);
$r = wr_promos_magasin();
verifie('le panel ne reprend pas la portion : laissée inactive, l’écart dit', ($r['demande']['statut'] ?? '') === 'validee' && str_contains((string) $r['demande']['panelErreur'], 'portion'), json_encode($r['demande'] ?? $r, JSON_UNESCAPED_UNICODE));
ErpApi::$perdPortion = false;

echo "Le coefficient ne bloque plus (18/10/2026)\n";
ErpApi::$actif = true;
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Sous ×2 au panel', 'du' => $j(110), 'au' => $j(112),
    'x' => ['q' => 2, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'FREE', 'prods' => [['id' => 2]]]];
$r = wr_promos_magasin();
verifie('un lot à ×1,67 part au panel et passe en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && $r['demande']['coef']['lot'] === 1.67, json_encode($r['demande'] ?? $r, JSON_UNESCAPED_UNICODE));

echo "Les créneaux au panel, modifier une promotion en caisse (18/10/2026)\n";
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Goûter du samedi', 'du' => $j(120), 'au' => $j(130),
    'creneaux' => [['jours' => [6, 7], 'de' => '15:00', 'a' => '18:00']],
    'x' => ['q' => 2, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 50, 'prods' => [['id' => 2]]]];
$r = wr_promos_magasin();
$pid = $r['demande']['panelId'] ?? 0;
verifie('les créneaux partent en time_from / time_to, un par jour', ($r['demande']['statut'] ?? '') === 'en_caisse'
    && (ErpApi::$promos[$pid]['schedule'] ?? null) === [['day_of_week' => 6, 'time_from' => '15:00:00', 'time_to' => '18:00:00'], ['day_of_week' => 7, 'time_from' => '15:00:00', 'time_to' => '18:00:00']], json_encode([$r['demande'] ?? $r, ErpApi::$promos[$pid]['schedule'] ?? null]));
ErpApi::$formatHeure = 'H:i';
$GLOBALS['corps']['nom'] = 'Goûter sans secondes'; $GLOBALS['corps']['du'] = $j(140); $GLOBALS['corps']['au'] = $j(150);
$r = wr_promos_magasin();
$pid2 = $r['demande']['panelId'] ?? 0;
verifie('le panel refuse l’heure avec secondes : renvoyée sans, en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && (ErpApi::$promos[$pid2]['schedule'][0]['time_from'] ?? null) === '15:00', json_encode([$r['demande']['panelErreur'] ?? $r, ErpApi::$promos[$pid2]['schedule'] ?? null]));
ErpApi::$formatHeure = null;
// La première, en caisse, modifiée : le même numéro au panel, mis à jour (PUT), toujours en caisse.
ErpApi::$appels = [];
$GLOBALS['corps'] = ['id' => $r0id = (pmLire()[count(pmLire()) - 2]['id']), 'shop' => 4, 'action' => 'envoyer', 'nom' => 'Goûter du samedi, −40 %', 'du' => $j(120), 'au' => $j(130),
    'creneaux' => [['jours' => [6], 'de' => '15:00', 'a' => '18:00']],
    'x' => ['q' => 2, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 40, 'prods' => [['id' => 2]]]];
$r = wr_promos_magasin();
verifie('une promotion en caisse se modifie : même n° au panel (PUT), toujours en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && ($r['demande']['panelId'] ?? 0) === $pid
    && (ErpApi::$promos[$pid]['name'] ?? '') === 'Goûter du samedi, −40 %' && (ErpApi::$promos[$pid]['reward']['value'] ?? null) === 40.0 && !in_array('POST /admin/promotions/buy-x-get-y', ErpApi::$appels, true), json_encode([$r['demande'] ?? $r, ErpApi::$appels]));

echo "Les promotions par canal\n";
$regle = pvNormaliser(['id' => 11, 'name' => "Cookie's !", 'promotion_type' => 'BUY_X_GET_Y', 'status' => 'ACTIVE', 'priority' => 100, 'shop_scope_type' => 'ALL_SHOPS',
    'trigger' => ['quantity' => 2, 'category_ids' => [10]], 'reward' => ['quantity' => 1, 'category_ids' => [10], 'type' => 'FIXED_AMOUNT_DISCOUNT', 'value' => 1.40]]);
// Une ligne de ticket : [clé, pid, q, prix unitaire, remise, total] ; le produit 9 n'est dans aucune promotion.
$tk = static fn (int $t, float $rem, int $q = 3, int $pid = 1) => ['t' => $t, 'mn' => '10:00', 'l' => [[(string) $pid, $pid, $q, 2.40, $rem, $q * 2.40 - $rem]]];
$jours = ['2026-10-10' => [$tk(501, 1.40), $tk(502, 1.40), $tk(503, 2.80, 6), $tk(504, 0.99, 1, 9)], '2026-10-11' => [$tk(505, 1.40)], '2026-10-12' => null];
// 501 : une précommande au comptoir ; 502 : click & collect encaissé au magasin ; 505 : livraison ; une commande payée en ligne, sans ticket.
$cmd = [['canal' => 'compt', 'tx' => 501, 'statut' => 'remise'], ['canal' => 'cc', 'tx' => 502, 'statut' => 'remise'], ['canal' => 'liv', 'tx' => 505, 'statut' => 'livrée'], ['canal' => 'cc', 'tx' => 0, 'statut' => 'à préparer']];
$r = pmCanaux($jours, [$regle], $cmd, [1 => 10], [], 4);
$p = array_column($r['promos'], null, 'id');
verifie('au comptoir : 3 fois sur 2 tickets (6 cookies = 2 fois), 4,20 € de remise', ($p[11]['compt'] ?? null) === ['n' => 3, 'r' => 4.2, 't' => 2], json_encode($p[11] ?? $r));
verifie('le ticket d’une commande webshop : dans son canal (click & collect, livraison)', $p[11]['cc']['n'] === 1 && $p[11]['liv']['n'] === 1 && $p[11]['cc']['r'] === 1.4);
verifie('une remise qu’aucune promotion n’explique : à part, en dernier', $r['promos'][1]['id'] === 'x' && $r['promos'][1]['compt']['n'] === 1 && $r['promos'][1]['compt']['r'] === 0.99, json_encode($r['promos']));
verifie('jour par jour : comptoir et web, le jour pas lu marqué', $r['parJour'][0]['compt']['n'] === 4 && $r['parJour'][0]['web']['n'] === 1 && $r['parJour'][1]['web']['n'] === 1
    && $r['parJour'][2]['lu'] === false && $r['joursSans'] === ['2026-10-12'], json_encode($r['parJour']));
verifie('les commandes payées en ligne comptées à part', $r['enLigne'] === ['cc' => 1, 'liv' => 0] && $r['commandes'] === true && $r['total']['compt']['n'] === 4, json_encode([$r['enLigne'], $r['total']]));
$r = pmCanaux($jours, [$regle], null, [1 => 10], [], 4);
verifie('sans les commandes du panel : tout au comptoir, dit', $r['commandes'] === false && $r['total']['cc']['n'] === 0 && $r['total']['compt']['n'] === 6, json_encode($r['total']));

echo "La sonde des commandes payées en ligne\n";
$cmd = ['id' => 812, 'name' => 'Marie Dupont', 'customer_email' => 'marie@example.com', 'phone' => '0470', 'delivery_address' => ['street' => 'Rue X 1', 'city' => 'Halle'],
    'note' => 'sonner deux fois', 'is_webshop' => true, 'total_value' => 18.4, 'id_transaction' => null,
    'products' => [['id_product' => 101, 'name' => 'Cookie chocolat', 'quantity' => 3, 'unit_price' => 2.4, 'discount_value' => 1.4, 'customer_note' => 'sans noix']]];
$p = pmSondePropre($cmd);
verifie('rien de nominatif : nom, e-mail, téléphone, adresse, note retirés (la clé reste)', $p['name'] === '[retiré]' && $p['customer_email'] === '[retiré]' && $p['phone'] === '[retiré]'
    && $p['delivery_address'] === '[retiré]' && $p['note'] === '[retiré]' && !str_contains(json_encode($p, JSON_UNESCAPED_UNICODE), 'Marie') && !str_contains(json_encode($p), 'example.com'), json_encode($p, JSON_UNESCAPED_UNICODE));
verifie('dans les lignes, le nom du produit reste, la note du client part', $p['products'][0]['name'] === 'Cookie chocolat' && $p['products'][0]['customer_note'] === '[retiré]' && $p['total_value'] === 18.4);
$ch = pmSondeChemins($p, '/discount|promo/i');
verifie('les chemins des remises', $ch === ['products.0.discount_value' => 1.4], json_encode($ch));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
