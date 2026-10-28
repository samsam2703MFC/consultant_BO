<?php
declare(strict_types=1);

/**
 * MétaDonnée — les formulaires de prospects Facebook et Instagram (Meta Lead
 * Ads) des pages du réseau, jusqu'à l'ERP du franchisé, et le contrôle de ce
 * qu'il en fait (maquette A retenue le 28/10/2026 : un sous-menu en quatre
 * étapes dans le rail, sous « ERP franchisé »).
 *
 * Le chemin d'un lead, de bout en bout :
 *
 *  1. FORMULAIRES — les formulaires de chaque page que le System User voit
 *     (`/{page}/leadgen_forms`). On CHOISIT ceux qu'on suit : un formulaire non
 *     suivi (un concours, un recrutement) ne laisse rien entrer, pas même une
 *     donnée personnelle en base — son lead est seulement compté ;
 *  2. MAPPING — chaque question d'un formulaire suivi trouve sa place dans un
 *     champ de l'ERP (`MD_CHAMPS`), avec sa règle : nom coupé en deux,
 *     téléphone au format international, tranche ramenée à un nombre, réponse
 *     traduite en code. Une question sans champ part dans la note. Tant que le
 *     mapping n'est pas enregistré, les leads du formulaire attendent ;
 *  3. ENVOI — le lead part dans l'ERP du magasin (POST, idempotent sur le
 *     numéro de lead Meta) ; le magasin vient du formulaire, ou du code postal
 *     quand un formulaire couvre plusieurs magasins. Sans magasin, le lead est
 *     « à attribuer » : le consultant le pose à la main ;
 *  4. SUIVI — le statut est relu dans l'ERP toutes les 10 minutes : vu,
 *     contacté, bureau créé, bureau connecté (le bureau a son compte au
 *     webshop), perdu. Pas vu après 2 h ou pas rappelé après 24 h : en retard ;
 *  5. SMS — le gérant du magasin reçoit un SMS à l'arrivée de chaque lead
 *     (SMSAPI, comme le CRM), entre 8 h et 20 h ; une relance s'il ne l'a pas
 *     ouvert ; le consultant un e-mail s'il n'est toujours pas rappelé.
 *
 * Arrivée : le webhook `leadgen` des pages — le même point d'entrée que le
 * Brand Guard, qui y reçoit `feed` — et un rattrapage horaire sur trois jours.
 * L'horloge : bin/metadonnee_cron.sh toutes les 5 minutes.
 *
 * Secrets : le jeton Meta est celui du Brand Guard (System User, hors base),
 * l'ERP passe par le compte API du panel (PanelApi) ; seul le jeton SMSAPI
 * peut être saisi à l'écran (réglage `mdSmsJeton`, jamais renvoyé). Les routes
 * de l'ERP sont des réglages : elles restent à écrire côté panel
 * (docs/contrat-api.md, « MétaDonnée »).
 *
 * `MdErp::$transport`, `MdSms::$transport` et `MetaGraph::$transport`
 * s'injectent : bin/tests_metadonnee.php vérifie la logique sans réseau.
 */

/** Les champs de l'ERP qu'une question peut remplir : clé → [libellé, règle]. */
const MD_CHAMPS = [
    'contact.full_name'  => ['Nom complet → prénom + nom', 'coupe'],
    'contact.first_name' => ['Prénom', 'texte'],
    'contact.last_name'  => ['Nom', 'texte'],
    'contact.email'      => ['E-mail', 'email'],
    'contact.phone'      => ['Téléphone', 'tel'],
    'contact.role'       => ['Fonction', 'texte'],
    'company.name'       => ['Entreprise', 'texte'],
    'company.vat'        => ['N° de TVA', 'texte'],
    'company.address'    => ['Adresse', 'texte'],
    'company.zip'        => ['Code postal', 'cp'],
    'company.city'       => ['Localité', 'texte'],
    'lead.headcount'     => ['Nombre de personnes', 'nombre'],
    'lead.need'          => ['Besoin (code)', 'code'],
    'lead.wanted_date'   => ['Pour quand (date)', 'date'],
    'lead.note'          => ['Note', 'note'],
    'lead.consent'       => ['Consentement', 'accord'],
];

/** Sans eux, l'ERP ne reçoit rien : le franchisé doit pouvoir rappeler un bureau. */
const MD_REQUIS = ['contact.phone', 'company.name'];

/** Les codes de besoin proposés pour `lead.need` (la liste reste libre). */
const MD_BESOINS = ['daily_lunch' => 'Lunch tous les jours', 'meeting_trays' => 'Plateaux réunion',
    'team_breakfast' => 'Petit-déjeuner d’équipe', 'event' => 'Événement', 'other' => 'Autre'];

/** Le traitement par le franchisé, tel que relu dans l'ERP, dans l'ordre. */
const MD_STATUTS = ['nouveau' => 'pas vu', 'vu' => 'vu · à rappeler', 'contacte' => 'contacté',
    'bureau_cree' => 'bureau créé', 'bureau_connecte' => 'bureau connecté', 'perdu' => 'perdu'];

/* --- tables et réglages ---------------------------------------------------------- */

/**
 * Les quatre tables, créées à la première utilisation (le cockpit n'a pas de
 * migration : une base en service ne rejoue pas schema.sql). Idempotent.
 */
function mdTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_md_form ('
        . 'form_id VARCHAR(40) PRIMARY KEY,'
        . "page_id VARCHAR(40) NOT NULL DEFAULT '',"
        . "page_nom VARCHAR(160) NOT NULL DEFAULT '',"
        . "nom VARCHAR(200) NOT NULL DEFAULT '',"
        . "statut_meta VARCHAR(20) NOT NULL DEFAULT '',"
        . "locale VARCHAR(12) NOT NULL DEFAULT '',"
        . 'questions_json JSON NULL,'
        . 'leads_meta INT NULL,'
        . 'suivi TINYINT(1) NOT NULL DEFAULT 0,'
        . "affectation ENUM('magasin','cp') NOT NULL DEFAULT 'magasin',"
        . 'shop_id VARCHAR(16) NULL,'
        . 'mapping_json JSON NULL,'
        . 'valeurs_json JSON NULL,'
        . 'questions_mappees CHAR(12) NULL,'
        . 'mapping_le DATETIME NULL,'
        . 'mapping_par VARCHAR(80) NULL,'
        . 'ignores INT NOT NULL DEFAULT 0,'
        . 'dernier_lead DATETIME NULL,'
        . 'relu_le DATETIME NULL,'
        . 'KEY idx_page (page_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_md_lead ('
        . 'id BIGINT AUTO_INCREMENT PRIMARY KEY,'
        . 'meta_lead_id VARCHAR(40) NOT NULL,'
        . 'form_id VARCHAR(40) NOT NULL,'
        . "page_id VARCHAR(40) NOT NULL DEFAULT '',"
        . "plateforme VARCHAR(4) NOT NULL DEFAULT '',"
        . 'campagne VARCHAR(200) NULL,'
        . 'annonce VARCHAR(200) NULL,'
        . 'recu_le DATETIME NOT NULL,'
        . 'lu_le DATETIME NOT NULL,'
        . "via VARCHAR(12) NOT NULL DEFAULT 'webhook',"
        . 'reponses_json JSON NULL,'
        . 'entreprise VARCHAR(200) NULL,'
        . 'contact VARCHAR(160) NULL,'
        . 'telephone VARCHAR(40) NULL,'
        . 'email VARCHAR(190) NULL,'
        . 'cp VARCHAR(12) NULL,'
        . 'shop_id VARCHAR(16) NULL,'
        . 'shop_par VARCHAR(12) NULL,'
        . "etat ENUM('attente','a_attribuer','a_envoyer','envoye','rejete','erreur') NOT NULL DEFAULT 'attente',"
        . 'motif VARCHAR(300) NULL,'
        . 'essais TINYINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'essai_le DATETIME NULL,'
        . 'erp_id VARCHAR(60) NULL,'
        . 'envoye_le DATETIME NULL,'
        . 'payload_json JSON NULL,'
        . 'statut_erp VARCHAR(20) NULL,'
        . 'statut_brut VARCHAR(60) NULL,'
        . 'statut_le DATETIME NULL,'
        . 'vu_le DATETIME NULL,'
        . 'contacte_le DATETIME NULL,'
        . 'connecte_le DATETIME NULL,'
        . 'relu_le DATETIME NULL,'
        . 'sms_le DATETIME NULL,'
        . 'sms_derniere DATETIME NULL,'
        . 'sms_relances TINYINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'sms_erreur VARCHAR(200) NULL,'
        . 'escalade_le DATETIME NULL,'
        . 'UNIQUE KEY uq_meta (meta_lead_id), KEY idx_form (form_id), KEY idx_etat (etat, recu_le), KEY idx_shop (shop_id, recu_le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_md_shop ('
        . 'shop_id VARCHAR(16) PRIMARY KEY,'
        . 'sms_on TINYINT(1) NOT NULL DEFAULT 0,'
        . "sms_tel VARCHAR(32) NOT NULL DEFAULT '',"
        . "sms_nom VARCHAR(80) NOT NULL DEFAULT '',"
        . "escalade_email VARCHAR(190) NOT NULL DEFAULT '',"
        . 'maj_le DATETIME NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_md_journal ('
        . 'id BIGINT AUTO_INCREMENT PRIMARY KEY,'
        . 'at DATETIME NOT NULL,'
        . 'type VARCHAR(16) NOT NULL,'
        . 'message VARCHAR(400) NOT NULL,'
        . 'lead_id BIGINT NULL,'
        . 'KEY idx_at (at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Le jeton de l'horloge : lu en base par bin/metadonnee_cron.sh à chaque appel.
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
        ['mdJeton', json_encode(bin2hex(random_bytes(24)))]);
}

function mdSettingSet(string $cle, mixed $valeur): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$cle, json_encode($valeur, JSON_UNESCAPED_UNICODE)]);
}

/** Une ligne du journal du module : ce que l'écran « Suivi des leads » montre du cycle. */
function mdJournal(string $type, string $message, ?int $leadId = null): void
{
    try {
        Db::exec('INSERT INTO ceo_md_journal (at, type, message, lead_id) VALUES (?,?,?,?)',
            [date('Y-m-d H:i:s'), $type, mb_substr($message, 0, 400), $leadId]);
    } catch (Throwable $e) { /* le journal ne casse jamais le traitement */ }
}

/** Les réglages par défaut : envoi vers l'ERP et SMS ÉTEINTS tant qu'on ne les allume pas. */
function mdReglagesDefaut(): array
{
    return [
        'erp' => ['envoiActif' => false, 'routeEnvoi' => '/consultant/leads', 'routeStatuts' => '/consultant/leads',
            'lienLead' => 'https://atelierby.tfbuddy.com/panel/leads/{erp_id}', 'relectureMin' => 10, 'relectureJours' => 30],
        'sms' => ['actif' => false, 'heureDebut' => 8, 'heureFin' => 20, 'relanceH' => 2, 'relancesMax' => 2, 'escaladeH' => 24,
            'from' => 'AtelierBy', 'base' => '',
            // Sans apostrophe typographique ni accent hors GSM : 160 caractères par SMS, pas 70.
            'modele' => "L'Atelier by - Lead bureau : {entreprise}, {personnes} pers., {cp}. {contact} {telephone}. ERP : {lien_erp}",
            'modeleRelance' => "L'Atelier by - Rappel : le lead {entreprise} ({cp}) attend depuis {attente}. A traiter : {lien_erp}"],
        'meta' => ['rattrapageJours' => 3, 'rattrapageMin' => 60],
        // Les plages de codes postaux d'un formulaire « par code postal » : [{de, a, shopId, libelle}].
        // Un shopId vide = « à attribuer » par le consultant.
        'cp' => [],
    ];
}

function mdReglages(): array
{
    $d = mdReglagesDefaut();
    $s = setting('mdReglages', []);
    if (!is_array($s)) { return $d; }
    foreach (['erp', 'sms', 'meta'] as $k) {
        if (is_array($s[$k] ?? null)) { $d[$k] = array_merge($d[$k], array_intersect_key($s[$k], $d[$k])); }
    }
    $d['cp'] = mdCpNettoyer($s['cp'] ?? []);
    return $d;
}

/** Les plages de codes postaux : bornes entières, dans l'ordre, rien d'autre. */
function mdCpNettoyer(mixed $plages): array
{
    $out = [];
    foreach (is_array($plages) ? $plages : [] as $p) {
        if (!is_array($p)) { continue; }
        $de = (int) ($p['de'] ?? 0); $a = (int) ($p['a'] ?? 0);
        if ($de <= 0 || $a <= 0) { continue; }
        if ($a < $de) { [$de, $a] = [$a, $de]; }
        $out[] = ['de' => $de, 'a' => $a, 'shopId' => trim((string) ($p['shopId'] ?? '')), 'libelle' => mb_substr(trim((string) ($p['libelle'] ?? '')), 0, 80)];
    }
    usort($out, static fn ($x, $y) => $x['de'] <=> $y['de']);
    return array_slice($out, 0, 60);
}

/* --- lecture pure : réponses, mapping, règles --------------------------------------- */

/** Minuscules sans accents : la clé de comparaison des libellés. */
function mdPlat(string $s): string
{
    $s = mb_strtolower($s);
    return strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'œ' => 'oe', '’' => "'", '_' => ' ']);
}

/** Le libellé d'une question standard de Meta, quand le formulaire n'en donne pas. */
function mdLibelleCle(string $cle, string $type = ''): string
{
    $std = ['full_name' => 'Nom complet', 'first_name' => 'Prénom', 'last_name' => 'Nom', 'email' => 'E-mail', 'work_email' => 'E-mail professionnel',
        'phone_number' => 'Numéro de téléphone', 'work_phone_number' => 'Téléphone professionnel', 'company_name' => 'Nom de l’entreprise',
        'job_title' => 'Fonction', 'zip' => 'Code postal', 'post_code' => 'Code postal', 'city' => 'Ville', 'street_address' => 'Adresse',
        'consentement' => 'Case de consentement'];
    $k = strtolower($cle);
    if (isset($std[$k])) { return $std[$k]; }
    if ($type !== '' && isset($std[strtolower($type)])) { return $std[strtolower($type)]; }
    $l = trim(str_replace('_', ' ', $cle));
    return $l === '' ? $cle : mb_strtoupper(mb_substr($l, 0, 1)) . mb_substr($l, 1);
}

/**
 * Les questions d'un formulaire telles que Meta les rend, ramenées à
 * {key, label, type, options}, plus la case de consentement (une pseudo-
 * question : Meta la rend à part, dans `custom_disclaimer_responses`).
 */
function mdQuestions(array $brutes): array
{
    $out = [];
    foreach ($brutes as $q) {
        if (!is_array($q) || ($q['key'] ?? '') === '') { continue; }
        $opts = [];
        foreach ((array) ($q['options'] ?? []) as $o) {
            $v = is_array($o) ? (string) ($o['value'] ?? ($o['key'] ?? '')) : (string) $o;
            if ($v !== '') { $opts[] = $v; }
        }
        $type = strtoupper((string) ($q['type'] ?? 'CUSTOM'));
        $label = trim((string) ($q['label'] ?? ''));
        $out[] = ['key' => (string) $q['key'], 'label' => $label !== '' ? $label : mdLibelleCle((string) $q['key'], $type), 'type' => $type, 'options' => $opts];
    }
    if (!in_array('consentement', array_column($out, 'key'), true)) {
        $out[] = ['key' => 'consentement', 'label' => 'Case de consentement', 'type' => 'CONSENT', 'options' => []];
    }
    return $out;
}

/** L'empreinte des questions : un mapping enregistré sur d'autres questions se voit. */
function mdEmpreinte(array $questions): string
{
    $k = array_column($questions, 'key');
    sort($k);
    return substr(sha1(implode('|', $k)), 0, 12);
}

