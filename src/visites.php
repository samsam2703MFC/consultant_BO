<?php
declare(strict_types=1);

/**
 * Visites terrain — l'application du consultant (PWA `visites/`).
 *
 * Trois acteurs, un seul serveur : le consultant planifie et fait la visite
 * (agenda, fiche, tableau de bord de la boutique, checklist, review, plan
 * d'action), le franchisé reçoit ses actions et envoie la photo de la
 * correction, l'admin (Sam) valide ou renvoie, escalade, et lit la synthèse.
 *
 * Ce que l'API existante fournit déjà, on le relit — jamais recopié :
 *   CA de la semaine et objectif   /exploitation/jour     (ERP, 🟢 API)
 *   note et avis Google            /reputation            (Google, 🟢 API)
 *   non-conformités du panel       /pwa/tasks/nc          (panel, 🟢 API)
 *   notifications                  push.php (pushDiffuser), Smtp
 * Ce qui n'existait pas vit ici : la visite, ses points, ses photos, le plan
 * d'action et ses transitions, le rapport mystery shopper, l'effectif.
 *
 * Toutes les écritures portent un `client_id` posé par l'appareil : la file
 * hors-ligne de l'application rejoue sans doublon.
 */

const VI_STATUTS_VISITE = ['planifiee', 'confirmee', 'en_cours', 'terminee', 'annulee'];
const VI_MOTIFS         = ['reguliere', 'asap', 'due', 'revisite'];
const VI_MODULES        = ['produit', 'hygiene', 'visuel', 'planogramme', 'assortiment', 'msp'];
const VI_PRIORITES      = ['P0', 'P1', 'P2'];
const VI_ASSIGNES       = ['franchise', 'equipe', 'consultant', 'admin'];
const VI_STATUTS_PLAN   = ['ouvert', 'attente', 'valide', 'reprendre', 'ferme', 'escalade'];
const VI_GENRES_PHOTO   = ['jour_facade', 'jour_interieur', 'jour_arriere', 'point', 'avant', 'apres', 'correction', 'msp'];
const VI_ROLES          = ['consultant', 'franchise', 'admin'];

/**
 * Ce que seule la présence sur place donne — et que la review recueille :
 * voir la réalité (l'énergie de l'équipe, l'exécution face au protocole, le
 * client qui sort satisfait ou non), nommer le vrai problème quand le chiffre
 * ou la qualité baisse, et une recommandation qui a du crédit parce qu'elle
 * a été vue, mesurée, touchée.
 */
const VI_EXECUTION = ['', 'standards', 'raccourcis', 'ecarts'];        // standards appliqués / quelques raccourcis / écarts fréquents
const VI_CLIENTS   = ['', 'satisfaits', 'mitiges', 'insatisfaits'];     // ce qu'on voit à la sortie
function viCausesDiag(): array
{
    return ['production' => 'Production mal synchronisée', 'equipe' => 'Équipe démotivée', 'decor' => 'Décor, ambiance qui n’invite pas',
        'prix' => 'Prix mal positionnés', 'appro' => 'Approvisionnement, ruptures', 'accueil' => 'Accueil, vente', 'hygiene' => 'Hygiène, propreté', 'autre' => 'Autre'];
}

/**
 * Qui peut faire passer un plan d'action d'un statut à l'autre. Le franchisé
 * ne fait qu'envoyer sa correction ; l'admin valide, renvoie, escalade, ferme ;
 * le consultant crée, confirme sur place (ferme), escalade — et peut valider
 * lui-même une correction vue en boutique.
 */
const VI_TRANSITIONS = [
    'franchise'  => ['ouvert' => ['attente'], 'reprendre' => ['attente']],
    'admin'      => ['ouvert' => ['escalade', 'ferme', 'attente'], 'attente' => ['valide', 'reprendre'],
                     'reprendre' => ['escalade', 'attente'], 'valide' => ['ferme'], 'escalade' => ['ouvert', 'ferme']],
    'consultant' => ['ouvert' => ['escalade', 'ferme', 'attente'], 'attente' => ['valide', 'reprendre'],
                     'reprendre' => ['escalade', 'attente'], 'valide' => ['ferme'], 'escalade' => ['ouvert', 'ferme']],
];

/* --- tables ------------------------------------------------------------------ */

