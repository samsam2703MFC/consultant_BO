<?php
declare(strict_types=1);

/*
 * MODULE FORMATION — LES STANDARDS DU RÉSEAU (SOP), EN FRANÇAIS ET EN NÉERLANDAIS.
 *
 * Une section du module Formation (public/formation/, src/formation.php) : le module lit le référentiel RH du
 * panel (postes, compétences, formations, procédures) ; ce fichier y ajoute les SOP du réseau, qui vivent dans
 * NOS tables. Les 25 SOP du Drive « L'Atelier Halle — SOP — dernières versions retrouvées » ont été reprises en
 * entier (data/formation/sops/*.json : texte d'origine et sa traduction ; photos dans public/formation/sop/photos/)
 * et rangées au premier appel. Nos bases deviennent la source ; le Drive devient l'archive (décisions du
 * 05/11/2026, docs/maquettes/formation/LISEZMOI.md).
 *
 *  ceo_sop           une ligne par SOP : code, famille (01 à 04), poste, partie du Manuel opératoire, version en
 *                    vigueur, état des deux langues (origine / relue / à relire), compétence du panel, titres
 *  ceo_sop_version   le contenu de chaque version publiée — JSON FR + NL : sections, étapes, points
 *                    critiques, quiz. Les versions précédentes restent lisibles.
 *  ceo_sop_suivi     une personne × une SOP : lue, quiz réussi, « vu faire » par la responsable du magasin
 *  ceo_sop_personne  une personne d'un magasin : ses postes, sa langue, si elle valide « vu faire »
 *
 * DANS LE MODULE : le groupe « Standards (SOP) » (liste, fiche FR | NL, compétences, rédaction) et les quatre
 * parties du Manuel opératoire, où chaque SOP se range à côté des procédures et des formations FO (partie
 * réglée, ou déduite du moment : ouverture → Ouverture, le reste → Opérationnel).
 *
 * LA TABLETTE DU POSTE (public/formation/sop/tablette.html — l'onglet SOP de l'app Cuisine y mène) : on choisit
 * son nom et on tape son PIN, vérifié au panel (GET /shops/{id}/employees) comme pour les checklists de l'app
 * Cuisine ; le PIN ne quitte jamais le serveur. Ensuite le pas à pas, le quiz, puis la responsable valide
 * « vu faire » avec SON PIN. Les routes /formation/sop/tablette/* sont ouvertes sans session cockpit : chaque
 * écriture porte un PIN ou un jeton signé (HMAC, secret de session du cockpit, 4 h). Depuis le lot 5 de la fusion
 * (06/11/2026, src/tablettes.php), elles exigent d'abord la session de l'APPAREIL, ouverte par le PIN du magasin :
 * le magasin vient de cette session (foShopTablette), un jeton ne vaut que sur une tablette de son magasin.
 *
 * LES COMPÉTENCES sont celles du panel (GET /competencies, comme le module) : chaque SOP se rattache à une
 * compétence dans Rédaction. Une compétence est acquise par une personne quand chacune de ses SOP publiées, pour
 * les postes de cette personne, a été vue faire à la version en vigueur ; elle est alors COCHÉE AU PANEL pour
 * cette personne (PUT /employees/{id}/competencies, relu, journalisé — décision du 06/11/2026).
 *
 * MANUEL DU RÉSEAU (fusion du 06/11/2026, src/referentiel.php) : chaque SOP est un « standard » rangé dans une
 * sous-catégorie du référentiel v25, étiqueté par phases et postes (P1–P12), relié à des codes C-…, FO-… et à des
 * checklists. Le « vu faire » coche au panel les compétences reliées aux codes C-… de la SOP ; l'ancienne
 * compétence du panel (competence_id) reste un repli. Statut `brouillon` : une SOP reprise du Manuel opératoire du
 * CRM, pas encore relue — ni sur la tablette, ni dans le Manuel imprimé.
 *
 * Contrat : docs/contrat-api.md § Formation · Standards (SOP) et § Formation · Manuel du réseau.
 */

const FO_LANGUES = ['fr', 'nl'];
const FO_POSTES = ['vente', 'cuisson', 'production', 'nettoyage'];
const FO_DOSSIERS = [
    '01' => ['fr' => 'Magasin et vente', 'nl' => 'Winkel en verkoop'],
    '02' => ['fr' => 'Cuisson et production', 'nl' => 'Bakken en productie'],
    '03' => ['fr' => 'Nettoyage et fermeture', 'nl' => 'Schoonmaak en sluiting'],
    '04' => ['fr' => 'Fiches de référence', 'nl' => 'Referentiefiches'],
];
const FO_TRAD = ['origine', 'relue', 'a_relire'];
const FO_PARTIES = ['preouverture', 'ouverture', 'operationnel', 'formation'];   // les quatre parties du Manuel opératoire (MN_PARTIES)
const FO_JETON_S = 4 * 3600;            // un jeton de la tablette vaut 4 h
const FO_PIN_ESSAIS = 5;                // essais de PIN manqués avant blocage…
const FO_PIN_BLOCAGE_S = 600;           // … de 10 minutes, pour cette personne de ce magasin
// Les documents RÉSERVÉS (07/11/2026, client : « déplacer de Ouverture à Manuel, avec accès poste (mot de passe) : celui
// du franchisé ou du responsable magasin ; pour le moment 1111, on changera en fin de développement ») : colonne `acces`
// de ceo_sop. Sur la tablette, un document réservé se range dans l'onglet Manuel, cadenas doré, et ne s'ouvre qu'avec le
// PIN du responsable. Ce PIN est le réglage `foPinResponsable` (ceo_app_setting) ; tant qu'il n'est pas posé, le PIN
// PROVISOIRE ci-dessous — à remplacer en fin de développement (le réglage, ou le PIN de la personne au panel).
const FO_ACCES = ['tous', 'responsable'];
const FO_PIN_RESPONSABLE_PROVISOIRE = '1111';
const FO_EQUIPE_CACHE_S = 6 * 3600;     // les noms et rôles du panel, gardés 6 h (jamais le PIN)
const FO_COMPETENCES_CACHE_S = 86400;   // la liste des compétences du panel, gardée un jour
const FO_FIL_JOURS = 14;

function foDataDir(): string { return __DIR__ . '/../data/formation'; }

/* ═══ Schéma et reprise ═══════════════════════════════════════════════════════════════════════════ */

/** Les tables de la formation ; la reprise du Drive au premier passage (ceo_sop vide). Idempotent. */
function ensureSop(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_sop ('
        . 'id VARCHAR(32) NOT NULL PRIMARY KEY,'
        . 'code VARCHAR(40) NULL,'
        . "type VARCHAR(20) NOT NULL DEFAULT 'SOP',"
        . "dossier CHAR(2) NOT NULL DEFAULT '01',"
        . "poste VARCHAR(20) NOT NULL DEFAULT 'vente',"
        . 'partie VARCHAR(16) NULL,'
        . 'moment VARCHAR(20) NULL,'
        . "statut VARCHAR(12) NOT NULL DEFAULT 'publiee',"
        . "version VARCHAR(12) NOT NULL DEFAULT '1.0',"
        . "version_revalider VARCHAR(12) NOT NULL DEFAULT '0',"
        . "langue_origine VARCHAR(5) NOT NULL DEFAULT 'FR',"
        . "trad_fr VARCHAR(10) NOT NULL DEFAULT 'origine',"
        . "trad_nl VARCHAR(10) NOT NULL DEFAULT 'a_relire',"
        . 'competence_id VARCHAR(40) NULL,'
        . 'competence_nom VARCHAR(190) NULL,'
        . 'titre_fr VARCHAR(190) NOT NULL,'
        . "titre_nl VARCHAR(190) NOT NULL DEFAULT '',"
        . 'objectif_fr TEXT NULL,'
        . 'objectif_nl TEXT NULL,'
        . 'duree VARCHAR(190) NULL,'
        . 'nb_etapes INT NOT NULL DEFAULT 0,'
        . 'nb_photos INT NOT NULL DEFAULT 0,'
        . 'ordre INT NOT NULL DEFAULT 0,'
        . 'source TEXT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL,'
        . 'KEY idx_dossier (dossier, ordre)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_sop_version ('
        . 'sop_id VARCHAR(32) NOT NULL,'
        . 'version VARCHAR(12) NOT NULL,'
        . 'contenu MEDIUMTEXT NOT NULL,'
        . 'note VARCHAR(500) NULL,'
        . 'revalider TINYINT(1) NOT NULL DEFAULT 0,'
        . 'publie_le DATETIME NOT NULL,'
        . 'publie_par VARCHAR(120) NULL,'
        . 'PRIMARY KEY (sop_id, version)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_sop_suivi ('
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'employee_id INT NOT NULL,'
        . 'sop_id VARCHAR(32) NOT NULL,'
        . "employee_nom VARCHAR(120) NOT NULL DEFAULT '',"
        . "version VARCHAR(12) NOT NULL DEFAULT '0',"
        . 'lu_le DATETIME NULL,'
        . 'quiz_le DATETIME NULL,'
        . 'quiz_score TINYINT NULL,'
        . 'quiz_essais INT NOT NULL DEFAULT 0,'
        . 'vu_le DATETIME NULL,'
        . 'vu_version VARCHAR(12) NULL,'
        . 'vu_par_id INT NULL,'
        . 'vu_par_nom VARCHAR(120) NULL,'
        . 'note VARCHAR(500) NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'PRIMARY KEY (shop_id, employee_id, sop_id),'
        . 'KEY idx_sop (sop_id),'
        . 'KEY idx_maj (shop_id, maj_le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_sop_personne ('
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'employee_id INT NOT NULL,'
        . "nom VARCHAR(120) NOT NULL DEFAULT '',"
        . 'postes VARCHAR(80) NULL,'
        . 'valideur TINYINT(1) NULL,'
        . 'langue CHAR(2) NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'PRIMARY KEY (shop_id, employee_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    try { Db::exec('ALTER TABLE ceo_sop ADD COLUMN partie VARCHAR(16) NULL AFTER poste'); } catch (PDOException $e) { /* déjà là */ }
    try { Db::exec('ALTER TABLE ceo_sop ADD COLUMN acces VARCHAR(16) NULL AFTER moment'); } catch (PDOException $e) { /* déjà là */ }
    if ((int) (Db::row('SELECT COUNT(*) AS n FROM ceo_sop')['n'] ?? 0) === 0) { foImporter(); }
}

/**
 * La reprise : chaque data/formation/sops/<id>.json absent de ceo_sop y entre, version d'origine comprise.
 * Ne réécrit jamais une SOP déjà en base (nos bases sont la source). Rend le nombre de SOP ajoutées.
 */
function foImporter(): int
{
    $index = json_decode((string) @file_get_contents(foDataDir() . '/index.json'), true);
    $ordre = is_array($index) && is_array($index['ordre'] ?? null) ? array_flip(array_map('strval', $index['ordre'])) : [];
    $reprise = is_array($index) ? (string) ($index['reprise'] ?? '') : '';
    $n = 0;
    foreach (glob(foDataDir() . '/sops/*.json') ?: [] as $f) {
        $j = json_decode((string) file_get_contents($f), true);
        if (!is_array($j) || !is_string($j['id'] ?? null)) { continue; }
        $id = foId($j['id']);
        if ($id === null || Db::row('SELECT id FROM ceo_sop WHERE id = ?', [$id]) !== null) { continue; }
        $c = foContenu($j);
        if ($c['titre']['fr'] === '' && $c['titre']['nl'] === '') { continue; }
        $lo = strtoupper((string) ($j['langue_origine'] ?? 'FR'));
        $lo = in_array($lo, ['FR', 'NL', 'FR-NL', 'NL-FR'], true) ? str_replace('NL-FR', 'FR-NL', $lo) : 'FR';
        $ver = foVersion((string) ($j['version'] ?? '1.0'));
        $now = date('Y-m-d H:i:s');
        $st = foStats($c);
        Db::exec('INSERT INTO ceo_sop (id, code, type, dossier, poste, moment, statut, version, version_revalider, langue_origine, trad_fr, trad_nl,
                    titre_fr, titre_nl, objectif_fr, objectif_nl, duree, nb_etapes, nb_photos, ordre, source, maj_le, maj_par)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, foCourt($j['code'] ?? null, 40), foType($j['type'] ?? 'SOP'), foDossier($j['dossier'] ?? '01'), foPoste($j['poste'] ?? 'vente'),
            foCourt($j['moment'] ?? null, 20), ($j['statut'] ?? null) === 'a_valider' ? 'a_valider' : 'publiee', $ver, $ver, $lo,
            str_contains($lo, 'FR') ? 'origine' : 'a_relire', str_contains($lo, 'NL') ? 'origine' : 'a_relire',
            mb_substr($c['titre']['fr'] ?: $c['titre']['nl'], 0, 190), mb_substr($c['titre']['nl'], 0, 190), $c['objectif']['fr'], $c['objectif']['nl'],
            foCourt($c['duree'], 190), $st['etapes'], $st['photos'], (int) ($ordre[$id] ?? 900 + $n),
            isset($j['source']) ? json_encode($j['source'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $now, 'Reprise du Drive']);
        Db::exec('INSERT INTO ceo_sop_version (sop_id, version, contenu, note, revalider, publie_le, publie_par) VALUES (?, ?, ?, ?, 0, ?, ?)',
            [$id, $ver, foJson($c), 'Reprise du Drive' . ($reprise !== '' ? ' (' . $reprise . ')' : ''), $now, 'Reprise du Drive']);
        $n++;
    }
    if ($n > 0) {
        try { journalAdd('Formation', 'Reprise', null, $n . ' SOP reprises du Drive et rangées dans nos bases (FR et NL)'); } catch (Throwable $e) { /* le journal ne bloque pas */ }
    }
    return $n;
}

/* ═══ Lectures du cockpit ═════════════════════════════════════════════════════════════════════════ */

/** GET /formation/sops — les standards, rangés par famille, avec leur progression dans le réseau. */
function ep_sop_sops(): array
{
    $rows = Db::rows('SELECT * FROM ceo_sop ORDER BY dossier, ordre, id');
    $prog = foProgressionReseau();
    $out = [];
    foreach ($rows as $r) { $out[] = foSopLigne($r) + ['progression' => $prog[(string) $r['id']] ?? ['vu' => 0, 'quiz' => 0, 'lu' => 0, 'aRevoir' => 0]]; }
    $aRelire = count(array_filter($out, static fn ($s) => in_array('a_relire', [$s['trad']['fr'], $s['trad']['nl']], true)));
    return [
        'dossiers' => foDossiers(),
        'sops' => $out,
        'totaux' => [
            'sops' => count($out),
            'aValider' => count(array_filter($out, static fn ($s) => $s['statut'] === 'a_valider')),
            'brouillons' => count(array_filter($out, static fn ($s) => $s['statut'] === 'brouillon')),
            'etapes' => array_sum(array_column($out, 'nbEtapes')),
            'tradARelire' => $aRelire,
            'sansCompetence' => count(array_filter($out, static fn ($s) => $s['competence'] === null)),
        ],
    ];
}

/** GET /formation/sops/{id}?shop= — une SOP : son contenu en vigueur, ses versions, l'équipe du magasin face à elle. */
function ep_sop_sop(string $id): array
{
    $r = foSopRow($id);
    if ($r === null) { return foRefus(404, 'SOP inconnue'); }
    $c = foContenuVersion((string) $r['id'], (string) $r['version']);
    $vers = Db::rows('SELECT version, note, revalider, publie_le, publie_par FROM ceo_sop_version WHERE sop_id = ? ORDER BY publie_le DESC, version DESC', [$r['id']]);
    $out = foSopLigne($r) + [
        'contenu' => $c,
        'versions' => array_map(static fn ($v) => ['version' => (string) $v['version'], 'note' => $v['note'], 'revalider' => (int) $v['revalider'] === 1,
            'publieLe' => foIso($v['publie_le']), 'publiePar' => $v['publie_par']], $vers),
        'source' => $r['source'] !== null ? json_decode((string) $r['source'], true) : null,
    ];
    $shop = foShopParam();
    if ($shop !== null) {
        $suivi = foSuiviMagasin($shop);
        $equipe = [];
        foreach (foEquipe($shop) as $p) {
            $s = $suivi[$p['id']][(string) $r['id']] ?? null;
            if ($s === null && !in_array((string) $r['poste'], $p['postes'], true)) { continue; }
            $equipe[] = ['id' => $p['id'], 'nom' => $p['nom'], 'langue' => $p['langue'], 'valideur' => $p['valideur'],
                'statut' => foStatut($s, $r), 'vuLe' => foIso($s['vu_le'] ?? null), 'vuPar' => $s['vu_par_nom'] ?? null];
        }
        $out['equipe'] = $equipe;
        $out['magasin'] = ['id' => $shop, 'nom' => foMagasinNom($shop)];
    }
    return $out;
}

/** GET /formation/sops/{id}/versions/{v} — le contenu d'une version, telle qu'elle a été publiée. */
function ep_sop_sop_version(string $id, string $v): array
{
    $r = foSopRow($id);
    if ($r === null) { return foRefus(404, 'SOP inconnue'); }
    $row = Db::row('SELECT version, contenu, note, revalider, publie_le, publie_par FROM ceo_sop_version WHERE sop_id = ? AND version = ?', [$r['id'], $v]);
    if ($row === null) { return foRefus(404, 'version inconnue'); }
    return ['id' => (string) $r['id'], 'version' => (string) $row['version'], 'enVigueur' => (string) $row['version'] === (string) $r['version'],
        'contenu' => json_decode((string) $row['contenu'], true), 'note' => $row['note'], 'publieLe' => foIso($row['publie_le']), 'publiePar' => $row['publie_par']];
}

/**
 * GET /formation/competences?shop= — les compétences du panel, les SOP qui y sont rattachées, le réseau
 * magasin par magasin (part des compétences acquises), et, pour un magasin, chaque personne × compétence.
 */
function ep_sop_competences(): array
{
    $panel = foCompetencesPanel(!empty($_GET['rafraichir']));
    $sops = Db::rows('SELECT * FROM ceo_sop ORDER BY dossier, ordre, id');
    $comps = foCompetencesUtilisees($sops);
    $magasins = [];
    foreach (foMagasins() as $m) {
        $eq = foEquipe($m['id'], false);
        if ($eq === []) { $magasins[] = $m + ['equipe' => 0, 'taux' => null, 'aVoirFaire' => 0, 'parCompetence' => (object) []]; continue; }
        $mat = foMatrice($m['id'], $eq, $sops, $comps);
        $magasins[] = $m + ['equipe' => count($eq), 'taux' => $mat['taux'], 'aVoirFaire' => $mat['aVoirFaire'], 'parCompetence' => (object) $mat['parCompetence']];
    }
    $out = ['panel' => $panel, 'competences' => array_values($comps), 'magasins' => $magasins,
        'sansCompetence' => array_values(array_map(static fn ($r) => ['id' => (string) $r['id'], 'titre' => (string) $r['titre_fr']],
            array_filter($sops, static fn ($r) => $r['competence_id'] === null && $r['competence_nom'] === null)))];
    $shop = foShopParam();
    if ($shop !== null) {
        $eq = foEquipe($shop);
        $mat = foMatrice($shop, $eq, $sops, $comps);
        $out['magasin'] = ['id' => $shop, 'nom' => foMagasinNom($shop), 'equipe' => $mat['personnes'], 'taux' => $mat['taux'], 'aVoirFaire' => $mat['liste']];
    }
    return $out;
}

/** GET /formation/equipe?shop= — les personnes du magasin (panel), leurs postes, leur langue, qui valide. */
function ep_sop_equipe(): array
{
    $shop = foShopParam();
    if ($shop === null) { return foRefus(400, 'magasin attendu (?shop=)'); }
    return ['magasin' => ['id' => $shop, 'nom' => foMagasinNom($shop)], 'personnes' => foEquipe($shop),
        'panel' => class_exists('PanelApi') && PanelApi::configured(), 'postes' => FO_POSTES];
}

/** GET /formation/fil?shop= — ce qui s'est passé ces 14 derniers jours au magasin (lectures, quiz, « vu faire »). */
function ep_sop_fil(): array
{
    $shop = foShopParam();
    if ($shop === null) { return foRefus(400, 'magasin attendu (?shop=)'); }
    return ['fil' => foFil($shop, 40)];
}

/** GET /formation/manuel?langue=fr — le Manuel opératoire complet (SOP publiées et à valider), pour le Manuel Opératoire du panel. */
function ep_sop_manuel(): array
{
    $l = foLangueParam();
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_sop WHERE statut <> \'brouillon\' ORDER BY dossier, ordre, id') as $r) {
        $c = foContenuVersion((string) $r['id'], (string) $r['version']);
        $out[] = foSopLigne($r) + ['contenu' => foContenuLangue($c, $l, true)];
    }
    return ['langue' => $l, 'edition' => date('Y-m-d'), 'dossiers' => foDossiers(), 'sops' => $out];
}

