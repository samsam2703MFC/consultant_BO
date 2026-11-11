# Résultat › Évolution magasin — le magasin depuis que le panel a ses ventes

**Statut : maquettes à choisir, rien n'est codé dans le BO.**

Demande du 11/11/2026 : l'analyse d'un magasin depuis qu'on a les données — le trafic clients et
le chiffre d'affaires du magasin, chaque fois avec la ligne de la période et, quand c'est possible,
la ligne N-1. Deux ou trois maquettes d'abord ; l'onglet ira ensuite dans **Résultat**, à côté
d'« Analyse réseau » : **Évolution magasin**.

## Les trois

| | forme | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|
| **A** | `a.png` : **le fil depuis l'ouverture, mois par mois**. Deux courbes l'une sous l'autre — clients, puis CA — sur tout l'historique, le N-1 en pointillé dès qu'il existe, l'écart en % sous chaque mois, le gagné / perdu en vert / rouge entre les deux courbes. Sept tuiles, trois tuiles par courbe, une table année par année. | toute l'histoire du magasin d'un coup d'œil : l'ouverture, les saisons, la pente | les mois récents sont serrés (27 mois sur une largeur) |
| **B** | `b.png` : **une année posée sur l'autre**. Janvier à décembre en abscisse, une courbe par année (2026 plein, 2025 = N-1 pointillé, 2024 = N-2 en filet), clients et CA côte à côte. Puis l'écart cumulé depuis le 1er janvier (clients et CA sur le zéro — l'écart entre les deux courbes, c'est le panier), puis la table des douze mois sur trois années. | la saison neutralisée : on compare un mois au même mois ; « à date » se lit tout de suite | l'historique d'avant N-2 sort du cadre ; pour un magasin d'un an, une seule courbe |
| **C** | `c.png` : **semaine par semaine, avec la tendance**. Les quatre magasins en vignettes en tête (sert de sélecteur), puis des barres par semaine depuis l'ouverture — clients, puis CA — avec la tendance (moyenne de 4 semaines) et le N-1 en pointillé. En bas, l'écart au N-1 semaine par semaine, en barres vertes / rouges, et sa moyenne sur 4 semaines. | le grain fin : un décrochage ou une reprise se voit à la semaine près ; les vignettes donnent le réseau | plus de bruit (fériés, météo) ; plus lourd à lire au premier regard |

`a-halle.png` : la maquette A sur **Halle**, ouvert en février 2026 — le cas « pas encore de
N-1 » : pas de ligne pointillée, la légende dit quand elle apparaîtra (février 2027), les tuiles
« vs N-1 » se replient sur « les 3 derniers mois vs les 3 d'avant ».

Les trois maquettes portent les mêmes réglages en tête : **Mois / Semaine** (A et B en mois,
C en semaine) et **Total / Par jour ouvert** (dessiné, pas calculé — voir plus bas).

## Les règles qu'elles posent

- **Le N-1, seulement quand il existe** : même mois un an plus tôt, même semaine décalée de 364
  jours (un lundi tombe sur un lundi) — les règles de `ventes_semaines.php`. La ligne commence au
  premier mois qui a son N-1, jamais à zéro.
- **Le mois d'ouverture est incomplet** (moins de 75 % des jours vendus) : hors courbe, un cercle
  vide avec ses jours (Corbais, juillet 2024 : 145 clients en 4 jours). Un N-1 qui tombe sur ce
  mois-là n'est pas tracé : août 2025 est le premier écart de Corbais, pas juillet.
- **Le mois en cours ne va pas sur la courbe** : 11 jours face à un mois plein, c'est une chute
  qui n'existe pas. Il a sa tuile, comparé aux **mêmes jours** du N-1 (Corbais, novembre à ce
  jour : 3 357 clients contre 3 007, +12 %).
- **Les écarts année par année sont à périmètre comparable** : seuls les mois qui ont un N-1
  comptent (2025 de Corbais : 5 mois, août → décembre).
- **Par jour ouvert** (à décider) : diviser par les jours vendus neutralise les fermetures. Exemple
  réel : Gosselies, juillet 2026 face à juillet 2025 (24 jours ouverts) : **+48 %** en total,
  **+14 %** par jour ouvert. En total, l'écart est marqué d'un astérisque quand le N-1 a eu des
  jours fermés.
- Semaine : les tickets d'essai d'avant l'ouverture (Corbais : 1 client le 15/07/24) sont hors
  série ; une semaine de moins de 5 jours est dessinée en clair.

## Ce que disent les chiffres (Corbais, lus le 11/11/2026)

| | |
|---|---|
| Depuis | juillet 2024 — 27 mois pleins + novembre en cours |
| Clients depuis l'ouverture | 243 886 |
| CA depuis l'ouverture | 4,18 M€ |
| 12 derniers mois pleins vs N-1 | clients **−4 %**, CA **+1 %**, panier 17,75 € contre 16,84 € (**+5 %**) |
| 2026 à date vs 2025 | clients **−3 %**, CA **+3 %** |
| Mois au-dessus du N-1 | clients : 1 / 15 (octobre 2026, +10 %) · CA : 8 / 15 |
| 4 dernières semaines vs N-1 | clients **+10 %**, CA **+4 %** |

Moins de clients, un panier plus haut, et une reprise du trafic depuis octobre : c'est ce que les
trois maquettes doivent faire voir, chacune à sa façon.

Les autres magasins : **Gosselies** depuis janvier 2025 (le panel n'a rien avant), N-1 depuis
janvier 2026 ; **Sombreffe** ouvert en avril 2025, N-1 depuis mai 2026 ; **Halle** ouvert en
février 2026, pas encore de N-1.

## Fichiers

| Fichier | Ce que c'est |
|---|---|
| `a.png`, `b.png`, `c.png` | les trois maquettes, Corbais |
| `a-halle.png` | la maquette A sur Halle, sans N-1 |
| `a.html`, `a-halle.html`, `b.html`, `c.html` | les pages, générées |
| `donnees.json` | les données réelles, lues au panel le 11/11/2026 : mois et semaines par magasin, avec le N-1 |
| `chrome.html` | l'écran Résultat du BO rendu tel quel (rail, en-tête, onglets), l'onglet « Évolution magasin » ajouté, le contenu à remplacer |
| `generer.js`, `capturer.js` | la génération des pages et leurs captures |

Données : `/ventes/reseau?vue=annee` pour 2024, 2025 et 2026 (mois), `/ventes/semaines?vue=semaine&n=12`
par tranches de douze semaines (semaines), sur l'API en ligne. Régénérer :

```bash
node docs/maquettes/evolution-magasin/generer.js
```

```bash
npx http-server . -p 8099 -c-1
```

```bash
node docs/maquettes/evolution-magasin/capturer.js
```

## Pour le coder ensuite

Un onglet de plus dans `valsResultatOnglets` (`['evolution', 'Évolution magasin']`) et un
template `tplResultatEvolution`, sur le modèle d'« Analyse réseau ». Côté serveur, un endpoint
`GET /ventes/evolution?shop=…&vue=mois|semaine` qui rend toutes les périodes depuis la première
vente avec leur N-1 — les fonctions de `ventes_semaines.php` (`vsLireMagasin`, `vsMagasin`) le
font déjà par tranches de 12 ; les mois clos du panel sont déjà gravés, seules les semaines
demandent une lecture en tranches gardée en cache.
