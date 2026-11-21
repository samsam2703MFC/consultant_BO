<?php
declare(strict_types=1);

/**
 * Dashboard magasin › Boîte mail › les modes IA (21/11/2026, « mets les 3 options futuristes en toggle, avec un bouton
 * type futuriste » ; maquettes docs/maquettes/boite-mail-futur/).
 *
 * LA BRIQUE COMMUNE. Chaque e-mail de la réception est lu UNE fois par l'IA, et la lecture est gardée
 * (panel_boite_lecture, par boîte, UIDVALIDITY et UID) : un résumé d'une ligne, la catégorie, le ton, l'urgence,
 * l'action proposée, un brouillon de réponse, la demande repérée (jour, produits, personnes), et ce qui interdit d'y
 * répondre seul (prix, remise, réclamation, allergènes, commande, B2B…). Les trois modes s'en servent :
 *
 *   Briefing  les non-lus en cartes, l'action prête : valider, modifier, plus tard (et à voix haute, dans le navigateur).
 *   Pilote    l'IA répond seule aux e-mails simples, dans des garde-fous fixes ; sinon elle dépose un brouillon, ou
 *             laisse l'e-mail au magasin en disant pourquoi. Trois crans : éteint, brouillons, simples. Éteint par
 *             défaut. Chaque envoi part au plus tôt 10 minutes après la lecture, annulable jusque-là. Le pilote ne
 *             répond qu'aux e-mails arrivés après son allumage.
 *   Radar     les demandes des e-mails des trois dernières semaines, posées sur les quatorze prochains jours.
 *
 * Ce qui part au modèle : l'expéditeur, l'objet, la date et les 1 500 premiers caractères du texte (citations
 * retirées), avec le nom et l'adresse du magasin. Le modèle ne connaît ni les prix, ni les stocks, ni les horaires :
 * il ne les invente pas (« [à compléter : …] »), et un brouillon qui en contient n'est jamais envoyé seul.
 *
 *   GET  /dashboard/boite/lectures?shop=&quoi=nonlus|recents   les e-mails, leur lecture si elle existe, ceux à lire
 *   POST /dashboard/boite/lire     {shop, uids}                  les lire (8 au plus par appel)
 *   GET  /dashboard/boite/pilote?shop=                           le cran, le fil, ce qui attend, les garde-fous
 *   POST /dashboard/boite/pilote   {shop, niveau} | {shop, action: annuler|vu, id}
 *   Le pilote tourne avec l'horloge de la boîte (GET /dashboard/boite/cron, toutes les 10 minutes).
 */

const BM_LIRE_LOT = 8;              // e-mails lus par appel au modèle
const BM_PILOTE_SEUIL = 85;         // confiance minimale d'une réponse envoyée seule
const BM_PILOTE_DELAI = 10;         // minutes avant l'envoi, annulable
const BM_PILOTE_PAR_JOUR = 30;      // réponses seules au plus, par magasin et par jour
const BM_PILOTE_NIVEAUX = [0 => 'Éteint', 1 => 'Brouillons', 2 => 'Simples'];
const BM_CATEGORIES = ['commande', 'offre', 'question', 'reclamation', 'fournisseur', 'facture', 'reseau', 'administratif',
    'candidature', 'newsletter', 'notification', 'personnel', 'autre'];
/** Ce qui interdit au pilote de répondre seul, et ce qu'on en dit au magasin. */
const BM_GARDES = ['prix' => 'parle de prix', 'remise' => 'demande une remise ou un geste', 'reclamation' => 'réclamation',
    'allergenes' => 'question d’allergènes ou de santé', 'commande' => 'commande ou demande d’offre à confirmer',
    'b2b' => 'client professionnel', 'personnel' => 'personnel ou RH', 'incertain' => 'l’IA n’est pas sûre'];

