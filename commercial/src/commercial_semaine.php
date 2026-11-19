<?php
declare(strict_types=1);

/**
 * La semaine du commercial et son suivi sur le terrain (portail Commercial, 19/11/2026, demande : « un assistant pour
 * que sa semaine soit efficace : quel magasin, quelle société, envoyer un mail — qui a validé le rendez-vous et qui pas
 * —, les commandes d'échantillons, le coup de téléphone validé au franchisé ; et l'app mobile de suivi : j'ai été chez le
 * client, j'ai fait x, offre validée, quelle est la next action — appeler, écrire, voucher, rappeler après la 1re
 * commande ? — pour chaque client, un assistant horizontal ; toutes les possibilités : client pas là, no show, pas le
 * temps… »).
 *
 * Deux tables :
 *  - sm_rdv : ce qui est prévu (visite, appel, e-mail, bon, échantillon, devis, rappel après la 1re commande, bureau à
 *    créer), avec sa date, la confirmation du client, l'échantillon commandé ; fait, reporté ou annulé ;
 *  - sm_cr  : le compte rendu de chaque contact (l'issue, ce qui a été fait, l'offre et sa réponse, la suite) ; la suite
 *    crée le rendez-vous suivant.
 * Et l'appel au franchisé, une fois par semaine et par magasin : sm_appel_franchise.
 */

const SM_RDV_TYPES = ['visite' => 'Visite', 'appel' => 'Appel', 'mail' => 'E-mail', 'voucher' => 'Bon à envoyer', 'echantillon' => 'Échantillon',
    'devis' => 'Devis', 'rappel_commande' => 'Rappel après la 1re commande', 'bureau' => 'Bureau à créer'];
const SM_RDV_STATUTS = ['a_faire', 'fait', 'reporte', 'annule'];
const SM_CONFIRMATIONS = ['', 'envoyee', 'confirme', 'sans_reponse', 'refuse'];
/** L'issue d'un contact : le client vu, ou pourquoi pas. */
const SM_ISSUES = ['vu' => 'Client vu', 'absent' => 'Client absent', 'no_show' => 'No show', 'pas_le_temps' => 'Pas le temps', 'reporte' => 'Reporté par le client',
    'refus' => 'Refus de recevoir', 'mauvais_contact' => 'Mauvais contact ou plus là', 'joint' => 'Joint au téléphone', 'pas_joint' => 'Pas joint au téléphone'];
const SM_FAITS = ['presentation' => 'Présentation', 'degustation' => 'Dégustation', 'echantillon' => 'Échantillon remis', 'catalogue' => 'Catalogue remis',
    'bon' => 'Bon remis', 'locaux' => 'Visite des locaux', 'decideur' => 'Décideur rencontré', 'commande' => 'Commande prise'];
const SM_OFFRE_STATUTS = ['' => 'Pas présentée', 'validee' => 'Validée', 'reflechir' => 'À réfléchir', 'refusee' => 'Refusée'];
const SM_MOTIFS = ['prix' => 'Prix', 'fournisseur' => 'Déjà un fournisseur', 'besoin' => 'Pas de besoin', 'budget' => 'Pas de budget', 'decideur' => 'Décideur absent',
    'livraison' => 'Livraison ou horaires', 'autre' => 'Autre'];
/** La suite d'un contact : le type du rendez-vous qu'elle crée, et son délai par défaut (jours). */
const SM_SUITES = ['appeler' => ['appel', 2], 'ecrire' => ['mail', 0], 'voucher' => ['voucher', 0], 'rappel_commande' => ['rappel_commande', 7],
    'repasser' => ['visite', 7], 'echantillon' => ['echantillon', 3], 'devis' => ['devis', 1], 'bureau' => ['bureau', 0], 'rien' => [null, 0]];

