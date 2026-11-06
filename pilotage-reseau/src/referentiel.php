<?php
declare(strict_types=1);

/*
 * MANUEL DU RÉSEAU — LE RÉFÉRENTIEL COMMUN (fusion du 06/11/2026).
 *
 * Décisions du client (docs/maquettes/fusion-manuel/PLAN.md, validées le 06/11/2026) :
 *  1. La colonne vertébrale est le référentiel v25 (src/data/referentiel_v25.json, clé `manuel`) : 8 domaines D1–D8
 *     et leurs 36 sous-catégories. La phase (les quatre parties du Manuel opératoire) et le poste (P1–P12) sont des
 *     étiquettes posées sur chaque standard ; les vues « par phase » et « par poste » se calculent.
 *  2. L'éditeur unique est ce module (Cockpit › Formation). Le CRM lit GET /formation/ref et ne garde que ce qui
 *     est propre à un dossier candidat.
 *  3. La clé d'une compétence est son code C-… du v25 (56 codes). Le panel TF Buddy garde l'état par employé :
 *     chaque code est relié à UNE compétence du panel (table de correspondance) ; « vu faire » coche celle-là.
 *
 *  ceo_ref_domaine   les 8 domaines (code D1…D8, rang, libellés FR et NL)
 *  ceo_ref_souscat   les 36 sous-catégories (clé « D5.3 » = rang dans l'ordre du v25), description, compétences,
 *                    formations et checklists du v25 — semées au premier appel, jamais réécrites ensuite
 *  ceo_ref_corresp   code C-… → compétence du panel : valide (choisie par une personne), certain (proposée sans
 *                    ambiguïté), propose (à trancher, jamais utilisée pour cocher), aucun
 *  ceo_sop           + souscat, phases, postes, competences, formations, checklists (listes JSON)
 *
 * Les libellés NL des domaines, sous-catégories, postes et phases sont fournis ici et SONT À RELIRE (traduction de
 * travail, 06/11/2026) ; une sous-catégorie relue dans le Classeur passe « relue » (nl_relu).
 *
 * Les deux guides que le CRM éditait encore (Guide pré-ouverture, Guide des formations et compétences : lot 4b)
 * vivent dans src/referentiel_guides.php ; ils s'ajoutent à GET /formation/ref (preouverture, blocs, poles).
 *
 * Deux reprises en brouillon : le Manuel opératoire du CRM (POST /formation/ref/importer-crm) et, depuis le lot 6 qui
 * retire l'écran « Manuel en ligne » du module, les procédures opérationnelles du panel (POST
 * /formation/ref/importer-panel).
 *
 * Contrat : docs/contrat-api.md § Formation · Manuel du réseau. Doc : docs/formation.md.
 */

const RF_PHASES = [   // les MN_PARTIES du Manuel opératoire — NL à relire
    'preouverture' => ['fr' => 'Pré-ouverture', 'nl' => 'Vóór de opening'],
    'ouverture' => ['fr' => 'Ouverture', 'nl' => 'Opening'],
    'operationnel' => ['fr' => 'Opérationnel', 'nl' => 'Operationeel'],
    'formation' => ['fr' => 'Formation continue', 'nl' => 'Permanente vorming'],
];
/**
 * Les phases d'une FORMATION (portail de formation restructuré, 06/11/2026) : quand on l'apprend. Une formation peut
 * vivre dans plusieurs phases (apprise avant l'ouverture, recyclée ensuite). Les standards (SOP) n'ont pas de phase
 * dans le portail : ils ont un moment de la journée (ouverture du magasin, journée, fermeture). NL à relire.
 */
const RF_PHASES_FORMATION = [
    'pre' => ['fr' => 'Pré-ouverture', 'court' => 'Pré-ouverture', 'nl' => 'Vóór de opening',
        'dfr' => 'Avant l’ouverture du magasin : la formation initiale du franchisé et de l’équipe, et les démarches à faire.',
        'dnl' => 'Vóór de opening van de winkel: de basisopleiding van de franchisenemer en het team, en de stappen die te zetten zijn.'],
    'ouv' => ['fr' => 'Ouverture', 'court' => 'Ouverture', 'nl' => 'Opening',
        'dfr' => 'La période de lancement : les six premières semaines avec le garant d’ouverture (contrat de franchise, article 4.5). Mise en route de la boutique, suivi des KPI et développement commercial.',
        'dnl' => 'De lanceringsperiode: de eerste zes weken met de openingsbegeleider (franchiseovereenkomst, artikel 4.5). Opstart van de winkel, opvolging van de KPI’s en commerciële ontwikkeling.'],
    'con' => ['fr' => 'Formation continue', 'court' => 'Continue', 'nl' => 'Permanente vorming',
        'dfr' => 'Après le lancement : les recyclages, la progression vers l’encadrement et le multi-boutique.',
        'dnl' => 'Na de lancering: de opfrissingen, de doorgroei naar leidinggevende functies en meerdere winkels.'],
];
/**
 * Le classement initial des 53 formations du v25 (posé une fois, modifiable ensuite : PUT /formation/ref/formations/{code}).
 * 32 viennent des blocs du Guide des formations ; 21 n'étaient dans aucun bloc ; l'Ouverture reçoit la période de
 * lancement, le suivi des KPI (FO-05) et le développement commercial (FO-15, FO-43, FO-45) — décisions du 06/11/2026.
 */
const RF_FORMATIONS_PHASES_2026_11 = [
    'FO-01' => ['pre'], 'FO-02' => ['pre'], 'FO-03' => ['pre', 'con'], 'FO-04' => ['pre'], 'FO-05' => ['pre', 'ouv', 'con'],
    'FO-06' => ['con'], 'FO-07' => ['pre'], 'FO-08' => ['pre'], 'FO-09' => ['pre'], 'FO-10' => ['pre'], 'FO-11' => ['pre'],
    'FO-12' => ['pre'], 'FO-13' => ['con'], 'FO-14' => ['pre'], 'FO-15' => ['ouv', 'con'], 'FO-16' => ['pre'], 'FO-17' => ['pre', 'con'],
    'FO-18' => ['con'], 'FO-19' => ['con'], 'FO-20' => ['pre'], 'FO-21' => ['con'], 'FO-22' => ['con'], 'FO-23' => ['con'],
    'FO-24' => ['ouv', 'con'], 'FO-25' => ['ouv'], 'FO-26' => ['pre'], 'FO-27' => ['ouv'], 'FO-28' => ['con'], 'FO-29' => ['pre', 'con'],
    'FO-30' => ['pre'], 'FO-31' => ['pre'], 'FO-32' => ['pre'], 'FO-33' => ['pre'], 'FO-34' => ['pre'], 'FO-35' => ['pre'],
    'FO-36' => ['pre'], 'FO-37' => ['pre'], 'FO-38' => ['pre'], 'FO-39' => ['con'], 'FO-40' => ['con'], 'FO-41' => ['con'],
    'FO-42' => ['con'], 'FO-43' => ['ouv', 'con'], 'FO-44' => ['con'], 'FO-45' => ['ouv', 'con'], 'FO-46' => ['pre'], 'FO-47' => ['pre'],
    'FO-48' => ['con'], 'FO-49' => ['con'], 'FO-50' => ['con'], 'FO-51' => ['pre'], 'FO-52' => ['pre'], 'FO-53' => ['pre'],
];
/** Les familles des formations (regroupement de lecture du portail). NL à relire. */
const RF_FAMILLES_FORMATION = [
    'franchise' => ['fr' => 'Franchise & gestion', 'nl' => 'Franchise & beheer',
        'fo' => ['FO-31', 'FO-32', 'FO-33', 'FO-34', 'FO-35', 'FO-36', 'FO-37', 'FO-38', 'FO-40', 'FO-42', 'FO-47', 'FO-48', 'FO-49', 'FO-50']],
    'production' => ['fr' => 'Production', 'nl' => 'Productie', 'fo' => ['FO-03', 'FO-07', 'FO-08', 'FO-09', 'FO-12']],
    'vente' => ['fr' => 'Vente & développement commercial', 'nl' => 'Verkoop & commerciële ontwikkeling',
        'fo' => ['FO-01', 'FO-04', 'FO-11', 'FO-13', 'FO-14', 'FO-15', 'FO-17', 'FO-18', 'FO-19', 'FO-20', 'FO-43', 'FO-45']],
    'traiteur' => ['fr' => 'Traiteur & snacking', 'nl' => 'Traiteur & snacking', 'fo' => ['FO-10', 'FO-46', 'FO-51', 'FO-52', 'FO-53']],
    'hygiene' => ['fr' => 'Hygiène & réglementation', 'nl' => 'Hygiëne & regelgeving', 'fo' => ['FO-02', 'FO-16', 'FO-26', 'FO-44']],
    'encadrement' => ['fr' => 'Encadrement', 'nl' => 'Leidinggeven',
        'fo' => ['FO-05', 'FO-06', 'FO-21', 'FO-22', 'FO-23', 'FO-24', 'FO-25', 'FO-27', 'FO-28', 'FO-29', 'FO-30', 'FO-39', 'FO-41']],
];
const RF_POSTES_NL = [   // les 12 postes du v25 — NL à relire
    'P1' => 'Medewerker brood & banket', 'P2' => 'Medewerker traiteur', 'P3' => 'Medewerker verkoop',
    'P4' => 'Afdelingschef verkoop', 'P5' => 'Afdelingschef brood & banket', 'P6' => 'Afdelingschef traiteur',
    'P7' => 'Adjunct-manager', 'P8' => 'Manager', 'P9' => 'Huurder-zaakvoerder', 'P10' => 'Kandidaat-franchisenemer',
    'P11' => 'Franchisenemer', 'P12' => 'Franchisenemer met meerdere winkels',
];
const RF_DOMAINES_NL = [   // NL à relire
    'D1' => 'Identiteit & cultuur', 'D2' => 'Imago & design', 'D3' => 'Communicatie', 'D4' => 'Klantervaring',
    'D5' => 'Operaties & productie', 'D6' => 'Human resources', 'D7' => 'Beheer & financiën', 'D8' => 'Conformiteit & kwaliteit',
];
const RF_SOUSCATS_NL = [   // NL à relire
    'D1.1' => 'Onze geschiedenis', 'D1.2' => 'Onze missie & visie', 'D1.3' => 'Onze waarden', 'D1.4' => 'Bedrijfscultuur',
    'D2.1' => 'Visuele identiteit', 'D2.2' => 'Inrichting & design', 'D2.3' => 'Verpakking & drukwerk',
    'D3.1' => 'Merkstrategie', 'D3.2' => 'Digitale communicatie', 'D3.3' => 'Lokale communicatie', 'D3.4' => 'Interne communicatie',
    'D3.5' => 'Click & collect / webshop / levering',
    'D4.1' => 'Klantbelofte', 'D4.2' => 'Onthaal & service', 'D4.3' => 'Klantenbinding',
    'D5.1' => 'Organisatie van het verkooppunt', 'D5.2' => 'Opening & sluiting', 'D5.3' => 'Productie',
    'D5.4' => 'Voorraad & bevoorrading', 'D5.5' => 'Uitrusting',
    'D6.1' => 'Rekrutering', 'D6.2' => 'Integratie & onboarding', 'D6.3' => 'Opleiding', 'D6.4' => 'Management & evaluatie',
    'D7.1' => 'Sturing van de activiteit', 'D7.2' => 'Kassa & betalingen', 'D7.3' => 'Financiële verplichtingen franchise',
    'D7.4' => 'Prijsbeleid', 'D7.5' => 'Waardering', 'D7.6' => 'Meerdere winkels & netwerkbeheer',
    'D8.1' => 'HACCP & voedselveiligheid', 'D8.2' => 'Kwaliteitscontrole', 'D8.3' => 'Wettelijke verplichtingen',
    'D8.4' => 'Verzekeringen & veiligheid', 'D8.5' => 'Crisisbeheer & continuïteit', 'D8.6' => 'AVG-conformiteit / klantgegevens',
];

