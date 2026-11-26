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
const BDV_STATUTS = ['a_valider', 'cree', 'refuse'];

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

/**
 * Une réponse au questionnaire (ou une saisie du dashboard), rendue propre : [données, erreurs]. Obligatoires : le prénom,
 * le nom, un e-mail valide, un téléphone, le nom de la société et l'adresse du bureau ; au questionnaire, l'accord.
 */
function bdvNettoyer(array $in, bool $public): array
{
    $d = [
        'prenom' => bdvTexte($in['prenom'] ?? ''), 'nom' => bdvTexte($in['nom'] ?? ''), 'fonction' => bdvTexte($in['fonction'] ?? ''),
        'email' => strtolower(bdvTexte($in['email'] ?? '', 160)), 'tel' => bdvTel($in['tel'] ?? ''),
        'societe' => bdvTexte($in['societe'] ?? ''), 'tva' => bdvTva($in['tva'] ?? ''),
        'adresse' => bdvTexte($in['adresse'] ?? '', 160), 'cp' => bdvTexte($in['cp'] ?? '', 12), 'ville' => bdvTexte($in['ville'] ?? '', 80),
        'personnes' => max(0, min(5000, (int) ($in['personnes'] ?? 0))),
        'jours' => array_values(array_unique(array_filter(array_map('intval', (array) ($in['jours'] ?? [])), static fn ($j) => $j >= 1 && $j <= 7))),
        'remarque' => bdvTexte($in['remarque'] ?? '', 600), 'bureau' => bdvTexte($in['bureau'] ?? ''),
    ];
    sort($d['jours']);
    $e = [];
    if ($d['prenom'] === '' || $d['nom'] === '') { $e[] = 'votre prénom et votre nom'; }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { $e[] = 'un e-mail valide'; }
    if ($d['tel'] === '') { $e[] = 'un numéro de téléphone'; }
    if ($d['societe'] === '') { $e[] = 'le nom de la société'; }
    if ($d['adresse'] === '' || $d['ville'] === '') { $e[] = 'l’adresse du bureau (rue et ville)'; }
    if (bdvTexte($in['tva'] ?? '') !== '' && $d['tva'] === '') { $e[] = 'un numéro de TVA valide (BE0123456789), ou rien'; }
    if ($public && empty($in['accord'])) { $e[] = 'votre accord pour être recontacté'; }
    if ($d['bureau'] === '') { $d['bureau'] = $d['societe']; }
    return [$d, $e];
}

/** Le texte d'une demande pour la liste du dashboard (sans le téléphone ni l'e-mail entiers). */
function bdvLigne(array $r): array
{
    $d = json_decode((string) ($r['donnees'] ?? '{}'), true) ?: [];
    return ['id' => (int) $r['id'], 'source' => (string) $r['source'], 'statut' => (string) $r['statut'], 'le' => substr((string) $r['cree_le'], 0, 16),
        'bureau' => (string) ($d['bureau'] ?? ''), 'societe' => (string) ($d['societe'] ?? ''), 'tva' => (string) ($d['tva'] ?? ''),
        'personne' => trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? '')), 'fonction' => (string) ($d['fonction'] ?? ''), 'email' => (string) ($d['email'] ?? ''), 'tel' => (string) ($d['tel'] ?? ''),
        'adresse' => (string) ($d['adresse'] ?? ''), 'cp' => (string) ($d['cp'] ?? ''), 'ville' => (string) ($d['ville'] ?? ''),
        'personnes' => (int) ($d['personnes'] ?? 0), 'jours' => (array) ($d['jours'] ?? []), 'remarque' => (string) ($d['remarque'] ?? ''),
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

/**
 * Une demande enregistrée, puis, dans TF Buddy : la personne, la société (TVA), le bon. Rend la ligne de la demande.
 * Ce qui échoue chez TF Buddy est gardé dans `erreurs` et n'empêche pas la demande : le magasin la voit et la reprend.
 */
