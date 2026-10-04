<?php
declare(strict_types=1);

/**
 * Gestion de production — le module franchisé (demande du 03/10/2026, maquettes
 * docs/maquettes/gestion-production). Trois temps :
 *
 *  1. LES PARAMÈTRES d'un magasin : les cuissons (les périodes de vente du panel,
 *     /admin/sales-dayparts, plus une cuisson locale tant que le panel n'en a pas
 *     de 4e), le % de la journée que produit chaque cuisson (50 % à la première),
 *     les catégories × cuissons (pièces par plaque, dernière recuisson), et les
 *     règles (semaines de base, sécurité, arrondi, seuils de recuisson, commandes
 *     et webshop).
 *  2. LE PLAN DU JOUR, cuisson par cuisson et produit par produit : prévision = la
 *     moyenne des N derniers mêmes jours, heure par heure (tickets du panel, déjà
 *     gravés par le module des ventes) ; à cuire = prévision × part de la cuisson ×
 *     (1 + sécurité) + commandes + webshop à retirer pendant la période − stock
 *     estimé de la cuisson précédente, arrondi à la plaque.
 *  3. LE SUIVI ET LES RECUISSONS : avant chaque cuisson, le même calcul sur le
 *     réel — sorti du four, vendu (tickets relus toutes les dix minutes), jeté
 *     (poubelle du panel) — et un verdict par produit : recuire, tient, trop
 *     produit, trop tard.
 *
 * Tout vient de l'API du panel. Ce qui ne s'y lit pas (les reports de la veille,
 * ce qui a réellement été enfourné) se saisit : « Valider la cuisson ».
 */

const PP_PARTS = [50, 25, 15, 10];   // le % de la journée par cuisson, par défaut
const PP_TTL_CMD = 600;              // les commandes du jour : dix minutes
const PP_TTL_DP = 21600;             // les périodes de vente du panel : six heures
const PP_TTL_BASE = 21600;           // la base de prévision (jours passés) : six heures
const PP_MIN_JOUR = 0.3;             // en dessous, un produit ne se planifie pas (moins d'une pièce tous les trois jours)

/** « 06:30 » → 6.5 ; null si ce n'est pas une heure. */
function gpHeure(mixed $t): ?float
{
    if (!is_string($t) || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($t), $m)) { return null; }
    $h = (int) $m[1]; $mi = (int) $m[2];
    if ($h > 24 || $mi > 59 || ($h === 24 && $mi > 0)) { return null; }
    return $h + $mi / 60;
}

/** 6.5 → « 06:30 ». */
function gpHhmm(float $h): string
{
    $h = max(0.0, min(24.0, $h));
    $m = (int) round($h * 60);
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** Écrit un réglage dans ceo_app_setting. */
function gpEcrire(string $cle, array $v): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}

/**
 * Les périodes de vente du panel — [{id, nom, de, a}] triées, actives seulement.
 * Six heures en cache ; un panel muet ressert la dernière lecture, sinon [].
 */
function gpDayparts(): array
{
    $c = setting('gpDayparts');
    if (is_array($c) && isset($c['l']) && (int) ($c['ts'] ?? 0) > time() - PP_TTL_DP) { return $c['l']; }
    $ancien = is_array($c) && isset($c['l']) ? $c['l'] : [];
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return $ancien; }
    $r = PanelApi::get('/admin/sales-dayparts/active');
    if (!is_array($r)) { return $ancien; }
    $l = [];
    foreach ((function_exists('analyseListe') ? analyseListe($r) : $r) as $x) {
        if (!is_array($x) || (isset($x['is_active']) && !(int) $x['is_active'])) { continue; }
        $de = gpHeure((string) ($x['time_from'] ?? '')); $a = gpHeure((string) ($x['time_to'] ?? ''));
        if ($de === null || $a === null || $a <= $de) { continue; }
        $l[] = ['id' => (int) ($x['id'] ?? 0), 'nom' => trim((string) ($x['name'] ?? '')) ?: 'Période', 'de' => gpHhmm($de), 'a' => gpHhmm($a), 'ordre' => (int) ($x['sort_order'] ?? 0)];
    }
    usort($l, static fn ($a, $b) => strcmp($a['de'], $b['de']));
    foreach ($l as &$x) { unset($x['ordre']); } unset($x);
    if ($l === []) { return $ancien; }
    gpEcrire('gpDayparts', ['ts' => time(), 'l' => $l]);
    return $l;
}

/** Les parts par défaut pour n cuissons : 50 % à la première, le reste dans les proportions 25 / 15 / 10 / … */
function gpPartsDefaut(int $n): array
{
    if ($n <= 0) { return []; }
    if ($n === 1) { return [100]; }
    $poids = []; for ($i = 1; $i < $n; $i++) { $poids[] = PP_PARTS[$i] ?? 5; }
    $t = array_sum($poids); $out = [50]; $reste = 50;
    foreach ($poids as $i => $p) { $v = $i === count($poids) - 1 ? $reste : (int) round(50 * $p / $t); $out[] = $v; $reste -= $v; }
    return $out;
}

/**
 * Les cuissons par défaut : les périodes de vente du panel ; s'il y en a moins de
 * quatre et que la dernière dure au moins quatre heures, elle se coupe en deux
 * (la seconde moitié est une cuisson locale). Sans panel : quatre cuissons types.
 */
function gpCuissonsDefaut(array $dp): array
{
    $c = [];
    foreach ($dp as $x) { $c[] = ['nom' => $x['nom'], 'de' => $x['de'], 'a' => $x['a'], 'daypart' => $x['id']]; }
    if ($c === []) {
        $c = [['nom' => 'Matin', 'de' => '06:00', 'a' => '11:00', 'daypart' => null], ['nom' => 'Midi', 'de' => '11:00', 'a' => '13:00', 'daypart' => null],
            ['nom' => 'Après-midi', 'de' => '14:00', 'a' => '16:30', 'daypart' => null], ['nom' => 'Fin de journée', 'de' => '16:30', 'a' => '19:00', 'daypart' => null]];
    } elseif (count($c) < 4) {
        $d = $c[count($c) - 1]; $h1 = gpHeure($d['de']); $h2 = gpHeure($d['a']);
        if ($h2 - $h1 >= 4) {
            $mi = round(($h1 + $h2) / 2 * 2) / 2;
            $c[count($c) - 1]['a'] = gpHhmm($mi);
            $c[] = ['nom' => 'Fin de journée', 'de' => gpHhmm($mi), 'a' => $d['a'], 'daypart' => null];
        }
    }
    $p = gpPartsDefaut(count($c));
    foreach ($c as $i => &$x) { $x['id'] = 'c' . ($i + 1); $x['pct'] = $p[$i]; } unset($x);
    return $c;
}

/** Les règles par défaut. */
function gpReglesDefaut(): array
{
    // poidsJ7 : la part du même jour de la semaine passée dans la prévision, en % (demande du 04/10/2026).
    return ['semaines' => 6, 'securite' => 10, 'minPlaques' => 1, 'seuilRecuisson' => 80, 'seuilTrop' => 140, 'avance' => 45, 'poidsJ7' => 40, 'commandes' => true, 'webshop' => true];
}

/**
 * Les zones d'une cuisson pour une catégorie : de l'ouverture de sa période (minuit pour la
 * première cochée) à l'ouverture de la période cochée suivante (minuit le soir pour la dernière).
 * [id => [de, a]] dans l'ordre des cuissons.
 */
function gpZones(array $cuissons, array $coches): array
{
    $k = array_values(array_filter($cuissons, static fn ($c) => in_array($c['id'], $coches, true)));
    $z = [];
    foreach ($k as $i => $c) { $z[$c['id']] = [$i === 0 ? 0.0 : gpHeure($c['de']), isset($k[$i + 1]) ? gpHeure($k[$i + 1]['de']) : 24.0]; }
    return $z;
}

/** La somme d'un profil horaire [h => q] entre deux heures décimales (l'heure entamée au prorata). */
function gpSomme(array $prof, float $a, float $b): float
{
    $s = 0.0;
    foreach ($prof as $h => $q) {
        $h = (float) $h;
        $lo = max($a, $h); $hi = min($b, $h + 1);
        if ($hi > $lo) { $s += (float) $q * ($hi - $lo); }
    }
    return $s;
}

