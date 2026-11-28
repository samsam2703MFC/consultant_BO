<?php
declare(strict_types=1);

/**
 * Dashboard magasin › Vendre › Clients (25/11/2026 ; maquettes panel/docs/maquettes/clients-panel/) : le module « Klienci »
 * du panel TF Buddy (Klienci indywidualni, Firmy B2B, Biura, Analiza klientów B2B) dans le dashboard franchisé. Sam :
 * « let's go and code it all », puis « use either all token available from consultant or admin for the repo » : les
 * seuls comptes du cockpit, le consultant (PanelApi) d'abord, l'admin (ErpApi) ensuite — pas de connexion par SMS.
 *
 * TF Buddy garde tout : rien n'est stocké ici, sauf la liste des clients du magasin gardée un quart d'heure
 * (`pclClients:{shop}:{type}`, ceo_app_setting) et les mois clos du relevé des tickets pro (`pclMois:{shop}:{mois}`).
 *
 *   GET  /dashboard/clients/particuliers?shop=3&q=dup[&filtre=remise][&compte=en_ligne|sans_compte][&frais=1]
 *                                                                                 recherche (50 au plus) ; `enLigne`, ceux qui ont un compte webshop
 *   GET  /dashboard/clients/entreprises?shop=3[&frais=1]                          toutes les entreprises du magasin
 *   GET  /dashboard/clients/fiche?shop=3&id=812                                   la fiche (profil, factures, 12 mois)
 *   POST /dashboard/clients/client {shop, action, id?, champs}                    creer · modifier · profil · entreprise · supprimer
 *   POST /dashboard/clients/tva {shop, tva}                                       la TVA vue par TF Buddy, puis VIES
 *   GET  /dashboard/clients/analyse?shop=3&annee=2026&mois=11                     les six chiffres du mois, l'année
 *   GET  /dashboard/clients/bureaux?shop=3&chemin=/12/sites[&query=…]             relais des bureaux (liste blanche)
 *   POST /dashboard/clients/bureaux {shop, methode, chemin, corps}                écriture des bureaux (liste blanche)
 *   POST /dashboard/clients/bureaux/pdf {shop, bureau, type, corps}               affiche, cartes, brochure (le PDF tel quel)
 *
 * Les routes de TF Buddy :
 *   - hors panel, lues et écrites par les deux comptes : `/shops/{s}/clients` (liste, création), `/shops/{s}/clients/{id}`
 *     (fiche + profil commercial : score, type, mode, crédit ; PATCH du profil), `/clients/{id}` (PATCH des coordonnées,
 *     DELETE), `/shops/{s}/clients/vat-lookup`, `/vies/lookup`, `/shops/{s}/clients/{id}/invoices` ;
 *   - sous `/panel/shops/{s}/…` (`b2b/clients`, `b2b/dashboard`, `offices…`) : mesuré le 24/11/2026, les deux comptes y
 *     sont refusés (404 ORDER_NOT_FOUND : le jeton n'a pas le magasin). Chaque lecture y est TENTÉE ; refusée, l'écran le
 *     dit (« TF Buddy ne donne pas … au compte du cockpit ») et, quand c'est possible, se rabat sur ce qui se lit :
 *     les entreprises sur `/shops/{s}/clients?type=b2b` et le relevé des tickets pro (consultant/src/ventes_pro.php) ;
 *     l'analyse B2B sur ce même relevé. Les bureaux n'ont pas de repli : ils attendent l'accès de service (R1 de
 *     panel/docs/livreur/CAHIER_DES_CHARGES_TFBUDDY.md).
 *
 * Ce qui ne sort jamais : les champs secrets d'une réponse (mot de passe, hachage, jeton, PIN, IBAN…, pclPropre), le
 * téléphone entier d'un particulier dans une liste (la fiche le montre). Une écriture sur un client vérifie d'abord qu'il
 * est un client du magasin (`/shops/{s}/clients/{id}` répond) ; la suppression, qu'il est né dans ce magasin
 * (`id_main_shop`) : un client partagé avec un autre magasin ne s'efface pas d'ici.
 */

const PCL_TTL_LISTE = 900;       // la liste des clients d'un magasin, gardée un quart d'heure ; une écriture l'oublie
const PCL_LIMITE = 50;           // une recherche de particuliers rend 50 fiches au plus
const PCL_PAGE = 500;            // le repli par pages (limit + offset), comme les bons (marketing/src/promos_bons.php)
const PCL_PAGES_MAX = 80;
/** La forme d'une ligne gardée (`pclClients:{shop}:{type}`) : 2 depuis le 28/11/2026, la date de la dernière connexion au
 *  webshop (`connexion`). Une liste gardée sous une autre forme se relit dans TF Buddy. */
const PCL_VERSION_LISTE = 2;
/** Le filtre du compte en ligne des particuliers (28/11/2026) : ceux qui ont un compte webshop, ceux qui n'en ont pas. */
const PCL_FILTRES_COMPTE = ['en_ligne', 'sans_compte'];
const PCL_TYPES_CLIENT = ['standard', 'vip', 'restaurant', 'hotel', 'wholesale', 'other'];
const PCL_MODES = ['delivery', 'pickup', 'both'];
const PCL_SCORES = ['A', 'B', 'C', 'D'];
/** Les coordonnées qu'un magasin peut écrire (PATCH /clients/{id}). */
const PCL_CHAMPS_COORD = ['name', 'surname', 'company_name', 'tax_number', 'street', 'street_number', 'city', 'zip', 'comment', 'phone', 'email', 'peppol_identifier'];
/** Une fiche de client telle que l'écran la reçoit (le reste de la réponse de TF Buddy ne sort pas). */
const PCL_CHAMPS_FICHE = ['id', 'id_main_shop', 'is_b2b', 'name', 'surname', 'company_name', 'tax_number', 'can_deferral', 'street', 'street_number',
    'city', 'zip', 'comment', 'phone', 'email', 'payment_terms', 'personal_discount_percent', 'create_timestamp', 'peppol_identifier', 'peppol_verified',
    'invoice_name', 'invoice_address', 'invoice_postal_code', 'invoice_city', 'invoice_country', 'locale'];

/* ── Ce qui ne dépend de rien (testé : panel/bin/tests_clients.php) ─────────────────────────────────────────────── */

function pclShop(?array $b = null): int
{
    return (int) ($b !== null ? ($b['shop'] ?? 0) : ($_GET['shop'] ?? 0));
}

/** Une clé de réponse qui ne sort jamais : mot de passe, hachage, jeton, secret, PIN, IBAN, code SMS… */
function pclSecret(string $k): bool
{
    return (bool) preg_match('/pass|hash|token|secret|salt|api_?key|refresh|otp|iban|cvv|(^|_)pin($|_)|sms_code|auth_code|verification_code/i', $k);
}

/** La réponse de TF Buddy sans ses champs secrets, à toute profondeur. */
function pclPropre(mixed $v): mixed
{
    if (!is_array($v)) { return $v; }
    $out = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && pclSecret($k)) { continue; }
        $out[$k] = pclPropre($x);
    }
    return $out;
}

function pclTexte($v): string
{
    return is_scalar($v) ? trim((string) $v) : '';
}

function pclNombre($v): ?float
{
    return $v === null || $v === '' || !is_numeric($v) ? null : (float) $v;
}

/** Les éléments d'une réponse de liste : la liste elle-même, ou sous data / items / clients / offices. */
function pclItems($corps): array
{
    if (!is_array($corps)) { return []; }
    if (array_is_list($corps)) { return $corps; }
    foreach (['data', 'items', 'clients', 'offices', 'results'] as $k) {
        if (isset($corps[$k]) && is_array($corps[$k])) { return pclItems($corps[$k]); }
    }
    return [];
}

/** Le nom d'un client : la société d'un B2B, sinon prénom et nom, sinon la société, sinon son numéro. */
function pclNom(array $c): string
{
    $soc = pclTexte($c['company_name'] ?? $c['absolute_name'] ?? '');
    if (!empty($c['is_b2b']) && $soc !== '') { return $soc; }
    $n = trim(pclTexte($c['name'] ?? '') . ' ' . pclTexte($c['surname'] ?? ''));
    return $n !== '' ? $n : ($soc !== '' ? $soc : 'client n° ' . (int) ($c['id'] ?? 0));
}

/** Un téléphone montré en partie dans une liste (« +32 4•• •• •• 18 ») ; la fiche le montre en entier. */
function pclMasqueTel(string $tel): string
{
    $tel = trim($tel);
    $ch = preg_replace('/\D+/', '', $tel);
    if (strlen($ch) < 6) { return $tel === '' ? '' : '••'; }
    // L'indicatif et le premier chiffre (« +32 4 »), ou les trois premiers d'un numéro national (« 049 »).
    $debut = str_starts_with($tel, '+') ? '+' . substr($ch, 0, 2) . ' ' . substr($ch, 2, 1) : substr($ch, 0, 3);
    return $debut . '•• •• •• ' . substr($ch, -2);
}

/**
 * Ce qui manque à une entreprise pour que ses factures et ses offres partent (les règles du panel, index.twig :
 * « niekompletne dane ») : le nom de la société, l'adresse (rue et ville), la TVA, un e-mail ou un téléphone.
 */
