# Mes primes, épurées : une carte = une prime, le classement dans un menu déroulant

**Statut : maquettes, à choisir (14/10/2026).** Rien n'est codé : la page en ligne reste
`apps/employee/src/app/Views/primes/primes.twig`.

Demande du 14/10/2026, sur `/consulant_bo/employee/primes` : chaque tableau est une prime (un
target, une catégorie de produits ou une promotion) avec sa récompense, réglée par l'admin,
50 € par défaut. Le classement à la Strava se trouve dans un menu déroulant (Queen of Tartes, Queen
of Quiches…) : le rang, les quantités, qui est 1re. Tout texte d'explication est retiré.

## Les trois types de prime

| Type | Exemples | Qui gagne la récompense |
|---|---|---|
| **Target** | ventes croisées ≥ 55 %, bats ton record, objectif magasin ≥ 100 % | chaque vendeuse qui atteint la cible |
| **Catégorie** | Queen of Tartes, Queen of Quiches (un motif sur le produit) | la 1re du magasin, au nombre de pièces |
| **Promo** | une promotion du panel : un bundle ou un « X achetés + Y offerts » (nom, dates, magasins et produits lus à l'API) | la 1re du magasin, au nombre de bundles vendus ou d'offres données |

Toutes les cartes ont la même forme : emoji, nom, type, récompense, puis la mesure, une jauge,
une pastille d'état (✓ gagné, 👑 1re, 2e / 6, −0,06) et une ligne de chiffres (la cible, ou la 1re
et sa quantité). Le haut de la page affiche seulement l'acquis et le total en jeu.

## Les maquettes

| | forme | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|
| **A** | `a-liste.html`, `a-menu.html` : les primes, puis **Classement** avec le menu déroulant et le podium | tout sur une page, le menu ne liste que les primes classées | le classement est en bas |
| **B** | `b-une-prime.html`, `b-menu.html` : **une prime à la fois** ; le menu en haut choisit la prime, la page affiche son classement, puis les autres primes en une ligne chacune | le classement d'abord, le réseau (10 premières) visible | les autres primes sont réduites à une ligne |
| **C** | `c-deroule.html` : **chaque carte déroule son classement** quand on la touche | pas de menu, le classement reste à côté de sa prime | une seule prime ouverte à la fois, la page s'allonge |
| Cockpit | `cockpit.html` : **Équipe & ventes › Paramètres › Primes de l’app worker** | une ligne par prime : active, type, ce qui compte, la moyenne du réseau, qui gagne, présente dans le classement ou non, récompense (50 € par défaut) ; puis les moyennes par magasin | |

## Les moyennes par magasin, pour régler les seuils (demande du 14/10/2026)

Dans les paramètres, sous les primes, un tableau donne une ligne par magasin et une ligne Réseau,
en moyenne mensuelle sur 1, 3 ou 6 mois. Les colonnes : vendeuses, tickets par mois, tickets par
vendeuse, ticket moyen, lignes par ticket, ventes croisées, puis les pièces par vendeuse pour
chaque catégorie ou promo active (tartes, quiches, la promo depuis son début). La colonne
« Moyenne réseau » de la table des primes reprend la ligne Réseau en face de chaque seuil : le
minimum de 10 tartes se lit contre 96 tartes par vendeuse et par mois.

On règle ainsi les cibles au plus juste. La cible de ventes croisées existe déjà par magasin
(`croiseesReglages()`, `cibles[shop]`) : on la saisit dans la colonne « Cible », un champ en
pointillé vaut la cible par défaut. « Caler chaque cible sur la moyenne du magasin + 3 pts » les
remplit toutes d'un coup (la colonne « Proposée »). « Exporter CSV » sort le tableau.

Les données existent déjà : la moisson de chaque jour et de chaque magasin compte, par vendeuse,
les tickets, les lignes, les ventes croisées et les pièces de chaque famille (`pvLignesJour()`,
`src/panel_ventes.php`). Le CA vient des caches de CA du cockpit. Pour une promo, ses produits
deviennent une famille de la moisson, par identifiant (voir plus bas). La ligne Réseau est pondérée par
les tickets. Dans la maquette, Halle reprend ses chiffres réels d'octobre (≈ 150 tickets par jour,
ticket moyen 12,74 €) ; les autres magasins sont inventés.

## Les promos : lues à l'API promotions et bundles du panel (demande du 14/10/2026)

Une prime Promo ne se saisit plus à la main. « + Promotion », ou « + prime » dans le bloc
**Promotions du panel**, la crée à partir d'une promotion active du panel. Le nom, les dates, les
magasins et les produits viennent de la promotion, et la prime s'arrête avec elle.

| Route du panel | Ce qu'elle donne |
|---|---|
| `GET /admin/promotions/bundles?active=1` | les bundles actifs : nom, prix, dates, magasins, produits |
| `GET /admin/promotions/buy-x-get-y` | les offres « X achetés + Y offerts » |
| `GET /admin/promotions` | la liste de toutes les promotions |

Ce qui compte pour la prime :

- **Bundle** : le nombre de bundles vendus par la vendeuse. Le bundle est une ligne du ticket, un
  produit de la catégorie « Bundle », comme le lit déjà `svBundles()` (`src/stats_ventes.php`).
- **X + Y offert** : le nombre d'offres données. La façon de le lire sur le ticket (ligne offerte
  à 0 €, ou pièces des produits X) reste à fixer quand on aura vu la réponse de l'API.

Dans la moisson (`pvLignesJour()`), une famille se reconnaît aujourd'hui à un motif sur le nom
(« tarte »). Une promo y entre par ses identifiants de produits, lus à l'API.

**Bloquant** : ces routes sont déjà interrogées en lecture seule par `GET /exploitation/bundles/sonde`
(`src/canaux_offres.php`). Le 10/10/2026, le compte API du cockpit y recevait **403**
(`docs/contrat-api.md`). L'admin du panel doit ouvrir la lecture des promotions à ce compte ; il faut
ensuite relancer la sonde pour voir la forme exacte des réponses. En attendant, les bundles restent
lisibles comme produits de la catégorie « Bundle » du catalogue, mais pas leurs dates ni leurs
magasins, et les offres « X + Y » pas du tout.

Captures : `*-tel.png` (premier écran), `*-long.png` (la page entière), `a-menu.png`, `b-menu.png`,
`cockpit.png`, `planche.jpg`. Les prénoms et les chiffres sont inventés (Halle, au 14 octobre).

## Ce qui quitte la page

Le compteur « Aujourd'hui », « Ma collection », les 12 dernières semaines, les ventes mois par mois,
les mois payés et toutes les phrases « comment ça marche ». Les bascules Mon magasin / Réseau
restent ; la bascule mois / semaine disparaît.

## À trancher avant de coder

- **Meilleure vendeuse** (CA par heure) n'entre dans aucun des trois types : elle est absente des maquettes.
- **Objectif magasin** se paie aujourd'hui en euros par heure prestée, par palier ; **Bats ton record**
  100 € par dixième. Dans les maquettes, ce sont des targets à 50 €.
- **La couronne du réseau** des concours (100 € aujourd'hui) n'apparaît plus : une seule récompense par prime.

## Pour refaire les captures

    npx http-server . -p 8099 -c-1        (depuis la racine du dépôt)
    node docs/maquettes/primes-epure/generer.js && node docs/maquettes/primes-epure/capturer.js