/**
 * Les cuissons cochées par défaut pour une catégorie : celles dont la période capte au moins
 * 20 % de ses ventes de la journée (la fenêtre de la première commence à minuit, celle de la
 * dernière finit à minuit) ; sinon la plus forte. Les boissons, l'épicerie et le B2B ne passent
 * pas au four : rien de coché.
 */
function gpCochesDefaut(array $cuissons, array $prof, ?string $groupe = null): array
{
    if ($groupe !== null && preg_match('/boisson|épicerie|epicerie|b\.?\s*2\s*b|bundle/iu', $groupe)) { return []; }
    $tot = array_sum($prof);
    if ($tot <= 0) { return [$cuissons[0]['id'] ?? 'c1']; }
    $z = gpZones($cuissons, array_column($cuissons, 'id'));
    $parts = [];
    foreach ($cuissons as $c) { [$a, $b] = $z[$c['id']]; $parts[$c['id']] = gpSomme($prof, $a, $b) / $tot; }
    $k = array_keys(array_filter($parts, static fn ($p) => $p >= 0.20));
    if ($k === []) { arsort($parts); $k = [array_key_first($parts)]; }
    return array_values(array_filter(array_column($cuissons, 'id'), static fn ($id) => in_array($id, $k, true)));
}

/** La fraction d'une pièce qu'une ligne de ticket représente : « 1/2 » dans le nom d'une portion. */
function gpFraction(string $cle, string $nom): float
{
    if (!str_contains($cle, ':')) { return 1.0; }
    $pos = mb_strrpos($nom, ' — ');
    $lib = $pos !== false ? trim(mb_substr($nom, $pos + 3)) : '';
    if (preg_match('#^(\d+)\s*/\s*(\d+)$#', $lib, $m) && (int) $m[2] > 0 && (int) $m[1] > 0) { return min(1.0, (int) $m[1] / (int) $m[2]); }
    return 1.0;
}

/**
 * Les ventes d'un jour pliées par pièce : [pid => [h => pièces]] et les noms — une portion
 * compte pour sa fraction de la pièce (deux demi-tartes = une tarte à cuire).
 */
function gpPlier(?array $p): array
{
    $q = []; $noms = [];
    foreach ((array) $p as $h => $lst) {
        foreach ((array) $lst as $cle => $x) {
            $cle = (string) $cle; $pid = (int) explode(':', $cle)[0];
            if ($pid <= 0 || !is_array($x)) { continue; }
            $nom = (string) ($x[0] ?? '');
            $f = gpFraction($cle, $nom);
            $q[$pid][(int) $h] = ($q[$pid][(int) $h] ?? 0.0) + (float) ($x[1] ?? 0) * $f;
            if (!str_contains($cle, ':') || !isset($noms[$pid])) { $pos = mb_strrpos($nom, ' — '); $noms[$pid] = str_contains($cle, ':') && $pos !== false ? mb_substr($nom, 0, $pos) : $nom; }
        }
    }
    return ['q' => $q, 'noms' => $noms];
}

/** Le catalogue pour la production : [pid => [nom, catId, cat]] et [catId => [nom, groupe]]. */
function gpCatalogue(): array
{
    static $memo = null;
    if ($memo !== null) { return $memo; }
    $prods = []; $cats = [];
    if (function_exists('panelCatalogue')) {
        $pc = panelCatalogue();
        foreach ((array) ($pc['produits'] ?? []) as $pid => $x) { $prods[(int) $pid] = ['nom' => (string) ($x['nom'] ?? ''), 'catId' => (int) ($x['catId'] ?? 0), 'cat' => (string) ($x['cat'] ?? '')]; }
        foreach ((array) ($pc['categories'] ?? []) as $cid => $x) { $cats[(int) $cid] = ['nom' => (string) ($x['nom'] ?? ''), 'groupe' => $x['groupe'] ?? null]; }
    }
    // Le groupe (la section) : le panel ne le porte que sur une partie des catégories — mesuré le
    // 03/10/2026, la plupart rendent null ; catalogueCategories() le complète. Une catégorie rangée
    // dans deux groupes (« Pâtisserie · Tartes ») prend le premier.
    if (function_exists('catalogueCategories')) {
        foreach ((array) catalogueCategories() as $cid => $x) {
            $g = isset($x['groupe']) && $x['groupe'] !== null && $x['groupe'] !== '' ? (string) $x['groupe'] : null;
            if ($g === null) { continue; }
            $avant = $cats[(int) $cid] ?? ['nom' => (string) ($x['nom'] ?? ''), 'groupe' => null];
            $cats[(int) $cid] = ['nom' => $avant['nom'] !== '' ? $avant['nom'] : (string) ($x['nom'] ?? ''), 'groupe' => ($avant['groupe'] ?? '') !== '' && $avant['groupe'] !== null ? $avant['groupe'] : $g];
        }
    }
    foreach ($cats as $cid => $x) { if (is_string($x['groupe']) && str_contains($x['groupe'], ' · ')) { $cats[$cid]['groupe'] = explode(' · ', $x['groupe'])[0]; } }
    return $memo = ['produits' => $prods, 'categories' => $cats];
}

/** Un nom de catégorie comparable : minuscules, espaces simples. */
function gpNomCle(string $n): string { return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($n))); }

/**
 * La catégorie d'un produit : celle du catalogue du panel ; à défaut, la catégorie du même NOM ;
 * l'identifiant qui préfixe celui du produit départage deux catégories du même nom (1410016 → 14100).
 * Mesuré le 03/10/2026 dans les quatre magasins : des produits récents (« Quiche Poulet &
 * Gorgonzola », « Mini - Gosette Pomme »…) manquent au catalogue et ne portaient que le nom de leur
 * catégorie — une seconde « Quiches », une « Viennoiserie réduction » hors de la section Viennoiserie.
 * [catId, cat, catCle, groupe].
 */
function gpCatDe(int $pid, ?array $x = null): array
{
    static $parNom = null;
    $cat = gpCatalogue();
    if ($parNom === null) { $parNom = []; foreach ($cat['categories'] as $cid => $c) { $k = gpNomCle((string) ($c['nom'] ?? '')); if ($k !== '' && !isset($parNom[$k])) { $parNom[$k] = (int) $cid; } } }
    $x = $x ?? ($cat['produits'][$pid] ?? null);
    $catId = (int) ($x['catId'] ?? 0); $nom = (string) ($x['cat'] ?? '');
    if ($nom === '' && function_exists('svCategories')) { $nom = (string) (svCategories()[$pid] ?? ''); }
    // Le préfixe d'abord quand il désigne une catégorie de ce nom : le panel a deux « Quiches »
    // (14000 et 14100), et 1410016 est une 14100.
    $pre = $pid >= 100000 ? intdiv($pid, 100) : 0;
    if ($catId <= 0 && $pre > 0 && isset($cat['categories'][$pre]) && ($nom === '' || gpNomCle((string) ($cat['categories'][$pre]['nom'] ?? '')) === gpNomCle($nom))) { $catId = $pre; }
    if ($catId <= 0 && $nom !== '' && isset($parNom[gpNomCle($nom)])) { $catId = $parNom[gpNomCle($nom)]; }
    if ($catId > 0 && (string) ($cat['categories'][$catId]['nom'] ?? '') !== '') { $nom = (string) $cat['categories'][$catId]['nom']; }
    if ($nom === '') { $nom = 'Sans catégorie'; }
    return ['catId' => $catId, 'cat' => $nom, 'catCle' => gpCleCat($catId, $nom), 'groupe' => $catId > 0 ? (string) ($cat['categories'][$catId]['groupe'] ?? '') : ''];
}

/** La clé d'une catégorie : son identifiant du panel ; à défaut, son nom. */
function gpCleCat(int $catId, string $cat): string
{
    return $catId > 0 ? (string) $catId : 'n:' . mb_strtolower(trim($cat));
}

/**
 * La base de prévision d'un jour : les N derniers mêmes jours de la semaine, heure par heure,
 * pièce par pièce — [jours, lus, fermes, manquants, produits: pid => {nom, cat, catCle, h: [h => moyenne]}].
 * Six heures en cache quand tous les jours sont lus.
 */
