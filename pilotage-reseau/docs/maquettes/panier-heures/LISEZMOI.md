# Heure par heure : la bascule « Panier » — maquette

Demande du 01/11/2026 : « rajoute un toggle dans le graphique Heure par heure, l'évolution du panier
moyen par rapport aux heures ».

Dans le dashboard magasin, onglet Opérationnel, carte « Heure par heure » : la bascule
**CA · Clients** devient **CA · Clients · Panier**.

## Ce que montre « Panier »

| Élément | Ce qu'il dit |
|---|---|
| Ligne rouge, valeur sur chaque point | le panier moyen **de chaque heure** : le CA de l'heure ÷ ses clients. Ce n'est pas un cumul, contrairement à CA et Clients |
| Ligne bleue pointillée | la même chose pour J−7 (dimanche dernier), la journée entière |
| Pointillé rouge horizontal | le panier du jour jusqu'ici (16,23 € à 14:22) ; son étiquette se pose là où elle ne touche aucun point, sinon seule la légende le nomme |
| Barres beiges au pied | les clients de chaque heure, à leur propre échelle. Elles disent ce que pèse chaque point |
| Point creux | moins de 5 clients dans l'heure, ou l'heure en cours : le panier y est peu fiable |
| En haut à droite | l'heure au panier le plus haut et l'heure au panier le plus bas (heures d'au moins 5 clients, heure en cours exclue) |

« Comptoir et pro séparés » : le panier du comptoir seul (les tickets pro retirés heure par heure),
comme le duel. « Combinés » : tous les tickets. La bascule n'apparaît que si les deux journées ont
leurs tickets heure par heure, comme pour Clients.

Au téléphone : même graphique, sans les valeurs sur les points ; les valeurs sont dans la légende.

## Captures

| Fichier | Ce que c'est |
|---|---|
| `avant.png` | la carte en ligne aujourd'hui, mode CA |
| `a-aujourdhui.png` | Halle (shop 4), dimanche 01/11/2026 à 14:22 : le panier monte de 8,65 € à 6 h à 19,95 € à 10 h |
| `b-journee-terminee.png` | Halle, samedi 31/10/2026, journée terminée : panier de la journée 14,25 €, le plus haut à 17 h (24,80 €) |
| `c-telephone.png` | la vue A au téléphone (390 px) |
| `a.html` | le DOM de la carte A |

Données réelles, lues en lecture seule sur le serveur (`/ventes/stats` pour aujourd'hui,
`/exploitation/jour` pour J−7 heure par heure). La page en ligne est ouverte avec `dashboard.js`
et `dashboard.css` de ce dépôt à la place des fichiers servis : **les captures montrent le code de
la branche**, pas un dessin. Tout appel autre que GET est intercepté.

Régénérer : `node docs/maquettes/panier-heures/generer.js`

## Statut

**Codée et mise en ligne le 01/11/2026** (déploiement n°1914, vérifiée sur le serveur à 15:08) : `opPanierHeures()` dans
`public/dashboard/dashboard.js`, appelée par `opDuelHeures()` quand `S.opCrb === 'pan'` ; le
troisième bouton dans la bascule `data-opcrb` ; les styles `.op-crb .cl/.cln/.pal/.p7l/.tpa/.pmj/.objr`,
`.op-dleg i.cl/i.pal/i.pmj` et `.op-pk` dans `public/dashboard/dashboard.css`. Les modes CA et
Clients ne changent pas.
