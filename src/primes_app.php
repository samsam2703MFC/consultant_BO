<?php
declare(strict_types=1);

/**
 * Cockpit CEO — les primes de l'app worker, en une liste (demande du 14/10/2026).
 *
 * Chaque prime est un TARGET (les ventes croisées, l'objectif du magasin), une CATÉGORIE de produits
 * (Queen of Tartes, Queen of Quiches) ou une PROMOTION du panel (un bundle, une offre « X achetés + Y
 * offerts »), avec UNE récompense que l'admin règle, 50 € par défaut :
 *
 *  - un target paie chaque vendeuse qui atteint la cible (l'objectif du magasin : chaque personne du
 *    magasin au planning, au-dessus du minimum d'heures) ;
 *  - une catégorie ou une promo paie la 1re du magasin, au nombre de pièces vendues (à égalité, moins
 *    de tickets gagne), au-dessus d'un minimum de pièces.
 *
 * La NOTE GOOGLE est un target aussi (14/10/2026, à la place du coefficient) : quand la note du magasin
 * tient son seuil (4,5), chaque personne du magasin au planning, au-dessus du minimum d'heures, gagne sa
 * récompense, 25 € par défaut.
 *
 * Bats ton record et la meilleure vendeuse gardent leur système (src/ventes.php, onglet Targets &
 * primes) : l'app les montre à côté, avec leurs montants à eux.
 *
 * Chaque prime peut porter une ICÔNE déposée par l'admin (PNG, WebP, JPEG ou SVG, sous
 * public/uploads/primes/) : l'app la montre à la place de l'emoji.
 *
 * Les promos viennent de l'API promotions du panel, et d'elle seule (16/10/2026) : /admin/promotions/
 * bundles, buy-x-get-y, quantity, scheduled-product-discount. Plus de repli sur les bundles du catalogue :
 * tant que le compte API y reçoit 403, la liste est vide et le cockpit le dit. (Le catalogue sert encore à
 * retrouver le produit d'un bundle par son nom.) Une promo devient une famille de la moisson par ses
 * identifiants de produits (pvLignesJour) ; ses pièces se comptent sur ses seules dates et ses seuls magasins.
 *
 * Le réglage vit dans ceo_app_setting.ventePrimesApp ; au premier appel il se déduit des réglages
 * d'avant (concours, minimum d'heures de la prime magasin).
 */

const PRIMES_APP_RECOMPENSE = 50;        // la récompense par défaut d'une prime
const PRIMES_APP_MAX_CATEGORIES = 6;
const PRIMES_APP_MAX_PROMOS = 6;
const PRIMES_PROMOS_CACHE = 3600;         // la liste des promotions du panel : une heure
const PRIMES_MOY_CACHE = 86400;           // les moyennes d'un mois clos : un jour
const PRIMES_GOOGLE_SEUIL = 4.5;          // la note Google à tenir
const PRIMES_GOOGLE_MONTANT = 25;         // la récompense Google, par personne
const PRIMES_ICONE_MAX = 1048576;         // une icône : 1 Mo au plus
const PRIMES_BARRE_OBJ = 75.0;   // la barre des targets : l'objectif à 75 % de sa longueur, sur chaque ligne
const PRIMES_TARGETS = ['croisees', 'objectif', 'google'];  // les targets, toujours là, dans cet ordre

/* ======================================================================
   Le réglage : la liste des primes
   ====================================================================== */

/** La liste d'avant, déduite des réglages déjà posés : ventes croisées, objectif magasin, les concours. */
function primesAppDefauts(): array
{
    $r = PRIMES_APP_RECOMPENSE;
    $mag = setting('ventePrimeMagasin');
    $heuresMin = (is_array($mag) && isset($mag['heuresMin']) && is_numeric($mag['heuresMin'])) ? max(0, min(200, (int) $mag['heuresMin'])) : 20;
    $liste = [
        ['cle' => 'croisees', 'type' => 'target', 'lib' => 'Ventes croisées', 'actif' => true, 'montant' => $r, 'classement' => true],
        ['cle' => 'objectif', 'type' => 'target', 'lib' => 'Objectif magasin', 'actif' => true, 'montant' => $r, 'classement' => false, 'pct' => 100, 'heuresMin' => $heuresMin],
        primesAppGoogleDefaut($heuresMin),
    ];
    // Les concours d'avant deviennent des catégories, au montant de leur couronne du magasin.
    $k = setting('venteConcoursTQ');
    $anciens = (is_array($k) && is_array($k['liste'] ?? null)) ? array_values($k['liste']) : concoursDefauts();
    $actif = !is_array($k) || !array_key_exists('actif', $k) || (bool) $k['actif'];
    foreach ($anciens as $i => $x) {
        $e = is_array($x) ? concoursEntree($x, $i) : null;
        if ($e === null) { continue; }
        $liste[] = ['cle' => $e['cle'], 'type' => 'categorie', 'lib' => $e['lib'], 'actif' => $actif, 'montant' => (int) $e['magasin'],
            'classement' => true, 'motif' => $e['motif'], 'minPieces' => (int) $e['minPieces']];
    }
    return ['recompense' => $r, 'liste' => $liste];
}

/** La note Google par défaut : 25 € par personne à 4,5 ou mieux ; éteinte si l'ancien coefficient l'était. */
function primesAppGoogleDefaut(int $heuresMin = 20): array
{
    $g = setting('ventePrimeGoogle');
    $actif = !is_array($g) || !array_key_exists('actif', $g) || (bool) $g['actif'];
    return ['cle' => 'google', 'type' => 'target', 'lib' => 'Note Google', 'actif' => $actif, 'montant' => PRIMES_GOOGLE_MONTANT,
        'classement' => false, 'seuil' => PRIMES_GOOGLE_SEUIL, 'heuresMin' => $heuresMin];
}

/** Une icône déposée : son chemin sous public/, « uploads/primes/<hex>.<ext> », sinon "". */
function primesAppIcone($v): string
{
    $v = is_string($v) ? trim($v) : '';
    return preg_match('#^uploads/primes/[a-f0-9]{16,40}\.(png|webp|jpg|svg)$#', $v) ? $v : '';
}

/** Un montant de récompense : un entier de 0 à 5 000 €, sinon la valeur par défaut. */
function primesAppMontant($v, int $defaut): int
{
    return (isset($v) && is_numeric($v)) ? max(0, min(5000, (int) round((float) $v))) : $defaut;
}

/** Une date AAAA-MM-JJ, ou null. */
function primesAppDate($v): ?string
{
    if (!is_string($v) || $v === '') { return null; }
    $t = strtotime(substr($v, 0, 10));
    return $t !== false ? date('Y-m-d', $t) : null;
}

/** Une promotion telle que la prime la garde : sa source, sa sorte, son nom, ses dates, ses magasins, ses produits. */
function primesAppPromoPropre($p): ?array
{
    if (!is_array($p)) { return null; }
    // L'API promotions du panel, ou une promotion créée dans le cockpit par l'assistant (16/10/2026) ; une promo
    // « catalogue » d'avant n'est plus gardée.
    $source = in_array($p['source'] ?? '', ['api', 'cockpit'], true) ? (string) $p['source'] : null;
    $id = mb_substr(trim((string) ($p['id'] ?? '')), 0, 40);
    if ($source === null || $id === '') { return null; }
    $shops = [];
    foreach ((array) ($p['shops'] ?? []) as $s) { if (is_scalar($s) && preg_match('/^\d+$/', (string) $s)) { $shops[] = (string) $s; } }
    $ids = [];
    foreach ((array) ($p['ids'] ?? []) as $x) { if (is_numeric($x) && (int) $x > 0) { $ids[] = (int) $x; } }
    $out = ['source' => $source, 'sorte' => $source === 'cockpit' ? 'produits' : (in_array($p['sorte'] ?? '', ['xy', 'quantite', 'remise'], true) ? $p['sorte'] : 'bundle'), 'id' => $id,
        'nom' => mb_substr(trim((string) ($p['nom'] ?? '')), 0, 80), 'prix' => (isset($p['prix']) && is_numeric($p['prix'])) ? round((float) $p['prix'], 2) : null,
        'du' => primesAppDate($p['du'] ?? null), 'au' => primesAppDate($p['au'] ?? null),
        'shops' => array_values(array_unique($shops)), 'ids' => array_values(array_unique($ids))];
    if ($source === 'cockpit') {
        // Une promotion du cockpit : ce qui a été choisi (catégories, sous-catégories, produits), relu en produits ici.
        $out['sel'] = primesSelPropre($p['sel'] ?? []);
        if ($out['sel'] === []) { return null; }
    }
    return $out;
}

/**
 * La clé d'une prime promo : « p » + la source + l'identifiant de la promotion, en minuscules sans signes. Celle d'un
 * franchisé (16/10/2026) porte son magasin — « pm » + magasin + « z » + le reste : la même promotion du panel peut avoir
 * sa prime de marque et la prime de chaque magasin.
 */
function primesAppClePromo(array $promo, ?string $shop = null): string
{
    $id = preg_replace('/[^a-z0-9]+/', '', strtolower($promo['id'])) ?? '';
    $sorte = $promo['source'] === 'api' ? (['xy' => 'x', 'quantite' => 'q', 'remise' => 'r'][$promo['sorte']] ?? 'b') : ($promo['source'] === 'cockpit' ? 'k' : 'c');
    return mb_substr($shop !== null ? 'pm' . $shop . 'z' . $sorte . $id : 'p' . $sorte . $id, 0, 24);
}

/**
 * Qui paie une prime de la marque : la marque ou un sponsor nommé (14/10/2026). La marque par défaut ; un sponsor
 * sans nom reste à la marque. Le magasin ne paie que SES primes, celles du franchisé (16/10/2026) : « magasin »
 * demandé pour une prime de la marque revient à la marque.
 */
function primesAppPayeur(array $x): array
{
    $p = (string) ($x['payeur'] ?? 'marque');
    $nom = mb_substr(trim((string) ($x['sponsor'] ?? '')), 0, 60);
    if ($p === 'sponsor' && $nom !== '') { return ['payeur' => 'sponsor', 'sponsor' => $nom]; }
    return ['payeur' => 'marque', 'sponsor' => ''];
}

/** La clé de comptabilité d'un payeur : « marque », « magasin » ou « sponsor:Nom ». */
function primesPayeurCle(array $e): string
{
    return ($e['payeur'] ?? 'marque') === 'sponsor' ? 'sponsor:' . ($e['sponsor'] ?? '') : (($e['payeur'] ?? '') === 'magasin' ? 'magasin' : 'marque');
}

/**
 * Une entrée de la liste, nettoyée, ou null si elle ne tient pas debout. Les deux targets ont leur
 * clé fixe ; une catégorie tire la sienne de son titre (concoursEntree) ; une promo, de sa promotion.
 * Chacune dit qui la paie et à quelle famille elle appartient (16/10/2026) : « marque » — posée par la marque,
 * payée par elle ou un sponsor, visible de chaque franchisé — ou « magasin » — une prime promo qu'un franchisé
 * pose pour SON magasin, un seul, et qu'il paie.
 */
function primesAppEntreeValide(array $x, int $i, int $recompense): ?array
{
    $e = primesAppEntreeValideSansPayeur($x, $i, $recompense);
    if ($e === null) { return null; }
    $icone = ['icone' => primesAppIcone($x['icone'] ?? null)];
    if (($x['famille'] ?? '') === 'magasin') {
        if ($e['type'] !== 'promo' || count($e['promo']['shops']) !== 1) { return null; }
        $shop = $e['promo']['shops'][0];
        $e['cle'] = primesAppClePromo($e['promo'], $shop);
        return $e + ['famille' => 'magasin', 'shop' => $shop, 'payeur' => 'magasin', 'sponsor' => ''] + $icone;
    }
    return $e + ['famille' => 'marque'] + primesAppPayeur($x) + $icone;
}