/** Le champ de l'ERP qu'une question appelle, d'après son type Meta puis son libellé. '' = la note. */
function mdChampSuggere(array $q): string
{
    $type = strtoupper((string) ($q['type'] ?? ''));
    $cle = strtolower((string) ($q['key'] ?? ''));
    $parType = ['FULL_NAME' => 'contact.full_name', 'FIRST_NAME' => 'contact.first_name', 'LAST_NAME' => 'contact.last_name',
        'EMAIL' => 'contact.email', 'WORK_EMAIL' => 'contact.email', 'PHONE' => 'contact.phone', 'WORK_PHONE_NUMBER' => 'contact.phone',
        'COMPANY_NAME' => 'company.name', 'JOB_TITLE' => 'contact.role', 'ZIP' => 'company.zip', 'POST_CODE' => 'company.zip',
        'CITY' => 'company.city', 'STREET_ADDRESS' => 'company.address', 'CONSENT' => 'lead.consent'];
    if (isset($parType[$type])) { return $parType[$type]; }
    $parCle = ['full_name' => 'contact.full_name', 'first_name' => 'contact.first_name', 'last_name' => 'contact.last_name',
        'email' => 'contact.email', 'work_email' => 'contact.email', 'phone_number' => 'contact.phone', 'work_phone_number' => 'contact.phone',
        'company_name' => 'company.name', 'job_title' => 'contact.role', 'zip' => 'company.zip', 'post_code' => 'company.zip',
        'city' => 'company.city', 'street_address' => 'company.address', 'consentement' => 'lead.consent'];
    if (isset($parCle[$cle])) { return $parCle[$cle]; }
    $l = mdPlat(($q['label'] ?? '') . ' ' . $cle);
    $regles = [
        '/(combien|nombre).*(personne|collabor|employ|salari|bureau)|effectif|nb de personnes/' => 'lead.headcount',
        '/accept|consent|rgpd|gdpr|accord/' => 'lead.consent',
        '/souhait|besoin|interess|quel type|formule|que voulez|que desirez/' => 'lead.need',
        '/pour quand|quand|a partir|date|debut/' => 'lead.wanted_date',
        '/\btva\b|\bbtw\b|\bvat\b/' => 'company.vat',
        '/code postal|postcode|postal|\bcp\b/' => 'company.zip',
        '/telephone|\bgsm\b|portable|phone|mobile/' => 'contact.phone',
        '/e-?mail|courriel/' => 'contact.email',
        '/entreprise|societe|company|raison sociale|nom du bureau/' => 'company.name',
        '/fonction|poste occupe|job/' => 'contact.role',
        '/message|remarque|commentaire|un mot|precision|question/' => 'lead.note',
    ];
    foreach ($regles as $re => $champ) {
        if (preg_match($re, $l)) { return $champ; }
    }
    return '';
}

function mdMappingSuggere(array $questions): array
{
    $m = [];
    foreach ($questions as $q) {
        $c = mdChampSuggere($q);
        if ($c !== '') { $m[(string) $q['key']] = $c; }
    }
    return $m;
}

/**
 * Les réponses d'un lead : `field_data` de la Graph API, libellées par les
 * questions du formulaire ; la case de consentement à part (oui / non).
 *
 * @return list<array{key:string,label:string,valeur:string}>
 */
function mdReponses(array $lead, array $questions): array
{
    $labels = [];
    foreach ($questions as $q) { $labels[(string) ($q['key'] ?? '')] = (string) ($q['label'] ?? ''); }
    $out = [];
    foreach ((array) ($lead['field_data'] ?? []) as $f) {
        $k = (string) ($f['name'] ?? '');
        if ($k === '') { continue; }
        $vals = array_map(static fn ($v) => trim((string) $v), (array) ($f['values'] ?? []));
        $out[] = ['key' => $k, 'label' => ($labels[$k] ?? '') !== '' ? $labels[$k] : mdLibelleCle($k), 'valeur' => implode(', ', array_filter($vals, static fn ($v) => $v !== ''))];
    }
    $cases = (array) ($lead['custom_disclaimer_responses'] ?? []);
    if ($cases) {
        $toutes = true;
        foreach ($cases as $c) { if (empty($c['is_checked']) || $c['is_checked'] === 'false') { $toutes = false; } }
        $out[] = ['key' => 'consentement', 'label' => 'Case de consentement', 'valeur' => $toutes ? 'oui' : 'non'];
    }
    return $out;
}

/** « Claire Mertens » → [Claire, Mertens] ; un seul mot → [mot, '']. */
function mdCouperNom(string $v): array
{
    $v = trim((string) preg_replace('/\s+/u', ' ', $v));
    if ($v === '') { return ['', '']; }
    $p = explode(' ', $v, 2);
    return [$p[0], $p[1] ?? ''];
}

/** L'adresse e-mail, réparée de ce qui ne la change pas (espaces, majuscules, mailto:, ponctuation). */
function mdEmailPropre(string $v): ?string
{
    $e = mb_strtolower(trim($v));
    $e = (string) preg_replace('/^mailto:/', '', $e);
    $e = str_replace(['@,', ',@'], '@', $e);
    $re = '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/';
    // L'adresse telle quelle d'abord (elle peut être dans une phrase), puis sans ses espaces (« jean @ exemple.be »).
    foreach ([$e, (string) preg_replace('/\s+/u', '', $e)] as $essai) {
        if (preg_match($re, $essai, $m)) { return rtrim($m[0], '.'); }
    }
    return null;
}

/** Le téléphone au format international ; un numéro belge sans indicatif prend +32. */
function mdTelE164(string $v): ?string
{
    $s = (string) preg_replace('/[^\d+]/', '', $v);
    if ($s === '' || $s === '+') { return null; }
    if (str_starts_with($s, '00')) { $s = '+' . substr($s, 2); }
    if (str_starts_with($s, '+')) {
        $d = (string) preg_replace('/\D/', '', substr($s, 1));
        if (str_starts_with($d, '320')) { $d = '32' . substr($d, 3); }   // « +32 (0)476… »
        return strlen($d) >= 8 && strlen($d) <= 15 ? '+' . $d : null;
    }
    if (str_starts_with($s, '0') && strlen($s) >= 9 && strlen($s) <= 10) { return '+32' . substr($s, 1); }
    if (str_starts_with($s, '32') && strlen($s) >= 10 && strlen($s) <= 11) { return '+' . $s; }
    if (strlen($s) >= 8 && strlen($s) <= 9) { return '+32' . $s; }
    return null;
}

/** +32476123456 → « 0476 12 34 56 » (ce que lit un gérant) ; un numéro étranger reste tel quel. */
function mdTelLisible(?string $e164): string
{
    $t = (string) $e164;
    if (!str_starts_with($t, '+32')) { return $t; }
    $n = '0' . substr($t, 3);
    if (strlen($n) === 10) { return substr($n, 0, 4) . ' ' . substr($n, 4, 2) . ' ' . substr($n, 6, 2) . ' ' . substr($n, 8, 2); }
    if (strlen($n) === 9) { return substr($n, 0, 3) . ' ' . substr($n, 3, 2) . ' ' . substr($n, 5, 2) . ' ' . substr($n, 7, 2); }
    return $n;
}

/** « 35 » → 35 ; « 26 à 50 » → 38 (le milieu de la tranche) ; « plus de 50 » → 50. */
function mdNombre(string $v): ?int
{
    $t = mdPlat($v);
    if (!preg_match_all('/\d+/', $t, $m) || !$m[0]) { return null; }
    $n = array_map('intval', $m[0]);
    if (count($n) >= 2 && preg_match('/\d+\s*(a|-|–|et|tot|to)\s*\d+/u', $t)) { return (int) round(($n[0] + $n[1]) / 2); }
    return $n[0];
}

/** Une date lisible → Y-m-d : ISO, jj/mm/aaaa, « 4 novembre » (l'année qui vient si la date est passée). */
function mdDate(string $v, ?int $maintenant = null): ?string
{
    $maintenant = $maintenant ?? time();
    $t = mdPlat($v);
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $t, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return $m[1] . '-' . $m[2] . '-' . $m[3]; }
    if (preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\b/', $t, $m)) {
        $a = (int) $m[3]; if ($a < 100) { $a += 2000; }
        if (checkdate((int) $m[2], (int) $m[1], $a)) { return sprintf('%04d-%02d-%02d', $a, (int) $m[2], (int) $m[1]); }
    }
    $mois = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
        'januari' => 1, 'februari' => 2, 'maart' => 3, 'april' => 4, 'juni' => 6, 'juli' => 7, 'augustus' => 8, 'oktober' => 10, 'december' => 12];
    if (preg_match('/\b(\d{1,2})(?:er)?\s+(' . implode('|', array_keys($mois)) . ')(?:\s+(\d{4}))?/', $t, $m)) {
        $j = (int) $m[1]; $mo = $mois[$m[2]];
        $a = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) date('Y', $maintenant);
        if (!isset($m[3]) || $m[3] === '') {
            if (checkdate($mo, $j, $a) && strtotime(sprintf('%04d-%02d-%02d', $a, $mo, $j)) < $maintenant - 86400) { $a++; }
        }
        if (checkdate($mo, $j, $a)) { return sprintf('%04d-%02d-%02d', $a, $mo, $j); }
    }
    return null;
}

function mdOui(string $v): bool
{
    $t = trim(mdPlat($v));
    return in_array($t, ['oui', 'yes', 'ja', 'true', '1', 'ok', 'coche', 'checked', 'x'], true) || str_contains($t, 'accept') || str_contains($t, "d'accord");
}

/**
 * Ce qui part dans l'ERP pour un lead : les réponses passées par le mapping.
 * Une question sans champ part dans la note (« libellé : réponse »), une
 * question « ignorer » ne part pas. `manques` = les champs requis absents ;
 * `consentement` = false quand la case existe et n'est pas cochée.
 *
 * @return array{payload:array, manques:list<string>, avertissements:list<string>, consentement:?bool}
 */
function mdTransformer(array $reponses, array $mapping, array $valeurs = [], ?int $maintenant = null): array
{
    $p = ['company' => [], 'contact' => [], 'lead' => []];
    $noteDirecte = []; $notes = []; $avert = [];
    $consent = null;
    foreach ($reponses as $r) {
        $k = (string) ($r['key'] ?? '');
        $v = trim((string) ($r['valeur'] ?? ''));
        $lib = (string) (($r['label'] ?? '') !== '' ? $r['label'] : $k);
        if ($v === '') { continue; }
        $champ = (string) ($mapping[$k] ?? '');
        if ($champ === 'ignorer') { continue; }
        if ($champ === '' || !isset(MD_CHAMPS[$champ])) { $notes[] = $lib . ' : ' . $v; continue; }
        [$grp, $cle] = explode('.', $champ, 2);
        switch (MD_CHAMPS[$champ][1]) {
            case 'coupe':
                [$pr, $no] = mdCouperNom($v);
                if (!isset($p['contact']['first_name'])) { $p['contact']['first_name'] = $pr; }
                if ($no !== '' && !isset($p['contact']['last_name'])) { $p['contact']['last_name'] = $no; }
                break;
            case 'email':
                $e = mdEmailPropre($v);
                if ($e !== null) { $p[$grp][$cle] = $e; } else { $avert[] = 'adresse illisible : « ' . $v . ' »'; $notes[] = $lib . ' : ' . $v; }
                break;
            case 'tel':
                $t = mdTelE164($v);
                if ($t !== null) { $p[$grp][$cle] = $t; } else { $avert[] = 'téléphone illisible : « ' . $v . ' »'; $notes[] = $lib . ' : ' . $v; }
                break;
            case 'cp':
                $c = (string) preg_replace('/\D/', '', $v);
                if (strlen($c) >= 4 && strlen($c) <= 5) { $p[$grp][$cle] = $c; } else { $notes[] = $lib . ' : ' . $v; }
                break;
            case 'nombre':
                $n = mdNombre($v);
                if ($n !== null) { $p[$grp][$cle] = $n; } else { $notes[] = $lib . ' : ' . $v; }
                break;
            case 'code':
                $code = $valeurs[$k][$v] ?? null;
                if (!is_string($code) || $code === '') { $code = 'other'; $notes[] = $lib . ' : ' . $v; }
                $p[$grp][$cle] = $code;
                break;
            case 'date':
                $d = mdDate($v, $maintenant);
                if ($d !== null) { $p[$grp][$cle] = $d; } else { $notes[] = $lib . ' : ' . $v; }
                break;
            case 'note':
                $noteDirecte[] = $v;
                break;
            case 'accord':
                $consent = mdOui($v);
                $p[$grp][$cle] = $consent;
                break;
            default:
                $p[$grp][$cle] = mb_substr($v, 0, 200);
        }
    }
    $note = array_merge($noteDirecte, $notes);
    if ($note) { $p['lead']['note'] = mb_substr(implode("\n", $note), 0, 2000); }
    $manques = [];
    foreach (MD_REQUIS as $req) {
        [$g, $c] = explode('.', $req, 2);
        if (!isset($p[$g][$c]) || $p[$g][$c] === '') { $manques[] = $req; }
    }
    return ['payload' => $p, 'manques' => $manques, 'avertissements' => $avert, 'consentement' => $consent];
}

/** Le magasin d'un code postal d'après les plages ; null = aucune plage, '' = « à attribuer ». */
function mdMagasinParCp(string $cp, array $plages): ?string
{
    $n = (int) preg_replace('/\D/', '', $cp);
    if ($n <= 0) { return null; }
    foreach ($plages as $p) {
        if ($n >= (int) $p['de'] && $n <= (int) $p['a']) { return (string) ($p['shopId'] ?? ''); }
    }
    return null;
}

/** « nouveau », « Seen », « office_connected »… → la clé de MD_STATUTS ; null si inconnu. */
function mdStatutErp(string $brut): ?string
{
    $s = str_replace(['-', ' '], '_', mdPlat(trim($brut)));
    if ($s === '') { return null; }
    $table = [
        'nouveau' => ['new', 'nouveau', 'open', 'pending', 'received', 'recu', 'created', 'unread', 'non_lu'],
        'vu' => ['seen', 'vu', 'viewed', 'read', 'opened', 'lu'],
        'contacte' => ['contacted', 'contacte', 'called', 'appele', 'in_progress', 'en_cours', 'quote', 'devis', 'devis_envoye', 'meeting', 'rdv', 'trial', 'essai', 'qualified'],
        'bureau_cree' => ['office_created', 'bureau_cree', 'account_created', 'compte_cree', 'onboarding'],
        'bureau_connecte' => ['office_connected', 'bureau_connecte', 'connected', 'connecte', 'won', 'gagne', 'converted', 'customer', 'client', 'active', 'actif'],
        'perdu' => ['lost', 'perdu', 'rejected', 'refused', 'refuse', 'closed', 'cancelled', 'canceled', 'annule', 'spam'],
    ];
    foreach ($table as $cle => $mots) {
        if (in_array($s, $mots, true)) { return $cle; }
    }
    return null;
}

/** Le rang d'un statut dans le traitement (perdu à part). */
function mdRang(?string $s): int
{
    return ['nouveau' => 0, 'vu' => 1, 'contacte' => 2, 'bureau_cree' => 3, 'bureau_connecte' => 4, 'perdu' => 5][(string) $s] ?? 0;
}

/** Les leads qu'un événement `leadgen` annonce : [lead, form, page]. */
function mdLeadsDuWebhook(array $payload): array
{
    $out = [];
    if (($payload['object'] ?? '') !== 'page') { return $out; }
    foreach ((array) ($payload['entry'] ?? []) as $entry) {
        foreach ((array) ($entry['changes'] ?? []) as $ch) {
            if (($ch['field'] ?? '') !== 'leadgen') { continue; }
            $v = (array) ($ch['value'] ?? []);
            $lead = (string) ($v['leadgen_id'] ?? '');
            if ($lead === '') { continue; }
            $out[] = ['lead' => $lead, 'form' => (string) ($v['form_id'] ?? ''), 'page' => (string) ($v['page_id'] ?? ($entry['id'] ?? ''))];
        }
    }
    return $out;
}

/* --- retard, SMS : des fonctions pures, testées sans base ni horloge ------------------- */

/** null, « pas vu » (après relanceH) ou « pas rappelé » (après escaladeH). */
function mdRetard(array $l, array $reg, int $maintenant): ?string
{
    if (($l['etat'] ?? '') !== 'envoye' || empty($l['envoye_le'])) { return null; }
    $age = $maintenant - (int) strtotime((string) $l['envoye_le']);
    $s = (string) ($l['statut_erp'] ?? '');
    $s = $s === '' ? 'nouveau' : $s;
    if ($s === 'nouveau' && $age > (int) $reg['sms']['relanceH'] * 3600) { return 'pas vu'; }
    if (in_array($s, ['nouveau', 'vu'], true) && $age > (int) $reg['sms']['escaladeH'] * 3600) { return 'pas rappelé'; }
    return null;
}

