<?php
/**
 * La nomenclature des checklists (08/11/2026, « option B : un code chiffré, le même partout ») — la source unique.
 *
 * Un code « zone.moment.numéro » (1.1.01) : le même au panel (le nom d'une checklist est « 1.1.01 · Ouverture de la
 * boutique »), dans Formation (la fiche de la checklist a pour code 1.1.01) et dans les SOP (leur classement cite
 * 1.1.01). Le code ne se traduit pas ; le nom, si (français, néerlandais, anglais ici ; l'app des employés demande
 * au panel la langue de chacun). Zones : 1 vente, 2 boulangerie, 3 traiteur, 4 nettoyage, 5 gestion (0 libre).
 * Moments : 1 ouverture, 2 journée, 3 après-midi, 4 fermeture, 5 chaque semaine, 6 chaque mois.
 *
 * Routes (src/task_cadre.php les branche) :
 *   GET  /task/nomenclature            — le plan et, pour chaque point, l'état actuel (fait, à faire, bloqué)
 *   POST /task/nomenclature/appliquer  — {apercu?} : renomme les checklists du panel, recode les fiches et les
 *                                        liens de Formation ; idempotent (ce qui est déjà fait ne repart pas)
 * Les changements de structure — fusionner CO-01 dans 1.1.01, partager CQ-02, supprimer la checklist d'essai —
 * demandent de retirer une tâche d'une checklist au panel : ils ne sont pas ici.
 */

const CKC_ZONES = [1 => ['Vente', 'Verkoop', 'Shop'], 2 => ['Boulangerie', 'Bakkerij', 'Bakery'], 3 => ['Traiteur', 'Traiteur', 'Deli'],
    4 => ['Nettoyage', 'Schoonmaak', 'Cleaning'], 5 => ['Gestion', 'Beheer', 'Management']];
const CKC_MOMENTS = [1 => ['Ouverture', 'Opening', 'Opening'], 2 => ['Journée', 'Dag', 'Day'], 3 => ['Après-midi', 'Namiddag', 'Afternoon'],
    4 => ['Fermeture', 'Sluiting', 'Closing'], 5 => ['Chaque semaine', 'Wekelijks', 'Weekly'], 6 => ['Chaque mois', 'Maandelijks', 'Monthly']];
const CKC_FORMAT = '/^[0-9]\.[1-9]\.\d{2}$/';

/**
 * Les checklists : code, noms (fr, nl, en), anciens codes, la checklist du panel (id, nom d'avant, heure, zone de
 * production), la fiche de Formation qui la décrit. « heure » null : on n'y touche pas ; « poste » null : non plus.
 */
