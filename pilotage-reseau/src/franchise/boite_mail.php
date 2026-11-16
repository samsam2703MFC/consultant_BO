<?php
declare(strict_types=1);

/**
 * Dashboard magasin › Boîte mail (15/11/2026, « quid de la boîte mail ? » → « go, commence avec Halle » ; « il me faut
 * tous les supports : templates, messages automatiques, transformer en offre… »).
 *
 * La boîte e-mail du magasin (OVH, @atelierby.be), lue et écrite depuis le dashboard : les dossiers, la recherche,
 * l'e-mail ouvert et ses pièces jointes ; répondre, écrire, transférer depuis l'adresse du magasin ; les modèles de
 * réponse ; les messages automatiques (accusé de réception, relance d'une offre restée sans réponse, rappel la veille
 * d'une commande) ; un e-mail transformé en offre (Vendre › Offres), et l'offre renvoyée en PDF dans la réponse.
 * Les protocoles : src/franchise/boite_protocole.php (IMAP, MIME, SMTP), testés par bin/tests_boite_mail.php.
 *
 * LES ACCÈS. Ceux de la boîte, jamais dans le code : `erp_cles`, portail `pil`, une ligne par valeur —
 *   pil_boite_{shop}_adresse, pil_boite_{shop}_mot_de_passe (secret) ; facultatifs : pil_boite_{shop}_utilisateur,
 *   pil_boite_{shop}_imap / _smtp (« hôte:port:ssl|tls », OVH par défaut), pil_boite_{shop}_nom (nom d'expéditeur).
 * Le magasin les pose lui-même (« Relier la boîte » : le mot de passe est essayé auprès du serveur avant d'être gardé,
 * puis n'est plus jamais rendu). Le mot de passe que l'ERP rend en clair dans la fiche du magasin n'est pas repris.
 *
 * LE VERROU. L'API du cockpit est ouverte en ligne (mode « ouvert ») : une boîte ne peut pas l'être. Lire ou écrire
 * demande, sur l'appareil, le mot de passe de la boîte — comme un webmail : un cookie signé (cockpit_b{shop}, 30 jours,
 * signé avec un secret du serveur et l'empreinte des accès : changer le mot de passe ferme les sessions). Les essais
 * ratés sont freinés (8 par quart d'heure). Le siège n'ouvre pas la boîte d'un magasin sans son mot de passe.
 *
 *   GET  /dashboard/boite?shop=                      l'état : reliée, ouverte, l'adresse, les dossiers et les non-lus
 *   POST /dashboard/boite/relier   {shop, adresse, motDePasse}    relier (ou changer, boîte ouverte) la boîte
 *   POST /dashboard/boite/ouvrir   {shop, motDePasse}             ouvrir sur cet appareil
 *   POST /dashboard/boite/fermer   {shop}                         fermer sur cet appareil
 *   POST /dashboard/boite/delier   {shop}                         retirer les accès de la boîte (boîte ouverte)
 *   GET  /dashboard/boite/messages?shop=&dossier=&page=&q=&filtre= la liste (50 par page, la plus récente d'abord)
 *   GET  /dashboard/boite/message?shop=&dossier=&uid=           un e-mail (marqué lu)
 *   GET  /dashboard/boite/piece?shop=&dossier=&uid=&partie=     une pièce jointe ou une image intégrée
 *   POST /dashboard/boite/geste    {shop, dossier, uids, action: lu|nonlu|suivi|nonsuivi|archiver|corbeille|deplacer, vers}
 *   POST /dashboard/boite/envoyer  {shop, a, cc, objet, texte, reponse?, transfert?, offre?, pieces?}
 *   GET  /dashboard/boite/modeles?shop=        POST {shop, action: creer|modifier|supprimer, id, nom, objet, corps}
 *   GET  /dashboard/boite/automatiques?shop=   POST {shop, regle, actif, objet, corps, jours}
 *   GET  /dashboard/boite/cron?jeton=          l'horloge des messages automatiques (bin/boite_cron.sh, toutes les 10 min)
 *   GET|POST /dashboard/boite/automatisations, POST …/automatisations/essai   Paramètres › Mails et automatisations
 *        (src/franchise/automatisations.php : le rappel la veille du retrait, par e-mail et SMS)
 */

require_once __DIR__ . '/boite_protocole.php';
require_once __DIR__ . '/automatisations.php';   // Paramètres › Mails et automatisations (16/11/2026) : ses routes sont celles de la boîte

const BM_PREFIXE = '/dashboard/boite';
const BM_IMAP_OVH = 'ssl0.ovh.net:993:ssl';
const BM_SMTP_OVH = 'ssl0.ovh.net:465:ssl';
const BM_COOKIE = 'cockpit_b';
const BM_TTL = 2592000;          // 30 jours
const BM_PAGE = 50;
const BM_TAILLE_MAX = 25000000;  // un e-mail plus lourd ne s'ouvre pas en entier (ses en-têtes, si)
const BM_ENVOI_MAX = 15000000;   // pièces jointes d'un envoi

/** Les modèles du réseau, proposés à chaque magasin (lecture seule ; « Dupliquer » en fait un modèle du magasin). */
const BM_MODELES_BASE = [
    ['id' => 'b-accuse', 'nom' => 'Accusé de réception', 'objet' => 'Re: {objet}',
        'corps' => "Bonjour {prenom},\n\nMerci pour votre message. Nous l’avons bien reçu et nous vous répondons au plus vite.\n\nBelle journée,\n{magasin}"],
    ['id' => 'b-precisions', 'nom' => 'Demande de précisions', 'objet' => 'Re: {objet}',
        'corps' => "Bonjour {prenom},\n\nMerci pour votre demande. Pour vous faire une offre précise, pourriez-vous nous indiquer :\n- le nombre de personnes,\n- la date et l’heure souhaitées,\n- retrait en magasin ou livraison (et l’adresse) ?\n\nÀ très vite,\n{magasin}"],
    ['id' => 'b-offre', 'nom' => 'Envoi de l’offre', 'objet' => 'Notre offre n° {offre}',
        'corps' => "Bonjour {prenom},\n\nMerci pour votre demande. Vous trouverez en pièce jointe notre offre n° {offre} ({total}) pour le {jour}.\n\nPour la confirmer, il suffit de répondre à cet e-mail.\n\nÀ très bientôt,\n{magasin}"],
    ['id' => 'b-relance', 'nom' => 'Relance d’une offre', 'objet' => 'Notre offre n° {offre}',
        'corps' => "Bonjour {prenom},\n\nNous revenons vers vous au sujet de notre offre n° {offre} pour le {jour}. Avez-vous pu la consulter ? Nous pouvons l’adapter si besoin.\n\nBien à vous,\n{magasin}"],
    ['id' => 'b-confirmation', 'nom' => 'Confirmation de commande', 'objet' => 'Votre commande du {jour}',
        'corps' => "Bonjour {prenom},\n\nC’est confirmé : votre commande est prévue pour le {jour}. Nous vous attendons au magasin.\n\nMerci de votre confiance,\n{magasin}"],
    ['id' => 'b-rappel', 'nom' => 'Rappel la veille', 'objet' => 'Votre commande de demain',
        'corps' => "Bonjour {prenom},\n\nPetit rappel : votre commande est prévue demain, le {jour}. Tout est prêt de notre côté.\n\nÀ demain,\n{magasin}"],
];

