<?php
declare(strict_types=1);

/**
 * Auto-installation — même philosophie que le panel consultant
 * (ReportShareRepository::ensureSchema) : l'application crée ses tables
 * elle-même au premier appel, avec le compte MySQL applicatif. Aucun accès
 * DBA n'est requis, seulement le privilège CREATE sur la base.
 *
 *  - tables absentes  → exécute sql/schema.sql (CREATE TABLE IF NOT EXISTS,
 *    ne touche pas aux tables existantes du panel) ;
 *  - base vide        → charge sql/seed.sql (réseau de démonstration) ;
 *  - premier passage  → génère le secret de session (ceo_app_setting).
 */

function ensureInstalled(): void
{
    try {
        Db::row('SELECT 1 FROM ceo_app_setting LIMIT 1');
    } catch (PDOException $e) {
        if (!isMissingTable($e)) { throw $e; }
        runSqlFile(__DIR__ . '/../sql/schema.sql');
    }

    // Jeu de démonstration : chargé UNIQUEMENT s'il est explicitement activé
    // (config 'seed' = true, ou variable d'env COCKPIT_SEED=1). Désactivé par
    // défaut → une base neuve reste vide, prête pour les vraies données.
    if (seedEnabled()) {
        $n = Db::row('SELECT COUNT(*) AS n FROM ceo_shop');
        if ((int) $n['n'] === 0) {
            runSqlFile(__DIR__ . '/../sql/seed.sql');
        }
    }

    if (setting('authSecret') === null) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
            ['authSecret', json_encode(bin2hex(random_bytes(32)))]);
    }

    ensureValidation();
    ensureReference();
    ensureProduction();
    ensureCentrale();
    ensureAnnotation();
    ensurePlanogramme();
}

/**
 * Structure du comptoir, placements et notes de présentation.
 *
 * Tout vit dans le cockpit : mesuré le 17/08/2026, l'API du panel n'expose ni
 * zone, ni meuble, ni emplacement, et le référentiel en portait zéro sur 710
 * références. Attendre une API pour déclarer un comptoir aurait laissé l'écran
 * vide indéfiniment ; seuls la photo de présentation et la diffusion en boutique
 * en dépendent vraiment, et ces deux-là sont annoncés comme manquants.
 *
 * Quatre niveaux, chacun avec son identité : renommer « Vitrine 1 » ne doit pas
 * déplacer les produits qu'elle porte, ce qu'un rattachement par libellé aurait
 * fait. L'ancienne table `ceo_prod_planogram` gardait justement zone, meuble et
 * niveau en TEXTE ; elle reste, complétée d'un `slot_id`, pour ne pas casser ce
 * qui la lit.
 */
