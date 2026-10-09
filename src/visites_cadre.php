<?php
declare(strict_types=1);

/**
 * Le cadre de visite et la gestion des consultants (09/10/2026).
 *
 * Trois choses que le module Visites n'avait pas :
 *
 *   1. Des TYPES DE VISITE — la liste déroulante du planning. Chaque type porte
 *      sa durée, le profil de consultant qu'il demande, sa checklist (modules et
 *      points, de la même forme que la liste standard) et ses tâches à faire
 *      avant, pendant et après. Choisir le type, c'est prendre tout ça. La liste
 *      standard (`visitesChecklist`) reste celle du type « Régulière » : un seul
 *      réglage, lu aux deux endroits. Deux types sont dynamiques : le suivi de
 *      plan d'action reprend les plans ouverts, la revisite reprend les points
 *      non conformes de la dernière visite — l'application les construit.
 *   2. Un CADRE par magasin — combien de visites de chaque type par mois (ou
 *      par trimestre), par quel profil, par quel consultant. Il remplace la
 *      fréquence en jours (gardée en repli) et alimente « À planifier », la
 *      couverture et les visites dues.
 *   3. Les TÂCHES du consultant — une table à part (`ceo_consultant_tache`) :
 *      celles que le type génère à la planification, avec leur échéance (J−2,
 *      J+1…), et celles qu'il se donne. L'écran « Gestion consultant » les réunit
 *      avec ses visites, les plans d'action et, quand le panel répond, les cas
 *      du helpdesk.
 *
 * Et l'agenda : un flux ICS par consultant (jeton dans l'adresse, que Google
 * Agenda relit tout seul), le lien « Ajouter à Google Agenda » sur chaque
 * visite, et l'invitation .ics par courriel quand une visite est planifiée,
 * déplacée ou annulée (réglage `invitations` des seuils, SMTP du cockpit).
 *
 * Réglages : `visitesTypes`, `visitesCadre`, `visitesProfils` ; table
 * `ceo_consultant_tache` ; colonnes `type_code` et `ics_seq` sur `ceo_visite`.
 */

const VC_QUAND = ['avant', 'pendant', 'apres'];
const VC_PAR = ['mois', 'trimestre'];
const VC_STATUTS_TACHE = ['a_faire', 'en_cours', 'fait', 'annule'];
const VC_SOURCES = ['type', 'visite', 'plan', 'panel', 'recurrente', 'helpdesk', 'perso'];
const VC_SCHEMA = 1;

/* --- schéma ------------------------------------------------------------------ */

