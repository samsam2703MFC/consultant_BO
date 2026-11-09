<?php
declare(strict_types=1);

/*
 * MANUEL DU RÉSEAU — LES DEUX GUIDES ÉDITÉS DANS LE COCKPIT (lot 4b de la fusion, 06/11/2026).
 *
 * Le client a fait de ce module l'ÉDITEUR UNIQUE du référentiel du réseau ; le CRM le LIT (GET /formation/ref) et ne
 * garde que ce qui est propre à un dossier candidat (dates, formateurs désignés, validations, attestation…). Deux
 * guides que le CRM éditait encore passent ici :
 *
 *  1. Le Guide pré-ouverture : ses sections (titre et introduction FR/NL, type « points » ou « contacts », ordre) et
 *     leurs points (libellé FR/NL, qui — franchisé, L'Atelier By, ensemble, formateurs —, échéance en jours avant
 *     l'ouverture, preuve à garder FR/NL, ordre). Les clés (« admin », « bce »…) ne changent jamais : le CRM y relie
 *     les points de son countdown.
 *  2. Le Guide des formations et compétences : les 11 blocs (titre, rôle du formateur, formations FO, heures prévues)
 *     et leurs points (compétence C-… ou formation FO-… : libellés, description, phase pre/ouv/con, bloquante pour
 *     l'attestation, notée sur les 5 pôles, critère « Réussi si… » — proposé tant qu'il n'est pas accepté ou
 *     réécrit —, renouvellement en mois, heures, fiche technique, vidéo, profils) ; les noms des 5 pôles FR/NL et le
 *     repère du réseau tracé sur les graphiques.
 *
 *  ceo_ref_po_section   sections du Guide pré-ouverture (cle, rang, type, fr, nl, intro_fr, intro_nl)
 *  ceo_ref_po_point     leurs points (section, cle, rang, fr, nl, qui, j, preuve_fr, preuve_nl)
 *  ceo_ref_bloc         les 11 blocs (n, fr, nl, role, role_nl, fo, heures ; heures_semis = celles du départ)
 *  ceo_ref_item         leurs points (id, bloc, rang, libellés, phase, bloq, poles, critère accepté / proposé,
 *                       renouvellement, heures, fiche, vidéo, profils ; semis = les valeurs du départ, JSON)
 *  ceo_app_setting      rfPoles {fr: [5], nl: [5], repere, le, par} ; rfGuidesReprise_2026_11 (bilan de la reprise)
 *
 * Semis : data/formation/guides/*.json (recopiés du CRM par tools/formation/guides_depuis_crm.php — les fichiers du
 * CRM ne sont pas lisibles d'ici en production), posés quand les tables sont vides. Puis, UNE fois, les réglages que
 * le CRM gardait dans crm_settings (même base en production) : guide_preouverture, formation_referentiel,
 * formation_blocs_heures, formation_poles, formation_repere.
 *
 * Contrat : docs/contrat-api.md § Formation · Manuel du réseau (preouverture, blocs, poles). Doc : docs/formation.md.
 */

const RG_QUI = ['franchise', 'atelier', 'ensemble', 'formateurs'];
const RG_TYPES = ['points', 'contacts'];
const RG_PHASES = ['pre', 'ouv', 'con'];
const RG_PROFILS = ['gerant', 'vendeur', 'etudiant', 'multi'];
const RG_NOTE_MAX = 5;
const RG_MAX_SECTIONS = 20;
const RG_MAX_POINTS = 60;
const RG_REGLAGE_POLES = 'rfPoles';
const RG_DRAPEAU_REPRISE = 'rfGuidesReprise_2026_11';
/** L'ancien 5e pôle (« Autonomie », avant le choix du client du 5 nov. 2026) suit le défaut, comme dans le CRM. */
const RG_POLES_ANCIENS = ['Autonomie', 'Zelfstandigheid'];

/* ═══ Schéma, semis, reprise du CRM ═══════════════════════════════════════════════════════════════ */