function ensurePlanogramme(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS pla_zone ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'nom VARCHAR(80) NOT NULL,'
        . 'rang SMALLINT UNSIGNED NOT NULL DEFAULT 0'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS pla_meuble ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'zone_id INT UNSIGNED NOT NULL,'
        . 'nom VARCHAR(80) NOT NULL,'
        . 'rang SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'KEY idx_zone (zone_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS pla_niveau ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'meuble_id INT UNSIGNED NOT NULL,'
        . 'nom VARCHAR(80) NOT NULL,'
        . 'rang SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'KEY idx_meuble (meuble_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS pla_slot ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'niveau_id INT UNSIGNED NOT NULL,'
        . 'position SMALLINT UNSIGNED NOT NULL,'
        . 'largeur_mm SMALLINT UNSIGNED NULL,'
        . 'capacite SMALLINT UNSIGNED NULL,'
        . 'UNIQUE KEY uniq_pos (niveau_id, position)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Le placement : quelle référence occupe quel emplacement, et comment.
    // `slot_id` est la vérité ; zone / meuble / niveau / position sont recopiés
    // en clair parce que le référentiel produit les lit sous cette forme et n'a
    // pas à recharger tout l'arbre pour afficher « Vitrine 1 · haut · 4 ».
    Db::exec('CREATE TABLE IF NOT EXISTS pla_placement ('
        . 'ref VARCHAR(24) PRIMARY KEY,'
        . 'slot_id INT UNSIGNED NULL,'
        . 'zone VARCHAR(160) NOT NULL DEFAULT \'\','
        . 'meuble VARCHAR(80) NOT NULL DEFAULT \'\','
        . 'niveau VARCHAR(80) NOT NULL DEFAULT \'\','
        . 'slot SMALLINT UNSIGNED NULL,'
        . 'fronts SMALLINT UNSIGNED NOT NULL DEFAULT 1,'
        . 'ordre SMALLINT UNSIGNED NOT NULL DEFAULT 1,'
        . 'KEY idx_slot (slot_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Notes de présentation. `cible` distingue ce à quoi la note s'applique —
    // une consigne de meuble vaut pour tout ce qu'il contient, une consigne de
    // référence ne vaut que pour elle. Deux tables auraient dupliqué la même
    // forme et le même écran.
    Db::exec('CREATE TABLE IF NOT EXISTS pla_note ('
        . 'cible VARCHAR(12) NOT NULL,'          // ref | zone | meuble
        . 'cible_id VARCHAR(24) NOT NULL,'
        . 'texte TEXT NULL,'
        . 'epinglee TINYINT(1) NOT NULL DEFAULT 0,'
        . 'gravite TINYINT NOT NULL DEFAULT 3,'
        . 'du DATE NULL, au DATE NULL,'
        . 'auteur VARCHAR(190) NOT NULL DEFAULT \'\','
        . 'maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (cible, cible_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    planoMigrer();
}

/**
 * Reprise des tables antérieures vers le préfixe `pla_`.
 *
 * Une saisie peut être en cours au moment de la livraison : renommer sans
 * reprendre les lignes ferait disparaître un comptoir déjà déclaré, sans erreur
 * visible — l'écran se contenterait de redevenir vide. On COPIE donc, en
 * gardant les identifiants (les enfants s'y rattachent), et seulement si la
 * table d'arrivée est vide : rejouer la migration ne doit pas écraser ce qui a
 * été saisi depuis.
 *
 * L'ancienne table n'est supprimée qu'après une copie vérifiée, ligne pour
 * ligne. En cas de doute elle reste en place : une table de trop ne coûte rien,
 * une table perdue si.
 */
function planoMigrer(): void
{
    $repris = [
        'ceo_plano_zone'   => ['pla_zone', 'id, nom, rang'],
        'ceo_plano_meuble' => ['pla_meuble', 'id, zone_id, nom, rang'],
        'ceo_plano_niveau' => ['pla_niveau', 'id, meuble_id, nom, rang'],
        'ceo_plano_slot'   => ['pla_slot', 'id, niveau_id, position, largeur_mm, capacite'],
        'ceo_plano_note'   => ['pla_note', 'cible, cible_id, texte, epinglee, gravite, du, au, auteur, maj_le'],
        'ceo_prod_planogram' => ['pla_placement', 'ref, slot_id, zone, meuble, niveau, slot, fronts, ordre'],
    ];
    foreach ($repris as $ancienne => [$nouvelle, $colonnes]) {
        try {
            $n = Db::row('SELECT COUNT(*) AS n FROM ' . $ancienne);
        } catch (PDOException $e) { continue; }   // déjà migrée, ou jamais créée
        $avant = (int) ($n['n'] ?? 0);
        try {
            $dest = Db::row('SELECT COUNT(*) AS n FROM ' . $nouvelle);
            if ((int) ($dest['n'] ?? 0) === 0 && $avant > 0) {
                Db::exec('INSERT INTO ' . $nouvelle . ' (' . $colonnes . ') SELECT ' . $colonnes . ' FROM ' . $ancienne);
            }
            $apres = Db::row('SELECT COUNT(*) AS n FROM ' . $nouvelle);
            if ((int) ($apres['n'] ?? 0) >= $avant) {
                Db::exec('DROP TABLE ' . $ancienne);
            }
        } catch (PDOException $e) {
            // Colonne absente d'une installation plus ancienne : on garde
            // l'ancienne table plutôt que de perdre ce qu'elle contient.
        }
    }
}

/**
 * Repères posés sur une photo de contrôle.
 *
 * Les repères sont stockés en VECTORIEL (coordonnées relatives 0..1 + texte),
 * jamais en image aplatie. Trois raisons : la photo de la boutique n'est jamais
 * modifiée, le repère reste modifiable et supprimable, et le rendu suit la
 * taille d'affichage sans se pixeliser. L'aplatir en PNG imposerait en plus de
 * relire la photo depuis son URL signée dans un canvas — ce que le navigateur
 * refuse sans en-têtes CORS sur le stockage du panel.
 */
function ensureAnnotation(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_task_annotation ('
        . 'id_shop INT UNSIGNED NOT NULL,'
        . 'id_task INT UNSIGNED NOT NULL,'
        . 'annot_date DATE NOT NULL,'
        . 'reperes MEDIUMTEXT NULL,'
        . 'auteur VARCHAR(190) NOT NULL DEFAULT \'\','
        . 'maj_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (id_shop, id_task, annot_date)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Données de RÉFÉRENCE de l'application (pas de démo métier), posées si elles
 * manquent. Une base neuve reste vide de données métier (magasins/projets), mais
 * l'app a besoin de cette structure pour FONCTIONNER : catégories du kanban
 * projet, types de rapport, modèles de rétroplanning et d'email. Sans elles,
 * l'assistant « Nouveau projet » et le reporting sont inutilisables. Même
 * philosophie que ensureValidation()/signalementDefaut() : idempotent, on ne
 * réécrit jamais une valeur déjà posée.
 */
function ensureReference(): void
{
    $poserSetting = static function (string $key, array $val): void {
        if (setting($key) === null) {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
                [$key, json_encode($val, JSON_UNESCAPED_UNICODE)]);
        }
    };
    // Marque du réseau (réel : les boutiques sont « Atelier by … »).
    $poserSetting('reseau', ['nom' => "L'Atelier by", 'sousTitre' => 'Pilotage Réseau']);
    // Renommage du sous-titre sur une installation déjà en service. On ne
    // remplace QUE l'ancien libellé livré : un sous-titre personnalisé depuis
    // l'écran Paramètres n'a pas à être écrasé par une mise à jour.
    $r = setting('reseau');
    if (is_array($r) && ($r['sousTitre'] ?? '') === 'Cockpit CEO — Réseau') {
        $r['sousTitre'] = 'Pilotage Réseau';
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['reseau', json_encode($r, JSON_UNESCAPED_UNICODE)]);
    }
    // Identité d'en-tête (pas une personne fictive : la marque, à personnaliser
    // dans Paramètres). Sans elle, l'avatar de l'en-tête reste vide.
    $poserSetting('utilisateur', ['initiales' => 'AB', 'nom' => "L'Atelier by", 'role' => 'CEO · admin']);
    // Libellés de mois : indispensables — chaque écran indexé par mois
    // (tableau, heatmap, marge, objectifs) lit M.MOIS[mois]. Sans eux, tout
    // rendu mensuel casse. Structure, pas une donnée métier.
    $poserSetting('moisLabels', ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc']);
    // Catégories du kanban projet + types de rapport (structure, pas des données).
    $poserSetting('scoring', scoringDefaut());
    $poserSetting('familles', ['Produits', 'Services', 'Organisation & coûts', 'Développement réseau']);
    $poserSetting('reportTypes', ['Financier', 'Commercial', 'Contrôle qualité', 'Pilotage projets', 'Développement réseau']);

    if ((int) Db::row('SELECT COUNT(*) AS n FROM ceo_email_template')['n'] === 0) {
        foreach (emailTemplatesDefaut() as $t) {
            Db::exec('INSERT INTO ceo_email_template (id, name, subject, body) VALUES (?,?,?,?)', $t);
        }
    }
    if ((int) Db::row('SELECT COUNT(*) AS n FROM ceo_project_template')['n'] === 0) {
        foreach (projectTemplatesDefaut() as $t) {
            Db::exec('INSERT INTO ceo_project_template (axe, jalons_json, couts_json) VALUES (?,?,?)', $t);
        }
    }
}

/**
 * Référentiel de production (partie franchiseur du dashboard Recuissons).
 *
 * Créé à chaud comme ensureValidation() : sur une installation déjà en service,
 * `schema.sql` ne repasse pas. Aucune donnée métier n'est posée — le catalogue
 * se remplit depuis l'écran ou par import ; seuls les réglages du moteur ont
 * des valeurs par défaut, sans lesquelles l'écran serait inutilisable.
 */
function ensureProduction(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_prod_product ('
        . 'ref VARCHAR(24) PRIMARY KEY, nom VARCHAR(160) NOT NULL,'
        . 'categorie VARCHAR(60) NOT NULL DEFAULT \'\', prep INT UNSIGNED NOT NULL DEFAULT 0,'
        . 'cuisson INT UNSIGNED NOT NULL DEFAULT 0, fin INT UNSIGNED NOT NULL DEFAULT 0,'
        . 'bmin SMALLINT UNSIGNED NOT NULL DEFAULT 0, bmult SMALLINT UNSIGNED NOT NULL DEFAULT 1,'
        . 'four SMALLINT UNSIGNED NOT NULL DEFAULT 0, dlv SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'mat DECIMAL(8,3) NULL, prix DECIMAL(8,2) NULL, must TINYINT(1) NOT NULL DEFAULT 0,'
        . 'qmin SMALLINT UNSIGNED NOT NULL DEFAULT 0, periods VARCHAR(120) NOT NULL DEFAULT \'\','
        . 'profil VARCHAR(120) NOT NULL DEFAULT \'\', pwa_id BIGINT UNSIGNED NULL,'
        . 'actif TINYINT(1) NOT NULL DEFAULT 1, KEY idx_pwa (pwa_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Le placement au comptoir a rejoint `pla_placement` (voir
    // ensurePlanogramme). Le recréer ici le ferait naître à chaque appel pour
    // être aussitôt repris et supprimé par la migration.

    // Réglages du moteur : structure indispensable au calcul, pas des données.
    if (setting('production') === null) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
            ['production', json_encode([
                'fenetreSemaines' => 6,      // fenêtre statistique glissante
                'observationsMin' => 3,      // en deçà : série non fiable
                'tauxHoraire'     => 24.0,   // charges comprises
                'arrondi'         => 'batch',
                'fours'           => 2,
                'ouverture'       => '07:00',
                // En deçà de cette part du prix, un coût matière est jugé
                // incomplet : la marge n'est ni affichée ni comptée dans le
                // score. C'est un jugement métier, donc un réglage.
                'coutRatioMin'    => 0.05,
            ], JSON_UNESCAPED_UNICODE)]);
    }
}

/** Modèles d'email de relance livrés (réglables ensuite dans l'app). */
function emailTemplatesDefaut(): array
{
    $sig = "\n\nBien à vous,\nDirection réseau — L'Atelier by";
    return [
        ['e1', 'Rappel J-3 avant échéance', "[L'Atelier by] Échéance dans 3 jours — {tache}",
            "Bonjour {destinataire},\n\nPetit rappel : la tâche « {tache} » (projet {projet}) arrive à échéance le {echeance}.\nMerci de confirmer que la livraison est en bonne voie, ou de signaler tout blocage dès maintenant." . $sig],
        ['e2', 'Relance J+1 en retard', "[L'Atelier by] Tâche en retard — {tache}",
            "Bonjour {destinataire},\n\nLa tâche « {tache} » (projet {projet}) était attendue le {echeance} et n'est pas livrée.\nMerci de nous transmettre sous 48 h : la nouvelle date ferme, la cause du retard et le plan de rattrapage." . $sig],
        ['e3', 'Candidat sans activité', "[L'Atelier by] Votre projet de franchise",
            "Bonjour {destinataire},\n\nNous restons sans nouvelle depuis quelques semaines concernant votre projet d'ouverture ({zone}).\nSouhaitez-vous poursuivre ? Nous pouvons planifier un point téléphonique cette semaine." . $sig],
    ];
}

/** Modèles de rétroplanning par axe (jalons + postes de coût). */
function projectTemplatesDefaut(): array
{
    return [
        ['Ventes',
            '[{"nom":"Étude & cadrage","j":-90},{"nom":"Test magasins pilotes","j":-45},{"nom":"Déploiement réseau","j":0}]',
            '[{"poste":"Jours-homme consultants","prevu":10000},{"poste":"Print & PLV","prevu":3000}]'],
        ['Marge nette franchisé',
            '[{"nom":"État des lieux","j":-90},{"nom":"Plan d\'action validé","j":-45},{"nom":"Mise en œuvre réseau","j":0}]',
            '[{"poste":"Jours-homme consultants","prevu":8000},{"poste":"Licences & outils","prevu":2000}]'],
        ['Développement réseau',
            '[{"nom":"Signature bail","j":-120},{"nom":"Travaux & agencement","j":-40},{"nom":"Recrutement & formation","j":-10},{"nom":"Ouverture","j":0}]',
            '[{"poste":"Agencement","prevu":30000},{"poste":"Jours-homme consultants","prevu":9000},{"poste":"Autres (juridique, permis)","prevu":4000}]'],
        ['Produit — Interne (production)',
            '[{"nom":"Recette & fiche technique","j":-100},{"nom":"Essais production","j":-60},{"nom":"Test magasins pilotes","j":-30},{"nom":"Lancement réseau","j":0}]',
            '[{"poste":"Jours-homme consultants","prevu":6000},{"poste":"Essais production & matières","prevu":4000},{"poste":"Print & PLV","prevu":2500}]'],
        ['Produit — Externe (achat)',
            '[{"nom":"Sourcing fournisseurs","j":-100},{"nom":"Appel d\'offres & dégustation","j":-60},{"nom":"Contrat & référencement","j":-30},{"nom":"Lancement réseau","j":0}]',
            '[{"poste":"Jours-homme consultants","prevu":5000},{"poste":"Échantillons & tests","prevu":1500},{"poste":"Print & PLV","prevu":2500}]'],
    ];
}

/**
 * Les ajouts de la validation des tâches, à chaque démarrage.
 *
 * `ensureInstalled()` ne rejoue `schema.sql` que si `ceo_app_setting` est
 * absente, et `seed.sql` que si la base est vide : sur une installation DÉJÀ
 * en service, aucun des deux ne repasse. Sans ce bloc, la mise à jour laissait
 * une base sans colonne `note`, sans `ceo_task_issue` et sans réglage — donc
 * un écran de validation aux niveaux vides, où l'on ne peut plus rien
 * signaler. La panne était silencieuse : ni erreur, ni trace.
 *
 * Tout est idempotent : on regarde avant d'écrire.
 */
function ensureValidation(): void
{
    $manque = static function (string $table, string $colonne): bool {
        $r = Db::row('SELECT COUNT(*) AS n FROM information_schema.columns'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $colonne]);
        return (int) $r['n'] === 0;
    };
    if ($manque('ceo_project_task', 'note')) {
        Db::exec('ALTER TABLE ceo_project_task ADD COLUMN note TINYINT NULL');
    }
    if ($manque('ceo_project_task', 'validated_by')) {
        Db::exec('ALTER TABLE ceo_project_task ADD COLUMN validated_by VARCHAR(80) NULL');
    }
    // La date de validation, distincte de la livraison : sans elle, le suivi
    // situerait une validation d'aujourd'hui au mois où la tâche a été rendue.
    if ($manque('ceo_project_task', 'validated_at')) {
        Db::exec('ALTER TABLE ceo_project_task ADD COLUMN validated_at DATETIME NULL');
    }
    // La remise déclarée par le consultant depuis le panel : sans ces colonnes,
    // « rendue » ne dit pas QUI l'a dit, et c'était toujours la direction.
    if ($manque('ceo_project_task', 'delivered_by')) {
        Db::exec('ALTER TABLE ceo_project_task ADD COLUMN delivered_by VARCHAR(80) NULL');
    }
    if ($manque('ceo_project_task', 'delivery_note')) {
        Db::exec('ALTER TABLE ceo_project_task ADD COLUMN delivery_note TEXT NULL');
    }

    Db::exec('CREATE TABLE IF NOT EXISTS ceo_task_issue ('
        . 'id BIGINT AUTO_INCREMENT PRIMARY KEY,'
        . 'task_id VARCHAR(16) NOT NULL,'
        . 'note TINYINT NOT NULL,'
        . 'famille VARCHAR(60) NOT NULL,'
        . 'type VARCHAR(80) NOT NULL,'
        . 'comment TEXT NULL,'
        . 'recipients VARCHAR(400) NULL,'
        . "status ENUM('nouveau','vu','traite') NOT NULL DEFAULT 'nouveau',"
        . 'created_at DATETIME NOT NULL,'
        . 'created_by VARCHAR(80) NOT NULL,'
        . 'seen_at DATETIME NULL,'
        . 'closed_at DATETIME NULL,'
        . 'closed_by VARCHAR(80) NULL,'
        . 'KEY idx_task (task_id),'
        . 'KEY idx_status (status, created_at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    if (setting('signalement') === null) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
            ['signalement', json_encode(signalementDefaut(), JSON_UNESCAPED_UNICODE)]);
    }
}

/**
 * Les cinq niveaux, le seuil et le référentiel famille → type.
 *
 * LA source du référentiel livré. Elle n'est pas dans `seed.sql` : ce fichier
 * ne se charge que sur une base vide, et le réglage doit exister partout.
 * Modifiable ensuite par `PUT /parametres/signalement` — ce défaut ne réécrit
 * jamais une valeur déjà posée.
 *
 * Les mêmes cinq niveaux servent au panel consultant pour les tâches boutique :
 * un « majeur » doit vouloir dire la même chose des deux côtés, sinon les
 * chiffres des deux applications ne s'additionnent pas.
 */
function signalementDefaut(): array
{
    return [
        'seuil'   => 4,
        'niveaux' => [
            ['n' => 5, 'nom' => 'Exemplaire',              'couleur' => '#C9A227', 'aide' => "au-dessus de l'attendu"],
            ['n' => 4, 'nom' => 'Conforme',                'couleur' => '#2D7A3E', 'aide' => 'livrable accepté'],
            ['n' => 3, 'nom' => 'Non conforme — mineur',   'couleur' => '#D97706', 'aide' => 'détail à reprendre'],
            ['n' => 2, 'nom' => 'Non conforme — majeur',   'couleur' => '#C0182B', 'aide' => 'écart net, à reprendre'],
            ['n' => 1, 'nom' => 'Non conforme — critique', 'couleur' => '#8D1D2C', 'aide' => 'à reprendre immédiatement'],
        ],
        'familles' => [
            ['nom' => 'Livrable',           'types' => ['Incomplet', 'Non conforme au brief', 'Sans relecture', 'Format inexploitable']],
            ['nom' => 'Délai',              'types' => ['Rendu hors délai', 'Sans prévenir', 'Report répété']],
            ['nom' => 'Qualité de service', 'types' => ['Injoignable', 'Compte rendu absent', 'Consigne non suivie']],
            ['nom' => 'Budget',             'types' => ['Dépassement', 'Non justifié']],
            ['nom' => 'Autre',              'types' => ['À préciser']],
        ],
    ];
}

/**
 * Pondération et seuils du scoring produit.
 *
 * Le score arbitre la gamme : garder, conforter, retirer. Les poids (volume,
 * marge, position) et les deux seuils de verdict étaient écrits dans le
 * JavaScript — donc invisibles, non discutables et impossibles à ajuster sans
 * déploiement. Ils vivent ici, modifiables dans Paramètres, comme les seuils
 * de coûts et le barème de conformité.
 */
function scoringDefaut(): array
{
    return [
        // Quatre critères, pondérés (somme libre — la part effective est
        // recalculée) :
        //   volume    — médiane des 6 dernières semaines, par période
        //   marge     — marge NETTE : prix de vente moins matière et main d'œuvre
        //   perte     — pénalise les produits jetés en fin de journée
        //   comptoir  — rôle d'image du produit, présence au comptoir
        'poids'  => ['volume' => 40, 'marge' => 30, 'perte' => 20, 'comptoir' => 10],
        'seuils' => ['moteur' => 68, 'conforter' => 46],
        // Échelle ABSOLUE du taux de marge brute → note sur 100, définie par
        // deux bornes et linéaire entre elles (plafonnée aux extrémités).
        // Auparavant la note était relative à la gamme : la meilleure marge
        // valait 100, la moins bonne 0 — un produit changeait donc de note
        // quand un AUTRE produit bougeait, sans rien avoir changé lui-même.
        'marge'  => ['bas' => 20, 'basNote' => 20, 'haut' => 80, 'hautNote' => 100],
    ];
}

/** Le jeu de démonstration doit-il être chargé ? Désactivé par défaut. */
function seedEnabled(): bool
{
    if ((string) (getenv('COCKPIT_SEED') ?: '') === '1') { return true; }
    $cfg = Db::config();
    return !empty($cfg['seed']);
}

function isMissingTable(PDOException $e): bool
{
    return $e->getCode() === '42S02' || str_contains($e->getMessage(), '42S02')
        || str_contains($e->getMessage(), "doesn't exist");
}

/** Exécute un fichier SQL instruction par instruction (fins « ;\n »). */
function runSqlFile(string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Fichier SQL introuvable : ' . $path);
    }
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $stmt = trim($stmt);
        // ignorer le vide et les blocs entièrement en commentaire
        if ($stmt === '' || preg_match('/^(--[^\n]*\n?)+$/', $stmt)) { continue; }
        Db::pdo()->exec($stmt);
    }
}