function ensureVisitesCadre(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    ensureVisites();
    if ((int) setting('visitesCadreSchema', 0) >= VC_SCHEMA) { return; }
    $manque = true;
    try { $manque = Db::row("SHOW COLUMNS FROM ceo_visite LIKE 'type_code'") === null; } catch (Throwable $e) { /* pas MySQL : on tente l'ajout */ }
    if ($manque) {
        foreach (['type_code VARCHAR(20) NOT NULL DEFAULT \'reguliere\'', 'ics_seq SMALLINT NOT NULL DEFAULT 0'] as $col) {
            try { Db::exec('ALTER TABLE ceo_visite ADD COLUMN ' . $col); } catch (Throwable $e) { /* déjà là */ }
        }
    }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_consultant_tache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(40) NULL,
        consultant_id VARCHAR(32) NOT NULL DEFAULT \'\',
        consultant_nom VARCHAR(120) NOT NULL DEFAULT \'\',
        shop_id VARCHAR(32) NULL,
        visite_id INT NULL,
        source VARCHAR(12) NOT NULL DEFAULT \'perso\',
        quand VARCHAR(8) NULL,
        delai TINYINT NULL,
        titre VARCHAR(190) NOT NULL,
        detail TEXT NULL,
        echeance DATE NULL,
        statut VARCHAR(10) NOT NULL DEFAULT \'a_faire\',
        fait_le DATETIME NULL,
        cree_par VARCHAR(120) NOT NULL DEFAULT \'\',
        cree_le DATETIME NOT NULL,
        maj_le DATETIME NOT NULL,
        UNIQUE KEY u_client (client_id),
        KEY k_cons (consultant_id, echeance),
        KEY k_visite (visite_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    viSettingSet('visitesCadreSchema', VC_SCHEMA);
}

/* --- profils ------------------------------------------------------------------ */

function vcProfilsDefaut(): array
{
    return [['code' => 'strategie', 'nom' => 'Stratégie et Développement'],
        ['code' => 'operations', 'nom' => 'Opérations et qualité'],
        ['code' => 'production', 'nom' => 'Produit et production']];
}

function vcProfils(): array
{
    $p = setting('visitesProfils', null);
    $out = [];
    foreach (is_array($p) ? $p : [] as $x) {
        if (!is_array($x) || !preg_match('/^[a-z0-9_]{1,20}$/', (string) ($x['code'] ?? ''))) { continue; }
        $out[] = ['code' => $x['code'], 'nom' => mb_substr(trim((string) ($x['nom'] ?? $x['code'])), 0, 60) ?: $x['code']];
    }
    return $out ?: vcProfilsDefaut();
}

function vcProfilNom(string $code): string
{
    if ($code === '') { return ''; }
    foreach (vcProfils() as $p) { if ($p['code'] === $code) { return $p['nom']; } }
    return $code;
}

/** Le profil de chaque consultant : réglage `visitesConsultantProfil` {id: code}. */
function vcProfilDe(string $consultant): string
{
    $m = setting('visitesConsultantProfil', []);
    return is_array($m) ? (string) ($m[$consultant] ?? '') : '';
}

/* --- types de visite ---------------------------------------------------------- */

function vcPt(string $ref, string $libelle, bool $photo = false, bool $pct = false): array
{
    return ['ref' => $ref, 'libelle' => $libelle, 'photo' => $photo, 'pct' => $pct];
}

function vcTa(string $quand, int $delai, string $libelle): array
{
    return ['quand' => $quand, 'delai' => $delai, 'libelle' => $libelle];
}

/** Les types livrés : code => type. La régulière lit la liste standard (viChecklist). */
function vcTypesDefaut(): array
{
    $t = [];
    $t['reguliere'] = ['code' => 'reguliere', 'nom' => 'Régulière', 'duree' => 90, 'profil' => '', 'actif' => true, 'dynamique' => null,
        'checklist' => null, 'taches' => [
            vcTa('avant', 2, 'Relire la dernière review et les plans d’action ouverts'),
            vcTa('avant', 2, 'Lire les tâches sautées de la semaine dans le panel'),
            vcTa('avant', 1, 'Préparer les chiffres : CA, food cost, invendus du mois'),
            vcTa('pendant', 0, 'La checklist, les photos, la review avec le franchisé'),
            vcTa('apres', 1, 'Envoyer la review et ouvrir les plans d’action'),
            vcTa('apres', 1, 'Noter la visite dans le panel (note de type VISIT)')]];
    $t['production'] = ['code' => 'production', 'nom' => 'Production', 'duree' => 180, 'profil' => 'production', 'actif' => true, 'dynamique' => null,
        'checklist' => [
            ['id' => 'mise_place', 'nom' => 'Mise en place', 'points' => [vcPt('plan_jour', 'Plan de production du jour affiché et suivi'), vcPt('pesees', 'Pesées et recettes respectées', true), vcPt('levee', 'Levée et façonnage : temps et température'), vcPt('stock_mat', 'Stock matières : rotation, DLC', true)]],
            ['id' => 'cuisson', 'nom' => 'Cuisson', 'points' => [vcPt('pain_cuit', 'Pain : couleur, croûte, cuisson à cœur', true), vcPt('vien_cuit', 'Viennoiserie : feuilletage, dorure', true), vcPt('fours', 'Fours : températures et programmes'), vcPt('sortie', 'Sortie de four : refroidissement, grilles')]],
            ['id' => 'recuissons', 'nom' => 'Recuissons', 'points' => [vcPt('recuisson', 'Recuisson de l’après-midi faite, quantités notées', true), vcPt('vitrine16', 'Vitrine pleine à 16 h', true), vcPt('ecart', 'Écart production / ventes de la semaine commenté')]],
            ['id' => 'invendus', 'nom' => 'Invendus', 'points' => [vcPt('comptage', 'Comptage du soir fait, avec motif'), vcPt('taux', 'Invendus sous 4 % du CA de la semaine'), vcPt('causes', 'Causes des pertes nommées, parade en place')]]],
        'taches' => [
            vcTa('avant', 2, 'Lire la production et les invendus de la semaine dans le cockpit'),
            vcTa('avant', 1, 'Prévenir le franchisé : visite en production, dès l’ouverture du fournil'),
            vcTa('pendant', 0, 'Les 14 points, les photos, 30 min avec le responsable de production'),
            vcTa('apres', 1, 'Review et plan d’action production'),
            vcTa('apres', 7, 'Vérifier l’écart production / ventes de la semaine suivante')]];
    $t['hygiene'] = ['code' => 'hygiene', 'nom' => 'Hygiène et qualité', 'duree' => 60, 'profil' => 'operations', 'actif' => true, 'dynamique' => null,
        'checklist' => [
            ['id' => 'hygiene', 'nom' => 'Hygiène', 'points' => [vcPt('sol', 'Sol zone production propre'), vcPt('frigo', 'Frigo vitrine ≤ 4 °C (photo du thermomètre)', true), vcPt('arriere', 'Zone arrière et réserve', true), vcPt('sanitaires', 'Sanitaires, lave-mains, savon'), vcPt('tenues', 'Tenues, cheveux, gants')]],
            ['id' => 'qualite', 'nom' => 'Qualité', 'points' => [vcPt('recette', 'Produits du jour conformes à la recette et au visuel', true), vcPt('dlc', 'DLC et étiquetage des préparations'), vcPt('froid', 'Chaîne du froid : relevés du jour tenus'), vcPt('allergenes', 'Allergènes affichés et à jour')]],
            ['id' => 'nettoyage', 'nom' => 'Nettoyage', 'points' => [vcPt('plan_net', 'Plan de nettoyage affiché et coché'), vcPt('materiel', 'Matériel et plans de travail propres', true), vcPt('dechets', 'Déchets : tri, local, fréquence')]]],
        'taches' => [
            vcTa('avant', 1, 'Relire les points hygiène des dernières visites et les remarques du client mystère'),
            vcTa('pendant', 0, 'Les 12 points, le thermomètre en photo'),
            vcTa('apres', 1, 'Review hygiène et plan d’action (P0 si température ou propreté)'),
            vcTa('apres', 7, 'Revérifier les corrections P0 sur photo')]];
    $t['msp'] = ['code' => 'msp', 'nom' => 'Client mystère', 'duree' => 45, 'profil' => 'operations', 'actif' => true, 'dynamique' => null,
        'checklist' => [['id' => 'msp', 'nom' => 'Client mystère', 'points' => [vcPt('accueil', 'Accueil : bonjour, sourire, disponibilité'), vcPt('attente', 'Temps d’attente et gestion de la file'), vcPt('conseil', 'Conseil et connaissance des produits'), vcPt('additionnelle', 'Vente additionnelle proposée'), vcPt('proprete', 'Propreté visible depuis la file', true), vcPt('presentation', 'Produits présentés et étiquetés', true), vcPt('encaissement', 'Encaissement : ticket, monnaie, carte'), vcPt('aurevoir', 'Au revoir et invitation à revenir')]]],
        'taches' => [
            vcTa('avant', 1, 'Choisir l’heure et le panier du passage, prévenir personne'),
            vcTa('pendant', 0, 'Le passage, les 8 points notés aussitôt'),
            vcTa('apres', 2, 'Encoder le rapport (obtenu / maximum) dans le scoring du trimestre')]];
    $t['bilan'] = ['code' => 'bilan', 'nom' => 'Bilan trimestriel', 'duree' => 120, 'profil' => 'strategie', 'actif' => true, 'dynamique' => null,
        'checklist' => [['id' => 'bilan', 'nom' => 'Bilan', 'points' => [vcPt('scoring', 'Scoring du trimestre présenté et commenté'), vcPt('google', 'Note Google : avis, réponses, cible'), vcPt('taches', 'Tâches du panel : obligatoires manquées, photos'), vcPt('msp_bilan', 'Client mystère : points relevés, corrigés'), vcPt('budget', 'Budget : CA face au budget, mois par mois'), vcPt('pnl', 'P&L : food cost, main-d’œuvre, résultat'), vcPt('invendus', 'Invendus : taux et causes'), vcPt('plan_dev', 'Plan de développement : jalons du trimestre'), vcPt('objectifs', 'Objectifs du trimestre suivant posés'), vcPt('engagement', 'Engagement du franchisé noté')]]],
        'taches' => [
            vcTa('avant', 5, 'Calculer le scoring du trimestre et préparer le rapport A4'),
            vcTa('avant', 2, 'Relire le P&L des trois mois et le plan de développement'),
            vcTa('pendant', 0, 'Le bilan avec le franchisé, les objectifs du trimestre suivant'),
            vcTa('apres', 1, 'Envoyer le rapport et le compte rendu'),
            vcTa('apres', 7, 'Poser les objectifs dans le cockpit (budget, objectifs produits)')]];
    $t['ouverture'] = ['code' => 'ouverture', 'nom' => 'Ouverture / lancement', 'duree' => 240, 'profil' => 'strategie', 'actif' => true, 'dynamique' => null,
        'checklist' => [
            ['id' => 'prepa', 'nom' => 'Préparation', 'points' => [vcPt('travaux', 'Travaux et agencement conformes au plan', true), vcPt('materiel', 'Matériel livré, installé, testé'), vcPt('caisse', 'Caisse, panel et dashboard reliés'), vcPt('stock', 'Stock de départ et matières livrés'), vcPt('plano_init', 'Planogramme monté', true), vcPt('enseigne', 'Enseigne, façade, vitrine', true)]],
            ['id' => 'equipe', 'nom' => 'Équipe', 'points' => [vcPt('effectif', 'Effectif recruté et planning posé'), vcPt('formation', 'Formation production et vente faite'), vcPt('tenues', 'Tenues et hygiène expliquées'), vcPt('app', 'App worker et tâches du panel en main')]],
            ['id' => 'produit', 'nom' => 'Produit', 'points' => [vcPt('gamme', 'Gamme d’ouverture complète'), vcPt('recettes', 'Recettes et fiches techniques disponibles'), vcPt('cuisson', 'Premières cuissons validées', true), vcPt('prix', 'Prix et étiquettes posés'), vcPt('obligatoires', 'Références obligatoires présentes')]],
            ['id' => 'lancement', 'nom' => 'Lancement', 'points' => [vcPt('com', 'Communication d’ouverture faite'), vcPt('google_fiche', 'Fiche Google ouverte et renseignée'), vcPt('j7', 'Point à J+7 : ventes, équipe, retours clients'), vcPt('j30', 'Point à J+30 : food cost, invendus, planning')]]],
        'taches' => [
            vcTa('avant', 14, 'Vérifier le planning des travaux et des livraisons'),
            vcTa('avant', 7, 'Planifier la formation de l’équipe'),
            vcTa('avant', 3, 'Vérifier caisse, panel, dashboard, app worker'),
            vcTa('avant', 1, 'Relire la checklist d’ouverture avec le franchisé'),
            vcTa('pendant', 0, 'La journée d’ouverture sur place'),
            vcTa('apres', 1, 'Compte rendu et plan d’action d’ouverture'),
            vcTa('apres', 7, 'Point à J+7'),
            vcTa('apres', 30, 'Point à J+30'),
            vcTa('apres', 30, 'Passer le magasin au cadre régulier')]];
    $t['suivi'] = ['code' => 'suivi', 'nom' => 'Suivi plan d’action', 'duree' => 45, 'profil' => '', 'actif' => true, 'dynamique' => 'plans',
        'checklist' => [], 'taches' => [
            vcTa('avant', 1, 'Relire les plans ouverts et les corrections envoyées'),
            vcTa('apres', 1, 'Valider ou renvoyer chaque correction')]];
    $t['revisite'] = ['code' => 'revisite', 'nom' => 'Revisite', 'duree' => 60, 'profil' => '', 'actif' => true, 'dynamique' => 'ko',
        'checklist' => [], 'taches' => [
            vcTa('avant', 1, 'Relire les points non conformes de la dernière visite'),
            vcTa('apres', 1, 'Fermer les plans corrigés, renvoyer les autres')]];
    return $t;
}

function vcNbPoints(?array $cl): int
{
    $n = 0;
    foreach (is_array($cl) ? $cl : [] as $m) { $n += count($m['points'] ?? []); }
    return $n;
}

/** Les types, dans l'ordre réglé, les types livrés non réglés à la suite. */
function vcTypes(): array
{
    $defaut = vcTypesDefaut();
    $regle = setting('visitesTypes', null);
    $out = []; $vus = [];
    foreach (is_array($regle) ? $regle : [] as $t) {
        if (!is_array($t) || !preg_match('/^[a-z0-9_]{1,20}$/', (string) ($t['code'] ?? ''))) { continue; }
        $code = (string) $t['code'];
        $base = $defaut[$code] ?? ['code' => $code, 'nom' => $code, 'duree' => 90, 'profil' => '', 'actif' => true, 'dynamique' => null, 'checklist' => [], 'taches' => []];
        $out[] = array_merge($base, array_intersect_key($t, array_flip(['nom', 'duree', 'profil', 'checklist', 'taches', 'actif', 'dynamique'])));
        $vus[$code] = true;
    }
    foreach ($defaut as $code => $t) { if (!isset($vus[$code])) { $out[] = $t; } }
    foreach ($out as &$t) {
        if ($t['code'] === 'reguliere' || $t['checklist'] === null) { $t['checklist'] = viChecklist(); }
        $t['duree'] = max(15, min(480, (int) $t['duree']));
        $t['profil'] = (string) $t['profil'];
        $t['profilNom'] = vcProfilNom($t['profil']);
        $t['actif'] = !isset($t['actif']) || (bool) $t['actif'];
        $t['taches'] = array_values(is_array($t['taches']) ? $t['taches'] : []);
        $t['points'] = vcNbPoints($t['checklist']);
        $t['nbTaches'] = count($t['taches']);
    }
    unset($t);
    return $out;
}

function vcType(string $code): ?array
{
    foreach (vcTypes() as $t) { if ($t['code'] === $code) { return $t; } }
    return null;
}

/** Un code de type valide, sinon la régulière. */
function vcTypeCode(mixed $code): string
{
    $c = (string) $code;
    return $c !== '' && vcType($c) !== null ? $c : 'reguliere';
}

/** Les types sans leur checklist : pour les listes et les écrans qui ne la montrent pas. */
function vcTypesCourts(): array
{
    return array_map(fn ($t) => ['code' => $t['code'], 'nom' => $t['nom'], 'duree' => $t['duree'], 'profil' => $t['profil'], 'profilNom' => $t['profilNom'],
        'actif' => $t['actif'], 'dynamique' => $t['dynamique'], 'points' => $t['points'], 'nbTaches' => $t['nbTaches']], vcTypes());
}

/** Une checklist telle qu'on l'écrit : modules et points nettoyés. Les modules de la liste standard sont ceux du module Visites. */
function vcChecklistNormalise(mixed $cl, bool $standard): array
{
    $out = [];
    foreach (is_array($cl) ? $cl : [] as $m) {
        if (!is_array($m)) { continue; }
        $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($m['id'] ?? '')));
        if ($standard) { $id = in_array($id, VI_MODULES, true) ? $id : ''; }
        if ($id === '' || mb_strlen($id) > 12) { continue; }
        $pts = [];
        foreach (is_array($m['points'] ?? null) ? $m['points'] : [] as $p) {
            if (!is_array($p)) { continue; }
            $ref = preg_replace('/[^\w-]/', '', (string) ($p['ref'] ?? ''));
            $lib = mb_substr(trim((string) ($p['libelle'] ?? '')), 0, 190);
            if ($ref === '' || $lib === '') { continue; }
            $pts[] = ['ref' => mb_substr($ref, 0, 60), 'libelle' => $lib, 'photo' => !empty($p['photo']), 'pct' => !empty($p['pct'])];
        }
        $out[] = ['id' => $id, 'nom' => mb_substr(trim((string) ($m['nom'] ?? $id)), 0, 60) ?: $id, 'points' => $pts];
    }
    return $out;
}