/** Les messages automatiques : la règle, ce qu'elle fait, son modèle de départ, son délai. Toutes éteintes au départ. */
const BM_REGLES = [
    'accuse' => ['nom' => 'Accusé de réception', 'modele' => 'b-accuse', 'jours' => 3,
        'texte' => 'Répond à chaque nouvel e-mail reçu — une fois par expéditeur sur la période choisie ; jamais aux envois automatiques, aux listes, aux « ne pas répondre », ni à la boîte elle-même.'],
    'relance' => ['nom' => 'Relance d’une offre sans réponse', 'modele' => 'b-relance', 'jours' => 3,
        'texte' => 'Relance une fois le client d’une offre en cours, restée sans suite depuis le nombre de jours choisi (et dont l’événement n’est pas passé).'],
    'rappel' => ['nom' => 'Rappel la veille de la commande', 'modele' => 'b-rappel', 'jours' => 1,
        'texte' => 'Écrit au client d’une offre convertie en commande la veille du jour prévu.'],
];

/* --- les tables (pil_) ------------------------------------------------------------------------------------------ */

function bmTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS pil_boite_modele (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, shop_id VARCHAR(16) NOT NULL,'
        . ' nom VARCHAR(120) NOT NULL, objet VARCHAR(200) NOT NULL DEFAULT \'\', corps TEXT NOT NULL, cree_le DATETIME NOT NULL, maj_le DATETIME NOT NULL,'
        . ' KEY k_shop (shop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS pil_boite_auto (shop_id VARCHAR(16) NOT NULL, regle VARCHAR(20) NOT NULL, actif TINYINT(1) NOT NULL DEFAULT 0,'
        . ' objet VARCHAR(200) NOT NULL, corps TEXT NOT NULL, jours INT NOT NULL DEFAULT 3, maj_le DATETIME NOT NULL, PRIMARY KEY (shop_id, regle))'
        . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS pil_boite_journal (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, shop_id VARCHAR(16) NOT NULL,'
        . ' regle VARCHAR(20) NOT NULL, cle VARCHAR(190) NOT NULL, destinataire VARCHAR(190) NOT NULL, objet VARCHAR(255) NOT NULL,'
        . ' envoye_le DATETIME NOT NULL, erreur VARCHAR(255) NULL, UNIQUE KEY u_cle (shop_id, cle), KEY k_shop (shop_id, envoye_le))'
        . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS pil_boite_etat (shop_id VARCHAR(16) NOT NULL PRIMARY KEY, uidvalidity BIGINT UNSIGNED NOT NULL,'
        . ' dernier_uid BIGINT UNSIGNED NOT NULL, maj_le DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* --- le compte et le verrou ------------------------------------------------------------------------------------- */

/** Les accès de la boîte du magasin (erp_cles), ou null si elle n'est pas reliée. */
function bmCompte(string $shop): ?array
{
    $p = 'pil_boite_' . $shop . '_';
    $adr = strtolower(erpCle($p . 'adresse')); $mdp = erpCle($p . 'mot_de_passe');
    if ($adr === '' || $mdp === '') { return null; }
    $nom = erpCle($p . 'nom');
    if ($nom === '') { $nom = function_exists('accesNomMagasin') ? (string) accesNomMagasin($shop) : ''; }
    $nom = trim((string) preg_replace('/^Atelier by\s*-?\s*/i', 'L’Atelier By ', $nom));
    return ['adresse' => $adr, 'motDePasse' => $mdp, 'utilisateur' => erpCle($p . 'utilisateur') ?: $adr, 'nom' => $nom,
        'imap' => bmServeur(erpCle($p . 'imap'), BM_IMAP_OVH), 'smtp' => bmServeur(erpCle($p . 'smtp'), BM_SMTP_OVH)];
}

/** Le secret qui signe les sessions de boîte (tiré une fois, gardé en base). */
function bmSecret(): string
{
    $s = setting('boiteSecret', '');
    if (is_string($s) && strlen($s) >= 64) { return $s; }
    $s = bin2hex(random_bytes(32));
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value', ['boiteSecret', json_encode($s)]);
    $relu = setting('boiteSecret', '');
    return is_string($relu) && strlen($relu) >= 64 ? $relu : $s;
}

function bmSigne(string $shop, int $exp, array $c): string
{
    return hash_hmac('sha256', 'b|' . $shop . '|' . $exp . '|' . hash('sha256', $c['adresse'] . "\0" . $c['motDePasse']), bmSecret());
}

/** La boîte est-elle ouverte sur cet appareil ? */
function bmOuverte(string $shop, ?array $c): bool
{
    if ($c === null) { return false; }
    $v = (string) ($_COOKIE[BM_COOKIE . $shop] ?? '');
    if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $v, $m) || (int) $m[1] < time()) { return false; }
    return hash_equals(bmSigne($shop, (int) $m[1], $c), $m[2]);
}

function bmPoserSession(string $shop, ?array $c): void
{
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $exp = $c === null ? time() - 3600 : time() + BM_TTL;
    setcookie(BM_COOKIE . $shop, $c === null ? '' : $exp . '.' . bmSigne($shop, $exp, $c),
        ['expires' => $exp, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $https]);
}

/** Une session IMAP de la boîte (une par requête). */
function bmImap(array $c): BmImap
{
    static $s = [];
    $k = $c['adresse'];
    return $s[$k] ??= BmImap::ouvrir($c['imap'], $c['utilisateur'], $c['motDePasse']);
}

function bmErreur(int $code, string $message, array $plus = []): array
{
    http_response_code($code);
    return ['error' => $message] + $plus;
}

/* --- les routes ------------------------------------------------------------------------------------------------- */

function bmRoute(string $method, string $path): ?array
{
    if ($path !== BM_PREFIXE && !str_starts_with($path, BM_PREFIXE . '/')) { return null; }
    $sous = substr($path, strlen(BM_PREFIXE));
    if ($sous === '/cron') { return bmCron(); }
    try { bmTables(); } catch (Throwable $e) { return bmErreur(503, 'les tables de la boîte ne se créent pas : ' . $e->getMessage()); }

    $b = in_array($method, ['POST', 'PUT', 'DELETE'], true) ? body() : [];
    if (!isset($_GET['shop']) && isset($b['shop'])) { $_GET['shop'] = (string) $b['shop']; }
    [$id, $err] = cmBoutique();
    if ($err !== null) { return $err; }
    $shop = (string) $id;
    $c = bmCompte($shop);

    if ($method === 'POST' && $sous === '/relier') { return bmRelier($shop, $c, $b); }
    if ($method === 'POST' && $sous === '/ouvrir') { return bmOuvrir($shop, $c, $b); }
    if ($method === 'POST' && $sous === '/fermer') { bmPoserSession($shop, null); return ['ok' => true]; }
    if ($method === 'GET' && $sous === '') { return bmEtat($shop, $c); }

    // Tout le reste : la boîte reliée ET ouverte sur cet appareil.
    if ($c === null) { return bmErreur(409, 'La boîte du magasin n’est pas encore reliée.', ['etat' => 'non_reliee']); }
    if (!bmOuverte($shop, $c)) { return bmErreur(401, 'Ouvrez la boîte avec son mot de passe.', ['etat' => 'fermee']); }
    try {
        if ($method === 'POST' && $sous === '/delier') { return bmDelier($shop); }
        if ($method === 'GET' && $sous === '/messages') { return bmMessages($c); }
        if ($method === 'GET' && $sous === '/message') { return bmMessage($shop, $c); }
        if ($method === 'GET' && $sous === '/piece') { bmPiece($c); }
        if ($method === 'POST' && $sous === '/geste') { return bmGeste($c, $b); }
        if ($method === 'POST' && $sous === '/envoyer') { return bmEnvoyerRoute($shop, $c, $b); }
        if ($sous === '/modeles') { return $method === 'POST' ? bmModelesEcrire($shop, $b) : bmModeles($shop); }
        if ($sous === '/automatiques') { return $method === 'POST' ? bmAutoEcrire($shop, $b) : bmAuto($shop); }
        if ($sous === '/automatisations') { return $method === 'POST' ? auEcrire($shop, $c, $b) : auLire($shop, $c); }
        if ($method === 'POST' && $sous === '/automatisations/essai') { return auEssai($shop, $c, $b); }
    } catch (BmRefus $e) {
        return bmErreur(401, 'Le serveur de la boîte refuse les accès gardés : le mot de passe a peut-être changé. Reliez la boîte à nouveau.', ['etat' => 'refusee']);
    } catch (RuntimeException $e) {
        return bmErreur(502, ucfirst($e->getMessage()) . '.');
    }
    return bmErreur(404, 'route inconnue');
}

/** L'état de la boîte ; ouverte : ses dossiers, chacun avec ses non-lus. */
function bmEtat(string $shop, ?array $c): array
{
    $out = ['shop' => $shop, 'reliee' => $c !== null, 'ouverte' => false, 'adresse' => $c['adresse'] ?? null, 'nom' => $c['nom'] ?? null,
        'serveur' => $c['imap']['hote'] ?? bmServeur('', BM_IMAP_OVH)['hote'], 'dossiers' => [], 'nonLus' => null];
    if (!bmOuverte($shop, $c)) { return $out; }
    $out['ouverte'] = true;
    try {
        $s = bmImap($c);
        $ordre = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5];
        $d = $s->dossiers();
        usort($d, static fn ($a, $b) => [$ordre[$a['role']] ?? 9, $a['nom']] <=> [$ordre[$b['role']] ?? 9, $b['nom']]);
        foreach ($d as &$x) {
            $x['nonLus'] = null; $x['total'] = null;
            if ($x['role'] !== null) { $e = $s->etat($x['brut']); $x['nonLus'] = $e['nonLus']; $x['total'] = $e['total']; }
            if ($x['role'] === 'inbox') { $out['nonLus'] = $x['nonLus']; }
        }
        unset($x);
        $out['dossiers'] = $d;
    } catch (BmRefus $e) {
        $out['ouverte'] = false; $out['erreur'] = 'Le serveur de la boîte refuse les accès gardés : reliez-la à nouveau.';
    } catch (RuntimeException $e) {
        $out['erreur'] = ucfirst($e->getMessage()) . '.';
    }
    return $out;
}