const CKC_LISTE = [
    ['code' => '1.1.01', 'fr' => 'Ouverture de la boutique', 'nl' => 'Opening van de winkel', 'en' => 'Shop opening', 'ex' => ['AC-01', 'CO-01'], 'panel' => 51, 'avant' => 'AC-01 · Ouverture boutique', 'heure' => '06:30', 'poste' => null, 'fiche' => 'chk-ac-01'],
    ['code' => '1.1.02', 'fr' => 'Photos des comptoirs', 'nl' => 'Foto’s van de toonbanken', 'en' => 'Counter photos', 'ex' => ['CQ-02'], 'panel' => 43, 'avant' => 'CQ-02 — Contrôle qualité d\'ouverture.', 'heure' => '07:00', 'poste' => null, 'fiche' => null],
    ['code' => '1.2.01', 'fr' => 'Facing et FIFO en service', 'nl' => 'Facing en FIFO tijdens de dienst', 'en' => 'Facing and FIFO during service', 'ex' => ['AC-19'], 'panel' => 58, 'avant' => 'AC-19 · Facing et FIFO en service', 'heure' => '12:00', 'poste' => null, 'fiche' => 'chk-ac-19'],
    ['code' => '1.2.02', 'fr' => 'Moments calmes', 'nl' => 'Rustige momenten', 'en' => 'Quiet moments', 'ex' => ['CHK-WKL-005'], 'panel' => null, 'avant' => null, 'heure' => null, 'poste' => null, 'fiche' => 'calmes'],
    ['code' => '1.3.01', 'fr' => 'Après-midi : recuisson et réassort', 'nl' => 'Namiddag: bijbakken en aanvullen', 'en' => 'Afternoon: re-baking and restocking', 'ex' => ['SOP-VEN'], 'panel' => 61, 'avant' => 'SOP-VEN · Après-midi vente', 'heure' => '14:00', 'poste' => null, 'fiche' => null],
    // Séparée de 1.1.02 le 09/11/2026 : les photos des comptoirs de l'après-midi (18 tâches, samedi et dimanche).
    ['code' => '1.3.02', 'fr' => 'Photos des comptoirs (après-midi)', 'nl' => 'Foto’s van de toonbanken (namiddag)', 'en' => 'Counter photos (afternoon)', 'ex' => [], 'panel' => 63, 'avant' => null, 'heure' => '14:30', 'poste' => null, 'fiche' => null],
    ['code' => '1.4.01', 'fr' => 'Fermeture de la boutique', 'nl' => 'Sluiting van de winkel', 'en' => 'Shop closing', 'ex' => ['CO-02', 'AC-02', 'CHK-WKL-004'], 'panel' => 46, 'avant' => 'CO-02 — Fermeture', 'heure' => '18:00', 'poste' => null, 'fiche' => 'ferm-quot'],
    ['code' => '1.5.01', 'fr' => 'Planogramme et merchandising (lundi)', 'nl' => 'Planogram en merchandising (maandag)', 'en' => 'Planogram and merchandising (Monday)', 'ex' => ['AC-06'], 'panel' => 55, 'avant' => 'AC-06 · Planogramme et merchandising', 'heure' => '10:00', 'poste' => null, 'fiche' => 'chk-ac-06'],
    ['code' => '1.5.02', 'fr' => 'Audit visuel de la boutique (mardi)', 'nl' => 'Visuele audit van de winkel (dinsdag)', 'en' => 'Shop visual audit (Tuesday)', 'ex' => ['CP-07'], 'panel' => 47, 'avant' => 'CP-07 — Audit visuel intérieur', 'heure' => '10:00', 'poste' => null, 'fiche' => null],
    ['code' => '2.1.01', 'fr' => 'Ouverture du poste cuisson', 'nl' => 'Opening van de bakpost', 'en' => 'Baking station opening', 'ex' => ['SOP-CUI'], 'panel' => 59, 'avant' => 'SOP-CUI · Ouverture du poste cuisson', 'heure' => '04:00', 'poste' => null, 'fiche' => null],
    ['code' => '2.2.01', 'fr' => 'Chambre froide et étiquetage', 'nl' => 'Diepvriescel en etikettering', 'en' => 'Freezer room and labelling', 'ex' => ['CO-10'], 'panel' => 49, 'avant' => 'CO-10 - Contrôle opérationnel Boulangerie', 'heure' => '06:00', 'poste' => null, 'fiche' => null],
    ['code' => '2.2.02', 'fr' => 'Contrôle qualité pâtisserie (un produit par jour)', 'nl' => 'Kwaliteitscontrole patisserie (één product per dag)', 'en' => 'Pastry quality check (one product a day)', 'ex' => ['CQ-01'], 'panel' => 45, 'avant' => 'CQ-01 — Contrôle qualité produit spécifique.', 'heure' => '09:00', 'poste' => 'Production Boulangerie', 'fiche' => null],
    ['code' => '2.4.01', 'fr' => 'Fin de poste cuisson', 'nl' => 'Einde van de bakpost', 'en' => 'Baking station end of shift', 'ex' => ['SOP-CUI'], 'panel' => 60, 'avant' => 'SOP-CUI · Fin de poste cuisson', 'heure' => null, 'poste' => null, 'fiche' => null],
    ['code' => '3.2.01', 'fr' => 'Contrôle qualité des plats traiteur (un plat par jour)', 'nl' => 'Kwaliteitscontrole traiteurgerechten (één gerecht per dag)', 'en' => 'Deli dish quality check (one dish a day)', 'ex' => ['CQ-F1'], 'panel' => 48, 'avant' => 'CQ-F1 — Contrôle qualité : Formation Cuisine 1', 'heure' => '09:00', 'poste' => null, 'fiche' => null],
    ['code' => '3.4.01', 'fr' => 'Fermeture du traiteur', 'nl' => 'Sluiting van de traiteur', 'en' => 'Deli closing', 'ex' => ['AC-03'], 'panel' => 52, 'avant' => 'AC-03 · Fermeture du service traiteur', 'heure' => '18:00', 'poste' => null, 'fiche' => 'chk-ac-03'],
    ['code' => '4.2.01', 'fr' => 'Hygiène quotidienne', 'nl' => 'Dagelijkse hygiëne', 'en' => 'Daily hygiene', 'ex' => ['AC-04'], 'panel' => 53, 'avant' => 'AC-04 · Hygiène quotidienne', 'heure' => '14:00', 'poste' => null, 'fiche' => 'chk-ac-04'],
    ['code' => '4.5.01', 'fr' => 'Nettoyage approfondi (mercredi)', 'nl' => 'Grondige schoonmaak (woensdag)', 'en' => 'Deep cleaning (Wednesday)', 'ex' => ['AC-05'], 'panel' => 54, 'avant' => 'AC-05 · Hygiène hebdomadaire approfondie', 'heure' => '15:00', 'poste' => null, 'fiche' => 'chk-ac-05'],
    ['code' => '5.2.01', 'fr' => 'Routine du dashboard magasin', 'nl' => 'Dashboardroutine van de winkel', 'en' => 'Shop dashboard routine', 'ex' => ['SOP-PIL-01'], 'panel' => 62, 'avant' => 'SOP-PIL-01 · Routine du dashboard magasin', 'heure' => '07:30', 'poste' => null, 'fiche' => null],
    ['code' => '5.5.01', 'fr' => 'Réunion d’équipe (lundi)', 'nl' => 'Teamvergadering (maandag)', 'en' => 'Team meeting (Monday)', 'ex' => ['AC-13'], 'panel' => 56, 'avant' => 'AC-13 · Réunion d’équipe', 'heure' => '09:00', 'poste' => null, 'fiche' => 'chk-ac-13'],
    ['code' => '5.6.01', 'fr' => 'Auto-audit HACCP', 'nl' => 'HACCP-zelfaudit', 'en' => 'HACCP self-audit', 'ex' => ['AC-14'], 'panel' => 57, 'avant' => 'AC-14 · Auto-audit HACCP mensuel', 'heure' => '09:00', 'poste' => null, 'fiche' => 'chk-ac-14'],
];