/** GET /formation/manuel.pdf?langue=fr&dossier= — le Manuel opératoire en PDF (ou en page imprimable, sans moteur PDF). */
function ep_sop_manuel_pdf(): array
{
    $l = foLangueParam();
    $d = isset($_GET['dossier']) && isset(FO_DOSSIERS[(string) $_GET['dossier']]) ? (string) $_GET['dossier'] : null;
    $rows = Db::rows('SELECT * FROM ceo_sop WHERE statut <> \'brouillon\'' . ($d !== null ? ' AND dossier = ?' : '') . ' ORDER BY dossier, ordre, id', $d !== null ? [$d] : []);
    $titre = ($l === 'nl' ? 'Operationeel handboek' : 'Manuel opératoire') . ($d !== null ? ' — ' . FO_DOSSIERS[$d][$l] : '');
    foPdfSortie($rows, $l, true, $titre, 'manuel-' . $l . ($d !== null ? '-' . $d : ''));
    return [];
}

/** GET /formation/sops/{id}.pdf?langue=fr — une SOP en PDF. */
function ep_sop_sop_pdf(string $id): array
{
    $r = foSopRow($id);
    if ($r === null) { return foRefus(404, 'SOP inconnue'); }
    $l = foLangueParam();
    foPdfSortie([$r], $l, false, (string) ($l === 'nl' && $r['titre_nl'] !== '' ? $r['titre_nl'] : $r['titre_fr']), sopSlug((string) ($r['code'] ?: $r['id'])) . '-' . $l);
    return [];
}

/* ═══ Écritures du cockpit ════════════════════════════════════════════════════════════════════════ */

/**
 * PUT /formation/sops/{id} — publier une nouvelle version : {contenu, note, revalider, trad:{fr,nl}, par}.
 * La version monte d'un cran (1.1 → 1.2) ; avec `revalider`, ceux qui l'avaient vue faire repassent « à revoir ».
 */
function wr_sop_publier(string $id): array
{
    $r = foSopRow($id);
    if ($r === null) { return foRefus(404, 'SOP inconnue'); }
    $b = body();
    if (!is_array($b['contenu'] ?? null)) { return foRefus(400, 'contenu attendu'); }
    $c = foContenu($b['contenu']);
    $err = foContenuErreur($c);
    if ($err !== null) { return foRefus(400, $err); }
    $ver = foVersionSuivante((string) $r['version']);
    $reval = !empty($b['revalider']);
    $par = foPar($b);
    $trad = is_array($b['trad'] ?? null) ? $b['trad'] : [];
    $tfr = in_array($trad['fr'] ?? null, FO_TRAD, true) ? (string) $trad['fr'] : (string) $r['trad_fr'];
    $tnl = in_array($trad['nl'] ?? null, FO_TRAD, true) ? (string) $trad['nl'] : (string) $r['trad_nl'];
    $st = foStats($c);
    $now = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO ceo_sop_version (sop_id, version, contenu, note, revalider, publie_le, publie_par) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$r['id'], $ver, foJson($c), foCourt($b['note'] ?? null, 500), $reval ? 1 : 0, $now, $par]);
    Db::exec('UPDATE ceo_sop SET version = ?, version_revalider = ?, titre_fr = ?, titre_nl = ?, objectif_fr = ?, objectif_nl = ?, duree = ?,
                nb_etapes = ?, nb_photos = ?, trad_fr = ?, trad_nl = ?, maj_le = ?, maj_par = ? WHERE id = ?',
        [$ver, $reval ? $ver : (string) $r['version_revalider'], mb_substr($c['titre']['fr'] ?: $c['titre']['nl'], 0, 190), mb_substr($c['titre']['nl'], 0, 190),
            $c['objectif']['fr'], $c['objectif']['nl'], foCourt($c['duree'], 190), $st['etapes'], $st['photos'], $tfr, $tnl, $now, $par, $r['id']]);
    $n = $reval ? (int) (Db::row('SELECT COUNT(*) AS n FROM ceo_sop_suivi WHERE sop_id = ? AND vu_le IS NOT NULL', [$r['id']])['n'] ?? 0) : 0;
    try { journalAdd($par, 'Formation', (string) $r['titre_fr'], 'Version ' . $ver . ' publiée' . ($reval ? ' — ' . $n . ' personne(s) à revoir' : ' (précision)')); } catch (Throwable $e) { /* */ }
    return ['ok' => true, 'version' => $ver, 'aRevoir' => $n, 'sop' => foSopLigne(foSopRow($id) ?? $r)];
}

/**
 * PATCH /formation/sops/{id} — les réglages d'une SOP, sans nouvelle version : compétence du panel
 * {competence: {id, nom} | null}, état d'une langue {trad: {nl: 'relue'}}, statut, poste, moment, famille, code.
 */
function wr_sop_reglage(string $id): array
{
    $r = foSopRow($id);
    if ($r === null) { return foRefus(404, 'SOP inconnue'); }
    $b = body();
    $set = [];
    $p = [];
    $quoi = [];
    if (array_key_exists('competence', $b)) {
        $c = $b['competence'];
        if ($c === null) { $set[] = 'competence_id = NULL, competence_nom = NULL'; $quoi[] = 'compétence retirée'; }
        elseif (is_array($c) && trim((string) ($c['nom'] ?? '')) !== '') {
            $set[] = 'competence_id = ?, competence_nom = ?';
            $p[] = foCourt(isset($c['id']) ? (string) $c['id'] : null, 40);
            $p[] = foCourt((string) $c['nom'], 190);
            $quoi[] = 'compétence « ' . trim((string) $c['nom']) . ' »';
        } else { return foRefus(400, 'compétence attendue : {id, nom} ou null'); }
    }
    if (is_array($b['trad'] ?? null)) {
        foreach (FO_LANGUES as $l) {
            if (!isset($b['trad'][$l])) { continue; }
            if (!in_array($b['trad'][$l], FO_TRAD, true)) { return foRefus(400, 'état de langue inconnu'); }
            $set[] = 'trad_' . $l . ' = ?'; $p[] = $b['trad'][$l]; $quoi[] = strtoupper($l) . ' ' . str_replace('_', ' ', (string) $b['trad'][$l]);
        }
    }
    foreach (['statut' => ['publiee', 'a_valider', 'brouillon'], 'poste' => FO_POSTES, 'acces' => FO_ACCES] as $k => $ok) {
        if (!isset($b[$k])) { continue; }
        if (!in_array($b[$k], $ok, true)) { return foRefus(400, $k . ' inconnu'); }
        $set[] = $k . ' = ?'; $p[] = $b[$k]; $quoi[] = $k . ' ' . $b[$k];
    }
    if (array_key_exists('moment', $b)) {
        // Le moment d'une SOP = son onglet de la tablette et du menu Opérationnel (06/11/2026) : ouverture, journée de
        // travail (journée, après-midi), fermeture ; null = « au besoin » (rangée avec la journée).
        if ($b['moment'] !== null && !in_array($b['moment'], ['ouverture', 'journee', 'apres-midi', 'fermeture'], true)) { return foRefus(400, 'moment inconnu'); }
        $set[] = 'moment = ?'; $p[] = $b['moment']; $quoi[] = 'moment ' . ($b['moment'] ?? 'au besoin');
    }
    if (array_key_exists('partie', $b)) {
        if ($b['partie'] !== null && !in_array($b['partie'], FO_PARTIES, true)) { return foRefus(400, 'partie inconnue'); }
        $set[] = 'partie = ?'; $p[] = $b['partie']; $quoi[] = 'partie du manuel ' . ($b['partie'] ?? 'déduite');
    }
    if (isset($b['dossier'])) { $set[] = 'dossier = ?'; $p[] = foDossier($b['dossier']); $quoi[] = 'famille ' . foDossier($b['dossier']); }
    if (array_key_exists('code', $b)) { $set[] = 'code = ?'; $p[] = foCourt($b['code'], 40); }
    if ($set === []) { return foRefus(400, 'rien à changer'); }
    $par = foPar($b);
    $set[] = 'maj_le = ?, maj_par = ?';
    array_push($p, date('Y-m-d H:i:s'), $par, $r['id']);
    Db::exec('UPDATE ceo_sop SET ' . implode(', ', $set) . ' WHERE id = ?', $p);
    if ($quoi) { try { journalAdd($par, 'Formation', (string) $r['titre_fr'], implode(' · ', $quoi)); } catch (Throwable $e) { /* */ } }
    return ['ok' => true, 'sop' => foSopLigne(foSopRow($id) ?? $r)];
}

/**
 * Le code d'un nouveau document (10/11/2026, « la création le donne seule ») : zone 0 (SOP et manuel) · le moment
 * (.1 ouverture, .2 journée et « au besoin », .3 après-midi, .4 fermeture ; .0 pour un article, un mémo, une fiche)
 * · le premier numéro libre de ce moment parmi les documents du module. Une checklist garde le code de sa checklist.
 */
function foCodeAuto(string $type, ?string $moment): string
{
    $m = in_array($type, ['Article', 'Mémo', 'Fiche'], true) ? 0 : (['ouverture' => 1, 'journee' => 2, 'apres-midi' => 3, 'fermeture' => 4][$moment ?? ''] ?? 2);
    $pris = [];
    foreach (Db::rows('SELECT code FROM ceo_sop WHERE code LIKE ?', ['0.' . $m . '.%']) as $r) {
        if (preg_match('/^0\.' . $m . '\.(\d{2})$/', (string) $r['code'], $x) === 1) { $pris[(int) $x[1]] = true; }
    }
    for ($n = 1; isset($pris[$n]); $n++) { }
    return sprintf('0.%d.%02d', $m, $n);
}

