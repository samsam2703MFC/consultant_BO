# Paramétrage des produits : la fiche, les saisons, les liaisons

**Statut : « tout sur une page » retenu le 14/11/2026, et codé** (onglet Produits › Paramétrage produits, `#/produits/parametrage` ; serveur `src/produit_parametrage.php`, routes `GET /produits/parametrage/liste`, `GET|POST /produits/parametrage`). Demande du 14/11/2026 : « un onglet qui serait une page de paramétrage des produits
(saison, toutes les options, tout ce que contiennent les API) et toutes les liaisons possibles entre les API reliées aux
produits ».

Un onglet **Paramétrage produits** en tête du groupe Produits du rail. À gauche, la liste des produits (recherche,
gamme, catégorie, actifs, webshop, champs manquants, sans gamme). À droite, la fiche du produit, en huit onglets :

- `a.png` — **la fiche** : les 44 champs que le panel rend pour un produit, rangés en Général, Vente & canaux,
  Fabrication, Conservation & vitrine, Étiquette & allergènes, avec la clé du panel sous chaque libellé et la route
  d'écriture de chaque section ; les gammes ; le prix, le coût, le coefficient et la part matière de chaque magasin.
- `b.png` — **saisons & gammes** : les 15 gammes du panel sur l'année, celles du produit cochées, et ce que le choix
  change (mois hors vente, CA concerné, webshop).
- `c.png` — **les liaisons** : les 28 objets de l'API reliés à un produit (catégorie, groupe, zone de production,
  secteur, gammes, traductions, recette, matières, fournisseurs, portions, compétences, stockage, vitrine, emballage,
  étiquette, magasins, prix, stock, promotions, codes promo, commandes B2B, ventes, pertes, concurrence, réclamations),
  chacune avec ses routes et son état : lue par le cockpit, lisible et modifiable, ou à mesurer.

Écriture : le même chemin que les corrections de recettes (vérifier → écrire avec le compte consultant → relire →
registre, « Annuler »).

## Données

`donnees.json`, lu le 14/11/2026 en lecture seule : `/product-availability-periods` (15), `/product-categories` (81),
`/product-category-groups` (12), `/product-storage-locations` (8), les 44 clés d'un produit (sonde du cockpit) et la
fiche « Pommes Tranches » (produit 6700228, recette 524). **Valeurs en italique pointillé : exemples**, le produit n'a
pas été relu champ par champ.

## Ce que la lecture a déjà montré

- « 🍦 Icône - Gamme Glace (Avril - Septembre) » est réglée du **04/01 au 03/01** au panel : toute l'année, pas
  d'avril à septembre.
- Une gamme **« test »** (01/06 → 01/09) est active, visible au webshop, sans produit.
- « Gamme Bureau » (01/01 → 01/01) et « Gamme B.-2-B. » (17/03 → 17/03) ne durent qu'un jour.
- Avec le jeton du proxy, `/products/{id}`, `/product-display-positions`, `/product-competences`,
  `/consultant/product-sectors` et les promotions répondent 401 : à mesurer avec le compte du cockpit avant de les
  rendre modifiables.

Générer : `node generer.js` ; capturer (serveur `php -S 127.0.0.1:8099 -t .` depuis `pilotage-reseau/`) : `node capturer.js`.