/**
 * Le classement initial des 25 SOP (contrat de la fusion, 06/11/2026) : sous-catégorie, phases, postes,
 * compétences, formations, checklists. Posé UNE fois (drapeau RF_DRAPEAU_CLASSEMENT), seulement sur les SOP qui
 * n'ont pas encore de sous-catégorie ; modifiable ensuite dans le Cockpit.
 */
const RF_PRE_OP = ['preouverture', 'operationnel'];
const RF_OP = ['operationnel'];
const RF_CLASSEMENT_2026_11 = [
    'ouv-mag' => ['D5.2', RF_PRE_OP, ['P3', 'P4', 'P8'], ['C-PLAN.Ouverture'], ['FO-01'], ['AC-01']],
    'fermeture' => ['D5.2', RF_PRE_OP, ['P3', 'P4', 'P8'], ['C-PLAN.Fermeture'], ['FO-01'], ['AC-02', 'AC-03']],
    'ferm-quot' => ['D5.2', RF_OP, ['P3', 'P4'], ['C-PLAN.Fermeture'], ['FO-01'], ['AC-02']],
    'ouv-poste' => ['D5.2', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Cuisson'], ['FO-12'], ['AC-01']],
    'fin-poste' => ['D5.2', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Cuisson'], ['FO-12'], ['AC-02']],
    'calmes' => ['D5.1', RF_OP, ['P3', 'P4'], ['C-PLAN.Planning'], ['FO-24'], []],
    'caisse' => ['D7.2', RF_PRE_OP, ['P3', 'P4'], ['C-VENTE.Caisse'], ['FO-14', 'FO-01'], []],
    'recuisson' => ['D5.3', RF_OP, ['P3'], ['C-PROD.Cuisson'], ['FO-12'], []],
    'tgtg' => ['D5.4', RF_OP, ['P3', 'P4'], ['C-STOCK.Casse'], ['FO-27'], []],
    'fours' => ['D8.1', RF_OP, ['P1', 'P3', 'P5'], ['C-TRANS.HACCP'], ['FO-02'], []],
    'baguettes' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Pain', 'C-PROD.Cuisson'], ['FO-07', 'FO-12'], []],
    'vienn' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Viennoiserie', 'C-SANDWICH.Préparation'], ['FO-09', 'FO-51'], []],
    'cramiques' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Viennoiserie'], ['FO-09'], []],
    'memo' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Cuisson'], ['FO-12'], []],
    'plaquage' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Cuisson'], ['FO-12'], []],
    'programmes' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Cuisson'], ['FO-12'], []],
    'cookies' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Pâtisserie'], ['FO-08'], []],
    'cremes' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Pâtisserie'], ['FO-08'], []],
    'eclairs' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Pâtisserie'], ['FO-08'], []],
    'flans' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Pâtisserie'], ['FO-08'], []],
    'fondants' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Pâtisserie'], ['FO-08'], []],
    't-class' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Pâtisserie'], ['FO-08'], []],
    't-froides' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Pâtisserie'], ['FO-08'], []],
    't-fruits' => ['D5.3', RF_PRE_OP, ['P1', 'P5'], ['C-PROD.Pâtisserie'], ['FO-08'], []],
    'quiches' => ['D5.3', RF_PRE_OP, ['P1', 'P2'], ['C-TRAITEUR.Chaud'], ['FO-10'], []],
];
const RF_DRAPEAU_CLASSEMENT = 'rfClassement_2026_11';
const RF_LISTES = ['phases', 'postes', 'competences', 'formations', 'checklists'];

/** Les postes d'une personne du magasin (réglages de la tablette : vente, cuisson…) traduits en postes du v25. */
const RF_POSTES_PERSONNE = ['vente' => ['P3'], 'cuisson' => ['P1'], 'production' => ['P1'], 'nettoyage' => []];
/** Une personne qui valide « vu faire » (la responsable) tient aussi les postes d'encadrement. */
const RF_POSTES_VALIDEUR = ['P4', 'P5', 'P8'];

/** La reprise du Manuel opératoire du CRM (POST /formation/ref/importer-crm). */
const RF_CRM_EXCLUS = ['AC-01', 'AC-02', 'AC-03'];   // les checklists des SOP d'ouverture et de fermeture : déjà là
const RF_CRM_POSTES = ['vente' => 'P3', 'production' => 'P1', 'traiteur' => 'P2', 'responsable' => 'P8'];
const RF_CRM_MOMENTS = ['avant' => 'ouverture', 'service' => 'journee', 'rush' => 'journee', 'fermeture' => 'fermeture'];
const RF_CRM_POSTE_SOP = ['vente' => 'vente', 'production' => 'production', 'traiteur' => 'production', 'responsable' => 'vente'];

/** La proposition automatique des correspondances. */
const RF_AUTO = 'Proposition automatique';
const RF_SEUIL_CERTAIN = 0.75;   // score minimal d'une correspondance « certaine »…
const RF_ECART_CERTAIN = 0.25;   // … avec cet écart net sur la deuxième proposition
const RF_MOTS_VIDES = ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'en', 'au', 'aux', 'un', 'une', 'pour', 'par', 'avec',
    'sur', 'dans', 'ou', 'son', 'sa', 'ses', 'leur', 'leurs', 'd', 'l', 'a', 'the', 'of', 'and'];

/* ═══ Schéma et semis ═════════════════════════════════════════════════════════════════════════════ */

/** Les tables du référentiel, les colonnes de classement des SOP, le semis du v25 et le classement initial. */
function ensureReferentiel(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ref_domaine ('
        . 'code VARCHAR(4) NOT NULL PRIMARY KEY,'
        . 'rang INT NOT NULL DEFAULT 0,'
        . 'fr VARCHAR(120) NOT NULL,'
        . "nl VARCHAR(120) NOT NULL DEFAULT '',"
        . 'nl_relu TINYINT(1) NOT NULL DEFAULT 0,'
        . 'maj_le DATETIME NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ref_souscat ('
        . 'cle VARCHAR(8) NOT NULL PRIMARY KEY,'
        . 'domaine VARCHAR(4) NOT NULL,'
        . 'rang INT NOT NULL DEFAULT 0,'
        . 'fr VARCHAR(160) NOT NULL,'
        . "nl VARCHAR(160) NOT NULL DEFAULT '',"
        . 'nl_relu TINYINT(1) NOT NULL DEFAULT 0,'
        . 'description TEXT NULL,'
        . 'competences TEXT NULL,'
        . 'formations TEXT NULL,'
        . 'checklists TEXT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL,'
        . 'KEY idx_domaine (domaine, rang)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Les codes C-… portent des accents (C-PROD.Pâtisserie) : comparés à l'octet près, jamais confondus.
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ref_corresp ('
        . 'code VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,'
        . 'panel_id INT NULL,'
        . 'panel_nom VARCHAR(190) NULL,'
        . "etat VARCHAR(10) NOT NULL DEFAULT 'aucun',"
        . 'score DECIMAL(4,3) NULL,'
        . 'propositions TEXT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    foreach (['souscat VARCHAR(8) NULL', 'phases VARCHAR(160) NULL', 'postes VARCHAR(160) NULL', 'competences TEXT NULL',
        'formations TEXT NULL', 'checklists TEXT NULL'] as $col) {
        try { Db::exec('ALTER TABLE ceo_sop ADD COLUMN ' . $col); } catch (PDOException $e) { /* déjà là */ }
    }
    // Les phases de chaque formation (portail de formation, 06/11/2026) : posées une fois, jamais réécrites ensuite.
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ref_formation ('
        . 'code VARCHAR(12) NOT NULL PRIMARY KEY,'
        . 'phases VARCHAR(40) NOT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $now = date('Y-m-d H:i:s');
    foreach (RF_FORMATIONS_PHASES_2026_11 as $code => $ph) {
        Db::exec('INSERT IGNORE INTO ceo_ref_formation (code, phases, maj_le, maj_par) VALUES (?, ?, ?, ?)', [$code, rfJsonEcrire($ph), $now, 'Classement initial']);
    }
    rfSemer();
    rfClasserUneFois();
    // Les deux guides que le CRM éditait (Guide pré-ouverture, Guide des formations et compétences ; lot 4b).
    if (function_exists('ensureRefGuides')) { ensureRefGuides(); }
}

/** Les domaines et sous-catégories du v25 absents de la base y entrent (INSERT IGNORE : rien n'est réécrit). */
function rfSemer(): void
{
    $now = date('Y-m-d H:i:s');
    foreach (rfV25()['manuel'] ?? [] as $i => $d) {
        $code = (string) ($d['code'] ?? '');
        if (!preg_match('/^D\d$/', $code)) { continue; }
        Db::exec('INSERT IGNORE INTO ceo_ref_domaine (code, rang, fr, nl, nl_relu, maj_le) VALUES (?, ?, ?, ?, 0, ?)',
            [$code, $i + 1, (string) $d['nom'], RF_DOMAINES_NL[$code] ?? '', $now]);
        foreach ($d['sousCategories'] ?? [] as $k => $s) {
            $cle = $code . '.' . ($k + 1);
            Db::exec('INSERT IGNORE INTO ceo_ref_souscat (cle, domaine, rang, fr, nl, nl_relu, description, competences, formations, checklists, maj_le, maj_par)
                      VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
                [$cle, $code, $k + 1, (string) $s['nom'], RF_SOUSCATS_NL[$cle] ?? '', (string) ($s['description'] ?? '') ?: null,
                    rfJsonEcrire($s['competences'] ?? []), rfJsonEcrire($s['formations'] ?? []), rfJsonEcrire($s['checklists'] ?? []), $now, 'Référentiel v25']);
        }
    }
}

/** Le classement initial du contrat, une seule fois, seulement sur les SOP encore sans sous-catégorie. */
function rfClasserUneFois(): void
{
    if (setting(RF_DRAPEAU_CLASSEMENT) !== null) { return; }
    $n = 0;
    $now = date('Y-m-d H:i:s');
    foreach (RF_CLASSEMENT_2026_11 as $id => [$sc, $ph, $po, $co, $fo, $ch]) {
        $n += Db::exec('UPDATE ceo_sop SET souscat = ?, phases = ?, postes = ?, competences = ?, formations = ?, checklists = ?, maj_le = ?, maj_par = ?
                        WHERE id = ? AND (souscat IS NULL OR souscat = \'\')',
            [$sc, rfJsonEcrire($ph), rfJsonEcrire($po), rfJsonEcrire($co), rfJsonEcrire($fo), rfJsonEcrire($ch), $now, 'Classement initial', $id]);
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [RF_DRAPEAU_CLASSEMENT, json_encode(['le' => date('c'), 'classees' => $n])]);
    if ($n > 0) {
        try { journalAdd('Installation', 'Formation', 'Manuel du réseau', $n . ' SOP classées dans le référentiel v25 (sous-catégorie, phases, postes, compétences, formations, checklists)'); } catch (Throwable $e) { /* le journal ne bloque pas */ }
    }
}

/* ═══ Le référentiel v25 et ses codes ═════════════════════════════════════════════════════════════ */

/** Le référentiel v25 (l'Excel), tel que tools/formation/referentiel_excel.py l'a écrit. */
function rfV25(): array
{
    static $v = null;
    if ($v === null) {
        $v = function_exists('pcV25') ? pcV25() : (json_decode((string) @file_get_contents(__DIR__ . '/data/referentiel_v25.json'), true) ?: []);
        $v += ['postes' => [], 'formations' => [], 'plans' => [], 'competences' => [], 'manuel' => []];
    }
    return $v;
}

/** [code => ligne] des 56 compétences, des 53 formations, des 12 postes du v25. */
function rfParCode(string $cle): array
{
    static $c = [];
    if (!isset($c[$cle])) {
        $c[$cle] = [];
        foreach (rfV25()[$cle] ?? [] as $x) { if (is_array($x) && isset($x['code'])) { $c[$cle][(string) $x['code']] = $x; } }
    }
    return $c[$cle];
}

/** Les checklists citées par les sous-catégories du v25 (AC-, CP-, CL-, CS-, CM-…), triées. */
function rfChecklistsV25(): array
{
    static $l = null;
    if ($l === null) {
        $l = [];
        foreach (rfV25()['manuel'] as $d) { foreach ($d['sousCategories'] ?? [] as $s) { foreach ($s['checklists'] ?? [] as $x) { $l[(string) $x] = true; } } }
        $l = array_keys($l);
        sort($l, SORT_NATURAL);
    }
    return $l;
}

/** Les codes cités par les sous-catégories mais absents de la feuille des compétences du v25 (C-ID.ADN…). */
function rfCompetencesNonDecrites(): array
{
    $vus = [];
    foreach (rfV25()['manuel'] as $d) { foreach ($d['sousCategories'] ?? [] as $s) { foreach ($s['competences'] ?? [] as $x) { $vus[(string) $x] = true; } } }
    return array_values(array_diff(array_keys($vus), array_keys(rfParCode('competences'))));
}

/** Les codes valides d'une liste de classement. */
function rfValides(string $liste): array
{
    return match ($liste) {
        'phases' => array_keys(RF_PHASES),
        'postes' => array_keys(rfParCode('postes')),
        'competences' => array_keys(rfParCode('competences')),
        'formations' => array_keys(rfParCode('formations')),
        'checklists' => rfChecklistsV25(),
        default => [],
    };
}

/** [codes reconnus, codes inconnus] : uniques, dans l'ordre donné ; la casse est rendue à celle du v25. */
function rfListeCodes(mixed $v, array $valides): array
{
    $bas = [];
    foreach ($valides as $c) { $bas[mb_strtolower((string) $c)] = (string) $c; }
    $ok = []; $ko = [];
    foreach (is_array($v) ? $v : [] as $x) {
        if (!is_scalar($x)) { $ko[] = '?'; continue; }
        $x = trim((string) $x);
        if ($x === '') { continue; }
        $c = in_array($x, $valides, true) ? $x : ($bas[mb_strtolower($x)] ?? null);
        if ($c === null) { $ko[] = mb_substr($x, 0, 60); continue; }
        if (!in_array($c, $ok, true)) { $ok[] = $c; }
    }
    return [$ok, $ko];
}

function rfJsonListe(mixed $s): array
{
    $d = is_string($s) && $s !== '' ? json_decode($s, true) : null;
    return is_array($d) ? array_values(array_map('strval', array_filter($d, 'is_scalar'))) : [];
}

function rfJsonEcrire(array $l): string
{
    return (string) json_encode(array_values($l), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Le classement d'une SOP, tel que l'API le rend (foSopLigne l'ajoute à chaque SOP). */
function rfClassementDe(array $r): array
{
    $sc = trim((string) ($r['souscat'] ?? ''));
    $out = ['souscat' => $sc !== '' ? $sc : null];
    foreach (RF_LISTES as $k) { $out[$k] = rfJsonListe($r[$k] ?? null); }
    return $out;
}

/** Les postes v25 d'une personne du magasin : ses postes de la tablette, plus l'encadrement si elle valide. */
function rfPostesPersonne(array $postes, bool $valideur): array
{
    $out = [];
    foreach ($postes as $p) { foreach (RF_POSTES_PERSONNE[(string) $p] ?? [] as $c) { $out[$c] = true; } }
    if ($valideur) { foreach (RF_POSTES_VALIDEUR as $c) { $out[$c] = true; } }
    $out = array_keys($out);
    sort($out, SORT_NATURAL);
    return $out;
}

/**
 * Une SOP concerne une personne : un de ses postes v25 est celui de la SOP ; une SOP pas encore classée par poste
 * garde l'ancienne règle (son poste de tablette parmi ceux de la personne).
 */
function rfSopPourPersonne(array $sop, array $p): bool
{
    $postesSop = rfJsonListe($sop['postes'] ?? null);
    if ($postesSop !== []) {
        $v25 = $p['postesV25'] ?? rfPostesPersonne($p['postes'] ?? [], !empty($p['valideur']));
        return array_intersect($postesSop, $v25) !== [];
    }
    return in_array((string) ($sop['poste'] ?? ''), $p['postes'] ?? [], true);
}

/* ═══ GET /formation/ref — le contrat de lecture ══════════════════════════════════════════════════ */

/**
 * Le référentiel entier (contrat de la fusion, docs/contrat-api.md) : phases, postes, domaines et sous-catégories,
 * standards et leur classement, formations, compétences et leur compétence du panel, plans, manques.
 * `?detail=1` (écrans du Cockpit) ajoute quelques champs en plus, jamais à la place.
 */
function ep_rf_ref(): array
{
    $detail = !empty($_GET['detail']);
    $v = rfV25();
    $sops = Db::rows('SELECT * FROM ceo_sop ORDER BY dossier, ordre, id');
    $corr = rfCorrespLignes();
    $standards = [];
    $parComp = [];
    foreach ($sops as $r) {
        $s = rfStandard($r, $detail);
        foreach ($s['competences'] as $c) { $parComp[$c][] = $s['id']; }
        $standards[] = $s;
    }
    $domaines = [];
    $scs = [];
    foreach (Db::rows('SELECT * FROM ceo_ref_souscat ORDER BY domaine, rang, cle') as $s) { $scs[(string) $s['domaine']][] = $s; }
    foreach (Db::rows('SELECT * FROM ceo_ref_domaine ORDER BY rang, code') as $d) {
        $out = ['code' => (string) $d['code'], 'fr' => (string) $d['fr'], 'nl' => rfNl($d['nl']), 'souscats' => []];
        if ($detail) { $out['nlRelu'] = (int) $d['nl_relu'] === 1; }
        foreach ($scs[(string) $d['code']] ?? [] as $s) { $out['souscats'][] = rfSouscatLigne($s, $detail); }
        $domaines[] = $out;
    }
    $partie = $detail ? rfPartiesFormations() : [];
    $phasesFo = rfFormationsPhases();
    $compParFo = [];
    foreach ($v['competences'] as $c) { if (!empty($c['formation'])) { $compParFo[(string) $c['formation']][] = (string) $c['code']; } }
    $formations = [];
    foreach ($v['formations'] as $f) {
        $code = (string) $f['code'];
        // Ajoutés pour le portail (06/11/2026), rien n'est retiré : phases, postes (O obligatoire · R recommandée),
        // famille de lecture, compétences que la formation enseigne.
        $o = ['code' => $code, 'fr' => (string) $f['nom'], 'nl' => null, 'controleur' => $f['controleur'] ?? null, 'frequence' => $f['frequence'] ?? null,
            'phases' => $phasesFo[$code] ?? [], 'postes' => (object) ($f['attendu'] ?? []), 'famille' => rfFamilleFormation($code),
            'competences' => $compParFo[$code] ?? []];
        if ($detail) { $o += ['partie' => $partie[$code] ?? null, 'attendu' => (object) ($f['attendu'] ?? [])]; }
        $formations[] = $o;
    }
    $competences = [];
    foreach ($v['competences'] as $c) {
        $code = (string) $c['code'];
        $competences[] = ['code' => $code, 'famille' => (string) ($c['famille'] ?? ''), 'fr' => (string) $c['nom'], 'nl' => null,
            'description' => $c['description'] ?? null, 'formation' => $c['formation'] ?? null, 'standards' => $parComp[$code] ?? [],
            'panel' => rfPanelDe($corr[$code] ?? null)];
    }
    $sansStd = array_values(array_map(static fn ($c) => $c['code'], array_filter($competences, static fn ($c) => $c['standards'] === [])));
    $out = [
        'version' => rfVersion(),
        'phases' => array_map(static fn ($k, $x) => ['cle' => $k, 'fr' => $x['fr'], 'nl' => $x['nl']], array_keys(RF_PHASES), RF_PHASES),
        // Les phases des FORMATIONS (portail, 06/11/2026) ; `phases` ci-dessus reste celui des standards.
        'phasesFormation' => array_map(static fn ($k, $x) => ['cle' => $k, 'fr' => $x['fr'], 'court' => $x['court'], 'nl' => $x['nl'],
            'description' => ['fr' => $x['dfr'], 'nl' => $x['dnl']]], array_keys(RF_PHASES_FORMATION), RF_PHASES_FORMATION),
        'famillesFormation' => array_map(static fn ($k, $x) => ['cle' => $k, 'fr' => $x['fr'], 'nl' => $x['nl']], array_keys(RF_FAMILLES_FORMATION), RF_FAMILLES_FORMATION),
        'postes' => array_map(static fn ($p) => ['code' => (string) $p['code'], 'fr' => (string) $p['nom'], 'nl' => RF_POSTES_NL[(string) $p['code']] ?? null], $v['postes']),
        'domaines' => $domaines,
        'standards' => $standards,
        'formations' => $formations,
        'competences' => $competences,
        'plans' => array_map(static fn ($p) => ['code' => (string) $p['code'], 'fr' => (string) $p['nom']], $v['plans']),
        'manques' => [
            'competencesSansStandard' => $sansStd,
            'standardsSansCompetence' => array_values(array_map(static fn ($s) => $s['id'], array_filter($standards, static fn ($s) => $s['competences'] === []))),
            'standardsSansSouscat' => array_values(array_map(static fn ($s) => $s['id'], array_filter($standards, static fn ($s) => $s['souscat'] === null))),
        ],
        'formationUrl' => rfFormationUrl(),
    ];
    if ($detail) {
        $out['checklistsConnues'] = rfChecklistsV25();
        $out['competencesNonDecrites'] = rfCompetencesNonDecrites();
    }
    // Les deux guides (src/referentiel_guides.php) : preouverture, blocs, poles — ajoutés, rien n'est retiré.
    if (function_exists('rgRef')) { $out += rgRef($detail); }
    return $out;
}

/** Un standard (une SOP) tel que le contrat le décrit. */
function rfStandard(array $r, bool $detail = false): array
{
    $c = rfClassementDe($r);
    $s = ['id' => (string) $r['id'], 'code' => $r['code'], 'fr' => (string) $r['titre_fr'], 'nl' => rfNl($r['titre_nl']),
        'statut' => (string) $r['statut'], 'version' => (string) $r['version'], 'souscat' => $c['souscat'],
        'phases' => $c['phases'], 'postes' => $c['postes'], 'competences' => $c['competences'], 'formations' => $c['formations'],
        'checklists' => $c['checklists'], 'moment' => $r['moment'], 'lien' => '#/sop/' . $r['id']];
    if ($detail) {
        $s += ['type' => (string) $r['type'], 'trad' => ['fr' => (string) $r['trad_fr'], 'nl' => (string) $r['trad_nl']],
            'nbEtapes' => (int) $r['nb_etapes'], 'partie' => function_exists('foPartie') ? foPartie($r) : null,
            'competencePanel' => $r['competence_nom'] !== null ? ['id' => $r['competence_id'], 'nom' => (string) $r['competence_nom']] : null];
    }
    return $s;
}

function rfSouscatLigne(array $s, bool $detail): array
{
    $o = ['cle' => (string) $s['cle'], 'fr' => (string) $s['fr'], 'nl' => rfNl($s['nl']), 'description' => $s['description'],
        'competences' => rfJsonListe($s['competences']), 'formations' => rfJsonListe($s['formations']), 'checklists' => rfJsonListe($s['checklists'])];
    if ($detail) { $o['nlRelu'] = (int) $s['nl_relu'] === 1; }
    return $o;
}

function rfNl(mixed $v): ?string
{
    $v = trim((string) $v);
    return $v !== '' ? $v : null;
}

/** La compétence du panel d'un code C-… : id et nom seulement pour une correspondance valide ou certaine. */
function rfPanelDe(?array $c): array
{
    if ($c === null) { return ['id' => null, 'nom' => null, 'etat' => 'aucun']; }
    $sur = in_array($c['etat'], ['valide', 'certain'], true) && $c['panel_id'] !== null;
    return ['id' => $sur ? (int) $c['panel_id'] : null, 'nom' => $sur ? (string) $c['panel_nom'] : null, 'etat' => (string) $c['etat']];
}

/** [code FO => partie du manuel] : le réglage du cockpit, sinon la répartition par défaut. */
function rfPartiesFormations(): array
{
    $out = function_exists('mnFormationsDefaut') ? mnFormationsDefaut() : [];
    if (function_exists('ensureManuelFormations')) {
        try {
            ensureManuelFormations();
            foreach (Db::rows('SELECT code, partie FROM ceo_manuel_formation WHERE partie IS NOT NULL') as $r) {
                if (isset(RF_PHASES[(string) $r['partie']])) { $out[(string) $r['code']] = (string) $r['partie']; }
            }
        } catch (Throwable $e) { /* la répartition par défaut suffit */ }
    }
    return $out;
}

/** [code FO => phases] : la table du portail, sinon le classement initial (table absente ou ligne manquante). */
function rfFormationsPhases(): array
{
    $out = RF_FORMATIONS_PHASES_2026_11;
    try {
        foreach (Db::rows('SELECT code, phases FROM ceo_ref_formation') as $r) {
            $out[(string) $r['code']] = array_values(array_intersect(array_keys(RF_PHASES_FORMATION), rfJsonListe($r['phases'])));
        }
    } catch (Throwable $e) { /* le classement initial suffit */ }
    return $out;
}

/** La famille de lecture d'une formation (clé de RF_FAMILLES_FORMATION) ; null si elle n'est rangée nulle part. */
function rfFamilleFormation(string $code): ?string
{
    foreach (RF_FAMILLES_FORMATION as $k => $f) { if (in_array($code, $f['fo'], true)) { return $k; } }
    return null;
}

/** PUT /formation/ref/formations/{code} — {phases: ['pre' | 'ouv' | 'con', …]} : les phases d'une formation du v25. */
function wr_rf_formation(string $code): array
{
    if (!isset(rfParCode('formations')[$code])) { return rfRefus(404, 'formation inconnue du v25'); }
    $b = body();
    if (!is_array($b['phases'] ?? null)) { return rfRefus(422, 'phases attendues : une liste parmi pre, ouv, con'); }
    $inconnues = array_diff(array_map('strval', $b['phases']), array_keys(RF_PHASES_FORMATION));
    if ($inconnues !== []) { return rfRefus(422, 'phase inconnue : ' . implode(', ', $inconnues)); }
    $ph = array_values(array_intersect(array_keys(RF_PHASES_FORMATION), array_map('strval', $b['phases'])));   // ordre pre, ouv, con
    $par = foPar($b);
    $avant = rfFormationsPhases()[$code] ?? [];
    Db::exec('INSERT INTO ceo_ref_formation (code, phases, maj_le, maj_par) VALUES (?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE phases = VALUES(phases), maj_le = VALUES(maj_le), maj_par = VALUES(maj_par)',
        [$code, rfJsonEcrire($ph), date('Y-m-d H:i:s'), $par]);
    $nom = static fn (array $l) => $l === [] ? 'aucune' : implode(', ', array_map(static fn ($k) => RF_PHASES_FORMATION[$k]['fr'], $l));
    try { journalAdd($par, 'Formation', 'Formation ' . $code, 'Phases : ' . $nom($ph) . ' (avant : ' . $nom($avant) . ')'); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'code' => $code, 'phases' => $ph];
}

/** La date de la dernière écriture dans le référentiel (standards, sous-catégories, correspondances, phases des formations). */
function rfVersion(): ?string
{
    $max = null;
    foreach (['ceo_sop', 'ceo_ref_souscat', 'ceo_ref_domaine', 'ceo_ref_corresp', 'ceo_ref_formation'] as $t) {
        $m = Db::row('SELECT MAX(maj_le) AS m FROM ' . $t)['m'] ?? null;
        if ($m !== null && ($max === null || $m > $max)) { $max = $m; }
    }
    $g = function_exists('rgVersion') ? rgVersion() : null;   // les deux guides
    if ($g !== null && ($max === null || $g > $max)) { $max = $g; }
    return $max !== null ? (new DateTimeImmutable((string) $max))->format('c') : null;
}

/** Le chemin du module Formation pour les liens du CRM : réglage, sinon config.php, sinon FORMATION_URL ; vide sinon. */
function rfFormationUrl(): string
{
    $s = setting('formationUrl');
    if (is_string($s) && trim($s) !== '') { return trim($s); }
    $c = Db::config()['formationUrl'] ?? null;
    if (is_string($c) && trim($c) !== '') { return trim($c); }
    return trim((string) (getenv('FORMATION_URL') ?: ''));
}

/* ═══ Écritures : le classement d'un standard, une sous-catégorie ═════════════════════════════════ */

/**
 * PUT /formation/ref/standards/{id} — {souscat?, phases?, postes?, competences?, formations?, checklists?}.
 * Clé absente = inchangée ; chaque code est contrôlé contre le v25 (422 avec la liste des codes inconnus).
 */
function wr_rf_standard(string $id): array
{
    $r = foSopRow($id);
    if ($r === null) { return rfRefus(404, 'standard inconnu'); }
    $b = body();
    $set = []; $p = []; $ko = []; $quoi = [];
    if (array_key_exists('souscat', $b)) {
        $sc = is_scalar($b['souscat']) ? trim((string) $b['souscat']) : '';
        if ($sc === '') { $set[] = 'souscat = NULL'; $quoi[] = 'sous-catégorie retirée'; }
        elseif (Db::row('SELECT cle FROM ceo_ref_souscat WHERE cle = ?', [$sc]) === null) { $ko['souscat'] = [mb_substr($sc, 0, 20)]; }
        else { $set[] = 'souscat = ?'; $p[] = $sc; $quoi[] = 'sous-catégorie ' . $sc; }
    }
    foreach (RF_LISTES as $k) {
        if (!array_key_exists($k, $b)) { continue; }
        if (!is_array($b[$k])) { $ko[$k] = ['liste attendue']; continue; }
        [$ok, $inconnus] = rfListeCodes($b[$k], rfValides($k));
        if ($inconnus !== []) { $ko[$k] = $inconnus; continue; }
        $set[] = $k . ' = ?';
        $p[] = rfJsonEcrire($ok);
        $quoi[] = $k . ' ' . ($ok !== [] ? implode(', ', $ok) : '—');
    }
    if ($ko !== []) { return rfRefus(422, 'codes inconnus du référentiel v25', ['invalides' => $ko]); }
    if ($set === []) { return rfRefus(422, 'rien à changer : souscat, phases, postes, competences, formations ou checklists'); }
    $par = foPar($b);
    $set[] = 'maj_le = ?, maj_par = ?';
    array_push($p, date('Y-m-d H:i:s'), $par, $r['id']);
    Db::exec('UPDATE ceo_sop SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
    try { journalAdd($par, 'Formation', (string) $r['titre_fr'], 'Classement : ' . implode(' · ', $quoi)); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'standard' => rfStandard(foSopRow($id) ?? $r)];
}

/** PUT /formation/ref/souscats/{cle} — {nl?, description?}. Un libellé NL enregistré ici est « relu ». */
function wr_rf_souscat(string $cle): array
{
    $s = Db::row('SELECT * FROM ceo_ref_souscat WHERE cle = ?', [$cle]);
    if ($s === null) { return rfRefus(404, 'sous-catégorie inconnue'); }
    $b = body();
    $set = []; $p = []; $quoi = [];
    if (array_key_exists('nl', $b)) {
        $nl = is_scalar($b['nl']) ? trim((string) $b['nl']) : '';
        if ($nl === '' || mb_strlen($nl) > 160) { return rfRefus(422, 'libellé NL attendu (160 caractères au plus)'); }
        $set[] = 'nl = ?, nl_relu = 1'; $p[] = $nl; $quoi[] = 'NL « ' . $nl . ' » (relu)';
    }
    if (array_key_exists('description', $b)) {
        $d = is_scalar($b['description']) ? trim((string) $b['description']) : '';
        if (mb_strlen($d) > 2000) { return rfRefus(422, 'description : 2000 caractères au plus'); }
        $set[] = 'description = ?'; $p[] = $d !== '' ? $d : null; $quoi[] = 'description';
    }
    if ($set === []) { return rfRefus(422, 'rien à changer : nl ou description'); }
    $par = foPar($b);
    $set[] = 'maj_le = ?, maj_par = ?';
    array_push($p, date('Y-m-d H:i:s'), $par, $cle);
    Db::exec('UPDATE ceo_ref_souscat SET ' . implode(', ', $set) . ' WHERE cle = ?', $p);
    try { journalAdd($par, 'Formation', 'Sous-catégorie ' . $cle . ' · ' . $s['fr'], implode(' · ', $quoi)); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'souscat' => rfSouscatLigne(Db::row('SELECT * FROM ceo_ref_souscat WHERE cle = ?', [$cle]) ?? $s, true)];
}

/* ═══ La correspondance des compétences C-… ↔ panel ═══════════════════════════════════════════════ */

/** [code => ligne] de ceo_ref_corresp. */
function rfCorrespLignes(): array
{
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_ref_corresp') as $r) { $out[(string) $r['code']] = $r; }
    return $out;
}

/**
 * GET /formation/ref/correspondance — chaque code C-…, sa compétence du panel et ses propositions, la liste du
 * panel (et quels codes y sont reliés), la couverture. Table encore vide et panel lisible : la proposition
 * automatique se fait d'abord.
 */
function ep_rf_corresp(): array
{
    $panel = function_exists('foCompetencesPanel') ? foCompetencesPanel(!empty($_GET['rafraichir'])) : ['liste' => [], 'indispo' => true, 'motif' => 'module SOP absent'];
    $auto = null;
    if ((int) (Db::row('SELECT COUNT(*) AS n FROM ceo_ref_corresp')['n'] ?? 0) === 0 && $panel['liste'] !== []) {
        $auto = rfProposer($panel['liste'], RF_AUTO);
    }
    $corr = rfCorrespLignes();
    $stdParComp = [];
    foreach (Db::rows('SELECT id, titre_fr, competences FROM ceo_sop') as $r) {
        foreach (rfJsonListe($r['competences']) as $c) { $stdParComp[$c][] = ['id' => (string) $r['id'], 'titre' => (string) $r['titre_fr']]; }
    }
    $lignes = [];
    $compte = ['valide' => 0, 'certain' => 0, 'propose' => 0, 'aucun' => 0];
    $relies = [];
    foreach (rfV25()['competences'] as $c) {
        $code = (string) $c['code'];
        $k = $corr[$code] ?? null;
        $etat = $k !== null ? (string) $k['etat'] : 'aucun';
        $compte[$etat] = ($compte[$etat] ?? 0) + 1;
        if (in_array($etat, ['valide', 'certain'], true) && $k['panel_id'] !== null) { $relies[(int) $k['panel_id']][] = $code; }
        $lignes[] = ['code' => $code, 'famille' => (string) ($c['famille'] ?? ''), 'fr' => (string) $c['nom'], 'description' => $c['description'] ?? null,
            'formation' => $c['formation'] ?? null, 'standards' => $stdParComp[$code] ?? [], 'etat' => $etat,
            'panel' => $k !== null && $k['panel_id'] !== null ? ['id' => (int) $k['panel_id'], 'nom' => (string) $k['panel_nom']] : null,
            'score' => $k !== null && $k['score'] !== null ? (float) $k['score'] : null,
            'propositions' => $k !== null ? (json_decode((string) $k['propositions'], true) ?: []) : [],
            'tranche' => $k !== null && $k['maj_par'] !== RF_AUTO && in_array($etat, ['valide', 'aucun'], true),
            'majLe' => $k !== null ? foIso($k['maj_le']) : null, 'majPar' => $k['maj_par'] ?? null];
    }
    $liste = array_map(static fn ($x) => ['id' => $x['id'] !== null ? (int) $x['id'] : null, 'nom' => $x['nom'], 'categorie' => $x['categorie'] ?? null,
        'codes' => $x['id'] !== null ? ($relies[(int) $x['id']] ?? []) : []], $panel['liste']);
    return [
        'panel' => ['total' => count($panel['liste']), 'source' => $panel['source'] ?? '', 'indispo' => !empty($panel['indispo']),
            'motif' => $panel['motif'] ?? null, 'lueLe' => $panel['lueLe'] ?? null],
        'lignes' => $lignes,
        'liste' => $liste,
        'couverture' => $compte + ['total' => count($lignes), 'panelRelies' => count($relies), 'panelTotal' => count($panel['liste']),
            'sansStandard' => count(array_filter($lignes, static fn ($l) => $l['standards'] === []))],
        'auto' => $auto,
    ];
}

/** PUT /formation/ref/correspondance/{code} — {panelId: id | null} : choisie par une personne (valide), ou aucune. */
function wr_rf_corresp(string $code): array
{
    $c = rfParCode('competences')[$code] ?? null;
    if ($c === null) { return rfRefus(404, 'code de compétence inconnu du v25'); }
    $b = body();
    if (!array_key_exists('panelId', $b)) { return rfRefus(422, 'panelId attendu (identifiant de la compétence du panel, ou null)'); }
    $par = foPar($b);
    $avant = rfCorrespLignes()[$code] ?? null;
    $now = date('Y-m-d H:i:s');
    if ($b['panelId'] === null || $b['panelId'] === '') {
        Db::exec('INSERT INTO ceo_ref_corresp (code, panel_id, panel_nom, etat, score, propositions, maj_le, maj_par) VALUES (?, NULL, NULL, \'aucun\', NULL, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE panel_id = NULL, panel_nom = NULL, etat = \'aucun\', maj_le = VALUES(maj_le), maj_par = VALUES(maj_par)',
            [$code, $avant['propositions'] ?? null, $now, $par]);
        try { journalAdd($par, 'Formation', 'Compétence ' . $code, 'Aucune compétence du panel ne correspond (tranché)'); } catch (Throwable $e) { /* */ }
    } else {
        $pid = is_int($b['panelId']) || (is_string($b['panelId']) && ctype_digit($b['panelId'])) ? (int) $b['panelId'] : 0;
        $nom = null;
        foreach ((function_exists('foCompetencesPanel') ? foCompetencesPanel()['liste'] : []) as $x) { if ((int) ($x['id'] ?? 0) === $pid && $pid > 0) { $nom = (string) $x['nom']; break; } }
        if ($nom === null) { return rfRefus(422, 'compétence du panel inconnue (relire la liste du panel)'); }
        Db::exec('INSERT INTO ceo_ref_corresp (code, panel_id, panel_nom, etat, score, propositions, maj_le, maj_par) VALUES (?, ?, ?, \'valide\', NULL, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE panel_id = VALUES(panel_id), panel_nom = VALUES(panel_nom), etat = \'valide\', maj_le = VALUES(maj_le), maj_par = VALUES(maj_par)',
            [$code, $pid, mb_substr($nom, 0, 190), $avant['propositions'] ?? null, $now, $par]);
        try { journalAdd($par, 'Formation', 'Compétence ' . $code, 'Reliée à la compétence du panel « ' . $nom . ' » (#' . $pid . ')'); } catch (Throwable $e) { /* */ }
    }
    $k = rfCorrespLignes()[$code];
    return ['ok' => true, 'code' => $code, 'etat' => (string) $k['etat'], 'panel' => rfPanelDe($k)];
}

/** POST /formation/ref/correspondance/proposer — {rafraichir?} : relance la proposition automatique (jamais sur une ligne tranchée). */
function wr_rf_proposer(): array
{
    $b = body();
    $panel = function_exists('foCompetencesPanel') ? foCompetencesPanel(!empty($b['rafraichir'])) : ['liste' => []];
    if ($panel['liste'] === []) { return rfRefus(503, 'compétences du panel illisibles : ' . ($panel['motif'] ?? 'aucune'), ['indispo' => true]); }
    return ['ok' => true] + rfProposer($panel['liste'], foPar($b));
}

/**
 * La proposition automatique : pour chaque code C-…, le score de chaque compétence du panel (mots du nom et du code,
 * sans accents ni casse, mots vides retirés). `certain` seulement sans ambiguïté : meilleur score ≥ RF_SEUIL_CERTAIN,
 * unique, avec RF_ECART_CERTAIN d'avance sur le deuxième, et aucun autre code n'a un score aussi haut sur la même
 * compétence du panel (ni ne l'a déjà comme correspondance validée). Sinon `propose` avec les trois meilleures, ou
 * `aucun`. Une ligne tranchée par une personne (valide, ou aucune) n'est jamais réécrite.
 */
function rfProposer(array $panel, string $par): array
{
    $cands = [];
    foreach ($panel as $x) {
        if (!isset($x['id']) || $x['id'] === null || !ctype_digit((string) $x['id'])) { continue; }
        $cands[] = ['id' => (int) $x['id'], 'nom' => (string) $x['nom'], 'norm' => rfNorm((string) $x['nom']), 'mots' => rfMots((string) $x['nom'])];
    }
    $corr = rfCorrespLignes();
    $valides = [];
    foreach ($corr as $code => $r) { if ($r['etat'] === 'valide' && $r['panel_id'] !== null) { $valides[(int) $r['panel_id']] = $code; } }
    $scores = [];
    foreach (rfV25()['competences'] as $c) {
        $code = (string) $c['code'];
        $suffixe = str_contains($code, '.') ? substr($code, strpos($code, '.') + 1) : '';
        $mots = array_values(array_unique(array_merge(rfMots((string) $c['nom']), rfMots(str_replace('-', ' ', $suffixe)))));
        $norm = rfNorm((string) $c['nom']);
        foreach ($cands as $i => $k) { $scores[$code][$i] = $k['norm'] === $norm && $norm !== '' ? 1.0 : rfDice($mots, $k['mots']); }
    }
    $bilan = ['certain' => 0, 'propose' => 0, 'aucun' => 0, 'gardees' => 0, 'panel' => count($cands)];
    $now = date('Y-m-d H:i:s');
    foreach ($scores as $code => $sc) {
        $k = $corr[$code] ?? null;
        if ($k !== null && ($k['etat'] === 'valide' || ($k['etat'] === 'aucun' && $k['maj_par'] !== RF_AUTO && $k['maj_par'] !== null))) { $bilan['gardees']++; continue; }
        arsort($sc);
        $ordre = array_keys($sc);
        $i1 = $ordre[0] ?? null;
        $s1 = $i1 !== null ? $sc[$i1] : 0.0;
        $s2 = isset($ordre[1]) ? $sc[$ordre[1]] : 0.0;
        $props = [];
        foreach (array_slice($ordre, 0, 3) as $i) { if ($sc[$i] > 0) { $props[] = ['id' => $cands[$i]['id'], 'nom' => $cands[$i]['nom'], 'score' => round($sc[$i], 2)]; } }
        $certain = $i1 !== null && $s1 >= RF_SEUIL_CERTAIN && $s1 - $s2 >= RF_ECART_CERTAIN && !isset($valides[$cands[$i1]['id']]);
        if ($certain) {
            foreach ($scores as $autre => $sa) { if ($autre !== $code && ($sa[$i1] ?? 0) >= $s1) { $certain = false; break; } }
        }
        $etat = $certain ? 'certain' : ($props !== [] ? 'propose' : 'aucun');
        $bilan[$etat]++;
        Db::exec('INSERT INTO ceo_ref_corresp (code, panel_id, panel_nom, etat, score, propositions, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE panel_id = VALUES(panel_id), panel_nom = VALUES(panel_nom), etat = VALUES(etat), score = VALUES(score),
                    propositions = VALUES(propositions), maj_le = VALUES(maj_le), maj_par = VALUES(maj_par)',
            [$code, $certain ? $cands[$i1]['id'] : null, $certain ? mb_substr($cands[$i1]['nom'], 0, 190) : null, $etat,
                $i1 !== null ? round($s1, 3) : null, json_encode($props, JSON_UNESCAPED_UNICODE), $now, RF_AUTO]);
    }
    try { journalAdd($par, 'Formation', 'Correspondance des compétences', 'Proposition automatique : ' . $bilan['certain'] . ' certaines, '
        . $bilan['propose'] . ' à trancher, ' . $bilan['aucun'] . ' sans proposition, ' . $bilan['gardees'] . ' tranchées gardées (panel : ' . $bilan['panel'] . ' compétences)'); } catch (Throwable $e) { /* */ }
    return $bilan;
}

/** Un nom ramené à ses mots : minuscules, sans accents, mots vides retirés, pluriel et « e » final rabotés. */
function rfMots(string $s): array
{
    $out = [];
    foreach (preg_split('/[^a-z0-9]+/', rfSansAccents($s)) ?: [] as $m) {
        if ($m === '' || in_array($m, RF_MOTS_VIDES, true) || (strlen($m) < 2 && !ctype_digit($m))) { continue; }
        if (strlen($m) > 3 && preg_match('/[sx]$/', $m)) { $m = substr($m, 0, -1); }
        if (strlen($m) > 4 && str_ends_with($m, 'e')) { $m = substr($m, 0, -1); }
        $out[$m] = true;
    }
    return array_keys($out);
}

function rfNorm(string $s): string
{
    return implode(' ', rfMots($s));
}

function rfSansAccents(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($n)) { $s = (string) preg_replace('/\p{Mn}+/u', '', $n); }
    }
    return strtr($s, ['œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', '’' => ' ', "'" => ' ']);
}

/** Coefficient de Dice entre deux ensembles de mots. */
function rfDice(array $a, array $b): float
{
    if ($a === [] || $b === []) { return 0.0; }
    return 2 * count(array_intersect($a, $b)) / (count($a) + count($b));
}

/* ═══ « Vu faire » : les compétences C-… de la SOP, cochées au panel ══════════════════════════════ */

/**
 * Après un « vu faire » sur la SOP $r : pour chaque code C-… de la SOP, la compétence est-elle acquise par la
 * personne (chacune des SOP publiées qui portent ce code et la concernent — ses postes v25, ou commencée — vue
 * faire à la version en vigueur) ? Acquise et reliée au panel (valide ou certain) : elle est cochée au panel
 * (foCocherPanel : PUT /employees/{id}/competencies, relu, journalisé). Rend [] quand la SOP n'a aucun code.
 *
 * @return list<array{code:string, nom:string, acquise:bool, vues:int, total:int, panel:?array, coche:?array}>
 */
function rfVuFaire(string $shop, array $p, array $r): array
{
    $codes = rfJsonListe($r['competences'] ?? null);
    if ($codes === []) { return []; }
    $corr = rfCorrespLignes();
    $v25 = rfParCode('competences');
    $sops = Db::rows('SELECT * FROM ceo_sop');
    $suivi = function_exists('foSuiviMagasin') ? (foSuiviMagasin($shop)[$p['id']] ?? []) : [];
    $out = [];
    foreach ($codes as $code) {
        $conc = array_values(array_filter($sops, static fn ($s) => $s['statut'] === 'publiee' && in_array($code, rfJsonListe($s['competences']), true)
            && (rfSopPourPersonne($s, $p) || isset($suivi[(string) $s['id']]))));
        $sts = array_map(static fn ($s) => foStatut($suivi[(string) $s['id']] ?? null, $s), $conc);
        $vues = count(array_filter($sts, static fn ($x) => $x === 'vu'));
        $acquise = $conc !== [] && $vues === count($conc);
        $k = $corr[$code] ?? null;
        $relie = $k !== null && in_array($k['etat'], ['valide', 'certain'], true) && $k['panel_id'] !== null;
        $coche = null;
        if ($acquise && $relie && function_exists('foCocherPanel')) {
            $coche = foCocherPanel($shop, (int) $p['id'], (int) $k['panel_id'], (string) $k['panel_nom'], (string) $p['nom']);
        }
        $out[] = ['code' => $code, 'nom' => (string) ($v25[$code]['nom'] ?? $code), 'acquise' => $acquise, 'vues' => $vues, 'total' => count($conc),
            'panel' => $relie ? ['id' => (int) $k['panel_id'], 'nom' => (string) $k['panel_nom'], 'etat' => (string) $k['etat']] : null, 'coche' => $coche];
    }
    return $out;
}

/* ═══ La reprise du Manuel opératoire du CRM ══════════════════════════════════════════════════════ */

/**
 * POST /formation/ref/importer-crm — les procédures du Manuel opératoire du CRM (`crm_mo_procedures`, même base en
 * production) deviennent des SOP en BROUILLON (id `crm-<id>`, source `crm:<id>`), sauf AC-01, AC-02, AC-03 (les
 * checklists des SOP d'ouverture et de fermeture). Idempotent : une procédure déjà reprise ne l'est pas deux fois.
 * Chapitre → sous-catégorie par son nom ; postes vente → P3, production → P1, traiteur → P2, responsable → P8 ;
 * les étapes passent dans le format des SOP (le français ; le néerlandais reste à écrire). Les photos ne sont pas
 * reprises (elles vivent dans le CRM) : comptées dans le bilan et notées sur la SOP.
 */
function wr_rf_importer_crm(): array
{
    $t = Db::row('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['crm_mo_procedures']);
    if ((int) ($t['n'] ?? 0) === 0) {
        return ['ok' => true, 'disponible' => false, 'message' => 'Le Manuel opératoire du CRM (table crm_mo_procedures) n’est pas dans cette base.',
            'reprises' => [], 'dejaLa' => [], 'ignorees' => [], 'photosNonReprises' => 0];
    }
    $chap = [];
    $tc = Db::row('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['crm_mo_chapitres']);
    if ((int) ($tc['n'] ?? 0) > 0) { foreach (Db::rows('SELECT id, nom FROM crm_mo_chapitres') as $c) { $chap[(int) $c['id']] = (string) $c['nom']; } }
    $souscats = Db::rows('SELECT cle, fr FROM ceo_ref_souscat ORDER BY domaine, rang');
    $par = foPar(body());
    $bilan = ['ok' => true, 'disponible' => true, 'reprises' => [], 'dejaLa' => [], 'ignorees' => [], 'photosNonReprises' => 0];
    $ordre = (int) (Db::row('SELECT COALESCE(MAX(ordre), 0) AS o FROM ceo_sop')['o'] ?? 0);
    foreach (Db::rows('SELECT * FROM crm_mo_procedures ORDER BY position, code, id') as $m) {
        $code = strtoupper(trim((string) $m['code']));
        $titre = trim((string) $m['titre']);
        $ligne = ['id' => 'crm-' . (int) $m['id'], 'code' => $code, 'titre' => $titre];
        if (in_array($code, RF_CRM_EXCLUS, true)) { $bilan['ignorees'][] = $ligne + ['raison' => 'checklist des SOP d’ouverture et de fermeture (déjà dans le référentiel)']; continue; }
        if ($titre === '') { $bilan['ignorees'][] = $ligne + ['raison' => 'sans titre']; continue; }
        if (Db::row('SELECT id FROM ceo_sop WHERE id = ? OR source = ?', [$ligne['id'], 'crm:' . (int) $m['id']]) !== null) { $bilan['dejaLa'][] = $ligne; continue; }
        [$contenu, $fo, $photos] = rfContenuCrm($m);
        $bilan['photosNonReprises'] += $photos;
        $postesCrm = array_values(array_filter(array_map('trim', explode(',', (string) $m['postes']))));
        $postes = array_values(array_unique(array_filter(array_map(static fn ($x) => RF_CRM_POSTES[$x] ?? null, $postesCrm))));
        sort($postes, SORT_NATURAL);
        [$fo] = rfListeCodes(array_merge(rfJsonListe($m['formations'] ?? null), $fo), rfValides('formations'));
        [$co] = rfListeCodes([trim((string) ($m['competence'] ?? ''))], rfValides('competences'));
        $partie = isset(RF_PHASES[(string) $m['partie']]) ? (string) $m['partie'] : null;
        $moment = RF_CRM_MOMENTS[(string) $m['moment']] ?? null;
        // L'ancien poste de tablette : le premier poste de terrain (un responsable n'en dit rien).
        $poste = RF_CRM_POSTE_SOP[array_values(array_diff($postesCrm, ['responsable']))[0] ?? ($postesCrm[0] ?? '')] ?? 'vente';
        $dossier = $moment === 'fermeture' ? '03' : (in_array($poste, ['production'], true) ? '02' : '01');
        $sc = rfSouscatParNom($chap[(int) ($m['chapitre_id'] ?? 0)] ?? '', $souscats);
        $st = foStats($contenu);
        $now = date('Y-m-d H:i:s');
        $ordre++;
        Db::exec('INSERT INTO ceo_sop (id, code, type, dossier, poste, partie, moment, statut, version, version_revalider, langue_origine, trad_fr, trad_nl,
                    titre_fr, titre_nl, objectif_fr, objectif_nl, duree, nb_etapes, nb_photos, ordre, source, maj_le, maj_par,
                    souscat, phases, postes, competences, formations, checklists)
                  VALUES (?, ?, \'SOP\', ?, ?, ?, ?, \'brouillon\', \'1.0\', \'1.0\', \'FR\', \'origine\', \'a_relire\', ?, \'\', ?, NULL, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $ligne['id'], mb_substr($code, 0, 40), $dossier, $poste, $partie, $moment, mb_substr($titre, 0, 190), $contenu['objectif']['fr'] ?: null,
            foCourt($contenu['duree'], 190), $st['etapes'], $ordre, 'crm:' . (int) $m['id'], $now, $par,
            $sc, rfJsonEcrire($partie !== null ? [$partie] : []), rfJsonEcrire($postes), rfJsonEcrire($co), rfJsonEcrire($fo), '[]']);
        Db::exec('INSERT INTO ceo_sop_version (sop_id, version, contenu, note, revalider, publie_le, publie_par) VALUES (?, \'1.0\', ?, ?, 0, ?, ?)',
            [$ligne['id'], foJson($contenu), 'Reprise du Manuel opératoire du CRM (' . $code . ', brouillon)', $now, $par]);
        $bilan['reprises'][] = $ligne + ['souscat' => $sc];
    }
    try { journalAdd($par, 'Formation', 'Manuel du réseau', 'Reprise du Manuel opératoire du CRM : ' . count($bilan['reprises']) . ' procédure(s) en brouillon, '
        . count($bilan['dejaLa']) . ' déjà là, ' . count($bilan['ignorees']) . ' ignorée(s), ' . $bilan['photosNonReprises'] . ' photo(s) non reprise(s)'); } catch (Throwable $e) { /* */ }
    return $bilan;
}

/**
 * Le contenu d'une procédure du CRM au format des SOP : une section « Étapes » ; chaque étape garde son titre et
 * sa consigne (FR), un point critique devient une étape critique et un point critique de la SOP ; les vidéos et
 * les photos non reprises sont notées dans les remarques. Rend [contenu, formations des étapes, photos].
 */
function rfContenuCrm(array $m): array
{
    $etapes = []; $crit = []; $rem = []; $fo = []; $photos = 0;
    $l = json_decode((string) ($m['etapes'] ?? ''), true);
    foreach (is_array($l) ? $l : [] as $i => $e) {
        if (!is_array($e)) { continue; }
        $t = trim((string) ($e['t'] ?? ''));
        $ti = trim((string) ($e['titre'] ?? ''));
        $cr = trim((string) ($e['crit'] ?? ''));
        if ($t === '' && $ti === '' && empty($e['media'])) { continue; }
        $n = count($etapes) + 1;
        $etapes[] = ['numero' => (string) $n, 'titre' => ['fr' => $ti, 'nl' => ''], 'texte' => ['fr' => $t !== '' ? $t : $ti, 'nl' => ''], 'critique' => $cr !== '', 'photo' => null];
        if ($cr !== '') { $crit[] = ['fr' => $cr, 'nl' => '']; }
        if (!empty($e['fo'])) { $fo[] = (string) $e['fo']; }
        if (!empty($e['media'])) { $photos++; $rem[] = 'Étape ' . $n . ' : la photo est restée dans le Manuel opératoire du CRM (non reprise).'; }
        if (is_string($e['video'] ?? null) && str_starts_with($e['video'], 'https://')) { $rem[] = 'Étape ' . $n . ' : vidéo ' . $e['video']; }
    }
    $rem[] = 'Reprise du Manuel opératoire du CRM (' . $m['code'] . ($m['version'] ? ', publiée en v' . (int) $m['version'] . ' dans le CRM' : ', brouillon dans le CRM')
        . ') : le néerlandais reste à écrire avant de publier.';
    if (trim((string) ($m['signe_par'] ?? '')) !== '') { $rem[] = 'Signée par : ' . trim((string) $m['signe_par']) . (trim((string) $m['heure']) !== '' ? ' · ' . $m['heure'] : ''); }
    $c = foContenu(['titre' => ['fr' => (string) $m['titre'], 'nl' => ''], 'objectif' => ['fr' => (string) ($m['objectif'] ?? ''), 'nl' => ''],
        'pour' => ['fr' => (string) ($m['qui'] ?? ''), 'nl' => ''], 'duree' => $m['duree'] !== null && (int) $m['duree'] > 0 ? (int) $m['duree'] . ' min' : null,
        'sections' => [['titre' => ['fr' => 'Étapes', 'nl' => 'Stappen'], 'etapes' => $etapes]], 'points_critiques' => $crit, 'remarques' => $rem]);
    return [$c, $fo, $photos];
}

/** La sous-catégorie d'un chapitre du CRM, par son nom : égalité des mots d'abord, sinon le meilleur recouvrement s'il est unique. */
function rfSouscatParNom(string $nom, array $souscats): ?string
{
    $m = rfMots($nom);
    if ($m === []) { return null; }
    $best = null; $bs = 0.0; $ex = false;
    foreach ($souscats as $s) {
        $ms = rfMots((string) $s['fr']);
        if ($ms !== [] && rfNorm((string) $s['fr']) === implode(' ', $m)) { return (string) $s['cle']; }
        $sc = rfDice($m, $ms);
        if ($sc > $bs) { $best = (string) $s['cle']; $bs = $sc; $ex = false; } elseif ($sc > 0 && $sc === $bs) { $ex = true; }
    }
    return $best !== null && !$ex && $bs >= 0.4 ? $best : null;
}

/* ═══ La reprise des procédures du panel (lot 6) ══════════════════════════════════════════════════ */

/**
 * POST /formation/ref/importer-panel — les procédures opérationnelles du panel TF Buddy (celles que lisait l'écran
 * « Manuel en ligne », retiré au lot 6 de la fusion) deviennent des SOP en BROUILLON : id `panel-<id>`, source
 * `panel:<id>`, code `TFB-<id>` (le code que le CRM leur donnait). Idempotent. Une procédure n'est pas reprise quand
 * elle est déjà un standard : déjà reprise (id ou source), reprise par le Manuel du CRM (code TFB-<id>), ou une SOP de
 * même titre (mêmes mots). Lecture de chaque procédure = celle de la page du manuel (fmProcedureLue : sections dans
 * leur ordre, pièces jointes) ; une section restée vide ou à « 1 » dans le panel est sautée. Sous-catégorie déduite
 * par le nom de la sous-catégorie, puis de la catégorie du panel (rfSouscatParNom) ; sinon vide, signalée dans le
 * bilan (`aClasser`). Phase = la partie du manuel réglée dans le cockpit (ceo_manuel_procedure), sinon déduite comme
 * le faisait l'écran (section ou catégorie « Formation… » ou « Contrôle continu » → formation continue, le reste →
 * opérationnel). Les vidéos réglées dans le cockpit et les pièces jointes du panel sont notées dans les remarques
 * (non reprises). Rien n'est écrit au panel.
 */
function wr_rf_importer_panel(): array
{
    $vide = ['reprises' => [], 'dejaLa' => [], 'ignorees' => [], 'erreurs' => [], 'aClasser' => []];
    if (!class_exists('PanelApi') || !PanelApi::configured()) {
        return ['ok' => true, 'disponible' => false, 'message' => 'Compte API du panel non configuré (Paramètres › Panel consultant) : les procédures du panel ne se lisent pas.'] + $vide;
    }
    @set_time_limit(300);
    $chemins = ['procedures' => '/operational-procedures', 'sections' => '/operational-framework/structure/sections',
        'categories' => '/operational-framework/structure/categories'];
    $v = PanelApi::getParalleleDetail($chemins, 3, 25);
    if ($v['procedures']['code'] < 200 || $v['procedures']['code'] >= 300) {
        return rfRefus(502, 'Procédures du panel illisibles : ' . ($v['procedures']['erreur'] ?? ('HTTP ' . $v['procedures']['code'])));
    }
    $secNom = [];
    foreach (fmListe($v['sections']['data']) as $r) { if (($i = fmInt($r, ['id'])) !== null) { $secNom[$i] = (string) (fmTexte($r, ['name', 'base_name', 'title']) ?? ''); } }
    $catSec = [];
    foreach (fmListe($v['categories']['data']) as $r) { if (($i = fmInt($r, ['id'])) !== null) { $catSec[$i] = fmInt($r, ['section_id', 'id_section']); } }
    $reglees = function_exists('mnClassement') ? mnClassement() : [];
    $souscats = Db::rows('SELECT cle, fr FROM ceo_ref_souscat ORDER BY domaine, rang');
    $titres = [];
    foreach (Db::rows('SELECT id, code, titre_fr FROM ceo_sop') as $r) {
        $n = rfNorm((string) $r['titre_fr']);
        if ($n !== '' && !isset($titres[$n])) { $titres[$n] = (string) ($r['code'] ?: $r['id']); }
    }
    $par = foPar(body());
    $bilan = ['ok' => true, 'disponible' => true] + $vide;
    $ordre = (int) (Db::row('SELECT COALESCE(MAX(ordre), 0) AS o FROM ceo_sop')['o'] ?? 0);
    foreach (fmListe($v['procedures']['data']) as $brut) {
        $p = fmProcedure($brut);
        $pid = (int) ($p['id'] ?? 0);
        $titre = trim((string) $p['titre']);
        $ligne = ['id' => 'panel-' . $pid, 'code' => 'TFB-' . $pid, 'titre' => $titre];
        if ($pid <= 0) { $bilan['ignorees'][] = $ligne + ['raison' => 'sans identifiant']; continue; }
        if ($titre === '' || $titre === 'Procédure') { $bilan['ignorees'][] = $ligne + ['raison' => 'sans titre']; continue; }
        $deja = Db::row('SELECT id, code, source FROM ceo_sop WHERE id = ? OR source = ? OR code = ? LIMIT 1', [$ligne['id'], 'panel:' . $pid, $ligne['code']]);
        if ($deja !== null) {
            $bilan['dejaLa'][] = $ligne + ['raison' => str_starts_with((string) $deja['source'], 'crm:') ? 'reprise par le Manuel du CRM (' . $deja['id'] . ')' : 'déjà reprise'];
            continue;
        }
        if (isset($titres[rfNorm($titre)])) { $bilan['dejaLa'][] = $ligne + ['raison' => 'même titre que ' . $titres[rfNorm($titre)]]; continue; }
        $lu = fmProcedureLue($pid);
        if (!$lu['ok']) { $bilan['erreurs'][] = $ligne + ['raison' => (string) $lu['error']]; continue; }
        $q = $lu['procedure'];
        $cat = trim((string) ($q['categorie'] ?? $p['categorie'] ?? ''));
        $sous = trim((string) ($q['sousCategorie'] ?? $p['sousCategorie'] ?? ''));
        $section = $secNom[$catSec[(int) ($q['categorieId'] ?? $p['categorieId'] ?? 0)] ?? 0] ?? '';
        $reglee = $reglees[(string) $pid]['partie'] ?? null;
        $partie = is_string($reglee) && isset(RF_PHASES[$reglee]) ? $reglee
            : (preg_match('/formation|controle continu/', rfSansAccents($section . ' ' . $cat)) ? 'formation' : 'operationnel');
        $sc = rfSouscatParNom($sous, $souscats) ?? rfSouscatParNom($cat, $souscats);
        $videos = $reglees[(string) $pid]['videos'] ?? [];
        $contenu = rfContenuPanel($q, $lu['sections'], $lu['pieces'], is_array($videos) ? $videos : [], $cat, $sous);
        $texte = rfSansAccents($section . ' ' . $cat . ' ' . $sous);
        $dossier = preg_match('/nettoy|hygien|fermeture/', $texte) ? '03' : (preg_match('/cuisson|production|traiteur|four/', $texte) ? '02' : '01');
        $poste = ['01' => 'vente', '02' => 'production', '03' => 'nettoyage'][$dossier];
        $st = foStats($contenu);
        $now = date('Y-m-d H:i:s');
        $ordre++;
        Db::exec('INSERT INTO ceo_sop (id, code, type, dossier, poste, partie, moment, statut, version, version_revalider, langue_origine, trad_fr, trad_nl,
                    titre_fr, titre_nl, objectif_fr, objectif_nl, duree, nb_etapes, nb_photos, ordre, source, maj_le, maj_par,
                    souscat, phases, postes, competences, formations, checklists)
                  VALUES (?, ?, \'SOP\', ?, ?, ?, NULL, \'brouillon\', \'1.0\', \'1.0\', \'FR\', \'origine\', \'a_relire\', ?, \'\', ?, NULL, NULL, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $ligne['id'], $ligne['code'], $dossier, $poste, $partie, mb_substr($titre, 0, 190), $contenu['objectif']['fr'] ?: null,
            $st['etapes'], $ordre, 'panel:' . $pid, $now, $par, $sc, rfJsonEcrire([$partie]), '[]', '[]', '[]', '[]']);
        Db::exec('INSERT INTO ceo_sop_version (sop_id, version, contenu, note, revalider, publie_le, publie_par) VALUES (?, \'1.0\', ?, ?, 0, ?, ?)',
            [$ligne['id'], foJson($contenu), 'Reprise des procédures du panel (TFB-' . $pid . ', brouillon)', $now, $par]);
        $titres[rfNorm($titre)] = $ligne['code'];
        $bilan['reprises'][] = $ligne + ['souscat' => $sc, 'phase' => $partie, 'etapes' => $st['etapes']];
        if ($sc === null) { $bilan['aClasser'][] = $ligne + ['categorie' => trim($cat . ($sous !== '' ? ' › ' . $sous : ''))]; }
    }
    try {
        journalAdd($par, 'Formation', 'Manuel du réseau', 'Reprise des procédures du panel : ' . count($bilan['reprises']) . ' en brouillon (dont '
            . count($bilan['aClasser']) . ' à classer), ' . count($bilan['dejaLa']) . ' déjà là, ' . count($bilan['ignorees']) . ' ignorée(s), '
            . count($bilan['erreurs']) . ' illisible(s)');
    } catch (Throwable $e) { /* journal absent */ }
    return $bilan;
}

/**
 * Le contenu d'une procédure du panel au format des SOP : l'objectif = sa description ; une section « Étapes » dont
 * chaque étape est une section du panel qui a un contenu (son titre, son texte ; le HTML de l'éditeur du panel ramené
 * à du texte) ; à défaut de sections, le contenu de la fiche en une étape. Le néerlandais reste à écrire.
 */
function rfContenuPanel(array $q, array $sections, array $pieces, array $videos, string $cat, string $sous): array
{
    $etapes = [];
    foreach ($sections as $sec) {
        $t = rfTexteDeHtml((string) ($sec['contenu'] ?? ''));
        if (mb_strlen($t) < 4) { continue; }   // sections laissées vides ou à « 1 » dans le panel
        $etapes[] = ['numero' => (string) (count($etapes) + 1), 'titre' => ['fr' => trim((string) ($sec['titre'] ?? '')), 'nl' => ''],
            'texte' => ['fr' => $t, 'nl' => ''], 'critique' => false, 'photo' => null];
    }
    if ($etapes === [] && mb_strlen($t = rfTexteDeHtml((string) ($q['contenu'] ?? ''))) >= 4) {
        $etapes[] = ['numero' => '1', 'titre' => ['fr' => '', 'nl' => ''], 'texte' => ['fr' => $t, 'nl' => ''], 'critique' => false, 'photo' => null];
    }
    $rem = ['Reprise des procédures du panel TF Buddy (#' . (int) ($q['id'] ?? 0) . ($cat !== '' ? ', « ' . $cat . ($sous !== '' ? ' › ' . $sous : '') . ' »' : '')
        . ') : le néerlandais reste à écrire et le classement à vérifier avant de publier.'];
    foreach ($pieces as $x) {
        $nom = trim((string) ($x['nom'] ?? ''));
        if ($nom !== '') { $rem[] = 'Pièce jointe du panel (non reprise) : ' . $nom . '.'; }
    }
    foreach ($videos as $x) {
        if (is_array($x) && is_string($x['url'] ?? null) && str_starts_with($x['url'], 'https://')) {
            $rem[] = 'Vidéo ajoutée dans le cockpit : ' . $x['url'] . (trim((string) ($x['titre'] ?? '')) !== '' ? ' (' . trim((string) $x['titre']) . ')' : '');
        }
    }
    return foContenu(['titre' => ['fr' => (string) ($q['titre'] ?? ''), 'nl' => ''],
        'objectif' => ['fr' => rfTexteDeHtml((string) ($q['description'] ?? '')), 'nl' => ''], 'pour' => ['fr' => '', 'nl' => ''], 'duree' => null,
        'sections' => [['titre' => ['fr' => 'Étapes', 'nl' => 'Stappen'], 'etapes' => $etapes]], 'points_critiques' => [], 'remarques' => $rem]);
}

/** Le HTML d'éditeur du panel ramené à du texte : un saut de ligne par paragraphe, élément de liste ou titre. */
function rfTexteDeHtml(string $h): string
{
    $h = (string) preg_replace('#<\s*br\s*/?>#i', "\n", $h);
    $h = (string) preg_replace('#<\s*li[^>]*>#i', "\n• ", $h);
    $h = (string) preg_replace('#</\s*(p|div|li|h[1-6]|tr|ul|ol|blockquote)\s*>#i', "\n", $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    $t = (string) preg_replace('/ *\n */', "\n", $t);
    $t = (string) preg_replace('/\n+(• )/u', "\n$1", $t);   // les éléments d'une liste se suivent, sans ligne vide
    return trim((string) preg_replace('/\n{3,}/', "\n\n", $t));
}

/* ═══ Routes ══════════════════════════════════════════════════════════════════════════════════════ */

/** Les routes du référentiel ; null quand le chemin n'en est pas une. */
function rfRoute(string $method, string $path): ?array
{
    if ($path !== '/formation/ref' && !str_starts_with($path, '/formation/ref/')) { return null; }
    $seg = static fn (string $s): string => rawurldecode($s);
    return match (true) {
        $method === 'GET' && $path === '/formation/ref' => ep_rf_ref(),
        $method === 'GET' && $path === '/formation/ref/correspondance' => ep_rf_corresp(),
        $method === 'POST' && $path === '/formation/ref/correspondance/proposer' => wr_rf_proposer(),
        $method === 'PUT' && preg_match('#^/formation/ref/correspondance/([^/]{1,120})$#', $path, $m) === 1 => wr_rf_corresp($seg($m[1])),
        $method === 'PUT' && preg_match('#^/formation/ref/standards/([\w-]{1,32})$#', $path, $m) === 1 => wr_rf_standard($m[1]),
        $method === 'PUT' && preg_match('#^/formation/ref/souscats/(D\d\.\d{1,2})$#', $path, $m) === 1 => wr_rf_souscat($m[1]),
        $method === 'PUT' && preg_match('#^/formation/ref/formations/(FO-\d{2})$#', $path, $m) === 1 => wr_rf_formation($m[1]),
        $method === 'POST' && $path === '/formation/ref/importer-crm' => wr_rf_importer_crm(),
        $method === 'POST' && $path === '/formation/ref/importer-panel' => wr_rf_importer_panel(),
        // Les deux guides (src/referentiel_guides.php) : pré-ouverture, blocs, points, pôles.
        default => function_exists('rgRoute') ? rgRoute($method, $path) : null,
    };
}

function rfRefus(int $code, string $msg, array $extra = []): array
{
    http_response_code($code);
    return ['error' => $msg] + $extra;
}
