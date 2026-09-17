# Scouting — ce que GeoExplore fait, et ce qu'on pourrait faire

Comparaison de l'écran **Scouting — où ouvrir** (`#/scouting`) avec
**GeoExplore** de GeoConsulting (`geoconsulting.eu/geoexplore`), puis cinq
propositions en maquette.

L'outil de GeoConsulting n'est pas public : la page produit, ses captures et
sa grille tarifaire le sont. C'est de là que vient la lecture ci-dessous —
pas d'un accès à l'application.

## Ce que GeoExplore met en avant

| Ce qu'il annonce | Ce qu'on voit sur sa capture | Chez nous |
| --- | --- | --- |
| « consulter les données à différentes échelles » (projet, quartier, ville, province) | zoom + découpage administratif | provinces et arrondissements seulement |
| « analyses croisées en temps réel » | aplat de couleurs par zone, légende **classée** (`< 5,84 %`, `5,84 – 8,4 %`, …), œil pour éteindre une classe | 4 108 pastilles rouges **de même taille** : la présence, jamais le potentiel |
| barre d'outils de dessin | point, ligne, polygone, rectangle, cercle, mesure, tampon, impression | la zone est un **disque de 4 km**, le même partout |
| « rapports automatisés entièrement paramétrables » | export PDF (tous les packs), Excel (à partir du pack Retail) | un CSV de coordonnées |
| « multi-utilisateurs » (5 à 10 selon le pack) | — | saisies partagées, mais pas d'étude nommée, datée, partagée |
| thèmes : commerces, économie, logements, bureaux, logistique, mobilité, tourisme ; « taux de cellules vides » ; « centralité en Wallonie » | — | boulangeries + population, rien d'autre |

Ce qu'on a et qu'il n'a pas, et qu'il ne faut pas perdre : un **modèle de CA
calé sur nos propres magasins** (dépense par ménage, emprise, passage,
surface), les **notes Google** de la concurrence, et l'assistant
« où puis-je ouvrir ? ».

## Les propositions

| Fichier | Ce qu'elle change |
| --- | --- |
| `a-carte-potentiel.html` | la carte **peint le potentiel** au lieu de pointer la concurrence |
| `b-zone-dessinee.html` | la zone d'étude **se dessine** — isochrone, polygone, cercle libre |
| `c-dossier.html` | l'étude **sort en dossier** daté, partageable, versionné |
| `d-trois-lectures.html` | le même territoire **lu trois fois** — marché, concurrence, synthèse |
| `e-echelle-arrondissement.html` | la même question **posée à l'arrondissement** |

### A — la carte se lit

Une maille d'un kilomètre carré, peinte par les **ménages accessibles en 4 km
divisés par les commerces déjà installés dans ce même rayon**. Quatre classes
aux bornes chiffrées, un œil par classe, les concurrents en couche discrète.
Les six réglages du panneau gauche se replient : ce sont des boutons, pas une
lecture.

Ce que la maquette sort des vraies données : 24 267 mailles peintes,
4 997 772 ménages, **2 252 mailles (9 %) sans un seul concurrent dans 4 km**,
et un classement des meilleures — Sambreville 16 216 ménages par point,
Fleurus 15 652, Aiseau-Presles 12 392.

Une maille verte sans concurrent peut l'être parce que le commerce n'est pas
cartographié : la carte le dit au lieu de le taire.

### B — la zone se dessine

Boîte à outils sur la carte (point, cercle, polygone, rectangle, isochrone,
mesure), et tout se recalcule **dans la zone tracée**. La maquette compare, à
Farciennes, l'isochrone de 10 minutes voiture au disque de 4 km
d'aujourd'hui : 28 158 ménages contre 25 342, soit **954 624 € de marché en
plus** pour la même surface de vente. Le curseur actuel s'appelle d'ailleurs
« approximation de l'isochrone 15–20 min » — autant calculer le vrai.

L'emprise est tenue constante entre les deux colonnes pour isoler l'effet de
la forme ; en vrai elle se recalculerait sur la concurrence de la zone.

### C — le dossier sort tout seul

Ce que l'écran sait déjà calculer, mis en page : une étude datée, avec sa
carte, son marché, sa concurrence, sa comparaison au réseau et **les
hypothèses qui l'ont produite**, en PDF et en Excel, partageable, avec son
historique (« dépense calée sur le réseau, 586 € → 339 € »). Deux rapports de
dates différentes restent comparables.