/**
 * Relier la boîte : l'adresse et le mot de passe sont essayés auprès du serveur, puis gardés dans erp_cles. Une boîte
 * déjà reliée ne se change que depuis une session ouverte (sinon n'importe qui pourrait la détourner).
 */
function bmRelier(string $shop, ?array $c, array $b): array
{
    if ($c !== null && !bmOuverte($shop, $c)) { return bmErreur(403, 'Cette boîte est déjà reliée : ouvrez-la d’abord pour changer ses accès.'); }
    $adr = strtolower(trim((string) ($b['adresse'] ?? ''))); $mdp = (string) ($b['motDePasse'] ?? '');
    if (!filter_var($adr, FILTER_VALIDATE_EMAIL) || $mdp === '') { return bmErreur(422, 'L’adresse e-mail et le mot de passe de la boîte sont attendus.'); }
    if (accesFreine('b' . $shop)) { return bmErreur(429, 'Trop d’essais. Réessayez dans un quart d’heure.'); }
    $p = 'pil_boite_' . $shop . '_';
    $imap = bmServeur(erpCle($p . 'imap'), BM_IMAP_OVH);
    try { BmImap::ouvrir($imap, $adr, $mdp); }
    catch (BmRefus $e) { accesEchec('b' . $shop); return bmErreur(401, 'Le serveur ' . $imap['hote'] . ' refuse cette adresse ou ce mot de passe.'); }
    catch (RuntimeException $e) { return bmErreur(502, ucfirst($e->getMessage()) . '.'); }
    erpCleEcrire($p . 'adresse', $adr, false, 'Boîte mail du magasin', 'pil');
    erpCleEcrire($p . 'mot_de_passe', $mdp, true, 'Boîte mail du magasin', 'pil');
    if (erpCle($p . 'utilisateur') !== '' && erpCle($p . 'utilisateur') !== $adr) { erpCleEcrire($p . 'utilisateur', '', false, 'Boîte mail du magasin', 'pil'); }
    $n = bmCompte($shop);
    bmPoserSession($shop, $n);
    journalAdd('Magasin ' . $shop, 'Boîte mail', null, 'Boîte reliée : ' . $adr);
    return ['ok' => true] + bmEtatSansImap($shop, $n);
}

function bmEtatSansImap(string $shop, ?array $c): array
{
    return ['reliee' => $c !== null, 'adresse' => $c['adresse'] ?? null, 'nom' => $c['nom'] ?? null];
}

/** Ouvrir sur cet appareil : le mot de passe comparé à celui qui est gardé (temps constant), essais freinés. */
function bmOuvrir(string $shop, ?array $c, array $b): array
{
    if ($c === null) { return bmErreur(409, 'La boîte du magasin n’est pas encore reliée.', ['etat' => 'non_reliee']); }
    if (accesFreine('b' . $shop)) { return bmErreur(429, 'Trop d’essais. Réessayez dans un quart d’heure.'); }
    if (!hash_equals($c['motDePasse'], (string) ($b['motDePasse'] ?? ''))) { accesEchec('b' . $shop); return bmErreur(401, 'Mot de passe incorrect.'); }
    bmPoserSession($shop, $c);
    return ['ok' => true];
}