/** Les tables des deux guides, leur semis et la reprise des réglages du CRM (appelé par ensureReferentiel). */
function ensureRefGuides(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ref_po_section ('
        . 'cle VARCHAR(40) NOT NULL PRIMARY KEY,'
        . 'rang INT NOT NULL DEFAULT 0,'
        . "type VARCHAR(10) NOT NULL DEFAULT 'points',"
        . 'fr VARCHAR(160) NOT NULL,'
        . "nl VARCHAR(160) NOT NULL DEFAULT '',"
        . 'intro_fr TEXT NULL,'
        . 'intro_nl TEXT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ref_po_point ('
        . 'section VARCHAR(40) NOT NULL,'
        . 'cle VARCHAR(40) NOT NULL,'
        . 'rang INT NOT NULL DEFAULT 0,'
        . 'fr VARCHAR(200) NOT NULL,'
        . "nl VARCHAR(200) NOT NULL DEFAULT '',"
        . "qui VARCHAR(12) NOT NULL DEFAULT 'franchise',"
        . 'j INT NULL,'
        . "preuve_fr VARCHAR(160) NOT NULL DEFAULT '',"
        . "preuve_nl VARCHAR(160) NOT NULL DEFAULT '',"
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL,'
        . 'PRIMARY KEY (section, cle),'
        . 'KEY idx_rang (section, rang)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ref_bloc ('
        . 'n TINYINT NOT NULL PRIMARY KEY,'
        . 'fr VARCHAR(160) NOT NULL,'
        . "nl VARCHAR(160) NOT NULL DEFAULT '',"
        . "role VARCHAR(120) NOT NULL DEFAULT '',"
        . "role_nl VARCHAR(120) NOT NULL DEFAULT '',"
        . "fo VARCHAR(200) NOT NULL DEFAULT '',"
        . 'heures DECIMAL(5,1) NOT NULL DEFAULT 0,'
        . 'heures_semis DECIMAL(5,1) NOT NULL DEFAULT 0,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Les codes C-… portent des accents (C-PROD.Pâtisserie) : comparés à l'octet près, comme ceo_ref_corresp.
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ref_item ('
        . 'id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,'
        . 'bloc TINYINT NOT NULL,'
        . 'rang INT NOT NULL DEFAULT 0,'
        . 'fr VARCHAR(200) NOT NULL,'
        . "nl VARCHAR(200) NOT NULL DEFAULT '',"
        . 'dfr TEXT NULL,'
        . 'dnl TEXT NULL,'
        . "fo VARCHAR(20) NOT NULL DEFAULT '',"
        . "phase VARCHAR(4) NOT NULL DEFAULT 'pre',"
        . 'bloq TINYINT(1) NOT NULL DEFAULT 1,'
        . 'poles TINYINT(1) NOT NULL DEFAULT 0,'
        . 'critere_fr TEXT NULL,'
        . 'critere_nl TEXT NULL,'
        . 'critere_accepte TINYINT(1) NOT NULL DEFAULT 0,'
        . 'critere_prop_fr TEXT NULL,'
        . 'critere_prop_nl TEXT NULL,'
        . 'renouvellement INT NOT NULL DEFAULT 0,'
        . "heures VARCHAR(12) NOT NULL DEFAULT '',"
        . "fiche VARCHAR(500) NOT NULL DEFAULT '',"
        . "fiche_nom VARCHAR(120) NOT NULL DEFAULT '',"
        . "video VARCHAR(500) NOT NULL DEFAULT '',"
        . "profils VARCHAR(80) NOT NULL DEFAULT '[]',"
        . 'semis TEXT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL,'
        . 'KEY idx_bloc (bloc, rang)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    rgSemer();
    rgReprendreCrmUneFois();
}

/** Le contenu de départ d'un guide (data/formation/guides/<nom>.json). */
function rgDepart(string $nom): array
{
    static $c = [];
    return $c[$nom] ??= (json_decode((string) @file_get_contents(__DIR__ . '/../data/formation/guides/' . $nom . '.json'), true) ?: []);
}

/**
 * Le semis : les sections quand la table est vide (une écriture refuse un guide vide : elle ne l'est donc qu'à
 * l'installation), les blocs et leurs points quand la table des blocs est vide. Un point retiré par une personne
 * n'est jamais remis.
 */
function rgSemer(): void
{
    $now = date('Y-m-d H:i:s');
    if ((int) (Db::row('SELECT COUNT(*) AS n FROM ceo_ref_po_section')['n'] ?? 0) === 0) {
        $s = rgDepart('preouverture')['sections'] ?? [];
        if ($s !== []) { rgSectionsEcrire($s, 'Guide de départ (CRM)', $now); }
    }
    if ((int) (Db::row('SELECT COUNT(*) AS n FROM ceo_ref_bloc')['n'] ?? 0) === 0) {
        foreach (rgDepart('formations')['blocs'] ?? [] as $b) {
            Db::exec('INSERT IGNORE INTO ceo_ref_bloc (n, fr, nl, role, role_nl, fo, heures, heures_semis, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $b['n'], (string) $b['fr'], (string) $b['nl'], (string) $b['role'], (string) $b['role_nl'], (string) $b['fo'],
                    (float) $b['heures'], (float) $b['heures'], $now, 'Guide de départ (CRM)']);
            foreach ($b['items'] ?? [] as $i => $it) {
                $semis = ['phase' => (string) $it['phase'], 'bloq' => (bool) $it['bloq'], 'poles' => (bool) $it['poles'],
                    'renouvellement' => (int) $it['renouvellement'], 'profils' => array_values((array) $it['profils'])];
                Db::exec('INSERT IGNORE INTO ceo_ref_item (id, bloc, rang, fr, nl, dfr, dnl, fo, phase, bloq, poles, critere_accepte, critere_prop_fr, critere_prop_nl,
                            renouvellement, profils, semis, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)',
                    [(string) $it['id'], (int) $b['n'], $i + 1, (string) $it['fr'], (string) $it['nl'], (string) $it['dfr'] ?: null, (string) $it['dnl'] ?: null,
                        (string) $it['fo'], $semis['phase'], $semis['bloq'] ? 1 : 0, $semis['poles'] ? 1 : 0,
                        (string) $it['critere_prop_fr'] ?: null, (string) $it['critere_prop_nl'] ?: null, $semis['renouvellement'],
                        rgJson($semis['profils']), rgJson($semis), $now, 'Guide de départ (CRM)']);
            }
        }
    }
}

/**
 * La reprise, UNE fois, des réglages que le CRM gardait (crm_settings, même base en production) : le guide
 * pré-ouverture entier, les réglages par compétence, les heures des blocs, les noms des pôles, le repère. Chaque
 * réglage absent laisse le contenu de départ. Bilan dans rfGuidesReprise_2026_11 et au journal.
 */
function rgReprendreCrmUneFois(): void
{
    if (setting(RG_DRAPEAU_REPRISE) !== null) { return; }
    $bilan = rgReprendreCrm();
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [RG_DRAPEAU_REPRISE, json_encode($bilan, JSON_UNESCAPED_UNICODE)]);
    if (!empty($bilan['disponible'])) {
        try { journalAdd('Installation', 'Formation', 'Manuel du réseau', 'Reprise des guides du CRM : ' . rgBilanTexte($bilan)); } catch (Throwable $e) { /* le journal ne bloque pas */ }
    }
}

function rgBilanTexte(array $b): string
{
    if (!$b['preouverture'] && !$b['competences'] && !$b['heures'] && !$b['poles'] && $b['repere'] === null && !$b['refus']) {
        return 'aucun réglage à reprendre, le contenu de départ sert';
    }
    $p = [];
    $p[] = $b['preouverture'] ? 'Guide pré-ouverture repris (' . $b['preouverture'] . ' sections)' : 'Guide pré-ouverture : celui de départ';
    $p[] = $b['competences'] . ' compétence(s) réglée(s)' . ($b['inconnues'] ? ' (' . count($b['inconnues']) . ' inconnue(s) : ' . implode(', ', $b['inconnues']) . ')' : '');
    $p[] = $b['heures'] . ' bloc(s) aux heures réglées';
    $p[] = $b['poles'] ? 'noms des pôles repris' : 'noms des pôles : ceux de départ';
    $p[] = $b['repere'] !== null ? 'repère ' . $b['repere'] : 'repère de départ';
    return implode(' · ', $p);
}