### D — le même territoire, trois lectures

Le sélecteur de thème en action, sur la même fenêtre (Hainaut, Namur,
Brabant wallon) : le **marché** (ménages accessibles en 4 km), la
**concurrence** (les commerces de ce même rayon), et la **synthèse** — le CA
que le modèle en tire, `ménages × 339 € × emprise ÷ (1 − 15 %)`, l'emprise
descendant avec la pression concurrentielle. Là où le marché est le plus
épais, la concurrence l'est aussi : c'est l'emprise qui tranche.

La pression est calculée avec une force moyenne de 0,62 par concurrent
(faute d'avoir la note de chacun dans la maquette) ; l'écran, lui, la tire de
la note Google.

### E — l'échelle change

La même question posée aux 43 arrondissements : combien de ménages se
partagent un commerce. **Charleroi en compte 2 635 pour un point de vente,
Tournai 440** — un rapport de six. Chaque maille prend la valeur de
l'arrondissement de la commune la plus proche, ce qui redessine le découpage
administratif sans en charger les contours.

Ce que la carte dit du réseau : Gosselies est dans Charleroi (1er des 43),
Halle dans Hal-Vilvorde (2e), Berlo dans Liège (18e). Le réseau est déjà là
où il y a de la place.

## D'où viennent les chiffres

Aucun chiffre n'est inventé.

- **Fond de carte** : OpenStreetMap, désaturé en CSS.
- **Population** : la grille 1 km² du recensement 2021 (StatBel, diffusion
  Eurostat) déjà servie avec l'écran — `public/assets/data/population_grid_2021.json`.
- **Concurrents** : les 5 253 positions du cache OSM du serveur, relu le
  15/09/2026 (`/scouting/tiles/{0..8}`). `donnees.js` n'en garde que les
  coordonnées : **ni nom, ni adresse, ni note**.
- **Modèle** : les paramètres réellement enregistrés sur le serveur
  (`/scouting` → dépense 339 € après calage réseau, passage 15 %, surface
  150 m², emprise maximale 20 %, sensibilité 0,2, ménage 2,31 personnes).
- **Farciennes** : la première ligne de `ceo_zones` telle que l'écran la sort
  aujourd'hui — score 100, 20 382 ménages, 3 concurrents, emprise 17,4 %,
  1 412 087 € de CA estimé, 9 414 €/m².
- **Réseau** : Halle mesurée (28/08/2024) 12 164 ménages, 586 €, 15,5 %,
  1 296 881 € sur 250 m² ; Berlo 13 821 ménages, 550 € ; Max & Sandra
  2 613 ménages, 416 €.

Les surfaces peintes sont calculées **dans la page**, avec la même
arithmétique que l'écran (ménages = population ÷ 2,31, rayon 4 km).

## Ce qui n'a pas besoin d'une maquette

- **Les onglets s'appellent `ceo_zones`, `ceo_concurrents`,
  `ceo_arrondissements`** — des noms de tables dans une interface. Zones
  candidates, Concurrents, Arrondissements.
- **Une échelle de plus** : le secteur statistique (StatBel en publie les
  contours) pour descendre sous la commune, comme GeoExplore le fait.
- **Anticiper**, puisque c'est son mot : permis de bâtir et projets de
  logements par commune, croissance de population 2011 → 2021 (la grille du
  recensement porte les deux millésimes).
- **Le profil des habitants** — âge, revenu médian, taille des ménages. La
  fiche du réseau les affiche déjà pour Halle et Berlo ; ils viennent de
  StatBel par commune et manquent partout ailleurs.
- **Le taux de cellules vides** (cellules commerciales inoccupées) : c'est
  l'indicateur retail de GeoExplore, et il se lit dans OSM
  (`disused:shop`, `vacant`) faute de mieux.

## Refaire les captures

```sh
node docs/maquettes/scouting-v2/generer.js     # écrit les pages HTML
npx http-server . -p 8099 -c-1 --silent        # depuis la racine du dépôt
# puis Playwright sur http://127.0.0.1:8099/docs/maquettes/scouting-v2/<page>.html
```

Les tuiles OpenStreetMap doivent être joignables ; dans l'environnement de
capture elles passent par le proxy, servies à la page par interception.
