<?php
declare(strict_types=1);

/*
 * LA FORMATION CONTINUE (22/11/2026, Sam ; plan et maquettes : formation/docs/maquettes/formation-continue/).
 *
 * Un TYPE de formation, distinct de la formation initiale (les FO de pré-ouverture et d'ouverture, inchangées) : une
 * formation continue est un document du Manuel opératoire (une ligne de `for_sop`, type « FC », codes FC-001…,
 * partie « formation ») — mêmes étapes, photos, vidéo, points critiques, quiz, versions FR | NL, éditeur, tablette, PDF,
 * lecture par le Professeur. Elle porte :
 *   - une CATÉGORIE : technique · connaissance · nouveau produit · nouvelle technique ;
 *   - un STATUT réseau : brouillon → à valider → validée (`statut` de for_sop : brouillon | a_valider | publiee) ;
 *   - le nombre de jours de PHOTO QUALITÉ que demande sa validation en magasin (14 pour un nouveau produit, 0 sinon,
 *     réglable : une technique reliée à un produit en demande aussi).
 * Validée, elle est DIFFUSÉE aux boutiques : chacune reçoit son exemplaire, son DOSSIER (`for_fc_dossier`), que son
 * consultant VÉRIFIE (contenu, équipe, photos), ADAPTE pour elle (contenu, assignation, échéance) et VALIDE ; chaque
 * geste est tracé (`for_fc_trace` : qui, quand), et la validation archive le dossier dans le DOCUMENT DE FORMATION du
 * franchisé, rangé dans son coffre-fort (consultant/src/coffre.php, onglet Formation).
 *
 * Décisions (Sam, 22/11/2026) et choix par défaut (« let's go code it », plan § 3) :
 *   - la page Formation du franchisé est « Formations et suivi » (dashboard, ?vue=formation), où mène le bouton abricot ;
 *   - pas de connexion imposée : les routes du magasin vérifient le `shop` contre la session quand il y en a une (comme
 *     le coffre) ; « mes franchisés » du consultant vient du cadre de visite (consultant/src/visites_cadre.php) ;
 *   - l'avancement de l'équipe (lu, quiz, vu faire) est DÉCLARÉ par le franchisé dans son dossier ;
 *   - la validation passe par une photo qualité du produit PAR JOUR pendant 14 jours, photo obligatoire, déposée par le
 *     magasin dans son dossier et relue par le consultant (conforme / à refaire) ; un jour manqué ou une photo refusée
 *     ajoute un jour. Le panel n'a pas de tâche propre à un magasin et à une période (swagger relu le 22/11/2026) : la
 *     tâche du panel pourra prendre le relais, la photo garde sa place ici.
 *
 *   for_sop          + categorie, nature (« continue » pour une FC ; vide = l'existant), produit, photos_jours
 *   for_fc_dossier   une boutique × une FC : statut (en_cours | a_valider | validee), échéance, personnes assignées,
 *                    contenu adapté (JSON complet ; NULL = le modèle du réseau), mise en place, fin des photos, validation
 *   for_fc_equipe    une personne × un dossier : lu, quiz, vu faire (déclarés par le franchisé)
 *   for_fc_photo     une photo qualité d'un jour : fichier privé (uploads/formation-continue/{shop}/), avis du consultant
 *   for_fc_trace     chaque geste : diffusée, adaptée, photo, avis, terminée, validée, renvoyée — qui, rôle, quand
 *
 * Routes (fcRoute) — le siège (module Formation) :
 *   GET   /formation/continue                      la liste, ses compteurs, initiale | continue
 *   GET   /formation/continue/{id}                 la fiche : contenu, boutiques, journal
 *   POST  /formation/continue                      {titre, categorie, objectif?, contenu?, produit?, photosJours?, formations?}
 *   PATCH /formation/continue/{id}                 {categorie?, statut?, produit?, photosJours?}
 *   POST  /formation/continue/{id}/diffuser        {shops?: [ids] (toutes les boutiques ouvertes sinon), echeance?}
 * le consultant (cockpit, Franchisés › Formations continues) :
 *   GET   /formation/continue/franchises?consultant=     les boutiques (de ce consultant) et leurs repères
 *   GET   /formation/continue/franchise?shop=             les dossiers d'une boutique
 *   GET   /formation/continue/dossier?id=                 un dossier : contenu (adapté), équipe, photos, journal
 *   PUT   /formation/continue/dossier                     {id, contenu?: {…}|null, assignes?, echeance?, note?} — adapter
 *   POST  /formation/continue/dossier/avis                {photo, avis: conforme|a_refaire, commentaire?}
 *   POST  /formation/continue/dossier/valider             {id, mot?} — la trace, l'archive au coffre
 *   POST  /formation/continue/dossier/renvoyer            {id, motif?}
 *   GET   /formation/continue/photo?id=                   une photo (le cockpit)
 * le magasin (dashboard, « Formations et suivi » — préfixe /formation/continue/magasin dans ACCES_FRANCHISE) :
 *   GET   /formation/continue/magasin?shop=                       Nouveautés, dossier, repères, équipe
 *   GET   /formation/continue/magasin/formation?shop=&id=         une formation de son dossier (sa version)
 *   POST  /formation/continue/magasin/avancement                  {shop, id, personne, lu?, quiz?, vu?}
 *   POST  /formation/continue/magasin/mise-en-place               {shop, id}
 *   POST  /formation/continue/magasin/photo                       multipart {shop, id, photo} — la photo du jour
 *   GET   /formation/continue/magasin/photo?shop=&id=             une photo de son dossier
 * Le Professeur lit le dossier d'une boutique par fcProfesseurDossier / fcProfesseurLire (panel/src/assistant_magasin.php).
 */

const FC_CATEGORIES = ['technique' => 'Technique', 'connaissance' => 'Connaissance', 'nouveau_produit' => 'Nouveau produit',
    'nouvelle_technique' => 'Nouvelle technique'];
const FC_STATUTS = ['brouillon' => 'Brouillon', 'a_valider' => 'À valider', 'publiee' => 'Validée'];
const FC_STATUTS_MAGASIN = ['en_cours' => 'En cours', 'a_valider' => 'À valider', 'validee' => 'Validée'];
const FC_PHOTOS_JOURS = 14;          // une photo qualité par jour, pendant 14 jours (Sam, 22/11/2026)
const FC_PHOTOS_MAX_GRILLE = 40;     // jours affichés au plus (14 + les jours ajoutés)
const FC_NOUVEAUTES_JOURS = 60;      // une formation diffusée reste dans les Nouveautés deux mois
const FC_PHOTO_MAX = 12 * 1024 * 1024;
const FC_PHOTO_EXT = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic'];
const FC_PERSONNES_MAX = 30;

/* ═══ Tables ═════════════════════════════════════════════════════════════════════════════════════════ */

function ensureFormationContinue(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    // for_sop vient du module (ensureSop) ; on ne le crée que s'il n'existe pas encore.
    try { Db::row('SELECT id FROM for_sop LIMIT 1'); } catch (Throwable $e) { if (function_exists('ensureSop')) { ensureSop(); } }
    // Les colonnes du classement (ensureReferentiel) servent aussi à la FC ; elles sont posées ici si le référentiel ne l'a pas fait.
    foreach (['categorie VARCHAR(20) NULL', 'nature VARCHAR(12) NULL', 'produit VARCHAR(190) NULL', 'photos_jours INT NULL',
        'phases VARCHAR(160) NULL', 'postes VARCHAR(160) NULL', 'formations TEXT NULL'] as $col) {
        try { Db::exec('ALTER TABLE for_sop ADD COLUMN ' . $col); } catch (Throwable $e) { /* déjà là */ }
    }
    Db::exec('CREATE TABLE IF NOT EXISTS for_fc_dossier ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'shop_id INT NOT NULL,'
        . 'sop_id VARCHAR(32) NOT NULL,'
        . "statut VARCHAR(12) NOT NULL DEFAULT 'en_cours',"
        . 'echeance DATE NULL,'
        . 'assignes TEXT NULL,'
        . 'contenu_adapte MEDIUMTEXT NULL,'
        . 'adaptation_note VARCHAR(500) NULL,'
        . 'adapte_par VARCHAR(120) NULL,'
        . 'adapte_le DATETIME NULL,'
        . 'photos_du DATE NULL,'
        . 'mise_en_place_le DATETIME NULL,'
        . 'mise_en_place_par VARCHAR(120) NULL,'
        . 'termine_le DATETIME NULL,'
        . 'valide_par VARCHAR(120) NULL,'
        . 'valide_le DATETIME NULL,'
        . 'valide_mot VARCHAR(500) NULL,'
        . 'ouvert_le DATETIME NULL,'
        . 'diffuse_le DATETIME NOT NULL,'
        . 'diffuse_par VARCHAR(120) NULL,'
        . 'archive_doc INT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'UNIQUE KEY uq_fc_dossier (shop_id, sop_id),'
        . 'KEY idx_fc_sop (sop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS for_fc_equipe ('
        . 'dossier_id INT NOT NULL,'
        . 'personne VARCHAR(120) NOT NULL,'
        . 'lu TINYINT(1) NOT NULL DEFAULT 0,'
        . 'quiz TINYINT(1) NOT NULL DEFAULT 0,'
        . 'vu TINYINT(1) NOT NULL DEFAULT 0,'
        . 'maj_le DATETIME NOT NULL,'
        . 'PRIMARY KEY (dossier_id, personne)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS for_fc_photo ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'dossier_id INT NOT NULL,'
        . 'jour DATE NOT NULL,'
        . 'fichier VARCHAR(80) NOT NULL,'
        . 'mime VARCHAR(40) NOT NULL,'
        . 'octets INT NOT NULL DEFAULT 0,'
        . 'depose_le DATETIME NOT NULL,'
        . 'depose_par VARCHAR(120) NULL,'
        . 'avis VARCHAR(12) NULL,'
        . 'avis_par VARCHAR(120) NULL,'
        . 'avis_le DATETIME NULL,'
        . 'commentaire VARCHAR(300) NULL,'
        . 'KEY idx_fc_photo (dossier_id, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS for_fc_trace ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'sop_id VARCHAR(32) NOT NULL,'
        . 'dossier_id INT NULL,'
        . 'shop_id INT NULL,'
        . 'action VARCHAR(20) NOT NULL,'
        . 'detail VARCHAR(500) NULL,'
        . "par VARCHAR(120) NOT NULL DEFAULT '',"
        . "role VARCHAR(12) NOT NULL DEFAULT '',"
        . 'le DATETIME NOT NULL,'
        . 'KEY idx_fc_trace (sop_id, le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* ═══ Qui, quoi, petites règles ══════════════════════════════════════════════════════════════════════ */

/** Qui agit : {nom, role}. La session si elle existe ; sinon le rôle de la route (siege | franchise) et un nom par défaut. */
function fcQui(string $defaut, int $shop = 0, ?string $nom = null): array
{
    $id = function_exists('accesIdentite') ? accesIdentite() : null;
    if (is_array($id)) { return ['nom' => mb_substr(trim((string) ($nom ?: ($id['nom'] ?? ''))), 0, 120) ?: ($id['role'] === 'siege' ? 'Siège' : 'Magasin'), 'role' => (string) $id['role']]; }
    if ($nom !== null && trim($nom) !== '') { return ['nom' => mb_substr(trim($nom), 0, 120), 'role' => $defaut]; }
    if ($defaut === 'franchise') { return ['nom' => fcMagasinNom($shop), 'role' => 'franchise']; }
    $u = function_exists('setting') ? setting('utilisateur', []) : [];
    return ['nom' => is_array($u) && trim((string) ($u['nom'] ?? '')) !== '' ? trim((string) $u['nom']) : 'Siège', 'role' => 'siege'];
}

/** Cette boutique peut-elle être lue ou écrite ? Accès ouvert : oui (décision de Sam). Siège : oui. Magasin : la sienne. */
function fcShopOk(int $shop): bool
{
    if ($shop <= 0) { return false; }
    if (function_exists('accesMode') && accesMode() === 'ouvert') { return true; }
    $id = function_exists('accesIdentite') ? accesIdentite() : null;
    if (!is_array($id)) { return false; }
    return $id['role'] === 'siege' || in_array((string) $shop, array_map('strval', (array) ($id['shops'] ?? [])), true);
}

/** Les boutiques ouvertes : [id => {id, nom, court}] (le module des visites, sinon la table shops). */
function fcMagasins(): array
{
    if (function_exists('viMagasins')) {
        try { $m = viMagasins(); if ($m !== []) { return $m; } } catch (Throwable $e) { /* sans le module */ }
    }
    $out = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops') as $r) {
            $n = (string) $r['name'];
            $out[(string) $r['id']] = ['id' => (string) $r['id'], 'nom' => $n, 'court' => str_contains($n, ' - ') ? trim(substr($n, strrpos($n, ' - ') + 3)) : $n];
        }
    } catch (Throwable $e) { /* sans table */ }
    return $out;
}