/** La reprise elle-même (rend son bilan) ; rien n'est écrit quand crm_settings n'est pas dans la base. */
function rgReprendreCrm(): array
{
    $b = ['le' => date('c'), 'disponible' => false, 'preouverture' => 0, 'competences' => 0, 'inconnues' => [], 'heures' => 0, 'poles' => false, 'repere' => null, 'refus' => []];
    $t = Db::row('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['crm_settings']);
    if ((int) ($t['n'] ?? 0) === 0) { return $b; }
    $b['disponible'] = true;
    $lire = static function (string $k) { $r = Db::row('SELECT svalue FROM crm_settings WHERE skey = ?', [$k]); $v = $r['svalue'] ?? null; return is_string($v) && trim($v) !== '' ? $v : null; };
    $now = date('Y-m-d H:i:s');
    $par = 'Reprise du CRM';
    // 1. Le guide pré-ouverture : {sections: [{k, titre {fr, nl}, intro {fr, nl}, type, points [{k, titre, qui, j, preuve}]}]}.
    $g = json_decode((string) $lire('guide_preouverture'), true);
    if (is_array($g) && is_array($g['sections'] ?? null) && $g['sections'] !== []) {
        $s = rgSectionsDepuisCrm($g['sections']);
        [$propres, $ko] = rgSectionsValider($s);
        if ($ko === [] && $propres !== []) { rgSectionsEcrire($propres, $par, $now); $b['preouverture'] = count($propres); }
        else { $b['refus'][] = 'guide_preouverture : ' . implode(' ; ', array_slice($ko, 0, 5)); }
    }
    // 2. Les réglages par compétence : {id: {phase, bloq, poles, crit, critNl, renouv, heures, fiche, ficheNom, video, profils}}.
    $reg = json_decode((string) $lire('formation_referentiel'), true);
    foreach (is_array($reg) ? $reg : [] as $id => $r) {
        $id = (string) $id;
        if (!is_array($r)) { continue; }
        if (Db::row('SELECT id FROM ceo_ref_item WHERE id = ?', [$id]) === null) { $b['inconnues'][] = mb_substr($id, 0, 40); continue; }
        $set = []; $p = [];
        if (in_array($r['phase'] ?? null, RG_PHASES, true)) { $set[] = 'phase = ?'; $p[] = $r['phase']; }
        if (array_key_exists('bloq', $r)) { $set[] = 'bloq = ?'; $p[] = $r['bloq'] ? 1 : 0; }
        if (array_key_exists('poles', $r)) { $set[] = 'poles = ?'; $p[] = $r['poles'] ? 1 : 0; }
        $crit = trim((string) ($r['crit'] ?? ''));
        if ($crit !== '') { $set[] = 'critere_fr = ?, critere_nl = ?, critere_accepte = 1'; array_push($p, mb_substr($crit, 0, 400), mb_substr(trim((string) ($r['critNl'] ?? '')), 0, 400) ?: null); }
        if (array_key_exists('renouv', $r)) { $set[] = 'renouvellement = ?'; $p[] = max(0, min(60, (int) $r['renouv'])); }
        if (trim((string) ($r['heures'] ?? '')) !== '') { $set[] = 'heures = ?'; $p[] = mb_substr(trim((string) $r['heures']), 0, 12); }
        foreach (['fiche' => 'fiche', 'video' => 'video'] as $k => $col) { $u = rgUrl((string) ($r[$k] ?? '')); if ($u !== '') { $set[] = $col . ' = ?'; $p[] = $u; } }
        if (trim((string) ($r['ficheNom'] ?? '')) !== '') { $set[] = 'fiche_nom = ?'; $p[] = mb_substr(trim((string) $r['ficheNom']), 0, 120); }
        if (is_array($r['profils'] ?? null)) { $set[] = 'profils = ?'; $p[] = rgJson(array_values(array_intersect(RG_PROFILS, array_map('strval', $r['profils'])))); }
        if ($set === []) { continue; }
        $set[] = 'maj_le = ?, maj_par = ?';
        array_push($p, $now, $par, $id);
        Db::exec('UPDATE ceo_ref_item SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
        $b['competences']++;
    }
    // 3. Les heures des blocs : {n: heures}.
    $h = json_decode((string) $lire('formation_blocs_heures'), true);
    foreach (is_array($h) ? $h : [] as $n => $v) {
        if (!is_numeric($v)) { continue; }
        $b['heures'] += Db::exec('UPDATE ceo_ref_bloc SET heures = ?, maj_le = ?, maj_par = ? WHERE n = ?', [max(0, min(999, (float) $v)), $now, $par, (int) $n]) > 0 ? 1 : 0;
    }
    // 4. Les noms des pôles [{fr, nl} × 5] et 5. le repère.
    $np = json_decode((string) $lire('formation_poles'), true);
    $rep = $lire('formation_repere');
    if (is_array($np) || $rep !== null) {
        $cur = rgPoles();
        if (is_array($np)) {
            foreach (range(0, RG_NOTE_MAX - 1) as $i) {
                foreach (['fr', 'nl'] as $l) {
                    $v = trim((string) ($np[$i][$l] ?? ''));
                    if ($v !== '' && !in_array($v, RG_POLES_ANCIENS, true)) { $cur[$l][$i] = mb_substr($v, 0, 60); $b['poles'] = true; }
                }
            }
        }
        if ($rep !== null && is_numeric($rep)) { $cur['repere'] = max(0.0, min((float) RG_NOTE_MAX, (float) $rep)); $b['repere'] = $cur['repere']; }
        rgPolesEcrire($cur, $par);
    }
    return $b;
}

/** Le guide du CRM (k, titre {fr, nl}, intro {fr, nl}, type, points [{k, titre, qui, j, preuve}]) dans la forme du contrat. */
function rgSectionsDepuisCrm(array $sections): array
{
    $t = static fn ($v, string $l): string => is_array($v) ? (string) ($v[$l] ?? '') : ($l === 'fr' ? (string) $v : '');
    $out = [];
    foreach ($sections as $s) {
        if (!is_array($s)) { continue; }
        $pts = [];
        foreach ((array) ($s['points'] ?? []) as $p) {
            if (!is_array($p) || trim($t($p['titre'] ?? '', 'fr')) === '') { continue; }   // le CRM écartait un point sans libellé
            $pts[] = ['cle' => (string) ($p['k'] ?? ''), 'fr' => $t($p['titre'] ?? '', 'fr'), 'nl' => $t($p['titre'] ?? '', 'nl'), 'qui' => (string) ($p['qui'] ?? 'franchise'),
                'j' => $p['j'] ?? null, 'preuve_fr' => $t($p['preuve'] ?? '', 'fr'), 'preuve_nl' => $t($p['preuve'] ?? '', 'nl')];
        }
        $out[] = ['cle' => (string) ($s['k'] ?? ''), 'type' => (string) ($s['type'] ?? 'points'), 'fr' => $t($s['titre'] ?? '', 'fr'), 'nl' => $t($s['titre'] ?? '', 'nl'),
            'intro_fr' => $t($s['intro'] ?? '', 'fr'), 'intro_nl' => $t($s['intro'] ?? '', 'nl'), 'points' => $pts];
    }
    return $out;
}

/* ═══ Lectures (GET /formation/ref : preouverture, blocs, poles) ══════════════════════════════════ */

/** Ce que les deux guides ajoutent au contrat de lecture ; `$detail` (écrans du Cockpit) ajoute sans rien retirer. */
function rgRef(bool $detail): array
{
    $out = ['preouverture' => ['sections' => rgSections($detail)], 'blocs' => rgBlocs($detail), 'poles' => rgPolesSortie()];
    if ($detail) {
        $out['guidesReprise'] = setting(RG_DRAPEAU_REPRISE);
        $out['guidesQui'] = RG_QUI;
        $out['guidesProfils'] = RG_PROFILS;
    }
    return $out;
}

/** Les sections du Guide pré-ouverture et leurs points, dans l'ordre. */
function rgSections(bool $detail = false): array
{
    $pts = [];
    foreach (Db::rows('SELECT * FROM ceo_ref_po_point ORDER BY section, rang, cle') as $p) {
        $o = ['cle' => (string) $p['cle'], 'fr' => (string) $p['fr'], 'nl' => (string) $p['nl'], 'qui' => (string) $p['qui'],
            'j' => $p['j'] === null ? null : (int) $p['j'], 'preuve_fr' => (string) $p['preuve_fr'], 'preuve_nl' => (string) $p['preuve_nl']];
        $pts[(string) $p['section']][] = $o;
    }
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_ref_po_section ORDER BY rang, cle') as $s) {
        $o = ['cle' => (string) $s['cle'], 'type' => (string) $s['type'], 'fr' => (string) $s['fr'], 'nl' => (string) $s['nl'],
            'intro_fr' => (string) ($s['intro_fr'] ?? ''), 'intro_nl' => (string) ($s['intro_nl'] ?? ''), 'points' => $pts[(string) $s['cle']] ?? []];
        if ($detail) { $o += ['majLe' => function_exists('foIso') ? foIso($s['maj_le']) : (string) $s['maj_le'], 'majPar' => $s['maj_par']]; }
        $out[] = $o;
    }
    return $out;
}

/** Les 11 blocs et leurs points, dans l'ordre. */
function rgBlocs(bool $detail = false): array
{
    $items = [];
    foreach (Db::rows('SELECT * FROM ceo_ref_item ORDER BY bloc, rang, id') as $r) { $items[(int) $r['bloc']][] = rgItemSortie($r, $detail); }
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_ref_bloc ORDER BY n') as $b) {
        $o = ['n' => (int) $b['n'], 'fr' => (string) $b['fr'], 'nl' => (string) $b['nl'], 'role' => (string) $b['role'], 'role_nl' => (string) $b['role_nl'],
            'fo' => (string) $b['fo'], 'heures' => (float) $b['heures'], 'items' => $items[(int) $b['n']] ?? []];
        if ($detail) { $o += ['heures_semis' => (float) $b['heures_semis'], 'majLe' => function_exists('foIso') ? foIso($b['maj_le']) : (string) $b['maj_le'], 'majPar' => $b['maj_par']]; }
        $out[] = $o;
    }
    return $out;
}