function bdvCreer(string $shop, array $d, string $source, string $ipHash = '', int $promo = 0): array
{
    ensureBureauxDevTables();
    Db::exec('INSERT INTO panel_bureau_dev (shop_id, source, donnees, ip_hash, cree_le) VALUES (?, ?, ?, ?, NOW())',
        [$shop, $source, json_encode($d, JSON_UNESCAPED_UNICODE), $ipHash]);
    $id = (int) Db::pdo()->lastInsertId();
    $err = [];
    $clientId = null; $societeId = null;
    if (function_exists('pclTf')) {
        $r = pclTf('POST', '/shops/' . (int) $shop . '/clients', array_filter(['name' => $d['prenom'], 'surname' => $d['nom'], 'phone' => $d['tel'], 'email' => $d['email'],
            'comment' => 'Bureau en développement : ' . $d['bureau'] . ($d['fonction'] !== '' ? ' (' . $d['fonction'] . ')' : '')], static fn ($v) => $v !== ''));
        $clientId = $r['code'] >= 200 && $r['code'] < 300 ? bdvIdClient($r['corps']) : null;
        if ($clientId === null) { $err[] = 'personne non créée dans TF Buddy : ' . pclMotif($r); }
        if ($d['tva'] !== '') {
            $r = pclTf('POST', '/shops/' . (int) $shop . '/clients', array_filter(['vat_number' => $d['tva'], 'company_name' => $d['societe'], 'street' => $d['adresse'],
                'zip' => $d['cp'], 'city' => $d['ville'], 'email' => $d['email'], 'phone' => $d['tel']], static fn ($v) => $v !== ''));
            $societeId = $r['code'] >= 200 && $r['code'] < 300 ? bdvIdClient($r['corps']) : null;
            if ($societeId === null) { $err[] = 'société non créée dans TF Buddy : ' . pclMotif($r); }
        }
    } else {
        $err[] = 'le relais TF Buddy n’est pas chargé';
    }
    [$code, $texte, $eBon] = bdvBon((int) $shop, $promo, $clientId);
    if ($eBon !== null) { $err[] = 'bon : ' . $eBon; }
    Db::exec('UPDATE panel_bureau_dev SET client_id = ?, societe_id = ?, bon_code = ?, bon_texte = ?, erreurs = ? WHERE id = ?',
        [$clientId, $societeId, $code, $texte !== null ? mb_substr($texte, 0, 255) : null, $err ? implode("\n", $err) : null, $id]);
    if (function_exists('journalAdd')) { journalAdd('dashboard', 'bureaux', null, 'Magasin ' . $shop . ' : bureau en développement « ' . $d['bureau'] . ' » (' . $source . ')' . ($err ? ' — ' . count($err) . ' souci(s) TF Buddy' : '')); }
    return bdvLigne(Db::row('SELECT * FROM panel_bureau_dev WHERE id = ?', [$id]) ?? []);
}

/** Les vieilles demandes traitées s'effacent (les données de la personne ne restent pas au cockpit). */
function bdvPurger(): void
{
    try { Db::exec('DELETE FROM panel_bureau_dev WHERE statut <> "a_valider" AND traite_le < DATE_SUB(NOW(), INTERVAL ' . BDV_GARDE_JOURS . ' DAY)'); } catch (Throwable $e) { /* la suivante purgera */ }
}

/* ── Les routes publiques (sans session : le jeton du questionnaire est la clé) ─────────────────────────────── */

function bdvPublic(string $method, string $jeton): array
{
    ensureBureauxDevTables();
    if (!preg_match('/^[a-f0-9]{32}$/', $jeton)) { http_response_code(404); return ['error' => 'questionnaire introuvable']; }
    $q = Db::row('SELECT * FROM panel_bureau_questionnaire WHERE jeton = ?', [$jeton]);
    if ($q === null || !(int) $q['actif']) { http_response_code(404); return ['error' => 'ce questionnaire n’est plus ouvert']; }
    $shop = (string) $q['shop_id'];
    $nom = function_exists('accesNomMagasin') ? accesNomMagasin($shop) : 'Magasin ' . $shop;
    $promo = (int) ($q['promo'] ?? 0);
    if ($method === 'GET') {
        $bon = null;
        if ($promo > 0 && function_exists('pmMienne')) { try { $p = pmMienne((int) $shop, $promo); if ($p) { $bon = trim($p['nom'] . ($p['description'] !== '' ? ' — ' . $p['description'] : '')); } } catch (Throwable $e) { $bon = null; } }
        return ['magasin' => $nom, 'titre' => (string) $q['titre'], 'message' => (string) ($q['message'] ?? ''), 'bon' => $bon];
    }
    $in = function_exists('body') ? body() : [];
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
    return ['ok' => true, 'magasin' => $nom, 'bon' => $l['bon']];
}