function bmIaTables(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS panel_boite_lecture (shop_id VARCHAR(16) NOT NULL, boite VARCHAR(190) NOT NULL, uidvalidity BIGINT UNSIGNED NOT NULL,'
        . ' uid BIGINT UNSIGNED NOT NULL, recu_le DATETIME NULL, lecture MEDIUMTEXT NOT NULL, modele VARCHAR(60) NOT NULL DEFAULT \'\', lu_le DATETIME NOT NULL,'
        . ' PRIMARY KEY (shop_id, boite, uidvalidity, uid), KEY k_recu (shop_id, recu_le)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS panel_boite_pilote (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, shop_id VARCHAR(16) NOT NULL,'
        . ' uidvalidity BIGINT UNSIGNED NOT NULL, uid BIGINT UNSIGNED NOT NULL, de_adresse VARCHAR(190) NOT NULL, de_nom VARCHAR(190) NOT NULL DEFAULT \'\','
        . ' objet VARCHAR(255) NOT NULL DEFAULT \'\', categorie VARCHAR(20) NOT NULL DEFAULT \'\', confiance TINYINT UNSIGNED NOT NULL DEFAULT 0,'
        . ' reponse TEXT NOT NULL, etat VARCHAR(12) NOT NULL, raison VARCHAR(255) NULL, envoi_le DATETIME NULL, cree_le DATETIME NOT NULL, maj_le DATETIME NOT NULL,'
        . ' UNIQUE KEY u_mail (shop_id, uidvalidity, uid), KEY k_envoi (etat, envoi_le), KEY k_shop (shop_id, cree_le)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS panel_boite_pilote_etat (shop_id VARCHAR(16) NOT NULL PRIMARY KEY, niveau TINYINT UNSIGNED NOT NULL DEFAULT 0,'
        . ' uidvalidity BIGINT UNSIGNED NOT NULL DEFAULT 0, dernier_uid BIGINT UNSIGNED NOT NULL DEFAULT 0, maj_le DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* --- la lecture IA (la brique commune) ------------------------------------------------------------------------- */

/** La consigne de lecture : ce que fait le magasin, ce que le modèle ne sait pas, quand une réponse peut partir seule. */
function bmLectureConsigne(array $c): string
{
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    return "Tu lis la boîte e-mail de « " . $c['nom'] . " » (" . $c['adresse'] . "), un magasin de L'Atelier By, réseau belge de"
        . " boulangeries-pâtisseries en franchise. Nous sommes le " . $jours[(int) date('w')] . ' ' . date('d/m/Y') . ".\n"
        . "Pour chaque e-mail, rends une lecture :\n"
        . "- resume : une ligne (120 caractères au plus), ce que veut l'expéditeur, concret (quoi, combien, pour quand).\n"
        . "- categorie, ton (le ton de l'expéditeur), urgence (haute : à traiter aujourd'hui).\n"
        . "- action : repondre (une réponse suffit), offre (une demande de commande ou de prix : il faut une offre), ranger"
        . " (à classer dans un dossier, nommé dans « dossier »), archiver (traité ou sans suite), lire (rien à faire).\n"
        . "- reponse : le brouillon de la réponse, dans la langue de l'e-mail (français ou néerlandais), court, chaleureux, au"
        . " vouvoiement, signé « L'équipe de " . $c['nom'] . " ». Vide pour archiver, ranger et lire, et pour un robot.\n"
        . "  Tu ne connais ni les prix, ni les stocks, ni les horaires, ni les délais du magasin : n'en invente aucun. Là où il"
        . " en faut un, écris « [à compléter : …] ». Ne promets rien que l'e-mail ne permet pas de tenir.\n"
        . "- simple : vrai seulement si la réponse peut partir telle quelle sans engager le magasin (accusé d'une candidature,"
        . " d'une facture ou d'un document reçu, remerciement, réponse à une confirmation déjà donnée par le client), sans"
        . " « [à compléter » et sans garde. Dans le doute, faux.\n"
        . "- confiance : de 0 à 100, ta certitude que la lecture et la réponse sont justes.\n"
        . "- garde : ce qui interdit de répondre sans le magasin : prix, remise, reclamation, allergenes (allergènes, régime,"
        . " santé), commande (une commande ou une offre à confirmer), b2b (une entreprise, une école, une association),"
        . " personnel (RH, candidature qui demande une décision), incertain ; vide sinon.\n"
        . "- jour (AAAA-MM-JJ, le jour de la commande ou de l'événement s'il est dit, sinon vide), produits (ce qui est"
        . " demandé, avec les quantités dites), personnes (0 si non dit), b2b.\n"
        . "Ne lis que ce qui est écrit. Réponds pour chaque repère donné, une fois.";
}

function bmLectureSchema(): array
{
    $ch = ['type' => 'string'];
    return ['type' => 'object', 'additionalProperties' => false, 'required' => ['lectures'], 'properties' => [
        'lectures' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['repere', 'resume', 'categorie', 'ton', 'urgence', 'action', 'reponse', 'dossier', 'simple', 'confiance', 'garde', 'jour', 'produits', 'personnes', 'b2b'],
            'properties' => [
                'repere' => $ch, 'resume' => $ch,
                'categorie' => ['type' => 'string', 'enum' => BM_CATEGORIES],
                'ton' => ['type' => 'string', 'enum' => ['chaleureux', 'neutre', 'mecontent']],
                'urgence' => ['type' => 'string', 'enum' => ['haute', 'normale', 'basse']],
                'action' => ['type' => 'string', 'enum' => ['repondre', 'offre', 'ranger', 'archiver', 'lire']],
                'reponse' => $ch, 'dossier' => $ch, 'simple' => ['type' => 'boolean'], 'confiance' => ['type' => 'integer'],
                'garde' => ['type' => 'string', 'enum' => array_merge([''], array_keys(BM_GARDES))],
                'jour' => $ch,
                'produits' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['nom', 'quantite'],
                    'properties' => ['nom' => $ch, 'quantite' => ['type' => 'integer']]]],
                'personnes' => ['type' => 'integer'], 'b2b' => ['type' => 'boolean'],
            ]]]]];
}