/** L'heure de Bruxelles est-elle dans la fenêtre des SMS ? */
function mdHeureOk(int $heure, array $sms): bool
{
    return $heure >= (int) $sms['heureDebut'] && $heure < (int) $sms['heureFin'];
}

/**
 * Le SMS dû pour un lead : 'premier', 'relance' ou null.
 * Rien hors de la fenêtre horaire (il part à l'ouverture de la suivante), rien
 * si le magasin n'a pas de numéro, rien tant que le lead n'est pas dans l'ERP
 * (le SMS dit « à traiter dans l'ERP »), rien pour un lead déjà vu.
 */
function mdSmsPlan(array $l, array $mag, array $reg, int $maintenant, int $heure): ?string
{
    if (empty($reg['sms']['actif']) || empty($mag['sms_on']) || trim((string) ($mag['sms_tel'] ?? '')) === '') { return null; }
    if (($l['etat'] ?? '') !== 'envoye') { return null; }
    if (!mdHeureOk($heure, $reg['sms'])) { return null; }
    $s = (string) ($l['statut_erp'] ?? '');
    if (empty($l['sms_le'])) {
        // Pas de rafale : un lead déjà vu, entré dans l'ERP il y a plus d'un jour, ou reçu chez Meta il y a plus
        // de trois (l'historique d'un formulaire tout juste suivi) n'en reçoit pas — sauf s'il vient d'être attribué à la main.
        $age = $maintenant - (int) strtotime((string) ($l['envoye_le'] ?? ''));
        $recu = $maintenant - (int) strtotime((string) ($l['recu_le'] ?? $l['envoye_le'] ?? ''));
        $frais = $recu <= 3 * 86400 || ($l['shop_par'] ?? '') === 'main';
        return ($s === '' || $s === 'nouveau') && !empty($l['envoye_le']) && $age <= 86400 && $frais ? 'premier' : null;
    }
    if (($s === '' || $s === 'nouveau') && (int) ($l['sms_relances'] ?? 0) < (int) $reg['sms']['relancesMax']
        && $maintenant - (int) strtotime((string) ($l['sms_derniere'] ?: $l['sms_le'])) >= (int) $reg['sms']['relanceH'] * 3600) {
        return 'relance';
    }
    return null;
}

/** L'e-mail au consultant est-il dû ? Une fois, quand le lead n'est pas rappelé après escaladeH. */
function mdEscaladePlan(array $l, array $mag, array $reg, int $maintenant): bool
{
    if (($l['etat'] ?? '') !== 'envoye' || !empty($l['escalade_le']) || empty($l['envoye_le'])) { return false; }
    if (trim((string) ($mag['escalade_email'] ?? '')) === '') { return false; }
    $s = (string) ($l['statut_erp'] ?? '');
    if (!in_array($s === '' ? 'nouveau' : $s, ['nouveau', 'vu'], true)) { return false; }
    return $maintenant - (int) strtotime((string) $l['envoye_le']) >= (int) $reg['sms']['escaladeH'] * 3600;
}

/** « 2 h 15 », « 3 j » : l'attente dite dans la relance. */
function mdDuree(int $secondes): string
{
    $m = max(0, intdiv($secondes, 60));
    if ($m < 60) { return $m . ' min'; }
    if ($m < 48 * 60) { return intdiv($m, 60) . ' h' . ($m % 60 >= 10 ? ' ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) : ''); }
    return intdiv($m, 1440) . ' j';
}

/** Le texte d'un SMS, variables remplies. */
function mdSmsTexte(string $modele, array $l, array $reg, int $maintenant): string
{
    $payload = json_decode((string) ($l['payload_json'] ?? ''), true);
    $pers = is_array($payload) ? ($payload['lead']['headcount'] ?? null) : null;
    $besoin = is_array($payload) ? (string) ($payload['lead']['need'] ?? '') : '';
    $vars = [
        '{entreprise}' => trim((string) ($l['entreprise'] ?? '')) ?: (trim((string) ($l['contact'] ?? '')) ?: 'un bureau'),
        '{personnes}'  => $pers !== null ? (string) $pers : '?',
        '{cp}'         => (string) ($l['cp'] ?? ''),
        '{contact}'    => (string) ($l['contact'] ?? ''),
        '{telephone}'  => mdTelLisible((string) ($l['telephone'] ?? '')),
        '{besoin}'     => MD_BESOINS[$besoin] ?? $besoin,
        '{magasin}'    => (string) ($l['magasin'] ?? ''),
        '{lien_erp}'   => str_replace('{erp_id}', rawurlencode((string) ($l['erp_id'] ?? '')), (string) $reg['erp']['lienLead']),
        '{attente}'    => mdDuree($maintenant - (int) strtotime((string) ($l['envoye_le'] ?? date('Y-m-d H:i:s', $maintenant)))),
    ];
    $t = strtr($modele, $vars);
    $t = (string) preg_replace('/ {2,}/', ' ', $t);
    return trim((string) preg_replace('/\s+([.,])/', '$1', $t));
}

/* --- les accès : ERP, SMS --------------------------------------------------------------- */

/**
 * L'API de l'ERP, par le compte du panel (PanelApi). Rend [code HTTP, corps].
 * 0 = injoignable ou compte non configuré.
 */
final class MdErp
{
    /** @var null|callable(string $methode, string $chemin, ?array $corps): array{0:int,1:mixed} */
    public static $transport = null;

    public static function configured(): bool
    {
        return self::$transport !== null || (class_exists('PanelApi') && PanelApi::configured());
    }

    public static function appel(string $methode, string $chemin, ?array $corps = null): array
    {
        if (self::$transport) { return (self::$transport)($methode, $chemin, $corps); }
        if (!class_exists('PanelApi') || !PanelApi::configured()) {
            return [0, ['error' => 'compte API du panel non configuré (Paramètres → compte consultant)']];
        }
        PanelApi::$lastError = null;
        if ($methode === 'GET') {
            $r = PanelApi::get($chemin);
            return $r === null ? [self::code(PanelApi::$lastError), ['error' => PanelApi::$lastError]] : [200, $r];
        }
        [$ok, $res] = PanelApi::envoi($methode, $chemin, $corps ?? []);
        if ($ok) { return [200, $res]; }
        // Le corps reste lisible (un 409 « déjà présent » porte l'identifiant du lead), l'erreur en plus.
        $msg = is_array($res) ? mdMessage($res) : '';
        return [self::code(PanelApi::$lastError), ['error' => $msg !== '' ? $msg : (PanelApi::$lastError ?? 'refusé')] + (is_array($res) ? $res : [])];
    }

    /** Le code HTTP lu dans le message d'erreur du client du panel (« → HTTP 422 : … »). */
    private static function code(?string $err): int
    {
        return $err !== null && preg_match('/HTTP (\d{3})/', $err, $m) ? (int) $m[1] : 0;
    }
}

/** Le message d'un corps d'erreur, sous les noms qu'une API lui donne. */
function mdMessage(mixed $res): string
{
    if (!is_array($res)) { return ''; }
    foreach (['error', 'message', 'description', 'detail'] as $k) {
        if (!empty($res[$k]) && is_string($res[$k])) { return mb_substr($res[$k], 0, 250); }
    }
    if (!empty($res['errors'])) { return mb_substr((string) json_encode($res['errors'], JSON_UNESCAPED_UNICODE), 0, 250); }
    return '';
}

/**
 * SMSAPI (comme le CRM) : un jeton OAuth avec la permission `sms`, sur la
 * plateforme .com (internationale) par défaut — un jeton de la plateforme .pl
 * y répond « Authorization failed (101) ». `encoding=utf-8` est obligatoire,
 * sans lui les accents ressortent illisibles.
 */
final class MdSms
{
    /** @var null|callable(string $url, ?array $champs, string $jeton): array{0:int,1:mixed} — champs null = GET */
    public static $transport = null;

    public static function config(): array
    {
        $cfg = [];
        try { $cfg = (array) ((Db::config()['smsapi'] ?? []) ?: []); } catch (Throwable $e) { $cfg = []; }
        $jeton = (string) setting('mdSmsJeton', '');
        if ($jeton === '') { $jeton = (string) ($cfg['token'] ?? ''); }
        if ($jeton === '') { $jeton = (string) (getenv('SMSAPI_TOKEN') ?: ''); }
        $base = trim((string) (mdReglages()['sms']['base'] ?? ''));
        if ($base === '') { $base = (string) ($cfg['base'] ?? ''); }
        if ($base === '') { $base = (string) (getenv('SMSAPI_BASE') ?: 'https://api.smsapi.com'); }
        return ['jeton' => trim($jeton), 'base' => rtrim(trim($base), '/')];
    }

    public static function configured(): bool
    {
        return self::config()['jeton'] !== '';
    }

    /** Un SMS. Rend [ok, identifiant SMSAPI ou message d'erreur]. */
    public static function envoyer(string $tel, string $texte, string $from = ''): array
    {
        $c = self::config();
        if ($c['jeton'] === '') { return [false, 'jeton SMSAPI absent']; }
        $to = ltrim((string) mdTelE164($tel), '+');
        if ($to === '') { return [false, 'numéro illisible : ' . $tel]; }
        $champs = ['to' => $to, 'message' => $texte, 'encoding' => 'utf-8', 'format' => 'json'];
        if (trim($from) !== '') { $champs['from'] = trim($from); }
        $url = $c['base'] . '/sms.do';
        [$code, $res] = self::$transport ? (self::$transport)($url, $champs, $c['jeton']) : self::curl($url, $champs, $c['jeton']);
        if ($code >= 200 && $code < 300 && is_array($res) && !isset($res['error'])) {
            return [true, (string) ($res['list'][0]['id'] ?? '')];
        }
        return [false, self::erreur($code, $res, $c['base'])];
    }

    /** Le compte SMSAPI (GET /profile) : le jeton est-il bon ? Sans envoyer de SMS. Rend [ok, message]. */
    public static function profil(): array
    {
        $c = self::config();
        if ($c['jeton'] === '') { return [false, 'jeton SMSAPI absent']; }
        $url = $c['base'] . '/profile';
        [$code, $res] = self::$transport ? (self::$transport)($url, null, $c['jeton']) : self::curl($url, null, $c['jeton']);
        if ($code >= 200 && $code < 300 && is_array($res) && !isset($res['error'])) {
            $pts = $res['points'] ?? null;
            return [true, trim((string) ($res['username'] ?? ($res['name'] ?? 'compte'))) . ($pts !== null ? ' · ' . $pts . ' points' : '')];
        }
        return [false, self::erreur($code, $res, $c['base'])];
    }

    /** Le message d'une erreur SMSAPI, avec le remède des codes connus. */
    private static function erreur(int $code, mixed $res, string $base): string
    {
        $num = is_array($res) && isset($res['error']) ? (int) $res['error'] : 0;
        $msg = is_array($res) ? (string) ($res['message'] ?? '') : '';
        $aide = [101 => ' — jeton refusé : jeton OAuth avec la permission « sms », sur la bonne plateforme (' . $base . ')',
            112 => ' — pays bloqué sur le compte SMSAPI : autoriser la Belgique (Limitation des pays d’envoi)',
            13 => ' — numéro refusé', 14 => ' — expéditeur refusé : nom non validé chez SMSAPI'][$num] ?? '';
        return ($num ? '(' . $num . ') ' : '') . ($msg !== '' ? $msg : ($code ? 'HTTP ' . $code : 'SMSAPI injoignable')) . $aide;
    }

    /** POST (un SMS) ou GET (`$champs` null : le profil). */
    private static function curl(string $url, ?array $champs, string $jeton): array
    {
        $ch = curl_init($url);
        if ($champs !== null) { curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($champs)]); }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $jeton], CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$raw === false ? 0 : $code, $raw === false ? null : json_decode((string) $raw, true)];
    }
}

/* --- les données du module -------------------------------------------------------------- */

/** Les magasins du cockpit (la même liste que /stores) : id → {id, nom, erpId}. */
function mdMagasins(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    try {
        foreach (ep_stores() as $s) {
            $id = (string) ($s['id'] ?? '');
            if ($id === '') { continue; }
            $cache[$id] = ['id' => $id, 'nom' => (string) ($s['nom'] ?? $id), 'erpId' => $s['pwaId'] ?? $id, 'statut' => (string) ($s['status'] ?? '')];
        }
    } catch (Throwable $e) { $cache = []; }
    return $cache;
}

/** Le magasin d'une page ou d'un formulaire, d'après son nom (« L'Atelier by Gosselies »). */
function mdSuggererMagasin(string $texte, array $magasins): ?string
{
    $t = ' ' . mdPlat($texte) . ' ';
    $trouve = null;
    foreach ($magasins as $m) {
        $nom = mdPlat((string) $m['nom']);
        // « Bruxelles — Châtelain » : le dernier morceau est le plus parlant.
        foreach (array_filter(array_map('trim', preg_split('/[—\-,]/u', $nom) ?: [])) as $mot) {
            if (mb_strlen($mot) >= 3 && preg_match('/[^a-z]' . preg_quote($mot, '/') . '[^a-z]/', $t)) {
                if ($trouve !== null && $trouve !== (string) $m['id']) { return null; }   // ambigu : rien
                $trouve = (string) $m['id'];
            }
        }
    }
    return $trouve;
}

/** Les réglages SMS des magasins : shop_id → ligne de ceo_md_shop. */
function mdShops(): array
{
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_md_shop') as $r) { $out[(string) $r['shop_id']] = $r; }
    return $out;
}

function mdForm(string $formId): ?array
{
    return Db::row('SELECT * FROM ceo_md_form WHERE form_id = ?', [$formId]);
}

function mdJson(mixed $v, array $defaut = []): array
{
    if (is_array($v)) { return $v; }
    $d = is_string($v) && $v !== '' ? json_decode($v, true) : null;
    return is_array($d) ? $d : $defaut;
}

/** Les questions du formulaire (consentement compris). */
function mdQuestionsDe(array $f): array
{
    return mdQuestions(mdJson($f['questions_json'] ?? null));
}

/** Le mapping qui sert : celui enregistré, sinon la suggestion (pour lire un lead, jamais pour l'envoyer). */
function mdMappingEffectif(array $f): array
{
    return !empty($f['mapping_le']) ? mdJson($f['mapping_json'] ?? null) : mdMappingSuggere(mdQuestionsDe($f));
}

/** Le lead est-il prêt à partir ? → [etat, motif, shopId, par]. */
function mdEvaluer(array $l, array $f, array $reg): array
{
    if (!(int) ($f['suivi'] ?? 0)) { return ['attente', 'formulaire non suivi', null, null]; }
    if (empty($f['mapping_le'])) { return ['attente', 'mapping à enregistrer (étape 2)', null, null]; }
    if (($l['shop_par'] ?? '') === 'main' && !empty($l['shop_id'])) { return ['a_envoyer', '', (string) $l['shop_id'], 'main']; }
    if (($f['affectation'] ?? 'magasin') === 'cp') {
        $cp = trim((string) ($l['cp'] ?? ''));
        if ($cp === '') { return ['a_attribuer', 'pas de code postal dans les réponses', null, 'cp']; }
        $s = mdMagasinParCp($cp, $reg['cp']);
        if ($s === null || $s === '') { return ['a_attribuer', 'aucun magasin pour le code postal ' . $cp, null, 'cp']; }
        return ['a_envoyer', '', $s, 'cp'];
    }
    if (empty($f['shop_id'])) { return ['a_attribuer', 'formulaire sans magasin', null, 'formulaire']; }
    return ['a_envoyer', '', (string) $f['shop_id'], 'formulaire'];
}

/** Les champs lus d'un lead pour la liste : entreprise, contact, téléphone, e-mail, code postal. */
function mdChampsRapides(array $reponses, array $f): array
{
    $t = mdTransformer($reponses, mdMappingEffectif($f), mdJson($f['valeurs_json'] ?? null));
    $p = $t['payload'];
    $contact = trim(($p['contact']['first_name'] ?? '') . ' ' . ($p['contact']['last_name'] ?? ''));
    return ['entreprise' => $p['company']['name'] ?? null, 'contact' => $contact !== '' ? $contact : null,
        'telephone' => $p['contact']['phone'] ?? null, 'email' => $p['contact']['email'] ?? null, 'cp' => $p['company']['zip'] ?? null];
}