function fcMagasinNom(int $shop): string
{
    $m = fcMagasins()[(string) $shop] ?? null;
    return $m !== null ? (string) $m['nom'] : 'Magasin ' . $shop;
}

/** Les jours de photo par défaut d'une catégorie : 14 pour un nouveau produit, 0 sinon (réglable sur la fiche). */
function fcPhotosDefaut(string $categorie): int { return $categorie === 'nouveau_produit' ? FC_PHOTOS_JOURS : 0; }

function fcCategorie(mixed $v): ?string { return is_string($v) && isset(FC_CATEGORIES[$v]) ? $v : null; }

/** Trace un geste (et le journal du cockpit). */
function fcTracer(string $sop, ?int $dossier, ?int $shop, string $action, string $detail, array $qui): void
{
    Db::exec('INSERT INTO for_fc_trace (sop_id, dossier_id, shop_id, action, detail, par, role, le) VALUES (?,?,?,?,?,?,?,?)',
        [$sop, $dossier, $shop, $action, mb_substr($detail, 0, 500), $qui['nom'], $qui['role'], date('Y-m-d H:i:s')]);
    if (function_exists('journalAdd')) {
        try { journalAdd($qui['nom'], 'Formation', 'Formation continue', $sop . ($shop ? ' · magasin ' . $shop : '') . ' : ' . $detail); } catch (Throwable $e) { /* le journal est un plus */ }
    }
}

/** Les FC (lignes de for_sop), [id => ligne]. */
function fcSops(?string $id = null): array
{
    $rows = $id !== null ? Db::rows("SELECT * FROM for_sop WHERE type = 'FC' AND id = ?", [$id]) : Db::rows("SELECT * FROM for_sop WHERE type = 'FC' ORDER BY code DESC, id DESC");
    $out = [];
    foreach ($rows as $r) { $out[(string) $r['id']] = $r; }
    return $out;
}

/** Le contenu en vigueur d'une FC (sa version), au format du Manuel (foContenu). */
function fcContenuModele(array $sop): array
{
    $r = Db::row('SELECT contenu FROM for_sop_version WHERE sop_id = ? AND version = ?', [$sop['id'], $sop['version']]);
    $j = $r !== null ? json_decode((string) $r['contenu'], true) : null;
    return function_exists('foContenu') ? foContenu(is_array($j) ? $j : []) : (is_array($j) ? $j : []);
}

/** Le contenu d'un dossier : l'adaptation de la boutique, sinon le modèle du réseau. */
function fcContenuDossier(array $d, array $sop): array
{
    if (!empty($d['contenu_adapte'])) {
        $j = json_decode((string) $d['contenu_adapte'], true);
        if (is_array($j)) { return function_exists('foContenu') ? foContenu($j) : $j; }
    }
    return fcContenuModele($sop);
}

/** Les personnes assignées d'un dossier (liste de noms). */
function fcAssignes(array $d): array
{
    $l = json_decode((string) ($d['assignes'] ?? ''), true);
    return is_array($l) ? array_values(array_filter(array_map(static fn ($x) => mb_substr(trim((string) $x), 0, 120), $l), static fn ($x) => $x !== '')) : [];
}

/* ═══ Photos, avancement, statut ═════════════════════════════════════════════════════════════════════ */

/**
 * La grille des photos d'un dossier : un jour par case depuis `photos_du`. Une case : conforme | a_refaire |
 * en_attente (photo reçue, pas encore relue) | manquante (jour passé sans photo) | a_faire (aujourd'hui) | a_venir.
 * Il faut `jours` photos conformes ; chaque jour manqué ou refusé ajoute une case (règle du plan, décision 7).
 */
function fcPhotosGrille(array $d, int $jours, string $auj): array
{
    $vide = ['jours' => $jours, 'conformes' => 0, 'refusees' => 0, 'manquantes' => 0, 'enAttente' => 0, 'du' => null, 'fin' => null, 'grille' => [], 'complet' => $jours === 0];
    if ($jours <= 0 || empty($d['photos_du'])) { return $vide; }
    $par = [];
    foreach (Db::rows('SELECT id, jour, avis, commentaire, depose_le FROM for_fc_photo WHERE dossier_id = ? ORDER BY jour, id', [(int) $d['id']]) as $p) { $par[(string) $p['jour']] = $p; }
    $g = []; $conf = 0; $ref = 0; $manq = 0; $att = 0;
    $t0 = strtotime((string) $d['photos_du']);
    for ($i = 0; $i < FC_PHOTOS_MAX_GRILLE; $i++) {
        $jour = date('Y-m-d', $t0 + $i * 86400 + 7200);   // + 2 h : jamais deux fois le même jour au changement d'heure
        $p = $par[$jour] ?? null;
        if ($p !== null) { $etat = $p['avis'] === 'conforme' ? 'conforme' : ($p['avis'] === 'a_refaire' ? 'a_refaire' : 'en_attente'); }
        else { $etat = $jour < $auj ? 'manquante' : ($jour === $auj ? 'a_faire' : 'a_venir'); }
        $g[] = ['jour' => $jour, 'etat' => $etat, 'photo' => $p !== null ? (int) $p['id'] : null, 'commentaire' => $p !== null ? $p['commentaire'] : null];
        if ($etat === 'conforme') { $conf++; } elseif ($etat === 'a_refaire') { $ref++; } elseif ($etat === 'manquante') { $manq++; } elseif ($etat === 'en_attente') { $att++; }
        // La grille s'arrête quand il y a assez de conformes, ou quand elle couvre les jours dus plus les jours ajoutés.
        if ($conf >= $jours || count($g) >= $jours + $ref + $manq) { break; }
    }
    $fin = end($g)['jour'] ?? null;
    return ['jours' => $jours, 'conformes' => $conf, 'refusees' => $ref, 'manquantes' => $manq, 'enAttente' => $att, 'du' => (string) $d['photos_du'], 'fin' => $fin, 'grille' => $g, 'complet' => $conf >= $jours];
}

/** L'équipe d'un dossier : [{personne, lu, quiz, vu}] pour chaque personne assignée (les autres déclarations restent). */
function fcEquipe(array $d): array
{
    $dec = [];
    foreach (Db::rows('SELECT personne, lu, quiz, vu FROM for_fc_equipe WHERE dossier_id = ?', [(int) $d['id']]) as $r) { $dec[(string) $r['personne']] = $r; }
    return array_map(static fn ($p) => ['personne' => $p, 'lu' => !empty($dec[$p]['lu']), 'quiz' => !empty($dec[$p]['quiz']), 'vu' => !empty($dec[$p]['vu'])], fcAssignes($d));
}

/**
 * L'avancement d'un dossier, en % : la moitié pour l'équipe (lu, quiz, vu faire de chaque personne, et la mise en place),
 * la moitié pour les photos s'il en faut ; sans photos, l'équipe compte pour tout. `complet` : tout est fait.
 */
function fcAvancement(array $d, array $equipe, array $photos): array
{
    $n = count($equipe);
    $faits = array_sum(array_map(static fn ($p) => (int) $p['lu'] + (int) $p['quiz'] + (int) $p['vu'], $equipe)) + (!empty($d['mise_en_place_le']) ? 1 : 0);
    $eq = $faits / (3 * $n + 1);
    $ph = $photos['jours'] > 0 ? min(1, $photos['conformes'] / $photos['jours']) : null;
    $pct = (int) round(100 * ($ph === null ? $eq : ($eq + $ph) / 2));
    return ['pct' => $pct, 'equipe' => (int) round(100 * $eq), 'photos' => $ph === null ? null : (int) round(100 * $ph), 'complet' => $eq >= 1 && ($ph === null || $ph >= 1)];
}