/** POST /formation/sops — une nouvelle SOP : {code, dossier, type, poste, titre: {fr, nl}} → version 1.0, à valider. */
function wr_sop_creer(): array
{
    $b = body();
    $titre = foBi($b['titre'] ?? null);
    if ($titre['fr'] === '' && $titre['nl'] === '') { return foRefus(400, 'titre attendu'); }
    // Sans code, ou avec un code hors format : la création le donne (foCodeAuto) ; l'identifiant vient alors du titre.
    $codeDonne = trim((string) ($b['code'] ?? ''));
    $type = foType($b['type'] ?? 'SOP');
    $momentCode = isset($b['moment']) && is_string($b['moment']) ? $b['moment'] : null;
    if ($type === 'Checklist' ? $codeDonne === '' : preg_match('/^0\.[0-4]\.\d{2}$/', $codeDonne) !== 1) { $b['code'] = $type === 'Checklist' ? null : foCodeAuto($type, $momentCode); $codeDonne = ''; }
    $base = foId(sopSlug($codeDonne ?: ($titre['fr'] ?: $titre['nl'])));
    if ($base === null) { return foRefus(400, 'identifiant impossible à former'); }
    $id = $base;
    for ($i = 2; foSopRow($id) !== null; $i++) { $id = substr($base, 0, 28) . '-' . $i; }
    // L'assistant de création (06/11/2026) envoie la SOP entière (`contenu` : étapes, points critiques, quiz) et son
    // moment ; sans `contenu`, une SOP vide à rédiger dans l'éditeur, comme avant. Le néerlandais peut manquer : la SOP
    // reste « à valider », NL « à relire », et l'éditeur exige les deux langues pour publier une nouvelle version.
    $avec = is_array($b['contenu'] ?? null);
    $c = foContenu($avec ? array_merge($b['contenu'], ['titre' => $titre, 'objectif' => $b['objectif'] ?? ($b['contenu']['objectif'] ?? null)])
        : ['titre' => $titre, 'objectif' => $b['objectif'] ?? null, 'sections' => [['titre' => ['fr' => 'Étapes', 'nl' => 'Stappen'], 'etapes' => []]]]);
    if ($avec) {
        $n = 0;
        foreach ($c['sections'] as $sec) { foreach ($sec['etapes'] as $s) { if ($s['texte']['fr'] === '' && $s['texte']['nl'] === '') { return foRefus(400, 'étape ' . $s['numero'] . ' : texte attendu'); } $n++; } }
        if ($n === 0) { return foRefus(400, 'au moins une étape'); }
    }
    $moment = $b['moment'] ?? null;
    if ($moment !== null && !in_array($moment, ['ouverture', 'journee', 'apres-midi', 'fermeture'], true)) { return foRefus(400, 'moment inconnu'); }
    $st = foStats($c);
    $now = date('Y-m-d H:i:s');
    $par = foPar($b);
    $ordre = (int) (Db::row('SELECT COALESCE(MAX(ordre), 0) + 1 AS o FROM ceo_sop')['o'] ?? 1);
    Db::exec('INSERT INTO ceo_sop (id, code, type, dossier, poste, moment, statut, version, version_revalider, langue_origine, trad_fr, trad_nl, titre_fr, titre_nl,
                objectif_fr, objectif_nl, duree, nb_etapes, nb_photos, ordre, maj_le, maj_par)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, foCourt($b['code'] ?? null, 40), foType($b['type'] ?? 'SOP'), foDossier($b['dossier'] ?? '01'), foPoste($b['poste'] ?? 'vente'), $moment, 'a_valider', '1.0', '1.0',
            $titre['fr'] !== '' ? 'FR' : 'NL', $titre['fr'] !== '' ? 'origine' : 'a_relire', $titre['nl'] !== '' ? 'origine' : 'a_relire',
            mb_substr($titre['fr'] ?: $titre['nl'], 0, 190), mb_substr($titre['nl'], 0, 190), $c['objectif']['fr'], $c['objectif']['nl'], foCourt($c['duree'], 190),
            $st['etapes'], $st['photos'], $ordre, $now, $par]);
    Db::exec('INSERT INTO ceo_sop_version (sop_id, version, contenu, note, revalider, publie_le, publie_par) VALUES (?, ?, ?, ?, 0, ?, ?)',
        [$id, '1.0', foJson($c), $avec ? 'Création (assistant)' : 'Création', $now, $par]);
    try { journalAdd($par, 'Formation', $titre['fr'] ?: $titre['nl'], ($avec ? 'Nouvelle SOP créée avec l’assistant, ' . $st['etapes'] . ' étape(s)' : 'Nouvelle SOP créée') . ' (à valider)'); } catch (Throwable $e) { /* */ }
    http_response_code(201);
    return ['ok' => true, 'id' => $id, 'code' => $b['code'] ?? null];
}

/** PUT /formation/equipe/{shop}/{emp} — {postes: [...], valideur: bool, langue: fr|nl} pour une personne du magasin. */
function wr_sop_personne(string $shop, int $emp): array
{
    if (!preg_match('/^[\w-]{1,32}$/', $shop) || $emp <= 0) { return foRefus(400, 'magasin ou personne mal formés'); }
    $b = body();
    $postes = null;
    if (isset($b['postes'])) {
        if (!is_array($b['postes'])) { return foRefus(400, 'postes : liste attendue'); }
        $postes = implode(',', array_values(array_intersect(FO_POSTES, array_map('strval', $b['postes']))));
    }
    $val = array_key_exists('valideur', $b) ? (!empty($b['valideur']) ? 1 : 0) : null;
    $lang = isset($b['langue']) && in_array($b['langue'], FO_LANGUES, true) ? (string) $b['langue'] : null;
    $nom = '';
    foreach (foEquipe($shop) as $p) { if ($p['id'] === $emp) { $nom = $p['nom']; } }
    foPersonneEcrire($shop, $emp, $nom, $postes, $val, $lang);
    return ['ok' => true, 'personnes' => foEquipe($shop)];
}

/** POST /formation/importer — rejoue la reprise pour les SOP absentes de la base (n'écrase rien). */
function wr_sop_importer(): array
{
    return ['ok' => true, 'ajoutees' => foImporter()];
}

/* ═══ La tablette du poste (routes ouvertes, chaque écriture porte un PIN ou un jeton) ═══════════════ */

/** GET /formation/sop/tablette/init?poste= — le magasin (celui de l'appareil), ses personnes (sans PIN), les SOP du poste. */
function ep_sop_tablette_init(): array
{
    // La même tablette pour tous les magasins (06/11/2026) : les SOP, sans magasin ni équipe quand aucun appareil n'est ouvert.
    $shop = function_exists('tabAppareil') && tabAppareil() !== null ? foShopTablette() : null;
    $poste = isset($_GET['poste']) && in_array($_GET['poste'], FO_POSTES, true) ? (string) $_GET['poste'] : null;
    // Un brouillon (repris du CRM, pas encore relu) ne va pas sur la tablette du magasin.
    $rows = Db::rows('SELECT * FROM ceo_sop WHERE statut <> \'brouillon\'' . ($poste !== null ? ' AND poste = ?' : '') . ' ORDER BY dossier, ordre, id', $poste !== null ? [$poste] : []);
    $personnes = array_map(static fn ($p) => ['id' => $p['id'], 'nom' => $p['nom'], 'valideur' => $p['valideur'], 'langue' => $p['langue'], 'postes' => $p['postes'],
        'postesV25' => $p['postesV25'] ?? []], $shop !== null ? foEquipe($shop) : []);
    $app = function_exists('tabAppareil') ? tabAppareil() : null;
    return ['magasin' => $shop !== null ? ['id' => $shop, 'nom' => $app !== null && $app['nom'] !== '' ? $app['nom'] : foMagasinNom($shop)] : null, 'poste' => $poste, 'postes' => FO_POSTES,
        'personnes' => $personnes, 'sops' => array_map('foSopLigne', $rows), 'pinPanel' => class_exists('PanelApi') && PanelApi::configured()];
}

/** POST /formation/sop/tablette/identifier — {employe, pin} → jeton de 4 h, la personne, où elle en est (magasin = celui de l'appareil). */
function wr_sop_tablette_identifier(): array
{
    $b = body();
    $shop = foShopTablette($b['shop'] ?? null);
    $emp = (int) ($b['employe'] ?? 0);
    if ($shop === null || $emp <= 0) { return foRefus(400, 'magasin et personne attendus'); }
    [$ok, $msg] = foPinOk($shop, $emp, (string) ($b['pin'] ?? ''));
    if (!$ok) { return foRefus($msg === 'bloqué' ? 429 : 403, $msg === 'bloqué' ? 'trop d’essais : réessayer dans 10 minutes' : $msg); }
    $p = foPersonne($shop, $emp);
    if ($p === null) { return foRefus(404, 'personne inconnue dans ce magasin'); }
    return ['jeton' => foJeton(['s' => $shop, 'e' => $emp, 'n' => $p['nom']]), 'personne' => $p, 'suivi' => (object) foSuiviPersonne($shop, $emp)];
}

/** GET /formation/sop/tablette/moi?jeton= — où en est la personne identifiée, SOP par SOP. */
function ep_sop_tablette_moi(): array
{
    $j = foJetonTablette($_GET['jeton'] ?? null);
    if ($j === null) { return foRefus(401, 'jeton absent ou expiré : s’identifier à nouveau'); }
    return ['personne' => foPersonne($j['s'], (int) $j['e']), 'suivi' => (object) foSuiviPersonne($j['s'], (int) $j['e'])];
}

/** GET /formation/sop/tablette/sop/{id} — la SOP en vigueur, FR et NL, quiz sans ses réponses. */
function ep_sop_tablette_sop(string $id): array
{
    $r = foSopRow($id);
    if ($r === null || $r['statut'] === 'brouillon') { return foRefus(404, 'SOP inconnue'); }
    if (!foAccesOuvert($r, $_GET['acces'] ?? null)) { return foRefus(403, 'réservé au franchisé et au responsable du magasin : PIN attendu') + ['acces' => 'responsable']; }
    $c = foContenuVersion((string) $r['id'], (string) $r['version']);
    foreach ($c['quiz'] as &$q) { unset($q['bonne']); }
    unset($q, $c['corrections'], $c['remarques']);
    return foSopLigne($r) + ['contenu' => $c];
}

/** POST /formation/sop/tablette/lu — {jeton, sop} : la personne a parcouru toutes les étapes. */
function wr_sop_tablette_lu(): array
{
    $b = body();
    $j = foJetonTablette($b['jeton'] ?? null);
    if ($j === null) { return foRefus(401, 'jeton absent ou expiré : s’identifier à nouveau'); }
    $r = foSopRow((string) ($b['sop'] ?? ''));
    if ($r === null || $r['statut'] === 'brouillon') { return foRefus(404, 'SOP inconnue'); }
    $now = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO ceo_sop_suivi (shop_id, employee_id, sop_id, employee_nom, version, lu_le, maj_le) VALUES (?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE employee_nom = VALUES(employee_nom), lu_le = VALUES(lu_le), maj_le = VALUES(maj_le),
                version = IF(vu_le IS NULL, VALUES(version), version)',
        [$j['s'], (int) $j['e'], $r['id'], (string) $j['n'], (string) $r['version'], $now, $now]);
    return ['ok' => true, 'statut' => foStatut(foSuiviLigne($j['s'], (int) $j['e'], (string) $r['id']), $r)];
}

/** POST /formation/sop/tablette/quiz — {jeton, sop, reponses: [i, i, i]} → score ; réussi quand tout est juste. */
function wr_sop_tablette_quiz(): array
{
    $b = body();
    // Sans jeton (tablette ouverte, personne d'identifié) : le quiz est corrigé, rien n'est enregistré (un exercice).
    $j = isset($b['jeton']) && $b['jeton'] !== null && $b['jeton'] !== '' ? foJetonTablette($b['jeton']) : null;
    if ($j === null && isset($b['jeton']) && $b['jeton'] !== null && $b['jeton'] !== '') { return foRefus(401, 'jeton absent ou expiré : s’identifier à nouveau'); }
    $r = foSopRow((string) ($b['sop'] ?? ''));
    if ($r === null) { return foRefus(404, 'SOP inconnue'); }
    if (!foAccesOuvert($r, $b['acces'] ?? null)) { return foRefus(403, 'réservé au franchisé et au responsable du magasin : PIN attendu') + ['acces' => 'responsable']; }
    if (in_array($r['statut'], ['a_valider', 'brouillon'], true)) { return foRefus(409, 'SOP à valider : lisible, sans quiz ni validation pour l’instant'); }
    if ($j === null) {
        $c = foContenuVersion((string) $r['id'], (string) $r['version']);
        $rep = is_array($b['reponses'] ?? null) ? array_values($b['reponses']) : [];
        $faux = [];
        foreach ($c['quiz'] as $i => $q) { if (!isset($rep[$i]) || (int) $rep[$i] !== (int) $q['bonne']) { $faux[] = $i; } }
        $total = count($c['quiz']);
        return ['ok' => true, 'score' => $total - count($faux), 'total' => $total, 'reussi' => $total > 0 && $faux === [], 'faux' => $faux, 'exercice' => true];
    }
    $c = foContenuVersion((string) $r['id'], (string) $r['version']);
    $rep = is_array($b['reponses'] ?? null) ? array_values($b['reponses']) : [];
    $faux = [];
    foreach ($c['quiz'] as $i => $q) { if (!isset($rep[$i]) || (int) $rep[$i] !== (int) $q['bonne']) { $faux[] = $i; } }
    $total = count($c['quiz']);
    $score = $total - count($faux);
    $reussi = $total > 0 && $faux === [];
    $now = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO ceo_sop_suivi (shop_id, employee_id, sop_id, employee_nom, version, lu_le, quiz_le, quiz_score, quiz_essais, maj_le)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
              ON DUPLICATE KEY UPDATE employee_nom = VALUES(employee_nom), quiz_essais = quiz_essais + 1, maj_le = VALUES(maj_le),
                lu_le = COALESCE(lu_le, VALUES(lu_le)),
                quiz_le = IF(VALUES(quiz_score) = ?, VALUES(quiz_le), quiz_le),
                quiz_score = IF(VALUES(quiz_score) = ?, VALUES(quiz_score), quiz_score),
                version = IF(vu_le IS NULL AND VALUES(quiz_score) = ?, VALUES(version), version)',
        [$j['s'], (int) $j['e'], $r['id'], (string) $j['n'], (string) $r['version'], $now, $reussi ? $now : null, $reussi ? $score : null, $now, $total, $total, $total]);
    return ['ok' => true, 'score' => $score, 'total' => $total, 'reussi' => $reussi, 'faux' => $faux,
        'statut' => foStatut(foSuiviLigne($j['s'], (int) $j['e'], (string) $r['id']), $r)];
}

/**
 * POST /formation/sop/tablette/vu — {sop, employe, valideur, pin, decision: vu|pas_encore, note} (magasin = celui de
 * l'appareil). La responsable du magasin (valideur) a vu la personne faire la SOP sur place ; son PIN est vérifié au panel.
 */