/** Le corps envoyé à l'ERP. Les groupes vides partent en objets, pas en listes. */
function mdPayload(array $l, array $f, array $t, array $mag): array
{
    $o = static fn (array $a) => $a === [] ? new stdClass() : $a;
    return [
        'meta_lead_id' => (string) $l['meta_lead_id'],
        'shop_id' => $mag['erpId'] ?? $mag['id'],
        'source' => ['channel' => 'meta_leadgen', 'platform' => (string) ($l['plateforme'] ?? '') ?: null, 'form_id' => (string) $f['form_id'],
            'form' => (string) $f['nom'], 'page' => (string) ($f['page_nom'] ?? ''), 'campaign' => $l['campagne'] ?? null, 'ad' => $l['annonce'] ?? null,
            'received_at' => date('c', (int) strtotime((string) $l['recu_le']))],
        'company' => $o($t['company']), 'contact' => $o($t['contact']), 'lead' => $o($t['lead']),
    ];
}

/** L'identifiant et le statut rendus par l'ERP, où qu'il les range. */
function mdErpReponse(mixed $res): array
{
    $id = null; $statut = null;
    $cands = is_array($res) ? [$res, $res['data'] ?? null, $res['lead'] ?? null, $res['data']['lead'] ?? null] : [];
    foreach ($cands as $c) {
        if (!is_array($c)) { continue; }
        foreach (['id', 'lead_id', 'reference', 'uuid'] as $k) {
            if ($id === null && isset($c[$k]) && (is_string($c[$k]) || is_int($c[$k])) && (string) $c[$k] !== '') { $id = (string) $c[$k]; }
        }
        foreach (['status', 'statut', 'state'] as $k) {
            if ($statut === null && isset($c[$k]) && is_string($c[$k])) { $statut = $c[$k]; }
        }
    }
    return [$id, $statut];
}

/** Une liste rendue par une API, sous ses noms usuels. */
function mdListe(mixed $res): array
{
    if (!is_array($res)) { return []; }
    if (array_is_list($res)) { return $res; }
    foreach (['data', 'items', 'leads', 'list', 'results'] as $k) {
        if (isset($res[$k]) && is_array($res[$k])) { return array_is_list($res[$k]) ? $res[$k] : mdListe($res[$k]); }
    }
    return [];
}

/* --- Meta : formulaires, leads, webhook ------------------------------------------------- */

/** Les pages que le System User voit : page_id → {token, nom, shopId suggéré}. */
function mdPagesMeta(): array
{
    $mags = mdMagasins();
    $bg = [];
    try {
        foreach (Db::rows('SELECT page_id, shop_id, boutique FROM brand_guard_pages WHERE page_id IS NOT NULL') as $r) { $bg[(string) $r['page_id']] = $r; }
    } catch (Throwable $e) { $bg = []; }
    $out = [];
    foreach (MetaGraph::tokensPages() as $id => $p) {
        $shop = null;
        if (isset($bg[$id])) { $shop = isset($mags[(string) $bg[$id]['shop_id']]) ? (string) $bg[$id]['shop_id'] : mdSuggererMagasin((string) $bg[$id]['boutique'], $mags); }
        $sid = $shop ?? mdSuggererMagasin($p['nom'], $mags);
        $out[(string) $id] = ['token' => $p['token'], 'nom' => $p['nom'], 'shopId' => $sid !== null ? (string) $sid : null];
    }
    return $out;
}

/**
 * Relit chez Meta les formulaires de toutes les pages, et l'abonnement de
 * chaque page au champ `leadgen`. Un formulaire déjà connu garde son suivi, son
 * magasin et son mapping ; un nouveau arrive NON suivi, magasin suggéré.
 */
function mdRelireFormulaires(): array
{
    mdTables();
    if (!MetaGraph::configured()) { return ['error' => 'jeton Meta absent (META_SYSTEM_TOKEN, voir docs/brand-guard.md)']; }
    $pages = mdPagesMeta();
    $n = 0; $neufs = 0; $infoPages = [];
    $maintenant = date('Y-m-d H:i:s');
    foreach ($pages as $pageId => $pg) {
        $abo = ['abonne' => null, 'champs' => []];
        try {
            $r = MetaGraph::http('GET', $pageId . '/subscribed_apps', ['access_token' => $pg['token']]);
            foreach ((array) ($r['data'] ?? []) as $app) { $abo['champs'] = array_values(array_unique(array_merge($abo['champs'], (array) ($app['subscribed_fields'] ?? [])))); }
            $abo['abonne'] = in_array('leadgen', $abo['champs'], true);
        } catch (Throwable $e) { $abo['erreur'] = $e->getMessage(); }
        $infoPages[] = ['id' => (string) $pageId, 'nom' => $pg['nom'], 'shopId' => $pg['shopId']] + $abo;
        $params = ['access_token' => $pg['token'], 'limit' => 100,
            'fields' => 'id,name,status,locale,leads_count,created_time,questions{key,label,type,options}'];
        $chemin = $pageId . '/leadgen_forms';
        for ($p = 0; $p < 10; $p++) {
            try { $r = MetaGraph::http('GET', $chemin, $params); }
            catch (Throwable $e) { mdJournal('erreur', 'Formulaires de « ' . $pg['nom'] . ' » illisibles : ' . $e->getMessage()); break; }
            foreach ((array) ($r['data'] ?? []) as $fm) {
                $fid = (string) ($fm['id'] ?? '');
                if ($fid === '') { continue; }
                $qs = mdQuestions((array) ($fm['questions'] ?? []));
                $existe = mdForm($fid);
                if ($existe === null) {
                    $neufs++;
                    $shop = mdSuggererMagasin((string) ($fm['name'] ?? ''), mdMagasins()) ?? $pg['shopId'];
                    Db::exec('INSERT INTO ceo_md_form (form_id, page_id, page_nom, nom, statut_meta, locale, questions_json, leads_meta, shop_id, relu_le) VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [$fid, $pageId, $pg['nom'], (string) ($fm['name'] ?? ''), (string) ($fm['status'] ?? ''), (string) ($fm['locale'] ?? ''),
                            json_encode($qs, JSON_UNESCAPED_UNICODE), isset($fm['leads_count']) ? (int) $fm['leads_count'] : null, $shop, $maintenant]);
                } else {
                    Db::exec('UPDATE ceo_md_form SET page_id = ?, page_nom = ?, nom = ?, statut_meta = ?, locale = ?, questions_json = ?, leads_meta = ?, relu_le = ? WHERE form_id = ?',
                        [$pageId, $pg['nom'], (string) ($fm['name'] ?? ''), (string) ($fm['status'] ?? ''), (string) ($fm['locale'] ?? ''),
                            json_encode($qs, JSON_UNESCAPED_UNICODE), isset($fm['leads_count']) ? (int) $fm['leads_count'] : null, $maintenant, $fid]);
                }
                $n++;
            }
            $next = $r['paging']['next'] ?? null;
            if (!is_string($next) || $next === '') { break; }
            $chemin = $next; $params = [];
        }
    }
    mdSettingSet('mdPages', $infoPages);
    mdJournal('meta', count($pages) . ' page(s), ' . $n . ' formulaire(s) relus chez Meta' . ($neufs ? ' dont ' . $neufs . ' nouveau(x), non suivi(s)' : ''));
    return ['ok' => true, 'pages' => count($pages), 'formulaires' => $n, 'nouveaux' => $neufs];
}

/** Un lead lu chez Meta. Sans `ads_management`, on le relit sans la campagne plutôt que de le perdre. */
function mdLireLead(string $leadId, string $token): array
{
    $champs = 'id,created_time,field_data,platform,form_id,custom_disclaimer_responses';
    try {
        return MetaGraph::http('GET', $leadId, ['access_token' => $token, 'fields' => $champs . ',campaign_name,ad_name']);
    } catch (MetaIndisponible $e) {
        return MetaGraph::http('GET', $leadId, ['access_token' => $token, 'fields' => $champs]);
    }
}

/** Les leads d'un formulaire depuis une date (pagination suivie, 2 000 au plus). */
function mdLeadsFormulaire(string $formId, string $token, int $depuis): array
{
    $champs = 'id,created_time,field_data,platform,form_id,custom_disclaimer_responses';
    $params = ['access_token' => $token, 'limit' => 100, 'fields' => $champs . ',campaign_name,ad_name',
        'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $depuis]])];
    $chemin = $formId . '/leads';
    $out = [];
    for ($p = 0; $p < 20; $p++) {
        try { $r = MetaGraph::http('GET', $chemin, $params); }
        catch (MetaIndisponible $e) {
            if ($p === 0 && str_contains((string) ($params['fields'] ?? ''), 'campaign_name')) { $params['fields'] = $champs; $p--; continue; }
            throw $e;
        }
        foreach ((array) ($r['data'] ?? []) as $l) { $out[] = $l; }
        $next = $r['paging']['next'] ?? null;
        if (!is_string($next) || $next === '' || ($r['data'] ?? []) === []) { break; }
        $chemin = $next; $params = [];
    }
    return $out;
}

/**
 * Range un lead lu chez Meta. Un numéro déjà reçu ne crée rien (webhook et
 * rattrapage se recouvrent exprès). Rend ['id'] et 'deja' s'il était connu.
 */
function mdEnregistrerLead(array $raw, array $f, string $via): array
{
    $mid = (string) ($raw['id'] ?? '');
    if ($mid === '') { return ['error' => 'lead sans identifiant']; }
    $deja = Db::row('SELECT id FROM ceo_md_lead WHERE meta_lead_id = ?', [$mid]);
    if ($deja !== null) { return ['id' => (int) $deja['id'], 'deja' => true]; }
    $reps = mdReponses($raw, mdQuestionsDe($f));
    $vite = mdChampsRapides($reps, $f);
    $ts = isset($raw['created_time']) ? strtotime((string) $raw['created_time']) : false;
    $recu = date('Y-m-d H:i:s', $ts !== false ? $ts : time());
    $pf = strtolower((string) ($raw['platform'] ?? ''));
    $pf = in_array($pf, ['fb', 'ig'], true) ? $pf : (str_starts_with($pf, 'insta') ? 'ig' : (str_starts_with($pf, 'face') ? 'fb' : ''));
    $ligne = ['cp' => $vite['cp'], 'shop_par' => null, 'shop_id' => null];
    [$etat, $motif, $shop, $par] = mdEvaluer($ligne, $f, mdReglages());
    Db::exec('INSERT IGNORE INTO ceo_md_lead (meta_lead_id, form_id, page_id, plateforme, campagne, annonce, recu_le, lu_le, via, reponses_json,'
        . ' entreprise, contact, telephone, email, cp, shop_id, shop_par, etat, motif) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$mid, (string) $f['form_id'], (string) ($f['page_id'] ?? ''), $pf, isset($raw['campaign_name']) ? mb_substr((string) $raw['campaign_name'], 0, 200) : null,
            isset($raw['ad_name']) ? mb_substr((string) $raw['ad_name'], 0, 200) : null, $recu, date('Y-m-d H:i:s'), $via,
            json_encode($reps, JSON_UNESCAPED_UNICODE), $vite['entreprise'], $vite['contact'], $vite['telephone'], $vite['email'], $vite['cp'],
            $shop, $par, $etat, $motif !== '' ? $motif : null]);
    $id = (int) (Db::row('SELECT id FROM ceo_md_lead WHERE meta_lead_id = ?', [$mid])['id'] ?? 0);
    Db::exec('UPDATE ceo_md_form SET dernier_lead = GREATEST(COALESCE(dernier_lead, ?), ?) WHERE form_id = ?', [$recu, $recu, (string) $f['form_id']]);
    mdJournal('lead', 'Lead reçu — ' . ($vite['entreprise'] ?: ($vite['contact'] ?: 'sans nom')) . ' (' . ($pf === 'ig' ? 'Instagram' : ($pf === 'fb' ? 'Facebook' : 'Meta'))
        . ', « ' . $f['nom'] . ' »)' . ($etat === 'attente' || $etat === 'a_attribuer' ? ' · ' . $motif : ''), $id);
    return ['id' => $id, 'etat' => $etat];
}

/**
 * Le rattrapage : les leads des N derniers jours de chaque formulaire SUIVI.
 * Un formulaire tout juste suivi récupère ainsi son historique (Meta n'en
 * garde que 90 jours).
 */
function mdRattrapage(int $jours, ?string $formId = null): array
{
    mdTables();
    if (!MetaGraph::configured()) { return ['error' => 'jeton Meta absent']; }
    $jours = max(1, min(90, $jours));
    $forms = $formId !== null ? array_filter([mdForm($formId)]) : Db::rows('SELECT * FROM ceo_md_form WHERE suivi = 1');
    if (!$forms) { return ['ok' => true, 'formulaires' => 0, 'nouveaux' => 0]; }
    $tokens = MetaGraph::tokensPages();
    $neufs = 0; $vus = 0; $lus = 0;
    foreach ($forms as $f) {
        $tk = $tokens[(string) $f['page_id']]['token'] ?? null;
        if ($tk === null) { mdJournal('erreur', 'Formulaire « ' . $f['nom'] . ' » : sa page n’est plus rattachée au System User'); continue; }
        try { $leads = mdLeadsFormulaire((string) $f['form_id'], $tk, time() - $jours * 86400); }
        catch (Throwable $e) { mdJournal('erreur', 'Leads de « ' . $f['nom'] . ' » illisibles : ' . $e->getMessage()); continue; }
        $lus++;
        foreach ($leads as $raw) {
            $vus++;
            $r = mdEnregistrerLead($raw, $f, 'rattrapage');
            if (!empty($r['id']) && empty($r['deja'])) { $neufs++; }
        }
    }
    mdJournal('rattrapage', 'Meta, ' . $jours . ' j, ' . $lus . ' formulaire(s) · ' . ($neufs ? $neufs . ' lead(s) manqué(s) récupéré(s)' : 'aucun lead manqué'));
    return ['ok' => true, 'formulaires' => $lus, 'vus' => $vus, 'nouveaux' => $neufs];
}

/**
 * Le webhook `leadgen` (appelé par wr_bg_webhook, signature déjà vérifiée).
 * Le lead d'un formulaire non suivi est seulement compté ; celui d'un formulaire
 * suivi est lu, rangé, envoyé et signalé tout de suite.
 */
function mdWebhook(array $payload): array
{
    $evs = mdLeadsDuWebhook($payload);
    if (!$evs) { return []; }
    mdTables();
    $faits = [];
    $tokens = null;
    foreach ($evs as $ev) {
        $f = $ev['form'] !== '' ? mdForm($ev['form']) : null;
        if ($f === null && $ev['form'] !== '') {
            Db::exec('INSERT IGNORE INTO ceo_md_form (form_id, page_id) VALUES (?, ?)', [$ev['form'], $ev['page']]);
            $f = mdForm($ev['form']);
        }
        if ($f === null || !(int) $f['suivi']) {
            if ($f !== null) { Db::exec('UPDATE ceo_md_form SET ignores = ignores + 1, dernier_lead = ? WHERE form_id = ?', [date('Y-m-d H:i:s'), $ev['form']]); }
            mdJournal('webhook', 'Lead non repris : formulaire ' . ($f !== null && $f['nom'] !== '' ? '« ' . $f['nom'] . ' »' : $ev['form']) . ' non suivi');
            $faits[] = ['lead' => $ev['lead'], 'ignore' => 'formulaire non suivi'];
            continue;
        }
        try {
            $tokens = $tokens ?? MetaGraph::tokensPages();
            $tk = $tokens[$ev['page']]['token'] ?? null;
            if ($tk === null) { throw new MetaIndisponible('page ' . $ev['page'] . ' non rattachée au System User'); }
            $r = mdEnregistrerLead(mdLireLead($ev['lead'], $tk), $f, 'webhook');
            if (!empty($r['id']) && empty($r['deja'])) { $r['suite'] = mdTraiterNouveau((int) $r['id']); }
            $faits[] = ['lead' => $ev['lead']] + $r;
        } catch (Throwable $e) {
            mdJournal('erreur', 'Lead ' . $ev['lead'] . ' non lu : ' . $e->getMessage() . ' — le rattrapage horaire le reprendra');
            $faits[] = ['lead' => $ev['lead'], 'erreur' => $e->getMessage()];
        }
    }
    return $faits;
}

