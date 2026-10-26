# Maquettes « Promotions au cockpit » (26/10/2026)

> **Construit le 26/10/2026 : la forme A**, avec un onglet en lecture seule dans le dashboard (« A, garde un onglet
> lecture seule dans le dashboard »). Le rail du cockpit a la section Promotions : **Édition** (`#/promotions/edition`,
> l'assistant du dashboard dans un cadre — `dashboard/?embed=promos` —, le magasin choisi dans son entête) et **Tableau
> d'analyse** (`#/promotions`, `GET /promotions/reseau`, `src/promos_reseau.php`, `public/assets/js/promotions-*.js`). Le
> dashboard du magasin lit ses promotions sans les créer ni les modifier ; l'onglet Promotions d'Analyse magasin est
> retiré. Réponses aux questions : une promotion à la fois, pour un magasin (la question 3 tombe avec A) ; l'Édition
> n'écrit que les promotions d'un magasin, comme l'assistant d'avant ; pas de rôle (l'API a un mot de passe commun).
> Détail dans `docs/contrat-api.md` (26/10/2026). La maquette reste ici comme référence.

Demande : sortir le module de création des promotions du dashboard du franchisé (onglet Promotions,
`public/dashboard/dashboard.js`, l'assistant « X achetés + Y ») et le mettre dans le rail du cockpit, **un menu à lui
avec deux sous-menus : Édition (le formulaire actuel) et Tableau d'analyse (toutes les promotions de tous les
magasins)**. Trois ou quatre maquettes en captures, on décide ensuite.

## Le rail (commun aux quatre)

Une section **Promotions** entre Produits et Marque & marketing :

| Entrée | Badge | Ce qu'elle ouvre |
|---|---|---|
| Édition | brouillons à reprendre | l'assistant du dashboard, avec le choix du magasin |
| Tableau d'analyse | promotions en caisse sous le repère ×2 | toutes les promotions, marque et magasins, et ce qu'elles font sur 30 jours |

Analyse magasin perd son onglet Promotions (lecture seule, renvoi ↗ au dashboard), que le Tableau d'analyse remplace.

## Les quatre formes

| | Forme | Tableau d'analyse | Édition |
|---|---|---|---|
| A | Deux pages pleines | `a-tableau.png` : six chiffres du réseau, la liste du dashboard à plat avec une colonne Magasin et les chiffres sur 30 jours (fois, ventes, poids dans le CA, remise, part webshop, coefficient) | `a-edition.png` : l'assistant tel quel en page pleine, le magasin dans une liste, les brouillons à reprendre en tête |
| B | Magasin par magasin | `b-tableau.png` : une ligne par magasin avec ses totaux et ses alertes (rien à lui en caisse, coefficient sous ×2), qui s'ouvre sur ses promotions ; « + Promotion » par magasin | `b-edition.png` : à gauche le magasin et ses promotions (reprendre, créer), à droite l'assistant |
| C | Une promotion, plusieurs magasins | `c-tableau.png` : la matrice promotions × magasins (fois, ventes, remise ou coefficient dans les cases) ; une même mécanique tient sur une ligne | `c-edition.png` : l'assistant gagne une étape « Magasins » en tête (un, plusieurs, tout le réseau), le coefficient magasin par magasin, les doublons déjà en caisse signalés |
| D | Le tableau d'abord, l'édition en panneau | `d-tableau.png` : la carte « Où les promotions sont utilisées » du dashboard, à l'échelle du réseau, puis la liste | `d-edition.png` : l'assistant dans un panneau par-dessus le tableau (sans la colonne calculateur et ticket) |

`planche.png` : les quatre côte à côte, avec ce que chacune apporte et coûte.

## Réel et illustré

**Réel** : le formulaire. `generer.cjs` fait tourner le vrai dashboard (`public/dashboard/`) sur des réponses d'API
fictives, remplit l'assistant (« Remplir un exemple · 1 mois », nom, créneau lun.–ven. 14–17 h, bon AUTOMNE) et en
reprend les étapes telles quelles : rien n'est redessiné. Les styles sont ceux du cockpit et du dashboard.

**Illustré** (`donnees.cjs`) : les produits, prix et coûts de Gosselies, les 18 promotions du réseau et leurs chiffres
sur 30 jours, le CA des magasins, les coefficients par magasin de la forme C. Les sorties de la colonne Fois, Ventes et
Poids CA existent déjà par magasin (onglet Promotions des Ventes par catégorie, carte « Où les promotions sont
utilisées ») : le tableau les additionne pour le réseau.

## À trancher

1. **La forme** : A, B, C, D, ou un mélange (par exemple le tableau de B ou de C avec l'édition de A).
2. **Le dashboard du magasin** : il ne crée plus rien. Garde-t-il un onglet Promotions en lecture seule (la liste, et
   « Où les promotions sont utilisées ») ou plus rien ?
3. **Plusieurs magasins d'un coup** (C) : au panel, une promotion par magasin (chacune réservée, comme aujourd'hui) ou
   une seule « magasins choisis » (`SELECTED_SHOPS`) ?
4. **Les promotions de la marque** : l'Édition les écrit-elle aussi (tout le réseau), ou seulement celles d'un magasin ?
5. **Qui a la main** : tout utilisateur du cockpit, ou un rôle ?

## Régénérer

```
php -S 127.0.0.1:8099 -t .                              # à la racine du dépôt
node docs/maquettes/promotions-cockpit/generer.cjs      # → index.html, *.png
```

`index.html` est cliquable : une barre en haut passe d'un écran à l'autre.
