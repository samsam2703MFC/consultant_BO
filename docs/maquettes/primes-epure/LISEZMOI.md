# Mes primes, épurées : une carte par prime, le classement dans un menu déroulant

**Statut : codé le 14/10/2026, variante A.** L'app : `apps/employee/src/app/Views/primes/primes.twig` ;
le cockpit : `src/primes.php`, `src/primes_app.php`, Équipe & ventes › Paramètres ; le contrat :
`docs/contrat-api.md`, « Les primes dans l'app worker ». Captures du code, sur un jeu d'essai calculé par
le moteur : `code-app.png` (`php rendu.php` → `rendu-app.html`), `code-menu.png` (le menu du classement
ouvert), `code-stats.png` (`rendu-stats.html`, la page Statistiques), `code-cockpit.png`.

Les choix faits au moment de coder, sans réponse sur les points ouverts :

- **la variante A** : la liste des primes, puis le classement dans un menu déroulant ;
- **une récompense par prime**, 50 € par défaut, pour les ventes croisées (à la cible), l'objectif du magasin
  (chaque personne au planning, au-dessus du minimum d'heures) et chaque catégorie ou promo (la 1re du magasin) ;
  plus de paliers, plus d'euros par heure, plus de couronne du réseau payée ;
- **Bats ton record** et **la meilleure vendeuse** gardent leur système et leurs montants (onglet Targets &
  primes) : l'app les montre comme des cartes, le cockpit comme des lignes « réglé dans Targets & primes ».
  Les passer à une récompense unique reste possible.

Ajouts du 14/10/2026, deuxième demande : **qui paie** chaque prime (la marque, le magasin ou un sponsor
nommé), réglé dans les paramètres et compté dans les Résultats ; les **ventes croisées par catégorie** (un
menu déroulant des catégories du catalogue) ; les primes **pas encore gagnées en gris** dans l'app ; le **SMS
à la réussite** (un interrupteur dans les paramètres), envoyé quand le CEO enregistre les primes du mois,
par Twilio dès que `config.php › sms` est rempli.

Refonte du 16/10/2026 :

- les primes en **carrés deux par ligne**, les **targets séparés des catégories** (et des promos) ;
- dans **chaque jauge**, la vendeuse voit **où elle en est** (le point, la valeur au centre) et **l'objectif à
  atteindre** (le repère, écrit dessous). Pour l'objectif magasin, l'atteinte à date face à 100 %, avec le
  repère du jour J (le prorata des jours) ;
- le **menu déroulant du classement** devient une feuille qui monte du bas (la 1re, mon rang, une coche) ;
- un menu **Statistiques** remplace Tâches dans la barre du bas (les tâches restent sur l'Accueil).

Ajouts du 14/10/2026, troisième demande :

- **la note Google** devient une prime de la liste, un target comme les autres : quand la note de la
  fiche Google du magasin est à **4,5 ou plus**, chaque personne au planning du mois (au-dessus du minimum
  d'heures) gagne **25 €**. Sous 4,5, elle ne gagne rien. Le seuil, le montant, les heures et le payeur se
  règlent dans les paramètres. Le coefficient du 06/10 (× 0,5 à × 1,5) disparaît ;
- **les icônes** : l'admin dépose une icône par prime (PNG, SVG, WebP ou JPEG, 1 Mo au plus) en cliquant
  sur sa tuile dans les paramètres. L'app la montre à la place de l'emoji, dans la carte et dans le menu
  du classement. Sans icône, l'emoji reste. Le rendu d'essai en utilise trois (`demo/uploads/primes` : la
  tarte, la quiche, le café) ;
- **le design system** : les couleurs, rayons et ombres des primes (app et cockpit) sont tous des
  variables. Côté app, ce sont celles de `marque.css` (valeurs du design system : l'ambre des collections
  pour « en tête », l'abricot et le brun pop-up pour les types, la palette packaging pour les avatars).
  Côté cockpit, ce sont celles de `assets/ds/global.css`.

Demande du 14/10/2026, sur `/consulant_bo/employee/primes` : chaque tableau est une prime (un
target, une catégorie de produits ou une promotion) avec sa récompense, réglée par l'admin,
50 € par défaut. Le classement à la Strava se trouve dans un menu déroulant (Queen of Tartes, Queen
of Quiches…) : le rang, les quantités, qui est 1re. Tout texte d'explication est retiré.

## Les trois types de prime

| Type | Exemples | Qui gagne la récompense |
|---|---|---|
| **Target** | ventes croisées ≥ 55 %, bats ton record, objectif magasin ≥ 100 %, note Google ≥ 4,5 | chaque vendeuse qui atteint la cible (objectif et note Google : chaque personne au planning) |
| **Catégorie** | Queen of Tartes, Queen of Quiches (un motif sur le produit) | la 1re du magasin, au nombre de pièces |
| **Promo** | une promotion du panel : un bundle ou un « X achetés + Y offerts » (nom, dates, magasins et produits lus à l'API) | la 1re du magasin, au nombre de bundles vendus ou d'offres données |

Toutes les cartes ont la même forme : l'icône (déposée, sinon l'emoji), nom, type, récompense, puis la mesure, une jauge,
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

## Pour refaire les captures

    npx http-server . -p 8099 -c-1        (depuis la racine du dépôt)
    node docs/maquettes/primes-epure/generer.js && node docs/maquettes/primes-epure/capturer.js
