<?php
declare(strict_types=1);

/*
 * MODULE FORMATION — LES EXAMENS THÉORIQUES DES CANDIDATS FRANCHISÉS (26/11/2026).
 *
 * Client : « créer les examens théoriques concernant la marque et les autres thèmes de ce que le franchisé doit savoir
 * avant d'ouvrir ; en faire une routine, car ça va évoluer ». Maquette validée (CRM, docs/maquettes/examens-theoriques/) :
 * les questions s'écrivent ICI (portail de formation), l'examen se passe dans l'application du candidat (CRM), le
 * résultat va dans le dossier du candidat (CRM). Public : les candidats franchisés seulement (pour le moment).
 *
 *  for_exam_theme     un thème : code (MAR, CON, HYG…), nom FR / NL, ordre, questions tirées par examen, seuil (%), actif
 *  for_exam_question  une question : thème, type (qcm · vf · multi · situation), énoncé et explication FR / NL, choix
 *                     (JSON [{fr, nl, bon}]), SOURCE (contrat · sop · fidelite · guide · autre, référence, libellé) et
 *                     son EMPREINTE au jour de la validation, bloquante, statut brouillon · validee · a_revoir · ecartee
 *  for_exam_releve    chaque relecture des sources (la routine mensuelle) : ce qui est passé « à revoir », ce qui est proposé
 *
 * LA ROUTINE : une question validée porte l'empreinte (sha1) du texte de sa source. La relecture (exReleve) recalcule
 * chaque empreinte ; une source changée fait passer la question « à revoir » (avant / après gardés) : elle n'est plus
 * servie aux examens tant qu'elle n'est pas revalidée. Pour chaque source changée, le modèle (Anthropic::json) propose
 * de nouvelles questions, rangées en BROUILLON. Rien n'entre dans un examen sans validation. Le rapport part par e-mail
 * aux adresses réglées (Sam et Pierre). Déclenchée par /etc/cron.d/cockpit-rapports (formation/bin/examens_cron.sh,
 * chaque jour à 06:20 → GET /formation/examens/releve-auto, jeton de service : relit seulement si aucune relecture n'a
 * eu lieu ce mois-ci — le 1er du mois, ou le premier jour où le serveur répond), à la demande (« Relire maintenant »),
 * et à l'ouverture de l'écran si la dernière relecture a plus de 35 jours.
 *
 * Sources : contrat = les articles du contrat de franchise tels que le Recrutement les rend avec les conditions du réseau
 * (GET /v1/integration/recrutement/sources-examen, jeton de service) ; sop = la version en vigueur d'un standard
 * (for_sop + for_sop_version) ; fidelite = les règles du programme de fidélité (ep_fidelite_programme, sans les saisons) ;
 * guide / autre = pas d'empreinte (jamais « à revoir » d'office).
 *
 * Lecture par le Recrutement : GET /formation/examens/banque (jeton de service) = les thèmes actifs et les questions
 * VALIDÉES avec leurs bonnes réponses — le CRM corrige côté serveur, jamais dans le navigateur du candidat.
 *
 * Contrat : shared/docs/contrat-api.md § Formation · Examens théoriques.
 */

const EX_TYPES = ['qcm', 'vf', 'multi', 'situation'];
const EX_STATUTS = ['brouillon', 'validee', 'a_revoir', 'ecartee'];
const EX_SOURCES = ['contrat', 'sop', 'fidelite', 'guide', 'autre'];
const EX_RELEVE_JOURS = 35;     // l'écran relance une relecture quand la dernière a plus de 35 jours
const EX_THEMES = [
    ['MAR', 'La marque et le concept', 'Het merk en het concept'],
    ['CON', 'Le contrat et vos obligations', 'Het contract en uw verplichtingen'],
    ['HYG', 'Hygiène et sécurité alimentaire', 'Hygiëne en voedselveiligheid'],
    ['PRO', 'Produits et fiches techniques', 'Producten en technische fiches'],
    ['VEN', 'Vente et service client', 'Verkoop en klantenservice'],
    ['FID', 'Fidélité et marketing local', 'Klantenbinding en lokale marketing'],
    ['GES', 'Gestion et chiffres', 'Beheer en cijfers'],
    ['PER', 'Personnel et planning', 'Personeel en planning'],
    ['OUV', 'Ouverture, fermeture et contrôles', 'Opening, sluiting en controles'],
    ['OUT', 'Outils du réseau', 'Tools van het netwerk'],
];

function exDataDir(): string { return __DIR__ . '/../data/formation/examens'; }

function exCompte(string $sql, array $p = []): int { $r = Db::row($sql, $p); return $r === null ? 0 : (int) $r['n']; }

/* ═══ Schéma et reprise ═══════════════════════════════════════════════════════════════════════════ */