/** Une lecture rendue par le modèle, remise d'aplomb (bornes, formats) : jamais « simple » avec un trou ou une garde. */
function bmLectureNette(array $x, array $e): array
{
    $reponse = trim(mb_substr((string) ($x['reponse'] ?? ''), 0, 4000));
    $garde = array_key_exists((string) ($x['garde'] ?? ''), BM_GARDES) ? (string) $x['garde'] : '';
    $jour = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($x['jour'] ?? '')) ? (string) $x['jour'] : '';
    $produits = [];
    foreach (array_slice((array) ($x['produits'] ?? []), 0, 12) as $p) {
        $n = trim(mb_substr((string) ($p['nom'] ?? ''), 0, 80));
        if ($n !== '') { $produits[] = ['nom' => $n, 'quantite' => max(0, min(5000, (int) ($p['quantite'] ?? 0)))]; }
    }
    $simple = !empty($x['simple']) && $garde === '' && $reponse !== '' && !str_contains($reponse, '[') && !$e['robot'];
    return ['resume' => trim(mb_substr((string) ($x['resume'] ?? ''), 0, 160)),
        'categorie' => in_array($x['categorie'] ?? '', BM_CATEGORIES, true) ? $x['categorie'] : 'autre',
        'ton' => in_array($x['ton'] ?? '', ['chaleureux', 'neutre', 'mecontent'], true) ? $x['ton'] : 'neutre',
        'urgence' => in_array($x['urgence'] ?? '', ['haute', 'normale', 'basse'], true) ? $x['urgence'] : 'normale',
        'action' => in_array($x['action'] ?? '', ['repondre', 'offre', 'ranger', 'archiver', 'lire'], true) ? $x['action'] : 'lire',
        'reponse' => $e['robot'] ? '' : $reponse, 'dossier' => trim(mb_substr((string) ($x['dossier'] ?? ''), 0, 40)),
        'simple' => $simple, 'confiance' => max(0, min(100, (int) ($x['confiance'] ?? 0))), 'garde' => $garde, 'jour' => $jour,
        'produits' => $produits, 'personnes' => max(0, min(10000, (int) ($x['personnes'] ?? 0))), 'b2b' => !empty($x['b2b']), 'robot' => $e['robot']];
}

/**
 * Les lectures des e-mails $uids de la réception (ouverte) : celles qui sont gardées, et — $lire — celles qui manquent,
 * demandées au modèle par lots (BM_LIRE_LOT). Rend [uid => lecture].
 */
