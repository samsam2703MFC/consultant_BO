<?php
declare(strict_types=1);

/**
 * Le portail Commercial (domaine `commercial/`, tables `sm_`, 19/11/2026) — l'outil du commercial B2B des magasins.
 * Maquettes validées : docs/maquettes/portail-commercial/. Page : /commercial/ ; API : /commercial/api/cockpit/commercial/…
 *
 * Deux sections. BUREAUX · WEBSHOP : les leads Meta par magasin, l'assistant « Nouveau bureau », les offres par
 * magasin, les bons et les catalogues envoyés depuis hello@atelierby.be, les échantillons commandés aux magasins, le
 * tableau de bord. B2B : le suivi des offres par secteur et par magasin, et les gains du commercial (commissions).
 *
 * Ce que le portail LIT ailleurs, jamais dans les tables d'un autre domaine : les leads par MétaDonnée (ep_md_leads,
 * Marketing), les catalogues des magasins par le
 * relais du Marketing (cmRoute), les tickets pro par le relevé du panel (vpLu / vpJour, src/ventes_pro.php), les
 * magasins par ep_stores. Ce qu'il ÉCRIT : ses tables sm_ et son réglage `smReglages`. La Prospection (liste de
 * démarchage, ep_prospection ; types d'offre, prOffres) est dans ce domaine depuis le 19/11/2026 : src/prospection.php.
 *
 * Pas encore d'API, dit tel quel à l'écran (« à brancher ») : le compte du bureau au webshop et sa tournée (TF Buddy,
 * à définir avec Szymon), la commande d'échantillon dans le système de commande (elle part au magasin par e-mail en
 * attendant), les cartes de fidélité office, les bons en caisse (ils se créent dans Promotions du magasin).
 */

const SM_TYPES = ['bureau', 'b2b'];
const SM_STATUTS = ['prospect', 'bureau_cree', 'client', 'perdu'];
const SM_WEBSHOP = ['a_creer', 'demande', 'cree', 'connecte'];
const SM_ECH_STATUTS = ['a_envoyer', 'commande', 'livre', 'converti', 'sans_suite'];
const SM_MODELES = ['bienvenue' => 'Bienvenue bureau', 'rdv' => 'Confirmation de rendez-vous', 'offre' => 'Offre', 'catalogue' => 'Catalogue', 'echantillon' => 'Échantillon', 'relance' => 'Relance'];
const SM_LANGUES = ['fr', 'nl'];
const SM_EXPEDITEUR = 'L’Atelier By — Ventes aux professionnels <hello@atelierby.be>';
const SM_TVA = 6.0;
const SM_OBJECTIF = 5000.0;     // le CA HT mensuel que le commercial doit apporter par ses nouveaux clients (réglable)            // le HT des tickets se déduit au taux alimentaire (la TVA d'un ticket n'est pas gardée seule)
const SM_JOURS_HISTO = 120;    // nouveau client : 1re commande dans la fenêtre, aucune dans les jours d'avant

/* --- tables ----------------------------------------------------------------------------------------------------- */

function smTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS sm_client ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(12) NOT NULL DEFAULT "bureau", shop_id VARCHAR(16) NOT NULL, secteur VARCHAR(16) NOT NULL DEFAULT "",'
        . 'entreprise VARCHAR(200) NOT NULL, tva VARCHAR(32) NOT NULL DEFAULT "", contact VARCHAR(160) NOT NULL DEFAULT "", fonction VARCHAR(80) NOT NULL DEFAULT "",'
        . 'email VARCHAR(190) NOT NULL DEFAULT "", telephone VARCHAR(40) NOT NULL DEFAULT "", adresse VARCHAR(255) NOT NULL DEFAULT "", cp VARCHAR(10) NOT NULL DEFAULT "",'
        . 'personnes INT NULL, lead_id INT NULL, lead_erp VARCHAR(40) NOT NULL DEFAULT "", tournee VARCHAR(160) NOT NULL DEFAULT "", cle_panel VARCHAR(40) NOT NULL DEFAULT "",'
        . 'webshop VARCHAR(12) NOT NULL DEFAULT "a_creer", bon_code VARCHAR(40) NOT NULL DEFAULT "", carte_office TINYINT NOT NULL DEFAULT 0,'
        . 'statut VARCHAR(16) NOT NULL DEFAULT "bureau_cree", langue CHAR(2) NOT NULL DEFAULT "fr", note TEXT NULL, debut DATE NOT NULL,'
        . 'cree_le DATETIME NOT NULL, maj_le DATETIME NOT NULL, KEY (shop_id), KEY (lead_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS sm_offre ('
        . 'offre_id VARCHAR(16) NOT NULL, shop_id VARCHAR(16) NOT NULL, prix VARCHAR(120) NOT NULL DEFAULT "", conditions VARCHAR(255) NOT NULL DEFAULT "",'
        . 'statut VARCHAR(12) NOT NULL DEFAULT "actif", maj_le DATETIME NOT NULL, PRIMARY KEY (offre_id, shop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS sm_bon ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, shop_id VARCHAR(16) NOT NULL DEFAULT "", avantage VARCHAR(160) NOT NULL,'
        . 'jusqu_au DATE NULL, jours INT NULL, usage_unique TINYINT NOT NULL DEFAULT 1, utilises INT NOT NULL DEFAULT 0, actif TINYINT NOT NULL DEFAULT 1,'
        . 'cree_le DATETIME NOT NULL, UNIQUE KEY (code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS sm_echantillon ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, prospect VARCHAR(200) NOT NULL, contact VARCHAR(160) NOT NULL DEFAULT "",'
        . 'secteur VARCHAR(16) NOT NULL DEFAULT "", shop_id VARCHAR(16) NOT NULL, contenu TEXT NOT NULL, le DATETIME NULL, mode VARCHAR(10) NOT NULL DEFAULT "livraison",'
        . 'adresse VARCHAR(255) NOT NULL DEFAULT "", cout DECIMAL(8,2) NULL, statut VARCHAR(12) NOT NULL DEFAULT "a_envoyer", envoye_le DATETIME NULL,'
        . 'note VARCHAR(255) NOT NULL DEFAULT "", cree_le DATETIME NOT NULL, KEY (shop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS sm_envoi ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, modele VARCHAR(16) NOT NULL, langue CHAR(2) NOT NULL DEFAULT "fr", client_id INT NULL, shop_id VARCHAR(16) NOT NULL DEFAULT "",'
        . 'secteur VARCHAR(16) NOT NULL DEFAULT "", offre_id VARCHAR(16) NOT NULL DEFAULT "", bon_code VARCHAR(40) NOT NULL DEFAULT "", destinataire VARCHAR(190) NOT NULL,'
        . 'sujet VARCHAR(255) NOT NULL, ok TINYINT NOT NULL DEFAULT 0, erreur VARCHAR(255) NOT NULL DEFAULT "", le DATETIME NOT NULL, KEY (le)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS sm_modele ('
        . 'id VARCHAR(16) NOT NULL, langue CHAR(2) NOT NULL, sujet VARCHAR(255) NOT NULL, corps TEXT NOT NULL, bouton VARCHAR(80) NOT NULL DEFAULT "",'
        . 'maj_le DATETIME NOT NULL, PRIMARY KEY (id, langue)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* --- réglages : commissions, expéditeur, zones ------------------------------------------------------------------- */

/**
 * Les gains par défaut (19/11/2026) : 20 % des commandes le premier mois, 10 % les mois 2 et 3, puis 5 % jusqu'à la fin
 * de la première année (12 mois depuis la 1re commande).
 */
function smGainsDefaut(): array
{
    return ['taux1' => 20.0, 'mois1' => 1, 'taux2' => 10.0, 'mois2' => 3, 'taux3' => 5.0, 'duree' => 12, 'min' => 0.0];
}

/**
 * Une règle de gains nettoyée : trois paliers — `taux1` % jusqu'au mois `mois1`, `taux2` % jusqu'au mois `mois2`,
 * `taux3` % jusqu'au mois `duree` (0 = sans fin), comptés depuis la 1re commande — et la commande minimum en € HT.
 */
function smRegleGains(array $x, array $base): array
{
    $n = static fn ($v, float $lo, float $hi, float $d) => is_numeric($v) ? max($lo, min($hi, round((float) $v, 2))) : $d;
    $mois1 = (int) $n($x['mois1'] ?? null, 0, 36, (float) $base['mois1']);
    return ['taux1' => $n($x['taux1'] ?? null, 0, 100, (float) $base['taux1']), 'mois1' => $mois1,
        'taux2' => $n($x['taux2'] ?? null, 0, 100, (float) $base['taux2']), 'mois2' => max($mois1, (int) $n($x['mois2'] ?? null, 0, 60, (float) ($base['mois2'] ?? $mois1))),
        'taux3' => $n($x['taux3'] ?? null, 0, 100, (float) ($base['taux3'] ?? $base['taux2'])), 'duree' => (int) $n($x['duree'] ?? null, 0, 120, (float) $base['duree']),
        'min' => $n($x['min'] ?? null, 0, 100000, (float) $base['min'])];
}

function smReglages(): array
{
    $s = setting('smReglages', []);
    $s = is_array($s) ? $s : [];
    $defaut = smRegleGains(is_array($s['gains']['defaut'] ?? null) ? $s['gains']['defaut'] : [], smGainsDefaut());
    $secteurs = [];
    foreach ((array) ($s['gains']['secteurs'] ?? []) as $k => $r) {
        if (is_array($r) && preg_match('/^[a-z]{2,16}$/', (string) $k)) { $secteurs[(string) $k] = smRegleGains($r, $defaut); }
    }
    $exp = trim((string) ($s['expediteur'] ?? ''));
    return [
        'gains' => ['defaut' => $defaut, 'secteurs' => $secteurs,
            'plancher' => is_numeric($s['gains']['plancher'] ?? null) ? max(0.0, (float) $s['gains']['plancher']) : 0.0,
            'tva' => is_numeric($s['gains']['tva'] ?? null) ? max(0.0, min(25.0, (float) $s['gains']['tva'])) : SM_TVA,
            // l'objectif (19/11/2026) : ramener au moins 5 000 € HT par mois de nouveaux clients ; son coût mensuel et la
            // marge prise pour son ROI (0 : le ROI se lit sur le CA HT)
            'objectif' => is_numeric($s['gains']['objectif'] ?? null) ? max(0.0, (float) $s['gains']['objectif']) : SM_OBJECTIF,
            'cout' => is_numeric($s['gains']['cout'] ?? null) ? max(0.0, (float) $s['gains']['cout']) : 0.0,
            'marge' => is_numeric($s['gains']['marge'] ?? null) ? max(0.0, min(100.0, (float) $s['gains']['marge'])) : 0.0,
            // les commissions du taux de départ (20 % le premier mois) sont à la charge du franchisé du magasin
            'departFranchise' => array_key_exists('departFranchise', (array) ($s['gains'] ?? [])) ? (bool) $s['gains']['departFranchise'] : true],
        'expediteur' => $exp !== '' ? $exp : SM_EXPEDITEUR,
        'signature' => ['nom' => (string) ($s['signature']['nom'] ?? ''), 'fonction' => (string) ($s['signature']['fonction'] ?? 'Ventes aux professionnels'),
            'telephone' => (string) ($s['signature']['telephone'] ?? ''), 'site' => (string) ($s['signature']['site'] ?? '')],
        'webshop' => (string) ($s['webshop'] ?? ''),
        'zones' => array_filter((array) ($s['zones'] ?? []), 'is_array'),
        'cps' => array_filter((array) ($s['cps'] ?? []), 'is_array'),
    ];
}