function gpBase(int $sid, string $date, int $semaines, int &$cout, int $budget, ?array $A = null): array
{
    // Le poids de J−7 (le même jour la semaine passée) : réglé par magasin, 40 % par défaut.
    $sp = setting('gpParams:' . $sid);
    $poids = max(0, min(100, (int) ((is_array($sp) ? ($sp['regles']['poidsJ7'] ?? null) : null) ?? gpReglesDefaut()['poidsJ7'])));
    $cle = 'gpBase6:' . $sid . ':' . $date . ':' . $semaines . ':' . $poids;
    $c = setting($cle);
    if (is_array($c) && isset($c['b']) && (int) ($c['ts'] ?? 0) > time() - PP_TTL_BASE) { return $c['b']; }
    $jours = []; for ($i = 1; $i <= $semaines; $i++) { $j = date('Y-m-d', strtotime($date . ' -' . (7 * $i) . ' days')); if (!defined('SV_DEBUT') || $j >= SV_DEBUT) { $jours[] = $j; } }
    // Les commandes (POS et webshop) ne sont pas des ventes comptoir : leurs articles sortent de la
    // base le jour et à l'heure de leur ticket — souvent payé avant le retrait (demande du 03/10/2026).
    $A ??= $jours !== [] ? gpCommandesArticles($sid, min($jours), $cout, $budget) : null;
    $somme = []; $noms = []; $lus = []; $fermes = []; $manquants = []; $retire = 0.0; $parJour = [];
    foreach ($jours as $j) {
        $p = function_exists('svProduitsJour') ? svProduitsJour($sid, $j, $cout, $budget) : null;
        if ($p === null) { $manquants[] = $j; continue; }
        $f = gpPlier($p);
        if ($A !== null) { foreach (gpCmdTicketsDuJour($A, $j) as $pid => $hs) { foreach ($hs as $h => $q) { if (isset($f['q'][$pid][$h])) { $r = min($q, $f['q'][$pid][$h]); $f['q'][$pid][$h] -= $r; $retire += $r; } } } }
        $tot = 0.0; foreach ($f['q'] as $hs) { $tot += array_sum($hs); }
        if ($tot <= 0) { $fermes[] = $j; continue; }
        $lus[] = $j;
        $parJour[$j] = $f['q'];
        foreach ($f['q'] as $pid => $hs) { foreach ($hs as $h => $q) { $somme[$pid][$h] = ($somme[$pid][$h] ?? 0.0) + $q; } }
        foreach ($f['noms'] as $pid => $n) { $noms[$pid] = $noms[$pid] ?? $n; }
    }
    $cat = gpCatalogue();
    $prods = [];
    $n = count($lus);
    // J−7 pèse `poidsJ7` %, les autres jours lus se partagent le reste. La moyenne simple (toutes
    // les semaines pareil) à 0 %, ou quand J−7 n'est pas lu, fermé, ou seul jour lu.
    $j7 = $jours[0] ?? null;
    $w = $poids > 0 && $j7 !== null && isset($parJour[$j7]) && $n >= 2 ? $poids / 100 : null;
    foreach ($somme as $pid => $hs) {
        ksort($hs);
        $h = [];
        foreach ($hs as $hh => $q) {
            if ($w === null) { $h[(int) $hh] = round($q / max(1, $n), 3); continue; }
            $q7 = (float) ($parJour[$j7][$pid][$hh] ?? 0.0);
            $h[(int) $hh] = round($w * $q7 + (1 - $w) * ($q - $q7) / ($n - 1), 3);
        }
        $x = $cat['produits'][$pid] ?? null;
        $k = gpCatDe((int) $pid, $x);
        $prods[$pid] = ['nom' => ($x['nom'] ?? '') !== '' ? $x['nom'] : ($noms[$pid] ?? ('Produit ' . $pid)), 'catId' => $k['catId'], 'cat' => $k['cat'], 'catCle' => $k['catCle'], 'groupe' => $k['groupe'], 'h' => $h];
    }
    $b = ['jours' => $jours, 'lus' => $lus, 'fermes' => $fermes, 'manquants' => $manquants, 'produits' => $prods, 'poidsJ7' => $w === null ? null : $poids, 'j7' => $j7,
        'commandes' => ['lues' => $A !== null, 'complet' => $A !== null && !$A['incomplet'], 'retirees' => round($retire / max(1, $n), 1)]];
    if ($manquants === [] && $A !== null && !$A['incomplet']) { gpEcrire($cle, ['ts' => time(), 'b' => $b]); }
    return $b;
}

/**
 * Les commandes d'un jour avec leurs articles : [{heure, canal, webshop, montant, statut, lignes: [[pid, q]], sansDetail}].
 * Dix minutes en cache ; null si le panel n'a jamais répondu. Jamais le client.
 */
function gpCommandes(int $sid, string $date): ?array
{
    $cout = 0;
    $A = gpCommandesArticles($sid, $date, $cout, defined('SV_BUDGET_DEMANDE') ? SV_BUDGET_DEMANDE : 300);
    return $A === null ? null : gpCmdRetraits($A, $date);
}

/**
 * Les commandes d'un magasin retirées depuis `$du`, avec leurs articles (demande du 03/10/2026 :
 * les commandes ne sont pas des ventes comptoir). Mesuré le 03/10/2026 : le panel ne joint aucun
 * article aux commandes (`products` vide, /client-orders/{id}/products 404) ; ils sont dans le
 * ticket de la commande (`id_transaction`), souvent payé un autre jour que le retrait. Chaque
 * commande : retrait (jour, heure), canal, webshop, montant, statut, ticket (jour, heure) et
 * lignes [[pid, pièces]] ; sans ticket (à payer au retrait) : sansDetail. Jamais le client.
 * La liste se relit toutes les dix minutes ; un ticket lu se garde (il ne change plus).
 * `incomplet` : des tickets restent à lire (budget de la requête) ; null si le panel n'a jamais répondu.
 */