/** Le statut d'un dossier relu : en cours → à valider quand tout est fait (tracé) ; rend le dossier à jour. */
function fcEvaluer(array $d, array $sop, string $auj): array
{
    if ($d['statut'] !== 'en_cours') { return $d; }
    $ph = fcPhotosGrille($d, (int) ($sop['photos_jours'] ?? 0), $auj);
    $av = fcAvancement($d, fcEquipe($d), $ph);
    if (!$av['complet']) { return $d; }
    $now = date('Y-m-d H:i:s');
    Db::exec("UPDATE for_fc_dossier SET statut = 'a_valider', termine_le = ?, maj_le = ? WHERE id = ? AND statut = 'en_cours'", [$now, $now, (int) $d['id']]);
    fcTracer((string) $sop['id'], (int) $d['id'], (int) $d['shop_id'], 'terminee', 'tout est fait' . ($ph['jours'] ? ' (' . $ph['conformes'] . ' photos conformes)' : '') . ' — passe « à valider »', ['nom' => 'automatique', 'role' => 'serveur']);
    return Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [(int) $d['id']]) ?? $d;
}

/** Un dossier tel que les écrans le lisent (sans le contenu). */
function fcDossierVue(array $d, array $sop, string $auj, bool $evaluer = true): array
{
    if ($evaluer) { $d = fcEvaluer($d, $sop, $auj); }
    $ph = fcPhotosGrille($d, (int) ($sop['photos_jours'] ?? 0), $auj);
    $eq = fcEquipe($d);
    $av = fcAvancement($d, $eq, $ph);
    $retard = $d['statut'] === 'en_cours' && !empty($d['echeance']) && (string) $d['echeance'] < $auj;
    return ['id' => (int) $d['id'], 'shop' => (string) $d['shop_id'], 'magasin' => fcMagasinNom((int) $d['shop_id']), 'fc' => (string) $sop['id'], 'code' => (string) ($sop['code'] ?? ''),
        'titre' => (string) $sop['titre_fr'], 'titreNl' => (string) ($sop['titre_nl'] ?? ''), 'categorie' => (string) ($sop['categorie'] ?? ''),
        'categorieLib' => FC_CATEGORIES[(string) ($sop['categorie'] ?? '')] ?? '', 'produit' => $sop['produit'] ?? null,
        'statut' => (string) $d['statut'], 'statutLib' => $retard ? 'En retard' : (FC_STATUTS_MAGASIN[(string) $d['statut']] ?? (string) $d['statut']), 'retard' => $retard,
        'echeance' => $d['echeance'] ?? null, 'assignes' => fcAssignes($d), 'adapte' => !empty($d['contenu_adapte']) || !empty($d['adaptation_note']),
        'adaptation' => ['note' => $d['adaptation_note'] ?? null, 'par' => $d['adapte_par'] ?? null, 'le' => isset($d['adapte_le']) ? substr((string) $d['adapte_le'], 0, 16) : null, 'contenu' => !empty($d['contenu_adapte'])],
        'avancement' => $av, 'photos' => $ph, 'equipe' => $eq, 'miseEnPlace' => !empty($d['mise_en_place_le']) ? ['le' => substr((string) $d['mise_en_place_le'], 0, 16), 'par' => $d['mise_en_place_par']] : null,
        'termineLe' => isset($d['termine_le']) ? substr((string) $d['termine_le'], 0, 16) : null,
        'validation' => !empty($d['valide_le']) ? ['par' => $d['valide_par'], 'le' => substr((string) $d['valide_le'], 0, 16), 'mot' => $d['valide_mot'] ?? null, 'archive' => isset($d['archive_doc']) ? (int) $d['archive_doc'] : null] : null,
        'diffuseLe' => substr((string) $d['diffuse_le'], 0, 16), 'nouveau' => empty($d['ouvert_le']), 'version' => (string) $sop['version']];
}

/** Le journal d'une FC (ou d'un dossier), le plus récent d'abord. */
function fcTraces(string $sop, ?int $dossier = null, int $max = 50): array
{
    $rows = $dossier !== null
        ? Db::rows('SELECT * FROM for_fc_trace WHERE sop_id = ? AND (dossier_id = ? OR dossier_id IS NULL) ORDER BY le DESC, id DESC LIMIT ' . $max, [$sop, $dossier])
        : Db::rows('SELECT * FROM for_fc_trace WHERE sop_id = ? ORDER BY le DESC, id DESC LIMIT ' . $max, [$sop]);
    return array_map(static fn ($t) => ['action' => (string) $t['action'], 'detail' => (string) $t['detail'], 'par' => (string) $t['par'], 'role' => (string) $t['role'],
        'le' => substr((string) $t['le'], 0, 16), 'shop' => $t['shop_id'] !== null ? (string) $t['shop_id'] : null], $rows);
}

/** Une ligne de la liste du siège. */
function fcLigne(array $r, array $doss): array
{
    $n = count($doss);
    $moy = $n ? (int) round(array_sum(array_map(static fn ($d) => $d['avancement']['pct'], $doss)) / $n) : null;
    return ['id' => (string) $r['id'], 'code' => (string) ($r['code'] ?? ''), 'titre' => (string) $r['titre_fr'], 'titreNl' => (string) ($r['titre_nl'] ?? ''),
        'categorie' => (string) ($r['categorie'] ?? ''), 'categorieLib' => FC_CATEGORIES[(string) ($r['categorie'] ?? '')] ?? '',
        'statut' => (string) $r['statut'], 'statutLib' => FC_STATUTS[(string) $r['statut']] ?? (string) $r['statut'], 'version' => (string) $r['version'],
        'produit' => $r['produit'] ?? null, 'photosJours' => (int) ($r['photos_jours'] ?? 0), 'etapes' => (int) ($r['nb_etapes'] ?? 0), 'photos' => (int) ($r['nb_photos'] ?? 0),
        'tradNl' => (string) ($r['trad_nl'] ?? ''), 'majLe' => substr((string) $r['maj_le'], 0, 16), 'majPar' => (string) ($r['maj_par'] ?? ''),
        'boutiques' => $n, 'validees' => count(array_filter($doss, static fn ($d) => $d['statut'] === 'validee')), 'avancementMoyen' => $moy,
        'diffuseLe' => $n ? min(array_map(static fn ($d) => $d['diffuseLe'], $doss)) : null];
}

/** Les dossiers d'une FC : [vue, …]. */
function fcDossiersDe(array $sop, string $auj): array
{
    return array_map(static fn ($d) => fcDossierVue($d, $sop, $auj), Db::rows('SELECT * FROM for_fc_dossier WHERE sop_id = ? ORDER BY shop_id', [$sop['id']]));
}

/* ═══ Le siège : la liste, la fiche, créer, régler, diffuser ═════════════════════════════════════════ */

/** GET /formation/continue — les formations continues, leurs catégories et statuts ; initiale | continue en tête. */
function ep_fc_liste(): array
{
    ensureFormationContinue();
    $auj = date('Y-m-d');
    $l = array_map(static fn ($r) => fcLigne($r, fcDossiersDe($r, $auj)), array_values(fcSops()));
    $parCat = array_fill_keys(array_keys(FC_CATEGORIES), 0);
    $parSt = array_fill_keys(array_keys(FC_STATUTS), 0);
    foreach ($l as $x) { if (isset($parCat[$x['categorie']])) { $parCat[$x['categorie']]++; } if (isset($parSt[$x['statut']])) { $parSt[$x['statut']]++; } }
    // Initiale | continue : les FO du référentiel par phase (for_ref_formation) — une FO peut être dans les deux.
    $ini = 0; $rec = 0; $deux = 0;
    try {
        foreach (Db::rows('SELECT phases FROM for_ref_formation') as $r) {
            $p = json_decode((string) $r['phases'], true) ?: [];
            $i = in_array('pre', $p, true) || in_array('ouv', $p, true); $c = in_array('con', $p, true);
            $ini += $i ? 1 : 0; $rec += $c ? 1 : 0; $deux += $i && $c ? 1 : 0;
        }
    } catch (Throwable $e) { /* le référentiel n'est pas encore semé */ }
    return ['formations' => $l, 'categories' => array_map(static fn ($k, $v) => ['cle' => $k, 'nom' => $v, 'n' => $parCat[$k]], array_keys(FC_CATEGORIES), FC_CATEGORIES),
        'statuts' => array_map(static fn ($k, $v) => ['cle' => $k, 'nom' => $v, 'n' => $parSt[$k]], array_keys(FC_STATUTS), FC_STATUTS),
        'types' => ['initiale' => $ini, 'recyclages' => $rec, 'lesDeux' => $deux, 'continue' => $rec + count($l), 'contenus' => count($l)],
        'magasins' => array_values(array_map(static fn ($m) => ['id' => (string) $m['id'], 'nom' => (string) $m['nom']], fcMagasins()))];
}

/** GET /formation/continue/{id} — la fiche : le contenu en vigueur, les boutiques, le journal. */
function ep_fc_fiche(string $id): array
{
    ensureFormationContinue();
    $sop = fcSops($id)[$id] ?? null;
    if ($sop === null) { return fcRefus(404, 'formation continue inconnue'); }
    $auj = date('Y-m-d');
    $doss = fcDossiersDe($sop, $auj);
    $f = fcLigne($sop, $doss);
    $c = fcContenuModele($sop);
    $f['contenu'] = $c;
    $f['formations'] = json_decode((string) ($sop['formations'] ?? ''), true) ?: [];
    $f['postes'] = json_decode((string) ($sop['postes'] ?? ''), true) ?: [];
    $f['dossiers'] = $doss;
    $f['journal'] = fcTraces($id);
    return $f;
}

/** Le prochain code FC-nnn. */
function fcCodeSuivant(): string
{
    $max = 0;
    foreach (Db::rows("SELECT code FROM for_sop WHERE code LIKE 'FC-%'") as $r) { if (preg_match('/^FC-(\d+)$/', (string) $r['code'], $m)) { $max = max($max, (int) $m[1]); } }
    return sprintf('FC-%03d', $max + 1);
}

/**
 * POST /formation/continue — crée une formation continue (brouillon, ou « à valider » avec `aValider`). `contenu` : le
 * format du Manuel (l'assistant des SOP l'envoie entier) ; sans lui, une première étape vide à rédiger dans l'éditeur.
 */