function primesAppEntreeValideSansPayeur(array $x, int $i, int $recompense): ?array
{
    $type = (string) ($x['type'] ?? '');
    $actif = !array_key_exists('actif', $x) || (bool) $x['actif'];
    $montant = primesAppMontant($x['montant'] ?? null, $recompense);
    if ($type === 'target') {
        $cle = (string) ($x['cle'] ?? '');
        if ($cle === 'croisees') {
            // La catégorie (14/10/2026) : une vente croisée est un ticket à deux lignes ou plus dont une au moins de cette
            // catégorie de produits ; vide : toutes les catégories (la règle d'avant).
            return ['cle' => 'croisees', 'type' => 'target', 'lib' => mb_substr(trim((string) ($x['lib'] ?? '')), 0, 40) ?: 'Ventes croisées',
                'actif' => $actif, 'montant' => $montant, 'classement' => !array_key_exists('classement', $x) || (bool) $x['classement'],
                'categorie' => mb_substr(trim((string) ($x['categorie'] ?? '')), 0, 80)];
        }
        if ($cle === 'objectif') {
            $pct = (isset($x['pct']) && is_numeric($x['pct'])) ? max(50, min(200, (int) round((float) $x['pct']))) : 100;
            $hMin = (isset($x['heuresMin']) && is_numeric($x['heuresMin'])) ? max(0, min(200, (int) $x['heuresMin'])) : 20;
            return ['cle' => 'objectif', 'type' => 'target', 'lib' => mb_substr(trim((string) ($x['lib'] ?? '')), 0, 40) ?: 'Objectif magasin',
                'actif' => $actif, 'montant' => $montant, 'classement' => false, 'pct' => $pct, 'heuresMin' => $hMin];
        }
        if ($cle === 'google') {
            // La note Google : sa récompense à elle (25 € par défaut, pas celle des autres primes), son seuil, son minimum d'heures.
            $seuil = (isset($x['seuil']) && is_numeric($x['seuil'])) ? max(1.0, min(5.0, round((float) $x['seuil'], 1))) : PRIMES_GOOGLE_SEUIL;
            $hMin = (isset($x['heuresMin']) && is_numeric($x['heuresMin'])) ? max(0, min(200, (int) $x['heuresMin'])) : 20;
            return ['cle' => 'google', 'type' => 'target', 'lib' => mb_substr(trim((string) ($x['lib'] ?? '')), 0, 40) ?: 'Note Google',
                'actif' => $actif, 'montant' => primesAppMontant($x['montant'] ?? null, PRIMES_GOOGLE_MONTANT), 'classement' => false, 'seuil' => $seuil, 'heuresMin' => $hMin];
        }
        return null;
    }
    if ($type === 'categorie') {
        $e = concoursEntree($x, $i);
        if ($e === null || in_array($e['cle'], PRIMES_TARGETS, true) || str_starts_with($e['cle'], 'p')) {
            // Une catégorie ne prend jamais la clé d'un target ni la forme d'une clé de promo.
            if ($e === null) { return null; }
            $e['cle'] = 'k' . $e['cle'];
        }
        $min = (isset($x['minPieces']) && is_numeric($x['minPieces'])) ? max(0, min(5000, (int) $x['minPieces'])) : 10;
        return ['cle' => mb_substr($e['cle'], 0, 20), 'type' => 'categorie', 'lib' => $e['lib'], 'actif' => $actif, 'montant' => $montant,
            'classement' => !array_key_exists('classement', $x) || (bool) $x['classement'], 'motif' => $e['motif'], 'minPieces' => $min];
    }
    if ($type === 'promo') {
        $promo = primesAppPromoPropre($x['promo'] ?? null);
        if ($promo === null) { return null; }
        $lib = mb_substr(trim((string) ($x['lib'] ?? '')), 0, 60);
        $min = (isset($x['minPieces']) && is_numeric($x['minPieces'])) ? max(0, min(5000, (int) $x['minPieces'])) : 1;
        $regle = in_array($x['regle'] ?? '', ['seuil', 'magasin', 'vendeuse'], true) ? $x['regle'] : 'premiere';
        $e = ['cle' => primesAppClePromo($promo), 'type' => 'promo', 'lib' => $lib !== '' ? $lib : ($promo['nom'] !== '' ? $promo['nom'] : 'Promo'),
            'actif' => $actif, 'montant' => $montant, 'classement' => !array_key_exists('classement', $x) || (bool) $x['classement'],
            'minPieces' => $min, 'regle' => $regle, 'promo' => $promo];
        if ($regle === 'magasin' || $regle === 'vendeuse') {
            // Sur objectif (16/10/2026) : l'objectif de chaque magasin (pièces), et pour « vendeuse » sa part par vendeuse.
            $o = primesAppObjectifs($x, $promo['shops']);
            if ($o === null) { return null; }
            $e += $o;
        }
        return $e;
    }
    return null;
}

/**
 * Les objectifs d'une prime promo sur objectif : {objectifs: {magasin: pièces}, vendeuses: {magasin: nombre}, objVend:
 * {magasin: pièces par vendeuse}, pct, n1: {magasin: pièces l'an dernier}} — ou null s'il n'y en a aucun. Une promo de
 * quelques magasins ne garde que les leurs. Par vendeuse : l'objectif du magasin ÷ ses vendeuses, arrondi au-dessus.
 */
function primesAppObjectifs(array $x, array $shops): ?array
{
    $num = static fn ($v, float $max) => is_numeric($v) && (float) $v > 0 ? min($max, round((float) $v, 1)) : null;
    $obj = []; $vend = []; $ref = []; $parV = [];
    foreach ((array) ($x['objectifs'] ?? []) as $sid => $v) {
        $sid = (string) $sid;
        if (!preg_match('/^\d+$/', $sid) || ($shops !== [] && !in_array($sid, $shops, true))) { continue; }
        $o = $num($v, 100000);
        if ($o === null) { continue; }
        $obj[$sid] = (int) ceil($o);
        $n = $num(((array) ($x['vendeuses'] ?? []))[$sid] ?? null, 200);
        if ($n !== null) { $vend[$sid] = $n; }
        $parV[$sid] = (int) max(1, ceil($obj[$sid] / max(1.0, $n ?? 1.0)));
        $r = ((array) ($x['n1'] ?? []))[$sid] ?? null;
        if (is_numeric($r) && (float) $r >= 0) { $ref[$sid] = round((float) $r, 1); }
    }
    if ($obj === []) { return null; }
    $pct = isset($x['pct']) && is_numeric($x['pct']) ? max(-90, min(500, (int) round((float) $x['pct']))) : null;
    return ['objectifs' => $obj, 'vendeuses' => $vend, 'objVend' => $parV, 'pct' => $pct, 'n1' => $ref];
}

/**
 * Une liste propre : les targets toujours là et en tête, pas de doublon, des catégories et des promos en nombre borné —
 * les promos de la marque d'un côté, celles de chaque magasin de l'autre (autant par magasin).
 */
function primesAppListePropre(array $liste, int $recompense): array
{
    $out = []; $vus = []; $nCat = 0; $nPro = 0; $nMag = [];
    foreach (array_values($liste) as $i => $x) {
        $e = is_array($x) ? primesAppEntreeValide($x, $i, $recompense) : null;
        if ($e === null || isset($vus[$e['cle']])) { continue; }
        if ($e['type'] === 'categorie' && ++$nCat > PRIMES_APP_MAX_CATEGORIES) { continue; }
        if ($e['famille'] === 'magasin') {
            $nMag[$e['shop']] = ($nMag[$e['shop']] ?? 0) + 1;
            if ($nMag[$e['shop']] > PRIMES_APP_MAX_PROMOS) { continue; }
        } elseif ($e['type'] === 'promo' && ++$nPro > PRIMES_APP_MAX_PROMOS) { continue; }
        $vus[$e['cle']] = true;
        $out[] = $e;
    }
    $def = primesAppDefauts()['liste'];
    foreach (PRIMES_TARGETS as $cle) {
        if (isset($vus[$cle])) { continue; }
        // Une liste enregistrée avant la note Google la reçoit avec ses 25 € ; les autres targets, à la récompense par défaut.
        foreach ($def as $d) { if ($d['cle'] === $cle) { $out[] = array_merge($d, $cle === 'google' ? [] : ['montant' => $recompense], ['famille' => 'marque'], primesAppPayeur([]), ['icone' => '']); } }
    }
    // Les targets d'abord, dans leur ordre ; le reste dans l'ordre reçu (usort est stable).
    usort($out, static function ($a, $b) {
        $r = static fn ($e) => ($i = array_search($e['cle'], PRIMES_TARGETS, true)) === false ? 9 : $i;
        return $r($a) <=> $r($b);
    });
    return $out;
}

/**
 * Le réglage en vigueur : {recompense, liste, externes: {record, meilleure} (qui paie ces deux-là, réglées
 * ailleurs), sms: {actif}}. Gardé le temps de la requête ; $relire le relit (après un enregistrement).
 */
function primesAppReglages(bool $relire = false): array
{
    static $memo = null;
    if ($memo !== null && !$relire) { return $memo; }
    $c = setting('ventePrimesApp');
    if (!is_array($c) || !is_array($c['liste'] ?? null)) { $c = primesAppDefauts(); }
    $r = primesAppMontant($c['recompense'] ?? null, PRIMES_APP_RECOMPENSE);
    return $memo = ['recompense' => $r, 'liste' => primesAppListePropre($c['liste'], $r),
        'externes' => primesAppExternes($c['externes'] ?? null), 'sms' => ['actif' => (bool) ($c['sms']['actif'] ?? false)]];
}

/** Qui paie Bats ton record et la meilleure vendeuse (la marque par défaut), et leur icône. */
function primesAppExternes($x): array
{
    $x = is_array($x) ? $x : [];
    $un = static fn ($e) => primesAppPayeur((array) $e) + ['icone' => primesAppIcone(((array) $e)['icone'] ?? null)];
    return ['record' => $un($x['record'] ?? []), 'meilleure' => $un($x['meilleure'] ?? [])];
}

/** L'icône d'une prime par sa clé (une prime de la liste, « record » ou « meilleure »), "" sans icône. */
function primesIconeDe(string $cle): string
{
    $ext = primesAppReglages()['externes'];
    if (isset($ext[$cle])) { return (string) $ext[$cle]['icone']; }
    return (string) (primesAppEntree($cle)['icone'] ?? '');
}

/** Le payeur d'une prime par sa clé (une prime de la liste, « record » ou « meilleure ») : « marque », « magasin », « sponsor:Nom ». */
function primesPayeurDe(string $cle): string
{
    $ext = primesAppReglages()['externes'];
    if (isset($ext[$cle])) { return primesPayeurCle($ext[$cle]); }
    $e = primesAppEntree($cle);
    return $e !== null ? primesPayeurCle($e) : 'marque';
}

/** Une entrée par sa clé, ou null. */
function primesAppEntree(string $cle): ?array
{
    foreach (primesAppReglages()['liste'] as $e) { if ($e['cle'] === $cle) { return $e; } }
    return null;
}

/** Les entrées d'un type, actives seulement si demandé. */
function primesAppDuType(string $type, bool $actives = true): array
{
    return array_values(array_filter(primesAppReglages()['liste'], static fn ($e) => $e['type'] === $type && (!$actives || $e['actif'])));
}

/** Les fiches calculées en cache (le mois en cours, tout le monde) : un réglage change, elles se refont. */
function primesCachesVider(): void
{
    $m = date('Y-m');
    try {
        Db::exec('DELETE FROM ceo_app_setting WHERE `key` LIKE ? OR `key` = ?', ['primesMoi%:' . $m, 'primesTous' . $m]);
    } catch (Throwable $e) { /* le cache expire de lui-même */ }
}

/**
 * POST /ventes/primes-app {recompense, liste: [{type, cle, lib, actif, montant, classement, …}]} — la liste
 * des primes de l'app. Une promo nouvelle arrive avec sa référence ({type: promo, ref: {source, id}}) :
 * le cockpit la relit dans la liste des promotions du panel, rien de ce que le navigateur envoie sur ses
 * produits n'est cru. Une promo déjà là garde ce qu'elle avait.
 */