/** Abonne une page au champ `leadgen` sans retirer ce qu'elle reçoit déjà (le `feed` du Brand Guard). */
function mdAbonnerPage(string $pageId): array
{
    mdTables();
    $pages = MetaGraph::tokensPages();
    $tk = $pages[$pageId]['token'] ?? null;
    if ($tk === null) { http_response_code(404); return ['error' => 'page non rattachée au System User']; }
    $champs = ['leadgen'];
    try {
        $r = MetaGraph::http('GET', $pageId . '/subscribed_apps', ['access_token' => $tk]);
        foreach ((array) ($r['data'] ?? []) as $app) { $champs = array_merge($champs, (array) ($app['subscribed_fields'] ?? [])); }
    } catch (Throwable $e) { /* pas lisible : on abonne quand même au leadgen */ }
    $champs = array_values(array_unique($champs));
    try {
        MetaGraph::http('POST', $pageId . '/subscribed_apps', ['access_token' => $tk, 'subscribed_fields' => implode(',', $champs)]);
    } catch (Throwable $e) { http_response_code(502); return ['error' => $e->getMessage()]; }
    $info = setting('mdPages', []);
    foreach (is_array($info) ? $info : [] as $i => $p) {
        if ((string) ($p['id'] ?? '') === $pageId) { $info[$i]['abonne'] = true; $info[$i]['champs'] = $champs; }
    }
    mdSettingSet('mdPages', is_array($info) ? $info : []);
    mdJournal('meta', 'Page « ' . $pages[$pageId]['nom'] . ' » abonnée aux leads (' . implode(', ', $champs) . ')');
    return ['ok' => true, 'champs' => $champs];
}

/* --- le traitement : ERP, relecture, SMS ------------------------------------------------- */

/**
 * Envoie un lead à l'ERP. `$main` : geste du consultant (« Renvoyer ») — il
 * passe même si l'envoi automatique est éteint.
 */
function mdEnvoyer(int $id, bool $main = false): array
{
    $l = Db::row('SELECT * FROM ceo_md_lead WHERE id = ?', [$id]);
    if ($l === null) { return ['error' => 'lead introuvable']; }
    $f = mdForm((string) $l['form_id']);
    if ($f === null) { return ['error' => 'formulaire introuvable']; }
    $reg = mdReglages();
    if (!$main && empty($reg['erp']['envoiActif'])) { return ['ok' => false, 'motif' => 'envoi automatique éteint']; }
    $maintenant = date('Y-m-d H:i:s');
    [$etat, $motif, $shop, $par] = mdEvaluer($l, $f, $reg);
    if ($etat !== 'a_envoyer') {
        Db::exec('UPDATE ceo_md_lead SET etat = ?, motif = ? WHERE id = ?', [$etat, $motif, $id]);
        return ['ok' => false, 'etat' => $etat, 'motif' => $motif];
    }
    $mags = mdMagasins();
    $mag = $mags[(string) $shop] ?? ['id' => $shop, 'erpId' => $shop, 'nom' => (string) $shop];
    $t = mdTransformer(mdJson($l['reponses_json']), mdJson($f['mapping_json']), mdJson($f['valeurs_json']), (int) strtotime((string) $l['recu_le']));
    $rejet = null;
    if ($t['consentement'] === false) { $rejet = 'consentement non donné : rien ne part'; }
    elseif ($t['manques']) { $rejet = 'champ requis manquant : ' . implode(', ', array_map(static fn ($c) => MD_CHAMPS[$c][0] ?? $c, $t['manques'])); }
    $payload = mdPayload($l, $f, $t['payload'], $mag);
    if ($rejet !== null) {
        Db::exec('UPDATE ceo_md_lead SET etat = ?, motif = ?, shop_id = ?, shop_par = ?, payload_json = ? WHERE id = ?',
            ['rejete', $rejet, $shop, $par, json_encode($payload, JSON_UNESCAPED_UNICODE), $id]);
        mdJournal('envoi', 'Lead ' . ($l['entreprise'] ?: '#' . $id) . ' non envoyé — ' . $rejet, $id);
        return ['ok' => false, 'etat' => 'rejete', 'motif' => $rejet];
    }
    [$code, $res] = MdErp::appel('POST', (string) $reg['erp']['routeEnvoi'], $payload);
    $essais = (int) $l['essais'] + 1;
    if (($code >= 200 && $code < 300) || $code === 409) {
        [$erpId, $statut] = mdErpReponse($res);
        $s = $statut !== null ? (mdStatutErp($statut) ?? 'nouveau') : 'nouveau';
        Db::exec('UPDATE ceo_md_lead SET etat = ?, motif = NULL, shop_id = ?, shop_par = ?, erp_id = COALESCE(?, erp_id), envoye_le = COALESCE(envoye_le, ?),'
            . ' payload_json = ?, statut_erp = COALESCE(statut_erp, ?), statut_brut = COALESCE(statut_brut, ?), statut_le = COALESCE(statut_le, ?), essais = ?, essai_le = ? WHERE id = ?',
            ['envoye', $shop, $par, $erpId, $maintenant, json_encode($payload, JSON_UNESCAPED_UNICODE), $s, $statut, $maintenant, $essais, $maintenant, $id]);
        mdJournal('envoi', 'Dans l’ERP de ' . $mag['nom'] . ' — ' . ($l['entreprise'] ?: '#' . $id) . ($erpId ? ' · ' . $erpId : '') . ($code === 409 ? ' (déjà présent)' : ''), $id);
        return ['ok' => true, 'etat' => 'envoye', 'erpId' => $erpId];
    }
    $msg = mdMessage($res) ?: ($code ? 'HTTP ' . $code : 'ERP injoignable');
    // Un refus sur le CONTENU (400, 422) se corrige ; le reste (réseau, 5xx, route absente) se rejoue.
    $definitif = in_array($code, [400, 422], true);
    $etatErr = $definitif ? 'rejete' : 'erreur';
    $motifErr = ($definitif ? 'refusé par l’ERP' : 'ERP') . ' (' . ($code ?: '—') . ') : ' . $msg . (in_array($code, [404, 405], true) ? ' — route ' . $reg['erp']['routeEnvoi'] . ' à créer côté panel ?' : '');
    Db::exec('UPDATE ceo_md_lead SET etat = ?, motif = ?, shop_id = ?, shop_par = ?, payload_json = ?, essais = ?, essai_le = ? WHERE id = ?',
        [$etatErr, mb_substr($motifErr, 0, 300), $shop, $par, json_encode($payload, JSON_UNESCAPED_UNICODE), min(255, $essais), $maintenant, $id]);
    mdJournal('erreur', 'Lead ' . ($l['entreprise'] ?: '#' . $id) . ' : ' . $motifErr, $id);
    return ['ok' => false, 'etat' => $etatErr, 'motif' => $motifErr];
}

/** Un lead tout juste rangé : envoi (si allumé) puis SMS (si dû). */
function mdTraiterNouveau(int $id): array
{
    $out = [];
    $reg = mdReglages();
    if (!empty($reg['erp']['envoiActif'])) { $out['envoi'] = mdEnvoyer($id); }
    $l = Db::row('SELECT * FROM ceo_md_lead WHERE id = ?', [$id]);
    if ($l !== null && $l['etat'] === 'envoye') { $out['sms'] = mdSmsLead($l, $reg); }
    return $out;
}

/** Les leads qui attendent un formulaire suivi, un mapping ou un magasin : réévalués (après un réglage). */
function mdReevaluer(?string $formId = null): array
{
    $reg = mdReglages();
    $sql = "SELECT * FROM ceo_md_lead WHERE etat IN ('attente','a_attribuer')" . ($formId !== null ? ' AND form_id = ?' : '') . ' ORDER BY recu_le LIMIT 500';
    $n = 0;
    $forms = [];
    foreach (Db::rows($sql, $formId !== null ? [$formId] : []) as $l) {
        $f = $forms[$l['form_id']] ??= mdForm((string) $l['form_id']);
        if ($f === null) { continue; }
        // Les champs rapides suivent le mapping enregistré (le code postal décide du magasin).
        $vite = mdChampsRapides(mdJson($l['reponses_json']), $f);
        $l['cp'] = $vite['cp'] ?? $l['cp'];
        [$etat, $motif, $shop, $par] = mdEvaluer($l, $f, $reg);
        if ($etat !== $l['etat'] || $motif !== (string) $l['motif'] || $shop !== $l['shop_id']) {
            Db::exec('UPDATE ceo_md_lead SET etat = ?, motif = ?, shop_id = ?, shop_par = ?, entreprise = ?, contact = ?, telephone = ?, email = ?, cp = ? WHERE id = ?',
                [$etat, $motif !== '' ? $motif : null, $shop, $par, $vite['entreprise'], $vite['contact'], $vite['telephone'], $vite['email'], $vite['cp'], (int) $l['id']]);
            $n++;
        }
    }
    return ['changes' => $n];
}

/** Les leads prêts (et les erreurs à rejouer, de plus en plus espacées) partent dans l'ERP. */
function mdEnvoyerEnAttente(int $max = 40): array
{
    $t = time();
    $ok = 0; $ko = 0;
    $rows = Db::rows("SELECT id, etat, essais, essai_le FROM ceo_md_lead WHERE etat IN ('a_envoyer','erreur') ORDER BY recu_le LIMIT " . (int) ($max * 3));
    foreach ($rows as $r) {
        if ($ok + $ko >= $max) { break; }
        if ($r['etat'] === 'erreur') {
            $n = (int) $r['essais'];
            if ($n >= 10) { continue; }   // dix essais : on attend un geste (« Renvoyer »)
            $delai = min(6 * 3600, 300 * (2 ** max(0, $n - 1)));
            if ($r['essai_le'] !== null && $t - (int) strtotime((string) $r['essai_le']) < $delai) { continue; }
        }
        $x = mdEnvoyer((int) $r['id']);
        if (!empty($x['ok'])) { $ok++; } else { $ko++; }
    }
    return ['envoyes' => $ok, 'echecs' => $ko];
}

/** Applique à un lead le statut relu dans l'ERP. Rend le nouveau statut s'il a changé. */
function mdAppliquerStatut(array $l, array $x, string $maintenant): ?string
{
    $brut = '';
    foreach (['status', 'statut', 'state'] as $k) { if (isset($x[$k]) && is_string($x[$k])) { $brut = $x[$k]; break; } }
    $s = mdStatutErp($brut) ?? ($l['statut_erp'] ?: 'nouveau');
    $date = static function (array $x, array $cles): ?string {
        foreach ($cles as $k) {
            if (!empty($x[$k]) && ($ts = strtotime((string) $x[$k])) !== false) { return date('Y-m-d H:i:s', $ts); }
        }
        return null;
    };
    $rang = mdRang($s);
    $vu = $l['vu_le'] ?: (($rang >= 1) ? ($date($x, ['seen_at', 'viewed_at', 'opened_at', 'read_at']) ?? $maintenant) : null);
    // Un lead perdu n'a pas forcément été rappelé : sa date de contact ne vient que de l'ERP.
    $contact = $l['contacte_le'] ?: ($s === 'perdu' ? $date($x, ['contacted_at', 'called_at'])
        : ($rang >= 2 ? ($date($x, ['contacted_at', 'called_at']) ?? $maintenant) : null));
    $connecte = $l['connecte_le'] ?: ($s === 'bureau_connecte' ? ($date($x, ['connected_at', 'office_connected_at', 'won_at']) ?? $maintenant) : null);
    $change = $s !== (string) $l['statut_erp'];
    Db::exec('UPDATE ceo_md_lead SET statut_erp = ?, statut_brut = ?, statut_le = ?, vu_le = ?, contacte_le = ?, connecte_le = ?, relu_le = ? WHERE id = ?',
        [$s, $brut !== '' ? mb_substr($brut, 0, 60) : $l['statut_brut'], $change ? $maintenant : $l['statut_le'], $vu, $contact, $connecte, $maintenant, (int) $l['id']]);
    return $change ? $s : null;
}

/** La relecture des statuts dans l'ERP : les leads envoyés depuis moins de 30 jours, pas encore clos. */
function mdRelire(array $reg, int $max = 400): array
{
    $depuis = date('Y-m-d H:i:s', time() - (int) $reg['erp']['relectureJours'] * 86400);
    $rows = Db::rows("SELECT * FROM ceo_md_lead WHERE etat = 'envoye' AND erp_id IS NOT NULL AND envoye_le >= ?"
        . " AND (statut_erp IS NULL OR statut_erp NOT IN ('bureau_connecte','perdu')) ORDER BY envoye_le DESC LIMIT " . (int) $max, [$depuis]);
    if (!$rows) { return ['relus' => 0, 'changes' => 0]; }
    $route = (string) $reg['erp']['routeStatuts'];
    $maintenant = date('Y-m-d H:i:s');
    $relus = 0; $changes = []; $erreur = null;
    foreach (array_chunk($rows, 50) as $lot) {
        $ids = implode(',', array_map(static fn ($r) => (string) $r['erp_id'], $lot));
        [$code, $res] = MdErp::appel('GET', $route . (str_contains($route, '?') ? '&' : '?') . 'ids=' . rawurlencode($ids));
        if ($code < 200 || $code >= 300) { $erreur = '(' . ($code ?: '—') . ') ' . (mdMessage($res) ?: 'ERP injoignable'); break; }
        $parId = []; $parMeta = [];
        foreach (mdListe($res) as $x) {
            if (!is_array($x)) { continue; }
            [$xid] = mdErpReponse($x);
            if ($xid !== null) { $parId[$xid] = $x; }
            if (!empty($x['meta_lead_id'])) { $parMeta[(string) $x['meta_lead_id']] = $x; }
        }
        foreach ($lot as $l) {
            $x = $parId[(string) $l['erp_id']] ?? $parMeta[(string) $l['meta_lead_id']] ?? null;
            if ($x === null) { continue; }
            $relus++;
            $s = mdAppliquerStatut($l, $x, $maintenant);
            if ($s !== null) { $changes[] = ($l['entreprise'] ?: '#' . $l['id']) . ' ' . (MD_STATUTS[$s] ?? $s); }
        }
    }
    if ($erreur !== null) { mdJournal('erreur', 'Relecture ERP interrompue : ' . $erreur); }
    mdJournal('relecture', count($rows) . ' lead(s) ouvert(s), ' . $relus . ' relu(s) · '
        . ($changes ? count($changes) . ' changé(s) : ' . implode(', ', array_slice($changes, 0, 4)) : 'rien de changé'));
    return ['ouverts' => count($rows), 'relus' => $relus, 'changes' => count($changes), 'erreur' => $erreur];
}

/** Le SMS dû pour un lead, s'il y en a un — premier ou relance. */
function mdSmsLead(array $l, array $reg, bool $main = false): array
{
    $mag = Db::row('SELECT * FROM ceo_md_shop WHERE shop_id = ?', [(string) $l['shop_id']]) ?? [];
    $t = time();
    $heure = (int) (new DateTime('now', new DateTimeZone('Europe/Brussels')))->format('G');
    $plan = $main ? (empty($l['sms_le']) ? 'premier' : 'relance') : mdSmsPlan($l, $mag, $reg, $t, $heure);
    if ($plan === null) { return ['envoye' => false]; }
    if (trim((string) ($mag['sms_tel'] ?? '')) === '') { return ['envoye' => false, 'motif' => 'magasin sans numéro SMS']; }
    $mags = mdMagasins();
    $l['magasin'] = $mags[(string) $l['shop_id']]['nom'] ?? '';
    $texte = mdSmsTexte($plan === 'premier' ? (string) $reg['sms']['modele'] : (string) $reg['sms']['modeleRelance'], $l, $reg, $t);
    [$ok, $info] = MdSms::envoyer((string) $mag['sms_tel'], $texte, (string) $reg['sms']['from']);
    $maintenant = date('Y-m-d H:i:s', $t);
    if ($ok) {
        if ($plan === 'premier') { Db::exec('UPDATE ceo_md_lead SET sms_le = ?, sms_derniere = ?, sms_erreur = NULL WHERE id = ?', [$maintenant, $maintenant, (int) $l['id']]); }
        else { Db::exec('UPDATE ceo_md_lead SET sms_derniere = ?, sms_relances = sms_relances + 1, sms_erreur = NULL WHERE id = ?', [$maintenant, (int) $l['id']]); }
        mdJournal($plan === 'premier' ? 'sms' : 'relance', 'SMS ' . ($plan === 'premier' ? '' : '(relance) ') . '→ ' . ($l['magasin'] ?: 'magasin') . ' ('
            . ($mag['sms_nom'] ?: mdTelLisible(mdTelE164((string) $mag['sms_tel']))) . ') · ' . ($l['entreprise'] ?: '#' . $l['id']), (int) $l['id']);
        return ['envoye' => true, 'plan' => $plan];
    }
    // Un échec n'est pas rejoué en boucle : la date est posée, l'erreur dite, le geste « Relancer » reste.
    if ($plan === 'premier') { Db::exec('UPDATE ceo_md_lead SET sms_le = ?, sms_derniere = ?, sms_erreur = ? WHERE id = ?', [$maintenant, $maintenant, mb_substr((string) $info, 0, 200), (int) $l['id']]); }
    else { Db::exec('UPDATE ceo_md_lead SET sms_derniere = ?, sms_relances = sms_relances + 1, sms_erreur = ? WHERE id = ?', [$maintenant, mb_substr((string) $info, 0, 200), (int) $l['id']]); }
    mdJournal('erreur', 'SMS non parti (' . ($l['entreprise'] ?: '#' . $l['id']) . ') : ' . $info, (int) $l['id']);
    return ['envoye' => false, 'motif' => $info];
}