function vcTachesNormalise(mixed $taches): array
{
    $out = [];
    foreach (is_array($taches) ? $taches : [] as $x) {
        if (!is_array($x)) { continue; }
        $lib = mb_substr(trim((string) ($x['libelle'] ?? '')), 0, 190);
        if ($lib === '') { continue; }
        $quand = in_array($x['quand'] ?? '', VC_QUAND, true) ? $x['quand'] : 'pendant';
        $out[] = ['quand' => $quand, 'delai' => $quand === 'pendant' ? 0 : max(0, min(60, (int) ($x['delai'] ?? 0))), 'libelle' => $lib];
    }
    return $out;
}

/* --- le cadre par magasin ----------------------------------------------------- */

/** La fréquence réglée en jours (le repli d'avant le cadre), sans passer par viFrequence. */
function vcFrequenceReglee(string $shop): int
{
    $f = setting('visitesFrequence', []);
    $v = is_array($f) ? (int) ($f[$shop] ?? 0) : 0;
    return $v > 0 ? $v : (int) viSeuils()['visiteJours'];
}

/** Les lignes du cadre : réglées, sinon dérivées de la fréquence (une régulière, n par mois). */
function vcCadre(): array
{
    $mags = viMagasins();
    $codes = array_map(fn ($t) => $t['code'], vcTypes());
    $out = [];
    $c = setting('visitesCadre', null);
    foreach (is_array($c) ? $c : [] as $l) {
        if (!is_array($l) || !isset($mags[(string) ($l['shop'] ?? '')])) { continue; }
        $type = (string) ($l['type'] ?? 'reguliere');
        if (!in_array($type, $codes, true)) { continue; }
        $out[] = ['id' => (string) ($l['id'] ?? ('c' . substr(md5($l['shop'] . $type . ($l['consultant'] ?? '')), 0, 8))), 'shop' => (string) $l['shop'], 'type' => $type,
            'nb' => max(1, min(31, (int) ($l['nb'] ?? 1))), 'par' => in_array($l['par'] ?? '', VC_PAR, true) ? $l['par'] : 'mois',
            'profil' => (string) ($l['profil'] ?? ''), 'consultant' => (string) ($l['consultant'] ?? ''), 'consultantNom' => (string) ($l['consultantNom'] ?? ''), 'auto' => false];
    }
    if ($out === []) {
        foreach ($mags as $sid => $m) {
            $j = vcFrequenceReglee((string) $sid);
            $out[] = ['id' => 'auto-' . $sid, 'shop' => (string) $sid, 'type' => 'reguliere', 'nb' => max(1, (int) round(30 / max(1, $j))), 'par' => 'mois',
                'profil' => '', 'consultant' => '', 'consultantNom' => '', 'auto' => true];
        }
    }
    return $out;
}

function vcCadreDe(string $shop): array
{
    return array_values(array_filter(vcCadre(), fn ($l) => $l['shop'] === $shop));
}

/** La fréquence en jours que le cadre implique pour un magasin — null tant que le cadre n'est pas réglé. */
function vcFrequenceJours(string $shop): ?int
{
    $parMois = 0.0; $regle = false;
    foreach (vcCadreDe($shop) as $l) {
        if ($l['auto']) { return null; }
        $regle = true;
        $parMois += $l['par'] === 'mois' ? $l['nb'] : $l['nb'] / 3;
    }
    if (!$regle || $parMois <= 0) { return null; }
    return max(1, (int) round(30 / $parMois));
}

/** Le trimestre civil d'un mois : [du, au]. */
function vcTrimestreDe(string $mois): array
{
    $a = (int) substr($mois, 0, 4); $m = (int) substr($mois, 5, 2);
    $m1 = (int) (floor(($m - 1) / 3) * 3 + 1);
    $du = sprintf('%04d-%02d-01', $a, $m1);
    return [$du, date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $a, $m1 + 2)))];
}

