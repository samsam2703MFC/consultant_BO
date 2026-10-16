# L'app employés (« App worker »)

L'application mobile des employés du réseau (TFB-Employee, livrée en zip le 06/10/2026), servie par
le cockpit à l'adresse `/consulant_bo/employee/`, et liée depuis le rail du cockpit, ERP franchisé ›
**App worker ↗**, vers sa page de connexion. Horaire, statistiques, primes, profil ; les tâches à faire (avec
photo) s'ouvrent depuis l'Accueil.

## Où vit quoi

| Dossier | Contenu |
|---|---|
| `apps/employee/src` | le code PHP de l'app (contrôleurs, services, dépôts, vues Twig, traductions fr/en/nl/it/pl) |
| `apps/employee/config/app.php` | les réglages, réécrits pour cet hébergement (voir plus bas) |
| `apps/employee/vendor` | ses dépendances Composer, allégées et **commises** (le déploiement ne lance pas Composer) |
| `apps/employee/marque/teinter.py` | teint le thème Mazer aux couleurs de la marque |
| `public/employee` | ce que le serveur sert : `index.php`, `.htaccess`, `sw.js`, `manifest.webmanifest`, `assets/` |

Rien de l'app n'est exécutable depuis `assets/` ; les gabarits Twig restent hors du dossier public.

## Ce qui a changé par rapport au zip

- **L'adresse** : l'app supposait vivre à `/employee` à la racine d'un domaine ; `ROOT` se déduit
  maintenant de l'adresse appelée (`EMPLOYEE_BASE_PATH`), le manifeste et le service worker sont
  relatifs à elle.
- **L'API** : celle du panel, la même que le cockpit (`config/config.php` › `panelApi.base`,
  sinon `EMPLOYEE_API_BASE`, sinon `https://atelierby.tfbuddy.com/api/v1`). Les employés se
  connectent avec leurs identifiants du panel (`POST /employees/authenticate`) ; le cockpit ne
  garde aucun mot de passe.
- **Les cookies** de session suivent le schéma (`secure` seulement en https) et le chemin de l'app.
- **Pas de `.env` obligatoire** : chaque réglage a sa valeur par défaut ; un `apps/employee/.env`
  (hors dépôt) ou des variables d'environnement les surchargent (`EMPLOYEE_API_BASE`,
  `EMPLOYEE_DEBUG=1` pour voir les erreurs, `DEFAULT_LANGUAGE`…).
- **La marque** : plus de Nunito ni de bleu Mazer. `assets/atelier/app.css` est le thème Mazer teint
  (rubis `#8D1D2C`, beige `#EAE4DC`, Gotham), `assets/atelier/marque.css` pose les polices de la
  marque (Gotham, Vank, partagées avec le cockpit) et le style des pièces propres à l'app ; les
  icônes Bootstrap restent. Les 86 Mo d'assets Mazer du zip ne sont pas repris : seuls les trois
  CSS utilisés, les icônes, les illustrations d'erreur et les icônes PWA.

## Régénérer le thème teint

```
python3 apps/employee/marque/teinter.py
```

## Tester en local, sans toucher au panel

Un serveur PHP avec un routeur qui rejoue le `.htaccess` (`php -S 127.0.0.1:8098 -t public
router.php`) et une API simulée (`demo` / `demo`) : connexion, accueil, planning, tâches, profil,
déconnexion. Aucune écriture vers le panel.

## L'Accueil (06/10/2026) : la performance de la personne d'abord

`/dashboard` : l'anneau des primes acquises / à portée du mois (un lien vers `/primes`), le compteur
de ventes croisées du jour avec le service du jour (planning) et les tâches à faire, « Mes prochaines
étapes » (les trois paliers les plus proches qui rapportent, calculés dans `DashboardController`
depuis la fiche), « Ma semaine » (place dans le réseau, ventes croisées, CA par heure, pièces du
concours) et le magasin en une ligne, en dernier. Chaque lecture (primes, planning, tâches) est
isolée : si l'une échoue, les autres s'affichent. Dans la dernière heure du service et après, une
carte « Service terminé » met le compteur du jour face à la cible (bravo, ou ce qu'il manque).
Styles dans `primes.css` (`.acc-*`), textes dans `translations/page/{fr,en,it,nl,pl}/dashboard.json`.

Les textes de l'Accueil et des Primes existent dans les cinq langues de l'app. Les noms de mois
viennent de la fonction Twig `mois('AAAA-MM', court)` de `AppExtension`, dans la langue de la
personne (`langue`, un global Twig). Les lignes de détail des mois payés sont écrites en français
par le cockpit : en français l'app les montre telles quelles, dans les autres langues elle recompose
la ligne depuis les montants.

## L'onglet Primes (16/10/2026) : des carrés deux par ligne, une demi-jauge, le classement dans un menu qui monte du bas

Refait le 16/10/2026 sur la base du 14/10 (ci-dessous) :

- **trois sections** : les **Targets** (ventes croisées, Bats ton record, objectif magasin, note Google, et la
  meilleure vendeuse avec son étiquette « Classement »), les **Catégories**, les **Promos** ; chacune dit ce
  qu'elle peut rapporter (« jusqu'à 500 € ») ;
