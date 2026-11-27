<?php
declare(strict_types=1);

/**
 * MON ÉQUIPE › ABSENCES, CONGÉS ET REMPLACEMENTS (27/11/2026 ; maquette panel/docs/maquettes/equipe-gestion/
 * absences-remplacements/). Sam : « lorsqu'une personne est engagée sous le régime d'un contrat de travail, lorsqu'elle
 * est absente ou en maladie, les frais doivent rester et on doit pouvoir, aux mêmes heures, rajouter une tierce personne
 * avec les frais supplémentaires que ça apporte. Et congé payé. »
 *
 * Le panel TF Buddy ne connaît pas les absences (swagger du 27/11/2026 : POST /schedule ne garde que l'employé, le jour et
 * les heures ; aucune route d'absence ni de congé). Elles vivent ici, une ligne par service absent (panel_equipe_absence) :
 * - ABSENCE PAYÉE (maladie, congé payé, congé de circonstance, pour une personne sous contrat de travail) : le service
 *   RESTE au panel, si bien que son coût reste partout (le P&L du panel, le Résultat, Mon équipe) ; Mon équipe la montre
 *   absente et ne la compte plus comme présence (le comptoir, les linéaires, « À corriger »).
 * - ABSENCE NON PAYÉE (sans solde, injustifiée ; ou toute absence d'une personne sans contrat de travail : intérim, B2B,
 *   contrat civil, stage, bénévole) : le service est retiré du panel (le coût sort partout) ; la ligne garde ses heures
 *   pour la montrer. Annulée (« Elle travaille »), le service revient au panel.
 * - LE REMPLACEMENT, aux mêmes heures : quelqu'un de l'équipe (un vrai service au panel : son coût s'ajoute partout), ou
 *   quelqu'un d'extérieur (intérim, extra : connu du seul cockpit, son nom et son taux ; son coût s'ajoute dans Mon équipe,
 *   la facture de l'agence passe par les frais).
 *
 *   POST   /dashboard/equipe/absence {shop, employe, du, au, motif, certificat, remplacements: [{date, employe} |
 *          {date, externe: {nom, taux}}]} — marque absents ses services du `du` au `au` au planning du magasin et pose les
 *          remplacements. Une absence déjà posée sur un service est mise à jour (motif, certificat, période).
 *   DELETE /dashboard/equipe/absence {shop, ids, remplacants} — annule : la ligne s'efface, un service retiré revient au
 *          panel ; avec `remplacants`, les services des remplaçants sont retirés du panel.
 * Le planning (GET /dashboard/equipe/planning) rend, jour par jour, ses absences (eqaJours) et le contrat de chacun.
 */

const EQA_MOTIFS = [
    'maladie' => ['nom' => 'Maladie', 'court' => 'malade', 'paye' => true],
    'cp' => ['nom' => 'Congé payé', 'court' => 'en congé payé', 'paye' => true],
    'circ' => ['nom' => 'Congé de circonstance', 'court' => 'en congé de circonstance', 'paye' => true],
    'nonpaye' => ['nom' => 'Absence non payée', 'court' => 'absente (non payée)', 'paye' => false],
];
/** Les contrats qui ne paient que les heures prestées : une absence n'y coûte rien (le `employment_type` du panel). */
const EQA_SANS_CONTRAT = ['civil_law_contract', 'b2b', 'temporary_work', 'internship', 'volunteer'];
const EQA_JOURS_MAX = 62;      // une absence couvre deux mois au plus d'un coup
const EQA_TAUX_MAX = 200.0;    // le taux horaire d'un extérieur, au plus

