<?php
declare(strict_types=1);

/**
 * Les bureaux en développement (26/11/2026). Sam : « relier directement une nouvelle personne et un nouveau bureau
 * (development) ou par après je peux valider le bureau », puis « fais aussi un questionnaire directement (1 par
 * magasin) qui crée directement aussi un voucher ».
 *
 * Une DEMANDE de bureau naît de deux façons : le questionnaire public du magasin (une page sans session, son adresse
 * porte le jeton du magasin), ou « Nouveau bureau en développement » dans le dashboard. Elle crée tout de suite dans
 * TF Buddy, avec les comptes du cockpit (pclTf : les routes /shops/{s}/clients et les bons leur répondent) :
 *   - la personne : un client particulier du magasin (POST /shops/{s}/clients ; nom et téléphone obligatoires) ;
 *   - la société, si un numéro de TVA est donné : un client B2B (POST /shops/{s}/clients {vat_number}, VIES complète) ;
 *   - le bon de bienvenue, si le magasin en a choisi un (une promotion avec bon, module Promotions) : le code commun de
 *     la campagne, ou un code personnel d'une utilisation au nom de la personne (POST /admin/voucher-campaigns/{id}/codes).
 * Le BUREAU, lui, ne se crée qu'à la validation : ses routes (/panel/shops/{s}/offices) n'ouvrent qu'au compte TF Buddy
 * du magasin, connecté dans le dashboard (tfb_magasin.php) — une page publique n'en a pas. Valider crée le bureau
 * (onboard, statut « active » ou « pending »), avec la société, le contact, le site, et relie la personne (members).
 *
 *   GET  /bureau-questionnaire/{jeton}                  PUBLIC : le questionnaire (magasin, titre, message, bon)
 *   POST /bureau-questionnaire/{jeton}                  PUBLIC : une réponse → {ok, bon: {code, texte} | null}
 *   GET|POST /bureau-questionnaire/m/{shop}             PUBLIC : le même, par le numéro du magasin (« ?m=2 », Corbais) — le
 *                                                       lien court, à écrire à l'avance ; il se ferme avec le questionnaire
 *   GET  /dashboard/bureaux-dev?shop=                   les demandes, le réglage du questionnaire, les promotions à bon
 *   POST /dashboard/bureaux-dev {shop, donnees}         une demande saisie au dashboard (même chemin que le questionnaire)
 *   POST /dashboard/bureaux-dev/questionnaire {shop, actif, promo, titre, message, regenerer}
 *   POST /dashboard/bureaux-dev/valider {shop, id, statut: active|pending, nom, tva, route_id}   → le bureau dans TF Buddy
 *   POST /dashboard/bureaux-dev/refuser {shop, id}
 *
 * Tables : panel_bureau_questionnaire (un par magasin), panel_bureau_dev (les demandes ; les données de la personne y
 * restent pour la validation, effacées 90 jours après qu'elle est traitée). Un questionnaire reçoit au plus
 * BDV_MAX_HEURE réponses par heure et par adresse IP (empreinte seulement) ; un champ piège écarte les robots.
 */

const BDV_MAX_HEURE = 5;
/** Au plus tant de réponses par jour et par magasin au questionnaire (le lien court se devine : il faut un plafond). */
const BDV_MAX_JOUR = 40;
const BDV_GARDE_JOURS = 90;
const BDV_STATUTS = ['a_valider', 'cree', 'rejoint', 'refuse'];

