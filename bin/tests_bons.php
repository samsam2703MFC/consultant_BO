#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du bon d'une promotion du magasin (promos_bons.php, 24/10/2026) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_bons.php
 *
 * Le panel est une doublure qui garde ce qu'on lui écrit et refuse comme le vrai, tel que l'essai du 24/10/2026 l'a lu
 * (dépôt WebShop, VOUCHERS_ERP.md) : une campagne UNIQUE ne passe pas ACTIVE par PATCH /status (« shared_code is not
 * supported for UNIQUE campaigns »), ni sans code actif ; une campagne n'est complète qu'avec une promotion active
 * rattachée et des codes. On vérifie les trois distributions (un code pour tous, des codes uniques, des clients du
 * magasin), le brouillon, la reprise, l'ajout et le retrait de clients, le bon retiré, la distribution qui change, les
 * refus, la recherche des clients, la lecture des codes et la bascule depuis la liste.
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
function ep_analyse_produits_coefficients(): array
{
    if (($_GET['shop'] ?? '') !== '4') { return ['produits' => []]; }
    return ['produits' => [
        ['id' => 1, 'nom' => 'Croissant', 'catId' => 10, 'prixHt' => 2.00, 'cout' => 0.50, 'tva' => 6],
        ['id' => 2, 'nom' => 'Pain au chocolat', 'catId' => 10, 'prixHt' => 1.50, 'cout' => 0.60, 'tva' => 6],
    ]];
}
function catalogueCouts(): array { return []; }
/** Une nouvelle requête : la garde des clients du temps de la requête oubliée (celle de ceo_app_setting reste). */
function pbOublierGarde(): void { unset($GLOBALS['pbClientsGarde']); }