function ensureEquipeAbsences(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS panel_equipe_absence ('
        . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . 'shop_id VARCHAR(16) NOT NULL,'
        . 'employe_id INT UNSIGNED NOT NULL,'        // l'employé absent (identifiant du panel)
        . 'jour DATE NOT NULL,'
        . 'service_id INT UNSIGNED NOT NULL,'        // son service au panel (retiré du panel si l'absence n'est pas payée)
        . 'de CHAR(5) NOT NULL,'
        . 'a CHAR(5) NOT NULL,'
        . 'motif VARCHAR(16) NOT NULL,'              // maladie, cp, circ, nonpaye
        . 'payee TINYINT(1) NOT NULL,'
        . 'certificat TINYINT(1) NOT NULL DEFAULT 0,'
        . 'periode_du DATE NOT NULL,'
        . 'periode_au DATE NOT NULL,'
        . 'remplacant_id INT UNSIGNED NULL,'         // le remplaçant de l'équipe…
        . 'remplacant_service INT UNSIGNED NULL,'    // … et son service au panel
        . 'externe_nom VARCHAR(80) NULL,'            // ou quelqu'un d'extérieur, connu du seul cockpit
        . 'externe_taux DECIMAL(7,2) NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL,'
        . 'UNIQUE KEY u_service (shop_id, service_id),'
        . 'KEY k_jour (shop_id, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Ses routes, appelées par eqpRoute ; null : pas une route des absences. */
function eqaRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/equipe/absence') { return null; }
    if ($method === 'POST') { return wr_equipe_absence(); }
    if ($method === 'DELETE') { return wr_equipe_absence_annuler(); }
    return null;
}

/** Une absence est payée (son coût reste) : un motif payé, pour une personne sous contrat de travail (ou à préciser). */
function eqaPayee(string $motif, ?string $contrat): bool
{
    return !empty(EQA_MOTIFS[$motif]['paye']) && !in_array((string) $contrat, EQA_SANS_CONTRAT, true);
}

/** Une ligne de la table, pour l'écran. */
function eqaLigne(array $r): array
{
    return ['id' => (int) $r['id'], 'employe' => (int) $r['employe_id'], 'date' => (string) $r['jour'], 'service' => (int) $r['service_id'],
        'de' => (string) $r['de'], 'a' => (string) $r['a'], 'motif' => (string) $r['motif'], 'payee' => (bool) (int) $r['payee'],
        'certificat' => (bool) (int) $r['certificat'], 'du' => (string) $r['periode_du'], 'au' => (string) $r['periode_au'],
        'remplacant' => $r['remplacant_id'] !== null ? (int) $r['remplacant_id'] : null,
        'remplacantService' => $r['remplacant_service'] !== null ? (int) $r['remplacant_service'] : null,
        'externe' => $r['externe_nom'] !== null && $r['externe_nom'] !== '' ? ['nom' => (string) $r['externe_nom'], 'taux' => (float) $r['externe_taux']] : null];
}

/** Les absences des jours demandés : date → [absence…] ; sans table (ou sans base), aucune. */
function eqaJours(int $shop, array $jours): array
{
    $out = array_fill_keys($jours, []);
    if ($jours === []) { return $out; }
    try {
        ensureEquipeAbsences();
        foreach (Db::rows('SELECT * FROM panel_equipe_absence WHERE shop_id = ? AND jour >= ? AND jour <= ? ORDER BY jour, de', [(string) $shop, min($jours), max($jours)]) as $r) {
            $l = eqaLigne($r);
            if (isset($out[$l['date']])) { $out[$l['date']][] = $l; }
        }
    } catch (Throwable $e) { /* sans table : aucune absence */ }
    return $out;
}

/**
 * LE PLAN d'une absence, pur : ce qu'il faut écrire, ou les erreurs (rien n'est écrit s'il y en a).
 *   $parJour   : date → services du magasin ce jour-là ([{id, employe, de, a}], eqpServices)
 *   $existants : service_id → ligne de la table (eqaLigne) des services de la personne sur la période, et ses absences
 *                non payées (leur service n'est plus au panel)
 *   $remps     : date → ['employe' => id] | ['externe' => ['nom' => …, 'taux' => …]]
 *   $equipe    : id → personne (eqpPersonne)
 * Rend ['lignes' => [{date, service, de, a, id?}], 'retirer' => [id…], 'creer' => [{date, employe, de, a, pour}],
 *       'externes' => [{date, service, nom, taux}], 'erreurs' => [texte…]].
 */
function eqaPlan(array $parJour, int $emp, bool $payee, array $existants, array $remps, array $equipe): array
{
    $P = ['lignes' => [], 'retirer' => [], 'creer' => [], 'externes' => [], 'erreurs' => []];
    $absJour = [];   // date → services absents de la personne ce jour-là
    foreach ($parJour as $date => $services) {
        foreach ($services as $s) {
            if ($s['employe'] !== $emp || $s['id'] < 1) { continue; }
            $ex = $existants[$s['id']] ?? null;
            $P['lignes'][] = ['date' => $date, 'service' => $s['id'], 'de' => $s['de'], 'a' => $s['a'], 'id' => $ex['id'] ?? null];
            if (!$payee) { $P['retirer'][] = $s['id']; }
            $absJour[$date][] = $s + ['ligne' => $ex];
        }
    }
    // Une absence non payée déjà posée (son service n'est plus au panel) : elle reste ce qu'elle est.
    foreach ($existants as $ex) {
        if ($ex['payee'] || !isset($parJour[$ex['date']])) { continue; }
        if ($payee) { $P['erreurs'][] = 'une absence non payée est déjà posée le ' . $ex['date'] . ' : annulez-la d’abord (« Elle travaille »)'; continue; }
        $absJour[$ex['date']][] = ['id' => $ex['service'], 'employe' => $emp, 'de' => $ex['de'], 'a' => $ex['a'], 'ligne' => $ex];
    }
    if ($P['lignes'] === [] && $absJour === []) { $P['erreurs'][] = 'aucun service de cette personne au planning sur ces jours : rien à marquer'; }
    foreach ($remps as $date => $r) {
        $L = $absJour[$date] ?? [];
        if ($L === []) { $P['erreurs'][] = 'le ' . $date . ' : pas de service absent à remplacer'; continue; }
        foreach ($L as $s) {
            $ex = $s['ligne'] ?? null;
            $dejaEquipe = $ex && $ex['remplacantService'] && array_filter($parJour[$date] ?? [], static fn ($x) => $x['id'] === $ex['remplacantService']);
            if ($dejaEquipe || ($ex && $ex['externe'])) { $P['erreurs'][] = 'le ' . $date . ' : ce service est déjà remplacé'; continue; }
            if (isset($r['externe'])) {
                $P['externes'][] = ['date' => $date, 'service' => $s['id'], 'nom' => $r['externe']['nom'], 'taux' => $r['externe']['taux']];
                continue;
            }
            $qui = (int) ($r['employe'] ?? 0); $p = $equipe[$qui] ?? null;
            if ($p === null) { $P['erreurs'][] = 'le ' . $date . ' : le remplaçant n’est pas de l’équipe du magasin'; continue; }
            if (!$p['actif']) { $P['erreurs'][] = 'le ' . $date . ' : ' . $p['nom'] . ' est désactivé(e)'; continue; }
            if ($qui === $emp) { $P['erreurs'][] = 'le ' . $date . ' : elle ne peut pas se remplacer elle-même'; continue; }
            $de = (int) eqpMinutes($s['de']); $a = (int) eqpMinutes($s['a']);
            $prevus = array_merge($parJour[$date] ?? [], array_map(static fn ($c) => ['id' => -1, 'employe' => $c['employe'], 'de' => $c['de'], 'a' => $c['a']], array_filter($P['creer'], static fn ($c) => $c['date'] === $date)));
            if (($x = eqpChevauche($prevus, $qui, $de, $a)) !== null) { $P['erreurs'][] = 'le ' . $date . ' : ' . $p['nom'] . ' travaille déjà de ' . $x['de'] . ' à ' . $x['a']; continue; }
            $P['creer'][] = ['date' => $date, 'employe' => $qui, 'de' => $s['de'], 'a' => $s['a'], 'pour' => $s['id']];
        }
    }
    return $P;
}

/** Le corps commun : le magasin, l'employé, la période, le motif, les remplacements ; [erreur, cadre]. */
function eqaCadre(array $b): array
{
    $shop = eqpShop($b);
    if ($shop <= 0) { return [eqpErreur(400, 'shop manquant'), null]; }
    $emp = $b['employe'] ?? null;
    if (!is_int($emp) || $emp < 1) { return [eqpErreur(422, 'employe : un identifiant'), null]; }
    $du = is_string($b['du'] ?? null) ? $b['du'] : ''; $au = is_string($b['au'] ?? null) ? $b['au'] : $du;
    foreach ([$du, $au] as $d) { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || strtotime($d) === false) { return [eqpErreur(422, 'du, au : AAAA-MM-JJ'), null]; } }
    if ($au < $du) { return [eqpErreur(422, 'la période finit avant de commencer'), null]; }
    $n = (int) round((strtotime($au) - strtotime($du)) / 86400) + 1;
    if ($n > EQA_JOURS_MAX) { return [eqpErreur(422, 'une absence couvre ' . EQA_JOURS_MAX . ' jours au plus d’un coup'), null]; }
    $auj = strtotime(date('Y-m-d'));
    if ((strtotime($du) - $auj) / 86400 < -31 || (strtotime($au) - $auj) / 86400 > 120) { return [eqpErreur(422, 'la période : d’un mois avant à quatre mois après aujourd’hui'), null]; }
    $motif = is_string($b['motif'] ?? null) ? $b['motif'] : '';
    if (!isset(EQA_MOTIFS[$motif])) { return [eqpErreur(422, 'motif : ' . implode(', ', array_keys(EQA_MOTIFS))), null]; }
    $jours = array_map(static fn ($i) => date('Y-m-d', strtotime($du . ' +' . $i . ' days')), range(0, $n - 1));
    $remps = [];
    foreach ((array) ($b['remplacements'] ?? []) as $r) {
        $d = is_array($r) && is_string($r['date'] ?? null) ? $r['date'] : '';
        if (!in_array($d, $jours, true)) { return [eqpErreur(422, 'un remplacement hors de la période'), null]; }
        if (isset($remps[$d])) { return [eqpErreur(422, 'un seul remplacement par jour'), null]; }
        if (isset($r['externe'])) {
            $x = $r['externe']; $nom = is_array($x) && is_string($x['nom'] ?? null) ? trim($x['nom']) : '';
            $taux = is_array($x) && is_numeric($x['taux'] ?? null) ? (float) $x['taux'] : 0.0;
            if ($nom === '' || mb_strlen($nom) > 80) { return [eqpErreur(422, 'le nom de l’extérieur : 1 à 80 caractères'), null]; }
            if ($taux <= 0 || $taux > EQA_TAUX_MAX) { return [eqpErreur(422, 'le taux horaire de l’extérieur : plus de 0 et au plus ' . (int) EQA_TAUX_MAX . ' €'), null]; }
            $remps[$d] = ['externe' => ['nom' => $nom, 'taux' => round($taux, 2)]];
        } elseif (is_int($r['employe'] ?? null) && $r['employe'] > 0) {
            $remps[$d] = ['employe' => $r['employe']];
        } else { return [eqpErreur(422, 'un remplacement : {date, employe} ou {date, externe: {nom, taux}}'), null]; }
    }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return [eqpErreur(503, 'compte du panel non configuré'), null]; }
    return [null, ['shop' => $shop, 'employe' => $emp, 'du' => $du, 'au' => $au, 'jours' => $jours, 'motif' => $motif,
        'certificat' => !empty($b['certificat']), 'remps' => $remps]];
}