function bmLectures(string $shop, array $c, BmImap $s, array $uids, bool $lire): array
{
    $val = (int) ($s->infos['uidvalidity'] ?? 0);
    $out = [];
    if (!$uids) { return $out; }
    $ph = implode(',', array_fill(0, count($uids), '?'));
    foreach (Db::rows('SELECT uid, lecture FROM panel_boite_lecture WHERE shop_id = ? AND boite = ? AND uidvalidity = ? AND uid IN (' . $ph . ')',
        array_merge([$shop, $c['adresse'], $val], array_map('intval', $uids))) as $r) {
        $j = json_decode((string) $r['lecture'], true);
        if (is_array($j)) { $out[(int) $r['uid']] = $j; }
    }
    $manquent = array_values(array_filter($uids, static fn ($u) => !isset($out[$u])));
    if (!$lire || !$manquent) { return $out; }
    if (!class_exists('Anthropic') || !Anthropic::configured()) { throw new RuntimeException('l’assistance IA n’est pas branchée : la clé Anthropic manque (Paramètres › Assistance IA)'); }
    foreach (array_chunk($manquent, BM_LIRE_LOT) as $lot) {
        $emails = bmApercus($s, $lot, 1500);
        if (!$emails) { continue; }
        $reperes = []; $lignes = [];
        foreach (array_values($emails) as $i => $e) {
            $emails[$e['uid']]['robot'] = bmRobot($e['entetes'], $e['repondreA'], $c);
            $reperes['E' . ($i + 1)] = $e['uid'];
            $lignes[] = '[E' . ($i + 1) . '] De : ' . ($e['de'] ? trim(($e['de']['nom'] ?? '') . ' <' . $e['de']['adresse'] . '>') : '(inconnu)')
                . ' · Objet : ' . ($e['objet'] !== '' ? $e['objet'] : '(sans objet)') . ($e['date'] ? ' · Reçu le ' . date('d/m/Y H:i', strtotime($e['date'])) : '')
                . ($e['pj'] ? ' · pièce jointe' : '') . ($emails[$e['uid']]['robot'] ? ' · envoi automatique (ne pas répondre)' : '')
                . ($e['repondu'] ? ' · déjà répondu' : '') . "\n     Texte : « " . ($e['debut'] !== '' ? $e['debut'] : '(vide)') . ' »';
        }
        $r = Anthropic::json(bmLectureConsigne($c), "E-mails :\n" . implode("\n", $lignes), bmLectureSchema(), 12000, null, 140);
        if ($r['erreur'] !== null) { throw new RuntimeException('l’IA ne lit pas ces e-mails : ' . $r['erreur']); }
        foreach ((array) ($r['json']['lectures'] ?? []) as $x) {
            $uid = $reperes[strtoupper(trim((string) ($x['repere'] ?? '')))] ?? null;
            if ($uid === null || !isset($emails[$uid])) { continue; }
            $l = bmLectureNette((array) $x, $emails[$uid]);
            Db::exec('INSERT INTO panel_boite_lecture (shop_id, boite, uidvalidity, uid, recu_le, lecture, modele, lu_le) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
                . ' ON DUPLICATE KEY UPDATE lecture = VALUES(lecture), modele = VALUES(modele), lu_le = NOW()',
                [$shop, $c['adresse'], $val, $uid, $emails[$uid]['date'] ? date('Y-m-d H:i:s', strtotime($emails[$uid]['date'])) : null,
                    json_encode($l, JSON_UNESCAPED_UNICODE), mb_substr((string) $r['modele'], 0, 60)]);
            $out[$uid] = $l;
        }
    }
    return $out;
}