function wr_fc_creer(): array
{
    ensureFormationContinue();
    $b = body();
    $titre = ['fr' => trim(mb_substr((string) ($b['titre']['fr'] ?? (is_string($b['titre'] ?? null) ? $b['titre'] : '')), 0, 190)), 'nl' => trim(mb_substr((string) ($b['titre']['nl'] ?? ''), 0, 190))];
    if ($titre['fr'] === '') { return fcRefus(400, 'le titre est attendu'); }
    $cat = fcCategorie($b['categorie'] ?? null);
    if ($cat === null) { return fcRefus(400, 'catégorie : ' . implode(', ', array_keys(FC_CATEGORIES))); }
    $code = fcCodeSuivant();
    $id = strtolower(str_replace('-', '', $code));   // fc001
    for ($i = 2; Db::row('SELECT id FROM for_sop WHERE id = ?', [$id]) !== null; $i++) { $id = strtolower(str_replace('-', '', $code)) . '-' . $i; }
    $bi = static fn ($v) => is_array($v) ? ['fr' => trim((string) ($v['fr'] ?? '')), 'nl' => trim((string) ($v['nl'] ?? ''))] : ['fr' => trim((string) ($v ?? '')), 'nl' => ''];
    $brut = is_array($b['contenu'] ?? null) ? $b['contenu'] : ['sections' => [['titre' => ['fr' => 'Les étapes', 'nl' => 'De stappen'], 'etapes' => [['numero' => '1', 'texte' => ['fr' => 'À rédiger.', 'nl' => '']]]]]];
    $brut['titre'] = $titre;
    $brut['objectif'] = $bi($b['objectif'] ?? ($brut['objectif'] ?? ''));
    $c = function_exists('foContenu') ? foContenu($brut) : $brut;
    $nbEt = 0; $nbPh = 0;
    foreach ($c['sections'] ?? [] as $s) { foreach ($s['etapes'] ?? [] as $e) { $nbEt++; $nbPh += !empty($e['photo']) ? 1 : 0; } }
    $photos = isset($b['photosJours']) ? max(0, min(30, (int) $b['photosJours'])) : fcPhotosDefaut($cat);
    $qui = fcQui('siege', 0, isset($b['par']) ? (string) $b['par'] : null);
    $now = date('Y-m-d H:i:s');
    $statut = !empty($b['aValider']) ? 'a_valider' : 'brouillon';
    $ordre = (int) (Db::row('SELECT COALESCE(MAX(ordre), 0) + 1 AS o FROM for_sop')['o'] ?? 1);
    $forms = array_values(array_filter(array_map('strval', (array) ($b['formations'] ?? [])), static fn ($f) => (bool) preg_match('/^FO-\d{1,3}$/', $f)));
    $postes = array_values(array_filter(array_map('strval', (array) ($b['postes'] ?? [])), static fn ($p) => (bool) preg_match('/^P\d{1,2}$/', $p)));
    Db::exec("INSERT INTO for_sop (id, code, type, dossier, poste, partie, moment, statut, version, version_revalider, langue_origine, trad_fr, trad_nl, titre_fr, titre_nl,
                objectif_fr, objectif_nl, duree, nb_etapes, nb_photos, ordre, maj_le, maj_par, categorie, nature, produit, photos_jours, phases, postes, formations)
              VALUES (?, ?, 'FC', '04', 'vente', 'formation', NULL, ?, '1.0', '1.0', 'FR', 'origine', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'continue', ?, ?, ?, ?, ?)",
        [$id, $code, $statut, $titre['nl'] !== '' ? 'origine' : 'a_relire', $titre['fr'], $titre['nl'], $c['objectif']['fr'] ?? '', $c['objectif']['nl'] ?? '',
            isset($c['duree']) && $c['duree'] !== null ? mb_substr((string) $c['duree'], 0, 190) : null, $nbEt, $nbPh, $ordre, $now, $qui['nom'], $cat,
            isset($b['produit']) && trim((string) $b['produit']) !== '' ? mb_substr(trim((string) $b['produit']), 0, 190) : null, $photos,
            json_encode(['formation']), json_encode($postes), json_encode($forms)]);
    Db::exec('INSERT INTO for_sop_version (sop_id, version, contenu, note, revalider, publie_le, publie_par) VALUES (?, ?, ?, ?, 0, ?, ?)',
        [$id, '1.0', (string) json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'Création', $now, $qui['nom']]);
    fcTracer($id, null, null, 'creee', 'créée (' . FC_CATEGORIES[$cat] . ', ' . $nbEt . ' étape(s))' . ($statut === 'a_valider' ? ', envoyée à la validation' : ''), $qui);
    http_response_code(201);
    return ['ok' => true, 'id' => $id, 'code' => $code, 'statut' => $statut];
}

/** PATCH /formation/continue/{id} — {categorie?, statut?: brouillon|a_valider|publiee, produit?, photosJours?}. */
function wr_fc_regler(string $id): array
{
    ensureFormationContinue();
    $sop = fcSops($id)[$id] ?? null;
    if ($sop === null) { return fcRefus(404, 'formation continue inconnue'); }
    $b = body();
    $set = []; $p = []; $dit = [];
    if (array_key_exists('categorie', $b)) {
        $c = fcCategorie($b['categorie']); if ($c === null) { return fcRefus(400, 'catégorie inconnue'); }
        $set[] = 'categorie = ?'; $p[] = $c; $dit[] = 'catégorie ' . FC_CATEGORIES[$c];
    }
    if (array_key_exists('statut', $b)) {
        $s = (string) $b['statut'];
        if (!isset(FC_STATUTS[$s])) { return fcRefus(400, 'statut : brouillon, a_valider ou publiee'); }
        $set[] = 'statut = ?'; $p[] = $s; $dit[] = ['brouillon' => 'remise en brouillon', 'a_valider' => 'envoyée à la validation', 'publiee' => 'validée'][$s];
    }
    if (array_key_exists('produit', $b)) { $v = trim((string) ($b['produit'] ?? '')); $set[] = 'produit = ?'; $p[] = $v !== '' ? mb_substr($v, 0, 190) : null; $dit[] = $v !== '' ? 'produit « ' . $v . ' »' : 'sans produit'; }
    if (array_key_exists('photosJours', $b)) { $n = max(0, min(30, (int) $b['photosJours'])); $set[] = 'photos_jours = ?'; $p[] = $n; $dit[] = $n ? $n . ' jours de photo qualité' : 'sans photo'; }
    if ($set === []) { return fcRefus(422, 'rien à changer'); }
    $qui = fcQui('siege', 0, isset($b['par']) ? (string) $b['par'] : null);
    $set[] = 'maj_le = ?'; $p[] = date('Y-m-d H:i:s'); $set[] = 'maj_par = ?'; $p[] = $qui['nom']; $p[] = $id;
    Db::exec('UPDATE for_sop SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
    fcTracer($id, null, null, isset($b['statut']) && $b['statut'] === 'publiee' ? 'validee_reseau' : 'reglee', implode(', ', $dit), $qui);
    return ['ok' => true, 'formation' => fcLigne(fcSops($id)[$id], fcDossiersDe(fcSops($id)[$id], date('Y-m-d')))];
}

/**
 * POST /formation/continue/{id}/diffuser {shops?: [ids], echeance?: 'AAAA-MM-JJ', photosDu?: 'AAAA-MM-JJ'} — la formation
 * VALIDÉE part dans le dossier des boutiques (toutes les boutiques ouvertes sans `shops`) ; une boutique qui l'a déjà
 * n'en reçoit pas une seconde. Les photos commencent le jour de la diffusion (ou `photosDu`, la mise en vente).
 */
function wr_fc_diffuser(string $id): array
{
    ensureFormationContinue();
    $sop = fcSops($id)[$id] ?? null;
    if ($sop === null) { return fcRefus(404, 'formation continue inconnue'); }
    if ($sop['statut'] !== 'publiee') { return fcRefus(409, 'seule une formation validée part chez les franchisés'); }
    $b = body();
    $mags = fcMagasins();
    $shops = isset($b['shops']) && is_array($b['shops']) ? array_values(array_unique(array_map('strval', $b['shops']))) : array_keys($mags);
    $shops = array_values(array_filter($shops, static fn ($s) => (int) $s > 0));
    if ($shops === []) { return fcRefus(422, 'aucune boutique'); }
    $ech = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['echeance'] ?? '')) ? (string) $b['echeance'] : null;
    $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['photosDu'] ?? '')) ? (string) $b['photosDu'] : date('Y-m-d');
    $qui = fcQui('siege', 0, isset($b['par']) ? (string) $b['par'] : null);
    $now = date('Y-m-d H:i:s');
    $faits = [];
    foreach ($shops as $s) {
        if (Db::row('SELECT id FROM for_fc_dossier WHERE shop_id = ? AND sop_id = ?', [(int) $s, $id]) !== null) { continue; }
        Db::exec("INSERT INTO for_fc_dossier (shop_id, sop_id, statut, echeance, photos_du, diffuse_le, diffuse_par, maj_le) VALUES (?, ?, 'en_cours', ?, ?, ?, ?, ?)",
            [(int) $s, $id, $ech, (int) ($sop['photos_jours'] ?? 0) > 0 ? $du : null, $now, $qui['nom'], $now]);
        $faits[] = (int) $s;
        fcTracer($id, (int) Db::pdo()->lastInsertId(), (int) $s, 'diffusee', 'reçue dans le dossier de ' . fcMagasinNom((int) $s) . ($ech ? ', à faire avant le ' . $ech : ''), $qui);
    }
    return ['ok' => true, 'diffusees' => $faits, 'deja' => count($shops) - count($faits)];
}

/* ═══ Le consultant : ses franchisés, un dossier, adapter, relire les photos, valider ═════════════════ */

/** Le consultant de chaque boutique, d'après le cadre de visite : [shop => [{id, nom}]]. */
function fcConsultantsParMagasin(): array
{
    $out = [];
    if (!function_exists('vcCadre')) { return $out; }
    try {
        foreach (vcCadre() as $l) {
            if (($l['consultant'] ?? '') === '') { continue; }
            $out[(string) $l['shop']][(string) $l['consultant']] = ['id' => (string) $l['consultant'], 'nom' => (string) ($l['consultantNom'] ?: $l['consultant'])];
        }
    } catch (Throwable $e) { /* sans cadre */ }
    return array_map('array_values', $out);
}

/**
 * GET /formation/continue/franchises?consultant= — les boutiques et leurs repères (à valider, en retard, avancement,
 * validées ce mois). `consultant` : seulement ses boutiques (cadre de visite) ; vide : tout le réseau (le siège).
 */