function wr_primes_app(): array
{
    $b = body();
    $cur = primesAppReglages();
    $r = primesAppMontant($b['recompense'] ?? null, $cur['recompense']);
    $liste = $cur['liste'];
    if (array_key_exists('liste', $b)) {
        if (!is_array($b['liste'])) { http_response_code(422); return ['error' => 'liste attendue']; }
        $deja = [];
        foreach ($cur['liste'] as $e) { $deja[$e['cle']] = $e; }
        $promosPanel = null;
        $liste = [];
        foreach (array_values($b['liste']) as $i => $x) {
            if (!is_array($x)) { continue; }
            if (($x['type'] ?? '') === 'promo') {
                $cle = (string) ($x['cle'] ?? '');
                // La prime d'un franchisé (16/10/2026) : un magasin, et un seul.
                $shop = is_scalar($x['shop'] ?? null) && preg_match('/^\d+$/', (string) $x['shop']) ? (string) $x['shop'] : '';
                if ($cle !== '' && isset($deja[$cle]) && $deja[$cle]['type'] === 'promo') {
                    // Déjà là : sa promotion et sa famille restent ce qu'elles étaient. Rouverte dans l'assistant (« refaire »,
                    // 16/10/2026), une promo du cockpit relit ses produits, ses dates et ses magasins — même identifiant, même clé.
                    $x['famille'] = $deja[$cle]['famille'] ?? 'marque';
                    $x['promo'] = $deja[$cle]['promo'];
                    if (!empty($x['refaire']) && ($deja[$cle]['promo']['source'] ?? '') === 'cockpit' && is_array($x['promo_neuve'] ?? null)) {
                        $pc = $x['promo_neuve'];
                        if ($x['famille'] === 'magasin') { $pc['shops'] = $deja[$cle]['promo']['shops']; }
                        $p = primesPromoCockpit($pc, (string) ($x['lib'] ?? ''));
                        if (isset($p['error'])) { http_response_code(422); return ['error' => 'prime ' . ($i + 1) . ' : ' . $p['error']]; }
                        $p['id'] = $deja[$cle]['promo']['id'];
                        $x['promo'] = $p;
                    }
                } elseif (($x['ref']['source'] ?? '') === 'cockpit') {
                    $pc = is_array($x['promo'] ?? null) ? $x['promo'] : [];
                    if (($x['famille'] ?? '') === 'magasin') { $pc['shops'] = $shop !== '' ? [$shop] : []; }
                    $p = primesPromoCockpit($pc, (string) ($x['lib'] ?? ''));
                    if (isset($p['error'])) { http_response_code(422); return ['error' => 'prime ' . ($i + 1) . ' : ' . $p['error']]; }
                    $x['promo'] = $p;
                } else {
                    $ref = is_array($x['ref'] ?? null) ? $x['ref'] : [];
                    $promosPanel ??= primesPromosPanel();
                    $trouve = null;
                    foreach ($promosPanel['liste'] as $p) { if ($p['source'] === ($ref['source'] ?? '') && $p['id'] === (string) ($ref['id'] ?? '')) { $trouve = $p; } }
                    if ($trouve === null) { http_response_code(422); return ['error' => 'promotion ' . ($i + 1) . ' introuvable dans la liste du panel']; }
                    if (($x['famille'] ?? '') === 'magasin') {
                        // Une promotion du panel prise par un franchisé : ses ventes dans SON magasin, si elle y tourne.
                        if ($shop === '' || ($trouve['shops'] !== [] && !in_array($shop, $trouve['shops'], true))) {
                            http_response_code(422); return ['error' => 'prime ' . ($i + 1) . ' : la promotion ne tourne pas dans ce magasin'];
                        }
                        $trouve['shops'] = [$shop];
                    }
                    $x['promo'] = $trouve;
                }
                if (($x['famille'] ?? '') === 'magasin' && $x['promo']['shops'] === []) {
                    http_response_code(422); return ['error' => 'prime ' . ($i + 1) . ' : une prime de franchisé est pour un magasin'];
                }
            }
            $e = primesAppEntreeValide($x, $i, $r);
            if ($e === null) {
                http_response_code(422);
                return ['error' => 'prime ' . ($i + 1) . ' : ' . (($x['type'] ?? '') === 'categorie' ? 'un titre et un motif valide (une expression comme tarte)'
                    : (in_array($x['regle'] ?? '', ['magasin', 'vendeuse'], true) ? 'un objectif pour au moins un de ses magasins' : 'réglage illisible'))];
            }
            $liste[] = $e;
        }
        $liste = primesAppListePropre($liste, $r);
    }
    $ext = array_key_exists('externes', $b) ? primesAppExternes($b['externes']) : $cur['externes'];
    $sms = array_key_exists('sms', $b) ? ['actif' => (bool) (is_array($b['sms']) ? ($b['sms']['actif'] ?? false) : $b['sms'])] : $cur['sms'];
    $out = ['recompense' => $r, 'liste' => $liste, 'externes' => $ext, 'sms' => $sms];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['ventePrimesApp', json_encode($out, JSON_UNESCAPED_UNICODE)]);
    primesAppReglages(true);
    primesCachesVider();
    $payeLib = static fn (array $e) => $e['payeur'] === 'sponsor' ? 'sponsor ' . $e['sponsor'] : ($e['payeur'] === 'magasin' ? 'magasin ' . ($e['shop'] ?? '') : $e['payeur']);
    journalAdd('CEO', 'Paramètre', 'Primes de l’app', 'Récompense par défaut ' . $r . ' € · ' . implode(' · ', array_map(
        static fn ($e) => $e['lib'] . ' ' . $e['montant'] . ' € (' . $payeLib($e) . ')' . ($e['actif'] ? '' : ' (éteinte)'), $liste))
        . ' · SMS à la réussite ' . ($sms['actif'] ? 'activés' : 'éteints'));
    return ['ok' => true, 'reglages' => $out];
}

/* ======================================================================
   Les icônes des primes
   ====================================================================== */

/**
 * POST /ventes/primes-icone {data: data-URL} — une icône pour une prime (14/10/2026) : PNG, WebP, JPEG
 * ou SVG, 1 Mo au plus. Écrite sous public/uploads/primes/ avec un nom tiré au hasard ; rend son chemin,
 * que la liste des primes garde ensuite (POST /ventes/primes-app, champ « icone »).
 */
function wr_primes_icone(): array
{
    $r = primesIconeEcrire((string) (body()['data'] ?? ''));
    if (isset($r['error'])) { http_response_code($r['code']); return ['error' => $r['error']]; }
    journalAdd('CEO', 'Paramètre', 'Primes de l’app', 'Icône déposée : ' . $r['icone']);
    return ['ok' => true, 'icone' => $r['icone']];
}

/** Lit une icône en data-URL, la vérifie et l'écrit dans $dossier (public/uploads/primes par défaut). */
function primesIconeEcrire(string $data, ?string $dossier = null): array
{
    if (!preg_match('#^data:([\w/+.-]+)(?:;[\w=-]+)*;base64,(.+)$#s', $data, $m)) { return ['code' => 422, 'error' => 'icône illisible (data-URL attendue)']; }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 32) { return ['code' => 422, 'error' => 'icône illisible']; }
    if (strlen($bin) > PRIMES_ICONE_MAX) { return ['code' => 413, 'error' => 'icône trop lourde — 1 Mo au maximum']; }
    $info = @getimagesizefromstring($bin);
    $ext = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'][$info['mime'] ?? ''] ?? null;
    if ($ext !== null && ($info[0] < 16 || $info[1] < 16)) { return ['code' => 422, 'error' => 'icône trop petite — 16 px au moins']; }
    if ($ext === null && primesSvgSain($bin)) { $ext = 'svg'; }
    if ($ext === null) { return ['code' => 415, 'error' => 'format non accepté — PNG, WebP, JPEG ou SVG (sans script ni lien externe)']; }
    $dos = $dossier ?? __DIR__ . '/../public/uploads/primes';
    if (!is_dir($dos) && !@mkdir($dos, 0775, true) && !is_dir($dos)) { return ['code' => 500, 'error' => 'dossier des icônes impossible à créer']; }
    $nom = bin2hex(random_bytes(10)) . '.' . $ext;
    if (@file_put_contents($dos . '/' . $nom, $bin) === false) { return ['code' => 500, 'error' => 'écriture de l’icône impossible']; }
    return ['icone' => 'uploads/primes/' . $nom];
}

/**
 * Un SVG qu'on peut servir : un <svg>, sans script, sans gestionnaire d'événement, sans objet embarqué,
 * sans entité, et dont les liens ne pointent que dans le fichier (#id) ou vers une image en data:.
 */
function primesSvgSain(string $s): bool
{
    if (!preg_match('#<svg[\s>]#i', $s)) { return false; }
    if (preg_match('#<!(?:DOCTYPE|ENTITY)|<\s*(?:script|foreignObject|iframe|embed|object|handler|listener)\b|\son[a-z]+\s*=|javascript:|@import|&\##i', $s)) { return false; }
    // Une animation ne réécrit ni un gestionnaire d'événement ni un lien.
    if (preg_match('#attributeName\s*=\s*(["\'])\s*(?:on|(?:xlink:)?href)#i', $s)) { return false; }
    preg_match_all('#\b(?:xlink:)?href\s*=\s*(["\'])(.*?)\1#is', $s, $mm);
    foreach ($mm[2] as $h) {
        $h = trim($h);
        if ($h !== '' && $h[0] !== '#' && !preg_match('#^data:image/(?:png|jpeg|webp);base64,#i', $h)) { return false; }
    }
    // url(…) seulement vers un élément du fichier : url(#dégradé).
    return !preg_match('#url\(\s*(["\']?)\s*(?!\#)#i', $s);
}

/* ======================================================================
   Les promotions du panel
   ====================================================================== */

/** La première valeur non vide parmi des clés, éventuellement dans un sous-objet (« product.id »). */
function primesPromoChamp(array $x, array $cles)
{
    foreach ($cles as $k) {
        $v = $x;
        foreach (explode('.', $k) as $p) { $v = is_array($v) && array_key_exists($p, $v) ? $v[$p] : null; }
        if ($v !== null && $v !== '' && $v !== []) { return $v; }
    }
    return null;
}

/** Les identifiants de produits nommés dans une promotion : les listes de produits, d'articles, de lignes. */
function primesPromoProduits(array $x): array
{
    $ids = [];
    // Les noms de l'API promotions (OpenAPI du panel, 16/10/2026) : items (bundle), eligible_product_ids (quantité),
    // reward_product_ids (remise programmée), trigger/reward.product_ids (X + Y) ; les noms d'usage en plus.
    foreach (['products', 'items', 'lines', 'bundle_items', 'bundle_products', 'buy_products', 'get_products', 'x_products', 'y_products', 'products_x', 'products_y', 'product_ids',
              'eligible_product_ids', 'reward_product_ids', 'trigger.product_ids', 'reward.product_ids'] as $k) {
        foreach ((array) (primesPromoChamp($x, [$k]) ?? []) as $l) {
            if (is_numeric($l)) { $ids[] = (int) $l; continue; }
            if (!is_array($l)) { continue; }
            $v = primesPromoChamp($l, ['id_product', 'product_id', 'product.id', 'id']);
            if (is_numeric($v)) { $ids[] = (int) $v; }
        }
    }
    return array_values(array_unique(array_filter($ids, static fn ($v) => $v > 0)));
}

/** Les produits des catégories qu'une promotion vise, par leur nom dans le catalogue (sous-catégorie, sinon catégorie). */
function primesPromoCategoriesIds(array $x): array
{
    $noms = [];
    foreach (['trigger.categories', 'reward.categories', 'eligible_categories', 'reward_categories', 'categories'] as $k) {
        foreach ((array) (primesPromoChamp($x, [$k]) ?? []) as $c) {
            $n = is_array($c) ? ($c['name'] ?? $c['nom'] ?? null) : null;
            if (is_string($n) && trim($n) !== '') { $noms[mb_strtolower(trim($n))] = true; }
        }
    }
    if ($noms === []) { return []; }
    $ids = [];
    foreach (primesCatalogue() as $p) {
        if (isset($noms[mb_strtolower($p['cat'])]) || isset($noms[mb_strtolower($p['grp'])])) { $ids[] = (int) $p['id']; }
    }
    return $ids;
}