/** La règle de gains d'un secteur : celle du secteur si elle est réglée, sinon celle par défaut. */
function smRegleSecteur(array $gains, string $secteur): array
{
    return $gains['secteurs'][$secteur] ?? $gains['defaut'];
}

/**
 * Les zones proposées (annexe de la feuille de mission, 19/11/2026) : les communes à moins de 12 km du magasin le plus
 * proche. Rangées par le nom du magasin ; la marque les remplace par magasin dans les Paramètres (`zones`).
 */
function smZonesDefaut(): array
{
    return [
        'halle' => ['Beersel', 'Braine-le-Château', 'Braine-l’Alleud', 'Drogenbos', 'Forest', 'Gooik', 'Halle', 'Hennuyères', 'Herfelingen', 'Itterbeek', 'Ittre',
            'Lennik', 'Linkebeek', 'Pepingen', 'Petit-Enghien', 'Rebecq', 'Rhode-Saint-Genèse', 'Schepdaal', 'Sint-Pieters-Leeuw', 'Tubize', 'Uccle', 'Waterloo'],
        'corbais' => ['Baisy-Thy', 'Bousval', 'Chastre', 'Chaumont-Gistoux', 'Court-Saint-Étienne', 'Grez-Doiceau', 'Lasne', 'Malèves', 'Mont-Saint-Guibert',
            'Opprebais', 'Orbais', 'Ottenburg', 'Ottignies-Louvain-la-Neuve', 'Piètrebais', 'Rixensart', 'Thorembais-Saint-Trond', 'Walhain', 'Wavre'],
        'sombreffe' => ['Fleurus', 'Gembloux', 'Jemeppe-sur-Sambre', 'Sambreville', 'Sombreffe', 'Temploux', 'Villers-la-Ville'],
        'gosselies' => ['Aiseau-Presles', 'Chapelle-lez-Herlaimont', 'Charleroi', 'Châtelet', 'Courcelles', 'Farciennes', 'Fontaine-l’Évêque', 'Les Bons Villers',
            'Loverval', 'Montigny-le-Tilleul', 'Pont-à-Celles'],
    ];
}

/**
 * Les codes postaux de chaque zone (la même proposition, 19/11/2026) : un code partagé entre deux zones suit sa commune
 * (1450 Chastre → Corbais, 5030 Gembloux et 6220 Fleurus → Sombreffe, 6250 Aiseau-Presles → Gosselies).
 */
function smCpDefaut(): array
{
    return [
        'halle' => '1180 1190 1410 1420 1421 1430 1440 1460 1461 1480 1500 1501 1502 1540 1600 1601 1602 1620 1630 1640 1650 1651 1652 1653 1654 1670 1671 1673 1674 1701 1703 1750 1755 7090 7850',
        'corbais' => '1300 1301 1315 1325 1330 1331 1340 1341 1342 1348 1360 1380 1390 1435 1450 1457 1470 1490 3040 5031',
        'sombreffe' => '1495 5020 5030 5032 5060 5140 5190 6220 6221 6222 6223 6224',
        'gosselies' => '6000 6001 6010 6020 6030 6031 6032 6040 6041 6042 6043 6044 6060 6061 6110 6111 6140 6141 6142 6180 6181 6182 6183 6200 6210 6211 6230 6238 6240 6250 6280 7160',
    ];
}

/** Les codes postaux d'un magasin : ceux réglés par la marque, sinon la proposition rangée sous son nom. */
function smCpsDe(string $shopId, string $nom, array $reglees): array
{
    if (isset($reglees[$shopId]) && is_array($reglees[$shopId])) { return array_values(array_filter(array_map(static fn ($c) => preg_replace('/\D/', '', (string) $c), $reglees[$shopId]), static fn ($c) => strlen($c) === 4)); }
    $n = mb_strtolower($nom);
    foreach (smCpDefaut() as $cle => $l) { if (str_contains($n, $cle)) { return explode(' ', $l); } }
    return [];
}

/** Le magasin d'un code postal : son id, ou null hors des zones. */
function smMagasinPourCp(string $cp, array $mags): ?string
{
    $cp = (string) preg_replace('/\D/', '', $cp);
    if (strlen($cp) !== 4) { return null; }
    foreach ($mags as $id => $m) { if (in_array($cp, $m['cps'] ?? [], true)) { return (string) $id; } }
    return null;
}

/** Le code postal belge d'une adresse (« Rue X 4, 1360 Orbais, Belgique ») ; '' sans code. */
function smCpAdresse(string $adresse): string
{
    return preg_match('/\b([1-9]\d{3})\b(?=\s+\p{L})/u', $adresse, $m) ? $m[1] : '';
}

/**
 * GET /commercial/trouver?q=nom&cp= — le magasin d'un bureau : par son code postal ; par son nom, cherché dans Google
 * Places (adresse, code postal) quand la clé Google est réglée. Rend le magasin et, pour un nom, les lieux trouvés.
 */
function ep_sm_trouver(): array
{
    $mags = smMagasins();
    $vue = static function (string $cp) use ($mags): array {
        $sid = smMagasinPourCp($cp, $mags);
        return ['cp' => $cp, 'shopId' => $sid, 'magasin' => $sid !== null ? $mags[$sid]['nom'] : null, 'horsZone' => $cp !== '' && $sid === null];
    };
    $cp = (string) preg_replace('/\D/', '', (string) ($_GET['cp'] ?? ''));
    $q = smTexte($_GET['q'] ?? '', 120);
    $out = $vue($cp) + ['lieux' => [], 'google' => class_exists('GoogleApi') && GoogleApi::configured()];
    if ($q !== '' && $out['google']) {
        $r = GoogleApi::chercher($q . ($cp !== '' ? ' ' . $cp : '') . ' Belgique');
        foreach (array_slice((array) $r, 0, 5) as $l) {
            if (!is_array($l)) { continue; }
            $adr = (string) ($l['adresse'] ?? $l['formattedAddress'] ?? '');
            $out['lieux'][] = ['nom' => (string) ($l['nom'] ?? ($l['displayName']['text'] ?? $l['displayName'] ?? '')), 'adresse' => $adr] + $vue(smCpAdresse($adr));
        }
    }
    return $out;
}

/** La zone d'un magasin : celle réglée par la marque, sinon la proposition rangée sous son nom. */
function smZoneDe(string $shopId, string $nom, array $reglees): array
{
    if (isset($reglees[$shopId]) && is_array($reglees[$shopId])) { return array_values(array_filter(array_map('strval', $reglees[$shopId]), 'strlen')); }
    $n = mb_strtolower($nom);
    foreach (smZonesDefaut() as $cle => $communes) { if (str_contains($n, $cle)) { return $communes; } }
    return [];
}

/** Les magasins ouverts : id, nom court, zone. */
function smMagasins(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $reg = smReglages();
    $cache = [];
    foreach (ep_stores() as $s) {
        $id = (string) ($s['id'] ?? '');
        if ($id === '' || (isset($s['status']) && stripos((string) $s['status'], 'ferm') !== false)) { continue; }
        $nom = trim((string) preg_replace('/^.*?\s[-—–]\s/u', '', (string) ($s['nom'] ?? $id)));
        $cache[$id] = ['id' => $id, 'nom' => $nom !== '' ? $nom : $id, 'zone' => smZoneDe($id, (string) ($s['nom'] ?? ''), $reg['zones']),
            'cps' => smCpsDe($id, (string) ($s['nom'] ?? ''), $reg['cps'])];
    }
    return $cache;
}

/** Les secteurs : les types d'offre de Prospection (Marketing), leur nom, leur argument. */
function smSecteurs(): array
{
    $out = [];
    foreach (prOffres() as $o) { $out[$o['id']] = ['id' => $o['id'], 'nom' => $o['nom'], 'pitch' => $o['pitch'], 'contenu' => $o['contenu']]; }
    return $out;
}

/* --- petites aides ---------------------------------------------------------------------------------------------- */

function smTexte(mixed $v, int $max): string
{
    return mb_substr(trim((string) $v), 0, $max);
}

function smEmail(mixed $v): string
{
    $v = mb_strtolower(trim((string) $v));
    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
}

/** Un code de bon : lettres, chiffres et tirets, en majuscules, 4 à 30 caractères. */
function smCodeBon(string $base): string
{
    $s = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base));
    return substr(trim($s, '-'), 0, 30);
}

/** Le HT d'un montant TTC au taux réglé. */
function smHt(float $ttc, float $tva): float
{
    return round($ttc / (1 + $tva / 100), 2);
}

/** Les tickets pro d'un magasin un jour : [heure, clé de compte, montant TTC net] ; null sans relevé lisible. */
function smTicketsJour(int $sid, string $j, bool $lire, int &$appels, int $budget): ?array
{
    $b = $lire ? vpJour($sid, $j, true, $appels, $budget) : vpLu($sid, $j);
    if ($b === null) { return null; }
    return array_map(static fn ($t) => [(string) $t[0], (string) $t[1], vpNet($t)], (array) ($b['t'] ?? []));
}

/* --- configuration de l'écran ----------------------------------------------------------------------------------- */

/** GET /commercial/config */
function ep_sm_config(): array
{
    smTables();
    $reg = smReglages();
    $smtp = class_exists('Smtp') ? Smtp::configured() : false;
    $cockpit = (string) (Db::config()['cockpitUrl'] ?? '');
    return ['magasins' => array_values(smMagasins()), 'secteurs' => array_values(smSecteurs()), 'modeles' => SM_MODELES,
        'reglages' => $reg, 'smtp' => ['configure' => $smtp, 'expediteur' => $reg['expediteur']],
        'cockpit' => $cockpit !== '' ? $cockpit : '/consulant_bo/',
        'aBrancher' => ['webshop' => 'Le compte du bureau au webshop et sa tournée : API de TF Buddy à définir avec Szymon.',
            'echantillon' => 'La commande d’échantillon dans le système de commande : en attendant, elle part au magasin par e-mail.',
            'cartes' => 'Les cartes de fidélité office : à brancher sur le programme de fidélité.',
            'bons' => 'Les bons en caisse se créent dans Promotions du magasin ; ici, on les range et on les envoie.']];
}

/* --- leads Meta, lus par MétaDonnée ------------------------------------------------------------------------------ */

/** La famille d'un statut de lead pour le portail : a_traiter, contacte, cree, connecte, perdu. */
function smFamilleLead(array $l): string
{
    $s = (string) ($l['statut'] ?? '');
    if ($s === 'perdu') { return 'perdu'; }
    if ($s === 'bureau_connecte') { return 'connecte'; }
    if ($s === 'bureau_cree') { return 'cree'; }
    if ($s === 'contacte') { return 'contacte'; }
    return 'a_traiter';
}