function ep_fc_franchises(): array
{
    ensureFormationContinue();
    $auj = date('Y-m-d');
    $cons = fcConsultantsParMagasin();
    $qui = trim((string) ($_GET['consultant'] ?? ''));
    $tous = [];
    foreach ($cons as $l) { foreach ($l as $c) { $tous[$c['id']] = $c; } }
    $sops = fcSops();
    $parShop = [];
    foreach (Db::rows('SELECT * FROM for_fc_dossier ORDER BY shop_id') as $d) {
        $sop = $sops[(string) $d['sop_id']] ?? null;
        if ($sop !== null) { $parShop[(string) $d['shop_id']][] = fcDossierVue($d, $sop, $auj); }
    }
    $mois = substr($auj, 0, 7);
    $out = [];
    foreach (fcMagasins() as $sid => $m) {
        $miens = array_column($cons[(string) $sid] ?? [], 'id');
        if ($qui !== '' && !in_array($qui, $miens, true)) { continue; }
        $D = $parShop[(string) $sid] ?? [];
        $enCours = array_filter($D, static fn ($d) => $d['statut'] !== 'validee');
        $out[] = ['shop' => (string) $sid, 'nom' => (string) $m['nom'], 'court' => (string) ($m['court'] ?? $m['nom']), 'consultants' => $cons[(string) $sid] ?? [],
            'formations' => count($D), 'aValider' => count(array_filter($D, static fn ($d) => $d['statut'] === 'a_valider')),
            'enRetard' => count(array_filter($D, static fn ($d) => $d['retard'])),
            'avancement' => $enCours ? (int) round(array_sum(array_map(static fn ($d) => $d['avancement']['pct'], $enCours)) / count($enCours)) : null,
            'valideesMois' => count(array_filter($D, static fn ($d) => $d['validation'] !== null && str_starts_with((string) $d['validation']['le'], $mois)))];
    }
    $T = static fn (string $k) => array_sum(array_column($out, $k));
    return ['franchises' => $out, 'consultants' => array_values($tous), 'consultant' => $qui !== '' ? ($tous[$qui] ?? ['id' => $qui, 'nom' => $qui]) : null,
        'consultantCompte' => function_exists('consultantIdCompte') ? (function () { try { return consultantIdCompte(); } catch (Throwable $e) { return null; } })() : null,
        'reperes' => ['aValider' => $T('aValider'), 'enRetard' => $T('enRetard'), 'valideesMois' => $T('valideesMois'),
            'avancement' => ($n = count(array_filter($out, static fn ($f) => $f['avancement'] !== null))) ? (int) round(array_sum(array_map(static fn ($f) => (int) $f['avancement'], $out)) / $n) : null]];
}

/** GET /formation/continue/franchise?shop= — les dossiers d'une boutique, les plus urgents d'abord. */
function ep_fc_franchise(): array
{
    ensureFormationContinue();
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { return fcRefus(400, 'shop requis'); }
    $auj = date('Y-m-d');
    $sops = fcSops();
    $l = [];
    foreach (Db::rows('SELECT * FROM for_fc_dossier WHERE shop_id = ?', [$shop]) as $d) {
        $sop = $sops[(string) $d['sop_id']] ?? null;
        if ($sop !== null) { $l[] = fcDossierVue($d, $sop, $auj); }
    }
    $rang = static fn ($d) => $d['statut'] === 'a_valider' ? 0 : ($d['retard'] ? 1 : ($d['statut'] === 'en_cours' ? 2 : 3));
    usort($l, static fn ($a, $b) => [$rang($a), $b['diffuseLe']] <=> [$rang($b), $a['diffuseLe']]);
    // Les formations validées au réseau pas encore diffusées à cette boutique : le consultant les voit venir.
    $avenir = [];
    foreach ($sops as $s) {
        if (in_array((string) $s['id'], array_column($l, 'fc'), true)) { continue; }
        $avenir[] = ['fc' => (string) $s['id'], 'code' => (string) $s['code'], 'titre' => (string) $s['titre_fr'], 'categorie' => (string) ($s['categorie'] ?? ''),
            'categorieLib' => FC_CATEGORIES[(string) ($s['categorie'] ?? '')] ?? '', 'statut' => (string) $s['statut'], 'statutLib' => FC_STATUTS[(string) $s['statut']] ?? ''];
    }
    return ['shop' => (string) $shop, 'nom' => fcMagasinNom($shop), 'dossiers' => $l, 'aVenir' => $avenir, 'categories' => FC_CATEGORIES];
}

/** Un dossier par son id, avec sa FC ; null si l'un manque. */
function fcDossierEtSop(int $id): ?array
{
    $d = $id > 0 ? Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [$id]) : null;
    $sop = $d !== null ? (fcSops((string) $d['sop_id'])[(string) $d['sop_id']] ?? null) : null;
    return $d !== null && $sop !== null ? [$d, $sop] : null;
}

/** GET /formation/continue/dossier?id= — tout le dossier : la version de la boutique, l'équipe, les photos, le journal. */
function ep_fc_dossier(): array
{
    ensureFormationContinue();
    $x = fcDossierEtSop((int) ($_GET['id'] ?? 0));
    if ($x === null) { return fcRefus(404, 'dossier inconnu'); }
    [$d, $sop] = $x;
    $v = fcDossierVue($d, $sop, date('Y-m-d'));
    $v['contenu'] = fcContenuDossier(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [(int) $d['id']]) ?? $d, $sop);
    $v['modele'] = fcContenuModele($sop);
    $v['journal'] = fcTraces((string) $sop['id'], (int) $d['id']);
    return $v;
}

/**
 * PUT /formation/continue/dossier {id, contenu?: {…}|null, assignes?: [noms], echeance?: 'AAAA-MM-JJ'|null, note?} —
 * le consultant adapte la formation POUR CETTE BOUTIQUE ; `contenu: null` revient au modèle du réseau. Le modèle ne
 * bouge pas ; l'écart reste visible (adaptation : note, qui, quand).
 */
function wr_fc_adapter(): array
{
    ensureFormationContinue();
    $b = body();
    $x = fcDossierEtSop((int) ($b['id'] ?? 0));
    if ($x === null) { return fcRefus(404, 'dossier inconnu'); }
    [$d, $sop] = $x;
    if ($d['statut'] === 'validee') { return fcRefus(409, 'formation déjà validée pour cette boutique : renvoyez-la d’abord'); }
    $set = []; $p = []; $dit = [];
    if (array_key_exists('contenu', $b)) {
        if ($b['contenu'] === null) { $set[] = 'contenu_adapte = NULL'; $dit[] = 'retour au modèle du réseau'; }
        elseif (is_array($b['contenu'])) {
            $c = function_exists('foContenu') ? foContenu(array_merge($b['contenu'], ['titre' => $b['contenu']['titre'] ?? ['fr' => (string) $sop['titre_fr'], 'nl' => (string) ($sop['titre_nl'] ?? '')]])) : $b['contenu'];
            $n = 0; foreach ($c['sections'] ?? [] as $s) { $n += count($s['etapes'] ?? []); }
            if ($n === 0) { return fcRefus(400, 'au moins une étape'); }
            $set[] = 'contenu_adapte = ?'; $p[] = (string) json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $dit[] = 'contenu adapté (' . $n . ' étape(s))';
        } else { return fcRefus(400, 'contenu : un objet ou null'); }
    }
    if (array_key_exists('assignes', $b)) {
        $l = array_slice(array_values(array_unique(array_filter(array_map(static fn ($s) => mb_substr(trim((string) $s), 0, 120), (array) $b['assignes']), static fn ($s) => $s !== ''))), 0, FC_PERSONNES_MAX);
        $set[] = 'assignes = ?'; $p[] = json_encode($l, JSON_UNESCAPED_UNICODE); $dit[] = 'qui la suit : ' . ($l ? implode(', ', $l) : 'personne de nommé');
    }
    if (array_key_exists('echeance', $b)) {
        $e = $b['echeance'] === null ? '' : trim((string) $b['echeance']);
        if ($e !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) { return fcRefus(422, 'échéance : AAAA-MM-JJ'); }
        $set[] = 'echeance = ?'; $p[] = $e !== '' ? $e : null; $dit[] = $e !== '' ? 'échéance ' . $e : 'sans échéance';
    }
    if (array_key_exists('note', $b)) { $n = trim(mb_substr((string) ($b['note'] ?? ''), 0, 500)); $set[] = 'adaptation_note = ?'; $p[] = $n !== '' ? $n : null; if ($n !== '') { $dit[] = '« ' . $n . ' »'; } }
    if ($set === []) { return fcRefus(422, 'rien à adapter'); }
    $qui = fcQui('siege', 0, isset($b['par']) ? (string) $b['par'] : null);
    $now = date('Y-m-d H:i:s');
    array_push($set, 'adapte_par = ?', 'adapte_le = ?', 'maj_le = ?'); array_push($p, $qui['nom'], $now, $now, (int) $d['id']);
    Db::exec('UPDATE for_fc_dossier SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
    fcTracer((string) $sop['id'], (int) $d['id'], (int) $d['shop_id'], 'adaptee', implode(' · ', $dit), $qui);
    $_GET['id'] = (string) $d['id'];
    return ['ok' => true, 'dossier' => ep_fc_dossier()];
}

/** POST /formation/continue/dossier/avis {photo, avis: conforme|a_refaire, commentaire?} — le consultant relit une photo. */
function wr_fc_avis(): array
{
    ensureFormationContinue();
    $b = body();
    $ph = Db::row('SELECT * FROM for_fc_photo WHERE id = ?', [(int) ($b['photo'] ?? 0)]);
    if ($ph === null) { return fcRefus(404, 'photo inconnue'); }
    $avis = (string) ($b['avis'] ?? '');
    if (!in_array($avis, ['conforme', 'a_refaire'], true)) { return fcRefus(400, 'avis : conforme ou a_refaire'); }
    $x = fcDossierEtSop((int) $ph['dossier_id']);
    if ($x === null) { return fcRefus(404, 'dossier inconnu'); }
    [$d, $sop] = $x;
    $com = trim(mb_substr((string) ($b['commentaire'] ?? ''), 0, 300));
    $qui = fcQui('siege', 0, isset($b['par']) ? (string) $b['par'] : null);
    Db::exec('UPDATE for_fc_photo SET avis = ?, avis_par = ?, avis_le = ?, commentaire = ? WHERE id = ?', [$avis, $qui['nom'], date('Y-m-d H:i:s'), $com !== '' ? $com : null, (int) $ph['id']]);
    fcTracer((string) $sop['id'], (int) $d['id'], (int) $d['shop_id'], 'avis', 'photo du ' . $ph['jour'] . ' : ' . ($avis === 'conforme' ? 'conforme' : 'à refaire') . ($com !== '' ? ' (« ' . $com . ' »)' : ''), $qui);
    $d = fcEvaluer($d, $sop, date('Y-m-d'));
    return ['ok' => true, 'dossier' => fcDossierVue($d, $sop, date('Y-m-d'), false)];
}

/**
 * POST /formation/continue/dossier/valider {id, mot?} — le consultant valide la formation POUR CETTE BOUTIQUE : son nom
 * et l'heure entrent au dossier et au journal ; le dossier est archivé dans le document de formation du franchisé
 * (coffre-fort, onglet Formation). On valide un dossier « à valider » ; un dossier en cours se valide aussi si le
 * consultant le décide (`forcer`), c'est alors dit dans la trace.
 */
function wr_fc_valider(): array
{
    ensureFormationContinue();
    $b = body();
    $x = fcDossierEtSop((int) ($b['id'] ?? 0));
    if ($x === null) { return fcRefus(404, 'dossier inconnu'); }
    [$d, $sop] = $x;
    if ($d['statut'] === 'validee') { return fcRefus(409, 'déjà validée le ' . substr((string) $d['valide_le'], 0, 16) . ' par ' . $d['valide_par']); }
    if ($d['statut'] !== 'a_valider' && empty($b['forcer'])) { return fcRefus(409, 'la formation n’est pas terminée : validez quand même avec « forcer »'); }
    $qui = fcQui('siege', 0, isset($b['par']) ? (string) $b['par'] : null);
    $mot = trim(mb_substr((string) ($b['mot'] ?? ''), 0, 500));
    $now = date('Y-m-d H:i:s');
    Db::exec("UPDATE for_fc_dossier SET statut = 'validee', valide_par = ?, valide_le = ?, valide_mot = ?, maj_le = ? WHERE id = ?", [$qui['nom'], $now, $mot !== '' ? $mot : null, $now, (int) $d['id']]);
    fcTracer((string) $sop['id'], (int) $d['id'], (int) $d['shop_id'], 'validee', 'validée pour ' . fcMagasinNom((int) $d['shop_id']) . ($d['statut'] !== 'a_valider' ? ' (avant la fin : décision du consultant)' : '') . ($mot !== '' ? ' — « ' . $mot . ' »' : ''), $qui);
    $d = Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [(int) $d['id']]) ?? $d;
    $arch = fcArchiver($d, $sop, $qui);
    return ['ok' => true, 'archive' => $arch, 'dossier' => fcDossierVue(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [(int) $d['id']]) ?? $d, $sop, date('Y-m-d'), false)];
}