function pclManques(array $c): array
{
    $m = [];
    if (pclTexte($c['company_name'] ?? $c['absolute_name'] ?? '') === '') { $m[] = 'nom'; }
    if (pclTexte($c['street'] ?? '') === '' || pclTexte($c['city'] ?? '') === '') { $m[] = 'adresse'; }
    if (pclTexte($c['tax_number'] ?? '') === '') { $m[] = 'tva'; }
    if (pclTexte($c['email'] ?? '') === '' && pclTexte($c['phone'] ?? '') === '') { $m[] = 'contact'; }
    return $m;
}

/** Une fiche de la liste `/shops/{s}/clients`, réduite à ce que la liste et la recherche montrent (gardée un quart d'heure). */
function pclCourt(array $c): array
{
    return ['id' => (int) ($c['id'] ?? 0), 'b2b' => !empty($c['is_b2b']), 'nom' => pclNom($c),
        'prenom' => pclTexte($c['name'] ?? ''), 'nomFam' => pclTexte($c['surname'] ?? ''), 'societe' => pclTexte($c['company_name'] ?? ''),
        'tva' => pclTexte($c['tax_number'] ?? ''), 'rue' => trim(pclTexte($c['street'] ?? '') . ' ' . pclTexte($c['street_number'] ?? '')),
        'cp' => pclTexte($c['zip'] ?? ''), 'ville' => pclTexte($c['city'] ?? ''), 'tel' => pclTexte($c['phone'] ?? ''), 'email' => pclTexte($c['email'] ?? ''),
        'remise' => pclNombre($c['personal_discount_percent'] ?? null), 'delai' => pclNombre($c['payment_terms'] ?? null),
        'differe' => !empty($c['can_deferral']), 'cree' => substr(pclTexte($c['create_timestamp'] ?? ''), 0, 10),
        'main' => (int) ($c['id_main_shop'] ?? 0), 'manques' => !empty($c['is_b2b']) ? pclManques($c) : [],
        // Le compte en ligne (28/11/2026) : TF Buddy note la dernière connexion au webshop (last_login_at) ; elle n'existe
        // que pour un client qui a un mot de passe. Le jour seulement ; vide = pas de compte (un client créé en caisse).
        'connexion' => substr(pclTexte($c['last_login_at'] ?? ''), 0, 10)];
}

/** Le texte où chercher un client : nom, société, TVA, e-mail, et les chiffres du téléphone. */
function pclCle(array $c): string
{
    return mb_strtolower(implode(' ', [$c['nom'], $c['prenom'], $c['nomFam'], $c['societe'], $c['tva'], $c['email'], $c['ville'],
        preg_replace('/\D+/', '', $c['tel'])]));
}

/** Les particuliers qui répondent à la recherche : chaque mot doit s'y trouver (un numéro se cherche par ses chiffres). */
function pclChercher(array $liste, string $q, string $filtre = '', string $compte = ''): array
{
    $mots = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($q))) ?: [], static fn ($m) => $m !== ''));
    $out = [];
    foreach ($liste as $c) {
        if ($filtre === 'remise' && !(($c['remise'] ?? 0) > 0)) { continue; }
        if ($compte !== '' && (($c['connexion'] ?? '') !== '') !== ($compte === 'en_ligne')) { continue; }
        $cle = pclCle($c);
        foreach ($mots as $m) {
            // « 0471 » trouve « +32 471 … » : un numéro se cherche sans son zéro de tête.
            $ok = str_contains($cle, $m) || (preg_match('/^\+?\d{3,}$/', $m) && str_contains($cle, ltrim(ltrim($m, '+'), '0')));
            if (!$ok) { continue 2; }
        }
        $out[] = $c;
    }
    // La recherche par le nom d'abord ; sans recherche, les derniers créés d'abord.
    usort($out, $mots === []
        ? static fn ($a, $b) => strcmp($b['cree'], $a['cree']) ?: $b['id'] <=> $a['id']
        : static fn ($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));
    return $out;
}

/** Ce que la liste des particuliers montre : le téléphone en partie. */
function pclLigneParticulier(array $c): array
{
    return ['id' => $c['id'], 'nom' => $c['nom'], 'tel' => pclMasqueTel($c['tel']), 'email' => $c['email'] !== '', 'ville' => $c['ville'],
        'cp' => $c['cp'], 'remise' => $c['remise'], 'cree' => $c['cree'],
        'enLigne' => ($c['connexion'] ?? '') !== '', 'connexion' => ($c['connexion'] ?? '') !== '' ? $c['connexion'] : null];
}

/** Le niveau d'alerte d'un crédit utilisé (les seuils du panel) : danger ≥ 100 %, attention ≥ 80 %, moyen ≥ 50 %. */
function pclAlerteCredit(?float $pct): ?string
{
    if ($pct === null) { return null; }
    return $pct >= 100 ? 'danger' : ($pct >= 80 ? 'attention' : ($pct >= 50 ? 'moyen' : 'ok'));
}

/** Le crédit d'une entreprise : la limite (profil), l'utilisé (factures impayées, ou ce que dit le panel). */
function pclCredit(?bool $actif, ?float $limite, ?float $utilise): ?array
{
    if (!$actif && ($limite === null || $limite <= 0)) { return null; }
    $pct = $limite !== null && $limite > 0 && $utilise !== null ? round(100 * $utilise / $limite, 1) : null;
    return ['actif' => (bool) $actif, 'limite' => $limite, 'utilise' => $utilise !== null ? round($utilise, 2) : null, 'pct' => $pct, 'alerte' => pclAlerteCredit($pct)];
}

/** Une entreprise de la liste riche du panel (`/panel/shops/{s}/b2b/clients`). */
function pclEntreprisePanel(array $c): array
{
    $p = is_array($c['profile'] ?? null) ? $c['profile'] : [];
    $r = is_array($c['recurrence'] ?? null) ? $c['recurrence'] : [];
    $k = is_array($c['credit'] ?? null) ? $c['credit'] : [];
    $credit = $k ? pclCredit(!empty($k['enabled']), pclNombre($k['limit'] ?? null), pclNombre($k['used'] ?? null)) : null;
    if ($credit !== null && pclNombre($k['percent_used'] ?? null) !== null) { $credit['pct'] = round((float) $k['percent_used'], 1); $credit['alerte'] = pclAlerteCredit($credit['pct']); }
    $c['is_b2b'] = 1;
    return ['id' => (int) ($c['id'] ?? 0), 'nom' => pclNom($c), 'tva' => pclTexte($c['tax_number'] ?? ''), 'ville' => pclTexte($c['city'] ?? ''),
        'email' => pclTexte($c['email'] ?? '') !== '', 'tel' => pclTexte($c['phone'] ?? '') !== '',
        'score' => in_array(strtoupper(pclTexte($p['score'] ?? '')), PCL_SCORES, true) ? strtoupper(pclTexte($p['score'])) : null,
        'type' => pclTexte($p['client_type'] ?? '') ?: null, 'mode' => pclTexte($p['service_mode'] ?? '') ?: null,
        'n12' => (int) ($c['orders_12m'] ?? 0), 'ca12' => round((float) ($c['ca_12m'] ?? 0), 2), 'panier' => pclNombre($c['avg_ticket'] ?? null),
        'dernier' => substr(pclTexte($c['last_order_at'] ?? ''), 0, 10) ?: null, 'premier' => substr(pclTexte($c['first_order_at'] ?? ''), 0, 10) ?: null,
        'cycle' => pclNombre($r['median_days'] ?? null), 'recurrence' => pclTexte($r['status'] ?? '') ?: null, 'depuis' => isset($r['days_since_last']) ? (int) $r['days_since_last'] : null,
        'differe' => !empty($c['can_deferral']), 'credit' => $credit, 'manques' => pclManques($c)];
}

/** Une entreprise de la liste de base (`/shops/{s}/clients?type=b2b`), complétée par le relevé des tickets pro (12 mois). */
function pclEntrepriseBase(array $c, ?array $r): array
{
    return ['id' => $c['id'], 'nom' => $c['nom'], 'tva' => $c['tva'], 'ville' => $c['ville'], 'email' => $c['email'] !== '', 'tel' => $c['tel'] !== '',
        'score' => null, 'type' => null, 'mode' => null, 'n12' => $r['n'] ?? 0, 'ca12' => round((float) ($r['ca'] ?? 0), 2),
        'panier' => !empty($r['n']) ? round($r['ca'] / $r['n'], 2) : null, 'dernier' => $r['dernier'] ?? null, 'premier' => $r['premier'] ?? null,
        'cycle' => $r['cycle'] ?? null, 'recurrence' => null, 'depuis' => null, 'differe' => $c['differe'], 'credit' => null, 'manques' => $c['manques']];
}

/** La médiane des écarts, en jours, entre des jours d'achat (« Y-m-d ») : le cycle d'achat. Moins de trois achats : null. */
function pclCycle(array $jours): ?float
{
    $jours = array_values(array_unique($jours));
    sort($jours);
    if (count($jours) < 3) { return null; }
    $e = [];
    for ($i = 1, $n = count($jours); $i < $n; $i++) { $e[] = (strtotime($jours[$i] . ' 12:00') - strtotime($jours[$i - 1] . ' 12:00')) / 86400; }
    sort($e);
    $m = intdiv(count($e), 2);
    return count($e) % 2 ? (float) $e[$m] : round(($e[$m - 1] + $e[$m]) / 2, 1);
}