/** Les SMS dus et les e-mails d'escalade du passage. */
function mdSmsPasser(array $reg): array
{
    $t = time();
    $depuis = date('Y-m-d H:i:s', $t - 7 * 86400);
    $rows = Db::rows("SELECT * FROM ceo_md_lead WHERE etat = 'envoye' AND envoye_le >= ? AND (statut_erp IS NULL OR statut_erp IN ('nouveau','vu')) ORDER BY envoye_le LIMIT 200", [$depuis]);
    $shops = mdShops();
    $sms = 0; $esc = 0;
    foreach ($rows as $l) {
        $mag = $shops[(string) $l['shop_id']] ?? [];
        if ($sms < 40 && !empty($reg['sms']['actif'])) {
            $r = mdSmsLead($l, $reg);
            if (!empty($r['envoye'])) { $sms++; }
        }
        if ($esc < 10 && mdEscaladePlan($l, $mag, $reg, $t)) { mdEscalader($l, $mag, $reg); $esc++; }
    }
    return ['sms' => $sms, 'escalades' => $esc];
}

/** L'e-mail au consultant pour un lead pas rappelé à temps. Posé une fois, même si le SMTP manque (c'est dit). */
function mdEscalader(array $l, array $mag, array $reg): void
{
    $mags = mdMagasins();
    $nomMag = $mags[(string) $l['shop_id']]['nom'] ?? (string) $l['shop_id'];
    $att = mdDuree(time() - (int) strtotime((string) $l['envoye_le']));
    $sujet = '[MétaDonnée] Lead pas rappelé à ' . $nomMag . ' — ' . ($l['entreprise'] ?: 'bureau');
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $html = '<p>Le lead <b>' . $e($l['entreprise'] ?: '—') . '</b> (' . $e($l['contact']) . ', ' . $e(mdTelLisible((string) $l['telephone'])) . ', ' . $e($l['cp']) . ')'
        . ' attend depuis <b>' . $e($att) . '</b> dans l’ERP de ' . $e($nomMag) . ' : ' . $e(MD_STATUTS[$l['statut_erp'] ?: 'nouveau'] ?? $l['statut_erp']) . '.</p>'
        . '<p>Lien ERP : ' . $e(str_replace('{erp_id}', (string) $l['erp_id'], (string) $reg['erp']['lienLead'])) . '</p>';
    $ok = class_exists('Smtp') && Smtp::configured() && Smtp::envoyer((string) $mag['escalade_email'], $sujet, $html);
    Db::exec('UPDATE ceo_md_lead SET escalade_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $l['id']]);
    mdJournal('escalade', ($ok ? 'E-mail à ' : '⚠ E-mail NON parti (' . (class_exists('Smtp') && Smtp::configured() ? (Smtp::$lastError ?? 'échec') : 'SMTP non configuré') . ') pour ')
        . $mag['escalade_email'] . ' — ' . ($l['entreprise'] ?: '#' . $l['id']) . ' pas rappelé depuis ' . $att, (int) $l['id']);
}

/**
 * Le cycle (horloge toutes les 5 minutes, ou « Lancer maintenant ») :
 * rattrapage Meta (au plus une fois par heure), leads en attente réévalués,
 * envoi à l'ERP, relecture des statuts (toutes les 10 min), SMS et escalades.
 */
function mdCycle(bool $force = false): array
{
    mdTables();
    $reg = mdReglages();
    $etat = setting('mdCycle', []);
    if (!is_array($etat)) { $etat = []; }
    $t = time();
    $bilan = [];
    if (MetaGraph::configured() && ($force || $t - (int) ($etat['rattrapage'] ?? 0) >= 60 * max(15, (int) $reg['meta']['rattrapageMin']))) {
        try { $bilan['meta'] = mdRattrapage((int) $reg['meta']['rattrapageJours']); }
        catch (Throwable $e) { $bilan['meta'] = ['error' => $e->getMessage()]; mdJournal('erreur', 'Rattrapage Meta : ' . $e->getMessage()); }
        $etat['rattrapage'] = $t;
    }
    $bilan['reevaluation'] = mdReevaluer();
    if (!empty($reg['erp']['envoiActif'])) { $bilan['envoi'] = mdEnvoyerEnAttente(40); }
    if ($force || $t - (int) ($etat['relecture'] ?? 0) >= 60 * max(5, (int) $reg['erp']['relectureMin'])) {
        $bilan['relecture'] = MdErp::configured() ? mdRelire($reg) : ['erreur' => 'compte API du panel non configuré'];
        $etat['relecture'] = $t;
    }
    $bilan['sms'] = mdSmsPasser($reg);
    $etat['dernier'] = $t;
    $etat['bilan'] = $bilan;
    mdSettingSet('mdCycle', $etat);
    if (random_int(1, 50) === 1) { Db::exec('DELETE FROM ceo_md_journal WHERE at < ?', [date('Y-m-d H:i:s', $t - 90 * 86400)]); }
    return ['ok' => true, 'dernier' => date('Y-m-d H:i:s', $t), 'bilan' => $bilan];
}

/* --- lectures pour l'écran ---------------------------------------------------------------- */

/** Une ligne de lead telle que l'écran la lit. */
function mdLeadVue(array $l, array $reg, int $t, array $mags, array $forms): array
{
    $f = $forms[$l['form_id']] ?? null;
    return [
        'id' => (int) $l['id'], 'metaId' => (string) $l['meta_lead_id'], 'formId' => (string) $l['form_id'], 'form' => $f['nom'] ?? '',
        'plateforme' => (string) $l['plateforme'], 'campagne' => $l['campagne'], 'annonce' => $l['annonce'], 'recu' => $l['recu_le'], 'via' => $l['via'],
        'entreprise' => $l['entreprise'], 'contact' => $l['contact'], 'telephone' => mdTelLisible($l['telephone']), 'email' => $l['email'], 'cp' => $l['cp'],
        'shopId' => $l['shop_id'], 'magasin' => $l['shop_id'] !== null ? ($mags[(string) $l['shop_id']]['nom'] ?? (string) $l['shop_id']) : null, 'shopPar' => $l['shop_par'],
        'etat' => $l['etat'], 'motif' => $l['motif'], 'essais' => (int) $l['essais'],
        'erpId' => $l['erp_id'], 'envoye' => $l['envoye_le'], 'statut' => $l['statut_erp'], 'statutBrut' => $l['statut_brut'], 'statutLe' => $l['statut_le'],
        'vu' => $l['vu_le'], 'contacte' => $l['contacte_le'], 'connecte' => $l['connecte_le'], 'relu' => $l['relu_le'],
        'sms' => $l['sms_le'], 'smsDerniere' => $l['sms_derniere'], 'smsRelances' => (int) $l['sms_relances'], 'smsErreur' => $l['sms_erreur'], 'escalade' => $l['escalade_le'],
        'retard' => mdRetard($l, $reg, $t),
        'lien' => $l['erp_id'] ? str_replace('{erp_id}', rawurlencode((string) $l['erp_id']), (string) $reg['erp']['lienLead']) : null,
    ];
}

/** Les chiffres d'une liste de leads (30 jours) : réseau ou magasin. */
function mdStats(array $rows, array $reg, int $t): array
{
    $n = count($rows); $fb = 0; $ig = 0; $erp = 0; $attr = 0; $rej = 0; $att = 0; $conn = 0; $ret = 0;
    $vusOk = 0; $vusBase = 0; $delais = [];
    $seuilVu = (int) $reg['sms']['relanceH'] * 3600;
    foreach ($rows as $l) {
        if ($l['plateforme'] === 'fb') { $fb++; } elseif ($l['plateforme'] === 'ig') { $ig++; }
        if ($l['etat'] === 'envoye') { $erp++; }
        if ($l['etat'] === 'a_attribuer') { $attr++; }
        if ($l['etat'] === 'rejete' || $l['etat'] === 'erreur') { $rej++; }
        if ($l['etat'] === 'attente') { $att++; }
        if ($l['statut_erp'] === 'bureau_connecte') { $conn++; }
        if (mdRetard($l, $reg, $t) !== null) { $ret++; }
        if ($l['etat'] === 'envoye' && $l['envoye_le']) {
            $env = (int) strtotime((string) $l['envoye_le']);
            if ($l['vu_le']) { $vusBase++; if ((int) strtotime((string) $l['vu_le']) - $env <= $seuilVu) { $vusOk++; } }
            elseif ($t - $env > $seuilVu) { $vusBase++; }
        }
        if ($l['contacte_le']) { $delais[] = max(0, (int) strtotime((string) $l['contacte_le']) - (int) strtotime((string) $l['recu_le'])); }
    }
    sort($delais);
    $med = $delais ? $delais[intdiv(count($delais), 2)] : null;
    return ['leads' => $n, 'fb' => $fb, 'ig' => $ig, 'dansErp' => $erp, 'aAttribuer' => $attr, 'rejetes' => $rej, 'attente' => $att,
        'vus2h' => $vusBase ? (int) round(100 * $vusOk / $vusBase) : null, 'contactMedianMin' => $med !== null ? intdiv($med, 60) : null,
        'connectes' => $conn, 'retard' => $ret];
}

/** GET /metadonnee — tout ce que les quatre écrans lisent. */
function ep_md(): array
{
    mdTables();
    $reg = mdReglages();
    $t = time();
    $mags = mdMagasins();
    $shops = mdShops();
    $rows = Db::rows('SELECT * FROM ceo_md_lead WHERE recu_le >= ?', [date('Y-m-d H:i:s', $t - 30 * 86400)]);
    $parForm = []; $parShop = [];
    foreach ($rows as $l) { $parForm[$l['form_id']][] = $l; if ($l['shop_id'] !== null) { $parShop[(string) $l['shop_id']][] = $l; } }

    $formulaires = [];
    foreach (Db::rows('SELECT * FROM ceo_md_form ORDER BY suivi DESC, page_nom, nom') as $f) {
        $qs = mdQuestionsDe($f);
        $dernier = Db::row('SELECT reponses_json FROM ceo_md_lead WHERE form_id = ? ORDER BY recu_le DESC LIMIT 1', [$f['form_id']]);
        $exemple = [];
        foreach (mdJson($dernier['reponses_json'] ?? null) as $r) { $exemple[(string) $r['key']] = (string) $r['valeur']; }
        // Les réponses vues à une question à choix : ses options chez Meta, puis ce que les leads ont répondu.
        $vues = [];
        foreach (array_slice(Db::rows('SELECT reponses_json FROM ceo_md_lead WHERE form_id = ? ORDER BY recu_le DESC LIMIT 300', [$f['form_id']]), 0, 300) as $r) {
            foreach (mdJson($r['reponses_json']) as $rep) { $vues[(string) $rep['key']][(string) $rep['valeur']] = ($vues[(string) $rep['key']][(string) $rep['valeur']] ?? 0) + 1; }
        }
        $mapping = !empty($f['mapping_le']) ? mdJson($f['mapping_json']) : null;
        $neufs = $mapping !== null && $f['questions_mappees'] !== mdEmpreinte($qs)
            ? count(array_filter($qs, static fn ($q) => !array_key_exists($q['key'], $mapping) && $q['key'] !== 'consentement')) : 0;
        $ls = $parForm[$f['form_id']] ?? [];
        $formulaires[] = [
            'id' => $f['form_id'], 'nom' => $f['nom'] !== '' ? $f['nom'] : 'Formulaire ' . $f['form_id'], 'pageId' => $f['page_id'], 'page' => $f['page_nom'],
            'statutMeta' => $f['statut_meta'], 'locale' => $f['locale'], 'leadsMeta' => $f['leads_meta'] !== null ? (int) $f['leads_meta'] : null,
            'suivi' => (bool) (int) $f['suivi'], 'affectation' => $f['affectation'], 'shopId' => $f['shop_id'],
            'questions' => array_map(static fn ($q) => $q + ['exemple' => $exemple[$q['key']] ?? null, 'suggestion' => mdChampSuggere($q),
                'vues' => array_slice(array_keys($vues[$q['key']] ?? []), 0, 20), 'compte' => $vues[$q['key']] ?? (object) []], $qs),
            'mapping' => $mapping, 'mappingSuggere' => mdMappingSuggere($qs), 'valeurs' => mdJson($f['valeurs_json']) ?: (object) [],
            'mappingLe' => $f['mapping_le'], 'mappingPar' => $f['mapping_par'], 'champsNeufs' => $neufs,
            'leads30' => count($ls), 'attente' => count(array_filter($ls, static fn ($l) => $l['etat'] === 'attente')),
            'fb' => count(array_filter($ls, static fn ($l) => $l['plateforme'] === 'fb')), 'ig' => count(array_filter($ls, static fn ($l) => $l['plateforme'] === 'ig')),
            'ignores' => (int) $f['ignores'], 'dernierLead' => $f['dernier_lead'], 'relu' => $f['relu_le'],
        ];
    }
    $magasins = [];
    foreach ($mags as $id => $m) {
        $s = $shops[$id] ?? null;
        $magasins[] = ['id' => (string) $id, 'nom' => $m['nom'], 'erpId' => $m['erpId'], 'statut' => $m['statut'],
            'sms' => ['on' => (bool) (int) ($s['sms_on'] ?? 0), 'tel' => mdTelLisible((string) ($s['sms_tel'] ?? '')), 'nom' => (string) ($s['sms_nom'] ?? ''),
                'escaladeEmail' => (string) ($s['escalade_email'] ?? '')],
            'stats' => mdStats($parShop[$id] ?? [], $reg, $t)];
    }
    $cycle = setting('mdCycle', []);
    $base = function_exists('rapBaseUrl') ? rtrim(rapBaseUrl(), '/') : '';
    return [
        'statut' => [
            'meta' => MetaGraph::statut(),
            'erp' => ['configure' => MdErp::configured(), 'base' => class_exists('PanelApi') ? (string) (PanelApi::config()['base'] ?? '') : ''],
            'sms' => ['configure' => MdSms::configured(), 'base' => MdSms::config()['base'], 'jetonSaisi' => (string) setting('mdSmsJeton', '') !== ''],
            'smtp' => class_exists('Smtp') && Smtp::configured(),
            'cron' => ['dernier' => is_array($cycle) && !empty($cycle['dernier']) ? date('Y-m-d H:i:s', (int) $cycle['dernier']) : null,
                'bilan' => is_array($cycle) ? ($cycle['bilan'] ?? null) : null,
                'url' => $base . '/api/cockpit/metadonnee/cron?jeton=…'],
            'webhook' => $base . '/api/cockpit/marketing/brand-guard/webhook/meta',
        ],
        'reglages' => $reg,
        'champs' => array_map(static fn ($k, $v) => ['cle' => $k, 'libelle' => $v[0], 'regle' => $v[1], 'requis' => in_array($k, MD_REQUIS, true)], array_keys(MD_CHAMPS), MD_CHAMPS),
        'besoins' => MD_BESOINS, 'statuts' => MD_STATUTS,
        'pages' => setting('mdPages', []) ?: [],
        'formulaires' => $formulaires,
        'magasins' => $magasins,
        'resume' => mdStats($rows, $reg, $t),
        'journal' => Db::rows('SELECT id, at, type, message, lead_id AS leadId FROM ceo_md_journal ORDER BY id DESC LIMIT 40'),
        'maintenant' => date('Y-m-d H:i:s', $t),
    ];
}

/** GET /metadonnee/leads?filtre=&shop=&form=&q=&limite= — la liste du suivi. */
function ep_md_leads(): array
{
    mdTables();
    $reg = mdReglages();
    $t = time();
    $jours = max(1, min(365, (int) ($_GET['jours'] ?? 30)));
    $rows = Db::rows('SELECT * FROM ceo_md_lead WHERE recu_le >= ? ORDER BY recu_le DESC LIMIT 2000', [date('Y-m-d H:i:s', $t - $jours * 86400)]);
    $shop = (string) ($_GET['shop'] ?? '');
    $form = (string) ($_GET['form'] ?? '');
    $q = mdPlat(trim((string) ($_GET['q'] ?? '')));
    if ($shop !== '') { $rows = array_values(array_filter($rows, static fn ($l) => (string) $l['shop_id'] === $shop)); }
    if ($form !== '') { $rows = array_values(array_filter($rows, static fn ($l) => (string) $l['form_id'] === $form)); }
    if ($q !== '') { $rows = array_values(array_filter($rows, static fn ($l) => str_contains(mdPlat(implode(' ', [$l['entreprise'], $l['contact'], $l['email'], $l['telephone'], $l['cp']])), $q))); }
    $filtres = [
        'tous' => static fn ($l) => true,
        'pas_vus' => static fn ($l) => $l['etat'] === 'envoye' && in_array((string) $l['statut_erp'], ['', 'nouveau'], true),
        'retard' => static fn ($l) => mdRetard($l, $reg, $t) !== null,
        'a_attribuer' => static fn ($l) => $l['etat'] === 'a_attribuer',
        'a_corriger' => static fn ($l) => $l['etat'] === 'rejete' || $l['etat'] === 'erreur',
        'attente' => static fn ($l) => $l['etat'] === 'attente' || $l['etat'] === 'a_envoyer',
        'contactes' => static fn ($l) => in_array((string) $l['statut_erp'], ['contacte', 'bureau_cree'], true),
        'connectes' => static fn ($l) => $l['statut_erp'] === 'bureau_connecte',
        'perdus' => static fn ($l) => $l['statut_erp'] === 'perdu',
    ];
    $comptes = [];
    foreach ($filtres as $k => $fn) { $comptes[$k] = count(array_filter($rows, $fn)); }
    $choisi = isset($filtres[$_GET['filtre'] ?? '']) ? (string) $_GET['filtre'] : 'tous';
    $sel = array_values(array_filter($rows, $filtres[$choisi]));
    $limite = max(10, min(500, (int) ($_GET['limite'] ?? 150)));
    $mags = mdMagasins();
    $forms = [];
    foreach (Db::rows('SELECT form_id, nom, mapping_json, mapping_le FROM ceo_md_form') as $f) { $forms[$f['form_id']] = $f; }
    return ['filtre' => $choisi, 'comptes' => $comptes, 'total' => count($sel),
        'leads' => array_map(static fn ($l) => mdLeadVue($l, $reg, $t, $mags, $forms), array_slice($sel, 0, $limite))];
}

/** GET /metadonnee/leads/{id} — un lead : ses réponses, ce qui est parti, son fil. */
function ep_md_lead(int $id): array
{
    mdTables();
    $l = Db::row('SELECT * FROM ceo_md_lead WHERE id = ?', [$id]);
    if ($l === null) { http_response_code(404); return ['error' => 'lead introuvable']; }
    $reg = mdReglages();
    $f = mdForm((string) $l['form_id']) ?? ['nom' => '', 'mapping_json' => null, 'valeurs_json' => null];
    $mapping = mdMappingEffectif($f + ['questions_json' => null]);
    $reps = array_map(static fn ($r) => $r + ['champ' => $mapping[$r['key']] ?? ''], mdJson($l['reponses_json']));
    $forms = [(string) $l['form_id'] => $f];
    return ['lead' => mdLeadVue($l, $reg, time(), mdMagasins(), $forms), 'reponses' => $reps,
        'payload' => mdJson($l['payload_json']) ?: null,
        'journal' => Db::rows('SELECT at, type, message FROM ceo_md_journal WHERE lead_id = ? ORDER BY id', [$id])];
}

/* --- écritures ------------------------------------------------------------------------------ */

/** POST /metadonnee/formulaires/relire */
function wr_md_formulaires_relire(): array
{
    try { return mdRelireFormulaires(); }
    catch (MetaTokenExpire $e) { http_response_code(502); return ['error' => 'jeton Meta expiré : ' . $e->getMessage()]; }
    catch (Throwable $e) { http_response_code(502); return ['error' => $e->getMessage()]; }
}

/**
 * PUT /metadonnee/formulaires/{id} — {suivi?, affectation?, shopId?, mapping?, valeurs?}.
 * Enregistrer un mapping libère les leads qui l'attendaient.
 */
function wr_md_formulaire_put(string $formId): array
{
    mdTables();
    $f = mdForm($formId);
    if ($f === null) { http_response_code(404); return ['error' => 'formulaire inconnu']; }
    $b = body();
    $set = []; $vals = [];
    if (array_key_exists('suivi', $b)) { $set[] = 'suivi = ?'; $vals[] = !empty($b['suivi']) ? 1 : 0; }
    if (array_key_exists('affectation', $b)) { $set[] = 'affectation = ?'; $vals[] = $b['affectation'] === 'cp' ? 'cp' : 'magasin'; }
    if (array_key_exists('shopId', $b)) {
        $s = trim((string) ($b['shopId'] ?? ''));
        if ($s !== '' && !isset(mdMagasins()[$s])) { http_response_code(422); return ['error' => 'magasin inconnu : ' . $s]; }
        $set[] = 'shop_id = ?'; $vals[] = $s !== '' ? $s : null;
    }
    if (array_key_exists('mapping', $b) && is_array($b['mapping'])) {
        $qs = mdQuestionsDe($f);
        $cles = array_column($qs, 'key');
        $m = [];
        foreach ($b['mapping'] as $k => $c) {
            $c = (string) $c;
            if (!in_array((string) $k, $cles, true)) { continue; }
            if ($c === '') { continue; }
            if ($c !== 'ignorer' && !isset(MD_CHAMPS[$c])) { http_response_code(422); return ['error' => 'champ ERP inconnu : ' . $c]; }
            $m[(string) $k] = $c;
        }
        $set[] = 'mapping_json = ?'; $vals[] = json_encode((object) $m, JSON_UNESCAPED_UNICODE);
        $set[] = 'questions_mappees = ?'; $vals[] = mdEmpreinte($qs);
        $set[] = 'mapping_le = ?'; $vals[] = date('Y-m-d H:i:s');
        $set[] = 'mapping_par = ?'; $vals[] = mb_substr(trim((string) ($b['par'] ?? '')), 0, 80) ?: null;
    }
    if (array_key_exists('valeurs', $b) && is_array($b['valeurs'])) {
        $v = [];
        foreach ($b['valeurs'] as $k => $paires) {
            if (!is_array($paires)) { continue; }
            foreach ($paires as $meta => $code) {
                $code = (string) preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower((string) $code));
                if ($code !== '') { $v[(string) $k][(string) $meta] = $code; }
            }
        }
        $set[] = 'valeurs_json = ?'; $vals[] = json_encode((object) $v, JSON_UNESCAPED_UNICODE);
    }
    if (!$set) { return ['ok' => true]; }
    $vals[] = $formId;
    Db::exec('UPDATE ceo_md_form SET ' . implode(', ', $set) . ' WHERE form_id = ?', $vals);
    $nom = $f['nom'] !== '' ? $f['nom'] : $formId;
    if (array_key_exists('suivi', $b)) { mdJournal('reglage', 'Formulaire « ' . $nom . ' » ' . (!empty($b['suivi']) ? 'suivi' : 'plus suivi')); }
    if (array_key_exists('mapping', $b)) { mdJournal('reglage', 'Mapping de « ' . $nom . ' » enregistré'); }
    $r = mdReevaluer($formId);
    return ['ok' => true, 'liberes' => $r['changes']];
}