function bmDelier(string $shop): array
{
    $p = 'pil_boite_' . $shop . '_';
    foreach (['adresse', 'mot_de_passe', 'utilisateur'] as $k) { erpCleEcrire($p . $k, '', false, 'Boîte mail du magasin', 'pil'); }
    Db::exec('UPDATE pil_boite_auto SET actif = 0, maj_le = NOW() WHERE shop_id = ?', [$shop]);
    bmPoserSession($shop, null);
    journalAdd('Magasin ' . $shop, 'Boîte mail', null, 'Boîte déliée (accès retirés, messages automatiques éteints)');
    return ['ok' => true];
}

/* --- lire ------------------------------------------------------------------------------------------------------- */

function bmDossierDemande(BmImap $s, string $brut): string
{
    $brut = $brut === '' ? 'INBOX' : $brut;
    foreach ($s->dossiers() as $d) { if ($d['brut'] === $brut) { return $brut; } }
    throw new RuntimeException('dossier inconnu');
}

function bmDossierRole(BmImap $s, string $role): ?string
{
    foreach ($s->dossiers() as $d) { if ($d['role'] === $role) { return $d['brut']; } }
    return null;
}

/** Une pièce jointe dans la structure IMAP (un nom de fichier, une disposition « attachment ») ? */
function bmStructurePj($bs): bool
{
    if (!is_array($bs)) { return false; }
    foreach ($bs as $i => $x) {
        if (is_string($x) && in_array(strtoupper($x), ['ATTACHMENT', 'FILENAME', 'NAME'], true) && $i % 2 === 0) { return true; }
        if (is_array($x) && bmStructurePj($x)) { return true; }
    }
    return false;
}

/** La date d'un e-mail en ISO (l'en-tête Date, sinon l'arrivée dans la boîte). */
function bmDate(?string $entete, ?string $interne): ?string
{
    foreach ([$entete, $interne] as $v) {
        $t = $v ? strtotime((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $v)) : false;
        if ($t !== false) { return date('c', $t); }
    }
    return null;
}

/** Les numéros d'offre cités (« offre n° 409 », « offre 409 », « devis n°409 »). */
function bmOffresCitees(string $t): array
{
    preg_match_all('/\b(?:offre|devis)\s*(?:n\s*[°o.]?\s*|#\s*)?(\d{2,7})\b/iu', $t, $m);
    return array_values(array_unique(array_map('intval', $m[1])));
}

function bmMessages(array $c): array
{
    $s = bmImap($c);
    $dossier = bmDossierDemande($s, (string) ($_GET['dossier'] ?? 'INBOX'));
    $s->ouvrirDossier($dossier);
    $crit = [];
    $filtre = (string) ($_GET['filtre'] ?? '');
    if ($filtre === 'nonlus') { $crit[] = 'UNSEEN'; }
    if ($filtre === 'suivis') { $crit[] = 'FLAGGED'; }
    if ($filtre === 'pj') { $crit[] = 'HEADER Content-Type "multipart/mixed"'; }
    $q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 120));
    if ($q !== '') { $crit[] = 'TEXT'; $crit[] = ['ch' => $q]; }
    if (!$crit) { $crit[] = 'ALL'; }
    // Les critères séparés d'une espace, sans espace en trop à la fin (un serveur strict répondrait BAD).
    $parts = [];
    foreach ($crit as $i => $x) { if ($i > 0) { $parts[] = ' '; } $parts[] = $x; }
    $uids = array_reverse($s->chercher($parts));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $tranche = array_slice($uids, ($page - 1) * BM_PAGE, BM_PAGE);
    $lus = $s->lire($tranche, 'UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID)]');
    $liste = [];
    foreach ($tranche as $uid) {
        $m = $lus[$uid] ?? null;
        if ($m === null) { continue; }
        [$e] = bmMimeCouper((string) ($m['BODY[HEADER]'] ?? ''));
        $fl = array_map('strtolower', array_map('strval', (array) ($m['FLAGS'] ?? [])));
        $objet = bmEntete($e['subject'] ?? '');
        $liste[] = ['uid' => $uid, 'de' => bmAdresses($e['from'] ?? '')[0] ?? null, 'a' => bmAdresses($e['to'] ?? ''), 'objet' => $objet,
            'date' => bmDate($e['date'] ?? null, isset($m['INTERNALDATE']) ? (string) $m['INTERNALDATE'] : null),
            'lu' => in_array('\\seen', $fl, true), 'suivi' => in_array('\\flagged', $fl, true), 'repondu' => in_array('\\answered', $fl, true),
            'pj' => bmStructurePj($m['BODYSTRUCTURE'] ?? null), 'taille' => (int) ($m['RFC822.SIZE'] ?? 0), 'offres' => bmOffresCitees($objet)];
    }
    return ['dossier' => $dossier, 'page' => $page, 'parPage' => BM_PAGE, 'total' => count($uids), 'messages' => $liste];
}

/** Un e-mail : lu en entier (sauf trop lourd), marqué lu, son HTML nettoyé ; ce qu'il dit des offres. */
function bmMessage(string $shop, array $c): array
{
    $s = bmImap($c);
    $dossier = bmDossierDemande($s, (string) ($_GET['dossier'] ?? 'INBOX'));
    $uid = (int) ($_GET['uid'] ?? 0);
    $s->ouvrirDossier($dossier);
    $t = $s->lire([$uid], 'UID FLAGS RFC822.SIZE');
    if (!isset($t[$uid])) { return bmErreur(404, 'Cet e-mail n’est plus dans le dossier.'); }
    $lourd = (int) ($t[$uid]['RFC822.SIZE'] ?? 0) > BM_TAILLE_MAX;
    $brut = (string) ($s->lire([$uid], $lourd ? 'UID BODY.PEEK[HEADER]' : 'UID BODY.PEEK[]')[$uid][$lourd ? 'BODY[HEADER]' : 'BODY[]'] ?? '');
    $lu = bmMimeLire($brut);
    $e = $lu['entetes'];
    $contenu = $lourd ? ['texte' => '', 'html' => null, 'pieces' => [], 'cids' => []] : bmMimeContenu($lu);
    $fl = array_map('strtolower', array_map('strval', (array) ($t[$uid]['FLAGS'] ?? [])));
    if (!in_array('\\seen', $fl, true) && empty($_GET['garder'])) { $s->drapeaux([$uid], '+', '\\Seen'); }
    $base = BM_PREFIXE . '/piece?shop=' . rawurlencode($shop) . '&dossier=' . rawurlencode($dossier) . '&uid=' . $uid . '&partie=';
    $html = $contenu['html'] !== null ? bmHtmlPropre($contenu['html'], $contenu['cids'], static fn ($id) => '{API}' . $base . rawurlencode($id)) : null;
    $objet = bmEntete($e['subject'] ?? '');
    $de = bmAdresses($e['from'] ?? '');
    return ['uid' => $uid, 'dossier' => $dossier, 'objet' => $objet, 'de' => $de[0] ?? null,
        'repondreA' => bmAdresses($e['reply-to'] ?? '')[0] ?? ($de[0] ?? null),
        'a' => bmAdresses($e['to'] ?? ''), 'cc' => bmAdresses($e['cc'] ?? ''), 'date' => bmDate($e['date'] ?? null, null),
        'messageId' => trim((string) ($e['message-id'] ?? '')), 'texte' => $contenu['texte'], 'html' => $html, 'lourd' => $lourd,
        'pieces' => $contenu['pieces'], 'suivi' => in_array('\\flagged', $fl, true), 'repondu' => in_array('\\answered', $fl, true),
        'offres' => bmOffresCitees($objet . "\n" . $contenu['texte']), 'moi' => $c['adresse']];
}