/**
 * Le pro d'un mois, depuis les jours du relevé (vpLu : [date => b], b = {ca, n, t: [[heure, clé, montant, facturé, différé, net?]]}) :
 * le CA et les tickets du magasin, le pro (montant après remise personnelle, vpNet), les factures, et chaque compte
 * (« c{id} ») avec son CA, ses tickets et ses jours d'achat.
 */
function pclAgregerMois(array $jours): array
{
    $m = ['ca' => 0.0, 'n' => 0, 'pro' => 0.0, 'tk' => 0, 'fac' => 0, 'facCa' => 0.0, 'cli' => [], 'lus' => 0];
    foreach ($jours as $j => $b) {
        if (!is_array($b)) { continue; }
        $m['lus']++;
        $m['ca'] += (float) ($b['ca'] ?? 0); $m['n'] += (int) ($b['n'] ?? 0);
        foreach ((array) ($b['t'] ?? []) as $t) {
            if (!is_array($t)) { continue; }
            $net = (float) ($t[5] ?? $t[2] ?? 0);
            $m['pro'] += $net; $m['tk']++;
            if (!empty($t[3])) { $m['fac']++; $m['facCa'] += $net; }
            $cle = (string) ($t[1] ?? '');
            if ($cle === '') { continue; }
            $x = $m['cli'][$cle] ?? [0.0, 0, []];
            $x[0] += $net; $x[1]++;
            if (!in_array((string) $j, $x[2], true)) { $x[2][] = (string) $j; }
            $m['cli'][$cle] = $x;
        }
    }
    foreach (['ca', 'pro', 'facCa'] as $k) { $m[$k] = round($m[$k], 2); }
    foreach ($m['cli'] as $k => $x) { $m['cli'][$k][0] = round($x[0], 2); }
    return $m;
}

/** Les comptes de plusieurs mois réunis : [id client => {ca, n, dernier, premier, cycle}] (les clés « c{id} » seules). */
function pclParClient(array $mois): array
{
    $acc = [];
    foreach ($mois as $m) {
        foreach ((array) ($m['cli'] ?? []) as $cle => $x) {
            if (!preg_match('/^c(\d+)$/', (string) $cle, $mm)) { continue; }
            $id = (int) $mm[1];
            $a = $acc[$id] ?? ['ca' => 0.0, 'n' => 0, 'j' => []];
            $a['ca'] += (float) $x[0]; $a['n'] += (int) $x[1];
            $a['j'] = array_merge($a['j'], (array) $x[2]);
            $acc[$id] = $a;
        }
    }
    $out = [];
    foreach ($acc as $id => $a) {
        sort($a['j']);
        $out[$id] = ['ca' => round($a['ca'], 2), 'n' => $a['n'], 'premier' => $a['j'][0] ?? null, 'dernier' => $a['j'] ? end($a['j']) : null, 'cycle' => pclCycle($a['j'])];
    }
    return $out;
}

/** Les chiffres d'un mois du relevé, dans la forme de l'analyse. */
function pclChiffresMois(?array $m): ?array
{
    if ($m === null || ($m['lus'] ?? 0) === 0) { return null; }
    $uniq = count(array_filter(array_keys((array) $m['cli']), static fn ($k) => $k !== ''));
    return ['ventes' => $m['pro'], 'tickets' => $m['tk'], 'panier' => $m['tk'] > 0 ? round($m['pro'] / $m['tk'], 2) : null, 'clients' => $uniq,
        'part' => $m['ca'] > 0 ? round(100 * $m['pro'] / $m['ca'], 1) : null, 'factures' => $m['fac'], 'facturesCa' => $m['facCa'], 'ca' => $m['ca']];
}

/** L'écart en % d'un chiffre face à un autre (null si l'autre manque ou vaut zéro). Une part se compare en points. */
function pclEcart(?float $v, ?float $ref, bool $points = false): ?float
{
    if ($v === null || $ref === null) { return null; }
    if ($points) { return round($v - $ref, 1); }
    return $ref == 0.0 ? null : round(100 * ($v - $ref) / abs($ref), 1);
}

/** Les six chiffres de l'analyse, chacun face au mois d'avant et au même mois l'an dernier. */
function pclKpis(?array $c, ?array $avant, ?array $an): array
{
    $out = [];
    foreach (['ventes', 'tickets', 'panier', 'clients', 'part', 'factures'] as $k) {
        $v = $c[$k] ?? null;
        $out[$k] = ['v' => $v, 'mom' => pclEcart($v !== null ? (float) $v : null, isset($avant[$k]) ? (float) $avant[$k] : null, $k === 'part'),
            'yoy' => pclEcart($v !== null ? (float) $v : null, isset($an[$k]) ? (float) $an[$k] : null, $k === 'part')];
        if ($k === 'factures') { $out[$k]['ca'] = $c['facturesCa'] ?? null; }
    }
    return $out;
}

/** Les KPI du tableau de bord B2B de TF Buddy (`kpi.b2b_*` : {value, mom_pct, yoy_pct}) dans la forme de l'écran. */
function pclKpisPanel(array $kpi): array
{
    $map = ['ventes' => 'b2b_sales', 'tickets' => 'b2b_transactions', 'panier' => 'b2b_avg_ticket', 'clients' => 'b2b_unique_clients', 'part' => 'b2b_share_pct', 'factures' => 'b2b_invoices'];
    $out = [];
    foreach ($map as $k => $src) {
        $x = is_array($kpi[$src] ?? null) ? $kpi[$src] : [];
        $out[$k] = ['v' => pclNombre($x['value'] ?? null), 'mom' => pclNombre($x['mom_pct'] ?? null), 'yoy' => pclNombre($x['yoy_pct'] ?? null)];
    }
    return $out;
}

/** Une entrée de la liste des bureaux du panel, dans la forme de l'écran. */
function pclBureau(array $o): array
{
    return ['id' => (int) ($o['id'] ?? 0), 'nom' => pclTexte($o['name'] ?? ''), 'societe' => pclTexte($o['company_name'] ?? ''), 'idSociete' => (int) ($o['id_company_client'] ?? 0),
        'tva' => pclTexte($o['tax_number'] ?? ''), 'ville' => pclTexte($o['primary_city'] ?? ''), 'adresse' => trim(pclTexte($o['primary_address'] ?? '') . ' ' . pclTexte($o['primary_postal_code'] ?? '')),
        'contact' => pclTexte($o['contact_person'] ?? ''), 'statut' => pclTexte($o['status'] ?? '') ?: 'active', 'catalogue' => pclTexte($o['assortment_mode'] ?? '') ?: null,
        'sites' => (int) ($o['site_count'] ?? 0), 'departements' => (int) ($o['department_count'] ?? 0), 'offres' => (int) ($o['offer_count'] ?? 0),
        'catalogues' => (int) ($o['catalog_count'] ?? 0), 'siteDefaut' => (int) ($o['primary_site_id'] ?? 0), 'jours' => array_values(array_map('intval', (array) ($o['delivery_days'] ?? []))),
        'prix' => !array_key_exists('show_prices', $o) || !empty($o['show_prices']), 'facturation' => pclTexte($o['billing_mode'] ?? '') ?: null];
}

/**
 * Les chemins des bureaux que le dashboard peut demander à TF Buddy (après `/panel/shops/{s}/offices`) — la liste
 * blanche du paquet (OfficeService::read / write). Un numéro est un entier ; rien d'autre ne passe.
 */
function pclCheminBureau(string $methode, string $chemin): bool
{
    $id = '\/\d{1,9}';
    $r = [
        'GET' => ['', '\/options', '\/webshop-url', $id, "$id\/(assortment|offers|members|members\/search|sites|departments|contacts|vouchers|staff|invite|delivery-settings|print-batches)", '\/geo\/(autocomplete|place|postcodes)'],
        'POST' => ['', '\/onboard', '\/vies', "$id\/(offers|members|members\/invite|sites|departments|contacts|vouchers|staff|invite)"],
        'PATCH' => [$id, "$id\/editor", "$id\/(offers|sites|departments|contacts|staff)$id"],
        'PUT' => ["$id\/(assortment|catalog|settings|delivery-settings)", "$id\/offers$id\/products"],
        'DELETE' => [$id, "$id\/invite", "$id\/(offers|members|sites|departments|contacts|vouchers|staff)$id"],
    ];
    foreach ($r[$methode] ?? [] as $motif) {
        if (preg_match('/^' . $motif . '$/', $chemin)) { return true; }
    }
    return false;
}

/** Les paramètres de requête qu'un relais des bureaux transmet (recherche de personnes, lots d'impression, adresses). */
function pclParams(array $g): string
{
    $out = [];
    // `id` et `lang` : geo/place {id, lang} et geo/autocomplete {query, country, lang} (l'adresse d'un site, 26/11/2026).
    foreach (['query', 'q', 'before', 'input', 'place_id', 'id', 'lang', 'session', 'sessiontoken', 'postcode', 'postal_code', 'country', 'limit', 'offset'] as $k) {
        if (isset($g[$k]) && is_scalar($g[$k]) && (string) $g[$k] !== '') { $out[$k] = mb_substr((string) $g[$k], 0, 120); }
    }
    return $out ? '?' . http_build_query($out) : '';
}