/** POST /formation/continue/dossier/renvoyer {id, motif?} — le dossier repart « en cours » (la validation reste au journal). */
function wr_fc_renvoyer(): array
{
    ensureFormationContinue();
    $b = body();
    $x = fcDossierEtSop((int) ($b['id'] ?? 0));
    if ($x === null) { return fcRefus(404, 'dossier inconnu'); }
    [$d, $sop] = $x;
    $motif = trim(mb_substr((string) ($b['motif'] ?? ''), 0, 300));
    $qui = fcQui('siege', 0, isset($b['par']) ? (string) $b['par'] : null);
    Db::exec("UPDATE for_fc_dossier SET statut = 'en_cours', termine_le = NULL, valide_par = NULL, valide_le = NULL, valide_mot = NULL, maj_le = ? WHERE id = ?", [date('Y-m-d H:i:s'), (int) $d['id']]);
    fcTracer((string) $sop['id'], (int) $d['id'], (int) $d['shop_id'], 'renvoyee', 'renvoyée en cours' . ($motif !== '' ? ' — « ' . $motif . ' »' : ''), $qui);
    return ['ok' => true, 'dossier' => fcDossierVue(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [(int) $d['id']]) ?? $d, $sop, date('Y-m-d'), false)];
}

/* ═══ L'archive : le document de formation du franchisé, au coffre-fort ══════════════════════════════ */

/** Une photo en vignette (data URI) : réduite par GD à 360 px de large quand c'est possible. */
function fcVignette(string $chemin, string $mime): string
{
    $o = @file_get_contents($chemin);
    if ($o === false) { return ''; }
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($o)) !== false) {
        $w = imagesx($im); $h = imagesy($im);
        if ($w > 360) { $n = imagescale($im, 360, (int) round($h * 360 / $w)); if ($n !== false) { imagedestroy($im); $im = $n; } }
        ob_start(); imagejpeg($im, null, 72); $o = (string) ob_get_clean(); imagedestroy($im); $mime = 'image/jpeg';
    }
    return 'data:' . $mime . ';base64,' . base64_encode($o);
}

/** Le dossier des photos d'une boutique (privé : l'accès direct est fermé, elles sortent par l'API). */
function fcPhotosDossier(int $shop): string
{
    $racine = dirname(__DIR__, 2) . '/public/uploads/formation-continue';
    if (!is_dir($racine)) { @mkdir($racine, 0775, true); }
    if (!is_file($racine . '/.htaccess')) { @file_put_contents($racine . '/.htaccess', "# Les photos des formations continues ne se lisent que par l'API du cockpit.\nRequire all denied\n"); }
    $d = $racine . '/' . $shop;
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}

/** Le HTML de l'archive d'un dossier validé : la formation, la version suivie, l'équipe, les photos, qui et quand. */
function fcArchiveHtml(array $d, array $sop): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $dfr = static fn ($s) => $s ? date('d/m/Y', strtotime((string) $s)) : '—';
    $v = fcDossierVue($d, $sop, date('Y-m-d'), false);
    $ph = '';
    foreach (Db::rows('SELECT * FROM for_fc_photo WHERE dossier_id = ? ORDER BY jour, id', [(int) $d['id']]) as $p) {
        $uri = fcVignette(fcPhotosDossier((int) $d['shop_id']) . '/' . basename((string) $p['fichier']), (string) $p['mime']);
        $ph .= '<td style="width:14%;padding:3px;vertical-align:top"><img src="' . $uri . '" style="width:100%;border-radius:3px"><div style="font-size:8px;color:#7a736a">'
            . $e(date('d/m', strtotime((string) $p['jour']))) . ' · ' . $e($p['avis'] === 'conforme' ? 'conforme' : ($p['avis'] === 'a_refaire' ? 'à refaire' : 'pas relue')) . '</div></td>';
    }
    $lignes = $ph !== '' ? '<table style="width:100%;border-collapse:collapse"><tr>' . implode('</tr><tr>', array_map(static fn ($c) => implode('', $c), array_chunk(preg_split('/(?=<td )/', $ph, -1, PREG_SPLIT_NO_EMPTY), 7))) . '</tr></table>' : '<p style="color:#7a736a">Sans photo qualité (formation sans produit).</p>';
    $eq = $v['equipe'] ? implode(' · ', array_map(static fn ($p) => $e($p['personne']) . ' (' . ($p['lu'] ? 'lu' : 'pas lu') . ', ' . ($p['quiz'] ? 'quiz' : 'sans quiz') . ', ' . ($p['vu'] ? 'vu faire' : 'pas vu faire') . ')', $v['equipe'])) : 'aucune personne nommée';
    $entete = function_exists('docCss') ? '<style>' . docCss() . '</style>' : '';
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">' . $entete . '<title>' . $e($sop['code'] . ' · ' . $sop['titre_fr']) . '</title></head>'
        . '<body style="font-family:Gotham,Helvetica,Arial,sans-serif;font-size:11px;color:#1c1a17;margin:0;padding:26px 30px">'
        . '<div style="border-bottom:2px solid #8D1D2C;padding-bottom:8px;margin-bottom:12px"><div style="font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:#8D1D2C">Document de formation · archive de validation</div>'
        . '<div style="font-size:16px;margin-top:2px">' . $e(fcMagasinNom((int) $d['shop_id'])) . '</div></div>'
        . '<div style="font-size:14px;margin:0 0 4px">' . $e($sop['code'] . ' · ' . $sop['titre_fr']) . '</div>'
        . '<div style="color:#7a736a;margin-bottom:10px">' . $e(FC_CATEGORIES[(string) $sop['categorie']] ?? '') . ' · version ' . $e($sop['version']) . ($v['adaptation']['contenu'] || $v['adaptation']['note'] ? ', adaptée pour la boutique le ' . $e($dfr($v['adaptation']['le'])) . ' par ' . $e($v['adaptation']['par']) . ($v['adaptation']['note'] ? ' (« ' . $e($v['adaptation']['note']) . ' »)' : '') : '') . '</div>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:12px">'
        . '<tr><td style="width:150px;color:#7a736a;padding:3px 0">Validée</td><td>le ' . $e($dfr($d['valide_le'])) . ' à ' . $e(substr((string) $d['valide_le'], 11, 5)) . ' par ' . $e($d['valide_par']) . '</td></tr>'
        . ($d['valide_mot'] ? '<tr><td style="color:#7a736a;padding:3px 0">Mot du consultant</td><td>« ' . $e($d['valide_mot']) . ' »</td></tr>' : '')
        . '<tr><td style="color:#7a736a;padding:3px 0">Reçue</td><td>le ' . $e($dfr($d['diffuse_le'])) . ($d['echeance'] ? ' · à faire avant le ' . $e($dfr($d['echeance'])) : '') . '</td></tr>'
        . '<tr><td style="color:#7a736a;padding:3px 0">Équipe</td><td>' . $eq . '</td></tr>'
        . '<tr><td style="color:#7a736a;padding:3px 0">Mise en place</td><td>' . ($v['miseEnPlace'] ? 'déclarée le ' . $e($dfr($v['miseEnPlace']['le'])) . ' par ' . $e($v['miseEnPlace']['par']) : '—') . '</td></tr>'
        . '<tr><td style="color:#7a736a;padding:3px 0">Photos qualité</td><td>' . ($v['photos']['jours'] ? $e($v['photos']['conformes'] . ' conformes sur ' . $v['photos']['jours'] . ' demandées, du ' . $dfr($v['photos']['du']) . ' au ' . $dfr($v['photos']['fin'])) : 'non demandées') . '</td></tr>'
        . '</table>' . $lignes
        . '<div style="margin-top:14px;font-size:9px;color:#7a736a;border-top:1px solid #e6e0d8;padding-top:6px">L’Atelier By · Document de formation · ' . $e(fcMagasinNom((int) $d['shop_id'])) . ' · édité le ' . date('d/m/Y à H:i') . '</div>'
        . '</body></html>';
}

/**
 * À la validation : l'archive du dossier (PDF si le serveur en fait, sinon la page HTML) entre au coffre-fort du
 * franchisé, onglet Formation (consultant/src/coffre.php, cofDeposer) ; son id est gardé au dossier. Rend {id, titre} | null.
 */