function wr_sop_tablette_vu(): array
{
    $b = body();
    $shop = foShopTablette($b['shop'] ?? null);
    $emp = (int) ($b['employe'] ?? 0);
    $val = (int) ($b['valideur'] ?? 0);
    if ($shop === null || $emp <= 0 || $val <= 0) { return foRefus(400, 'magasin, personne et responsable attendus'); }
    $r = foSopRow((string) ($b['sop'] ?? ''));
    if ($r === null) { return foRefus(404, 'SOP inconnue'); }
    if (in_array($r['statut'], ['a_valider', 'brouillon'], true)) { return foRefus(409, 'SOP à valider : lisible, sans validation pour l’instant'); }
    $vp = foPersonne($shop, $val);
    if ($vp === null || !$vp['valideur']) { return foRefus(403, 'seule la responsable du magasin valide « vu faire »'); }
    if ($val === $emp) { return foRefus(403, 'on ne valide pas soi-même'); }
    [$ok, $msg] = foPinOk($shop, $val, (string) ($b['pin'] ?? ''));
    if (!$ok) { return foRefus($msg === 'bloqué' ? 429 : 403, $msg === 'bloqué' ? 'trop d’essais : réessayer dans 10 minutes' : $msg); }
    $p = foPersonne($shop, $emp);
    if ($p === null) { return foRefus(404, 'personne inconnue dans ce magasin'); }
    $s = foSuiviLigne($shop, $emp, (string) $r['id']);
    $c = foContenuVersion((string) $r['id'], (string) $r['version']);
    $decision = ($b['decision'] ?? 'vu') === 'pas_encore' ? 'pas_encore' : 'vu';
    if ($decision === 'vu' && $c['quiz'] !== [] && (empty($s['quiz_le']) || version_compare((string) $s['version'], (string) $r['version_revalider'], '<'))) {
        return foRefus(409, 'le quiz de cette version n’est pas encore réussi');
    }
    $now = date('Y-m-d H:i:s');
    $note = foCourt($b['note'] ?? null, 500);
    if ($decision === 'vu') {
        Db::exec('INSERT INTO ceo_sop_suivi (shop_id, employee_id, sop_id, employee_nom, version, lu_le, vu_le, vu_version, vu_par_id, vu_par_nom, note, maj_le)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE employee_nom = VALUES(employee_nom), version = VALUES(version), lu_le = COALESCE(lu_le, VALUES(lu_le)),
                    vu_le = VALUES(vu_le), vu_version = VALUES(vu_version), vu_par_id = VALUES(vu_par_id), vu_par_nom = VALUES(vu_par_nom),
                    note = VALUES(note), maj_le = VALUES(maj_le)',
            [$shop, $emp, $r['id'], $p['nom'], (string) $r['version'], $now, $now, (string) $r['version'], $val, $vp['nom'], $note, $now]);
    } else {
        Db::exec('INSERT INTO ceo_sop_suivi (shop_id, employee_id, sop_id, employee_nom, version, note, maj_le) VALUES (?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE note = VALUES(note), maj_le = VALUES(maj_le)',
            [$shop, $emp, $r['id'], $p['nom'], (string) $r['version'], $note ?? 'pas encore', $now]);
    }
    try { journalAdd($vp['nom'], 'Formation', (string) $r['titre_fr'], $p['nom'] . ($decision === 'vu' ? ' : vu faire ✓' : ' : pas encore') . ' (' . foMagasinNom($shop) . ')'); } catch (Throwable $e) { /* */ }
    // Les compétences du référentiel (codes C-… de la SOP, src/referentiel.php) : acquises maintenant ? Chacune,
    // acquise et reliée au panel (correspondance valide ou certaine), est cochée au panel pour la personne.
    $acq = null;
    $acqs = $decision === 'vu' && function_exists('rfVuFaire') ? rfVuFaire($shop, $p, $r) : [];
    $relies = array_values(array_filter($acqs, static fn ($x) => $x['panel'] !== null));
    if ($relies !== []) {
        $x = array_values(array_filter($relies, static fn ($y) => $y['acquise']))[0] ?? $relies[0];
        $acq = ['nom' => $x['nom'], 'code' => $x['code'], 'acquise' => $x['acquise'], 'vues' => $x['vues'], 'total' => $x['total']] + ($x['coche'] !== null ? ['panel' => $x['coche']] : []);
    }
    // Repli : la SOP n'a aucun code C-… relié au panel — l'ancienne compétence du panel rattachée dans Rédaction.
    elseif ($decision === 'vu' && ($r['competence_nom'] ?? null) !== null) {
        $sops = Db::rows('SELECT * FROM ceo_sop');
        $comps = foCompetencesUtilisees($sops);
        $mat = foMatrice($shop, [$p], $sops, $comps);
        $cle = foCompCle($r);
        $etat = $mat['personnes'][0]['competences'][$cle] ?? null;
        $acq = ['nom' => (string) $r['competence_nom'], 'acquise' => ($etat['statut'] ?? '') === 'acquise', 'vues' => $etat['vues'] ?? 0, 'total' => $etat['total'] ?? 0];
        // Acquise : la compétence est cochée au panel pour cette personne (décision du 06/11/2026).
        if ($acq['acquise'] && ctype_digit((string) ($r['competence_id'] ?? ''))) {
            $acq['panel'] = foCocherPanel($shop, $emp, (int) $r['competence_id'], (string) $r['competence_nom'], $p['nom']);
        }
    }
    return ['ok' => true, 'decision' => $decision, 'statut' => foStatut(foSuiviLigne($shop, $emp, (string) $r['id']), $r), 'competence' => $acq, 'competences' => $acqs];
}

/** PUT /formation/sop/tablette/langue — {jeton, langue} : la tablette s'en souvient pour cette personne. */
function wr_sop_tablette_langue(): array
{
    $b = body();
    $j = foJetonTablette($b['jeton'] ?? null);
    if ($j === null) { return foRefus(401, 'jeton absent ou expiré'); }
    $l = in_array($b['langue'] ?? null, FO_LANGUES, true) ? (string) $b['langue'] : null;
    if ($l === null) { return foRefus(400, 'langue : fr ou nl'); }
    foPersonneEcrire($j['s'], (int) $j['e'], (string) $j['n'], null, null, $l);
    return ['ok' => true, 'langue' => $l];
}

/** Les routes de la formation ; null quand le chemin n'en est pas une. `$public` : seulement la tablette. */
function sopRoute(string $method, string $path, bool $public = false): ?array
{
    if (!str_starts_with($path, '/formation/sop/')) { return null; }
    // La tablette du magasin (lot 5, src/tablettes.php) : chaque route exige la session de l'appareil, ouverte par
    // le PIN du magasin ; sans elle, 401 `appareil` et la tablette revient à l'écran du PIN.
    // La tablette OUVERTE (06/11/2026, client : « la tablette doit être ouverte, et pour tous les magasins la même chose ») :
    // lire la liste, une SOP et faire le quiz en exercice ne demandent plus la session de l'appareil ; le reste (s'identifier,
    // compter une lecture, « vu faire ») la demande toujours.
    $ouvert = ($method === 'GET' && ($path === '/formation/sop/tablette/init' || str_starts_with($path, '/formation/sop/tablette/sop/')))
        || ($method === 'POST' && ($path === '/formation/sop/tablette/quiz' || $path === '/formation/sop/tablette/acces'));
    if (!$ouvert && str_starts_with($path, '/formation/sop/tablette/') && function_exists('tabGarde') && ($g = tabGarde()) !== null) { return $g; }
    // Une photo envoyée par l'assistant de création : publique comme celles du dépôt (la tablette et la fiche l'affichent).
    if ($method === 'GET' && preg_match('#^/formation/sop/photo/([\w.-]{1,90}\.(?:jpe?g|png|webp))$#i', $path, $m) === 1) { return ep_sop_photo($m[1]); }
    $t = match (true) {
        $method === 'GET' && $path === '/formation/sop/tablette/init' => ep_sop_tablette_init(),
        $method === 'GET' && $path === '/formation/sop/tablette/moi' => ep_sop_tablette_moi(),
        $method === 'GET' && preg_match('#^/formation/sop/tablette/sop/([\w-]{1,32})$#', $path, $m) === 1 => ep_sop_tablette_sop($m[1]),
        $method === 'POST' && $path === '/formation/sop/tablette/identifier' => wr_sop_tablette_identifier(),
        $method === 'POST' && $path === '/formation/sop/tablette/lu' => wr_sop_tablette_lu(),
        $method === 'POST' && $path === '/formation/sop/tablette/quiz' => wr_sop_tablette_quiz(),
        $method === 'POST' && $path === '/formation/sop/tablette/acces' => wr_sop_tablette_acces(),
        $method === 'POST' && $path === '/formation/sop/tablette/vu' => wr_sop_tablette_vu(),
        $method === 'PUT' && $path === '/formation/sop/tablette/langue' => wr_sop_tablette_langue(),
        default => null,
    };
    if ($t !== null || $public) { return $t; }
    return match (true) {
        $method === 'GET' && $path === '/formation/sop/liste' => ep_sop_sops(),
        $method === 'GET' && preg_match('#^/formation/sop/fiche/([\w-]{1,32})\.pdf$#', $path, $m) === 1 => ep_sop_sop_pdf($m[1]),
        $method === 'GET' && preg_match('#^/formation/sop/fiche/([\w-]{1,32})$#', $path, $m) === 1 => ep_sop_sop($m[1]),
        $method === 'GET' && preg_match('#^/formation/sop/fiche/([\w-]{1,32})/versions/([\w.]{1,12})$#', $path, $m) === 1 => ep_sop_sop_version($m[1], $m[2]),
        $method === 'GET' && $path === '/formation/sop/competences' => ep_sop_competences(),
        $method === 'GET' && $path === '/formation/sop/equipe' => ep_sop_equipe(),
        $method === 'GET' && $path === '/formation/sop/fil' => ep_sop_fil(),
        $method === 'GET' && $path === '/formation/sop/manuel' => ep_sop_manuel(),
        $method === 'GET' && $path === '/formation/sop/manuel.pdf' => ep_sop_manuel_pdf(),
        $method === 'POST' && $path === '/formation/sop/fiche' => wr_sop_creer(),
        $method === 'POST' && $path === '/formation/sop/photo' => wr_sop_photo(),
        $method === 'PUT' && preg_match('#^/formation/sop/fiche/([\w-]{1,32})$#', $path, $m) === 1 => wr_sop_publier($m[1]),
        $method === 'PATCH' && preg_match('#^/formation/sop/fiche/([\w-]{1,32})$#', $path, $m) === 1 => wr_sop_reglage($m[1]),
        $method === 'PUT' && preg_match('#^/formation/sop/equipe/([\w-]{1,32})/(\d+)$#', $path, $m) === 1 => wr_sop_personne($m[1], (int) $m[2]),
        $method === 'POST' && $path === '/formation/sop/importer' => wr_sop_importer(),
        default => null,
    };
}

/* ═══ Le suivi, les statuts, les compétences ══════════════════════════════════════════════════════ */

/**
 * Le statut d'une personne face à une SOP : non, lu, quiz, vu, a_revoir. « vu » vaut tant qu'aucune
 * version publiée « à revalider » n'est venue après ; sinon « a_revoir » (aussi pour une lecture ancienne).
 */
function foStatut(?array $s, array $sop): string
{
    if ($s === null) { return 'non'; }
    $rv = (string) ($sop['version_revalider'] ?? '0');
    if (!empty($s['vu_le'])) { return version_compare((string) ($s['vu_version'] ?? '0'), $rv, '>=') ? 'vu' : 'a_revoir'; }
    if (empty($s['quiz_le']) && empty($s['lu_le'])) { return 'non'; }
    if (version_compare((string) ($s['version'] ?? '0'), $rv, '<')) { return 'a_revoir'; }
    return !empty($s['quiz_le']) ? 'quiz' : 'lu';
}

function foSuiviLigne(string $shop, int $emp, string $sop): ?array
{
    return Db::row('SELECT * FROM ceo_sop_suivi WHERE shop_id = ? AND employee_id = ? AND sop_id = ?', [$shop, $emp, $sop]);
}

/** [employee_id => [sop_id => ligne]] pour un magasin. */
function foSuiviMagasin(string $shop): array
{
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_sop_suivi WHERE shop_id = ?', [$shop]) as $s) { $out[(int) $s['employee_id']][(string) $s['sop_id']] = $s; }
    return $out;
}

/** [sop_id => {statut, luLe, quizLe, vuLe, vuPar, note}] pour une personne. */
function foSuiviPersonne(string $shop, int $emp): array
{
    $sops = [];
    foreach (Db::rows('SELECT * FROM ceo_sop') as $r) { $sops[(string) $r['id']] = $r; }
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_sop_suivi WHERE shop_id = ? AND employee_id = ?', [$shop, $emp]) as $s) {
        $r = $sops[(string) $s['sop_id']] ?? null;
        if ($r === null) { continue; }
        $out[(string) $s['sop_id']] = ['statut' => foStatut($s, $r), 'luLe' => foIso($s['lu_le']), 'quizLe' => foIso($s['quiz_le']),
            'vuLe' => foIso($s['vu_le']), 'vuPar' => $s['vu_par_nom'], 'note' => $s['note']];
    }
    return $out;
}

/** [sop_id => {vu, quiz, lu, aRevoir}] : le réseau entier, personnes distinctes. */
function foProgressionReseau(): array
{
    $sops = [];
    foreach (Db::rows('SELECT id, version_revalider FROM ceo_sop') as $r) { $sops[(string) $r['id']] = $r; }
    $out = [];
    foreach (Db::rows('SELECT sop_id, version, lu_le, quiz_le, vu_le, vu_version FROM ceo_sop_suivi') as $s) {
        $r = $sops[(string) $s['sop_id']] ?? null;
        if ($r === null) { continue; }
        $st = foStatut($s, $r);
        $o = &$out[(string) $s['sop_id']];
        $o ??= ['vu' => 0, 'quiz' => 0, 'lu' => 0, 'aRevoir' => 0];
        $k = ['vu' => 'vu', 'quiz' => 'quiz', 'lu' => 'lu', 'a_revoir' => 'aRevoir'][$st] ?? null;
        if ($k !== null) { $o[$k]++; }
        unset($o);
    }
    return $out;
}

/** La clé d'une compétence : l'id du panel s'il existe, sinon le nom (minuscules). */
function foCompCle(array $r): string
{
    $id = trim((string) ($r['competence_id'] ?? ''));
    return $id !== '' ? 'p:' . $id : 'n:' . mb_strtolower(trim((string) ($r['competence_nom'] ?? '')));
}

/** Les compétences rattachées aux SOP : [cle => {cle, id, nom, postes, sops: [...]}]. */
function foCompetencesUtilisees(array $sops): array
{
    $out = [];
    foreach ($sops as $r) {
        if (($r['competence_nom'] ?? null) === null) { continue; }
        $k = foCompCle($r);
        $out[$k] ??= ['cle' => $k, 'id' => $r['competence_id'] !== null ? (string) $r['competence_id'] : null, 'nom' => (string) $r['competence_nom'], 'postes' => [], 'sops' => []];
        $out[$k]['sops'][] = (string) $r['id'];
        if (!in_array((string) $r['poste'], $out[$k]['postes'], true)) { $out[$k]['postes'][] = (string) $r['poste']; }
    }
    return $out;
}

/**
 * La matrice d'un magasin : chaque personne × chaque compétence (acquise, en_cours, a_acquerir, a_revoir,
 * na = pas son poste), le taux d'acquisition, ce que la responsable doit voir faire.
 */