/**
 * Une réponse de TF Buddy est-elle un REFUS d'accès (le jeton n'a pas ce magasin) plutôt qu'une erreur de la demande ?
 * 401, 403, et le 404 des routes du panel que le jeton ne couvre pas (ORDER_NOT_FOUND, mesuré le 24/11/2026).
 */
function pclRefus(int $code, $corps): bool
{
    if ($code === 401 || $code === 403) { return true; }
    if ($code !== 404) { return false; }
    $d = is_array($corps) ? strtoupper(implode(' ', array_filter([pclTexte($corps['description'] ?? ''), pclTexte($corps['message'] ?? ''), pclTexte($corps['error'] ?? ''), pclTexte($corps['status'] ?? '')]))) : '';
    return $d === '' || (bool) preg_match('/ORDER_NOT_FOUND|SHOP|ACCESS|FORBIDDEN|PERMISSION|SCOPE|DENIED/', $d);
}

/** Le message d'une réponse de TF Buddy, lisible au magasin. */
function pclMotif(array $r): string
{
    $c = $r['corps'] ?? null;
    $d = '';
    if (is_array($c)) {
        foreach (['description', 'message', 'reason', 'error', 'detail'] as $k) { if (pclTexte($c[$k] ?? '') !== '') { $d = pclTexte($c[$k]); break; } }
        $champs = [];
        foreach ((array) ($c['errors'] ?? []) as $k => $v) { $champs[] = (is_string($k) ? $k . ' : ' : '') . (is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : pclTexte($v)); }
        if ($champs) { $d .= ($d !== '' ? ' — ' : '') . implode(' ; ', $champs); }
    }
    $code = (int) ($r['code'] ?? 0);
    return $code === 0 ? 'TF Buddy injoignable' . ($r['erreur'] ? ' (' . $r['erreur'] . ')' : '') : 'HTTP ' . $code . ($d !== '' ? ' : ' . mb_substr($d, 0, 300) : '');
}

/* ── Les appels à TF Buddy : le compte consultant, puis le compte admin ──────────────────────────────────────── */

/**
 * Un appel à TF Buddy avec les comptes du cockpit : le consultant (PanelApi) d'abord, l'admin (ErpApi) si le premier
 * est refusé (401, 403, le 404 d'un magasin non couvert) ou injoignable. Une écriture refusée n'a rien changé : la
 * reprendre avec l'autre compte ne fait rien deux fois. → {code, corps, compte, erreur, refus}.
 */
function pclTf(string $methode, string $chemin, ?array $corps = null, int $timeout = 25): array
{
    // Trois comptes (25/11/2026, « all should work either with admin, consultant or shop token ») : le jeton du magasin
    // (tfb_magasin.php, une session du panel ouverte depuis le dashboard), le consultant (PanelApi), l'admin (ErpApi).
    // Les routes /panel/shops/{s}/… essaient le magasin d'abord (lui seul y a le droit) ; les autres, en dernier.
    $magasin = static fn () => function_exists('tfbmAppel') ? tfbmAppel($methode, $chemin, $corps, $timeout) : null;
    $consultant = static function () use ($methode, $chemin, $corps, $timeout): ?array {
        if (!class_exists('PanelApi') || !PanelApi::configured()) { return null; }
        if ($methode === 'GET') { $r = PanelApi::sondeGet($chemin, $timeout); return ['code' => (int) ($r['code'] ?? 0), 'corps' => $r['corps'] ?? null, 'erreur' => $r['erreur'] ?? null, 'compte' => 'consultant']; }
        $r = PanelApi::requete($methode, $chemin, $corps, $timeout);
        return ['code' => (int) ($r['code'] ?? 0), 'corps' => $r['data'] ?? null, 'erreur' => $r['erreur'] ?? null, 'compte' => 'consultant'];
    };
    $admin = static function () use ($methode, $chemin, $corps): ?array {
        if (!class_exists('ErpApi') || !ErpApi::disponible()) { return null; }
        $r = $methode === 'GET' ? ErpApi::sondeGet($chemin) : ErpApi::ecrire($methode, $chemin, $corps);
        return ['code' => (int) ($r['code'] ?? 0), 'corps' => $r['corps'] ?? null, 'erreur' => $r['erreur'] ?? null, 'compte' => 'admin'];
    };
    $ordre = str_starts_with($chemin, '/panel/') ? [$magasin, $consultant, $admin] : [$consultant, $admin, $magasin];
    $essais = [];
    foreach ($ordre as $f) {
        $r = $f();
        if ($r === null) { continue; }
        if ($r['code'] >= 200 && $r['code'] < 300) { return $r + ['refus' => false]; }
        $essais[] = $r;
        // Une écriture refusée pour une autre raison qu'un droit (422, 409, 404 d'une chose absente) : on s'arrête là.
        if ($r['code'] !== 0 && !pclRefus($r['code'], $r['corps'])) { return $r + ['refus' => false]; }
    }
    if (!$essais) { return ['code' => 0, 'corps' => null, 'erreur' => 'aucun compte TF Buddy configuré au cockpit', 'compte' => null, 'refus' => true]; }
    // Le refus le plus parlant : celui qui a un code (un injoignable ne dit rien du droit).
    usort($essais, static fn ($a, $b) => ($b['code'] !== 0) <=> ($a['code'] !== 0));
    $r = $essais[0];
    $r['refus'] = $r['code'] === 0 ? false : pclRefus($r['code'], $r['corps']);
    $r['compte'] = implode(' puis ', array_map(static fn ($e) => $e['compte'] . ' (' . $e['code'] . ')', $essais));
    // Le jeton du magasin envoyé et refusé en 401 : sa session est périmée, le navigateur doit le renouveler.
    $r['magasinExpire'] = (bool) array_filter($essais, static fn ($e) => $e['compte'] === 'magasin' && $e['code'] === 401);
    return $r;
}

/** « à » contracté devant l'article : « à les bureaux » → « aux bureaux », « à le profil » → « au profil ». */
function pclA(string $quoi): string
{
    if (str_starts_with($quoi, 'les ')) { return 'aux ' . substr($quoi, 4); }
    if (str_starts_with($quoi, 'le ')) { return 'au ' . substr($quoi, 3); }
    return 'à ' . $quoi;
}

/** Un refus d'accès dit au magasin : ce que TF Buddy ne donne pas au compte du cockpit, et pourquoi. */
function pclRefusDit(array $r, string $quoi): array
{
    return ['ok' => false, 'refus' => true, 'quoi' => $quoi, 'motif' => pclMotif($r), 'comptes' => $r['compte'] ?? null,
        // Au magasin, en clair : se connecter avec son compte TF Buddy (tfb_magasin.php) ouvre ces données tout de suite ;
        // l'accès de service (R1 de panel/docs/livreur/CAHIER_DES_CHARGES_TFBUDDY.md) les ouvrira sans connexion.
        'magasin' => function_exists('tfbmJeton') && tfbmJeton() !== null, 'magasinExpire' => !empty($r['magasinExpire']),
        'texte' => !empty($r['magasinExpire']) ? 'La session TF Buddy du magasin a expiré : reconnectez-vous pour lire ' . $quoi . '.'
            : (function_exists('tfbmJeton') && tfbmJeton() !== null ? 'Le compte TF Buddy connecté n’a pas accès ' . pclA($quoi) . ' de ce magasin.'
            : 'Les comptes du cockpit n’ont pas accès ' . pclA($quoi) . ' dans TF Buddy. Connectez le compte TF Buddy du magasin (téléphone, PIN, code SMS) pour les lire et les modifier ici.')];
}

/* ── La liste des clients d'un magasin, gardée un quart d'heure ───────────────────────────────────────────────── */

function pclMemoire(): void
{
    $l = strtolower(trim((string) ini_get('memory_limit')));
    if ($l === '' || $l === '-1') { return; }
    $n = (int) $l * match (substr($l, -1)) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
    if ($n < 512 * 1024 ** 2) { @ini_set('memory_limit', '512M'); }
}

/** Ajoute à $out les clients d'une réponse (fusionnés, bloqués, inactifs et l'autre type écartés) ; rend leur nombre lu. */
function pclAjouter(array &$out, $corps, string $type): int
{
    $l = pclItems($corps);
    foreach ($l as $x) {
        if (!is_array($x) || (int) ($x['id'] ?? 0) <= 0 || !empty($x['merged_into_id']) || !empty($x['blocked']) || (isset($x['active']) && !$x['active'])) { continue; }
        if (!empty($x['is_b2b']) !== ($type === 'b2b')) { continue; }
        $out[(int) $x['id']] = pclCourt($x);
    }
    return count($l);
}

/**
 * Les clients d'un magasin d'un type (« b2b », « b2c ») : [id => pclCourt]. D'un seul tenant d'abord, les pages ensuite
 * si la lecture d'un tenant est refusée ou rend un compte rond (le panel aurait coupé). Null : TF Buddy ne répond pas.
 * `$lu` reçoit l'heure de la lecture.
 */