/** POST /metadonnee/formulaires/{id}/rattrapage {jours} — l'historique d'un formulaire tout juste suivi. */
function wr_md_formulaire_rattrapage(string $formId): array
{
    try { return mdRattrapage((int) (body()['jours'] ?? 30), $formId); }
    catch (Throwable $e) { http_response_code(502); return ['error' => $e->getMessage()]; }
}

/** POST /metadonnee/apercu {formId, mapping, valeurs, leadId?} — ce qui partirait, sans rien envoyer. */
function wr_md_apercu(): array
{
    mdTables();
    $b = body();
    $f = mdForm((string) ($b['formId'] ?? ''));
    if ($f === null) { http_response_code(404); return ['error' => 'formulaire inconnu']; }
    $l = !empty($b['leadId'])
        ? Db::row('SELECT * FROM ceo_md_lead WHERE id = ? AND form_id = ?', [(int) $b['leadId'], $f['form_id']])
        : Db::row('SELECT * FROM ceo_md_lead WHERE form_id = ? ORDER BY recu_le DESC LIMIT 1', [$f['form_id']]);
    if ($l === null) { return ['vide' => true]; }
    $mapping = is_array($b['mapping'] ?? null) ? $b['mapping'] : mdMappingEffectif($f);
    $valeurs = is_array($b['valeurs'] ?? null) ? $b['valeurs'] : mdJson($f['valeurs_json']);
    $t = mdTransformer(mdJson($l['reponses_json']), $mapping, $valeurs, (int) strtotime((string) $l['recu_le']));
    $reg = mdReglages();
    $shop = trim((string) ($b['shopId'] ?? ($f['shop_id'] ?? '')));
    if (($b['affectation'] ?? $f['affectation']) === 'cp') { $shop = (string) (mdMagasinParCp((string) ($t['payload']['company']['zip'] ?? ''), $reg['cp']) ?? ''); }
    $mags = mdMagasins();
    $mag = $mags[$shop] ?? ['id' => $shop ?: null, 'erpId' => null, 'nom' => ''];
    return ['lead' => ['id' => (int) $l['id'], 'entreprise' => $l['entreprise'], 'recu' => $l['recu_le']],
        'route' => 'POST ' . $reg['erp']['routeEnvoi'], 'payload' => mdPayload($l, $f, $t['payload'], $mag),
        'manques' => array_map(static fn ($c) => MD_CHAMPS[$c][0] ?? $c, $t['manques']), 'avertissements' => $t['avertissements'],
        'consentement' => $t['consentement'], 'magasin' => $mag['nom'] ?: null];
}

/** PUT /metadonnee/leads/{id} {shopId} — le consultant attribue le magasin (et le lead part si l'envoi est allumé). */
function wr_md_lead_put(int $id): array
{
    mdTables();
    $l = Db::row('SELECT * FROM ceo_md_lead WHERE id = ?', [$id]);
    if ($l === null) { http_response_code(404); return ['error' => 'lead introuvable']; }
    $b = body();
    if (array_key_exists('shopId', $b)) {
        $s = trim((string) $b['shopId']);
        if ($s === '' || !isset(mdMagasins()[$s])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
        if ($l['etat'] === 'envoye' && (string) $l['shop_id'] !== $s) { http_response_code(409); return ['error' => 'déjà dans l’ERP d’un autre magasin']; }
        Db::exec("UPDATE ceo_md_lead SET shop_id = ?, shop_par = 'main', etat = IF(etat = 'envoye', etat, 'a_envoyer'), motif = NULL WHERE id = ?", [$s, $id]);
        mdJournal('reglage', 'Lead ' . ($l['entreprise'] ?: '#' . $id) . ' attribué à ' . (mdMagasins()[$s]['nom'] ?? $s), $id);
    }
    $out = ['ok' => true];
    if (!empty(mdReglages()['erp']['envoiActif']) || !empty($b['envoyer'])) { $out['envoi'] = mdEnvoyer($id, true); }
    return $out;
}

/** POST /metadonnee/leads/{id}/envoyer — « Renvoyer à l'ERP ». */
function wr_md_lead_envoyer(int $id): array
{
    mdTables();
    $r = mdEnvoyer($id, true);
    if (($r['etat'] ?? '') === 'envoye') {
        $l = Db::row('SELECT * FROM ceo_md_lead WHERE id = ?', [$id]);
        if ($l !== null && empty($l['sms_le'])) { $r['sms'] = mdSmsLead($l, mdReglages()); }
    }
    return $r;
}

/** POST /metadonnee/leads/{id}/sms — relancer le franchisé tout de suite (hors fenêtre horaire comprise). */
function wr_md_lead_sms(int $id): array
{
    mdTables();
    $l = Db::row('SELECT * FROM ceo_md_lead WHERE id = ?', [$id]);
    if ($l === null) { http_response_code(404); return ['error' => 'lead introuvable']; }
    if ($l['etat'] !== 'envoye') { http_response_code(409); return ['error' => 'le lead n’est pas encore dans l’ERP']; }
    if (!MdSms::configured()) { http_response_code(409); return ['error' => 'SMSAPI non configuré (étape 4)']; }
    return mdSmsLead($l, mdReglages(), true);
}

/** POST /metadonnee/tick — le cycle, tout de suite. */
function wr_md_tick(): array
{
    return mdCycle(true);
}

/** GET /metadonnee/cron?jeton= — l'entrée de l'horloge. */
function ep_md_cron(): array
{
    mdTables();
    $jeton = (string) ($_GET['jeton'] ?? '');
    if ($jeton === '' || !hash_equals((string) setting('mdJeton', ''), $jeton)) { http_response_code(403); return ['error' => 'jeton invalide']; }
    return mdCycle(false);
}

/**
 * PUT /metadonnee/reglages — {erp?, sms?, meta?, cp?, smsJeton?}. Le jeton
 * SMSAPI n'est jamais renvoyé ; « '' » l'efface, absent le garde.
 */
function wr_md_reglages_put(): array
{
    mdTables();
    $b = body();
    $cur = setting('mdReglages', []);
    if (!is_array($cur)) { $cur = []; }
    $def = mdReglagesDefaut();
    $int = static fn ($v, int $min, int $max) => max($min, min($max, (int) $v));
    if (is_array($b['erp'] ?? null)) {
        // Seul ce qui est saisi est gardé : un défaut qui évolue (modèle, route) suit le code.
        $e = is_array($cur['erp'] ?? null) ? $cur['erp'] : [];
        foreach (['routeEnvoi', 'routeStatuts'] as $k) {
            if (isset($b['erp'][$k])) {
                $r = '/' . ltrim(trim((string) $b['erp'][$k]), '/');
                if (!preg_match('#^/[\w\-./{}?=&]*$#', $r)) { http_response_code(422); return ['error' => 'route ERP illisible : ' . $r]; }
                $e[$k] = $r;
            }
        }
        if (isset($b['erp']['lienLead'])) { $e['lienLead'] = mb_substr(trim((string) $b['erp']['lienLead']), 0, 300); }
        if (array_key_exists('envoiActif', $b['erp'])) { $e['envoiActif'] = (bool) $b['erp']['envoiActif']; }
        if (isset($b['erp']['relectureMin'])) { $e['relectureMin'] = $int($b['erp']['relectureMin'], 5, 240); }
        if (isset($b['erp']['relectureJours'])) { $e['relectureJours'] = $int($b['erp']['relectureJours'], 1, 90); }
        $cur['erp'] = $e;
    }
    if (is_array($b['sms'] ?? null)) {
        $s = is_array($cur['sms'] ?? null) ? $cur['sms'] : [];
        if (array_key_exists('actif', $b['sms'])) { $s['actif'] = (bool) $b['sms']['actif']; }
        foreach (['heureDebut' => [0, 23], 'heureFin' => [1, 24], 'relanceH' => [1, 48], 'relancesMax' => [0, 5], 'escaladeH' => [1, 168]] as $k => [$mi, $ma]) {
            if (isset($b['sms'][$k])) { $s[$k] = $int($b['sms'][$k], $mi, $ma); }
        }
        $vu = array_merge($def['sms'], $s);
        if ($vu['heureFin'] <= $vu['heureDebut']) { http_response_code(422); return ['error' => 'la fenêtre des SMS doit finir après son début']; }
        if (isset($b['sms']['from'])) { $s['from'] = mb_substr(preg_replace('/[^\w .\-]/u', '', (string) $b['sms']['from']) ?? '', 0, 11); }
        if (isset($b['sms']['base'])) { $s['base'] = rtrim(mb_substr(trim((string) $b['sms']['base']), 0, 120), '/'); }
        foreach (['modele', 'modeleRelance'] as $k) {
            if (isset($b['sms'][$k])) {
                $txt = trim((string) $b['sms'][$k]);
                if ($txt === '') { http_response_code(422); return ['error' => 'le modèle de SMS ne peut pas être vide']; }
                $s[$k] = mb_substr($txt, 0, 480);
            }
        }
        $cur['sms'] = $s;
    }
    if (is_array($b['meta'] ?? null)) {
        $m = is_array($cur['meta'] ?? null) ? $cur['meta'] : [];
        if (isset($b['meta']['rattrapageJours'])) { $m['rattrapageJours'] = $int($b['meta']['rattrapageJours'], 1, 90); }
        if (isset($b['meta']['rattrapageMin'])) { $m['rattrapageMin'] = $int($b['meta']['rattrapageMin'], 15, 1440); }
        $cur['meta'] = $m;
    }
    if (array_key_exists('cp', $b)) {
        $mags = mdMagasins();
        $cp = mdCpNettoyer($b['cp']);
        foreach ($cp as $p) { if ($p['shopId'] !== '' && !isset($mags[$p['shopId']])) { http_response_code(422); return ['error' => 'magasin inconnu dans les codes postaux : ' . $p['shopId']]; } }
        $cur['cp'] = $cp;
    }
    mdSettingSet('mdReglages', $cur);
    if (array_key_exists('smsJeton', $b) && is_string($b['smsJeton'])) {
        $j = trim($b['smsJeton']);
        if ($j === '') { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['mdSmsJeton']); }
        else { mdSettingSet('mdSmsJeton', $j); }
    }
    mdJournal('reglage', 'Réglages MétaDonnée enregistrés (' . implode(', ', array_keys(array_intersect_key($b, ['erp' => 1, 'sms' => 1, 'meta' => 1, 'cp' => 1, 'smsJeton' => 1]))) . ')');
    $r = array_key_exists('cp', $b) ? mdReevaluer() : ['changes' => 0];
    return ['ok' => true, 'reglages' => mdReglages(), 'liberes' => $r['changes']];
}