/** GET /commercial/leads?jours=30&shop=&famille= */
function ep_sm_leads(): array
{
    smTables();
    $garde = $_GET;
    $_GET = ['jours' => (string) max(1, min(365, (int) ($garde['jours'] ?? 30))), 'limite' => '500', 'filtre' => 'tous'];
    try { $r = ep_md_leads(); } finally { $_GET = $garde; }
    $leads = (array) ($r['leads'] ?? []);
    $portail = [];
    foreach (Db::rows('SELECT id, lead_id FROM sm_client WHERE lead_id IS NOT NULL') as $c) { $portail[(int) $c['lead_id']] = (int) $c['id']; }
    $mags = smMagasins();
    $par = [];
    foreach ($mags as $id => $m) { $par[$id] = ['id' => $id, 'nom' => $m['nom'], 'leads' => 0, 'a_traiter' => 0, 'contacte' => 0, 'cree' => 0, 'connecte' => 0, 'perdu' => 0]; }
    $comptes = ['tous' => 0, 'a_traiter' => 0, 'contacte' => 0, 'cree' => 0, 'connecte' => 0, 'perdu' => 0];
    $shop = (string) ($garde['shop'] ?? '');
    $fam = (string) ($garde['famille'] ?? '');
    $liste = [];
    foreach ($leads as $l) {
        $f = smFamilleLead($l);
        $sid = (string) ($l['shopId'] ?? '');
        $parZone = false;
        if ($sid === '' && ($z = smMagasinPourCp((string) ($l['cp'] ?? ''), $mags)) !== null) { $sid = $z; $parZone = true; }
        if (isset($par[$sid])) { $par[$sid]['leads']++; $par[$sid][$f]++; }
        if ($shop !== '' && $sid !== $shop) { continue; }
        $comptes['tous']++; $comptes[$f]++;
        if ($fam !== '' && $fam !== $f) { continue; }
        $liste[] = ['id' => (int) $l['id'], 'recu' => $l['recu'] ?? null, 'plateforme' => $l['plateforme'] ?? '', 'form' => $l['form'] ?? '',
            'entreprise' => $l['entreprise'] ?? '', 'contact' => $l['contact'] ?? '', 'telephone' => $l['telephone'] ?? '', 'email' => $l['email'] ?? '',
            'cp' => $l['cp'] ?? '', 'shopId' => $sid, 'magasin' => $parZone ? $mags[$sid]['nom'] : ($l['magasin'] ?? ''), 'shopPar' => $parZone ? 'zone' : ($l['shopPar'] ?? ''), 'etat' => $l['etat'] ?? '',
            'statut' => $l['statut'] ?? '', 'famille' => $f, 'retard' => $l['retard'] ?? null, 'erpId' => $l['erpId'] ?? null, 'lien' => $l['lien'] ?? null,
            'clientId' => $portail[(int) $l['id']] ?? null];
    }
    return ['jours' => (int) ($garde['jours'] ?? 30), 'parMagasin' => array_values($par), 'comptes' => $comptes, 'leads' => array_slice($liste, 0, 300),
        'source' => 'MétaDonnée (Marketing) : leads Meta, magasin attribué, statut relu dans l’ERP'];
}

/* --- clients du portail (bureaux et clients B2B) ----------------------------------------------------------------- */

function smClientVue(array $c, array $mags, array $secteurs): array
{
    return ['id' => (int) $c['id'], 'type' => $c['type'], 'shopId' => $c['shop_id'], 'magasin' => $mags[$c['shop_id']]['nom'] ?? $c['shop_id'],
        'secteur' => $c['secteur'], 'secteurNom' => $secteurs[$c['secteur']]['nom'] ?? '', 'entreprise' => $c['entreprise'], 'tva' => $c['tva'],
        'contact' => $c['contact'], 'fonction' => $c['fonction'], 'email' => $c['email'], 'telephone' => $c['telephone'], 'adresse' => $c['adresse'], 'cp' => $c['cp'],
        'personnes' => $c['personnes'] !== null ? (int) $c['personnes'] : null, 'leadId' => $c['lead_id'] !== null ? (int) $c['lead_id'] : null, 'leadErp' => $c['lead_erp'],
        'tournee' => $c['tournee'], 'clePanel' => $c['cle_panel'], 'webshop' => $c['webshop'], 'bon' => $c['bon_code'], 'carteOffice' => (bool) $c['carte_office'],
        'statut' => $c['statut'], 'langue' => $c['langue'], 'note' => (string) ($c['note'] ?? ''), 'debut' => $c['debut'], 'creeLe' => $c['cree_le']];
}

/** GET /commercial/clients?shop= */
function ep_sm_clients(): array
{
    smTables();
    $shop = (string) ($_GET['shop'] ?? '');
    $rows = $shop !== '' ? Db::rows('SELECT * FROM sm_client WHERE shop_id = ? ORDER BY cree_le DESC', [$shop]) : Db::rows('SELECT * FROM sm_client ORDER BY cree_le DESC');
    $mags = smMagasins(); $sec = smSecteurs();
    return ['clients' => array_map(static fn ($c) => smClientVue($c, $mags, $sec), $rows)];
}