function fcArchiver(array $d, array $sop, array $qui): ?array
{
    if (!function_exists('cofDeposer')) { return null; }
    try {
        $html = fcArchiveHtml($d, $sop);
        $pdf = function_exists('rapPdfRendu') ? rapPdfRendu($html, ['quoi' => 'Document de formation']) : null;
        $titre = $sop['code'] . ' · ' . $sop['titre_fr'] . ' — validée le ' . date('d/m/Y');
        $nom = strtolower((string) $sop['code']) . '-validation-' . date('Y-m-d') . ($pdf ? '.pdf' : '.html');
        $doc = cofDeposer((int) $d['shop_id'], 'formation', $titre, $nom, $pdf ?: $html, $qui, 'validée par ' . $qui['nom'] . ($d['valide_mot'] ? ' · « ' . $d['valide_mot'] . ' »' : ''));
        if ($doc === null) { return null; }
        Db::exec('UPDATE for_fc_dossier SET archive_doc = ? WHERE id = ?', [(int) $doc['id'], (int) $d['id']]);
        fcTracer((string) $sop['id'], (int) $d['id'], (int) $d['shop_id'], 'archivee', 'archivée au coffre-fort (onglet Formation) : ' . $titre, $qui);
        return ['id' => (int) $doc['id'], 'titre' => $titre];
    } catch (Throwable $e) {
        fcTracer((string) $sop['id'], (int) $d['id'], (int) $d['shop_id'], 'archive_ko', 'archive non rangée : ' . $e->getMessage(), $qui);
        return null;
    }
}

/* ═══ Le magasin : « Formations et suivi » ════════════════════════════════════════════════════════════ */

/** Les dossiers d'une boutique que le magasin voit (toutes ses formations diffusées). */
function fcDossiersMagasin(int $shop, string $auj): array
{
    $sops = fcSops();
    $l = [];
    foreach (Db::rows('SELECT * FROM for_fc_dossier WHERE shop_id = ?', [$shop]) as $d) {
        $sop = $sops[(string) $d['sop_id']] ?? null;
        if ($sop !== null && $sop['statut'] === 'publiee') { $l[] = fcDossierVue($d, $sop, $auj); }
    }
    $rang = static fn ($d) => $d['retard'] ? 0 : ($d['statut'] === 'en_cours' ? 1 : ($d['statut'] === 'a_valider' ? 2 : 3));
    usort($l, static fn ($a, $b) => [$rang($a), $b['diffuseLe']] <=> [$rang($b), $a['diffuseLe']]);
    return $l;
}

/** GET /formation/continue/magasin?shop= — la page « Formations et suivi » : Nouveautés, dossier, repères, équipe. */
function ep_fc_magasin(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if (!fcShopOk($shop)) { return fcRefus($shop > 0 ? 403 : 400, $shop > 0 ? 'ce magasin n’est pas sur votre session' : 'shop requis'); }
    ensureFormationContinue();
    $auj = date('Y-m-d');
    $doss = fcDossiersMagasin($shop, $auj);
    $lim = date('Y-m-d', strtotime($auj . ' -' . FC_NOUVEAUTES_JOURS . ' days'));
    $nouv = array_values(array_filter($doss, static fn ($d) => substr($d['diffuseLe'], 0, 10) >= $lim));
    usort($nouv, static fn ($a, $b) => strcmp($b['diffuseLe'], $a['diffuseLe']));
    $personnes = [];
    foreach ($doss as $d) { foreach ($d['equipe'] as $p) { $personnes[$p['personne']] = true; } }
    return ['shop' => (string) $shop, 'nom' => fcMagasinNom($shop),
        'nouveautes' => array_map(static fn ($d) => ['id' => $d['id'], 'code' => $d['code'], 'titre' => $d['titre'], 'categorie' => $d['categorie'], 'categorieLib' => $d['categorieLib'],
            'date' => substr($d['diffuseLe'], 0, 10), 'nouveau' => $d['nouveau'], 'lien' => '?shop=' . $shop . '&vue=formation&fc=' . $d['id']], array_slice($nouv, 0, 8)),
        'nouveautesTotal' => count($nouv), 'dossiers' => $doss,
        'reperes' => ['enRetard' => count(array_filter($doss, static fn ($d) => $d['retard'])),
            'enCours' => count(array_filter($doss, static fn ($d) => $d['statut'] === 'en_cours' && !$d['retard'])),
            'aValider' => count(array_filter($doss, static fn ($d) => $d['statut'] === 'a_valider')),
            'validees' => count(array_filter($doss, static fn ($d) => $d['statut'] === 'validee')),
            'photoDuJour' => count(array_filter($doss, static fn ($d) => $d['statut'] === 'en_cours' && in_array('a_faire', array_column($d['photos']['grille'], 'etat'), true)))],
        'equipe' => array_keys($personnes), 'categories' => FC_CATEGORIES];
}

/** Un dossier de CETTE boutique ; null sinon (même réponse dans les deux cas). */
function fcDossierMagasin(int $shop, int $id): ?array
{
    $x = fcDossierEtSop($id);
    if ($x === null || (int) $x[0]['shop_id'] !== $shop || $x[1]['statut'] !== 'publiee') { return null; }
    return $x;
}

/** GET /formation/continue/magasin/formation?shop=&id= — une formation du dossier, dans la version de la boutique. */
function ep_fc_magasin_formation(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if (!fcShopOk($shop)) { return fcRefus(403, 'ce magasin n’est pas sur votre session'); }
    ensureFormationContinue();
    $x = fcDossierMagasin($shop, (int) ($_GET['id'] ?? 0));
    if ($x === null) { return fcRefus(404, 'formation inconnue pour ce magasin'); }
    [$d, $sop] = $x;
    if (empty($d['ouvert_le'])) { Db::exec('UPDATE for_fc_dossier SET ouvert_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $d['id']]); $d['ouvert_le'] = date('Y-m-d H:i:s'); }
    $v = fcDossierVue($d, $sop, date('Y-m-d'));
    $v['contenu'] = fcContenuDossier($d, $sop);
    $v['journal'] = array_values(array_filter(fcTraces((string) $sop['id'], (int) $d['id']), static fn ($t) => $t['shop'] === (string) $shop));
    return $v;
}

/** POST /formation/continue/magasin/avancement {shop, id, personne, lu?, quiz?, vu?} — le franchisé déclare. */
function wr_fc_avancement(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    if (!fcShopOk($shop)) { return fcRefus(403, 'ce magasin n’est pas sur votre session'); }
    ensureFormationContinue();
    $x = fcDossierMagasin($shop, (int) ($b['id'] ?? 0));
    if ($x === null) { return fcRefus(404, 'formation inconnue pour ce magasin'); }
    [$d, $sop] = $x;
    if ($d['statut'] === 'validee') { return fcRefus(409, 'formation déjà validée'); }
    $p = mb_substr(trim((string) ($b['personne'] ?? '')), 0, 120);
    if ($p === '' || !in_array($p, fcAssignes($d), true)) {
        // Une personne pas encore assignée s'ajoute (le franchisé nomme son équipe ; le consultant peut l'adapter).
        if ($p === '') { return fcRefus(400, 'personne attendue'); }
        $l = fcAssignes($d); if (count($l) >= FC_PERSONNES_MAX) { return fcRefus(422, FC_PERSONNES_MAX . ' personnes au plus'); }
        $l[] = $p; Db::exec('UPDATE for_fc_dossier SET assignes = ? WHERE id = ?', [json_encode($l, JSON_UNESCAPED_UNICODE), (int) $d['id']]); $d['assignes'] = json_encode($l, JSON_UNESCAPED_UNICODE);
    }
    $r = Db::row('SELECT * FROM for_fc_equipe WHERE dossier_id = ? AND personne = ?', [(int) $d['id'], $p]);
    $val = static fn ($k) => array_key_exists($k, $b) ? (!empty($b[$k]) ? 1 : 0) : (int) ($r[$k] ?? 0);
    $now = date('Y-m-d H:i:s');
    if ($r === null) { Db::exec('INSERT INTO for_fc_equipe (dossier_id, personne, lu, quiz, vu, maj_le) VALUES (?,?,?,?,?,?)', [(int) $d['id'], $p, $val('lu'), $val('quiz'), $val('vu'), $now]); }
    else { Db::exec('UPDATE for_fc_equipe SET lu = ?, quiz = ?, vu = ?, maj_le = ? WHERE dossier_id = ? AND personne = ?', [$val('lu'), $val('quiz'), $val('vu'), $now, (int) $d['id'], $p]); }
    $qui = fcQui('franchise', $shop, isset($b['par']) ? (string) $b['par'] : null);
    $faits = implode(', ', array_filter([$val('lu') ? 'lu' : null, $val('quiz') ? 'quiz' : null, $val('vu') ? 'vu faire' : null]));
    fcTracer((string) $sop['id'], (int) $d['id'], $shop, 'equipe', $p . ' : ' . ($faits !== '' ? $faits : 'rien de fait'), $qui);
    $d = fcEvaluer(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [(int) $d['id']]) ?? $d, $sop, date('Y-m-d'));
    return ['ok' => true, 'dossier' => fcDossierVue($d, $sop, date('Y-m-d'), false)];
}

/** POST /formation/continue/magasin/mise-en-place {shop, id} — le franchisé déclare la mise en place en magasin. */
function wr_fc_mise_en_place(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    if (!fcShopOk($shop)) { return fcRefus(403, 'ce magasin n’est pas sur votre session'); }
    ensureFormationContinue();
    $x = fcDossierMagasin($shop, (int) ($b['id'] ?? 0));
    if ($x === null) { return fcRefus(404, 'formation inconnue pour ce magasin'); }
    [$d, $sop] = $x;
    $qui = fcQui('franchise', $shop, isset($b['par']) ? (string) $b['par'] : null);
    $annuler = !empty($b['annuler']);
    Db::exec('UPDATE for_fc_dossier SET mise_en_place_le = ?, mise_en_place_par = ?, maj_le = ? WHERE id = ?', [$annuler ? null : date('Y-m-d H:i:s'), $annuler ? null : $qui['nom'], date('Y-m-d H:i:s'), (int) $d['id']]);
    fcTracer((string) $sop['id'], (int) $d['id'], $shop, 'mise_en_place', $annuler ? 'mise en place retirée' : 'mise en place en magasin déclarée', $qui);
    $d = fcEvaluer(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [(int) $d['id']]) ?? $d, $sop, date('Y-m-d'));
    return ['ok' => true, 'dossier' => fcDossierVue($d, $sop, date('Y-m-d'), false)];
}

/**
 * POST /formation/continue/magasin/photo (multipart : shop, id, photo) — LA PHOTO DU JOUR : une par jour, photo
 * obligatoire de la photo qualité du produit ; une photo refusée se refait le même jour (elle remplace la précédente).
 */