/** Une pièce jointe : ses octets, avec le bon type ; tout ce qui n'est pas une image ou un PDF se télécharge. */
function bmPiece(array $c): never
{
    $s = bmImap($c);
    $dossier = bmDossierDemande($s, (string) ($_GET['dossier'] ?? 'INBOX'));
    $uid = (int) ($_GET['uid'] ?? 0); $partie = (string) ($_GET['partie'] ?? '');
    $s->ouvrirDossier($dossier);
    $brut = (string) ($s->lire([$uid], 'UID BODY.PEEK[]')[$uid]['BODY[]'] ?? '');
    $f = null;
    foreach (bmMimeLire($brut)['feuilles'] as $x) { if ($x['id'] === $partie) { $f = $x; } }
    if ($f === null) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'pièce introuvable']); exit; }
    $type = preg_match('#^[\w.+-]+/[\w.+-]+$#', $f['type']) ? $f['type'] : 'application/octet-stream';
    $sur = preg_match('#^image/(png|jpe?g|gif|webp)$#', $type) || $type === 'application/pdf';
    $nom = $f['nom'] !== '' ? $f['nom'] : ($type === 'message/rfc822' ? 'e-mail.eml' : 'piece-' . $partie);
    header('Content-Type: ' . ($sur ? $type : 'application/octet-stream'));
    header('Content-Length: ' . strlen($f['octets']));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
    header('Content-Disposition: ' . ($sur && empty($_GET['telecharger']) ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\w.\-]/', '_', $nom) . '"; filename*=UTF-8\'\'' . rawurlencode($nom));
    echo $f['octets'];
    exit;
}

/* --- gestes ----------------------------------------------------------------------------------------------------- */

function bmGeste(array $c, array $b): array
{
    $s = bmImap($c);
    $dossier = bmDossierDemande($s, (string) ($b['dossier'] ?? 'INBOX'));
    $uids = array_values(array_filter(array_map('intval', (array) ($b['uids'] ?? [])), static fn ($u) => $u > 0));
    if (!$uids) { return bmErreur(422, 'Aucun e-mail désigné.'); }
    $s->ouvrirDossier($dossier);
    $a = (string) ($b['action'] ?? '');
    switch ($a) {
        case 'lu': $s->drapeaux($uids, '+', '\\Seen'); break;
        case 'nonlu': $s->drapeaux($uids, '-', '\\Seen'); break;
        case 'suivi': $s->drapeaux($uids, '+', '\\Flagged'); break;
        case 'nonsuivi': $s->drapeaux($uids, '-', '\\Flagged'); break;
        case 'archiver':
        case 'corbeille':
            $vers = bmDossierRole($s, $a === 'archiver' ? 'archive' : 'trash');
            if ($vers === null) { return bmErreur(409, $a === 'archiver' ? 'La boîte n’a pas de dossier d’archives.' : 'La boîte n’a pas de corbeille.'); }
            if ($vers === $dossier) { return bmErreur(409, 'Ces e-mails y sont déjà.'); }
            $s->deplacer($uids, $vers);
            break;
        case 'deplacer':
            $vers = bmDossierDemande($s, (string) ($b['vers'] ?? ''));
            if ($vers === $dossier) { return bmErreur(409, 'Ces e-mails y sont déjà.'); }
            $s->deplacer($uids, $vers);
            break;
        default: return bmErreur(422, 'geste inconnu');
    }
    return ['ok' => true, 'action' => $a, 'n' => count($uids)];
}

/* --- envoyer ---------------------------------------------------------------------------------------------------- */

/**
 * Envoie depuis l'adresse du magasin, par son serveur, et en range une copie dans « Envoyés ». $opts : reponse
 * [dossier, uid] (In-Reply-To, et l'e-mail marqué « répondu ») ; transfert [dossier, uid] (l'e-mail joint en .eml) ;
 * offre (l'id : son PDF de TF Buddy joint) ; pieces [{nom, type, contenu}]. Rend [ok, avertissement|null].
 */
function bmEnvoyer(string $shop, array $c, array $a, array $cc, string $objet, string $texte, array $opts = []): array
{
    $pieces = (array) ($opts['pieces'] ?? []);
    $reponse = null; $s = null;
    if (!empty($opts['reponse']) || !empty($opts['transfert'])) {
        $s = bmImap($c);
        $ref = $opts['reponse'] ?? $opts['transfert'];
        $d = bmDossierDemande($s, (string) ($ref['dossier'] ?? 'INBOX'));
        $s->ouvrirDossier($d);
        $u = (int) ($ref['uid'] ?? 0);
        if (!empty($opts['reponse'])) {
            [$e] = bmMimeCouper((string) ($s->lire([$u], 'UID BODY.PEEK[HEADER.FIELDS (MESSAGE-ID REFERENCES)]')[$u]['BODY[HEADER]'] ?? ''));
            $reponse = [trim((string) ($e['message-id'] ?? '')), trim((string) ($e['references'] ?? ''))];
        } else {
            $pieces[] = ['nom' => 'e-mail-transfere.eml', 'type' => 'message/rfc822', 'contenu' => (string) ($s->lire([$u], 'UID BODY.PEEK[]')[$u]['BODY[]'] ?? '')];
        }
    }
    if (!empty($opts['offre'])) {
        [$o, $code, $err] = ocMienne((int) $shop, (int) $opts['offre']);
        if ($o === null) { throw new RuntimeException('l’offre n° ' . (int) $opts['offre'] . ' ne se lit pas (' . $err . ')'); }
        $r = ErpApi::brut('/offers/' . (int) $opts['offre'] . '/document?lang=' . (in_array($opts['langue'] ?? 'fr', ['fr', 'nl', 'en'], true) ? $opts['langue'] : 'fr'));
        if ($r['code'] !== 200 || !str_starts_with((string) $r['corps'], '%PDF')) { throw new RuntimeException('TF Buddy ne rend pas le PDF de l’offre n° ' . (int) $opts['offre'] . ' (il faut un client et au moins un produit)'); }
        $pieces[] = ['nom' => 'Offre n° ' . (int) $opts['offre'] . '.pdf', 'type' => 'application/pdf', 'contenu' => (string) $r['corps']];
    }
    if (array_sum(array_map(static fn ($p) => strlen((string) ($p['contenu'] ?? '')), $pieces)) > BM_ENVOI_MAX) { throw new RuntimeException('les pièces jointes dépassent 15 Mo'); }
    [$message, $mid] = bmComposer(['nom' => $c['nom'], 'adresse' => $c['adresse']], $a, $cc, $objet, $texte, $pieces, $reponse);
    $avert = null;
    try {
        BmSmtp::envoyer($c['smtp'], $c['utilisateur'], $c['motDePasse'], $c['adresse'], array_merge(array_column($a, 'adresse'), array_column($cc, 'adresse')), $message);
    } catch (BmPartiel $e) { $avert = 'Parti, sauf pour : ' . substr($e->getMessage(), strlen('parti, sauf pour : ')); }
    // La copie dans « Envoyés » ; l'original marqué « répondu ». Un échec ici ne défait pas l'envoi : il est dit.
    try {
        $s = $s ?? bmImap($c);
        $env = bmDossierRole($s, 'sent');
        if ($env !== null) { $s->deposer($env, $message); } else { $avert = trim(($avert ?? '') . ' Pas de dossier « Envoyés » : la copie n’est pas rangée.'); }
        if ($reponse !== null && empty($opts['sansDrapeau'])) {
            $d = bmDossierDemande($s, (string) ($opts['reponse']['dossier'] ?? 'INBOX'));
            $s->ouvrirDossier($d);
            $s->drapeaux([(int) $opts['reponse']['uid']], '+', '\\Answered');
        }
    } catch (Throwable $e) { $avert = trim(($avert ?? '') . ' Envoyé, mais la copie n’est pas rangée dans « Envoyés » (' . $e->getMessage() . ').'); }
    return [$mid, $avert];
}