/**
 * Une promotion du panel, mise à plat : {source, sorte, id, nom, prix, du, au, shops, ids, composants}.
 * Les clés de l'API ne sont pas encore connues (403 au 10/10/2026) : on lit les noms d'usage.
 * Un bundle se compte par SON produit (une ligne du ticket) : son propre identifiant s'il en a un,
 * sinon le produit du catalogue qui porte son nom. Une offre X + Y se compte par ses produits.
 */
function primesPromoNormaliser(array $x, string $sorte, array $bundlesCatalogue): ?array
{
    $id = primesPromoChamp($x, ['id', 'uuid', 'id_promotion']);
    if (!is_scalar($id) || (string) $id === '') { return null; }
    $actif = primesPromoChamp($x, ['is_active', 'active', 'enabled']);
    if ($actif !== null && !$actif) { return null; }
    $statut = primesPromoChamp($x, ['status']);
    if (is_string($statut) && strtoupper($statut) !== 'ACTIVE') { return null; }   // brouillon ou éteinte
    $nom = trim((string) (primesPromoChamp($x, ['name', 'title', 'label', 'nom', 'description']) ?? ''));
    $du = primesAppDate(primesPromoChamp($x, ['starts_at', 'start_date', 'date_start', 'date_from', 'valid_from', 'start', 'from']));
    $au = primesAppDate(primesPromoChamp($x, ['ends_at', 'end_date', 'date_end', 'date_to', 'valid_to', 'valid_until', 'end', 'to']));
    if ($au !== null && $au < date('Y-m-01', strtotime('first day of last month'))) { return null; }   // finie depuis longtemps
    $shops = [];
    $lus = primesPromoChamp($x, ['shops', 'shop_ids', 'id_shops']) ?? [];
    if (is_string($lus)) { $lus = explode(',', $lus); }   // la liste de l'API : « 4 » ou « 4,5 »
    foreach ((array) $lus as $s) {
        $v = is_array($s) ? ($s['id'] ?? $s['id_shop'] ?? null) : (is_string($s) ? trim($s) : $s);
        if (is_numeric($v)) { $shops[] = (string) (int) $v; }
    }
    $s1 = primesPromoChamp($x, ['id_shop', 'shop_id']);
    if (is_numeric($s1)) { $shops[] = (string) (int) $s1; }
    // Les produits nommés, et ceux des catégories visées (trigger/reward.categories : « Cookies ») relues dans le
    // catalogue par leur nom — sous-catégorie ou catégorie.
    $composants = array_values(array_unique(array_merge(primesPromoProduits($x), primesPromoCategoriesIds($x))));
    if ($sorte === 'bundle') {
        $propre = primesPromoChamp($x, ['id_product', 'product_id', 'product.id']);
        $ids = is_numeric($propre) ? [(int) $propre] : [];
        if ($ids === [] && $nom !== '') {
            foreach ($bundlesCatalogue as $b) { if (mb_strtolower($b['nom']) === mb_strtolower($nom)) { $ids = $b['ids']; } }
        }
        // Un bundle de l'API sans produit à lui : ses articles (items) se comptent.
        if ($ids === []) { $ids = $composants; }
    } else {
        $ids = $composants;
    }
    $prix = primesPromoChamp($x, ['price', 'bundle_price', 'bundle_promotion.bundle_price', 'total_price', 'amount', 'prix']);
    return ['source' => 'api', 'sorte' => $sorte, 'id' => (string) $id, 'nom' => $nom !== '' ? $nom : ('Promotion ' . $id),
        'prix' => is_numeric($prix) ? round((float) $prix, 2) : null, 'du' => $du, 'au' => $au,
        'shops' => array_values(array_unique($shops)), 'ids' => $ids, 'composants' => $composants];
}

/** Les bundles du catalogue : les produits de la catégorie « Bundle » (groupe « Bundle & Promotion »), sans dates ni magasins. */
function primesPromosCatalogue(): array
{
    if (!function_exists('svCategories')) { return []; }
    $out = [];
    try {
        foreach (svCategories() as $pid => $cat) {
            if (preg_match('/bundle|promotion/iu', (string) $cat) !== 1) { continue; }
            $nom = function_exists('svNomProduit') ? svNomProduit((int) $pid, '') : '';
            $out[] = ['source' => 'catalogue', 'sorte' => 'bundle', 'id' => (string) $pid, 'nom' => $nom !== '' ? $nom : ('Produit ' . $pid),
                'prix' => null, 'du' => null, 'au' => null, 'shops' => [], 'ids' => [(int) $pid], 'composants' => []];
        }
    } catch (Throwable $e) { /* catalogue muet : pas de repli aujourd'hui */ }
    usort($out, static fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
    return $out;
}

/**
 * Les promotions du panel qu'une prime peut suivre : celles de l'API promotions, et d'elle seule (bundles
 * actifs, offres X + Y, prix par quantité, remises programmées). Gravé une heure.
 *
 * @return array{quand:int, api:bool, erreurs:list<array{chemin:string,code:int,erreur:?string}>, liste:list<array>}
 */
function primesPromosPanel(bool $frais = false): array
{
    $c = setting('primesPromos3');
    if (!$frais && is_array($c) && isset($c['liste']) && (int) ($c['quand'] ?? 0) > time() - PRIMES_PROMOS_CACHE) { return $c; }
    $catalogue = primesPromosCatalogue();
    $liste = []; $erreurs = []; $api = false;
    // Les promotions ne vivent que sous /admin (l'API consultant n'en a aucune) : le compte admin ERP
    // (Paramètres → Compte admin ERP, POST /admin/auth/login) les lit ; le compte consultant reste en repli,
    // il se voit répondre 403 (16/10/2026).
    $compte = class_exists('ErpApi') && ErpApi::disponible() ? 'admin' : (class_exists('PanelApi') && PanelApi::configured() ? 'consultant' : null);
    if ($compte !== null) {
        foreach (['bundle' => '/admin/promotions/bundles', 'xy' => '/admin/promotions/buy-x-get-y',
                  'quantite' => '/admin/promotions/quantity', 'remise' => '/admin/promotions/scheduled-product-discount'] as $sorte => $route) {
            $chemin = $route . '?status=ACTIVE&limit=100';
            $r = $compte === 'admin' ? ErpApi::sondeGet($chemin) : PanelApi::sondeGet($chemin, 20);
            if ((int) $r['code'] < 200 || (int) $r['code'] >= 300 || !is_array($r['corps'])) {
                $erreurs[] = ['chemin' => $chemin, 'code' => (int) $r['code'], 'erreur' => $r['erreur'] ?? null];
                continue;
            }
            $api = true;
            foreach (PanelApi::liste($r['corps']) as $x) {
                if (!is_array($x)) { continue; }
                // La liste ne porte que des compteurs : les produits et les catégories sont dans la fiche
                // (/admin/promotions/{sorte}/{id} — items, trigger, reward…), lue promotion par promotion (16/10/2026).
                if ($compte === 'admin' && isset($x['id']) && is_scalar($x['id'])) {
                    $f = ErpApi::sondeGet($route . '/' . rawurlencode((string) $x['id']));
                    if ((int) $f['code'] >= 200 && (int) $f['code'] < 300 && is_array($f['corps'])) { $x = array_merge($x, $f['corps']['data'] ?? $f['corps']); }
                }
                $p = primesPromoNormaliser($x, $sorte, $catalogue);
                if ($p !== null) { $liste[] = $p; }
            }
        }
    } else {
        $erreurs[] = ['chemin' => '/admin/promotions', 'code' => 0, 'erreur' => 'aucun compte API configuré'];
    }
    $out = ['quand' => time(), 'api' => $api, 'compte' => $compte, 'erreurs' => $erreurs, 'liste' => $liste];
    if ($liste !== [] || $erreurs !== []) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['primesPromos3', json_encode($out, JSON_UNESCAPED_UNICODE)]);
    }
    return $out;
}

/**
 * GET /ventes/primes-promos/sonde — la forme brute de l'API promotions (compte admin ERP), lecture seule : pour chaque
 * liste, ses clés, le 1er élément et sa fiche (/admin/promotions/{sorte}/{id}). De quoi régler la lecture des produits.
 */
function ep_primes_promos_sonde(): array
{
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return ['erreur' => 'compte admin ERP indisponible']; }
    $out = [];
    foreach (['bundles', 'buy-x-get-y', 'quantity', 'scheduled-product-discount'] as $r) {
        $l = ErpApi::sondeGet('/admin/promotions/' . $r . '?status=ACTIVE&limit=2');
        $items = is_array($l['corps']) ? PanelApi::liste($l['corps']) : [];
        $un = $items[0] ?? null;
        $fiche = null;
        if (is_array($un) && isset($un['id']) && is_scalar($un['id'])) {
            $f = ErpApi::sondeGet('/admin/promotions/' . $r . '/' . rawurlencode((string) $un['id']));
            $fiche = ['code' => $f['code'], 'corps' => $f['corps']];
        }
        $out[$r] = ['code' => $l['code'], 'cles' => is_array($l['corps']) ? array_keys($l['corps']) : null, 'item' => $un, 'fiche' => $fiche];
    }
    return $out;
}

/**
 * GET /ventes/primes-reference?ids=1,2&du=&au=&shops=4,5 — pour l'assistant (16/10/2026) : ce que chaque magasin a vendu de
 * ces produits sur la même période l'an dernier (`n1`, en pièces ; /shops/{id}/statistics/sales/product-category-groups,
 * gardé par apTranches2), et à défaut au rythme des 28 derniers jours ramené à la période (`recent`). `ref` : n1 s'il y en a,
 * sinon recent ; `source` le dit. Sans période : le mois en cours.
 */
function ep_primes_reference(): array
{
    $ids = [];
    foreach (explode(',', (string) ($_GET['ids'] ?? '')) as $v) { if (ctype_digit(trim($v))) { $ids[(int) trim($v)] = true; } }
    if ($ids === []) { http_response_code(422); return ['error' => 'aucun produit']; }
    $du = primesAppDate($_GET['du'] ?? null) ?? date('Y-m-01');
    $au = primesAppDate($_GET['au'] ?? null) ?? date('Y-m-t');
    if ($au < $du) { [$du, $au] = [$au, $du]; }
    $jours = (int) round((strtotime($au) - strtotime($du)) / 86400) + 1;
    $nomDe = primesMagasins();
    $shops = [];
    foreach (explode(',', (string) ($_GET['shops'] ?? '')) as $v) { $v = trim($v); if ($v !== '' && isset($nomDe[$v])) { $shops[] = (int) $v; } }
    if ($shops === []) { $shops = array_map('intval', array_keys($nomDe)); }
    $du1 = date('Y-m-d', strtotime($du . ' -1 year')); $au1 = date('Y-m-d', strtotime($au . ' -1 year'));
    $rAu = date('Y-m-d', strtotime('-1 day')); $rDu = date('Y-m-d', strtotime('-28 days'));
    $couples = [];
    foreach ($shops as $sid) { $couples[] = [$sid, $du1, $au1]; $couples[] = [$sid, $rDu, $rAu]; }
    $t = function_exists('apTranches2') ? apTranches2($couples) : [];
    $somme = static function (?array $p) use ($ids): ?float {
        if (!is_array($p)) { return null; }
        $q = 0.0;
        foreach ($p as $pid => $x) { if (isset($ids[(int) $pid])) { $q += (float) $x[2]; } }
        return round($q, 1);
    };
    $out = [];
    foreach ($shops as $sid) {
        $n1 = $somme($t[$sid . ':' . $du1] ?? null);
        $r = $somme($t[$sid . ':' . $rDu] ?? null);
        $recent = $r !== null ? round($r * $jours / 28, 1) : null;
        $ref = ($n1 !== null && $n1 > 0) ? $n1 : (($recent !== null && $recent > 0) ? $recent : null);
        $out[] = ['id' => (string) $sid, 'nom' => $nomDe[(string) $sid] ?? ('Magasin ' . $sid), 'n1' => $n1, 'recent' => $recent, 'ref' => $ref,
            'source' => $ref === null ? null : (($n1 !== null && $n1 > 0) ? 'n1' : 'recent')];
    }
    return ['du' => $du, 'au' => $au, 'jours' => $jours, 'du1' => $du1, 'au1' => $au1, 'recentDu' => $rDu, 'recentAu' => $rAu, 'magasins' => $out];
}