function ensureExamens(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS for_exam_theme ('
        . 'code VARCHAR(8) NOT NULL PRIMARY KEY,'
        . 'nom_fr VARCHAR(120) NOT NULL,'
        . "nom_nl VARCHAR(120) NOT NULL DEFAULT '',"
        . 'ordre INT NOT NULL DEFAULT 0,'
        . 'tirees INT NOT NULL DEFAULT 10,'
        . 'seuil INT NOT NULL DEFAULT 80,'
        . 'actif TINYINT(1) NOT NULL DEFAULT 1,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS for_exam_question ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . 'code VARCHAR(20) NOT NULL,'
        . 'theme VARCHAR(8) NOT NULL,'
        . "type VARCHAR(12) NOT NULL DEFAULT 'qcm',"
        . 'enonce_fr TEXT NOT NULL,'
        . 'enonce_nl TEXT NULL,'
        . 'choix MEDIUMTEXT NOT NULL,'
        . 'explication_fr TEXT NULL,'
        . 'explication_nl TEXT NULL,'
        . "source_type VARCHAR(12) NOT NULL DEFAULT 'autre',"
        . "source_ref VARCHAR(120) NOT NULL DEFAULT '',"
        . "source_lib VARCHAR(190) NOT NULL DEFAULT '',"
        . 'source_empreinte CHAR(40) NULL,'
        . 'source_extrait TEXT NULL,'
        . 'bloquante TINYINT(1) NOT NULL DEFAULT 0,'
        . "statut VARCHAR(12) NOT NULL DEFAULT 'brouillon',"
        . 'revoir_raison VARCHAR(500) NULL,'
        . 'revoir_avant TEXT NULL,'
        . 'revoir_apres TEXT NULL,'
        . "origine VARCHAR(12) NOT NULL DEFAULT 'manuel',"
        . 'version INT NOT NULL DEFAULT 1,'
        . 'cree_le DATETIME NOT NULL,'
        . 'cree_par VARCHAR(120) NULL,'
        . 'valide_le DATETIME NULL,'
        . 'valide_par VARCHAR(120) NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL,'
        . 'UNIQUE KEY uq_code (code),'
        . 'KEY idx_theme (theme, statut)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS for_exam_releve ('
        . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . 'le DATETIME NOT NULL,'
        . "par VARCHAR(120) NOT NULL DEFAULT '',"
        . 'a_revoir INT NOT NULL DEFAULT 0,'
        . 'propositions INT NOT NULL DEFAULT 0,'
        . 'detail MEDIUMTEXT NULL,'
        . 'envoye_a VARCHAR(500) NULL,'
        . 'erreur VARCHAR(500) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (exCompte('SELECT COUNT(*) AS n FROM for_exam_theme') === 0) {
        $now = date('Y-m-d H:i:s');
        foreach (EX_THEMES as $i => [$c, $fr, $nl]) {
            Db::exec('INSERT IGNORE INTO for_exam_theme (code, nom_fr, nom_nl, ordre, tirees, seuil, actif, maj_le, maj_par) VALUES (?, ?, ?, ?, 10, 80, 1, ?, ?)',
                [$c, $fr, $nl, $i + 1, $now, 'Reprise']);
        }
    }
    exReprise();
}

/** La première banque (data/formation/examens/questions.json), en BROUILLON, une seule fois. */
function exReprise(): void
{
    if (exCompte('SELECT COUNT(*) AS n FROM for_exam_question') > 0) { return; }
    $f = exDataDir() . '/questions.json';
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($j)) { return; }
    $now = date('Y-m-d H:i:s');
    $n = [];
    foreach ($j as $q) {
        if (!is_array($q)) { continue; }
        $theme = (string) ($q['theme'] ?? '');
        if (!in_array($theme, array_column(EX_THEMES, 0), true)) { continue; }
        $n[$theme] = ($n[$theme] ?? 0) + 1;
        $propre = exPropre($q + ['statut' => 'brouillon']);
        if ($propre === null) { continue; }
        Db::exec('INSERT IGNORE INTO for_exam_question (code, theme, type, enonce_fr, enonce_nl, choix, explication_fr, explication_nl, source_type, source_ref, source_lib,
                bloquante, statut, origine, version, cree_le, cree_par, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
            [sprintf('Q-%s-%03d', $theme, $n[$theme]), $theme, $propre['type'], $propre['enonce_fr'], $propre['enonce_nl'], json_encode($propre['choix'], JSON_UNESCAPED_UNICODE),
                $propre['explication_fr'], $propre['explication_nl'], $propre['source_type'], $propre['source_ref'], $propre['source_lib'], $propre['bloquante'],
                'brouillon', 'reprise', $now, 'Reprise', $now, 'Reprise']);
    }
}

/* ═══ Règles d'une question ═══════════════════════════════════════════════════════════════════════ */

/**
 * Une question remise d'aplomb (saisie ou reprise) ; null si elle ne tient pas. Accepte les deux formes : celle de
 * l'écran ({enonce:{fr,nl}, choix:[{fr,nl,bon}], explication:{fr,nl}, source:{type,ref,lib}}) et celle du fichier de
 * reprise ({q, choix:[[texte, bon]], expl, src:{type,ref,lib}, bloq}).
 */