function smTablesSemaine(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    smTables();
    Db::exec('CREATE TABLE IF NOT EXISTS sm_rdv ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, lead_id INT NULL, prospect VARCHAR(200) NOT NULL, contact VARCHAR(160) NOT NULL DEFAULT "",'
        . 'email VARCHAR(190) NOT NULL DEFAULT "", telephone VARCHAR(40) NOT NULL DEFAULT "", adresse VARCHAR(255) NOT NULL DEFAULT "", shop_id VARCHAR(16) NOT NULL,'
        . 'secteur VARCHAR(16) NOT NULL DEFAULT "", type VARCHAR(16) NOT NULL DEFAULT "visite", le DATETIME NOT NULL, degustation TINYINT NOT NULL DEFAULT 0,'
        . 'confirmation VARCHAR(12) NOT NULL DEFAULT "", confirme_le DATETIME NULL, echantillon_id INT NULL, statut VARCHAR(10) NOT NULL DEFAULT "a_faire",'
        . 'issue VARCHAR(16) NOT NULL DEFAULT "", origine_cr INT NULL, note VARCHAR(500) NOT NULL DEFAULT "", cree_le DATETIME NOT NULL,'
        . 'KEY (le), KEY (client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS sm_cr ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, rdv_id INT NULL, client_id INT NULL, prospect VARCHAR(200) NOT NULL, shop_id VARCHAR(16) NOT NULL, le DATETIME NOT NULL,'
        . 'issue VARCHAR(16) NOT NULL, faits VARCHAR(255) NOT NULL DEFAULT "", offre_id VARCHAR(16) NOT NULL DEFAULT "", offre_statut VARCHAR(10) NOT NULL DEFAULT "",'
        . 'motif VARCHAR(16) NOT NULL DEFAULT "", suite VARCHAR(16) NOT NULL DEFAULT "rien", suite_le DATE NULL, suite_rdv INT NULL, note TEXT NULL,'
        . 'cree_le DATETIME NOT NULL, KEY (client_id), KEY (le)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS sm_appel_franchise ('
        . 'shop_id VARCHAR(16) NOT NULL, semaine DATE NOT NULL, ok TINYINT NOT NULL DEFAULT 0, le DATETIME NULL, note VARCHAR(500) NOT NULL DEFAULT "",'
        . 'PRIMARY KEY (shop_id, semaine)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le lundi de la semaine d'une date. */
function smLundi(string $j): string
{
    $t = (int) strtotime($j);
    return date('Y-m-d', $t - ((int) date('N', $t) - 1) * 86400);
}

/** La date d'une suite : la date choisie si elle est valable, sinon le jour + son délai par défaut (un dimanche passe au lundi). */
function smSuiteDate(string $suite, string $jour, ?string $choisie): ?string
{
    if (!isset(SM_SUITES[$suite]) || SM_SUITES[$suite][0] === null) { return null; }
    if ($choisie !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $choisie)) { return $choisie; }
    $d = date('Y-m-d', (int) strtotime($jour . ' +' . SM_SUITES[$suite][1] . ' days'));
    return date('N', (int) strtotime($d)) === '7' ? date('Y-m-d', (int) strtotime($d . ' +1 day')) : $d;
}

/**
 * La suite proposée d'après le contact (la même règle que l'app mobile) : l'issue, l'offre et ce qui a été fait.
 * Rend la clé de SM_SUITES.
 */
function smSuiteProposee(string $issue, string $offre, array $faits, bool $estClient): string
{
    if (in_array($issue, ['absent', 'no_show', 'pas_joint'], true)) { return 'appeler'; }
    if ($issue === 'pas_le_temps' || $issue === 'reporte') { return 'appeler'; }
    if ($issue === 'mauvais_contact') { return 'ecrire'; }
    if ($issue === 'refus') { return 'rien'; }
    if ($offre === 'validee') { return $estClient ? 'rappel_commande' : 'bureau'; }
    if ($offre === 'reflechir') { return in_array('degustation', $faits, true) || in_array('echantillon', $faits, true) ? 'appeler' : 'echantillon'; }
    if ($offre === 'refusee') { return 'rien'; }
    return in_array('presentation', $faits, true) ? 'devis' : 'appeler';
}

/** Les fiches des magasins lues au panel (GET /shops) : e-mail et téléphone, gardés un jour ; rien d'autre n'est gardé. */
function smFichesMagasins(): array
{
    $m = setting('smFichesMagasins');
    if (is_array($m) && (int) ($m['le'] ?? 0) > time() - 86400 && is_array($m['v'] ?? null)) { return $m['v']; }
    $v = [];
    if (class_exists('PanelApi') && PanelApi::configured()) {
        $l = PanelApi::get('/shops');
        foreach ((array) (is_array($l) && isset($l['data']) ? $l['data'] : $l) as $s) {
            if (!is_array($s) || !isset($s['id'])) { continue; }
            $v[(string) $s['id']] = ['email' => smEmail($s['email'] ?? ''), 'tel' => smTexte($s['phone'] ?? '', 40), 'adresse' => trim(smTexte(($s['street'] ?? '') . ' ' . ($s['street_num'] ?? ''), 160) . ', ' . smTexte(($s['zip'] ?? '') . ' ' . ($s['city'] ?? ''), 80), ', ')];
        }
        if ($v !== []) { settingEcrire('smFichesMagasins', ['le' => time(), 'v' => $v]); }
    }
    return $v;
}

function smRdvVue(array $r, array $mags): array
{
    return ['id' => (int) $r['id'], 'clientId' => $r['client_id'] !== null ? (int) $r['client_id'] : null, 'leadId' => $r['lead_id'] !== null ? (int) $r['lead_id'] : null,
        'prospect' => $r['prospect'], 'contact' => $r['contact'], 'email' => $r['email'], 'telephone' => $r['telephone'], 'adresse' => $r['adresse'],
        'shopId' => $r['shop_id'], 'magasin' => $mags[$r['shop_id']]['nom'] ?? $r['shop_id'], 'secteur' => $r['secteur'], 'type' => $r['type'],
        'typeNom' => SM_RDV_TYPES[$r['type']] ?? $r['type'], 'le' => $r['le'], 'degustation' => (bool) $r['degustation'], 'confirmation' => $r['confirmation'],
        'confirmeLe' => $r['confirme_le'], 'echantillonId' => $r['echantillon_id'] !== null ? (int) $r['echantillon_id'] : null, 'statut' => $r['statut'],
        'issue' => $r['issue'], 'origineCr' => $r['origine_cr'] !== null ? (int) $r['origine_cr'] : null, 'note' => $r['note']];
}

/** Les champs d'un rendez-vous lus dans le corps ; ceux présents (PUT) ou tous (POST). */
function smRdvChamps(array $b, bool $tous): array
{
    $c = [];
    $set = static fn (string $k): bool => $tous || array_key_exists($k, $b);
    if ($set('shopId')) {
        $s = (string) ($b['shopId'] ?? '');
        if (!isset(smMagasins()[$s])) { throw new InvalidArgumentException('magasin inconnu'); }
        $c['shop_id'] = $s;
    }
    foreach (['prospect' => 200, 'contact' => 160, 'telephone' => 40, 'adresse' => 255, 'note' => 500] as $k => $max) { if ($set($k)) { $c[$k] = smTexte($b[$k] ?? '', $max); } }
    if ($set('email')) { $c['email'] = smEmail($b['email'] ?? ''); }
    if ($set('secteur')) { $s = (string) ($b['secteur'] ?? ''); $c['secteur'] = isset(smSecteurs()[$s]) ? $s : ''; }
    if ($set('type')) { $c['type'] = isset(SM_RDV_TYPES[$b['type'] ?? '']) ? (string) $b['type'] : 'visite'; }
    if ($set('le')) {
        $l = str_replace('T', ' ', (string) ($b['le'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?/', $l)) { throw new InvalidArgumentException('date du rendez-vous attendue'); }
        $c['le'] = strlen($l) === 10 ? $l . ' 09:00:00' : substr($l, 0, 16) . ':00';
    }
    if ($set('degustation')) { $c['degustation'] = !empty($b['degustation']) ? 1 : 0; }
    if ($set('clientId')) { $c['client_id'] = is_numeric($b['clientId'] ?? null) && (int) $b['clientId'] > 0 ? (int) $b['clientId'] : null; }
    if ($set('leadId')) { $c['lead_id'] = is_numeric($b['leadId'] ?? null) && (int) $b['leadId'] > 0 ? (int) $b['leadId'] : null; }
    if (array_key_exists('statut', $b)) { $c['statut'] = in_array($b['statut'], SM_RDV_STATUTS, true) ? $b['statut'] : 'a_faire'; }
    if (array_key_exists('confirmation', $b)) {
        $c['confirmation'] = in_array($b['confirmation'], SM_CONFIRMATIONS, true) ? $b['confirmation'] : '';
        if ($c['confirmation'] === 'confirme') { $c['confirme_le'] = date('Y-m-d H:i:s'); }
    }
    if (array_key_exists('echantillonId', $b)) { $c['echantillon_id'] = is_numeric($b['echantillonId']) ? (int) $b['echantillonId'] : null; }
    return $c;
}

/** Un client du portail complète un rendez-vous : nom, contact, coordonnées, magasin, secteur. */
function smRdvDepuisClient(array $c): array
{
    if (empty($c['client_id'])) { return $c; }
    $r = Db::row('SELECT * FROM sm_client WHERE id = ?', [(int) $c['client_id']]);
    if ($r === null) { return $c; }
    foreach (['prospect' => 'entreprise', 'contact' => 'contact', 'email' => 'email', 'telephone' => 'telephone', 'adresse' => 'adresse', 'shop_id' => 'shop_id', 'secteur' => 'secteur'] as $k => $src) {
        if (($c[$k] ?? '') === '' && (string) $r[$src] !== '') { $c[$k] = $r[$src]; }
    }
    return $c;
}

/** POST /commercial/rdv */
function wr_sm_rdv_creer(): array
{
    smTablesSemaine();
    try { $c = smRdvDepuisClient(smRdvChamps(body(), true)); } catch (InvalidArgumentException $e) { http_response_code(422); return ['error' => $e->getMessage()]; }
    if (($c['prospect'] ?? '') === '') { http_response_code(422); return ['error' => 'la société manque']; }
    $c += ['cree_le' => date('Y-m-d H:i:s')];
    Db::exec('INSERT INTO sm_rdv (' . implode(',', array_keys($c)) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')', array_values($c));
    $id = (int) Db::pdo()->lastInsertId();
    return ['ok' => true, 'rdv' => smRdvVue(Db::row('SELECT * FROM sm_rdv WHERE id = ?', [$id]), smMagasins())];
}

/** PUT /commercial/rdv/{id} */
function wr_sm_rdv_put(int $id): array
{
    smTablesSemaine();
    if (Db::row('SELECT id FROM sm_rdv WHERE id = ?', [$id]) === null) { http_response_code(404); return ['error' => 'rendez-vous introuvable']; }
    try { $c = smRdvChamps(body(), false); } catch (InvalidArgumentException $e) { http_response_code(422); return ['error' => $e->getMessage()]; }
    if ($c !== []) { Db::exec('UPDATE sm_rdv SET ' . implode(', ', array_map(static fn ($k) => $k . ' = ?', array_keys($c))) . ' WHERE id = ?', [...array_values($c), $id]); }
    return ['ok' => true, 'rdv' => smRdvVue(Db::row('SELECT * FROM sm_rdv WHERE id = ?', [$id]), smMagasins())];
}

/** POST /commercial/rdv/{id}/confirmer — l'e-mail de confirmation (modèle « rdv »), depuis l'expéditeur réglé. */
function wr_sm_rdv_confirmer(int $id): array
{
    smTablesSemaine();
    $r = Db::row('SELECT * FROM sm_rdv WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'rendez-vous introuvable']; }
    $a = smEmail(body()['a'] ?? $r['email']);
    if ($a === '') { http_response_code(422); return ['error' => 'pas d’adresse e-mail pour ce rendez-vous']; }
    $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $t = (int) strtotime((string) $r['le']);
    $quand = $jours[(int) date('N', $t)] . ' ' . docDate((string) $r['le'], true) . ' à ' . date('H:i', $t);
    $env = smEnvoyer(['modele' => 'rdv', 'a' => $a, 'clientId' => $r['client_id'], 'shopId' => $r['shop_id'], 'entreprise' => $r['prospect'], 'contact' => $r['contact'],
        'dateRdv' => $quand, 'langue' => (string) (body()['langue'] ?? 'fr')]);
    if ($env['ok']) { Db::exec('UPDATE sm_rdv SET confirmation = IF(confirmation = "confirme", "confirme", "envoyee"), email = ? WHERE id = ?', [$a, $id]); }
    else { http_response_code(502); }
    return $env;
}

/**
 * GET /commercial/semaine?du=AAAA-MM-JJ — la semaine (du lundi au samedi) : les rendez-vous, les candidats (leads à
 * traiter, bureaux et clients du portail, lieux de Prospection à rappeler), l'appel au franchisé de chaque magasin.
 */
function ep_sm_semaine(): array
{
    smTablesSemaine();
    $du = smLundi(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['du'] ?? '')) ? (string) $_GET['du'] : date('Y-m-d'));
    $au = date('Y-m-d', (int) strtotime($du . ' +6 days'));
    $mags = smMagasins();
    $rdv = array_map(static fn ($r) => smRdvVue($r, $mags), Db::rows('SELECT * FROM sm_rdv WHERE le >= ? AND le < ? ORDER BY le', [$du . ' 00:00:00', date('Y-m-d', (int) strtotime($au . ' +1 day')) . ' 00:00:00']));
    $retard = array_map(static fn ($r) => smRdvVue($r, $mags), Db::rows('SELECT * FROM sm_rdv WHERE statut = "a_faire" AND le < ? ORDER BY le LIMIT 50', [$du . ' 00:00:00']));
    // les candidats : ce qui attend un contact
    $deja = [];
    foreach (Db::rows('SELECT client_id, lead_id FROM sm_rdv WHERE statut = "a_faire"') as $x) {
        if ($x['client_id'] !== null) { $deja['c' . $x['client_id']] = true; }
        if ($x['lead_id'] !== null) { $deja['l' . $x['lead_id']] = true; }
    }
    $cand = [];
    try {
        $garde = $_GET; $_GET = ['jours' => '60'];
        try { $l = ep_sm_leads(); } finally { $_GET = $garde; }
        foreach ($l['leads'] as $x) {
            if ($x['clientId'] !== null || !in_array($x['famille'], ['a_traiter', 'contacte'], true) || isset($deja['l' . $x['id']])) { continue; }
            $cand[] = ['source' => 'lead', 'leadId' => $x['id'], 'clientId' => null, 'prospect' => $x['entreprise'] !== '' ? $x['entreprise'] : $x['contact'], 'contact' => $x['contact'],
                'email' => $x['email'], 'telephone' => $x['telephone'], 'shopId' => $x['shopId'], 'magasin' => $x['magasin'], 'secteur' => 'bureau',
                'pourquoi' => 'Lead Meta ' . ($x['famille'] === 'a_traiter' ? 'à traiter' : 'contacté') . ' · reçu ' . substr((string) $x['recu'], 8, 2) . '/' . substr((string) $x['recu'], 5, 2)];
        }
    } catch (Throwable $e) { /* MétaDonnée indisponible : les autres candidats restent */ }
    foreach (Db::rows('SELECT * FROM sm_client WHERE statut <> "perdu" ORDER BY maj_le DESC LIMIT 100') as $c) {
        if (isset($deja['c' . $c['id']])) { continue; }
        $cand[] = ['source' => 'client', 'leadId' => null, 'clientId' => (int) $c['id'], 'prospect' => $c['entreprise'], 'contact' => $c['contact'], 'email' => $c['email'],
            'telephone' => $c['telephone'], 'shopId' => $c['shop_id'], 'magasin' => $mags[$c['shop_id']]['nom'] ?? $c['shop_id'], 'secteur' => $c['secteur'],
            'pourquoi' => ['prospect' => 'Prospect du portail', 'bureau_cree' => 'Bureau créé, à faire commander', 'client' => 'Client à fidéliser'][$c['statut']] ?? 'Client du portail'];
    }
    foreach ($mags as $sid => $m) {
        try {
            foreach ((array) (ep_prospection((string) $sid)['lieux'] ?? []) as $pl) {
                $ret = (string) ($pl['retour'] ?? '');
                if (!in_array($ret, ['rappeler', 'rdv', 'interesse'], true) && ($pl['action'] ?? '') === '') { continue; }
                if ((string) ($pl['nom'] ?? '') === '') { continue; }
                $cand[] = ['source' => 'prospection', 'leadId' => null, 'clientId' => null, 'prospect' => (string) $pl['nom'], 'contact' => '', 'email' => '', 'telephone' => '',
                    'shopId' => (string) $sid, 'magasin' => $m['nom'], 'secteur' => (string) ($pl['offre'] ?? ''),
                    'pourquoi' => 'Prospection : ' . ($ret !== '' ? $ret : 'à suivre') . (($pl['actionLe'] ?? '') !== '' ? ' · pour le ' . substr((string) $pl['actionLe'], 8, 2) . '/' . substr((string) $pl['actionLe'], 5, 2) : '')];
            }
        } catch (Throwable $e) { /* Prospection indisponible pour ce magasin */ }
    }
    $appels = [];
    foreach (Db::rows('SELECT * FROM sm_appel_franchise WHERE semaine = ?', [$du]) as $a) { $appels[$a['shop_id']] = $a; }
    $fiches = smFichesMagasins();
    $magasins = [];
    foreach ($mags as $sid => $m) {
        $duMag = array_filter($rdv, static fn ($r) => $r['shopId'] === (string) $sid && $r['statut'] !== 'annule');
        $magasins[] = $m + ['tel' => $fiches[$sid]['tel'] ?? '', 'rdv' => count($duMag), 'degustations' => count(array_filter($duMag, static fn ($r) => $r['degustation'])),
            'candidats' => count(array_filter($cand, static fn ($c) => $c['shopId'] === (string) $sid)),
            'appel' => isset($appels[$sid]) ? ['ok' => (bool) $appels[$sid]['ok'], 'le' => $appels[$sid]['le'], 'note' => $appels[$sid]['note']] : ['ok' => false, 'le' => null, 'note' => '']];
    }
    $ech = [];
    $ids = array_values(array_filter(array_column($rdv, 'echantillonId')));
    if ($ids) {
        foreach (Db::rows('SELECT * FROM sm_echantillon WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $e) { $ech[(int) $e['id']] = smEchVue($e, $mags); }
    }
    return ['du' => $du, 'au' => $au, 'rdv' => $rdv, 'enRetard' => $retard, 'candidats' => $cand, 'magasins' => $magasins, 'echantillons' => (object) $ech,
        'types' => SM_RDV_TYPES];
}

/** PUT /commercial/semaine/franchise — { shopId, du, ok, note? } : l'appel au franchisé de la semaine, validé ou non. */
function wr_sm_semaine_franchise(): array
{
    smTablesSemaine();
    $b = body();
    $s = (string) ($b['shopId'] ?? '');
    if (!isset(smMagasins()[$s])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $du = smLundi(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['du'] ?? '')) ? (string) $b['du'] : date('Y-m-d'));
    $ok = !empty($b['ok']) ? 1 : 0;
    Db::exec('INSERT INTO sm_appel_franchise (shop_id, semaine, ok, le, note) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE ok = VALUES(ok), le = VALUES(le), note = VALUES(note)',
        [$s, $du, $ok, $ok ? date('Y-m-d H:i:s') : null, smTexte($b['note'] ?? '', 500)]);
    return ['ok' => true];
}

/** GET /commercial/mobile?jour= — l'app de terrain : le jour, les retards, la suite de la semaine. */
function ep_sm_mobile(): array
{
    smTablesSemaine();
    $jour = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['jour'] ?? '')) ? (string) $_GET['jour'] : date('Y-m-d');
    $mags = smMagasins();
    $v = static fn ($r) => smRdvVue($r, $mags);
    $fin = date('Y-m-d', (int) strtotime(smLundi($jour) . ' +7 days'));
    return ['jour' => $jour,
        'aujourdhui' => array_map($v, Db::rows('SELECT * FROM sm_rdv WHERE le >= ? AND le < ? AND statut <> "annule" ORDER BY le', [$jour . ' 00:00:00', date('Y-m-d', (int) strtotime($jour . ' +1 day')) . ' 00:00:00'])),
        'enRetard' => array_map($v, Db::rows('SELECT * FROM sm_rdv WHERE statut = "a_faire" AND le < ? ORDER BY le LIMIT 30', [$jour . ' 00:00:00'])),
        'aVenir' => array_map($v, Db::rows('SELECT * FROM sm_rdv WHERE statut = "a_faire" AND le >= ? AND le < ? ORDER BY le LIMIT 40', [date('Y-m-d', (int) strtotime($jour . ' +1 day')) . ' 00:00:00', $fin . ' 00:00:00'])),
        'listes' => ['issues' => SM_ISSUES, 'faits' => SM_FAITS, 'offres' => SM_OFFRE_STATUTS, 'motifs' => SM_MOTIFS,
            'suites' => array_map(static fn ($s) => ['type' => $s[0], 'jours' => $s[1]], SM_SUITES), 'types' => SM_RDV_TYPES]];
}

/** GET /commercial/cr?client=&rdv= — les comptes rendus d'un client (ou d'un rendez-vous), du plus récent au plus ancien. */
function ep_sm_cr(): array
{
    smTablesSemaine();
    $c = (int) ($_GET['client'] ?? 0); $r = (int) ($_GET['rdv'] ?? 0);
    $rows = $c > 0 ? Db::rows('SELECT * FROM sm_cr WHERE client_id = ? ORDER BY le DESC LIMIT 50', [$c])
        : ($r > 0 ? Db::rows('SELECT * FROM sm_cr WHERE rdv_id = ? ORDER BY le DESC', [$r]) : Db::rows('SELECT * FROM sm_cr ORDER BY le DESC LIMIT 50'));
    return ['cr' => array_map(static fn ($x) => ['id' => (int) $x['id'], 'rdvId' => $x['rdv_id'] !== null ? (int) $x['rdv_id'] : null, 'clientId' => $x['client_id'] !== null ? (int) $x['client_id'] : null,
        'prospect' => $x['prospect'], 'shopId' => $x['shop_id'], 'le' => $x['le'], 'issue' => $x['issue'], 'issueNom' => SM_ISSUES[$x['issue']] ?? $x['issue'],
        'faits' => array_values(array_filter(explode(',', (string) $x['faits']))), 'offre' => $x['offre_id'], 'offreStatut' => $x['offre_statut'], 'motif' => $x['motif'],
        'suite' => $x['suite'], 'suiteLe' => $x['suite_le'], 'suiteRdv' => $x['suite_rdv'] !== null ? (int) $x['suite_rdv'] : null, 'note' => (string) ($x['note'] ?? '')], $rows)];
}

/**
 * POST /commercial/cr — le compte rendu d'un contact (l'assistant de l'app mobile) :
 * { rdvId?, clientId?, prospect?, contact?, email?, telephone?, shopId?, issue, faits[], offre?, offreStatut?, motif?, suite, suiteLe?, note? }.
 * Le rendez-vous passe à « fait » ; la suite crée le rendez-vous suivant ; une offre refusée sans suite classe le client perdu.
 */
function wr_sm_cr_creer(): array
{
    smTablesSemaine();
    $b = body();
    $rdv = !empty($b['rdvId']) ? Db::row('SELECT * FROM sm_rdv WHERE id = ?', [(int) $b['rdvId']]) : null;
    $clientId = is_numeric($b['clientId'] ?? null) && (int) $b['clientId'] > 0 ? (int) $b['clientId'] : ($rdv['client_id'] ?? null);
    $client = $clientId ? Db::row('SELECT * FROM sm_client WHERE id = ?', [(int) $clientId]) : null;
    $shop = (string) ($b['shopId'] ?? ($rdv['shop_id'] ?? ($client['shop_id'] ?? '')));
    if (!isset(smMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $prospect = smTexte($b['prospect'] ?? ($rdv['prospect'] ?? ($client['entreprise'] ?? '')), 200);
    if ($prospect === '') { http_response_code(422); return ['error' => 'la société manque']; }
    $issue = isset(SM_ISSUES[$b['issue'] ?? '']) ? (string) $b['issue'] : '';
    if ($issue === '') { http_response_code(422); return ['error' => 'dites comment s’est passé le contact']; }
    $faits = array_values(array_intersect(array_map('strval', (array) ($b['faits'] ?? [])), array_keys(SM_FAITS)));
    $offreSt = isset(SM_OFFRE_STATUTS[$b['offreStatut'] ?? '']) ? (string) $b['offreStatut'] : '';
    $offre = isset(smSecteurs()[(string) ($b['offre'] ?? '')]) ? (string) $b['offre'] : '';
    $motif = $offreSt === 'refusee' && isset(SM_MOTIFS[$b['motif'] ?? '']) ? (string) $b['motif'] : '';
    $suite = isset(SM_SUITES[$b['suite'] ?? '']) ? (string) $b['suite'] : smSuiteProposee($issue, $offreSt, $faits, $client !== null);
    $jour = date('Y-m-d');
    $suiteLe = smSuiteDate($suite, $jour, isset($b['suiteLe']) ? (string) $b['suiteLe'] : null);
    $now = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO sm_cr (rdv_id, client_id, prospect, shop_id, le, issue, faits, offre_id, offre_statut, motif, suite, suite_le, note, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$rdv['id'] ?? null, $clientId, $prospect, $shop, $now, $issue, implode(',', $faits), $offre, $offreSt, $motif, $suite, $suiteLe, smTexte($b['note'] ?? '', 4000), $now]);
    $crId = (int) Db::pdo()->lastInsertId();
    if ($rdv !== null) { Db::exec('UPDATE sm_rdv SET statut = ?, issue = ? WHERE id = ?', [$issue === 'reporte' ? 'reporte' : 'fait', $issue, (int) $rdv['id']]); }
    $suivant = null;
    if ($suiteLe !== null) {
        $type = SM_SUITES[$suite][0];
        $heure = $rdv !== null && $type === 'visite' ? substr((string) $rdv['le'], 11, 5) : '09:00';
        Db::exec('INSERT INTO sm_rdv (client_id, lead_id, prospect, contact, email, telephone, adresse, shop_id, secteur, type, le, statut, origine_cr, note, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,"a_faire",?,?,?)',
            [$clientId, $rdv['lead_id'] ?? null, $prospect, smTexte($b['contact'] ?? ($rdv['contact'] ?? ($client['contact'] ?? '')), 160), smEmail($b['email'] ?? ($rdv['email'] ?? ($client['email'] ?? ''))),
                smTexte($b['telephone'] ?? ($rdv['telephone'] ?? ($client['telephone'] ?? '')), 40), smTexte($rdv['adresse'] ?? ($client['adresse'] ?? ''), 255), $shop,
                $offre !== '' ? $offre : (string) ($rdv['secteur'] ?? ($client['secteur'] ?? '')), $type, $suiteLe . ' ' . $heure . ':00', $crId,
                smTexte(SM_ISSUES[$issue] . ($offreSt !== '' ? ' · offre ' . mb_strtolower(SM_OFFRE_STATUTS[$offreSt]) : ''), 500), $now]);
        $suivant = (int) Db::pdo()->lastInsertId();
        Db::exec('UPDATE sm_cr SET suite_rdv = ? WHERE id = ?', [$suivant, $crId]);
    }
    if ($client !== null) {
        $st = $offreSt === 'refusee' && $suite === 'rien' ? 'perdu' : ($offreSt === 'validee' && $client['statut'] === 'prospect' ? 'bureau_cree' : null);
        if ($st !== null) { Db::exec('UPDATE sm_client SET statut = ?, maj_le = NOW() WHERE id = ?', [$st, (int) $client['id']]); }
    }
    journalAdd('Commercial', 'Commercial', null, 'Compte rendu : ' . $prospect . ' — ' . SM_ISSUES[$issue]);
    return ['ok' => true, 'crId' => $crId, 'suite' => $suite, 'suiteLe' => $suiteLe,
        'suivant' => $suivant ? smRdvVue(Db::row('SELECT * FROM sm_rdv WHERE id = ?', [$suivant]), smMagasins()) : null];
}

/** Les routes de la semaine et du suivi ; null pour une autre adresse. */
function smRouteSemaine(string $method, string $p): mixed
{
    if ($method === 'GET' && $p === '/semaine') { return ep_sm_semaine(); }
    if ($method === 'GET' && $p === '/mobile') { return ep_sm_mobile(); }
    if ($method === 'GET' && $p === '/cr') { return ep_sm_cr(); }
    if ($method === 'POST' && $p === '/cr') { return wr_sm_cr_creer(); }
    if ($method === 'POST' && $p === '/rdv') { return wr_sm_rdv_creer(); }
    if ($method === 'PUT' && preg_match('#^/rdv/(\d+)$#', $p, $m)) { return wr_sm_rdv_put((int) $m[1]); }
    if ($method === 'POST' && preg_match('#^/rdv/(\d+)/confirmer$#', $p, $m)) { return wr_sm_rdv_confirmer((int) $m[1]); }
    if ($method === 'PUT' && $p === '/semaine/franchise') { return wr_sm_semaine_franchise(); }
    return null;
}