/** Les champs d'un client lus dans le corps, nettoyés ; seuls ceux présents (PUT) ou tous (POST). */
function smClientChamps(array $b, bool $tous): array
{
    $mags = smMagasins(); $sec = smSecteurs();
    $c = [];
    $set = static function (string $k) use ($b, $tous): bool { return $tous || array_key_exists($k, $b); };
    if ($set('type')) { $c['type'] = in_array($b['type'] ?? '', SM_TYPES, true) ? $b['type'] : 'bureau'; }
    if ($set('shopId')) {
        $s = (string) ($b['shopId'] ?? '');
        if (!isset($mags[$s])) { throw new InvalidArgumentException('magasin inconnu'); }
        $c['shop_id'] = $s;
    }
    if ($set('secteur')) { $s = (string) ($b['secteur'] ?? ''); $c['secteur'] = isset($sec[$s]) ? $s : ''; }
    foreach (['entreprise' => 200, 'tva' => 32, 'contact' => 160, 'fonction' => 80, 'telephone' => 40, 'adresse' => 255, 'cp' => 10, 'tournee' => 160, 'leadErp' => 40] as $k => $max) {
        if ($set($k)) { $c[$k === 'leadErp' ? 'lead_erp' : $k] = smTexte($b[$k] ?? '', $max); }
    }
    if ($set('email')) { $c['email'] = smEmail($b['email'] ?? ''); }
    if ($set('personnes')) { $c['personnes'] = is_numeric($b['personnes'] ?? null) && (int) $b['personnes'] > 0 ? (int) $b['personnes'] : null; }
    if ($set('leadId')) { $c['lead_id'] = is_numeric($b['leadId'] ?? null) && (int) $b['leadId'] > 0 ? (int) $b['leadId'] : null; }
    if ($set('clePanel')) { $k = (string) ($b['clePanel'] ?? ''); $c['cle_panel'] = preg_match('/^c\d{1,12}$/', $k) ? $k : ''; }
    if ($set('webshop')) { $c['webshop'] = in_array($b['webshop'] ?? '', SM_WEBSHOP, true) ? $b['webshop'] : 'a_creer'; }
    if ($set('statut')) { $c['statut'] = in_array($b['statut'] ?? '', SM_STATUTS, true) ? $b['statut'] : 'bureau_cree'; }
    if ($set('langue')) { $c['langue'] = in_array($b['langue'] ?? '', SM_LANGUES, true) ? $b['langue'] : 'fr'; }
    if ($set('carteOffice')) { $c['carte_office'] = !empty($b['carteOffice']) ? 1 : 0; }
    if ($set('bon')) { $c['bon_code'] = smCodeBon((string) ($b['bon'] ?? '')); }
    if ($set('note')) { $c['note'] = smTexte($b['note'] ?? '', 2000); }
    if ($set('debut')) { $d = (string) ($b['debut'] ?? ''); $c['debut'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d'); }
    return $c;
}

/**
 * POST /commercial/clients — l'assistant « Nouveau bureau » : le bureau (ou le client B2B), son bon de bienvenue s'il
 * est demandé, puis l'e-mail de bienvenue s'il est demandé. Le compte au webshop reste « à créer » tant que l'API de
 * TF Buddy n'existe pas.
 */
function wr_sm_client_creer(): array
{
    smTables();
    $b = body();
    try { $c = smClientChamps($b, true); } catch (InvalidArgumentException $e) { http_response_code(422); return ['error' => $e->getMessage()]; }
    if ($c['entreprise'] === '') { http_response_code(422); return ['error' => 'le nom de l’entreprise manque']; }
    $bon = is_array($b['bonCreer'] ?? null) ? $b['bonCreer'] : null;
    if ($bon !== null && trim((string) ($bon['avantage'] ?? '')) !== '') {
        $code = smCodeBon((string) (($bon['code'] ?? '') !== '' ? $bon['code'] : 'BIENVENUE-' . $c['entreprise']));
        $r = smBonEcrire(['code' => $code, 'shopId' => $c['shop_id'], 'avantage' => $bon['avantage'], 'jours' => $bon['jours'] ?? 30, 'usageUnique' => true]);
        if (isset($r['error'])) { http_response_code(422); return $r; }
        $c['bon_code'] = $r['bon']['code'];
    }
    $now = date('Y-m-d H:i:s');
    $c += ['cree_le' => $now, 'maj_le' => $now];
    $cols = array_keys($c);
    Db::exec('INSERT INTO sm_client (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($c));
    $id = (int) Db::pdo()->lastInsertId();
    journalAdd('Commercial', 'Commercial', null, 'Bureau créé : ' . $c['entreprise']);
    $client = smClientVue(Db::row('SELECT * FROM sm_client WHERE id = ?', [$id]), smMagasins(), smSecteurs());
    $envoi = null;
    if (!empty($b['envoyer']) && $client['email'] !== '') {
        $envoi = smEnvoyer(['modele' => (string) ($b['modele'] ?? 'bienvenue'), 'langue' => $client['langue'], 'clientId' => $id, 'a' => $client['email'],
            'catalogue' => is_array($b['catalogue'] ?? null) ? $b['catalogue'] : null]);
    }
    return ['ok' => true, 'client' => $client, 'envoi' => $envoi];
}

/** PUT /commercial/clients/{id} */
function wr_sm_client_put(int $id): array
{
    smTables();
    if (Db::row('SELECT id FROM sm_client WHERE id = ?', [$id]) === null) { http_response_code(404); return ['error' => 'client introuvable']; }
    try { $c = smClientChamps(body(), false); } catch (InvalidArgumentException $e) { http_response_code(422); return ['error' => $e->getMessage()]; }
    if ($c === []) { http_response_code(422); return ['error' => 'rien à changer']; }
    $c['maj_le'] = date('Y-m-d H:i:s');
    Db::exec('UPDATE sm_client SET ' . implode(', ', array_map(static fn ($k) => $k . ' = ?', array_keys($c))) . ' WHERE id = ?', [...array_values($c), $id]);
    return ['ok' => true, 'client' => smClientVue(Db::row('SELECT * FROM sm_client WHERE id = ?', [$id]), smMagasins(), smSecteurs())];
}

/**
 * GET /commercial/clients-panel?shop= — les clients pro (TVA valide) vus au magasin sur 90 jours, pour rattacher un
 * bureau à son compte du panel : clé, nom (fiche du panel), dernière commande, commandes, CA. Lu dans les relevés.
 */
function ep_sm_clients_panel(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(422); return ['error' => 'magasin attendu']; }
    $agg = [];
    $appels = 0;
    for ($i = 0; $i < 90; $i++) {
        $j = date('Y-m-d', strtotime('-' . $i . ' days'));
        foreach ((array) smTicketsJour($sid, $j, false, $appels, 0) as $t) {
            if ($t[1] === '' || $t[1][0] !== 'c') { continue; }
            $a = $agg[$t[1]] ?? ['cle' => $t[1], 'derniere' => $j, 'commandes' => 0, 'ca' => 0.0];
            $a['commandes']++; $a['ca'] += $t[2];
            $agg[$t[1]] = $a;
        }
    }
    $noms = $agg && function_exists('vpNoms') ? vpNoms($sid, array_keys($agg)) : [];
    $out = array_map(static fn ($a) => $a + ['nom' => $noms[$a['cle']] ?? null, 'ca' => round($a['ca'], 2)], array_values($agg));
    usort($out, static fn ($a, $b) => strcmp((string) $b['derniere'], (string) $a['derniere']));
    return ['shop' => $sid, 'clients' => $out, 'source' => 'tickets pro du panel (TVA valide), 90 jours'];
}

/* --- offres par magasin ------------------------------------------------------------------------------------------ */

/** GET /commercial/offres — chaque type d'offre de Prospection, son prix et son état par magasin. */
function ep_sm_offres(): array
{
    smTables();
    $cell = [];
    foreach (Db::rows('SELECT * FROM sm_offre') as $r) { $cell[$r['offre_id']][$r['shop_id']] = ['prix' => $r['prix'], 'conditions' => $r['conditions'], 'statut' => $r['statut']]; }
    $envois = [];
    foreach (Db::rows('SELECT offre_id, COUNT(*) AS n FROM sm_envoi WHERE offre_id <> "" AND ok = 1 GROUP BY offre_id') as $r) { $envois[$r['offre_id']] = (int) $r['n']; }
    $out = [];
    foreach (smSecteurs() as $o) { $out[] = $o + ['magasins' => (object) ($cell[$o['id']] ?? []), 'envoyees' => $envois[$o['id']] ?? 0]; }
    return ['offres' => $out, 'magasins' => array_values(smMagasins())];
}

/** PUT /commercial/offres — { offre, shopId, prix, conditions?, statut: actif|brouillon } ; un prix vide retire l'offre du magasin. */
function wr_sm_offre_put(): array
{
    smTables();
    $b = body();
    $o = (string) ($b['offre'] ?? ''); $s = (string) ($b['shopId'] ?? '');
    if (!isset(smSecteurs()[$o]) || !isset(smMagasins()[$s])) { http_response_code(422); return ['error' => 'offre ou magasin inconnu']; }
    $prix = smTexte($b['prix'] ?? '', 120);
    if ($prix === '') { Db::exec('DELETE FROM sm_offre WHERE offre_id = ? AND shop_id = ?', [$o, $s]); return ['ok' => true, 'retiree' => true]; }
    $st = ($b['statut'] ?? '') === 'brouillon' ? 'brouillon' : 'actif';
    Db::exec('INSERT INTO sm_offre (offre_id, shop_id, prix, conditions, statut, maj_le) VALUES (?,?,?,?,?,NOW())'
        . ' ON DUPLICATE KEY UPDATE prix = VALUES(prix), conditions = VALUES(conditions), statut = VALUES(statut), maj_le = NOW()',
        [$o, $s, $prix, smTexte($b['conditions'] ?? '', 255), $st]);
    return ['ok' => true];
}

/* --- bons --------------------------------------------------------------------------------------------------------- */

function smBonVue(array $r, array $envois, array $mags): array
{
    return ['id' => (int) $r['id'], 'code' => $r['code'], 'shopId' => $r['shop_id'], 'magasin' => $r['shop_id'] === '' ? 'Tous' : ($mags[$r['shop_id']]['nom'] ?? $r['shop_id']),
        'avantage' => $r['avantage'], 'jusquAu' => $r['jusqu_au'], 'jours' => $r['jours'] !== null ? (int) $r['jours'] : null, 'usageUnique' => (bool) $r['usage_unique'],
        'utilises' => (int) $r['utilises'], 'actif' => (bool) $r['actif'], 'envoyes' => $envois[$r['code']] ?? 0, 'creeLe' => $r['cree_le']];
}

/** GET /commercial/bons */
function ep_sm_bons(): array
{
    smTables();
    $envois = [];
    foreach (Db::rows('SELECT bon_code, COUNT(*) AS n FROM sm_envoi WHERE bon_code <> "" AND ok = 1 GROUP BY bon_code') as $r) { $envois[$r['bon_code']] = (int) $r['n']; }
    $mags = smMagasins();
    return ['bons' => array_map(static fn ($r) => smBonVue($r, $envois, $mags), Db::rows('SELECT * FROM sm_bon ORDER BY actif DESC, cree_le DESC'))];
}

/** Écrit un bon (création) : { code, shopId ('' = tous), avantage, jusquAu? | jours?, usageUnique } ; ['bon'] ou ['error']. */
function smBonEcrire(array $b): array
{
    $code = smCodeBon((string) ($b['code'] ?? ''));
    if (strlen($code) < 4) { return ['error' => 'code de bon trop court (4 caractères au moins)']; }
    $shop = (string) ($b['shopId'] ?? '');
    if ($shop !== '' && !isset(smMagasins()[$shop])) { return ['error' => 'magasin inconnu']; }
    $av = smTexte($b['avantage'] ?? '', 160);
    if ($av === '') { return ['error' => 'l’avantage du bon manque'] ; }
    $jusq = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['jusquAu'] ?? '')) ? (string) $b['jusquAu'] : null;
    $jours = $jusq === null && is_numeric($b['jours'] ?? null) ? max(1, min(365, (int) $b['jours'])) : null;
    $base = $code; $n = 2;
    while (Db::row('SELECT id FROM sm_bon WHERE code = ?', [$code]) !== null) { $code = substr($base, 0, 27) . '-' . $n++; }
    Db::exec('INSERT INTO sm_bon (code, shop_id, avantage, jusqu_au, jours, usage_unique, cree_le) VALUES (?,?,?,?,?,?,NOW())',
        [$code, $shop, $av, $jusq, $jours, !empty($b['usageUnique']) ? 1 : 0]);
    return ['bon' => smBonVue(Db::row('SELECT * FROM sm_bon WHERE code = ?', [$code]), [], smMagasins())];
}

/** POST /commercial/bons */
function wr_sm_bon_creer(): array
{
    smTables();
    $r = smBonEcrire(body());
    if (isset($r['error'])) { http_response_code(422); }
    return $r + (isset($r['error']) ? [] : ['ok' => true]);
}

/** PUT /commercial/bons/{id} — { utilises?, actif? } */
function wr_sm_bon_put(int $id): array
{
    smTables();
    $b = body();
    if (Db::row('SELECT id FROM sm_bon WHERE id = ?', [$id]) === null) { http_response_code(404); return ['error' => 'bon introuvable']; }
    if (array_key_exists('utilises', $b)) { Db::exec('UPDATE sm_bon SET utilises = ? WHERE id = ?', [max(0, (int) $b['utilises']), $id]); }
    if (array_key_exists('actif', $b)) { Db::exec('UPDATE sm_bon SET actif = ? WHERE id = ?', [!empty($b['actif']) ? 1 : 0, $id]); }
    return ['ok' => true];
}

/* --- catalogues des magasins, par le relais du Marketing --------------------------------------------------------- */