function ensureBureauxDevTables(): void
{
    static $fait = false;
    if ($fait || !class_exists('Db')) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS panel_bureau_questionnaire ('
        . 'shop_id VARCHAR(16) NOT NULL PRIMARY KEY, jeton CHAR(32) NOT NULL, actif TINYINT NOT NULL DEFAULT 1,'
        . 'promo INT NULL, titre VARCHAR(120) NOT NULL DEFAULT "", message TEXT NULL, maj_le DATETIME NOT NULL, UNIQUE KEY (jeton)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS panel_bureau_dev ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, shop_id VARCHAR(16) NOT NULL, source VARCHAR(20) NOT NULL, statut VARCHAR(20) NOT NULL DEFAULT "a_valider",'
        . 'donnees MEDIUMTEXT NOT NULL, client_id INT NULL, societe_id INT NULL, bon_code VARCHAR(40) NULL, bon_texte VARCHAR(255) NULL,'
        . 'erreurs TEXT NULL, office_id INT NULL, ip_hash CHAR(64) NOT NULL DEFAULT "", cree_le DATETIME NOT NULL, traite_le DATETIME NULL,'
        . 'INDEX (shop_id, statut), INDEX (ip_hash, cree_le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/* ── Ce qui ne dépend de rien (testé : panel/bin/tests_bureaux_dev.php) ──────────────────────────────────────── */

function bdvTexte($v, int $max = 120): string
{
    return is_scalar($v) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, $max) : '';
}

/** Un numéro de TVA rendu propre (« be 0412.338.901 » → « BE0412338901 ») ; '' s'il n'en a pas la forme. */
function bdvTva($v): string
{
    $t = strtoupper(preg_replace('/[\s.\-]+/', '', bdvTexte($v, 40)));
    return preg_match('/^[A-Z]{2}[0-9A-Z]{6,14}$/', $t) ? $t : '';
}

/** Un téléphone : chiffres et « + » de tête ; '' s'il compte moins de 8 chiffres. */
function bdvTel($v): string
{
    $t = preg_replace('/[^\d+]/', '', bdvTexte($v, 30));
    $t = ($t !== '' && $t[0] === '+' ? '+' : '') . str_replace('+', '', $t);
    return strlen(ltrim($t, '+')) >= 8 ? $t : '';
}

/** Un téléphone au format international pour TF Buddy (E.164) : « 0470 12 34 56 » → « +32470123456 » (la Belgique par défaut). */
function bdvE164(string $tel, string $pays = 'BE'): string
{
    if ($tel === '') { return ''; }
    if (str_starts_with($tel, '+')) { return $tel; }
    if (str_starts_with($tel, '00')) { return '+' . substr($tel, 2); }
    $ind = ['BE' => '32', 'FR' => '33', 'NL' => '31', 'LU' => '352', 'DE' => '49', 'PL' => '48'][$pays] ?? '32';
    return '+' . $ind . ltrim($tel, '0');
}

/** Une heure « HH:MM », ou ''. */
function bdvHeure($v): string
{
    $v = bdvTexte($v, 5);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : '';
}

/** Un choix parmi une liste, sinon $defaut. */
function bdvChoix($v, array $permis, string $defaut = ''): string
{
    $v = bdvTexte($v, 20);
    return in_array($v, $permis, true) ? $v : $defaut;
}

/**
 * Une réponse au formulaire (ou une saisie du dashboard), rendue propre : [données, erreurs].
 * Le formulaire public (26/11/2026, Sam : « faire le formulaire », maquette panel/docs/maquettes/bureau-formulaire/)
 * demande ce dont TF Buddy a besoin pour créer le compte webshop, la société, le bureau, son site et son équipe.
 * Obligatoires partout : prénom, nom, e-mail, téléphone, société, adresse du bureau. Au formulaire public, en plus : la
 * TVA (un bureau est celui d'une société B2B), l'adresse de facturation, au moins un jour, le créneau, l'accord.
 * Aucun mot de passe : le compte se crée sans, la personne choisit le sien par l'e-mail de TF Buddy.
 */
function bdvNettoyer(array $in, bool $public): array
{
    $pays = bdvChoix(strtoupper((string) ($in['pays'] ?? 'BE')), ['BE', 'FR', 'NL', 'LU', 'DE'], 'BE');
    $liste = static fn ($v, int $max) => array_slice(array_values(array_unique(array_filter(array_map(static fn ($x) => bdvTexte($x, 60), is_array($v) ? $v : [])))), 0, $max);
    $collegues = [];
    foreach (is_array($in['collegues'] ?? null) ? $in['collegues'] : [] as $c) {
        $e = strtolower(bdvTexte(is_array($c) ? ($c['email'] ?? '') : '', 160));
        if (filter_var($e, FILTER_VALIDATE_EMAIL) && !isset($collegues[$e])) { $collegues[$e] = ['nom' => bdvTexte($c['nom'] ?? '', 80), 'email' => $e]; }
        if (count($collegues) >= 30) { break; }
    }
    $logo = is_string($in['logo'] ?? null) && strlen($in['logo']) <= 300000 && preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $in['logo']) ? $in['logo'] : '';
    $d = [
        'prenom' => bdvTexte($in['prenom'] ?? ''), 'nom' => bdvTexte($in['nom'] ?? ''), 'fonction' => bdvTexte($in['fonction'] ?? ''),
        'email' => strtolower(bdvTexte($in['email'] ?? '', 160)), 'tel' => bdvTel($in['tel'] ?? ''), 'langue' => bdvChoix($in['langue'] ?? 'fr', ['fr', 'nl', 'en'], 'fr'),
        'societe' => bdvTexte($in['societe'] ?? ''), 'tva' => bdvTva($in['tva'] ?? ''), 'pays' => $pays,
        'secteur' => bdvChoix($in['secteur'] ?? '', ['corporate', 'retail', 'horeca', 'event']), 'bureau' => bdvTexte($in['bureau'] ?? ''),
        'emailFact' => strtolower(bdvTexte($in['emailFact'] ?? '', 160)), 'factRue' => bdvTexte($in['factRue'] ?? '', 120), 'factNum' => bdvTexte($in['factNum'] ?? '', 12),
        'factBoite' => bdvTexte($in['factBoite'] ?? '', 12), 'factCp' => bdvTexte($in['factCp'] ?? '', 12), 'factVille' => bdvTexte($in['factVille'] ?? '', 80),
        'bonCommande' => bdvTexte($in['bonCommande'] ?? '', 60), 'paiement' => bdvChoix($in['paiement'] ?? 'company', ['company', 'person'], 'company'),
        'adresse' => bdvTexte($in['adresse'] ?? '', 160), 'cp' => bdvTexte($in['cp'] ?? '', 12), 'ville' => bdvTexte($in['ville'] ?? '', 80),
        'batiment' => bdvTexte($in['batiment'] ?? '', 60), 'etage' => bdvTexte($in['etage'] ?? '', 30),
        'personnes' => max(0, min(5000, (int) ($in['personnes'] ?? 0))),
        'jours' => array_values(array_unique(array_filter(array_map('intval', (array) ($in['jours'] ?? [])), static fn ($j) => $j >= 1 && $j <= 7))),
        'de' => bdvHeure($in['de'] ?? ''), 'a' => bdvHeure($in['a'] ?? ''), 'remise' => bdvChoix($in['remise'] ?? '', ['qr', 'signature', 'dropoff']),
        'services' => $liste($in['services'] ?? [], 12), 'collegues' => array_values($collegues),
        'remarque' => bdvTexte($in['remarque'] ?? '', 600), 'recontact' => !empty($in['recontact']), 'nouvelles' => !empty($in['nouvelles']),
        'logo' => $logo,
    ];
    sort($d['jours']);
    $e = [];
    if ($d['prenom'] === '' || $d['nom'] === '') { $e[] = 'votre prénom et votre nom'; }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { $e[] = 'un e-mail valide'; }
    if ($d['tel'] === '') { $e[] = 'un numéro de téléphone'; }
    if ($d['societe'] === '') { $e[] = 'le nom de la société'; }
    if ($d['adresse'] === '' || $d['ville'] === '') { $e[] = 'l’adresse du bureau (rue et ville)'; }
    if (bdvTexte($in['tva'] ?? '') !== '' && $d['tva'] === '') { $e[] = 'un numéro de TVA valide (BE0123456789)' . ($public ? '' : ', ou rien'); }
    elseif ($public && $d['tva'] === '') { $e[] = 'le numéro de TVA de la société'; }
    if ($d['emailFact'] !== '' && !filter_var($d['emailFact'], FILTER_VALIDATE_EMAIL)) { $e[] = 'un e-mail de facturation valide, ou rien'; }
    if ($public && ($d['factRue'] === '' || $d['factCp'] === '' || $d['factVille'] === '')) { $e[] = 'l’adresse de facturation (rue, code postal, localité)'; }
    if ($public && $d['jours'] === []) { $e[] = 'au moins un jour de livraison'; }
    if (($public || $d['de'] !== '' || $d['a'] !== '') && ($d['de'] === '' || $d['a'] === '' || $d['a'] <= $d['de'])) { $e[] = 'le créneau de livraison (de … à …)'; }
    if ($public && empty($in['accord'])) { $e[] = 'votre accord avec les conditions et la vie privée'; }
    if ($d['bureau'] === '') { $d['bureau'] = $d['societe']; }
    if ($d['emailFact'] === '') { $d['emailFact'] = $d['email']; }
    return [$d, $e];
}

