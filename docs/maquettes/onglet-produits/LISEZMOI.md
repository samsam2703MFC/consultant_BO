# L'onglet Produits du dashboard magasin : quatre maquettes

**Statut : remplacées par la 2e version, centrée sur le développement** (`../onglet-produits-developpement/`, maquettes E, F, G). Le code reste, les deux générateurs le partagent.

Demande du 11/11/2026, sur `/dashboard/?shop=4&vue=ops` : ajouter un onglet qui montre **quels
produits je vends, combien, à quel prix et à quelle marge**, avec :

- un graphique sur **12 semaines** ;
- un clic sur une **catégorie** qui ouvre une **modale** ;
- un clic sur un **produit** qui ouvre une **liste déroulante** : prix de vente, quantités, marge…

L'onglet **Produits** se place après Année. Les quatre maquettes partagent l'en-tête du dashboard,
les cinq chiffres clés, le choix de période (12 semaines par défaut) et **la même liste déroulante
d'un produit**. Elles diffèrent par la manière de ranger les produits et par le graphique des
12 semaines.

## Les chiffres sont réels

Atelier by - Halle (shop 4), lus le 11 novembre 2026 en lecture seule, de S34 à S45 (17 août → 8 nov.),
plus S46 en cours (3 jours) :

| | |
|---|---|
| Chiffre sur 12 semaines | **152 213 €**, 12 684 € par semaine |
| Pièces vendues | **54 591**, 4 549 par semaine, ▲ 14 % sur les 6 dernières semaines |
| Marge brute | **86 546 €**, **59,8 %** des ventes chiffrées |
| Références vendues | 239 ; 20 sans coût de recette (7 456 € de ventes hors marge) |
| Premier produit | Croissant : 4 871 pièces, 8 657 €, 61,5 % de marge, coefficient × 2,48 pour une cible × 2,65 |

Lectures : `GET /ventes/stats?vue=semaine` (13 fois, une par semaine) pour les pièces, les ventes et
le coût, catégorie par catégorie et produit par produit ; `GET /analyse/produits/coefficients` pour
le prix catalogue, le coût de la recette, le coefficient et sa cible.

## Les quatre

| | rangement | graphique 12 semaines | modale de catégorie | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|---|---|
| **A** — le tableau (`a.html`) | une liste groupe › catégorie › produit, qui se déplie, en colonnes : pièces, par semaine, prix moyen, chiffre, marge, marge %, part, 12 semaines, tendance | colonnes coût matière + marge = chiffre de la semaine, le % de marge dans la colonne | Viennoiserie Ind. : les chiffres, les 12 semaines, ses produits classés (`a-modale`) | la suite de « Ventes par catégorie » : rien à apprendre ; tout se compare en colonne | long à parcourir : 239 produits, il faut déplier |
| **B** — les tuiles (`b.html`) | une tuile par catégorie (chiffre, pièces, jauge de marge, petites colonnes des 12 semaines, tendance) ; dessous, les 15 premiers produits à la marge | le chiffre de chaque semaine **par groupe** (5 groupes + les autres) : le mix | Quiches : les pièces par format (entière, demi, quart), les produits (`b-modale`) | se lit d'un coup d'œil ; on voit ce qui porte le magasin | moins de chiffres à l'écran ; 12 tuiles, le reste replié |
| **C** — la carte (`c.html`) | chaque produit placé selon ses **pièces par semaine** et sa **marge** ; quatre familles : piliers, volume, pépites, à questionner | la marge de chaque famille, semaine par semaine (quatre petits graphiques) | Petite Boulangerie : ses produits sur la carte du magasin, les 12 semaines, la liste (`c-modale`) | dit quoi faire : le prix à revoir, ce qu'il faut pousser ; les marges négatives sautent aux yeux | demande une explication la première fois |
| **D** — la grille (`d.html`) | chaque produit sur 12 colonnes de semaines, la case plus foncée quand la semaine est forte pour ce produit | deux courbes : chiffre et marge brute | Pain : les courbes et la grille de ses produits (`d-modale`) | le détail de chaque semaine ; les ruptures, lancements et arrêts se voient | large ; la marge n'est qu'en dernière colonne |

Images : `a.jpg` … `d.jpg` (l'onglet, un produit déplié), `a-modale.jpg` … `d-modale.jpg` (la modale
d'une catégorie), `planche.jpg` (les quatre côte à côte).

## La liste déroulante d'un produit (commune aux quatre)

Trois colonnes :

1. **Le prix de vente** : prix catalogue TTC, prix encaissé moyen (et l'écart, les remises), prix HT,
   coût de la recette, marge par pièce, coefficient et cible de la catégorie, le prix qui atteindrait
   la cible et celui qui atteindrait l'objectif du réseau (× 3,13).
2. **Les quantités** : les pièces de chaque semaine (S46 hachurée, la moyenne en pointillé), par
   semaine, par jour ouvert, la tendance (6 dernières semaines face aux 6 d'avant), la meilleure
   semaine ; pour un produit vendu en portions, le détail entière / demi / quart.
3. **La marge brute** : en euros et en % des ventes, la barre coût + marge = ventes, par semaine, la
   part du chiffre et de la marge du magasin, le rang, la durée de vie ; les liens vers la fiche
   produit et l'onglet Recette & marge déjà en ligne.

## Ce que les maquettes ont fait remonter

- **Les coûts par produit de S34 à S39 sont faux dans `/ventes/stats`.** En S34, la somme des coûts
  produit fait 8 541 € pour 4 629 € de matière au P&L ; les entremets y coûtent 15 fois leur prix,
  les croûtes aux fruits 20 fois, les pains 30 % de trop. Depuis S41, l'écart au P&L est sous 2 %.
  Les maquettes prennent donc pour toutes les semaines **le coût de la recette actuelle** (le coût
  unitaire des ventes S41 → S46). À trancher avant de coder : relire l'historique ou garder ce choix.
- **Le catalogue a changé en S40** : « Tartes » est devenu « Tartes – Ø 28 cm », et les portions
  « 1/2 - Quiches », « 1/4 - Quiches » ont laissé la place aux portions de chaque quiche. Les
  maquettes le montrent par une étiquette « nouveau · S40 » / « arrêté · S40 » plutôt qu'une
  tendance de + 6 500 %.
- **Les produits qui rapportent peu** : 2 en marge négative (l'Opéra individuel, dont la recette
  coûte plus que le prix, et un Saint-Nicolas offert), 41 dans la famille « le volume » (beaucoup vendus, marge sous 60 %), dont la Baguette Tradition 500 g :
  2 137 pièces à 2,20 €, coefficient × 1,42, 33 % de marge ; à la cible de sa catégorie elle se
  vendrait 3,35 €.
- Les marges se calculent sur le prix **TTC** (même calcul que « Ventes par catégorie »), le
  coefficient et la marge par pièce sur le prix **HT** (même calcul que « Recette & marge ») : deux
  pourcentages pour un même produit (Croissant : 61,5 % et 60 %). À unifier ou à expliquer.

## Régénérer

```bash
node docs/maquettes/onglet-produits/extraire.js 4 2026-11-11   # relit le serveur, écrit reel-halle.json
node docs/maquettes/onglet-produits/generer.js
npx http-server . -p 8099 -c-1                                 # depuis la racine du dépôt
node docs/maquettes/onglet-produits/capturer.js
# → http://127.0.0.1:8099/docs/maquettes/onglet-produits/planche.html
```