function exPropre(array $q): ?array
{
    $txt = static fn ($v, string $l = 'fr'): string => trim((string) (is_array($v) ? ($v[$l] ?? '') : ($l === 'fr' ? $v : '')));
    $enFr = mb_substr($txt($q['enonce'] ?? ($q['q'] ?? '')), 0, 2000);
    $type = in_array($q['type'] ?? 'qcm', EX_TYPES, true) ? (string) $q['type'] : 'qcm';
    $choix = [];
    foreach ((array) ($q['choix'] ?? []) as $c) {
        if (is_array($c) && array_is_list($c)) { $c = ['fr' => (string) ($c[0] ?? ''), 'nl' => '', 'bon' => !empty($c[1])]; }
        if (!is_array($c)) { continue; }
        $fr = mb_substr(trim((string) ($c['fr'] ?? '')), 0, 500);
        if ($fr === '') { continue; }
        $choix[] = ['fr' => $fr, 'nl' => mb_substr(trim((string) ($c['nl'] ?? '')), 0, 500), 'bon' => !empty($c['bon'])];
    }
    if ($enFr === '' || count($choix) < 2 || count($choix) > 6) { return null; }
    $src = is_array($q['source'] ?? null) ? $q['source'] : (is_array($q['src'] ?? null) ? $q['src'] : []);
    $st = in_array($src['type'] ?? 'autre', EX_SOURCES, true) ? (string) $src['type'] : 'autre';
    return [
        'type' => $type, 'enonce_fr' => $enFr, 'enonce_nl' => mb_substr($txt($q['enonce'] ?? '', 'nl'), 0, 2000),
        'choix' => $choix,
        'explication_fr' => mb_substr($txt($q['explication'] ?? ($q['expl'] ?? '')), 0, 2000),
        'explication_nl' => mb_substr($txt($q['explication'] ?? '', 'nl'), 0, 2000),
        'source_type' => $st, 'source_ref' => mb_substr(trim((string) ($src['ref'] ?? '')), 0, 120), 'source_lib' => mb_substr(trim((string) ($src['lib'] ?? '')), 0, 190),
        'bloquante' => !empty($q['bloquante'] ?? ($q['bloq'] ?? false)) ? 1 : 0,
    ];
}

/** Ce qui empêche une question d'être validée ('' = rien). */
function exManque(array $q): string
{
    $bons = count(array_filter($q['choix'], static fn ($c) => !empty($c['bon'])));
    if ($bons === 0) { return 'aucune bonne réponse cochée'; }
    if (in_array($q['type'], ['qcm', 'vf', 'situation'], true) && $bons !== 1) { return 'une seule bonne réponse pour ce type de question'; }
    if ($q['type'] === 'vf' && count($q['choix']) !== 2) { return 'un vrai / faux a exactement deux réponses'; }
    if ($q['type'] === 'multi' && $bons === count($q['choix'])) { return 'au moins une réponse doit être fausse'; }
    return '';
}

function exQuestionOut(array $r): array
{
    $choix = json_decode((string) $r['choix'], true);
    return [
        'id' => (int) $r['id'], 'code' => (string) $r['code'], 'theme' => (string) $r['theme'], 'type' => (string) $r['type'],
        'enonce' => ['fr' => (string) $r['enonce_fr'], 'nl' => (string) ($r['enonce_nl'] ?? '')],
        'choix' => is_array($choix) ? $choix : [],
        'explication' => ['fr' => (string) ($r['explication_fr'] ?? ''), 'nl' => (string) ($r['explication_nl'] ?? '')],
        'source' => ['type' => (string) $r['source_type'], 'ref' => (string) $r['source_ref'], 'lib' => (string) $r['source_lib'], 'suivie' => $r['source_empreinte'] !== null],
        'bloquante' => (bool) $r['bloquante'], 'statut' => (string) $r['statut'], 'origine' => (string) $r['origine'], 'version' => (int) $r['version'],
        'revoir' => $r['statut'] === 'a_revoir' ? ['raison' => (string) ($r['revoir_raison'] ?? ''), 'avant' => (string) ($r['revoir_avant'] ?? ''), 'apres' => (string) ($r['revoir_apres'] ?? '')] : null,
        'creeLe' => substr((string) $r['cree_le'], 0, 16), 'creePar' => (string) ($r['cree_par'] ?? ''),
        'valideLe' => $r['valide_le'] ? substr((string) $r['valide_le'], 0, 16) : null, 'validePar' => (string) ($r['valide_par'] ?? ''),
        'majLe' => substr((string) $r['maj_le'], 0, 16), 'majPar' => (string) ($r['maj_par'] ?? ''),
    ];
}

function exQui(): string
{
    return function_exists('fcQui') ? fcQui('siege')['nom'] : 'Siège';
}

/* ═══ Les sources et leurs empreintes ═══════════════════════════════════════════════════════════ */

/**
 * Le texte des sources, lu une fois par requête : articles du contrat (Recrutement), standards (for_sop), règles de
 * fidélité. ['contrat' => {ref: texte}|null, 'contratErreur' => ?, 'fidelite' => texte|null]. Les SOP se lisent à la demande.
 */
function exSources(bool $frais = false): array
{
    static $memo = null;
    if ($memo !== null && !$frais) { return $memo; }
    $contrat = null; $err = null;
    if (function_exists('erpLireRecrutement')) {
        $d = erpLireRecrutement('sources-examen', 25);
        if (is_array($d) && is_array($d['articles'] ?? null)) {
            $contrat = [];
            foreach ($d['articles'] as $ref => $a) { $contrat[(string) $ref] = is_array($a) ? trim((string) ($a['titre'] ?? '') . "\n" . (string) ($a['texte'] ?? '')) : (string) $a; }
        } else { $err = 'le Recrutement ne rend pas les articles du contrat'; }
    }
    $fid = null;
    if (function_exists('ep_fidelite_programme')) {
        try {
            $p = ep_fidelite_programme();
            if (is_array($p) && empty($p['error'])) { unset($p['saisons']); $fid = json_encode($p, JSON_UNESCAPED_UNICODE); }
        } catch (Throwable $e) { $fid = null; }
    }
    return $memo = ['contrat' => $contrat, 'contratErreur' => $err, 'fidelite' => $fid];
}

