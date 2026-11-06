<?php
declare(strict_types=1);

/**
 * Le bon d'une promotion du magasin (24/10/2026, Sam : « dans le module voucher il faut pouvoir créer un voucher en
 * assistant et directement enchaîner avec la promotion qui sera liée … aller chercher ses clients … dans le dashboard
 * franchisé »).
 *
 * Au panel, un bon = une CAMPAGNE (/admin/voucher-campaigns) rattachée à UNE promotion en mode VOUCHER_ONLY : la
 * promotion ne s'applique en caisse qu'avec un des codes de la campagne. Le cockpit n'en garde rien (« rien en local ») :
 * la campagne, ses codes et leurs clients vivent au panel, relus à la demande.
 *
 * Trois façons de distribuer le bon (`cible`) :
 *  - « code »    : un code pour tous (campagne SHARED, `shared_code`), saisi ou généré, un nombre d'utilisations au plus ;
 *  - « uniques » : N codes d'une utilisation chacun (campagne UNIQUE, codes/generate) ;
 *  - « clients » : un code personnel par client du magasin (campagne UNIQUE, requires_customer, un code par client avec
 *                  son id_customer, une utilisation) — les clients se lisent au panel (GET /shops/{id}/clients), à
 *                  choisir parmi les comptes B2B du magasin ou ses particuliers (29/10/2026, `?type=b2b|b2c`).
 *
 * L'ordre d'écriture, appris par l'essai du 24/10/2026 (dépôt WebShop, VOUCHERS_ERP.md §2) :
 *   1. la promotion écrite INACTIVE et relue (pmPanelEnvoyer sans activer) ;
 *   2. la campagne créée en brouillon (DRAFT) avec ses codes, puis rattachée à la promotion ;
 *   3. à l'envoi seulement : la promotion activée, puis la campagne (PATCH /{id} : PATCH /status refuse une campagne
 *      UNIQUE, « shared_code is not supported for UNIQUE campaigns »), relue complète (configuration_complete).
 * Un refus à l'étape 2 : la promotion reste inactive (un brouillon), rien ne passe en caisse. Une campagne n'a pas de
 * début (le panel compare les débuts en UTC, une heure locale « pas encore commencée » bloquait l'essai) : elle vit avec
 * sa promotion, et finit avec elle. Le panel ne supprime pas une campagne : un bon retiré passe INACTIVE et se détache.
 */

const PB_CIBLES = ['code', 'uniques', 'clients'];
const PB_MAX_CODES = 500;
const PB_MAX_CLIENTS = 100;
const PB_LOT_CODES = 100;
/** Les lettres et chiffres d'un code généré : sans 0/O ni 1/I, qui se confondent sur un ticket. */
const PB_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/** Un code saisi : 4 à 20 lettres, chiffres ou tirets, en majuscules ; null sinon. */
function pbCode($v): ?string
{
    $v = strtoupper(trim((string) $v));
    return preg_match('/^[A-Z0-9][A-Z0-9-]{3,19}$/', $v) === 1 ? $v : null;
}

/** Un code au hasard, lisible. */
function pbGenerer(int $n = 8, string $prefixe = ''): string
{
    $c = '';
    for ($i = 0; $i < $n; $i++) { $c .= PB_ALPHABET[random_int(0, strlen(PB_ALPHABET) - 1)]; }
    return $prefixe . $c;
}

/**
 * Le bon tel que le navigateur l'envoie, rendu propre — null quand la promotion est automatique (sans bon). Le numéro
 * de la campagne n'est jamais pris du navigateur : il se relit au panel sur la promotion (pbCampagneDe).
 */
function pbBon($b): ?array
{
    if (!is_array($b) || empty($b['on'])) { return null; }
    $cible = in_array($b['cible'] ?? '', PB_CIBLES, true) ? (string) $b['cible'] : 'code';
    $clients = [];
    foreach ((array) ($b['clients'] ?? []) as $c) {
        $id = (int) (is_array($c) ? ($c['id'] ?? 0) : $c);
        if ($id > 0 && !isset($clients[$id])) { $clients[$id] = ['id' => $id, 'nom' => mb_substr(trim((string) (is_array($c) ? ($c['nom'] ?? '') : '')), 0, 80)]; }
    }
    $saisi = trim((string) ($b['code'] ?? ''));
    $prefixe = strtoupper(trim((string) ($b['prefixe'] ?? '')));
    $limite = $b['limite'] ?? null;
    return [
        'cible' => $cible,
        'code' => $cible === 'code' ? pbCode($saisi) : null,
        'codeSaisi' => $cible === 'code' ? $saisi : '',
        'nombre' => $cible === 'uniques' ? max(0, min(PB_MAX_CODES, (int) ($b['nombre'] ?? 0))) : 0,
        'prefixe' => $cible === 'uniques' && preg_match('/^[A-Z0-9-]{0,8}$/', $prefixe) === 1 ? $prefixe : '',
        'clients' => $cible === 'clients' ? array_values($clients) : [],
        'limite' => $cible === 'code' && is_numeric($limite) && (int) $limite > 0 ? min(100000, (int) $limite) : null,
    ];
}