function gpCommandesArticles(int $sid, string $du, int &$cout, int $budget): ?array
{
    $cle = 'ppCmdA:' . $sid . ':' . $du;
    $c = setting($cle);
    $liste = is_array($c) && isset($c['l']) && (int) ($c['ts'] ?? 0) > time() - PP_TTL_CMD ? $c['l'] : null;
    if ($liste === null) {
        $liste = is_array($c) && isset($c['l']) ? $c['l'] : null;
        $r = class_exists('PanelApi') && PanelApi::configured() ? PanelApi::sondeGet('/shops/' . $sid . '/client-orders?date_from=' . $du, 25) : ['code' => 0];
        // Mesuré à Gosselies : sans aucune commande, le panel répond {status: error, description:
        // NO_ORDERS_FOR_PICKUP_DATE} — une liste vide, pas une panne.
        if (is_array($r['corps'] ?? null) && str_contains((string) ($r['corps']['description'] ?? ''), 'NO_ORDERS')) { $r = ['code' => 200, 'corps' => []]; }
        if ((int) ($r['code'] ?? 0) === 200 && is_array($r['corps'] ?? null)) {
            $liste = [];
            foreach ((function_exists('analyseListe') ? analyseListe($r['corps']) : $r['corps']) as $o) {
                if (!is_array($o)) { continue; }
                $quand = (string) ($o['pick_up_datetime'] ?? '');
                if (substr($quand, 0, 10) < $du) { continue; }
                $canal = function_exists('coCanal') ? coCanal($o) : (!empty($o['is_webshop']) ? 'cc' : 'compt');
                $statut = function_exists('coStatut') ? coStatut($o, $canal) : '';
                if ($statut === 'annulée') { continue; }
                $liste[] = ['jour' => substr($quand, 0, 10), 'heure' => substr($quand, 11, 5), 'canal' => $canal, 'webshop' => $canal !== 'compt',
                    'montant' => round((float) ($o['total_value'] ?? 0), 2), 'statut' => $statut, 'tk' => (int) ($o['id_transaction'] ?? 0)];
            }
            gpEcrire($cle, ['ts' => time(), 'l' => $liste]);
        }
    }
    if ($liste === null) { return null; }
    // Les tickets des commandes : lus une fois, gardés par magasin (les plus vieux de 120 jours tombent).
    $ck = 'ppTk:' . $sid;
    $T = setting($ck); $T = is_array($T) ? $T : [];
    $aLire = []; foreach ($liste as $o) { if ($o['tk'] > 0 && !isset($T[(string) $o['tk']])) { $aLire[$o['tk']] = true; } }
    if ($aLire !== [] && $cout < $budget && class_exists('PanelApi') && PanelApi::configured()) {
        $lus = 0;
        foreach (array_chunk(array_slice(array_keys($aLire), 0, $budget - $cout), 40) as $lot) {
            $ch = []; foreach ($lot as $id) { $ch[$id] = '/transactions/' . $id . '?include=products'; }
            $res = PanelApi::getParallele($ch, 8);
            $cout += count($lot);
            foreach ($lot as $id) {
                $t = $res[$id] ?? null;
                if (!is_array($t) || !isset($t['insert_timestamp'])) { continue; }
                $l = [];
                foreach ((array) ($t['products'] ?? []) as $x) {
                    $pid = (int) ($x['id_product'] ?? 0);
                    if ($pid <= 0 || !is_array($x)) { continue; }
                    $f = function_exists('svPortion') ? (float) (svPortion($x)['fraction'] ?? 1) : 1.0;
                    $l[] = [$pid, round((float) ($x['quantity'] ?? 0) * ($f > 0 ? $f : 1.0), 3)];
                }
                $ts = (string) $t['insert_timestamp'];
                $T[(string) $id] = ['j' => substr($ts, 0, 10), 'h' => (int) substr($ts, 11, 2), 'l' => $l];
                $lus++;
            }
        }
        if ($lus > 0) {
            $lim = date('Y-m-d', strtotime('-120 days'));
            $T = array_filter($T, static fn ($x) => is_array($x) && ($x['j'] ?? '') >= $lim);
            gpEcrire($ck, $T);
        }
    }
    $out = []; $incomplet = false;
    foreach ($liste as $o) {
        $t = $o['tk'] > 0 ? ($T[(string) $o['tk']] ?? null) : null;
        if ($o['tk'] > 0 && $t === null) { $incomplet = true; }
        unset($o['tk']);
        $out[] = $o + ['ticket' => $t === null ? null : ['jour' => $t['j'], 'h' => $t['h']], 'lignes' => $t['l'] ?? [], 'sansDetail' => $t === null];
    }
    return ['du' => $du, 'commandes' => $out, 'incomplet' => $incomplet];
}

/** Les articles des tickets de commande encaissés un jour, à retirer du comptoir : [pid][heure] => pièces. */
function gpCmdTicketsDuJour(array $A, string $jour): array
{
    $o = [];
    foreach ($A['commandes'] as $c) {
        if (($c['ticket']['jour'] ?? null) !== $jour) { continue; }
        $h = (int) $c['ticket']['h'];
        foreach ($c['lignes'] as [$pid, $q]) { $o[(int) $pid][$h] = ($o[(int) $pid][$h] ?? 0.0) + (float) $q; }
    }
    return $o;
}

/** Les commandes retirées un jour, au format du plan : [{heure, canal, webshop, montant, statut, lignes, sansDetail}]. */
function gpCmdRetraits(array $A, string $jour): array
{
    $o = [];
    foreach ($A['commandes'] as $c) {
        if ($c['jour'] !== $jour) { continue; }
        $o[] = ['heure' => $c['heure'], 'canal' => $c['canal'], 'webshop' => $c['webshop'], 'montant' => $c['montant'], 'statut' => $c['statut'], 'lignes' => $c['lignes'], 'sansDetail' => $c['sansDetail'], 'clesArticle' => null];
    }
    usort($o, static fn ($a, $b) => strcmp($a['heure'], $b['heure']));
    return $o;
}

/** Les articles d'une commande : [[pid, quantité]] — l'identifiant du produit et la quantité, rien d'autre. */
function gpArticles(array $items): array
{
    $l = [];
    foreach ($items as $p) {
        if (!is_array($p)) { continue; }
        // L'identifiant du PRODUIT : jamais celui de la ligne de commande quand le produit est imbriqué.
        $sous = isset($p['product']) && is_array($p['product']) ? $p['product'] : null;
        $cands = [$p['id_product'] ?? null, $p['product_id'] ?? null, $p['productId'] ?? null, $sous['id_product'] ?? null, $sous['id'] ?? null, $sous === null ? ($p['id'] ?? null) : null];
        $pid = 0; foreach ($cands as $v) { if (is_numeric($v) && (int) $v > 0) { $pid = (int) $v; break; } }
        $pp = $sous !== null ? $p + $sous : $p;
        if ($pid <= 0) { continue; }
        $q = 1.0; foreach (['quantity', 'qty', 'amount', 'count'] as $k) { if (isset($pp[$k]) && is_numeric($pp[$k]) && (float) $pp[$k] > 0) { $q = (float) $pp[$k]; break; } }
        $l[] = [$pid, round($q, 3)];
    }
    return $l;
}

/**
 * Les paramètres d'un magasin, complétés par les défauts : les cuissons enregistrées (sinon
 * celles du panel), les règles, et une ligne par catégorie vendue dans la base (enregistrée,
 * sinon cochée d'après ses ventes heure par heure — `auto`).
 */
function gpParams(int $sid, array $dp, array $base): array
{
    $s = setting('gpParams:' . $sid);
    $s = is_array($s) ? $s : [];
    $cuissons = isset($s['cuissons']) && is_array($s['cuissons']) && $s['cuissons'] !== [] ? array_values($s['cuissons']) : gpCuissonsDefaut($dp);
    $regles = array_merge(gpReglesDefaut(), is_array($s['regles'] ?? null) ? $s['regles'] : []);
    // Le profil de chaque catégorie, pour les coches par défaut.
    $prof = []; $noms = [];
    foreach ($base['produits'] as $p) { foreach ($p['h'] as $h => $q) { $prof[$p['catCle']][$h] = ($prof[$p['catCle']][$h] ?? 0.0) + $q; } $noms[$p['catCle']] = [$p['cat'], $p['catId']]; }
    $cfgS = is_array($s['categories'] ?? null) ? $s['categories'] : [];
    $ids = array_column($cuissons, 'id');
    $cats = [];
    foreach ($prof as $k => $hs) {
        $e = $cfgS[$k] ?? null;
        $pa = is_array($e) ? gpParts($e['parts'] ?? null, $ids) : null;
        $cats[(string) $k] = is_array($e)
            ? ['cuissons' => $pa !== null ? array_keys($pa) : array_values(array_filter((array) ($e['cuissons'] ?? []), static fn ($c) => in_array($c, $ids, true))), 'parts' => $pa, 'plaque' => isset($e['plaque']) && (int) $e['plaque'] > 0 ? (int) $e['plaque'] : null, 'limite' => gpHeure($e['limite'] ?? null) !== null ? gpHhmm(gpHeure($e['limite'])) : null, 'auto' => false]
            : ['cuissons' => gpCochesDefaut($cuissons, $hs, $noms[$k][1] > 0 ? (gpCatalogue()['categories'][$noms[$k][1]]['groupe'] ?? null) : null), 'parts' => null, 'plaque' => null, 'limite' => null, 'auto' => true];
        $cats[(string) $k]['nom'] = $noms[$k][0]; $cats[(string) $k]['catId'] = $noms[$k][1];
    }
    // Une catégorie réglée mais absente de la base (elle ne s'est pas vendue ces semaines-là) garde son réglage.
    foreach ($cfgS as $k => $e) {
        if (isset($cats[(string) $k]) || !is_array($e)) { continue; }
        $pa = gpParts($e['parts'] ?? null, $ids);
        $cats[(string) $k] = ['cuissons' => $pa !== null ? array_keys($pa) : array_values(array_filter((array) ($e['cuissons'] ?? []), static fn ($c) => in_array($c, $ids, true))), 'parts' => $pa, 'plaque' => isset($e['plaque']) && (int) $e['plaque'] > 0 ? (int) $e['plaque'] : null,
            'limite' => gpHeure($e['limite'] ?? null) !== null ? gpHhmm(gpHeure($e['limite'])) : null, 'auto' => false, 'nom' => (string) ($e['nom'] ?? $k), 'catId' => (int) ($e['catId'] ?? 0)];
    }
    return ['cuissons' => $cuissons, 'regles' => $regles, 'categories' => $cats, 'enregistre' => isset($s['cuissons']) || isset($s['categories']) || isset($s['regles']), 'maj' => $s['maj'] ?? null];
}

