# Matières premières : par fournisseur ou par catégorie, le prix du fournisseur face à chaque magasin (maquette réelle)

**Statut : maquette réelle du 14/11/2026, à valider. Rien n'est écrit.**

Demande du 14/11/2026 : « un tableau avec les matières premières, sélection soit par fournisseur soit par catégorie, avec
le prix marqué fournisseur + celui de chaque magasin ».

- `a.png` — **par fournisseur** (SLFood, 183 matières), groupées par catégorie : 101 alignées (les 4 magasins à 10 % près
  de la liste), 72 avec un écart de plus de 10 %, 5 sans prix dans un magasin, 4 sans prix de référence.
- `b.png` — **par catégorie** (Matières premières (laitier), 19 matières), groupées par fournisseur : 18 sans fournisseur
  lié, 17 sans prix de référence (ni liste ni prix général), 10 sans prix dans un magasin (Halle surtout) ; la Crème debic
  40 % a un prix général de 3,50 €/kg quand les 4 magasins paient 2,20 € (−37 %).

Colonnes : la matière (unité, centrale ou libre), le fournisseur et sa référence (colis, liste en vigueur) en vue par
catégorie, le **prix fournisseur** par unité (la liste en vigueur, sinon le prix général du panel), puis **chaque magasin** :
son prix et son écart (✓ à 10 % près, orange au-delà, rouge au-delà de ×2 ou sans prix), les recettes et leur CA, et
« Fiche » (la fiche de la matière, modifiable, en ligne depuis le 14/11). Un liseré par ligne dit l'état d'un coup d'œil.

Données : `donnees.json`, lu en ligne le 14/11/2026 par `GET /verification/recettes/matieres` (les 400 matières des recettes
vendues). Régénérer : `php -S 127.0.0.1:8099 -t .` depuis pilotage-reseau/, puis `node …/generer.js` et `node …/capturer.js`.
