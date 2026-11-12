<?php
declare(strict_types=1);

/**
 * Pilotage › Audit fournisseurs (12/11/2026, demande de Sam) : deux fois par an, les franchisés notent chaque
 * fournisseur et prestataire référencé de la marque (Glace Rawette, TFBuddy, SLFood, Bruyerre, le collectif
 * marketing…). Décisions et maquettes : docs/audits-fournisseurs.md.
 *
 * - UN formulaire : un TRONC COMMUN posé à chaque fournisseur (questions sans fournisseur, « {fournisseur} » dans
 *   le libellé prend son nom) et les questions PROPRES à chacun. Chaque question a un type de réponse et, si elle
 *   est notée, ses points ; une règle (« note ≤ 2 ») peut exiger un commentaire, ouvrir une action ou une alerte ;
 *   une sous-question ne s'affiche que si la réponse parente le dit.
 * - Une CAMPAGNE fige le formulaire à l'envoi. Chaque magasin ouvert reçoit un lien personnel (audit/?d=&jeton=,
 *   sans compte) par e-mail et par notification push ; le franchisé répond SEUL. Relances automatiques par
 *   l'horloge des visites (toutes les 5 min, viHorloge → auHorloge) ; clôture à la date dite.
 * - Les RÉSULTATS se lisent par fournisseur. Chaque fournisseur reçoit une COPIE de ses résultats avec la note du
 *   franchiseur, relue et envoyée à la main ; le nom des boutiques y est masqué par défaut.
 *
 * Les fournisseurs : le référentiel du cockpit, ceo_supplier (on ne crée pas de seconde table de fournisseurs,
 * cf. fournisseurAssure) — trois colonnes y sont ajoutées : audit_ref (référencé pour l'audit), audit_cat, audit_genre.
 * Tables : ceo_audit_question, ceo_audit_campagne, ceo_audit_destinataire, ceo_audit_reponse, ceo_audit_alerte,
 * ceo_audit_copie. Les fonctions sans base (auQuestionNormalise, auPoints, auScore…) : bin/tests_audits.php.
 */

const AU_SCHEMA = 1;
const AU_TYPES = ['note' => 'Note 1 à 5', 'ouinon' => 'Oui / Non', 'choix' => 'Choix unique', 'multi' => 'Choix multiple',
    'nombre' => 'Nombre', 'texte' => 'Texte libre', 'photo' => 'Photo', 'date' => 'Date'];
const AU_GENRES = ['fournisseur' => 'Fournisseur', 'prestataire' => 'Prestataire'];
const AU_SEUILS = ['bon' => 80, 'surveiller' => 60];
const AU_RELANCES = [5, 10, -1];
const AU_STATUTS = ['bon' => 'Bon', 'surveiller' => 'À surveiller', 'revoir' => 'Revoir le contrat'];

/* --- fonctions pures : questions, réponses, points, règles ------------------------------------------------- */

function auNombre(mixed $v): ?float
{
    if ($v === null || $v === '' || is_bool($v) || is_array($v)) { return null; }
    if (is_string($v)) { $v = str_replace([',', ' '], ['.', ''], trim($v)); }
    return is_numeric($v) ? (float) $v : null;
}

/** Un code stable tiré d'un libellé : « Oui, avec réserves » → oui_avec_reserves. */
function auCode(string $libelle): string
{
    $s = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT', $libelle) : $libelle;
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
    return mb_substr($s !== '' ? $s : 'choix', 0, 30);
}

function auConditionNormalise(mixed $c): ?array
{
    if (!is_array($c)) { return null; }
    $op = (string) ($c['op'] ?? '');
    if ($op === 'in') {
        $v = array_values(array_unique(array_filter(array_map('strval', (array) ($c['valeur'] ?? [])), 'strlen')));
        return $v ? ['op' => 'in', 'valeur' => $v] : null;
    }
    if ($op === 'le' || $op === 'ge') {
        $n = auNombre($c['valeur'] ?? null);
        return $n === null ? null : ['op' => $op, 'valeur' => $n];
    }
    return null;
}

/** La règle d'une question : la condition, et ce qu'elle déclenche (commentaire exigé, action, alerte). */
function auRegleNormalise(mixed $r): ?array
{
    $c = auConditionNormalise($r);
    if ($c === null) { return null; }
    $action = mb_substr(trim((string) ($r['action'] ?? '')), 0, 160);
    $c += ['commentaire' => !empty($r['commentaire']), 'action' => $action, 'alerte' => !empty($r['alerte'])];
    return $c['commentaire'] || $c['action'] !== '' || $c['alerte'] ? $c : null;
}

/** Une question telle que la base la garde (reglage JSON) ou que l'écran l'envoie, rendue sûre et complète. */
function auQuestionNormalise(array $q): array
{
    $type = array_key_exists((string) ($q['type'] ?? ''), AU_TYPES) ? (string) $q['type'] : 'note';
    $r = $q['reglage'] ?? [];
    if (is_string($r)) { $r = json_decode($r, true); }
    if (!is_array($r)) { $r = []; }
    foreach (['choix', 'coef', 'paliers', 'unite', 'min', 'max', 'regle', 'si'] as $k) {
        if (array_key_exists($k, $q) && !array_key_exists($k, $r)) { $r[$k] = $q[$k]; }
    }
    $f = trim((string) ($q['fournisseur_id'] ?? $q['fournisseur'] ?? ''));
    $p = $q['parent_id'] ?? $q['parent'] ?? null;
    $out = ['id' => isset($q['id']) ? (int) $q['id'] : null, 'fournisseur' => $f !== '' ? mb_substr($f, 0, 10) : null,
        'parent' => $p !== null && (int) $p > 0 ? (int) $p : null, 'ordre' => (int) ($q['ordre'] ?? 0),
        'libelle' => mb_substr(trim((string) ($q['libelle'] ?? '')), 0, 300), 'aide' => mb_substr(trim((string) ($q['aide'] ?? '')), 0, 300),
        'type' => $type, 'obligatoire' => !array_key_exists('obligatoire', $q) || (bool) $q['obligatoire']];
    if ($type === 'ouinon') {
        $pts = [];
        foreach ((array) ($r['choix'] ?? []) as $c) { if (is_array($c)) { $pts[(string) ($c['code'] ?? '')] = $c['points'] ?? null; } }
        $out['choix'] = [['code' => 'oui', 'libelle' => 'Oui', 'points' => auNombre($pts['oui'] ?? 5) ?? 0.0],
            ['code' => 'non', 'libelle' => 'Non', 'points' => auNombre($pts['non'] ?? 0) ?? 0.0]];
    } elseif ($type === 'choix' || $type === 'multi') {
        $ch = []; $vus = [];
        foreach ((array) ($r['choix'] ?? []) as $c) {
            if (!is_array($c)) { continue; }
            $lib = mb_substr(trim((string) ($c['libelle'] ?? '')), 0, 120);
            if ($lib === '') { continue; }
            $code = (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($c['code'] ?? '')));
            $code = mb_substr($code !== '' ? $code : auCode($lib), 0, 28);
            $base = $code; $i = 2;
            while (isset($vus[$code])) { $code = $base . $i++; }
            $vus[$code] = true;
            $ch[] = ['code' => $code, 'libelle' => $lib, 'points' => $type === 'choix' ? (auNombre($c['points'] ?? null) ?? 0.0) : null];
        }
        $out['choix'] = $ch;
    }
    if ($type === 'note') {
        $out['coef'] = max(0.0, min(20.0, auNombre($r['coef'] ?? 1) ?? 1.0));
        $out['min'] = mb_substr(trim((string) ($r['min'] ?? '')), 0, 60);
        $out['max'] = mb_substr(trim((string) ($r['max'] ?? '')), 0, 60);
    }
    if ($type === 'nombre') {
        $pal = [];
        foreach ((array) ($r['paliers'] ?? []) as $x) {
            if (!is_array($x) || ($pts = auNombre($x['points'] ?? null)) === null) { continue; }
            $pal[] = ['jusqua' => auNombre($x['jusqua'] ?? null), 'points' => $pts];
        }
        // Les bornes croissantes, « au-delà » (sans borne) en dernier.
        usort($pal, static fn ($a, $b) => [$a['jusqua'] === null, $a['jusqua'] ?? 0] <=> [$b['jusqua'] === null, $b['jusqua'] ?? 0]);
        $out['paliers'] = $pal;
        $out['unite'] = mb_substr(trim((string) ($r['unite'] ?? '')), 0, 20);
    }
    $out['si'] = $out['parent'] !== null ? auConditionNormalise($r['si'] ?? null) : null;
    $out['regle'] = auRegleNormalise($r['regle'] ?? null);
    return $out;
}

/** Ce que la base garde dans `reglage` : tout ce qui n'a pas sa colonne. */
function auReglage(array $q): array
{
    return array_diff_key($q, array_flip(['id', 'fournisseur', 'parent', 'ordre', 'libelle', 'aide', 'type', 'obligatoire']));
}

function auNotee(array $q): bool { return auPointsMax($q) !== null; }

/** Le maximum de points d'une question, ou null si elle n'est pas notée. */
function auPointsMax(array $q): ?float
{
    return match ($q['type']) {
        'note' => 5 * (float) ($q['coef'] ?? 1),
        'ouinon', 'choix' => $q['choix'] ? max(array_map(static fn ($c) => (float) $c['points'], $q['choix'])) : null,
        'nombre' => $q['paliers'] ? max(array_map(static fn ($p) => (float) $p['points'], $q['paliers'])) : null,
        default => null,
    };
}

/** Une réponse brute, validée selon le type ; null si elle est vide ou invalide. */
function auValeur(array $q, mixed $v): mixed
{
    switch ($q['type']) {
        case 'note':
            $n = auNombre($v);
            return $n !== null && $n >= 1 && $n <= 5 && floor($n) === $n ? (int) $n : null;
        case 'ouinon':
        case 'choix':
            $codes = array_column($q['choix'] ?? [], 'code');
            return is_string($v) && in_array($v, $codes, true) ? $v : null;
        case 'multi':
            $codes = array_column($q['choix'] ?? [], 'code');
            $l = array_values(array_unique(array_filter((array) $v, static fn ($c) => is_string($c) && in_array($c, $codes, true))));
            return $l ?: null;
        case 'nombre':
            $n = auNombre($v);
            return $n !== null && abs($n) < 1e9 ? $n : null;
        case 'texte':
            $t = is_scalar($v) ? trim((string) $v) : '';
            return $t !== '' ? mb_substr($t, 0, 2000) : null;
        case 'photo':
            return is_string($v) && preg_match('#^uploads/audits/[\w/-]+\.(jpg|png|webp)$#', $v) ? $v : null;
        case 'date':
            if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return null; }
            [$a, $m, $j] = array_map('intval', explode('-', $v));
            return checkdate($m, $j, $a) ? $v : null;
    }
    return null;
}

/** Les points d'une réponse, ou null (question non notée, réponse absente). */
function auPoints(array $q, mixed $valeur): ?float
{
    if ($valeur === null || !auNotee($q)) { return null; }
    if ($q['type'] === 'note') { return round((float) $valeur * (float) $q['coef'], 2); }
    if ($q['type'] === 'ouinon' || $q['type'] === 'choix') {
        foreach ($q['choix'] as $c) { if ($c['code'] === $valeur) { return (float) $c['points']; } }
        return null;
    }
    if ($q['type'] === 'nombre') {
        foreach ($q['paliers'] as $p) { if ($p['jusqua'] === null || (float) $valeur <= $p['jusqua']) { return (float) $p['points']; } }
        return 0.0;   // au-delà du dernier palier borné, sans palier « au-delà »
    }
    return null;
}