/** Le panel en doublure : promotions « X + Y », campagnes de bons et leurs codes, clients des magasins. */
final class ErpApi
{
    public static bool $actif = true;
    public static ?string $lastError = null;
    public static array $promos = [];
    public static array $camps = [];
    public static array $codes = [];
    public static array $appels = [];
    public static int $suivant = 30;
    public static int $suivantCamp = 1;
    public static int $suivantCode = 1;
    public static bool $ficheNommeCampagne = false;   // la fiche « X + Y » porte voucher_campaign_id (sinon : la liste)
    public static ?string $refusCampagne = null;
    public static ?string $refusPromo = null;
    public static array $clients = [4 => [
        ['id' => 11, 'name' => 'Anne', 'surname' => 'Dupont', 'company_name' => null, 'city' => 'Halle', 'email' => 'anne.dupont@exemple.be', 'phone' => '+32470000011', 'is_b2b' => 0, 'id_main_shop' => 4],
        ['id' => 12, 'name' => 'Marc', 'surname' => 'Durand', 'company_name' => 'IBA', 'city' => 'Bruxelles', 'email' => null, 'phone' => '+32470000123', 'is_b2b' => 1, 'id_main_shop' => 4, 'tax_number' => 'BE0123.456.789'],
        ['id' => 13, 'name' => null, 'surname' => null, 'company_name' => 'Odoo', 'city' => 'Halle', 'email' => 'contact@odoo.example', 'phone' => null, 'is_b2b' => 1, 'id_main_shop' => 4, 'tax_number' => 'BE0477472701'],
        ['id' => 14, 'name' => 'Paul', 'surname' => 'Dumont', 'city' => 'Mons', 'email' => 'paul@exemple.be', 'is_b2b' => 0, 'id_main_shop' => 5],
        ['id' => 15, 'name' => 'Ancien', 'surname' => 'Dubois', 'city' => 'Halle', 'is_b2b' => 0, 'id_main_shop' => 4, 'merged_into_id' => 11],
    ], 5 => [['id' => 51, 'name' => 'Zoé', 'surname' => 'Autre', 'city' => 'Mons', 'is_b2b' => 0, 'id_main_shop' => 5]]];
    /** Les lectures de la liste des clients d'un magasin (la garde d'une heure les épargne). */
    public static int $lecturesClients = 0;
    /** Faux : le panel refuse la lecture d'un seul tenant (sans `offset`) — le cockpit doit passer aux pages. */
    public static bool $clientsEnBloc = true;
    public static function disponible(): bool { return self::$actif; }
    private static function ok($c = [], int $code = 200): array { return ['code' => $code, 'corps' => $c, 'erreur' => null]; }
    private static function ko(int $code, string $desc, array $errs = []): array
    {
        return ['code' => $code, 'corps' => ['status' => 'error', 'description' => $desc, 'errors' => $errs], 'erreur' => 'HTTP ' . $code . ' : ' . $desc . ($errs ? ' — ' . implode(' ; ', array_map(static fn ($k, $v) => "$k : $v", array_keys($errs), $errs)) : '')];
    }
    /** La fiche d'une campagne, comme le panel la rend (24/10/2026) : campaign, promotion, codes, complétude. */
    public static function ficheCamp(int $id): array
    {
        $c = self::$camps[$id];
        $codes = array_values(array_filter(self::$codes, static fn ($k) => $k['id_voucher_campaign'] === $id));
        $p = $c['id_promotion'] ? (self::$promos[$c['id_promotion']] ?? null) : null;
        $manque = [];
        if (!$c['id_promotion']) { $manque[] = 'PROMOTION_NOT_ASSIGNED'; } elseif ($p === null) { $manque[] = 'PROMOTION_NOT_FOUND'; } elseif (($p['status'] ?? '') !== 'ACTIVE') { $manque[] = 'PROMOTION_NOT_ACTIVE'; }
        if ($c['code_type'] === 'SHARED' && ($c['shared_code'] ?? '') === '') { $manque[] = 'SHARED_CODE_NOT_CONFIGURED'; }
        if ($c['code_type'] === 'UNIQUE' && !array_filter($codes, static fn ($k) => $k['status'] === 'ACTIVE')) { $manque[] = 'VOUCHER_CODES_NOT_GENERATED'; }
        $sc = $c['code_type'] === 'SHARED' ? $c['shared_code'] : ($codes[0]['code'] ?? null);   // le panel rend un shared_code calculé
        return ['campaign' => array_diff_key($c, ['shared_code' => 1]), 'promotion' => $p, 'codes' => $codes, 'shared_code' => $sc,
            'code_stats' => ['total' => count($codes)], 'redemptions_total' => 0, 'configuration_complete' => $manque === [], 'missing_requirements' => $manque];
    }
    public static function ecrire(string $m, string $path, ?array $b = null): array
    {
        self::$appels[] = $m . ' ' . $path;
        // Les promotions « X + Y ».
        if ($m === 'POST' && $path === '/admin/promotions/buy-x-get-y') {
            if (self::$refusPromo !== null) { return self::ko(422, self::$refusPromo); }
            $id = self::$suivant++;
            self::$promos[$id] = self::fiche($id, $b);
            return self::ok(['data' => ['id' => $id]]);
        }
        if (preg_match('#^/admin/promotions/buy-x-get-y/(\d+)$#', $path, $x) && isset(self::$promos[(int) $x[1]])) {
            $id = (int) $x[1];
            if ($m === 'PATCH' || $m === 'PUT') { self::$promos[$id] = isset($b['name']) ? self::fiche($id, $b) : array_merge(self::$promos[$id], $b); return self::ok(); }
            if ($m === 'DELETE') { unset(self::$promos[$id]); return self::ok(); }
        }
        // Les campagnes.
        if ($m === 'POST' && $path === '/admin/voucher-campaigns') {
            if (self::$refusCampagne !== null) { return self::ko(422, 'INVALID_VOUCHER_CAMPAIGN_DATA', ['name' => self::$refusCampagne]); }
            if (($b['name'] ?? '') === '') { return self::ko(422, 'INVALID_VOUCHER_CAMPAIGN_DATA', ['name' => 'name is required.']); }
            if (($b['code_type'] ?? 'SHARED') === 'UNIQUE' && ($b['shared_code'] ?? null) !== null) { return self::ko(422, 'INVALID_VOUCHER_CAMPAIGN_DATA', ['shared_code' => 'shared_code is not supported for UNIQUE campaigns.']); }
            $id = self::$suivantCamp++;
            self::$camps[$id] = ['id' => $id, 'id_promotion' => null, 'status' => $b['status'] ?? 'DRAFT'] + $b;
            return self::ok(['status' => 'success', 'data' => self::ficheCamp($id)], 201);
        }
        if (preg_match('#^/admin/voucher-campaigns/(\d+)(/.*)?$#', $path, $x)) {
            $id = (int) $x[1]; $suite = $x[2] ?? '';
            if (!isset(self::$camps[$id])) { return self::ko(404, 'VOUCHER_CAMPAIGN_NOT_FOUND'); }
            $c = &self::$camps[$id];
            $actifs = static fn () => array_filter(self::$codes, static fn ($k) => $k['id_voucher_campaign'] === $id && $k['status'] === 'ACTIVE');
            if ($m === 'PATCH' && $suite === '') {
                $type = $b['code_type'] ?? $c['code_type'];
                $partage = array_key_exists('shared_code', $b) ? $b['shared_code'] : ($type === 'SHARED' ? $c['shared_code'] : self::ficheCamp($id)['shared_code']);
                if ($type === 'UNIQUE' && $partage !== null) { return self::ko(422, 'INVALID_VOUCHER_CAMPAIGN_DATA', ['shared_code' => 'shared_code is not supported for UNIQUE campaigns.']); }
                if (($b['status'] ?? '') === 'ACTIVE' && $type === 'UNIQUE' && !$actifs()) { return self::ko(422, 'INVALID_VOUCHER_CAMPAIGN_DATA', ['code_type' => 'ACTIVE UNIQUE campaign requires at least one active code.']); }
                $c = array_merge($c, $b);
                return self::ok(self::ficheCamp($id));
            }
            if ($m === 'PATCH' && $suite === '/status') {
                // Le refus relu le 24/10/2026 : la validation reprend le shared_code calculé d'une campagne UNIQUE.
                if (($b['status'] ?? '') === 'ACTIVE' && $c['code_type'] === 'UNIQUE') { return self::ko(422, 'INVALID_VOUCHER_CAMPAIGN_DATA', ['shared_code' => 'shared_code is not supported for UNIQUE campaigns.']); }
                $c['status'] = $b['status'];
                return self::ok(self::ficheCamp($id));
            }
            if ($m === 'PUT' && $suite === '/promotion') {
                $p = self::$promos[(int) ($b['id_promotion'] ?? 0)] ?? null;
                if ($p === null) { return self::ko(404, 'PROMOTION_NOT_FOUND'); }
                if ($c['id_promotion'] !== null && $c['id_promotion'] !== (int) $b['id_promotion']) { return self::ko(409, 'CAMPAIGN_HAS_PROMOTION'); }
                if (($p['activation_mode'] ?? '') !== 'VOUCHER_ONLY' && empty($b['confirm_activation_mode_change'])) { return self::ko(422, 'ACTIVATION_MODE_CHANGE_NOT_CONFIRMED'); }
                $c['id_promotion'] = (int) $b['id_promotion'];
                self::$promos[$c['id_promotion']]['activation_mode'] = 'VOUCHER_ONLY';
                return self::ok(self::ficheCamp($id));
            }
            if ($m === 'DELETE' && $suite === '/promotion') { $c['id_promotion'] = null; $c['status'] = 'DRAFT'; return self::ok(self::ficheCamp($id)); }
            if ($m === 'POST' && $suite === '/codes') {
                foreach (self::$codes as $k) { if ($k['code'] === $b['code']) { return self::ko(422, 'INVALID_VOUCHER_CODE_DATA', ['code' => 'code already exists.']); } }
                $kid = self::$suivantCode++;
                self::$codes[$kid] = ['id' => $kid, 'id_voucher_campaign' => $id, 'code' => $b['code'], 'status' => $b['status'] ?? 'ACTIVE', 'id_customer' => $b['id_customer'] ?? null,
                    'usage_limit' => $b['usage_limit'] ?? null, 'usage_count' => 0, 'valid_from' => $b['valid_from'] ?? null, 'valid_to' => $b['valid_to'] ?? null];
                return self::ok(['status' => 'success', 'data' => self::$codes[$kid]], 201);
            }
            if ($m === 'POST' && $suite === '/codes/generate') {
                if ($c['code_type'] !== 'UNIQUE') { return self::ko(422, 'CAMPAIGN_NOT_UNIQUE'); }
                $items = [];
                for ($i = 0; $i < (int) $b['count']; $i++) {
                    $kid = self::$suivantCode++;
                    $items[] = self::$codes[$kid] = ['id' => $kid, 'id_voucher_campaign' => $id, 'code' => ($b['prefix'] ?? '') . 'G' . $kid, 'status' => 'ACTIVE', 'id_customer' => null, 'usage_limit' => null, 'usage_count' => 0];
                }
                return self::ok(['status' => 'success', 'data' => ['items' => $items]], 201);
            }
        }
        if (preg_match('#^/admin/voucher-codes/(\d+)/status$#', $path, $x) && $m === 'PATCH') {
            if (!isset(self::$codes[(int) $x[1]])) { return self::ko(404, 'VOUCHER_CODE_NOT_FOUND'); }
            self::$codes[(int) $x[1]]['status'] = $b['status'];
            return self::ok(self::$codes[(int) $x[1]]);
        }
        return self::ko(404, 'NOT_FOUND');
    }
    public static function get(string $path): mixed
    {
        self::$appels[] = 'GET ' . $path;
        if (preg_match('#^/admin/promotions/buy-x-get-y/(\d+)$#', $path, $x) && isset(self::$promos[(int) $x[1]])) {
            $f = self::$promos[(int) $x[1]];
            if (self::$ficheNommeCampagne) {
                foreach (self::$camps as $c) { if ($c['id_promotion'] === (int) $x[1]) { $f['voucher_campaign_id'] = $c['id']; } }
            }
            return $f;
        }
        if (preg_match('#^/shops/\d+/clients#', $path)) {
            $r = self::sondeGet($path);
            return $r['code'] >= 200 && $r['code'] < 300 ? $r['corps'] : null;
        }
        return null;
    }
    public static function getParallele(array $paths, int $front = 4): array
    {
        $o = [];
        foreach ($paths as $k => $p) { $o[$k] = self::get($p); }
        return $o;
    }
    public static function sondeGet(string $path): array
    {
        self::$appels[] = 'GET ' . $path;
        $u = parse_url($path); parse_str((string) ($u['query'] ?? ''), $q); $p = $u['path'];
        if ($p === '/admin/promotions/buy-x-get-y') {
            $l = array_map(static fn ($f) => ['id' => $f['id'], 'name' => $f['name'] ?? '', 'status' => $f['status'] ?? '', 'promotion_type' => 'BUY_X_GET_Y'], array_values(self::$promos));
            return self::ok(['data' => $l]);
        }
        if ($p === '/admin/voucher-campaigns') {
            $l = array_values(array_filter(self::$camps, static fn ($c) => !isset($q['id_promotion']) || $c['id_promotion'] === (int) $q['id_promotion']));
            return self::ok(['items' => $l, 'pagination' => ['page' => 1, 'limit' => 10, 'total' => count($l), 'pages' => 1]]);
        }
        if (preg_match('#^/admin/voucher-campaigns/(\d+)$#', $p, $x)) {
            return isset(self::$camps[(int) $x[1]]) ? self::ok(self::ficheCamp((int) $x[1])) : self::ko(404, 'VOUCHER_CAMPAIGN_NOT_FOUND');
        }
        if (preg_match('#^/admin/voucher-campaigns/(\d+)/codes$#', $p, $x)) {
            $l = array_values(array_filter(self::$codes, static fn ($k) => $k['id_voucher_campaign'] === (int) $x[1]));
            $lim = (int) ($q['limit'] ?? 25); $page = max(1, (int) ($q['page'] ?? 1));
            return self::ok(['items' => array_slice($l, ($page - 1) * $lim, $lim), 'pagination' => ['page' => $page, 'limit' => $lim, 'total' => count($l), 'pages' => max(1, (int) ceil(count($l) / $lim))]]);
        }
        if (preg_match('#^/shops/(\d+)/clients$#', $p, $x)) {
            // Comme le panel (sonde du 29/10/2026) : `type=b2b` les B2B, toute autre valeur les particuliers, sans `type`
            // les deux, B2B devant ; `limit` n'est tenu qu'avec `offset` — sans lui, tout vient d'un coup.
            self::$lecturesClients++;
            if (!self::$clientsEnBloc && !isset($q['offset'])) { return self::ko(500, 'Allowed memory size exhausted'); }
            $l = self::$clients[(int) $x[1]] ?? [];
            if (isset($q['type'])) { $l = array_values(array_filter($l, static fn ($c) => !empty($c['is_b2b']) === ($q['type'] === 'b2b'))); }
            else { usort($l, static fn ($a, $b) => (int) !empty($b['is_b2b']) <=> (int) !empty($a['is_b2b'])); }
            return self::ok(isset($q['offset'], $q['limit']) ? array_slice($l, (int) $q['offset'], (int) $q['limit']) : $l);
        }
        return self::ko(404, 'NOT_FOUND');
    }
    private static function fiche(int $id, array $b): array { return ['id' => $id] + $b; }
}