function ensureVisites(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_visite (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(40) NULL,
        shop_id VARCHAR(32) NOT NULL,
        consultant_id VARCHAR(32) NOT NULL DEFAULT \'\',
        consultant_nom VARCHAR(120) NOT NULL DEFAULT \'\',
        prevu_le DATE NOT NULL,
        debut_h VARCHAR(5) NOT NULL DEFAULT \'09:00\',
        duree_min SMALLINT NOT NULL DEFAULT 90,
        motif VARCHAR(12) NOT NULL DEFAULT \'reguliere\',
        statut VARCHAR(12) NOT NULL DEFAULT \'planifiee\',
        commence_a DATETIME NULL,
        termine_a DATETIME NULL,
        sentiment TINYINT NULL,
        positif TEXT NULL,
        notes TEXT NULL,
        execution VARCHAR(12) NULL,
        clients VARCHAR(12) NULL,
        causes TEXT NULL,
        diagnostic TEXT NULL,
        reco TEXT NULL,
        cree_le DATETIME NOT NULL,
        maj_le DATETIME NOT NULL,
        UNIQUE KEY u_client (client_id),
        KEY k_shop (shop_id, prevu_le),
        KEY k_cons (consultant_id, prevu_le)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_visite_point (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visite_id INT NOT NULL,
        module VARCHAR(12) NOT NULL,
        point_ref VARCHAR(60) NOT NULL,
        libelle VARCHAR(190) NOT NULL DEFAULT \'\',
        etat VARCHAR(4) NOT NULL DEFAULT \'\',
        note TINYINT NULL,
        valeur SMALLINT NULL,
        commentaire TEXT NULL,
        causes TEXT NULL,
        maj_le DATETIME NOT NULL,
        UNIQUE KEY u_point (visite_id, point_ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_visite_photo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(40) NULL,
        visite_id INT NULL,
        shop_id VARCHAR(32) NOT NULL,
        plan_id INT NULL,
        point_ref VARCHAR(60) NULL,
        genre VARCHAR(20) NOT NULL DEFAULT \'point\',
        chemin VARCHAR(255) NOT NULL,
        prise_a DATETIME NOT NULL,
        lat DECIMAL(9,6) NULL,
        lng DECIMAL(9,6) NULL,
        largeur SMALLINT NULL,
        hauteur SMALLINT NULL,
        octets INT NULL,
        par VARCHAR(20) NOT NULL DEFAULT \'consultant\',
        UNIQUE KEY u_client (client_id),
        KEY k_visite (visite_id),
        KEY k_shop (shop_id, prise_a),
        KEY k_plan (plan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_visite_action (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(40) NULL,
        shop_id VARCHAR(32) NOT NULL,
        visite_id INT NULL,
        point_ref VARCHAR(60) NULL,
        titre VARCHAR(190) NOT NULL,
        detail TEXT NULL,
        priorite CHAR(2) NOT NULL DEFAULT \'P1\',
        assigne VARCHAR(12) NOT NULL DEFAULT \'franchise\',
        echeance DATE NULL,
        statut VARCHAR(12) NOT NULL DEFAULT \'ouvert\',
        retour TEXT NULL,
        escalade_motif TEXT NULL,
        photo_id INT NULL,
        cree_par VARCHAR(120) NOT NULL DEFAULT \'\',
        cree_le DATETIME NOT NULL,
        maj_le DATETIME NOT NULL,
        ferme_le DATETIME NULL,
        UNIQUE KEY u_client (client_id),
        KEY k_shop (shop_id, statut),
        KEY k_visite (visite_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_visite_action_evt (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        quand DATETIME NOT NULL,
        qui VARCHAR(120) NOT NULL DEFAULT \'\',
        de_statut VARCHAR(12) NULL,
        vers_statut VARCHAR(12) NOT NULL,
        commentaire TEXT NULL,
        photo_id INT NULL,
        KEY k_plan (plan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_msp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id VARCHAR(32) NOT NULL,
        mois CHAR(7) NOT NULL,
        total DECIMAL(5,1) NULL,
        rubriques TEXT NULL,
        commentaires TEXT NULL,
        fichier VARCHAR(255) NULL,
        par VARCHAR(120) NOT NULL DEFAULT \'\',
        le DATETIME NOT NULL,
        UNIQUE KEY u_mois (shop_id, mois)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_equipe_releve (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id VARCHAR(32) NOT NULL,
        releve_le DATE NOT NULL,
        effectif TINYINT NULL,
        prevu TINYINT NULL,
        departs TINYINT NULL,
        par VARCHAR(120) NOT NULL DEFAULT \'\',
        KEY k_shop (shop_id, releve_le)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Les colonnes de la review arrivées après la table : ajoutées si elles manquent.
    $manque = true;
    try { $manque = Db::row("SHOW COLUMNS FROM ceo_visite LIKE 'reco'") === null; } catch (Throwable $e) { /* pas MySQL : on tente l'ajout */ }
    if ($manque) {
        foreach (['execution VARCHAR(12) NULL', 'clients VARCHAR(12) NULL', 'causes TEXT NULL', 'diagnostic TEXT NULL', 'reco TEXT NULL'] as $col) {
            try { Db::exec('ALTER TABLE ceo_visite ADD COLUMN ' . $col); } catch (Throwable $e) { /* déjà là */ }
        }
    }
    // Le jeton de l'horloge : lu en base par bin/visites_cron.sh à chaque appel.
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
        ['visitesJeton', json_encode(bin2hex(random_bytes(24)))]);
}

function viSettingSet(string $cle, mixed $valeur): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$cle, json_encode($valeur, JSON_UNESCAPED_UNICODE)]);
}

/* --- référentiels ------------------------------------------------------------ */

/** La checklist par défaut : cinq modules, des points nommés, une cible. */
function viChecklistDefaut(): array
{
    return [
        ['id' => 'produit', 'nom' => 'Produit', 'points' => [
            ['ref' => 'pain', 'libelle' => 'Pain blanc — tête, croûte, cuisson', 'photo' => true],
            ['ref' => 'viennoiserie', 'libelle' => 'Croissants — feuilletage, température', 'photo' => true],
            ['ref' => 'sables', 'libelle' => 'Sablés et biscuits — fraîcheur', 'photo' => false],
            ['ref' => 'tartes', 'libelle' => 'Tartes — aspect, fraîcheur', 'photo' => true],
            ['ref' => 'sandwiches', 'libelle' => 'Sandwiches — garniture, fraîcheur', 'photo' => false]]],
        ['id' => 'hygiene', 'nom' => 'Hygiène', 'points' => [
            ['ref' => 'sol', 'libelle' => 'Sol zone production propre', 'photo' => false],
            ['ref' => 'frigo', 'libelle' => 'Frigo vitrine ≤ 4 °C (photo du thermomètre)', 'photo' => true],
            ['ref' => 'arriere', 'libelle' => 'Zone arrière et réserve', 'photo' => true],
            ['ref' => 'sanitaires', 'libelle' => 'Sanitaires, lave-mains, savon', 'photo' => false],
            ['ref' => 'tenues', 'libelle' => 'Tenues, cheveux, gants', 'photo' => false]]],
        ['id' => 'visuel', 'nom' => 'Visuel', 'points' => [
            ['ref' => 'facade', 'libelle' => 'Façade, enseigne, vitrine', 'photo' => true],
            ['ref' => 'interieur', 'libelle' => 'Intérieur, propreté visible, éclairage', 'photo' => true],
            ['ref' => 'prix', 'libelle' => 'Affichage des prix complet', 'photo' => false],
            ['ref' => 'plv', 'libelle' => 'PLV et promotions du moment en place', 'photo' => false]]],
        ['id' => 'planogramme', 'nom' => 'Planogramme', 'points' => [
            ['ref' => 'comptoir_pains', 'libelle' => 'Comptoir pains', 'photo' => true, 'pct' => true],
            ['ref' => 'comptoir_viennoiserie', 'libelle' => 'Comptoir viennoiserie', 'photo' => true, 'pct' => true],
            ['ref' => 'comptoir_tartes', 'libelle' => 'Comptoir tartes et sablés', 'photo' => true, 'pct' => true]]],
        // L'assortiment se contrôle après le planogramme : le comptoir dit où
        // chaque référence va, l'assortiment dit lesquelles doivent y être. Ce
        // que le cockpit sait déjà (obligatoires sans place, obligatoires
        // jamais passées en caisse) s'affiche au-dessus du module ; ces points
        // enregistrent ce que seul l'oeil sur place peut constater.
        ['id' => 'assortiment', 'nom' => 'Assortiment', 'points' => [
            ['ref' => 'obligatoires', 'libelle' => 'Références obligatoires toutes présentes en rayon', 'photo' => true],
            ['ref' => 'ruptures', 'libelle' => 'Ruptures du jour — cause connue, parade en place', 'photo' => false],
            ['ref' => 'etiquettes', 'libelle' => 'Étiquettes et prix conformes au référentiel', 'photo' => true]]],
        ['id' => 'msp', 'nom' => 'Mystery shopper', 'points' => [
            ['ref' => 'msp_hygiene', 'libelle' => 'Hygiène : ce que le rapport a relevé est corrigé', 'photo' => false],
            ['ref' => 'msp_accueil', 'libelle' => 'Accueil : conforme au rapport', 'photo' => false],
            ['ref' => 'msp_ambiance', 'libelle' => 'Ambiance : bruit, musique, propreté visible', 'photo' => false]]],
    ];
}

function viCausesPlano(): array
{
    return ['deplace' => 'Produits déplacés', 'vide' => 'Emplacements vides', 'surstock' => 'Surstock', 'promo' => 'Promo en cours', 'rupture' => 'Rupture'];
}

function viChecklist(): array
{
    $c = setting('visitesChecklist', null);
    return is_array($c) && $c ? $c : viChecklistDefaut();
}

function viSeuilsDefaut(): array
{
    return [
        'p0Jours' => 3,        // un P0 ouvert plus longtemps : rouge
        'escaladeJours' => 2,  // au-delà de l'échéance : escalade automatique
        'googleCible' => 4.5,  // sous la cible : orange ; sous cible − googleRouge : rouge
        'googleRouge' => 0.5,
        'planoOrange' => 80,   // conformité planogramme en %
        'planoRouge' => 60,
        'caOrange' => 10,      // CA de la semaine sous l'objectif de x % : orange…
        'caSeul' => false,     // …seulement si ce réglage est vrai (sinon le CA colore la carte)
        'mspAlerte' => 12,     // une rubrique MSP sous ce score /20 : rouge
        'visiteJours' => 7,    // fréquence de visite par défaut
        'rappelJ1' => '18:00',
        'rappelJour' => '07:00',
        'syntheseHeure' => '07:00',
        'mails' => false,      // les mails (rappels, synthèse) ne partent que si vrai
        'mailSynthese' => '',
    ];
}

function viSeuils(): array
{
    $s = setting('visitesSeuils', []);
    return array_merge(viSeuilsDefaut(), is_array($s) ? $s : []);
}

/** La fréquence de visite par boutique (jours), sinon le défaut. */
function viFrequence(string $shop): int
{
    $f = setting('visitesFrequence', []);
    $v = is_array($f) ? (int) ($f[$shop] ?? 0) : 0;
    return $v > 0 ? $v : (int) viSeuils()['visiteJours'];
}

/* --- lectures de l'existant --------------------------------------------------- */

function viMagasins(): array
{
    static $m = null;
    if ($m !== null) { return $m; }
    $m = [];
    foreach (ep_stores() as $s) {
        if (($s['status'] ?? '') !== 'Ouvert') { continue; }
        $nom = (string) $s['nom'];
        $court = str_contains($nom, ' - ') ? trim(substr($nom, strrpos($nom, ' - ') + 3)) : $nom;
        $m[(string) $s['id']] = ['id' => (string) $s['id'], 'nom' => $nom, 'court' => $court, 'ville' => (string) ($s['zone'] ?? ''), 'fr' => (string) ($s['fr'] ?? '')];
    }
    return $m;
}

function viConsultants(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $c = [];
    try {
        foreach (ep_consultants() as $x) { $c[] = ['id' => (string) $x['id'], 'nom' => (string) $x['nom'], 'email' => $x['email'] ?? null]; }
    } catch (Throwable $e) { $c = []; }
    return $c;
}

function viConsultantNom(string $id): string
{
    foreach (viConsultants() as $c) { if ($c['id'] === $id) { return $c['nom']; } }
    return $id;
}

/** CA de la semaine en cours, par boutique : somme des jours passés et du jour. */
function viCa(): array
{
    static $ca = null;
    if ($ca !== null) { return $ca; }
    $ca = [];
    try {
        $j = ep_exploitation_jour();
        foreach ($j['magasins'] ?? [] as $m) {
            $tot = 0.0; $obj = 0.0; $jours = 0; $serie = [];
            foreach ($m['semaine'] ?? [] as $d) {
                $vu = !empty($d['passe']) || !empty($d['aujourdhui']);
                $serie[] = ['date' => $d['date'], 'ca' => $d['ca'] ?? null, 'objectif' => $d['objectif'] ?? null, 'vu' => $vu, 'ouvert' => $d['ouvert'] ?? true];
                if ($vu && !empty($d['ouvert'])) { $tot += (float) ($d['ca'] ?? 0); $obj += (float) ($d['objectif'] ?? 0); $jours++; }
            }
            $ca[(string) $m['shopId']] = ['ca' => round($tot), 'objectif' => round($obj), 'jours' => $jours,
                'pct' => $obj > 0 ? round(($tot - $obj) / $obj * 100) : null,
                'caDelta' => $m['caDelta'] ?? null, 'serie' => $serie, 'source' => 'api'];
        }
    } catch (Throwable $e) { /* l'ERP est absent : les cartes le disent */ }
    return $ca;
}

function viGoogle(): array
{
    static $g = null;
    if ($g !== null) { return $g; }
    $g = [];
    try {
        $r = ep_reputation();
        $limite = date('Y-m-d', strtotime('-30 days'));
        foreach ($r['magasins'] ?? [] as $m) {
            $faibles = 0; $derniers = [];
            foreach ($m['derniers'] ?? [] as $d) {
                if ((int) ($d['note'] ?? 5) <= 2 && (string) ($d['le'] ?? '') >= $limite) { $faibles++; }
                // Le texte Google n'est pas recopié : la note, la date, un extrait court.
                $derniers[] = ['note' => $d['note'] ?? null, 'le' => $d['le'] ?? null, 'extrait' => mb_substr((string) ($d['texte'] ?? ''), 0, 90)];
            }
            $g[(string) $m['id']] = ['note' => $m['note'] ?? null, 'avis' => $m['avis'] ?? null, 'faibles' => $faibles,
                'derniers' => array_slice($derniers, 0, 5), 'synchro' => $m['synchro'] ?? null, 'cible' => $r['cible'] ?? 4.5, 'source' => 'api'];
        }
    } catch (Throwable $e) { /* pas de réputation : rien */ }
    return $g;
}

/** Les non-conformités du panel sur 30 jours — un appel au panel, à la demande. */
function viNc(string $shop): array
{
    $sauve = $_GET;
    $_GET = ['shop' => $shop, 'du' => date('Y-m-d', strtotime('-30 days')), 'au' => date('Y-m-d')];
    try {
        $r = ep_pwa_tasks_nc();
    } catch (Throwable $e) {
        $r = ['indispo' => true];
    } finally { $_GET = $sauve; }
    if (!empty($r['indispo']) || !isset($r['nc'])) { return ['indispo' => true, 'ouvertes' => null, 'total' => null, 'liste' => []]; }
    $ouvertes = 0; $liste = [];
    foreach ($r['nc'] as $n) {
        $suite = $n['suite'] ?? null;
        $corrigee = is_array($suite) && !empty($suite['conforme']);
        if (!$corrigee) { $ouvertes++; }
        $liste[] = ['tache' => $n['tache'] ?? '', 'jour' => $n['jour'] ?? null, 'note' => $n['note'] ?? null,
            'recidive' => $n['recidive'] ?? null, 'corrigee' => $corrigee, 'commentaire' => mb_substr((string) ($n['comment'] ?? ''), 0, 120)];
    }
    return ['indispo' => false, 'ouvertes' => $ouvertes, 'total' => count($r['nc']), 'seuil' => $r['seuil'] ?? 4,
        'liste' => array_slice(array_reverse($liste), 0, 6), 'source' => 'api'];
}

/* --- lectures locales --------------------------------------------------------- */

function viVisiteLigne(array $v): array
{
    return ['id' => (int) $v['id'], 'client_id' => $v['client_id'], 'shop' => (string) $v['shop_id'],
        'consultant' => $v['consultant_id'], 'consultantNom' => $v['consultant_nom'],
        'prevu_le' => $v['prevu_le'], 'debut_h' => $v['debut_h'], 'duree_min' => (int) $v['duree_min'],
        'motif' => $v['motif'], 'statut' => $v['statut'], 'commence_a' => $v['commence_a'], 'termine_a' => $v['termine_a'],
        'sentiment' => $v['sentiment'] !== null ? (int) $v['sentiment'] : null, 'positif' => $v['positif'], 'notes' => $v['notes'],
        'execution' => $v['execution'] ?? null, 'clients' => $v['clients'] ?? null,
        'causes' => !empty($v['causes']) ? (json_decode((string) $v['causes'], true) ?: []) : [],
        'diagnostic' => $v['diagnostic'] ?? null, 'reco' => $v['reco'] ?? null,
        'maj_le' => $v['maj_le']];
}

function viPointLigne(array $p): array
{
    return ['visite_id' => (int) $p['visite_id'], 'module' => $p['module'], 'ref' => $p['point_ref'], 'libelle' => $p['libelle'],
        'etat' => $p['etat'], 'note' => $p['note'] !== null ? (int) $p['note'] : null, 'valeur' => $p['valeur'] !== null ? (int) $p['valeur'] : null,
        'commentaire' => $p['commentaire'], 'causes' => $p['causes'] ? (json_decode((string) $p['causes'], true) ?: []) : [], 'maj_le' => $p['maj_le']];
}

function viPhotoLigne(array $p): array
{
    return ['id' => (int) $p['id'], 'client_id' => $p['client_id'], 'visite_id' => $p['visite_id'] !== null ? (int) $p['visite_id'] : null,
        'shop' => (string) $p['shop_id'], 'plan_id' => $p['plan_id'] !== null ? (int) $p['plan_id'] : null, 'ref' => $p['point_ref'],
        'genre' => $p['genre'], 'chemin' => $p['chemin'], 'prise_a' => $p['prise_a'], 'par' => $p['par']];
}

function viPlanLigne(array $p): array
{
    $auj = date('Y-m-d');
    $ouvert = in_array($p['statut'], ['ouvert', 'reprendre', 'escalade'], true);
    $retard = $ouvert && $p['echeance'] !== null && $p['echeance'] < $auj
        ? (int) ((strtotime($auj) - strtotime((string) $p['echeance'])) / 86400) : 0;
    return ['id' => (int) $p['id'], 'client_id' => $p['client_id'], 'shop' => (string) $p['shop_id'],
        'visite_id' => $p['visite_id'] !== null ? (int) $p['visite_id'] : null, 'ref' => $p['point_ref'],
        'titre' => $p['titre'], 'detail' => $p['detail'], 'priorite' => $p['priorite'], 'assigne' => $p['assigne'],
        'echeance' => $p['echeance'], 'statut' => $p['statut'], 'retour' => $p['retour'], 'escalade_motif' => $p['escalade_motif'],
        'photo_id' => $p['photo_id'] !== null ? (int) $p['photo_id'] : null, 'cree_par' => $p['cree_par'], 'cree_le' => $p['cree_le'],
        'maj_le' => $p['maj_le'], 'ferme_le' => $p['ferme_le'], 'retard' => $retard,
        'age' => (int) ((time() - strtotime((string) $p['cree_le'])) / 86400)];
}

function viPlans(?string $shop, int $joursFermes = 90): array
{
    $limite = date('Y-m-d H:i:s', strtotime("-{$joursFermes} days"));
    $sql = 'SELECT * FROM ceo_visite_action WHERE (statut <> \'ferme\' OR maj_le >= ?)';
    $args = [$limite];
    if ($shop !== null) { $sql .= ' AND shop_id = ?'; $args[] = $shop; }
    $sql .= ' ORDER BY FIELD(priorite, \'P0\', \'P1\', \'P2\'), echeance, id';
    return array_map('viPlanLigne', Db::rows($sql, $args));
}

function viMsp(?string $shop, int $n = 3): array
{
    $sql = 'SELECT * FROM ceo_msp' . ($shop !== null ? ' WHERE shop_id = ?' : '') . ' ORDER BY mois DESC';
    $out = [];
    foreach (Db::rows($sql, $shop !== null ? [$shop] : []) as $r) {
        $s = (string) $r['shop_id'];
        if (count($out[$s] ?? []) >= $n) { continue; }
        $out[$s][] = ['id' => (int) $r['id'], 'shop' => $s, 'mois' => $r['mois'], 'total' => $r['total'] !== null ? (float) $r['total'] : null,
            'rubriques' => $r['rubriques'] ? (json_decode((string) $r['rubriques'], true) ?: []) : [],
            'commentaires' => $r['commentaires'], 'fichier' => $r['fichier'], 'par' => $r['par'], 'le' => $r['le']];
    }
    return $out;
}

function viEquipe(?string $shop): array
{
    $sql = 'SELECT * FROM ceo_equipe_releve' . ($shop !== null ? ' WHERE shop_id = ?' : '') . ' ORDER BY releve_le DESC, id DESC';
    $out = [];
    foreach (Db::rows($sql, $shop !== null ? [$shop] : []) as $r) {
        $s = (string) $r['shop_id'];
        if (isset($out[$s])) { continue; }
        $out[$s] = ['effectif' => $r['effectif'] !== null ? (int) $r['effectif'] : null, 'prevu' => $r['prevu'] !== null ? (int) $r['prevu'] : null,
            'departs' => $r['departs'] !== null ? (int) $r['departs'] : null, 'releve_le' => $r['releve_le'], 'par' => $r['par'], 'source' => 'local'];
    }
    return $out;
}

/** Conformité planogramme de la dernière visite terminée : moyenne des % saisis. */
function viPlano(string $shop): ?array
{
    $v = Db::row('SELECT id, prevu_le FROM ceo_visite WHERE shop_id = ? AND statut = \'terminee\' ORDER BY prevu_le DESC, id DESC LIMIT 1', [$shop]);
    if ($v === null) { return null; }
    $pts = Db::rows('SELECT valeur, causes FROM ceo_visite_point WHERE visite_id = ? AND module = \'planogramme\' AND valeur IS NOT NULL', [(int) $v['id']]);
    if (!$pts) { return null; }
    $sum = 0; $ruptures = 0;
    foreach ($pts as $p) { $sum += (int) $p['valeur']; if (str_contains((string) $p['causes'], 'rupture')) { $ruptures++; } }
    return ['pct' => (int) round($sum / count($pts)), 'ruptures' => $ruptures, 'le' => $v['prevu_le'], 'source' => 'local'];
}

/** Le feu de la boutique, et pourquoi — la première règle qui s'applique gagne. */
function viFeu(string $shop, array $ca, array $google, array $plans, ?array $plano, array $msp, ?string $derniereVisite, ?string $prochaine): array
{
    $s = viSeuils();
    $rouge = []; $orange = [];
    $auj = date('Y-m-d');
    foreach ($plans as $p) {
        if ($p['shop'] !== $shop || !in_array($p['statut'], ['ouvert', 'reprendre', 'escalade'], true)) { continue; }
        if ($p['priorite'] === 'P0' && $p['statut'] === 'escalade') { $rouge[] = 'P0 escaladé : ' . $p['titre']; }
        elseif ($p['priorite'] === 'P0' && $p['age'] > (int) $s['p0Jours']) { $rouge[] = 'P0 ouvert depuis ' . $p['age'] . ' j : ' . $p['titre']; }
        elseif ($p['priorite'] === 'P0') { $orange[] = 'P0 ouvert : ' . $p['titre']; }
        elseif ($p['priorite'] === 'P1') { $orange[] = 'P1 ouvert : ' . $p['titre']; }
    }
    $g = $google[$shop] ?? null;
    if ($g && $g['note'] !== null) {
        $cible = (float) ($s['googleCible'] ?? 4.5);
        if ((float) $g['note'] < $cible - (float) $s['googleRouge']) { $rouge[] = 'Google ' . number_format((float) $g['note'], 1, ',', '') . ' sous ' . number_format($cible - (float) $s['googleRouge'], 1, ',', ''); }
        elseif ((float) $g['note'] < $cible) { $orange[] = 'Google ' . number_format((float) $g['note'], 1, ',', '') . ' sous la cible ' . number_format($cible, 1, ',', ''); }
        if ($g['faibles'] >= 2) { $orange[] = $g['faibles'] . ' avis ≤ 2/5 sur 30 jours'; }
    }
    if ($plano) {
        if ($plano['pct'] < (int) $s['planoRouge']) { $rouge[] = 'Planogramme ' . $plano['pct'] . ' %'; }
        elseif ($plano['pct'] < (int) $s['planoOrange']) { $orange[] = 'Planogramme ' . $plano['pct'] . ' %'; }
    }
    $m = $msp[$shop][0] ?? null;
    if ($m) {
        foreach ($m['rubriques'] as $nom => $val) {
            if (is_numeric($val) && (float) $val < (float) $s['mspAlerte']) { $rouge[] = 'MSP ' . $nom . ' ' . $val . '/20'; }
        }
    }
    $c = $ca[$shop] ?? null;
    if ($c && $c['pct'] !== null && $c['pct'] <= -(int) $s['caOrange'] && !empty($s['caSeul'])) { $orange[] = 'CA ' . $c['pct'] . ' % vs objectif'; }
    $due = null;
    if ($prochaine === null) {
        $freq = viFrequence($shop);
        $ref = $derniereVisite ?? null;
        if ($ref === null || strtotime($ref) < strtotime("-{$freq} days")) {
            $due = $ref === null ? 'jamais visitée' : 'visite due depuis ' . (int) ((time() - strtotime($ref)) / 86400 - $freq) . ' j';
            $orange[] = $due;
        }
    }
    $feu = $rouge ? 'rouge' : ($orange ? 'orange' : 'vert');
    return ['feu' => $feu, 'motifs' => array_values(array_unique(array_merge($rouge, $orange))), 'due' => $due];
}

/* --- l'application ------------------------------------------------------------ */

function viRole(): array
{
    $role = (string) ($_GET['role'] ?? '');
    $shop = trim((string) ($_GET['shop'] ?? ''));
    if ($shop !== '' && $role === '') { $role = 'franchise'; }
    if (!in_array($role, VI_ROLES, true)) { $role = 'consultant'; }
    return [$role, $shop !== '' ? $shop : null, trim((string) ($_GET['id'] ?? ''))];
}

/**
 * GET /visites/app — tout ce que l'application affiche, en une lecture : les
 * boutiques et leur feu, les visites de la fenêtre (90 jours en arrière, 21
 * devant), les plans d'action, les photos, la checklist, les seuils. Un seul
 * appel = une seule chose à garder hors-ligne.
 */
function ep_visites_app(): array
{
    ensureVisites();
    [$role, $shop, $id] = viRole();
    $mags = viMagasins();
    if ($shop !== null && !isset($mags[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $ca = viCa(); $google = viGoogle();
    $plans = viPlans($shop);
    $msp = viMsp($shop); $equipe = viEquipe($shop);
    $du = date('Y-m-d', strtotime('-90 days')); $au = date('Y-m-d', strtotime('+21 days'));
    $sql = 'SELECT * FROM ceo_visite WHERE prevu_le BETWEEN ? AND ?' . ($shop !== null ? ' AND shop_id = ?' : '') . ' ORDER BY prevu_le, debut_h';
    $visites = array_map('viVisiteLigne', Db::rows($sql, $shop !== null ? [$du, $au, $shop] : [$du, $au]));
    $ids = array_map(fn ($v) => $v['id'], $visites);
    $points = []; $photos = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $points = array_map('viPointLigne', Db::rows("SELECT * FROM ceo_visite_point WHERE visite_id IN ($in)", $ids));
    }
    $sqlP = 'SELECT * FROM ceo_visite_photo WHERE prise_a >= ?' . ($shop !== null ? ' AND shop_id = ?' : '') . ' ORDER BY prise_a DESC';
    $photos = array_map('viPhotoLigne', Db::rows($sqlP, $shop !== null ? [$du . ' 00:00:00', $shop] : [$du . ' 00:00:00']));
    $auj = date('Y-m-d');
    $boutiques = [];
    foreach ($mags as $m) {
        // La clé du tableau redevient un entier en PHP : la boutique porte son identifiant en chaîne.
        $sid = (string) $m['id'];
        if ($shop !== null && $sid !== $shop) { continue; }
        $derniere = null; $prochaine = null; $enCours = null;
        foreach ($visites as $v) {
            if ($v['shop'] !== $sid) { continue; }
            if ($v['statut'] === 'terminee' && ($derniere === null || $v['prevu_le'] > $derniere['prevu_le'])) { $derniere = $v; }
            if (in_array($v['statut'], ['planifiee', 'confirmee'], true) && $v['prevu_le'] >= $auj && ($prochaine === null || $v['prevu_le'] < $prochaine['prevu_le'])) { $prochaine = $v; }
            if ($v['statut'] === 'en_cours') { $enCours = $v; }
        }
        $plano = viPlano($sid);
        $feu = viFeu($sid, $ca, $google, $plans, $plano, $msp, $derniere['prevu_le'] ?? null, $prochaine['prevu_le'] ?? null);
        $ouverts = array_values(array_filter($plans, fn ($p) => $p['shop'] === $sid && in_array($p['statut'], ['ouvert', 'reprendre', 'escalade', 'attente'], true)));
        $boutiques[] = array_merge($m, [
            'feu' => $feu['feu'], 'motifs' => $feu['motifs'], 'due' => $feu['due'],
            'ca' => $ca[$sid] ?? null, 'google' => $google[$sid] ?? null, 'plano' => $plano,
            'equipe' => $equipe[$sid] ?? null, 'msp' => $msp[$sid][0] ?? null,
            'plansOuverts' => count($ouverts), 'p0' => count(array_filter($ouverts, fn ($p) => $p['priorite'] === 'P0')),
            'derniereVisite' => $derniere ? ['id' => $derniere['id'], 'le' => $derniere['prevu_le'], 'consultant' => $derniere['consultantNom'],
                'causes' => $derniere['causes'], 'reco' => $derniere['reco'], 'diagnostic' => $derniere['diagnostic'], 'execution' => $derniere['execution'], 'clients' => $derniere['clients'], 'positif' => $derniere['positif']] : null,
            'prochaineVisite' => $prochaine ? ['id' => $prochaine['id'], 'le' => $prochaine['prevu_le'], 'h' => $prochaine['debut_h'], 'consultant' => $prochaine['consultantNom']] : null,
            'visiteEnCours' => $enCours ? $enCours['id'] : null, 'frequence' => viFrequence($sid),
        ]);
    }
    return ['role' => $role, 'id' => $id, 'shop' => $shop, 'maintenant' => date('Y-m-d H:i'),
        'boutiques' => $boutiques, 'consultants' => viConsultants(),
        'visites' => $visites, 'points' => $points, 'photos' => $photos, 'plans' => $plans,
        'msp' => $msp, 'equipe' => $equipe, 'checklist' => viChecklist(), 'causes' => viCausesPlano(), 'causesDiag' => viCausesDiag(), 'seuils' => viSeuils(),
        'frequence' => setting('visitesFrequence', []) ?: (object) [],
        'reseau' => viReseau($boutiques)];
}

/** Les moyennes du réseau, pour situer une boutique. */
function viReseau(array $boutiques): array
{
    $ca = 0; $obj = 0; $notes = []; $planos = [];
    foreach ($boutiques as $b) {
        if ($b['ca']) { $ca += $b['ca']['ca']; $obj += $b['ca']['objectif']; }
        if ($b['google'] && $b['google']['note'] !== null) { $notes[] = (float) $b['google']['note']; }
        if ($b['plano']) { $planos[] = $b['plano']['pct']; }
    }
    return ['caPct' => $obj > 0 ? round(($ca - $obj) / $obj * 100) : null,
        'google' => $notes ? round(array_sum($notes) / count($notes), 2) : null,
        'plano' => $planos ? (int) round(array_sum($planos) / count($planos)) : null, 'boutiques' => count($boutiques)];
}

/**
 * GET /visites/boutique/{shop} — ce qui coûte un appel au panel (les
 * non-conformités) et l'historique de trois mois : lu à l'ouverture du tableau
 * de bord, pas dans la lecture de départ.
 */
function ep_visites_boutique(string $shop): array
{
    ensureVisites();
    $mags = viMagasins();
    if (!isset($mags[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $du = date('Y-m-d', strtotime('-92 days'));
    $visites = array_map('viVisiteLigne', Db::rows('SELECT * FROM ceo_visite WHERE shop_id = ? AND prevu_le >= ? AND statut = \'terminee\' ORDER BY prevu_le DESC', [$shop, $du]));
    $parMois = [];
    foreach (Db::rows('SELECT DATE_FORMAT(cree_le, \'%Y-%m\') mois, statut, COUNT(*) n FROM ceo_visite_action WHERE shop_id = ? AND cree_le >= ? GROUP BY mois, statut', [$shop, $du . ' 00:00:00']) as $r) {
        $parMois[$r['mois']][$r['statut']] = (int) $r['n'];
    }
    $planoParVisite = [];
    foreach ($visites as $v) {
        $pts = Db::rows('SELECT valeur FROM ceo_visite_point WHERE visite_id = ? AND module = \'planogramme\' AND valeur IS NOT NULL', [$v['id']]);
        if ($pts) { $planoParVisite[] = ['le' => $v['prevu_le'], 'pct' => (int) round(array_sum(array_map(fn ($p) => (int) $p['valeur'], $pts)) / count($pts))]; }
    }
    $photosJour = array_map('viPhotoLigne', Db::rows('SELECT * FROM ceo_visite_photo WHERE shop_id = ? AND genre LIKE \'jour_%\' AND prise_a >= ? ORDER BY prise_a DESC LIMIT 30', [$shop, $du . ' 00:00:00']));
    return ['shop' => $shop, 'nc' => viNc($shop), 'msp' => viMsp($shop, 4)[$shop] ?? [], 'equipe' => viEquipe($shop)[$shop] ?? null,
        'visites' => $visites, 'plansParMois' => $parMois, 'planoParVisite' => array_reverse($planoParVisite), 'photosJour' => $photosJour,
        'ca' => viCa()[$shop] ?? null, 'google' => viGoogle()[$shop] ?? null, 'lu' => date('Y-m-d H:i')];
}

/* --- écritures : visites ------------------------------------------------------ */

function viDate(?string $d): ?string { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null; }
function viHeure(?string $h): ?string { return is_string($h) && preg_match('/^\d{2}:\d{2}$/', $h) ? $h : null; }
function viQui(array $b): string { return mb_substr(trim((string) ($b['qui'] ?? 'consultant')), 0, 120); }

function viVisiteParId(string $id): ?array
{
    $v = ctype_digit($id) ? Db::row('SELECT * FROM ceo_visite WHERE id = ?', [(int) $id]) : null;
    if ($v === null) { $v = Db::row('SELECT * FROM ceo_visite WHERE client_id = ?', [$id]); }
    return $v;
}

/** POST /visites — planifier (rejouable : même client_id, même visite). */
function wr_visites_post(): array
{
    ensureVisites();
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    if (!isset(viMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $date = viDate($b['prevu_le'] ?? null);
    if ($date === null) { http_response_code(422); return ['error' => 'date requise (AAAA-MM-JJ)']; }
    $motif = in_array($b['motif'] ?? '', VI_MOTIFS, true) ? $b['motif'] : 'reguliere';
    $cid = mb_substr(trim((string) ($b['client_id'] ?? '')), 0, 40) ?: null;
    $cons = mb_substr(trim((string) ($b['consultant'] ?? '')), 0, 32);
    $consNom = mb_substr(trim((string) ($b['consultant_nom'] ?? '')), 0, 120) ?: viConsultantNom($cons);
    $now = date('Y-m-d H:i:s');
    if ($cid !== null && ($ex = Db::row('SELECT id FROM ceo_visite WHERE client_id = ?', [$cid])) !== null) {
        return ['ok' => true, 'visite' => viVisiteLigne(Db::row('SELECT * FROM ceo_visite WHERE id = ?', [(int) $ex['id']])), 'deja' => true];
    }
    Db::exec('INSERT INTO ceo_visite (client_id, shop_id, consultant_id, consultant_nom, prevu_le, debut_h, duree_min, motif, statut, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$cid, $shop, $cons, $consNom, $date, viHeure($b['debut_h'] ?? null) ?? '09:00', max(15, min(480, (int) ($b['duree_min'] ?? 90))), $motif, 'planifiee', $now, $now]);
    $id = (int) Db::pdo()->lastInsertId();
    journalAdd($consNom ?: 'Consultant', 'Visite', null, 'Visite planifiée : ' . viMagasins()[$shop]['court'] . ' le ' . $date);
    return ['ok' => true, 'visite' => viVisiteLigne(Db::row('SELECT * FROM ceo_visite WHERE id = ?', [$id]))];
}

/** PUT /visites/{id} — statut, créneau, début, fin, sentiment, notes. */
function wr_visites_put(string $id): array
{
    ensureVisites();
    $v = viVisiteParId($id);
    if ($v === null) { http_response_code(404); return ['error' => 'visite inconnue']; }
    $b = body();
    $set = []; $args = [];
    if (isset($b['statut'])) {
        if (!in_array($b['statut'], VI_STATUTS_VISITE, true)) { http_response_code(422); return ['error' => 'statut inconnu']; }
        $set[] = 'statut = ?'; $args[] = $b['statut'];
        if ($b['statut'] === 'en_cours' && $v['commence_a'] === null) { $set[] = 'commence_a = ?'; $args[] = date('Y-m-d H:i:s'); }
        if ($b['statut'] === 'terminee') { $set[] = 'termine_a = ?'; $args[] = date('Y-m-d H:i:s'); }
    }
    if (($d = viDate($b['prevu_le'] ?? null)) !== null) { $set[] = 'prevu_le = ?'; $args[] = $d; }
    if (($h = viHeure($b['debut_h'] ?? null)) !== null) { $set[] = 'debut_h = ?'; $args[] = $h; }
    if (isset($b['duree_min'])) { $set[] = 'duree_min = ?'; $args[] = max(15, min(480, (int) $b['duree_min'])); }
    if (array_key_exists('sentiment', $b)) { $s = (int) $b['sentiment']; $set[] = 'sentiment = ?'; $args[] = $s >= 1 && $s <= 5 ? $s : null; }
    if (array_key_exists('positif', $b)) { $set[] = 'positif = ?'; $args[] = mb_substr((string) $b['positif'], 0, 2000); }
    if (array_key_exists('notes', $b)) { $set[] = 'notes = ?'; $args[] = mb_substr((string) $b['notes'], 0, 4000); }
    if (array_key_exists('execution', $b)) { $set[] = 'execution = ?'; $args[] = in_array($b['execution'], VI_EXECUTION, true) && $b['execution'] !== '' ? $b['execution'] : null; }
    if (array_key_exists('clients', $b)) { $set[] = 'clients = ?'; $args[] = in_array($b['clients'], VI_CLIENTS, true) && $b['clients'] !== '' ? $b['clients'] : null; }
    if (array_key_exists('causes', $b)) { $c = is_array($b['causes']) ? array_values(array_intersect(array_map('strval', $b['causes']), array_keys(viCausesDiag()))) : []; $set[] = 'causes = ?'; $args[] = $c ? json_encode($c) : null; }
    if (array_key_exists('diagnostic', $b)) { $set[] = 'diagnostic = ?'; $args[] = mb_substr((string) $b['diagnostic'], 0, 4000) ?: null; }
    if (array_key_exists('reco', $b)) { $set[] = 'reco = ?'; $args[] = mb_substr((string) $b['reco'], 0, 4000) ?: null; }
    if (isset($b['consultant'])) { $set[] = 'consultant_id = ?'; $args[] = mb_substr((string) $b['consultant'], 0, 32); $set[] = 'consultant_nom = ?'; $args[] = viConsultantNom((string) $b['consultant']); }
    if (!$set) { return ['ok' => true, 'visite' => viVisiteLigne($v)]; }
    $set[] = 'maj_le = ?'; $args[] = date('Y-m-d H:i:s'); $args[] = (int) $v['id'];
    Db::exec('UPDATE ceo_visite SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    if (($b['statut'] ?? '') === 'terminee') {
        journalAdd($v['consultant_nom'] ?: 'Consultant', 'Visite', null, 'Visite terminée : ' . (viMagasins()[(string) $v['shop_id']]['court'] ?? $v['shop_id'])
            . (!empty($b['reco']) ? ' — ' . mb_substr((string) $b['reco'], 0, 160) : ''));
    }
    return ['ok' => true, 'visite' => viVisiteLigne(Db::row('SELECT * FROM ceo_visite WHERE id = ?', [(int) $v['id']]))];
}

/** PUT /visites/{id}/points — un lot de points de checklist (idempotent). */
function wr_visites_points_put(string $id): array
{
    ensureVisites();
    $v = viVisiteParId($id);
    if ($v === null) { http_response_code(404); return ['error' => 'visite inconnue']; }
    $b = body();
    $pts = is_array($b['points'] ?? null) ? $b['points'] : [];
    $n = 0;
    foreach ($pts as $p) {
        $ref = preg_replace('/[^\w-]/', '', (string) ($p['ref'] ?? ''));
        $module = in_array($p['module'] ?? '', VI_MODULES, true) ? $p['module'] : null;
        if ($ref === '' || $module === null) { continue; }
        $etat = in_array($p['etat'] ?? '', ['', 'ok', 'ko', 'na'], true) ? (string) ($p['etat'] ?? '') : '';
        $note = isset($p['note']) && (int) $p['note'] >= 1 && (int) $p['note'] <= 5 ? (int) $p['note'] : null;
        $valeur = isset($p['valeur']) && $p['valeur'] !== '' && $p['valeur'] !== null ? max(0, min(100, (int) $p['valeur'])) : null;
        $causes = is_array($p['causes'] ?? null) ? array_values(array_intersect(array_map('strval', $p['causes']), array_keys(viCausesPlano()))) : [];
        Db::exec('INSERT INTO ceo_visite_point (visite_id, module, point_ref, libelle, etat, note, valeur, commentaire, causes, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE module = VALUES(module), libelle = VALUES(libelle), etat = VALUES(etat), note = VALUES(note), valeur = VALUES(valeur),
                  commentaire = VALUES(commentaire), causes = VALUES(causes), maj_le = VALUES(maj_le)',
            [(int) $v['id'], $module, mb_substr($ref, 0, 60), mb_substr((string) ($p['libelle'] ?? ''), 0, 190), $etat, $note, $valeur,
             mb_substr((string) ($p['commentaire'] ?? ''), 0, 1000) ?: null, $causes ? json_encode($causes) : null, date('Y-m-d H:i:s')]);
        $n++;
    }
    Db::exec('UPDATE ceo_visite SET maj_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $v['id']]);
    return ['ok' => true, 'points' => $n,
        'liste' => array_map('viPointLigne', Db::rows('SELECT * FROM ceo_visite_point WHERE visite_id = ?', [(int) $v['id']]))];
}

/**
 * POST /visites/photos — une photo (data-URL JPEG/PNG/WebP, 2 Mo au plus,
 * déjà réduite par l'appareil). Rattachée à une visite, un point, un plan
 * d'action ou simplement à la boutique (photo du jour).
 */
function wr_visites_photos_post(): array
{
    ensureVisites();
    $b = body();
    $cid = mb_substr(trim((string) ($b['client_id'] ?? '')), 0, 40) ?: null;
    if ($cid !== null && ($ex = Db::row('SELECT * FROM ceo_visite_photo WHERE client_id = ?', [$cid])) !== null) {
        return ['ok' => true, 'photo' => viPhotoLigne($ex), 'deja' => true];
    }
    $shop = trim((string) ($b['shop'] ?? ''));
    $visite = null;
    if (!empty($b['visite_id'])) {
        $visite = viVisiteParId((string) $b['visite_id']);
        if ($visite === null) { http_response_code(404); return ['error' => 'visite inconnue']; }
        $shop = (string) $visite['shop_id'];
    }
    if (!isset(viMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $genre = in_array($b['genre'] ?? '', VI_GENRES_PHOTO, true) ? $b['genre'] : 'point';
    $data = (string) ($b['data'] ?? '');
    if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) { http_response_code(422); return ['error' => 'image illisible (data-URL attendue)']; }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 64) { http_response_code(422); return ['error' => 'image illisible']; }
    if (strlen($bin) > 2 * 1024 * 1024) { http_response_code(413); return ['error' => 'photo trop lourde — 2 Mo au maximum']; }
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) { http_response_code(415); return ['error' => 'format non accepté — JPEG, PNG ou WebP']; }
    $dossier = __DIR__ . '/../public/uploads/visites/' . preg_replace('/[^\w-]/', '', $shop);
    if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) { http_response_code(500); return ['error' => 'dossier des photos impossible à créer']; }
    $nom = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(6)), 0, 10) . '.' . $ext;
    if (@file_put_contents($dossier . '/' . $nom, $bin) === false) { http_response_code(500); return ['error' => 'écriture de la photo impossible']; }
    $chemin = 'uploads/visites/' . preg_replace('/[^\w-]/', '', $shop) . '/' . $nom;
    $prise = isset($b['prise_a']) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', (string) $b['prise_a']) ? str_replace('T', ' ', substr((string) $b['prise_a'], 0, 16)) . ':00' : date('Y-m-d H:i:s');
    $lat = isset($b['lat']) && is_numeric($b['lat']) ? round((float) $b['lat'], 6) : null;
    $lng = isset($b['lng']) && is_numeric($b['lng']) ? round((float) $b['lng'], 6) : null;
    $planId = !empty($b['plan_id']) ? (int) $b['plan_id'] : null;
    Db::exec('INSERT INTO ceo_visite_photo (client_id, visite_id, shop_id, plan_id, point_ref, genre, chemin, prise_a, lat, lng, largeur, hauteur, octets, par) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$cid, $visite ? (int) $visite['id'] : null, $shop, $planId, mb_substr(preg_replace('/[^\w-]/', '', (string) ($b['ref'] ?? '')), 0, 60) ?: null,
         $genre, $chemin, $prise, $lat, $lng, $info[0] ?? null, $info[1] ?? null, strlen($bin), in_array($b['par'] ?? '', VI_ROLES, true) ? $b['par'] : 'consultant']);
    $id = (int) Db::pdo()->lastInsertId();
    return ['ok' => true, 'photo' => viPhotoLigne(Db::row('SELECT * FROM ceo_visite_photo WHERE id = ?', [$id]))];
}

/* --- écritures : plans d'action ---------------------------------------------- */

function viPlanParId(string $id): ?array
{
    $p = ctype_digit($id) ? Db::row('SELECT * FROM ceo_visite_action WHERE id = ?', [(int) $id]) : null;
    if ($p === null) { $p = Db::row('SELECT * FROM ceo_visite_action WHERE client_id = ?', [$id]); }
    return $p;
}

/** Prévenir qui de droit : push sur l'abonnement de la boutique, du consultant ou de l'admin. */
function viNotifier(string $cible, string $titre, string $corps, string $url, string $tag): void
{
    try {
        if (function_exists('pushDiffuser')) { pushDiffuser($cible, ['titre' => $titre, 'corps' => $corps, 'url' => $url, 'tag' => $tag]); }
    } catch (Throwable $e) { /* une notification qui ne part pas ne bloque rien */ }
}

/** POST /plans — un plan ou un lot (depuis la review). */
function wr_plans_post(): array
{
    ensureVisites();
    $b = body();
    $lot = is_array($b['plans'] ?? null) ? $b['plans'] : [$b];
    $qui = viQui($b);
    $out = []; $nouveaux = [];
    foreach ($lot as $p) {
        $shop = trim((string) ($p['shop'] ?? ''));
        $visite = !empty($p['visite_id']) ? viVisiteParId((string) $p['visite_id']) : null;
        if ($visite) { $shop = (string) $visite['shop_id']; }
        $titre = mb_substr(trim((string) ($p['titre'] ?? '')), 0, 190);
        if (!isset(viMagasins()[$shop]) || $titre === '') { continue; }
        $cid = mb_substr(trim((string) ($p['client_id'] ?? '')), 0, 40) ?: null;
        if ($cid !== null && ($ex = Db::row('SELECT * FROM ceo_visite_action WHERE client_id = ?', [$cid])) !== null) { $out[] = viPlanLigne($ex); continue; }
        $prio = in_array($p['priorite'] ?? '', VI_PRIORITES, true) ? $p['priorite'] : 'P1';
        $ass = in_array($p['assigne'] ?? '', VI_ASSIGNES, true) ? $p['assigne'] : 'franchise';
        $now = date('Y-m-d H:i:s');
        Db::exec('INSERT INTO ceo_visite_action (client_id, shop_id, visite_id, point_ref, titre, detail, priorite, assigne, echeance, statut, cree_par, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$cid, $shop, $visite ? (int) $visite['id'] : null, mb_substr(preg_replace('/[^\w-]/', '', (string) ($p['ref'] ?? '')), 0, 60) ?: null,
             $titre, mb_substr((string) ($p['detail'] ?? ''), 0, 2000) ?: null, $prio, $ass, viDate($p['echeance'] ?? null), 'ouvert', $qui, $now, $now]);
        $id = (int) Db::pdo()->lastInsertId();
        Db::exec('INSERT INTO ceo_visite_action_evt (plan_id, quand, qui, de_statut, vers_statut, commentaire) VALUES (?,?,?,?,?,?)', [$id, $now, $qui, null, 'ouvert', 'créé']);
        $ligne = viPlanLigne(Db::row('SELECT * FROM ceo_visite_action WHERE id = ?', [$id]));
        $out[] = $ligne; $nouveaux[$shop][] = $ligne;
    }
    foreach ($nouveaux as $shop => $ls) {
        $shop = (string) $shop;
        $court = viMagasins()[$shop]['court'] ?? $shop;
        $p0 = count(array_filter($ls, fn ($l) => $l['priorite'] === 'P0'));
        viNotifier($shop, 'Plan d’action — ' . $court, count($ls) . ' action' . (count($ls) > 1 ? 's' : '') . ($p0 ? ' dont ' . $p0 . ' P0' : '') . ' : ' . $ls[0]['titre'],
            'visites/?shop=' . rawurlencode($shop), 'plan-' . $shop);
        journalAdd($qui, 'Plan d’action', null, count($ls) . ' action(s) pour ' . $court);
    }
    return ['ok' => true, 'plans' => $out];
}

/** PUT /plans/{id} — transition de statut (selon le rôle), ou correction du contenu. */
function wr_plans_put(string $id): array
{
    ensureVisites();
    $p = viPlanParId($id);
    if ($p === null) { http_response_code(404); return ['error' => 'plan inconnu']; }
    $b = body();
    $role = in_array($b['role'] ?? '', VI_ROLES, true) ? $b['role'] : 'consultant';
    $qui = viQui($b);
    $now = date('Y-m-d H:i:s');
    $set = []; $args = [];
    // Contenu : titre, détail, priorité, assigné, échéance — consultant et admin.
    if ($role !== 'franchise') {
        if (isset($b['titre']) && trim((string) $b['titre']) !== '') { $set[] = 'titre = ?'; $args[] = mb_substr(trim((string) $b['titre']), 0, 190); }
        if (array_key_exists('detail', $b)) { $set[] = 'detail = ?'; $args[] = mb_substr((string) $b['detail'], 0, 2000) ?: null; }
        if (in_array($b['priorite'] ?? '', VI_PRIORITES, true)) { $set[] = 'priorite = ?'; $args[] = $b['priorite']; }
        if (in_array($b['assigne'] ?? '', VI_ASSIGNES, true)) { $set[] = 'assigne = ?'; $args[] = $b['assigne']; }
        if (array_key_exists('echeance', $b)) { $set[] = 'echeance = ?'; $args[] = viDate($b['echeance'] ?? null); }
    }
    $photoId = null;
    if (!empty($b['photo_client_id'])) {
        $ph = Db::row('SELECT id FROM ceo_visite_photo WHERE client_id = ?', [mb_substr((string) $b['photo_client_id'], 0, 40)]);
        $photoId = $ph ? (int) $ph['id'] : null;
    } elseif (!empty($b['photo_id'])) { $photoId = (int) $b['photo_id']; }
    if ($photoId !== null) { $set[] = 'photo_id = ?'; $args[] = $photoId; Db::exec('UPDATE ceo_visite_photo SET plan_id = ? WHERE id = ?', [(int) $p['id'], $photoId]); }
    $vers = (string) ($b['statut'] ?? '');
    if ($vers !== '') {
        if (!in_array($vers, VI_STATUTS_PLAN, true)) { http_response_code(422); return ['error' => 'statut inconnu']; }
        $permis = VI_TRANSITIONS[$role][$p['statut']] ?? [];
        if ($vers !== $p['statut'] && !in_array($vers, $permis, true)) {
            http_response_code(409);
            return ['error' => 'passage ' . $p['statut'] . ' → ' . $vers . ' refusé pour ' . $role, 'plan' => viPlanLigne($p)];
        }
        if ($vers !== $p['statut']) {
            $set[] = 'statut = ?'; $args[] = $vers;
            if ($vers === 'ferme') { $set[] = 'ferme_le = ?'; $args[] = $now; }
            if ($vers === 'reprendre') { $set[] = 'retour = ?'; $args[] = mb_substr((string) ($b['retour'] ?? $b['commentaire'] ?? ''), 0, 1000) ?: null; }
            if ($vers === 'escalade') { $set[] = 'escalade_motif = ?'; $args[] = mb_substr((string) ($b['escalade_motif'] ?? $b['commentaire'] ?? ''), 0, 1000) ?: null; }
            Db::exec('INSERT INTO ceo_visite_action_evt (plan_id, quand, qui, de_statut, vers_statut, commentaire, photo_id) VALUES (?,?,?,?,?,?,?)',
                [(int) $p['id'], $now, $qui, $p['statut'], $vers, mb_substr((string) ($b['commentaire'] ?? $b['retour'] ?? ''), 0, 1000) ?: null, $photoId]);
        }
    }
    if (!$set) { return ['ok' => true, 'plan' => viPlanLigne($p)]; }
    $set[] = 'maj_le = ?'; $args[] = $now; $args[] = (int) $p['id'];
    Db::exec('UPDATE ceo_visite_action SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    $shop = (string) $p['shop_id']; $court = viMagasins()[$shop]['court'] ?? $shop;
    if ($vers !== '' && $vers !== $p['statut']) {
        $t = $p['titre'];
        if ($vers === 'attente')   { viNotifier('admin', 'Correction reçue — ' . $court, $t . ' : photo à valider', 'visites/?role=admin', 'valider-' . $p['id']); }
        if ($vers === 'reprendre') { viNotifier($shop, 'À reprendre — ' . $court, $t . ($b['retour'] ?? '' ? ' : ' . $b['retour'] : ''), 'visites/?shop=' . rawurlencode($shop), 'plan-' . $p['id']); }
        if ($vers === 'valide')    { viNotifier($shop, 'Correction validée — ' . $court, $t, 'visites/?shop=' . rawurlencode($shop), 'plan-' . $p['id']); }
        if ($vers === 'escalade')  { viNotifier('admin', 'Escalade — ' . $court, $p['priorite'] . ' ' . $t, 'visites/?role=admin', 'escalade-' . $p['id']); }
        journalAdd($qui, 'Plan d’action', null, $court . ' : « ' . $t . ' » ' . $p['statut'] . ' → ' . $vers);
    }
    return ['ok' => true, 'plan' => viPlanLigne(Db::row('SELECT * FROM ceo_visite_action WHERE id = ?', [(int) $p['id']]))];
}

/* --- écritures : MSP, équipe, réglages ---------------------------------------- */

/** POST /msp — le rapport mystery shopper du mois (score, rubriques, PDF facultatif). */
function wr_msp_post(): array
{
    ensureVisites();
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    if (!isset(viMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $mois = (string) ($b['mois'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $mois)) { http_response_code(422); return ['error' => 'mois attendu AAAA-MM']; }
    $rub = [];
    foreach (is_array($b['rubriques'] ?? null) ? $b['rubriques'] : [] as $k => $v) {
        $k = mb_substr(trim((string) $k), 0, 40);
        if ($k !== '' && is_numeric($v)) { $rub[$k] = round((float) $v, 1); }
    }
    $total = isset($b['total']) && is_numeric($b['total']) ? round((float) $b['total'], 1) : ($rub ? round(array_sum($rub) / count($rub), 1) : null);
    $fichier = null;
    if (!empty($b['fichier']) && preg_match('#^data:application/pdf;base64,(.+)$#s', (string) $b['fichier'], $m)) {
        $bin = base64_decode($m[1], true);
        if ($bin !== false && strlen($bin) <= 8 * 1024 * 1024 && str_starts_with($bin, '%PDF')) {
            $dossier = __DIR__ . '/../public/uploads/visites/msp';
            if (is_dir($dossier) || @mkdir($dossier, 0775, true)) {
                $nom = preg_replace('/[^\w-]/', '', $shop) . '-' . $mois . '.pdf';
                if (@file_put_contents($dossier . '/' . $nom, $bin) !== false) { $fichier = 'uploads/visites/msp/' . $nom; }
            }
        }
    }
    Db::exec('INSERT INTO ceo_msp (shop_id, mois, total, rubriques, commentaires, fichier, par, le) VALUES (?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE total = VALUES(total), rubriques = VALUES(rubriques), commentaires = VALUES(commentaires),
              fichier = COALESCE(VALUES(fichier), fichier), par = VALUES(par), le = VALUES(le)',
        [$shop, $mois, $total, json_encode($rub, JSON_UNESCAPED_UNICODE), mb_substr((string) ($b['commentaires'] ?? ''), 0, 2000) ?: null, $fichier, viQui($b), date('Y-m-d H:i:s')]);
    return ['ok' => true, 'msp' => viMsp($shop, 4)[$shop] ?? []];
}

/** DELETE /msp/{id} */
function wr_msp_delete(int $id): array
{
    ensureVisites();
    Db::exec('DELETE FROM ceo_msp WHERE id = ?', [$id]);
    return ['ok' => true];
}

/** PUT /equipe/{shop} — un relevé d'effectif. */
function wr_equipe_put(string $shop): array
{
    ensureVisites();
    if (!isset(viMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $b = body();
    $n = fn ($k) => isset($b[$k]) && $b[$k] !== '' && is_numeric($b[$k]) ? max(0, min(99, (int) $b[$k])) : null;
    Db::exec('INSERT INTO ceo_equipe_releve (shop_id, releve_le, effectif, prevu, departs, par) VALUES (?,?,?,?,?,?)',
        [$shop, viDate($b['releve_le'] ?? null) ?? date('Y-m-d'), $n('effectif'), $n('prevu'), $n('departs'), viQui($b)]);
    return ['ok' => true, 'equipe' => viEquipe($shop)[$shop] ?? null];
}

/** PUT /visites/reglages — checklist, seuils, fréquences. */
function wr_visites_reglages_put(): array
{
    ensureVisites();
    $b = body();
    if (isset($b['checklist'])) {
        $cl = [];
        foreach (is_array($b['checklist']) ? $b['checklist'] : [] as $m) {
            $id = in_array($m['id'] ?? '', VI_MODULES, true) ? $m['id'] : null;
            if ($id === null) { continue; }
            $pts = [];
            foreach (is_array($m['points'] ?? null) ? $m['points'] : [] as $p) {
                $ref = preg_replace('/[^\w-]/', '', (string) ($p['ref'] ?? ''));
                $lib = mb_substr(trim((string) ($p['libelle'] ?? '')), 0, 190);
                if ($ref === '' || $lib === '') { continue; }
                $pts[] = ['ref' => mb_substr($ref, 0, 60), 'libelle' => $lib, 'photo' => !empty($p['photo']), 'pct' => !empty($p['pct'])];
            }
            $cl[] = ['id' => $id, 'nom' => mb_substr(trim((string) ($m['nom'] ?? $id)), 0, 60), 'points' => $pts];
        }
        viSettingSet('visitesChecklist', $cl ?: viChecklistDefaut());
    }
    if (isset($b['seuils']) && is_array($b['seuils'])) {
        $s = viSeuils(); $d = viSeuilsDefaut();
        foreach ($d as $k => $v) {
            if (!array_key_exists($k, $b['seuils'])) { continue; }
            $x = $b['seuils'][$k];
            if (is_bool($v)) { $s[$k] = (bool) $x; }
            elseif (is_int($v)) { $s[$k] = max(0, (int) $x); }
            elseif (is_float($v)) { $s[$k] = (float) $x; }
            else { $s[$k] = mb_substr(trim((string) $x), 0, 190); }
        }
        if (!empty($s['mailSynthese']) && !filter_var($s['mailSynthese'], FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'adresse de synthèse invalide']; }
        viSettingSet('visitesSeuils', $s);
    }
    if (isset($b['frequence']) && is_array($b['frequence'])) {
        $f = [];
        foreach ($b['frequence'] as $shop => $j) { if (isset(viMagasins()[(string) $shop]) && (int) $j > 0) { $f[(string) $shop] = min(90, (int) $j); } }
        viSettingSet('visitesFrequence', $f);
    }
    return ['ok' => true, 'checklist' => viChecklist(), 'seuils' => viSeuils(), 'frequence' => setting('visitesFrequence', []) ?: (object) []];
}

/* --- synthèse et horloge ------------------------------------------------------- */

/** GET /visites/synthese — la synthèse réseau de Sam, du jour. */
function ep_visites_synthese(): array
{
    ensureVisites();
    $sauve = $_GET; $_GET = ['role' => 'admin'];
    try { $app = ep_visites_app(); } finally { $_GET = $sauve; }
    $auj = date('Y-m-d'); $lundi = date('Y-m-d', strtotime('monday this week'));
    $plans = $app['plans'];
    $enCours = array_filter($plans, fn ($p) => in_array($p['statut'], ['ouvert', 'reprendre', 'escalade'], true));
    $attente = array_filter($plans, fn ($p) => $p['statut'] === 'attente');
    $fermes = array_filter($plans, fn ($p) => $p['statut'] === 'ferme' && (string) $p['ferme_le'] >= $lundi);
    $compte = fn ($prio) => count(array_filter($enCours, fn ($p) => $p['priorite'] === $prio));
    $escalades = []; $actions = [];
    $mags = viMagasins();
    foreach ($enCours as $p) {
        $court = $mags[$p['shop']]['court'] ?? $p['shop'];
        if ($p['priorite'] === 'P0' && ($p['retard'] > 0 || $p['statut'] === 'escalade')) {
            $escalades[] = ['feu' => 'rouge', 'shop' => $p['shop'], 'titre' => $court . ' — ' . $p['priorite'] . ' ' . $p['titre'], 'detail' => ($p['retard'] ? 'échéance dépassée de ' . $p['retard'] . ' j' : 'escaladé') . ($p['photo_id'] ? '' : ' · aucune photo')];
            $actions[] = 'Appeler le franchisé de ' . $court . ' (' . $p['titre'] . ')';
        }
    }
    foreach ($attente as $p) {
        $j = (int) ((time() - strtotime((string) $p['maj_le'])) / 86400);
        $court = $mags[$p['shop']]['court'] ?? $p['shop'];
        if ($j >= 2) { $escalades[] = ['feu' => 'orange', 'shop' => $p['shop'], 'titre' => $court . ' — correction reçue, pas encore validée', 'detail' => 'en attente admin depuis ' . $j . ' j']; }
        $actions[] = 'Valider la photo de ' . $court . ' (' . $p['titre'] . ')';
    }
    foreach ($app['boutiques'] as $b) {
        if ($b['due']) { $actions[] = 'Planifier ' . $b['court'] . ' (' . $b['due'] . ')'; }
        if ($b['google'] && $b['google']['faibles'] >= 2) { $escalades[] = ['feu' => 'orange', 'shop' => $b['id'], 'titre' => $b['court'] . ' — ' . $b['google']['faibles'] . ' avis ≤ 2/5 sur 30 jours', 'detail' => 'à lire avant la prochaine visite']; }
        if ($b['prochaineVisite'] && $b['prochaineVisite']['le'] === $auj) { $actions[] = 'Visite aujourd’hui : ' . $b['court'] . ' ' . $b['prochaineVisite']['h'] . ' (' . $b['prochaineVisite']['consultant'] . ')'; }
    }
    $semaine = []; $nbVisites = 0; $checklists = 0;
    foreach ($app['visites'] as $v) {
        if ($v['prevu_le'] < $lundi || $v['statut'] === 'annulee') { continue; }
        $semaine[$v['consultantNom'] ?: 'Sans consultant'] = ($semaine[$v['consultantNom'] ?: 'Sans consultant'] ?? 0) + 1;
        $nbVisites++;
        if ($v['statut'] === 'terminee') { $checklists++; }
    }
    $photos = count(array_filter($app['photos'], fn ($p) => (string) $p['prise_a'] >= $lundi));
    return ['date' => $auj, 'boutiques' => $app['boutiques'], 'reseau' => $app['reseau'],
        'compteurs' => ['enCours' => count($enCours), 'p0' => $compte('P0'), 'p1' => $compte('P1'), 'p2' => $compte('P2'), 'attente' => count($attente), 'fermes' => count($fermes)],
        'escalades' => $escalades, 'actions' => array_slice(array_values(array_unique($actions)), 0, 8),
        'consultants' => $semaine, 'visitesSemaine' => $nbVisites, 'checklists' => $checklists, 'photosSemaine' => $photos];
}

function viSyntheseHtml(array $s): string
{
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $feu = ['vert' => '🟢', 'orange' => '🟡', 'rouge' => '🔴'];
    $h = '<h2 style="font-family:Georgia,serif">Synthèse réseau — ' . $e(date('d/m/Y', strtotime($s['date']))) . '</h2>';
    $c = $s['compteurs'];
    $h .= '<p>Plans d’action en cours : <b>' . $c['enCours'] . '</b> (' . $c['p0'] . ' P0 · ' . $c['p1'] . ' P1 · ' . $c['p2'] . ' P2) · en attente de validation : <b>' . $c['attente'] . '</b> · fermés cette semaine : <b>' . $c['fermes'] . '</b></p>';
    $h .= '<table cellpadding="6" style="border-collapse:collapse;font-size:13px"><tr style="text-align:left"><th>Boutique</th><th>Feu</th><th>Dernière visite</th><th>CA semaine</th><th>Google</th><th>Planogramme</th><th>Actions</th><th>Signal</th></tr>';
    foreach ($s['boutiques'] as $b) {
        $h .= '<tr style="border-top:1px solid #ddd"><td><b>' . $e($b['court']) . '</b></td><td>' . $feu[$b['feu']] . '</td><td>' . $e($b['derniereVisite'] ? $b['derniereVisite']['le'] . ' · ' . $b['derniereVisite']['consultant'] : '—') . '</td>'
            . '<td>' . ($b['ca'] ? number_format($b['ca']['ca'], 0, ',', ' ') . ' € · ' . ($b['ca']['pct'] !== null ? $b['ca']['pct'] . ' %' : '') : '—') . '</td>'
            . '<td>' . ($b['google'] && $b['google']['note'] !== null ? number_format((float) $b['google']['note'], 1, ',', '') . ' (' . $b['google']['avis'] . ')' : '—') . '</td>'
            . '<td>' . ($b['plano'] ? $b['plano']['pct'] . ' %' : '—') . '</td><td>' . $b['plansOuverts'] . ($b['p0'] ? ' · ' . $b['p0'] . ' P0' : '') . '</td>'
            . '<td>' . $e(implode(' · ', array_slice($b['motifs'], 0, 2))) . '</td></tr>';
    }
    $h .= '</table>';
    if ($s['escalades']) { $h .= '<h3>Escalades possibles</h3><ul>'; foreach ($s['escalades'] as $x) { $h .= '<li>' . $feu[$x['feu']] . ' ' . $e($x['titre']) . ' — <i>' . $e($x['detail']) . '</i></li>'; } $h .= '</ul>'; }
    $diags = array_filter($s['boutiques'], fn ($b) => !empty($b['derniereVisite']['reco']) || !empty($b['derniereVisite']['causes']));
    if ($diags) {
        $h .= '<h3>Vu sur place — le vrai problème</h3><ul>';
        foreach ($diags as $b) { $dv = $b['derniereVisite']; $h .= '<li><b>' . $e($b['court']) . '</b> (' . $e($dv['le']) . ', ' . $e($dv['consultant']) . ') : ' . $e(implode(', ', array_map(fn ($c) => viCausesDiag()[$c] ?? $c, $dv['causes'] ?? []))) . ($dv['reco'] ? ' — « ' . $e($dv['reco']) . ' »' : '') . '</li>'; }
        $h .= '</ul>';
    }
    if ($s['actions']) { $h .= '<h3>Actions requises aujourd’hui</h3><ol>'; foreach ($s['actions'] as $a) { $h .= '<li>' . $e($a) . '</li>'; } $h .= '</ol>'; }
    $h .= '<p style="color:#777;font-size:12px">Cette semaine : ' . $s['visitesSemaine'] . ' visites · ' . $s['checklists'] . ' terminées · ' . $s['photosSemaine'] . ' photos.</p>';
    return $h;
}

/**
 * L'horloge (toutes les 5 minutes par bin/visites_cron.sh) : escalade
 * automatique des P0 dépassés, rappels J-1 et jour J, synthèse du matin.
 * Chaque tâche journalière marque sa date dans `visitesCron` : elle ne
 * s'exécute qu'une fois par jour, même si le cron appelle plus souvent.
 */
function viHorloge(bool $force = false): array
{
    ensureVisites();
    $s = viSeuils();
    $etat = setting('visitesCron', []); if (!is_array($etat)) { $etat = []; }
    $auj = date('Y-m-d'); $heure = date('H:i');
    $fait = ['escalades' => 0, 'rappelsJ1' => 0, 'rappelsJour' => 0, 'synthese' => false, 'mails' => 0];
    $mags = viMagasins();
    // 1. Un P0 (ou P1) ouvert dont l'échéance est dépassée de `escaladeJours` : escaladé, l'admin prévenu.
    $limite = date('Y-m-d', strtotime('-' . (int) $s['escaladeJours'] . ' days'));
    foreach (Db::rows('SELECT * FROM ceo_visite_action WHERE statut IN (\'ouvert\', \'reprendre\') AND priorite = \'P0\' AND echeance IS NOT NULL AND echeance < ?', [$limite]) as $p) {
        $now = date('Y-m-d H:i:s');
        Db::exec('UPDATE ceo_visite_action SET statut = \'escalade\', escalade_motif = ?, maj_le = ? WHERE id = ?', ['échéance dépassée (automatique)', $now, (int) $p['id']]);
        Db::exec('INSERT INTO ceo_visite_action_evt (plan_id, quand, qui, de_statut, vers_statut, commentaire) VALUES (?,?,?,?,?,?)', [(int) $p['id'], $now, 'horloge', $p['statut'], 'escalade', 'échéance dépassée']);
        $court = $mags[(string) $p['shop_id']]['court'] ?? $p['shop_id'];
        viNotifier('admin', 'Escalade automatique — ' . $court, 'P0 « ' . $p['titre'] . ' » : échéance dépassée', 'visites/?role=admin', 'escalade-' . $p['id']);
        $fait['escalades']++;
    }
    $mails = !empty($s['mails']) && class_exists('Smtp') && Smtp::configured();
    // 2. Rappel J-1, le soir : consultant et franchisé.
    if (($force || $heure >= (string) $s['rappelJ1']) && ($etat['j1'] ?? '') !== $auj) {
        $demain = date('Y-m-d', strtotime('+1 day'));
        foreach (Db::rows('SELECT * FROM ceo_visite WHERE prevu_le = ? AND statut IN (\'planifiee\', \'confirmee\')', [$demain]) as $v) {
            $shop = (string) $v['shop_id']; $court = $mags[$shop]['court'] ?? $shop;
            $ouverts = Db::rows('SELECT priorite, titre FROM ceo_visite_action WHERE shop_id = ? AND statut IN (\'ouvert\', \'reprendre\', \'escalade\') ORDER BY FIELD(priorite, \'P0\', \'P1\', \'P2\') LIMIT 2', [$shop]);
            $suite = $ouverts ? ' — ' . implode(', ', array_map(fn ($o) => $o['priorite'] . ' ' . $o['titre'], $ouverts)) : '';
            viNotifier('c:' . $v['consultant_id'], 'Demain ' . $v['debut_h'] . ' — ' . $court, 'Visite prévue' . $suite, 'visites/?role=consultant&id=' . rawurlencode((string) $v['consultant_id']) . '#fiche/' . $v['id'], 'j1-' . $v['id']);
            viNotifier($shop, 'Visite demain ' . $v['debut_h'], $v['consultant_nom'] . ' passe demain' . ($ouverts ? ' — préparez : ' . $ouverts[0]['titre'] : ''), 'visites/?shop=' . rawurlencode($shop), 'j1-' . $v['id']);
            $fait['rappelsJ1']++;
            if ($mails) {
                foreach (viConsultants() as $c) {
                    if ($c['id'] === (string) $v['consultant_id'] && !empty($c['email'])) {
                        try { if (Smtp::envoyer($c['email'], 'Demain ' . $v['debut_h'] . ' — visite ' . $court, '<p>Visite prévue demain ' . $v['debut_h'] . ' à ' . htmlspecialchars($court) . '.' . htmlspecialchars($suite) . '</p>')) { $fait['mails']++; } } catch (Throwable $e) { /* le push a déjà prévenu */ }
                    }
                }
            }
        }
        $etat['j1'] = $auj;
    }
    // 3. Le matin : rappel du jour et synthèse.
    if (($force || $heure >= (string) $s['rappelJour']) && ($etat['jour'] ?? '') !== $auj) {
        foreach (Db::rows('SELECT * FROM ceo_visite WHERE prevu_le = ? AND statut IN (\'planifiee\', \'confirmee\')', [$auj]) as $v) {
            $shop = (string) $v['shop_id']; $court = $mags[$shop]['court'] ?? $shop;
            viNotifier('c:' . $v['consultant_id'], 'Aujourd’hui ' . $v['debut_h'] . ' — ' . $court, 'Ouvrez la fiche avant d’entrer', 'visites/?role=consultant&id=' . rawurlencode((string) $v['consultant_id']) . '#fiche/' . $v['id'], 'jour-' . $v['id']);
            $fait['rappelsJour']++;
        }
        $etat['jour'] = $auj;
    }
    if (($force || $heure >= (string) $s['syntheseHeure']) && ($etat['synthese'] ?? '') !== $auj) {
        $synt = ep_visites_synthese();
        viNotifier('admin', 'Synthèse du ' . date('d/m'), $synt['compteurs']['enCours'] . ' plans en cours · ' . $synt['compteurs']['attente'] . ' à valider · ' . count($synt['escalades']) . ' escalade(s)', 'visites/?role=admin#synthese', 'synthese-' . $auj);
        if ($mails && !empty($s['mailSynthese'])) {
            try { if (Smtp::envoyer((string) $s['mailSynthese'], 'Synthèse réseau — ' . date('d/m/Y'), viSyntheseHtml($synt))) { $fait['mails']++; } } catch (Throwable $e) { /* la page reste */ }
        }
        $fait['synthese'] = true;
        $etat['synthese'] = $auj;
    }
    $etat['dernier'] = date('Y-m-d H:i:s');
    viSettingSet('visitesCron', $etat);
    return ['ok' => true, 'fait' => $fait, 'etat' => $etat];
}

/** GET /visites/cron?jeton= — l'entrée du cron. */
function ep_visites_cron(): array
{
    ensureVisites();
    $jeton = (string) ($_GET['jeton'] ?? '');
    if ($jeton === '' || !hash_equals((string) setting('visitesJeton', ''), $jeton)) { http_response_code(403); return ['error' => 'jeton invalide']; }
    return viHorloge(false);
}

/** POST /visites/tick — l'horloge, tout de suite, depuis le cockpit. */
function wr_visites_tick(): array
{
    return viHorloge((bool) (body()['force'] ?? false));
}

/** Les réglages tels que le cockpit les montre, avec l'adresse du cron. */
function ep_visites_reglages(): array
{
    ensureVisites();
    return ['checklist' => viChecklist(), 'seuils' => viSeuils(), 'frequence' => setting('visitesFrequence', []) ?: (object) [],
        'cron' => setting('visitesCron', []) ?: (object) [], 'cronUrl' => rtrim(rapBaseUrl(), '/') . '/api/cockpit/visites/cron?jeton=' . rawurlencode((string) setting('visitesJeton', '')),
        'smtp' => class_exists('Smtp') && Smtp::configured(), 'magasins' => array_values(viMagasins()), 'consultants' => viConsultants()];
}