/** Les anciens codes du référentiel v25 et du panel qui ont un nouveau code (les autres codes du v25 restent). */
const CKC_ANCIENS = ['AC-01' => '1.1.01', 'AC-02' => '1.4.01', 'AC-03' => '3.4.01', 'AC-04' => '4.2.01', 'AC-05' => '4.5.01', 'AC-06' => '1.5.01',
    'AC-13' => '5.5.01', 'AC-14' => '5.6.01', 'AC-19' => '1.2.01', 'CP-07' => '1.5.02'];

/** Les liens des SOP vers leurs checklists, quand le simple remplacement des anciens codes ne suffit pas. */
const CKC_LIENS = [
    'ouv-mag' => ['1.1.01', '1.1.02'],      // l'ouverture du magasin et des comptoirs : la checklist et les photos
    'ouv-poste' => ['2.1.01'],              // pointait vers l'ouverture de la boutique (AC-01)
    'fin-poste' => ['2.4.01'],              // pointait vers la fermeture de la boutique (AC-02)
    'fermeture' => ['1.4.01', '3.4.01'],
    'recuisson' => ['1.3.01'],              // aucun lien jusqu'ici
    'sop-pil-01' => ['5.2.01'],
    'cremes' => ['2.2.02'], 't-fruits' => ['2.2.02'], 'flans' => ['2.2.02'], 't-froides' => ['2.2.02'], 'eclairs' => ['2.2.02'],
];

/** Le code de checklist en tête d'un nom (« 1.1.01 · … » ou, avant la nomenclature, « AC-01 · … »), sinon null. */
function ckcCodeDe(string $nom): ?string
{
    if (preg_match('/^\s*([0-9]\.[1-9]\.\d{2})(?![\d.])/u', $nom, $m) === 1) { return $m[1]; }
    return preg_match('/^\s*([A-Z]{2,5}(?:-[A-Z0-9]{1,6}){1,3})(?![\w-])/u', $nom, $m) === 1 ? $m[1] : null;
}

/** Un code saisi ou lu (ancien ou nouveau) dans la nomenclature : AC-01 → 1.1.01 ; un autre code reste tel quel. */
function ckcTraduire(string $code): string
{
    $c = strtoupper(trim($code));
    return CKC_ANCIENS[$c] ?? trim($code);
}

