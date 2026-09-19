<?php
declare(strict_types=1);

/**
 * Newsletter & campagnes — la marque et ses franchisés écrivent aux clients
 * captés en boutique (email + SMS), avec segments, vouchers, déclinaison sur
 * les réseaux, automatisations et droits marque / franchisé.
 *
 * MODE TEST : ce module ne fait PARTIR AUCUN ENVOI. Les campagnes programmées
 * sont enregistrées avec leur date, l'envoi réel (lots de 100 par minute,
 * vouchers Stripe, SMS) sera branché quand la marque décidera de dispatcher.
 * Les bases de données et leurs comptes sont des JEUX D'ESSAI tant que les
 * vraies bases clients (tfb_customers, tfb_office_clients, tfb_b2b_accounts,
 * tfb_crm_candidates) ne sont pas raccordées : la réponse le dit (`test`).
 *
 * Deux vues, un seul contrat :
 *  - la MARQUE (cockpit, rôle `brand`) : tout — segments, modèles,
 *    automatisations, adresses d'expéditeur, autoriser ou bloquer l'envoi
 *    par franchisé ;
 *  - le FRANCHISÉ (dashboard magasin, rôle = id du magasin) : envoie une
 *    campagne, si autorisé, à ses propres segments (son magasin ou « Tous »),
 *    depuis l'adresse de son magasin uniquement ; modifie son adresse ; ne
 *    crée ni segment ni automatisation. Bloqué par la marque : lecture seule.
 *
 * Le rôle est porté par la requête (`role`) comme le dashboard porte son
 * `shop` : le serveur applique les droits du rôle demandé, il ne les élargit
 * jamais — un franchisé ne peut pas agir au nom d'un autre magasin.
 */

const NL_LANGUES = ['fr', 'nl', 'en'];
const NL_DECLENCHEURS = ['dormant45', 'third', 'birthday', 'firstOffice', 'season'];
const NL_MODELES = ['produit', 'happy', 'fidelite', 'gagne', 'office', 'blank'];
const NL_STATUTS = ['draft', 'sched', 'live', 'sent'];

/* ---------------------------------------------------------------------------
 * Tables (idempotent, à chaque démarrage comme le reste de l'installateur)
 * ------------------------------------------------------------------------- */