/** Le magasin d'un lien court (« ?m=2 ») : son numéro, s'il est un magasin ouvert du réseau. $magasins : [id => nom]. */
function bdvMagasinDuLien(string $id, ?array $magasins = null): ?string
{
    if (!preg_match('/^[1-9]\d{0,5}$/', $id)) { return null; }
    $magasins ??= function_exists('accesMagasins') ? accesMagasins() : [];
    return array_key_exists($id, $magasins) ? $id : null;
}

/** La route publique du lien court : le questionnaire du magasin, créé ouvert s'il n'existe pas encore. */
function bdvPublicMagasin(string $method, string $id): array
{
    $shop = bdvMagasinDuLien($id);
    if ($shop === null) { http_response_code(404); return ['error' => 'questionnaire introuvable']; }
    $q = bdvQuestionnaire($shop, true);
    return bdvPublic($method, (string) $q['jeton']);
}

/* ── Les routes du dashboard ────────────────────────────────────────────────────────────────────────────────── */

function bdvShop(?array $b = null): string
{
    $s = (string) ($b !== null ? ($b['shop'] ?? '') : ($_GET['shop'] ?? ''));
    return preg_match('/^\d{1,6}$/', $s) ? $s : '';
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
        'promos' => bdvPromosABon((int) $shop), 'lienCourt' => bdvMagasinDuLien($shop)];
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
        $w = pclTf('POST', '/shops/' . (int) $shop . '/clients', array_filter(['vat_number' => $tva, 'company_name' => (string) ($d['societe'] ?? ''), 'street' => (string) ($d['adresse'] ?? ''),
            'zip' => (string) ($d['cp'] ?? ''), 'city' => (string) ($d['ville'] ?? '')], static fn ($v) => $v !== ''));
        $societeId = $w['code'] >= 200 && $w['code'] < 300 ? bdvIdClient($w['corps']) : null;
        if ($societeId === null) { http_response_code(502); return ['error' => 'la société ne se crée pas dans TF Buddy (' . pclMotif($w) . ').']; }
        Db::exec('UPDATE panel_bureau_dev SET societe_id = ? WHERE id = ?', [$societeId, (int) $r['id']]);
    }
    $route = isset($b['route_id']) && (int) $b['route_id'] > 0 ? (int) $b['route_id'] : null;
    $corps = [
        'office' => ['id_company_client' => $societeId, 'name' => $nom, 'tax_number' => $tva, 'status' => $statut, 'billing_mode' => 'person', 'show_prices' => true,
            'delivery_days' => $d['jours'] ?? [1, 2, 3, 4, 5]],
        'contact' => ['name' => trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? '')), 'email' => (string) ($d['email'] ?? ''), 'phone' => (string) ($d['tel'] ?? ''), 'function' => (string) ($d['fonction'] ?? ''), 'role' => 'contact'],
        'sites' => ($d['adresse'] ?? '') !== '' ? [['name' => $nom, 'address' => (string) $d['adresse'], 'postal_code' => (string) ($d['cp'] ?? ''), 'city' => (string) ($d['ville'] ?? ''),
            'country' => 'BE', 'route_id' => $route, 'is_default' => true, 'active' => true]] : [],
        'departments' => [], 'staff' => [], 'voucher_ids' => [], 'request_key' => 'bdv-' . (int) $r['id'], 'send_summary' => false, 'send_membership' => false, 'locale' => 'fr',
    ];
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