/** Ce que le bon demande encore : une phrase par manque (vide = il s'écrit). */
function pbErreurs(?array $bon): array
{
    if ($bon === null) { return []; }
    $e = [];
    if ($bon['cible'] === 'code' && $bon['codeSaisi'] !== '' && $bon['code'] === null) { $e[] = 'Le code du bon : 4 à 20 lettres, chiffres ou tirets (vide : le cockpit en crée un).'; }
    if ($bon['cible'] === 'uniques' && $bon['nombre'] < 1) { $e[] = 'Le nombre de codes du bon : de 1 à ' . PB_MAX_CODES . '.'; }
    if ($bon['cible'] === 'clients' && $bon['clients'] === []) { $e[] = 'Choisissez au moins un client du magasin pour le bon.'; }
    if ($bon['cible'] === 'clients' && count($bon['clients']) > PB_MAX_CLIENTS) { $e[] = 'Le bon vise ' . PB_MAX_CLIENTS . ' clients au plus en une fois.'; }
    return $e;
}

/** Les éléments d'une réponse paginée du panel ({items}, {data: {items}}, {data: […]}, ou une liste). */
function pbItems($corps): array
{
    if (!is_array($corps)) { return []; }
    foreach ([$corps['items'] ?? null, $corps['data']['items'] ?? null, $corps['data'] ?? null, $corps] as $n) {
        if (is_array($n) && array_is_list($n)) { return $n; }
    }
    return [];
}

/** Le premier numéro rendu par une création : à la racine, dans `data`, dans `data.campaign`, dans `campaign`. */
function pbId($corps): ?int
{
    if (!is_array($corps)) { return null; }
    foreach ([$corps['data']['campaign'] ?? null, $corps['campaign'] ?? null, $corps['data'] ?? null, $corps] as $n) {
        if (is_array($n) && isset($n['id']) && is_numeric($n['id']) && (int) $n['id'] > 0) { return (int) $n['id']; }
    }
    return null;
}

/**
 * La campagne rattachée à une promotion du panel : celle que la fiche nomme (`voucher_campaign_id`, `voucher_campaign`),
 * sinon celle que la liste des campagnes rend pour cette promotion (`?id_promotion=`). Null : pas de bon.
 */
function pbCampagneDe(int $promoId, ?array $fiche = null): ?int
{
    foreach ([$fiche['voucher_campaign_id'] ?? null, $fiche['voucher_campaign']['id'] ?? null] as $v) {
        if (is_numeric($v) && (int) $v > 0) { return (int) $v; }
    }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return null; }
    $l = ErpApi::sondeGet('/admin/voucher-campaigns?limit=10&id_promotion=' . $promoId);
    foreach (pbItems($l['corps']) as $c) {
        if (is_array($c) && (int) ($c['id_promotion'] ?? 0) === $promoId && (int) ($c['id'] ?? 0) > 0) { return (int) $c['id']; }
    }
    return null;
}

/** La fiche d'une campagne : {campaign, promotion, shared_code, code_stats, …} (la forme relue le 24/10/2026). */
function pbFiche(int $id): ?array
{
    $r = ErpApi::sondeGet('/admin/voucher-campaigns/' . $id);
    if ($r['code'] < 200 || $r['code'] >= 300 || !is_array($r['corps'])) { return null; }
    $c = $r['corps'];
    return is_array($c['data'] ?? null) && isset($c['data']['campaign']) ? $c['data'] : $c;
}

/** Les codes d'une campagne, toutes pages (200 par page, 600 au plus). */
function pbCodes(int $id): array
{
    $out = [];
    for ($page = 1; $page <= 3; $page++) {
        $r = ErpApi::sondeGet('/admin/voucher-campaigns/' . $id . '/codes?limit=200&page=' . $page);
        $l = pbItems($r['corps']);
        foreach ($l as $k) { if (is_array($k)) { $out[] = $k; } }
        $pg = is_array($r['corps']) ? ($r['corps']['pagination'] ?? ($r['corps']['data']['pagination'] ?? null)) : null;
        if (count($l) < 200 || !is_array($pg) || $page >= (int) ($pg['pages'] ?? 1)) { break; }
    }
    return $out;
}

/** La cible d'une campagne relue : un code pour tous (SHARED), des clients (UNIQUE avec client requis), des codes. */
function pbCibleDe(array $camp): string
{
    if (strtoupper((string) ($camp['code_type'] ?? '')) === 'SHARED') { return 'code'; }
    return !empty($camp['requires_customer']) ? 'clients' : 'uniques';
}

