<?php
declare(strict_types=1);

/**
 * LA cible du food cost du réseau (18/11/2026, Sam : « on garde pour le moment à 40 % (la même variable partout) »).
 *
 * Une seule valeur, lue partout où le cockpit juge un coût matière :
 *  - le seuil d'alerte « Food cost du mois (%) » du Catalogue des KPI — c'est LUI qu'on règle (Paramètres › Catalogue
 *    des KPI) : un seuil changé là s'applique partout ;
 *  - les rapports et le dossier magasin (rapports.php, dossier.php) ;
 *  - les seuils du cockpit et du P&L court (bootstrap `seuils.food`, seuilsCharges : le jour, la période, le téléphone,
 *    la trésorerie et son plafond fournisseurs) — avant le 18/11/2026, le taux matière de Budget › Paramètres ;
 *  - le prix objectif des produits (prix = coût de recette ÷ cible, produits_coef.php) et le repère du dashboard ;
 *  - la cible food cost de départ du Coach Vente (src/coach_objectifs.php).
 *
 * FOOD_COST_CIBLE est la valeur de départ (posée une fois au Catalogue par kpiFoodCost2026) et le repli quand le
 * référentiel ne se lit pas. Les écrans la reçoivent de l'API ; leurs replis en dur valent la même chose.
 */

const FOOD_COST_CIBLE = 40.0;

/** La cible du food cost du réseau, en % du CA : le seuil d'alerte du Catalogue des KPI, sinon FOOD_COST_CIBLE. */
function foodCostCible(): float
{
    static $v = null;
    if ($v !== null) { return $v; }
    $x = null;
    if (function_exists('kpiDefs')) {
        try {
            $d = kpiDefs()['food-cost-pct'] ?? null;
            $x = is_array($d) && $d['seuil_alerte'] !== null ? (float) $d['seuil_alerte'] : null;
        } catch (Throwable $e) { $x = null; }
    }
    return $v = ($x !== null && $x > 0 && $x < 100 ? $x : FOOD_COST_CIBLE);
}
