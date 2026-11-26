# La fiche d'un bureau (Vendre › Clients › Bureaux) — maquettes

**Statut : maquettes, à choisir.** 26/11/2026. Sam, sur la capture de l'onglet Bureaux (Corbais, compte TF Buddy du
magasin connecté) : « retravailler car non visible pour toutes les informations, faire une drop list ou autre, faire 2-3
maquettes en screenshots, ensuite je décide ». La fiche actuelle tient dans une colonne de 370 px : les onglets
débordent (« Documents » coupé), une seule rubrique se voit à la fois, et un bureau sans site affiche
`HTTP 422 : OFFICE_DELIVERY_SITE_REQUIRED` brut.

| Fichier | Proposition |
|---|---|
| `maquette-a.png` | **A — le tiroir** : la liste garde la largeur ; un panneau de 640 px glisse à droite avec tout, l'un sous l'autre (livraison, sites, catalogue, offres, personnes, documents) ; bureau précédent / suivant |
| `maquette-b.png` | **B — la ligne dépliée** (« drop list ») : le bureau s'ouvre sous sa ligne, sur toute la largeur, en quatre colonnes (livraison et sites · catalogue · offres · personnes), les gestes et documents en barre |
| `maquette-c.png` | **C — la page du bureau** : la liste s'efface ; une liste déroulante (avec recherche) et ‹ › passent d'un bureau à l'autre ; six chiffres (sites, départements, personnes, offres, commandes et CA 12 mois) ; trois colonnes de cartes |

Dans les trois : ce qui manque est dit à sa place avec le bouton pour le faire (« Pas de site de livraison… » →
« Ajouter un site »), au lieu du code d'erreur seul. Données fictives (les bureaux du banc, Gosselies).

Régénérer : `node panel/docs/maquettes/clients-bureaux-fiche/capturer.js` (`index.html?v=a|b|c`).