/** Une demande pour le dashboard : ses données (sans le logo, dit seulement), les ids TF Buddy, le bon, les soucis. */
function bdvLigne(array $r): array
{
    $d = json_decode((string) ($r['donnees'] ?? '{}'), true) ?: [];
    $logo = (string) ($d['logo'] ?? '') !== '';
    unset($d['logo']);
    return ['id' => (int) $r['id'], 'source' => (string) $r['source'], 'statut' => (string) $r['statut'], 'le' => substr((string) $r['cree_le'], 0, 16),
        'bureau' => (string) ($d['bureau'] ?? ''), 'societe' => (string) ($d['societe'] ?? ''), 'tva' => (string) ($d['tva'] ?? ''),
        'personne' => trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? '')), 'fonction' => (string) ($d['fonction'] ?? ''), 'email' => (string) ($d['email'] ?? ''), 'tel' => (string) ($d['tel'] ?? ''),
        'adresse' => (string) ($d['adresse'] ?? ''), 'cp' => (string) ($d['cp'] ?? ''), 'ville' => (string) ($d['ville'] ?? ''),
        'personnes' => (int) ($d['personnes'] ?? 0), 'jours' => (array) ($d['jours'] ?? []), 'remarque' => (string) ($d['remarque'] ?? ''),
        'd' => $d, 'logo' => $logo, 'compte' => (string) ($d['_compte'] ?? ''),
        'clientId' => $r['client_id'] !== null ? (int) $r['client_id'] : null, 'societeId' => $r['societe_id'] !== null ? (int) $r['societe_id'] : null,
        'bon' => $r['bon_code'] !== null ? ['code' => (string) $r['bon_code'], 'texte' => (string) ($r['bon_texte'] ?? '')] : null,
        'erreurs' => array_values(array_filter(explode("\n", (string) ($r['erreurs'] ?? '')))), 'officeId' => $r['office_id'] !== null ? (int) $r['office_id'] : null,
        'traite' => $r['traite_le'] !== null ? substr((string) $r['traite_le'], 0, 16) : null];
}

/** L'id d'un client dans une réponse de création de TF Buddy ({data: {id}}, {id}, {client: {id}}). */
function bdvIdClient($c): ?int
{
    if (!is_array($c)) { return null; }
    foreach ([$c['data']['id'] ?? null, $c['client']['id'] ?? null, $c['id'] ?? null, $c['data']['client']['id'] ?? null] as $v) {
        if (is_numeric($v) && (int) $v > 0) { return (int) $v; }
    }
    return null;
}

/* ── Le questionnaire du magasin ────────────────────────────────────────────────────────────────────────────── */

function bdvQuestionnaire(string $shop, bool $creer = false): ?array
{
    ensureBureauxDevTables();
    $q = Db::row('SELECT * FROM panel_bureau_questionnaire WHERE shop_id = ?', [$shop]);
    if ($q === null && $creer) {
        Db::exec('INSERT INTO panel_bureau_questionnaire (shop_id, jeton, actif, titre, message, maj_le) VALUES (?, ?, 1, ?, ?, NOW())',
            [$shop, bin2hex(random_bytes(16)), 'Du frais au bureau', 'Dites-nous où vous travaillez : nous livrons votre bureau, et votre première commande est récompensée.']);
        $q = Db::row('SELECT * FROM panel_bureau_questionnaire WHERE shop_id = ?', [$shop]);
    }
    return $q;
}

/** Les promotions du magasin qui ont un bon (module Promotions) : [{id, nom, description, enCaisse, campagne}]. */
function bdvPromosABon(int $shop): array
{
    if (!function_exists('pmMiennes')) { return []; }
    try { [$l] = pmMiennes($shop); } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($l as $p) {
        if (empty($p['bon'])) { continue; }
        $out[] = ['id' => (int) $p['panelId'], 'nom' => (string) $p['nom'], 'description' => (string) ($p['description'] ?? ''),
            'enCaisse' => ($p['statut'] ?? '') === 'en_caisse', 'campagne' => isset($p['bon']['campagne']) ? (int) $p['bon']['campagne'] : null];
    }
    return $out;
}

/**
 * Le bon de bienvenue d'une personne : [code, texte, erreur]. La campagne de la promotion choisie : un code commun
 * (`cible` « code ») se donne tel quel ; sinon un code personnel d'une utilisation, au nom du client.
 */
function bdvBon(int $shop, int $promo, ?int $clientId): array
{
    if ($promo <= 0) { return [null, null, null]; }
    if (!function_exists('pmMienne') || !function_exists('pbLire') || !class_exists('ErpApi') || !ErpApi::disponible()) { return [null, null, 'le module des bons n’est pas disponible']; }
    $p = pmMienne($shop, $promo);
    if ($p === null || empty($p['bon'])) { return [null, null, 'la promotion du bon n’existe plus']; }
    $camp = (int) ($p['bon']['campagne'] ?? 0) ?: (function_exists('pbCampagneDe') ? (int) pbCampagneDe($promo) : 0);
    if ($camp <= 0) { return [null, null, 'la promotion n’a pas de campagne de bon']; }
    $texte = trim($p['nom'] . ($p['description'] !== '' ? ' — ' . $p['description'] : ''));
    $b = pbLire($camp);
    if ($b === null) { return [null, $texte, 'la campagne du bon ne se lit pas']; }
    if ($b['cible'] === 'code') { return [$b['code'] !== '' ? $b['code'] : null, $texte, $b['code'] !== '' ? null : 'le code commun est vide']; }
    if ($clientId === null) { return [null, $texte, 'pas de client pour un code personnel']; }
    $err = null;
    for ($essai = 0; $essai < 3; $essai++) {
        $code = function_exists('pbGenerer') ? pbGenerer(8, 'B') : 'B' . strtoupper(bin2hex(random_bytes(4)));
        $w = ErpApi::ecrire('POST', '/admin/voucher-campaigns/' . $camp . '/codes',
            ['code' => $code, 'status' => 'ACTIVE', 'id_customer' => $clientId, 'valid_from' => null, 'valid_to' => null, 'usage_limit' => 1]);
        if ($w['erreur'] === null) { return [$code, $texte, null]; }
        $err = $w['erreur'];
        if ((int) $w['code'] !== 422 || stripos((string) $err, 'code') === false) { break; }
    }
    return [null, $texte, 'code non créé : ' . $err];
}

