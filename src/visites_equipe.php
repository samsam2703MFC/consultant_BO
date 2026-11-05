<?php
declare(strict_types=1);

/*
 * L'équipe en poste, notée pendant la visite (05/11/2026, Sam : « via les API planning, le personnel travaillant, une
 * note rapide de 1 à 5 avec une note, fast check oui / non : en habit de travail ? SBAM ? XP client ? »).
 *
 * La tablette lit le planning du jour du magasin (GET /visites/equipe/{shop}) : la route du planning du panel,
 * /shops/{id}/schedule?date=, la même que l'Opérationnel du dashboard ; ses postes de travail viennent de
 * /shops/{id}/employees. Si le panel ne répond pas : la table synchronisée (franchisee_employee_schedule), marquée comme
 * telle. Le consultant note chaque personne vue ; la note se garde sur la visite (con_visite.personnel, viPersonnel).
 */

/** Les trois contrôles éclair de chaque personne : clé, libellé, ce qu'on regarde. */
const VI_EQUIPE_CHECKS = [
    ['k' => 'tenue', 'fr' => 'En tenue de travail', 'aide' => 'tablier, tenue de la marque, cheveux attachés'],
    ['k' => 'sbam', 'fr' => 'SBAM', 'aide' => 'sourire, bonjour, au revoir, merci'],
    ['k' => 'xp', 'fr' => 'Expérience client', 'aide' => 'conseil, dégustation, mise en avant'],
];

