<?php
declare(strict_types=1);

/**
 * LES cibles du réseau, une variable chacune, lue partout (18/11/2026, Sam : « on garde pour le moment à 40 % (la même
 * variable partout) », « oui, même variable pour le personnel à 17 % », puis « mets aussi les articles par ticket et le
 * panier »).
 *
 *  - le FOOD COST (foodCostCible, 40 %) : le seuil d'alerte « Food cost du mois (%) » du Catalogue des KPI ;
 *  - le PERSONNEL (personnelCible, 17 %) : le seuil d'alerte « Labour cost (%) » du Catalogue des KPI ;
 *  - le PANIER MOYEN (panierCible, 13 € au départ) : le seuil d'alerte « Ticket moyen (€) » du Catalogue ;
 *  - les ARTICLES PAR TICKET (articlesCible, 3,6 au départ) : le seuil d'alerte « Articles par ticket » du Catalogue.
 *  Panier et articles gardent la valeur que le Catalogue porte déjà : ils ne sont pas réécrits, seulement lus partout.
 *  Les cibles de vente additionnelle par magasin de Ventes › primes (`venteCrossTargets`, en lignes par ticket) sont
 *  une autre mesure, propre à chaque magasin et aux primes : elles ne passent pas par ici.
 *
 * C'est au Catalogue qu'on les règle (Paramètres › Catalogue des KPI) : un seuil changé là s'applique partout —
 *  - les rapports et le dossier magasin (rapports.php, dossier.php) ;
 *  - les seuils du cockpit et du P&L court (bootstrap `seuils`, seuilsCharges : le jour, la période, le téléphone, la
 *    trésorerie, ses propositions de plafond fournisseurs et de paie) — avant le 18/11/2026, le taux matière de
 *    Budget › Paramètres et la table `kpi` ;
 *  - le prix objectif des produits (prix = coût de recette ÷ cible food cost, produits_coef.php) ;
 *  - la valeur du magasin (la matière d'un mois sans mesure, le personnel du modèle) et les couleurs du dashboard ;
 *  - les cibles de départ du Coach Vente (src/coach_objectifs.php) : food cost, personnel, panier, articles.
 *
 * FOOD_COST_CIBLE et PERSONNEL_CIBLE sont les valeurs de départ (posées une fois au Catalogue par kpiCibles2026) ;
 * PANIER_CIBLE et ARTICLES_CIBLE, celles du semis du Catalogue. Toutes servent de repli quand le référentiel ne se lit
 * pas (ou qu'un KPI dérivé y a été supprimé). Les écrans les reçoivent de l'API ; leurs replis en dur valent la même chose.
 */

const FOOD_COST_CIBLE = 40.0;
const PERSONNEL_CIBLE = 17.0;
const PANIER_CIBLE = 13.0;      // la valeur de départ du Catalogue (« Ticket moyen (€) »), en repli
const ARTICLES_CIBLE = 3.6;     // la valeur de départ du Catalogue (« Articles par ticket »), en repli

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

/**
 * Les paliers du budget d'heures (23/11/2026, Sam : « le budget heures semaine est de 17 % ; au-dessus un marqueur
 * orange, rouge au-dessus de 20 %, noir au-dessus de 22 % (variables) ») : les frais de personnel du planning face au
 * CA prévu. Jusqu'à la cible (personnelCible), dans la cible ; au-dessus, orange ; au-dessus de `rouge`, rouge ;
 * au-dessus de `noir`, noir. Les deux paliers se règlent dans le réglage `personnelPaliers` ({rouge, noir}, en %) ;
 * un réglage absent, illisible ou incohérent (rouge ≤ cible, noir < rouge) retombe sur les valeurs de départ.
 */
const PERSONNEL_ROUGE = 20.0;
const PERSONNEL_NOIR = 22.0;

/** @return array{cible: float, rouge: float, noir: float, source: string} */
function personnelPaliers(): array
{
    $cible = personnelCible();
    $r = PERSONNEL_ROUGE; $n = PERSONNEL_NOIR; $src = 'départ';
    $s = function_exists('setting') ? setting('personnelPaliers', null) : null;
    if (is_array($s) && is_numeric($s['rouge'] ?? null) && is_numeric($s['noir'] ?? null)) {
        $sr = (float) $s['rouge']; $sn = (float) $s['noir'];
        if ($sr > $cible && $sn >= $sr && $sn < 100) { $r = $sr; $n = $sn; $src = 'réglage'; }
    }
    // Une cible du réseau relevée au-dessus des paliers de départ les pousse : l'ordre cible < rouge ≤ noir tient toujours.
    if ($r <= $cible) { $r = $cible + 3; $n = max($n, $r + 2); }
    return ['cible' => $cible, 'rouge' => $r, 'noir' => $n, 'source' => $src];
}

/** Le palier d'une part du personnel (en % du CA) : ok, orange, rouge ou noir. */
function personnelNiveau(float $pct, ?array $p = null): string
{
    $p ??= personnelPaliers();
    return $pct <= $p['cible'] ? 'ok' : ($pct <= $p['rouge'] ? 'orange' : ($pct <= $p['noir'] ? 'rouge' : 'noir'));
}

/** La cible du panier moyen du réseau, en € TTC (CA ÷ tickets). */
function panierCible(): float
{
    return cibleReseau('panier-moyen', PANIER_CIBLE);
}

/** La cible des articles par ticket du réseau (articles vendus ÷ tickets). */
function articlesCible(): float
{
    return cibleReseau('articles-ticket', ARTICLES_CIBLE);
}
