<?php
declare(strict_types=1);

/**
 * Le profil de semaine des magasins (GET /magasins/profil-jour) : table con_shop_profil_jour, créée et écrite par
 * ensureProfilJour (shared/src/endpoints.php, « Résultat du jour »).
 *
 * Sorti tel quel de marketing/src/mesure.php le 21/11/2026 : une table con_ se lit dans le domaine Pilotage.
 */

/**
 * GET /magasins/profil-jour — le profil de semaine mémorisé, magasin par
 * magasin. Ce que pèse chaque jour, mesuré, daté, et lisible sans passer par
 * l'écran qui l'a produit.
 */
function ep_profil_jour(): array
{
    ensureProfilJour();
    $noms = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) { $noms[(string) $s['id']] = (string) $s['name']; }
    } catch (PDOException $e) { /* noms indisponibles */ }
    $JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
    $par = [];
    foreach (Db::rows('SELECT * FROM con_shop_profil_jour ORDER BY shop_id, jour') as $r) {
        $sid = (string) $r['shop_id'];
        if (!isset($par[$sid])) { $par[$sid] = ['shopId' => $sid, 'magasin' => $noms[$sid] ?? ('Magasin ' . $sid),
            'jours' => [], 'total' => 0.0, 'maj' => null, 'du' => null, 'au' => null]; }
        $m = $r['ca_moyen'] !== null ? (float) $r['ca_moyen'] : null;
        $par[$sid]['jours'][] = ['jour' => (int) $r['jour'], 'nom' => $JOURS[(int) $r['jour']] ?? (string) $r['jour'],
            'moyenne' => $m, 'observations' => (int) $r['jours']];
        $par[$sid]['total'] += (float) ($m ?? 0);
        $par[$sid]['maj'] = $r['maj']; $par[$sid]['du'] = $r['du']; $par[$sid]['au'] = $r['au'];
    }
    foreach ($par as $sid => $p) {
        foreach ($p['jours'] as $i => $j) {
            $par[$sid]['jours'][$i]['part'] = $p['total'] > 0 && $j['moyenne'] !== null
                ? round(100 * $j['moyenne'] / $p['total'], 2) : null;
        }
        $par[$sid]['total'] = round($p['total'], 2);
    }
    return ['magasins' => array_values($par),
        'lecture' => 'Moyenne du CA par jour de semaine, mesurée sur la fenêtre lue par « Résultat du jour » '
            . 'et réécrite à chaque passage. La part est celle du jour dans une semaine type.'];
}