/**
 * Le cadre face au réel, pour un mois : ligne par ligne, l'attendu sur la
 * fenêtre (le mois, ou le trimestre), les visites faites, planifiées, et ce
 * qui reste à planifier. Le suivi et la revisite ne comptent pas : ils
 * naissent d'un P0 ou d'un point non conforme.
 */
function vcAttendu(string $mois): array
{
    $debut = $mois . '-01'; $fin = date('Y-m-t', strtotime($debut));
    [$tDu, $tAu] = vcTrimestreDe($mois);
    $rows = Db::rows('SELECT shop_id, type_code, statut, prevu_le, consultant_id, consultant_nom FROM ceo_visite WHERE prevu_le BETWEEN ? AND ? AND statut <> \'annulee\'', [$tDu, $tAu]);
    $mags = viMagasins(); $types = []; foreach (vcTypes() as $t) { $types[$t['code']] = $t; }
    $out = [];
    foreach (vcCadre() as $l) {
        [$du, $au] = $l['par'] === 'mois' ? [$debut, $fin] : [$tDu, $tAu];
        $faites = 0; $planifiees = 0; $derniere = null; $prochaine = null;
        foreach ($rows as $r) {
            if ((string) $r['shop_id'] !== $l['shop'] || (string) ($r['type_code'] ?? 'reguliere') !== $l['type']) { continue; }
            if ($r['prevu_le'] < $du || $r['prevu_le'] > $au) { continue; }
            if ($r['statut'] === 'terminee') { $faites++; if ($derniere === null || $r['prevu_le'] > $derniere) { $derniere = $r['prevu_le']; } }
            else { $planifiees++; if ($prochaine === null || $r['prevu_le'] < $prochaine) { $prochaine = $r['prevu_le']; } }
        }
        $t = $types[$l['type']] ?? null;
        $out[] = $l + ['magasin' => $mags[$l['shop']]['court'] ?? $l['shop'], 'typeNom' => $t['nom'] ?? $l['type'], 'profilNom' => vcProfilNom($l['profil'] ?: ($t['profil'] ?? '')),
            'du' => $du, 'au' => $au, 'attendu' => $l['nb'], 'faites' => $faites, 'planifiees' => $planifiees,
            'aPlanifier' => max(0, $l['nb'] - $faites - $planifiees), 'derniere' => $derniere, 'prochaine' => $prochaine];
    }
    return $out;
}

/* --- GET / PUT /visites/cadre -------------------------------------------------- */

function ep_visites_cadre(): array
{
    ensureVisitesCadre();
    $mois = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mois'] ?? '')) ? (string) $_GET['mois'] : date('Y-m');
    $cons = viConsultants();
    foreach ($cons as &$c) { $c['profil'] = vcProfilDe($c['id']); $c['profilNom'] = vcProfilNom($c['profil']); }
    unset($c);
    return ['mois' => $mois, 'types' => vcTypes(), 'cadre' => vcCadre(), 'attendu' => vcAttendu($mois), 'profils' => vcProfils(),
        'consultants' => $cons, 'magasins' => array_values(viMagasins()), 'modulesStandard' => VI_MODULES];
}

function wr_visites_cadre_put(): array
{
    ensureVisitesCadre();
    $b = body();
    if (isset($b['profils']) && is_array($b['profils'])) {
        $p = [];
        foreach ($b['profils'] as $x) {
            if (!is_array($x)) { continue; }
            $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($x['code'] ?? '')));
            $nom = mb_substr(trim((string) ($x['nom'] ?? '')), 0, 60);
            if ($code === '' || $nom === '') { continue; }
            $p[] = ['code' => mb_substr($code, 0, 20), 'nom' => $nom];
        }
        viSettingSet('visitesProfils', $p ?: vcProfilsDefaut());
    }
    if (isset($b['consultantProfil']) && is_array($b['consultantProfil'])) {
        $codes = array_map(fn ($x) => $x['code'], vcProfils());
        $m = [];
        foreach ($b['consultantProfil'] as $id => $code) { if (in_array((string) $code, $codes, true)) { $m[mb_substr((string) $id, 0, 32)] = (string) $code; } }
        viSettingSet('visitesConsultantProfil', $m);
    }
    if (isset($b['types']) && is_array($b['types'])) {
        $codes = array_map(fn ($x) => $x['code'], vcProfils());
        $types = [];
        foreach ($b['types'] as $t) {
            if (!is_array($t)) { continue; }
            $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($t['code'] ?? '')));
            if ($code === '' || mb_strlen($code) > 20) { continue; }
            $x = ['code' => $code, 'nom' => mb_substr(trim((string) ($t['nom'] ?? $code)), 0, 60) ?: $code,
                'duree' => max(15, min(480, (int) ($t['duree'] ?? 90))), 'profil' => in_array((string) ($t['profil'] ?? ''), $codes, true) ? (string) $t['profil'] : '',
                'actif' => !isset($t['actif']) || (bool) $t['actif'], 'dynamique' => in_array($t['dynamique'] ?? null, ['plans', 'ko'], true) ? $t['dynamique'] : null,
                'taches' => vcTachesNormalise($t['taches'] ?? [])];
            $cl = vcChecklistNormalise($t['checklist'] ?? [], $code === 'reguliere');
            if ($code === 'reguliere') {
                // La régulière écrit la liste standard : un seul réglage, lu par l'application et par le cadre.
                if ($cl !== []) { viSettingSet('visitesChecklist', $cl); }
                $x['checklist'] = null;
            } else {
                $x['checklist'] = $cl;
            }
            $types[] = $x;
        }
        viSettingSet('visitesTypes', $types);
    }
    if (isset($b['cadre']) && is_array($b['cadre'])) {
        $mags = viMagasins(); $codes = array_map(fn ($t) => $t['code'], vcTypes()); $cons = viConsultants();
        $lignes = [];
        foreach ($b['cadre'] as $l) {
            if (!is_array($l)) { continue; }
            $shop = (string) ($l['shop'] ?? ''); $type = (string) ($l['type'] ?? '');
            if (!isset($mags[$shop]) || !in_array($type, $codes, true)) { continue; }
            $cid = mb_substr(trim((string) ($l['consultant'] ?? '')), 0, 32); $nom = '';
            foreach ($cons as $c) { if ($c['id'] === $cid) { $nom = $c['nom']; } }
            if ($cid !== '' && $nom === '') { $nom = mb_substr(trim((string) ($l['consultantNom'] ?? '')), 0, 120); }
            $lignes[] = ['id' => preg_replace('/[^\w-]/', '', (string) ($l['id'] ?? '')) ?: ('c' . substr(md5($shop . $type . $cid . microtime(true)), 0, 8)),
                'shop' => $shop, 'type' => $type, 'nb' => max(1, min(31, (int) ($l['nb'] ?? 1))), 'par' => in_array($l['par'] ?? '', VC_PAR, true) ? $l['par'] : 'mois',
                'profil' => mb_substr((string) ($l['profil'] ?? ''), 0, 20), 'consultant' => $cid, 'consultantNom' => $nom];
        }
        viSettingSet('visitesCadre', $lignes);
    }
    journalAdd('Admin', 'Visites', null, 'Cadre de visite réglé : ' . count(vcTypes()) . ' types, ' . count(vcCadre()) . ' lignes');
    return ep_visites_cadre() + ['ok' => true];
}

/* --- les tâches du consultant ------------------------------------------------- */

