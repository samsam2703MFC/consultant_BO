<?php
declare(strict_types=1);

/**
 * La matière expliquée — l'indicateur d'une paire d'inventaires, un seul (19/11/2026, docs/audit-rail.md § 4.4).
 *
 * Entre deux inventaires (« justesse », sur les seules matières fiables) et Vérification › Matières ↔ recettes
 * (« explication », sur toutes les matières chiffrées) donnaient deux pourcentages pour la même semaine. Il n'en reste
 * qu'un, calculé ici et lu par les deux écrans : la part de ce qui est sorti du stock que la caisse et la poubelle
 * expliquent, matière par matière, au plus ce qui est sorti — une matière vendue au-delà de son stock (livraison hors
 * panel) ne rachète pas le manquant d'une autre —, sur toutes les matières qui ont un prix, valorisée au prix d'achat.
 * Une matière qu'aucune vente ne consomme (recette manquante) compte donc comme non expliquée : c'est ce qu'il faut
 * corriger, pas une ligne à écarter.
 */

/** L'écart matière (100 − matière expliquée, % du consommé) : vert jusqu'à 3 %, orange jusqu'à 6 % — comme le non expliqué. */
const INV_TAUX_SEUILS = [3.0, 6.0];

/**
 * Ajoute une matière au compteur $a (clés « sortiPos » et « couvert », créées au besoin).
 *
 * @param float $sorties  ce qui est sorti du stock (compté au début + livré − pertes − compté à la fin)
 * @param float $explique ventes × recette + poubelle × recette
 * @param float $prix     prix d'achat de la matière
 */
function invTauxAjoute(array &$a, float $sorties, float $explique, float $prix): void
{
    $a['sortiPos'] = (float) ($a['sortiPos'] ?? 0.0);
    $a['couvert'] = (float) ($a['couvert'] ?? 0.0);
    if ($sorties <= 0) { return; }
    $a['sortiPos'] += $sorties * $prix;
    $a['couvert'] += min(max($explique, 0.0), $sorties) * $prix;
}

/** Le ton d'une matière expliquée : « ok », « att » ou « ko » selon INV_TAUX_SEUILS ; « mu » sans mesure. */
function invTauxTon(?float $taux): string
{
    if ($taux === null) { return 'mu'; }
    $ecart = round(100 - $taux, 1);
    return $ecart <= INV_TAUX_SEUILS[0] ? 'ok' : ($ecart <= INV_TAUX_SEUILS[1] ? 'att' : 'ko');
}

/** La matière expliquée, en %, à un chiffre après la virgule ; null quand rien n'est sorti. */
function invTaux(array $a): ?float
{
    $s = (float) ($a['sortiPos'] ?? 0.0);
    return $s > 0 ? round(100 * (float) ($a['couvert'] ?? 0.0) / $s, 1) : null;
}