function pclListe(int $shop, string $type, bool $frais = false, ?int &$lu = null): ?array
{
    $cle = 'pclClients:' . $shop . ':' . $type;
    $garde = &$GLOBALS['pclGarde'];
    if (!$frais && isset($garde[$cle])) { $lu = $garde[$cle][0]; return $garde[$cle][1]; }
    $c = !$frais && function_exists('setting') ? setting($cle) : null;
    if (is_array($c) && is_array($c['l'] ?? null) && (int) ($c['lu'] ?? 0) > time() - PCL_TTL_LISTE && (int) ($c['v'] ?? 1) === PCL_VERSION_LISTE) {
        $out = [];
        foreach ($c['l'] as $x) { if (is_array($x) && (int) ($x['id'] ?? 0) > 0) { $out[(int) $x['id']] = $x; } }
        $lu = (int) $c['lu'];
        $garde[$cle] = [$lu, $out];
        return $out;
    }
    pclMemoire();
    $out = [];
    $r = pclTf('GET', '/shops/' . $shop . '/clients?type=' . $type, null, 40);
    $n = $r['code'] >= 200 && $r['code'] < 300 && is_array($r['corps']) ? pclAjouter($out, $r['corps'], $type) : -1;
    unset($r);
    $partiel = false;
    if ($n < 0 || ($n > 0 && $n % 50 === 0)) {
        $out = [];
        for ($page = 0; $page < PCL_PAGES_MAX; $page++) {
            $p = pclTf('GET', '/shops/' . $shop . '/clients?type=' . $type . '&limit=' . PCL_PAGE . '&offset=' . ($page * PCL_PAGE));
            if ($p['code'] < 200 || $p['code'] >= 300) { if ($page === 0) { return null; } $partiel = true; break; }
            if (pclAjouter($out, $p['corps'], $type) < PCL_PAGE) { break; }
        }
    }
    $lu = time();
    $garde[$cle] = [$lu, $out];
    if (!$partiel && class_exists('Db')) {
        try {
            Db::exec('INSERT INTO ceo_app_setting (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$cle, json_encode(['v' => PCL_VERSION_LISTE, 'lu' => $lu, 'l' => array_values($out)], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* sans la garde, la recherche suivante relira TF Buddy : plus lente, toujours juste */ }
    }
    return $out;
}

/** Oublie les listes gardées d'un magasin (après une écriture). */
function pclOublier(int $shop): void
{
    foreach (['b2b', 'b2c'] as $t) {
        unset($GLOBALS['pclGarde']['pclClients:' . $shop . ':' . $t]);
        if (class_exists('Db')) { try { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['pclClients:' . $shop . ':' . $t]); } catch (Throwable $e) { /* la garde expire seule */ } }
    }
}

/* ── Le relevé des tickets pro, mois par mois (consultant/src/ventes_pro.php) ──────────────────────────────────── */

/**
 * Le pro d'un mois du magasin, lu dans le relevé des tickets (vpLu, sans appel au panel) : pclAgregerMois + `jours` (du
 * mois, jusqu'à aujourd'hui). Un mois clos et entièrement lu se garde (`pclMois:{shop}:{AAAA-MM}`) : il ne change plus.
 * Null : le relevé n'est pas là (le module des ventes pro n'est pas chargé) ou le mois n'est pas commencé.
 */
function pclMois(int $shop, string $mois, ?string $auj = null, ?int $jusqua = null): ?array
{
    $auj ??= date('Y-m-d');
    if (!function_exists('vpLu') || $mois . '-01' > $auj) { return null; }
    $cle = 'pclMois:' . $shop . ':' . $mois;
    $fin = min(date('Y-m-t', strtotime($mois . '-01 12:00')), $auj);
    // `$jusqua` : les premiers jours du mois seulement (un mois en cours se compare aux mêmes jours d'avant) ; jamais gardé.
    if ($jusqua !== null && $jusqua < (int) substr($fin, 8, 2)) { $fin = $mois . '-' . str_pad((string) max(1, $jusqua), 2, '0', STR_PAD_LEFT); }
    $clos = date('Y-m-t', strtotime($mois . '-01 12:00')) < $auj && $fin === date('Y-m-t', strtotime($mois . '-01 12:00'));
    $g = $clos && function_exists('setting') ? setting($cle) : null;
    if (is_array($g) && isset($g['lus'], $g['cli'])) { return $g; }
    $jours = [];
    for ($d = $mois . '-01'; $d <= $fin; $d = date('Y-m-d', strtotime($d . ' 12:00 +1 day'))) {
        $b = null;
        try { $b = vpLu($shop, $d); } catch (Throwable $e) { $b = null; }
        $jours[$d] = $b;
    }
    $m = pclAgregerMois(array_filter($jours, static fn ($b) => $b !== null));
    $m['jours'] = count($jours);
    if ($clos && $m['lus'] === $m['jours'] && class_exists('Db')) {
        try { Db::exec('INSERT INTO ceo_app_setting (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($m, JSON_UNESCAPED_UNICODE)]); }
        catch (Throwable $e) { /* relu la prochaine fois */ }
    }
    return $m;
}

/** Les douze derniers mois (le mois en cours compris) : [AAAA-MM]. */
function pclDouzeMois(string $auj): array
{
    $out = [];
    $d = strtotime(substr($auj, 0, 7) . '-01 12:00');
    for ($i = 11; $i >= 0; $i--) { $out[] = date('Y-m', strtotime('-' . $i . ' month', $d)); }
    return $out;
}

/* ── Les lectures ────────────────────────────────────────────────────────────────────────────────────────────── */

/** GET /dashboard/clients/particuliers — la recherche dans les particuliers du magasin. */
function ep_clients_particuliers(): array
{
    $shop = pclShop();
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $lu = null;
    $l = pclListe($shop, 'b2c', !empty($_GET['frais']), $lu);
    if ($l === null) { http_response_code(502); return ['error' => 'les particuliers du magasin ne se lisent pas dans TF Buddy']; }
    $q = mb_substr(pclTexte($_GET['q'] ?? ''), 0, 80);
    $f = pclTexte($_GET['filtre'] ?? '') === 'remise' ? 'remise' : '';
    $compte = in_array(pclTexte($_GET['compte'] ?? ''), PCL_FILTRES_COMPTE, true) ? pclTexte($_GET['compte']) : '';
    $t = pclChercher(array_values($l), $q, $f, $compte);
    return ['shop' => $shop, 'total' => count($l), 'trouves' => count($t), 'q' => $q, 'filtre' => $f, 'compte' => $compte,
        'clients' => array_map('pclLigneParticulier', array_slice($t, 0, PCL_LIMITE)), 'limite' => PCL_LIMITE,
        'avecRemise' => count(array_filter($l, static fn ($c) => ($c['remise'] ?? 0) > 0)),
        'enLigne' => count(array_filter($l, static fn ($c) => ($c['connexion'] ?? '') !== '')), 'lu' => $lu ? date('H:i', $lu) : null];
}

/** GET /dashboard/clients/entreprises — les entreprises du magasin (la liste riche du panel, sinon la base + le relevé). */
function ep_clients_entreprises(): array
{
    $shop = pclShop();
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $r = pclTf('GET', '/panel/shops/' . $shop . '/b2b/clients?type=b2b&with_profile=1&limit=1000&offset=0');
    if ($r['code'] >= 200 && $r['code'] < 300 && is_array($r['corps'])) {
        $l = array_values(array_filter(array_map(static fn ($c) => is_array($c) ? pclEntreprisePanel($c) : null, pclItems($r['corps'])), static fn ($c) => $c !== null && $c['id'] > 0));
        return ['shop' => $shop, 'source' => 'panel', 'compte' => $r['compte'], 'entreprises' => $l, 'total' => count($l),
            'incompletes' => count(array_filter($l, static fn ($c) => $c['manques'] !== []))];
    }
    $refus = pclRefusDit($r, 'le profil commercial des entreprises (score, crédit, récurrence)');
    $lu = null;
    $base = pclListe($shop, 'b2b', !empty($_GET['frais']), $lu);
    if ($base === null) { http_response_code(502); return ['error' => 'les entreprises du magasin ne se lisent pas dans TF Buddy', 'panel' => $refus]; }
    $auj = date('Y-m-d');
    $mois = [];
    foreach (pclDouzeMois($auj) as $m) { $x = pclMois($shop, $m, $auj); if ($x !== null) { $mois[$m] = $x; } }
    $par = pclParClient($mois);
    $l = [];
    foreach ($base as $id => $c) { $l[] = pclEntrepriseBase($c, $par[$id] ?? null); }
    usort($l, static fn ($a, $b) => $b['ca12'] <=> $a['ca12'] ?: strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));
    $jours = array_sum(array_map(static fn ($m) => $m['jours'], $mois)); $lus = array_sum(array_map(static fn ($m) => $m['lus'], $mois));
    return ['shop' => $shop, 'source' => 'base', 'panel' => $refus, 'entreprises' => $l, 'total' => count($l),
        'incompletes' => count(array_filter($l, static fn ($c) => $c['manques'] !== [])), 'lu' => $lu ? date('H:i', $lu) : null,
        'releve' => ['jours' => $jours, 'lus' => $lus, 'texte' => 'Fréquence et CA sur 12 mois : les tickets pro de la caisse (fiches avec une TVA valide), '
            . $lus . ' jours lus sur ' . $jours . '.']];
}

/** GET /dashboard/clients/fiche — un client du magasin : ses coordonnées, son profil, ses factures, ses 12 mois. */
function ep_clients_fiche(): array
{
    $shop = pclShop();
    $id = (int) ($_GET['id'] ?? 0);
    if ($shop <= 0 || $id <= 0) { http_response_code(400); return ['error' => 'shop et id attendus']; }
    $r = pclTf('GET', '/shops/' . $shop . '/clients/' . $id);
    if ($r['code'] === 404) { http_response_code(404); return ['error' => 'ce client n’est pas un client de ce magasin dans TF Buddy']; }
    if ($r['code'] < 200 || $r['code'] >= 300 || !is_array($r['corps'])) { http_response_code(502); return ['error' => 'fiche illisible : ' . pclMotif($r)]; }
    $c = is_array($r['corps']['client'] ?? null) ? $r['corps']['client'] : $r['corps'];
    $p = is_array($r['corps']['profile'] ?? null) ? $r['corps']['profile'] : null;
    $client = array_intersect_key(pclPropre($c), array_flip(PCL_CHAMPS_FICHE));
    $client['nom'] = pclNom($c);
    $client['connexion'] = substr(pclTexte($c['last_login_at'] ?? ''), 0, 10) ?: null;   // le compte en ligne (28/11/2026)
    $b2b = !empty($c['is_b2b']);
    $out = ['shop' => $shop, 'id' => $id, 'b2b' => $b2b, 'client' => $client, 'manques' => $b2b ? pclManques($c) : [],
        'profil' => $p ? ['score' => pclTexte($p['score'] ?? '') ?: null, 'type' => pclTexte($p['client_type'] ?? '') ?: null, 'mode' => pclTexte($p['service_mode'] ?? '') ?: null,
            'creditActif' => !empty($p['credit_enabled']), 'creditLimite' => pclNombre($p['credit_limit'] ?? null), 'statut' => pclTexte($p['status'] ?? '') ?: null,
            'depuis' => substr(pclTexte($p['joined_at'] ?? ''), 0, 10) ?: null] : null];
    // Les factures (le crédit utilisé = ce qui reste impayé), pour une entreprise.
    if ($b2b) {
        $f = pclTf('GET', '/shops/' . $shop . '/clients/' . $id . '/invoices');
        if ($f['code'] >= 200 && $f['code'] < 300) {
            $L = array_values(array_filter(pclItems($f['corps']), 'is_array'));
            usort($L, static fn ($a, $b) => strcmp(pclTexte($b['invoiced_date'] ?? ''), pclTexte($a['invoiced_date'] ?? '')));
            $imp = 0.0; $nImp = 0;
            foreach ($L as $x) { if (empty($x['was_paid'])) { $imp += (float) ($x['invoice_value_after_corrections'] ?? $x['invoice_value'] ?? 0); $nImp++; } }
            $out['factures'] = ['n' => count($L), 'impayees' => $nImp, 'impaye' => round($imp, 2), 'liste' => array_map(static fn ($x) => [
                'id' => (int) ($x['id'] ?? 0), 'date' => substr(pclTexte($x['invoiced_date'] ?? ''), 0, 10), 'echeance' => substr(pclTexte($x['due_date'] ?? ''), 0, 10),
                'montant' => round((float) ($x['invoice_value_after_corrections'] ?? $x['invoice_value'] ?? 0), 2), 'payee' => !empty($x['was_paid']),
                'differee' => !empty($x['deferral_invoice']), 'peppol' => pclTexte($x['peppol_status'] ?? '') ?: null], array_slice($L, 0, 12))];
            $out['credit'] = $p ? pclCredit(!empty($p['credit_enabled']), pclNombre($p['credit_limit'] ?? null), round($imp, 2)) : null;
        } else {
            $out['factures'] = null; $out['facturesMotif'] = pclMotif($f);
        }
        // Les commandes de l'entreprise : la route du panel, tentée (refusée tant que l'accès de service manque).
        $o = pclTf('GET', '/panel/shops/' . $shop . '/b2b/clients/' . $id . '/orders?limit=20');
        $out['commandes'] = $o['code'] >= 200 && $o['code'] < 300 ? array_map(static fn ($x) => [
            'id' => (int) ($x['id'] ?? 0), 'date' => substr(pclTexte($x['pick_up_datetime'] ?? $x['created_at'] ?? $x['issuing_timestamp'] ?? ''), 0, 10),
            'montant' => pclNombre($x['total_value'] ?? $x['total'] ?? null), 'statut' => pclTexte($x['order_status'] ?? $x['status'] ?? '') ?: null,
            'texte' => pclTexte($x['description'] ?? $x['comment'] ?? '')], array_slice(array_values(array_filter(pclItems($o['corps']), 'is_array')), 0, 20)) : null;
        if ($out['commandes'] === null) { $out['commandesMotif'] = $o['refus'] ? 'TF Buddy ne donne pas les commandes d’une entreprise aux comptes du cockpit.' : pclMotif($o); }
    }
    // Les 12 mois, depuis le relevé des tickets pro (une entreprise) : fréquence, CA, cycle d'achat.
    if ($b2b) {
        $auj = date('Y-m-d');
        $mois = [];
        foreach (pclDouzeMois($auj) as $m) { $x = pclMois($shop, $m, $auj); if ($x !== null) { $mois[$m] = $x; } }
        $out['releve'] = pclParClient($mois)[$id] ?? ['ca' => 0.0, 'n' => 0, 'premier' => null, 'dernier' => null, 'cycle' => null];
    }
    return $out;
}

/** GET /dashboard/clients/analyse — l'analyse B2B d'un mois : le tableau de bord de TF Buddy, sinon le relevé. */
function ep_clients_analyse(): array
{
    $shop = pclShop();
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $auj = date('Y-m-d');
    $annee = (int) ($_GET['annee'] ?? date('Y'));
    $mois = (int) ($_GET['mois'] ?? date('n'));
    if ($mois < 1 || $mois > 12 || $annee < 2020 || $annee > (int) date('Y')) { http_response_code(400); return ['error' => 'mois invalide']; }
    $cle = sprintf('%04d-%02d', $annee, $mois);
    if ($cle > substr($auj, 0, 7)) { http_response_code(400); return ['error' => 'ce mois n’est pas commencé']; }
    $r = pclTf('GET', '/panel/shops/' . $shop . '/b2b/dashboard?year=' . $annee . '&month=' . $mois);
    if ($r['code'] >= 200 && $r['code'] < 300 && is_array($r['corps']) && is_array($r['corps']['kpi'] ?? null)) {
        $ch = [];
        foreach (array_values((array) ($r['corps']['chart']['months'] ?? $r['corps']['chart'] ?? [])) as $i => $x) {
            if (!is_array($x)) { continue; }
            $ch[] = ['mois' => (int) ($x['month'] ?? $i + 1), 'ventes' => [pclNombre($x['curr_sales'] ?? null), pclNombre($x['prev_sales'] ?? null)],
                'tickets' => [pclNombre($x['curr_txn'] ?? null), pclNombre($x['prev_txn'] ?? null)], 'clients' => [pclNombre($x['curr_uniq'] ?? null), pclNombre($x['prev_uniq'] ?? null)],
                'part' => [pclNombre($x['curr_share'] ?? null), pclNombre($x['prev_share'] ?? null)]];
        }
        return ['shop' => $shop, 'source' => 'panel', 'annee' => $annee, 'mois' => $mois, 'kpi' => pclKpisPanel($r['corps']['kpi']), 'graphique' => $ch];
    }
    $refus = pclRefusDit($r, 'le tableau de bord B2B');
    if (!function_exists('vpLu')) { return ['shop' => $shop, 'source' => null, 'annee' => $annee, 'mois' => $mois, 'panel' => $refus, 'kpi' => null, 'graphique' => []]; }
    $lit = static fn (int $a, int $m, ?int $j = null) => pclMois($shop, sprintf('%04d-%02d', $a, $m), $auj, $j);
    // Un mois en cours se compare aux mêmes jours du mois d'avant et de l'an dernier (du 1er au jour d'aujourd'hui).
    $enCours = $cle === substr($auj, 0, 7) ? (int) substr($auj, 8, 2) : null;
    $cur = $lit($annee, $mois);
    $avant = $mois === 1 ? $lit($annee - 1, 12, $enCours) : $lit($annee, $mois - 1, $enCours);
    $an = $lit($annee - 1, $mois, $enCours);
    $ch = [];
    for ($m = 1; $m <= 12; $m++) {
        $a = pclChiffresMois($lit($annee, $m)); $b = pclChiffresMois($lit($annee - 1, $m));
        $ch[] = ['mois' => $m, 'ventes' => [$a['ventes'] ?? null, $b['ventes'] ?? null], 'tickets' => [$a['tickets'] ?? null, $b['tickets'] ?? null],
            'clients' => [$a['clients'] ?? null, $b['clients'] ?? null], 'part' => [$a['part'] ?? null, $b['part'] ?? null]];
    }
    $c = pclChiffresMois($cur);
    return ['shop' => $shop, 'source' => 'releve', 'annee' => $annee, 'mois' => $mois, 'panel' => $refus,
        'kpi' => pclKpis($c, pclChiffresMois($avant), pclChiffresMois($an)), 'graphique' => $ch,
        'ca' => $c['ca'] ?? null, 'memesJours' => $enCours, 'couverture' => $cur ? ['lus' => $cur['lus'], 'jours' => $cur['jours']] : null,
        'texte' => 'Lu dans les tickets de la caisse : un ticket pro est celui d’une fiche avec une TVA valide (la jauge B2B de l’Opérationnel), montant après la remise personnelle des paiements différés.'];
}

/** GET /dashboard/clients/bureaux — relais de lecture des bureaux du magasin (liste blanche, champs secrets retirés). */
function ep_clients_bureaux(): array
{
    $shop = pclShop();
    $ch = pclTexte($_GET['chemin'] ?? '');
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!pclCheminBureau('GET', $ch)) { http_response_code(400); return ['error' => 'chemin des bureaux non permis']; }
    $r = pclTf('GET', '/panel/shops/' . $shop . '/offices' . $ch . pclParams($_GET));
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $d = pclPropre($r['corps']);
        if ($ch === '') { $d = ['bureaux' => array_map('pclBureau', array_values(array_filter(pclItems($d), 'is_array')))]; }
        elseif (is_array($d) && isset($d['data']) && is_array($d['data']) && ($d['success'] ?? null) !== null) { $d = $d['data']; }
        return ['ok' => true, 'compte' => $r['compte'], 'data' => $d];
    }
    if ($r['refus']) { return pclRefusDit($r, 'les bureaux du magasin'); }
    return ['ok' => false, 'refus' => false, 'code' => $r['code'], 'motif' => pclMotif($r)];
}