/** Une condition (« in », « le », « ge ») sur une réponse. Une réponse absente ne remplit aucune condition. */
function auCondition(?array $c, mixed $valeur): bool
{
    if ($c === null || $valeur === null) { return false; }
    if ($c['op'] === 'in') {
        return is_array($valeur) ? (bool) array_intersect($valeur, $c['valeur']) : in_array((string) $valeur, $c['valeur'], true);
    }
    $n = auNombre($valeur);
    if ($n === null) { return false; }
    return $c['op'] === 'le' ? $n <= (float) $c['valeur'] : $n >= (float) $c['valeur'];
}

/** Une sous-question s'affiche si la réponse parente remplit sa condition (sans condition : si elle est donnée). */
function auVisible(array $q, array $valeurs): bool
{
    if ($q['parent'] === null) { return true; }
    $v = $valeurs[$q['parent']] ?? null;
    return $q['si'] === null ? $v !== null : auCondition($q['si'], $v);
}

function auLibelle(string $libelle, string $fournisseur): string
{
    return str_replace('{fournisseur}', $fournisseur, $libelle);
}

/** Les questions d'un fournisseur, dans l'ordre : le tronc commun, puis les siennes ; chaque sous-question sous sa mère. */
function auQuestionsPour(array $questions, string $fid): array
{
    $l = array_values(array_filter($questions, static fn ($q) => $q['fournisseur'] === null || $q['fournisseur'] === $fid));
    $ids = array_flip(array_map(static fn ($q) => (int) $q['id'], $l));
    $meres = array_values(array_filter($l, static fn ($q) => $q['parent'] === null || !isset($ids[$q['parent']])));
    usort($meres, static fn ($a, $b) => [$a['fournisseur'] !== null, $a['ordre'], $a['id']] <=> [$b['fournisseur'] !== null, $b['ordre'], $b['id']]);
    $out = [];
    foreach ($meres as $m) {
        $out[] = $m;
        $f = array_values(array_filter($l, static fn ($q) => $q['parent'] !== null && $q['parent'] === $m['id']));
        usort($f, static fn ($a, $b) => [$a['ordre'], $a['id']] <=> [$b['ordre'], $b['id']]);
        array_push($out, ...$f);
    }
    return $out;
}

/** Le score d'un fournisseur pour un magasin : les points des questions notées, visibles et répondues. */
function auScore(array $questions, array $valeurs): array
{
    $pts = 0.0; $max = 0.0;
    foreach ($questions as $q) {
        if (!auVisible($q, $valeurs) || ($m = auPointsMax($q)) === null) { continue; }
        $v = $valeurs[$q['id']] ?? null;
        if ($v === null) { continue; }
        $pts += (float) auPoints($q, $v); $max += $m;
    }
    return ['points' => round($pts, 2), 'max' => round($max, 2), 'pct' => $max > 0 ? round($pts / $max * 100, 1) : null];
}

function auStatut(?float $pct, array $seuils = AU_SEUILS): ?string
{
    if ($pct === null) { return null; }
    return $pct >= (float) $seuils['bon'] ? 'bon' : ($pct >= (float) $seuils['surveiller'] ? 'surveiller' : 'revoir');
}

/** Ce qui manque pour terminer : réponse obligatoire absente, commentaire exigé par une règle. */
function auManques(array $questions, array $valeurs, array $commentaires): array
{
    $m = [];
    foreach ($questions as $q) {
        if (!auVisible($q, $valeurs)) { continue; }
        $v = $valeurs[$q['id']] ?? null;
        if ($v === null && $q['obligatoire']) { $m[] = ['question' => $q['id'], 'motif' => 'reponse']; continue; }
        if ($q['regle'] !== null && $q['regle']['commentaire'] && auCondition($q['regle'], $v) && trim((string) ($commentaires[$q['id']] ?? '')) === '') {
            $m[] = ['question' => $q['id'], 'motif' => 'commentaire'];
        }
    }
    return $m;
}

/**
 * La relance est-elle due ? Chaque palier (jours après l'envoi, ou avant la clôture s'il est négatif : -1 = la
 * veille) tombe à une date ; on en doit autant qu'il en est passé, moins celles déjà faites.
 */
function auRelanceDue(array $paliers, string $envoyeLe, ?string $clotureLe, int $faites, string $maintenant): bool
{
    $t = strtotime($maintenant); $env = strtotime($envoyeLe);
    if ($t === false || $env === false) { return false; }
    $dues = 0;
    foreach ($paliers as $n) {
        $n = (int) $n;
        if ($n > 0) { $quand = strtotime('+' . $n . ' days', $env); }
        elseif ($n < 0 && $clotureLe !== null) { $quand = strtotime($clotureLe . ' 09:00:00 ' . $n . ' days'); }
        else { continue; }
        if ($quand !== false && $quand > $env && $quand <= $t) { $dues++; }
    }
    return $dues > $faites;
}

/** La teinte d'une réponse dans la grille (1 à 5), ou null : la note elle-même, sinon la part des points. */
function auTeinte(array $q, mixed $valeur): ?int
{
    if ($valeur === null) { return null; }
    if ($q['type'] === 'note') { return (int) $valeur; }
    $m = auPointsMax($q);
    $p = auPoints($q, $valeur);
    if ($m === null || $m <= 0 || $p === null) { return null; }
    return 1 + (int) round(max(0.0, min(1.0, $p / $m)) * 4);
}

/** Ce qu'une réponse montre en clair. */
function auAffiche(array $q, mixed $valeur): string
{
    if ($valeur === null) { return ''; }
    $lib = static function (string $code) use ($q): string {
        foreach ($q['choix'] ?? [] as $c) { if ($c['code'] === $code) { return $c['libelle']; } }
        return $code;
    };
    return match ($q['type']) {
        'ouinon', 'choix' => $lib((string) $valeur),
        'multi' => implode(', ', array_map($lib, (array) $valeur)),
        'nombre' => rtrim(rtrim(number_format((float) $valeur, 2, ',', ' '), '0'), ',') . (($q['unite'] ?? '') !== '' ? ' ' . $q['unite'] : ''),
        'date' => date('d/m/Y', (int) strtotime((string) $valeur)),
        'photo' => 'photo',
        default => (string) $valeur,
    };
}

/** Les questions livrées : le tronc commun décidé le 12/11/2026 (continuer ? · prix · délais et réactivité). */
function auTroncDefaut(): array
{
    return [
        ['libelle' => 'Souhaitez-vous continuer avec {fournisseur} ?', 'type' => 'choix',
            'choix' => [['code' => 'oui', 'libelle' => 'Oui', 'points' => 5], ['code' => 'reserves', 'libelle' => 'Oui, avec réserves', 'points' => 2], ['code' => 'non', 'libelle' => 'Non', 'points' => 0]],
            'regle' => ['op' => 'in', 'valeur' => ['non'], 'alerte' => true, 'action' => 'Fournisseur remis en question'],
            'sous' => ['libelle' => 'Pourquoi ? Avez-vous une autre piste ?', 'type' => 'texte', 'si' => ['op' => 'in', 'valeur' => ['reserves', 'non']]]],
        ['libelle' => 'Les prix de {fournisseur} sont-ils corrects ?', 'type' => 'note', 'coef' => 1, 'min' => 'Trop chers', 'max' => 'Très corrects',
            'regle' => ['op' => 'le', 'valeur' => 2, 'commentaire' => true, 'action' => 'Revoir les prix avec {fournisseur}']],
        ['libelle' => 'Respect des délais et réactivité', 'type' => 'note', 'coef' => 1, 'min' => 'Très mauvais', 'max' => 'Excellent',
            'regle' => ['op' => 'le', 'valeur' => 2, 'commentaire' => true, 'action' => 'Point délais avec {fournisseur}']],
    ];
}

/* --- les mots du franchisé (courriels), dans la langue du magasin ------------------------------------------- */

const AU_MOTS = [
    'fr' => ['sujet' => 'Votre avis sur nos fournisseurs — {campagne}', 'relance' => 'Rappel : votre avis sur nos fournisseurs, avant le {date}',
        'bonjour' => 'Bonjour {magasin},', 'intro' => 'Deux fois par an, nous vous demandons votre avis sur les fournisseurs et prestataires du réseau : {liste}.',
        'rappel' => 'Votre avis compte : il reste peu de temps pour noter les fournisseurs du réseau.', 'bouton' => 'Donner mon avis',
        'fin' => 'Votre lien personnel, sans mot de passe, est valable jusqu’au {date}. Vous pouvez vous arrêter et reprendre plus tard. Chaque fournisseur reçoit une synthèse de ses résultats, sans le nom des boutiques.',
        'push' => '{n} fournisseurs à noter, avant le {date}'],
    'nl' => ['sujet' => 'Uw mening over onze leveranciers — {campagne}', 'relance' => 'Herinnering: uw mening over onze leveranciers, vóór {date}',
        'bonjour' => 'Beste {magasin},', 'intro' => 'Twee keer per jaar vragen we uw mening over de leveranciers en dienstverleners van het netwerk: {liste}.',
        'rappel' => 'Uw mening telt: er blijft weinig tijd over om de leveranciers van het netwerk te beoordelen.', 'bouton' => 'Mijn mening geven',
        'fin' => 'Uw persoonlijke link, zonder wachtwoord, is geldig tot {date}. U kunt stoppen en later verdergaan. Elke leverancier ontvangt een samenvatting van zijn resultaten, zonder de naam van de winkels.',
        'push' => '{n} leveranciers te beoordelen, vóór {date}'],
    'en' => ['sujet' => 'Your view on our suppliers — {campagne}', 'relance' => 'Reminder: your view on our suppliers, before {date}',
        'bonjour' => 'Hello {magasin},', 'intro' => 'Twice a year we ask for your view on the network’s suppliers and service providers: {liste}.',
        'rappel' => 'Your view matters: there is little time left to rate the network’s suppliers.', 'bouton' => 'Give my view',
        'fin' => 'Your personal link, no password needed, is valid until {date}. You can stop and continue later. Each supplier receives a summary of its results, without shop names.',
        'push' => '{n} suppliers to rate, before {date}'],
    'de' => ['sujet' => 'Ihre Meinung zu unseren Lieferanten — {campagne}', 'relance' => 'Erinnerung: Ihre Meinung zu unseren Lieferanten, bis {date}',
        'bonjour' => 'Hallo {magasin},', 'intro' => 'Zweimal im Jahr fragen wir Sie nach Ihrer Meinung zu den Lieferanten und Dienstleistern des Netzwerks: {liste}.',
        'rappel' => 'Ihre Meinung zählt: Es bleibt nur noch wenig Zeit, die Lieferanten des Netzwerks zu bewerten.', 'bouton' => 'Meine Meinung abgeben',
        'fin' => 'Ihr persönlicher Link, ohne Passwort, ist gültig bis {date}. Sie können jederzeit unterbrechen und später fortfahren. Jeder Lieferant erhält eine Zusammenfassung seiner Ergebnisse, ohne die Namen der Geschäfte.',
        'push' => '{n} Lieferanten zu bewerten, bis {date}'],
];

function auMot(string $cle, string $l, array $vars = []): string
{
    $t = AU_MOTS[$l][$cle] ?? AU_MOTS['fr'][$cle];
    foreach ($vars as $k => $v) { $t = str_replace('{' . $k . '}', (string) $v, $t); }
    return $t;
}

