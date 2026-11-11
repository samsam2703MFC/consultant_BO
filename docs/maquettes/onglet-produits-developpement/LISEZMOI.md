# L'onglet Produits, 2e version : est-ce que la catégorie, le produit se développe ?

**Statut : à choisir.** Remplace l'angle des maquettes A à D (`../onglet-produits/`).

Précision du 11/11/2026 sur la demande : l'idée de l'onglet est de savoir **s'il y a un
développement** dans une catégorie, dans un produit. L'onglet répond donc d'abord par un verdict :
**se développe**, **stable**, **recule** (ou nouveau, arrêté, trop peu vendu). Il garde le reste de la
demande :

- un graphique sur 12 semaines ;
- un clic sur une catégorie (ou un groupe) ouvre une modale ;
- un clic sur un produit ouvre une liste déroulante : prix de vente, quantités et marge, **avant et
  après**.

## La règle

- **Les 6 dernières semaines closes (S40 → S45) face aux 6 d'avant (S34 → S39)**, en chiffre.
  On parle de développement au-delà de + 8 %, de recul en dessous de − 8 %, de stabilité entre les deux.
  Si l'une des deux périodes est presque vide, ou s'il n'y a plus aucune vente depuis 3 semaines, le
  produit est marqué nouveau ou arrêté. Moins de 24 pièces sur les 12 semaines : trop peu vendu pour juger.
- **Face au réseau** : la même comparaison pour la moyenne d'un magasin du réseau, sur la même
  catégorie ou le même produit. Elle permet de distinguer la vraie hausse de la saison : « plus vite
  que le réseau », « comme le réseau », « alors que le réseau progresse ». Dans les graphiques, la
  courbe du réseau est ramenée au niveau d'avant du magasin, pour montrer la courbe qu'il aurait suivie
  au rythme du réseau.
- **L'élan** : dans la liste déroulante, les 3 dernières semaines face aux 6 d'avant, pour voir si
  ça « repart » ou si ça « ralentit » quand le verdict des 6 semaines dit l'inverse. Exemple, le
  Baulus : − 11 % sur 6 semaines, mais + 11 % sur les 3 dernières.
- **Le réseau l'an dernier** (produits seulement) : il dit s'il y a un effet de saison sur ces
  semaines-là.

## Les chiffres sont réels

Atelier by - Halle (shop 4), lus le 11 novembre 2026 en lecture seule :

| | |
|---|---|
| Le magasin | **+ 11,9 %** de chiffre (+ 8 555 €), 11 971 € → 13 397 € par semaine ; un magasin du réseau : + 8,7 % |
| Pièces, marge | + 14,0 % de pièces ; + 8 765 € de marge brute, 59,1 % → 60,0 % des ventes |
| Catégories | 14 se développent, 3 stables, 8 reculent, 5 nouvelles |
| Produits | 47 se développent, 12 stables, 23 reculent ; 62 nouveaux, 31 arrêtés |
| Ce qui porte la hausse | Viennoiserie + 3 133 € (+ 15 %, réseau + 6 %) : 37 % de la hausse du magasin ; Boulangerie + 1 446 € ; Tartes + 1 365 € (réseau 0 %) ; Traiteur + 22 % (réseau + 6 %) |
| Ce qui décroche du réseau | Pâtisserie + 1 % quand le réseau fait + 21 % ; Boissons − 3 % (réseau + 18 %) ; Épicerie − 15 % (réseau + 2 %) |
| Produits | Baguette Tradition 500 g + 13 % (réseau + 6 %), mais avec 33 % de marge ; Brownies − 23 % (réseau + 26 %) ; Croûte aux fraises − 67 %, comme le réseau (− 73 %) : la saison |