/** PUT /metadonnee/magasins/{id} — {smsOn, smsTel, smsNom, escaladeEmail}. */
function wr_md_magasin_put(string $shopId): array
{
    mdTables();
    if (!isset(mdMagasins()[$shopId])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $b = body();
    $cur = Db::row('SELECT * FROM ceo_md_shop WHERE shop_id = ?', [$shopId]) ?? ['sms_on' => 0, 'sms_tel' => '', 'sms_nom' => '', 'escalade_email' => ''];
    $tel = array_key_exists('smsTel', $b) ? trim((string) $b['smsTel']) : (string) $cur['sms_tel'];
    if ($tel !== '') {
        $e = mdTelE164($tel);
        if ($e === null) { http_response_code(422); return ['error' => 'numéro illisible : ' . $tel]; }
        $tel = $e;
    }
    $mail = array_key_exists('escaladeEmail', $b) ? trim((string) $b['escaladeEmail']) : (string) $cur['escalade_email'];
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'adresse illisible : ' . $mail]; }
    $on = array_key_exists('smsOn', $b) ? (!empty($b['smsOn']) ? 1 : 0) : (int) $cur['sms_on'];
    if ($on && $tel === '') { http_response_code(422); return ['error' => 'un numéro est nécessaire pour allumer les SMS de ce magasin']; }
    $nom = array_key_exists('smsNom', $b) ? mb_substr(trim((string) $b['smsNom']), 0, 80) : (string) $cur['sms_nom'];
    Db::exec('INSERT INTO ceo_md_shop (shop_id, sms_on, sms_tel, sms_nom, escalade_email, maj_le) VALUES (?,?,?,?,?,?)'
        . ' ON DUPLICATE KEY UPDATE sms_on = VALUES(sms_on), sms_tel = VALUES(sms_tel), sms_nom = VALUES(sms_nom), escalade_email = VALUES(escalade_email), maj_le = VALUES(maj_le)',
        [$shopId, $on, $tel, $nom, $mail, date('Y-m-d H:i:s')]);
    mdJournal('reglage', 'SMS de ' . mdMagasins()[$shopId]['nom'] . ' : ' . ($on ? 'allumés vers ' . mdTelLisible($tel) : 'coupés'));
    return ['ok' => true, 'sms' => ['on' => (bool) $on, 'tel' => mdTelLisible($tel), 'nom' => $nom, 'escaladeEmail' => $mail]];
}

/** POST /metadonnee/sms/test {tel, texte?} — un SMS d'essai (le modèle rempli d'un exemple, sinon le texte donné). */
function wr_md_sms_test(): array
{
    mdTables();
    $b = body();
    $tel = trim((string) ($b['tel'] ?? ''));
    if ($tel === '') { http_response_code(422); return ['error' => 'numéro manquant']; }
    $reg = mdReglages();
    $texte = trim((string) ($b['texte'] ?? ''));
    if ($texte === '') {
        $t = time();
        $texte = mdSmsTexte((string) $reg['sms']['modele'], ['entreprise' => 'Bureau Exemple', 'contact' => 'Marie Dupont', 'telephone' => '+32470000000', 'cp' => '6041',
            'erp_id' => 'TEST', 'envoye_le' => date('Y-m-d H:i:s', $t), 'payload_json' => json_encode(['lead' => ['headcount' => 20]])], $reg, $t);
    }
    [$ok, $info] = MdSms::envoyer($tel, $texte, (string) $reg['sms']['from']);
    mdJournal($ok ? 'sms' : 'erreur', 'SMS d’essai → ' . mdTelLisible(mdTelE164($tel)) . ($ok ? ' parti' : ' non parti : ' . $info));
    if (!$ok) { http_response_code(502); return ['error' => $info]; }
    return ['ok' => true, 'id' => $info, 'texte' => $texte];
}

/** POST /metadonnee/pages/{id}/abonner */
function wr_md_page_abonner(string $pageId): array
{
    try { return mdAbonnerPage($pageId); }
    catch (Throwable $e) { http_response_code(502); return ['error' => $e->getMessage()]; }
}

/** POST /metadonnee/erp/test — la route des statuts répond-elle ? (lecture seule) */
function wr_md_erp_test(): array
{
    mdTables();
    $reg = mdReglages();
    $route = (string) $reg['erp']['routeStatuts'];
    [$code, $res] = MdErp::appel('GET', $route . (str_contains($route, '?') ? '&' : '?') . 'ids=');
    $ok = $code >= 200 && $code < 300;
    return ['ok' => $ok, 'code' => $code, 'message' => $ok ? 'GET ' . $route . ' répond (' . count(mdListe($res)) . ' ligne(s))'
        : 'GET ' . $route . ' → ' . ($code ?: 'injoignable') . ' : ' . (mdMessage($res) ?: '—') . (in_array($code, [404, 405], true) ? ' — route à créer côté panel' : '')];
}

/* --- tester les connexions et les données de l'API ---------------------------------------- */

/**
 * La sonde : chaque maillon vérifié en LECTURE seule — Meta (jeton, pages,
 * formulaires, abonnement, signature), l'ERP (compte, route des statuts),
 * SMSAPI (le profil du compte, aucun SMS ne part), le SMTP de l'escalade et
 * l'horloge. Chaque ligne dit ce qui répond et, sinon, le remède.
 */
function mdSonde(): array
{
    mdTables();
    $reg = mdReglages();
    $blocs = [];
    $m = [];
    $st = MetaGraph::statut();
    if (!$st['configure']) { $m[] = ['ko', 'Jeton du System User', 'absent — META_SYSTEM_TOKEN ou config/config.php (meta.systemToken), voir docs/brand-guard.md']; }
    else {
        try {
            $pages = MetaGraph::tokensPages();
            $m[] = [$pages ? 'ok' : 'ko', 'Jeton du System User', count($pages) . ' page(s) visible(s)' . ($pages ? '' : ' — rattacher les pages au System User dans le Business Manager')];
            $nF = 0; $abo = 0; $err = [];
            foreach (array_slice($pages, 0, 12, true) as $id => $p) {
                try { $r = MetaGraph::http('GET', $id . '/leadgen_forms', ['access_token' => $p['token'], 'fields' => 'id', 'limit' => 100]); $nF += count((array) ($r['data'] ?? [])); }
                catch (Throwable $e) { $err[] = $p['nom'] . ' : ' . $e->getMessage(); }
                try {
                    $r = MetaGraph::http('GET', $id . '/subscribed_apps', ['access_token' => $p['token']]);
                    foreach ((array) ($r['data'] ?? []) as $app) { if (in_array('leadgen', (array) ($app['subscribed_fields'] ?? []), true)) { $abo++; break; } }
                } catch (Throwable $e) { /* la ligne des formulaires le dit déjà */ }
            }
            $m[] = [$err ? 'att' : 'ok', 'Formulaires de prospects', $nF . ' formulaire(s) lisible(s)'
                . ($err ? ' — ' . implode(' ; ', array_slice($err, 0, 2)) . ' (permission leads_retrieval ? accès aux prospects de la page ?)' : '')];
            $m[] = [$pages && $abo === count($pages) ? 'ok' : 'att', 'Pages abonnées aux leads', $abo . ' / ' . count($pages)
                . ($abo < count($pages) ? ' — sans abonnement, les leads n’arrivent que par le rattrapage horaire (étape 1 : « Abonner aux leads »)' : '')];
        } catch (MetaTokenExpire $e) { $m[] = ['ko', 'Jeton du System User', 'expiré ou révoqué : ' . $e->getMessage()]; }
        catch (Throwable $e) { $m[] = ['ko', 'Jeton du System User', $e->getMessage()]; }
    }
    $m[] = [$st['secretDefini'] ? 'ok' : 'ko', 'Signature du webhook', $st['secretDefini'] ? 'clé secrète de l’app présente' : 'clé secrète absente (META_APP_SECRET) : le webhook refuse tout'];
    $blocs[] = ['nom' => 'Meta', 'etapes' => $m];

    $e = [];
    if (!MdErp::configured()) { $e[] = ['ko', 'Compte API du panel', 'non configuré (Paramètres → compte consultant) : rien ne peut partir']; }
    else {
        $e[] = ['ok', 'Compte API du panel', class_exists('PanelApi') ? (string) PanelApi::config()['base'] : 'transport d’essai'];
        $route = (string) $reg['erp']['routeStatuts'];
        [$code, $res] = MdErp::appel('GET', $route . (str_contains($route, '?') ? '&' : '?') . 'ids=');
        $ok = $code >= 200 && $code < 300;
        $e[] = [$ok ? 'ok' : 'ko', 'Lecture des statuts · GET ' . $route, $ok ? 'répond (' . count(mdListe($res)) . ' ligne(s))'
            : ($code ?: 'injoignable') . ' : ' . (mdMessage($res) ?: '—') . (in_array($code, [404, 405], true) ? ' — route à créer côté panel' : ($code === 401 ? ' — connexion refusée : identifiants du compte' : ''))];
        $e[] = [!empty($reg['erp']['envoiActif']) ? 'ok' : 'att', 'Envoi · POST ' . $reg['erp']['routeEnvoi'],
            !empty($reg['erp']['envoiActif']) ? 'automatique' : 'éteint — « Envoyer un lead d’essai » (étape 2) vérifie la route sans l’allumer'];
    }
    $blocs[] = ['nom' => 'ERP', 'etapes' => $e];

    $sm = [];
    [$okS, $info] = MdSms::profil();
    $sm[] = [$okS ? 'ok' : 'ko', 'Jeton SMSAPI · ' . MdSms::config()['base'], $info];
    $nMag = (int) (Db::row("SELECT COUNT(*) AS n FROM ceo_md_shop WHERE sms_on = 1 AND sms_tel <> ''")['n'] ?? 0);
    $sm[] = [$nMag ? 'ok' : 'att', 'Magasins avec un numéro', $nMag . ' magasin(s)'];
    $sm[] = [!empty($reg['sms']['actif']) ? 'ok' : 'att', 'SMS automatiques', !empty($reg['sms']['actif']) ? 'allumés' : 'éteints'];
    $smtp = class_exists('Smtp') && Smtp::configured();
    $sm[] = [$smtp ? 'ok' : 'att', 'E-mail au consultant (SMTP)', $smtp ? 'configuré' : 'non configuré : l’escalade ne partira pas'];
    $blocs[] = ['nom' => 'SMS', 'etapes' => $sm];

    $cy = setting('mdCycle', []);
    $d = is_array($cy) && !empty($cy['dernier']) ? (int) $cy['dernier'] : 0;
    $blocs[] = ['nom' => 'Horloge', 'etapes' => [[$d && time() - $d < 20 * 60 ? 'ok' : 'att', 'bin/metadonnee_cron.sh, toutes les 5 min',
        $d ? 'dernier passage le ' . date('d/m à H:i', $d) : 'jamais passée — la ligne cron est posée par bin/deploy.sh']]];
    mdJournal('reglage', 'Connexions testées');
    return ['at' => date('Y-m-d H:i:s'), 'blocs' => $blocs];
}

/** POST /metadonnee/sonde */
function wr_md_sonde(): array
{
    return mdSonde();
}

/**
 * POST /metadonnee/erp/essai {shopId?} — un lead d'ESSAI envoyé à l'ERP
 * (`test: true`, « TEST — … » dans le nom, numéro TEST-…), puis relu par la
 * route des statuts : les vraies réponses de l'API, brutes, à l'écran. Il
 * reste dans l'ERP : à supprimer côté panel.
 */
function wr_md_erp_essai(): array
{
    mdTables();
    $reg = mdReglages();
    $b = body();
    $mags = mdMagasins();
    $mag = $mags[(string) ($b['shopId'] ?? '')] ?? (array_values($mags)[0] ?? ['id' => null, 'erpId' => null, 'nom' => '']);
    $mid = 'TEST-' . date('YmdHis');
    $payload = ['meta_lead_id' => $mid, 'test' => true, 'shop_id' => $mag['erpId'] ?? null,
        'source' => ['channel' => 'meta_leadgen', 'platform' => null, 'form_id' => 'TEST', 'form' => 'Essai MétaDonnée (cockpit)', 'page' => '',
            'campaign' => null, 'ad' => null, 'received_at' => date('c')],
        'company' => ['name' => 'TEST — lead d’essai MétaDonnée', 'zip' => '0000'],
        'contact' => ['first_name' => 'Essai', 'last_name' => 'Cockpit', 'phone' => '+32470000000', 'email' => 'essai@exemple.invalid'],
        'lead' => ['headcount' => 1, 'need' => 'other', 'note' => 'Lead d’essai envoyé depuis le cockpit (MétaDonnée) — à supprimer.']];
    [$code, $res] = MdErp::appel('POST', (string) $reg['erp']['routeEnvoi'], $payload);
    [$id, $statut] = mdErpReponse($res);
    $out = ['magasin' => $mag['nom'] ?? null, 'payload' => $payload,
        'envoi' => ['route' => 'POST ' . $reg['erp']['routeEnvoi'], 'code' => $code, 'corps' => $res],
        'lu' => ['id' => $id, 'statut' => $statut, 'statutCockpit' => $statut !== null ? mdStatutErp($statut) : null]];
    if ($code >= 200 && $code < 300 && $id !== null) {
        $route = (string) $reg['erp']['routeStatuts'];
        [$c2, $r2] = MdErp::appel('GET', $route . (str_contains($route, '?') ? '&' : '?') . 'ids=' . rawurlencode($id));
        $trouve = false;
        foreach (mdListe($r2) as $x) { if (is_array($x) && mdErpReponse($x)[0] === $id) { $trouve = true; } }
        $out['relecture'] = ['route' => 'GET ' . $route . '?ids=' . $id, 'code' => $c2, 'corps' => $r2, 'trouve' => $trouve];
    }
    $out['ok'] = $code >= 200 && $code < 300 && $id !== null && !empty($out['relecture']['trouve']);
    mdJournal($out['ok'] ? 'envoi' : 'erreur', 'Lead d’essai ' . $mid . ' → ERP : ' . ($code ?: 'injoignable') . ($id ? ' · ' . $id : ' · aucun identifiant rendu')
        . (isset($out['relecture']) ? ' · relu ' . ($out['relecture']['trouve'] ? 'oui' : 'NON') : ''));
    return $out;
}