/** GET /commercial/catalogues?shop= — les catalogues du magasin (« Mon catalogue »), avec leur document. */
function ep_sm_catalogues(): array
{
    $shop = (string) ($_GET['shop'] ?? '');
    if (!isset(smMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin attendu']; }
    $garde = $_GET;
    $_GET = ['shop' => $shop];
    try { $r = cmRoute('GET', '/dashboard/catalogues'); } finally { $_GET = $garde; }
    $code = http_response_code();
    if ($code >= 400 || !is_array($r) || isset($r['error'])) {
        http_response_code(200);
        $motif = is_array($r) ? ($r['error']['message'] ?? $r['message'] ?? $r['error'] ?? null) : null;
        return ['shop' => $shop, 'catalogues' => [], 'indispo' => true, 'motif' => is_string($motif) && $motif !== '' ? $motif : 'le Marketing ne répond pas'];
    }
    $liste = $r['data'] ?? $r['items'] ?? $r['catalogues'] ?? (array_is_list($r) ? $r : []);
    $out = [];
    foreach ((array) $liste as $c) {
        if (!is_array($c)) { continue; }
        $out[] = ['id' => $c['id'] ?? null, 'nom' => (string) ($c['name'] ?? $c['title'] ?? $c['nom'] ?? 'Catalogue'), 'statut' => (string) ($c['status'] ?? ''),
            'maj' => $c['updatedAt'] ?? $c['updated_at'] ?? null, 'pages' => $c['pages'] ?? $c['pageCount'] ?? null,
            'lien' => isset($c['id']) ? 'api/cockpit/dashboard/catalogues/' . $c['id'] . '/document?shop=' . rawurlencode($shop) : null];
    }
    return ['shop' => $shop, 'catalogues' => $out, 'source' => 'Mon catalogue des magasins (Marketing)'];
}

/** POST /commercial/catalogues/lien — { shopId, id } : le lien public du catalogue (créé au Marketing), pour l'e-mail. */
function wr_sm_catalogue_lien(): array
{
    $b = body();
    $shop = (string) ($b['shopId'] ?? '');
    $id = (string) ($b['id'] ?? '');
    if (!isset(smMagasins()[$shop]) || !ctype_digit($id)) { http_response_code(422); return ['error' => 'magasin et catalogue attendus']; }
    $garde = $_GET;
    $_GET = ['shop' => $shop];
    try { $r = cmRoute('POST', '/dashboard/catalogues/' . $id . '/lien'); } finally { $_GET = $garde; }
    $url = null;
    $chercher = static function ($x) use (&$chercher, &$url): void {
        if ($url !== null || !is_array($x)) { return; }
        foreach ($x as $k => $v) {
            if (is_string($v) && preg_match('#^https?://#', $v) && stripos((string) $k, 'url') !== false) { $url = $v; return; }
            $chercher($v);
        }
    };
    $chercher($r['data'] ?? $r);
    if ($url === null) { http_response_code(502); return ['error' => 'le Marketing n’a pas rendu de lien public pour ce catalogue']; }
    http_response_code(200);
    return ['ok' => true, 'url' => $url];
}

/* --- modèles d'e-mail et envois depuis hello@atelierby.be -------------------------------------------------------- */

/** Les modèles par défaut, en français et en néerlandais. Variables : {{prenom}}, {{entreprise}}, {{magasin}}, … */
function smModelesDefaut(): array
{
    return [
        'bienvenue' => [
            'fr' => ['Votre bureau est prêt : L’Atelier By {{magasin}} vous livre',
                "Bonjour {{prenom}},\n\nLe bureau de **{{entreprise}}** est ouvert sur notre webshop. Le magasin de {{magasin}} vous livre : {{tournee}}. Commandez jusqu’à la veille 16 h.\n\n{{bon_phrase}}\n\nÀ très vite,", 'Commander pour le bureau'],
            'nl' => ['Uw kantoor is klaar: L’Atelier By {{magasin}} levert bij u',
                "Beste {{prenom}},\n\nHet kantoor van **{{entreprise}}** staat open in onze webshop. De winkel van {{magasin}} levert: {{tournee}}. Bestel tot 16 u de dag voordien.\n\n{{bon_phrase}}\n\nTot binnenkort,", 'Bestellen voor het kantoor']],
        'rdv' => [
            'fr' => ['Notre rendez-vous : {{date_rdv}}',
                "Bonjour {{prenom}},\n\nJe vous confirme notre rendez-vous du **{{date_rdv}}** pour {{entreprise}}, avec L’Atelier By {{magasin}}.\n\nPouvez-vous me confirmer d’un mot en réponse à ce message ? Si le moment ne vous convient plus, proposez-moi un autre créneau.\n\nÀ très vite,", ''],
            'nl' => ['Onze afspraak: {{date_rdv}}',
                "Beste {{prenom}},\n\nHierbij bevestig ik onze afspraak van **{{date_rdv}}** voor {{entreprise}}, met L’Atelier By {{magasin}}.\n\nKunt u dit kort bevestigen door op dit bericht te antwoorden? Past het moment niet meer, stel dan gerust een ander voor.\n\nTot binnenkort,", '']],
        'offre' => [
            'fr' => ['{{offre}} : notre proposition pour {{entreprise}}',
                "Bonjour {{prenom}},\n\n{{pitch}}\n\nNotre proposition, préparée par le magasin de {{magasin}} : **{{prix}}**. {{conditions}}\n\n{{bon_phrase}}\n\nBien à vous,", 'Voir la proposition'],
            'nl' => ['{{offre}}: ons voorstel voor {{entreprise}}',
                "Beste {{prenom}},\n\n{{pitch}}\n\nOns voorstel, voorbereid door de winkel van {{magasin}}: **{{prix}}**. {{conditions}}\n\n{{bon_phrase}}\n\nMet vriendelijke groeten,", 'Het voorstel bekijken']],
        'catalogue' => [
            'fr' => ['Le catalogue de L’Atelier By {{magasin}}',
                "Bonjour {{prenom}},\n\nVoici le catalogue du magasin de {{magasin}} : **{{catalogue}}**. Les plateaux, les viennoiseries et les tartes se commandent en ligne, livrés ou à retirer.\n\n{{bon_phrase}}\n\nBien à vous,", 'Ouvrir le catalogue'],
            'nl' => ['De catalogus van L’Atelier By {{magasin}}',
                "Beste {{prenom}},\n\nHier is de catalogus van de winkel van {{magasin}}: **{{catalogue}}**. Schotels, koffiekoeken en taarten bestelt u online, geleverd of af te halen.\n\n{{bon_phrase}}\n\nMet vriendelijke groeten,", 'De catalogus openen']],
        'echantillon' => [
            'fr' => ['Un plateau découverte pour {{entreprise}}',
                "Bonjour {{prenom}},\n\nComme convenu, le magasin de {{magasin}} vous apporte un plateau découverte : **{{date_echantillon}}**.\n\nDites-nous ce que l’équipe en a pensé : nous préparerons ensuite une proposition à votre mesure.\n\nBien à vous,", ''],
            'nl' => ['Een kennismakingsschotel voor {{entreprise}}',
                "Beste {{prenom}},\n\nZoals afgesproken brengt de winkel van {{magasin}} u een kennismakingsschotel: **{{date_echantillon}}**.\n\nLaat ons weten wat het team ervan vond: daarna maken we een voorstel op maat.\n\nMet vriendelijke groeten,", '']],
        'relance' => [
            'fr' => ['{{entreprise}} : on vous livre cette semaine ?',
                "Bonjour {{prenom}},\n\nVotre bureau chez L’Atelier By {{magasin}} n’a pas commandé depuis un moment. Une réunion, un anniversaire, la pause du vendredi : on livre {{tournee}}.\n\n{{bon_phrase}}\n\nÀ bientôt,", 'Commander'],
            'nl' => ['{{entreprise}}: zullen we deze week leveren?',
                "Beste {{prenom}},\n\nUw kantoor bij L’Atelier By {{magasin}} heeft al even niet besteld. Een vergadering, een verjaardag, de vrijdagpauze: we leveren {{tournee}}.\n\n{{bon_phrase}}\n\nTot binnenkort,", 'Bestellen']],
    ];
}

/** Un modèle : celui réglé en base, sinon celui par défaut. */
function smModele(string $id, string $langue): array
{
    smTables();
    $d = smModelesDefaut()[$id][$langue] ?? smModelesDefaut()['bienvenue']['fr'];
    $r = Db::row('SELECT sujet, corps, bouton FROM sm_modele WHERE id = ? AND langue = ?', [$id, $langue]);
    return $r !== null ? ['sujet' => $r['sujet'], 'corps' => $r['corps'], 'bouton' => $r['bouton'], 'regle' => true]
        : ['sujet' => $d[0], 'corps' => $d[1], 'bouton' => $d[2], 'regle' => false];
}

/** GET /commercial/modeles */
function ep_sm_modeles(): array
{
    $out = [];
    foreach (SM_MODELES as $id => $nom) {
        foreach (SM_LANGUES as $l) { $out[] = ['id' => $id, 'nom' => $nom, 'langue' => $l] + smModele($id, $l); }
    }
    return ['modeles' => $out, 'variables' => ['prenom', 'contact', 'entreprise', 'magasin', 'tournee', 'bon', 'bon_phrase', 'offre', 'pitch', 'prix', 'conditions', 'catalogue', 'date_echantillon', 'date_rdv']];
}

/** PUT /commercial/modeles/{id}/{langue} — { sujet, corps, bouton } ; corps vide = retour au modèle par défaut. */
function wr_sm_modele_put(string $id, string $langue): array
{
    smTables();
    if (!isset(SM_MODELES[$id]) || !in_array($langue, SM_LANGUES, true)) { http_response_code(404); return ['error' => 'modèle inconnu']; }
    $b = body();
    $corps = trim((string) ($b['corps'] ?? ''));
    if ($corps === '') { Db::exec('DELETE FROM sm_modele WHERE id = ? AND langue = ?', [$id, $langue]); return ['ok' => true, 'modele' => smModele($id, $langue)]; }
    Db::exec('INSERT INTO sm_modele (id, langue, sujet, corps, bouton, maj_le) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE sujet = VALUES(sujet), corps = VALUES(corps), bouton = VALUES(bouton), maj_le = NOW()',
        [$id, $langue, smTexte($b['sujet'] ?? '', 255), mb_substr($corps, 0, 6000), smTexte($b['bouton'] ?? '', 80)]);
    return ['ok' => true, 'modele' => smModele($id, $langue)];
}

/** Remplace les {{variables}} ; une variable inconnue disparaît. */
function smRemplir(string $t, array $v): string
{
    return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn ($m) => (string) ($v[$m[1]] ?? ''), $t);
}

/** Le corps d'un modèle en HTML d'e-mail (§13.6) : paragraphes, **gras** (les accents permis : noms, montants, dates). */
function smCorpsHtml(string $texte): string
{
    $out = '';
    foreach (preg_split('/\n\s*\n/', trim($texte)) ?: [] as $p) {
        $p = trim($p);
        if ($p === '') { continue; }
        $h = htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = (string) preg_replace('/\*\*(.+?)\*\*/u', '<b style="font-weight:700">$1</b>', $h);
        $out .= docMailP(nl2br($h, false));
    }
    return $out;
}

/** Les variables d'un envoi : le client, son magasin, l'offre, le bon, le catalogue, l'échantillon. */
function smVariables(array $o): array
{
    $c = null;
    if (!empty($o['clientId'])) {
        $r = Db::row('SELECT * FROM sm_client WHERE id = ?', [(int) $o['clientId']]);
        if ($r !== null) { $c = smClientVue($r, smMagasins(), smSecteurs()); }
    }
    $shop = (string) ($o['shopId'] ?? ($c['shopId'] ?? ''));
    $mag = smMagasins()[$shop]['nom'] ?? '';
    $contact = (string) ($o['contact'] ?? ($c['contact'] ?? ''));
    $langue = in_array($o['langue'] ?? '', SM_LANGUES, true) ? $o['langue'] : 'fr';
    $v = ['contact' => $contact, 'prenom' => $contact !== '' ? explode(' ', $contact)[0] : ($langue === 'nl' ? 'heer, mevrouw' : 'Madame, Monsieur'),
        'entreprise' => (string) ($o['entreprise'] ?? ($c['entreprise'] ?? '')), 'magasin' => $mag,
        'tournee' => (string) ($c['tournee'] ?? '') !== '' ? (string) $c['tournee'] : ($langue === 'nl' ? 'volgens de afgesproken ronde' : 'selon la tournée convenue')];
    $bon = (string) ($o['bon'] ?? ($c['bon'] ?? ''));
    if ($bon !== '') {
        $rb = Db::row('SELECT * FROM sm_bon WHERE code = ?', [$bon]);
        if ($rb !== null) {
            $fin = $rb['jusqu_au'] ?? ($rb['jours'] !== null ? date('Y-m-d', strtotime('+' . (int) $rb['jours'] . ' days')) : null);
            $v['bon'] = $rb['code'];
            $v['bon_phrase'] = $langue === 'nl'
                ? 'Uw code: **' . $rb['code'] . '**, ' . $rb['avantage'] . ($fin ? ', tot **' . date('d/m/Y', strtotime($fin)) . '**' : '') . '.'
                : 'Votre bon : **' . $rb['code'] . '**, ' . $rb['avantage'] . ($fin ? ', jusqu’au **' . docDate($fin, true) . '**' : '') . '.';
        }
    }
    $off = (string) ($o['offre'] ?? '');
    if ($off !== '' && isset(smSecteurs()[$off])) {
        $s = smSecteurs()[$off];
        $cel = Db::row('SELECT prix, conditions FROM sm_offre WHERE offre_id = ? AND shop_id = ?', [$off, $shop]);
        $v += ['offre' => $s['nom'], 'pitch' => $s['pitch'], 'prix' => (string) ($cel['prix'] ?? ''), 'conditions' => (string) ($cel['conditions'] ?? '')];
    }
    if (is_array($o['catalogue'] ?? null)) { $v['catalogue'] = smTexte($o['catalogue']['nom'] ?? '', 160); }
    if (!empty($o['dateEchantillon'])) { $v['date_echantillon'] = (string) $o['dateEchantillon']; }
    if (!empty($o['dateRdv'])) { $v['date_rdv'] = (string) $o['dateRdv']; }
    $v += ['bon' => '', 'bon_phrase' => '', 'offre' => '', 'pitch' => '', 'prix' => '', 'conditions' => '', 'catalogue' => '', 'date_echantillon' => '', 'date_rdv' => ''];
    return ['v' => $v, 'client' => $c, 'shop' => $shop, 'langue' => $langue];
}

/** L'e-mail rendu : sujet, HTML (§13.6, version A), la variable du lien. */
function smRendre(array $o): array
{
    $x = smVariables($o);
    $m = smModele((string) ($o['modele'] ?? 'bienvenue'), $x['langue']);
    $sujet = trim(smRemplir($m['sujet'], $x['v']));
    $corps = smCorpsHtml(smRemplir($m['corps'], $x['v']));
    $reg = smReglages();
    $lien = trim((string) ($o['lien'] ?? ''));
    if ($lien === '' && is_array($o['catalogue'] ?? null)) { $lien = trim((string) ($o['catalogue']['url'] ?? '')); }
    if ($lien === '') { $lien = $reg['webshop']; }
    if ($m['bouton'] !== '' && preg_match('#^https?://#', $lien)) { $corps .= docMailBouton($m['bouton'], $lien); }
    $corps .= docMailSignature($reg['signature'] + ['service' => 'L’Atelier By']);
    $pourquoi = $x['langue'] === 'nl' ? 'U ontvangt dit bericht als professionele klant of prospect van L’Atelier By.' : 'Vous recevez ce message comme client ou prospect professionnel de L’Atelier By.';
    $html = docMail(['sujet' => $sujet, 'service' => 'Ventes aux professionnels', 'corps' => $corps, 'pied' => docMailPied($pourquoi)]);
    return ['sujet' => $sujet, 'html' => $html, 'langue' => $x['langue'], 'client' => $x['client'], 'shop' => $x['shop']];
}

/** POST /commercial/apercu — { modele, langue, clientId?, shopId?, offre?, bon?, catalogue?, … } → { sujet, html } */
function wr_sm_apercu(): array
{
    smTables();
    $r = smRendre(body());
    return ['sujet' => $r['sujet'], 'html' => $r['html']];
}

/** Envoie un modèle et le note dans sm_envoi : ['ok', 'erreur', 'sujet', 'a']. */
function smEnvoyer(array $o): array
{
    $a = smEmail($o['a'] ?? '');
    if ($a === '') { return ['ok' => false, 'erreur' => 'adresse du destinataire invalide']; }
    $r = smRendre($o);
    $ok = class_exists('Smtp') && Smtp::envoyer($a, $r['sujet'], $r['html'], [], smReglages()['expediteur']);
    $err = $ok ? '' : (string) (Smtp::$lastError ?? 'envoi impossible');
    $modele = isset(SM_MODELES[$o['modele'] ?? '']) ? (string) $o['modele'] : 'bienvenue';
    Db::exec('INSERT INTO sm_envoi (modele, langue, client_id, shop_id, secteur, offre_id, bon_code, destinataire, sujet, ok, erreur, le) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
        [$modele, $r['langue'], $r['client']['id'] ?? null, $r['shop'], (string) ($r['client']['secteur'] ?? ($o['offre'] ?? '')), (string) ($o['offre'] ?? ''),
            (string) ($o['bon'] ?? ($r['client']['bon'] ?? '')), $a, mb_substr($r['sujet'], 0, 255), $ok ? 1 : 0, mb_substr($err, 0, 255)]);
    return ['ok' => $ok, 'erreur' => $err !== '' ? $err : null, 'sujet' => $r['sujet'], 'a' => $a];
}

/** POST /commercial/envoyer */
function wr_sm_envoyer(): array
{
    smTables();
    $r = smEnvoyer(body());
    if (!$r['ok']) { http_response_code(502); }
    return $r;
}

/** GET /commercial/envois?jours=30 */
function ep_sm_envois(): array
{
    smTables();
    $j = max(1, min(365, (int) ($_GET['jours'] ?? 30)));
    $rows = Db::rows('SELECT * FROM sm_envoi WHERE le >= ? ORDER BY le DESC LIMIT 300', [date('Y-m-d H:i:s', time() - $j * 86400)]);
    return ['envois' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'modele' => $r['modele'], 'langue' => $r['langue'], 'clientId' => $r['client_id'] !== null ? (int) $r['client_id'] : null,
        'shopId' => $r['shop_id'], 'offre' => $r['offre_id'], 'bon' => $r['bon_code'], 'a' => $r['destinataire'], 'sujet' => $r['sujet'], 'ok' => (bool) $r['ok'],
        'erreur' => $r['erreur'] !== '' ? $r['erreur'] : null, 'le' => $r['le']], $rows)];
}