/** Le texte de la source d'une question, ou null s'il ne se lit pas (source non suivie, ou muette). */
function exTexteSource(string $type, string $ref): ?string
{
    $ref = trim($ref);
    if ($type === 'contrat' && $ref !== '') {
        $c = exSources()['contrat'];
        if (!is_array($c)) { return null; }
        $k = preg_replace('/^(art\.?|article)\s*/i', '', $ref);
        return $c[$k] ?? null;
    }
    if ($type === 'sop' && $ref !== '') {
        try {
            $r = Db::row('SELECT s.titre_fr, v.contenu FROM for_sop s JOIN for_sop_version v ON v.sop_id = s.id AND v.version = s.version WHERE s.code = ? OR s.id = ? LIMIT 1', [$ref, $ref]);
        } catch (Throwable $e) { return null; }
        if ($r === null) { return null; }
        $c = json_decode((string) $r['contenu'], true);
        $fr = is_array($c) ? exAplatirFr($c) : (string) $r['contenu'];
        return trim((string) $r['titre_fr'] . "\n" . $fr);
    }
    if ($type === 'fidelite') { return exSources()['fidelite']; }
    return null;
}

/** Le texte français d'un contenu de SOP (sections, étapes, points critiques), sans les traductions ni les photos. */
function exAplatirFr(mixed $v, string $cle = ''): string
{
    if (is_string($v)) { return in_array($cle, ['nl', 'photo', 'image', 'img', 'src', 'id', 'url'], true) ? '' : $v; }
    if (!is_array($v)) { return is_scalar($v) ? (string) $v : ''; }
    $out = [];
    foreach ($v as $k => $x) { if ((string) $k !== 'nl' && (string) $k !== 'quiz') { $t = exAplatirFr($x, (string) $k); if (trim($t) !== '') { $out[] = $t; } } }
    return implode("\n", $out);
}

function exEmpreinte(?string $t): ?string
{
    if ($t === null) { return null; }
    $n = preg_replace('/\s+/u', ' ', mb_strtolower(trim($t)));
    return $n === '' ? null : sha1($n);
}

/* ═══ La relecture (la routine) ══════════════════════════════════════════════════════════════════ */

/**
 * Relire les sources : chaque question validée dont la source suivie a changé passe « à revoir » ; pour chaque source
 * changée, le modèle propose jusqu'à deux questions nouvelles (BROUILLON). Rapport par e-mail. Une ligne for_exam_releve.
 */
function exReleve(string $par, bool $proposer = true, bool $envoyer = true): array
{
    ensureExamens();
    exSources(true);
    $now = date('Y-m-d H:i:s');
    $revoir = []; $sourcesChangees = []; $nonLues = 0;
    foreach (Db::rows("SELECT * FROM for_exam_question WHERE statut = 'validee' AND source_empreinte IS NOT NULL") as $r) {
        $t = exTexteSource((string) $r['source_type'], (string) $r['source_ref']);
        if ($t === null) { $nonLues++; continue; }
        if (exEmpreinte($t) === $r['source_empreinte']) { continue; }
        $raison = 'La source a changé : ' . ($r['source_lib'] ?: ($r['source_type'] . ' ' . $r['source_ref']));
        Db::exec("UPDATE for_exam_question SET statut = 'a_revoir', revoir_raison = ?, revoir_avant = ?, revoir_apres = ?, maj_le = ?, maj_par = ? WHERE id = ?",
            [mb_substr($raison, 0, 500), mb_substr((string) $r['source_extrait'], 0, 4000), mb_substr($t, 0, 4000), $now, 'Relecture mensuelle', (int) $r['id']]);
        $revoir[] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'theme' => (string) $r['theme'], 'enonce' => (string) $r['enonce_fr'], 'raison' => $raison];
        $sourcesChangees[$r['source_type'] . '|' . $r['source_ref']] = ['type' => (string) $r['source_type'], 'ref' => (string) $r['source_ref'], 'lib' => (string) $r['source_lib'],
            'theme' => (string) $r['theme'], 'avant' => (string) $r['source_extrait'], 'apres' => $t];
    }
    $props = []; $erreur = null;
    if ($proposer && $sourcesChangees && class_exists('Anthropic') && Anthropic::configured()) {
        foreach (array_slice(array_values($sourcesChangees), 0, 8) as $s) {
            [$nouv, $e] = exProposer($s);
            if ($e !== null) { $erreur = $e; break; }
            foreach ($nouv as $q) { $props[] = $q; }
        }
    } elseif ($proposer && $sourcesChangees) { $erreur = 'pas de clé Anthropic : aucune question proposée'; }
    $detail = ['revoir' => $revoir, 'propositions' => $props, 'nonLues' => $nonLues, 'contratErreur' => exSources()['contratErreur']];
    $a = array_values(array_filter(array_map('trim', explode(',', (string) setting('examensRapportA', '')))));
    $envoyeA = null;
    if ($envoyer && $a && ($revoir || $props) && class_exists('Smtp') && Smtp::configured()) {
        $ok = [];
        foreach ($a as $x) { if (filter_var($x, FILTER_VALIDATE_EMAIL) && Smtp::envoyer($x, '[Formation] Examens théoriques — relecture du ' . date('d/m/Y'), exRapportHtml($revoir, $props))) { $ok[] = $x; } }
        $envoyeA = implode(', ', $ok) ?: null;
    }
    Db::exec('INSERT INTO for_exam_releve (le, par, a_revoir, propositions, detail, envoye_a, erreur) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$now, mb_substr($par, 0, 120), count($revoir), count($props), json_encode($detail, JSON_UNESCAPED_UNICODE), $envoyeA, $erreur]);
    return ['le' => $now, 'aRevoir' => count($revoir), 'propositions' => count($props), 'nonLues' => $nonLues, 'envoyeA' => $envoyeA, 'erreur' => $erreur, 'detail' => $detail];
}