function vcTacheLigne(array $r): array
{
    $auj = date('Y-m-d');
    $ouverte = in_array($r['statut'], ['a_faire', 'en_cours'], true);
    return ['id' => (int) $r['id'], 'client_id' => $r['client_id'], 'consultant' => (string) $r['consultant_id'], 'consultantNom' => (string) $r['consultant_nom'],
        'shop' => $r['shop_id'] !== null ? (string) $r['shop_id'] : null, 'visite_id' => $r['visite_id'] !== null ? (int) $r['visite_id'] : null,
        'source' => $r['source'], 'quand' => $r['quand'], 'delai' => $r['delai'] !== null ? (int) $r['delai'] : null,
        'titre' => $r['titre'], 'detail' => $r['detail'], 'echeance' => $r['echeance'], 'statut' => $r['statut'], 'fait_le' => $r['fait_le'],
        'retard' => $ouverte && $r['echeance'] !== null && $r['echeance'] < $auj ? (int) round((strtotime($auj) - strtotime((string) $r['echeance'])) / 86400) : 0,
        'cree_par' => $r['cree_par'], 'cree_le' => $r['cree_le'], 'maj_le' => $r['maj_le']];
}

function vcEcheance(string $date, string $quand, int $delai): string
{
    if ($quand === 'avant') { return date('Y-m-d', strtotime($date . ' -' . $delai . ' days')); }
    if ($quand === 'apres') { return date('Y-m-d', strtotime($date . ' +' . $delai . ' days')); }
    return $date;
}

/** Les tâches du type, créées pour une visite (une fois : `client_id` = v{id}:{n}). */
function vcTachesGenerer(array $v): int
{
    $t = vcType((string) ($v['type_code'] ?? 'reguliere'));
    if ($t === null || (string) ($v['consultant_id'] ?? '') === '') { return 0; }
    $n = 0; $now = date('Y-m-d H:i:s');
    foreach ($t['taches'] as $i => $x) {
        $cid = 'v' . (int) $v['id'] . ':' . $i;
        if (Db::row('SELECT id FROM ceo_consultant_tache WHERE client_id = ?', [$cid]) !== null) { continue; }
        Db::exec('INSERT INTO ceo_consultant_tache (client_id, consultant_id, consultant_nom, shop_id, visite_id, source, quand, delai, titre, detail, echeance, statut, cree_par, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$cid, (string) $v['consultant_id'], (string) $v['consultant_nom'], (string) $v['shop_id'], (int) $v['id'], 'type', $x['quand'], (int) $x['delai'],
             mb_substr($x['libelle'], 0, 190), 'Tâche du type « ' . $t['nom'] . ' »', vcEcheance((string) $v['prevu_le'], $x['quand'], (int) $x['delai']), 'a_faire', 'type', $now, $now]);
        $n++;
    }
    return $n;
}

/** La visite a bougé : les tâches du type encore ouvertes suivent la nouvelle date. */
function vcTachesRecaler(array $v): void
{
    foreach (Db::rows('SELECT id, quand, delai FROM ceo_consultant_tache WHERE visite_id = ? AND source = \'type\' AND statut IN (\'a_faire\', \'en_cours\') AND quand IS NOT NULL', [(int) $v['id']]) as $r) {
        Db::exec('UPDATE ceo_consultant_tache SET echeance = ?, maj_le = ? WHERE id = ?', [vcEcheance((string) $v['prevu_le'], (string) $r['quand'], (int) $r['delai']), date('Y-m-d H:i:s'), (int) $r['id']]);
    }
}

function vcTachesAnnuler(int $visiteId): void
{
    Db::exec('UPDATE ceo_consultant_tache SET statut = \'annule\', maj_le = ? WHERE visite_id = ? AND statut IN (\'a_faire\', \'en_cours\')', [date('Y-m-d H:i:s'), $visiteId]);
}

/** Les tâches de plusieurs visites (l'application terrain les montre sur la fiche). */
function vcTachesDesVisites(array $ids): array
{
    if ($ids === []) { return []; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return array_map('vcTacheLigne', Db::rows("SELECT * FROM ceo_consultant_tache WHERE visite_id IN ($in) ORDER BY echeance, id", array_map('intval', $ids)));
}

/** POST /consultants/taches — une tâche que le consultant se donne (ou qu'on lui donne). */
function wr_consultants_tache(): array
{
    ensureVisitesCadre();
    $b = body();
    $titre = mb_substr(trim((string) ($b['titre'] ?? '')), 0, 190);
    if ($titre === '') { http_response_code(422); return ['error' => 'titre requis']; }
    $cons = mb_substr(trim((string) ($b['consultant'] ?? '')), 0, 32);
    if ($cons === '') { $cons = (string) (consultantIdCompte() ?? ''); }
    if ($cons === '') { http_response_code(422); return ['error' => 'consultant requis']; }
    $cid = mb_substr(trim((string) ($b['client_id'] ?? '')), 0, 40) ?: null;
    if ($cid !== null && ($ex = Db::row('SELECT * FROM ceo_consultant_tache WHERE client_id = ?', [$cid])) !== null) { return ['ok' => true, 'tache' => vcTacheLigne($ex), 'deja' => true]; }
    $shop = trim((string) ($b['shop'] ?? ''));
    $shop = $shop !== '' && isset(viMagasins()[$shop]) ? $shop : null;
    $source = in_array($b['source'] ?? '', VC_SOURCES, true) ? $b['source'] : 'perso';
    $ech = viDate($b['echeance'] ?? null);
    $vid = isset($b['visite_id']) && is_numeric($b['visite_id']) ? (int) $b['visite_id'] : null;
    $now = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO ceo_consultant_tache (client_id, consultant_id, consultant_nom, shop_id, visite_id, source, quand, delai, titre, detail, echeance, statut, cree_par, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$cid, $cons, viConsultantNom($cons), $shop, $vid, $source, null, null, $titre, mb_substr(trim((string) ($b['detail'] ?? '')), 0, 2000) ?: null, $ech, 'a_faire', viQui($b), $now, $now]);
    $id = (int) Db::pdo()->lastInsertId();
    return ['ok' => true, 'tache' => vcTacheLigne(Db::row('SELECT * FROM ceo_consultant_tache WHERE id = ?', [$id]))];
}