/** Un point de formation : le critère en vigueur est l'accepté, sinon la proposition (`critere_propose`). */
function rgItemSortie(array $r, bool $detail = false): array
{
    $accepte = (int) $r['critere_accepte'] === 1 && trim((string) $r['critere_fr']) !== '';
    $propFr = (string) ($r['critere_prop_fr'] ?? '');
    $o = ['id' => (string) $r['id'], 'fr' => (string) $r['fr'], 'nl' => (string) $r['nl'], 'dfr' => (string) ($r['dfr'] ?? ''), 'dnl' => (string) ($r['dnl'] ?? ''),
        'fo' => (string) $r['fo'], 'phase' => (string) $r['phase'], 'bloq' => (int) $r['bloq'] === 1, 'poles' => (int) $r['poles'] === 1,
        'critere_fr' => $accepte ? (string) $r['critere_fr'] : $propFr, 'critere_nl' => $accepte ? (string) ($r['critere_nl'] ?? '') : (string) ($r['critere_prop_nl'] ?? ''),
        'critere_propose' => !$accepte && $propFr !== '', 'renouvellement' => (int) $r['renouvellement'], 'heures' => (string) $r['heures'],
        'fiche' => (string) $r['fiche'], 'fiche_nom' => (string) $r['fiche_nom'], 'video' => (string) $r['video'], 'profils' => rgListe($r['profils'])];
    if ($detail) {
        $s = json_decode((string) ($r['semis'] ?? ''), true);
        $o += ['critere_prop_fr' => $propFr, 'critere_prop_nl' => (string) ($r['critere_prop_nl'] ?? ''), 'semis' => is_array($s) ? $s : null,
            'majLe' => function_exists('foIso') ? foIso($r['maj_le']) : (string) $r['maj_le'], 'majPar' => $r['maj_par']];
    }
    return $o;
}

/** Les noms des 5 pôles et le repère : le réglage, sinon le départ ; toujours cinq noms dans chaque langue. */
function rgPoles(): array
{
    $d = rgDepart('formations')['poles'] ?? ['fr' => [], 'nl' => [], 'repere' => 4];
    $s = setting(RG_REGLAGE_POLES);
    $out = ['fr' => [], 'nl' => [], 'repere' => (float) ($d['repere'] ?? 4)];
    foreach (range(0, RG_NOTE_MAX - 1) as $i) {
        foreach (['fr', 'nl'] as $l) {
            $v = is_array($s) ? trim((string) ($s[$l][$i] ?? '')) : '';
            $out[$l][$i] = $v !== '' ? $v : (string) ($d[$l][$i] ?? '');
        }
    }
    if (is_array($s) && isset($s['repere']) && is_numeric($s['repere'])) { $out['repere'] = max(0.0, min((float) RG_NOTE_MAX, (float) $s['repere'])); }
    if (is_array($s)) { $out['le'] = $s['le'] ?? null; $out['par'] = $s['par'] ?? null; }
    return $out;
}

function rgPolesSortie(): array
{
    $p = rgPoles();
    return ['fr' => $p['fr'], 'nl' => $p['nl'], 'repere' => $p['repere']];
}