/** La relecture mensuelle (cron quotidien) : seulement si aucune relecture n'a eu lieu ce mois-ci. */
function exReleveAuto(): array
{
    ensureExamens();
    $mois = date('Y-m-01 00:00:00');
    if (exCompte('SELECT COUNT(*) AS n FROM for_exam_releve WHERE le >= ?', [$mois]) > 0) { return ['fait' => false, 'raison' => 'déjà relu ce mois-ci']; }
    return ['fait' => true] + exReleve('Relecture mensuelle');
}

/** Le modèle propose des questions sur une source changée ; rangées en brouillon. [questions créées, erreur]. */
function exProposer(array $s): array
{
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['questions'], 'properties' => ['questions' => ['type' => 'array', 'items' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['enonce', 'choix', 'bonne', 'explication'],
        'properties' => ['enonce' => ['type' => 'string'], 'choix' => ['type' => 'array', 'items' => ['type' => 'string']], 'bonne' => ['type' => 'integer'], 'explication' => ['type' => 'string']]]]]];
    $system = 'Tu rédiges des questions d’examen théorique pour les candidats franchisés d’un réseau de boulangeries (L’Atelier By, Belgique). '
        . 'À partir du texte NOUVEAU d’une source (comparé à l’ancien), propose au plus deux questions à choix multiple (4 réponses, une seule bonne) '
        . 'sur ce qui a CHANGÉ et que le franchisé doit savoir. Réponses courtes, sans piège de vocabulaire, en français. '
        . 'L’explication cite la source. N’invente rien qui ne soit pas dans le texte nouveau.';
    $texte = 'Source : ' . ($s['lib'] ?: $s['type'] . ' ' . $s['ref']) . "\n\nTEXTE AVANT :\n" . mb_substr($s['avant'], 0, 6000) . "\n\nTEXTE NOUVEAU :\n" . mb_substr($s['apres'], 0, 6000);
    $r = Anthropic::json($system, $texte, $schema, 2000, null, 90);
    if ($r['json'] === null) { return [[], (string) $r['erreur']]; }
    $out = []; $now = date('Y-m-d H:i:s');
    foreach (array_slice((array) ($r['json']['questions'] ?? []), 0, 2) as $q) {
        $choix = array_values(array_filter(array_map('strval', (array) ($q['choix'] ?? [])), static fn ($c) => trim($c) !== ''));
        $bonne = (int) ($q['bonne'] ?? -1);
        if (count($choix) < 2 || !isset($choix[$bonne])) { continue; }
        $p = exPropre(['enonce' => ['fr' => (string) $q['enonce']], 'choix' => array_map(static fn ($c, $i) => ['fr' => $c, 'bon' => $i === $bonne], $choix, array_keys($choix)),
            'explication' => ['fr' => (string) ($q['explication'] ?? '')], 'source' => ['type' => $s['type'], 'ref' => $s['ref'], 'lib' => $s['lib']], 'type' => 'qcm']);
        if ($p === null) { continue; }
        $code = exCodeNeuf($s['theme']);
        Db::exec('INSERT INTO for_exam_question (code, theme, type, enonce_fr, enonce_nl, choix, explication_fr, explication_nl, source_type, source_ref, source_lib, bloquante, statut, origine, version, cree_le, cree_par, maj_le, maj_par)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?, ?, ?, ?)',
            [$code, $s['theme'], $p['type'], $p['enonce_fr'], '', json_encode($p['choix'], JSON_UNESCAPED_UNICODE), $p['explication_fr'], '', $p['source_type'], $p['source_ref'], $p['source_lib'],
                'brouillon', 'releve', $now, 'Relecture mensuelle', $now, 'Relecture mensuelle']);
        $out[] = ['code' => $code, 'theme' => $s['theme'], 'enonce' => $p['enonce_fr'], 'source' => $s['lib']];
    }
    return [$out, null];
}

function exCodeNeuf(string $theme): string
{
    $n = 0;
    foreach (Db::rows('SELECT code FROM for_exam_question WHERE theme = ?', [$theme]) as $r) {
        if (preg_match('/-(\d+)$/', (string) $r['code'], $m)) { $n = max($n, (int) $m[1]); }
    }
    return sprintf('Q-%s-%03d', $theme, $n + 1);
}