/** Le client_id d'un jeton client de TF Buddy (la charge utile du JWT, sans vérifier la signature : il vient de TF Buddy). */
function bdvJwtClient(string $jwt): ?int
{
    $p = explode('.', $jwt);
    if (count($p) !== 3) { return null; }
    $c = json_decode((string) base64_decode(strtr($p[1], '-_', '+/') . str_repeat('=', (4 - strlen($p[1]) % 4) % 4)), true);
    $id = is_array($c) ? ($c['client_id'] ?? null) : null;
    return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
}

/** Un client du magasin retrouvé par son e-mail, sinon son téléphone (la liste des particuliers, gardée 15 min). */
function bdvClientExistant(string $shop, string $email, string $tel): ?int
{
    if (!function_exists('pclListe')) { return null; }
    try { $l = pclListe((int) $shop, 'b2c', true); } catch (Throwable $e) { return null; }
    $chiffres = static fn ($t) => ltrim(preg_replace('/\D+/', '', (string) $t), '0');
    foreach ((array) $l as $c) { if ($email !== '' && strtolower((string) ($c['email'] ?? '')) === $email) { return (int) $c['id']; } }
    $t = $chiffres($tel);
    foreach ((array) $l as $c) { $x = $chiffres($c['tel'] ?? ''); if ($t !== '' && strlen($x) >= 8 && (str_ends_with($t, $x) || str_ends_with($x, $t))) { return (int) $c['id']; } }
    return null;
}

/**
 * Le compte webshop de la personne (POST /clients/auth/register, route publique de TF Buddy : un compte actif tout de
 * suite, rattaché au magasin). AUCUN mot de passe ne vient du formulaire (le cockpit est servi en http) : un mot de passe
 * jetable, tiré ici, sert une fois à ouvrir la session (/clients/auth/login, pour rejoindre un bureau existant) et n'est
 * ni gardé ni journalisé ; puis TF Buddy envoie à la personne l'e-mail qui lui fait choisir le sien
 * (/clients/auth/password/reset/request, un lien d'une heure ; sinon « mot de passe oublié » au webshop).
 * Rend [etat: cree|existant|erreur, clientId, jeton d'accès (une session de 30 min, gardée le temps de la requête), erreur].
 */
function bdvCompteWebshop(string $shop, array $d): array
{
    if (!function_exists('tfbmPost')) { return ['erreur', null, null, 'le relais TF Buddy n’est pas chargé']; }
    $mdp = 'Bv' . bin2hex(random_bytes(12)) . '!7';
    [$c, $r] = tfbmPost('/clients/auth/register', ['id_main_shop' => (int) $shop, 'name' => $d['prenom'], 'surname' => $d['nom'], 'email' => $d['email'],
        'phone' => bdvE164($d['tel'], $d['pays'] ?? 'BE'), 'password' => $mdp, 'password_confirm' => $mdp, 'locale' => $d['langue'] ?? 'fr']);
    if ($c === 409) { return ['existant', bdvClientExistant($shop, $d['email'], $d['tel']), null, null]; }
    if ($c < 200 || $c >= 300) { return ['erreur', null, null, 'compte webshop non créé (HTTP ' . $c . ($c ? ' ' . tfbmCodeErreur($r) : '') . ')']; }
    [$c2, $r2] = tfbmPost('/clients/auth/login', ['login' => $d['email'], 'password' => $mdp]);
    unset($mdp);
    $jeton = $c2 >= 200 && $c2 < 300 && is_string($r2['access_token'] ?? null) ? $r2['access_token'] : null;
    $id = $jeton !== null ? bdvJwtClient($jeton) : null;
    tfbmPost('/clients/auth/password/reset/request', ['email' => $d['email']]);
    return ['cree', $id ?? bdvClientExistant($shop, $d['email'], $d['tel']), $jeton, $id === null ? 'compte créé, mais son numéro ne se lit pas' : null];
}

/** Rejoindre tout de suite le bureau actif de cette TVA dans ce magasin, avec la session de la personne : [id, nom] ou null. */
function bdvRejoindre(string $shop, string $tva, string $jeton): ?array
{
    if (!class_exists('PanelApi') || $tva === '') { return null; }
    $avant = PanelApi::$jetonImpose;
    PanelApi::$jetonImpose = $jeton;
    try {
        $r = PanelApi::requete('POST', '/webshop/offices/search', ['shop' => (int) $shop, 'tax_number' => $tva]);
        foreach ((array) ($r['data']['offices'] ?? $r['data']['data']['offices'] ?? []) as $o) {
            if ((int) ($o['id_shop'] ?? $shop) !== (int) $shop || (int) ($o['id'] ?? 0) <= 0) { continue; }
            $j = PanelApi::requete('POST', '/webshop/offices/' . (int) $o['id'] . '/join', ['shop' => (int) $shop, 'tax_number' => $tva]);
            if ($j['code'] >= 200 && $j['code'] < 300) { return [(int) $o['id'], (string) ($o['name'] ?? '')]; }
        }
    } catch (Throwable $e) { /* la demande reste à valider */ } finally { PanelApi::$jetonImpose = $avant; }
    return null;
}

/** La société B2B : celle que TF Buddy connaît déjà pour cette TVA, sinon créée. [id, erreur]. */
function bdvSociete(string $shop, array $d): array
{
    $l = pclTf('POST', '/shops/' . (int) $shop . '/clients/vat-lookup', ['vat_number' => $d['tva']]);
    $ex = is_array($l['corps'] ?? null) && !empty($l['corps']['client_exists']) ? bdvIdClient($l['corps']['data'] ?? null) : null;
    if ($ex !== null) { return [$ex, null]; }
    $rue = ($d['factRue'] ?? '') !== '' ? $d['factRue'] : $d['adresse'];
    $r = pclTf('POST', '/shops/' . (int) $shop . '/clients', array_filter(['vat_number' => $d['tva'], 'company_name' => $d['societe'], 'street' => $rue,
        'street_number' => $d['factNum'] ?? '', 'zip' => ($d['factCp'] ?? '') ?: $d['cp'], 'city' => ($d['factVille'] ?? '') ?: $d['ville'],
        'email' => ($d['emailFact'] ?? '') ?: $d['email'], 'phone' => $d['tel']], static fn ($v) => $v !== ''));
    $id = $r['code'] >= 200 && $r['code'] < 300 ? bdvIdClient($r['corps']) : null;
    return [$id, $id === null ? 'société non créée dans TF Buddy : ' . pclMotif($r) : null];
}