/** GET /ventes/primes-promos[?frais] — les promotions du panel, et celles qui ont déjà leur prime. */
function ep_primes_promos(): array
{
    $p = primesPromosPanel(isset($_GET['frais']));
    $prises = [];
    foreach (primesAppDuType('promo', false) as $e) { $prises[$e['promo']['source'] . ':' . $e['promo']['id']] = ['cle' => $e['cle'], 'actif' => $e['actif']]; }
    foreach ($p['liste'] as $i => $x) { $p['liste'][$i]['prime'] = $prises[$x['source'] . ':' . $x['id']] ?? null; }
    return $p;
}

/* ----------------------------------------------------------------------
   L'assistant de création d'une prime promotion (16/10/2026) : le catalogue pour la barre de recherche
   (catégories, sous-catégories, produits), la sélection relue en produits, la promotion du cockpit.
   ---------------------------------------------------------------------- */

/**
 * Le catalogue, à plat pour la recherche : [{id, nom, cat, grp}] (id = l'identifiant du produit au panel, celui des
 * lignes de tickets ; cat = la sous-catégorie ; grp = la catégorie). Gardé une heure.
 */
function primesCatalogue(bool $frais = false): array
{
    static $memo = null;
    if ($memo !== null && !$frais) { return $memo; }
    $c = setting('primesCatalogue');
    if (!$frais && is_array($c) && isset($c['p']) && (int) ($c['quand'] ?? 0) > time() - 3600) { return $memo = (array) $c['p']; }
    $out = [];
    if (function_exists('ep_prod_catalogue')) {
        try {
            foreach (ep_prod_catalogue() as $pr) {
                $id = $pr['pwaId'] ?? null;
                if (!is_numeric($id) || (int) $id <= 0) { continue; }
                $out[(int) $id] = ['id' => (int) $id, 'nom' => (string) ($pr['nom'] ?? ''), 'cat' => (string) ($pr['categorie'] ?? ''), 'grp' => (string) ($pr['groupe'] ?? '')];
            }
        } catch (Throwable $e) { /* catalogue muet : le repli ci-dessous */ }
    }
    if ($out === [] && function_exists('svCategories')) {
        $grp = function_exists('svGroupes') ? svGroupes() : [];
        foreach (svCategories() as $pid => $cat) {
            $out[(int) $pid] = ['id' => (int) $pid, 'nom' => function_exists('svNomProduit') ? svNomProduit((int) $pid, 'Produit ' . $pid) : 'Produit ' . $pid,
                'cat' => (string) $cat, 'grp' => (string) ($grp[(string) $cat] ?? '')];
        }
    }
    $out = array_values($out);
    usort($out, static fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
    if ($out !== []) {
        try { Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['primesCatalogue', json_encode(['quand' => time(), 'p' => $out], JSON_UNESCAPED_UNICODE)]); }
        catch (Throwable $e) { /* sans gravure */ }
    }
    return $memo = $out;
}

/** GET /ventes/primes-catalogue — les catégories, les sous-catégories et les produits, pour la barre de recherche de l'assistant. */
function ep_primes_catalogue(): array
{
    $p = primesCatalogue(isset($_GET['frais']));
    $grp = []; $cat = [];
    foreach ($p as $x) {
        if ($x['grp'] !== '') { $grp[$x['grp']] = ($grp[$x['grp']] ?? 0) + 1; }
        if ($x['cat'] !== '') { $k = $x['grp'] . "\0" . $x['cat']; $cat[$k] = ($cat[$k] ?? 0) + 1; }
    }
    $groupes = []; foreach ($grp as $nom => $n) { $groupes[] = ['nom' => (string) $nom, 'n' => $n]; }
    $cats = []; foreach ($cat as $k => $n) { [$g, $c] = explode("\0", (string) $k, 2); $cats[] = ['nom' => $c, 'groupe' => $g, 'n' => $n]; }
    usort($groupes, static fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
    usort($cats, static fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
    return ['groupes' => $groupes, 'categories' => $cats, 'produits' => $p];
}

/** Une sélection propre : [{t: groupe|categorie|produit, v, lib}], quarante au plus, sans doublon. */
function primesSelPropre($sel): array
{
    $out = []; $vus = [];
    foreach (is_array($sel) ? $sel : [] as $x) {
        if (!is_array($x)) { continue; }
        $t = (string) ($x['t'] ?? '');
        if (!in_array($t, ['groupe', 'categorie', 'produit'], true)) { continue; }
        $v = mb_substr(trim((string) ($x['v'] ?? '')), 0, 120);
        if ($v === '' || ($t === 'produit' && !ctype_digit($v))) { continue; }
        if (isset($vus[$t . ':' . $v])) { continue; }
        $vus[$t . ':' . $v] = true;
        $out[] = ['t' => $t, 'v' => $v, 'lib' => mb_substr(trim((string) ($x['lib'] ?? $v)), 0, 80) ?: $v];
        if (count($out) >= 40) { break; }
    }
    return $out;
}

/**
 * Les produits d'une sélection, relus dans le catalogue : une catégorie (groupe), tous ses produits ; une
 * sous-catégorie, les siens ; un produit, lui. Pour une sous-catégorie, le groupe peut suivre (« groupe\0nom »).
 */
function primesSelIds(array $sel): array
{
    if ($sel === []) { return []; }
    $ids = [];
    foreach (primesCatalogue() as $p) {
        foreach ($sel as $x) {
            $v = (string) ($x['v'] ?? '');
            $ok = match ($x['t'] ?? '') {
                'groupe' => $p['grp'] !== '' && $p['grp'] === $v,
                'categorie' => str_contains($v, "\0") ? ($p['grp'] . "\0" . $p['cat']) === $v : ($p['cat'] !== '' && $p['cat'] === $v),
                'produit' => (string) $p['id'] === $v,
                default => false,
            };
            if ($ok) { $ids[$p['id']] = true; break; }
        }
    }
    return array_map('intval', array_keys($ids));
}

/**
 * Une promotion créée dans le cockpit par l'assistant : son nom, ses dates, ses magasins et sa sélection ; ses
 * produits relus ici (rien de ce que le navigateur envoie sur les identifiants n'est cru). Erreur claire sinon.
 */
function primesPromoCockpit(array $p, string $lib): array
{
    $sel = primesSelPropre($p['sel'] ?? []);
    if ($sel === []) { return ['error' => 'choisir au moins une catégorie, une sous-catégorie ou un produit']; }
    $ids = primesSelIds($sel);
    if ($ids === []) { return ['error' => 'aucun produit du catalogue dans cette sélection']; }
    $du = primesAppDate($p['du'] ?? null); $au = primesAppDate($p['au'] ?? null);
    if ($du !== null && $au !== null && $au < $du) { return ['error' => 'la fin de la période est avant son début']; }
    $shops = [];
    foreach ((array) ($p['shops'] ?? []) as $s) { if (is_scalar($s) && preg_match('/^\d+$/', (string) $s)) { $shops[] = (string) $s; } }
    $nom = mb_substr(trim($lib !== '' ? $lib : (string) ($p['nom'] ?? '')), 0, 80);
    return ['source' => 'cockpit', 'sorte' => 'produits', 'id' => substr(md5(json_encode($sel) . $du . $au . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 10),
        'nom' => $nom !== '' ? $nom : 'Promo ' . $sel[0]['lib'], 'prix' => null, 'du' => $du, 'au' => $au,
        'shops' => array_values(array_unique($shops)), 'ids' => $ids, 'sel' => $sel];
}

/** Les familles des promos à compter dans la moisson : [cle => ids], éteintes comprises (le compte attend). */
function promoFamilles(): array
{
    $out = [];
    foreach (primesAppDuType('promo', false) as $e) {
        $ids = ($e['promo']['source'] ?? '') === 'cockpit' ? (primesSelIds($e['promo']['sel'] ?? []) ?: $e['promo']['ids']) : $e['promo']['ids'];
        if ($ids !== []) { $out[$e['cle']] = array_fill_keys($ids, true); }
    }
    return $out;
}

/** Les dates et les magasins de chaque promo : [cle => {du, au, shops: set|null}]. */
function promoPeriodes(): array
{
    $out = [];
    foreach (primesAppDuType('promo', false) as $e) {
        $out[$e['cle']] = ['du' => $e['promo']['du'], 'au' => $e['promo']['au'], 'shops' => $e['promo']['shops'] !== [] ? array_fill_keys($e['promo']['shops'], true) : null];
    }
    return $out;
}

/** Un jour et un magasin sont-ils dans la promo ? */
function promoCouvre(array $per, string $jour, string $shop): bool
{
    if ($per['du'] !== null && $jour < $per['du']) { return false; }
    if ($per['au'] !== null && $jour > $per['au']) { return false; }
    return $per['shops'] === null || isset($per['shops'][$shop]);
}

/** Les promos actives d'un mois (leurs dates le touchent), pour un magasin si demandé. */
function promosDuMois(string $m, ?string $shop = null): array
{
    $du = $m . '-01'; $au = date('Y-m-t', strtotime($du));
    return array_values(array_filter(primesAppDuType('promo'), static function ($e) use ($du, $au, $shop) {
        $p = $e['promo'];
        if (($p['du'] !== null && $p['du'] > $au) || ($p['au'] !== null && $p['au'] < $du)) { return false; }
        return $shop === null || $p['shops'] === [] || in_array($shop, $p['shops'], true);
    }));
}

/**
 * Les familles qu'un jour moissonné doit porter, pour la relecture : [cle => {du, au, shops}]. Une
 * catégorie, depuis le premier jour du mois d'avant (le mois clos se paie encore) ; une promo, sur ses
 * dates.
 */
function pvFamillesRequises(): array
{
    $out = [];
    $debut = date('Y-m-01', strtotime('first day of last month'));
    foreach (primesAppDuType('categorie', false) as $e) { $out[$e['cle']] = ['du' => $debut, 'au' => null, 'shops' => null, 'promo' => false]; }
    // Des ventes croisées par catégorie : les jours depuis le mois d'avant doivent porter le compte par catégorie (« k »).
    if (function_exists('pvCroiseesCategorie') && pvCroiseesCategorie() !== null) { $out['#k'] = ['du' => $debut, 'au' => null, 'shops' => null, 'promo' => true]; }
    $familles = promoFamilles();
    foreach (promoPeriodes() as $cle => $per) {
        if (!isset($familles[$cle])) { continue; }
        $out[$cle] = ['du' => max($debut, (string) ($per['du'] ?? $debut)), 'au' => $per['au'], 'shops' => $per['shops'], 'promo' => true];
    }
    return $out;
}

/**
 * Un jour moissonné porte-t-il toutes les familles qu'il doit compter ? Un jour gravé avant la liste
 * des familles (« f ») est tenu pour juste sur les catégories — comme avant — mais pas sur les promos.
 */
function pvFamillesOk(array $entree, int $sid, string $jour, array $requises): bool
{
    $f = isset($entree['f']) && is_array($entree['f']) ? array_fill_keys(array_map('strval', $entree['f']), true) : null;
    foreach ($requises as $cle => $r) {
        if (!promoCouvre($r, $jour, (string) $sid)) { continue; }
        if ($cle === '#k') { if (!isset($entree['k'])) { return false; } continue; }
        if ($f === null ? $r['promo'] : !isset($f[$cle])) { return false; }
    }
    return true;
}

/** Les catégories de produits du catalogue, pour le menu déroulant des ventes croisées (sans les bundles). */
function primesCategories(): array
{
    if (!function_exists('svCategories')) { return []; }
    try { $cats = array_values(array_unique(array_filter(array_map('strval', svCategories()), static fn ($c) => $c !== '' && preg_match('/bundle|promotion/iu', $c) !== 1))); }
    catch (Throwable $e) { return []; }
    sort($cats, SORT_NATURAL | SORT_FLAG_CASE);
    return $cats;
}

/* ======================================================================
   Les moyennes par magasin, pour régler les seuils
   ====================================================================== */

/**
 * Un mois, magasin par magasin, depuis les jours moissonnés : tickets, lignes, ventes croisées, pièces
 * de chaque famille, et les tickets de chaque vendeuse (pour les compter). Gravé un jour une fois le
 * mois clos.
 *
 * @return array<string, array{t:int, l:int, c:int, tc:int, q:array<string,float>, e:array<int,int>, jours:int}>
 */
function primesMoyennesMois(string $m): array
{
    $clos = date('Y-m-t', strtotime($m . '-01')) < date('Y-m-d');
    $cat = function_exists('pvCroiseesCategorie') ? pvCroiseesCategorie() : null;
    $cle = 'prMoy' . $m . ($cat !== null ? ':' . substr(md5($cat), 0, 8) : '');
    $c = setting($cle);
    if ($clos && is_array($c) && isset($c['s']) && (int) ($c['quand'] ?? 0) > time() - PRIMES_MOY_CACHE) { return (array) $c['s']; }
    try { $shops = array_map(fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return []; }
    $fin = min(date('Y-m-t', strtotime($m . '-01')), date('Y-m-d', strtotime('-1 day')));
    $out = [];
    foreach ($shops as $sid) {
        $x = ['t' => 0, 'l' => 0, 'c' => 0, 'tc' => 0, 'q' => [], 'e' => [], 'jours' => 0];
        for ($j = $m . '-01'; $j <= $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            $d = setting('pvL' . $sid . ':' . $j);
            if (!is_array($d) || !isset($d['e'])) { continue; }
            $x['jours']++;
            $v = pvLignesVersion($d);
            foreach ((array) $d['e'] as $id => $y) {
                $t = (int) ($y['t'] ?? 0);
                $x['t'] += $t; $x['l'] += (int) ($y['l'] ?? 0);
                $cY = $v >= 1 ? pvCroiseesDe($d, (array) $y, $cat) : null;
                if ($cY !== null) { $x['c'] += $cY; $x['tc'] += $t; }
                if ((int) $id > 0) { $x['e'][(int) $id] = ($x['e'][(int) $id] ?? 0) + $t; }
                if ($v >= 3) { foreach ((array) ($y['q'] ?? []) as $k => $q) { $x['q'][$k] = ($x['q'][$k] ?? 0.0) + (float) $q; } }
            }
        }
        $out[(string) $sid] = $x;
    }
    if ($clos) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['quand' => time(), 's' => $out])]);
    }
    return $out;
}