function foMatrice(string $shop, array $equipe, array $sops, array $comps): array
{
    $parId = [];
    foreach ($sops as $r) { $parId[(string) $r['id']] = $r; }
    $suivi = foSuiviMagasin($shop);
    $personnes = [];
    $acq = 0; $att = 0;
    $parComp = [];
    $liste = [];
    foreach ($equipe as $p) {
        $etats = [];
        foreach ($comps as $k => $c) {
            // Une SOP concerne la personne si elle est de son poste, ou si la personne l'a commencée.
            $conc = array_values(array_filter($c['sops'], static fn ($id) => isset($parId[$id]) && $parId[$id]['statut'] === 'publiee'
                && (in_array((string) $parId[$id]['poste'], $p['postes'], true) || isset($suivi[$p['id']][$id]))));
            if ($conc === []) { $etats[$k] = ['statut' => 'na']; continue; }
            $sts = array_map(static fn ($id) => foStatut($suivi[$p['id']][$id] ?? null, $parId[$id]), $conc);
            $v = count(array_filter($sts, static fn ($s) => $s === 'vu'));
            $st = in_array('a_revoir', $sts, true) ? 'a_revoir' : ($v === count($conc) ? 'acquise' : (array_diff($sts, ['non']) ? 'en_cours' : 'a_acquerir'));
            $etats[$k] = ['statut' => $st, 'vues' => $v, 'total' => count($conc)];
            $att++; if ($st === 'acquise') { $acq++; }
            $parComp[$k] ??= ['acquises' => 0, 'personnes' => 0];
            $parComp[$k]['personnes']++; if ($st === 'acquise') { $parComp[$k]['acquises']++; }
        }
        foreach ($suivi[$p['id']] ?? [] as $sid => $s) {
            if (!isset($parId[$sid]) || foStatut($s, $parId[$sid]) !== 'quiz') { continue; }
            $liste[] = ['employe' => $p['id'], 'nom' => $p['nom'], 'sop' => $sid, 'titre' => (string) $parId[$sid]['titre_fr'], 'quizLe' => foIso($s['quiz_le'])];
        }
        $personnes[] = $p + ['competences' => $etats];
    }
    usort($liste, static fn ($a, $b) => strcmp((string) $b['quizLe'], (string) $a['quizLe']));
    $pc = [];
    foreach ($parComp as $k => $x) { $pc[$k] = $x['personnes'] > 0 ? (int) round($x['acquises'] / $x['personnes'] * 100) : null; }
    return ['personnes' => $personnes, 'taux' => $att > 0 ? (int) round($acq / $att * 100) : null, 'aVoirFaire' => count($liste), 'liste' => $liste, 'parCompetence' => $pc];
}

/**
 * La liste des compétences du panel, gardée un jour : une route de liste si elle existe, sinon les compétences
 * requises lues sur les fiches techniques des produits (GET /products/{id}/technical-sheet/raw — celles de
 * l'app Cuisine). {liste: [{id, nom, description}], source, indispo?}.
 */
function foCompetencesPanel(bool $forcer = false): array
{
    $c = setting('foCompetencesPanel');
    if (!$forcer && is_array($c) && (int) ($c['ts'] ?? 0) > time() - FO_COMPETENCES_CACHE_S && !empty($c['liste'])) {
        return ['liste' => $c['liste'], 'source' => $c['source'] ?? '', 'lueLe' => date('c', (int) $c['ts'])];
    }
    $ancien = is_array($c) && is_array($c['liste'] ?? null) ? $c['liste'] : [];
    if (!class_exists('PanelApi') || !PanelApi::configured()) {
        return ['liste' => $ancien, 'source' => '', 'indispo' => true, 'motif' => 'compte consultant du panel non configuré (Paramètres)'];
    }
    $liste = [];
    $source = '';
    // La route du catalogue, celle que le module Formation lit déjà (src/formation.php, fmCompetence).
    $r = PanelApi::get('/competencies?per_page=1000');
    $l = foCompetencesNormaliser(is_array($r) ? (function_exists('fmListe') ? fmListe($r) : analyseListe($r)) : []);
    if ($l !== []) { $liste = $l; $source = '/competencies'; }
    if ($liste === []) {
        $prods = PanelApi::produits();
        $ids = [];
        foreach (is_array($prods) ? analyseListe($prods) : [] as $pr) {
            $id = (int) ($pr['id'] ?? $pr['id_product'] ?? 0);
            if ($id > 0) { $ids[] = $id; }
            if (count($ids) >= 80) { break; }
        }
        $paths = [];
        foreach ($ids as $id) { $paths['p' . $id] = '/products/' . $id . '/technical-sheet/raw?lang_code=fr'; }
        $vu = [];
        foreach (PanelApi::getParallele($paths, 4, 25) as $res) {
            if (!is_array($res)) { continue; }
            $cs = $res['competences'] ?? ($res['data']['competences'] ?? ($res['object']['required_competences'] ?? []));
            foreach (foCompetencesNormaliser(is_array($cs) ? $cs : []) as $x) { $vu[$x['id'] ?? mb_strtolower($x['nom'])] = $x; }
        }
        $liste = array_values($vu);
        usort($liste, static fn ($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));
        $source = $liste !== [] ? 'fiches techniques (' . count($ids) . ' produits)' : '';
    }
    if ($liste === []) {
        return ['liste' => $ancien, 'source' => '', 'indispo' => true, 'motif' => PanelApi::$lastError ?: 'aucune compétence trouvée au panel'];
    }
    try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['foCompetencesPanel', json_encode(['ts' => time(), 'liste' => $liste, 'source' => $source], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) { /* */ }
    return ['liste' => $liste, 'source' => $source, 'lueLe' => date('c')];
}

function foCompetencesNormaliser(array $l): array
{
    $out = [];
    foreach ($l as $x) {
        if (!is_array($x)) { continue; }
        if (function_exists('fmCompetence')) {
            $c = fmCompetence($x);
            if ($c['id'] === null) { continue; }
            $out[] = ['id' => (string) $c['id'], 'nom' => (string) $c['nom'], 'description' => $c['description'], 'categorie' => $c['categorie']];
            continue;
        }
        $nom = trim((string) ($x['name'] ?? $x['nom'] ?? ''));
        if ($nom === '') { continue; }
        $id = $x['id'] ?? null;
        $out[] = ['id' => $id !== null ? (string) $id : null, 'nom' => $nom, 'description' => trim((string) ($x['description'] ?? '')) ?: null, 'categorie' => null];
    }
    usort($out, static fn ($a, $b) => [(string) $a['categorie'], mb_strtolower($a['nom'])] <=> [(string) $b['categorie'], mb_strtolower($b['nom'])]);
    return $out;
}

/**
 * Coche une compétence acquise au panel pour une personne : PUT /employees/{id}/competencies, puis relecture de
 * la fiche (fmRelireEmploye du module) — « ok » seulement si le panel la porte vraiment. Déjà cochée : rien
 * n'est écrit. Journalisé. Rend {ok, deja?, erreur?}.
 */
function foCocherPanel(string $shop, int $emp, int $cid, string $nomComp, string $nomPers): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured() || !method_exists('PanelApi', 'requete') || !function_exists('fmRelireEmploye')) {
        return ['ok' => false, 'erreur' => 'compte consultant du panel non configuré'];
    }
    try {
        [$avant] = fmRelireEmploye($emp);
        if ($avant === null) { return ['ok' => false, 'erreur' => 'personne inconnue du panel']; }
        if (in_array($cid, $avant['competences'] ?? [], true)) { return ['ok' => true, 'deja' => true]; }
        // L'appel du panel pour « valider la compétence » d'une personne : PUT /employees/{id}/competencies.
        $w = PanelApi::requete('PUT', '/employees/' . $emp . '/competencies', ['competency_id' => $cid]);
        if (($w['code'] ?? 0) < 200 || ($w['code'] ?? 0) >= 300) { return ['ok' => false, 'erreur' => 'le panel a refusé : ' . ($w['erreur'] ?? ('HTTP ' . ($w['code'] ?? '?')))]; }
        [$apres] = fmRelireEmploye($emp);
        if ($apres === null || !in_array($cid, $apres['competences'] ?? [], true)) { return ['ok' => false, 'erreur' => 'le panel a répondu, mais la fiche ne porte pas la compétence']; }
        if (function_exists('fmPatchEmploye') && ctype_digit($shop)) { fmPatchEmploye((int) $shop, $apres); }
        try { journalAdd('Formation', 'Compétence', $nomPers, 'Compétence « ' . $nomComp . ' » acquise (toutes ses SOP vues faire) : cochée au panel'); } catch (Throwable $e) { /* */ }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'erreur' => $e->getMessage()];
    }
}

/* ═══ Les personnes du magasin ════════════════════════════════════════════════════════════════════ */

/**
 * Les personnes d'un magasin : le panel (/shops/{id}/employees : id, nom, rôle — gardés 6 h, jamais le PIN),
 * complété par nos réglages (postes, langue, valideur). Sans panel : nos réglages seuls (et le jeu de
 * démonstration quand il est activé). `$lire` faux : seulement ce qui est déjà en cache (vue réseau).
 */
function foEquipe(string $shop, bool $lire = true): array
{
    $base = foEquipePanel($shop, $lire);
    $reg = [];
    foreach (Db::rows('SELECT * FROM ceo_sop_personne WHERE shop_id = ?', [$shop]) as $r) { $reg[(int) $r['employee_id']] = $r; }
    $out = [];
    foreach ($base as $id => $e) {
        $r = $reg[$id] ?? null;
        $out[] = foPersonneForme($id, $e['nom'], $e['role'], $r);
        unset($reg[$id]);
    }
    if ($base === []) { foreach ($reg as $id => $r) { $out[] = foPersonneForme($id, (string) $r['nom'], '', $r); } }
    usort($out, static fn ($a, $b) => [$b['valideur'], $a['nom']] <=> [$a['valideur'], $b['nom']]);
    return $out;
}

function foPersonne(string $shop, int $emp): ?array
{
    foreach (foEquipe($shop) as $p) { if ($p['id'] === $emp) { return $p; } }
    return null;
}

function foPersonneForme(int $id, string $nom, string $role, ?array $r): array
{
    $postes = $r !== null && $r['postes'] !== null ? array_values(array_filter(explode(',', (string) $r['postes']))) : foPostesDuRole($role);
    $val = $r !== null && $r['valideur'] !== null ? (int) $r['valideur'] === 1 : foValideurDuRole($role);
    return ['id' => $id, 'nom' => $nom, 'role' => $role, 'postes' => $postes, 'valideur' => $val,
        'langue' => $r !== null && in_array($r['langue'], FO_LANGUES, true) ? (string) $r['langue'] : 'fr',
        // Ses postes du référentiel v25 (P1–P12), déduits de ses postes de tablette : « Ma journée » les lit.
        'postesV25' => function_exists('rfPostesPersonne') ? rfPostesPersonne($postes, $val) : []];
}

function foPersonneEcrire(string $shop, int $emp, string $nom, ?string $postes, ?int $valideur, ?string $langue): void
{
    Db::exec('INSERT INTO ceo_sop_personne (shop_id, employee_id, nom, postes, valideur, langue, maj_le) VALUES (?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE nom = IF(VALUES(nom) = \'\', nom, VALUES(nom)), postes = COALESCE(VALUES(postes), postes),
                valideur = COALESCE(VALUES(valideur), valideur), langue = COALESCE(VALUES(langue), langue), maj_le = VALUES(maj_le)',
        [$shop, $emp, $nom, $postes, $valideur, $langue, date('Y-m-d H:i:s')]);
}

/** [id => {nom, role}] du panel, gardé 6 h dans ceo_app_setting (`foEquipe:<shop>`) — ni PIN ni autre donnée personnelle. */
function foEquipePanel(string $shop, bool $lire): array
{
    $cle = 'foEquipe:' . $shop;
    $c = setting($cle);
    $ancien = is_array($c) && is_array($c['v'] ?? null) ? $c['v'] : [];
    if ($ancien !== [] && ((int) ($c['ts'] ?? 0) > time() - FO_EQUIPE_CACHE_S || !$lire)) { return foEquipeCles($ancien); }
    if (!class_exists('PanelApi') || !PanelApi::configured()) {
        if (seedEnabled()) { return foEquipeCles(foEquipeDemo($shop)); }
        return foEquipeCles($ancien);
    }
    if (!$lire) { return foEquipeCles($ancien); }
    $r = PanelApi::get('/shops/' . rawurlencode($shop) . '/employees');
    $l = is_array($r) ? analyseListe($r) : [];
    $out = [];
    foreach ($l as $em) {
        if (!is_array($em) || (int) ($em['id'] ?? 0) <= 0) { continue; }
        if (isset($em['active']) && !$em['active']) { continue; }
        $out[(string) (int) $em['id']] = ['nom' => function_exists('invNomCourt') ? invNomCourt($em) : trim((string) ($em['name'] ?? '')), 'role' => foRoleDe($em)];
    }
    if ($out === []) { return foEquipeCles($ancien); }
    try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['ts' => time(), 'v' => $out], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) { /* */ }
    return foEquipeCles($out);
}

function foEquipeCles(array $v): array
{
    $out = [];
    foreach ($v as $id => $e) { if (is_array($e)) { $out[(int) $id] = ['nom' => (string) ($e['nom'] ?? ''), 'role' => (string) ($e['role'] ?? '')]; } }
    return $out;
}

/** Le rôle d'un employé du panel, quel que soit le nom du champ. */
function foRoleDe(array $em): string
{
    foreach (['role', 'position', 'job', 'job_title', 'function', 'type', 'employee_type'] as $k) {
        $v = $em[$k] ?? null;
        if (is_array($v)) { $v = $v['name'] ?? $v['label'] ?? null; }
        if (is_string($v) && trim($v) !== '') { return trim($v); }
    }
    return '';
}

function foPostesDuRole(string $role): array
{
    $r = mb_strtolower($role);
    if ($r !== '' && preg_match('/respons|manag|gérant|gerant|franchis|chef|owner|admin|zaakvoerder|verantwoordelijk/u', $r)) { return FO_POSTES; }
    $p = [];
    if (preg_match('/vend|verko|caiss|kass|comptoir|sales|seller|winkel|toog/u', $r)) { $p[] = 'vente'; }
    if (preg_match('/cuis|bak|boulang|four|oven|baker/u', $r)) { $p[] = 'cuisson'; }
    if (preg_match('/prod|pât|pat|atelier|keuken|kitchen/u', $r)) { $p[] = 'production'; }
    if ($p !== []) { $p[] = 'nettoyage'; }
    return $p ?: ['vente', 'nettoyage'];
}

function foValideurDuRole(string $role): bool
{
    return $role !== '' && (bool) preg_match('/respons|manag|gérant|gerant|franchis|chef|owner|zaakvoerder|verantwoordelijk/u', mb_strtolower($role));
}

/**
 * Le jeu de démonstration (config `seed` activée et panel non configuré) : une équipe fictive par magasin,
 * PIN 1234 pour tous. Jamais lu quand le panel est branché.
 */
function foEquipeDemo(string $shop): array
{
    $noms = [['Elke V.', 'Responsable magasin'], ['Sofie M.', 'Vendeuse'], ['Inès B.', 'Vendeuse'], ['Karim A.', 'Boulanger cuisson'], ['Tom W.', 'Boulanger cuisson'], ['Mila R.', 'Production pâtisserie']];
    $base = (abs(crc32($shop)) % 900 + 100) * 10;
    $out = [];
    foreach ($noms as $i => $n) { $out[(string) ($base + $i + 1)] = ['nom' => $n[0], 'role' => $n[1]]; }
    return $out;
}

/**
 * Le PIN d'une personne, vérifié au panel (GET /shops/{id}/employees, champ `pin` — comme les checklists de
 * l'app Cuisine). 5 essais manqués bloquent la personne 10 minutes. Rend [ok, message].
 */