/**
 * Une demande enregistrée, puis, dans TF Buddy : la personne, la société (TVA), le bon. Au formulaire public, la personne
 * reçoit un compte webshop, et rejoint aussitôt le bureau de sa société s'il existe déjà (la demande est alors « rejoint »).
 * Ce qui échoue chez TF Buddy est gardé dans `erreurs` et n'empêche pas la demande : le magasin la voit et la reprend.
 */
function bdvCreer(string $shop, array $d, string $source, string $ipHash = '', int $promo = 0): array
{
    ensureBureauxDevTables();
    Db::exec('INSERT INTO panel_bureau_dev (shop_id, source, donnees, ip_hash, cree_le) VALUES (?, ?, ?, ?, NOW())',
        [$shop, $source, json_encode($d, JSON_UNESCAPED_UNICODE), $ipHash]);
    $id = (int) Db::pdo()->lastInsertId();
    $err = [];
    $clientId = null; $societeId = null; $rejoint = null;
    if (!function_exists('pclTf')) {
        $err[] = 'le relais TF Buddy n’est pas chargé';
    } elseif ($source === 'questionnaire') {
        [$etat, $clientId, $jeton, $e] = bdvCompteWebshop($shop, $d);
        $d['_compte'] = $etat;
        if ($e !== null) { $err[] = $e; }
        if ($etat === 'existant' && $clientId === null) { $err[] = 'compte webshop déjà existant (cet e-mail ou ce téléphone), pas retrouvé dans les clients du magasin : il sera invité par e-mail à la validation'; }
        if ($jeton !== null && $d['tva'] !== '') { $rejoint = bdvRejoindre($shop, $d['tva'], $jeton); }
        unset($jeton);
        if ($rejoint === null && $d['tva'] !== '') { [$societeId, $e] = bdvSociete($shop, $d); if ($e !== null) { $err[] = $e; } }
    } else {
        $r = pclTf('POST', '/shops/' . (int) $shop . '/clients', array_filter(['name' => $d['prenom'], 'surname' => $d['nom'], 'phone' => $d['tel'], 'email' => $d['email'],
            'comment' => 'Bureau en développement : ' . $d['bureau'] . ($d['fonction'] !== '' ? ' (' . $d['fonction'] . ')' : '')], static fn ($v) => $v !== ''));
        $clientId = $r['code'] >= 200 && $r['code'] < 300 ? bdvIdClient($r['corps']) : null;
        if ($clientId === null) { $err[] = 'personne non créée dans TF Buddy : ' . pclMotif($r); }
        if ($d['tva'] !== '') { [$societeId, $e] = bdvSociete($shop, $d); if ($e !== null) { $err[] = $e; } }
    }
    [$code, $texte, $eBon] = bdvBon((int) $shop, $promo, $clientId);
    if ($eBon !== null) { $err[] = 'bon : ' . $eBon; }
    Db::exec('UPDATE panel_bureau_dev SET donnees = ?, client_id = ?, societe_id = ?, bon_code = ?, bon_texte = ?, erreurs = ?, statut = ?, office_id = ?, traite_le = ? WHERE id = ?',
        [json_encode($d, JSON_UNESCAPED_UNICODE), $clientId, $societeId, $code, $texte !== null ? mb_substr($texte, 0, 255) : null, $err ? implode("\n", $err) : null,
            $rejoint !== null ? 'rejoint' : 'a_valider', $rejoint[0] ?? null, $rejoint !== null ? date('Y-m-d H:i:s') : null, $id]);
    if (function_exists('journalAdd')) {
        journalAdd('dashboard', 'bureaux', null, 'Magasin ' . $shop . ' : ' . ($rejoint !== null ? 'une personne rejoint le bureau « ' . $rejoint[1] . ' »' : 'bureau en développement « ' . $d['bureau'] . ' »')
            . ' (' . $source . ')' . ($err ? ' — ' . count($err) . ' souci(s) TF Buddy' : ''));
    }
    $l = bdvLigne(Db::row('SELECT * FROM panel_bureau_dev WHERE id = ?', [$id]) ?? []);
    return $l + ['rejoint' => $rejoint !== null ? $rejoint[1] : null];
}

/** Les vieilles demandes traitées s'effacent (les données de la personne ne restent pas au cockpit). */
function bdvPurger(): void
{
    try { Db::exec('DELETE FROM panel_bureau_dev WHERE statut <> "a_valider" AND traite_le < DATE_SUB(NOW(), INTERVAL ' . BDV_GARDE_JOURS . ' DAY)'); } catch (Throwable $e) { /* la suivante purgera */ }
}

/* ── Les routes publiques (sans session : le jeton du questionnaire est la clé) ─────────────────────────────── */