function bmEnvoyerRoute(string $shop, array $c, array $b): array
{
    $a = bmAdresses(is_array($b['a'] ?? null) ? implode(', ', $b['a']) : (string) ($b['a'] ?? ''));
    $cc = bmAdresses(is_array($b['cc'] ?? null) ? implode(', ', $b['cc']) : (string) ($b['cc'] ?? ''));
    if (!$a) { return bmErreur(422, 'Au moins un destinataire, avec une adresse valide.'); }
    if (count($a) + count($cc) > 30) { return bmErreur(422, '30 destinataires au plus.'); }
    $objet = trim(mb_substr((string) ($b['objet'] ?? ''), 0, 250));
    $texte = rtrim(mb_substr((string) ($b['texte'] ?? ''), 0, 100000));
    if ($objet === '' && $texte === '') { return bmErreur(422, 'Un objet ou un texte, au moins.'); }
    $pieces = [];
    foreach (array_slice((array) ($b['pieces'] ?? []), 0, 10) as $p) {
        $o = base64_decode((string) ($p['base64'] ?? ''), true);
        if ($o === false) { return bmErreur(422, 'Une pièce jointe est illisible.'); }
        $pieces[] = ['nom' => mb_substr((string) ($p['nom'] ?? 'piece'), 0, 120), 'type' => (string) ($p['type'] ?? 'application/octet-stream'), 'contenu' => $o];
    }
    $opts = ['pieces' => $pieces, 'offre' => (int) ($b['offre'] ?? 0) ?: null, 'langue' => (string) ($b['langue'] ?? 'fr')];
    if (is_array($b['reponse'] ?? null) && (int) ($b['reponse']['uid'] ?? 0) > 0) { $opts['reponse'] = $b['reponse']; }
    if (is_array($b['transfert'] ?? null) && (int) ($b['transfert']['uid'] ?? 0) > 0) { $opts['transfert'] = $b['transfert']; }
    [$mid, $avert] = bmEnvoyer($shop, $c, $a, $cc, $objet, $texte, $opts);
    return ['ok' => true, 'messageId' => $mid, 'avertissement' => $avert];
}

/* --- modèles ---------------------------------------------------------------------------------------------------- */

function bmModeles(string $shop): array
{
    $l = array_map(static fn ($r) => ['id' => (int) $r['id'], 'nom' => $r['nom'], 'objet' => $r['objet'], 'corps' => $r['corps'], 'maj' => $r['maj_le']],
        Db::rows('SELECT * FROM pil_boite_modele WHERE shop_id = ? ORDER BY nom', [$shop]));
    return ['reseau' => BM_MODELES_BASE, 'magasin' => $l,
        'variables' => ['{prenom}' => 'le prénom du client', '{nom}' => 'son nom', '{objet}' => 'l’objet de son e-mail', '{magasin}' => 'le nom du magasin',
            '{offre}' => 'le n° de l’offre', '{total}' => 'son total TTC', '{jour}' => 'le jour de l’événement']];
}

function bmModelesEcrire(string $shop, array $b): array
{
    $a = (string) ($b['action'] ?? '');
    $id = (int) ($b['id'] ?? 0);
    if ($a === 'supprimer') { Db::exec('DELETE FROM pil_boite_modele WHERE id = ? AND shop_id = ?', [$id, $shop]); return ['ok' => true] + bmModeles($shop); }
    $nom = trim(mb_substr((string) ($b['nom'] ?? ''), 0, 120));
    $objet = trim(mb_substr((string) ($b['objet'] ?? ''), 0, 200));
    $corps = rtrim(mb_substr((string) ($b['corps'] ?? ''), 0, 20000));
    if ($nom === '' || $corps === '') { return bmErreur(422, 'Un nom et un texte, au moins.'); }
    if ($a === 'creer') { Db::exec('INSERT INTO pil_boite_modele (shop_id, nom, objet, corps, cree_le, maj_le) VALUES (?, ?, ?, ?, NOW(), NOW())', [$shop, $nom, $objet, $corps]); }
    elseif ($a === 'modifier') { Db::exec('UPDATE pil_boite_modele SET nom = ?, objet = ?, corps = ?, maj_le = NOW() WHERE id = ? AND shop_id = ?', [$nom, $objet, $corps, $id, $shop]); }
    else { return bmErreur(422, 'action inconnue'); }
    return ['ok' => true] + bmModeles($shop);
}

/** Un modèle rempli : {prenom}, {nom}, {objet}, {magasin}, {offre}, {total}, {jour}. Une variable sans valeur s'efface. */
function bmRemplir(string $t, array $v): string
{
    return (string) preg_replace_callback('/\{(prenom|nom|objet|magasin|offre|total|jour)\}/', static fn ($m) => (string) ($v[$m[1]] ?? ''), $t);
}

function bmPrenom(string $nom): string
{
    $nom = trim($nom);
    if ($nom === '' || str_contains($nom, '@')) { return ''; }
    if (str_contains($nom, ',')) { $p = array_map('trim', explode(',', $nom, 2)); $nom = $p[1] !== '' ? $p[1] : $p[0]; }   // « Dupont, Marie »
    $premier = (string) strtok($nom, ' ');
    return mb_strlen($premier) > 1 && !preg_match('/\d|^(sa|sprl|srl|bv|nv|asbl|vzw|the|le|la|les)$/iu', $premier) ? $premier : '';
}