/** POST /dashboard/equipe/absence — voir l'en-tête. */
function wr_equipe_absence(): array
{
    [$err, $c] = eqaCadre(body());
    if ($err !== null) { return $err; }
    $shop = $c['shop']; $emp = $c['employe'];
    $chemins = ['emp' => '/shops/' . $shop . '/employees'];
    foreach ($c['jours'] as $d) { $chemins['sch' . $d] = '/shops/' . $shop . '/schedule?date=' . $d; }
    $res = eqpGetParallele($chemins, 6);
    if (!is_array($res['emp'] ?? null)) { return eqpErreur(502, 'le panel n’a pas rendu les employés : rien n’est enregistré'); }
    $equipe = []; $contrats = [];
    foreach (analyseListe($res['emp']) as $r) {
        if (!is_array($r)) { continue; }
        $e = eqpPersonne($r);
        if ($e['shop'] === $shop) { $equipe[$e['id']] = $e; $contrats[$e['id']] = isset($r['employment_type']) ? (string) $r['employment_type'] : null; }
    }
    if (!isset($equipe[$emp])) { return eqpErreur(404, 'cette personne n’est pas de l’équipe du magasin'); }
    $parJour = [];
    foreach ($c['jours'] as $d) {
        if (!is_array($res['sch' . $d] ?? null)) { return eqpErreur(502, 'le panel n’a pas rendu le planning du ' . $d . ' : rien n’est enregistré'); }
        $parJour[$d] = eqpServices($res['sch' . $d], $shop, $d);
    }
    try {
        ensureEquipeAbsences();
        $existants = [];
        foreach (Db::rows('SELECT * FROM panel_equipe_absence WHERE shop_id = ? AND employe_id = ? AND jour >= ? AND jour <= ?', [(string) $shop, $emp, $c['du'], $c['au']]) as $r) {
            $l = eqaLigne($r); $existants[$l['service']] = $l;
        }
    } catch (Throwable $e) { return eqpErreur(500, 'les absences ne se lisent pas : rien n’est enregistré'); }
    $payee = eqaPayee($c['motif'], $contrats[$emp] ?? null);
    $P = eqaPlan($parJour, $emp, $payee, $existants, $c['remps'], $equipe);
    if ($P['erreurs'] !== []) { return eqpErreur(422, $P['erreurs'][0], ['erreurs' => $P['erreurs']]); }

    @set_time_limit(180);
    $nom = $equipe[$emp]['nom']; $qui = eqpQui(); $echecs = []; $arret = null;
    $refus = static function (array $r) use (&$arret): bool {
        if (in_array($r['code'], [401, 403], true)) { $arret = 'le panel refuse les écritures du planning à ce compte (HTTP ' . $r['code'] . ')'; }
        return $arret !== null;
    };
    // 1. Une absence non payée : son service quitte le panel (son coût sort). Un refus : la ligne n'est pas gardée.
    $retires = [];
    foreach ($P['retirer'] as $id) {
        if (isset($existants[$id]) && !$existants[$id]['payee']) { $retires[$id] = true; continue; }
        $r = eqpEcrire('DELETE', '/schedule/' . $id);
        if ($r['code'] >= 200 && $r['code'] < 300) { $retires[$id] = true; continue; }
        $echecs[] = ['service' => $id, 'quoi' => 'retirer le service', 'motif' => (string) ($r['erreur'] ?? 'refusé')];
        if ($refus($r)) { break; }
    }
    // 2. Les lignes : une par service absent.
    $poses = 0;
    foreach ($P['lignes'] as $l) {
        if (!$payee && !isset($retires[$l['service']])) { continue; }
        Db::exec('INSERT INTO panel_equipe_absence (shop_id, employe_id, jour, service_id, de, a, motif, payee, certificat, periode_du, periode_au, maj_le, maj_par)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE de = VALUES(de), a = VALUES(a), motif = VALUES(motif), payee = VALUES(payee),'
            . ' certificat = VALUES(certificat), periode_du = VALUES(periode_du), periode_au = VALUES(periode_au), maj_le = NOW(), maj_par = VALUES(maj_par)',
            [(string) $shop, $emp, $l['date'], $l['service'], $l['de'], $l['a'], $c['motif'], $payee ? 1 : 0, $c['certificat'] ? 1 : 0, $c['du'], $c['au'], $qui]);
        $poses++;
    }
    // 3. Les remplaçants de l'équipe : un vrai service au panel, aux mêmes heures ; il est noté sur l'absence.
    $remplaces = 0;
    if ($arret === null) {
        foreach ($P['creer'] as $x) {
            $r = eqpEcrire('POST', '/schedule', ['id_employee' => $x['employe'], 'work_date' => $x['date'], 'start_hour' => $x['de'] . ':00', 'end_hour' => $x['a'] . ':00']);
            if ($r['code'] < 200 || $r['code'] >= 300) {
                $echecs[] = ['date' => $x['date'], 'quoi' => 'le service de ' . $equipe[$x['employe']]['nom'], 'motif' => (string) ($r['erreur'] ?? 'refusé')];
                if ($refus($r)) { break; }
                continue;
            }
            $id = is_array($r['data']) ? (int) ($r['data']['inserted_id'] ?? $r['data']['id_schedule'] ?? $r['data']['id'] ?? ($r['data']['data']['id_schedule'] ?? 0)) : 0;
            Db::exec('UPDATE panel_equipe_absence SET remplacant_id = ?, remplacant_service = ?, externe_nom = NULL, externe_taux = NULL, maj_le = NOW(), maj_par = ? WHERE shop_id = ? AND service_id = ?',
                [$x['employe'], $id > 0 ? $id : null, $qui, (string) $shop, $x['pour']]);
            $remplaces++;
        }
    }
    // 4. Les extérieurs : connus du seul cockpit.
    foreach ($P['externes'] as $x) {
        Db::exec('UPDATE panel_equipe_absence SET externe_nom = ?, externe_taux = ?, remplacant_id = NULL, remplacant_service = NULL, maj_le = NOW(), maj_par = ? WHERE shop_id = ? AND service_id = ?',
            [$x['nom'], $x['taux'], $qui, (string) $shop, $x['service']]);
        $remplaces++;
    }
    try {
        journalAdd('Magasin ' . $shop, 'Planning', null, 'Mon équipe : ' . $nom . ' ' . EQA_MOTIFS[$c['motif']]['court'] . ' du ' . $c['du'] . ' au ' . $c['au']
            . ' (' . $poses . ' service' . ($poses > 1 ? 's' : '') . ($payee ? ', le coût reste' : ', retiré' . ($poses > 1 ? 's' : '') . ' du panel') . ')'
            . ($remplaces ? ', ' . $remplaces . ' remplacement' . ($remplaces > 1 ? 's' : '') : ''));
    } catch (Throwable $e) { /* le journal ne bloque pas */ }
    if ($poses === 0 && $remplaces === 0) { return eqpErreur($arret !== null ? 403 : 502, $arret ?? 'le panel a tout refusé : rien n’est enregistré', ['echecs' => $echecs]); }
    return ['ok' => true, 'absences' => $poses, 'payee' => $payee, 'remplacements' => $remplaces, 'echecs' => $echecs, 'arret' => $arret];
}

