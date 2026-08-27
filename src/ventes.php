<?php
declare(strict_types=1);

/**
 * Cockpit CEO — le personnel de vente : target et classement.
 *
 * Qui vend le mieux, personne par personne. Le classement se fait au
 * CA PAR HEURE PRESTÉE, jamais au CA brut : une vendeuse à 20 heures ne se
 * compare pas à une à 38. Le panier moyen (CA ÷ tickets) et le cross-selling
 * (lignes par ticket) complètent la lecture. Chaque mois, la marque prime la
 * meilleure de chaque magasin et la meilleure du réseau.
 *
 * Deux sources, toutes deux RÉSOLUES et jamais supposées :
 *  - le vendeur sur le ticket (la caisse) ;
 *  - les heures prestées (le planning du panel).
 * Si l'une manque, l'écran nomme ce qui manque — il ne classe pas au CA brut
 * en silence, ce qui ferait gagner les plus gros horaires.
 */

/** Sous ce volume d'heures, on montre mais on ne classe pas. */
const VENTE_SEUIL_HEURES = 20;

/** Les colonnes candidates pour le vendeur, sur `transaction`. */
const VENTE_COLS_VENDEUR = ['id_user', 'user_id', 'id_employee', 'employee_id',
    'id_seller', 'seller_id', 'id_cashier', 'cashier_id', 'id_user_membership',
    'id_staff', 'staff_id', 'id_worker'];

/**
 * GET /ventes/sonde — ce que la base expose réellement.
 *
 * Écran de diagnostic, pas de pilotage : il dit quelle colonne porte le
 * vendeur, quelles tables ressemblent à un planning, et ce que chacune
 * contient. C'est lui qui décide de la suite — pas une supposition.
 */
function ep_ventes_sonde(): array
{
    $out = ['transaction' => [], 'candidatsVendeur' => [], 'tables' => [], 'users' => []];
    try {
        foreach (Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction'") as $r) {
            $v = is_array($r) ? (string) reset($r) : (string) $r;
            if ($v !== '') { $out['transaction'][] = $v; }
        }
        foreach (VENTE_COLS_VENDEUR as $c) {
            if (in_array($c, array_map('strtolower', $out['transaction']), true)) { $out['candidatsVendeur'][] = $c; }
        }
        foreach (Db::rows("SELECT TABLE_NAME nom, TABLE_ROWS lignes FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                              AND (TABLE_NAME LIKE '%plan%' OR TABLE_NAME LIKE '%shift%'
                                OR TABLE_NAME LIKE '%schedul%' OR TABLE_NAME LIKE '%hour%'
                                OR TABLE_NAME LIKE '%presence%' OR TABLE_NAME LIKE '%clock%'
                                OR TABLE_NAME LIKE '%pointage%' OR TABLE_NAME LIKE '%work%'
                                OR TABLE_NAME LIKE '%staff%' OR TABLE_NAME LIKE '%employee%'
                                OR TABLE_NAME LIKE '%hr\\_%')
                            ORDER BY TABLE_NAME") as $t) {
            $nom = (string) $t['nom'];
            $cols = [];
            foreach (Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$nom]) as $r) {
                $v = is_array($r) ? (string) reset($r) : (string) $r;
                if ($v !== '') { $cols[] = $v; }
            }
            $out['tables'][] = ['nom' => $nom, 'lignes' => (int) ($t['lignes'] ?? 0), 'colonnes' => $cols];
        }
        foreach (Db::rows("SELECT TABLE_NAME nom, TABLE_ROWS lignes FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%user%'
                            ORDER BY TABLE_NAME") as $t) {
            $out['users'][] = ['nom' => (string) $t['nom'], 'lignes' => (int) ($t['lignes'] ?? 0)];
        }
    } catch (Throwable $e) {
        $out['erreur'] = $e->getMessage();
    }
    return $out;
}