/**
 * Le bon d'une promotion, relu au panel, pour l'assistant et la liste : {campagne, nom, statut, actif, cible, code,
 * limite, codes: [{id, code, statut, client, nomClient, usages, limite}], nCodes, nActifs, utilisations, complete,
 * manque}. `$clients` : {id client → client du magasin (pbClientCourt)}, pour nommer les codes des clients et dire
 * lesquels sont B2B. Null : la campagne ne se lit pas.
 */
function pbLire(int $campId, array $clients = []): ?array
{
    $f = pbFiche($campId);
    if ($f === null) { return null; }
    $c = is_array($f['campaign'] ?? null) ? $f['campaign'] : $f;
    $cible = pbCibleDe($c);
    $codes = [];
    if ($cible !== 'code') {
        foreach (pbCodes($campId) as $k) {
            $cl = (int) ($k['id_customer'] ?? 0);
            $codes[] = ['id' => (int) ($k['id'] ?? 0), 'code' => (string) ($k['code'] ?? ''), 'statut' => strtoupper((string) ($k['status'] ?? '')),
                'client' => $cl > 0 ? $cl : null, 'nomClient' => $cl > 0 ? ($clients[$cl]['nom'] ?? null) : null,
                'b2bClient' => $cl > 0 && isset($clients[$cl]) ? (bool) $clients[$cl]['b2b'] : null,
                'usages' => (int) ($k['usage_count'] ?? 0), 'limite' => isset($k['usage_limit']) ? (int) $k['usage_limit'] : null];
        }
    }
    $statut = strtoupper((string) ($c['status'] ?? ''));
    return ['campagne' => $campId, 'promotion' => (int) ($c['id_promotion'] ?? 0) ?: null,
        'nom' => (string) ($c['name'] ?? ''), 'statut' => $statut, 'actif' => $statut === 'ACTIVE', 'cible' => $cible,
        'code' => $cible === 'code' ? (string) ($f['shared_code'] ?? $c['shared_code'] ?? '') : null,
        'limite' => isset($c['usage_limit_total']) ? (int) $c['usage_limit_total'] : null,
        'codes' => $codes, 'nCodes' => $cible === 'code' ? 1 : count($codes),
        'nActifs' => $cible === 'code' ? 1 : count(array_filter($codes, static fn ($k) => $k['statut'] === 'ACTIVE')),
        'utilisations' => (int) ($f['redemptions_total'] ?? 0), 'complete' => (bool) ($f['configuration_complete'] ?? false),
        'manque' => array_values(array_map('strval', (array) ($f['missing_requirements'] ?? [])))];
}

/** Le corps d'une campagne : celui de la promotion pour le nom et la fin, celui du bon pour la distribution. */
function pbCorpsCampagne(array $d, array $bon, ?string $statut): array
{
    $code = $bon['cible'] === 'code';
    $corps = [
        'name' => $d['nom'], 'description' => 'Bon de la promotion « ' . $d['nom'] . ' » · ' . pmNomShop((int) $d['shop']) . ' · dashboard magasin',
        'planned_promotion_type' => 'BUY_X_GET_Y', 'code_type' => $code ? 'SHARED' : 'UNIQUE',
        'valid_from' => null, 'valid_to' => $d['au'] !== null ? $d['au'] . ':00' : null,
        'usage_limit_total' => $code ? $bon['limite'] : null,
        'usage_limit_per_code' => $code ? null : 1,
        'usage_limit_per_customer' => $bon['cible'] === 'clients' ? 1 : null,
        'requires_customer' => $bon['cible'] === 'clients',
        'shared_code' => $code ? $bon['code'] : null,
    ];
    return $statut === null ? $corps : $corps + ['status' => $statut];
}