/* --- échantillons ------------------------------------------------------------------------------------------------ */

/** L'adresse e-mail de chaque magasin, lue au panel (GET /shops), gardée un jour ; rien d'autre de la fiche n'est gardé. */
function smEmailsMagasins(): array
{
    $m = setting('smEmailsMagasins');
    if (is_array($m) && (int) ($m['le'] ?? 0) > time() - 86400 && is_array($m['v'] ?? null)) { return $m['v']; }
    $v = [];
    if (class_exists('PanelApi') && PanelApi::configured()) {
        $l = PanelApi::get('/shops');
        foreach ((array) (is_array($l) && isset($l['data']) ? $l['data'] : $l) as $s) {
            if (is_array($s) && isset($s['id']) && ($e = smEmail($s['email'] ?? '')) !== '') { $v[(string) $s['id']] = $e; }
        }
        if ($v !== []) { settingEcrire('smEmailsMagasins', ['le' => time(), 'v' => $v]); }
    }
    return $v;
}

function smEchVue(array $r, array $mags): array
{
    return ['id' => (int) $r['id'], 'clientId' => $r['client_id'] !== null ? (int) $r['client_id'] : null, 'prospect' => $r['prospect'], 'contact' => $r['contact'],
        'secteur' => $r['secteur'], 'shopId' => $r['shop_id'], 'magasin' => $mags[$r['shop_id']]['nom'] ?? $r['shop_id'], 'contenu' => json_decode((string) $r['contenu'], true) ?: [],
        'le' => $r['le'], 'mode' => $r['mode'], 'adresse' => $r['adresse'], 'cout' => $r['cout'] !== null ? (float) $r['cout'] : null, 'statut' => $r['statut'],
        'envoyeLe' => $r['envoye_le'], 'note' => $r['note'], 'creeLe' => $r['cree_le']];
}

/** GET /commercial/echantillons?jours=60 */
function ep_sm_echantillons(): array
{
    smTables();
    $j = max(1, min(365, (int) ($_GET['jours'] ?? 60)));
    $rows = Db::rows('SELECT * FROM sm_echantillon WHERE cree_le >= ? ORDER BY cree_le DESC', [date('Y-m-d H:i:s', time() - $j * 86400)]);
    $mags = smMagasins();
    $l = array_map(static fn ($r) => smEchVue($r, $mags), $rows);
    $mois = date('Y-m');
    $duMois = array_filter($l, static fn ($e) => substr((string) $e['creeLe'], 0, 7) === $mois);
    return ['echantillons' => $l, 'mois' => ['n' => count($duMois), 'cout' => round(array_sum(array_map(static fn ($e) => (float) ($e['cout'] ?? 0), $duMois)), 2),
        'convertis' => count(array_filter($duMois, static fn ($e) => $e['statut'] === 'converti'))]];
}