/**
 * Les parts propres d'une catégorie (demande du 03/10/2026 : « les tartes, c'est 30 matin, 30 midi,
 * 30 après-midi ») : [id de cuisson => %], dans l'ordre des cuissons, sans les parts nulles ; null
 * quand la catégorie suit les parts de la journée.
 */
function gpParts(mixed $p, array $ids): ?array
{
    if (!is_array($p)) { return null; }
    $o = [];
    foreach ($ids as $id) { $v = $p[$id] ?? null; if (is_numeric($v) && (float) $v > 0) { $o[$id] = round(min(100.0, (float) $v), 1); } }
    return $o === [] ? null : $o;
}

/**
 * Valide et normalise des paramètres envoyés par l'écran. [ok, erreur|null, params].
 * Cuissons : 1 à 6, horaires croissants sans chevauchement, parts à 100 %. Catégories :
 * cuissons existantes, plaque 1–500, dernière recuisson à l'heure. Règles bornées.
 */
function gpValider(array $p): array
{
    $c = array_values(array_filter((array) ($p['cuissons'] ?? []), 'is_array'));
    if (count($c) < 1 || count($c) > 6) { return [false, 'entre une et six cuissons', null]; }
    $out = []; $tot = 0.0; $fin = -1.0; $vus = [];
    foreach ($c as $i => $x) {
        $de = gpHeure($x['de'] ?? null); $a = gpHeure($x['a'] ?? null);
        $nom = trim((string) ($x['nom'] ?? '')) ?: 'Cuisson ' . ($i + 1);
        if ($de === null || $a === null || $a <= $de) { return [false, 'horaires de la cuisson « ' . $nom . ' » invalides', null]; }
        if ($de < $fin - 1e-9) { return [false, 'les cuissons se chevauchent (« ' . $nom . ' »)', null]; }
        $pct = $x['pct'] ?? null;
        if (!is_numeric($pct) || (float) $pct < 0 || (float) $pct > 100) { return [false, 'part de la cuisson « ' . $nom . ' » invalide', null]; }
        $id = preg_match('/^c\d{1,2}$/', (string) ($x['id'] ?? '')) && !isset($vus[(string) $x['id']]) ? (string) $x['id'] : null;
        if ($id === null) { $n = 1; while (isset($vus['c' . $n])) { $n++; } $id = 'c' . $n; }
        $vus[$id] = true;
        $out[] = ['id' => $id, 'nom' => mb_substr($nom, 0, 40), 'de' => gpHhmm($de), 'a' => gpHhmm($a), 'pct' => round((float) $pct, 1), 'daypart' => isset($x['daypart']) && (int) $x['daypart'] > 0 ? (int) $x['daypart'] : null];
        $tot += (float) $pct; $fin = $a;
    }
    if (abs($tot - 100) > 0.5) { return [false, 'les parts des cuissons font ' . round($tot, 1) . ' %, pas 100 %', null]; }
    $ids = array_column($out, 'id');
    $cats = [];
    foreach ((array) ($p['categories'] ?? []) as $k => $e) {
        if (!is_array($e) || !preg_match('/^(\d{1,9}|n:.{1,80})$/u', (string) $k)) { continue; }
        $pl = $e['plaque'] ?? null;
        if ($pl !== null && $pl !== '' && (!is_numeric($pl) || (int) $pl < 1 || (int) $pl > 500)) { return [false, 'pièces par plaque invalides pour « ' . ($e['nom'] ?? $k) . ' »', null]; }
        $lim = $e['limite'] ?? null;
        if ($lim !== null && $lim !== '' && gpHeure($lim) === null) { return [false, 'dernière recuisson invalide pour « ' . ($e['nom'] ?? $k) . ' »', null]; }
        $pa = $e['parts'] ?? null;
        if (is_array($pa)) { foreach ($pa as $v) { if ($v !== null && $v !== '' && (!is_numeric($v) || (float) $v < 0 || (float) $v > 100)) { return [false, 'part de cuisson invalide pour « ' . ($e['nom'] ?? $k) . ' »', null]; } } }
        $pa = gpParts($pa, $ids);
        $cats[(string) $k] = ['cuissons' => $pa !== null ? array_keys($pa) : array_values(array_unique(array_filter((array) ($e['cuissons'] ?? []), static fn ($x) => in_array($x, $ids, true)))), 'parts' => $pa,
            'plaque' => $pl !== null && $pl !== '' ? (int) $pl : null, 'limite' => $lim !== null && $lim !== '' ? gpHhmm(gpHeure($lim)) : null,
            'nom' => mb_substr((string) ($e['nom'] ?? ''), 0, 80), 'catId' => (int) ($e['catId'] ?? 0)];
    }
    $r = array_merge(gpReglesDefaut(), is_array($p['regles'] ?? null) ? $p['regles'] : []);
    $bornes = ['semaines' => [1, 12], 'securite' => [0, 50], 'minPlaques' => [0, 10], 'seuilRecuisson' => [0, 100], 'seuilTrop' => [100, 400], 'avance' => [0, 180], 'poidsJ7' => [0, 100]];
    foreach ($bornes as $k => [$lo, $hi]) {
        if (!is_numeric($r[$k]) || (float) $r[$k] < $lo || (float) $r[$k] > $hi) { return [false, 'règle « ' . $k . ' » hors bornes (' . $lo . ' à ' . $hi . ')', null]; }
        $r[$k] = $k === 'securite' ? round((float) $r[$k], 1) : (int) $r[$k];
    }
    $r['commandes'] = !empty($r['commandes']); $r['webshop'] = !empty($r['webshop']);
    $r = array_intersect_key($r, gpReglesDefaut());
    return [true, null, ['cuissons' => $out, 'categories' => $cats, 'regles' => $r]];
}

/** Le prix de vente de chaque produit dans ce magasin (products/available), la moyenne du réseau à défaut : [pid => €]. */
function gpPrix(int $sid): array
{
    $m = function_exists('cataloguePrixMagasin') ? cataloguePrixMagasin($sid) : [];
    $r = function_exists('cataloguePrix') ? cataloguePrix() : [];
    return $m + $r;
}

/** Arrondi à la plaque : [plaques|null, pièces à sortir]. */
function gpArrondi(float $aCuire, ?int $plaque, int $minPlaques): array
{
    if ($aCuire < 0.5) { return [$plaque ? 0 : null, 0]; }
    if (!$plaque) { return [null, (int) ceil($aCuire - 1e-6)]; }
    $n = max($minPlaques, (int) ceil($aCuire / $plaque - 1e-6));
    return [$n, $n * $plaque];
}

/** Les lignes de commande d'un produit retirées dans une zone horaire : [comptoir, webshop]. */
function gpCmdZone(array $cmds, int $pid, float $a, float $b): array
{
    $c = 0.0; $w = 0.0;
    foreach ($cmds as $o) {
        $h = gpHeure($o['heure'] ?? '');
        if ($h === null || $h < $a || $h >= $b) { continue; }
        foreach ($o['lignes'] as [$p, $q]) { if ((int) $p === $pid) { if (!empty($o['webshop'])) { $w += (float) $q; } else { $c += (float) $q; } } }
    }
    return [$c, $w];
}

/**
 * Le plan du jour : pour chaque cuisson, les produits des catégories cochées — prévision,
 * part, prévu + sécurité, commandes, webshop, stock estimé, à cuire, plaques. `faits` (ce qui
 * a été réellement enfourné, validé à l'écran) remplace la sortie prévue pour le stock suivant.
 */