/** Les codes à créer : N codes générés (par lots), ou un code par client nouveau. Rend la première erreur, ou null. */
function pbAjouterCodes(int $campId, array $d, array $bon, array $deja): ?string
{
    $fin = $d['au'] !== null ? $d['au'] . ':00' : null;
    if ($bon['cible'] === 'uniques') {
        $reste = $bon['nombre'] - count($deja);
        while ($reste > 0) {
            $n = min(PB_LOT_CODES, $reste);
            $w = ErpApi::ecrire('POST', '/admin/voucher-campaigns/' . $campId . '/codes/generate', ['count' => $n, 'length' => 8, 'prefix' => $bon['prefixe']]);
            if ($w['erreur'] !== null) { return 'codes non générés : ' . $w['erreur']; }
            $reste -= $n;
        }
        return null;
    }
    if ($bon['cible'] === 'clients') {
        $ont = [];
        foreach ($deja as $k) { if ($k['client'] !== null && $k['statut'] === 'ACTIVE') { $ont[$k['client']] = true; } }
        foreach ($bon['clients'] as $cl) {
            if (isset($ont[$cl['id']])) { continue; }
            // Un code déjà pris au panel (le hasard) : un autre, deux fois au plus.
            for ($essai = 0; $essai < 3; $essai++) {
                $w = ErpApi::ecrire('POST', '/admin/voucher-campaigns/' . $campId . '/codes',
                    ['code' => pbGenerer(8), 'status' => 'ACTIVE', 'id_customer' => $cl['id'], 'valid_from' => null, 'valid_to' => $fin, 'usage_limit' => 1]);
                if ($w['erreur'] === null || (int) $w['code'] !== 422 || stripos((string) $w['erreur'], 'code') === false) { break; }
            }
            if ($w['erreur'] !== null) { return 'code de ' . ($cl['nom'] !== '' ? $cl['nom'] : 'client n° ' . $cl['id']) . ' non créé : ' . $w['erreur']; }
        }
        // Un client retiré du bon : son code se désactive (le panel ne supprime pas un code).
        $voulus = array_flip(array_column($bon['clients'], 'id'));
        foreach ($deja as $k) {
            if ($k['client'] !== null && $k['statut'] === 'ACTIVE' && !isset($voulus[$k['client']])) {
                $w = ErpApi::ecrire('PATCH', '/admin/voucher-codes/' . $k['id'] . '/status', ['status' => 'DISABLED']);
                if ($w['erreur'] !== null) { return 'code d’un client retiré non désactivé : ' . $w['erreur']; }
            }
        }
    }
    return null;
}

/**
 * Le bon posé sur la promotion n° $promoId, sans rien activer : la campagne créée (DRAFT) ou mise à jour, ses codes,
 * le rattachement. Une distribution qui change (un code pour tous, des codes, des clients) fait un nouveau bon :
 * l'ancien passe INACTIVE et se détache. Rend [numéro de campagne, erreur|null].
 */
function pbPoser(array $d, array $bon, int $promoId, ?int $campId): array
{
    $actuel = $campId !== null ? pbLire($campId) : null;
    if ($actuel !== null && $actuel['cible'] !== $bon['cible']) {
        pbRetirer($campId);
        $campId = null; $actuel = null;
    }
    // Un code pour tous laissé vide : celui du bon actuel, sinon un nouveau.
    if ($bon['cible'] === 'code' && $bon['code'] === null) { $bon['code'] = ($actuel['code'] ?? '') !== '' ? $actuel['code'] : pbGenerer(8); }
    if ($campId === null) {
        $w = ErpApi::ecrire('POST', '/admin/voucher-campaigns', pbCorpsCampagne($d, $bon, 'DRAFT'));
        if ($w['erreur'] !== null) { return [null, 'le panel refuse le bon : ' . $w['erreur']]; }
        $campId = pbId($w['corps']);
        if ($campId === null) { return [null, 'le panel n’a pas rendu le numéro du bon créé']; }
        $deja = [];
    } else {
        $w = ErpApi::ecrire('PATCH', '/admin/voucher-campaigns/' . $campId, pbCorpsCampagne($d, $bon, null));
        if ($w['erreur'] !== null) { return [$campId, 'le panel refuse la mise à jour du bon : ' . $w['erreur']]; }
        $deja = $actuel['codes'] ?? [];
    }
    $e = pbAjouterCodes($campId, $d, $bon, $deja);
    if ($e !== null) { return [$campId, $e]; }
    if ($actuel === null || (int) ($actuel['promotion'] ?? 0) !== $promoId) {
        $w = ErpApi::ecrire('PUT', '/admin/voucher-campaigns/' . $campId . '/promotion', ['id_promotion' => $promoId, 'confirm_activation_mode_change' => true]);
        // 409 : déjà rattachée à cette promotion (une reprise) — la fiche le dira à la relecture.
        if ($w['erreur'] !== null && (int) $w['code'] !== 409) { return [$campId, 'le bon ne se rattache pas à la promotion : ' . $w['erreur']]; }
    }
    return [$campId, null];
}

/** La campagne activée (PATCH /{id}, le type et le code commun rappelés) puis relue complète. Rend l'erreur, ou null. */
function pbActiver(int $campId): ?string
{
    $l = pbLire($campId);
    if ($l === null) { return 'bon illisible au panel'; }
    $w = ErpApi::ecrire('PATCH', '/admin/voucher-campaigns/' . $campId,
        ['status' => 'ACTIVE', 'code_type' => $l['cible'] === 'code' ? 'SHARED' : 'UNIQUE', 'shared_code' => $l['cible'] === 'code' ? $l['code'] : null]);
    if ($w['erreur'] !== null) { return 'activation du bon refusée : ' . $w['erreur']; }
    $l = pbLire($campId);
    if ($l === null || !$l['actif']) { return 'bon pas relu actif'; }
    if (!$l['complete']) { return 'bon incomplet au panel (' . implode(', ', $l['manque']) . ')'; }
    return null;
}