require __DIR__ . '/../src/promos_magasin.php';
require __DIR__ . '/../src/promos_bons.php';
require __DIR__ . '/../src/promos_doc.php';
require __DIR__ . '/../src/promos_ventes.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, $detail = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== null ? ' — ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n"; }
}
$j = static fn (int $n, string $h = '00:00') => date('Y-m-d', strtotime("+$n day")) . ' ' . $h;
$oublie = static function (): void { unset($GLOBALS['pvReglesMemo']); };
$GLOBALS['reglages'] = [];
$promo = static fn (string $nom, ?array $bon, string $action = 'envoyer', ?string $id = null) => array_filter(['shop' => 4, 'id' => $id, 'action' => $action, 'nom' => $nom, 'du' => $j(1), 'au' => $j(8),
    'x' => ['q' => 2, 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]], 'y' => ['q' => 1, 'type' => 'FREE', 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]], 'bon' => $bon], static fn ($v) => $v !== null);
$campDe = static function (int $pid): ?array { foreach (ErpApi::$camps as $c) { if ($c['id_promotion'] === $pid) { return $c; } } return null; };
$codesDe = static fn (int $cid) => array_values(array_filter(ErpApi::$codes, static fn ($k) => $k['id_voucher_campaign'] === $cid));
$rang = static function (string $appel): int { $i = array_search($appel, ErpApi::$appels, true); return $i === false ? -1 : (int) $i; };