function bdvPublic(string $method, string $jeton, string $sous = ''): array
{
    ensureBureauxDevTables();
    if (!preg_match('/^[a-f0-9]{32}$/', $jeton)) { http_response_code(404); return ['error' => 'questionnaire introuvable']; }
    $q = Db::row('SELECT * FROM panel_bureau_questionnaire WHERE jeton = ?', [$jeton]);
    if ($q === null || !(int) $q['actif']) { http_response_code(404); return ['error' => 'ce questionnaire n’est plus ouvert']; }
    $shop = (string) $q['shop_id'];
    $nom = function_exists('accesNomMagasin') ? accesNomMagasin($shop) : 'Magasin ' . $shop;
    $promo = (int) ($q['promo'] ?? 0);
    if ($method === 'GET') {
        $bon = null; $bonD = null;
        if ($promo > 0 && function_exists('pmMienne')) {
            try { $p = pmMienne((int) $shop, $promo); if ($p) { $bon = trim($p['nom'] . ($p['description'] !== '' ? ' — ' . $p['description'] : '')); $bonD = ['nom' => (string) $p['nom'], 'description' => (string) ($p['description'] ?? '')]; } } catch (Throwable $e) { $bon = null; }
        }
        return ['magasin' => $nom, 'shop' => (int) $shop, 'titre' => (string) $q['titre'], 'message' => (string) ($q['message'] ?? ''), 'bon' => $bon, 'bonDetail' => $bonD];
    }
    $in = function_exists('body') ? body() : [];
    if ($sous === 'tva') { return bdvPublicTva((string) $shop, $in['tva'] ?? '', $jeton); }
    // Le piège : un champ que personne ne voit ; rempli, c'est un robot (réponse polie, rien d'enregistré).
    if (bdvTexte($in['site_web'] ?? '') !== '') { return ['ok' => true, 'bon' => null]; }
    $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $jeton);
    $n = (int) (Db::row('SELECT COUNT(*) n FROM panel_bureau_dev WHERE ip_hash = ? AND cree_le > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$ip])['n'] ?? 0);
    if ($n >= BDV_MAX_HEURE) { http_response_code(429); return ['error' => 'Trop de réponses depuis cet appareil : réessayez dans une heure.']; }
    $j = (int) (Db::row('SELECT COUNT(*) n FROM panel_bureau_dev WHERE shop_id = ? AND source = "questionnaire" AND cree_le > DATE_SUB(NOW(), INTERVAL 1 DAY)', [$shop])['n'] ?? 0);
    if ($j >= BDV_MAX_JOUR) { http_response_code(429); return ['error' => 'Le questionnaire a reçu beaucoup de réponses aujourd’hui : réessayez demain, ou appelez le magasin.']; }
    [$d, $e] = bdvNettoyer($in, true);
    if ($e) { http_response_code(422); return ['error' => 'Il manque ' . implode(', ', $e) . '.', 'manque' => $e]; }
    bdvPurger();
    $l = bdvCreer($shop, $d, 'questionnaire', $ip, $promo);
    return ['ok' => true, 'magasin' => $nom, 'bon' => $l['bon'], 'compte' => $l['compte'] ?: null, 'rejoint' => $l['rejoint'], 'bureau' => $d['bureau']];
}

/**
 * La TVA vérifiée pour le formulaire public (POST …/tva {tva}) : valide ou non, le nom et le siège, pour préremplir.
 * Rien d'autre ne sort (ni si la société est déjà cliente, ni ses contacts) ; 20 vérifications par heure et par appareil.
 */
function bdvPublicTva(string $shop, $tvaIn, string $jeton): array
{
    $tva = bdvTva($tvaIn);
    if ($tva === '') { http_response_code(422); return ['error' => 'Un numéro de TVA a la forme BE0123456789.']; }
    $f = sys_get_temp_dir() . '/cockpit-bdv-tva-' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $jeton);
    $t = array_values(array_filter(array_map('intval', explode(',', (string) @file_get_contents($f))), static fn ($x) => $x > time() - 3600));
    if (count($t) >= 20) { http_response_code(429); return ['error' => 'Trop de vérifications : remplissez les champs vous-même.']; }
    $t[] = time();
    @file_put_contents($f, implode(',', $t), LOCK_EX);
    if (!function_exists('pclTf')) { return ['tva' => $tva, 'verifiee' => false]; }
    $v = pclTf('POST', '/vies/lookup', ['vat_number' => $tva]);
    $c = is_array($v['corps'] ?? null) ? $v['corps'] : [];
    if (($v['code'] < 200 || $v['code'] >= 300) && $v['code'] !== 404) { return ['tva' => $tva, 'verifiee' => false]; }
    $x = is_array($c['data'] ?? null) ? $c['data'] : [];
    $txt = static fn ($k) => is_scalar($x[$k] ?? null) ? bdvTexte($x[$k], 120) : '';
    return ['tva' => $tva, 'verifiee' => true, 'valide' => !empty($c['valid']), 'societe' => $txt('name') ?: $txt('company_name'),
        'rue' => $txt('street'), 'numero' => $txt('street_number'), 'cp' => $txt('zip') ?: $txt('postal_code'), 'ville' => $txt('city')];
}

/** Le magasin d'un lien court (« ?m=2 ») : son numéro, s'il est un magasin ouvert du réseau. $magasins : [id => nom]. */
function bdvMagasinDuLien(string $id, ?array $magasins = null): ?string
{
    if (!preg_match('/^[1-9]\d{0,5}$/', $id)) { return null; }
    $magasins ??= function_exists('accesMagasins') ? accesMagasins() : [];
    return array_key_exists($id, $magasins) ? $id : null;
}

/** La route publique du lien court : le questionnaire du magasin, créé ouvert s'il n'existe pas encore. */
function bdvPublicMagasin(string $method, string $id, string $sous = ''): array
{
    $shop = bdvMagasinDuLien($id);
    if ($shop === null) { http_response_code(404); return ['error' => 'questionnaire introuvable']; }
    $q = bdvQuestionnaire($shop, true);
    return bdvPublic($method, (string) $q['jeton'], $sous);
}

/* ── Les routes du dashboard ────────────────────────────────────────────────────────────────────────────────── */

function bdvShop(?array $b = null): string
{
    $s = (string) ($b !== null ? ($b['shop'] ?? '') : ($_GET['shop'] ?? ''));
    return preg_match('/^\d{1,6}$/', $s) ? $s : '';
}

/** Les magasins dont le générateur de liens peut donner le lien : tous pour le siège (ou l'accès ouvert), aucun sinon (le sien suffit). */
function bdvMagasinsDuLien(): array
{
    if (!function_exists('accesMagasins')) { return []; }
    $siege = (function_exists('accesMode') && accesMode() === 'ouvert') || (function_exists('accesIdentite') && (accesIdentite()['role'] ?? '') === 'siege');
    if (!$siege) { return []; }
    $l = [];
    foreach (accesMagasins() as $id => $nom) { $l[] = ['id' => (string) $id, 'nom' => (string) $nom]; }
    return $l;
}

