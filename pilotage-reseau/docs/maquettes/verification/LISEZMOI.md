# Vérification : stocks, ventes, recettes, chiffre d'affaires (maquette)

**Statut : maquette du 31/10/2026, à valider.** Le plan de réalisation est dans
[`docs/plan-verification.md`](../../plan-verification.md).

Demandes du 31/10/2026 :

- une rubrique **Vérification** dans le pilotage réseau, qui reprend les stocks et les
  ventes des franchisés et vérifie, par les recettes, si tout est en ordre, et fait la
  même chose pour le chiffre d'affaires ;
- une **bascule** qui liste toutes les recettes douteuses ou manquantes, pour une
  gestion à 100 % ;
- un **audit par magasin au jour, à la semaine et au mois** (entré · produit · poubelle ·
  invendu), **en %, dans un tableau** ;
- **un tableau par magasin, tous de la même structure** : stock début + entrées −
  stock fin = sorti ; sorti − ventes − poubelle = **delta**, par catégorie et par
  produit, pour **voir où il y a des fuites, et s'il y en a** ;
- tout **enregistrer chaque jour en base**, pour voir les progrès de gestion ;
- **l'entrée, c'est la validation des commandes entrantes** (réponse du 31/10).

Décisions du 31/10 : seuils de fuite 3 % / 6 % ; un inventaire matière par semaine (l'onglet
Matières se lit d'un inventaire à l'autre, sur sept jours) ; la liste des recettes à corriger est
pour le CEO seul (pas d'envoi) ; pas d'alerte pour l'instant.

## Les écrans

| Fichier | Écran | Ce qu'il montre |
|---|---|---|
| `a.png` | Audit des flux, le réseau, la semaine | Par magasin : l'entré (au coût), la part devenue pièces, puis vendu, poubelle, invendu et non expliqué en % du disponible ; le non expliqué et la poubelle jour par jour |
| `a-jour.png` | Audit des flux, un magasin, un jour | Produit par produit : gardé d’hier, entré (commande validée), produit (sorti du four), disponible ; vendu, poubelle, invendu, non expliqué, en pièces et en % |
| `b.png` | Audit des flux, un magasin, le mois | Jour par jour, le total de chaque semaine et du mois ; « où ça fuit » par catégorie |
| `g.png` | **Fuites par magasin, produits finis** | La matrice catégories × magasins, puis **un tableau par magasin, de structure identique** : stock début, + entrées (commandes validées), + produit (cuissons), − stock fin, = sorti, ventes, poubelle, **delta** (pièces, % du sorti, €). Une catégorie se déplie en produits |
| `g-matieres.png` | **Fuites par magasin, matières premières** | Le même tableau en matières, au coût : stock début + **entrées = commandes validées à la réception** − stock fin = sorti, face aux ventes et à la poubelle passées par les recettes. Les commandes de la semaine une par une, dont une livrée mais pas validée ; les **gestes à surveiller** (stock corrigé à la main, prix saisi à la main, livraison d'un fournisseur libre, tickets modifiés après paiement, inventaire modifié après coup) |
| `c.png` | Matières ↔ recettes | D'un inventaire hebdomadaire à l'autre : stock + entré − stock = consommé réel, face au théorique des recettes, matière par matière ; d'où vient l'écart |
| `d.png` | Chiffre d'affaires | Caisse, P&L, base des redevances, CA attendu par la matière ; lignes sans produit, annulations ; les jours à regarder |
| `e.png` | La bascule allumée | Les 37 recettes douteuses ou manquantes, classées par CA concerné ; la couverture vers 100 % |
| `f.png` | Évolution | Ce que montre la base quotidienne : douze semaines, avant / après, mieux ou moins bien |

`planche.jpg` : tous les écrans sur une seule image.

Dans le rail, une section **Vérification** (badge « NOUVEAU ») entre Magasins et
Produits : Audit des flux, Fuites par magasin, Matières et recettes (badge : le nombre
de recettes à corriger), Chiffre d'affaires, Évolution. La bascule « Recettes
douteuses ou manquantes » est en haut de chaque onglet.

## Données

**Ce sont des chiffres d'illustration.** La maquette a été faite sans accès au panel
(aucun identifiant dans l'environnement de travail). Les magasins, les produits, les
catégories, les matières et les prix sont ceux du réseau. Les quantités et les montants
viennent d'un générateur déterministe (mulberry32), cohérent d'un écran à l'autre : la
semaine 43 de `a.png` est la somme des jours de `b.png`, les fuites de `g.png` reprennent
les mêmes totaux, et `f.png` est la suite des mêmes semaines. Quelques faits viennent
de mesures déjà notées dans `docs/contrat-api.md`, par exemple le coût « custom » de
0,79 € du FlipFlap - Thon à Halle. Aucun nom de personne n'apparaît.

## Régénérer

```
php -S 127.0.0.1:8099 -t .        # depuis la racine du dépôt (ou npx http-server . -p 8099 -c-1)
node docs/maquettes/verification/generer.js
node docs/maquettes/verification/capturer.js
```

`vf.css` s'ajoute au CSS du cockpit (`global.css`, `app.css`) et n'ajoute que les
classes `vf-*`.