function gpPlan(array $params, array $base, array $cmds, array $faits = [], array $prix = [], array $opts = []): array
{
    // Options du flux de production (/production) : le stock de départ (le report de la veille),
    // la production minimum de la 1re cuisson (part de la journée) et les obligatoires du jour
    // (pid => pièces minimum), planifiés même sans vente dans la base.
    $stock0 = (array) ($opts['stock0'] ?? []);
    $minPct = isset($opts['minPct']) && is_numeric($opts['minPct']) ? max(0.0, min(1.0, (float) $opts['minPct'])) : null;
    $oblig = []; foreach ((array) ($opts['oblig'] ?? []) as $k => $v) { $oblig[(int) $k] = max(0, (int) $v); }
    $C = $params['cuissons']; $R = $params['regles'];
    $sec = 1 + (float) $R['securite'] / 100; $minPl = (int) $R['minPlaques'];
    if (!$R['commandes']) { $cmds = array_values(array_filter($cmds, static fn ($o) => !empty($o['webshop']))); }
    if (!$R['webshop']) { $cmds = array_values(array_filter($cmds, static fn ($o) => empty($o['webshop']))); }
    $pct = []; foreach ($C as $c) { $pct[$c['id']] = (float) $c['pct']; }
    $lignes = []; foreach ($C as $c) { $lignes[$c['id']] = []; }
    $poidsCat = []; $poidsGrp = [];
    $prods = $base['produits'];
    if ($oblig !== []) {
        $cat = gpCatalogue();
        foreach ($oblig as $pid => $_) {
            if (isset($prods[$pid])) { continue; }
            $x = $cat['produits'][$pid] ?? null;
            if ($x === null) { continue; }
            $k = gpCatDe((int) $pid, $x);
            $prods[$pid] = ['nom' => (string) $x['nom'] !== '' ? (string) $x['nom'] : 'Produit ' . $pid, 'catId' => $k['catId'], 'cat' => $k['cat'], 'catCle' => $k['catCle'], 'groupe' => $k['groupe'], 'h' => []];
        }
    }
    // Un produit commandé ce jour-là se planifie même sans vente comptoir dans la base.
    $commandes = [];
    foreach ($cmds as $o) { foreach ((array) ($o['lignes'] ?? []) as [$cpid, $cq]) { if ((float) $cq > 0) { $commandes[(int) $cpid] = true; } } }
    if ($commandes !== []) {
        $cat = gpCatalogue();
        foreach ($commandes as $cpid => $_) {
            if (isset($prods[$cpid])) { continue; }
            $x = $cat['produits'][$cpid] ?? null;
            if ($x === null) { continue; }
            $k = gpCatDe((int) $cpid, $x);
            $prods[$cpid] = ['nom' => (string) $x['nom'] !== '' ? (string) $x['nom'] : 'Produit ' . $cpid, 'catId' => $k['catId'], 'cat' => $k['cat'], 'catCle' => $k['catCle'], 'groupe' => $k['groupe'], 'h' => []];
        }
    }
    foreach ($prods as $pid => $p) {
        $cfg = $params['categories'][$p['catCle']] ?? null;
        $prevJ = array_sum($p['h']);
        $ob = $oblig[(int) $pid] ?? null;
        // Une catégorie qui ne passe pas au four ce jour-là cuit ses commandes à la 1re cuisson.
        if ($ob === null && isset($commandes[(int) $pid]) && ($cfg === null || $cfg['cuissons'] === [])) { $cfg = ['cuissons' => [$C[0]['id']], 'plaque' => $cfg['plaque'] ?? null, 'limite' => null]; }
        // Un obligatoire d'une catégorie qui ne passe pas au four ce jour-là : la 1re cuisson.
        if ($ob !== null && ($cfg === null || $cfg['cuissons'] === [])) { $cfg = ['cuissons' => [$C[0]['id']], 'plaque' => $cfg['plaque'] ?? null]; }
        if ($cfg === null || $cfg['cuissons'] === [] || ($prevJ < PP_MIN_JOUR && $ob === null && !isset($commandes[(int) $pid]))) { continue; }
        $poidsCat[$p['catCle']] = ($poidsCat[$p['catCle']] ?? 0.0) + $prevJ;
        $grp = (string) ($p['groupe'] ?? '') !== '' ? (string) $p['groupe'] : $p['cat'];
        $poidsGrp[$grp] = ($poidsGrp[$grp] ?? 0.0) + $prevJ;
        $pu = isset($prix[(int) $pid]) ? (float) $prix[(int) $pid] : null;
        $z = gpZones($C, $cfg['cuissons']);
        $tp = 0.0; foreach ($z as $id => $_) { $tp += $pct[$id]; }
        // La part de chaque cuisson ; la 1re relevée à la production minimum, les suivantes réduites d'autant.
        $parts = []; foreach ($z as $id => $_) { $parts[$id] = $tp > 0 ? $pct[$id] / $tp : 0.0; }
        // Une catégorie qui a ses propres parts les suit ; le minimum de la 1re cuisson du jour ne vaut
        // que pour celles qui suivent les parts de la journée.
        $propres = is_array($cfg['parts'] ?? null) ? array_intersect_key($cfg['parts'], $z) : [];
        $tq = array_sum($propres);
        if ($tq > 0) { foreach ($z as $id => $_) { $parts[$id] = (float) ($propres[$id] ?? 0) / $tq; } }
        $id0 = array_key_first($z);
        if ($tq <= 0 && $minPct !== null && $id0 !== null && count($z) > 1 && $parts[$id0] < $minPct) {
            $r0 = 1 - $parts[$id0];
            foreach ($parts as $id => $v) { $parts[$id] = $id === $id0 ? $minPct : ($r0 > 0 ? $v * (1 - $minPct) / $r0 : 0.0); }
        }
        $stock = (float) ($stock0[(int) $pid] ?? 0); $prec = null;
        foreach ($z as $id => [$za, $zb]) {
            if ($prec !== null) {
                // Ce qui reste de la cuisson précédente à l'ouverture de celle-ci.
                [$pa] = $z[$prec['id']];
                $stock = max(0.0, $prec['stock'] + $prec['sortie'] - gpSomme($p['h'], $pa, $za) - $prec['cmd'] - $prec['ws']);
            }
            $part = $parts[$id];
            $ap = $prevJ * $part * $sec;
            if ($ob !== null && $id === $id0) { $ap = max($ap, (float) $ob); }
            [$cm, $ws] = gpCmdZone($cmds, (int) $pid, $za, $zb);
            $aCuire = max(0.0, $ap + $cm + $ws - $stock);
            [$pl, $sortie] = gpArrondi($aCuire, $cfg['plaque'], $minPl);
            $fait = $faits[$id][(string) $pid] ?? null;
            $lignes[$id][] = ['pid' => (int) $pid, 'nom' => $p['nom'], 'cat' => $p['cat'], 'catCle' => $p['catCle'], 'groupe' => $grp, 'prix' => $pu, 'ca' => $pu !== null ? round($pu * $sortie, 2) : null, 'prevJ' => round($prevJ, 2), 'h' => $p['h'],
                'zone' => [gpHhmm($za), gpHhmm($zb)], 'fenetre' => round(gpSomme($p['h'], $za, $zb), 2), 'part' => round(100 * $part, 1), 'prevu' => round($ap, 2),
                'cmd' => round($cm, 2), 'ws' => round($ws, 2), 'stock' => round($stock, 2), 'aCuire' => round($aCuire, 2), 'plaque' => $cfg['plaque'], 'plaques' => $pl, 'sortie' => $sortie,
                'fait' => $fait !== null ? (float) $fait : null, 'oblig' => $ob !== null];
            $prec = ['id' => $id, 'stock' => $stock, 'sortie' => $fait !== null ? (float) $fait : (float) $sortie, 'cmd' => $cm, 'ws' => $ws];
        }
    }
    $av = (int) $R['avance'];
    $out = [];
    foreach ($C as $i => $c) {
        $L = $lignes[$c['id']];
        // Section (groupe du panel) › catégorie › produit, chacun du plus vendu au moins vendu : les sous-totaux se lisent d'un bloc.
        usort($L, static fn ($a, $b) => [($poidsGrp[$b['groupe']] ?? 0), $a['groupe'], ($poidsCat[$b['catCle']] ?? 0), $a['cat'], $b['prevJ']] <=> [($poidsGrp[$a['groupe']] ?? 0), $b['groupe'], ($poidsCat[$a['catCle']] ?? 0), $b['cat'], $a['prevJ']]);
        $t = ['pieces' => 0, 'plaques' => 0, 'prevu' => 0.0, 'cmd' => 0.0, 'ws' => 0.0, 'stock' => 0.0, 'ca' => 0.0, 'sansPrix' => 0];
        $cats = [];
        foreach ($L as $l) { $t['pieces'] += $l['sortie']; $t['plaques'] += (int) $l['plaques']; $t['prevu'] += $l['prevu']; $t['cmd'] += $l['cmd']; $t['ws'] += $l['ws']; $t['stock'] += $l['stock']; $cats[$l['catCle']] = true;
            if ($l['ca'] !== null) { $t['ca'] += $l['ca']; } elseif ($l['sortie'] > 0) { $t['sansPrix']++; } }
        $h1 = gpHeure($c['de']);
        $out[] = ['id' => $c['id'], 'k' => $i + 1, 'nom' => $c['nom'], 'de' => $c['de'], 'a' => $c['a'], 'pct' => $c['pct'], 'panel' => !empty($c['daypart']),
            'four' => gpHhmm(max(0.0, $h1 - $av / 60)), 'lignes' => $L,
            'total' => ['pieces' => $t['pieces'], 'plaques' => $t['plaques'], 'prevu' => round($t['prevu'], 1), 'cmd' => round($t['cmd'], 1), 'ws' => round($t['ws'], 1), 'stock' => round($t['stock'], 1), 'categories' => count($cats), 'ca' => round($t['ca'], 2), 'sansPrix' => $t['sansPrix']]];
    }
    return $out;
}