function ep_bureaux_dev(): array
{
    $shop = bdvShop();
    if ($shop === '') { http_response_code(400); return ['error' => 'shop manquant']; }
    $q = bdvQuestionnaire($shop, true);
    $rows = Db::rows('SELECT * FROM panel_bureau_dev WHERE shop_id = ? ORDER BY statut = "a_valider" DESC, id DESC LIMIT 200', [$shop]);
    return ['shop' => $shop, 'demandes' => array_map('bdvLigne', $rows),
        'questionnaire' => ['actif' => (bool) (int) $q['actif'], 'jeton' => (string) $q['jeton'], 'promo' => $q['promo'] !== null ? (int) $q['promo'] : null,
            'titre' => (string) $q['titre'], 'message' => (string) ($q['message'] ?? '')],
        'promos' => bdvPromosABon((int) $shop), 'lienCourt' => bdvMagasinDuLien($shop), 'magasins' => bdvMagasinsDuLien()];
}

function wr_bureaux_dev_creer(): array
{
    $b = body();
    $shop = bdvShop($b);
    if ($shop === '') { http_response_code(400); return ['error' => 'shop manquant']; }
    [$d, $e] = bdvNettoyer(is_array($b['donnees'] ?? null) ? $b['donnees'] : [], false);
    if ($e) { http_response_code(422); return ['error' => 'Il manque ' . implode(', ', $e) . '.']; }
    $q = bdvQuestionnaire($shop, true);
    $promo = !empty($b['bon']) ? (int) ($q['promo'] ?? 0) : 0;
    return ['ok' => true, 'demande' => bdvCreer($shop, $d, 'dashboard', '', $promo)];
}

function wr_bureaux_dev_questionnaire(): array
{
    $b = body();
    $shop = bdvShop($b);
    if ($shop === '') { http_response_code(400); return ['error' => 'shop manquant']; }
    bdvQuestionnaire($shop, true);
    $promo = isset($b['promo']) && (int) $b['promo'] > 0 ? (int) $b['promo'] : null;
    if ($promo !== null && !in_array($promo, array_column(bdvPromosABon((int) $shop), 'id'), true)) { http_response_code(422); return ['error' => 'cette promotion n’a pas de bon dans ce magasin']; }
    Db::exec('UPDATE panel_bureau_questionnaire SET actif = ?, promo = ?, titre = ?, message = ?, maj_le = NOW() WHERE shop_id = ?',
        [!empty($b['actif']) ? 1 : 0, $promo, bdvTexte($b['titre'] ?? '', 120) ?: 'Du frais au bureau', bdvTexte($b['message'] ?? '', 600), $shop]);
    if (!empty($b['regenerer'])) { Db::exec('UPDATE panel_bureau_questionnaire SET jeton = ? WHERE shop_id = ?', [bin2hex(random_bytes(16)), $shop]); }
    return ['ok' => true] + ep_bureaux_dev_pour($shop);
}

function ep_bureaux_dev_pour(string $shop): array
{
    $_GET['shop'] = $shop;
    return ep_bureaux_dev();
}

function bdvDemande(string $shop, int $id): ?array
{
    ensureBureauxDevTables();
    return Db::row('SELECT * FROM panel_bureau_dev WHERE id = ? AND shop_id = ?', [$id, $shop]);
}

/**
 * Le corps de POST /panel/shops/{s}/offices/onboard depuis une demande (schéma de TF Buddy, 26/11/2026) : le bureau
 * (facturation `company` ou `person`, secteur, remise à la livraison, créneau, jours, logo), le contact, le site, les
 * services, les collègues (invités par e-mail : send_membership) ; la personne elle-même dans `staff` si son id de client
 * n'est pas connu (son compte existait ailleurs). `request_key` : une validation rejouée ne crée pas un second bureau.
 */
function bdvCorpsOnboard(array $d, string $nom, string $tva, int $societeId, string $statut, ?int $route, ?int $clientId, int $id): array
{
    $personne = trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));
    $staff = array_map(static fn ($c) => ['name' => (string) ($c['nom'] ?? '') ?: (string) $c['email'], 'email' => (string) $c['email']], (array) ($d['collegues'] ?? []));
    if ($clientId === null && ($d['email'] ?? '') !== '') { array_unshift($staff, ['name' => $personne, 'email' => (string) $d['email']]); }
    $notes = implode(' · ', array_filter([($d['remarque'] ?? '') !== '' ? (string) $d['remarque'] : '', ($d['personnes'] ?? 0) > 0 ? $d['personnes'] . ' personnes sur place' : '',
        ($d['bonCommande'] ?? '') !== '' ? 'bon de commande : ' . $d['bonCommande'] : '']));
    return [
        'office' => array_filter(['id_company_client' => $societeId, 'name' => $nom, 'tax_number' => $tva, 'status' => $statut,
            'billing_mode' => ($d['paiement'] ?? 'person') === 'company' ? 'company' : 'person', 'show_prices' => true,
            'segment' => ($d['secteur'] ?? '') !== '' ? $d['secteur'] : null, 'delivery_validation' => ($d['remise'] ?? '') !== '' ? $d['remise'] : null,
            'delivery_window_start' => ($d['de'] ?? '') !== '' ? $d['de'] : null, 'delivery_window_end' => ($d['a'] ?? '') !== '' ? $d['a'] : null,
            'delivery_days' => ($d['jours'] ?? []) ?: [1, 2, 3, 4, 5], 'contact_person' => $personne, 'contact_email' => (string) ($d['email'] ?? ''),
            'logo_data_url' => ($d['logo'] ?? '') !== '' ? $d['logo'] : null], static fn ($v) => $v !== null),
        'contact' => ['name' => $personne, 'email' => (string) ($d['email'] ?? ''), 'phone' => bdvE164((string) ($d['tel'] ?? ''), (string) ($d['pays'] ?? 'BE')), 'function' => (string) ($d['fonction'] ?? ''), 'role' => 'contact'],
        'sites' => ($d['adresse'] ?? '') !== '' ? [array_filter(['name' => $nom, 'address' => (string) $d['adresse'], 'postal_code' => (string) ($d['cp'] ?? ''), 'city' => (string) ($d['ville'] ?? ''),
            'country' => (string) ($d['pays'] ?? 'BE'), 'building' => (string) ($d['batiment'] ?? ''), 'floor' => (string) ($d['etage'] ?? ''), 'notes' => $notes,
            'route_id' => $route, 'is_default' => true, 'active' => true], static fn ($v) => $v !== null && $v !== '')] : [],
        'departments' => array_map(static fn ($n) => ['name' => (string) $n, 'active' => true], (array) ($d['services'] ?? [])),
        'staff' => $staff, 'voucher_ids' => [], 'request_key' => 'bdv-' . $id, 'send_summary' => false, 'send_membership' => $staff !== [], 'locale' => (string) ($d['langue'] ?? 'fr'),
    ];
}