function wr_fc_photo(): array
{
    $shop = (int) ($_POST['shop'] ?? 0);
    if ($shop <= 0) {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === []) { return fcRefus(413, 'photo trop lourde pour le serveur'); }
        return fcRefus(400, 'shop requis');
    }
    if (!fcShopOk($shop)) { return fcRefus(403, 'ce magasin n’est pas sur votre session'); }
    ensureFormationContinue();
    $x = fcDossierMagasin($shop, (int) ($_POST['id'] ?? 0));
    if ($x === null) { return fcRefus(404, 'formation inconnue pour ce magasin'); }
    [$d, $sop] = $x;
    if ((int) ($sop['photos_jours'] ?? 0) <= 0) { return fcRefus(409, 'cette formation ne demande pas de photo'); }
    if ($d['statut'] === 'validee') { return fcRefus(409, 'formation déjà validée'); }
    $f = $_FILES['photo'] ?? null;
    if (!is_array($f) || is_array($f['name'] ?? null)) { return fcRefus(422, 'une photo attendue'); }
    $ext = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) { return fcRefus(422, 'envoi interrompu, réessayez'); }
    if ((int) $f['size'] > FC_PHOTO_MAX) { return fcRefus(413, 'photo trop lourde : ' . (FC_PHOTO_MAX >> 20) . ' Mo au plus'); }
    if (!isset(FC_PHOTO_EXT[$ext])) { return fcRefus(422, 'une photo : jpg, png, webp ou heic'); }
    $r = fcGarderPhoto($d, (string) $f['tmp_name'], $ext, (int) $f['size'], fcQui('franchise', $shop, isset($_POST['par']) ? (string) $_POST['par'] : null), true);
    if (isset($r['error'])) { return $r; }
    fcTracer((string) $sop['id'], (int) $d['id'], $shop, 'photo', 'photo qualité du ' . date('d/m') . ' déposée', fcQui('franchise', $shop));
    return ['ok' => true, 'dossier' => fcDossierVue(Db::row('SELECT * FROM for_fc_dossier WHERE id = ?', [(int) $d['id']]) ?? $d, $sop, date('Y-m-d'), false)];
}

/** Range la photo du jour d'un dossier (remplace celle du jour si elle n'est pas conforme). Rend {id} ou {error}. */
function fcGarderPhoto(array $d, string $chemin, string $ext, int $octets, array $qui, bool $deplacer): array
{
    $jour = date('Y-m-d');
    $deja = Db::row('SELECT * FROM for_fc_photo WHERE dossier_id = ? AND jour = ? ORDER BY id DESC LIMIT 1', [(int) $d['id'], $jour]);
    if ($deja !== null && $deja['avis'] === 'conforme') { http_response_code(409); return ['error' => 'la photo d’aujourd’hui est déjà conforme']; }
    $nom = bin2hex(random_bytes(12)) . '.' . $ext;
    $cible = fcPhotosDossier((int) $d['shop_id']) . '/' . $nom;
    if (!($deplacer ? @move_uploaded_file($chemin, $cible) : @copy($chemin, $cible))) { http_response_code(500); return ['error' => 'le serveur n’a pas pu ranger la photo']; }
    @chmod($cible, 0640);
    if ($deja !== null) {
        @unlink(fcPhotosDossier((int) $d['shop_id']) . '/' . basename((string) $deja['fichier']));
        Db::exec('DELETE FROM for_fc_photo WHERE id = ?', [(int) $deja['id']]);
    }
    if (empty($d['photos_du'])) { Db::exec('UPDATE for_fc_dossier SET photos_du = ? WHERE id = ?', [$jour, (int) $d['id']]); }
    Db::exec('INSERT INTO for_fc_photo (dossier_id, jour, fichier, mime, octets, depose_le, depose_par) VALUES (?,?,?,?,?,?,?)',
        [(int) $d['id'], $jour, $nom, FC_PHOTO_EXT[$ext], $octets, date('Y-m-d H:i:s'), $qui['nom']]);
    return ['id' => (int) Db::pdo()->lastInsertId()];
}

/** Sort une photo (le fichier), puis exit ; 404 JSON sinon. */
function fcPhotoSortir(?array $p, int $shop): never
{
    $chemin = $p !== null ? fcPhotosDossier($shop) . '/' . basename((string) $p['fichier']) : '';
    if ($p === null || !is_file($chemin)) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'photo introuvable']); exit; }
    header('Content-Type: ' . $p['mime']);
    header('Content-Length: ' . filesize($chemin));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($chemin);
    exit;
}

/* ═══ Le Professeur : le dossier de formation d'une boutique ══════════════════════════════════════════ */

/** Le dossier d'une boutique, pour le Professeur : [{ref, code, titre, categorie, statut, adaptee, echeance, photos}]. */
function fcProfesseurDossier(int $shop): array
{
    ensureFormationContinue();
    return array_map(static fn ($d) => ['ref' => 'dossier-' . $d['id'], 'code' => $d['code'], 'titre' => $d['titre'], 'categorie' => $d['categorieLib'], 'statut' => $d['statutLib'],
        'adaptee' => $d['adapte'], 'echeance' => $d['echeance'], 'avancement' => $d['avancement']['pct'] . ' %',
        'photos' => $d['photos']['jours'] ? $d['photos']['conformes'] . '/' . $d['photos']['jours'] . ' conformes' : 'aucune'], fcDossiersMagasin($shop, date('Y-m-d')));
}

/** Une formation du dossier d'une boutique, en texte (sa version, adaptations comprises), pour le Professeur ; null sinon. */
function fcProfesseurLire(int $shop, string $ref): ?array
{
    ensureFormationContinue();
    $id = preg_match('/^dossier-(\d+)$/', $ref, $m) ? (int) $m[1] : 0;
    $x = fcDossierMagasin($shop, $id);
    if ($x === null) { return null; }
    [$d, $sop] = $x;
    $c = fcContenuDossier($d, $sop);
    $t = static fn ($v) => is_array($v) ? (string) (($v['fr'] ?? '') !== '' ? $v['fr'] : ($v['nl'] ?? '')) : (string) $v;
    $txt = [];
    foreach ($c['sections'] ?? [] as $s) {
        $txt[] = '## ' . $t($s['titre'] ?? '');
        foreach ($s['etapes'] ?? [] as $e) { $txt[] = 'Étape ' . ($e['numero'] ?? '') . ($e['titre'] ? ' — ' . $t($e['titre']) : '') . ' : ' . $t($e['texte'] ?? '') . (!empty($e['critique']) ? ' [point critique]' : ''); }
    }
    foreach ($c['points_critiques'] ?? [] as $p) { $txt[] = 'Point critique : ' . $t($p); }
    return ['ref' => $ref, 'code' => (string) $sop['code'], 'titre' => (string) $sop['titre_fr'], 'categorie' => FC_CATEGORIES[(string) $sop['categorie']] ?? '',
        'version' => (string) $sop['version'], 'adaptee' => !empty($d['contenu_adapte']),
        'adaptation' => !empty($d['contenu_adapte']) || !empty($d['adaptation_note']) ? 'adaptée pour cette boutique le ' . substr((string) $d['adapte_le'], 0, 10) . ' par ' . $d['adapte_par'] . (!empty($d['adaptation_note']) ? ' : ' . $d['adaptation_note'] : '') : null,
        'objectif' => $t($c['objectif'] ?? ''), 'texte' => implode("\n", $txt)];
}

/* ═══ Le routeur ══════════════════════════════════════════════════════════════════════════════════════ */

function fcRefus(int $code, string $msg): array
{
    http_response_code($code);
    return ['error' => $msg];
}

function fcRoute(string $method, string $path): ?array
{
    if ($path !== '/formation/continue' && !str_starts_with($path, '/formation/continue/')) { return null; }
    $m = [];
    // Le magasin.
    if ($path === '/formation/continue/magasin' && $method === 'GET') { return ep_fc_magasin(); }
    if ($path === '/formation/continue/magasin/formation' && $method === 'GET') { return ep_fc_magasin_formation(); }
    if ($path === '/formation/continue/magasin/avancement' && $method === 'POST') { return wr_fc_avancement(); }
    if ($path === '/formation/continue/magasin/mise-en-place' && $method === 'POST') { return wr_fc_mise_en_place(); }
    if ($path === '/formation/continue/magasin/photo' && $method === 'POST') { return wr_fc_photo(); }
    if ($path === '/formation/continue/magasin/photo' && $method === 'GET') {
        $shop = (int) ($_GET['shop'] ?? 0);
        ensureFormationContinue();
        $p = fcShopOk($shop) ? Db::row('SELECT p.* FROM for_fc_photo p JOIN for_fc_dossier d ON d.id = p.dossier_id WHERE p.id = ? AND d.shop_id = ?', [(int) ($_GET['id'] ?? 0), $shop]) : null;
        fcPhotoSortir($p, $shop);
    }
    // Le consultant.
    if ($path === '/formation/continue/franchises' && $method === 'GET') { return ep_fc_franchises(); }
    if ($path === '/formation/continue/franchise' && $method === 'GET') { return ep_fc_franchise(); }
    if ($path === '/formation/continue/dossier' && $method === 'GET') { return ep_fc_dossier(); }
    if ($path === '/formation/continue/dossier' && $method === 'PUT') { return wr_fc_adapter(); }
    if ($path === '/formation/continue/dossier/avis' && $method === 'POST') { return wr_fc_avis(); }
    if ($path === '/formation/continue/dossier/valider' && $method === 'POST') { return wr_fc_valider(); }
    if ($path === '/formation/continue/dossier/renvoyer' && $method === 'POST') { return wr_fc_renvoyer(); }
    if ($path === '/formation/continue/photo' && $method === 'GET') {
        ensureFormationContinue();
        $p = Db::row('SELECT p.*, d.shop_id FROM for_fc_photo p JOIN for_fc_dossier d ON d.id = p.dossier_id WHERE p.id = ?', [(int) ($_GET['id'] ?? 0)]);
        fcPhotoSortir($p, $p !== null ? (int) $p['shop_id'] : 0);
    }
    // Le siège.
    if ($path === '/formation/continue' && $method === 'GET') { return ep_fc_liste(); }
    if ($path === '/formation/continue' && $method === 'POST') { return wr_fc_creer(); }
    if (preg_match('#^/formation/continue/([a-z0-9-]{1,32})/diffuser$#', $path, $m) && $method === 'POST') { return wr_fc_diffuser($m[1]); }
    if (preg_match('#^/formation/continue/([a-z0-9-]{1,32})$#', $path, $m)) {
        if ($method === 'GET') { return ep_fc_fiche($m[1]); }
        if ($method === 'PATCH') { return wr_fc_regler($m[1]); }
    }
    return null;
}
