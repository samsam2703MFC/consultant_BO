<?php
declare(strict_types=1);

/**
 * Le dashboard magasin au téléphone, onglet Semaine (demande du 04/10/2026, maquette A « les
 * feux ») : le damier jour × mesure. Le chiffre, l'objectif et le résultat de chaque jour
 * viennent déjà de /exploitation/jour (magasins[].semaine) ; cette route ajoute ce qui ne se lit
 * que jour par jour : les contrôles obligatoires (rendus, notés, non conformes) et la poubelle.
 */

const DS_TTL_JOUR = 300;     // aujourd'hui : cinq minutes
const DS_TTL_PASSE = 1800;   // un jour passé : une demi-heure, les notes du consultant arrivent après coup

/**
 * Les contrôles obligatoires d'un magasin un jour, comptés comme l'onglet Contrôle les compte
 * (une tâche dont le panel ne dit pas qu'elle est facultative est obligatoire) :
 * {rendus, total, notes, nc, aControler, sansPhoto, manquent: [noms], sansPhotoNoms: [noms]} ;
 * null si le panel n'a jamais répondu. Un contrôle n'est rendu qu'avec sa photo (06/10/2026) :
 * coché sans photo, il se compte à part (`sansPhoto`), ni rendu ni manquant.
 */
function dsControles(int $sid, string $date): ?array
{
    $cle = 'dsCtrl2:' . $sid . ':' . $date;
    $c = setting($cle);
    $ttl = $date >= date('Y-m-d') ? DS_TTL_JOUR : DS_TTL_PASSE;
    if (is_array($c) && isset($c['v']) && (int) ($c['ts'] ?? 0) > time() - $ttl) { return $c['v']; }
    $mem = $_GET; $_GET = ['date' => $date, 'shop' => (string) $sid];
    try { $r = ep_pwa_tasks(); } finally { $_GET = $mem; }
    if (!empty($r['indispo'])) { return is_array($c) ? ($c['v'] ?? null) : null; }
    $sh = null; foreach ((array) ($r['shops'] ?? []) as $s) { if ((int) ($s['shopId'] ?? 0) === $sid) { $sh = $s; } }
    $seuil = (int) ($r['seuil'] ?? 4);
    $v = ['rendus' => 0, 'total' => 0, 'notes' => 0, 'nc' => 0, 'aControler' => 0, 'sansPhoto' => 0, 'manquent' => [], 'sansPhotoNoms' => []];
    foreach ($sh !== null ? (array) ($sh['taches'] ?? []) : [] as $t) {
        if (!is_array($t) || ($t['obligatoire'] ?? null) === false) { continue; }
        $v['total']++;
        if (($t['statut'] ?? '') === 'nonRendue') { $v['manquent'][] = (string) ($t['tache'] ?? ''); continue; }
        if (($t['statut'] ?? '') === 'sansPhoto') { $v['sansPhoto']++; $v['sansPhotoNoms'][] = (string) ($t['tache'] ?? ''); continue; }
        $v['rendus']++;
        if (($t['statut'] ?? '') === 'aControler') { $v['aControler']++; }
        if (isset($t['note']) && is_numeric($t['note'])) { $v['notes']++; if ((int) $t['note'] < $seuil) { $v['nc']++; } }
    }
    svGrave($cle, ['ts' => time(), 'v' => $v]);
    return $v;
}

/** La poubelle de chaque jour : [date => {pieces, cout} | null] ; les jours manquants lus au panel en parallèle. */
function dsPoubelle(int $sid, array $jours): array
{
    $L = []; $paths = [];
    foreach ($jours as $d) {
        $c = setting(invCle($sid, $d, $d));
        $ok = is_array($c) && isset($c['l']) && is_array($c['l']);
        $L[$d] = $ok ? $c['l'] : null;
        if (!$ok || (int) ($c['ts'] ?? 0) <= time() - invTtl($d)) { $paths[$d] = invChemin($sid, $d, $d); }
    }
    if ($paths && class_exists('PanelApi') && PanelApi::configured()) {
        foreach (PanelApi::getParallele($paths, 4, 25) as $d => $r) {
            $l = invLignesDe($r);
            if ($l === null) { continue; }
            svGrave(invCle($sid, (string) $d, (string) $d), ['ts' => time(), 'l' => $l]);
            $L[(string) $d] = $l;
        }
    }
    $out = [];
    foreach ($L as $d => $l) {
        if ($l === null) { $out[$d] = null; continue; }
        $p = 0.0; $cout = 0.0;
        foreach ($l as $x) { $p += (float) $x['pieces']; $cout += invCoutNet($sid, $x)[0]; }
        $out[$d] = ['pieces' => round($p, 1), 'cout' => round($cout, 2)];
    }
    return $out;
}

/**
 * GET /exploitation/semaine-jours?shop=4&date=YYYY-MM-DD — la semaine (lundi → dimanche) de la
 * date, jour par jour : contrôles obligatoires et poubelle. Les jours à venir sont rendus vides.
 */
function ep_exploitation_semaine_jours(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
    @set_time_limit(120);
    $lun = date('Y-m-d', strtotime('monday this week', strtotime($date . ' 12:00:00')));
    $auj = date('Y-m-d');
    $tous = []; for ($i = 0; $i < 7; $i++) { $tous[] = date('Y-m-d', strtotime($lun . ' 12:00:00 +' . $i . ' days')); }
    $passes = array_values(array_filter($tous, static fn ($d) => $d <= $auj));
    $P = dsPoubelle($sid, $passes);
    $jours = [];
    foreach ($tous as $d) {
        $fut = $d > $auj;
        $jours[] = ['date' => $d, 'futur' => $fut, 'aujourdhui' => $d === $auj,
            'controles' => $fut ? null : dsControles($sid, $d), 'poubelle' => $fut ? null : ($P[$d] ?? null)];
    }
    return ['shop' => $sid, 'du' => $lun, 'au' => end($tous), 'jours' => $jours,
        'source' => 'contrôles : tâches obligatoires du panel, jour par jour (/pwa/tasks) · poubelle : /shops/{id}/products/waste, coût net'];
}