function exRapportHtml(array $revoir, array $props): string
{
    $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $h = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#1c1a17"><p>Relecture des sources des examens théoriques du ' . date('d/m/Y') . '.</p>';
    if ($revoir) {
        $h .= '<p><b>' . count($revoir) . ' question(s) à revoir</b> (la source a changé ; elles ne sont plus posées tant qu’elles ne sont pas revalidées) :</p><ul>';
        foreach ($revoir as $q) { $h .= '<li>' . $e($q['code']) . ' — ' . $e($q['enonce']) . '<br><small style="color:#7a736a">' . $e($q['raison']) . '</small></li>'; }
        $h .= '</ul>';
    }
    if ($props) {
        $h .= '<p><b>' . count($props) . ' question(s) proposée(s)</b>, en brouillon :</p><ul>';
        foreach ($props as $q) { $h .= '<li>' . $e($q['code']) . ' — ' . $e($q['enonce']) . '</li>'; }
        $h .= '</ul>';
    }
    return $h . '<p>À valider dans le portail de formation › Examens théoriques.</p></div>';
}

/* ═══ Routes ══════════════════════════════════════════════════════════════════════════════════════ */

function exRoute(string $method, string $path): ?array
{
    if ($path !== '/formation/examens' && !str_starts_with($path, '/formation/examens/')) { return null; }
    // Les bonnes réponses ne sortent que vers le siège ou le Recrutement (jeton de service) : jamais vers un magasin.
    $service = function_exists('erpJetonServiceOk') && erpJetonServiceOk();
    $id = function_exists('accesIdentite') ? accesIdentite() : null;
    $ouvert = function_exists('accesMode') && accesMode() === 'ouvert';
    if (!$service && !$ouvert && (!is_array($id) || ($id['role'] ?? '') !== 'siege')) { return foRefus(403, 'réservé au siège'); }
    ensureExamens();
    $m = [];
    if ($path === '/formation/examens/banque' && $method === 'GET') { return ep_ex_banque(); }
    if ($path === '/formation/examens/releve-auto' && $method === 'GET') { return exReleveAuto(); }
    if ($path === '/formation/examens' && $method === 'GET') { return ep_ex_liste(); }
    if (preg_match('#^/formation/examens/themes/([A-Z]{2,8})$#', $path, $m) && $method === 'PUT') { return wr_ex_theme($m[1]); }
    if ($path === '/formation/examens/questions' && $method === 'POST') { return wr_ex_question(null); }
    if (preg_match('#^/formation/examens/questions/(\d+)$#', $path, $m)) {
        if ($method === 'PUT') { return wr_ex_question((int) $m[1]); }
        if ($method === 'DELETE') { return wr_ex_supprimer((int) $m[1]); }
    }
    if (preg_match('#^/formation/examens/questions/(\d+)/statut$#', $path, $m) && $method === 'POST') { return wr_ex_statut([(int) $m[1]], (string) (body()['statut'] ?? '')); }
    if ($path === '/formation/examens/valider' && $method === 'POST') { return wr_ex_statut(array_map('intval', (array) (body()['ids'] ?? [])), 'validee'); }
    if ($path === '/formation/examens/reglages' && $method === 'PUT') { return wr_ex_reglages(); }
    if ($path === '/formation/examens/releve' && $method === 'POST') { return exReleve(exQui(), !empty(body()['proposer'] ?? true)); }
    return null;
}

/** GET /formation/examens — l'écran : thèmes et compteurs, questions, dernière relecture, réglages, statistiques du CRM. */
function ep_ex_liste(): array
{
    $dern = Db::row('SELECT * FROM for_exam_releve ORDER BY id DESC LIMIT 1');
    if ($dern === null || strtotime((string) $dern['le']) < time() - EX_RELEVE_JOURS * 86400) {
        try { exReleve('Ouverture de l’écran'); } catch (Throwable $e) { error_log('examens relecture : ' . $e->getMessage()); }
        $dern = Db::row('SELECT * FROM for_exam_releve ORDER BY id DESC LIMIT 1');
    }
    $qs = array_map('exQuestionOut', Db::rows('SELECT * FROM for_exam_question ORDER BY theme, code'));
    $stats = function_exists('erpLireRecrutement') ? erpLireRecrutement('examens-stats', 8) : null;
    $parQ = is_array($stats['questions'] ?? null) ? $stats['questions'] : [];
    foreach ($qs as &$q) { $s = $parQ[$q['code']] ?? null; $q['posee'] = is_array($s) ? (int) ($s['posee'] ?? 0) : null; $q['reussie'] = is_array($s) ? (int) ($s['reussie'] ?? 0) : null; }
    unset($q);
    $themes = [];
    foreach (Db::rows('SELECT * FROM for_exam_theme ORDER BY ordre, code') as $t) {
        $mine = array_filter($qs, static fn ($q) => $q['theme'] === $t['code']);
        $n = static fn (string $st) => count(array_filter($mine, static fn ($q) => $q['statut'] === $st));
        $themes[] = ['code' => (string) $t['code'], 'nom' => ['fr' => (string) $t['nom_fr'], 'nl' => (string) $t['nom_nl']], 'tirees' => (int) $t['tirees'], 'seuil' => (int) $t['seuil'],
            'actif' => (bool) $t['actif'], 'validees' => $n('validee'), 'brouillons' => $n('brouillon'), 'aRevoir' => $n('a_revoir'), 'ecartees' => $n('ecartee'),
            'bloquantes' => count(array_filter($mine, static fn ($q) => $q['statut'] === 'validee' && $q['bloquante']))];
    }
    $actifs = array_filter($themes, static fn ($t) => $t['actif']);
    return [
        'themes' => $themes, 'questions' => $qs,
        'examen' => ['themes' => count($actifs), 'questions' => array_sum(array_map(static fn ($t) => min($t['tirees'], $t['validees']), $actifs)),
            'manque' => array_values(array_map(static fn ($t) => $t['code'], array_filter($actifs, static fn ($t) => $t['validees'] < $t['tirees'])))],
        'releve' => $dern ? ['le' => substr((string) $dern['le'], 0, 16), 'par' => (string) $dern['par'], 'aRevoir' => (int) $dern['a_revoir'], 'propositions' => (int) $dern['propositions'],
            'detail' => json_decode((string) $dern['detail'], true), 'envoyeA' => $dern['envoye_a'], 'erreur' => $dern['erreur']] : null,
        'reglages' => ['rapportA' => (string) setting('examensRapportA', '')],
        'candidats' => is_array($stats) ? ['dossiers' => (int) ($stats['dossiers'] ?? 0), 'reussis' => (int) ($stats['reussis'] ?? 0)] : null,
        'types' => EX_TYPES, 'sources' => EX_SOURCES,
    ];
}