/* ── Les écritures ───────────────────────────────────────────────────────────────────────────────────────────── */

/** Le client `id` est-il un client du magasin ? [fiche (client + profil), null] ou [null, [code, erreur]]. */
function pclMien(int $shop, int $id): array
{
    if ($id <= 0) { return [null, [400, 'client manquant']]; }
    $r = pclTf('GET', '/shops/' . $shop . '/clients/' . $id);
    if ($r['code'] === 404) { return [null, [404, 'ce client n’est pas un client de ce magasin dans TF Buddy']]; }
    if ($r['code'] < 200 || $r['code'] >= 300 || !is_array($r['corps'])) { return [null, [502, 'fiche illisible : ' . pclMotif($r)]]; }
    $c = is_array($r['corps']['client'] ?? null) ? $r['corps']['client'] : $r['corps'];
    return [['client' => $c, 'profil' => is_array($r['corps']['profile'] ?? null) ? $r['corps']['profile'] : null], null];
}

/** Les coordonnées envoyées, réduites à ce qu'un magasin peut écrire ('' → null, sauf les champs obligatoires). */
function pclCoordonnees(array $d, array $permis = PCL_CHAMPS_COORD): array
{
    $out = [];
    foreach ($permis as $k) {
        if (!array_key_exists($k, $d)) { continue; }
        $v = is_scalar($d[$k]) || $d[$k] === null ? trim((string) $d[$k]) : '';
        $out[$k] = $v === '' ? null : mb_substr($v, 0, 255);
    }
    if (isset($out['email']) && $out['email'] !== null && !filter_var($out['email'], FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('e-mail invalide'); }
    return $out;
}

/** Le profil commercial envoyé (PATCH /shops/{s}/clients/{id}), validé. */
function pclProfil(array $d): array
{
    $out = [];
    if (array_key_exists('score', $d)) { $s = strtoupper(pclTexte($d['score'])); if ($s !== '' && !in_array($s, PCL_SCORES, true)) { throw new InvalidArgumentException('score A, B, C ou D'); } $out['score'] = $s ?: null; }
    if (array_key_exists('type', $d)) { $t = pclTexte($d['type']); if ($t !== '' && !in_array($t, PCL_TYPES_CLIENT, true)) { throw new InvalidArgumentException('type de client inconnu'); } $out['client_type'] = $t ?: null; }
    if (array_key_exists('mode', $d)) { $m = pclTexte($d['mode']); if ($m !== '' && !in_array($m, PCL_MODES, true)) { throw new InvalidArgumentException('mode de service inconnu'); } $out['service_mode'] = $m ?: null; }
    if (array_key_exists('creditActif', $d)) { $out['credit_enabled'] = (bool) $d['creditActif']; }
    if (array_key_exists('creditLimite', $d)) {
        $l = pclNombre($d['creditLimite']);
        if ($d['creditLimite'] !== null && $d['creditLimite'] !== '' && ($l === null || $l < 0 || $l > 1_000_000)) { throw new InvalidArgumentException('limite de crédit invalide'); }
        $out['credit_limit'] = $l;
    }
    return $out;
}

/** Une écriture refusée par TF Buddy, dite au magasin. */
function pclEchec(array $r, string $quoi): array
{
    http_response_code($r['refus'] ? 403 : (($r['code'] >= 400 && $r['code'] < 500) ? 422 : 502));
    return ['error' => 'TF Buddy refuse ' . $quoi . ' (' . pclMotif($r) . ').', 'refus' => $r['refus']];
}

/** POST /dashboard/clients/client {shop, action, id?, champs} — créer, modifier, profil, passer en entreprise, supprimer. */
function wr_clients_client(): array
{
    $b = body();
    $shop = pclShop($b);
    $action = pclTexte($b['action'] ?? '');
    $id = (int) ($b['id'] ?? 0);
    $ch = is_array($b['champs'] ?? null) ? $b['champs'] : [];
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    try {
        if ($action === 'creer') {
            $ent = !empty($b['entreprise']);
            if ($ent) {
                $tva = strtoupper(preg_replace('/[\s.\-]+/', '', pclTexte($ch['tax_number'] ?? $ch['vat_number'] ?? '')));
                if (!preg_match('/^[A-Z]{2}[0-9A-Z]{6,14}$/', $tva)) { http_response_code(422); return ['error' => 'un numéro de TVA (BE0123456789) est attendu']; }
                $corps = ['vat_number' => $tva] + array_filter(pclCoordonnees($ch, ['company_name', 'street', 'street_number', 'city', 'zip', 'phone', 'email', 'comment']), static fn ($v) => $v !== null);
            } else {
                $corps = array_filter(pclCoordonnees($ch, ['name', 'surname', 'phone', 'email', 'street', 'street_number', 'zip', 'city', 'comment']), static fn ($v) => $v !== null);
                if (empty($corps['surname']) || empty($corps['phone'])) { http_response_code(422); return ['error' => 'le nom et le téléphone sont obligatoires'];  }
            }
            $r = pclTf('POST', '/shops/' . $shop . '/clients', $corps);
            if ($r['code'] < 200 || $r['code'] >= 300) { return pclEchec($r, 'la création du client'); }
            $d = is_array($r['corps']['data'] ?? null) ? $r['corps']['data'] : (is_array($r['corps']) ? $r['corps'] : []);
            pclOublier($shop);
            if (function_exists('journalAdd')) { journalAdd('dashboard', 'clients', null, 'Magasin ' . $shop . ' : client ' . ($ent ? 'entreprise' : 'particulier') . ' n° ' . (int) ($d['id'] ?? 0) . ' créé dans TF Buddy'); }
            return ['ok' => true, 'id' => (int) ($d['id'] ?? 0), 'cree' => !empty($r['corps']['client_created']), 'rattache' => !empty($r['corps']['shop_assignment_created']), 'compte' => $r['compte']];
        }
        [$f, $err] = pclMien($shop, $id);
        if ($err !== null) { http_response_code($err[0]); return ['error' => $err[1]]; }
        if ($action === 'modifier') {
            $corps = pclCoordonnees($ch);
            if (!$corps) { http_response_code(422); return ['error' => 'rien à modifier']; }
            $r = pclTf('PATCH', '/clients/' . $id, $corps);
            if ($r['code'] < 200 || $r['code'] >= 300) { return pclEchec($r, 'la modification'); }
        } elseif ($action === 'profil') {
            if (empty($f['client']['is_b2b'])) { http_response_code(422); return ['error' => 'le profil commercial est celui d’une entreprise']; }
            $corps = pclProfil($ch);
            if (!$corps) { http_response_code(422); return ['error' => 'rien à modifier']; }
            $r = pclTf('PATCH', '/shops/' . $shop . '/clients/' . $id, $corps);
            if ($r['code'] < 200 || $r['code'] >= 300) { return pclEchec($r, 'le profil commercial'); }
        } elseif ($action === 'entreprise') {
            if (!empty($f['client']['is_b2b'])) { http_response_code(409); return ['error' => 'ce client est déjà une entreprise']; }
            $tva = strtoupper(preg_replace('/[\s.\-]+/', '', pclTexte($ch['tax_number'] ?? '')));
            $soc = pclTexte($ch['company_name'] ?? '');
            if (!preg_match('/^[A-Z]{2}[0-9A-Z]{6,14}$/', $tva) || $soc === '') { http_response_code(422); return ['error' => 'la société et son numéro de TVA sont attendus']; }
            // Le geste du panel (B2CClientService::convertToB2B) : la fiche devient B2B, le nom de la personne s'efface.
            $corps = ['is_b2b' => 1, 'name' => null, 'surname' => null, 'tax_number' => $tva, 'company_name' => $soc]
                + array_filter(pclCoordonnees($ch, ['street', 'street_number', 'city', 'zip', 'email', 'phone']), static fn ($v) => $v !== null);
            if (isset($ch['differe'])) { $corps['can_deferral'] = !empty($ch['differe']) ? 1 : 0; }
            if (isset($ch['delai']) && pclNombre($ch['delai']) !== null) { $corps['payment_terms'] = (int) $ch['delai']; }
            if (isset($ch['remise']) && pclNombre($ch['remise']) !== null) { $corps['personal_discount_percent'] = max(0.0, min(100.0, (float) $ch['remise'])); }
            $r = pclTf('PATCH', '/clients/' . $id, $corps);
            if ($r['code'] < 200 || $r['code'] >= 300) { return pclEchec($r, 'le passage en entreprise'); }
        } elseif ($action === 'supprimer') {
            if ((int) ($f['client']['id_main_shop'] ?? 0) !== $shop) { http_response_code(409); return ['error' => 'ce client a été créé par un autre magasin : il ne s’efface pas d’ici']; }
            $r = pclTf('DELETE', '/clients/' . $id);
            if ($r['code'] < 200 || $r['code'] >= 300) { return pclEchec($r, 'la suppression'); }
        } else {
            http_response_code(400); return ['error' => 'action inconnue'];
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(422); return ['error' => $e->getMessage()];
    }
    pclOublier($shop);
    if (function_exists('journalAdd')) { journalAdd('dashboard', 'clients', null, 'Magasin ' . $shop . ' : client n° ' . $id . ' — ' . $action . ' dans TF Buddy'); }
    return ['ok' => true, 'id' => $id, 'action' => $action, 'compte' => $r['compte'] ?? null];
}

/** POST /dashboard/clients/tva {shop, tva} — la TVA vue par TF Buddy (client existant, rattaché ?), sinon par VIES. */
function wr_clients_tva(): array
{
    $b = body();
    $shop = pclShop($b);
    $tva = strtoupper(preg_replace('/[\s.\-]+/', '', pclTexte($b['tva'] ?? '')));
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!preg_match('/^[A-Z]{2}[0-9A-Z]{6,14}$/', $tva)) { http_response_code(422); return ['error' => 'un numéro de TVA (BE0123456789) est attendu']; }
    $r = pclTf('POST', '/shops/' . $shop . '/clients/vat-lookup', ['vat_number' => $tva]);
    if (($r['code'] >= 200 && $r['code'] < 300 || $r['code'] === 404) && is_array($r['corps']) && array_key_exists('found', $r['corps'])) {
        $d = is_array($r['corps']['data'] ?? null) ? pclPropre($r['corps']['data']) : [];
        return ['ok' => true, 'tva' => $tva, 'trouve' => !empty($r['corps']['found']), 'source' => pclTexte($r['corps']['source'] ?? ''),
            'existe' => !empty($r['corps']['client_exists']), 'rattache' => !empty($r['corps']['assigned_to_shop']),
            'societe' => array_intersect_key($d, array_flip(['id', 'company_name', 'name', 'tax_number', 'street', 'street_number', 'city', 'zip', 'phone', 'email']))];
    }
    $v = pclTf('POST', '/vies/lookup', ['vat_number' => $tva]);
    if (($v['code'] >= 200 && $v['code'] < 300 || $v['code'] === 404) && is_array($v['corps'])) {
        $d = is_array($v['corps']['data'] ?? null) ? pclPropre($v['corps']['data']) : [];
        return ['ok' => true, 'tva' => $tva, 'trouve' => !empty($v['corps']['valid']), 'source' => 'vies', 'existe' => false, 'rattache' => false,
            'societe' => ['company_name' => $d['name'] ?? null] + array_intersect_key($d, array_flip(['tax_number', 'street', 'street_number', 'city', 'zip', 'phone', 'email']))];
    }
    http_response_code(502);
    return ['error' => 'la TVA ne se vérifie pas (' . pclMotif($r) . ' ; VIES : ' . pclMotif($v) . ')'];
}

/** POST /dashboard/clients/bureaux {shop, methode, chemin, corps} — écriture des bureaux (liste blanche). */
function wr_clients_bureaux(): array
{
    $b = body();
    $shop = pclShop($b);
    $m = strtoupper(pclTexte($b['methode'] ?? 'POST'));
    $ch = pclTexte($b['chemin'] ?? '');
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || !pclCheminBureau($m, $ch)) { http_response_code(400); return ['error' => 'écriture des bureaux non permise'];  }
    $corps = is_array($b['corps'] ?? null) ? $b['corps'] : ($m === 'DELETE' ? null : []);
    $r = pclTf($m, '/panel/shops/' . $shop . '/offices' . $ch, $corps);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        if (function_exists('journalAdd')) { journalAdd('dashboard', 'clients', null, 'Magasin ' . $shop . ' : bureaux ' . $m . ' ' . $ch . ' dans TF Buddy'); }
        $d = pclPropre($r['corps']);
        if (is_array($d) && isset($d['data']) && is_array($d['data']) && ($d['success'] ?? null) !== null) { $d = $d['data']; }
        return ['ok' => true, 'data' => $d, 'compte' => $r['compte']];
    }
    if ($r['refus']) { http_response_code(403); return pclRefusDit($r, 'l’écriture des bureaux') + ['error' => 'TF Buddy refuse l’écriture des bureaux aux comptes du cockpit.']; }
    return pclEchec($r, 'cette modification du bureau');
}