/**
 * Centrale d'achat — tables dont le BO est PROPRIÉTAIRE.
 *
 * Le module lit surtout des sources amont (produits, ventes, magasins). Ne sont
 * créées ici que les tables dont le handoff dit qu'elles appartiennent au BO :
 * la demande de prix, qui naît de l'écran, et les réglages du moteur de marge.
 * Rien d'autre n'est inventé : une table vide ne remplace pas une API absente,
 * elle la déguise — les écrans concernés annoncent leur source manquante.
 */
function ensureCentrale(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ca_demande ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'fournisseur VARCHAR(120) NOT NULL DEFAULT \'\','
        . 'base VARCHAR(16) NOT NULL DEFAULT \'periode\','
        . 'du DATE NULL, au DATE NULL,'
        . 'campagne VARCHAR(60) NOT NULL DEFAULT \'\','
        . 'lignes MEDIUMTEXT NULL,'
        . 'total_qte INT UNSIGNED NOT NULL DEFAULT 0,'
        . 'total_cible DECIMAL(12,2) NOT NULL DEFAULT 0,'
        . 'statut VARCHAR(24) NOT NULL DEFAULT \'envoyée\','
        . 'cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'KEY idx_stat (statut)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Réglages du moteur : structure indispensable au calcul, pas des données.
    // Les taux viennent du handoff ; ils sont modifiables dans Réglages.
    if (setting('centrale') === null) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
            ['centrale', json_encode([
                'commissionMarquePct'   => 4.0,   // commission marque sur le CA
                'margeCentraleCiblePct' => 12.0,
                'tvaDefautPct'          => 6.0,   // Belgique : 6 % alimentaire
                'objectifBaissePrixPct' => 3.0,   // défaut d'une demande de prix
                'objectifHausseVolPct'  => 10.0,
            ], JSON_UNESCAPED_UNICODE)]);
    }
}
