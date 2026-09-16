<?php
declare(strict_types=1);

/**
 * Commandes en cours et livraisons.
 *
 * Avant de poser « commandes en cours » sur un tableau de bord, il faut savoir
 * si la donnée existe et où. Ce fichier commence donc par une sonde de
 * cadrage — structure seulement, jamais le contenu des lignes : elle rend les
 * noms de tables de la base partagée, les colonnes de celles dont le nom
 * évoque une commande ou une livraison, et le code HTTP des routes candidates
 * du panel. Lecture seule.
 */

/** GET /ventes/commandes/sonde — où sont les commandes et les livraisons ? */
function ep_commandes_sonde(): array
{
    $sid = (int) ($_GET['shop'] ?? 3);
    $out = ['shop' => $sid, 'toutes' => [], 'tables' => [], 'panel' => []];
    $motifs = '/(order|deliver|commande|livrai|reserv|booking|preorder|requisition|expedit|invoice|facture)/i';
    try {
        foreach (Db::rows("SELECT TABLE_NAME nom, TABLE_ROWS lignes FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                            ORDER BY TABLE_NAME") as $t) {
            $nom = (string) ($t['nom'] ?? '');
            if ($nom === '') { continue; }
            $out['toutes'][] = $nom . ' (' . (int) ($t['lignes'] ?? 0) . ')';
            if (!preg_match($motifs, $nom)) { continue; }
            $cols = [];
            foreach (Db::rows("SELECT COLUMN_NAME c FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                                ORDER BY ORDINAL_POSITION", [$nom]) as $r) {
                $v = (string) ($r['c'] ?? '');
                if ($v !== '') { $cols[] = $v; }
            }
            $n = 0;
            try { $n = (int) (Db::rows('SELECT COUNT(*) n FROM `' . $nom . '`')[0]['n'] ?? 0); } catch (Throwable $e) { /* vue ou droits */ }
            $out['tables'][] = ['nom' => $nom, 'lignes' => $n, 'colonnes' => $cols];
        }
    } catch (Throwable $e) {
        $out['erreurBase'] = $e->getMessage();
    }
    if (!PanelApi::configured()) { $out['panel'] = ['erreur' => 'compte panel non configuré']; return $out; }
    $cands = [
        '/shops/' . $sid . '/orders',
        '/orders?shop_id=' . $sid,
        '/shops/' . $sid . '/deliveries',
        '/deliveries?shop_id=' . $sid,
        '/shops/' . $sid . '/reservations',
        '/shops/' . $sid . '/preorders',
        '/shops/' . $sid . '/material-requisitions',
        '/shops/' . $sid . '/material-requisitions/list',
        '/consultant/shops/' . $sid . '/orders',
        '/pwa/orders?shop_id=' . $sid,
    ];
    foreach ($cands as $p) {
        $r = PanelApi::sondeGet($p);
        $c = $r['corps'] ?? null;
        $l = is_array($c) ? analyseListe($c) : [];
        // Les CLÉS, jamais les valeurs : une commande porte un nom et un
        // téléphone, ils n'ont rien à faire dans une sonde.
        $out['panel'][] = ['route' => $p, 'code' => (int) $r['code'],
            'n' => $l !== [] ? count($l) : null,
            'cles' => $l !== [] && is_array($l[0]) ? array_keys($l[0]) : (is_array($c) ? array_slice(array_keys($c), 0, 12) : null),
            'erreur' => $r['erreur'] === null ? null : mb_substr((string) $r['erreur'], 0, 160)];
    }
    return $out;
}