/** DELETE /dashboard/equipe/absence {shop, ids, remplacants} — voir l'en-tête. */
function wr_equipe_absence_annuler(): array
{
    $b = body();
    $shop = eqpShop($b);
    if ($shop <= 0) { return eqpErreur(400, 'shop manquant'); }
    $ids = eqpIds($b['ids'] ?? null);
    if ($ids === null || $ids === [] || count($ids) > 100) { return eqpErreur(422, 'ids : une liste d’identifiants (100 au plus)'); }
    try {
        ensureEquipeAbsences();
        $L = array_map('eqaLigne', Db::rows('SELECT * FROM panel_equipe_absence WHERE shop_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([(string) $shop], $ids)));
    } catch (Throwable $e) { return eqpErreur(500, 'les absences ne se lisent pas'); }
    if ($L === []) { return eqpErreur(404, 'aucune de ces absences n’est de ce magasin'); }
    if (array_filter($L, static fn ($l) => !$l['payee'] || (!empty($b['remplacants']) && $l['remplacantService'])) && (!class_exists('PanelApi') || !PanelApi::configured())) {
        return eqpErreur(503, 'compte du panel non configuré');
    }
    @set_time_limit(180);
    $ok = 0; $echecs = [];
    foreach ($L as $l) {
        // Une absence non payée : son service revient au panel ; sans lui, l'absence reste.
        if (!$l['payee']) {
            $r = eqpEcrire('POST', '/schedule', ['id_employee' => $l['employe'], 'work_date' => $l['date'], 'start_hour' => $l['de'] . ':00', 'end_hour' => $l['a'] . ':00']);
            if ($r['code'] < 200 || $r['code'] >= 300) { $echecs[] = ['id' => $l['id'], 'date' => $l['date'], 'motif' => 'le service n’est pas revenu au panel : ' . (string) ($r['erreur'] ?? 'refusé')]; continue; }
        }
        if (!empty($b['remplacants']) && $l['remplacantService']) {
            $r = eqpEcrire('DELETE', '/schedule/' . $l['remplacantService']);
            if (($r['code'] < 200 || $r['code'] >= 300) && $r['code'] !== 404) { $echecs[] = ['id' => $l['id'], 'date' => $l['date'], 'motif' => 'le service du remplaçant reste au panel : ' . (string) ($r['erreur'] ?? 'refusé')]; }
        }
        Db::exec('DELETE FROM panel_equipe_absence WHERE shop_id = ? AND id = ?', [(string) $shop, $l['id']]);
        $ok++;
    }
    if ($ok > 0) {
        try { journalAdd('Magasin ' . $shop, 'Planning', null, 'Mon équipe : absence annulée (' . $ok . ' service' . ($ok > 1 ? 's' : '') . ', employé ' . $L[0]['employe'] . ')'); }
        catch (Throwable $e) { /* le journal ne bloque pas */ }
    }
    if ($ok === 0) { return eqpErreur(502, 'rien n’est annulé', ['echecs' => $echecs]); }
    return ['ok' => true, 'annulees' => $ok, 'echecs' => $echecs];
}

/** Un service retiré du planning (la fenêtre d'un service, retirer de l'horaire) : son absence, s'il en avait une, part avec. */
function eqaOublierService(int $shop, int $service): void
{
    try { ensureEquipeAbsences(); Db::exec('DELETE FROM panel_equipe_absence WHERE shop_id = ? AND service_id = ? AND payee = 1', [(string) $shop, $service]); }
    catch (Throwable $e) { /* sans table : rien à oublier */ }
}