/** GET /visites/equipe/{shop}?date= — les personnes au planning du jour, leurs heures et leurs postes. */
function ep_visites_equipe(string $shop): array
{
    if (!isset(viMagasins()[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $date = viDate($_GET['date'] ?? null) ?? date('Y-m-d');
    return viEquipeJour((int) $shop, $date) + ['shop' => $shop, 'date' => $date, 'checks' => VI_EQUIPE_CHECKS];
}

/** Le planning d'un magasin pour un jour : { source, personnes: [{ emp, nom, debut, fin, postes }] }, en cache cinq minutes. */
function viEquipeJour(int $shop, string $date): array
{
    $cle = 'visEquipe:' . $shop . ':' . $date;
    $c = function_exists('motCache') ? motCache($cle) : null;
    if (is_array($c) && (int) ($c['t'] ?? 0) > time() - 300) { return $c['v']; }
    $personnes = []; $source = null; $erreur = null;
    if (class_exists('PanelApi') && PanelApi::configured()) {
        $rep = PanelApi::getParallele(['sch' => '/shops/' . $shop . '/schedule?date=' . $date, 'emp' => '/shops/' . $shop . '/employees'], 2);
        $postes = [];
        foreach (is_array($rep['emp'] ?? null) ? analyseListe($rep['emp']) : [] as $em) {
            $p = [];
            foreach ((array) ($em['workstations'] ?? []) as $w) { $nw = trim((string) (is_array($w) ? ($w['name'] ?? '') : $w)); if ($nw !== '') { $p[] = $nw; } }
            $postes[(int) ($em['id'] ?? 0)] = $p;
        }
        if (is_array($rep['sch'] ?? null)) {
            $source = 'panel';
            foreach (analyseListe($rep['sch']) as $r) {
                if (($r['work_date'] ?? $date) !== $date || (int) ($r['id_shop'] ?? $shop) !== $shop) { continue; }
                $nom = trim((string) ($r['display_name'] ?? ''));
                if ($nom === '') { $nom = trim(((string) ($r['name'] ?? '')) . ' ' . ((string) ($r['surname'] ?? ''))); }
                $emp = (int) ($r['id_employee'] ?? 0);
                $personnes[] = ['emp' => $emp, 'nom' => $nom !== '' ? $nom : 'Employé ' . $emp, 'debut' => substr((string) ($r['start_hour'] ?? ''), 0, 5),
                    'fin' => substr((string) ($r['end_hour'] ?? ''), 0, 5), 'postes' => $postes[$emp] ?? []];
            }
        } else { $erreur = 'planning du panel indisponible'; }
    }
    if ($source === null) {
        try {
            $emps = function_exists('venteEmployes') ? venteEmployes() : [];
            foreach (Db::rows('SELECT id_employee, start_hour, end_hour FROM franchisee_employee_schedule WHERE work_date = ? ORDER BY start_hour', [$date]) as $r) {
                $e = $emps[(int) $r['id_employee']] ?? null;
                if ($e === null || (string) ($e['shop'] ?? '') !== (string) $shop) { continue; }
                $source = 'table locale';
                $personnes[] = ['emp' => (int) $r['id_employee'], 'nom' => (string) $e['nom'], 'debut' => substr((string) $r['start_hour'], 0, 5), 'fin' => substr((string) $r['end_hour'], 0, 5), 'postes' => []];
            }
        } catch (Throwable $e) { $erreur = $erreur ?? 'planning illisible'; }
    }
    // Une personne à deux services dans la journée : une seule ligne, ses deux créneaux.
    $par = [];
    foreach ($personnes as $p) {
        if ($p['debut'] === '' || $p['fin'] === '') { continue; }
        $k = $p['emp'] > 0 ? 'e' . $p['emp'] : 'n' . $p['nom'];
        if (isset($par[$k])) { $par[$k]['creneaux'][] = [$p['debut'], $p['fin']]; continue; }
        $par[$k] = ['emp' => $p['emp'], 'nom' => $p['nom'], 'postes' => $p['postes'], 'creneaux' => [[$p['debut'], $p['fin']]]];
    }
    $v = ['source' => $source, 'erreur' => $source === null ? $erreur : null, 'personnes' => array_values($par)];
    usort($v['personnes'], static fn ($a, $b) => [$a['creneaux'][0][0], $a['nom']] <=> [$b['creneaux'][0][0], $b['nom']]);
    if (function_exists('motCacheEcrire')) { motCacheEcrire($cle, ['t' => time(), 'v' => $v]); }
    return $v;
}

/**
 * La note de l'équipe reçue de la tablette : { personnes: [{ emp, nom, heures, vu, note 1..5, com, tenue, sbam, xp (1, 0 ou
 * null) }] } (ou la liste directement).
 * Une personne « pas vue » ne garde que son nom ; null si rien n'est noté pour personne.
 */
function viPersonnel(mixed $x, string $qui): ?array
{
    if (!is_array($x)) { return null; }
    if (is_array($x['personnes'] ?? null)) { $x = $x['personnes']; }
    $out = []; $n = 0;
    foreach (array_slice(array_values($x), 0, 40) as $p) {
        if (!is_array($p)) { continue; }
        $nom = mb_substr(trim((string) ($p['nom'] ?? '')), 0, 120);
        if ($nom === '') { continue; }
        $vu = !array_key_exists('vu', $p) || (bool) $p['vu'];
        $note = isset($p['note']) && is_numeric($p['note']) ? (int) $p['note'] : null;
        $l = ['emp' => (int) ($p['emp'] ?? 0), 'nom' => $nom, 'heures' => mb_substr(trim((string) ($p['heures'] ?? '')), 0, 40), 'vu' => $vu, 'note' => $vu && $note !== null && $note >= 1 && $note <= 5 ? $note : null,
            'com' => $vu ? mb_substr(trim((string) ($p['com'] ?? '')), 0, 500) : ''];
        foreach (VI_EQUIPE_CHECKS as $c) { $v = $p[$c['k']] ?? null; $l[$c['k']] = $vu && ($v === 1 || $v === 0 || $v === true || $v === false || $v === '1' || $v === '0') ? (int) $v : null; }
        if ($l['note'] !== null || !$vu || $l['com'] !== '' || $l['tenue'] !== null || $l['sbam'] !== null || $l['xp'] !== null) { $n++; }
        $out[] = $l;
    }
    return $n ? ['personnes' => $out, 'qui' => mb_substr($qui, 0, 120), 'le' => date('Y-m-d H:i:s')] : null;
}