/** PUT /consultants/taches/{id} — statut, titre, échéance, détail. */
function wr_consultants_tache_put(string $id): array
{
    ensureVisitesCadre();
    $t = ctype_digit($id) ? Db::row('SELECT * FROM ceo_consultant_tache WHERE id = ?', [(int) $id]) : Db::row('SELECT * FROM ceo_consultant_tache WHERE client_id = ?', [$id]);
    if ($t === null) { http_response_code(404); return ['error' => 'tâche inconnue']; }
    $b = body();
    $set = []; $args = [];
    if (isset($b['statut'])) {
        if (!in_array($b['statut'], VC_STATUTS_TACHE, true)) { http_response_code(422); return ['error' => 'statut inconnu']; }
        $set[] = 'statut = ?'; $args[] = $b['statut'];
        $set[] = 'fait_le = ?'; $args[] = $b['statut'] === 'fait' ? date('Y-m-d H:i:s') : null;
    }
    if (isset($b['titre']) && trim((string) $b['titre']) !== '') { $set[] = 'titre = ?'; $args[] = mb_substr(trim((string) $b['titre']), 0, 190); }
    if (array_key_exists('detail', $b)) { $set[] = 'detail = ?'; $args[] = mb_substr(trim((string) $b['detail']), 0, 2000) ?: null; }
    if (array_key_exists('echeance', $b)) { $set[] = 'echeance = ?'; $args[] = viDate($b['echeance']); }
    if (isset($b['shop'])) { $s = trim((string) $b['shop']); $set[] = 'shop_id = ?'; $args[] = $s !== '' && isset(viMagasins()[$s]) ? $s : null; }
    if (!$set) { return ['ok' => true, 'tache' => vcTacheLigne($t)]; }
    $set[] = 'maj_le = ?'; $args[] = date('Y-m-d H:i:s'); $args[] = (int) $t['id'];
    Db::exec('UPDATE ceo_consultant_tache SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    return ['ok' => true, 'tache' => vcTacheLigne(Db::row('SELECT * FROM ceo_consultant_tache WHERE id = ?', [(int) $t['id']]))];
}

/* --- après une écriture de visite ---------------------------------------------- */

/** Après POST /visites : les tâches du type, l'invitation. */
function vcApresPlanification(int $visiteId): array
{
    $v = Db::row('SELECT * FROM ceo_visite WHERE id = ?', [$visiteId]);
    if ($v === null) { return ['taches' => 0, 'invitation' => null]; }
    return ['taches' => vcTachesGenerer($v), 'invitation' => vcInviter($v, 'REQUEST')];
}

/** Après PUT /visites/{id} : si la visite a bougé ou s'annule, les tâches suivent et l'agenda est prévenu ; terminée, ses tâches « pendant » sont faites. */
function vcApresChangement(array $avant, array $apres): array
{
    $out = ['taches' => false, 'invitation' => null, 'note' => null];
    $bouge = $avant['prevu_le'] !== $apres['prevu_le'] || $avant['debut_h'] !== $apres['debut_h'] || (int) $avant['duree_min'] !== (int) $apres['duree_min']
        || ($avant['type_code'] ?? '') !== ($apres['type_code'] ?? '') || (string) $avant['consultant_id'] !== (string) $apres['consultant_id'];
    $annulee = $apres['statut'] === 'annulee' && $avant['statut'] !== 'annulee';
    if ($annulee) {
        vcTachesAnnuler((int) $apres['id']);
        Db::exec('UPDATE ceo_visite SET ics_seq = ics_seq + 1 WHERE id = ?', [(int) $apres['id']]);
        $apres['ics_seq'] = (int) ($apres['ics_seq'] ?? 0) + 1;
        $out['taches'] = true; $out['invitation'] = vcInviter($apres, 'CANCEL');
    } elseif ($bouge && in_array($apres['statut'], ['planifiee', 'confirmee'], true)) {
        if ((string) $avant['consultant_id'] !== (string) $apres['consultant_id'] || ($avant['type_code'] ?? '') !== ($apres['type_code'] ?? '')) {
            vcTachesAnnuler((int) $apres['id']);
            Db::exec('DELETE FROM ceo_consultant_tache WHERE visite_id = ? AND source = \'type\' AND statut = \'annule\'', [(int) $apres['id']]);
            vcTachesGenerer($apres);
        } else { vcTachesRecaler($apres); }
        Db::exec('UPDATE ceo_visite SET ics_seq = ics_seq + 1 WHERE id = ?', [(int) $apres['id']]);
        $apres['ics_seq'] = (int) ($apres['ics_seq'] ?? 0) + 1;
        $out['taches'] = true; $out['invitation'] = vcInviter($apres, 'REQUEST');
    }
    if ($apres['statut'] === 'terminee' && $avant['statut'] !== 'terminee') {
        Db::exec('UPDATE ceo_consultant_tache SET statut = \'fait\', fait_le = ?, maj_le = ? WHERE visite_id = ? AND quand = \'pendant\' AND statut IN (\'a_faire\', \'en_cours\')', [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int) $apres['id']]);
        $out['note'] = vcNotePanel($apres);
    }
    return $out;
}

/** La note de visite déposée dans le panel (type VISIT), si le réglage le demande. */
function vcNotePanel(array $v): ?array
{
    if (empty(viSeuils()['notePanel']) || !function_exists('notePanelDeposer')) { return null; }
    $mags = viMagasins(); $court = $mags[(string) $v['shop_id']]['court'] ?? (string) $v['shop_id'];
    $t = vcType((string) ($v['type_code'] ?? 'reguliere'));
    $texte = 'Visite ' . ($t['nom'] ?? 'régulière') . ' du ' . date('d/m/Y', strtotime((string) $v['prevu_le'])) . ' à ' . $court . ' par ' . $v['consultant_nom']
        . (!empty($v['reco']) ? "\nRecommandation : " . mb_substr((string) $v['reco'], 0, 800) : '')
        . (!empty($v['diagnostic']) ? "\nDiagnostic : " . mb_substr((string) $v['diagnostic'], 0, 800) : '');
    try { return notePanelDeposer((string) $v['shop_id'], 'Visite', $texte, 'visite #' . $v['id']); }
    catch (Throwable $e) { return ['id' => null, 'motif' => $e->getMessage()]; }
}

/* --- l'agenda : ICS, lien Google, invitations ---------------------------------- */

function vcIcsJeton(string $cons): string
{
    return substr(hash_hmac('sha256', 'ics:' . $cons, (string) setting('visitesJeton', '')), 0, 32);
}

function vcIcsUrl(string $cons): string
{
    return rtrim(rapBaseUrl(), '/') . '/api/cockpit/consultants/' . rawurlencode($cons) . '/visites.ics?jeton=' . vcIcsJeton($cons);
}

function vcIcsTexte(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], $s);
}

/** Une ligne iCalendar pliée à 74 caractères, comme la norme le demande. */
function vcIcsPlie(string $l): string
{
    $out = ''; $i = 0; $n = mb_strlen($l);
    while ($i < $n) { $out .= ($i > 0 ? "\r\n " : '') . mb_substr($l, $i, 74); $i += 74; }
    return $out;
}

function vcIcsDate(string $date, string $heure): string
{
    return str_replace('-', '', $date) . 'T' . str_replace(':', '', $heure) . '00';
}

function vcIcsFin(string $date, string $heure, int $duree): string
{
    return date('Ymd\THis', strtotime($date . ' ' . $heure) + max(15, $duree) * 60);
}

/** L'adresse de la fiche de visite dans l'application terrain. */
function vcFicheUrl(array $v): string
{
    return rtrim(rapBaseUrl(), '/') . '/visites/?role=consultant&id=' . rawurlencode((string) ($v['consultant_id'] ?? $v['consultant'] ?? '')) . '#fiche/' . (int) $v['id'];
}

/** Le titre d'un événement : « Visite Halle — Production ». */
function vcTitre(array $v, ?array $mag): string
{
    $t = vcType((string) ($v['type_code'] ?? $v['type'] ?? 'reguliere'));
    return 'Visite ' . ($mag['court'] ?? (string) ($v['shop_id'] ?? $v['shop'] ?? '')) . ' — ' . ($t['nom'] ?? 'Régulière');
}

/** Un VEVENT d'une ligne de ceo_visite (colonnes brutes). */
function vcIcsEvenement(array $v, string $methode = 'PUBLISH', ?string $invite = null): string
{
    $mags = viMagasins(); $mag = $mags[(string) $v['shop_id']] ?? null;
    $statut = $v['statut'] === 'annulee' || $methode === 'CANCEL' ? 'CANCELLED' : ($v['statut'] === 'confirmee' ? 'CONFIRMED' : 'TENTATIVE');
    $desc = ($mag ? $mag['nom'] . ($mag['fr'] ? ' · ' . $mag['fr'] : '') . "\n" : '') . 'Consultant : ' . $v['consultant_nom'] . "\n" . 'Fiche : ' . vcFicheUrl($v);
    $l = ['BEGIN:VEVENT', 'UID:visite-' . (int) $v['id'] . '@cockpit.latelier', 'SEQUENCE:' . (int) ($v['ics_seq'] ?? 0), 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART;TZID=Europe/Brussels:' . vcIcsDate((string) $v['prevu_le'], (string) $v['debut_h']),
        'DTEND;TZID=Europe/Brussels:' . vcIcsFin((string) $v['prevu_le'], (string) $v['debut_h'], (int) $v['duree_min']),
        'SUMMARY:' . vcIcsTexte(vcTitre($v, $mag)),
        'LOCATION:' . vcIcsTexte($mag ? trim($mag['nom'] . ($mag['ville'] ? ', ' . $mag['ville'] : '')) : (string) $v['shop_id']),
        'DESCRIPTION:' . vcIcsTexte($desc), 'STATUS:' . $statut, 'URL:' . vcFicheUrl($v)];
    if ($methode !== 'PUBLISH') {
        $de = class_exists('Smtp') ? (string) (Smtp::config()['expediteur'] ?? '') : '';
        $adresse = preg_match('/<([^>]+)>/', $de, $m) ? $m[1] : $de;
        if ($adresse !== '') { $l[] = 'ORGANIZER;CN=' . vcIcsTexte('Cockpit L’Atelier') . ':mailto:' . $adresse; }
        if ($invite !== null) { $l[] = 'ATTENDEE;CN=' . vcIcsTexte((string) $v['consultant_nom']) . ';ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:' . $invite; }
    }
    $l[] = 'END:VEVENT';
    return implode("\r\n", array_map('vcIcsPlie', $l)) . "\r\n";
}