echo "Le bon, rendu propre\n";
verifie('sans bon (ou éteint) : null, la promotion reste automatique', pbBon(null) === null && pbBon(['on' => false, 'cible' => 'code']) === null);
$b = pbBon(['on' => true, 'cible' => 'code', 'code' => ' halle10 ', 'limite' => '50']);
verifie('un code pour tous : en majuscules, limite gardée', $b['code'] === 'HALLE10' && $b['limite'] === 50 && pbErreurs($b) === [], $b);
verifie('un code mal formé : dit, pas envoyé', pbErreurs(pbBon(['on' => true, 'cible' => 'code', 'code' => 'a b'])) !== [] && pbErreurs(pbBon(['on' => true, 'cible' => 'code', 'code' => ''])) === []);
verifie('des codes uniques : 1 à 500', pbErreurs(pbBon(['on' => true, 'cible' => 'uniques', 'nombre' => 0])) !== [] && pbBon(['on' => true, 'cible' => 'uniques', 'nombre' => 9999])['nombre'] === 500);
verifie('des clients : au moins un, sans doublon', pbErreurs(pbBon(['on' => true, 'cible' => 'clients'])) !== []
    && count(pbBon(['on' => true, 'cible' => 'clients', 'clients' => [['id' => 11], ['id' => 11], ['id' => 12]]])['clients']) === 2);
verifie('le numéro de campagne du navigateur est ignoré', !array_key_exists('campagne', pbBon(['on' => true, 'cible' => 'code', 'campagne' => 99])));

echo "Sans bon : rien ne change\n";
$GLOBALS['corps'] = $promo('Sans bon', null);
$r = wr_promos_magasin();
verifie('promotion automatique, aucune campagne', ($r['demande']['statut'] ?? '') === 'en_caisse' && ErpApi::$promos[30]['activation_mode'] === 'AUTOMATIC' && ErpApi::$camps === [] && $r['bon'] === null, $r);