/** La campagne mise hors service (INACTIVE). Rend l'erreur, ou null. */
function pbDesactiver(int $campId): ?string
{
    $w = ErpApi::ecrire('PATCH', '/admin/voucher-campaigns/' . $campId . '/status', ['status' => 'INACTIVE']);
    if ($w['erreur'] === null) { return null; }
    $l = pbLire($campId);
    $w = ErpApi::ecrire('PATCH', '/admin/voucher-campaigns/' . $campId,
        ['status' => 'INACTIVE', 'code_type' => ($l['cible'] ?? '') === 'code' ? 'SHARED' : 'UNIQUE', 'shared_code' => ($l['cible'] ?? '') === 'code' ? $l['code'] : null]);
    return $w['erreur'] !== null ? 'désactivation du bon refusée : ' . $w['erreur'] : null;
}

/** Un bon retiré de sa promotion : hors service, puis détaché (le panel le repasse en brouillon). */
function pbRetirer(int $campId): ?string
{
    $e = pbDesactiver($campId);
    $w = ErpApi::ecrire('DELETE', '/admin/voucher-campaigns/' . $campId . '/promotion');
    return $e ?? ($w['erreur'] !== null && (int) $w['code'] !== 404 ? 'le bon ne se détache pas : ' . $w['erreur'] : null);
}

/**
 * L'envoi d'une promotion du magasin avec son bon (à la place de pmPanelEnvoyer) : promotion écrite inactive et relue,
 * bon posé (campagne, codes, rattachement), puis — à l'envoi — promotion activée et bon activé. Sans bon, et quand la
 * promotion en avait un : envoi ordinaire (activation automatique), puis l'ancien bon retiré. Rend {ok, id, erreur, campagne}.
 */
function pbEnvoyer(array $d, ?int $panelId, bool $envoi, ?int $campAvant): array
{
    $bon = $d['bon'] ?? null;
    if ($bon === null) {
        $r = pmPanelEnvoyer($d, $panelId, $envoi);
        if ($campAvant !== null && $r['id'] !== null) {
            $e = pbRetirer($campAvant);
            if ($e !== null && $r['erreur'] === null) { $r = ['ok' => $r['ok'], 'id' => $r['id'], 'erreur' => 'promotion enregistrée, mais l’ancien bon reste au panel : ' . $e]; }
        }
        return $r + ['campagne' => null];
    }
    $r = pmPanelEnvoyer($d, $panelId, false);
    if ($r['id'] === null || !$r['ok']) { return $r + ['campagne' => $campAvant]; }
    $id = (int) $r['id'];
    [$campId, $e] = pbPoser($d, $bon, $id, $campAvant);
    if ($e !== null) { return ['ok' => false, 'id' => $id, 'erreur' => 'promotion n° ' . $id . ' au panel, inactive — ' . $e, 'campagne' => $campId]; }
    if (!$envoi) {
        // Un brouillon : le bon reste hors service avec lui (une reprise d'une promotion en caisse ne passe pas par ici).
        if ($campAvant !== null) { pbDesactiver($campId); }
        return ['ok' => true, 'id' => $id, 'erreur' => null, 'campagne' => $campId];
    }
    $r = pmPanelEnvoyer($d, $id, true);
    if (!$r['ok']) { return $r + ['campagne' => $campId]; }
    $e = pbActiver($campId);
    return $e === null ? ['ok' => true, 'id' => $id, 'erreur' => null, 'campagne' => $campId]
        : ['ok' => false, 'id' => $id, 'erreur' => 'promotion n° ' . $id . ' active au panel, mais ' . $e . ' : elle ne s’applique pas tant que le bon n’est pas actif', 'campagne' => $campId];
}

/* --- les clients du magasin -------------------------------------------------------------------------------- */

/**
 * Les deux sortes de clients qu'un bon peut viser (29/10/2026, Sam : « choisir un client B2B ou un client normal
 * (rattaché au magasin) »). Le panel les sépare lui-même : `GET /shops/{id}/clients?type=b2b` rend les comptes B2B du
 * magasin, toute autre valeur de `type` (ici `b2c`) ses particuliers — lu par la sonde du 29/10/2026
 * (tools/sonde_clients_bon.php : au magasin 2, 706 B2B et 7 935 particuliers).
 */
const PB_TYPES = ['b2b' => 'b2b', 'particulier' => 'b2c'];
/** Une page du repli : `limit` n'est tenu qu'avec `offset` (sans lui, tout vient d'un coup, 10 Mo au magasin 2). */
const PB_PAGE_CLIENTS = 500;
/** 80 pages au plus (40 000 clients) : une boucle sans fin si le panel ignorait `offset`. */
const PB_PAGES_MAX = 80;
/**
 * La liste lue au panel se garde une heure (dix minutes jusqu'au 29/10/2026 : la première recherche des 7 935
 * particuliers du magasin 2 prenait 12,8 s à chaque fois). Un client créé en caisse entre-temps : « relire » (`frais`).
 */