/** GET /lectures : les non-lus (briefing, 20 au plus) ou les trois dernières semaines (radar, 80 au plus), avec leur lecture. */
function bmLecturesRoute(string $shop, array $c): array
{
    $s = bmImap($c);
    $s->ouvrirDossier('INBOX');
    $quoi = (string) ($_GET['quoi'] ?? 'nonlus');
    if ($quoi === 'recents') {
        $uids = array_slice(array_reverse($s->chercher(['SINCE ' . date('d-M-Y', strtotime('-21 days'))])), 0, 80);
    } else {
        $uids = array_slice(array_reverse($s->chercher(['UNSEEN'])), 0, 20);
    }
    $lectures = bmLectures($shop, $c, $s, $uids, false);
    $lus = $uids ? $s->lire($uids, 'UID FLAGS INTERNALDATE BODY.PEEK[HEADER.FIELDS (FROM REPLY-TO SUBJECT DATE)]') : [];
    $elements = [];
    foreach ($uids as $u) {
        $m = $lus[$u] ?? null;
        if ($m === null) { continue; }
        [$e] = bmMimeCouper((string) ($m['BODY[HEADER]'] ?? ''));
        $fl = array_map('strtolower', array_map('strval', (array) ($m['FLAGS'] ?? [])));
        $de = bmAdresses($e['from'] ?? '')[0] ?? null;
        $elements[] = ['uid' => $u, 'de' => $de, 'repondreA' => bmAdresses($e['reply-to'] ?? '')[0] ?? $de, 'objet' => bmEntete($e['subject'] ?? ''),
            'date' => bmDate($e['date'] ?? null, isset($m['INTERNALDATE']) ? (string) $m['INTERNALDATE'] : null),
            'lu' => in_array('\\seen', $fl, true), 'repondu' => in_array('\\answered', $fl, true), 'suivi' => in_array('\\flagged', $fl, true),
            'lecture' => $lectures[$u] ?? null];
    }
    return ['quoi' => $quoi, 'elements' => $elements, 'aLire' => array_values(array_filter($uids, static fn ($u) => !isset($lectures[$u]))),
        'ia' => class_exists('Anthropic') && Anthropic::configured(), 'lot' => BM_LIRE_LOT];
}

/** POST /lire : lire (au modèle) jusqu'à BM_LIRE_LOT e-mails de la réception. */
function bmLireRoute(string $shop, array $c, array $b): array
{
    $uids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($b['uids'] ?? [])), static fn ($u) => $u > 0))), 0, BM_LIRE_LOT);
    if (!$uids) { return bmErreur(422, 'Aucun e-mail désigné.'); }
    @set_time_limit(150);
    $s = bmImap($c);
    $s->ouvrirDossier('INBOX');
    try { $l = bmLectures($shop, $c, $s, $uids, true); }
    catch (RuntimeException $e) { return bmErreur(502, ucfirst($e->getMessage()) . '.'); }
    return ['lectures' => (object) $l];
}

/* --- le pilote automatique ------------------------------------------------------------------------------------- */

function bmPiloteNiveau(string $shop): int
{
    $r = Db::row('SELECT niveau FROM panel_boite_pilote_etat WHERE shop_id = ?', [$shop]);
    return $r === null ? 0 : (int) $r['niveau'];
}

/** GET /pilote : le cran, le fil des 7 derniers jours, ce qui attend le magasin, les garde-fous. */
function bmPiloteLire(string $shop): array
{
    // Le fil : ce que le pilote a fait seul (envois, annulations) ; ce qui attend : ce qu'il a laissé au magasin, avec ou
    // sans brouillon déposé, jusqu'à « C'est traité ».
    $fil = Db::rows('SELECT id, uid, de_adresse, de_nom, objet, categorie, confiance, reponse, etat, raison, envoi_le, cree_le FROM panel_boite_pilote'
        . ' WHERE shop_id = ? AND cree_le > ? AND etat NOT IN (?, ?) ORDER BY cree_le DESC, id DESC LIMIT 60', [$shop, date('Y-m-d H:i:s', strtotime('-7 days')), 'attente', 'brouillon']);
    $attente = Db::rows('SELECT id, uid, de_adresse, de_nom, objet, categorie, confiance, reponse, etat, raison, cree_le FROM panel_boite_pilote'
        . ' WHERE shop_id = ? AND etat IN (?, ?) ORDER BY cree_le DESC LIMIT 30', [$shop, 'attente', 'brouillon']);
    $n = static fn (string $etat) => (int) (Db::row('SELECT COUNT(*) n FROM panel_boite_pilote WHERE shop_id = ? AND etat = ? AND cree_le > ?',
        [$shop, $etat, date('Y-m-d H:i:s', strtotime('-7 days'))])['n'] ?? 0);
    $h = setting('boiteHorloge', null);
    $conv = static function (array $r): array {
        foreach (['id', 'uid', 'confiance'] as $k) { $r[$k] = (int) $r[$k]; }
        if (!empty($r['envoi_le'])) { $r['envoi_le'] = date('c', strtotime((string) $r['envoi_le'])); }
        $r['cree_le'] = date('c', strtotime((string) $r['cree_le']));
        return $r;
    };
    return ['niveau' => bmPiloteNiveau($shop), 'niveaux' => BM_PILOTE_NIVEAUX, 'seuil' => BM_PILOTE_SEUIL, 'delai' => BM_PILOTE_DELAI,
        'horloge' => is_array($h) ? ($h['le'] ?? null) : null, 'ia' => class_exists('Anthropic') && Anthropic::configured(),
        'fil' => array_map($conv, $fil), 'attente' => array_map($conv, $attente),
        'stats' => ['envoyes' => $n('envoye'), 'brouillons' => (int) (Db::row('SELECT COUNT(*) n FROM panel_boite_pilote WHERE shop_id = ? AND raison LIKE ? AND cree_le > ?',
            [$shop, '%brouillon déposé%', date('Y-m-d H:i:s', strtotime('-7 days'))])['n'] ?? 0), 'annules' => $n('annule'), 'planifies' => $n('planifie'), 'attente' => count($attente)],
        'gardes' => array_values(BM_GARDES)];
}