Lectures : `GET /ventes/stats?vue=semaine` (le magasin, produit par produit, déjà lu pour A à D),
`GET /analyse/categories/magasin?niveau=groupe|categorie` (12 groupes, 34 catégories : le magasin et
le réseau, en euros et en pièces), `GET /analyse/produits/magasin?pid=` (133 produits : le magasin, le
réseau, le réseau l'an dernier).

## Les trois

| | rangement | graphique 12 semaines | modale | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|---|---|
| **E — le pont** (`e.html`) | « D'où vient la hausse » : chaque groupe (et ses catégories) avec son écart en euros sur une barre centrée sur zéro ; dessous, deux listes : **ce qui se développe**, **ce qui recule** | colonnes semaine par semaine, avant en clair, après en foncé, la moyenne de chaque période, le rythme du réseau | groupe Viennoiserie : ses catégories, ses produits, classés par écart (`e-modale`) | répond en euros : qui porte la hausse, qui la freine ; les deux listes disent quoi regarder | la courbe d'une catégorie n'apparaît que dans sa fiche |
| **F — les courbes** (`f.html`) | une petite courbe par catégorie (indice 100 = avant), avec le réseau à côté, rangées en « se développent », « stables », « reculent » ; dessous, les 10 produits qui bougent le plus | le magasin face au réseau, en indice | Sandwiches garnis : sa courbe, ses produits chacun sur sa courbe, Thon déplié (`f-modale`) | la pente de chaque catégorie se voit d'un coup d'œil ; vraie hausse ou saison | les montants en euros passent au second plan |
| **G — la carte et le tableau** (`g.html`) | les catégories placées selon leur poids (chiffre) et leur développement, en 4 familles : moteurs, à surveiller, ça démarre, s'essouffle ; dessous, le tableau groupe › catégorie › produit : avant, après, écart, réseau, marge avant → après, verdict | petites colonnes du magasin à côté de la carte ; 12 semaines sur chaque ligne | Boissons froides : stable quand le réseau fait + 22 % (`g-modale`) | le plus complet ; le tableau reprend la liste de « Ventes par catégorie » | la carte demande une explication la première fois |

## La liste déroulante d'un produit (commune aux trois)

1. **Est-ce que ça se développe ?** Le verdict et l'écart en %, puis une phrase : le chiffre avant et
   après, le réseau sur le même produit, l'élan des 3 dernières semaines, la saison (le réseau l'an
   dernier). Viennent ensuite les pièces de chaque semaine, avec la moyenne avant et après et le
   rythme du réseau.
2. **Prix, quantités, marge, avant → après** : pièces par semaine, prix encaissé, chiffre par semaine,
   marge par semaine, marge en % des ventes, avec l'écart ; puis le prix catalogue, le coût de la
   recette, le coefficient et le prix à la cible.
3. **Son poids** : sa part dans la catégorie et dans le magasin, son écart dans celui de sa
   catégorie, son rang à la marge ; les liens vers la fiche produit et Recette & marge.

## À trancher avant de coder

- **Le changement de caisse de S40.** « Tartes » est devenu « Tartes – Ø 28 cm », avec des noms
  néerlandais remplacés par des noms français. Les maquettes rattachent les anciennes références aux
  nouvelles par leur nom (21 rattachements, comme Rijst → Riz ou Kaas → Fromage). Sans ce rattachement,
  toutes les tartes paraissent « nouvelles » et l'ancienne catégorie « arrêtée ». Pour le coder, il
  faut une table de correspondance dans le catalogue, à valider avec vous.
- **Les portions.** Jusqu'en S40, la caisse ne nommait pas la tarte ou la quiche d'une portion
  (« 1/2 », « 1/4 - Quiches ») : le développement de ces produits se mesure donc sur la pièce entière.
  Au niveau de la catégorie, le chiffre est complet.
- **La période.** 6 semaines face aux 6 d'avant (choix des maquettes), 4 face à 4, ou le mois face au
  mois d'avant (proposés dans la barre). Le seuil de 8 % est aussi à confirmer.
- **Le coût par produit de S34 à S39** reste celui de la recette actuelle (voir
  `../onglet-produits/LISEZMOI.md`) : l'API rend des coûts faux sur ces semaines-là.

## Régénérer

```bash
node docs/maquettes/onglet-produits/extraire.js 4 2026-11-11            # le magasin, produit par produit
node docs/maquettes/onglet-produits-developpement/extraire-reseau.js    # le réseau
node docs/maquettes/onglet-produits-developpement/generer.js
npx http-server . -p 8099 -c-1                                          # depuis la racine du dépôt
node docs/maquettes/onglet-produits-developpement/capturer.js
# → http://127.0.0.1:8099/docs/maquettes/onglet-produits-developpement/planche.html
```