/** GET /formation/examens/banque — pour le Recrutement (jeton de service) : thèmes actifs et questions VALIDÉES. */
function ep_ex_banque(): array
{
    $themes = array_map(static fn ($t) => ['code' => (string) $t['code'], 'nom' => ['fr' => (string) $t['nom_fr'], 'nl' => (string) $t['nom_nl']],
        'tirees' => (int) $t['tirees'], 'seuil' => (int) $t['seuil'], 'ordre' => (int) $t['ordre']], Db::rows('SELECT * FROM for_exam_theme WHERE actif = 1 ORDER BY ordre, code'));
    $qs = array_map(static function ($r) {
        $q = exQuestionOut($r);
        return ['code' => $q['code'], 'theme' => $q['theme'], 'type' => $q['type'], 'version' => $q['version'], 'enonce' => $q['enonce'], 'choix' => $q['choix'],
            'explication' => $q['explication'], 'source' => ['type' => $q['source']['type'], 'ref' => $q['source']['ref'], 'lib' => $q['source']['lib']], 'bloquante' => $q['bloquante']];
    }, Db::rows("SELECT * FROM for_exam_question WHERE statut = 'validee' ORDER BY theme, code"));
    // Un thème sans question validée n'est pas servi : il ne pourrait jamais être réussi (l'examen resterait bloqué).
    $avec = array_flip(array_column($qs, 'theme'));
    $themes = array_values(array_filter($themes, static fn ($t) => isset($avec[$t['code']])));
    $codes = array_flip(array_column($themes, 'code'));
    $qs = array_values(array_filter($qs, static fn ($q) => isset($codes[$q['theme']])));
    return ['themes' => $themes, 'questions' => $qs, 'lu' => date('c')];
}

function wr_ex_theme(string $code): array
{
    $t = Db::row('SELECT * FROM for_exam_theme WHERE code = ?', [$code]);
    if ($t === null) { return foRefus(404, 'thème inconnu'); }
    $b = body();
    $tirees = array_key_exists('tirees', $b) ? max(1, min(40, (int) $b['tirees'])) : (int) $t['tirees'];
    $seuil = array_key_exists('seuil', $b) ? max(50, min(100, (int) $b['seuil'])) : (int) $t['seuil'];
    $actif = array_key_exists('actif', $b) ? (!empty($b['actif']) ? 1 : 0) : (int) $t['actif'];
    $nomFr = isset($b['nom']['fr']) && trim((string) $b['nom']['fr']) !== '' ? mb_substr(trim((string) $b['nom']['fr']), 0, 120) : (string) $t['nom_fr'];
    $nomNl = isset($b['nom']['nl']) ? mb_substr(trim((string) $b['nom']['nl']), 0, 120) : (string) $t['nom_nl'];
    Db::exec('UPDATE for_exam_theme SET tirees = ?, seuil = ?, actif = ?, nom_fr = ?, nom_nl = ?, maj_le = ?, maj_par = ? WHERE code = ?',
        [$tirees, $seuil, $actif, $nomFr, $nomNl, date('Y-m-d H:i:s'), exQui(), $code]);
    return ['ok' => true];
}