/** POST /pilote : changer le cran ; annuler un envoi planifié ; marquer « vu » ce qui attendait. */
function bmPiloteEcrire(string $shop, array $c, array $b): array
{
    $a = (string) ($b['action'] ?? '');
    if ($a === 'annuler' || $a === 'vu') {
        $id = (int) ($b['id'] ?? 0);
        $n = $a === 'annuler'
            ? Db::exec('UPDATE panel_boite_pilote SET etat = ?, maj_le = NOW(), raison = ? WHERE id = ? AND shop_id = ? AND etat = ?', ['annule', 'annulé par le magasin', $id, $shop, 'planifie'])
            : Db::exec('UPDATE panel_boite_pilote SET etat = ?, maj_le = NOW() WHERE id = ? AND shop_id = ? AND etat IN (?, ?)', ['vu', $id, $shop, 'attente', 'brouillon']);
        if ($a === 'annuler' && !$n) { return bmErreur(409, 'Trop tard : cette réponse est déjà partie, ou n’était plus prévue.'); }
        return ['ok' => true] + bmPiloteLire($shop);
    }
    if (!array_key_exists('niveau', $b)) { return bmErreur(422, 'Le cran du pilote est attendu.'); }
    $niv = (int) $b['niveau'];
    if (!isset(BM_PILOTE_NIVEAUX[$niv])) { return bmErreur(422, 'Ce cran n’existe pas.'); }
    if ($niv > 0 && (!class_exists('Anthropic') || !Anthropic::configured())) { return bmErreur(503, 'L’assistance IA n’est pas branchée : la clé Anthropic manque.'); }
    $avant = bmPiloteNiveau($shop);
    // Allumé (ou rallumé) : le pilote part des e-mails qui arrivent maintenant, jamais de l'arriéré. (MySQL évalue les
    // affectations de gauche à droite : uidvalidity se lit avec l'ANCIEN cran.)
    Db::exec('INSERT INTO panel_boite_pilote_etat (shop_id, niveau, uidvalidity, dernier_uid, maj_le) VALUES (?, ?, 0, 0, NOW())'
        . ' ON DUPLICATE KEY UPDATE uidvalidity = IF(VALUES(niveau) > 0 AND niveau = 0, 0, uidvalidity), niveau = VALUES(niveau), maj_le = NOW()', [$shop, $niv]);
    if ($niv === 0) { Db::exec('UPDATE panel_boite_pilote SET etat = ?, raison = ?, maj_le = NOW() WHERE shop_id = ? AND etat = ?', ['annule', 'pilote éteint', $shop, 'planifie']); }
    if ($avant !== $niv) { journalAdd('Magasin ' . $shop, 'Boîte mail', null, 'Pilote automatique : ' . BM_PILOTE_NIVEAUX[$avant] . ' → ' . BM_PILOTE_NIVEAUX[$niv]); }
    return ['ok' => true] + bmPiloteLire($shop);
}

/**
 * Un passage du pilote pour un magasin (l'horloge, toutes les 10 minutes) : lire les e-mails arrivés depuis le dernier
 * passage, décider pour chacun (envoi planifié, brouillon, ou au magasin), puis envoyer ce qui est dû.
 */