echo "Un code pour tous\n";
ErpApi::$appels = [];
$GLOBALS['corps'] = $promo('Cookies 2+1 · code', ['on' => true, 'cible' => 'code', 'code' => 'halle10', 'limite' => 50]);
$r = wr_promos_magasin();
$c = $campDe(31);
verifie('la promotion part « par code » (VOUCHER_ONLY), en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && ErpApi::$promos[31]['activation_mode'] === 'VOUCHER_ONLY' && ErpApi::$promos[31]['status'] === 'ACTIVE', $r);
verifie('sa campagne : SHARED, le code, la limite, la fin de la promotion, active et complète', $c !== null && $c['code_type'] === 'SHARED' && $c['shared_code'] === 'HALLE10' && $c['usage_limit_total'] === 50
    && $c['valid_from'] === null && $c['valid_to'] === $j(8) . ':00' && $c['status'] === 'ACTIVE' && ErpApi::ficheCamp($c['id'])['configuration_complete'], $c);
verifie('l’ordre du panel : promotion inactive, campagne en brouillon et rattachée, promotion activée, puis la campagne',
    $rang('POST /admin/promotions/buy-x-get-y') < $rang('POST /admin/voucher-campaigns') && $rang('POST /admin/voucher-campaigns') < $rang('PUT /admin/voucher-campaigns/1/promotion')
    && $rang('PUT /admin/voucher-campaigns/1/promotion') < $rang('PATCH /admin/voucher-campaigns/1'), ErpApi::$appels);
verifie('la réponse dit le bon : actif, son code', ($r['bon']['actif'] ?? false) && $r['bon']['code'] === 'HALLE10' && $r['bon']['cible'] === 'code' && $r['panelErreur'] === null, $r['bon'] ?? null);
$oublie(); $_GET = ['shop' => '4']; $l = ep_promos_magasin_demandes(); $_GET = [];
$dm = array_values(array_filter($l['demandes'], static fn ($d) => $d['id'] === 'p31'))[0] ?? null;
verifie('la liste la relit « avec un bon » (la campagne se cherche par la promotion)', $dm !== null && $dm['bon'] !== null && $dm['bon']['campagne'] === null, $dm);
$_GET = ['shop' => '4', 'id' => 'p31']; $v = ep_promos_magasin_bon(); $_GET = [];
verifie('« Voir les codes » : le bon relu, son code', ($v['bon']['campagne'] ?? 0) === 1 && $v['bon']['code'] === 'HALLE10' && $v['bon']['actif'], $v);
ErpApi::$ficheNommeCampagne = true; $oublie();
$_GET = ['shop' => '4']; $l = ep_promos_magasin_demandes(); $_GET = [];
$dm = array_values(array_filter($l['demandes'], static fn ($d) => $d['id'] === 'p31'))[0] ?? null;
verifie('une fiche qui nomme sa campagne : relue telle quelle', ($dm['bon']['campagne'] ?? null) === 1, $dm);
ErpApi::$ficheNommeCampagne = false; $oublie();

echo "Des codes uniques\n";
ErpApi::$appels = [];
$GLOBALS['corps'] = $promo('Croissants · codes', ['on' => true, 'cible' => 'uniques', 'nombre' => 150, 'prefixe' => 'hal-']);
$r = wr_promos_magasin();
$c = $campDe(32);
verifie('UNIQUE, 150 codes générés par lots de 100, préfixés', $c !== null && $c['code_type'] === 'UNIQUE' && count($codesDe($c['id'])) === 150 && str_starts_with($codesDe($c['id'])[0]['code'], 'HAL-')
    && count(array_filter(ErpApi::$appels, static fn ($a) => str_ends_with($a, '/codes/generate'))) === 2, [$c, count($codesDe($c['id'] ?? 0))]);
verifie('activée par PATCH /{id} (PATCH /status la refuse), complète', $c['status'] === 'ACTIVE' && $c['usage_limit_per_code'] === 1 && !in_array('PATCH /admin/voucher-campaigns/' . $c['id'] . '/status', ErpApi::$appels, true)
    && ($r['bon']['complete'] ?? false) && ($r['bon']['nCodes'] ?? 0) === 150, $r['bon'] ?? null);

echo "Des clients du magasin\n";
$_GET = ['shop' => '4', 'q' => 'du']; $cl = ep_promos_magasin_clients();
verifie('la recherche : nom, société, ville ; un contact masqué ; un B2B par sa société', array_column($cl['clients'], 'nom') === ['Anne Dupont', 'IBA', 'Paul Dumont']
    && $cl['clients'][0]['contact'] === 'a…@exemple.be' && $cl['clients'][1]['personne'] === 'Marc Durand' && $cl['clients'][1]['contact'] === '…123' && $cl['total'] === 4, $cl);
$_GET = ['shop' => '4', 'q' => 'odoo']; $cl = ep_promos_magasin_clients();
verifie('une société sans prénom : son nom, B2B', ($cl['clients'][0]['nom'] ?? '') === 'Odoo' && $cl['clients'][0]['b2b'] === true, $cl);
$_GET = ['shop' => '4', 'q' => 'd']; $cl = ep_promos_magasin_clients();
verifie('une lettre : rien (deux au moins)', $cl['clients'] === []);
$_GET = ['shop' => '5', 'q' => 'du']; $cl = ep_promos_magasin_clients(); $_GET = [];
verifie('un autre magasin : ses clients seulement', $cl['clients'] === [], $cl);
verifie('sans type : les B2B et les particuliers, chacun lu au panel avec son filtre, d’un seul tenant', in_array('GET /shops/5/clients?type=b2b', ErpApi::$appels, true)
    && in_array('GET /shops/5/clients?type=b2c', ErpApi::$appels, true) && !array_filter(ErpApi::$appels, static fn ($a) => str_contains($a, '/shops/5/clients?type=b2c&limit')), array_values(array_filter(ErpApi::$appels, static fn ($a) => str_contains($a, '/clients'))));

echo "Client B2B ou client du magasin (29/10/2026)\n";
$_GET = ['shop' => '4', 'type' => 'b2b', 'q' => '']; $cl = ep_promos_magasin_clients();
verifie('B2B sans texte : les comptes du magasin, par société, avec leur TVA', array_column($cl['clients'], 'nom') === ['IBA', 'Odoo'] && $cl['clients'][0]['tva'] === 'BE0123.456.789'
    && $cl['nB2b'] === 2 && $cl['nParticuliers'] === null, $cl);
$_GET = ['shop' => '4', 'type' => 'b2b', 'q' => 'du']; $cl = ep_promos_magasin_clients();
verifie('B2B : la personne derrière le compte se cherche aussi, pas les particuliers', array_column($cl['clients'], 'nom') === ['IBA'], $cl);
$_GET = ['shop' => '4', 'type' => 'b2b', 'q' => '0123.456']; $cl = ep_promos_magasin_clients();
$_GET = ['shop' => '4', 'type' => 'b2b', 'q' => 'be 0477 472']; $cl2 = ep_promos_magasin_clients();
verifie('B2B : le numéro de TVA, avec ou sans points ni espaces', array_column($cl['clients'], 'nom') === ['IBA'] && array_column($cl2['clients'], 'nom') === ['Odoo'], [$cl, $cl2]);
$_GET = ['shop' => '4', 'type' => 'particulier', 'q' => 'du']; $cl = ep_promos_magasin_clients();
verifie('particuliers : ceux du magasin, sans les B2B ni les fusionnés ; un autre magasin principal se signale', array_column($cl['clients'], 'nom') === ['Anne Dupont', 'Paul Dumont']
    && $cl['clients'][0]['b2b'] === false && $cl['clients'][0]['ailleurs'] === false && $cl['clients'][1]['ailleurs'] === true
    && $cl['nParticuliers'] === 2, $cl);
$_GET = ['shop' => '4', 'type' => 'particulier', 'q' => '']; $cl = ep_promos_magasin_clients();
verifie('particuliers sans texte : rien (deux lettres au moins)', $cl['clients'] === [] && $cl['nParticuliers'] === 2, $cl);
// Le magasin 2 du 29/10/2026 en petit : 300 B2B devant, 2 300 particuliers — le bon d'avant n'en lisait que 2 000.
ErpApi::$clients[2] = [];
for ($i = 1; $i <= 300; $i++) { ErpApi::$clients[2][] = ['id' => 20000 + $i, 'company_name' => 'Société ' . $i, 'is_b2b' => 1, 'id_main_shop' => 2, 'tax_number' => 'BE0' . (400000000 + $i)]; }
for ($i = 1; $i <= 2300; $i++) { ErpApi::$clients[2][] = ['id' => 30000 + $i, 'name' => 'Client', 'surname' => 'N' . $i, 'is_b2b' => 0, 'id_main_shop' => 2, 'phone' => '+3247' . str_pad((string) $i, 7, '0', STR_PAD_LEFT)]; }
ErpApi::$clients[2][] = ['id' => 39999, 'name' => 'Zénobie', 'surname' => 'Lointaine', 'city' => 'Gosselies', 'is_b2b' => 0, 'id_main_shop' => 2];
ErpApi::$lecturesClients = 0;
$_GET = ['shop' => '2', 'type' => 'particulier', 'q' => 'lointaine']; $cl = ep_promos_magasin_clients();
verifie('un grand magasin : le particulier du fond de la liste se trouve, en une seule lecture', array_column($cl['clients'], 'nom') === ['Zénobie Lointaine']
    && $cl['nParticuliers'] === 2301 && ErpApi::$lecturesClients === 1 && preg_match('/^\d\d:\d\d$/', (string) $cl['lu']) === 1 && is_int($cl['ms']), $cl + ['lectures' => ErpApi::$lecturesClients]);
$_GET = ['shop' => '2', 'type' => 'particulier', 'q' => 'client n12']; $cl = ep_promos_magasin_clients();
verifie('chercher lettre après lettre : la liste gardée, le panel pas relu', ErpApi::$lecturesClients === 1 && $cl['trouves'] === 111 && count($cl['clients']) === 30, [ErpApi::$lecturesClients, $cl['trouves']]);
$_GET = ['shop' => '2', 'type' => 'particulier', 'q' => '0470 00 07 77']; $cl = ep_promos_magasin_clients();
verifie('un téléphone tapé à la belge (0470 …) face au format du panel (+32470…)', array_column($cl['clients'], 'nom') === ['Client N777'], $cl);
$_GET = ['shop' => '2', 'type' => 'b2b', 'q' => 'société 299']; $cl = ep_promos_magasin_clients(); $_GET = [];
verifie('un compte rond (300 B2B) : le panel a peut-être coupé, les pages vérifient', array_column($cl['clients'], 'nom') === ['Société 299'] && $cl['nB2b'] === 300
    && ErpApi::$lecturesClients === 3 && in_array('GET /shops/2/clients?type=b2b&limit=500&offset=0', ErpApi::$appels, true), [$cl, ErpApi::$lecturesClients]);
// La garde vieille de plus d'une heure : relue. Plus jeune : gardée — sauf « relire » (frais=1).
$GLOBALS['reglages']['pbClients:2:particulier']['lu'] = time() - 3000;
ErpApi::$clients[2][] = ['id' => 39998, 'name' => 'Nouvelle', 'surname' => 'Venue', 'is_b2b' => 0, 'id_main_shop' => 2];
pbOublierGarde();
$_GET = ['shop' => '2', 'type' => 'particulier', 'q' => 'nouvelle']; $cl = ep_promos_magasin_clients();
verifie('une garde de cinquante minutes vaut encore : le client créé depuis n’y est pas', $cl['clients'] === [] && ErpApi::$lecturesClients === 3, $cl);
$_GET = ['shop' => '2', 'type' => 'particulier', 'q' => 'nouvelle', 'frais' => '1']; $cl = ep_promos_magasin_clients();
verifie('« relire » : le panel relu, le nouveau client trouvé', array_column($cl['clients'], 'nom') === ['Nouvelle Venue'] && ErpApi::$lecturesClients === 4, $cl);
$GLOBALS['reglages']['pbClients:2:particulier']['lu'] = time() - 3700; pbOublierGarde();
$_GET = ['shop' => '2', 'type' => 'particulier', 'q' => 'nouvelle']; $cl = ep_promos_magasin_clients();
verifie('une garde de plus d’une heure : relue au panel', ErpApi::$lecturesClients === 5, ErpApi::$lecturesClients);
ErpApi::$clientsEnBloc = false; pbOublierGarde(); unset($GLOBALS['reglages']['pbClients:2:particulier']);
$_GET = ['shop' => '2', 'type' => 'particulier', 'q' => 'lointaine']; $cl = ep_promos_magasin_clients(); $_GET = [];
verifie('la lecture d’un seul tenant refusée : les pages de 500 prennent le relais', array_column($cl['clients'], 'nom') === ['Zénobie Lointaine'] && $cl['nParticuliers'] === 2302
    && in_array('GET /shops/2/clients?type=b2c&limit=500&offset=2000', ErpApi::$appels, true), [$cl['nParticuliers'], ErpApi::$lecturesClients]);
ErpApi::$clientsEnBloc = true;
$GLOBALS['corps'] = $promo('Fidèles · Halle', ['on' => true, 'cible' => 'clients', 'clients' => [['id' => 11, 'nom' => 'Anne Dupont'], ['id' => 12, 'nom' => 'Marc Durand']]]);
$r = wr_promos_magasin();
$c = $campDe(33); $k = $codesDe($c['id'] ?? 0);
verifie('un code personnel par client, une utilisation, client requis', $c !== null && !empty($c['requires_customer']) && $c['usage_limit_per_customer'] === 1 && count($k) === 2
    && array_column($k, 'id_customer') === [11, 12] && $k[0]['usage_limit'] === 1 && $c['status'] === 'ACTIVE', [$c, $k]);
$_GET = ['shop' => '4', 'id' => 'p33']; $v = ep_promos_magasin_bon(); $_GET = [];
verifie('« Voir les codes » : chaque code avec le nom de son client, B2B ou non', array_column($v['bon']['codes'], 'nomClient') === ['Anne Dupont', 'IBA']
    && array_column($v['bon']['codes'], 'b2bClient') === [false, true], $v['bon']['codes'] ?? null);
ErpApi::$appels = [];
$GLOBALS['corps'] = $promo('Fidèles · Halle', ['on' => true, 'cible' => 'clients', 'clients' => [['id' => 12], ['id' => 13]]], 'envoyer', 'p33');
$r = wr_promos_magasin();
$k = $codesDe($c['id']);
verifie('modifiée : le même bon, un code pour le client ajouté, celui du client retiré désactivé', $campDe(33)['id'] === $c['id'] && count($k) === 3
    && $k[0]['status'] === 'DISABLED' && $k[1]['status'] === 'ACTIVE' && $k[2]['id_customer'] === 13 && !in_array('POST /admin/voucher-campaigns', ErpApi::$appels, true), $k);

echo "Brouillon, puis enregistré\n";
$GLOBALS['corps'] = $promo('Brouillon avec bon', ['on' => true, 'cible' => 'code', 'code' => 'ESSAI2'], 'brouillon');
$r = wr_promos_magasin();
$c = $campDe(34);
verifie('un brouillon : promotion inactive, bon en brouillon mais rattaché', ($r['demande']['statut'] ?? '') === 'brouillon' && ErpApi::$promos[34]['status'] === 'INACTIVE' && $c !== null && $c['status'] === 'DRAFT', [$r, $c]);
ErpApi::$appels = [];
$GLOBALS['corps'] = $promo('Brouillon avec bon', ['on' => true, 'cible' => 'code', 'code' => ''], 'envoyer', 'p34');
$r = wr_promos_magasin();
verifie('repris et enregistré : le même bon (son code gardé), activé', ($r['demande']['statut'] ?? '') === 'en_caisse' && $campDe(34)['id'] === $c['id'] && $campDe(34)['status'] === 'ACTIVE'
    && $campDe(34)['shared_code'] === 'ESSAI2' && !in_array('POST /admin/voucher-campaigns', ErpApi::$appels, true), [$r['bon'] ?? null, $campDe(34)]);

echo "Le bon retiré, la distribution qui change\n";
$avant = $campDe(31)['id'];
$GLOBALS['corps'] = $promo('Cookies 2+1 · code', null, 'envoyer', 'p31');
$r = wr_promos_magasin();
verifie('sans bon : promotion automatique, l’ancien bon hors service et détaché', ErpApi::$promos[31]['activation_mode'] === 'AUTOMATIC' && ErpApi::$camps[$avant]['id_promotion'] === null
    && ErpApi::$camps[$avant]['status'] === 'DRAFT' && in_array('PATCH /admin/voucher-campaigns/' . $avant . '/status', ErpApi::$appels, true) && $r['panelErreur'] === null, [$r, ErpApi::$camps[$avant]]);
$avant = $campDe(32)['id'];
$GLOBALS['corps'] = $promo('Croissants · codes', ['on' => true, 'cible' => 'code', 'code' => 'CROISSANT'], 'envoyer', 'p32');
$r = wr_promos_magasin();
$nouveau = $campDe(32);
verifie('des codes → un code pour tous : un nouveau bon, l’ancien détaché', $nouveau !== null && $nouveau['id'] !== $avant && $nouveau['code_type'] === 'SHARED' && ErpApi::$camps[$avant]['id_promotion'] === null && $nouveau['status'] === 'ACTIVE', [$nouveau, ErpApi::$camps[$avant]]);

echo "Les refus\n";
$n = count(ErpApi::$camps);
ErpApi::$refusPromo = 'INVALID_PROMOTION_DATA';
$GLOBALS['corps'] = $promo('Refusée', ['on' => true, 'cible' => 'code', 'code' => 'REFUS1']);
$r = @wr_promos_magasin();
ErpApi::$refusPromo = null;
verifie('la promotion refusée : aucun bon créé', count(ErpApi::$camps) === $n && isset($r['error']), $r);
ErpApi::$refusCampagne = 'name is required.';
$GLOBALS['corps'] = $promo('Bon refusé', ['on' => true, 'cible' => 'code', 'code' => 'REFUS2']);
$r = wr_promos_magasin();
ErpApi::$refusCampagne = null;
$pid = (int) ($r['demande']['panelId'] ?? 0);
verifie('le bon refusé : la promotion reste inactive (un brouillon), l’erreur se dit', $pid > 0 && ErpApi::$promos[$pid]['status'] === 'INACTIVE' && ($r['demande']['statut'] ?? '') === 'brouillon'
    && str_contains((string) $r['panelErreur'], 'refuse le bon') && count(ErpApi::$camps) === $n, $r);
$GLOBALS['corps'] = $promo('Code invalide', ['on' => true, 'cible' => 'code', 'code' => 'é!']);
$r = @wr_promos_magasin();
verifie('un code mal formé : 422, rien au panel', ($r['error'] ?? '') === 'à compléter' && str_contains(implode(' ', $r['erreurs']), 'code du bon'), $r);

echo "La bascule depuis la liste\n";
$cid = $campDe(33)['id'];
$GLOBALS['corps'] = ['shop' => 4, 'id' => 'p33', 'actif' => false];
$r = wr_promos_magasin_bon();
verifie('hors service : la campagne INACTIVE, la promotion reste en caisse', ($r['bon']['statut'] ?? '') === 'INACTIVE' && ErpApi::$camps[$cid]['status'] === 'INACTIVE' && ErpApi::$promos[33]['status'] === 'ACTIVE', $r);
$GLOBALS['corps'] = ['shop' => 4, 'id' => 'p33', 'actif' => true];
$r = wr_promos_magasin_bon();
verifie('réactivé : actif et complet', ($r['bon']['actif'] ?? false) && $r['bon']['complete'], $r);
$GLOBALS['corps'] = ['shop' => 4, 'id' => 'p34', 'actif' => true];
ErpApi::$promos[34]['status'] = 'INACTIVE'; $oublie();
$r = @wr_promos_magasin_bon();
verifie('une promotion en brouillon : son bon ne s’active pas d’ici (409)', str_contains((string) ($r['error'] ?? ''), 'brouillon'), $r);
$GLOBALS['corps'] = ['shop' => 5, 'id' => 'p33', 'actif' => false];
$r = @wr_promos_magasin_bon();
verifie('le bon d’un autre magasin : introuvable', ($r['error'] ?? '') === 'promotion introuvable' && ErpApi::$camps[$cid]['status'] === 'ACTIVE', $r);
$_GET = ['shop' => '4', 'id' => 'p30']; $r = @ep_promos_magasin_bon(); $_GET = [];
verifie('une promotion sans bon : « pas de bon » (404)', str_contains((string) ($r['error'] ?? ''), 'pas de bon'), $r);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