/** Les codes de la nomenclature (pour les listes de Formation). */
function ckcCodes(): array { return array_column(CKC_LISTE, 'code'); }

/** La nomenclature, telle que l'écran la montre : zones, moments, checklists (sans les champs internes). */
function ckcPublique(): array
{
    $l = [];
    foreach (CKC_LISTE as $x) { $l[] = ['code' => $x['code'], 'fr' => $x['fr'], 'nl' => $x['nl'], 'en' => $x['en'], 'ex' => $x['ex'], 'panel' => $x['panel']]; }
    $z = []; foreach (CKC_ZONES as $k => $v) { $z[] = ['code' => $k, 'fr' => $v[0], 'nl' => $v[1], 'en' => $v[2]]; }
    $m = []; foreach (CKC_MOMENTS as $k => $v) { $m[] = ['code' => $k, 'fr' => $v[0], 'nl' => $v[1], 'en' => $v[2]]; }
    return ['zones' => $z, 'moments' => $m, 'checklists' => $l];
}

/* ═══ Le plan et son application ════════════════════════════════════════════════════════════════════════ */

/**
 * Le plan, point par point, avec l'état actuel lu au panel et dans la base : chaque point dit « fait », « à faire »
 * (et ce qui changera), ou « bloqué » (et pourquoi : le nom au panel n'est ni l'ancien ni le nouveau, une fiche
 * manque…). La base de Formation est celle du cockpit ; le panel est lu une fois.
 */