const PB_CLIENTS_TTL = 3600;

/** Le type demandé (« b2b », « particulier ») ; tout le reste : les deux. */
function pbType($v): ?string
{
    $v = strtolower(trim((string) $v));
    return isset(PB_TYPES[$v]) ? $v : null;
}

/** Le nom d'un client du panel : la société d'un B2B, sinon prénom et nom, sinon la société, sinon son numéro. */
function pbNomClient(array $c): string
{
    $soc = trim((string) ($c['company_name'] ?? ''));
    if (!empty($c['is_b2b']) && $soc !== '') { return $soc; }
    $n = trim(trim((string) ($c['name'] ?? '')) . ' ' . trim((string) ($c['surname'] ?? '')));
    return $n !== '' ? $n : ($soc ?: 'client n° ' . (int) ($c['id'] ?? 0));
}

/** Un e-mail montré à moitié (« j…@exemple.be »), pour distinguer deux homonymes sans l'exposer. */
function pbMasquer(?string $mail, ?string $tel): ?string
{
    $mail = trim((string) $mail); $tel = preg_replace('/\D+/', '', (string) $tel);
    if ($mail !== '' && str_contains($mail, '@')) { [$a, $b] = explode('@', $mail, 2); return mb_substr($a, 0, 1) . '…@' . $b; }
    return $tel !== '' && strlen($tel) >= 3 ? '…' . substr($tel, -3) : null;
}

/**
 * Un client du panel réduit à ce que le bon montre et cherche : {id, nom, soc, personne, tva, ville, b2b, ailleurs,
 * contact, r}. `soc` : la société d'un particulier qui en porte une ; `personne` : la personne derrière un B2B ;
 * `ailleurs` : son magasin principal (`id_main_shop`) en est un autre ; `r` : le texte cherché (nom, société, e-mail,
 * téléphone, ville, code postal, TVA, code client), en minuscules, avec les chiffres seuls du téléphone et de la TVA.
 */
function pbClientCourt(array $c, int $shop): array
{
    $b2b = !empty($c['is_b2b']);
    $soc = trim((string) ($c['company_name'] ?? ''));
    $pers = trim(trim((string) ($c['name'] ?? '')) . ' ' . trim((string) ($c['surname'] ?? '')));
    $tva = trim((string) ($c['tax_number'] ?? ''));
    $tel = (string) ($c['phone_e164'] ?? $c['phone'] ?? '');
    $champs = [$c['name'] ?? '', $c['surname'] ?? '', $soc, $c['email'] ?? '', $c['phone'] ?? '', $c['city'] ?? '', $c['zip'] ?? '', $tva, $c['client_code'] ?? '',
        preg_replace('/\D+/', '', $tel), preg_replace('/\D+/', '', $tva)];
    $main = (int) ($c['id_main_shop'] ?? 0);
    return ['id' => (int) $c['id'], 'nom' => pbNomClient($c), 'soc' => !$b2b && $soc !== '' ? $soc : null,
        'personne' => $b2b && $soc !== '' && $pers !== '' ? $pers : null, 'tva' => $b2b && $tva !== '' ? $tva : null,
        'ville' => trim((string) ($c['city'] ?? '')) ?: null, 'b2b' => $b2b, 'ailleurs' => $main > 0 && $main !== $shop,
        'contact' => pbMasquer($c['email'] ?? null, $c['phone'] ?? null),
        'r' => mb_strtolower(implode(' ', array_map(static fn ($v) => trim((string) $v), $champs)))];
}

/** Assez de mémoire pour lire une liste d'un seul tenant : la limite relevée à 512 Mo si elle est plus basse. */
function pbMemoire(): void
{
    $l = strtolower(trim((string) ini_get('memory_limit')));
    if ($l === '' || $l === '-1') { return; }
    $n = (int) $l * match (substr($l, -1)) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
    if ($n < 512 * 1024 ** 2) { @ini_set('memory_limit', '512M'); }
}

/** Les clients d'une réponse du panel ajoutés à $out (fusionnés, bloqués, inactifs et l'autre type écartés). Rend leur nombre lu. */
function pbAjouterClients(array &$out, $corps, int $shop, string $type): int
{
    $l = pbItems($corps);
    foreach ($l as $x) {
        if (!is_array($x) || (int) ($x['id'] ?? 0) <= 0 || !empty($x['merged_into_id']) || !empty($x['blocked']) || (isset($x['active']) && !$x['active'])) { continue; }
        // Le panel trie ses B2B devant : un filtre `type` ignoré mêlerait les deux, on ne garde que le type demandé.
        if (!empty($x['is_b2b']) !== ($type === 'b2b')) { continue; }
        $out[(int) $x['id']] = pbClientCourt($x, $shop);
    }
    return count($l);
}