function bmPiloteTourner(string $shop, array $c): array
{
    $etat = Db::row('SELECT * FROM panel_boite_pilote_etat WHERE shop_id = ?', [$shop]);
    $niv = (int) ($etat['niveau'] ?? 0);
    if ($niv <= 0) { return ['éteint' => true]; }
    $s = bmImap($c);
    $s->ouvrirDossier('INBOX');
    $val = (int) ($s->infos['uidvalidity'] ?? 0); $suivant = (int) ($s->infos['uidnext'] ?? 0);
    $r = ['planifies' => 0, 'brouillons' => 0, 'attente' => 0, 'envoyes' => 0];
    if ((int) $etat['uidvalidity'] !== $val || $suivant === 0) {
        $dernier = max(0, $suivant - 1);
        if ($suivant === 0) { $t = $s->chercher(['ALL']); $dernier = $t ? max($t) : 0; }
        Db::exec('UPDATE panel_boite_pilote_etat SET uidvalidity = ?, dernier_uid = ?, maj_le = NOW() WHERE shop_id = ?', [$val, $dernier, $shop]);
        Db::exec('UPDATE panel_boite_pilote SET etat = ?, raison = ?, maj_le = NOW() WHERE shop_id = ? AND etat = ? AND uidvalidity <> ?', ['annule', 'boîte réindexée', $shop, 'planifie', $val]);
        return ['départ' => $dernier] + bmPiloteEnvoyer($shop, $c, $s, $val, $r);
    }
    $dernier = (int) $etat['dernier_uid'];
    // Un lot par passage (un appel au modèle) : l'horloge tient dans ses 280 secondes ; le reste, au passage suivant.
    $nouveaux = array_slice(array_values(array_filter($s->chercher(['UID ' . ($dernier + 1) . ':*']), static fn ($u) => $u > $dernier)), 0, BM_LIRE_LOT);
    if ($nouveaux) {
        $lectures = bmLectures($shop, $c, $s, $nouveaux, true);
        $aujourdhui = (int) (Db::row('SELECT COUNT(*) n FROM panel_boite_pilote WHERE shop_id = ? AND etat IN (?, ?) AND cree_le > ?', [$shop, 'planifie', 'envoye', date('Y-m-d 00:00:00')])['n'] ?? 0);
        $tetes = $s->lire($nouveaux, 'UID BODY.PEEK[HEADER.FIELDS (' . BM_ENTETES_ROBOT . ')]');
        foreach ($nouveaux as $uid) {
            [$e] = bmMimeCouper((string) ($tetes[$uid]['BODY[HEADER]'] ?? ''));
            $de = bmAdresses($e['reply-to'] ?? '')[0] ?? (bmAdresses($e['from'] ?? '')[0] ?? null);
            $l = $lectures[$uid] ?? null;
            $objet = bmEntete($e['subject'] ?? '');
            if ($l !== null && !bmRobot($e, $de, $c) && !in_array($l['categorie'], ['newsletter', 'notification'], true)) {
                $recent = Db::row('SELECT id FROM panel_boite_pilote WHERE shop_id = ? AND de_adresse = ? AND etat IN (?, ?) AND cree_le > ?',
                    [$shop, $de['adresse'], 'planifie', 'envoye', date('Y-m-d H:i:s', strtotime('-1 day'))]);
                $seul = $niv >= 2 && $l['simple'] && $l['confiance'] >= BM_PILOTE_SEUIL && $l['action'] === 'repondre' && $recent === null && $aujourdhui < BM_PILOTE_PAR_JOUR;
                $raison = $l['garde'] !== '' ? BM_GARDES[$l['garde']] : ($l['reponse'] === '' ? 'rien à répondre seul' : (str_contains($l['reponse'], '[') ? 'il manque une information (à compléter)'
                    : ($l['confiance'] < BM_PILOTE_SEUIL ? 'pas assez sûre (' . $l['confiance'] . ' %)' : ($recent !== null ? 'déjà répondu à cet expéditeur aujourd’hui' : ($niv < 2 ? 'cran « Brouillons »' : 'pas une question simple')))));
                $etatL = 'attente'; $envoi = null;
                if ($seul) { $etatL = 'planifie'; $envoi = date('Y-m-d H:i:s', time() + BM_PILOTE_DELAI * 60); $aujourdhui++; $raison = null; $r['planifies']++; }
                elseif ($l['reponse'] !== '') {
                    // Un brouillon dans « Brouillons », en réponse à l'e-mail : le magasin le complète et l'envoie de sa messagerie.
                    try {
                        $ref = [trim((string) ($e['message-id'] ?? '')), trim((string) ($e['references'] ?? ''))];
                        [$msg] = bmComposer(['nom' => $c['nom'], 'adresse' => $c['adresse']], [$de], [], 'Re: ' . preg_replace('/^\s*(re|tr|fw|fwd)\s*:\s*/i', '', $objet), $l['reponse'], [], $ref);
                        $br = bmDossierRole($s, 'drafts', true);
                        if ($br !== null && $s->deposer($br, $msg, '\\Draft \\Seen')) { $etatL = 'brouillon'; $r['brouillons']++; $raison .= ' · brouillon déposé'; }
                        $s->ouvrirDossier('INBOX');
                    } catch (Throwable $x) { $raison .= ' · brouillon non déposé : ' . $x->getMessage(); }
                }
                if ($etatL === 'attente') { $r['attente']++; }
                Db::exec('INSERT IGNORE INTO panel_boite_pilote (shop_id, uidvalidity, uid, de_adresse, de_nom, objet, categorie, confiance, reponse, etat, raison, envoi_le, cree_le, maj_le)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [$shop, $val, $uid, $de['adresse'], mb_substr((string) $de['nom'], 0, 190), mb_substr($objet, 0, 255), $l['categorie'], $l['confiance'], $l['reponse'], $etatL,
                        $raison !== null ? mb_substr($raison, 0, 255) : null, $envoi]);
            }
            Db::exec('UPDATE panel_boite_pilote_etat SET dernier_uid = ?, maj_le = NOW() WHERE shop_id = ?', [$uid, $shop]);
        }
    }
    return bmPiloteEnvoyer($shop, $c, $s, $val, $r);
}