/**
 * Le suivi avant la prochaine cuisson (celle dont la période n'a pas encore ouvert) : pour
 * chaque produit qu'elle concerne, sorti des cuissons d'avant (validé, sinon prévu), vendu,
 * jeté, stock, besoin jusqu'à la cuisson suivante de la catégorie, à enfourner et verdict.
 * null quand la journée n'a plus de cuisson.
 */
function gpSuivi(array $params, array $base, array $cmds, array $plan, array $vendu, array $jete, float $now, array $faits = [], array $prix = []): ?array
{
    $C = $params['cuissons']; $R = $params['regles'];
    $sec = 1 + (float) $R['securite'] / 100; $minPl = (int) $R['minPlaques'];
    if (!$R['commandes']) { $cmds = array_values(array_filter($cmds, static fn ($o) => !empty($o['webshop']))); }
    if (!$R['webshop']) { $cmds = array_values(array_filter($cmds, static fn ($o) => empty($o['webshop']))); }
    $prochaine = null;
    foreach ($C as $c) { if (gpHeure($c['de']) > $now) { $prochaine = $c; break; } }
    if ($prochaine === null) { return null; }
    $planDe = []; foreach ($plan as $pc) { foreach ($pc['lignes'] as $l) { $planDe[$pc['id']][$l['pid']] = $l; } }
    $fin = 0.0; foreach ($C as $c) { $fin = max($fin, gpHeure($c['a'])); }
    $L = []; $t = ['vendu' => 0.0, 'prevu' => 0.0, 'cuire' => 0, 'plaques' => 0, 'plan' => 0, 'planPlaques' => 0, 'ca' => 0.0];
    foreach ($planDe[$prochaine['id']] ?? [] as $pid => $lp) {
        $cfg = $params['categories'][$lp['catCle']] ?? null;
        if ($cfg === null) { continue; }
        $p = $base['produits'][$pid] ?? null; $h = $p['h'] ?? [];
        $produit = 0.0;
        foreach ($C as $c) {
            if ($c['id'] === $prochaine['id']) { break; }
            if (!in_array($c['id'], $cfg['cuissons'], true)) { continue; }
            $f = $faits[$c['id']][(string) $pid] ?? null;
            $produit += $f !== null ? (float) $f : (float) (($planDe[$c['id']][$pid]['sortie'] ?? 0));
        }
        $vd = array_sum($vendu[$pid] ?? []);
        $jt = (float) ($jete[$pid] ?? 0);
        $stock = max(0.0, $produit - $vd - $jt);
        $prevuMaint = gpSomme($h, 0.0, $now);
        // Le stock doit tenir jusqu'à l'ouverture de la cuisson suivante de la catégorie, sinon jusqu'à la fermeture.
        $suiv = null; $vu = false;
        foreach ($C as $c) { if ($vu && in_array($c['id'], $cfg['cuissons'], true)) { $suiv = $c; break; } if ($c['id'] === $prochaine['id']) { $vu = true; } }
        $jusqua = $suiv !== null ? gpHeure($suiv['de']) : $fin;
        [$cm, $ws] = gpCmdZone($cmds, (int) $pid, $now, $jusqua);
        $besoin = gpSomme($h, $now, $jusqua) * $sec + $cm + $ws;
        $couv = $besoin > 0 ? $stock / $besoin : ($stock > 0 ? 9.0 : 1.0);
        $lim = $cfg['limite'] !== null ? gpHeure($cfg['limite']) : null;
        $manque = max(0.0, $besoin - $stock);
        [$pl, $sortie] = gpArrondi($manque, $cfg['plaque'], 1);
        if ($lim !== null && $now >= $lim) { $v = 'tard'; }
        elseif ($besoin > 0 && 100 * $couv >= (float) $R['seuilTrop']) { $v = 'trop'; }
        elseif ($besoin <= 0 && $stock > 0) { $v = 'trop'; }
        elseif (100 * $couv >= (float) $R['seuilRecuisson'] || $sortie === 0 || $besoin < 0.5) { $v = 'tient'; }
        else { $v = 'recuire'; }
        if ($v !== 'recuire') { $pl = $cfg['plaque'] ? 0 : null; $sortie = 0; }
        $pu = isset($prix[(int) $pid]) ? (float) $prix[(int) $pid] : ($lp['prix'] ?? null);
        $L[] = ['pid' => (int) $pid, 'nom' => $lp['nom'], 'cat' => $lp['cat'], 'catCle' => $lp['catCle'], 'groupe' => $lp['groupe'] ?? '', 'prix' => $pu, 'ca' => $pu !== null ? round($pu * $sortie, 2) : null, 'h' => $h, 'plaque' => $cfg['plaque'], 'limite' => $cfg['limite'],
            'produit' => round($produit, 1), 'vendu' => round($vd, 1), 'jete' => round($jt, 1), 'prevuMaintenant' => round($prevuMaint, 1),
            'ecart' => $prevuMaint >= 1 ? round(100 * ($vd / $prevuMaint - 1), 1) : null, 'stock' => round($stock, 1), 'jusqua' => gpHhmm($jusqua),
            'cmd' => round($cm, 2), 'ws' => round($ws, 2), 'besoin' => round($besoin, 1), 'couverture' => round(min(9.0, $couv) * 100, 1),
            'plan' => (int) $lp['sortie'], 'planPlaques' => $lp['plaques'], 'verdict' => $v, 'aEnfourner' => $sortie, 'plaques' => $pl];
        $t['vendu'] += $vd; $t['prevu'] += $prevuMaint; $t['cuire'] += $sortie; $t['plaques'] += (int) $pl; $t['plan'] += (int) $lp['sortie']; $t['planPlaques'] += (int) $lp['plaques']; $t['ca'] += $pu !== null ? $pu * $sortie : 0;
    }
    $nb = []; foreach ($L as $l) { $nb[$l['verdict']] = ($nb[$l['verdict']] ?? 0) + 1; }
    $h1 = gpHeure($prochaine['de']);
    return ['maintenant' => gpHhmm($now), 'cuisson' => ['id' => $prochaine['id'], 'nom' => $prochaine['nom'], 'de' => $prochaine['de'], 'a' => $prochaine['a'], 'four' => gpHhmm(max(0.0, $h1 - (int) $R['avance'] / 60))],
        'lignes' => $L, 'verdicts' => $nb,
        'total' => ['vendu' => round($t['vendu'], 1), 'prevuMaintenant' => round($t['prevu'], 1), 'ecart' => $t['prevu'] > 0 ? round(100 * ($t['vendu'] / $t['prevu'] - 1), 1) : null,
            'aEnfourner' => $t['cuire'], 'plaques' => $t['plaques'], 'plan' => $t['plan'], 'planPlaques' => $t['planPlaques'], 'ca' => round($t['ca'], 2)]];
}