/**
 * POST /dashboard/clients/bureaux/pdf {shop, bureau, type: poster|cards|brochure, corps} — le PDF que TF Buddy rend
 * (OfficeDocumentService : affiche {offer_id} ; cartes {mode, product_id, valid_to, cards, request_key} ; brochure
 * {catalog, offer_ids}), tel quel. Sort le PDF, ou du JSON en cas de refus, puis exit.
 */
function ep_clients_bureaux_pdf(): void
{
    $json = static function (int $code, array $d): void {
        http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit;
    };
    $b = body();
    $shop = pclShop($b);
    $id = (int) ($b['bureau'] ?? 0);
    $type = pclTexte($b['type'] ?? '');
    $cles = ['poster' => ['offer_id'], 'cards' => ['mode', 'product_id', 'valid_to', 'cards', 'request_key'], 'brochure' => ['catalog', 'offer_ids']][$type] ?? null;
    if ($shop <= 0 || $id <= 0 || $cles === null) { $json(400, ['error' => 'shop, bureau et type (poster, cards, brochure) attendus']); }
    $corps = array_intersect_key(is_array($b['corps'] ?? null) ? $b['corps'] : [], array_flip($cles));
    $chemin = '/panel/shops/' . $shop . '/offices/' . $id . '/' . $type;
    $dernier = [0, ''];
    // Le jeton du magasin d'abord (tfb_magasin.php), puis le consultant, puis l'admin.
    $jm = function_exists('tfbmJeton') ? tfbmJeton() : null;
    foreach ([['magasin', null], ['PanelApi', 'configured'], ['ErpApi', 'disponible']] as [$cl, $ok]) {
        if ($cl === 'magasin') { if ($jm === null || !class_exists('PanelApi')) { continue; } $tok = $jm; $base = PanelApi::config()['base']; }
        else { if (!class_exists($cl) || !$cl::$ok()) { continue; } $tok = $cl::jetonSonde(); $base = $cl::config()['base']; }
        if ($tok === null) { continue; }
        $ch = curl_init($base . $chemin);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_POSTFIELDS => json_encode((object) $corps, JSON_UNESCAPED_UNICODE), CURLOPT_MAXFILESIZE => 30_000_000,
            CURLOPT_HTTPHEADER => ['Accept: application/pdf, application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $tok]]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $raw = $raw === false ? '' : (string) $raw;
        if ($code >= 200 && $code < 300 && str_starts_with($raw, '%PDF-')) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="bureau-' . $id . '-' . $type . '.pdf"');
            header('Cache-Control: private, no-store');
            echo $raw; exit;
        }
        $dernier = [$code, $raw];
        if (!pclRefus($code, json_decode($raw, true))) { break; }
    }
    $j = json_decode($dernier[1], true);
    $r = ['code' => $dernier[0], 'corps' => is_array($j) ? $j : null, 'erreur' => $dernier[0] === 0 && $dernier[1] === '' ? 'aucun jeton TF Buddy au cockpit' : null];
    $json($dernier[0] === 0 || pclRefus($dernier[0], $j) ? 403 : 502, ['error' => 'TF Buddy ne rend pas ce document (' . pclMotif($r) . ').']);
}

/* ── Le routeur ──────────────────────────────────────────────────────────────────────────────────────────────── */

function pclRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/clients' && !str_starts_with($path, '/dashboard/clients/')) { return null; }
    if ($method === 'GET') {
        switch ($path) {
            case '/dashboard/clients/particuliers': return ep_clients_particuliers();
            case '/dashboard/clients/entreprises': return ep_clients_entreprises();
            case '/dashboard/clients/fiche': return ep_clients_fiche();
            case '/dashboard/clients/analyse': return ep_clients_analyse();
            case '/dashboard/clients/bureaux': return ep_clients_bureaux();
        }
    }
    if ($method === 'POST') {
        switch ($path) {
            case '/dashboard/clients/client': return wr_clients_client();
            case '/dashboard/clients/tva': return wr_clients_tva();
            case '/dashboard/clients/bureaux': return wr_clients_bureaux();
        }
    }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