function ckcPlan(): array
{
    ensureSop();
    ensureReferentiel();
    [$cls, $e] = tkLireListe('/operational-framework/checklists');
    $panel = [];
    foreach ((array) $cls as $c) { $panel[(int) ($c['id'] ?? 0)] = $c; }
    $postes = [];
    [$ws] = tkLireListe('/operational-framework/workstations');
    foreach ((array) $ws as $w) { $postes[tkTxt($w['name'] ?? '')] = (int) ($w['id'] ?? 0); }
    $points = [];

    // 1. Le panel : le nom, l'heure, la zone, l'ancien code dans la description
    foreach (CKC_LISTE as $x) {
        if ($x['panel'] === null) { continue; }
        $nouveau = $x['code'] . ' · ' . $x['fr'];
        $c = $panel[$x['panel']] ?? null;
        $p = ['ou' => 'panel', 'code' => $x['code'], 'id' => $x['panel'], 'quoi' => 'Checklist « ' . $nouveau . ' »'];
        if ($c === null) { $points[] = $p + ['etat' => 'bloque', 'pourquoi' => $e ? 'panel illisible : ' . $e : 'checklist #' . $x['panel'] . ' absente du panel']; continue; }
        $nom = tkTxt($c['name'] ?? '');
        if ($nom !== $nouveau && $nom !== $x['avant']) { $points[] = $p + ['etat' => 'bloque', 'pourquoi' => 'au panel, elle s’appelle « ' . $nom . ' » (ni l’ancien nom, ni le nouveau) : à vérifier à la main']; continue; }
        $b = [];
        if ($nom !== $nouveau) { $b['nom'] = $nouveau; }
        $heure = substr((string) ($c['execution_time'] ?? ''), 0, 5);
        if ($x['heure'] !== null && $heure !== $x['heure']) { $b['heure'] = $x['heure']; }
        if ($x['poste'] !== null) {
            $pid = $postes[$x['poste']] ?? 0;
            if ($pid <= 0) { $points[] = $p + ['etat' => 'bloque', 'pourquoi' => 'zone « ' . $x['poste'] . ' » introuvable au panel']; continue; }
            if ((int) ($c['workstation_id'] ?? 0) !== $pid) { $b['posteId'] = $pid; }
        }
        $desc = tkTxt($c['description'] ?? '');
        $ex = '(ex ' . implode(', ', array_values(array_filter($x['ex'], fn ($k) => !str_starts_with($k, 'CHK-')))) . ')';
        if (!str_contains($desc, '(ex ')) { $b['description'] = trim(($desc === 'Array' ? '' : $desc) . ' ' . $ex); }
        $avant = ['nom' => $nom, 'heure' => $heure, 'posteId' => (int) ($c['workstation_id'] ?? 0), 'description' => $desc];
        $points[] = $p + ($b ? ['etat' => 'afaire', 'changements' => $b, 'avant' => $avant] : ['etat' => 'fait']);
    }

    // 2. Formation : le code et le titre des fiches « Checklist »
    foreach (CKC_LISTE as $x) {
        if ($x['fiche'] === null) { continue; }
        $r = foSopRow($x['fiche']);
        $p = ['ou' => 'fiche', 'code' => $x['code'], 'id' => $x['fiche'], 'quoi' => 'Fiche ' . $x['code'] . ' · ' . $x['fr']];
        if ($r === null) { $points[] = $p + ['etat' => 'bloque', 'pourquoi' => 'fiche « ' . $x['fiche'] . ' » absente de Formation']; continue; }
        $b = [];
        if ((string) $r['code'] !== $x['code']) { $b['code'] = $x['code']; }
        if ((string) $r['titre_fr'] !== $x['fr']) { $b['titre_fr'] = $x['fr']; }
        if ((string) $r['titre_nl'] !== $x['nl']) { $b['titre_nl'] = $x['nl']; }
        $avant = ['code' => (string) $r['code'], 'titre_fr' => (string) $r['titre_fr'], 'titre_nl' => (string) $r['titre_nl']];
        $points[] = $p + ($b ? ['etat' => 'afaire', 'changements' => $b, 'avant' => $avant] : ['etat' => 'fait']);
    }

    // 3. Formation : les liens des SOP (et des fiches) vers leurs checklists
    $fiches = [];
    foreach (CKC_LISTE as $x) { if ($x['fiche'] !== null) { $fiches[$x['fiche']] = [$x['code']]; } }
    foreach (Db::rows('SELECT id, code, titre_fr, checklists FROM ceo_sop ORDER BY id') as $r) {
        $actuel = rfJsonListe($r['checklists']);
        $voulu = CKC_LIENS[$r['id']] ?? $fiches[$r['id']] ?? null;
        if ($voulu === null) {
            $voulu = [];
            foreach ($actuel as $k) { $n = ckcTraduire($k); if (!in_array($n, $voulu, true)) { $voulu[] = $n; } }
        }
        if ($voulu === $actuel) { continue; }
        $points[] = ['ou' => 'lien', 'code' => implode(', ', $voulu), 'id' => $r['id'], 'quoi' => 'Liens de ' . ($r['code'] ?: $r['id']) . ' · ' . $r['titre_fr'],
            'etat' => 'afaire', 'changements' => ['checklists' => $voulu], 'avant' => ['checklists' => $actuel]];
    }
    // 4. Le référentiel : les checklists de chaque sous-catégorie
    foreach (Db::rows('SELECT cle, fr, checklists FROM ceo_ref_souscat ORDER BY cle') as $s) {
        $actuel = rfJsonListe($s['checklists']);
        $voulu = [];
        foreach ($actuel as $k) { $n = ckcTraduire($k); if (!in_array($n, $voulu, true)) { $voulu[] = $n; } }
        if ($voulu === $actuel) { continue; }
        $points[] = ['ou' => 'souscat', 'code' => implode(', ', $voulu), 'id' => $s['cle'], 'quoi' => 'Sous-catégorie ' . $s['cle'] . ' · ' . $s['fr'],
            'etat' => 'afaire', 'changements' => ['checklists' => $voulu], 'avant' => ['checklists' => $actuel]];
    }
    $n = ['fait' => 0, 'afaire' => 0, 'bloque' => 0];
    foreach ($points as $p) { $n[$p['etat']]++; }
    return ['nomenclature' => ckcPublique(), 'points' => $points, 'totaux' => $n,
        'structure' => [
            ['quoi' => 'Fusionner « CO-01 — Ouverture » dans 1.1.01 (sa tâche « Vérifier si le magasin est propre » y passe)', 'id' => 44],
            ['quoi' => 'Partager 1.1.02 : les 18 photos de 14:30 (samedi, dimanche) dans une nouvelle 1.3.02 · Photos des comptoirs l’après-midi (week-end) ; 1.1.02 devient « Photos des comptoirs à l’ouverture »', 'id' => 43],
            ['quoi' => 'Supprimer « Checklist Test Consultant »', 'id' => 50],
        ]];
}

/**
 * POST /task/nomenclature/appliquer {apercu?} — exécute les points « à faire » du plan, dans l'ordre : panel,
 * fiches, liens, sous-catégories. Un refus du panel n'arrête pas les autres points ; tout est journalisé.
 * Une fiche qui a une version publiée en reçoit une nouvelle (« précision », sans relecture demandée) avec le
 * nouveau titre : sinon la prochaine publication remettrait l'ancien.
 */