/** Les commandes du jour avec, pour chaque ligne, le produit et la cuisson où elle entre. */
function gpCommandesVues(array $cmds, array $params, array $base): array
{
    $cat = gpCatalogue();
    $out = [];
    foreach ($cmds as $o) {
        $h = gpHeure($o['heure']);
        $lig = []; $cu = [];
        foreach ($o['lignes'] as [$pid, $q]) {
            $p = $base['produits'][(int) $pid] ?? null;
            $nom = $p['nom'] ?? ($cat['produits'][(int) $pid]['nom'] ?? ('Produit ' . $pid));
            $cfg = $p !== null ? ($params['categories'][$p['catCle']] ?? null) : null;
            $dans = null;
            if ($cfg !== null && $cfg['cuissons'] !== [] && $h !== null) { foreach (gpZones($params['cuissons'], $cfg['cuissons']) as $id => [$a, $b]) { if ($h >= $a && $h < $b) { $dans = $id; } } }
            if ($dans !== null) { $cu[$dans] = true; }
            $lig[] = ['pid' => (int) $pid, 'nom' => $nom, 'q' => $q, 'cuisson' => $dans];
        }
        $out[] = ['heure' => $o['heure'], 'canal' => $o['canal'], 'webshop' => $o['webshop'], 'montant' => $o['montant'], 'statut' => $o['statut'], 'sansDetail' => $o['sansDetail'], 'lignes' => $lig, 'cuissons' => array_keys($cu),
            'clesArticle' => $o['clesArticle'] ?? null];
    }
    return $out;
}

/**
 * GET /production/plan?shop=4&date=YYYY-MM-DD — les paramètres résolus, le plan du jour par
 * cuisson, les commandes, et (pour aujourd'hui) le suivi avant la prochaine cuisson.
 */
function ep_production_plan(): array
{
    $auj = date('Y-m-d');
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = $auj; }
    if ($date > date('Y-m-d', strtotime($auj . ' +7 days'))) { $date = $auj; }
    @set_time_limit(90);
    $dp = gpDayparts();
    $s = setting('gpParams:' . $sid);
    $sem = is_array($s) && isset($s['regles']['semaines']) ? max(1, min(12, (int) $s['regles']['semaines'])) : gpReglesDefaut()['semaines'];
    $cout = 0;
    $base = gpBase($sid, $date, $sem, $cout, defined('SV_BUDGET_DEMANDE') ? SV_BUDGET_DEMANDE : 500);
    $params = gpParams($sid, $dp, $base);
    $cmdsLus = gpCommandes($sid, $date);
    $cmds = $cmdsLus ?? [];
    $faitsS = setting('ppFait:' . $sid . ':' . $date);
    $faits = is_array($faitsS) && is_array($faitsS['c'] ?? null) ? $faitsS['c'] : [];
    $prix = gpPrix($sid);
    $plan = gpPlan($params, $base, $cmds, $faits, $prix);
    $suivi = null;
    if ($date === $auj) {
        $now = (int) date('G') + (int) date('i') / 60;
        $pv = function_exists('svProduitsJour') ? svProduitsJour($sid, $date, $cout, defined('SV_BUDGET_DEMANDE') ? SV_BUDGET_DEMANDE : 500) : null;
        $vendu = gpPlier($pv)['q'];
        $jete = [];
        if (function_exists('invLignes')) { foreach ((array) invLignes($sid, $date, $date) as $l) { $jete[(int) $l['pid']] = ($jete[(int) $l['pid']] ?? 0.0) + (float) $l['pieces']; } }
        $suivi = gpSuivi($params, $base, $cmds, $plan, $vendu, $jete, $now, $faits, $prix);
        if ($suivi !== null) { $suivi['ventesLues'] = $pv !== null; }
    }
    // Les catégories pour l'écran des paramètres : la part de leurs ventes dans chaque cuisson.
    $catVue = [];
    $C = $params['cuissons'];
    $zTout = gpZones($C, array_column($C, 'id'));
    $profCat = [];
    foreach ($base['produits'] as $p) { foreach ($p['h'] as $h => $q) { $profCat[$p['catCle']][$h] = ($profCat[$p['catCle']][$h] ?? 0.0) + $q; } }
    $cc = gpCatalogue()['categories'];
    foreach ($params['categories'] as $k => $e) {
        $hs = $profCat[$k] ?? []; $tot = array_sum($hs);
        $parts = []; foreach ($C as $c) { [$a, $b] = $zTout[$c['id']]; $parts[$c['id']] = $tot > 0 ? round(100 * gpSomme($hs, $a, $b) / $tot, 1) : null; }
        $catVue[] = ['cle' => (string) $k, 'nom' => $e['nom'], 'catId' => $e['catId'], 'groupe' => $e['catId'] > 0 ? ($cc[$e['catId']]['groupe'] ?? null) : null, 'parJour' => round($tot, 1), 'ventesParCuisson' => $parts,
            'cuissons' => $e['cuissons'], 'plaque' => $e['plaque'], 'limite' => $e['limite'], 'auto' => $e['auto']];
    }
    usort($catVue, static fn ($a, $b) => [(string) $a['groupe'], -$a['parJour']] <=> [(string) $b['groupe'], -$b['parJour']]);
    $totJ = 0.0; foreach ($base['produits'] as $p) { $totJ += array_sum($p['h']); }
    return ['shop' => $sid, 'date' => $date, 'aujourdhui' => $auj, 'jourSemaine' => (int) date('N', strtotime($date)),
        'params' => ['cuissons' => $C, 'regles' => $params['regles'], 'enregistre' => $params['enregistre'], 'maj' => $params['maj']],
        'categories' => $catVue, 'dayparts' => $dp,
        'base' => ['semaines' => $sem, 'jours' => $base['jours'], 'lus' => $base['lus'], 'fermes' => $base['fermes'], 'manquants' => $base['manquants'], 'piecesParJour' => round($totJ, 1)],
        'plan' => $plan, 'commandes' => gpCommandesVues($cmds, $params, $base), 'commandesLues' => $cmdsLus !== null, 'faits' => $faits, 'suivi' => $suivi,
        'source' => 'tickets du panel (moyenne des ' . $sem . ' derniers mêmes jours, heure par heure) · périodes de vente /admin/sales-dayparts · commandes /shops/{id}/client-orders · poubelle /shops/{id}/products/waste'];
}

/** POST /production/plan/params — { shop, params: { cuissons, categories, regles } }. */
function wr_production_params(): array
{
    $b = body();
    $sid = (int) ($b['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'magasin manquant']; }
    [$ok, $err, $p] = gpValider(is_array($b['params'] ?? null) ? $b['params'] : []);
    if (!$ok) { http_response_code(422); return ['error' => $err]; }
    $p['maj'] = date('c'); $p['par'] = mb_substr(trim((string) ($b['par'] ?? '')), 0, 80) ?: null;
    gpEcrire('gpParams:' . $sid, $p);
    return ['ok' => true, 'params' => $p];
}

/** POST /production/plan/fait — { shop, date, cuisson, lignes: { pid: pièces } } : ce qui a été réellement enfourné. */
function wr_production_fait(): array
{
    $b = body();
    $sid = (int) ($b['shop'] ?? 0); $date = (string) ($b['date'] ?? ''); $cu = (string) ($b['cuisson'] ?? '');
    if ($sid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^c\d{1,2}$/', $cu)) { http_response_code(400); return ['error' => 'magasin, date ou cuisson manquant'];
    }
    $l = [];
    foreach ((array) ($b['lignes'] ?? []) as $pid => $q) {
        if (!preg_match('/^\d{1,9}$/', (string) $pid) || !is_numeric($q) || (float) $q < 0 || (float) $q > 5000) { http_response_code(422); return ['error' => 'quantité invalide pour le produit ' . $pid]; }
        $l[(string) $pid] = round((float) $q, 2);
    }
    $cle = 'ppFait:' . $sid . ':' . $date;
    $c = setting($cle); $c = is_array($c) && is_array($c['c'] ?? null) ? $c : ['c' => []];
    $c['c'][$cu] = $l; $c['le'] = date('c');
    gpEcrire($cle, $c);
    return ['ok' => true, 'cuisson' => $cu, 'lignes' => count($l)];
}