/** Les réponses planifiées dont l'heure est passée : envoyées, sauf si le magasin a répondu entre-temps ou si l'e-mail a quitté la réception. */
function bmPiloteEnvoyer(string $shop, array $c, BmImap $s, int $val, array $r): array
{
    foreach (Db::rows('SELECT * FROM panel_boite_pilote WHERE shop_id = ? AND etat = ? AND envoi_le <= NOW() ORDER BY envoi_le LIMIT 10', [$shop, 'planifie']) as $p) {
        $uid = (int) $p['uid'];
        $s->ouvrirDossier('INBOX');
        $f = (int) $p['uidvalidity'] === $val ? $s->lire([$uid], 'UID FLAGS') : [];
        $fl = array_map('strtolower', array_map('strval', (array) ($f[$uid]['FLAGS'] ?? [])));
        $pourquoi = !isset($f[$uid]) ? 'l’e-mail n’est plus dans la réception' : (in_array('\\answered', $fl, true) ? 'le magasin a déjà répondu' : null);
        if ($pourquoi !== null) { Db::exec('UPDATE panel_boite_pilote SET etat = ?, raison = ?, maj_le = NOW() WHERE id = ?', ['annule', $pourquoi, (int) $p['id']]); continue; }
        // La ligne passe d'abord à « envoi » : deux horloges simultanées n'envoient pas deux fois.
        if (!Db::exec('UPDATE panel_boite_pilote SET etat = ?, maj_le = NOW() WHERE id = ? AND etat = ?', ['envoi', (int) $p['id'], 'planifie'])) { continue; }
        try {
            [, $avert] = bmEnvoyer($shop, $c, [['nom' => (string) $p['de_nom'], 'adresse' => (string) $p['de_adresse']]], [],
                'Re: ' . preg_replace('/^\s*(re|tr|fw|fwd)\s*:\s*/i', '', (string) $p['objet']), (string) $p['reponse'], ['reponse' => ['dossier' => 'INBOX', 'uid' => $uid]]);
            Db::exec('UPDATE panel_boite_pilote SET etat = ?, raison = ?, envoi_le = NOW(), maj_le = NOW() WHERE id = ?', ['envoye', $avert, (int) $p['id']]);
            $r['envoyes']++;
        } catch (Throwable $e) {
            Db::exec('UPDATE panel_boite_pilote SET etat = ?, raison = ?, maj_le = NOW() WHERE id = ?', ['echec', mb_substr($e->getMessage(), 0, 255), (int) $p['id']]);
        }
    }
    return $r;
}