function ckcAppliquer(array $b): array
{
    $plan = ckcPlan();
    if (!empty($b['apercu'])) { return $plan + ['apercu' => true]; }
    $par = 'CEO';
    $res = [];
    foreach ($plan['points'] as $p) {
        if ($p['etat'] !== 'afaire') { $res[] = $p; continue; }
        $ch = $p['changements'];
        try {
            if ($p['ou'] === 'panel') {
                $r = tkChecklistModifier((int) $p['id'], $ch);
                $ok = !empty($r['ok']);
                $res[] = $p + ['ok' => $ok, 'erreur' => $ok ? null : ($r['error'] ?? 'refusé par le panel')];
                http_response_code(200);
                continue;
            }
            if ($p['ou'] === 'fiche') {
                $r = foSopRow((string) $p['id']);
                $fr = $ch['titre_fr'] ?? (string) $r['titre_fr'];
                $nl = $ch['titre_nl'] ?? (string) $r['titre_nl'];
                $v = Db::row('SELECT version, contenu FROM ceo_sop_version WHERE sop_id = ? ORDER BY publie_le DESC, version DESC LIMIT 1', [$r['id']]);
                $c = $v ? json_decode((string) $v['contenu'], true) : null;
                $now = date('Y-m-d H:i:s');
                if (is_array($c) && (isset($ch['titre_fr']) || isset($ch['titre_nl']))) {
                    $c['titre'] = ['fr' => $fr, 'nl' => $nl];
                    $ver = foVersionSuivante((string) $r['version']);
                    Db::exec('INSERT INTO ceo_sop_version (sop_id, version, contenu, note, revalider, publie_le, publie_par) VALUES (?, ?, ?, ?, 0, ?, ?)',
                        [$r['id'], $ver, foJson($c), 'Nouvelle nomenclature des checklists : code ' . ($ch['code'] ?? $r['code']) . ', titre « ' . $fr . ' »', $now, $par]);
                    Db::exec('UPDATE ceo_sop SET version = ? WHERE id = ?', [$ver, $r['id']]);
                }
                Db::exec('UPDATE ceo_sop SET code = ?, titre_fr = ?, titre_nl = ?, maj_le = ?, maj_par = ? WHERE id = ?',
                    [$ch['code'] ?? $r['code'], mb_substr($fr, 0, 190), mb_substr($nl, 0, 190), $now, $par, $r['id']]);
                journalAdd($par, 'Formation', $fr, 'Nouvelle nomenclature : la fiche « ' . $p['avant']['code'] . ' » devient ' . ($ch['code'] ?? $r['code']));
                $res[] = $p + ['ok' => true];
                continue;
            }
            if ($p['ou'] === 'lien') {
                Db::exec('UPDATE ceo_sop SET checklists = ?, maj_le = ?, maj_par = ? WHERE id = ?', [rfJsonEcrire($ch['checklists']), date('Y-m-d H:i:s'), $par, $p['id']]);
                $res[] = $p + ['ok' => true];
                continue;
            }
            if ($p['ou'] === 'souscat') {
                Db::exec('UPDATE ceo_ref_souscat SET checklists = ?, maj_le = ?, maj_par = ? WHERE cle = ?', [rfJsonEcrire($ch['checklists']), date('Y-m-d H:i:s'), $par, $p['id']]);
                $res[] = $p + ['ok' => true];
                continue;
            }
        } catch (Throwable $e) {
            $res[] = $p + ['ok' => false, 'erreur' => $e->getMessage()];
        }
    }
    $faits = count(array_filter($res, fn ($x) => ($x['ok'] ?? null) === true));
    $refus = count(array_filter($res, fn ($x) => ($x['ok'] ?? null) === false));
    journalAdd($par, 'Cadre des tâches', null, 'Nouvelle nomenclature des checklists appliquée : ' . $faits . ' changement(s)' . ($refus ? ', ' . $refus . ' refusé(s)' : ''));
    http_response_code($refus ? 502 : 200);
    return ['ok' => $refus === 0, 'faits' => $faits, 'refus' => $refus, 'points' => $res, 'apres' => ckcPlan()['totaux'],
        'error' => $refus ? $refus . ' changement(s) refusé(s) : voir les points en rouge' : null];
}