/**
 * Les clients d'un magasin d'un type (« b2b », « particulier »), lus au panel, réduits (pbClientCourt) et gardés une
 * heure : [id => client]. `$frais` : relus au panel, la garde passée outre. Null : le panel ne répond pas. Un client
 * fusionné dans un autre (`merged_into_id`), bloqué ou inactif ne reçoit pas de bon.
 *
 * D'un seul tenant d'abord : la sonde du 29/10/2026 a lu les 7 935 particuliers du magasin 2 en 2,2 s (+53 Mo au plus
 * haut), contre 8,1 s en seize pages de 500 l'une après l'autre. Les pages (`limit` + `offset`, la première seule puis
 * quatre de front) restent le repli : une lecture d'un tenant refusée, ou un compte rond — le panel aurait coupé.
 */
function pbClientsDuType(int $shop, string $type, bool $frais = false): ?array
{
    // Gardée aussi le temps de la requête (« Voir les codes » lit les deux types) ; une variable globale, que les tests vident.
    $garde = &$GLOBALS['pbClientsGarde'];
    $cle = 'pbClients:' . $shop . ':' . $type;
    if (isset($garde[$cle]) && !$frais) { return $garde[$cle]; }
    $c = !$frais && function_exists('setting') ? setting($cle) : null;
    if (is_array($c) && is_array($c['l'] ?? null) && (int) ($c['lu'] ?? 0) > time() - PB_CLIENTS_TTL) {
        $out = [];
        foreach ($c['l'] as $x) { if (is_array($x) && (int) ($x['id'] ?? 0) > 0) { $out[(int) $x['id']] = $x; } }
        $GLOBALS['pbClientsLu'][$cle] = (int) $c['lu'];
        return $garde[$cle] = $out;
    }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return null; }
    $out = []; $partiel = false;
    pbMemoire();
    $r = ErpApi::sondeGet('/shops/' . $shop . '/clients?type=' . PB_TYPES[$type]);
    $n = $r['code'] >= 200 && $r['code'] < 300 && is_array($r['corps']) ? pbAjouterClients($out, $r['corps'], $shop, $type) : -1;
    unset($r);
    if ($n < 0 || ($n > 0 && $n % 50 === 0)) {
        $out = [];
        for ($page = 0, $k = 1; $page < PB_PAGES_MAX; $page += $k, $k = 4) {
            $ch = [];
            for ($i = $page; $i < min($page + $k, PB_PAGES_MAX); $i++) {
                $ch[$i] = '/shops/' . $shop . '/clients?type=' . PB_TYPES[$type] . '&limit=' . PB_PAGE_CLIENTS . '&offset=' . ($i * PB_PAGE_CLIENTS);
            }
            $fin = false;
            foreach (ErpApi::getParallele($ch) as $i => $corps) {
                // Une page refusée en route : la liste vaut pour cette fois, sans se garder (la suivante relira tout).
                if ($corps === null) { if ($i === 0) { return null; } $fin = $partiel = true; break; }
                if (pbAjouterClients($out, $corps, $shop, $type) < PB_PAGE_CLIENTS) { $fin = true; break; }
            }
            if ($fin) { break; }
        }
    }
    $GLOBALS['pbClientsLu'][$cle] = time();
    if (!$partiel) {
        try {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['lu' => time(), 'l' => array_values($out)], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* sans la garde, la recherche relira le panel : plus lente, toujours juste */ }
    }
    return $garde[$cle] = $out;
}

/** Les clients du magasin, B2B et particuliers : [id => client]. Null : ni l'un ni l'autre ne se lit au panel. */
function pbClientsDuMagasin(int $shop): ?array
{
    $b = pbClientsDuType($shop, 'b2b');
    $p = pbClientsDuType($shop, 'particulier');
    return $b === null && $p === null ? null : ($b ?? []) + ($p ?? []);
}

/**
 * Les textes cherchés : en minuscules ; un numéro (TVA, téléphone : « BE 0123.456 », « 0470 12 34 ») en chiffres seuls, et
 * sans son zéro de tête — le panel garde les téléphones au format international (« +32470… »).
 */
function pbRecherches(string $q): array
{
    $q = mb_strtolower(trim($q));
    // « BE 0477.472.701 » : le préfixe de pays d'une TVA tombe avec les points et les espaces.
    if (preg_match('/^(?:[a-z]{2}\s*)?\+?[\d\s.\/-]{3,}$/', $q) !== 1 || preg_match_all('/\d/', $q) < 3) { return [$q]; }
    $q = preg_replace('/\D+/', '', $q);
    $sans = ltrim($q, '0');
    return $sans !== $q && strlen($sans) >= 6 ? [$q, $sans] : [$q];
}