/** Les pièces d'une promo depuis son début (hier au plus tard), par magasin : pièces et vendeuses qui ont vendu. */
function primesMoyennesPromo(array $e): array
{
    $per = promoPeriodes()[$e['cle']] ?? null;
    if ($per === null) { return []; }
    $du = max((string) ($per['du'] ?? date('Y-m-01')), date('Y-m-d', strtotime('-92 days')));
    $au = min((string) ($per['au'] ?? '9999-12-31'), date('Y-m-d', strtotime('-1 day')));
    try { $shops = array_map(fn ($s) => (string) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e2) { return []; }
    $out = [];
    foreach ($shops as $sid) {
        if ($per['shops'] !== null && !isset($per['shops'][$sid])) { continue; }
        $p = 0.0; $vend = [];
        for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            $d = setting('pvL' . $sid . ':' . $j);
            if (!is_array($d) || !isset($d['e'])) { continue; }
            foreach ((array) $d['e'] as $id => $y) {
                if ((int) ($y['t'] ?? 0) > 0 && (int) $id > 0) { $vend[(int) $id] = true; }
                $p += (float) ($y['q'][$e['cle']] ?? 0);
            }
        }
        $out[$sid] = ['pieces' => round($p, 1), 'vendeuses' => count($vend)];
    }
    return $out;
}

/**
 * GET /ventes/primes-moyennes?n=3 — par magasin et pour le réseau, la moyenne mensuelle des n derniers
 * mois clos : vendeuses (30 tickets au moins dans le mois), tickets par mois et par vendeuse, ticket
 * moyen, lignes par ticket, ventes croisées, atteinte de l'objectif, pièces par vendeuse de chaque
 * catégorie ; et pour chaque promo, ses pièces par vendeuse depuis son début. Avec la cible de ventes
 * croisées de chaque magasin, pour la régler en face.
 */
function ep_primes_moyennes(): array
{
    $n = (int) ($_GET['n'] ?? 3);
    if (!in_array($n, [1, 3, 6], true)) { $n = 3; }
    $mois = [];
    for ($i = 1; $i <= $n; $i++) { $mois[] = date('Y-m', strtotime(date('Y-m') . '-01 -' . $i . ' month')); }
    $nomDe = primesMagasins();
    $cats = primesAppDuType('categorie', false);
    $minT = (int) croiseesReglages()['minTickets'];
    $acc = [];
    $vide = static fn () => ['mois' => 0, 'vend' => 0, 't' => 0, 'l' => 0, 'c' => 0, 'tc' => 0, 'ca' => 0.0, 'tk' => 0, 'obj' => 0.0, 'caObj' => 0.0, 'q' => []];
    foreach ($mois as $m) {
        $mm = primesMoyennesMois($m);
        $bud = primesBudgets($m);
        foreach ($nomDe as $sid => $nom) {
            $x = $mm[(string) $sid] ?? null;
            if ($x === null || $x['jours'] === 0) { continue; }
            $a = $acc[$sid] ?? $vide();
            $a['mois']++;
            $a['vend'] += count(array_filter($x['e'], static fn ($t) => $t >= $minT));
            foreach (['t', 'l', 'c', 'tc'] as $k) { $a[$k] += $x[$k]; }
            foreach ($cats as $e) { $a['q'][$e['cle']] = ($a['q'][$e['cle']] ?? 0.0) + (float) ($x['q'][$e['cle']] ?? 0); }
            $k = function_exists('pvKpisMois') ? pvKpisMois((int) $sid, $m) : null;
            if ($k !== null && $k['tickets'] > 0) { $a['ca'] += $k['ca']; $a['tk'] += $k['tickets']; }
            if ($k !== null && isset($bud[(string) $sid])) { $a['obj'] += (float) $bud[(string) $sid]['montant']; $a['caObj'] += $k['ca']; }
            $acc[$sid] = $a;
        }
    }
    $ligne = static function (array $a) use ($cats): array {
        $nm = max(1, $a['mois']);
        $v = $a['vend'] > 0 ? $a['vend'] : null;
        $q = [];
        foreach ($cats as $e) { $q[$e['cle']] = $v !== null ? round(($a['q'][$e['cle']] ?? 0) / $v, 1) : null; }
        return ['mois' => $a['mois'], 'vendeuses' => round($a['vend'] / $nm, 1), 'tickets' => (int) round($a['t'] / $nm),
            'ticketsVendeuse' => $v !== null ? (int) round($a['t'] / $v) : null,
            'ticketMoyen' => $a['tk'] > 0 ? round($a['ca'] / $a['tk'], 2) : null,
            'lignesTicket' => $a['t'] > 0 ? round($a['l'] / $a['t'], 2) : null,
            'croisees' => $a['tc'] > 0 ? round(100 * $a['c'] / $a['tc'], 1) : null,
            'atteinte' => $a['obj'] > 0 ? round(100 * $a['caObj'] / $a['obj'], 1) : null,
            'pieces' => $q];
    };
    $cr = croiseesReglages();
    $magasins = []; $reseau = $vide();
    foreach ($nomDe as $sid => $nom) {
        $a = $acc[$sid] ?? $vide();
        foreach (['mois', 'vend', 't', 'l', 'c', 'tc', 'tk', 'ca', 'obj', 'caObj'] as $k) { $reseau[$k] += $a[$k]; }
        foreach ($a['q'] as $k => $v) { $reseau['q'][$k] = ($reseau['q'][$k] ?? 0.0) + $v; }
        $magasins[] = ['id' => (string) $sid, 'nom' => $nom, 'cible' => $cr['cibles'][(string) $sid] ?? null] + $ligne($a);
    }
    // Le réseau : les sommes, ramenées au nombre de mois (pas à la somme des mois-magasins).
    $r = $ligne(array_merge($reseau, ['mois' => 1]));
    $r['tickets'] = (int) round($reseau['t'] / max(1, $n));
    $r['vendeuses'] = round($reseau['vend'] / max(1, $n), 1);
    // Les promos : leurs pièces par vendeuse depuis leur début, magasin par magasin et pour le réseau.
    $promos = [];
    foreach (primesAppDuType('promo') as $e) {
        $pm = primesMoyennesPromo($e);
        $tp = 0.0; $tv = 0;
        foreach ($pm as $x) { $tp += $x['pieces']; $tv += $x['vendeuses']; }
        $promos[] = ['cle' => $e['cle'], 'lib' => $e['lib'], 'du' => $e['promo']['du'],
            'magasins' => array_map(static fn ($x) => $x['vendeuses'] > 0 ? round($x['pieces'] / $x['vendeuses'], 1) : null, $pm),
            'reseau' => $tv > 0 ? round($tp / $tv, 1) : null];
    }
    return ['n' => $n, 'mois' => array_map(static fn ($m) => ['m' => $m, 'lib' => primesLibMois($m)], $mois),
        'categories' => array_map(static fn ($e) => ['cle' => $e['cle'], 'lib' => $e['lib']], $cats),
        'cibleDefaut' => $cr['cibleDefaut'], 'minTickets' => $minT,
        'magasins' => $magasins, 'reseau' => $r, 'promos' => $promos];
}

/* ======================================================================
   Les cartes de l'app : une par prime
   ====================================================================== */

/** La position d'une valeur sur une jauge bornée, en %. */
function primesJauge(?float $v, float $bas, float $haut): ?float
{
    if ($v === null || $haut <= $bas) { return null; }
    return round(max(0.0, min(100.0, 100 * ($v - $bas) / ($haut - $bas))), 1);
}

/** La 1re et la 2e d'un classement de magasin (les lignes de primesClassements). */
function primesTete(?array $cl, string $champ): array
{
    $l = (array) ($cl['magasin'] ?? []);
    $f = static fn ($x) => $x === null ? null : ['nom' => (string) $x['nom'], 'q' => (float) ($x[$champ] ?? 0), 'moi' => (bool) ($x['moi'] ?? false)];
    return [$f($l[0] ?? null), $f($l[1] ?? null)];
}

/**
 * Le podium d'une catégorie (16/10/2026) : les trois premières du magasin, chacune avec son rang, son nom et ses
 * pièces ; quand elle est plus loin, elle prend la 3e ligne (1re, 2e, puis elle à son rang).
 */
function primesPodium(?array $cl): array
{
    $l = array_values((array) ($cl['magasin'] ?? []));
    foreach ($l as $i => $x) { $l[$i]['rang'] = (int) ($x['rang'] ?? $i + 1); }
    $out = array_slice($l, 0, 3);
    foreach ($l as $x) { if (!empty($x['moi']) && (int) ($x['rang'] ?? 0) > 3 && count($out) === 3) { $out[2] = $x; } }
    return array_map(static fn ($x) => ['rang' => $x['rang'], 'nom' => (string) ($x['nom'] ?? ''), 'q' => (float) ($x['pieces'] ?? 0), 'moi' => (bool) ($x['moi'] ?? false)], $out);
}

/**
 * Les cartes de « Mes primes », dans l'ordre du réglage : les ventes croisées, Bats ton record,
 * l'objectif du magasin, la note Google, la meilleure vendeuse, puis les catégories et les promos. Une
 * carte dit la récompense, l'état (gagne | tete | rang | att | vide), la mesure, la jauge, et la 1re
 * quand il y a un classement ; l'icône déposée par l'admin (« icone », sinon l'emoji). La vue met les mots.
 */