function foPinOk(string $shop, int $emp, string $pin): array
{
    $pin = trim($pin);
    if (!preg_match('/^\d{3,8}$/', $pin)) { return [false, 'PIN attendu (chiffres)']; }
    $cle = 'foPin:' . $shop . ':' . $emp;
    $b = setting($cle);
    if (is_array($b) && (int) ($b['jusque'] ?? 0) > time()) { return [false, 'bloqué']; }
    $vrai = null;
    if (class_exists('PanelApi') && PanelApi::configured()) {
        $r = PanelApi::get('/shops/' . rawurlencode($shop) . '/employees');
        if (!is_array($r)) { return [false, 'panel injoignable : réessayer']; }
        foreach (analyseListe($r) as $em) {
            if (is_array($em) && (int) ($em['id'] ?? 0) === $emp) { $vrai = trim((string) ($em['pin'] ?? $em['pin_code'] ?? $em['pincode'] ?? '')); break; }
        }
        if ($vrai === null) { return [false, 'personne inconnue dans ce magasin']; }
        if ($vrai === '') { return [false, 'aucun PIN enregistré au panel pour cette personne']; }
    } elseif (seedEnabled() && isset(foEquipeDemo($shop)[(string) $emp])) {
        $vrai = '1234';
    } else {
        return [false, 'compte consultant du panel non configuré : PIN invérifiable'];
    }
    $ok = hash_equals($vrai, $pin);
    $n = $ok ? 0 : (int) (is_array($b) ? ($b['n'] ?? 0) : 0) + 1;
    $val = $ok ? null : ['n' => $n, 'jusque' => $n >= FO_PIN_ESSAIS ? time() + FO_PIN_BLOCAGE_S : 0];
    try {
        if ($val === null) { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', [$cle]); }
        else { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($val)]); }
    } catch (Throwable $e) { /* */ }
    return $ok ? [true, ''] : [false, $n >= FO_PIN_ESSAIS ? 'bloqué' : 'PIN incorrect'];
}

/** Un jeton signé pour la tablette : base64url(json) . '.' . HMAC-SHA256 (secret de session du cockpit). */
function foJeton(array $d): string
{
    $d['x'] = time() + FO_JETON_S;
    $p = rtrim(strtr(base64_encode((string) json_encode($d, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    return $p . '.' . hash_hmac('sha256', 'fo|' . $p, foSecret());
}

function foJetonLire(mixed $j): ?array
{
    if (!is_string($j) || !preg_match('/^([\w-]+)\.([a-f0-9]{64})$/', $j, $m)) { return null; }
    if (!hash_equals(hash_hmac('sha256', 'fo|' . $m[1], foSecret()), $m[2])) { return null; }
    $d = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);
    if (!is_array($d) || (int) ($d['x'] ?? 0) < time() || !isset($d['s'], $d['e'])) { return null; }
    // `r` : le rôle d'un jeton d'accès (« responsable » : documents réservés), absent d'un jeton de personne.
    return ['s' => (string) $d['s'], 'e' => (int) $d['e'], 'n' => (string) ($d['n'] ?? ''), 'r' => isset($d['r']) ? (string) $d['r'] : null];
}

function foSecret(): string
{
    $s = function_exists('authSecret') ? authSecret() : '';
    return $s !== '' ? $s : (string) (setting('authSecret', '') ?: 'formation');
}

/** Le fil d'un magasin : lectures, quiz réussis, « vu faire », des 14 derniers jours. */
function foFil(string $shop, int $max): array
{
    $depuis = date('Y-m-d H:i:s', time() - FO_FIL_JOURS * 86400);
    $rows = Db::rows('SELECT s.*, p.titre_fr FROM ceo_sop_suivi s JOIN ceo_sop p ON p.id = s.sop_id WHERE s.shop_id = ? AND s.maj_le >= ? ORDER BY s.maj_le DESC LIMIT 200', [$shop, $depuis]);
    $ev = [];
    foreach ($rows as $r) {
        foreach ([['vu_le', 'vu'], ['quiz_le', 'quiz'], ['lu_le', 'lu']] as [$k, $t]) {
            if (!empty($r[$k]) && $r[$k] >= $depuis) {
                $ev[] = ['le' => foIso($r[$k]), 'type' => $t, 'nom' => (string) $r['employee_nom'], 'sop' => (string) $r['sop_id'], 'titre' => (string) $r['titre_fr'],
                    'par' => $t === 'vu' ? $r['vu_par_nom'] : null];
            }
        }
    }
    usort($ev, static fn ($a, $b) => strcmp((string) $b['le'], (string) $a['le']));
    return array_slice($ev, 0, $max);
}

/* ═══ Le PDF (Manuel opératoire, une SOP) ═════════════════════════════════════════════════════════ */
//
// Mise en page : design system « L'Atelier By », §13 (documents de gestion, version A du 06/11/2026). Papier
// blanc, Gotham intégrée (400 = Gotham_Medium, le texte ; 700 = Gotham_Regular, les seuls accents : type de
// pièce, titre d'étape, valeurs des références), palette du back-office, le Ruby à deux endroits : le filet
// d'en-tête et le filet de l'encadré des points critiques. Ni aplat, ni fond de page, ni pilule.
//
// Écrit pour wkhtmltopdf 0.12.6 « patched qt », le moteur de production : ni variables CSS, ni flexbox, ni
// grid, ni calc() — des blocs et des tableaux, des largeurs en mm, des valeurs résolues (le filet de ligne
// rgba(34,34,34,.06) devient #f2f2f2, son équivalent opaque sur blanc : ce WebKit peint mal les bordures
// rgba). Polices, logo et photos intégrés en data: ; photos réduites par GD sur fond blanc (pas
// d'object-fit). Un titre de section part avec sa première étape dans un bloc insécable (wkhtmltopdf ignore
// page-break-after: avoid), une étape ne se coupe pas, l'en-tête du sommaire se répète.
// Pied de page « marque · document · Page n / N » : une page de pied donnée à wkhtmltopdf (rapPdfRendu,
// clé piedHtml) ; Chromium (repli) et l'impression du navigateur le posent par les marges de @page.

/** Sort le PDF (moteur du cockpit, rapPdfRendu) ; sans moteur, la page imprimable — puis exit. */
function foPdfSortie(array $rows, string $l, bool $couverture, string $titre, string $fichier): void
{
    $pied = foPdfPiedTexte($rows, $l, $couverture, $titre);
    $html = foPdfHtml($rows, $l, $couverture, $titre, $pied);
    $pdf = function_exists('rapPdfRendu') ? rapPdfRendu($html, ['magasin' => foPdfMarque(), 'rapport' => $titre, 'genere' => date('d/m/Y'), 'envoye' => '',
        'piedHtml' => foPdfPiedHtml($pied, $l)]) : null;
    if ($pdf !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $fichier . '.pdf"');
        echo $pdf;
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('</body>', '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)})</script></body>', $html);
    exit;
}

/** Les libellés fixes du document, en FR ou en NL. */
function foPdfTextes(string $l): array
{
    return $l === 'nl'
        ? ['manuel' => 'Operationeel handboek', 'std' => 'De standaarden van het netwerk', 'ed' => 'Editie van', 'som' => 'Inhoud', 'obj' => 'Doel', 'pour' => 'Voor',
            'ver' => 'Versie', 'maj' => 'Bijgewerkt', 'comp' => 'Competentie', 'crit' => 'Kritieke punten', 'pc' => 'Kritiek punt', 'trad' => 'Vertaling na te lezen',
            'aval' => 'Te valideren', 'duree' => 'Duur', 'langue' => 'Taal', 'code' => 'Code', 'titre' => 'Titel', 'type' => 'Type', 'page' => 'Pagina',
            'types' => ['SOP' => 'SOP', 'Checklist' => 'Checklist', 'Mémo' => 'Memo', 'Fiche' => 'Fiche', 'Article' => 'Artikel']]
        : ['manuel' => 'Manuel opératoire', 'std' => 'Les standards du réseau', 'ed' => 'Édition du', 'som' => 'Sommaire', 'obj' => 'Objectif', 'pour' => 'Pour',
            'ver' => 'Version', 'maj' => 'Mise à jour', 'comp' => 'Compétence', 'crit' => 'Points critiques', 'pc' => 'Point critique', 'trad' => 'Traduction à relire',
            'aval' => 'À valider', 'duree' => 'Durée', 'langue' => 'Langue', 'code' => 'Code', 'titre' => 'Titre', 'type' => 'Type', 'page' => 'Page',
            'types' => ['SOP' => 'SOP', 'Checklist' => 'Checklist', 'Mémo' => 'Mémo', 'Fiche' => 'Fiche', 'Article' => 'Article']];
}

/** Le nom du réseau (réglage `reseau`, comme les rapports du cockpit). */
function foPdfMarque(): string
{
    $r = function_exists('setting') ? setting('reseau', []) : [];
    $n = is_array($r) ? trim((string) ($r['nom'] ?? '')) : '';
    return $n !== '' ? $n : 'L’Atelier By';
}

/** La ligne de gauche du pied : marque · document · langue · date d'édition. */
function foPdfPiedTexte(array $rows, string $l, bool $couverture, string $titre): string
{
    $doc = $titre;
    if (!$couverture && count($rows) === 1) {
        $r = $rows[0];
        $doc = trim(((string) $r['code'] !== '' ? $r['code'] . ' · ' : '') . 'v' . $r['version']);
    }
    return foPdfMarque() . ' · ' . $doc . ' · ' . strtoupper($l) . ' · ' . date('d/m/Y');
}

/**
 * Gotham (§4.1, §13.2) : 400 = Gotham_Medium, le texte ; 700 = Gotham_Regular, les accents. Intégrée (data:)
 * dans le document ; en chemin de fichier absolu pour la page de pied, que wkhtmltopdf recharge à chaque feuille
 * (--enable-local-file-access) — la décoder 80 fois rallongerait le rendu, borné à 25 s.
 */
function foPdfPolices(bool $avecAccents = true, bool $parFichier = false): string
{
    $dir = __DIR__ . '/../public/formation/fonts/';
    $css = '';
    foreach (array_filter(['Gotham_Medium.otf' => 400, 'Gotham_Regular.otf' => $avecAccents ? 700 : null]) as $f => $poids) {
        $chemin = realpath($dir . $f);
        if ($chemin === false) { continue; }
        $url = $parFichier ? "'file://" . str_replace(["'", '\\'], ['%27', '/'], $chemin) . "'"
            : 'data:font/opentype;base64,' . base64_encode((string) file_get_contents($chemin));
        $css .= "@font-face{font-family:'Gotham';src:url(" . $url . ") format('opentype');font-weight:" . $poids . ";font-style:normal}";
    }
    return $css;
}

/**
 * Une image intégrée (data:), réduite pour tenir dans $maxW × $maxH px et posée sur fond blanc — un PNG
 * transparent sort noir dans certains moteurs PDF, wkhtmltopdf ne lit pas le WebP et ignore object-fit : la
 * taille se décide ici. JPEG pour les photos, PNG pour le logo. Rend [src, largeur, hauteur] ou null.
 */
function foPdfImage(string $chemin, int $maxW, int $maxH, bool $png = false): ?array
{
    if (!is_file($chemin) || !function_exists('imagecreatefromstring')) { return null; }
    $src = @imagecreatefromstring((string) file_get_contents($chemin));
    if ($src === false) { return null; }
    $w = imagesx($src); $h = imagesy($src);
    $k = min(1.0, $maxW / max(1, $w), $maxH / max(1, $h));
    $nw = max(1, (int) round($w * $k)); $nh = max(1, (int) round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    $png ? imagepng($dst, null, 9) : imagejpeg($dst, null, 82);
    $o = (string) ob_get_clean();
    return ['src' => 'data:image/' . ($png ? 'png' : 'jpeg') . ';base64,' . base64_encode($o), 'w' => $nw, 'h' => $nh];
}

/** Une chaîne CSS (content: "…") sûre. */
function foPdfCss(string $s): string
{
    return str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', ' ', ' '], $s);
}

/** La page de pied que wkhtmltopdf répète sur chaque feuille : le document à gauche, « Page n / N » à droite. */
function foPdfPiedHtml(string $gauche, string $l): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<!doctype html><html><head><meta charset="utf-8"><style>' . foPdfPolices(false, true)
        . 'html,body{margin:0;padding:0;background:#fff}'
        . 'table{width:100%;border-collapse:collapse;border-top:1px solid #e6e0d8}'
        . "td{font:400 8.5px/1.6 'Gotham',Helvetica,Arial,sans-serif;color:#7a736a;padding-top:2.4mm;vertical-align:top}"
        . '</style><script>'
        . 'function pied(){var v={},q=document.location.search.substring(1).split("&");'
        . 'for(var i=0;i<q.length;i++){var kv=q[i].split("=");v[kv[0]]=decodeURIComponent(kv[1]||"");}'
        . 'var el=document.getElementById("pg");if(el){el.textContent=(v.page||"")+" / "+(v.topage||"");}}'
        . '</script></head><body onload="pied()"><table><tr><td>' . $e($gauche) . '</td>'
        . '<td style="text-align:right;white-space:nowrap;padding-left:6mm">' . $e(foPdfTextes($l)['page']) . ' <span id="pg"></span></td></tr></table></body></html>';
}

function foPdfHtml(array $rows, string $l, bool $couverture, string $titre, string $pied = ''): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $T = foPdfTextes($l);
    $marque = foPdfMarque();
    $photos = realpath(__DIR__ . '/../public/formation/sop/photos') ?: '';
    $logo = foPdfImage(__DIR__ . '/../public/formation/img/logo.png', 480, 160, true);
    $pied = $pied !== '' ? $pied : foPdfPiedTexte($rows, $l, $couverture, $titre);
    $marge = "font:400 8.5px 'Gotham',Helvetica,Arial,sans-serif;color:#7a736a;border-top:1px solid #e6e0d8;vertical-align:top;padding-top:2.4mm";

    // Les marges de @page portent le pied pour Chromium et l'impression du navigateur ; wkhtmltopdf ne les lit
    // pas (il a sa page de pied) : elles vivent dans leur propre <style>, une erreur d'analyse y reste enfermée.
    $cssPage = '@page{size:A4;margin:14mm 15mm 20mm;'
        . '@bottom-left{content:"' . foPdfCss($pied) . '";' . $marge . '}'
        . '@bottom-right{content:"' . foPdfCss($T['page']) . ' " counter(page) " / " counter(pages);' . $marge . ';text-align:right}}';
    $css = foPdfPolices()
        . 'html,body{background:#ffffff}'
        . "body{margin:0;font:400 11px/1.5 'Gotham',Helvetica,Arial,sans-serif;color:#1c1a17;-webkit-print-color-adjust:exact;print-color-adjust:exact}"
        . 'b{font-weight:700}h1,h2,h3,th{font-weight:400}p{margin:0}'
        . 'table{border-collapse:collapse;border-spacing:0}'
        . 'img{background:#ffffff}'
        // En-tête : logo à gauche, type de pièce à droite, filet Ruby 2 px.
        . '.hd{width:100%;border-bottom:2px solid #8D1D2C;margin:0 0 16px}'
        . '.hd td{padding:0 0 12px;vertical-align:bottom}'
        . '.hd img{height:34px;width:auto;display:block}'
        . '.hd-m{font-size:15px}'
        . '.hd-r{text-align:right;font-size:10px;line-height:1.5;color:#7a736a}'
        . '.hd-r b{display:block;font-size:15px;letter-spacing:.06em;text-transform:uppercase;color:#1c1a17}'
        // Titre de la SOP ou du manuel, son rattachement en petites capitales.
        . '.kick{font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin:0 0 4px}'
        . 'h1{font-size:21px;line-height:1.25;margin:0 0 8px;color:#1c1a17}'
        . '.tag{display:inline-block;font-size:9px;font-weight:400;line-height:1.4;padding:1px 6px;border-radius:4px;background:#F6E7CE;color:#7A5207;white-space:nowrap}'
        . '.tags{margin:0 0 10px}.tags .tag{margin-right:6px}'
        . '.lab{font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin:0 0 4px}'
        // Objectif | Pour, puis les références entre deux filets.
        . '.parties{width:100%;table-layout:fixed;margin:4px 0 14px}'
        . '.parties td{vertical-align:top;padding:0 24px 0 0}'
        . '.refs{width:100%;table-layout:fixed;border-top:1px solid #e6e0d8;border-bottom:1px solid #e6e0d8;margin:0 0 4px}'
        . '.refs td{vertical-align:top;padding:8px 12px 8px 0}'
        . '.refs .v{font-size:11px;font-weight:700}'
        // Une section : son titre part avec la première étape (bloc insécable).
        . '.garde{page-break-inside:avoid}'
        . '.sec{font-size:13px;line-height:1.35;color:#1c1a17;padding:14px 0 5px;border-bottom:1px solid #e6e0d8}'
        . '.st{width:100%;table-layout:fixed;page-break-inside:avoid}'
        . '.st td{vertical-align:top;padding:5px 8px 5px 0;border-bottom:1px solid #f2f2f2}'
        . '.st .n{width:12mm;color:#7a736a;white-space:nowrap}'
        . '.st .t{display:block;font-weight:700}'
        . '.st .tag{margin-left:6px}'
        . '.st .ph{width:44mm;padding-right:0;text-align:right}'
        . '.st .ph img{display:block;margin:0 0 0 auto;border:1px solid #e6e0d8}'
        // Points critiques : l'encadré à filet gauche Ruby 3 px.
        . '.box{border-left:3px solid #8D1D2C;padding:2px 0 2px 12px;margin:20px 0 0;page-break-inside:avoid}'
        . '.box ul{margin:4px 0 0;padding:0 0 0 14px}.box li{margin:0 0 2px}'
        // Sommaire du manuel : en-tête répété, une ligne ne se coupe pas.
        . '.som-t{font-size:13px;line-height:1.35;color:#1c1a17;margin:20px 0 0}'
        . '.som{width:100%;table-layout:fixed;margin:6px 0 0}'
        . '.som th{padding:6px 8px 6px 0;border-bottom:1px solid #e6e0d8;font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a;text-align:left}'
        . '.som td{padding:3px 8px 3px 0;border-bottom:1px solid #f2f2f2;vertical-align:top}'
        . '.som .grp td{padding-top:10px;font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;border-bottom:1px solid #e6e0d8}'
        . '.som .num{text-align:right;white-space:nowrap;padding-right:0}'
        . 'thead{display:table-header-group}tr{page-break-inside:avoid}'
        . '.saut{page-break-before:always}';

    $entete = static fn (string $type, string $sous): string => '<table class="hd" cellpadding="0" cellspacing="0"><tr><td>'
        . ($logo !== null ? '<img src="' . $logo['src'] . '" alt="' . $e($marque) . '">' : '<span class="hd-m">' . $e($marque) . '</span>')
        . '</td><td class="hd-r"><b>' . $e($type) . '</b>' . $e($sous) . '</td></tr></table>';

    $h = '<!doctype html><html lang="' . $l . '"><head><meta charset="utf-8"><title>' . $e($titre) . '</title><style>' . $css . '</style><style>' . $cssPage . '</style></head><body>';
    if ($couverture) {
        // Première page : la pièce (le manuel), ses références et le sommaire — pas de couverture pleine,
        // réservée aux documents de campagne (§13.1).
        $h .= $entete($T['manuel'], $T['ed'] . ' ' . date('d/m/Y') . ' · ' . strtoupper($l))
            . ($titre !== $T['manuel'] ? '<p class="kick">' . $e($titre) . '</p>' : '') . '<h1>' . $e($T['std']) . '</h1>'
            . '<table class="refs" cellpadding="0" cellspacing="0"><tr>'
            . '<td><div class="lab">' . $e($T['ed']) . '</div><div class="v">' . $e(date('d/m/Y')) . '</div></td>'
            . '<td><div class="lab">' . $e($T['langue']) . '</div><div class="v">' . $e(strtoupper($l)) . '</div></td>'
            . '<td><div class="lab">SOP</div><div class="v">' . count($rows) . '</div></td><td></td></tr></table>'
            . '<p class="som-t">' . $e($T['som']) . '</p>'
            . '<table class="som" cellpadding="0" cellspacing="0"><thead><tr><th style="width:34mm">' . $e($T['code']) . '</th><th>' . $e($T['titre'])
            . '</th><th style="width:22mm">' . $e($T['type']) . '</th><th class="num" style="width:16mm">' . $e($T['ver']) . '</th></tr></thead><tbody>';
        $cur = '';
        foreach ($rows as $r) {
            if ($r['dossier'] !== $cur) {
                $cur = (string) $r['dossier'];
                $h .= '<tr class="grp"><td colspan="4">' . $e($cur . ' · ' . (FO_DOSSIERS[$cur][$l] ?? $cur)) . '</td></tr>';
            }
            $h .= '<tr><td>' . $e((string) $r['code']) . '</td><td>' . $e(foTitreL($r, $l)) . '</td><td>' . $e($T['types'][(string) $r['type']] ?? (string) $r['type'])
                . '</td><td class="num">v' . $e($r['version']) . '</td></tr>';
        }
        $h .= '</tbody></table>';
    }
    foreach ($rows as $i => $r) {
        $c = foContenuLangue(foContenuVersion((string) $r['id'], (string) $r['version']), $l, false);
        $tradAR = ($r['trad_' . $l] ?? '') === 'a_relire';
        $dossier = (string) $r['dossier'];
        $h .= '<div class="sop' . ($couverture || $i > 0 ? ' saut' : '') . '">'
            . $entete($T['types'][(string) $r['type']] ?? (string) $r['type'], trim(((string) $r['code'] !== '' ? $r['code'] . ' · ' : '') . 'v' . $r['version'] . ' · ' . strtoupper($l)))
            . '<p class="kick">' . $e($T['manuel'] . ' · ' . $dossier . ' · ' . (FO_DOSSIERS[$dossier][$l] ?? '')) . '</p>'
            . '<h1>' . $e($c['titre']) . '</h1>';
        if ($r['statut'] === 'a_valider' || $tradAR) {
            $h .= '<p class="tags">' . ($r['statut'] === 'a_valider' ? '<span class="tag">' . $e($T['aval']) . '</span>' : '')
                . ($tradAR ? '<span class="tag">' . $e($T['trad']) . '</span>' : '') . '</p>';
        }
        if ($c['objectif'] !== '' || $c['pour'] !== '') {
            $h .= '<table class="parties" cellpadding="0" cellspacing="0"><tr>'
                . '<td>' . ($c['objectif'] !== '' ? '<div class="lab">' . $e($T['obj']) . '</div><p>' . $e($c['objectif']) . '</p>' : '') . '</td>'
                . '<td>' . ($c['pour'] !== '' ? '<div class="lab">' . $e($T['pour']) . '</div><p>' . $e($c['pour']) . '</p>' : '') . '</td></tr></table>';
        }
        $refs = [[$T['ver'], 'v' . $r['version']], [$T['maj'], foPdfDate((string) $r['maj_le'])]];
        if ($c['duree'] !== '') { $refs[] = [$T['duree'], $c['duree']]; }
        if (($r['competence_nom'] ?? null) !== null) { $refs[] = [$T['comp'], (string) $r['competence_nom']]; }
        $h .= '<table class="refs" cellpadding="0" cellspacing="0"><tr>';
        foreach (array_pad($refs, 4, null) as $ref) {
            $h .= '<td>' . ($ref !== null ? '<div class="lab">' . $e($ref[0]) . '</div><div class="v">' . $e($ref[1]) . '</div>' : '') . '</td>';
        }
        $h .= '</tr></table>';
        foreach ($c['sections'] as $sec) {
            $avecPhoto = false;
            foreach ($sec['etapes'] as $st) { if ($st['photo'] !== null) { $avecPhoto = true; break; } }
            $lignes = [];
            foreach ($sec['etapes'] as $st) {
                // Cadre de 42 × 56 mm : une photo en largeur prend la largeur, une capture en hauteur la hauteur.
                $img = $avecPhoto && $st['photo'] !== null && $photos !== '' ? foPdfImage(foPhotoFichier($st['photo']), 500, 666) : null;
                $taille = $img !== null ? (($img['w'] * 56 >= $img['h'] * 42) ? 'width:42mm;height:' . round(42 * $img['h'] / $img['w'], 1) . 'mm'
                    : 'width:' . round(56 * $img['w'] / $img['h'], 1) . 'mm;height:56mm') : '';
                $tag = $st['critique'] ? '<span class="tag">' . $e($T['pc']) . '</span>' : '';
                $lignes[] = '<table class="st" cellpadding="0" cellspacing="0"><tr><td class="n">' . $e($st['numero']) . '</td><td>'
                    . ($st['titre'] !== '' ? '<b class="t">' . $e($st['titre']) . $tag . '</b>' : ($tag !== '' ? '<div>' . $tag . '</div>' : ''))
                    . nl2br($e($st['texte'])) . (!empty($st['video']) ? '<div class="vid">' . $e('▶ ' . $st['video']) . '</div>' : '') . '</td>'
                    . ($avecPhoto ? '<td class="ph">' . ($img !== null ? '<img src="' . $img['src'] . '" alt="" style="' . $taille . '">' : '') . '</td>' : '') . '</tr></table>';
            }
            // Le titre ne reste jamais seul en bas de page : il part avec la première étape.
            $h .= '<div class="garde"><div class="sec">' . $e($sec['titre']) . '</div>' . ($lignes[0] ?? '') . '</div>' . implode('', array_slice($lignes, 1));
        }
        if ($c['points_critiques'] !== []) {
            $h .= '<div class="box"><div class="lab">' . $e($T['crit']) . '</div><ul>'
                . implode('', array_map(static fn ($x) => '<li>' . $e($x) . '</li>', $c['points_critiques'])) . '</ul></div>';
        }
        $h .= '</div>';
    }
    return $h . '</body></html>';
}

/** « 2026-11-06 08:12:00 » → « 06/11/2026 » (§13.1 : dates des tableaux). */
function foPdfDate(string $v): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] : $v;
}