- chaque prime est un **carré**, deux par ligne : l'icône, la récompense, le nom, une **demi-jauge**, l'objectif
  en clair, l'état. Le point rubis sur la jauge, c'est **elle aujourd'hui** (la valeur au centre, avec « Toi »,
  « magasin » ou « J16 ») ; le repère sombre, c'est **l'objectif à atteindre**, écrit dessous (« ◉ Objectif
  55 % »). La cible pour un target, le seuil pour la note Google, **52 pcs** pour passer la 1re d'une catégorie
  (la sienne + 1), « Garder la 1re place » quand elle mène. Pour l'objectif du magasin, la jauge montre
  **l'atteinte à date** (le CA encaissé ÷ l'objectif du mois), le repère 100 %, et un pointillé **où il faudrait
  en être au jour J** (le prorata des jours) ; la pastille donne la projection (« ✓ proj. 108 % ») ;
- le **classement** : un bouton (l'icône, le nom, mon rang « 2e / 6 ») ouvre une **feuille qui monte du bas** :
  chaque classement avec son icône, son type, la 1re et sa quantité, mon rang, une coche sur celui qu'on
  regarde. Clavier : flèches, Entrée, Échap. La bascule Mon magasin / Réseau est un sélecteur segmenté.

## L'onglet Primes (14/10/2026) : une carte par prime, le classement dans un menu déroulant

`/primes` (variante A de `docs/maquettes/primes-epure/`) : rien que des primes, sans texte d'explication.
En haut, **acquis** et **en jeu**. Puis une carte par prime, dans l'ordre du réglage du cockpit : l'icône
déposée par l'admin dans le cockpit (sinon l'emoji), le nom, le type (**Target**, **Catégorie**, **Promo** ;
Classement pour la meilleure vendeuse), la
récompense (« +50 € » une fois gagnée), la mesure et sa jauge (avec le repère de la cible), une pastille
(✓ gagné, 👑 1re, 2e / 6, −2 pt) et une ligne de chiffres (la cible, ou la 1re et sa quantité). Une prime
gagnée est verte, en tête dorée, **pas encore gagnée grise**. Les ventes croisées portent leur catégorie
quand le cockpit en a choisi une (« Ventes croisées · Boissons chaudes »). La **note Google** du magasin a
sa carte : la note face au seuil (4,6 ★, cible 4,5 ★), +25 € pour chacune au planning quand elle tient le
seuil. En bas,
**Classement** : un menu déroulant (un vrai `<select>`, la roue native du téléphone) choisit la prime
(Queen of Tartes, Queen of Quiches, la promo, les ventes croisées), une bascule Mon magasin / Réseau, le
podium (la 1re couronnée) puis les rangs suivants, moi en évidence. Toucher une carte qui a un classement
ouvre le sien. Le compteur du jour, la collection, les semaines et les mois quittent la page (l'Accueil
garde ce qui lui sert).

La page ne calcule rien : `PrimesService` appelle le cockpit, `GET {COCKPIT_API_URL}/ventes/moi?m=`, avec
le jeton de la session ; la fiche porte `cartes` et `classements` (voir `docs/contrat-api.md`, « Les primes
dans l'app worker »). Les noms des primes fixes (ventes croisées, record, objectif magasin, meilleure
vendeuse, note Google) sont traduits par l'app ; les catégories et les promos gardent le nom que l'admin
leur donne. Les icônes sont servies par le cockpit (`public/uploads/primes`) : `PrimesService` ajoute à la
fiche l'adresse du cockpit (`iconesBase`, `COCKPIT_API_URL` sans « /api/… »). Styles :
`public/employee/assets/atelier/primes.css` (fin du fichier), sans aucune couleur en dur : toutes sont des
variables de `marque.css`, aux valeurs du design system (`public/assets/ds/global.css`) ; textes :
`translations/page/{fr,en,it,nl,pl}/primes.json`. Un rendu sur un jeu d'essai, calculé par le moteur du
cockpit et rendu par cette vue : `php docs/maquettes/primes-epure/rendu.php [fr|en|…]`.

## Statistiques (16/10/2026) : à la place de Tâches dans la barre du bas

`/statistiques` (`StatsController`, `Views/stats/statistiques.twig`, styles `assets/atelier/stats.css`, textes
`translations/page/{fr,en,it,nl,pl}/stats.json`) : **ses chiffres à elle, rien que les siens**, depuis la même
fiche que Primes (`GET /ventes/moi` du cockpit, rien n'est calculé dans l'app).

- **Aujourd'hui** : ses tickets, ses lignes par ticket, son taux de ventes croisées face à la cible ;
- **ce mois-ci, au jour J** : d'abord le geste de vente, en avant — son **ticket moyen** et ses **lignes par
  ticket** (son record en regard) —, puis ses ventes croisées (la cible en regard), son CA par heure (ses
  heures), son chiffre d'affaires, ses tickets, son score et sa place dans le magasin et dans le réseau ;
- **12 semaines** : une colonne par semaine, un indicateur à la fois, choisi dans une rangée de puces (ticket
  moyen, lignes par ticket avec la ligne du record, ventes croisées avec la ligne de la cible, CA par heure,
  tickets) ; la semaine en cours en rubis ; toucher une semaine affiche sa valeur. La page ouvre sur le premier
  indicateur qui a des données. La même série existe en tableau pour les lecteurs d'écran ;
- **mes titres** des six derniers mois.

Sans vente à son nom ce mois-ci, la page ne montre pas une grille de zéros mais un mot (« Pas encore de vente à
ton nom ce mois-ci — tes chiffres s'affichent dès ton premier ticket »), puis ses douze semaines si elle a vendu
avant. Les tâches n'ont plus d'onglet : on y arrive par la tuile « tâches à faire » de l'Accueil (les pages des
tâches allument « Accueil » dans la barre du bas). Rendu d'essai : `php docs/maquettes/primes-epure/rendu.php`
écrit aussi `rendu-stats.html`.