function primesCartes(array $b, array $pm, array $classements, bool $enCours): array
{
    $clDe = [];
    foreach ($classements as $cl) { $clDe[$cl['cle']] = $cl; }
    // Le mois est compté mais elle n'a aucun ticket à son nom (16/10/2026) : « sans_vente », pas « pas encore compté ».
    $sansVente = (int) ($b['croisees']['jours'] ?? 0) > 0 && (int) ($b['croisees']['tickets'] ?? 0) === 0 && (int) ($b['meilleure']['tickets'] ?? 0) === 0;
    $vide = static fn (string $motif) => $sansVente ? 'sans_vente' : $motif;
    $cartes = [];
    $c = $b['croisees'];
    $eC = primesAppEntree('croisees');
    if ($eC !== null && $eC['actif']) {
        $val = $c['taux']; $cible = (float) $c['cible'];
        $cartes[] = ['cle' => 'croisees', 'type' => 'target', 'emo' => '🛍️', 'icone' => $eC['icone'], 'lib' => $eC['lib'], 'categorie' => (string) ($eC['categorie'] ?? ''), 'montant' => (int) $eC['montant'], 'max' => false,
            'gain' => (float) $c['montant'], 'etat' => $c['montant'] > 0 ? 'gagne' : ($val === null ? 'vide' : 'att'),
            'val' => $val, 'unite' => 'pct', 'cible' => $cible, 'ecart' => $val !== null ? round(max(0, $cible - $val), 1) : null,
            'motif' => $val === null ? $vide('pas_compte') : (($c['tickets'] < $c['minTickets']) ? 'min_tickets' : null), 'minTickets' => (int) $c['minTickets'],
            'classement' => isset($clDe['croisees'])];
    }
    $r = $b['record'];
    if (($r['eurDixieme'] ?? 0) > 0) {
        $cible = $r['record'] !== null ? round((float) $r['record'] + 0.2, 2) : null;
        $lt = $r['lt'] !== null ? (float) $r['lt'] : null;
        $cartes[] = ['cle' => 'record', 'type' => 'target', 'emo' => '🚀', 'icone' => primesIconeDe('record'), 'lib' => 'Bats ton record', 'montant' => (int) $r['eurDixieme'] * max(1, (int) $r['maxDixiemes']),
            'max' => (int) $r['maxDixiemes'] > 1, 'gain' => (float) $r['montant'],
            'etat' => $r['montant'] > 0 ? 'gagne' : (($lt === null || $cible === null) ? 'vide' : 'att'),
            'val' => $lt, 'unite' => 'lpt', 'cible' => $cible, 'record' => $r['record'], 'ecart' => ($lt !== null && $cible !== null) ? round(max(0, $cible - $lt), 2) : null,
            'motif' => $lt === null ? $vide('pas_compte') : ($r['record'] === null ? 'pas_record' : null), 'classement' => false];
    }
    $eO = primesAppEntree('objectif');
    if ($eO !== null && $eO['actif']) {
        $pct = (float) $eO['pct'];
        $att = $pm['clos'] ? $pm['atteinte'] : ($pm['atteinteProj'] ?? null);
        $etat = $pm['vous'] > 0 ? 'gagne' : (($att === null || $pm['sousMin']) ? 'vide' : 'att');
        // Au jour J (16/10/2026) : la jauge montre l'atteinte à date (le CA encaissé ÷ l'objectif du mois), le repère ce
        // qu'il faudrait avoir atteint aujourd'hui au prorata des jours ; la projection reste à côté, c'est elle qui gagne.
        $j = $enCours ? (int) date('j') : null;
        $cibleJour = $j !== null ? round($pct * $j / (int) date('t'), 1) : null;
        $aDate = $pm['atteinte'] ?? null;
        $cartes[] = ['cle' => 'objectif', 'type' => 'target', 'emo' => '🏪', 'icone' => $eO['icone'], 'lib' => $eO['lib'], 'montant' => (int) $eO['montant'], 'max' => false,
            'gain' => (float) $pm['vous'], 'etat' => $etat, 'val' => $att, 'unite' => 'pct', 'cible' => $pct, 'projete' => !$pm['clos'],
            'ecart' => $att !== null ? round(max(0, $pct - $att), 1) : null,
            'aDate' => $aDate, 'jour' => $j, 'cibleJour' => $cibleJour, 'avance' => ($aDate !== null && $cibleJour !== null) ? round($aDate - $cibleJour, 1) : null,
            'motif' => $pm['objectif'] === null ? 'pas_objectif' : ($pm['sousMin'] ? 'heures' : ($att === null ? 'pas_compte' : null)),
            'heuresMin' => (int) $eO['heuresMin'], 'classement' => false];
    }
    // La note Google : la note du magasin face au seuil ; la récompense pour chacune au planning, au-dessus du minimum d'heures.
    $g = $b['google'] ?? null;
    $eG = primesAppEntree('google');
    if ($g !== null && $eG !== null && $eG['actif']) {
        $note = $g['note']; $seuil = (float) $g['seuil'];
        $cartes[] = ['cle' => 'google', 'type' => 'target', 'emo' => '⭐', 'icone' => $eG['icone'], 'lib' => $eG['lib'], 'montant' => (int) $eG['montant'], 'max' => false,
            'gain' => (float) $g['montant'], 'etat' => $g['montant'] > 0 ? 'gagne' : (($note === null || $g['sousMin']) ? 'vide' : 'att'),
            'val' => $note, 'unite' => 'note', 'cible' => $seuil, 'avis' => (int) $g['avis'],
            'ecart' => $note !== null ? round(max(0, $seuil - $note), 1) : null,
            'motif' => $note === null ? 'pas_note' : ($g['sousMin'] ? 'heures' : null), 'heuresMin' => (int) $g['heuresMin'], 'classement' => false];
    }
    $v = $b['meilleure'];
    if (($v['montantMag'] ?? 0) > 0) {
        $cartes[] = ['cle' => 'meilleure', 'type' => 'classement', 'emo' => '🏆', 'icone' => primesIconeDe('meilleure'), 'lib' => 'Meilleure vendeuse', 'montant' => (int) $v['montantMag'], 'max' => false,
            'gain' => (float) $v['montant'],
            'etat' => $v['score'] === null ? 'vide' : ($v['rangMag'] === 1 ? ($enCours ? 'tete' : 'gagne') : 'rang'),
            'val' => $v['score'], 'unite' => 'score', 'rang' => $v['rangMag'], 'sur' => $v['surMag'],
            'premier' => $v['premierNom'] !== null ? ['nom' => $v['premierNom'], 'q' => (float) $v['scorePremier'], 'moi' => $v['rangMag'] === 1] : null,
            'second' => $v['secondNom'] !== null ? ['nom' => $v['secondNom'], 'q' => (float) $v['scoreSecond'], 'moi' => false] : null,
            'motif' => $v['score'] === null ? $vide('pas_classee') : null, 'classement' => false];
    }
    $pieces = array_merge(
        array_map(static fn ($q) => $q + ['type' => 'categorie', 'emo' => '👑'], (array) ($b['concours']['liste'] ?? [])),
        array_map(static fn ($q) => $q + ['type' => 'promo', 'emo' => '🏷️'], (array) ($b['promos']['liste'] ?? [])));
    foreach ($pieces as $q) {
        [$un, $deux] = primesTete($clDe[$q['cle']] ?? null, 'pieces');
        $p = $q['pieces'];
        $regle = $q['regle'] ?? 'premiere';
        // Sur objectif (16/10/2026) : « magasin », la jauge est le total du magasin face à son objectif ; « vendeuse »,
        // ses pièces face au sien. Comme au seuil : gagnée dès l'objectif atteint.
        $obj = in_array($regle, ['magasin', 'vendeuse'], true) ? ($q['objectif'] ?? null) : null;
        $seuil = $regle === 'seuil' || $obj !== null;
        if ($regle === 'magasin' && $p !== null) { $p = (float) ($q['totalMag'] ?? $p); }
        $etat = $p === null ? 'vide' : ($seuil ? ((int) ($q['montant'] ?? 0) > 0 ? 'gagne' : 'att')
            : ($q['rangMag'] === 1 ? ($enCours ? 'tete' : 'gagne') : ($q['rangMag'] === null ? 'att' : 'rang')));
        $cible = $obj ?? (float) $q['minPieces'];
        $cartes[] = ['cle' => $q['cle'], 'type' => $q['type'], 'emo' => $q['emo'], 'icone' => primesIconeDe($q['cle']), 'lib' => $q['lib'], 'montant' => (int) $q['montantMag'], 'max' => false,
            'gain' => (float) $q['montant'], 'etat' => $etat, 'val' => $p, 'unite' => 'pcs', 'rang' => $q['rangMag'], 'sur' => $q['surMag'],
            'minPieces' => (int) $q['minPieces'], 'regle' => $regle, 'objPcs' => $obj, 'moiPcs' => $regle === 'magasin' ? $q['pieces'] : null,
            'ecart' => ($p !== null && ($seuil ? $p < $cible : $q['rangMag'] === null)) ? max(1, (int) ceil(($seuil ? $cible : $q['minPieces']) - $p)) : null,
            'premier' => $un, 'second' => $deux, 'podium' => $q['type'] === 'categorie' ? primesPodium($clDe[$q['cle']] ?? null) : [],
            'du' => $q['du'] ?? null, 'au' => $q['au'] ?? null,
            'motif' => $p === null ? $vide('pas_compte') : null, 'classement' => isset($clDe[$q['cle']])];
    }
    return array_map('primesCarteJauge', $cartes);
}

/**
 * Un mois à venir (16/10/2026) : la fiche des primes prévues — les targets actives, Bats ton record, la meilleure
 * vendeuse, et les promos dont la période touche le mois dans le magasin de la personne —, chacune avec sa récompense,
 * sa règle et son objectif (pour son magasin), sans rien de compté. `aVenir: true`, `etat: avenir`.
 */
function primesAVenir(int $emp, string $m): array
{
    $emps = venteEmployes();
    if (!isset($emps[$emp])) { return ['error' => 'personne inconnue', 'code' => 404]; }
    $shop = (string) $emps[$emp]['shop'];
    $nomDe = primesMagasins();
    $carte = static fn (array $c) => $c + ['gain' => 0.0, 'etat' => 'avenir', 'val' => null, 'max' => false, 'objectif' => null, 'unite' => null, 'regle' => null, 'du' => null, 'au' => null];
    $cartes = [];
    $eC = primesAppEntree('croisees');
    if ($eC !== null && $eC['actif']) {
        $cfgC = croiseesReglages();
        $cartes[] = $carte(['cle' => 'croisees', 'type' => 'target', 'emo' => '🛍️', 'icone' => $eC['icone'], 'lib' => $eC['lib'], 'montant' => (int) $eC['montant'],
            'objectif' => croiseesCible($cfgC, $shop), 'unite' => 'pct', 'minTickets' => (int) $cfgC['minTickets'], 'categorie' => (string) ($eC['categorie'] ?? '')]);
    }
    $regR = venteRecordReglages();
    if ($regR['eurDixieme'] > 0) {
        $cartes[] = $carte(['cle' => 'record', 'type' => 'target', 'emo' => '🚀', 'icone' => primesIconeDe('record'), 'lib' => 'Bats ton record',
            'montant' => (int) $regR['eurDixieme'] * max(1, (int) $regR['maxDixiemes']), 'max' => (int) $regR['maxDixiemes'] > 1, 'unite' => 'lpt']);
    }
    $eO = primesAppEntree('objectif');
    if ($eO !== null && $eO['actif']) {
        $cartes[] = $carte(['cle' => 'objectif', 'type' => 'target', 'emo' => '🏪', 'icone' => $eO['icone'], 'lib' => $eO['lib'], 'montant' => (int) $eO['montant'],
            'objectif' => (float) $eO['pct'], 'unite' => 'pct', 'heuresMin' => (int) $eO['heuresMin']]);
    }
    $eG = primesAppEntree('google');
    if ($eG !== null && $eG['actif']) {
        $cartes[] = $carte(['cle' => 'google', 'type' => 'target', 'emo' => '⭐', 'icone' => $eG['icone'], 'lib' => $eG['lib'], 'montant' => (int) $eG['montant'],
            'objectif' => (float) $eG['seuil'], 'unite' => 'note', 'heuresMin' => (int) $eG['heuresMin']]);
    }
    $mv = ventePrimesConfig();
    if (($mv['magasin'] ?? 0) > 0) {
        $cartes[] = $carte(['cle' => 'meilleure', 'type' => 'classement', 'emo' => '🏆', 'icone' => primesIconeDe('meilleure'), 'lib' => 'Meilleure vendeuse', 'montant' => (int) $mv['magasin'], 'regle' => 'premiere']);
    }
    foreach (promosConfig($m, $shop) as $k) {
        $regle = $k['regle'] ?? 'premiere';
        $obj = in_array($regle, ['magasin', 'vendeuse'], true) ? primesObjectifDe($k, $shop) : ((int) $k['minPieces'] > 0 ? (float) $k['minPieces'] : null);
        $cartes[] = $carte(['cle' => $k['cle'], 'type' => 'promo', 'emo' => '🏷️', 'icone' => primesIconeDe($k['cle']), 'lib' => $k['lib'], 'montant' => (int) $k['magasin'],
            'objectif' => $obj, 'unite' => 'pcs', 'regle' => $regle, 'du' => $k['du'], 'au' => $k['au']]);
    }
    $enJeu = (float) array_sum(array_map(static fn ($c) => $c['montant'], $cartes));
    return ['v' => PRIMES_FICHE_V, 'emp' => $emp, 'm' => $m, 'lib' => primesLibMois($m), 'enCours' => false, 'aVenir' => true, 'quand' => time(),
        'magasin' => ['id' => $shop, 'nom' => $nomDe[$shop] ?? ('Magasin ' . $shop)], 'cartes' => $cartes, 'classements' => [], 'collection' => [],
        'heures' => null, 'aujourdhui' => null, 'acquis' => 0.0, 'enJeu' => $enJeu, 'aPortee' => $enJeu, 'google' => null, 'montants' => [],
        'briques' => [], 'prime' => [], 'semaines12' => [], 'motif' => null];
}

