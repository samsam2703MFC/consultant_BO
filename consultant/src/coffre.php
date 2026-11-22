<?php
declare(strict_types=1);

/*
 * LE COFFRE-FORT DU FRANCHISÉ (22/11/2026, Sam : « chaque franchisé a un coffre-fort avec ses documents : DIP, contrat,
 * légal, formation initiale et continue, rapports de visite ; un tableau à onglets pour n'avoir qu'une page ; dans son
 * dashboard, un lien direct, tout en dessous »). Plan et maquettes : consultant/docs/maquettes/coffre-fort/.
 *
 * Décisions de Sam (22/11/2026) : le DIP, le contrat et les autres pièces du réseau sont DÉPOSÉS par le consultant
 * (onglet Documents de la fiche franchisé) ; pas de connexion imposée — le coffre suit le réglage d'accès du cockpit,
 * comme le reste du dashboard ; le franchisé dépose lui-même ses pièces légales, le consultant aussi ; UN COFFRE PAR
 * BOUTIQUE ; pas d'envoi au comptable, mais un document du coffre se joint à un mail de la Boîte mail du magasin.
 *
 * Le coffre RASSEMBLE, il ne recopie rien :
 *   - les documents de `con_doc` (src/documents.php) mis « dans le coffre du franchisé » (visible_franchise = 1 ;
 *     les documents déposés avant le coffre n'y entrent que si le consultant les y met) ;
 *   - les rapports de visite envoyés (`con_visite`, rapport_envoye_le) : ils se lisent dans « Mon suivi » (src/suivi.php),
 *     qui en reste la source et marque le rapport lu.
 * Les fichiers restent sous uploads/documents/{magasin}/ (accès direct fermé) et ne sortent que par l'API.
 *
 *   con_coffre_acces   chaque ouverture, téléchargement ou pièce jointe d'un mail : document, magasin, qui, rôle,
 *                      comment, quand (« Qui l'a ouvert », « nouveau » tant que le magasin ne l'a pas ouvert)
 *
 * Routes (le préfixe /coffre/magasin est un écran de magasin : ACCES_FRANCHISE, le `shop` vérifié contre la session) :
 *   GET  /coffre/magasin?shop=                                 les onglets, les documents, les rapports, les repères
 *   GET  /coffre/magasin/fichier?shop=&id=[&telecharger=1]     le fichier, s'il est de ce magasin et dans le coffre
 *   POST /coffre/magasin/document (multipart : shop, titre?, date?, echeance?, note?, fichiers[]) : une pièce légale
 *        déposée par le magasin
 * La Boîte mail joint un document du coffre par cofPiecesMail (panel/src/boite_mail.php, champ `coffre` de l'envoi).
 */

/** Les onglets du coffre, dans leur ordre (« Tout » est calculé). */
const COF_ONGLETS = ['contrat' => 'Contrat & DIP', 'legal' => 'Légal', 'formation' => 'Formation', 'visites' => 'Rapports de visite',
    'finances' => 'Finances', 'courriers' => 'Courriers & réunions'];
/** L'onglet de chaque sorte de `con_doc` (DOC_SORTES) ; une sorte inconnue va dans Courriers & réunions. */
const COF_SORTE_ONGLET = ['dip' => 'contrat', 'contrat_franchise' => 'contrat', 'avenant' => 'contrat', 'implantation' => 'contrat',
    'contrat' => 'contrat', 'legal' => 'legal', 'formation' => 'formation', 'finances' => 'finances',
    'reunion' => 'courriers', 'courrier' => 'courriers', 'autre' => 'courriers'];
const COF_RENOUVELER_J = 60;   // une pièce passe « à renouveler » 60 jours avant son échéance
const COF_MAIL_MAX = 5;        // documents du coffre joints à un même mail

function cofTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    docTable();
    Db::exec('CREATE TABLE IF NOT EXISTS con_coffre_acces ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'doc_id INT NOT NULL,'
        . 'shop INT NOT NULL,'
        . "qui VARCHAR(120) NOT NULL DEFAULT '',"
        . "role VARCHAR(12) NOT NULL DEFAULT '',"          // franchise | siege
        . "comment VARCHAR(12) NOT NULL DEFAULT 'ouvert',"  // ouvert | telecharge | mail
        . 'le DATETIME NOT NULL,'
        . 'KEY idx_cof_doc (doc_id, le),'
        . 'KEY idx_cof_shop (shop, le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Qui appelle : {nom, role}. Le rôle vient de la session (accesIdentite) ; sans session (accès ouvert), celui de la
 * route ($defaut : « franchise » pour les routes du magasin), et le nom du magasin pour un magasin.
 */
function cofQui(string $defaut, int $shop = 0): array
{
    $id = function_exists('accesIdentite') ? accesIdentite() : null;
    if (is_array($id)) { return ['nom' => mb_substr((string) ($id['nom'] ?? ''), 0, 120) ?: ($id['role'] === 'siege' ? 'Siège' : 'Magasin'), 'role' => (string) $id['role']]; }
    if ($defaut === 'franchise') { return ['nom' => $shop > 0 && function_exists('docMagasinNom') ? docMagasinNom($shop) : 'Magasin', 'role' => 'franchise']; }
    return ['nom' => 'Siège', 'role' => 'siege'];
}

/**
 * Ce magasin peut-il être lu ou écrit par cet appel ? Le garde de l'API (accesRefus) vérifie déjà le `shop` de
 * l'adresse et du corps JSON ; un envoi multipart (le dépôt) ne passe pas par lui : on refait ici le même contrôle.
 * Accès ouvert : oui (décision 2 de Sam). Siège : oui. Magasin : seulement les siens.
 */
function cofShopOk(int $shop): bool
{
    if ($shop <= 0) { return false; }
    if (function_exists('accesMode') && accesMode() === 'ouvert') { return true; }
    $id = function_exists('accesIdentite') ? accesIdentite() : null;
    if (!is_array($id)) { return false; }
    return $id['role'] === 'siege' || in_array((string) $shop, array_map('strval', (array) ($id['shops'] ?? [])), true);
}

/** Note une ouverture (ou un téléchargement, une pièce jointe). Une trace qui ne s'écrit pas n'empêche pas la lecture. */
function cofNoter(int $docId, int $shop, string $comment, array $qui): void
{
    try {
        cofTable();
        Db::exec('INSERT INTO con_coffre_acces (doc_id, shop, qui, role, comment, le) VALUES (?,?,?,?,?,?)',
            [$docId, $shop, $qui['nom'], $qui['role'], $comment, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* la trace est un plus */ }
}

/** [doc_id => {n, dernier}] : ce que le MAGASIN a ouvert de son coffre (lu par l'onglet Documents du cockpit). */
function cofOuverturesMagasin(int $shop): array
{
    try {
        cofTable();
        $out = [];
        foreach (Db::rows("SELECT doc_id, COUNT(*) AS n, MAX(le) AS dernier FROM con_coffre_acces WHERE shop = ? AND role = 'franchise' GROUP BY doc_id", [$shop]) as $r) {
            $out[(int) $r['doc_id']] = ['n' => (int) $r['n'], 'dernier' => substr((string) $r['dernier'], 0, 16)];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * L'état d'un document du coffre, d'après son échéance : expiree (passée), renouveler (dans COF_RENOUVELER_J jours),
 * valide (plus loin), depose (sans échéance). Rend {cle, texte, jours}.
 */
function cofEtat(?string $echeance, string $auj): array
{
    if ($echeance === null || $echeance === '') { return ['cle' => 'depose', 'texte' => 'déposé', 'jours' => null]; }
    $j = (int) floor((strtotime($echeance) - strtotime($auj)) / 86400);
    if ($j < 0) { return ['cle' => 'expiree', 'texte' => 'échue depuis ' . (-$j) . ' j', 'jours' => $j]; }
    if ($j <= COF_RENOUVELER_J) { return ['cle' => 'renouveler', 'texte' => 'à renouveler · ' . $j . ' j', 'jours' => $j]; }
    return ['cle' => 'valide', 'texte' => 'valide', 'jours' => $j];
}

/** Une ligne de `con_doc` telle que le coffre la montre, avec ses dernières traces et « nouveau ». */
function cofLigne(array $r, array $traces, bool $nouveau, string $auj): array
{
    $d = docLigne($r);
    return ['id' => $d['id'], 'onglet' => COF_SORTE_ONGLET[$d['sorte']] ?? 'courriers', 'sorte' => $d['sorte'], 'sorteLib' => $d['sorteLib'],
        'titre' => $d['titre'], 'note' => $d['note'], 'date' => $d['date'] ?? substr($d['deposeLe'], 0, 10), 'echeance' => $d['echeance'],
        'etat' => cofEtat($d['echeance'], $auj), 'deposePar' => $d['deposePar'] !== '' ? $d['deposePar'] : 'le siège', 'deposeLe' => $d['deposeLe'],
        'deposeRole' => $d['deposeRole'] !== '' ? $d['deposeRole'] : 'siege', 'nom' => $d['nom'], 'mime' => $d['mime'], 'octets' => $d['octets'],
        'nouveau' => $nouveau, 'traces' => $traces];
}

/** GET /coffre/magasin?shop= — le coffre d'une boutique : ses documents, ses rapports de visite, les repères du haut. */
function ep_coffre_magasin(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if (!cofShopOk($shop)) { http_response_code($shop > 0 ? 403 : 400); return ['error' => $shop > 0 ? 'ce magasin n’est pas sur votre session' : 'shop requis']; }
    cofTable();
    $auj = date('Y-m-d');
    $rows = Db::rows('SELECT * FROM con_doc WHERE shop = ? AND visible_franchise = 1 ORDER BY COALESCE(date_doc, DATE(depose_le)) DESC, id DESC', [$shop]);
    // Les cinq dernières traces de chaque document ; « nouveau » tant que le MAGASIN ne l'a pas ouvert.
    $traces = []; $vu = [];
    if ($rows !== []) {
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        foreach (Db::rows('SELECT doc_id, qui, role, comment, le FROM con_coffre_acces WHERE doc_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY le DESC, id DESC', $ids) as $t) {
            $k = (int) $t['doc_id'];
            if ($t['role'] === 'franchise') { $vu[$k] = true; }
            if (count($traces[$k] ?? []) < 5) { $traces[$k][] = ['qui' => (string) $t['qui'], 'role' => (string) $t['role'], 'comment' => (string) $t['comment'], 'le' => substr((string) $t['le'], 0, 16)]; }
        }
    }
    $docs = array_map(static fn ($r) => cofLigne($r, $traces[(int) $r['id']] ?? [], !isset($vu[(int) $r['id']]), $auj), $rows);
    $visites = cofVisites($shop);
    $n = array_fill_keys(array_keys(COF_ONGLETS), 0);
    foreach ($docs as $d) { $n[$d['onglet']]++; }
    $n['visites'] += count($visites);
    $onglets = [['cle' => 'tout', 'nom' => 'Tout', 'n' => count($docs) + count($visites)]];
    foreach (COF_ONGLETS as $k => $nom) { $onglets[] = ['cle' => $k, 'nom' => $nom, 'n' => $n[$k]]; }
    $contrat = null;
    foreach ($docs as $d) { if ($d['sorte'] === 'contrat_franchise') { $contrat = ['titre' => $d['titre'], 'echeance' => $d['echeance'], 'date' => $d['date']]; break; } }
    return ['shop' => (string) $shop, 'nom' => docMagasinNom($shop), 'onglets' => $onglets, 'documents' => $docs, 'visites' => $visites,
        'reperes' => ['nouveaux' => count(array_filter($docs, static fn ($d) => $d['nouveau'])),
            'aRenouveler' => array_values(array_map(static fn ($d) => ['id' => $d['id'], 'titre' => $d['titre'], 'echeance' => $d['echeance'], 'etat' => $d['etat']],
                array_filter($docs, static fn ($d) => in_array($d['etat']['cle'], ['renouveler', 'expiree'], true)))),
            'contrat' => $contrat],
        'suivi' => function_exists('suLien') ? suLien((string) $shop) : null,
        'depot' => ['sorte' => 'legal', 'maxOctets' => docMaxOctets(), 'extensions' => array_keys(DOC_EXT)]];
}

/** Les rapports de visite ENVOYÉS d'une boutique, le plus récent d'abord (ce que le franchisé a reçu). */
function cofVisites(int $shop): array
{
    try {
        $out = [];
        foreach (Db::rows('SELECT id, prevu_le, type_code, consultant_nom, rapport_envoye_le, rapport_envoye_par, rapport_lu_le FROM con_visite
                           WHERE shop_id = ? AND rapport_envoye_le IS NOT NULL ORDER BY rapport_envoye_le DESC, id DESC LIMIT 200', [(string) $shop]) as $v) {
            $type = (string) (($v['type_code'] ?? '') ?: 'reguliere');
            $out[] = ['id' => (int) $v['id'], 'onglet' => 'visites', 'le' => (string) $v['prevu_le'],
                'type' => function_exists('suTypeNom') ? suTypeNom($type, 'fr') : $type, 'typeCode' => $type,
                'consultant' => (string) ($v['consultant_nom'] ?? ''), 'envoyeLe' => substr((string) $v['rapport_envoye_le'], 0, 16),
                'envoyePar' => (string) ($v['rapport_envoye_par'] ?? ''), 'luLe' => !empty($v['rapport_lu_le']) ? substr((string) $v['rapport_lu_le'], 0, 16) : null];
        }
        return $out;
    } catch (Throwable $e) { return []; }   // le module des visites n'a pas encore ses tables
}

/** La ligne d'un document, s'il est de CETTE boutique et dans son coffre ; null sinon (même réponse dans les deux cas). */
function cofDocDuCoffre(int $shop, int $id): ?array
{
    if ($shop <= 0 || $id <= 0) { return null; }
    cofTable();
    return Db::row('SELECT * FROM con_doc WHERE id = ? AND shop = ? AND visible_franchise = 1', [$id, $shop]);
}

/** GET /coffre/magasin/fichier?shop=&id=[&telecharger=1] — le fichier, s'il est de cette boutique et dans le coffre. */
function ep_coffre_fichier(): void
{
    $shop = (int) ($_GET['shop'] ?? 0);
    $id = (int) ($_GET['id'] ?? 0);
    $r = cofShopOk($shop) ? cofDocDuCoffre($shop, $id) : null;
    if ($r !== null) { cofNoter($id, $shop, empty($_GET['telecharger']) ? 'ouvert' : 'telecharge', cofQui('franchise', $shop)); }
    docSortir($r);   // un document d'une autre boutique, ou hors du coffre : le même 404
}

/**
 * POST /coffre/magasin/document (multipart : shop, titre?, date?, echeance?, note?, fichiers[]) — le magasin dépose
 * une pièce LÉGALE dans son coffre (décision 3 de Sam) ; les autres sortes se déposent au cockpit.
 */
function wr_coffre_document(): array
{
    $shop = (int) ($_POST['shop'] ?? 0);
    if ($shop <= 0) {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === []) { http_response_code(413); return ['error' => 'envoi trop lourd pour le serveur : ' . round(docMaxOctets() / 1048576) . ' Mo au plus']; }
        http_response_code(400); return ['error' => 'shop requis'];
    }
    if (!cofShopOk($shop)) { http_response_code(403); return ['error' => 'ce magasin n’est pas sur votre session']; }
    $date = trim((string) ($_POST['date'] ?? ''));
    $ech = trim((string) ($_POST['echeance'] ?? ''));
    $titre = trim(mb_substr((string) ($_POST['titre'] ?? ''), 0, 190));
    $note = trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 500)) ?: null;
    $recus = docFichiersRecus();
    if ($recus === []) { http_response_code(422); return ['error' => 'aucun fichier reçu']; }
    cofTable();
    $qui = cofQui('franchise', $shop);
    $coffre = ['echeance' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $ech) ? $ech : null, 'visible' => true, 'par' => $qui['nom'], 'role' => $qui['role']];
    $max = docMaxOctets();
    $ajoutes = []; $refuses = [];
    foreach (array_slice($recus, 0, 10) as [$nom, $tmp, $octets, $err]) {
        $nom = trim(str_replace(["\0", '/', '\\'], '', $nom)) ?: 'document';
        $ext = strtolower((string) pathinfo($nom, PATHINFO_EXTENSION));
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($err === UPLOAD_ERR_OK && $octets > $max)) { $refuses[] = ['nom' => $nom, 'motif' => 'trop lourd : ' . round($max / 1048576) . ' Mo au plus']; continue; }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $refuses[] = ['nom' => $nom, 'motif' => 'envoi interrompu, réessayez']; continue; }
        if (!isset(DOC_EXT[$ext])) { $refuses[] = ['nom' => $nom, 'motif' => 'sorte de fichier refusée (' . ($ext ?: 'sans extension') . ')']; continue; }
        $t = $titre !== '' ? ($titre . (count($recus) > 1 ? ' — ' . pathinfo($nom, PATHINFO_FILENAME) : '')) : (string) pathinfo($nom, PATHINFO_FILENAME);
        $l = docGarder($shop, 'legal', $t, preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null, $note, $nom, $tmp, $octets, true, $coffre);
        if ($l === null) { $refuses[] = ['nom' => $nom, 'motif' => 'le serveur n’a pas pu l’écrire']; continue; }
        $ajoutes[] = $l;
    }
    if ($ajoutes !== [] && function_exists('journalAdd')) {
        journalAdd($qui['nom'], 'Franchisé', 'Coffre-fort', count($ajoutes) . ' pièce(s) légale(s) déposée(s) par le magasin ' . $shop . ' : ' . implode(', ', array_map(static fn ($l) => $l['titre'], $ajoutes)));
    }
    if ($ajoutes === []) { http_response_code(422); }
    return ['ok' => $ajoutes !== [], 'ajoutes' => $ajoutes, 'refuses' => $refuses];
}

/**
 * Les documents du coffre à joindre à un mail de la Boîte mail du magasin (Sam, 22/11/2026 : « pas d'envoi au
 * comptable, mais dans les mails, une annexe du coffre-fort ») : [{nom, type, contenu}], COF_MAIL_MAX au plus,
 * seulement ceux de CETTE boutique et dans son coffre ; chaque pièce jointe est notée. Lève une erreur lisible sinon.
 */
function cofPiecesMail(string $shop, array $ids): array
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0))), 0, COF_MAIL_MAX);
    if ($ids === []) { return []; }
    cofTable();
    $qui = cofQui('franchise', (int) $shop);
    $out = [];
    foreach ($ids as $id) {
        $r = cofDocDuCoffre((int) $shop, $id);
        $chemin = $r !== null ? docDossier((int) $r['shop']) . '/' . basename((string) $r['fichier']) : '';
        if ($r === null || !is_file($chemin)) { throw new RuntimeException('le document n° ' . $id . ' n’est pas dans le coffre de ce magasin'); }
        $out[] = ['nom' => (string) $r['nom_origine'], 'type' => (string) $r['mime'], 'contenu' => (string) file_get_contents($chemin)];
        cofNoter($id, (int) $shop, 'mail', $qui);
    }
    return $out;
}