/**
 * Valider une demande : le bureau créé dans TF Buddy (POST /panel/shops/{s}/offices/onboard), avec la société (créée
 * ici si la TVA arrive à la validation), le contact, le site, puis la personne reliée (members). Le compte TF Buddy du
 * magasin doit être connecté : ces routes n'ouvrent qu'à lui.
 */
function wr_bureaux_dev_valider(): array
{
    $b = body();
    $shop = bdvShop($b);
    $r = bdvDemande($shop, (int) ($b['id'] ?? 0));
    if ($r === null) { http_response_code(404); return ['error' => 'demande introuvable']; }
    if ($r['statut'] !== 'a_valider') { http_response_code(409); return ['error' => 'cette demande est déjà traitée']; }
    $d = json_decode((string) $r['donnees'], true) ?: [];
    $statut = ($b['statut'] ?? '') === 'pending' ? 'pending' : 'active';
    $nom = bdvTexte($b['nom'] ?? '') ?: (string) ($d['bureau'] ?? $d['societe'] ?? 'Bureau');
    $tva = bdvTva($b['tva'] ?? '') ?: (string) ($d['tva'] ?? '');
    $societeId = $r['societe_id'] !== null ? (int) $r['societe_id'] : null;
    if ($societeId === null) {
        if ($tva === '') { http_response_code(422); return ['error' => 'TF Buddy demande une société B2B pour créer un bureau : donnez son numéro de TVA.']; }
        [$societeId, $e] = bdvSociete($shop, ['tva' => $tva] + $d + ['societe' => '', 'adresse' => '', 'cp' => '', 'ville' => '', 'email' => '', 'tel' => '']);
        if ($societeId === null) { http_response_code(502); return ['error' => ucfirst((string) $e) . '.']; }
        Db::exec('UPDATE panel_bureau_dev SET societe_id = ? WHERE id = ?', [$societeId, (int) $r['id']]);
    }
    $route = isset($b['route_id']) && (int) $b['route_id'] > 0 ? (int) $b['route_id'] : null;
    $corps = bdvCorpsOnboard($d, $nom, $tva, (int) $societeId, $statut, $route, $r['client_id'] !== null ? (int) $r['client_id'] : null, (int) $r['id']);
    $w = pclTf('POST', '/panel/shops/' . (int) $shop . '/offices/onboard', $corps);
    if ($w['code'] < 200 || $w['code'] >= 300) {
        http_response_code($w['refus'] ? 403 : 502);
        return ['error' => $w['refus'] ? 'Le bureau se crée avec le compte TF Buddy du magasin : connectez-le (Clients, en haut à droite), puis validez.' : 'TF Buddy refuse le bureau (' . pclMotif($w) . ').', 'refus' => $w['refus']];
    }
    $c = is_array($w['corps']) ? $w['corps'] : [];
    $officeId = (int) ($c['office']['id'] ?? $c['data']['office']['id'] ?? $c['id'] ?? $c['data']['id'] ?? 0) ?: null;
    $notes = [];
    if ($officeId !== null && $r['client_id'] !== null) {
        $m = pclTf('POST', '/panel/shops/' . (int) $shop . '/offices/' . $officeId . '/members', ['client_id' => (int) $r['client_id']]);
        if ($m['code'] < 200 || $m['code'] >= 300) { $notes[] = 'la personne n’a pas été reliée au bureau (' . pclMotif($m) . ')'; }
    }
    $fact = (string) ($d['emailFact'] ?? '');
    if ($officeId !== null && $fact !== '' && $fact !== (string) ($d['email'] ?? '')) {
        $m = pclTf('POST', '/panel/shops/' . (int) $shop . '/offices/' . $officeId . '/contacts', ['name' => 'Facturation', 'email' => $fact, 'role' => 'billing', 'active' => true]);
        if ($m['code'] < 200 || $m['code'] >= 300) { $notes[] = 'le contact de facturation n’a pas été ajouté (' . pclMotif($m) . ')'; }
    }
    Db::exec('UPDATE panel_bureau_dev SET statut = "cree", office_id = ?, traite_le = NOW(), erreurs = CONCAT(COALESCE(erreurs, ""), ?) WHERE id = ?',
        [$officeId, $notes ? "\n" . implode("\n", $notes) : '', (int) $r['id']]);
    if (function_exists('journalAdd')) { journalAdd('dashboard', 'bureaux', null, 'Magasin ' . $shop . ' : bureau « ' . $nom . ' » créé dans TF Buddy (' . $statut . ') depuis une demande n° ' . (int) $r['id']); }
    return ['ok' => true, 'officeId' => $officeId, 'statut' => $statut, 'notes' => $notes];
}

function wr_bureaux_dev_refuser(): array
{
    $b = body();
    $shop = bdvShop($b);
    $r = bdvDemande($shop, (int) ($b['id'] ?? 0));
    if ($r === null) { http_response_code(404); return ['error' => 'demande introuvable']; }
    Db::exec('UPDATE panel_bureau_dev SET statut = "refuse", traite_le = NOW() WHERE id = ?', [(int) $r['id']]);
    return ['ok' => true];
}

function bdvRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/bureaux-dev' && !str_starts_with($path, '/dashboard/bureaux-dev/')) { return null; }
    ensureBureauxDevTables();
    if ($method === 'GET' && $path === '/dashboard/bureaux-dev') { return ep_bureaux_dev(); }
    if ($method === 'POST') {
        switch ($path) {
            case '/dashboard/bureaux-dev': return wr_bureaux_dev_creer();
            case '/dashboard/bureaux-dev/questionnaire': return wr_bureaux_dev_questionnaire();
            case '/dashboard/bureaux-dev/valider': return wr_bureaux_dev_valider();
            case '/dashboard/bureaux-dev/refuser': return wr_bureaux_dev_refuser();
        }
    }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