/* ═══ Le contenu d'une SOP ════════════════════════════════════════════════════════════════════════ */

/** Le contenu, normalisé : chaque texte en {fr, nl}, les étapes numérotées, rien d'autre que les champs connus. */
function foContenu(array $j): array
{
    $sections = [];
    foreach (is_array($j['sections'] ?? null) ? $j['sections'] : [] as $sec) {
        if (!is_array($sec)) { continue; }
        $et = [];
        foreach (is_array($sec['etapes'] ?? null) ? $sec['etapes'] : [] as $i => $s) {
            if (!is_array($s)) { continue; }
            $photo = foPhotoNom($s['photo'] ?? null);
            $titre = foBi($s['titre'] ?? null);
            $et[] = ['numero' => foCourt(isset($s['numero']) ? (string) $s['numero'] : (string) ($i + 1), 16) ?? (string) ($i + 1),
                'titre' => $titre['fr'] === '' && $titre['nl'] === '' ? null : $titre, 'texte' => foBi($s['texte'] ?? null),
                'critique' => !empty($s['critique']), 'photo' => $photo, 'video' => foVideo($s['video'] ?? null)];
        }
        $sections[] = ['titre' => foBi($sec['titre'] ?? null), 'etapes' => $et];
    }
    $quiz = [];
    foreach (is_array($j['quiz'] ?? null) ? $j['quiz'] : [] as $q) {
        if (!is_array($q)) { continue; }
        $ch = is_array($q['choix'] ?? null) ? $q['choix'] : [];
        $fr = array_values(array_map('strval', is_array($ch['fr'] ?? null) ? $ch['fr'] : (array_is_list($ch) ? $ch : [])));
        $nl = array_values(array_map('strval', is_array($ch['nl'] ?? null) ? $ch['nl'] : []));
        if (count($fr) < 2 && count($nl) < 2) { continue; }
        $n = max(count($fr), count($nl));
        $quiz[] = ['question' => foBi($q['question'] ?? null), 'choix' => ['fr' => array_pad($fr, $n, ''), 'nl' => array_pad($nl, $n, '')],
            'bonne' => max(0, min($n - 1, (int) ($q['bonne'] ?? 0)))];
    }
    return [
        'titre' => foBi($j['titre'] ?? null),
        'objectif' => foBi($j['objectif'] ?? null),
        'pour' => foBi($j['pour'] ?? null),
        'duree' => is_string($j['duree'] ?? null) ? trim($j['duree']) : null,
        'sections' => $sections,
        'points_critiques' => array_values(array_filter(array_map('foBi', is_array($j['points_critiques'] ?? null) ? $j['points_critiques'] : []),
            static fn ($x) => $x['fr'] !== '' || $x['nl'] !== '')),
        'quiz' => $quiz,
        'corrections' => array_values(array_map('strval', array_filter(is_array($j['corrections'] ?? null) ? $j['corrections'] : [], 'is_scalar'))),
        'remarques' => array_values(array_map('strval', array_filter(is_array($j['remarques'] ?? null) ? $j['remarques'] : [], 'is_scalar'))),
    ];
}

/** Ce qui empêche de publier un contenu ; null s'il est publiable. */
function foContenuErreur(array $c): ?string
{
    if ($c['titre']['fr'] === '' || $c['titre']['nl'] === '') { return 'le titre est attendu en FR et en NL'; }
    $n = 0;
    foreach ($c['sections'] as $si => $sec) {
        foreach ($sec['etapes'] as $ei => $s) {
            $n++;
            if ($s['texte']['fr'] === '' || $s['texte']['nl'] === '') { return 'étape ' . $s['numero'] . ' (section ' . ($si + 1) . ') : texte attendu en FR et en NL'; }
        }
    }
    if ($n === 0) { return 'au moins une étape'; }
    foreach ($c['quiz'] as $i => $q) {
        if ($q['question']['fr'] === '' || $q['question']['nl'] === '') { return 'question ' . ($i + 1) . ' du quiz : attendue en FR et en NL'; }
    }
    return null;
}

/** Le contenu d'une version (normalisé) ; un contenu vide si la version manque. */
function foContenuVersion(string $id, string $v): array
{
    $r = Db::row('SELECT contenu FROM ceo_sop_version WHERE sop_id = ? AND version = ?', [$id, $v]);
    $j = $r !== null ? json_decode((string) $r['contenu'], true) : null;
    return foContenu(is_array($j) ? $j : []);
}

/** Le contenu dans une langue : chaque {fr, nl} devient un texte (repli sur l'autre langue s'il est vide). */
function foContenuLangue(array $c, string $l, bool $avecQuiz): array
{
    $o = $l === 'nl' ? 'fr' : 'nl';
    $t = static fn (?array $x): string => $x === null ? '' : ((string) ($x[$l] ?? '') !== '' ? (string) $x[$l] : (string) ($x[$o] ?? ''));
    $out = ['titre' => $t($c['titre']), 'objectif' => $t($c['objectif']), 'pour' => $t($c['pour']), 'duree' => (string) ($c['duree'] ?? ''), 'sections' => [],
        'points_critiques' => array_map($t, $c['points_critiques'])];
    foreach ($c['sections'] as $sec) {
        $out['sections'][] = ['titre' => $t($sec['titre']), 'etapes' => array_map(static fn ($s) => ['numero' => $s['numero'], 'titre' => $t($s['titre']),
            'texte' => $t($s['texte']), 'critique' => $s['critique'], 'photo' => $s['photo'], 'video' => $s['video'] ?? null], $sec['etapes'])];
    }
    if ($avecQuiz) {
        $out['quiz'] = array_map(static fn ($q) => ['question' => $t($q['question']), 'choix' => array_map(static fn ($a, $b) => $a !== '' ? $a : $b,
            $q['choix'][$l] ?? [], $q['choix'][$o] ?? []), 'bonne' => $q['bonne']], $c['quiz']);
    }
    return $out;
}