function rgPolesEcrire(array $p, string $par): void
{
    $v = ['fr' => array_values($p['fr']), 'nl' => array_values($p['nl']), 'repere' => (float) $p['repere'], 'le' => date('Y-m-d H:i:s'), 'par' => $par];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [RG_REGLAGE_POLES, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}

/** La dernière écriture dans les deux guides (rfVersion l'ajoute à la version du référentiel). */
function rgVersion(): ?string
{
    $max = null;
    foreach (['ceo_ref_po_section', 'ceo_ref_po_point', 'ceo_ref_bloc', 'ceo_ref_item'] as $t) {
        try { $m = Db::row('SELECT MAX(maj_le) AS m FROM ' . $t)['m'] ?? null; } catch (Throwable $e) { $m = null; }
        if ($m !== null && ($max === null || $m > $max)) { $max = (string) $m; }
    }
    $p = setting(RG_REGLAGE_POLES);
    if (is_array($p) && is_string($p['le'] ?? null) && ($max === null || $p['le'] > $max)) { $max = $p['le']; }
    return $max;
}

/* ═══ Écritures ═══════════════════════════════════════════════════════════════════════════════════ */

/**
 * PUT /formation/ref/preouverture — {sections: [{cle, type, fr, nl, intro_fr, intro_nl, points: [{cle, fr, nl, qui, j,
 * preuve_fr, preuve_nl}]}]} : le guide entier, dans son ordre. Une clé vide reçoit une clé neuve (tirée du libellé) ;
 * une clé existante ne change jamais. Refus 422 (rien n'est écrit) : guide vide, section ou point sans libellé, qui
 * inconnu, échéance hors de −60…400, trop de sections ou de points.
 */
function wr_rg_preouverture(): array
{
    $b = body();
    if (!is_array($b['sections'] ?? null)) { return rfRefus(422, 'sections attendues (liste)'); }
    [$s, $ko] = rgSectionsValider($b['sections']);
    if ($ko !== []) { return rfRefus(422, 'guide refusé', ['invalides' => $ko]); }
    if ($s === []) { return rfRefus(422, 'le guide ne peut pas être vide'); }
    $par = foPar($b);
    $avant = rgSections();
    rgSectionsEcrire($s, $par, date('Y-m-d H:i:s'));
    $nP = array_sum(array_map(static fn ($x) => count($x['points']), $s));
    $nAv = array_sum(array_map(static fn ($x) => count($x['points']), $avant));
    try { journalAdd($par, 'Formation', 'Guide pré-ouverture', count($s) . ' section(s), ' . $nP . ' point(s) (avant : ' . count($avant) . ' section(s), ' . $nAv . ' point(s))'); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'sections' => rgSections(true)];
}

/** [sections propres, erreurs] : les règles du contrat ; les clés manquantes reçoivent une clé neuve, unique. */
function rgSectionsValider(array $sections): array
{
    $ko = []; $out = []; $clesS = [];
    if (count($sections) > RG_MAX_SECTIONS) { $ko[] = RG_MAX_SECTIONS . ' sections au plus'; }
    foreach (array_slice($sections, 0, RG_MAX_SECTIONS) as $i => $s) {
        $n = 'section ' . ($i + 1);
        if (!is_array($s)) { $ko[] = $n . ' : objet attendu'; continue; }
        $fr = rgTexte($s['fr'] ?? '', 160);
        if ($fr === '') { $ko[] = $n . ' : titre français manquant'; }
        $type = in_array($s['type'] ?? 'points', RG_TYPES, true) ? (string) ($s['type'] ?? 'points') : null;
        if ($type === null) { $ko[] = $n . ' : type « points » ou « contacts »'; $type = 'points'; }
        $cle = rgCleUnique(rgCle($s['cle'] ?? '') ?: rgCle($fr) ?: 's' . ($i + 1), $clesS);
        $pts = []; $clesP = [];
        $liste = is_array($s['points'] ?? null) ? $s['points'] : [];
        if (count($liste) > RG_MAX_POINTS) { $ko[] = $n . ' : ' . RG_MAX_POINTS . ' points au plus'; }
        foreach (array_slice($liste, 0, RG_MAX_POINTS) as $k => $p) {
            $np = $n . ', point ' . ($k + 1);
            if (!is_array($p)) { $ko[] = $np . ' : objet attendu'; continue; }
            $pfr = rgTexte($p['fr'] ?? '', 200);
            if ($pfr === '') { $ko[] = $np . ' : libellé français manquant'; }
            $qui = (string) ($p['qui'] ?? 'franchise');
            if (!in_array($qui, RG_QUI, true)) { $ko[] = $np . ' : qui inconnu (' . mb_substr($qui, 0, 20) . ')'; $qui = 'franchise'; }
            $j = $p['j'] ?? null;
            if ($j === '' || $j === null) { $j = null; }
            elseif (!is_numeric($j) || (int) $j != $j || (int) $j < -60 || (int) $j > 400) { $ko[] = $np . ' : échéance en jours avant l’ouverture, de -60 à 400 (vide = sans date)'; $j = null; }
            else { $j = (int) $j; }
            $pts[] = ['cle' => rgCleUnique(rgCle($p['cle'] ?? '') ?: rgCle($pfr) ?: 'p' . ($k + 1), $clesP), 'fr' => $pfr, 'nl' => rgTexte($p['nl'] ?? '', 200), 'qui' => $qui, 'j' => $j,
                'preuve_fr' => rgTexte($p['preuve_fr'] ?? '', 160), 'preuve_nl' => rgTexte($p['preuve_nl'] ?? '', 160)];
        }
        $out[] = ['cle' => $cle, 'type' => $type, 'fr' => $fr, 'nl' => rgTexte($s['nl'] ?? '', 160), 'intro_fr' => rgTexte($s['intro_fr'] ?? '', 1200),
            'intro_nl' => rgTexte($s['intro_nl'] ?? '', 1200), 'points' => $pts];
    }
    return [$out, $ko];
}

/** Remplace le guide entier, dans une transaction (un guide à moitié écrit n'existe jamais). */
function rgSectionsEcrire(array $sections, string $par, string $now): void
{
    [$s] = rgSectionsValider($sections);
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        Db::exec('DELETE FROM ceo_ref_po_point');
        Db::exec('DELETE FROM ceo_ref_po_section');
        foreach ($s as $i => $x) {
            Db::exec('INSERT INTO ceo_ref_po_section (cle, rang, type, fr, nl, intro_fr, intro_nl, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$x['cle'], $i + 1, $x['type'], $x['fr'], $x['nl'], $x['intro_fr'] ?: null, $x['intro_nl'] ?: null, $now, $par]);
            foreach ($x['points'] as $k => $p) {
                Db::exec('INSERT INTO ceo_ref_po_point (section, cle, rang, fr, nl, qui, j, preuve_fr, preuve_nl, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$x['cle'], $p['cle'], $k + 1, $p['fr'], $p['nl'], $p['qui'], $p['j'], $p['preuve_fr'], $p['preuve_nl'], $now, $par]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * PUT /formation/ref/blocs/{n} — {fr?, nl?, role?, role_nl?, fo?, heures?, ordre?: [ids]} : clé absente = inchangée.
 * `ordre` = les points du bloc dans leur nouvel ordre (exactement les mêmes, sinon 422).
 */
function wr_rg_bloc(int $n): array
{
    $bl = Db::row('SELECT * FROM ceo_ref_bloc WHERE n = ?', [$n]);
    if ($bl === null) { return rfRefus(404, 'bloc inconnu'); }
    $b = body();
    $set = []; $p = []; $quoi = []; $ko = [];
    foreach (['fr' => 160, 'nl' => 160, 'role' => 120, 'role_nl' => 120, 'fo' => 200] as $k => $max) {
        if (!array_key_exists($k, $b)) { continue; }
        $v = rgTexte($b[$k], $max);
        if ($k === 'fr' && $v === '') { $ko['fr'] = ['titre français manquant']; continue; }
        $set[] = $k . ' = ?'; $p[] = $v; $quoi[] = $k . ' « ' . mb_substr($v, 0, 60) . ' »';
    }
    if (array_key_exists('heures', $b)) {
        $h = is_numeric(str_replace(',', '.', (string) $b['heures'])) ? (float) str_replace(',', '.', (string) $b['heures']) : null;
        if ($h === null || $h < 0 || $h > 999) { $ko['heures'] = ['de 0 à 999']; }
        else { $set[] = 'heures = ?'; $p[] = round($h, 1); $quoi[] = 'heures ' . round($h, 1); }
    }
    $ordre = null;
    if (array_key_exists('ordre', $b)) {
        $ids = array_map(static fn ($r) => (string) $r['id'], Db::rows('SELECT id FROM ceo_ref_item WHERE bloc = ? ORDER BY rang, id', [$n]));
        $o = is_array($b['ordre']) ? array_values(array_map('strval', array_filter($b['ordre'], 'is_scalar'))) : null;
        $a = $o; if ($a !== null) { sort($a); } $c = $ids; sort($c);
        if ($o === null || $a !== $c || count(array_unique($o)) !== count($o)) { $ko['ordre'] = ['les points du bloc, chacun une fois']; } else { $ordre = $o; $quoi[] = 'ordre des points'; }
    }
    if ($ko !== []) { return rfRefus(422, 'bloc refusé', ['invalides' => $ko]); }
    if ($set === [] && $ordre === null) { return rfRefus(422, 'rien à changer : fr, nl, role, role_nl, fo, heures ou ordre'); }
    $par = foPar($b);
    $now = date('Y-m-d H:i:s');
    if ($set !== []) {
        $set[] = 'maj_le = ?, maj_par = ?';
        array_push($p, $now, $par, $n);
        Db::exec('UPDATE ceo_ref_bloc SET ' . implode(', ', $set) . ' WHERE n = ?', $p);
    }
    if ($ordre !== null) {
        foreach ($ordre as $i => $id) { Db::exec('UPDATE ceo_ref_item SET rang = ?, maj_le = ?, maj_par = ? WHERE id = ? AND bloc = ?', [$i + 1, $now, $par, $id, $n]); }
    }
    try { journalAdd($par, 'Formation', 'Guide des formations · bloc ' . $n, implode(' · ', $quoi)); } catch (Throwable $e) { /* */ }
    $bloc = null;
    foreach (rgBlocs(true) as $x) { if ($x['n'] === $n) { $bloc = $x; } }
    return ['ok' => true, 'bloc' => $bloc];
}

/**
 * PUT /formation/ref/items/{id} — {fr?, nl?, dfr?, dnl?, fo?, phase?, bloq?, poles?, critere_fr?, critere_nl?,
 * accepter?, renouvellement?, heures?, fiche?, fiche_nom?, video?, profils?, bloc?} : clé absente = inchangée.
 * `critere_fr` non vide = critère accepté (réécrit) ; vide = retour à la proposition. `accepter: true` accepte la
 * proposition telle quelle. `bloc` déplace le point (à la fin du bloc visé).
 */
function wr_rg_item(string $id): array
{
    $r = Db::row('SELECT * FROM ceo_ref_item WHERE id = ?', [$id]);
    if ($r === null) { return rfRefus(404, 'point de formation inconnu'); }
    $b = body();
    [$set, $p, $quoi, $ko] = rgItemChamps($b, $r);
    if ($ko !== []) { return rfRefus(422, 'point refusé', ['invalides' => $ko]); }
    if ($set === []) { return rfRefus(422, 'rien à changer'); }
    $par = foPar($b);
    $set[] = 'maj_le = ?, maj_par = ?';
    array_push($p, date('Y-m-d H:i:s'), $par, $id);
    Db::exec('UPDATE ceo_ref_item SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
    try { journalAdd($par, 'Formation', 'Guide des formations · ' . $id . ' · ' . $r['fr'], implode(' · ', $quoi)); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'item' => rgItemSortie(Db::row('SELECT * FROM ceo_ref_item WHERE id = ?', [$id]) ?? $r, true)];
}

/** [colonnes, valeurs, ce qui change (journal), refus] d'une écriture sur un point ; $r = la ligne actuelle (ou null : création). */
function rgItemChamps(array $b, ?array $r): array
{
    $set = []; $p = []; $quoi = []; $ko = [];
    foreach (['fr' => 200, 'nl' => 200, 'dfr' => 2000, 'dnl' => 2000, 'fiche_nom' => 120, 'heures' => 12] as $k => $max) {
        if (!array_key_exists($k, $b)) { continue; }
        $v = rgTexte($b[$k], $max);
        if ($k === 'fr' && $v === '') { $ko['fr'] = ['libellé français manquant']; continue; }
        $set[] = $k . ' = ?'; $p[] = in_array($k, ['dfr', 'dnl'], true) && $v === '' ? null : $v; $quoi[] = $k;
    }
    if (array_key_exists('fo', $b)) {
        $fo = strtoupper(rgTexte($b['fo'], 20));
        if ($fo !== '' && !preg_match('/^FO-\d{1,3}$/', $fo)) { $ko['fo'] = ['FO-<n> ou vide']; } else { $set[] = 'fo = ?'; $p[] = $fo; $quoi[] = 'formation ' . ($fo ?: '—'); }
    }
    if (array_key_exists('phase', $b)) {
        if (!in_array($b['phase'], RG_PHASES, true)) { $ko['phase'] = ['pre, ouv ou con']; } else { $set[] = 'phase = ?'; $p[] = $b['phase']; $quoi[] = 'phase ' . $b['phase']; }
    }
    foreach (['bloq' => 'bloquante', 'poles' => '5 pôles'] as $k => $lib) {
        if (!array_key_exists($k, $b)) { continue; }
        if (!is_bool($b[$k])) { $ko[$k] = ['vrai ou faux']; continue; }
        $set[] = $k . ' = ?'; $p[] = $b[$k] ? 1 : 0; $quoi[] = $lib . ' ' . ($b[$k] ? 'oui' : 'non');
    }
    if (!empty($b['accepter'])) {
        $prop = $r !== null ? trim((string) ($r['critere_prop_fr'] ?? '')) : '';
        if ($prop === '') { $ko['accepter'] = ['aucune proposition à accepter']; }
        else { $set[] = 'critere_fr = critere_prop_fr, critere_nl = critere_prop_nl, critere_accepte = 1'; $quoi[] = 'critère proposé accepté'; }
    } elseif (array_key_exists('critere_fr', $b)) {
        $c = rgTexte($b['critere_fr'], 400);
        if ($c === '') { $set[] = 'critere_fr = NULL, critere_nl = NULL, critere_accepte = 0'; $quoi[] = 'critère : retour à la proposition'; }
        else {
            $nl = array_key_exists('critere_nl', $b) ? rgTexte($b['critere_nl'], 400) : ($r !== null && (int) $r['critere_accepte'] === 1 ? (string) ($r['critere_nl'] ?? '') : '');
            $set[] = 'critere_fr = ?, critere_nl = ?, critere_accepte = 1'; array_push($p, $c, $nl !== '' ? $nl : null); $quoi[] = 'critère réécrit';
        }
    } elseif (array_key_exists('critere_nl', $b)) {
        if ($r === null || (int) $r['critere_accepte'] !== 1) { $ko['critere_nl'] = ['accepter ou réécrire d’abord le critère français']; }
        else { $nl = rgTexte($b['critere_nl'], 400); $set[] = 'critere_nl = ?'; $p[] = $nl !== '' ? $nl : null; $quoi[] = 'critère NL'; }
    }
    if (array_key_exists('renouvellement', $b)) {
        $m = $b['renouvellement'];
        if (!is_numeric($m) || (int) $m != $m || (int) $m < 0 || (int) $m > 60) { $ko['renouvellement'] = ['de 0 à 60 mois']; }
        else { $set[] = 'renouvellement = ?'; $p[] = (int) $m; $quoi[] = 'renouvellement ' . (int) $m . ' mois'; }
    }
    foreach (['fiche', 'video'] as $k) {
        if (!array_key_exists($k, $b)) { continue; }
        $raw = rgTexte($b[$k], 500);
        $u = rgUrl($raw);
        if ($raw !== '' && $u === '') { $ko[$k] = ['adresse https:// seulement']; continue; }
        $set[] = $k . ' = ?'; $p[] = $u; $quoi[] = $k . ($u !== '' ? '' : ' retirée');
    }
    if (array_key_exists('profils', $b)) {
        $l = is_array($b['profils']) ? array_values(array_unique(array_map('strval', array_filter($b['profils'], 'is_scalar')))) : null;
        if ($l === null || array_diff($l, RG_PROFILS) !== []) { $ko['profils'] = ['parmi ' . implode(', ', RG_PROFILS)]; }
        else { $l = array_values(array_intersect(RG_PROFILS, $l)); $set[] = 'profils = ?'; $p[] = rgJson($l); $quoi[] = 'profils ' . (implode(', ', $l) ?: '—'); }
    }
    if (array_key_exists('bloc', $b) && $r !== null && (int) $b['bloc'] !== (int) $r['bloc']) {
        $n = (int) $b['bloc'];
        if (Db::row('SELECT n FROM ceo_ref_bloc WHERE n = ?', [$n]) === null) { $ko['bloc'] = ['bloc inconnu']; }
        else {
            $rang = (int) (Db::row('SELECT COALESCE(MAX(rang), 0) AS m FROM ceo_ref_item WHERE bloc = ?', [$n])['m'] ?? 0) + 1;
            $set[] = 'bloc = ?, rang = ?'; array_push($p, $n, $rang); $quoi[] = 'déplacé au bloc ' . $n;
        }
    }
    return [$set, $p, $quoi, $ko];
}

/** POST /formation/ref/blocs/{n}/items — {id, fr, nl?, …} : un point de plus, à la fin du bloc. 409 si l'id existe. */
function wr_rg_item_ajout(int $n): array
{
    if (Db::row('SELECT n FROM ceo_ref_bloc WHERE n = ?', [$n]) === null) { return rfRefus(404, 'bloc inconnu'); }
    $b = body();
    $id = rgTexte($b['id'] ?? '', 60);
    if (!rgIdValide($id)) { return rfRefus(422, 'identifiant attendu : un code C-… ou FO-… (lettres, chiffres, point, tiret ; 60 caractères au plus)'); }
    if (Db::row('SELECT id, bloc FROM ceo_ref_item WHERE id = ?', [$id]) !== null) { return rfRefus(409, 'ce point existe déjà'); }
    if (rgTexte($b['fr'] ?? '', 200) === '') { return rfRefus(422, 'libellé français manquant'); }
    unset($b['bloc'], $b['accepter']);
    [$set, $p, , $ko] = rgItemChamps($b, null);
    if ($ko !== []) { return rfRefus(422, 'point refusé', ['invalides' => $ko]); }
    $par = foPar($b);
    $now = date('Y-m-d H:i:s');
    $rang = (int) (Db::row('SELECT COALESCE(MAX(rang), 0) AS m FROM ceo_ref_item WHERE bloc = ?', [$n])['m'] ?? 0) + 1;
    $fo = preg_match('/^FO-\d{1,3}$/', strtoupper($id)) ? strtoupper($id) : '';
    Db::exec('INSERT INTO ceo_ref_item (id, bloc, rang, fr, fo, phase, bloq, poles, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, \'pre\', 1, 0, ?, ?)', [$id, $n, $rang, rgTexte($b['fr'], 200), $fo, $now, $par]);
    if ($set !== []) { array_push($p, $id); Db::exec('UPDATE ceo_ref_item SET ' . implode(', ', $set) . ' WHERE id = ?', $p); }
    try { journalAdd($par, 'Formation', 'Guide des formations · bloc ' . $n, 'Point ajouté : ' . $id . ' · ' . rgTexte($b['fr'], 200)); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'item' => rgItemSortie(Db::row('SELECT * FROM ceo_ref_item WHERE id = ?', [$id]), true)];
}

/** DELETE /formation/ref/items/{id} — retire un point (les validations déjà faites dans le CRM le gardent). */
function wr_rg_item_suppr(string $id): array
{
    $r = Db::row('SELECT * FROM ceo_ref_item WHERE id = ?', [$id]);
    if ($r === null) { return rfRefus(404, 'point de formation inconnu'); }
    Db::exec('DELETE FROM ceo_ref_item WHERE id = ?', [$id]);
    // Le bloc garde une trace de l'écriture (la version du référentiel avance, le CRM relit).
    $par = foPar(body());
    Db::exec('UPDATE ceo_ref_bloc SET maj_le = ?, maj_par = ? WHERE n = ?', [date('Y-m-d H:i:s'), $par, (int) $r['bloc']]);
    try { journalAdd($par, 'Formation', 'Guide des formations · bloc ' . (int) $r['bloc'], 'Point retiré : ' . $id . ' · ' . $r['fr']); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'id' => $id];
}

/** POST /formation/ref/blocs/{n}/accepter — accepte d'un coup les critères encore proposés du bloc. */
function wr_rg_accepter(int $n): array
{
    if (Db::row('SELECT n FROM ceo_ref_bloc WHERE n = ?', [$n]) === null) { return rfRefus(404, 'bloc inconnu'); }
    $par = foPar(body());
    $k = Db::exec("UPDATE ceo_ref_item SET critere_fr = critere_prop_fr, critere_nl = critere_prop_nl, critere_accepte = 1, maj_le = ?, maj_par = ?
                   WHERE bloc = ? AND critere_accepte = 0 AND critere_prop_fr IS NOT NULL AND critere_prop_fr <> ''", [date('Y-m-d H:i:s'), $par, $n]);
    try { journalAdd($par, 'Formation', 'Guide des formations · bloc ' . $n, $k . ' critère(s) proposé(s) accepté(s)'); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'acceptes' => $k];
}

/** PUT /formation/ref/poles — {fr?: [5], nl?: [5], repere?} : un nom vide reprend celui de départ ; repère de 0 à 5. */
function wr_rg_poles(): array
{
    $b = body();
    $cur = rgPoles();
    $ko = [];
    foreach (['fr', 'nl'] as $l) {
        if (!array_key_exists($l, $b)) { continue; }
        if (!is_array($b[$l]) || count($b[$l]) !== RG_NOTE_MAX) { $ko[$l] = [RG_NOTE_MAX . ' noms attendus']; continue; }
        $d = rgDepart('formations')['poles'][$l] ?? [];
        foreach (array_values($b[$l]) as $i => $v) { $v = rgTexte($v, 60); $cur[$l][$i] = $v !== '' ? $v : (string) ($d[$i] ?? ''); }
    }
    if (array_key_exists('repere', $b)) {
        $r = is_numeric(str_replace(',', '.', (string) $b['repere'])) ? (float) str_replace(',', '.', (string) $b['repere']) : -1.0;
        if ($r < 0 || $r > RG_NOTE_MAX) { $ko['repere'] = ['de 0 à ' . RG_NOTE_MAX . ' (0 = aucun)']; } else { $cur['repere'] = $r; }
    }
    if ($ko !== []) { return rfRefus(422, 'pôles refusés', ['invalides' => $ko]); }
    $par = foPar($b);
    rgPolesEcrire($cur, $par);
    try { journalAdd($par, 'Formation', 'Guide des formations · 5 pôles', implode(' · ', $cur['fr']) . ' · repère ' . $cur['repere']); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'poles' => rgPolesSortie()];
}

/* ═══ Routes ══════════════════════════════════════════════════════════════════════════════════════ */

/** Les routes d'écriture des deux guides (sous /formation/ref) ; null quand le chemin n'en est pas une. */
function rgRoute(string $method, string $path): ?array
{
    return match (true) {
        $method === 'PUT' && $path === '/formation/ref/preouverture' => wr_rg_preouverture(),
        $method === 'PUT' && $path === '/formation/ref/poles' => wr_rg_poles(),
        $method === 'PUT' && preg_match('#^/formation/ref/blocs/(\d{1,2})$#', $path, $m) === 1 => wr_rg_bloc((int) $m[1]),
        $method === 'POST' && preg_match('#^/formation/ref/blocs/(\d{1,2})/items$#', $path, $m) === 1 => wr_rg_item_ajout((int) $m[1]),
        $method === 'POST' && preg_match('#^/formation/ref/blocs/(\d{1,2})/accepter$#', $path, $m) === 1 => wr_rg_accepter((int) $m[1]),
        $method === 'PUT' && preg_match('#^/formation/ref/items/([^/]{1,240})$#', $path, $m) === 1 => wr_rg_item(rawurldecode($m[1])),
        $method === 'DELETE' && preg_match('#^/formation/ref/items/([^/]{1,240})$#', $path, $m) === 1 => wr_rg_item_suppr(rawurldecode($m[1])),
        default => null,
    };
}

/* ═══ Outils ══════════════════════════════════════════════════════════════════════════════════════ */

function rgTexte(mixed $v, int $max): string
{
    return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
}

/** Une clé de section ou de point : minuscules, chiffres et « _ » (tirée du libellé quand elle manque). */
function rgCle(mixed $v): string
{
    $s = is_scalar($v) ? (function_exists('rfSansAccents') ? rfSansAccents((string) $v) : mb_strtolower((string) $v)) : '';
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    return mb_substr($s, 0, 30);
}

function rgCleUnique(string $cle, array &$vues): string
{
    $c = $cle; $i = 2;
    while (isset($vues[$c])) { $c = mb_substr($cle, 0, 26) . '_' . $i++; }
    $vues[$c] = true;
    return $c;
}

function rgIdValide(string $id): bool
{
    return preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._\-]{1,59}$/u', $id) === 1;
}

/** Une adresse https, sinon rien (la fiche technique et la vidéo s'ouvrent dans l'application du formateur). */
function rgUrl(string $u): string
{
    $u = trim($u);
    return preg_match('#^https://[^\s<>"]{3,500}$#i', $u) ? $u : '';
}

function rgListe(mixed $s): array
{
    $d = is_string($s) && $s !== '' ? json_decode($s, true) : null;
    return is_array($d) ? array_values(array_map('strval', array_filter($d, 'is_scalar'))) : [];
}

function rgJson(array $l): string
{
    return (string) json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