/**
 * La demi-jauge d'une carte (16/10/2026) : où elle en est aujourd'hui (« jauge », en % d'une échelle qui part de
 * 0) et l'objectif à atteindre (« repere » sur la même échelle, « repereVal » le chiffre à atteindre) :
 *  - cible : la cible du target (ventes croisées, record, objectif du magasin — 100 % du mois) ;
 *  - seuil : la note Google à tenir ;
 *  - passer : les pièces (ou le score) pour passer la 1re du magasin, la sienne + 1 ;
 *  - garder : elle est 1re, l'objectif est de rester devant la 2e (son chiffre) ;
 *  - min : personne n'a encore vendu, le minimum de pièces.
 * L'objectif du magasin en cours de mois porte aussi « repereJour » : où il faudrait en être aujourd'hui.
 */
function primesCarteJauge(array $c): array
{
    $v = $c['val'] ?? null;
    [$bas, $haut, $rType, $rVal, $jour] = [0.0, null, null, null, null];
    if ($c['cle'] === 'croisees') {
        [$rType, $rVal] = ['cible', (float) $c['cible']];
        $haut = min(100.0, max($rVal + 15, (float) ($v ?? 0) + 5));
    } elseif ($c['cle'] === 'record') {
        if ($c['cible'] !== null) { [$rType, $rVal] = ['cible', (float) $c['cible']]; $haut = max($rVal * 1.15, (float) ($v ?? 0) * 1.05); }
    } elseif ($c['cle'] === 'objectif') {
        $v = ($c['jour'] ?? null) !== null ? $c['aDate'] : $v;
        [$rType, $rVal] = ['cible', (float) $c['cible']];
        $jour = ($c['jour'] ?? null) !== null ? (float) $c['cibleJour'] : null;
        $haut = max($rVal + 10, (float) ($v ?? 0) + 5);
    } elseif ($c['cle'] === 'google') {
        [$rType, $rVal] = ['seuil', (float) $c['cible']];
        [$bas, $haut] = [$rVal - 1, 5.0];
    } else {
        // Les classements (catégories, promos, meilleure vendeuse) : passer la 1re, garder la place, ou le minimum. Une
        // promo au seuil (16/10/2026) : le seuil est l'objectif, chacune pour soi.
        $un = $c['premier'] ?? null; $deux = $c['second'] ?? null;
        if (($c['objPcs'] ?? null) !== null) { [$rType, $rVal] = ['cible', (float) $c['objPcs']]; }
        elseif (($c['regle'] ?? '') === 'seuil') { [$rType, $rVal] = ['cible', (float) max(1, (int) ($c['minPieces'] ?? 1))]; }
        elseif ($un !== null && !$un['moi']) { [$rType, $rVal] = ['passer', floor((float) $un['q']) + 1]; }
        elseif ($un !== null && $deux !== null) { [$rType, $rVal] = ['garder', (float) $deux['q']]; }
        elseif ($un === null && ($c['minPieces'] ?? 0) > 0) { [$rType, $rVal] = ['min', (float) $c['minPieces']]; }
        $haut = max((float) ($rVal ?? 0), (float) ($v ?? 0), (float) ($c['minPieces'] ?? 0)) * 1.15;
    }
    $ok = $v !== null && $haut !== null && $haut > $bas;
    // Les catégories (16/10/2026) : pas de jauge, la quantité qu'il faut pour être 1re (« pour1 »), ou, en tête,
    // l'avance sur la 2e (« avance1 »).
    $pour1 = null; $avance1 = null;
    if (($c['type'] ?? '') === 'categorie' && $v !== null) {
        $un = $c['premier'] ?? null; $deux = $c['second'] ?? null; $min = (float) ($c['minPieces'] ?? 0);
        if ($un !== null && $un['moi'] && $v >= $min) { $avance1 = $deux !== null ? (int) floor($v - (float) $deux['q']) : null; }
        else { $pour1 = max(1, (int) ceil(max($un !== null && !$un['moi'] ? floor((float) $un['q']) + 1 : 1, $min) - $v)); }
    }
    // La barre des targets (16/10/2026, le « drapeau ») : l'objectif toujours au même endroit (PRIMES_BARRE_OBJ % de
    // la barre), la valeur et le repère du jour J sur la même échelle (de 0 ; de seuil − 1 pour la note Google).
    // « actif » : la prime est activée — la carte gagnée ou en tête ; pour l'objectif du magasin en cours de mois,
    // le CA encaissé a passé l'objectif.
    [$barre, $barreJour] = [null, null];
    if ($rVal !== null && $rVal > $bas) {
        $sur = static fn (float $x) => round(max(0.0, min(100.0, PRIMES_BARRE_OBJ * ($x - $bas) / ($rVal - $bas))), 1);
        $barre = $v !== null ? $sur((float) $v) : null;
        $barreJour = $jour !== null ? $sur($jour) : null;
    }
    // Les catégories (16/10/2026, le drapeau comme les targets) : le trait, c'est ce qu'il lui faut pour être 1re (ses
    // pièces + pour1 : la 1re + 1, le minimum compris) ; quand elle mène, la 2e qu'elle doit garder derrière ; seule
    // en lice, le minimum.
    if (($c['type'] ?? '') === 'categorie' && $v !== null) {
        $objCat = $avance1 !== null ? (($c['second'] ?? null) !== null ? (float) $c['second']['q'] : null) : ($pour1 !== null ? (float) $v + $pour1 : null);
        $objCat = ($objCat !== null && $objCat > 0) ? $objCat : max(1.0, (float) ($c['minPieces'] ?? 0));
        $barre = round(max(0.0, min(100.0, PRIMES_BARRE_OBJ * (float) $v / $objCat)), 1);
    }
    $actif = ($c['cle'] === 'objectif' && ($c['jour'] ?? null) !== null)
        ? ($v !== null && (float) $v >= (float) $c['cible'] && ($c['motif'] ?? null) === null)
        : in_array($c['etat'] ?? '', ['gagne', 'tete'], true);
    return $c + ['barre' => $barre, 'barreJour' => $barreJour, 'actif' => $actif, 'pour1' => $pour1, 'avance1' => $avance1, 'jauge' => $ok ? primesJauge((float) $v, $bas, $haut) : null,
        'repere' => ($haut !== null && $haut > $bas && $rVal !== null) ? primesJauge($rVal, $bas, $haut) : null,
        'repereType' => $rVal !== null ? $rType : null, 'repereVal' => $rVal,
        'repereJour' => ($haut !== null && $jour !== null) ? primesJauge($jour, $bas, $haut) : null];
}

/* ======================================================================
   Le SMS à la réussite
   ====================================================================== */

/** Le téléphone de chaque personne : la fiche locale (franchisee_employee.phone), sinon celle du panel. */
function primesTelephones(): array
{
    $out = [];
    try {
        foreach (Db::rows("SELECT id, phone FROM franchisee_employee WHERE phone IS NOT NULL AND phone <> ''") as $r) { $out[(int) $r['id']] = (string) $r['phone']; }
    } catch (Throwable $e) { /* pas de colonne phone : le panel seulement */ }
    $c = setting('pvEmployesTel');
    foreach ((array) ($c['t'] ?? []) as $id => $tel) { if (!isset($out[(int) $id]) && (string) $tel !== '') { $out[(int) $id] = (string) $tel; } }
    return $out;
}

/** Les primes gagnées d'une personne sur le mois (une ligne de primesMoisTous), par leur nom. */
function primesGagnees(array $p): array
{
    $out = [];
    $nom = static fn (string $cle, string $defaut) => (primesAppEntree($cle)['lib'] ?? '') ?: $defaut;
    if (($p['croisees']['montant'] ?? 0) > 0) { $out[] = $nom('croisees', 'Ventes croisées'); }
    if (($p['magasin'] ?? 0) > 0) { $out[] = $nom('objectif', 'Objectif magasin'); }
    if (($p['google'] ?? 0) > 0) { $out[] = $nom('google', 'Note Google'); }
    foreach ((array) ($p['concours']['titres'] ?? []) as $t) { $out[] = $t['lib']; }
    foreach ((array) ($p['promo']['titres'] ?? []) as $t) { $out[] = $t['lib']; }
    if (($p['record']['montant'] ?? 0) > 0) { $out[] = 'Bats ton record'; }
    if (($p['meilleure'] ?? 0) > 0) { $out[] = 'Meilleure vendeuse'; }
    return $out;
}

/** Le texte du SMS : le prénom, le mois, le total, les primes. Court : il tient dans un SMS. */
function primesSmsTexte(array $p, string $m): string
{
    $prenom = explode(' ', trim((string) $p['nom']))[0] ?? '';
    $lib = function_exists('primesLibMois') ? primesLibMois($m) : $m;
    return 'Bravo ' . $prenom . ' ! ' . ucfirst(explode(' ', $lib)[0]) . ' : ' . number_format((float) $p['total'], 0, ',', ' ') . ' € de primes gagnées ('
        . implode(', ', primesGagnees($p)) . '). L’Atelier';
}

/**
 * Les SMS à la réussite d'un mois, au moment où le CEO enregistre les primes (la réussite est alors
 * acquise, pas une projection) : un SMS par personne primée, s'ils sont activés. Jamais deux fois au
 * même mois (primesSms{mois}). Rend {actif, configure, envoyes, echecs, sansNumero}.
 */
function primesSmsReussite(string $m, array $personnes): array
{
    $reg = primesAppReglages()['sms'];
    if (!$reg['actif']) { return ['actif' => false]; }
    $configure = function_exists('smsConfigure') && smsConfigure();
    $deja = setting('primesSms' . $m);
    $deja = is_array($deja) ? $deja : [];
    $tels = primesTelephones();
    $n = ['envoyes' => 0, 'echecs' => 0, 'sansNumero' => 0];
    foreach ($personnes as $p) {
        if ((float) ($p['total'] ?? 0) <= 0 || !empty($deja[(string) $p['id']]['ok'])) { continue; }
        $num = function_exists('smsNumero') ? smsNumero($tels[(int) $p['id']] ?? null) : null;
        if ($num === null) { $n['sansNumero']++; continue; }
        $r = $configure ? smsEnvoyer($num, primesSmsTexte($p, $m)) : ['ok' => false, 'erreur' => 'aucun fournisseur SMS réglé'];
        $deja[(string) $p['id']] = ['quand' => date('Y-m-d H:i'), 'ok' => $r['ok'], 'erreur' => $r['erreur']];
        $n[$r['ok'] ? 'envoyes' : 'echecs']++;
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['primesSms' . $m, json_encode($deja, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Vente', null, 'SMS à la réussite ' . $m . ' — ' . $n['envoyes'] . ' envoyé(s), ' . $n['echecs'] . ' en échec, ' . $n['sansNumero'] . ' sans numéro'
        . ($configure ? '' : ' (aucun fournisseur SMS réglé : config.php › sms)'));
    return ['actif' => true, 'configure' => $configure] + $n;
}