/* --- messages automatiques -------------------------------------------------------------------------------------- */

function bmModeleBase(string $id): array
{
    foreach (BM_MODELES_BASE as $m) { if ($m['id'] === $id) { return $m; } }
    return ['objet' => '', 'corps' => ''];
}

/** Les règles du magasin, chacune avec son texte (le modèle de départ tant qu'il n'a pas été écrit). */
function bmRegles(string $shop): array
{
    $l = [];
    foreach (Db::rows('SELECT * FROM pil_boite_auto WHERE shop_id = ?', [$shop]) as $r) { $l[$r['regle']] = $r; }
    $out = [];
    foreach (BM_REGLES as $k => $d) {
        $r = $l[$k] ?? null; $m = bmModeleBase($d['modele']);
        $out[] = ['regle' => $k, 'nom' => $d['nom'], 'texte' => $d['texte'], 'actif' => (bool) ($r['actif'] ?? false),
            'objet' => (string) ($r['objet'] ?? $m['objet']), 'corps' => (string) ($r['corps'] ?? $m['corps']), 'jours' => (int) ($r['jours'] ?? $d['jours'])];
    }
    return $out;
}

function bmAuto(string $shop): array
{
    $j = array_map(static fn ($r) => ['regle' => $r['regle'], 'nom' => BM_REGLES[$r['regle']]['nom'] ?? (AU_AUTOS[$r['regle']]['nom'] ?? $r['regle']), 'destinataire' => $r['destinataire'],
        'objet' => $r['objet'], 'le' => $r['envoye_le'], 'erreur' => $r['erreur']],
        Db::rows('SELECT * FROM pil_boite_journal WHERE shop_id = ? ORDER BY envoye_le DESC, id DESC LIMIT 40', [$shop]));
    $h = setting('boiteHorloge', []);
    return ['regles' => bmRegles($shop), 'journal' => $j, 'horloge' => is_array($h) ? ($h['le'] ?? null) : null];
}

function bmAutoEcrire(string $shop, array $b): array
{
    $k = (string) ($b['regle'] ?? '');
    if (!isset(BM_REGLES[$k])) { return bmErreur(422, 'règle inconnue'); }
    $actuel = null;
    foreach (bmRegles($shop) as $r) { if ($r['regle'] === $k) { $actuel = $r; } }
    $objet = trim(mb_substr((string) ($b['objet'] ?? $actuel['objet']), 0, 200));
    $corps = rtrim(mb_substr((string) ($b['corps'] ?? $actuel['corps']), 0, 20000));
    $jours = max(1, min(30, (int) ($b['jours'] ?? $actuel['jours'])));
    $actif = array_key_exists('actif', $b) ? (bool) $b['actif'] : $actuel['actif'];
    if ($corps === '') { return bmErreur(422, 'Le texte du message est vide.'); }
    Db::exec('INSERT INTO pil_boite_auto (shop_id, regle, actif, objet, corps, jours, maj_le) VALUES (?, ?, ?, ?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE actif = VALUES(actif), objet = VALUES(objet), corps = VALUES(corps), jours = VALUES(jours), maj_le = NOW()',
        [$shop, $k, $actif ? 1 : 0, $objet, $corps, $jours]);
    bmJeton();   // l'horloge a besoin de son jeton dès la première règle allumée
    if ($k === 'accuse' && $actif && !$actuel['actif']) {
        // On part de maintenant : les e-mails déjà là ne reçoivent pas d'accusé.
        Db::exec('DELETE FROM pil_boite_etat WHERE shop_id = ?', [$shop]);
    }
    journalAdd('Magasin ' . $shop, 'Boîte mail', null, 'Message automatique « ' . BM_REGLES[$k]['nom'] . ' » ' . ($actif ? 'allumé' : 'éteint'));
    return ['ok' => true] + bmAuto($shop);
}

/** Le jeton de l'horloge (tiré une fois, lu en base par bin/boite_cron.sh). */
function bmJeton(): string
{
    $j = setting('boiteJeton', '');
    if (is_string($j) && strlen($j) >= 32) { return $j; }
    $j = bin2hex(random_bytes(24));
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value', ['boiteJeton', json_encode($j)]);
    return (string) setting('boiteJeton', $j);
}

/** GET /dashboard/boite/cron?jeton= — l'horloge : chaque magasin qui a une règle allumée et une boîte reliée. */
function bmCron(): array
{
    $j = setting('boiteJeton', '');
    if (!is_string($j) || strlen($j) < 32 || !hash_equals($j, (string) ($_GET['jeton'] ?? ''))) { return bmErreur(403, 'jeton refusé'); }
    @set_time_limit(280);
    bmTables();
    $rapport = [];
    foreach (Db::rows('SELECT DISTINCT shop_id FROM pil_boite_auto WHERE actif = 1') as $r) {
        $shop = (string) $r['shop_id'];
        $c = bmCompte($shop);
        if ($c === null) { $rapport[$shop] = 'boîte non reliée'; continue; }
        try { $rapport[$shop] = bmTourner($shop, $c); }
        catch (Throwable $e) { $rapport[$shop] = 'erreur : ' . $e->getMessage(); }
    }
    // Les automatisations du magasin (Paramètres › Mails et automatisations) : le rappel la veille du retrait.
    try { $rapport['automatisations'] = auCron(); } catch (Throwable $e) { $rapport['automatisations'] = 'erreur : ' . $e->getMessage(); }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['boiteHorloge', json_encode(['le' => date('c'), 'rapport' => $rapport], JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'rapport' => $rapport];
}

/** Envoie un message automatique une seule fois (la clé du journal est unique), et le note. */
function bmAutoEnvoyer(string $shop, array $c, string $regle, string $cle, array $a, string $objet, string $texte, ?array $reponse = null): string
{
    $deja = Db::row('SELECT id FROM pil_boite_journal WHERE shop_id = ? AND cle = ?', [$shop, $cle]);
    if ($deja !== null) { return 'déjà'; }
    Db::exec('INSERT INTO pil_boite_journal (shop_id, regle, cle, destinataire, objet, envoye_le) VALUES (?, ?, ?, ?, ?, NOW())', [$shop, $regle, $cle, $a['adresse'], mb_substr($objet, 0, 255)]);
    try {
        // Un accusé automatique répond (In-Reply-To) sans marquer l'e-mail « répondu » : il reste à traiter.
        bmEnvoyer($shop, $c, [$a], [], $objet, $texte, $reponse ? ['reponse' => $reponse, 'sansDrapeau' => true] : []);
        return 'envoyé';
    } catch (Throwable $e) {
        Db::exec('UPDATE pil_boite_journal SET erreur = ? WHERE shop_id = ? AND cle = ?', [mb_substr($e->getMessage(), 0, 255), $shop, $cle]);
        return 'erreur';
    }
}

/** Les règles allumées d'un magasin, une fois. */
function bmTourner(string $shop, array $c): array
{
    $r = [];
    $regles = [];
    foreach (bmRegles($shop) as $x) { if ($x['actif']) { $regles[$x['regle']] = $x; } }
    $vars = ['magasin' => $c['nom']];
    if (isset($regles['accuse'])) { $r['accuse'] = bmTournerAccuse($shop, $c, $regles['accuse'], $vars); }
    if (isset($regles['relance']) || isset($regles['rappel'])) {
        $offres = bmOffres((int) $shop);
        if ($offres === null) { $r['offres'] = 'les offres ne se lisent pas'; }
        else {
            $auj = date('Y-m-d');
            foreach ($offres as $o) {
                $mail = strtolower(trim((string) ($o['client']['email'] ?? '')));
                if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) { continue; }
                $v = $vars + ['prenom' => bmPrenom((string) ($o['client']['contact'] ?? '') ?: (string) ($o['client']['nom'] ?? '')), 'nom' => (string) ($o['client']['nom'] ?? ''),
                    'offre' => (string) $o['id'], 'total' => number_format((float) ($o['total'] ?? 0), 2, ',', ' ') . ' €',
                    'jour' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($o['jour'] ?? '')) ? date('d/m/Y', (int) strtotime($o['jour'])) : '', 'objet' => (string) ($o['nom'] ?? '')];
                $dest = ['nom' => (string) ($o['client']['nom'] ?? ''), 'adresse' => $mail];
                if (isset($regles['relance']) && $o['etat'] === 'cours') {
                    $cree = substr((string) ($o['cree'] ?? ''), 0, 10);
                    $jourOk = !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($o['jour'] ?? '')) || $o['jour'] >= $auj;
                    if ($cree !== '' && $cree <= date('Y-m-d', strtotime('-' . $regles['relance']['jours'] . ' days')) && $jourOk) {
                        $x = $regles['relance'];
                        $r['relance'][] = bmAutoEnvoyer($shop, $c, 'relance', 'relance:' . $o['id'], $dest, bmRemplir($x['objet'], $v), bmRemplir($x['corps'], $v));
                    }
                }
                if (isset($regles['rappel']) && $o['etat'] === 'conv' && ($o['jour'] ?? '') === date('Y-m-d', strtotime('+1 day'))) {
                    $x = $regles['rappel'];
                    $r['rappel'][] = bmAutoEnvoyer($shop, $c, 'rappel', 'rappel:' . $o['id'] . ':' . $o['jour'], $dest, bmRemplir($x['objet'], $v), bmRemplir($x['corps'], $v));
                }
            }
        }
    }
    return $r;
}