/** Le courrier au magasin : ce qu'il faut préparer, pour qui, quand. */
function smEchMail(array $e, string $mag): array
{
    $lignes = '';
    foreach ($e['contenu'] as $x) {
        $lignes .= '<tr><td style="padding:6px 0;border-bottom:1px solid ' . DOC_ROW . '">' . htmlspecialchars((string) ($x['produit'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 0;border-bottom:1px solid ' . DOC_ROW . ';text-align:right">' . htmlspecialchars((string) ($x['q'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    $quand = $e['le'] ? date('d/m/Y H:i', strtotime((string) $e['le'])) : 'à convenir';
    $sujet = 'Échantillon à préparer — ' . $e['prospect'] . ' — ' . $quand;
    $corps = docMailP('Bonjour,')
        . docMailP('Le commercial vous demande un échantillon pour <b style="font-weight:700">' . htmlspecialchars($e['prospect'], ENT_QUOTES, 'UTF-8') . '</b>'
            . ($e['contact'] !== '' ? ' (' . htmlspecialchars($e['contact'], ENT_QUOTES, 'UTF-8') . ')' : '') . ', en ' . ($e['mode'] === 'retrait' ? 'retrait au magasin' : 'livraison')
            . ' le <b style="font-weight:700">' . $quand . '</b>' . ($e['adresse'] !== '' ? ', ' . htmlspecialchars($e['adresse'], ENT_QUOTES, 'UTF-8') : '') . '.')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:' . DOC_FONT . ';font-size:13px;margin:4px 0 14px">' . $lignes . '</table>'
        . ($e['note'] !== '' ? docMailP(htmlspecialchars($e['note'], ENT_QUOTES, 'UTF-8')) : '')
        . docMailP('Merci de le préparer comme une commande, sans encaissement. Répondez à ce message en cas d’empêchement.');
    return ['sujet' => $sujet, 'html' => docMail(['sujet' => $sujet, 'service' => 'Commercial · ' . $mag, 'corps' => $corps, 'pied' => docMailPied('Message du portail Commercial de L’Atelier By.')])];
}

/** Envoie la demande d'échantillon au magasin par e-mail (en attendant la commande dans le système de commande). */
function smEchEnvoyerMagasin(int $id): array
{
    $r = Db::row('SELECT * FROM sm_echantillon WHERE id = ?', [$id]);
    if ($r === null) { return ['ok' => false, 'erreur' => 'échantillon introuvable']; }
    $e = smEchVue($r, smMagasins());
    $a = smEmailsMagasins()[$e['shopId']] ?? '';
    if ($a === '') { return ['ok' => false, 'erreur' => 'adresse e-mail du magasin inconnue (fiche du panel)']; }
    $m = smEchMail($e, $e['magasin']);
    $ok = class_exists('Smtp') && Smtp::envoyer($a, $m['sujet'], $m['html'], [], smReglages()['expediteur']);
    if ($ok) { Db::exec('UPDATE sm_echantillon SET statut = IF(statut = "a_envoyer", "commande", statut), envoye_le = NOW() WHERE id = ?', [$id]); }
    return ['ok' => $ok, 'erreur' => $ok ? null : (string) (Smtp::$lastError ?? 'envoi impossible'), 'a' => $a];
}

/** POST /commercial/echantillons — { prospect, contact?, clientId?, secteur?, shopId, contenu: [{produit, q}], le?, mode, adresse?, cout?, note?, envoyer? } */
function wr_sm_echantillon_creer(): array
{
    smTables();
    $b = body();
    $shop = (string) ($b['shopId'] ?? '');
    if (!isset(smMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $prospect = smTexte($b['prospect'] ?? '', 200);
    $contenu = [];
    foreach ((array) ($b['contenu'] ?? []) as $x) {
        $p = smTexte(is_array($x) ? ($x['produit'] ?? '') : '', 120);
        if ($p !== '') { $contenu[] = ['produit' => $p, 'q' => smTexte($x['q'] ?? '1', 20)]; }
    }
    if ($prospect === '' || $contenu === []) { http_response_code(422); return ['error' => 'le prospect et le contenu sont attendus']; }
    $le = preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2})?$/', (string) ($b['le'] ?? '')) ? str_replace('T', ' ', (string) $b['le']) : null;
    Db::exec('INSERT INTO sm_echantillon (client_id, prospect, contact, secteur, shop_id, contenu, le, mode, adresse, cout, note, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
        [is_numeric($b['clientId'] ?? null) ? (int) $b['clientId'] : null, $prospect, smTexte($b['contact'] ?? '', 160), isset(smSecteurs()[(string) ($b['secteur'] ?? '')]) ? (string) $b['secteur'] : '',
            $shop, json_encode($contenu, JSON_UNESCAPED_UNICODE), $le, ($b['mode'] ?? '') === 'retrait' ? 'retrait' : 'livraison', smTexte($b['adresse'] ?? '', 255),
            is_numeric($b['cout'] ?? null) ? round((float) $b['cout'], 2) : null, smTexte($b['note'] ?? '', 255)]);
    $id = (int) Db::pdo()->lastInsertId();
    $envoi = !empty($b['envoyer']) ? smEchEnvoyerMagasin($id) : null;
    return ['ok' => true, 'echantillon' => smEchVue(Db::row('SELECT * FROM sm_echantillon WHERE id = ?', [$id]), smMagasins()), 'envoi' => $envoi];
}

/** PUT /commercial/echantillons/{id} — { statut?, note?, envoyer? } */
function wr_sm_echantillon_put(int $id): array
{
    smTables();
    if (Db::row('SELECT id FROM sm_echantillon WHERE id = ?', [$id]) === null) { http_response_code(404); return ['error' => 'échantillon introuvable']; }
    $b = body();
    if (isset($b['statut']) && in_array($b['statut'], SM_ECH_STATUTS, true)) { Db::exec('UPDATE sm_echantillon SET statut = ? WHERE id = ?', [$b['statut'], $id]); }
    if (array_key_exists('note', $b)) { Db::exec('UPDATE sm_echantillon SET note = ? WHERE id = ?', [smTexte($b['note'], 255), $id]); }
    $envoi = !empty($b['envoyer']) ? smEchEnvoyerMagasin($id) : null;
    return ['ok' => true, 'echantillon' => smEchVue(Db::row('SELECT * FROM sm_echantillon WHERE id = ?', [$id]), smMagasins()), 'envoi' => $envoi];
}

/* --- tableau de bord --------------------------------------------------------------------------------------------- */

/**
 * GET /commercial/tableau?jours=30 — par magasin, les clients pro du panel (TVA valide) : nouveaux (1re commande dans la
 * fenêtre, aucune dans les SM_JOURS_HISTO jours d'avant), anciens, commandes, CA HT ; les clients du portail ; les
 * commandes pro par semaine (clients du portail, autres) ; les bureaux à suivre. Lu dans les relevés du panel : les
 * jours de la fenêtre manquants se lisent au panel (un appel par jour, quelques-uns par magasin et par appel).
 */
function ep_sm_tableau(): array
{
    smTables();
    @set_time_limit(90);
    $jours = max(7, min(90, (int) ($_GET['jours'] ?? 30)));
    $tva = smReglages()['gains']['tva'];
    $auj = date('Y-m-d');
    $mags = smMagasins();
    $clients = Db::rows('SELECT * FROM sm_client');
    $parCle = [];
    foreach ($clients as $c) { if ($c['cle_panel'] !== '') { $parCle[$c['shop_id'] . '|' . $c['cle_panel']] = $c; } }
    $lignes = []; $semaines = []; $derniere = []; $manquants = 0; $histoManquants = 0; $appels = 0;
    for ($w = 7; $w >= 0; $w--) { $semaines[date('o-\SW', strtotime('-' . ($w * 7) . ' days'))] = ['semaine' => date('\SW', strtotime('-' . ($w * 7) . ' days')), 'portail' => 0, 'autres' => 0]; }
    foreach ($mags as $sid => $m) {
        $premier = []; $vu = []; $cmd = 0; $ca = 0.0;
        for ($i = SM_JOURS_HISTO - 1; $i >= 0; $i--) {
            $j = date('Y-m-d', strtotime($auj . ' -' . $i . ' days'));
            $dansFenetre = $i < $jours;
            $t = smTicketsJour((int) $sid, $j, $dansFenetre, $appels, $dansFenetre ? 6 * count($mags) : 0);
            if ($t === null) { if ($dansFenetre) { $manquants++; } else { $histoManquants++; } continue; }
            foreach ($t as [$h, $cle, $mt]) {
                if ($cle !== '' && !isset($premier[$cle])) { $premier[$cle] = $j; }
                if ($cle !== '') { $derniere[$sid . '|' . $cle] = $j; }
                $wk = date('o-\SW', strtotime($j));
                if (isset($semaines[$wk])) { $semaines[$wk][isset($parCle[$sid . '|' . $cle]) ? 'portail' : 'autres']++; }
                if (!$dansFenetre) { continue; }
                $cmd++; $ca += $mt;
                if ($cle !== '') { $vu[$cle] = true; }
            }
        }
        $debutFen = date('Y-m-d', strtotime($auj . ' -' . ($jours - 1) . ' days'));
        $nouveaux = count(array_filter(array_keys($vu), static fn ($k) => ($premier[$k] ?? '') >= $debutFen));
        $duPortail = array_filter($clients, static fn ($c) => $c['shop_id'] === (string) $sid);
        $lignes[] = ['shopId' => $sid, 'magasin' => $m['nom'], 'nouveaux' => $nouveaux, 'anciens' => count($vu) - $nouveaux, 'commandes' => $cmd,
            'caHt' => smHt($ca, $tva), 'panierHt' => $cmd > 0 ? smHt($ca / $cmd, $tva) : null,
            'portail' => count($duPortail), 'portailActifs' => count(array_filter($duPortail, static fn ($c) => $c['cle_panel'] !== '' && isset($vu[$c['cle_panel']])))];
    }
    $tot = ['nouveaux' => 0, 'anciens' => 0, 'commandes' => 0, 'caHt' => 0.0, 'portail' => 0, 'portailActifs' => 0];
    foreach ($lignes as $l) { foreach ($tot as $k => $_) { $tot[$k] += $l[$k]; } }
    $tot['caHt'] = round($tot['caHt'], 2);
    $tot['panierHt'] = $tot['commandes'] > 0 ? round($tot['caHt'] / $tot['commandes'], 2) : null;
    // les bureaux à suivre : ce que le portail sait, sans rien supposer
    $suivre = [];
    foreach ($clients as $c) {
        if ($c['statut'] === 'perdu') { continue; }
        $nom = $c['entreprise']; $mag = $mags[$c['shop_id']]['nom'] ?? $c['shop_id'];
        $age = (int) floor((time() - strtotime((string) $c['cree_le'])) / 86400);
        $der = $c['cle_panel'] !== '' ? ($derniere[$c['shop_id'] . '|' . $c['cle_panel']] ?? null) : null;
        if ($c['cle_panel'] === '') { $suivre[] = ['id' => (int) $c['id'], 'entreprise' => $nom, 'magasin' => $mag, 'quoi' => 'Rattacher son compte client du panel : ses commandes ne comptent pas encore', 'action' => 'rattacher', 'rang' => 1]; continue; }
        if ($der === null && $age >= 2) { $suivre[] = ['id' => (int) $c['id'], 'entreprise' => $nom, 'magasin' => $mag, 'quoi' => 'Bureau créé il y a ' . $age . ' jours, pas encore de commande', 'action' => 'relancer', 'rang' => 2]; continue; }
        if ($der !== null && ($d = (int) floor((strtotime($auj) - strtotime($der)) / 86400)) >= 21) {
            $suivre[] = ['id' => (int) $c['id'], 'entreprise' => $nom, 'magasin' => $mag, 'quoi' => 'Aucune commande depuis ' . $d . ' jours', 'action' => 'relancer', 'rang' => 3];
        }
        if ($c['webshop'] === 'a_creer') { $suivre[] = ['id' => (int) $c['id'], 'entreprise' => $nom, 'magasin' => $mag, 'quoi' => 'Compte webshop à créer (API TF Buddy à venir)', 'action' => 'webshop', 'rang' => 4]; }
    }
    usort($suivre, static fn ($a, $b) => $a['rang'] <=> $b['rang']);
    return ['jours' => $jours, 'parMagasin' => $lignes, 'total' => $tot, 'semaines' => array_values($semaines), 'aSuivre' => array_slice($suivre, 0, 12),
        'manquants' => $manquants, 'histoManquants' => $histoManquants, 'histoJours' => (SM_JOURS_HISTO - $jours) * count($mags), 'cartesOffice' => null, 'tva' => $tva,
        'source' => 'tickets pro du panel (TVA valide) ; HT estimé au taux de ' . str_replace('.', ',', (string) $tva) . ' % ; cartes office : à brancher'];
}

/* --- B2B : suivi des offres par secteur et par magasin ------------------------------------------------------------ */

/** GET /commercial/suivi?du=AAAA-MM-JJ — par secteur et par magasin : offres envoyées, échantillons, clients ; et la liste de Prospection par magasin. */
function ep_sm_suivi(): array
{
    smTables();
    $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['du'] ?? '')) ? (string) $_GET['du'] : date('Y-m-d', strtotime('-90 days'));
    $mags = smMagasins(); $sec = smSecteurs();
    $vide = static fn () => ['offres' => 0, 'echantillons' => 0, 'convertis' => 0, 'clients' => 0];
    $m = [];
    foreach (array_merge(array_keys($sec), ['']) as $s) { foreach (array_keys($mags) as $sid) { $m[$s][$sid] = $vide(); } }
    foreach (Db::rows('SELECT shop_id, secteur, COUNT(*) AS n FROM sm_envoi WHERE ok = 1 AND modele IN ("offre","catalogue") AND le >= ? GROUP BY shop_id, secteur', [$du . ' 00:00:00']) as $r) {
        if (isset($m[$r['secteur']][$r['shop_id']])) { $m[$r['secteur']][$r['shop_id']]['offres'] += (int) $r['n']; }
    }
    foreach (Db::rows('SELECT shop_id, secteur, statut FROM sm_echantillon WHERE cree_le >= ?', [$du . ' 00:00:00']) as $r) {
        if (!isset($m[$r['secteur']][$r['shop_id']])) { continue; }
        $m[$r['secteur']][$r['shop_id']]['echantillons']++;
        if ($r['statut'] === 'converti') { $m[$r['secteur']][$r['shop_id']]['convertis']++; }
    }
    foreach (Db::rows('SELECT shop_id, secteur FROM sm_client WHERE statut <> "perdu" AND cree_le >= ?', [$du . ' 00:00:00']) as $r) {
        if (isset($m[$r['secteur']][$r['shop_id']])) { $m[$r['secteur']][$r['shop_id']]['clients']++; }
    }
    $secteurs = [];
    foreach ($m as $s => $parMag) {
        $t = $vide();
        foreach ($parMag as $x) { foreach ($t as $k => $_) { $t[$k] += $x[$k]; } }
        if ($s === '' && array_sum($t) === 0) { continue; }
        $secteurs[] = ['id' => $s, 'nom' => $s === '' ? 'Sans secteur' : $sec[$s]['nom'], 'total' => $t, 'magasins' => $parMag];
    }
    // la liste de démarchage de chaque magasin (Prospection) : dans la liste, contactés, rendez-vous, clients
    $prosp = [];
    foreach ($mags as $sid => $mg) {
        $p = ['shopId' => $sid, 'magasin' => $mg['nom'], 'liste' => 0, 'contactes' => 0, 'rdv' => 0, 'clients' => 0, 'refus' => 0];
        try {
            foreach ((array) (ep_prospection((string) $sid)['lieux'] ?? []) as $l) {
                if (!empty($l['coche'])) { $p['liste']++; }
                $r = (string) ($l['retour'] ?? '');
                if ($r !== '' || ($l['visite'] ?? '') !== '') { $p['contactes']++; }
                if ($r === 'rdv' || $r === 'interesse') { $p['rdv']++; }
                if ($r === 'client') { $p['clients']++; }
                if ($r === 'refus') { $p['refus']++; }
            }
        } catch (Throwable $e) { $p['indispo'] = true; }
        $prosp[] = $p;
    }
    return ['du' => $du, 'magasins' => array_values($mags), 'secteurs' => $secteurs, 'prospection' => $prosp,
        'source' => 'offres, catalogues, échantillons et clients : le portail ; listes de démarchage : Prospection (Marketing)'];
}

/* --- paramètres --------------------------------------------------------------------------------------------------- */

/** PUT /commercial/reglages — { gains?: {defaut, secteurs, plancher, tva}, expediteur?, signature?, webshop?, zones? } */
function wr_sm_reglages_put(): array
{
    $b = body();
    $s = setting('smReglages', []);
    $s = is_array($s) ? $s : [];
    if (is_array($b['gains'] ?? null)) {
        $g = $b['gains'];
        $defaut = smRegleGains(is_array($g['defaut'] ?? null) ? $g['defaut'] : [], smGainsDefaut());
        $secteurs = [];
        foreach ((array) ($g['secteurs'] ?? []) as $k => $r) { if (is_array($r) && isset(smSecteurs()[(string) $k])) { $secteurs[(string) $k] = smRegleGains($r, $defaut); } }
        $s['gains'] = ['defaut' => $defaut, 'secteurs' => $secteurs,
            'plancher' => is_numeric($g['plancher'] ?? null) ? max(0.0, round((float) $g['plancher'], 2)) : 0.0,
            'tva' => is_numeric($g['tva'] ?? null) ? max(0.0, min(25.0, (float) $g['tva'])) : SM_TVA,
            'objectif' => is_numeric($g['objectif'] ?? null) ? max(0.0, round((float) $g['objectif'], 2)) : SM_OBJECTIF,
            'cout' => is_numeric($g['cout'] ?? null) ? max(0.0, round((float) $g['cout'], 2)) : 0.0,
            'marge' => is_numeric($g['marge'] ?? null) ? max(0.0, min(100.0, (float) $g['marge'])) : 0.0,
            'departFranchise' => array_key_exists('departFranchise', $g) ? !empty($g['departFranchise']) : true];
    }
    if (array_key_exists('expediteur', $b)) { $s['expediteur'] = smTexte($b['expediteur'], 190); }
    if (is_array($b['signature'] ?? null)) {
        $s['signature'] = array_map(static fn ($v) => smTexte($v, 120), array_intersect_key($b['signature'], array_flip(['nom', 'fonction', 'telephone', 'site'])));
    }
    if (array_key_exists('webshop', $b)) { $w = smTexte($b['webshop'], 255); $s['webshop'] = preg_match('#^https?://#', $w) ? $w : ''; }
    if (is_array($b['zones'] ?? null)) {
        $z = [];
        foreach ($b['zones'] as $sid => $communes) {
            if (isset(smMagasins()[(string) $sid]) && is_array($communes)) { $z[(string) $sid] = array_values(array_filter(array_map(static fn ($c) => smTexte($c, 80), $communes), 'strlen')); }
        }
        $s['zones'] = $z;
    }
    if (is_array($b['cps'] ?? null)) {
        $z = [];
        foreach ($b['cps'] as $sid => $cps) {
            if (isset(smMagasins()[(string) $sid]) && is_array($cps)) { $z[(string) $sid] = array_values(array_filter(array_map(static fn ($c) => (string) preg_replace('/\D/', '', (string) $c), $cps), static fn ($c) => strlen($c) === 4)); }
        }
        $s['cps'] = $z;
    }
    settingEcrire('smReglages', $s);
    journalAdd('Commercial', 'Commercial', null, 'Paramètres du portail Commercial enregistrés');
    return ['ok' => true, 'reglages' => smReglages()];
}

/* --- les alertes du rail et les formulaires Meta ---------------------------------------------------------------- */

/** GET /commercial/alertes — les pastilles du rail : leads Meta à traiter, bureaux à valider (compte webshop pas connecté). */
function ep_sm_alertes(): array
{
    smTables();
    $leads = 0;
    try {
        $garde = $_GET; $_GET = ['jours' => '30'];
        try { $l = ep_sm_leads(); } finally { $_GET = $garde; }
        $leads = (int) ($l['comptes']['a_traiter'] ?? 0);
    } catch (Throwable $e) { /* MétaDonnée indisponible */ }
    $bureaux = (int) (Db::row('SELECT COUNT(*) AS n FROM sm_client WHERE statut <> "perdu" AND webshop <> "connecte"')['n'] ?? 0);
    return ['leads' => $leads, 'bureaux' => $bureaux];
}

/** Un formulaire de recrutement (candidats, franchisés) n'apporte pas de bureau : on le dit. */
function smFormulaireRecrutement(array $f): bool
{
    $txt = mb_strtolower((string) ($f['nom'] ?? '') . ' ' . implode(' ', array_map(static fn ($q) => (string) ($q['label'] ?? ''), (array) ($f['questions'] ?? []))));
    // « Job title » (la fonction, question standard de Meta) n'est pas une offre d'emploi.
    return (bool) preg_match('/franchis|recrut|flexi|candidat|emploi|\bjobs?\b(?!\s*title)|\bcv\b|vacature|sollicit/u', $txt);
}

/** GET /commercial/formulaires — les formulaires Meta de MétaDonnée (Marketing) : suivis ou non, et ce qu'ils apportent. */
function ep_sm_formulaires(): array
{
    $d = ep_md();
    $out = [];
    foreach ((array) ($d['formulaires'] ?? []) as $f) {
        $out[] = ['id' => (string) ($f['id'] ?? ''), 'nom' => (string) ($f['nom'] ?? ''), 'page' => (string) ($f['page'] ?? ''), 'suivi' => !empty($f['suivi']),
            'affectation' => (string) ($f['affectation'] ?? ''), 'shopId' => $f['shopId'] ?? null, 'leads30' => $f['leads30'] ?? null, 'dernierLead' => $f['dernierLead'] ?? null,
            'recrutement' => smFormulaireRecrutement($f), 'questions' => array_values(array_map(static fn ($q) => (string) ($q['label'] ?? ''), (array) ($f['questions'] ?? [])))];
    }
    usort($out, static fn ($a, $b) => [$a['recrutement'], !$a['suivi'], $a['nom']] <=> [$b['recrutement'], !$b['suivi'], $b['nom']]);
    return ['formulaires' => $out, 'source' => 'MétaDonnée (Marketing) : formulaires de la Page Meta'];
}

/** PUT /commercial/formulaires/{id} — { suivi, affectation: magasin|cp, shopId? } : suivre un formulaire de bureaux (par MétaDonnée). */
function wr_sm_formulaire_put(string $id): array
{
    return wr_md_formulaire_put($id);
}

/** POST /commercial/formulaires/{id}/historique — { jours } : récupérer les leads passés du formulaire (90 jours au plus chez Meta). */
function wr_sm_formulaire_historique(string $id): array
{
    return wr_md_formulaire_rattrapage($id);
}

/* --- le routeur -------------------------------------------------------------------------------------------------- */

/** Les routes /commercial/… ; null pour une autre adresse. */
function smRoute(string $method, string $path): mixed
{
    if ($path !== '/commercial' && !str_starts_with($path, '/commercial/')) { return null; }
    $p = substr($path, strlen('/commercial')) ?: '/';
    // la semaine du commercial et le suivi de terrain (src/commercial_semaine.php)
    if (($s = smRouteSemaine($method, $p)) !== null) { return $s; }
    // le formulaire Meta des bureaux (src/commercial_meta.php)
    if (($s = smRouteMeta($method, $p)) !== null) { return $s; }
    if ($method === 'GET') {
        return match (true) {
            $p === '/config' => ep_sm_config(),
            $p === '/tableau' => ep_sm_tableau(),
            $p === '/leads' => ep_sm_leads(),
            $p === '/clients' => ep_sm_clients(),
            $p === '/clients-panel' => ep_sm_clients_panel(),
            $p === '/offres' => ep_sm_offres(),
            $p === '/bons' => ep_sm_bons(),
            $p === '/catalogues' => ep_sm_catalogues(),
            $p === '/modeles' => ep_sm_modeles(),
            $p === '/envois' => ep_sm_envois(),
            $p === '/echantillons' => ep_sm_echantillons(),
            $p === '/suivi' => ep_sm_suivi(),
            $p === '/gains' => ep_sm_gains(),
            $p === '/trouver' => ep_sm_trouver(),
            $p === '/commissions' => ep_sm_commissions(),
            $p === '/simulation' => ep_sm_simulation(),
            $p === '/alertes' => ep_sm_alertes(),
            $p === '/formulaires' => ep_sm_formulaires(),
            default => smInconnue(),
        };
    }
    if ($method === 'POST') {
        return match (true) {
            $p === '/clients' => wr_sm_client_creer(),
            $p === '/bons' => wr_sm_bon_creer(),
            $p === '/apercu' => wr_sm_apercu(),
            $p === '/envoyer' => wr_sm_envoyer(),
            $p === '/echantillons' => wr_sm_echantillon_creer(),
            $p === '/catalogues/lien' => wr_sm_catalogue_lien(),
            (bool) preg_match('#^/formulaires/(\d{5,20})/historique$#', $p, $mf) => wr_sm_formulaire_historique($mf[1]),
            default => smInconnue(),
        };
    }
    if ($method === 'PUT') {
        if (preg_match('#^/clients/(\d+)$#', $p, $m)) { return wr_sm_client_put((int) $m[1]); }
        if (preg_match('#^/bons/(\d+)$#', $p, $m)) { return wr_sm_bon_put((int) $m[1]); }
        if (preg_match('#^/echantillons/(\d+)$#', $p, $m)) { return wr_sm_echantillon_put((int) $m[1]); }
        if (preg_match('#^/modeles/([a-z]+)/(fr|nl)$#', $p, $m)) { return wr_sm_modele_put($m[1], $m[2]); }
        if ($p === '/offres') { return wr_sm_offre_put(); }
        if ($p === '/reglages') { return wr_sm_reglages_put(); }
        if (preg_match('#^/formulaires/(\d{5,20})$#', $p, $m)) { return wr_sm_formulaire_put($m[1]); }
    }
    return smInconnue();
}

function smInconnue(): array
{
    http_response_code(404);
    return ['error' => 'route inconnue du portail Commercial'];
}