/** POST /formation/examens/questions (création, brouillon) · PUT …/{id} (une question validée modifiée garde son statut, version + 1). */
function wr_ex_question(?int $id): array
{
    $b = body();
    $p = exPropre($b);
    if ($p === null) { return foRefus(422, 'Une question demande un énoncé et deux à six réponses.'); }
    $theme = (string) ($b['theme'] ?? '');
    if (Db::row('SELECT code FROM for_exam_theme WHERE code = ?', [$theme]) === null) { return foRefus(422, 'thème inconnu'); }
    $now = date('Y-m-d H:i:s'); $qui = exQui();
    $choix = json_encode($p['choix'], JSON_UNESCAPED_UNICODE);
    if ($id === null) {
        $code = exCodeNeuf($theme);
        Db::exec('INSERT INTO for_exam_question (code, theme, type, enonce_fr, enonce_nl, choix, explication_fr, explication_nl, source_type, source_ref, source_lib, bloquante, statut, origine, version, cree_le, cree_par, maj_le, maj_par)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
            [$code, $theme, $p['type'], $p['enonce_fr'], $p['enonce_nl'], $choix, $p['explication_fr'], $p['explication_nl'], $p['source_type'], $p['source_ref'], $p['source_lib'], $p['bloquante'], 'brouillon', 'manuel', $now, $qui, $now, $qui]);
        $r = Db::row('SELECT * FROM for_exam_question WHERE code = ?', [$code]);
        return exQuestionOut($r);
    }
    $r = Db::row('SELECT * FROM for_exam_question WHERE id = ?', [$id]);
    if ($r === null) { return foRefus(404, 'question inconnue'); }
    $statut = (string) $r['statut'];
    if ($statut === 'validee' && ($m = exManque($p)) !== '') { return foRefus(422, 'Question validée : ' . $m . '.'); }
    $change = $p['enonce_fr'] !== $r['enonce_fr'] || $choix !== $r['choix'] || $p['bloquante'] !== (int) $r['bloquante'];
    Db::exec('UPDATE for_exam_question SET theme = ?, type = ?, enonce_fr = ?, enonce_nl = ?, choix = ?, explication_fr = ?, explication_nl = ?, source_type = ?, source_ref = ?, source_lib = ?,
            bloquante = ?, version = version + ?, maj_le = ?, maj_par = ? WHERE id = ?',
        [$theme, $p['type'], $p['enonce_fr'], $p['enonce_nl'], $choix, $p['explication_fr'], $p['explication_nl'], $p['source_type'], $p['source_ref'], $p['source_lib'],
            $p['bloquante'], ($statut === 'validee' && $change) ? 1 : 0, $now, $qui, $id]);
    if ($statut === 'validee' && ($p['source_type'] !== $r['source_type'] || $p['source_ref'] !== $r['source_ref'])) { exPoserEmpreinte($id); }
    return exQuestionOut(Db::row('SELECT * FROM for_exam_question WHERE id = ?', [$id]));
}

/** L'empreinte de la source au jour de la validation (null : source non suivie ou muette). */
function exPoserEmpreinte(int $id): void
{
    $r = Db::row('SELECT source_type, source_ref FROM for_exam_question WHERE id = ?', [$id]);
    if ($r === null) { return; }
    $t = exTexteSource((string) $r['source_type'], (string) $r['source_ref']);
    Db::exec('UPDATE for_exam_question SET source_empreinte = ?, source_extrait = ? WHERE id = ?', [exEmpreinte($t), $t === null ? null : mb_substr($t, 0, 4000), $id]);
}

/** Changer le statut (validee · ecartee · brouillon) d'une ou plusieurs questions. Valider vérifie la question et pose l'empreinte. */
function wr_ex_statut(array $ids, string $statut): array
{
    if (!in_array($statut, ['validee', 'ecartee', 'brouillon'], true)) { return foRefus(422, 'statut inconnu'); }
    $ok = []; $refus = [];
    $now = date('Y-m-d H:i:s'); $qui = exQui();
    foreach (array_slice(array_unique(array_filter($ids)), 0, 300) as $id) {
        $r = Db::row('SELECT * FROM for_exam_question WHERE id = ?', [$id]);
        if ($r === null) { continue; }
        if ($statut === 'validee') {
            $q = exQuestionOut($r);
            $m = exManque(['type' => $q['type'], 'choix' => $q['choix']]);
            if ($m !== '') { $refus[] = ['code' => $q['code'], 'raison' => $m]; continue; }
            Db::exec("UPDATE for_exam_question SET statut = 'validee', valide_le = ?, valide_par = ?, revoir_raison = NULL, revoir_avant = NULL, revoir_apres = NULL,
                    version = version + ?, maj_le = ?, maj_par = ? WHERE id = ?", [$now, $qui, $r['statut'] === 'a_revoir' ? 1 : 0, $now, $qui, $id]);
            exPoserEmpreinte((int) $id);
        } else {
            Db::exec('UPDATE for_exam_question SET statut = ?, maj_le = ?, maj_par = ? WHERE id = ?', [$statut, $now, $qui, $id]);
        }
        $ok[] = (int) $id;
    }
    return ['ok' => $ok, 'refus' => $refus];
}

function wr_ex_supprimer(int $id): array
{
    $r = Db::row('SELECT statut FROM for_exam_question WHERE id = ?', [$id]);
    if ($r === null) { return foRefus(404, 'question inconnue'); }
    if ($r['statut'] === 'validee' || $r['statut'] === 'a_revoir') { return foRefus(409, 'Une question validée s’écarte (elle reste dans l’historique des examens), elle ne se supprime pas.'); }
    Db::exec('DELETE FROM for_exam_question WHERE id = ?', [$id]);
    return ['ok' => true];
}

function wr_ex_reglages(): array
{
    $b = body();
    $a = array_values(array_filter(array_map('trim', explode(',', (string) ($b['rapportA'] ?? ''))), static fn ($x) => $x !== ''));
    foreach ($a as $x) { if (!filter_var($x, FILTER_VALIDATE_EMAIL)) { return foRefus(422, 'Adresse illisible : ' . $x); } }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['examensRapportA', json_encode(implode(', ', $a))]);
    return ['ok' => true, 'rapportA' => implode(', ', $a)];
}