/** Les offres du magasin, par la route des Offres (même lecture, même cache). */
function bmOffres(int $shop): ?array
{
    if (!function_exists('ep_offres_client')) { return null; }
    $g = $_GET; $code = http_response_code();
    $_GET = ['shop' => (string) $shop];
    try { $r = ep_offres_client(); } catch (Throwable $e) { $r = null; }
    finally { $_GET = $g; if (is_int($code)) { http_response_code($code); } }
    return is_array($r['offres'] ?? null) ? $r['offres'] : null;
}

/**
 * L'accusé de réception : les e-mails arrivés depuis le dernier passage (UID au-delà du dernier vu), un accusé par
 * expéditeur sur la période ; jamais aux envois automatiques, listes, « ne pas répondre », ni à la boîte elle-même.
 */
function bmTournerAccuse(string $shop, array $c, array $regle, array $vars): array
{
    $s = bmImap($c);
    $s->ouvrirDossier('INBOX');
    $val = (int) ($s->infos['uidvalidity'] ?? 0); $suivant = (int) ($s->infos['uidnext'] ?? 0);
    $etat = Db::row('SELECT * FROM pil_boite_etat WHERE shop_id = ?', [$shop]);
    if ($etat === null || (int) $etat['uidvalidity'] !== $val || $suivant === 0) {
        $dernier = max(0, $suivant - 1);
        if ($suivant === 0) { $t = $s->chercher(['ALL']); $dernier = $t ? max($t) : 0; }
        Db::exec('INSERT INTO pil_boite_etat (shop_id, uidvalidity, dernier_uid, maj_le) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE uidvalidity = VALUES(uidvalidity), dernier_uid = VALUES(dernier_uid), maj_le = NOW()',
            [$shop, $val, $dernier]);
        return ['départ' => $dernier];
    }
    $dernier = (int) $etat['dernier_uid'];
    $nouveaux = array_slice(array_values(array_filter($s->chercher(['UID ' . ($dernier + 1) . ':*']), static fn ($u) => $u > $dernier)), 0, 30);
    $out = [];
    if ($nouveaux) {
        $l = $s->lire($nouveaux, 'UID BODY.PEEK[HEADER.FIELDS (FROM REPLY-TO SUBJECT MESSAGE-ID AUTO-SUBMITTED PRECEDENCE LIST-ID LIST-UNSUBSCRIBE X-AUTO-RESPONSE-SUPPRESS RETURN-PATH X-LOOP)]');
        foreach ($nouveaux as $uid) {
            [$e] = bmMimeCouper((string) ($l[$uid]['BODY[HEADER]'] ?? ''));
            $de = bmAdresses($e['reply-to'] ?? '')[0] ?? (bmAdresses($e['from'] ?? '')[0] ?? null);
            $robot = $de === null || $de['adresse'] === $c['adresse']
                || preg_match('/^(no-?reply|ne-?pas-?repondre|mailer-daemon|postmaster|bounce|notifications?)\b/i', $de['adresse'])
                || (isset($e['auto-submitted']) && strtolower($e['auto-submitted']) !== 'no')
                || preg_match('/bulk|list|junk/i', $e['precedence'] ?? '') || isset($e['list-id']) || isset($e['list-unsubscribe'])
                || isset($e['x-auto-response-suppress']) || trim($e['return-path'] ?? 'x') === '<>' || isset($e['x-loop']);
            if (!$robot) {
                $recent = Db::row('SELECT id FROM pil_boite_journal WHERE shop_id = ? AND regle = ? AND destinataire = ? AND envoye_le > ? AND erreur IS NULL',
                    [$shop, 'accuse', $de['adresse'], date('Y-m-d H:i:s', strtotime('-' . $regle['jours'] . ' days'))]);
                if ($recent === null) {
                    $objet = bmEntete($e['subject'] ?? '');
                    $v = $vars + ['prenom' => bmPrenom($de['nom']), 'nom' => $de['nom'], 'objet' => $objet];
                    $out[] = bmAutoEnvoyer($shop, $c, 'accuse', 'accuse:' . $val . ':' . $uid, $de, bmRemplir($regle['objet'], $v), bmRemplir($regle['corps'], $v), ['dossier' => 'INBOX', 'uid' => $uid]);
                    $s->ouvrirDossier('INBOX');
                }
            }
            Db::exec('UPDATE pil_boite_etat SET dernier_uid = ?, maj_le = NOW() WHERE shop_id = ?', [$uid, $shop]);
        }
    }
    return $out;
}
