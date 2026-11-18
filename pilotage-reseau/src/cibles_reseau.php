<?php
declare(strict_types=1);

/**
 * LES cibles du réseau, une variable chacune, lue partout (18/11/2026, Sam : « on garde pour le moment à 40 % (la même
 * variable partout) », puis « oui, même variable pour le personnel à 17 % »).
 *
 *  - le FOOD COST (foodCostCible, 40 %) : le seuil d'alerte « Food cost du mois (%) » du Catalogue des KPI ;
 *  - le PERSONNEL (personnelCible, 17 %) : le seuil d'alerte « Labour cost (%) » du Catalogue des KPI.
 *
 * C'est au Catalogue qu'on les règle (Paramètres › Catalogue des KPI) : un seuil changé là s'applique partout —
 *  - les rapports et le dossier magasin (rapports.php, dossier.php) ;
 *  - les seuils du cockpit et du P&L court (bootstrap `seuils`, seuilsCharges : le jour, la période, le téléphone, la
 *    trésorerie, ses propositions de plafond fournisseurs et de paie) — avant le 18/11/2026, le taux matière de
 *    Budget › Paramètres et la table `kpi` ;
 *  - le prix objectif des produits (prix = coût de recette ÷ cible food cost, produits_coef.php) ;
 *  - la valeur du magasin (la matière d'un mois sans mesure, le personnel du modèle) et les couleurs du dashboard ;
 *  - les cibles de départ du Coach Vente (src/coach_objectifs.php).
 *
 * FOOD_COST_CIBLE et PERSONNEL_CIBLE sont les valeurs de départ (posées une fois au Catalogue par kpiCibles2026) et le
 * repli quand le référentiel ne se lit pas. Les écrans les reçoivent de l'API ; leurs replis en dur valent la même chose.
 */

const FOOD_COST_CIBLE = 40.0;
const PERSONNEL_CIBLE = 17.0;

/** Une cible du réseau : le seuil d'alerte d'un KPI du Catalogue, sinon $repli. Gardée le temps de la requête. */
function cibleReseau(string $code, float $repli): float
{
    static $memo = [];
    if (isset($memo[$code])) { return $memo[$code]; }
    $x = null;
    if (function_exists('kpiDefs')) {
        try {
            $d = kpiDefs()[$code] ?? null;
            $x = is_array($d) && $d['seuil_alerte'] !== null ? (float) $d['seuil_alerte'] : null;
        } catch (Throwable $e) { $x = null; }
    }
    return $memo[$code] = ($x !== null && $x > 0 && $x < 100 ? $x : $repli);
}

/** La cible du food cost du réseau, en % du CA. */
function foodCostCible(): float
{
    return cibleReseau('food-cost-pct', FOOD_COST_CIBLE);
}

/** La cible des frais de personnel du réseau, en % du CA. */
function personnelCible(): float
{
    return cibleReseau('labour-pct', PERSONNEL_CIBLE);
}