function ensureNewsletter(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_nl_magasin ('
        . 'shop_id VARCHAR(16) PRIMARY KEY,'          // « brand » ou l'id du magasin
        . 'nom VARCHAR(120) NOT NULL,'
        . 'sender_name VARCHAR(120) NOT NULL,'
        . 'sender_email VARCHAR(190) NOT NULL DEFAULT "",'
        . 'domain_status VARCHAR(12) NOT NULL DEFAULT "unverified",'   // verified | warmup | unverified
        . 'can_send TINYINT NOT NULL DEFAULT 1,'
        . 'sort_order INT NOT NULL DEFAULT 0,'
        . 'updated_at DATETIME NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_nl_source ('
        . 'id VARCHAR(16) PRIMARY KEY,'
        . 'table_ref VARCHAR(60) NOT NULL,'
        . 'nom_json TEXT NOT NULL,'                  // {fr, nl, en}
        . 'total INT NOT NULL DEFAULT 0,'
        . 'optin INT NOT NULL DEFAULT 0,'
        . 'sort_order INT NOT NULL DEFAULT 0'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_nl_segment ('
        . 'id VARCHAR(40) PRIMARY KEY,'
        . 'source_id VARCHAR(16) NOT NULL,'
        . 'shop_id VARCHAR(16) NOT NULL DEFAULT "",'  // "" = tous les magasins
        . 'nom_json TEXT NOT NULL,'                  // {fr, nl, en} ou chaîne
        . 'regle_json TEXT NOT NULL,'                // libellé {fr, nl, en} ou chaîne
        . 'rules_json TEXT NULL,'                    // {magasin, produit, periode, panier_min}
        . 'total INT NOT NULL DEFAULT 0,'
        . 'split_fr INT NOT NULL DEFAULT 0,'
        . 'split_nl INT NOT NULL DEFAULT 0,'
        . 'split_en INT NOT NULL DEFAULT 0,'
        . 'created_by VARCHAR(16) NOT NULL DEFAULT "brand",'
        . 'created_at DATETIME NULL,'
        . 'sort_order INT NOT NULL DEFAULT 0'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_nl_campagne ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'nom VARCHAR(160) NOT NULL,'
        . 'source_id VARCHAR(16) NOT NULL,'
        . 'segment_id VARCHAR(40) NOT NULL,'
        . 'langues VARCHAR(12) NOT NULL DEFAULT "fr",'       // fr,nl,en
        . 'template_id VARCHAR(16) NOT NULL DEFAULT "blank",'
        . 'channel VARCHAR(8) NOT NULL DEFAULT "email",'      // email | sms | both
        . 'subjects_json TEXT NULL,'                           // {lang: objet}
        . 'bodies_json MEDIUMTEXT NULL,'                       // {lang: corps}
        . 'sms_json TEXT NULL,'                                // {lang: sms}
        . 'social_json TEXT NULL,'                             // {linkedin:{on,text,reviewed}, …}
        . 'send_mode VARCHAR(8) NOT NULL DEFAULT "manual",'   // manual | auto
        . 'trigger_rule VARCHAR(24) NULL,'
        . 'send_at DATETIME NULL,'
        . 'max_vouchers INT NULL,'
        . 'sender_shop_id VARCHAR(16) NOT NULL DEFAULT "brand",'
        . 'statut VARCHAR(8) NOT NULL DEFAULT "sched",'        // draft | sched | live | sent
        . 'test_sent TINYINT NOT NULL DEFAULT 0,'
        . 'stats_json TEXT NULL,'                              // envoyés, ouvertures, clics, vouchers, revenu…
        . 'exemple TINYINT NOT NULL DEFAULT 0,'                // jeu d'essai
        . 'created_by VARCHAR(16) NOT NULL DEFAULT "brand",'
        . 'created_at DATETIME NULL,'
        . 'updated_at DATETIME NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    nlSemer();
}

/** Les magasins du réseau, ceux de `ep_stores()` : la table newsletter les suit. */
function nlMagasinsReseau(): array
{
    try { $shops = ep_stores(); } catch (Throwable $e) { $shops = []; }
    return array_values(array_filter($shops, fn ($s) => !isset($s['status']) || preg_match('/ouvert/i', (string) $s['status'])));
}

/** Le domaine expéditeur de la marque : ce qui est « vérifié ». */
function nlDomaine(): string
{
    $d = (string) setting('nlDomaine', 'latelier.by');
    return $d !== '' ? strtolower($d) : 'latelier.by';
}

/**
 * Jeu d'essai — posé une fois, jamais rejoué sur une base qui a des lignes.
 * Les magasins, eux, sont resynchronisés à chaque démarrage : un magasin
 * ouvert dans le réseau apparaît ici avec l'envoi autorisé et une adresse à
 * compléter ; rien n'est inventé sur l'adresse.
 */
function nlSemer(): void
{
    $shops = nlMagasinsReseau();
    $ordre = 0;
    Db::exec('INSERT INTO ceo_nl_magasin (shop_id, nom, sender_name, sender_email, domain_status, can_send, sort_order, updated_at) VALUES (?,?,?,?,?,?,?,NOW())'
        . ' ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)',
        ['brand', "L'Atelier By", "L'Atelier By", 'bonjour@' . nlDomaine(), 'verified', 1, $ordre]);
    foreach ($shops as $s) {
        $ordre++;
        $nom = (string) ($s['zone'] ?: $s['nom']);
        Db::exec('INSERT INTO ceo_nl_magasin (shop_id, nom, sender_name, sender_email, domain_status, can_send, sort_order, updated_at) VALUES (?,?,?,?,?,?,?,NOW())'
            . ' ON DUPLICATE KEY UPDATE nom = VALUES(nom), sort_order = VALUES(sort_order)',
            [(string) $s['id'], $nom, "L'Atelier By " . $nom, '', 'unverified', 1, $ordre]);
    }
    $n = Db::row('SELECT COUNT(*) AS n FROM ceo_nl_source');
    if ((int) ($n['n'] ?? 0) > 0) { return; }

    // --- bases (jeu d'essai, comptes du brief)
    $sources = [
        ['indiv', 'tfb_customers', ['fr' => 'Clients individuels', 'nl' => 'Particuliere klanten', 'en' => 'Individual customers'], 4210, 3480],
        ['office', 'tfb_office_clients', ['fr' => 'Clients office', 'nl' => 'Office-klanten', 'en' => 'Office clients'], 1860, 1610],
        ['b2b', 'tfb_b2b_accounts', ['fr' => 'Clients B2B', 'nl' => 'B2B-klanten', 'en' => 'B2B clients'], 312, 298],
        ['crm', 'tfb_crm_candidates', ['fr' => 'Candidats (CRM)', 'nl' => 'Kandidaten (CRM)', 'en' => 'Candidates (CRM)'], 1540, 1546],
    ];
    foreach ($sources as $i => $s) {
        Db::exec('INSERT INTO ceo_nl_source (id, table_ref, nom_json, total, optin, sort_order) VALUES (?,?,?,?,?,?)',
            [$s[0], $s[1], json_encode($s[2], JSON_UNESCAPED_UNICODE), $s[3], $s[4], $i]);
    }
    // --- segments : un « tous les clients » par magasin, puis les segments réseau
    $ordre = 0;
    $seg = function (string $id, string $src, string $shop, array $nom, array $regle, int $total, array $split, ?array $rules = null) use (&$ordre): void {
        Db::exec('INSERT INTO ceo_nl_segment (id, source_id, shop_id, nom_json, regle_json, rules_json, total, split_fr, split_nl, split_en, created_by, created_at, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?)',
            [$id, $src, $shop, json_encode($nom, JSON_UNESCAPED_UNICODE), json_encode($regle, JSON_UNESCAPED_UNICODE), $rules ? json_encode($rules, JSON_UNESCAPED_UNICODE) : null,
                $total, $split[0], $split[1], $split[2], 'brand', $ordre++]);
    };
    $parts = [[886, 318, 44], [640, 96, 30], [430, 60, 22], [520, 210, 40], [380, 70, 18]];
    foreach ($shops as $i => $s) {
        $nom = (string) ($s['zone'] ?: $s['nom']);
        $p = $parts[$i % count($parts)];
        $seg('tous-' . $s['id'], 'indiv', (string) $s['id'],
            ['fr' => 'Tous les clients — ' . $nom, 'nl' => 'Alle klanten — ' . $nom, 'en' => 'All customers — ' . $nom],
            ['fr' => 'magasin = ' . $nom . ' · opt-in', 'nl' => 'winkel = ' . $nom . ' · opt-in', 'en' => 'shop = ' . $nom . ' · opt-in'],
            array_sum($p), $p, ['magasin' => (string) $s['id'], 'produit' => null, 'periode' => '365', 'panier_min' => null]);
    }
    $seg('tartine', 'indiv', '', ['fr' => "Ont acheté une tartine l'an passé", 'nl' => 'Kochten vorig jaar een tartine', 'en' => 'Bought a tartine last year'],
        ['fr' => 'produit = tartine · 12 derniers mois', 'nl' => 'product = tartine · laatste 12 maanden', 'en' => 'product = tartine · last 12 months'], 412, [301, 98, 13],
        ['magasin' => null, 'produit' => 'tartine', 'periode' => '365', 'panier_min' => null]);
    $seg('dormant', 'indiv', '', ['fr' => "Pas d'achat depuis 45 jours", 'nl' => 'Geen aankoop sinds 45 dagen', 'en' => 'No purchase in 45 days'],
        ['fr' => 'dernier achat > 45 j · tous magasins', 'nl' => 'laatste aankoop > 45 d · alle winkels', 'en' => 'last purchase > 45 d · all shops'], 936, [612, 281, 43],
        ['magasin' => null, 'produit' => null, 'periode' => 'dormant45', 'panier_min' => null]);
    $seg('office-livres', 'office', '', ['fr' => 'Bureaux livrés', 'nl' => 'Geleverde kantoren', 'en' => 'Delivered offices'],
        ['fr' => 'livraison bureau · tous magasins', 'nl' => 'kantoorlevering · alle winkels', 'en' => 'office delivery · all shops'], 1104, [702, 289, 113]);
    $seg('office-new', 'office', '', ['fr' => 'Nouveaux comptes office (90 j)', 'nl' => 'Nieuwe office-accounts (90 d)', 'en' => 'New office accounts (90 d)'],
        ['fr' => 'créé < 90 j · 1ère commande faite', 'nl' => 'aangemaakt < 90 d · 1e bestelling', 'en' => 'created < 90 d · first order placed'], 186, [104, 61, 21]);
    $seg('b2b-all', 'b2b', '', ['fr' => 'Tous les comptes B2B actifs', 'nl' => 'Alle actieve B2B-accounts', 'en' => 'All active B2B accounts'],
        ['fr' => 'commande < 6 mois · opt-in', 'nl' => 'bestelling < 6 maanden · opt-in', 'en' => 'order < 6 months · opt-in'], 298, [171, 118, 9]);
    $seg('b2b-horeca', 'b2b', '', ['fr' => 'Horeca — pains & viennoiseries', 'nl' => 'Horeca — brood & koffiekoeken', 'en' => 'Horeca — bread & pastries'],
        ['fr' => 'catégorie = horeca · gamme pain', 'nl' => 'categorie = horeca · broodgamma', 'en' => 'category = horeca · bread range'], 74, [40, 32, 2]);
    $seg('crm-leads', 'crm', '', ['fr' => 'Leads de marché — franchise', 'nl' => 'Marktleads — franchise', 'en' => 'Market leads — franchise'],
        ['fr' => 'statut = lead · pas encore rencontré', 'nl' => 'status = lead · nog niet ontmoet', 'en' => 'status = lead · not yet met'], 1546, [903, 572, 71]);
    $seg('crm-hot', 'crm', '', ['fr' => 'Candidats chauds', 'nl' => 'Warme kandidaten', 'en' => 'Hot candidates'],
        ['fr' => 'statut = entretien planifié', 'nl' => 'status = gesprek gepland', 'en' => 'status = interview scheduled'], 38, [22, 15, 1]);

    // --- campagnes d'exemple : de quoi lire le tableau et une rétrospective
    $premier = $shops[0] ?? null;
    $segPremier = $premier ? 'tous-' . $premier['id'] : 'dormant';
    $nomPremier = $premier ? (string) ($premier['zone'] ?: $premier['nom']) : 'Réseau';
    $exp = $premier ? (string) $premier['id'] : 'brand';
    $camp = function (string $nom, string $src, string $seg, string $tpl, string $channel, string $sujet, string $mode, ?string $trig, string $sendAt, ?int $maxV, string $sender, string $statut, ?array $stats, ?array $social) {
        Db::exec('INSERT INTO ceo_nl_campagne (nom, source_id, segment_id, langues, template_id, channel, subjects_json, bodies_json, sms_json, social_json, send_mode, trigger_rule, send_at, max_vouchers, sender_shop_id, statut, test_sent, stats_json, exemple, created_by, created_at, updated_at)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,1,?,NOW(),NOW())',
            [$nom, $src, $seg, 'fr,nl', $tpl, $channel, json_encode(['fr' => $sujet], JSON_UNESCAPED_UNICODE), null, null,
                $social ? json_encode($social, JSON_UNESCAPED_UNICODE) : null, $mode, $trig, $sendAt, $maxV, $sender, $statut,
                $stats ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null, 'brand']);
    };
    $an = (int) date('Y');
    $camp('Tarte diamant — ' . $nomPremier, 'indiv', $segPremier, 'gagne', 'both', 'Vous avez une tarte qui vous attend', 'manual', null, "$an-09-12 08:30:00", 30, $exp, 'sent',
        ['sent' => 1248, 'open' => 599, 'click' => 237, 'vouchers' => 22, 'revenue' => 1184, 'prev' => 'galette'],
        ['linkedin' => ['on' => true], 'instagram' => ['on' => true]]);
    $camp('Réveil 45 jours', 'indiv', 'dormant', 'produit', 'email', "On ne vous a pas vu depuis un moment", 'auto', 'dormant45', "$an-09-19 08:30:00", 40, 'brand', 'live',
        ['sent' => 612, 'open' => 190, 'click' => 55, 'vouchers' => 6], null);
    $camp('Plateau du vendredi', 'office', 'office-livres', 'office', 'email', 'Le plateau du vendredi, livré à 11h30', 'auto', 'firstOffice', "$an-09-24 08:30:00", null, 'brand', 'sched', null, null);
    $camp('Cougnou ' . $an, 'indiv', 'tartine', 'produit', 'both', 'Le cougnou est de retour', 'manual', null, "$an-12-01 08:30:00", null, 'brand', 'draft', null, ['instagram' => ['on' => true]]);
    $camp('Galette des rois — ' . $nomPremier, 'indiv', $segPremier, 'produit', 'email', 'La fève est peut-être pour vous', 'manual', null, "$an-01-03 08:30:00", 30, $exp, 'sent',
        ['sent' => 1190, 'open' => 524, 'click' => 179, 'vouchers' => 30, 'revenue' => 866, 'prev' => null], null);
}

/* ---------------------------------------------------------------------------
 * Lecture
 * ------------------------------------------------------------------------- */
function nlRole(): string
{
    $r = trim((string) ($_GET['role'] ?? (body()['role'] ?? 'brand')));
    return preg_match('/^(brand|\d{1,10})$/', $r) ? $r : 'brand';
}

function nlJson(?string $s, mixed $defaut = null): mixed
{
    if ($s === null || $s === '') { return $defaut; }
    $d = json_decode($s, true);
    return $d === null ? $defaut : $d;
}

function nlMagasinRow(array $r): array
{
    return ['id' => $r['shop_id'], 'nom' => $r['nom'], 'kind' => $r['shop_id'] === 'brand' ? 'brand' : 'franchise',
        'senderName' => $r['sender_name'], 'email' => $r['sender_email'], 'status' => $r['domain_status'], 'canSend' => (int) $r['can_send'] === 1];
}

function nlSegmentRow(array $r): array
{
    return ['id' => $r['id'], 'src' => $r['source_id'], 'shop' => (string) $r['shop_id'], 'name' => nlJson($r['nom_json'], ''), 'rule' => nlJson($r['regle_json'], ''),
        'rules' => nlJson($r['rules_json']), 'count' => (int) $r['total'], 'split' => [(int) $r['split_fr'], (int) $r['split_nl'], (int) $r['split_en']], 'createdBy' => $r['created_by']];
}

function nlCampagneRow(array $r): array
{
    $stats = nlJson($r['stats_json'], []);
    return ['id' => (int) $r['id'], 'name' => $r['nom'], 'src' => $r['source_id'], 'segment' => $r['segment_id'], 'langs' => array_values(array_filter(explode(',', (string) $r['langues']))),
        'templateId' => $r['template_id'], 'channel' => $r['channel'], 'subjects' => nlJson($r['subjects_json'], []), 'bodies' => nlJson($r['bodies_json'], []), 'sms' => nlJson($r['sms_json'], []),
        'social' => nlJson($r['social_json'], []), 'sendMode' => $r['send_mode'], 'trigger' => $r['trigger_rule'], 'sendAt' => $r['send_at'], 'maxVouchers' => $r['max_vouchers'] !== null ? (int) $r['max_vouchers'] : null,
        'sender' => $r['sender_shop_id'], 'status' => $r['statut'], 'testSent' => (int) $r['test_sent'] === 1, 'stats' => is_array($stats) ? $stats : [], 'exemple' => (int) $r['exemple'] === 1,
        'createdBy' => $r['created_by'], 'createdAt' => $r['created_at']];
}

/**
 * GET /newsletter?role=brand|{shopId} — tout ce que la vue a besoin de savoir.
 * Le franchisé ne voit que les segments de son magasin ou de tout le réseau,
 * les campagnes qu'il a créées ou qui partent de son adresse, et son magasin
 * dans les paramètres.
 */
function ep_newsletter(): array
{
    ensureNewsletter();
    $role = nlRole();
    $magasins = array_map('nlMagasinRow', Db::rows('SELECT * FROM ceo_nl_magasin ORDER BY sort_order, shop_id'));
    $moi = null;
    foreach ($magasins as $m) { if ($m['id'] === $role) { $moi = $m; } }
    if ($role !== 'brand' && $moi === null) { http_response_code(404); return ['error' => 'magasin inconnu de la newsletter']; }
    $sources = array_map(fn ($r) => ['id' => $r['id'], 'table' => $r['table_ref'], 'name' => nlJson($r['nom_json'], ''), 'count' => (int) $r['total'], 'optin' => (int) $r['optin']],
        Db::rows('SELECT * FROM ceo_nl_source ORDER BY sort_order, id'));
    $segments = array_map('nlSegmentRow', Db::rows('SELECT * FROM ceo_nl_segment ORDER BY sort_order, created_at DESC'));
    $campagnes = array_map('nlCampagneRow', Db::rows('SELECT * FROM ceo_nl_campagne ORDER BY COALESCE(send_at, created_at) DESC, id DESC'));
    if ($role !== 'brand') {
        $segments = array_values(array_filter($segments, fn ($s) => $s['shop'] === '' || $s['shop'] === $role));
        $campagnes = array_values(array_filter($campagnes, fn ($c) => $c['createdBy'] === $role || $c['sender'] === $role));
        $magasins = array_values(array_filter($magasins, fn ($m) => $m['id'] === $role));
    }
    $mois = date('Y-m');
    $envoisMois = count(array_filter($campagnes, fn ($c) => in_array($c['status'], ['sent', 'live'], true) && str_starts_with((string) $c['sendAt'], $mois)));
    $vouchers = [0, 0];
    foreach ($campagnes as $c) {
        if ($c['maxVouchers']) { $vouchers[0] += (int) ($c['stats']['vouchers'] ?? 0); $vouchers[1] += (int) $c['maxVouchers']; }
    }
    return [
        'test' => true,
        'role' => $role,
        'moi' => $moi,
        'canSend' => $role === 'brand' || ($moi && $moi['canSend']),
        'domaine' => nlDomaine(),
        'magasins' => $magasins,
        'sources' => $sources,
        'segments' => $segments,
        'campagnes' => $campagnes,
        'chiffres' => ['optin' => array_sum(array_map(fn ($s) => $s['optin'], $sources)), 'envoisMois' => $envoisMois, 'vouchers' => $vouchers],
        'smtp' => Smtp::configured(),
    ];
}

/* ---------------------------------------------------------------------------
 * Écritures
 * ------------------------------------------------------------------------- */
function nlLangues(mixed $v): string
{
    $l = is_array($v) ? $v : explode(',', (string) $v);
    $l = array_values(array_intersect(NL_LANGUES, array_map('strval', $l)));
    return implode(',', $l ?: ['fr']);
}

function nlTextesParLangue(mixed $v, int $max): ?string
{
    if (!is_array($v)) { return null; }
    $out = [];
    foreach (NL_LANGUES as $l) { if (isset($v[$l]) && is_string($v[$l])) { $out[$l] = mb_substr($v[$l], 0, $max); } }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
}

/** POST /newsletter/campagnes — programmer (ou garder en brouillon) une campagne. */
function wr_newsletter_campagne_post(): array
{
    ensureNewsletter();
    $b = body();
    $role = nlRole();
    $moi = Db::row('SELECT * FROM ceo_nl_magasin WHERE shop_id = ?', [$role]);
    if ($role !== 'brand') {
        if ($moi === null) { http_response_code(404); return ['error' => 'magasin inconnu']; }
        if ((int) $moi['can_send'] !== 1) { http_response_code(403); return ['error' => 'Envoi bloqué par la marque.']; }
    }
    $segId = mb_substr(trim((string) ($b['segment'] ?? '')), 0, 40);
    $seg = Db::row('SELECT * FROM ceo_nl_segment WHERE id = ?', [$segId]);
    if ($seg === null) { http_response_code(422); return ['error' => 'segment inconnu']; }
    if ($role !== 'brand' && $seg['shop_id'] !== '' && $seg['shop_id'] !== $role) { http_response_code(403); return ['error' => "Ce segment n'est pas celui de votre magasin."]; }
    $mode = ($b['sendMode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
    if ($mode === 'auto' && $role !== 'brand') { http_response_code(403); return ['error' => 'Seule la marque crée une automatisation.']; }
    $trig = $mode === 'auto' ? (string) ($b['trigger'] ?? '') : null;
    if ($mode === 'auto' && !in_array($trig, NL_DECLENCHEURS, true)) { http_response_code(422); return ['error' => 'déclencheur inconnu']; }
    $sender = mb_substr(trim((string) ($b['sender'] ?? $role)), 0, 16);
    if ($role !== 'brand' && $sender !== $role) { http_response_code(403); return ['error' => "Un franchisé envoie depuis l'adresse de son magasin."]; }
    if (Db::row('SELECT 1 FROM ceo_nl_magasin WHERE shop_id = ?', [$sender]) === null) { http_response_code(422); return ['error' => 'expéditeur inconnu']; }
    $tpl = in_array($b['templateId'] ?? '', NL_MODELES, true) ? $b['templateId'] : 'blank';
    $channel = in_array($b['channel'] ?? '', ['email', 'sms', 'both'], true) ? $b['channel'] : 'email';
    $statut = ($b['status'] ?? '') === 'draft' ? 'draft' : ($mode === 'auto' ? 'live' : 'sched');
    $date = (string) ($b['date'] ?? ''); $heure = (string) ($b['time'] ?? '08:30');
    $sendAt = null;
    if ($mode === 'manual') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $heure)) { http_response_code(422); return ['error' => 'date ou heure invalide']; }
        $sendAt = $date . ' ' . $heure . ':00';
    } else { $sendAt = date('Y-m-d H:i:s'); }
    $maxV = isset($b['maxVouchers']) && $b['maxVouchers'] !== '' && $b['maxVouchers'] !== null ? max(0, min(100000, (int) $b['maxVouchers'])) : null;
    $nom = mb_substr(trim((string) ($b['name'] ?? '')), 0, 160);
    if ($nom === '') { http_response_code(422); return ['error' => 'nom attendu']; }
    $social = null;
    if (isset($b['social']) && is_array($b['social'])) {
        $s = [];
        foreach (['linkedin', 'instagram', 'slack'] as $k) {
            if (!isset($b['social'][$k]) || !is_array($b['social'][$k])) { continue; }
            $s[$k] = ['on' => !empty($b['social'][$k]['on']), 'text' => mb_substr((string) ($b['social'][$k]['text'] ?? ''), 0, 3000), 'reviewed' => !empty($b['social'][$k]['reviewed'])];
        }
        $social = json_encode($s, JSON_UNESCAPED_UNICODE);
    }
    Db::exec('INSERT INTO ceo_nl_campagne (nom, source_id, segment_id, langues, template_id, channel, subjects_json, bodies_json, sms_json, social_json, send_mode, trigger_rule, send_at, max_vouchers, sender_shop_id, statut, test_sent, stats_json, exemple, created_by, created_at, updated_at)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,0,?,NOW(),NOW())',
        [$nom, $seg['source_id'], $segId, nlLangues($b['langs'] ?? 'fr'), $tpl, $channel,
            nlTextesParLangue($b['subjects'] ?? null, 200), nlTextesParLangue($b['bodies'] ?? null, 20000), nlTextesParLangue($b['sms'] ?? null, 400), $social,
            $mode, $trig, $sendAt, $maxV, $sender, $statut, !empty($b['testSent']) ? 1 : 0, $role]);
    $id = (int) Db::pdo()->lastInsertId();
    journalAdd($role === 'brand' ? 'Marque' : 'Magasin ' . $role, 'Newsletter', null,
        ($statut === 'draft' ? 'Brouillon gardé' : ($mode === 'auto' ? 'Automatisation créée' : 'Campagne programmée')) . ' : ' . $nom . ' (mode test, aucun envoi ne part)');
    $r = Db::row('SELECT * FROM ceo_nl_campagne WHERE id = ?', [$id]);
    return ['ok' => true, 'campagne' => $r ? nlCampagneRow($r) : null, 'test' => true];
}

/** DELETE /newsletter/campagnes/{id} — retirer un brouillon ou une campagne programmée. */
function wr_newsletter_campagne_delete(int $id): array
{
    ensureNewsletter();
    $role = nlRole();
    $r = Db::row('SELECT * FROM ceo_nl_campagne WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if ($role !== 'brand' && $r['created_by'] !== $role) { http_response_code(403); return ['error' => "Cette campagne n'est pas la vôtre."]; }
    if ($r['statut'] === 'sent') { http_response_code(409); return ['error' => 'Une campagne envoyée reste dans l’historique.']; }
    Db::exec('DELETE FROM ceo_nl_campagne WHERE id = ?', [$id]);
    journalAdd($role === 'brand' ? 'Marque' : 'Magasin ' . $role, 'Newsletter', null, 'Campagne retirée : ' . $r['nom']);
    return ['ok' => true];
}

/** POST /newsletter/segments — la marque crée un segment (règle gardée en rules_json). */
function wr_newsletter_segment_post(): array
{
    ensureNewsletter();
    $role = nlRole();
    if ($role !== 'brand') { http_response_code(403); return ['error' => 'Seule la marque peut créer un segment.']; }
    $b = body();
    $nom = mb_substr(trim((string) ($b['name'] ?? '')), 0, 160);
    if ($nom === '') { http_response_code(422); return ['error' => 'nom attendu']; }
    $src = mb_substr((string) ($b['src'] ?? 'indiv'), 0, 16);
    if (Db::row('SELECT 1 FROM ceo_nl_source WHERE id = ?', [$src]) === null) { http_response_code(422); return ['error' => 'base inconnue']; }
    $shop = mb_substr(trim((string) ($b['shop'] ?? '')), 0, 16);
    if ($shop !== '' && Db::row('SELECT 1 FROM ceo_nl_magasin WHERE shop_id = ? AND shop_id <> "brand"', [$shop]) === null) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $rules = ['magasin' => $shop !== '' ? $shop : null, 'produit' => in_array($b['product'] ?? '', ['tartine', 'cougnou', 'galette', 'tarte', 'plateau'], true) ? $b['product'] : null,
        'periode' => in_array((string) ($b['period'] ?? ''), ['30', '90', '365', 'dormant45'], true) ? (string) $b['period'] : '365',
        'panier_min' => isset($b['minBasket']) && (float) $b['minBasket'] > 0 ? (float) $b['minBasket'] : null];
    $total = max(1, (int) ($b['count'] ?? 1));
    $split = [(int) round($total * 0.7), (int) round($total * 0.26)];
    $split[] = max(0, $total - $split[0] - $split[1]);
    $id = 'seg-' . substr(md5($nom . microtime(true)), 0, 12);
    $regle = mb_substr(trim((string) ($b['rule'] ?? '')), 0, 200);
    Db::exec('INSERT INTO ceo_nl_segment (id, source_id, shop_id, nom_json, regle_json, rules_json, total, split_fr, split_nl, split_en, created_by, created_at, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),-1)',
        [$id, $src, $shop, json_encode($nom, JSON_UNESCAPED_UNICODE), json_encode($regle, JSON_UNESCAPED_UNICODE), json_encode($rules, JSON_UNESCAPED_UNICODE), $total, $split[0], $split[1], $split[2], 'brand']);
    journalAdd('Marque', 'Newsletter', null, 'Segment créé : ' . $nom);
    $r = Db::row('SELECT * FROM ceo_nl_segment WHERE id = ?', [$id]);
    return ['ok' => true, 'segment' => $r ? nlSegmentRow($r) : null];
}

/** PUT /newsletter/magasins/{id} — expéditeur (nom, adresse), statut du domaine, envoi autorisé. */
function wr_newsletter_magasin_put(string $id): array
{
    ensureNewsletter();
    $role = nlRole();
    $r = Db::row('SELECT * FROM ceo_nl_magasin WHERE shop_id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    if ($role !== 'brand' && $role !== $id) { http_response_code(403); return ['error' => "Un franchisé ne modifie que l'adresse de son magasin."]; }
    $b = body();
    $nom = array_key_exists('senderName', $b) ? mb_substr(trim((string) $b['senderName']), 0, 120) : $r['sender_name'];
    $email = array_key_exists('email', $b) ? mb_substr(trim((string) $b['email']), 0, 190) : $r['sender_email'];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'adresse invalide']; }
    // Le statut suit l'adresse : le domaine de la marque est vérifié, tout
    // autre domaine ne l'est pas. La marque peut ensuite marquer « en chauffe ».
    $statut = $r['domain_status'];
    if ($email !== $r['sender_email']) {
        $statut = ($email !== '' && str_ends_with(strtolower($email), '@' . nlDomaine())) ? 'verified' : 'unverified';
    }
    if ($role === 'brand' && isset($b['status']) && in_array($b['status'], ['verified', 'warmup', 'unverified'], true)) { $statut = $b['status']; }
    $canSend = (int) $r['can_send'];
    if ($role === 'brand' && array_key_exists('canSend', $b) && $id !== 'brand') { $canSend = !empty($b['canSend']) ? 1 : 0; }
    if ($nom === '') { http_response_code(422); return ['error' => "nom d'expéditeur attendu"]; }
    Db::exec('UPDATE ceo_nl_magasin SET sender_name = ?, sender_email = ?, domain_status = ?, can_send = ?, updated_at = NOW() WHERE shop_id = ?', [$nom, $email, $statut, $canSend, $id]);
    if ($canSend !== (int) $r['can_send']) {
        journalAdd('Marque', 'Newsletter', null, ($canSend ? 'Envoi autorisé' : 'Envoi bloqué') . ' : ' . $r['nom']);
    }
    $m = Db::row('SELECT * FROM ceo_nl_magasin WHERE shop_id = ?', [$id]);
    return ['ok' => true, 'magasin' => $m ? nlMagasinRow($m) : null];
}

/**
 * POST /newsletter/test — « envoyer un test à 3 adresses ».
 * Mode test : rien ne part. La demande est journalisée, la vue coche le
 * contrôle « test envoyé ». Quand le SMTP du cockpit sera retenu pour les
 * newsletters, c'est ici que partira le vrai test.
 */
function wr_newsletter_test_post(): array
{
    ensureNewsletter();
    $role = nlRole();
    $b = body();
    $sujet = mb_substr(trim((string) ($b['subject'] ?? '')), 0, 200);
    journalAdd($role === 'brand' ? 'Marque' : 'Magasin ' . $role, 'Newsletter', null, 'Test demandé (mode test, non envoyé) : ' . ($sujet !== '' ? $sujet : '(sans objet)'));
    return ['ok' => true, 'simule' => true, 'adresses' => 3];
}