/* ═══ Photos envoyées et vidéos des étapes (assistant de création, 06/11/2026) ══════════════════════════════════
 * Une photo du dépôt : « nom.jpg » dans public/formation/sop/photos/. Une photo envoyée : « u/nom.jpg », gardée dans
 * public/uploads/sop/ (que la livraison ne touche pas : `rsync --exclude public/uploads`) et servie par
 * GET /formation/sop/photo/nom.jpg. Une vidéo : une adresse https (YouTube, Vimeo, un fichier), montrée sur la tablette. */

const FO_PHOTO_MAX = 8 * 1024 * 1024;   // une photo envoyée, avant réduction
const FO_PHOTO_COTE = 1600;             // le grand côté après réduction

/** Le nom d'une photo d'étape : « u/… » (envoyée) gardé tel quel, sinon le nom de fichier du dépôt ; null sinon. */
function foPhotoNom(mixed $v): ?string
{
    if (!is_string($v) || trim($v) === '') { return null; }
    $v = trim($v);
    if (preg_match('#^u/[\w.-]{1,90}\.(jpe?g|png|webp)$#i', $v) === 1) { return $v; }
    $b = basename($v);
    return preg_match('/^[\w.-]{1,80}\.(jpe?g|png|webp)$/i', $b) === 1 ? $b : null;
}

/** Le fichier d'une photo d'étape, sur le disque. */
function foPhotoFichier(string $photo): string
{
    if (str_starts_with($photo, 'u/')) { return foPhotosEnvoyees() . '/' . basename(substr($photo, 2)); }
    return (realpath(__DIR__ . '/../public/formation/sop/photos') ?: __DIR__ . '/../public/formation/sop/photos') . '/' . basename($photo);
}
function foPhotosEnvoyees(): string { return __DIR__ . '/../public/uploads/sop'; }

/** Une vidéo d'étape : une adresse https lisible (300 caractères au plus), sinon null. */
function foVideo(mixed $v): ?string
{
    if (!is_string($v)) { return null; }
    $v = trim($v);
    if ($v === '' || mb_strlen($v) > 300 || !str_starts_with($v, 'https://') || filter_var($v, FILTER_VALIDATE_URL) === false || preg_match('/[\s"<>]/', $v)) { return null; }
    return $v;
}

/**
 * POST /formation/sop/photo {data: "data:image/…;base64,…"} — une photo d'étape : JPEG, PNG ou WebP reconnus à leur
 * signature, 8 Mo au plus, réduite à 1600 px de grand côté (JPEG) quand GD est là. Rend {photo: "u/nom.jpg", url}.
 */
function wr_sop_photo(): array
{
    $b = body();
    $d = is_string($b['data'] ?? null) ? $b['data'] : '';
    if (preg_match('#^data:image/(?:jpeg|png|webp);base64,#', $d, $m) !== 1) { return foRefus(400, 'photo attendue (JPEG, PNG ou WebP)'); }
    $brut = base64_decode(substr($d, strlen($m[0])), true);
    if ($brut === false || $brut === '') { return foRefus(400, 'photo illisible'); }
    if (strlen($brut) > FO_PHOTO_MAX) { return foRefus(413, 'photo trop lourde (8 Mo au plus)'); }
    $ext = str_starts_with($brut, "\xFF\xD8\xFF") ? 'jpg' : (str_starts_with($brut, "\x89PNG\r\n\x1A\n") ? 'png'
        : (substr($brut, 0, 4) === 'RIFF' && substr($brut, 8, 4) === 'WEBP' ? 'webp' : ''));
    if ($ext === '') { return foRefus(400, 'ce fichier n’est pas une photo (JPEG, PNG ou WebP)'); }
    // Réduite et réécrite en JPEG par GD : rien d'autre que les pixels ne reste (ni métadonnées, ni position GPS).
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($brut)) !== false) {
        $w = imagesx($im); $h = imagesy($im);
        $k = min(1, FO_PHOTO_COTE / max($w, $h));
        $nw = max(1, (int) round($w * $k)); $nh = max(1, (int) round($h * $k));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagejpeg($out, null, 82); $brut = (string) ob_get_clean();
        imagedestroy($im); imagedestroy($out);
        $ext = 'jpg';
    }
    $dir = foPhotosEnvoyees();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return foRefus(500, 'dossier des photos impossible à créer'); }
    $nom = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $nom, $brut) === false) { return foRefus(500, 'photo impossible à enregistrer'); }
    return ['ok' => true, 'photo' => 'u/' . $nom, 'url' => 'formation/sop/photo/' . $nom];
}

/** GET /formation/sop/photo/{nom} — une photo envoyée (assistant de création). */
function ep_sop_photo(string $nom): array
{
    $f = foPhotosEnvoyees() . '/' . basename($nom);
    if (!is_file($f)) { return foRefus(404, 'photo inconnue'); }
    $type = preg_match('/\.png$/i', $f) ? 'image/png' : (preg_match('/\.webp$/i', $f) ? 'image/webp' : 'image/jpeg');
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=604800, immutable');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

function foStats(array $c): array
{
    $e = 0; $p = 0;
    foreach ($c['sections'] as $sec) { foreach ($sec['etapes'] as $s) { $e++; if ($s['photo'] !== null) { $p++; } } }
    return ['etapes' => $e, 'photos' => $p];
}

/** Une SOP telle que l'API la rend (sans son contenu). */
/** L'accès d'un document : « responsable » (réservé, PIN du responsable sur la tablette) ou « tous ». */
function foAcces(array $r): string { return ($r['acces'] ?? null) === 'responsable' ? 'responsable' : 'tous'; }

/** Le PIN du responsable qui ouvre les documents réservés : le réglage `foPinResponsable`, sinon le PIN provisoire. */
function foPinResponsable(): string
{
    $v = setting('foPinResponsable');
    $v = is_scalar($v) ? trim((string) $v) : '';
    return preg_match('/^\d{4,8}$/', $v) === 1 ? $v : FO_PIN_RESPONSABLE_PROVISOIRE;
}

/** Un document réservé est-il ouvert par ce jeton d'accès (rendu par POST /formation/sop/tablette/acces) ? */
function foAccesOuvert(array $r, mixed $jeton): bool
{
    if (foAcces($r) !== 'responsable') { return true; }
    $d = foJetonLire($jeton);
    return $d !== null && ($d['r'] ?? null) === 'responsable';
}

/**
 * POST /formation/sop/tablette/acces — {pin} : le PIN du responsable ouvre les documents réservés pour 4 h (jeton signé,
 * gardé dans l'onglet). Cinq essais manqués bloquent dix minutes, pour toute la tablette (comme le PIN d'une personne).
 */
function wr_sop_tablette_acces(): array
{
    $b = body();
    $pin = trim((string) ($b['pin'] ?? ''));
    if (!preg_match('/^\d{3,8}$/', $pin)) { return foRefus(400, 'PIN attendu (chiffres)'); }
    $cle = 'foPinResponsableEssais';
    $e = setting($cle);
    if (is_array($e) && (int) ($e['jusque'] ?? 0) > time()) { return foRefus(429, 'trop d’essais : réessayer dans 10 minutes'); }
    $ok = hash_equals(foPinResponsable(), $pin);
    $n = $ok ? 0 : (int) (is_array($e) ? ($e['n'] ?? 0) : 0) + 1;
    try {
        if ($ok) { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', [$cle]); }
        else { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['n' => $n, 'jusque' => $n >= FO_PIN_ESSAIS ? time() + FO_PIN_BLOCAGE_S : 0])]); }
    } catch (Throwable $x) { /* */ }
    if (!$ok) { return foRefus($n >= FO_PIN_ESSAIS ? 429 : 403, $n >= FO_PIN_ESSAIS ? 'trop d’essais : réessayer dans 10 minutes' : 'PIN incorrect'); }
    return ['ok' => true, 'jeton' => foJeton(['s' => '*', 'e' => 0, 'r' => 'responsable'])];
}

function foSopLigne(array $r): array
{
    return [
        'id' => (string) $r['id'], 'code' => $r['code'], 'type' => (string) $r['type'], 'dossier' => (string) $r['dossier'],
        'poste' => (string) $r['poste'], 'moment' => $r['moment'], 'acces' => foAcces($r),
        'partie' => foPartie($r), 'partieReglee' => in_array($r['partie'] ?? null, FO_PARTIES, true), 'statut' => (string) $r['statut'], 'version' => (string) $r['version'],
        'versionRevalider' => (string) $r['version_revalider'], 'langueOrigine' => (string) $r['langue_origine'],
        'trad' => ['fr' => (string) $r['trad_fr'], 'nl' => (string) $r['trad_nl']],
        'competence' => $r['competence_nom'] !== null ? ['id' => $r['competence_id'], 'nom' => (string) $r['competence_nom'], 'cle' => foCompCle($r)] : null,
        'titre' => ['fr' => (string) $r['titre_fr'], 'nl' => (string) $r['titre_nl']],
        'objectif' => ['fr' => (string) ($r['objectif_fr'] ?? ''), 'nl' => (string) ($r['objectif_nl'] ?? '')],
        'duree' => $r['duree'], 'nbEtapes' => (int) $r['nb_etapes'], 'nbPhotos' => (int) $r['nb_photos'],
        'majLe' => foIso($r['maj_le']), 'majPar' => $r['maj_par'],
        // Le classement du Manuel du réseau (src/referentiel.php) : sous-catégorie v25, phases, postes P1–P12, codes.
        'classement' => function_exists('rfClassementDe') ? rfClassementDe($r) : null,
    ];
}

/** La partie du Manuel opératoire : celle réglée, sinon déduite (ouverture → Ouverture, le reste → Opérationnel). */
function foPartie(array $r): string
{
    if (in_array($r['partie'] ?? null, FO_PARTIES, true)) { return (string) $r['partie']; }
    return ($r['moment'] ?? null) === 'ouverture' ? 'ouverture' : 'operationnel';
}

function foSopRow(string $id): ?array
{
    $id = foId($id);
    return $id === null ? null : Db::row('SELECT * FROM ceo_sop WHERE id = ?', [$id]);
}

function foTitreL(array $r, string $l): string
{
    return $l === 'nl' && (string) $r['titre_nl'] !== '' ? (string) $r['titre_nl'] : (string) $r['titre_fr'];
}

function foDossiers(): array
{
    $out = [];
    foreach (FO_DOSSIERS as $k => $v) { $out[] = ['id' => $k, 'nom' => $v]; }
    return $out;
}

/* ═══ Magasins, paramètres, petites aides ═════════════════════════════════════════════════════════ */

/** Les magasins du réseau (/stores), ouverts d'abord : [{id, nom}]. */
function foMagasins(): array
{
    $out = [];
    foreach (function_exists('ep_stores') ? ep_stores() : [] as $s) {
        if (!is_array($s) || !isset($s['id'])) { continue; }
        if (($s['status'] ?? '') === 'Fermé') { continue; }
        $out[] = ['id' => (string) $s['id'], 'nom' => function_exists('psCourt') ? psCourt((string) $s['nom']) : (string) $s['nom']];
    }
    return $out;
}

function foMagasinNom(string $shop): string
{
    foreach (foMagasins() as $m) { if ($m['id'] === $shop) { return $m['nom']; } }
    return 'magasin ' . $shop;
}

function foShopParam(): ?string { return foShopVal($_GET['shop'] ?? null); }

function foShopVal(mixed $v): ?string
{
    if (!is_scalar($v)) { return null; }
    $v = trim((string) $v);
    return preg_match('/^[\w-]{1,32}$/', $v) ? $v : null;
}

/**
 * Le magasin de la tablette : celui de la session de l'appareil (src/tablettes.php, PIN du magasin) — le magasin
 * envoyé par la page n'est plus cru. Sans src/tablettes.php (installation partielle) : celui envoyé, comme avant.
 */
function foShopTablette(mixed $envoye = null): ?string
{
    if (function_exists('tabShop')) { return tabShop(); }
    return foShopVal($envoye ?? ($_GET['shop'] ?? null));
}

/** Le jeton d'une personne, valable seulement sur une tablette de SON magasin. */
function foJetonTablette(mixed $j): ?array
{
    $d = foJetonLire($j);
    // Un jeton d'accès aux documents réservés n'identifie personne : il ne vaut pas jeton de personne.
    if ($d !== null && $d['r'] !== null) { return null; }
    if ($d === null || !function_exists('tabShop')) { return $d; }
    return $d['s'] === tabShop() ? $d : null;
}

function foLangueParam(): string { return ($_GET['langue'] ?? 'fr') === 'nl' ? 'nl' : 'fr'; }

/** Un identifiant de SOP : minuscules, chiffres, tirets, 32 au plus. */
function foId(mixed $v): ?string
{
    if (!is_string($v)) { return null; }
    $v = strtolower(trim($v));
    return preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $v) ? $v : null;
}

/** {fr, nl} depuis un tableau, une chaîne (→ fr) ou rien. */
function foBi(mixed $v): array
{
    if (is_string($v)) { return ['fr' => trim($v), 'nl' => '']; }
    if (!is_array($v)) { return ['fr' => '', 'nl' => '']; }
    return ['fr' => trim(is_scalar($v['fr'] ?? null) ? (string) $v['fr'] : ''), 'nl' => trim(is_scalar($v['nl'] ?? null) ? (string) $v['nl'] : '')];
}

function foCourt(mixed $v, int $max): ?string
{
    if (!is_scalar($v)) { return null; }
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/** Le type d'un document du manuel ; « Article » (07/11/2026) = un article du manuel opératoire (lu comme une SOP sur la tablette). */
function foType(mixed $v): string { return in_array($v, ['SOP', 'Checklist', 'Mémo', 'Fiche', 'Article'], true) ? (string) $v : 'SOP'; }
function foDossier(mixed $v): string { $v = str_pad(substr((string) $v, 0, 2), 2, '0', STR_PAD_LEFT); return isset(FO_DOSSIERS[$v]) ? $v : '01'; }
function foPoste(mixed $v): string { return in_array($v, FO_POSTES, true) ? (string) $v : 'vente'; }

/** « v5 », « 1.1 », « 2.0 » → « 5 », « 1.1 », « 2.0 ». */
function foVersion(string $v): string
{
    $v = ltrim(trim($v), 'vV');
    return preg_match('/^\d+(\.\d+){0,2}$/', $v) ? $v : '1.0';
}

/** La version suivante : 1.1 → 1.2, 5 → 5.1, 2.0.3 → 2.0.4. */
function foVersionSuivante(string $v): string
{
    $p = explode('.', foVersion($v));
    if (count($p) === 1) { return $p[0] . '.1'; }
    $p[count($p) - 1] = (string) ((int) end($p) + 1);
    return implode('.', $p);
}

function foPar(array $b): string
{
    $p = foCourt($b['par'] ?? null, 120);
    if ($p !== null) { return $p; }
    $u = setting('utilisateur', []);
    return is_array($u) && trim((string) ($u['nom'] ?? '')) !== '' ? trim((string) $u['nom']) : 'Cockpit';
}

function foJson(array $c): string { return (string) json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

function foIso(mixed $dt): ?string
{
    if ($dt === null || $dt === '') { return null; }
    try { return (new DateTimeImmutable((string) $dt))->format('c'); } catch (Exception $e) { return null; }
}

function foRefus(int $code, string $msg): array
{
    http_response_code($code);
    return ['error' => $msg];
}

/** Un nom de fichier : minuscules, chiffres, tirets. */
function sopSlug(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '’' => '', "'" => '']);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-') ?: 'sop';
}