function vcIcsCalendrier(array $visites, string $methode, string $nom, ?string $invite = null): string
{
    $h = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//L’Atelier by//Cockpit visites//FR', 'CALSCALE:GREGORIAN', 'METHOD:' . $methode,
        'X-WR-CALNAME:' . vcIcsTexte($nom), 'X-WR-TIMEZONE:Europe/Brussels'];
    $out = implode("\r\n", array_map('vcIcsPlie', $h)) . "\r\n";
    foreach ($visites as $v) { $out .= vcIcsEvenement($v, $methode, $invite); }
    return $out . "END:VCALENDAR\r\n";
}

/** GET /consultants/{id}/visites.ics?jeton= — le flux auquel Google Agenda s'abonne. Sort en text/calendar. */
function vcIcsSortie(string $cons): never
{
    ensureVisitesCadre();
    $jeton = (string) ($_GET['jeton'] ?? '');
    if ($jeton === '' || !hash_equals(vcIcsJeton($cons), $jeton)) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo 'jeton invalide'; exit; }
    $du = date('Y-m-d', strtotime('-60 days')); $au = date('Y-m-d', strtotime('+180 days'));
    $rows = Db::rows('SELECT * FROM ceo_visite WHERE consultant_id = ? AND prevu_le BETWEEN ? AND ? AND statut <> \'annulee\' ORDER BY prevu_le, debut_h', [$cons, $du, $au]);
    $ics = vcIcsCalendrier($rows, 'PUBLISH', 'Visites — ' . viConsultantNom($cons));
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="visites-' . preg_replace('/[^\w-]/', '', $cons) . '.ics"');
    header('Cache-Control: no-store');
    echo $ics;
    exit;
}

/** Le lien « Ajouter à Google Agenda » d'une visite (ligne brute ou viVisiteLigne). */
function vcGoogleLien(array $v, ?array $mag = null): string
{
    $shop = (string) ($v['shop_id'] ?? $v['shop'] ?? '');
    $mag = $mag ?? (viMagasins()[$shop] ?? null);
    $date = (string) $v['prevu_le']; $h = (string) $v['debut_h']; $duree = (int) $v['duree_min'];
    $lieu = $mag ? trim($mag['nom'] . ($mag['ville'] ? ', ' . $mag['ville'] : '')) : $shop;
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode(vcTitre($v, $mag))
        . '&dates=' . vcIcsDate($date, $h) . '/' . vcIcsFin($date, $h, $duree) . '&ctz=Europe%2FBrussels'
        . '&location=' . rawurlencode($lieu) . '&details=' . rawurlencode('Fiche de visite : ' . vcFicheUrl($v));
}

/** L'invitation par courriel (.ics en pièce jointe) au consultant de la visite. */
function vcInviter(array $v, string $methode): array
{
    $s = viSeuils();
    if (empty($s['invitations'])) { return ['envoye' => false, 'motif' => 'invitations désactivées (réglages)']; }
    if (!class_exists('Smtp') || !Smtp::configured()) { return ['envoye' => false, 'motif' => 'SMTP non configuré']; }
    $email = null;
    foreach (viConsultants() as $c) { if ($c['id'] === (string) $v['consultant_id'] && !empty($c['email'])) { $email = (string) $c['email']; } }
    if ($email === null) { return ['envoye' => false, 'motif' => 'consultant sans adresse']; }
    $mags = viMagasins(); $mag = $mags[(string) $v['shop_id']] ?? null;
    $titre = vcTitre($v, $mag);
    $quand = date('d/m/Y', strtotime((string) $v['prevu_le'])) . ' à ' . $v['debut_h'] . ' (' . (int) $v['duree_min'] . ' min)';
    $sujet = ($methode === 'CANCEL' ? 'Annulée : ' : ((int) ($v['ics_seq'] ?? 0) > 0 ? 'Mise à jour : ' : 'Invitation : ')) . $titre . ' — ' . $quand;
    $html = '<p>' . htmlspecialchars($titre) . '<br>' . htmlspecialchars($quand) . ($mag ? '<br>' . htmlspecialchars($mag['nom'] . ($mag['ville'] ? ', ' . $mag['ville'] : '')) : '') . '</p>'
        . ($methode === 'CANCEL' ? '<p>Cette visite est annulée.</p>' : '<p>Ouvrez la pièce jointe pour l’ajouter à votre agenda, ou <a href="' . htmlspecialchars(vcGoogleLien($v, $mag)) . '">ajoutez-la à Google Agenda</a>.</p>')
        . '<p><a href="' . htmlspecialchars(vcFicheUrl($v)) . '">La fiche de visite</a></p>';
    $ics = vcIcsCalendrier([$v], $methode, $titre, $email);
    try {
        $ok = Smtp::envoyer($email, $sujet, $html, [['nom' => 'visite-' . (int) $v['id'] . '.ics', 'type' => 'text/calendar; charset=UTF-8; method=' . $methode, 'contenu' => $ics]]);
    } catch (Throwable $e) { return ['envoye' => false, 'motif' => $e->getMessage()]; }
    return ['envoye' => $ok, 'motif' => $ok ? null : (Smtp::$lastError ?? 'envoi refusé'), 'a' => $email];
}

/* --- GET /consultants/gestion --------------------------------------------------- */

/** Les cas du helpdesk attribués au consultant, lus chez le panel quand il répond — en tâches. */
function vcHelpdesk(): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['lu' => false, 'motif' => 'compte consultant non configuré', 'cas' => []]; }
    try { $r = PanelApi::get('/consultant/tasks/helpdesk'); } catch (Throwable $e) { return ['lu' => false, 'motif' => $e->getMessage(), 'cas' => []]; }
    if (!is_array($r)) { return ['lu' => false, 'motif' => PanelApi::$lastError ?? 'le panel n’a pas répondu', 'cas' => []]; }
    $liste = isset($r['data']) && is_array($r['data']) ? $r['data'] : (isset($r['cases']) && is_array($r['cases']) ? $r['cases'] : (array_is_list($r) ? $r : []));
    $cas = [];
    foreach ($liste as $c) {
        if (!is_array($c)) { continue; }
        $cas[] = ['id' => $c['id'] ?? null, 'titre' => mb_substr((string) ($c['title'] ?? $c['subject'] ?? $c['name'] ?? ('Cas #' . ($c['id'] ?? ''))), 0, 190),
            'shop' => isset($c['shop_id']) ? (string) $c['shop_id'] : null, 'statut' => (string) ($c['status'] ?? ''), 'echeance' => substr((string) ($c['due_date'] ?? $c['due_at'] ?? ''), 0, 10) ?: null,
            'domaine' => (string) ($c['area_key'] ?? $c['area'] ?? ''), 'cree_le' => substr((string) ($c['created_at'] ?? ''), 0, 16) ?: null];
    }
    return ['lu' => true, 'motif' => null, 'cas' => $cas];
}

/**
 * GET /consultants/gestion?consultant=u6&mois=2026-10 — tout ce que l'écran
 * « Gestion consultant » montre : les visites du mois (tous consultants, les
 * siennes marquées), leurs points, les plans d'action ouverts, ses tâches, le
 * cadre face au réel, l'état des boutiques (feu), le réseau consultants ×
 * magasins, l'agenda (flux ICS, invitations). `consultant=tous` pour le réseau.
 */