/* --- schéma ------------------------------------------------------------------------------------------------- */

function auColonne(string $table, string $def): void
{
    if (function_exists('suColonne')) { suColonne($table, $def); return; }
    try { Db::exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $def); } catch (Throwable $e) { /* déjà là */ }
}

function auSettingSet(string $cle, mixed $valeur): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($valeur, JSON_UNESCAPED_UNICODE)]);
}

function ensureAudits(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    if ((int) setting('auditsSchema', 0) >= AU_SCHEMA) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_audit_question (
        id INT AUTO_INCREMENT PRIMARY KEY, fournisseur_id VARCHAR(10) NULL, parent_id INT NULL, ordre INT NOT NULL DEFAULT 0,
        libelle VARCHAR(300) NOT NULL, aide VARCHAR(300) NULL, type VARCHAR(12) NOT NULL, reglage TEXT NULL,
        obligatoire TINYINT NOT NULL DEFAULT 1, actif TINYINT NOT NULL DEFAULT 1, maj_le DATETIME NOT NULL,
        KEY (fournisseur_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_audit_campagne (
        id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(120) NOT NULL, statut VARCHAR(12) NOT NULL DEFAULT \'brouillon\',
        cloture_le DATE NULL, relances VARCHAR(60) NULL, push TINYINT NOT NULL DEFAULT 1, message TEXT NULL,
        formulaire MEDIUMTEXT NULL, base VARCHAR(255) NULL, cree_le DATETIME NOT NULL, envoye_le DATETIME NULL,
        cloturee_le DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_audit_destinataire (
        id INT AUTO_INCREMENT PRIMARY KEY, campagne_id INT NOT NULL, shop_id VARCHAR(20) NOT NULL, email VARCHAR(160) NULL,
        envoye_le DATETIME NULL, envoi_motif VARCHAR(255) NULL, push TINYINT NOT NULL DEFAULT 0, ouvert_le DATETIME NULL,
        termine_le DATETIME NULL, relances INT NOT NULL DEFAULT 0, relance_le DATETIME NULL,
        UNIQUE KEY campagne_shop (campagne_id, shop_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_audit_reponse (
        id INT AUTO_INCREMENT PRIMARY KEY, destinataire_id INT NOT NULL, fournisseur_id VARCHAR(10) NOT NULL, question_id INT NOT NULL,
        valeur TEXT NULL, points DECIMAL(8,2) NULL, commentaire TEXT NULL, maj_le DATETIME NOT NULL,
        UNIQUE KEY dest_four_q (destinataire_id, fournisseur_id, question_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_audit_alerte (
        id INT AUTO_INCREMENT PRIMARY KEY, campagne_id INT NOT NULL, destinataire_id INT NOT NULL, shop_id VARCHAR(20) NOT NULL,
        fournisseur_id VARCHAR(10) NOT NULL, question_id INT NOT NULL, titre VARCHAR(200) NOT NULL, alerte TINYINT NOT NULL DEFAULT 0,
        statut VARCHAR(12) NOT NULL DEFAULT \'ouvert\', cree_le DATETIME NOT NULL, maj_le DATETIME NULL,
        UNIQUE KEY dest_four_q (destinataire_id, fournisseur_id, question_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_audit_copie (
        id INT AUTO_INCREMENT PRIMARY KEY, campagne_id INT NOT NULL, fournisseur_id VARCHAR(10) NOT NULL, note TEXT NULL,
        reglage TEXT NULL, email VARCHAR(160) NULL, statut VARCHAR(12) NOT NULL DEFAULT \'a_ecrire\', envoye_le DATETIME NULL,
        envoi_motif VARCHAR(255) NULL, maj_le DATETIME NOT NULL,
        UNIQUE KEY campagne_four (campagne_id, fournisseur_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    foreach (['audit_ref TINYINT NOT NULL DEFAULT 0', 'audit_cat VARCHAR(80) NULL', 'audit_genre VARCHAR(12) NULL'] as $c) { auColonne('ceo_supplier', $c); }
    // Le secret des liens du franchisé : un par installation, jamais montré.
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value', ['auditsJeton', json_encode(bin2hex(random_bytes(24)))]);
    if ((int) (Db::row('SELECT COUNT(*) AS n FROM ceo_audit_question')['n'] ?? 0) === 0) {
        foreach (auTroncDefaut() as $i => $d) {
            $mere = auQuestionInserer($d + ['ordre' => ($i + 1) * 10]);
            if (isset($d['sous'])) { auQuestionInserer($d['sous'] + ['parent' => $mere, 'ordre' => 10]); }
        }
    }
    auSettingSet('auditsSchema', AU_SCHEMA);
}

function auQuestionInserer(array $brut): int
{
    $q = auQuestionNormalise($brut);
    Db::exec('INSERT INTO ceo_audit_question (fournisseur_id, parent_id, ordre, libelle, aide, type, reglage, obligatoire, actif, maj_le) VALUES (?,?,?,?,?,?,?,?,1,?)',
        [$q['fournisseur'], $q['parent'], $q['ordre'], $q['libelle'], $q['aide'] !== '' ? $q['aide'] : null, $q['type'],
         json_encode(auReglage($q), JSON_UNESCAPED_UNICODE), $q['obligatoire'] ? 1 : 0, date('Y-m-d H:i:s')]);
    return (int) Db::pdo()->lastInsertId();
}

/* --- lectures ----------------------------------------------------------------------------------------------- */

function auSeuils(): array
{
    $s = setting('auditsSeuils', []);
    $s = is_array($s) ? $s + AU_SEUILS : AU_SEUILS;
    return ['bon' => (float) $s['bon'], 'surveiller' => (float) $s['surveiller']];
}

/** Les fournisseurs du référentiel, l'adresse résolue (fiche, sinon le carnet de la centrale d'achat, sinon le panel). */
function auFournisseurs(bool $seulsReferences = false): array
{
    $carnet = [];
    try { if (function_exists('caMailCarnet')) { $carnet = caMailCarnet(); } } catch (Throwable $e) { $carnet = []; }
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_supplier' . ($seulsReferences ? ' WHERE audit_ref = 1' : '') . ' ORDER BY name') as $s) {
        $mail = trim((string) $s['email']); $source = $mail !== '' ? 'fiche' : null;
        if ($mail === '' && function_exists('caMailAdresse')) {
            try { $mail = caMailAdresse((string) $s['name'], $carnet); $source = $mail !== '' ? 'carnet' : null; } catch (Throwable $e) { $mail = ''; }
        }
        $out[] = ['id' => (string) $s['id'], 'nom' => (string) $s['name'], 'perimetre' => (string) ($s['perimeter'] ?? ''),
            'ref' => (int) ($s['audit_ref'] ?? 0) === 1, 'cat' => (string) ($s['audit_cat'] ?? ''), 'genre' => (string) ($s['audit_genre'] ?? '') ?: 'fournisseur',
            'email' => $mail !== '' ? $mail : null, 'emailSource' => $source];
    }
    return $out;
}

function auQuestions(): array
{
    return array_map('auQuestionNormalise', Db::rows('SELECT * FROM ceo_audit_question WHERE actif = 1 ORDER BY ordre, id'));
}

function auCampagne(int $id): ?array
{
    $c = Db::row('SELECT * FROM ceo_audit_campagne WHERE id = ?', [$id]);
    if ($c === null) { return null; }
    $c['formulaire'] = suJsonAu($c['formulaire']);
    $r = suJsonAu($c['relances']);
    $c['relances'] = $r ?: AU_RELANCES;
    return $c;
}

function suJsonAu(mixed $s): array
{
    if (is_array($s)) { return $s; }
    $d = is_string($s) && $s !== '' ? json_decode($s, true) : null;
    return is_array($d) ? $d : [];
}

/** Le formulaire tel qu'il part : les fournisseurs référencés et toutes les questions, figés dans la campagne. */
function auFormulaireCourant(): array
{
    $f = array_map(static fn ($x) => ['id' => $x['id'], 'nom' => $x['nom'], 'cat' => $x['cat'], 'genre' => $x['genre']], auFournisseurs(true));
    $ids = array_column($f, 'id');
    $q = array_values(array_filter(auQuestions(), static fn ($x) => $x['fournisseur'] === null || in_array($x['fournisseur'], $ids, true)));
    return ['fournisseurs' => $f, 'questions' => $q, 'seuils' => auSeuils()];
}

/** Les réponses d'un destinataire : [fournisseur][question] => valeur, commentaire, points, id. */
function auReponses(int $destId): array
{
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_audit_reponse WHERE destinataire_id = ?', [$destId]) as $r) {
        $out[(string) $r['fournisseur_id']][(int) $r['question_id']] = ['id' => (int) $r['id'], 'valeur' => json_decode((string) $r['valeur'], true),
            'commentaire' => (string) ($r['commentaire'] ?? ''), 'points' => $r['points'] !== null ? (float) $r['points'] : null];
    }
    return $out;
}

function auMagasinCourt(string $shop): string
{
    return function_exists('suCourt') ? suCourt($shop) : (viMagasins()[$shop]['court'] ?? $shop);
}

function auLangue(string $shop): string
{
    try { $l = function_exists('suLangue') ? suLangue($shop) : 'fr'; } catch (Throwable $e) { $l = 'fr'; }
    return isset(AU_MOTS[$l]) ? $l : 'fr';
}

/* --- le lien du franchisé ---------------------------------------------------------------------------------- */

function auJeton(int $destId): string
{
    return substr(hash_hmac('sha256', 'audit:' . $destId, (string) setting('auditsJeton', '')), 0, 32);
}

function auLien(array $camp, int $destId): string
{
    $base = trim((string) ($camp['base'] ?? '')) ?: rapBaseUrl();
    return rtrim($base, '/') . '/audit/?d=' . $destId . '&jeton=' . auJeton($destId);
}

/** Le destinataire et sa campagne, si le lien est bon. */
function auParLien(array $src): ?array
{
    $id = (int) ($src['d'] ?? 0); $jeton = (string) ($src['jeton'] ?? '');
    if ($id <= 0 || $jeton === '' || (string) setting('auditsJeton', '') === '' || !hash_equals(auJeton($id), $jeton)) { return null; }
    $d = Db::row('SELECT * FROM ceo_audit_destinataire WHERE id = ?', [$id]);
    if ($d === null) { return null; }
    $c = auCampagne((int) $d['campagne_id']);
    return $c === null || $c['statut'] === 'brouillon' ? null : ['dest' => $d, 'camp' => $c];
}

function auOuverte(array $camp): bool
{
    return $camp['statut'] === 'envoyee' && ($camp['cloture_le'] === null || date('Y-m-d') <= (string) $camp['cloture_le']);
}

/** Ce que voit le franchisé : chaque fournisseur, ses questions (sans les points), ses réponses déjà données. */
function auVueFranchise(array $form, array $reponses): array
{
    $four = [];
    foreach ($form['fournisseurs'] as $f) {
        $qs = auQuestionsPour($form['questions'], $f['id']);
        $rep = $reponses[$f['id']] ?? [];
        $valeurs = array_map(static fn ($r) => $r['valeur'], $rep);
        $questions = array_map(static fn ($q) => [
            'id' => $q['id'], 'parent' => $q['parent'], 'type' => $q['type'], 'obligatoire' => $q['obligatoire'],
            'libelle' => auLibelle($q['libelle'], $f['nom']), 'aide' => auLibelle($q['aide'], $f['nom']),
            'choix' => array_map(static fn ($c) => ['code' => $c['code'], 'libelle' => $c['libelle']], $q['choix'] ?? []),
            'min' => $q['min'] ?? '', 'max' => $q['max'] ?? '', 'unite' => $q['unite'] ?? '', 'si' => $q['si'],
            'commentaireSi' => $q['regle'] !== null && $q['regle']['commentaire'] ? array_intersect_key($q['regle'], ['op' => 1, 'valeur' => 1]) : null,
        ], $qs);
        $manques = auManques($qs, $valeurs, array_map(static fn ($r) => $r['commentaire'], $rep));
        $four[] = ['id' => $f['id'], 'nom' => $f['nom'], 'cat' => $f['cat'], 'questions' => $questions,
            'reponses' => (object) array_map(static fn ($r) => ['valeur' => $r['valeur'], 'commentaire' => $r['commentaire']], $rep),
            'fini' => $rep !== [] && $manques === []];
    }
    return $four;
}

/* --- routes publiques : la page du franchisé (audit/), sans session ---------------------------------------- */

function auRoutePublique(string $method, string $path): ?array
{
    ensureAudits();
    if ($method === 'GET' && $path === '/audits/public') { return ep_audit_public(); }
    if ($method === 'POST' && $path === '/audits/public/section') { return wr_audit_public_section(); }
    if ($method === 'POST' && $path === '/audits/public/terminer') { return wr_audit_public_terminer(); }
    if ($method === 'POST' && $path === '/audits/public/photo') { return wr_audit_public_photo(); }
    return null;
}

function auLienRefuse(): array
{
    http_response_code(403);
    return ['error' => 'lien', 'message' => 'Ce lien n’est pas (ou plus) valable. Demandez un nouveau lien au siège.'];
}

/** GET /audits/public?d=&jeton= */
function ep_audit_public(): array
{
    $x = auParLien($_GET);
    if ($x === null) { return auLienRefuse(); }
    ['dest' => $d, 'camp' => $c] = $x;
    if ($d['ouvert_le'] === null) { Db::exec('UPDATE ceo_audit_destinataire SET ouvert_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $d['id']]); }
    $shop = (string) $d['shop_id'];
    return ['campagne' => ['nom' => $c['nom'], 'cloture' => $c['cloture_le'], 'ouverte' => auOuverte($c)],
        'magasin' => ['id' => $shop, 'court' => auMagasinCourt($shop)], 'langue' => auLangue($shop),
        'termine' => $d['termine_le'], 'fournisseurs' => auVueFranchise($c['formulaire'], auReponses((int) $d['id']))];
}

/** POST /audits/public/section?d=&jeton= — les réponses d'un fournisseur, enregistrées à chaque écran. */
function wr_audit_public_section(): array
{
    $x = auParLien($_GET);
    if ($x === null) { return auLienRefuse(); }
    ['dest' => $d, 'camp' => $c] = $x;
    if (!auOuverte($c)) { http_response_code(409); return ['error' => 'clos', 'message' => 'Cet audit est clos : les réponses ne sont plus modifiables.']; }
    $b = body();
    $fid = (string) ($b['fournisseur'] ?? '');
    $f = null;
    foreach ($c['formulaire']['fournisseurs'] ?? [] as $x2) { if ($x2['id'] === $fid) { $f = $x2; } }
    if ($f === null) { http_response_code(422); return ['error' => 'fournisseur inconnu']; }
    $qs = auQuestionsPour($c['formulaire']['questions'] ?? [], $fid);
    $parId = array_column($qs, null, 'id');
    $n = 0; $now = date('Y-m-d H:i:s');
    foreach ((array) ($b['reponses'] ?? []) as $r) {
        if (!is_array($r) || !isset($parId[(int) ($r['question'] ?? 0)])) { continue; }
        $q = $parId[(int) $r['question']];
        $v = auValeur($q, $r['valeur'] ?? null);
        $com = mb_substr(trim((string) ($r['commentaire'] ?? '')), 0, 2000);
        Db::exec('INSERT INTO ceo_audit_reponse (destinataire_id, fournisseur_id, question_id, valeur, points, commentaire, maj_le) VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), points = VALUES(points), commentaire = VALUES(commentaire), maj_le = VALUES(maj_le)',
            [(int) $d['id'], $fid, (int) $q['id'], json_encode($v, JSON_UNESCAPED_UNICODE), auPoints($q, $v), $com !== '' ? $com : null, $now]);
        $n++;
    }
    if ($d['termine_le'] !== null) { auAlertesEvaluer($c, $d, $fid); }
    $rep = auReponses((int) $d['id'])[$fid] ?? [];
    $valeurs = array_map(static fn ($r) => $r['valeur'], $rep);
    return ['ok' => true, 'enregistre' => $n, 'manques' => auManques($qs, $valeurs, array_map(static fn ($r) => $r['commentaire'], $rep))];
}

/** POST /audits/public/terminer?d=&jeton= — tout est répondu : l'audit du magasin est terminé, les règles jouent. */
function wr_audit_public_terminer(): array
{
    $x = auParLien($_GET);
    if ($x === null) { return auLienRefuse(); }
    ['dest' => $d, 'camp' => $c] = $x;
    if (!auOuverte($c)) { http_response_code(409); return ['error' => 'clos', 'message' => 'Cet audit est clos.']; }
    $rep = auReponses((int) $d['id']); $manques = [];
    foreach ($c['formulaire']['fournisseurs'] ?? [] as $f) {
        $qs = auQuestionsPour($c['formulaire']['questions'] ?? [], $f['id']);
        $r = $rep[$f['id']] ?? [];
        $m = auManques($qs, array_map(static fn ($y) => $y['valeur'], $r), array_map(static fn ($y) => $y['commentaire'], $r));
        if ($m) { $manques[] = ['fournisseur' => $f['id'], 'nom' => $f['nom'], 'manques' => $m]; }
    }
    if ($manques) { http_response_code(422); return ['error' => 'incomplet', 'manques' => $manques]; }
    $premier = $d['termine_le'] === null;
    Db::exec('UPDATE ceo_audit_destinataire SET termine_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $d['id']]);
    foreach ($c['formulaire']['fournisseurs'] ?? [] as $f) { auAlertesEvaluer($c, $d, $f['id']); }
    if ($premier) {
        $court = auMagasinCourt((string) $d['shop_id']);
        if (function_exists('viNotifier')) { viNotifier('admin', 'Audit fournisseurs — ' . $court . ' a répondu', $c['nom'], '#/audits', 'audit-' . $c['id']); }
        journalAdd('Franchisé', 'Audit fournisseurs', $c['nom'], $court . ' a terminé son audit');
    }
    return ['ok' => true, 'termine' => date('Y-m-d H:i:s')];
}

/** POST /audits/public/photo?d=&jeton= — une photo (data-URL, réduite par la page), rangée sous uploads/audits/. */
function wr_audit_public_photo(): array
{
    $x = auParLien($_GET);
    if ($x === null) { return auLienRefuse(); }
    ['dest' => $d, 'camp' => $c] = $x;
    if (!auOuverte($c)) { http_response_code(409); return ['error' => 'clos']; }
    $data = (string) (body()['data'] ?? '');
    if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) { http_response_code(422); return ['error' => 'image illisible (data-URL attendue)']; }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 64) { http_response_code(422); return ['error' => 'image illisible']; }
    if (strlen($bin) > 2 * 1024 * 1024) { http_response_code(413); return ['error' => 'photo trop lourde — 2 Mo au maximum']; }
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) { http_response_code(415); return ['error' => 'format non accepté — JPEG, PNG ou WebP']; }
    $rel = 'uploads/audits/' . (int) $c['id'] . '/' . (int) $d['id'];
    $dossier = __DIR__ . '/../public/' . $rel;
    if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) { http_response_code(500); return ['error' => 'dossier des photos impossible à créer']; }
    $nom = date('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (@file_put_contents($dossier . '/' . $nom, $bin) === false) { http_response_code(500); return ['error' => 'écriture de la photo impossible']; }
    return ['ok' => true, 'chemin' => $rel . '/' . $nom];
}

/** Les règles d'un fournisseur pour un magasin : une alerte (ou action) par question dont la condition est remplie. */
function auAlertesEvaluer(array $c, array $d, string $fid): void
{
    $f = null;
    foreach ($c['formulaire']['fournisseurs'] ?? [] as $x) { if ($x['id'] === $fid) { $f = $x; } }
    if ($f === null) { return; }
    $qs = auQuestionsPour($c['formulaire']['questions'] ?? [], $fid);
    $rep = auReponses((int) $d['id'])[$fid] ?? [];
    $valeurs = array_map(static fn ($r) => $r['valeur'], $rep);
    $now = date('Y-m-d H:i:s');
    foreach ($qs as $q) {
        if ($q['regle'] === null || ($q['regle']['action'] === '' && !$q['regle']['alerte'])) { continue; }
        $oui = auVisible($q, $valeurs) && auCondition($q['regle'], $valeurs[$q['id']] ?? null);
        if ($oui) {
            $titre = auLibelle($q['regle']['action'] !== '' ? $q['regle']['action'] : 'Alerte : ' . $q['libelle'], $f['nom']);
            Db::exec('INSERT INTO ceo_audit_alerte (campagne_id, destinataire_id, shop_id, fournisseur_id, question_id, titre, alerte, statut, cree_le) VALUES (?,?,?,?,?,?,?,\'ouvert\',?)
                ON DUPLICATE KEY UPDATE titre = VALUES(titre), alerte = VALUES(alerte)',
                [(int) $c['id'], (int) $d['id'], (string) $d['shop_id'], $fid, (int) $q['id'], mb_substr($titre, 0, 200), $q['regle']['alerte'] ? 1 : 0, $now]);
            if ($q['regle']['alerte'] && function_exists('viNotifier')) {
                viNotifier('admin', 'Audit — ' . $f['nom'], auMagasinCourt((string) $d['shop_id']) . ' : ' . $titre, '#/audits', 'audit-alerte-' . $d['id'] . '-' . $q['id']);
            }
        } else {
            // La réponse a changé : l'alerte encore ouverte n'a plus lieu d'être.
            Db::exec('DELETE FROM ceo_audit_alerte WHERE destinataire_id = ? AND fournisseur_id = ? AND question_id = ? AND statut = \'ouvert\'', [(int) $d['id'], $fid, (int) $q['id']]);
        }
    }
}

/* --- routes du cockpit : Pilotage › Audit fournisseurs ------------------------------------------------------ */

function auRoute(string $method, string $path): mixed
{
    ensureAudits();
    $m = [];
    return match (true) {
        $method === 'GET' && $path === '/audits/etat' => ep_audits_etat(),
        $method === 'GET' && $path === '/audits/apercu' => ep_audits_apercu(),
        $method === 'POST' && $path === '/audits/fournisseurs' => wr_audits_fournisseur(null),
        $method === 'PUT' && preg_match('#^/audits/fournisseurs/([\w-]{1,10})$#', $path, $m) === 1 => wr_audits_fournisseur($m[1]),
        $method === 'POST' && $path === '/audits/questions' => wr_audits_question(null),
        $method === 'POST' && $path === '/audits/questions/ordre' => wr_audits_questions_ordre(),
        $method === 'PUT' && preg_match('#^/audits/questions/(\d{1,10})$#', $path, $m) === 1 => wr_audits_question((int) $m[1]),
        $method === 'DELETE' && preg_match('#^/audits/questions/(\d{1,10})$#', $path, $m) === 1 => wr_audits_question_suppr((int) $m[1]),
        $method === 'PUT' && $path === '/audits/seuils' => wr_audits_seuils(),
        $method === 'POST' && $path === '/audits/campagnes' => wr_audits_campagne(null),
        $method === 'GET' && preg_match('#^/audits/campagnes/(\d{1,10})$#', $path, $m) === 1 => ep_audits_campagne((int) $m[1]),
        $method === 'PUT' && preg_match('#^/audits/campagnes/(\d{1,10})$#', $path, $m) === 1 => wr_audits_campagne((int) $m[1]),
        $method === 'DELETE' && preg_match('#^/audits/campagnes/(\d{1,10})$#', $path, $m) === 1 => wr_audits_campagne_suppr((int) $m[1]),
        $method === 'POST' && preg_match('#^/audits/campagnes/(\d{1,10})/envoyer$#', $path, $m) === 1 => wr_audits_envoyer((int) $m[1]),
        $method === 'POST' && preg_match('#^/audits/campagnes/(\d{1,10})/relancer$#', $path, $m) === 1 => wr_audits_relancer((int) $m[1]),
        $method === 'POST' && preg_match('#^/audits/campagnes/(\d{1,10})/cloturer$#', $path, $m) === 1 => wr_audits_cloturer((int) $m[1]),
        $method === 'GET' && preg_match('#^/audits/campagnes/(\d{1,10})/resultats$#', $path, $m) === 1 => ep_audits_resultats((int) $m[1]),
        $method === 'PUT' && preg_match('#^/audits/alertes/(\d{1,10})$#', $path, $m) === 1 => wr_audits_alerte((int) $m[1]),
        $method === 'PUT' && preg_match('#^/audits/copies/(\d{1,10})/([\w-]{1,10})$#', $path, $m) === 1 => wr_audits_copie((int) $m[1], $m[2]),
        $method === 'GET' && preg_match('#^/audits/copies/(\d{1,10})/([\w-]{1,10})/apercu$#', $path, $m) === 1 => ep_audits_copie_apercu((int) $m[1], $m[2]),
        $method === 'POST' && preg_match('#^/audits/copies/(\d{1,10})/([\w-]{1,10})/envoyer$#', $path, $m) === 1 => wr_audits_copie_envoyer((int) $m[1], $m[2]),
        default => notFound(),
    };
}

/** Une campagne en une ligne de liste : ses compteurs. */
function auCampagneLigne(array $c): array
{
    $n = Db::row('SELECT COUNT(*) AS n, SUM(termine_le IS NOT NULL) AS fini, SUM(ouvert_le IS NOT NULL AND termine_le IS NULL) AS encours,
        SUM(ouvert_le IS NULL) AS jamais, SUM(envoye_le IS NULL) AS nonenv FROM ceo_audit_destinataire WHERE campagne_id = ?', [(int) $c['id']]) ?? [];
    return ['id' => (int) $c['id'], 'nom' => $c['nom'], 'statut' => $c['statut'], 'cloture' => $c['cloture_le'], 'relances' => $c['relances'],
        'push' => (int) $c['push'] === 1, 'message' => (string) ($c['message'] ?? ''), 'creeLe' => $c['cree_le'], 'envoyeLe' => $c['envoye_le'], 'clotureeLe' => $c['cloturee_le'],
        'fournisseurs' => count($c['formulaire']['fournisseurs'] ?? []),
        'compte' => ['total' => (int) ($n['n'] ?? 0), 'fini' => (int) ($n['fini'] ?? 0), 'encours' => (int) ($n['encours'] ?? 0), 'jamais' => (int) ($n['jamais'] ?? 0), 'nonEnvoye' => (int) ($n['nonenv'] ?? 0)]];
}

/** GET /audits/etat — le référentiel, le formulaire, les campagnes. */
function ep_audits_etat(): array
{
    $camps = array_map(static fn ($c) => auCampagneLigne(auCampagne((int) $c['id'])), Db::rows('SELECT id FROM ceo_audit_campagne ORDER BY id DESC'));
    return ['fournisseurs' => auFournisseurs(), 'questions' => auQuestions(), 'types' => AU_TYPES, 'genres' => AU_GENRES, 'seuils' => auSeuils(),
        'statuts' => AU_STATUTS, 'relancesDefaut' => AU_RELANCES, 'campagnes' => $camps,
        'smtp' => class_exists('Smtp') && Smtp::configured(), 'magasins' => count(viMagasins())];
}

/** GET /audits/apercu[?c=] — la page du franchisé telle qu'elle sera (ou est) : sans magasin, sans écriture. */
function ep_audits_apercu(): array
{
    $c = !empty($_GET['c']) ? auCampagne((int) $_GET['c']) : null;
    $form = $c !== null && $c['statut'] !== 'brouillon' ? $c['formulaire'] : auFormulaireCourant();
    return ['apercu' => true, 'campagne' => ['nom' => $c['nom'] ?? 'Aperçu', 'cloture' => $c['cloture_le'] ?? null, 'ouverte' => true],
        'magasin' => ['id' => '', 'court' => 'Aperçu'], 'langue' => 'fr', 'termine' => null, 'fournisseurs' => auVueFranchise($form, [])];
}

/** POST /audits/fournisseurs · PUT /audits/fournisseurs/{id} — référencer, classer, l'adresse de la copie. */
function wr_audits_fournisseur(?string $id): array
{
    $b = body();
    $cat = mb_substr(trim((string) ($b['cat'] ?? '')), 0, 80);
    $genre = array_key_exists((string) ($b['genre'] ?? ''), AU_GENRES) ? (string) $b['genre'] : 'fournisseur';
    $mail = trim((string) ($b['email'] ?? ''));
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'adresse e-mail invalide']; }
    if ($id === null) {
        $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 120);
        if ($nom === '') { http_response_code(422); return ['error' => 'nom du fournisseur manquant']; }
        $dej = Db::row('SELECT id FROM ceo_supplier WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))', [$nom]);
        $id = $dej['id'] ?? ('f' . bin2hex(random_bytes(4)));
        if ($dej === null) {
            Db::exec('INSERT INTO ceo_supplier (id, name, perimeter, email) VALUES (?,?,?,?)', [$id, $nom, $cat !== '' ? $cat : null, $mail]);
        }
        Db::exec('UPDATE ceo_supplier SET audit_ref = 1, audit_cat = ?, audit_genre = ?' . ($mail !== '' ? ', email = ?' : '') . ' WHERE id = ?',
            array_merge([$cat !== '' ? $cat : null, $genre], $mail !== '' ? [$mail] : [], [$id]));
        journalAdd('CEO', 'Audit fournisseurs', $nom, 'Fournisseur référencé pour l’audit');
        return ['ok' => true, 'id' => $id];
    }
    $s = Db::row('SELECT * FROM ceo_supplier WHERE id = ?', [$id]);
    if ($s === null) { http_response_code(404); return ['error' => 'fournisseur inconnu']; }
    $ref = array_key_exists('ref', $b) ? ((bool) $b['ref'] ? 1 : 0) : (int) ($s['audit_ref'] ?? 0);
    Db::exec('UPDATE ceo_supplier SET audit_ref = ?, audit_cat = ?, audit_genre = ?, email = ? WHERE id = ?',
        [$ref, array_key_exists('cat', $b) ? ($cat !== '' ? $cat : null) : $s['audit_cat'], array_key_exists('genre', $b) ? $genre : ($s['audit_genre'] ?? null),
         array_key_exists('email', $b) ? $mail : (string) $s['email'], $id]);
    journalAdd('CEO', 'Audit fournisseurs', (string) $s['name'], $ref ? 'Fournisseur référencé pour l’audit (fiche mise à jour)' : 'Fournisseur retiré de l’audit');
    return ['ok' => true, 'id' => $id];
}

/** POST /audits/questions · PUT /audits/questions/{id} */
function wr_audits_question(?int $id): array
{
    $b = body();
    $q = auQuestionNormalise($b);
    if ($q['libelle'] === '') { http_response_code(422); return ['error' => 'libellé manquant']; }
    if (in_array($q['type'], ['choix', 'multi'], true) && count($q['choix']) < 2) { http_response_code(422); return ['error' => 'au moins deux choix'] ; }
    if ($q['fournisseur'] !== null && Db::row('SELECT id FROM ceo_supplier WHERE id = ?', [$q['fournisseur']]) === null) { http_response_code(422); return ['error' => 'fournisseur inconnu']; }
    if ($q['parent'] !== null) {
        $p = Db::row('SELECT * FROM ceo_audit_question WHERE id = ? AND actif = 1', [$q['parent']]);
        if ($p === null || $p['parent_id'] !== null) { http_response_code(422); return ['error' => 'question mère inconnue (un seul niveau de sous-question)']; }
        $q['fournisseur'] = $p['fournisseur_id'];
    }
    if ($id === null) {
        if ($q['ordre'] === 0) {
            $max = Db::row('SELECT MAX(ordre) AS m FROM ceo_audit_question WHERE actif = 1 AND ' . ($q['parent'] !== null ? 'parent_id = ?' : ($q['fournisseur'] === null ? 'fournisseur_id IS NULL AND parent_id IS NULL' : 'fournisseur_id = ? AND parent_id IS NULL')),
                $q['parent'] !== null ? [$q['parent']] : ($q['fournisseur'] === null ? [] : [$q['fournisseur']]));
            $q['ordre'] = (int) ($max['m'] ?? 0) + 10;
        }
        $id = auQuestionInserer($q);
        journalAdd('CEO', 'Audit fournisseurs', 'Formulaire', 'Question ajoutée : ' . $q['libelle']);
        return ['ok' => true, 'id' => $id];
    }
    $avant = Db::row('SELECT * FROM ceo_audit_question WHERE id = ? AND actif = 1', [$id]);
    if ($avant === null) { http_response_code(404); return ['error' => 'question inconnue']; }
    if (!array_key_exists('ordre', $b)) { $q['ordre'] = (int) $avant['ordre']; }
    Db::exec('UPDATE ceo_audit_question SET libelle = ?, aide = ?, type = ?, reglage = ?, obligatoire = ?, ordre = ?, maj_le = ? WHERE id = ?',
        [$q['libelle'], $q['aide'] !== '' ? $q['aide'] : null, $q['type'], json_encode(auReglage($q), JSON_UNESCAPED_UNICODE), $q['obligatoire'] ? 1 : 0, $q['ordre'], date('Y-m-d H:i:s'), $id]);
    journalAdd('CEO', 'Audit fournisseurs', 'Formulaire', 'Question modifiée : ' . $q['libelle']);
    return ['ok' => true, 'id' => $id];
}

/** DELETE /audits/questions/{id} — retirée du formulaire (les campagnes envoyées gardent la leur, figée). */
function wr_audits_question_suppr(int $id): array
{
    $q = Db::row('SELECT * FROM ceo_audit_question WHERE id = ? AND actif = 1', [$id]);
    if ($q === null) { http_response_code(404); return ['error' => 'question inconnue']; }
    Db::exec('UPDATE ceo_audit_question SET actif = 0, maj_le = ? WHERE id = ? OR parent_id = ?', [date('Y-m-d H:i:s'), $id, $id]);
    journalAdd('CEO', 'Audit fournisseurs', 'Formulaire', 'Question retirée : ' . $q['libelle']);
    return ['ok' => true];
}

/** POST /audits/questions/ordre {ids:[…]} — l'ordre d'une section, de haut en bas. */
function wr_audits_questions_ordre(): array
{
    $ids = array_values(array_filter(array_map('intval', (array) (body()['ids'] ?? [])), static fn ($i) => $i > 0));
    foreach ($ids as $i => $id) { Db::exec('UPDATE ceo_audit_question SET ordre = ? WHERE id = ?', [($i + 1) * 10, $id]); }
    return ['ok' => true];
}

/** PUT /audits/seuils {bon, surveiller} — en % du maximum. */
function wr_audits_seuils(): array
{
    $b = body();
    $bon = auNombre($b['bon'] ?? null); $sur = auNombre($b['surveiller'] ?? null);
    if ($bon === null || $sur === null || $sur < 0 || $bon > 100 || $sur >= $bon) { http_response_code(422); return ['error' => 'seuils invalides (0 ≤ à surveiller < bon ≤ 100)']; }
    auSettingSet('auditsSeuils', ['bon' => $bon, 'surveiller' => $sur]);
    journalAdd('CEO', 'Audit fournisseurs', 'Seuils', 'Bon ≥ ' . $bon . ' %, à surveiller ≥ ' . $sur . ' %');
    return ['ok' => true, 'seuils' => auSeuils()];
}

function auRelancesNormalise(mixed $v): array
{
    $l = array_values(array_unique(array_filter(array_map('intval', (array) $v), static fn ($n) => $n !== 0 && $n >= -30 && $n <= 60)));
    return array_slice($l, 0, 6);
}

/** POST /audits/campagnes · PUT /audits/campagnes/{id} — une campagne en brouillon (nom, clôture, relances, message). */
function wr_audits_campagne(?int $id): array
{
    $b = body();
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 120);
    $clo = (string) ($b['cloture'] ?? '');
    if ($nom === '') { http_response_code(422); return ['error' => 'nom de la campagne manquant']; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $clo) || $clo < date('Y-m-d')) { http_response_code(422); return ['error' => 'date de clôture invalide (aujourd’hui ou plus tard)']; }
    $rel = json_encode(array_key_exists('relances', $b) ? auRelancesNormalise($b['relances']) : AU_RELANCES);
    $push = !array_key_exists('push', $b) || (bool) $b['push'] ? 1 : 0;
    $msg = mb_substr(trim((string) ($b['message'] ?? '')), 0, 2000);
    if ($id === null) {
        Db::exec('INSERT INTO ceo_audit_campagne (nom, statut, cloture_le, relances, push, message, cree_le) VALUES (?,\'brouillon\',?,?,?,?,?)',
            [$nom, $clo, $rel, $push, $msg !== '' ? $msg : null, date('Y-m-d H:i:s')]);
        $id = (int) Db::pdo()->lastInsertId();
        journalAdd('CEO', 'Audit fournisseurs', $nom, 'Campagne créée');
        return ['ok' => true, 'id' => $id];
    }
    $c = auCampagne($id);
    if ($c === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if ($c['statut'] === 'cloturee') { http_response_code(409); return ['error' => 'campagne close']; }
    // Envoyée : seules la clôture et les relances bougent encore.
    if ($c['statut'] === 'envoyee') {
        Db::exec('UPDATE ceo_audit_campagne SET cloture_le = ?, relances = ? WHERE id = ?', [$clo, $rel, $id]);
    } else {
        Db::exec('UPDATE ceo_audit_campagne SET nom = ?, cloture_le = ?, relances = ?, push = ?, message = ? WHERE id = ?', [$nom, $clo, $rel, $push, $msg !== '' ? $msg : null, $id]);
    }
    journalAdd('CEO', 'Audit fournisseurs', $c['nom'], 'Campagne modifiée');
    return ['ok' => true, 'id' => $id];
}

function wr_audits_campagne_suppr(int $id): array
{
    $c = auCampagne($id);
    if ($c === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if ($c['statut'] !== 'brouillon') { http_response_code(409); return ['error' => 'seul un brouillon se supprime']; }
    Db::exec('DELETE FROM ceo_audit_campagne WHERE id = ?', [$id]);
    journalAdd('CEO', 'Audit fournisseurs', $c['nom'], 'Brouillon supprimé');
    return ['ok' => true];
}

/** GET /audits/campagnes/{id} — la campagne et ses destinataires (statut, avancement, relances). */
function ep_audits_campagne(int $id): array
{
    $c = auCampagne($id);
    if ($c === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    $mags = viMagasins(); $nbF = max(1, count($c['formulaire']['fournisseurs'] ?? []));
    $faits = [];
    foreach (Db::rows('SELECT r.destinataire_id AS d, COUNT(DISTINCT r.fournisseur_id) AS n FROM ceo_audit_reponse r JOIN ceo_audit_destinataire x ON x.id = r.destinataire_id WHERE x.campagne_id = ? GROUP BY r.destinataire_id', [$id]) as $r) {
        $faits[(int) $r['d']] = (int) $r['n'];
    }
    $dest = array_map(static fn ($d) => ['id' => (int) $d['id'], 'shop' => (string) $d['shop_id'], 'court' => $mags[$d['shop_id']]['court'] ?? auMagasinCourt((string) $d['shop_id']),
        'email' => $d['email'], 'envoyeLe' => $d['envoye_le'], 'motif' => $d['envoi_motif'], 'push' => (int) $d['push'] === 1, 'ouvertLe' => $d['ouvert_le'],
        'termineLe' => $d['termine_le'], 'relances' => (int) $d['relances'], 'relanceLe' => $d['relance_le'],
        'avancement' => $d['termine_le'] !== null ? $nbF : min($nbF, $faits[(int) $d['id']] ?? 0),
        'statut' => $d['termine_le'] !== null ? 'fini' : ($d['ouvert_le'] !== null ? 'encours' : ($d['envoye_le'] !== null ? 'jamais' : 'nonenvoye'))],
        Db::rows('SELECT * FROM ceo_audit_destinataire WHERE campagne_id = ? ORDER BY termine_le IS NOT NULL, ouvert_le IS NOT NULL, shop_id', [$id]));
    return ['campagne' => auCampagneLigne($c) + ['fournisseursListe' => $c['formulaire']['fournisseurs'] ?? []], 'destinataires' => $dest, 'nbFournisseurs' => $nbF];
}

/** Le courriel d'invitation (ou de relance) d'un destinataire, dans la langue du magasin. */
function auCourriel(array $c, array $d, bool $relance): array
{
    $shop = (string) $d['shop_id']; $l = auLangue($shop);
    $date = $c['cloture_le'] ? date('d/m/Y', (int) strtotime((string) $c['cloture_le'])) : '';
    $noms = array_column($c['formulaire']['fournisseurs'] ?? [], 'nom');
    $lien = auLien($c, (int) $d['id']);
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $corps = docMailP($e(auMot('bonjour', $l, ['magasin' => auMagasinCourt($shop)])))
        . docMailP($e(auMot($relance ? 'rappel' : 'intro', $l, ['liste' => implode(', ', $noms)])))
        . (!$relance && trim((string) ($c['message'] ?? '')) !== '' ? docMailP(nl2br($e($c['message']))) : '')
        . docMailBouton(auMot('bouton', $l), $lien)
        . docMailP('<span style="font-size:12px;color:#6b6259">' . $e(auMot('fin', $l, ['date' => $date])) . '</span>');
    $sujet = auMot($relance ? 'relance' : 'sujet', $l, ['campagne' => $c['nom'], 'date' => $date]);
    return ['sujet' => $sujet, 'html' => docMail(['sujet' => $sujet, 'titre' => $c['nom'], 'corps' => $corps]), 'lien' => $lien, 'langue' => $l, 'date' => $date, 'n' => count($noms)];
}

/** Envoie l'invitation (ou la relance) à un destinataire : e-mail, et push vers les appareils du magasin. */
function auEnvoyer(array $c, array $d, bool $relance): array
{
    $m = auCourriel($c, $d, $relance);
    $ok = false; $motif = null;
    $mail = $d['email'] ?: (function_exists('suAdresse') ? suAdresse((string) $d['shop_id'])['email'] : null);
    if ($mail === null || $mail === '') { $motif = 'adresse du franchisé inconnue (Qui fait quoi)'; }
    elseif (!class_exists('Smtp') || !Smtp::configured()) { $motif = 'SMTP non configuré (Paramètres)'; }
    else {
        try { $ok = Smtp::envoyer((string) $mail, $m['sujet'], $m['html']); $motif = $ok ? null : (Smtp::$lastError ?? 'envoi refusé'); }
        catch (Throwable $e) { $motif = $e->getMessage(); }
    }
    $push = false;
    if ((int) $c['push'] === 1 && function_exists('viNotifier')) {
        viNotifier((string) $d['shop_id'], $c['nom'], auMot('push', $m['langue'], ['n' => $m['n'], 'date' => $m['date']]),
            'audit/?d=' . (int) $d['id'] . '&jeton=' . auJeton((int) $d['id']), 'audit-' . (int) $c['id']);
        $push = true;
    }
    return ['ok' => $ok, 'motif' => $motif !== null ? mb_substr((string) $motif, 0, 250) : null, 'email' => $mail, 'push' => $push];
}

/** POST /audits/campagnes/{id}/envoyer — fige le formulaire, un lien par magasin ouvert, l'invitation part. */
function wr_audits_envoyer(int $id): array
{
    $c = auCampagne($id);
    if ($c === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if ($c['statut'] !== 'brouillon') { http_response_code(409); return ['error' => 'campagne déjà envoyée']; }
    if ($c['cloture_le'] === null || $c['cloture_le'] < date('Y-m-d')) { http_response_code(422); return ['error' => 'la date de clôture est passée'] ; }
    $form = auFormulaireCourant();
    if (!$form['fournisseurs']) { http_response_code(422); return ['error' => 'aucun fournisseur référencé pour l’audit'] ; }
    if (!$form['questions']) { http_response_code(422); return ['error' => 'le formulaire n’a aucune question'] ; }
    $mags = viMagasins();
    if (!$mags) { http_response_code(422); return ['error' => 'aucun magasin ouvert'] ; }
    $now = date('Y-m-d H:i:s');
    Db::exec('UPDATE ceo_audit_campagne SET statut = \'envoyee\', formulaire = ?, base = ?, envoye_le = ? WHERE id = ? AND statut = \'brouillon\'',
        [json_encode($form, JSON_UNESCAPED_UNICODE), rapBaseUrl(), $now, $id]);
    $c = auCampagne($id);
    $bilan = ['envoyes' => 0, 'sansEnvoi' => 0, 'motifs' => []];
    foreach ($mags as $shop => $mag) {
        $mail = function_exists('suAdresse') ? suAdresse((string) $shop)['email'] : null;
        Db::exec('INSERT INTO ceo_audit_destinataire (campagne_id, shop_id, email) VALUES (?,?,?) ON DUPLICATE KEY UPDATE email = VALUES(email)', [$id, (string) $shop, $mail]);
        $d = Db::row('SELECT * FROM ceo_audit_destinataire WHERE campagne_id = ? AND shop_id = ?', [$id, (string) $shop]);
        $r = auEnvoyer($c, $d, false);
        Db::exec('UPDATE ceo_audit_destinataire SET envoye_le = ?, envoi_motif = ?, push = ? WHERE id = ?', [$r['ok'] || $r['push'] ? $now : null, $r['motif'], $r['push'] ? 1 : 0, (int) $d['id']]);
        if ($r['ok']) { $bilan['envoyes']++; } else { $bilan['sansEnvoi']++; $bilan['motifs'][] = $mag['court'] . ' : ' . $r['motif']; }
    }
    journalAdd('CEO', 'Audit fournisseurs', $c['nom'], 'Campagne envoyée : ' . $bilan['envoyes'] . ' e-mail(s), ' . $bilan['sansEnvoi'] . ' sans e-mail');
    return ['ok' => true] + $bilan;
}

/** POST /audits/campagnes/{id}/relancer {destinataire?} — à la main : un magasin, ou tous ceux qui n'ont pas fini. */
function wr_audits_relancer(int $id): array
{
    $c = auCampagne($id);
    if ($c === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if (!auOuverte($c)) { http_response_code(409); return ['error' => 'campagne non ouverte'] ; }
    $un = (int) (body()['destinataire'] ?? 0);
    $ls = Db::rows('SELECT * FROM ceo_audit_destinataire WHERE campagne_id = ? AND termine_le IS NULL' . ($un > 0 ? ' AND id = ?' : ''), $un > 0 ? [$id, $un] : [$id]);
    $n = 0; $motifs = [];
    foreach ($ls as $d) {
        $r = auEnvoyer($c, $d, true);
        Db::exec('UPDATE ceo_audit_destinataire SET relances = relances + 1, relance_le = ?, envoye_le = COALESCE(envoye_le, ?) WHERE id = ?', [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int) $d['id']]);
        if ($r['ok'] || $r['push']) { $n++; } else { $motifs[] = auMagasinCourt((string) $d['shop_id']) . ' : ' . $r['motif']; }
    }
    journalAdd('CEO', 'Audit fournisseurs', $c['nom'], 'Relance à la main : ' . $n . ' magasin(s)');
    return ['ok' => true, 'relances' => $n, 'motifs' => $motifs];
}

/** Clôt une campagne : plus de réponses ; une copie à écrire par fournisseur. */
function auCloturer(array $c): void
{
    Db::exec('UPDATE ceo_audit_campagne SET statut = \'cloturee\', cloturee_le = ? WHERE id = ? AND statut = \'envoyee\'', [date('Y-m-d H:i:s'), (int) $c['id']]);
    foreach ($c['formulaire']['fournisseurs'] ?? [] as $f) {
        Db::exec('INSERT INTO ceo_audit_copie (campagne_id, fournisseur_id, statut, maj_le) VALUES (?,?,\'a_ecrire\',?) ON DUPLICATE KEY UPDATE campagne_id = campagne_id',
            [(int) $c['id'], $f['id'], date('Y-m-d H:i:s')]);
    }
}

/** POST /audits/campagnes/{id}/cloturer */
function wr_audits_cloturer(int $id): array
{
    $c = auCampagne($id);
    if ($c === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if ($c['statut'] !== 'envoyee') { http_response_code(409); return ['error' => 'seule une campagne envoyée se clôt'] ; }
    auCloturer($c);
    journalAdd('CEO', 'Audit fournisseurs', $c['nom'], 'Campagne close à la main');
    return ['ok' => true];
}

/* --- résultats ---------------------------------------------------------------------------------------------- */

/** Les résultats d'une campagne, fournisseur par fournisseur (scores, réponses par magasin, commentaires, alertes). */
function auResultats(array $c, bool $detail = true): array
{
    $form = $c['formulaire']; $seuils = $form['seuils'] ?? auSeuils();
    $mags = viMagasins();
    $dests = Db::rows('SELECT * FROM ceo_audit_destinataire WHERE campagne_id = ? AND termine_le IS NOT NULL ORDER BY shop_id', [(int) $c['id']]);
    $rep = [];
    foreach ($dests as $d) { $rep[(int) $d['id']] = auReponses((int) $d['id']); }
    $alertes = [];
    foreach (Db::rows('SELECT * FROM ceo_audit_alerte WHERE campagne_id = ? ORDER BY id', [(int) $c['id']]) as $a) { $alertes[(string) $a['fournisseur_id']][] = $a; }
    $out = [];
    foreach ($form['fournisseurs'] ?? [] as $f) {
        $qs = auQuestionsPour($form['questions'] ?? [], $f['id']);
        $scores = []; $lignes = []; $parQ = []; $commentaires = [];
        foreach ($dests as $d) {
            $r = $rep[(int) $d['id']][$f['id']] ?? [];
            if (!$r) { continue; }
            $valeurs = array_map(static fn ($x) => $x['valeur'], $r);
            $s = auScore($qs, $valeurs);
            if ($s['pct'] !== null) { $scores[] = $s; }
            $shop = (string) $d['shop_id']; $court = $mags[$shop]['court'] ?? auMagasinCourt($shop);
            $cell = [];
            foreach ($qs as $q) {
                $v = auVisible($q, $valeurs) ? ($valeurs[$q['id']] ?? null) : null;
                $parQ[$q['id']][] = $v;
                $cell[$q['id']] = ['v' => $v, 'aff' => auAffiche($q, $v), 'teinte' => auTeinte($q, $v)];
                $com = trim((string) ($r[$q['id']]['commentaire'] ?? ''));
                if ($com !== '' || ($q['type'] === 'texte' && $v !== null)) {
                    $commentaires[] = ['id' => (int) ($r[$q['id']]['id'] ?? 0), 'shop' => $shop, 'court' => $court, 'question' => auLibelle($q['libelle'], $f['nom']),
                        'texte' => $q['type'] === 'texte' ? (string) $v : $com, 'reponse' => $q['type'] === 'texte' ? '' : auAffiche($q, $v)];
                }
                if ($q['type'] === 'photo' && $v !== null) { $cell[$q['id']]['photo'] = $v; }
            }
            $lignes[] = ['shop' => $shop, 'court' => $court, 'pct' => $s['pct'], 'points' => $s['points'], 'max' => $s['max'], 'cellules' => $cell];
        }
        $pct = $scores ? round(array_sum(array_column($scores, 'pct')) / count($scores), 1) : null;
        $questions = [];
        foreach ($qs as $q) {
            $vs = array_values(array_filter($parQ[$q['id']] ?? [], static fn ($v) => $v !== null));
            $x = ['id' => $q['id'], 'parent' => $q['parent'], 'libelle' => auLibelle($q['libelle'], $f['nom']), 'type' => $q['type'], 'max' => auPointsMax($q), 'n' => count($vs), 'commun' => $q['fournisseur'] === null];
            if ($q['type'] === 'note' || $q['type'] === 'nombre') { $x['moyenne'] = $vs ? round(array_sum(array_map('floatval', $vs)) / count($vs), 2) : null; }
            if (in_array($q['type'], ['ouinon', 'choix', 'multi'], true)) {
                $x['repartition'] = array_map(static fn ($ch) => ['code' => $ch['code'], 'libelle' => $ch['libelle'],
                    'n' => count(array_filter($vs, static fn ($v) => is_array($v) ? in_array($ch['code'], $v, true) : $v === $ch['code']))], $q['choix']);
            }
            if ($q['type'] === 'note') { $x['repartition'] = array_map(static fn ($n) => ['code' => (string) $n, 'libelle' => (string) $n, 'n' => count(array_filter($vs, static fn ($v) => (int) $v === $n))], [1, 2, 3, 4, 5]); }
            if ($x['max'] !== null) {
                $pts = array_filter(array_map(static fn ($v) => auPoints($q, $v), $vs), static fn ($p) => $p !== null);
                $x['pointsMoyens'] = $pts ? round(array_sum($pts) / count($pts), 2) : null;
            }
            $questions[] = $x;
        }
        $al = array_map(static fn ($a) => ['id' => (int) $a['id'], 'shop' => (string) $a['shop_id'], 'court' => $mags[$a['shop_id']]['court'] ?? (string) $a['shop_id'],
            'question' => (int) $a['question_id'], 'titre' => $a['titre'], 'alerte' => (int) $a['alerte'] === 1, 'statut' => $a['statut'], 'le' => $a['cree_le']], $alertes[$f['id']] ?? []);
        $x = ['id' => $f['id'], 'nom' => $f['nom'], 'cat' => $f['cat'], 'genre' => $f['genre'], 'reponses' => count($lignes), 'pct' => $pct, 'statut' => auStatut($pct, $seuils),
            'points' => $scores ? round(array_sum(array_column($scores, 'points')) / count($scores), 1) : null,
            'max' => $scores ? round(array_sum(array_column($scores, 'max')) / count($scores), 1) : null,
            'questions' => $questions, 'alertes' => $al];
        if ($detail) { $x += ['lignes' => $lignes, 'commentaires' => $commentaires]; }
        $out[] = $x;
    }
    usort($out, static fn ($a, $b) => ($b['pct'] ?? -1) <=> ($a['pct'] ?? -1));
    return $out;
}

/** GET /audits/campagnes/{id}/resultats — par fournisseur, avec l'écart à la campagne précédente et les copies. */
function ep_audits_resultats(int $id): array
{
    $c = auCampagne($id);
    if ($c === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if ($c['statut'] === 'brouillon') { http_response_code(409); return ['error' => 'campagne pas encore envoyée'] ; }
    $res = auResultats($c);
    $prec = Db::row('SELECT id FROM ceo_audit_campagne WHERE id < ? AND statut <> \'brouillon\' ORDER BY id DESC LIMIT 1', [$id]);
    $avant = [];
    if ($prec !== null && ($pc = auCampagne((int) $prec['id'])) !== null) {
        foreach (auResultats($pc, false) as $r) { $avant[$r['id']] = $r['pct']; }
    }
    $copies = [];
    foreach (Db::rows('SELECT * FROM ceo_audit_copie WHERE campagne_id = ?', [$id]) as $k) { $copies[(string) $k['fournisseur_id']] = $k; }
    $four = [];
    foreach (auFournisseurs() as $f) { $four[$f['id']] = $f; }
    foreach ($res as &$r) {
        $r['avant'] = $avant[$r['id']] ?? null;
        $r['ecart'] = $r['pct'] !== null && $r['avant'] !== null ? round($r['pct'] - $r['avant'], 1) : null;
        $k = $copies[$r['id']] ?? null;
        $reg = suJsonAu($k['reglage'] ?? null);
        $r['copie'] = ['statut' => $k['statut'] ?? null, 'note' => (string) ($k['note'] ?? ''), 'email' => $k['email'] ?? null,
            'emailFiche' => $four[$r['id']]['email'] ?? null, 'commentaires' => array_values(array_map('intval', (array) ($reg['commentaires'] ?? []))),
            'masquer' => !array_key_exists('masquer', $reg) || (bool) $reg['masquer'], 'pdf' => (bool) ($reg['pdf'] ?? false),
            'envoyeLe' => $k['envoye_le'] ?? null, 'motif' => $k['envoi_motif'] ?? null];
    }
    unset($r);
    $n = Db::row('SELECT COUNT(*) AS n, SUM(termine_le IS NOT NULL) AS fini FROM ceo_audit_destinataire WHERE campagne_id = ?', [$id]) ?? [];
    return ['campagne' => auCampagneLigne($c), 'precedente' => $prec !== null ? (int) $prec['id'] : null, 'seuils' => $c['formulaire']['seuils'] ?? auSeuils(),
        'reponses' => ['fini' => (int) ($n['fini'] ?? 0), 'total' => (int) ($n['n'] ?? 0)], 'fournisseurs' => $res];
}

/** PUT /audits/alertes/{id} {statut} — ouvert, traite. */
function wr_audits_alerte(int $id): array
{
    $st = (string) (body()['statut'] ?? '');
    if (!in_array($st, ['ouvert', 'traite'], true)) { http_response_code(422); return ['error' => 'statut invalide'] ; }
    $a = Db::row('SELECT * FROM ceo_audit_alerte WHERE id = ?', [$id]);
    if ($a === null) { http_response_code(404); return ['error' => 'alerte inconnue']; }
    Db::exec('UPDATE ceo_audit_alerte SET statut = ?, maj_le = ? WHERE id = ?', [$st, date('Y-m-d H:i:s'), $id]);
    journalAdd('CEO', 'Audit fournisseurs', $a['titre'], $st === 'traite' ? 'Action traitée' : 'Action rouverte');
    return ['ok' => true];
}

/* --- la copie au fournisseur ------------------------------------------------------------------------------- */

/** PUT /audits/copies/{campagne}/{fournisseur} {note, commentaires[], masquer, pdf, email} */
function wr_audits_copie(int $cid, string $fid): array
{
    $c = auCampagne($cid);
    if ($c === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    if (!in_array($fid, array_column($c['formulaire']['fournisseurs'] ?? [], 'id'), true)) { http_response_code(404); return ['error' => 'fournisseur hors campagne']; }
    $b = body();
    $mail = trim((string) ($b['email'] ?? ''));
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'adresse e-mail invalide']; }
    $note = mb_substr(trim((string) ($b['note'] ?? '')), 0, 4000);
    $reg = ['commentaires' => array_values(array_filter(array_map('intval', (array) ($b['commentaires'] ?? [])))), 'masquer' => !array_key_exists('masquer', $b) || (bool) $b['masquer'], 'pdf' => !empty($b['pdf'])];
    $k = Db::row('SELECT * FROM ceo_audit_copie WHERE campagne_id = ? AND fournisseur_id = ?', [$cid, $fid]);
    $statut = ($k['statut'] ?? '') === 'envoyee' ? 'envoyee' : ($note !== '' ? 'brouillon' : 'a_ecrire');
    Db::exec('INSERT INTO ceo_audit_copie (campagne_id, fournisseur_id, note, reglage, email, statut, maj_le) VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE note = VALUES(note), reglage = VALUES(reglage), email = VALUES(email), statut = VALUES(statut), maj_le = VALUES(maj_le)',
        [$cid, $fid, $note !== '' ? $note : null, json_encode($reg), $mail !== '' ? $mail : null, $statut, date('Y-m-d H:i:s')]);
    return ['ok' => true, 'statut' => $statut];
}

/** La copie d'un fournisseur, en HTML de courriel : la note du franchiseur, le score, les moyennes, les commentaires retenus. */
function auCopieHtml(array $c, string $fid): array
{
    $res = null;
    foreach (auResultats($c) as $r) { if ($r['id'] === $fid) { $res = $r; } }
    if ($res === null) { return ['error' => 'fournisseur hors campagne']; }
    $k = Db::row('SELECT * FROM ceo_audit_copie WHERE campagne_id = ? AND fournisseur_id = ?', [(int) $c['id'], $fid]) ?? [];
    $reg = suJsonAu($k['reglage'] ?? null);
    $masquer = !array_key_exists('masquer', $reg) || (bool) $reg['masquer'];
    $retenus = array_flip(array_map('intval', (array) ($reg['commentaires'] ?? [])));
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $nf = static fn ($v, int $d = 1) => number_format((float) $v, $d, ',', ' ');
    $total = (int) (Db::row('SELECT COUNT(*) AS n FROM ceo_audit_destinataire WHERE campagne_id = ?', [(int) $c['id']])['n'] ?? 0);
    $corps = docMailP($e($res['nom'] . ' · ' . $res['reponses'] . ' boutique(s) sur ' . $total . ' ont répondu.'));
    $note = trim((string) ($k['note'] ?? ''));
    if ($note !== '') {
        $corps .= '<div style="border-left:3px solid #8D1D2C;background:#faf7f2;padding:12px 16px;margin:0 0 16px">'
            . '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8D1D2C;margin-bottom:6px">Note du franchiseur</div>'
            . '<div style="font-size:14px;line-height:1.6">' . nl2br($e($note)) . '</div></div>';
    }
    if ($res['pct'] !== null) {
        $corps .= docMailP('<b>Score : ' . $nf($res['pct']) . ' %</b>' . ($res['points'] !== null ? ' (' . $nf($res['points']) . ' / ' . $nf($res['max'], 0) . ' points en moyenne)' : '')
            . ' — ' . $e(AU_STATUTS[$res['statut']] ?? ''));
    }
    $lignes = '';
    foreach ($res['questions'] as $q) {
        if ($q['n'] === 0 || in_array($q['type'], ['texte', 'photo', 'date'], true)) { continue; }
        $val = '';
        if ($q['type'] === 'note') { $val = $nf($q['moyenne'] ?? 0) . ' / 5'; }
        elseif ($q['type'] === 'nombre') { $val = 'moyenne ' . $nf($q['moyenne'] ?? 0); }
        elseif (isset($q['repartition'])) { $val = implode(' · ', array_map(static fn ($r) => $r['n'] . ' ' . $r['libelle'], array_filter($q['repartition'], static fn ($r) => $r['n'] > 0))); }
        $lignes .= '<tr><td style="padding:6px 8px 6px 0;border-bottom:1px solid #e6e0d8;font-size:13px">' . $e($q['libelle']) . '</td>'
            . '<td style="padding:6px 0;border-bottom:1px solid #e6e0d8;font-size:13px;text-align:right;white-space:nowrap">' . $e($val) . '</td></tr>';
    }
    if ($lignes !== '') { $corps .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 16px">' . $lignes . '</table>'; }
    $coms = array_values(array_filter($res['commentaires'], static fn ($x) => isset($retenus[$x['id']])));
    if ($coms) {
        $corps .= docMailP('<b>Ce que disent les franchisés</b>');
        foreach ($coms as $x) { $corps .= docMailP('« ' . $e($x['texte']) . ' »' . ($masquer ? '' : ' <span style="color:#6b6259">— ' . $e($x['court']) . '</span>')); }
    }
    $corps .= docMailP('<span style="font-size:12px;color:#6b6259">Résultats confidentiels, réservés à ' . $e($res['nom']) . '.</span>');
    $sujet = 'Votre évaluation par nos franchisés — ' . $c['nom'];
    return ['sujet' => $sujet, 'html' => docMail(['sujet' => $sujet, 'titre' => $sujet, 'corps' => $corps]), 'pdf' => (bool) ($reg['pdf'] ?? false),
        'email' => trim((string) ($k['email'] ?? '')), 'nom' => $res['nom']];
}

/** GET /audits/copies/{campagne}/{fournisseur}/apercu */
function ep_audits_copie_apercu(int $cid, string $fid): array
{
    $c = auCampagne($cid);
    if ($c === null || $c['statut'] === 'brouillon') { http_response_code(404); return ['error' => 'campagne inconnue']; }
    $x = auCopieHtml($c, $fid);
    if (isset($x['error'])) { http_response_code(404); return $x; }
    return ['sujet' => $x['sujet'], 'html' => $x['html']];
}

/** POST /audits/copies/{campagne}/{fournisseur}/envoyer — la copie part, validée à la main. */
function wr_audits_copie_envoyer(int $cid, string $fid): array
{
    $c = auCampagne($cid);
    if ($c === null || $c['statut'] === 'brouillon') { http_response_code(404); return ['error' => 'campagne inconnue']; }
    $x = auCopieHtml($c, $fid);
    if (isset($x['error'])) { http_response_code(404); return $x; }
    $mail = $x['email'];
    if ($mail === '') {
        foreach (auFournisseurs() as $f) { if ($f['id'] === $fid) { $mail = (string) ($f['email'] ?? ''); } }
    }
    if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'adresse du fournisseur inconnue : renseignez-la'] ; }
    if (!class_exists('Smtp') || !Smtp::configured()) { http_response_code(503); return ['error' => 'SMTP non configuré (Paramètres)'] ; }
    $pieces = [];
    if ($x['pdf'] && function_exists('rapPdfRendu')) {
        $pdf = rapPdfRendu($x['html'], ['titre' => $x['sujet']]);
        if ($pdf !== null) { $pieces[] = ['nom' => 'audit-' . auCode($x['nom']) . '.pdf', 'type' => 'application/pdf', 'contenu' => $pdf]; }
    }
    try { $ok = Smtp::envoyer($mail, $x['sujet'], $x['html'], $pieces); }
    catch (Throwable $e) { $ok = false; Smtp::$lastError = $e->getMessage(); }
    $motif = $ok ? null : mb_substr((string) (Smtp::$lastError ?? 'envoi refusé'), 0, 250);
    Db::exec('UPDATE ceo_audit_copie SET statut = ?, envoye_le = ?, envoi_motif = ?, email = COALESCE(email, ?), maj_le = ? WHERE campagne_id = ? AND fournisseur_id = ?',
        [$ok ? 'envoyee' : 'brouillon', $ok ? date('Y-m-d H:i:s') : null, $motif, $mail, date('Y-m-d H:i:s'), $cid, $fid]);
    journalAdd('CEO', 'Audit fournisseurs', $x['nom'], $ok ? 'Copie des résultats envoyée à ' . $mail : 'Copie non envoyée : ' . $motif);
    if (!$ok) { http_response_code(502); return ['error' => 'envoi refusé : ' . $motif]; }
    return ['ok' => true, 'a' => $mail, 'pdf' => (bool) $pieces];
}

/* --- l'horloge : relances et clôture (appelée par viHorloge, toutes les 5 minutes) ------------------------- */

function auHorloge(array &$fait): void
{
    ensureAudits();
    $fait['auditsRelances'] = 0; $fait['auditsClos'] = 0;
    $now = date('Y-m-d H:i:s'); $h = (int) date('G');
    foreach (Db::rows('SELECT id FROM ceo_audit_campagne WHERE statut = \'envoyee\'') as $x) {
        $c = auCampagne((int) $x['id']);
        if ($c === null) { continue; }
        if ($c['cloture_le'] !== null && date('Y-m-d') > (string) $c['cloture_le']) {
            auCloturer($c);
            $fait['auditsClos']++;
            if (function_exists('viNotifier')) { viNotifier('admin', 'Audit fournisseurs clos', $c['nom'] . ' : les copies des fournisseurs sont à écrire', '#/audits', 'audit-' . $c['id']); }
            journalAdd('Horloge', 'Audit fournisseurs', $c['nom'], 'Campagne close (date de clôture passée)');
            continue;
        }
        // Les relances partent en journée, jamais la nuit.
        if ($h < 9 || $h >= 19) { continue; }
        foreach (Db::rows('SELECT * FROM ceo_audit_destinataire WHERE campagne_id = ? AND termine_le IS NULL AND envoye_le IS NOT NULL', [(int) $c['id']]) as $d) {
            if (!auRelanceDue($c['relances'], (string) $d['envoye_le'], $c['cloture_le'], (int) $d['relances'], $now)) { continue; }
            auEnvoyer($c, $d, true);
            Db::exec('UPDATE ceo_audit_destinataire SET relances = relances + 1, relance_le = ? WHERE id = ?', [$now, (int) $d['id']]);
            $fait['auditsRelances']++;
        }
    }
}