/**
 * GET /exploitation/promos-magasin/clients?shop=4&type=b2b|particulier&q=dup[&frais=1] — les clients du magasin, lus au panel,
 * pour le bon : les comptes B2B (société, TVA, la personne) ou les particuliers rattachés au magasin (nom, ville),
 * un contact masqué. 30 au plus, triés par nom ; deux lettres au moins pour chercher — sauf les B2B, qui se
 * parcourent sans texte. Sans `type` : les deux (la forme d'avant le 29/10/2026).
 */
function ep_promos_magasin_clients(): array
{
    $t0 = microtime(true);
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $type = pbType($_GET['type'] ?? '');
    $qs = pbRecherches((string) ($_GET['q'] ?? ''));
    $q = $qs[0];
    $listes = [];
    foreach ($type !== null ? [$type] : array_keys(PB_TYPES) as $t) { $listes[$t] = pbClientsDuType($shop, $t, !empty($_GET['frais'])); }
    if (!array_filter($listes, static fn ($l) => $l !== null)) {
        http_response_code(502); return ['error' => 'clients du magasin illisibles au panel' . (ErpApi::$lastError ? ' : ' . ErpApi::$lastError : '')];
    }
    $tous = [];
    foreach ($listes as $l) { $tous += $l ?? []; }
    $l = [];
    if (mb_strlen($q) >= 2 || ($type === 'b2b' && $q === '')) {
        foreach ($tous as $c) {
            if ($q !== '' && !array_filter($qs, static fn ($x) => str_contains((string) $c['r'], $x))) { continue; }
            $l[] = array_diff_key($c, ['r' => 1]);
        }
        usort($l, static fn ($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));
    }
    // L'heure de la plus ancienne des listes lues : « liste du panel lue à 9 h 50 · relire ».
    $lus = array_filter(array_map(static fn ($t) => $GLOBALS['pbClientsLu']['pbClients:' . $shop . ':' . $t] ?? null, array_keys(array_filter($listes, static fn ($x) => $x !== null))));
    return ['shop' => (string) $shop, 'type' => $type, 'total' => count($tous), 'trouves' => count($l), 'clients' => array_slice($l, 0, 30),
        'lu' => $lus ? date('H:i', min($lus)) : null, 'ms' => (int) round(1000 * (microtime(true) - $t0)),
        'nB2b' => isset($listes['b2b']) ? count($listes['b2b']) : null, 'nParticuliers' => isset($listes['particulier']) ? count($listes['particulier']) : null];
}

/* --- le bon d'une promotion, relu et basculé depuis la liste ---------------------------------------------------- */

/** La promotion n° (« p21 ») du magasin et son bon : [demande, numéro de campagne] — ou une erreur HTTP. */
function pbDuMagasin(int $shop, $id): array
{
    $pid = pmNumero($id);
    if ($shop <= 0 || $pid === null) { return [null, null, 404, 'promotion introuvable']; }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return [null, null, 503, 'compte admin du panel indisponible']; }
    $d = pmMienne($shop, $pid);
    if ($d === null) { return [null, null, 404, 'promotion introuvable']; }
    $camp = ($d['bon'] ?? null) !== null ? (($d['bon']['campagne'] ?? null) ?: pbCampagneDe($pid)) : null;
    if ($camp === null) { return [$d, null, 404, 'cette promotion n’a pas de bon']; }
    return [$d, $camp, 200, null];
}

/**
 * GET /exploitation/promos-magasin/bon?shop=4&id=p21 — le bon de la promotion, relu au panel : sa distribution, ses
 * codes (en clair : le magasin les remet à ses clients) et le nom du client de chacun.
 */
function ep_promos_magasin_bon(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    [, $camp, $code, $err] = pbDuMagasin($shop, $_GET['id'] ?? '');
    if ($err !== null) { http_response_code($code); return ['error' => $err]; }
    $b = pbLire($camp, pbClientsDuMagasin($shop) ?? []);
    if ($b === null) { http_response_code(502); return ['error' => 'bon illisible au panel'];  }
    return ['ok' => true, 'bon' => $b];
}

/** POST /exploitation/promos-magasin/bon {shop, id: « p21 », actif} — le bon de la promotion activé ou mis hors service. */
function wr_promos_magasin_bon(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    [$d, $camp, $code, $err] = pbDuMagasin($shop, $b['id'] ?? '');
    if ($err !== null) { http_response_code($code); return ['error' => $err]; }
    $actif = !empty($b['actif']);
    if ($actif && ($d['statut'] ?? '') !== 'en_caisse') { http_response_code(409); return ['error' => 'la promotion est en brouillon : enregistrez-la d’abord, son bon s’active avec elle']; }
    $e = $actif ? pbActiver($camp) : pbDesactiver($camp);
    if ($e !== null) { http_response_code(502); return ['error' => $e]; }
    return ['ok' => true, 'bon' => pbLire($camp)];
}