function ep_consultants_gestion(): array
{
    ensureVisitesCadre();
    $mois = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['mois'] ?? '')) ? (string) $_GET['mois'] : date('Y-m');
    $cons = trim((string) ($_GET['consultant'] ?? ''));
    if ($cons === '') { $cons = (string) (consultantIdCompte() ?? ''); }
    if ($cons === 'tous') { $cons = ''; }
    $consultants = viConsultants();
    foreach ($consultants as &$c) { $c['profil'] = vcProfilDe($c['id']); $c['profilNom'] = vcProfilNom($c['profil']); $c['ics'] = vcIcsUrl($c['id']); }
    unset($c);
    $moi = null;
    foreach ($consultants as $c) { if ($c['id'] === $cons) { $moi = $c; } }
    if ($cons !== '' && $moi === null) { $moi = ['id' => $cons, 'nom' => viConsultantNom($cons), 'email' => null, 'profil' => vcProfilDe($cons), 'profilNom' => vcProfilNom(vcProfilDe($cons)), 'ics' => vcIcsUrl($cons)]; }
    $mags = viMagasins();
    $debut = $mois . '-01'; $fin = date('Y-m-t', strtotime($debut));
    $du = date('Y-m-d', strtotime($debut . ' -6 days')); $au = date('Y-m-d', strtotime($fin . ' +6 days'));
    $auj = date('Y-m-d');
    $types = vcTypes(); $typeNom = []; foreach ($types as $t) { $typeNom[$t['code']] = $t['nom']; }

    // Les visites de la fenêtre, tous consultants : la grille montre celles des autres en gris.
    $visites = array_map('viVisiteLigne', Db::rows('SELECT * FROM ceo_visite WHERE prevu_le BETWEEN ? AND ? ORDER BY prevu_le, debut_h', [$du, $au]));
    $ids = array_map(fn ($v) => $v['id'], $visites);
    $pts = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::rows("SELECT visite_id, COUNT(*) n, SUM(CASE WHEN etat <> '' OR note IS NOT NULL OR valeur IS NOT NULL THEN 1 ELSE 0 END) faits, SUM(CASE WHEN etat = 'ko' OR (note IS NOT NULL AND note <= 2) THEN 1 ELSE 0 END) ko FROM ceo_visite_point WHERE visite_id IN ($in) GROUP BY visite_id", $ids) as $r) {
            $pts[(int) $r['visite_id']] = ['total' => (int) $r['n'], 'faits' => (int) $r['faits'], 'ko' => (int) $r['ko']];
        }
    }
    foreach ($visites as &$v) {
        $v['typeNom'] = $typeNom[$v['type']] ?? $v['type'];
        $v['magasin'] = $mags[$v['shop']]['court'] ?? $v['shop'];
        $v['points'] = $pts[$v['id']] ?? null;
        $v['mienne'] = $cons === '' || (string) $v['consultant'] === $cons;
        $v['google'] = vcGoogleLien($v, $mags[$v['shop']] ?? null);
    }
    unset($v);

    // Les plans d'action ouverts (fermés depuis 30 jours compris), avec le magasin.
    $plans = [];
    foreach (viPlans(null, 30) as $p) { $p['magasin'] = $mags[$p['shop']]['court'] ?? $p['shop']; $plans[] = $p; }

    // Les tâches : ouvertes (toutes), à échéance dans la fenêtre, ou faites ce mois.
    $sql = 'SELECT * FROM ceo_consultant_tache WHERE (statut IN (\'a_faire\', \'en_cours\') OR (echeance BETWEEN ? AND ?) OR (fait_le >= ?))' . ($cons !== '' ? ' AND consultant_id = ?' : '') . ' ORDER BY echeance IS NULL, echeance, id';
    $args = [$du, $au, $debut . ' 00:00:00']; if ($cons !== '') { $args[] = $cons; }
    $taches = [];
    foreach (array_map('vcTacheLigne', Db::rows($sql, $args)) as $t) { $t['magasin'] = $t['shop'] !== null ? ($mags[$t['shop']]['court'] ?? $t['shop']) : null; $taches[] = $t; }

    // L'état des boutiques : le feu, la dernière et la prochaine visite, les plans ouverts.
    $etat = viBoutiquesEtat(null, date('Y-m-d', strtotime('-90 days')), date('Y-m-d', strtotime('+60 days')));
    $boutiques = array_map(fn ($b) => ['id' => $b['id'], 'nom' => $b['nom'], 'court' => $b['court'], 'ville' => $b['ville'], 'fr' => $b['fr'], 'feu' => $b['feu'], 'motifs' => $b['motifs'], 'due' => $b['due'],
        'plansOuverts' => $b['plansOuverts'], 'p0' => $b['p0'], 'derniereVisite' => $b['derniereVisite'], 'prochaineVisite' => $b['prochaineVisite'], 'frequence' => $b['frequence'],
        'google' => $b['google'] ? ['note' => $b['google']['note'], 'avis' => $b['google']['avis']] : null, 'ca' => $b['ca'] ? ['ca' => $b['ca']['ca'], 'pct' => $b['ca']['pct']] : null], $etat['boutiques']);

    // Le réseau : consultant × magasin — dernière visite faite, prochaine planifiée, visites du mois, tâches ouvertes.
    $derniers = []; $prochains = [];
    foreach (Db::rows('SELECT consultant_id, shop_id, MAX(prevu_le) d FROM ceo_visite WHERE statut = \'terminee\' GROUP BY consultant_id, shop_id') as $r) { $derniers[(string) $r['consultant_id']][(string) $r['shop_id']] = $r['d']; }
    foreach (Db::rows('SELECT consultant_id, shop_id, MIN(prevu_le) d FROM ceo_visite WHERE statut IN (\'planifiee\', \'confirmee\', \'en_cours\') AND prevu_le >= ? GROUP BY consultant_id, shop_id', [$auj]) as $r) { $prochains[(string) $r['consultant_id']][(string) $r['shop_id']] = $r['d']; }
    $ouvertes = []; $retards = [];
    foreach (Db::rows('SELECT consultant_id, COUNT(*) n, SUM(CASE WHEN echeance IS NOT NULL AND echeance < ? THEN 1 ELSE 0 END) r FROM ceo_consultant_tache WHERE statut IN (\'a_faire\', \'en_cours\') GROUP BY consultant_id', [$auj]) as $r) { $ouvertes[(string) $r['consultant_id']] = (int) $r['n']; $retards[(string) $r['consultant_id']] = (int) $r['r']; }
    $reseau = [];
    foreach ($consultants as $c) {
        $faites = 0; $planifiees = 0; $cells = [];
        foreach ($visites as $v) { if ((string) $v['consultant'] !== $c['id'] || $v['prevu_le'] < $debut || $v['prevu_le'] > $fin || $v['statut'] === 'annulee') { continue; } if ($v['statut'] === 'terminee') { $faites++; } else { $planifiees++; } }
        foreach ($mags as $sid => $m) {
            $sid = (string) $sid;
            $cells[$sid] = ['derniere' => $derniers[$c['id']][$sid] ?? null, 'prochaine' => $prochains[$c['id']][$sid] ?? null,
                'responsable' => (bool) array_filter(vcCadreDe($sid), fn ($l) => $l['consultant'] === $c['id'])];
        }
        $reseau[] = ['id' => $c['id'], 'nom' => $c['nom'], 'profil' => $c['profil'], 'profilNom' => $c['profilNom'], 'faites' => $faites, 'planifiees' => $planifiees,
            'tachesOuvertes' => $ouvertes[$c['id']] ?? 0, 'tachesRetard' => $retards[$c['id']] ?? 0, 'magasins' => $cells, 'email' => !empty($c['email'])];
    }

    $s = viSeuils();
    return ['mois' => $mois, 'du' => $du, 'au' => $au, 'aujourdhui' => $auj, 'consultant' => $moi, 'consultants' => $consultants,
        'magasins' => array_values($mags), 'types' => vcTypesCourts(), 'profils' => vcProfils(),
        'visites' => $visites, 'plans' => $plans, 'taches' => $taches, 'cadre' => vcAttendu($mois), 'boutiques' => $boutiques, 'reseau' => $reseau,
        'helpdesk' => $cons !== '' ? vcHelpdesk() : ['lu' => false, 'motif' => 'par consultant', 'cas' => []],
        'agenda' => ['ics' => $moi ? $moi['ics'] : null, 'webcal' => $moi ? preg_replace('#^https?://#', 'webcal://', $moi['ics']) : null,
            'invitations' => !empty($s['invitations']), 'smtp' => class_exists('Smtp') && Smtp::configured(), 'notePanel' => !empty($s['notePanel'])],
        'lu' => date('Y-m-d H:i')];
}
