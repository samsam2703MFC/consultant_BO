# L'app employés (« App worker »)

L'application mobile des employés du réseau (TFB-Employee, livrée en zip le 06/10/2026), servie par
le cockpit à l'adresse `/consulant_bo/employee/`, et liée depuis le rail du cockpit, menu **Application
employé** (avec la page admin et la page franchisé des primes) › **Application ↗**, vers sa page de connexion. Horaire, primes, profil ; les tâches à faire (avec
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

## La prime B2B (23/10/2026) : ramène un client pro

Demande : « dans le système franchisé ou marque, la possibilité de créer une prime pour le B2B, pour donner envie
d'aller chercher les écoles… ; la rémunération en % de la commande, une fois ou chaque mois pendant 1 mois, un
trimestre, un an ». Sept maquettes (`docs/maquettes/primes-b2b/`), l'option D retenue : une phrase. Règles décidées :
le % sur le **HT**, un nouveau client = **aucune commande pro depuis 12 mois**, la prime va à **la vendeuse de la 1re
commande**.

- **Le cockpit** (Application employé › Page admin ou Page franchisé, « + Prime B2B ») : « Pour chaque nouveau
  client pro, la vendeuse touche *5 %* de *chaque commande pendant 3 mois*. » — les mots en couleur se touchent
  (le % ou un montant fixe, la 1re commande, 1, 3, 12 mois ou sans limite), un exemple chiffré dessous ; plafond par
  client, commande minimum, nom et dates dans « plus de réglages ». Une prime de franchisé est pour un magasin, payée
  par lui ; une de la marque, pour tous, payée par la marque ou un sponsor. Trois au plus de chaque côté.
- **Le calcul** (`src/primes_b2b.php`, `b2bCalcul` sans base, testé) : les tickets pro de la base partagée
  (`transaction` ⨝ `client.is_b2b`), le client avec une TVA valide (la règle des clients pro du cockpit), le HT =
  TTC − TVA du ticket. Un client compte dès sa 1re commande dans la période de la prime (dès sa création par défaut) ;
  sa fenêtre court la durée choisie ; le plafond vaut pour toute la durée. Gardé quinze minutes (un jour, un mois
  clos). La prime s'ajoute à la fiche (`briques.b2b`, une carte `type: b2b`), au total du mois, à l'enregistrement
  des primes (payée par le magasin ou la marque) et au tableau « Primes du mois » (colonne Clients pro).
- **L'app** (`primes/_ligne_b2b.twig`) : la ligne dit ce qu'elle a gagné et combien de clients pro elle a ramenés, la
  règle en court (« 5 % · 3 mois ») ; dans « À aller chercher », la phrase du plan (« Un nouveau client pro — une
  école, une entreprise, une association : 5 % de chaque commande pendant 3 mois pour toi. Déjà +52 € ce mois-ci. ») ;
  le détail liste ses clients sans leur nom (« Client du 12/10 · 1 commande ce mois · 420 € HT · jusqu'au 12/01 »).
  Un mois à venir montre la règle. Textes dans les cinq langues.

Le tableau du cockpit (`#/primes-app-worker`, demande : « un beau tableau, simple, minimaliste type Apple ») : des
listes groupées façon Réglages d'iPhone — Targets, Catégories, Promos, Clients pro · B2B —, une ligne par prime
(l'interrupteur, le nom, ce qui compte en gris, la récompense et qui paie à droite) ; toucher la ligne la modifie.

## L'onglet Primes (23/10/2026) : combien, comment y arriver, où tu en es — en forme sobre, puis en une seule liste

Deux demandes : « tourner l'application vers la motivation de ce que tu peux gagner, comment y arriver, où tu en
es », puis « l'UX doit être plus minimaliste (type Apple) ». De haut en bas, sur `/primes` :

- **Combien** : un grand chiffre, ce qui est encore en jeu ce mois-ci (`enJeu − acquis`), la barre de ce qui est
  déjà gagné, les heures de service qui restent. Un mois clos : « En septembre, tu as gagné » ; à venir : « En jeu
  en novembre ».
- **Comment y arriver** : les primes encore à aller chercher, la plus proche d'abord ; chaque ligne dit ce qu'elle
  rapporte, ce qu'il faut faire, et une barre « où tu en es » ; elle mène à la ligne de sa prime. Le plan vient de
  `app/Services/Primes/PrimesPlan.php`, lu dans la fiche du cockpit (les cartes, les briques, la prime du magasin,
  les heures), rien d'inventé : ventes croisées et record sont des moyennes du mois, donc ce qu'il faut viser se
  calcule sur ses tickets à venir, estimés à son rythme (tickets par heure prestée × heures qui restent au
  planning) — « sur tes ~210 prochains tickets, vise 70 % : 7 sur 10 avec un 2e article ». Un cran hors de portée
  (plus de 100 % de ventes croisées, plus d'un article de plus par ticket que sa moyenne) n'est pas promis.
  L'objectif du magasin et la note Google se disent « avec l'équipe » ; une promo sur objectif du magasin porte
  « prime du magasin ».
- **Où tu en es** : les targets et les promos, une ligne par prime (les promos ne sont plus en carrés), qui se
  déroule sur son détail ; puis le classement.

**Tes primes, une seule liste (23/10/2026)** : à la demande (« fusionner Comment y arriver et Où tu en es, ou un
toggle »), trois options montrées en capture — une liste par état, une bascule entre les deux vues, une liste
compacte ; la première est retenue. Sous « Tes primes », trois blocs : **À aller chercher** (le plan, la plus
proche d'abord, son total), chaque ligne avec sa jauge et, dessous, la phrase de ce qu'il faut faire ; **Déjà
gagnées** (leur total) ; **Pas ce mois-ci** (hors de portée, avec la raison, ou rien de compté ; « jusqu'à »). Les
trois totaux font l'« encore en jeu » du haut. Les catégories y reviennent (leur classement reste plus bas). Un
mois clos : « Gagnées », « Pas gagnées ». La ligne d'une prime vit dans `primes/_ligne.twig` (ses macros dans
`primes/_macros.twig`) ; la phrase vient de `components/primes_etape.twig` en mode `texte`. L'Accueil garde son
« Comment y arriver » (trois lignes), qui mène à la ligne de la prime (`/primes#prime-<clé>`).

**Bats ton record, en trois étapes (23/10/2026)** : à la demande (« montrer le nombre de tickets manquants et à
quelle étape elle se trouve »), maquette validée en version courte. Poser son record (un mois d'au moins 30
tickets : sa moyenne de lignes par ticket devient le record), le battre (record + 0,2 : le 1er dixième pose le
nouveau record, le 2e paie), gagner (un montant par dixième, jusqu'au plafond). Sans record, la ligne compte les
tickets du mois jusqu'au repère « ton record » (« Étape 1/3 · encore 12 tickets ») et le plan dit « ta moyenne du
mois deviendra ton record, à battre dès novembre » (`PrimesPlan` : `pose`, hors de portée ce mois-ci). Le détail
montre les trois étapes en une rangée (où elle en est, une valeur sous chacune) et une phrase : les seuils de
chaque dixième, ou ce qu'il manque pour le suivant. Le cockpit donne ses tickets du mois dans la brique du record
(`tickets`, `src/primes.php`). Le haut du détail d'une ligne tient en une carte : « à gagner » et « offerte par ».

La forme (`public/employee/assets/atelier/primes.css`, la dernière couche) : la police du système (SF sur
iPhone) à la place de Gotham et Vank sur ces écrans, un fond clair, des listes groupées blanches sans bordure ni
ombre, le rubis seul en accent et le vert pour ce qui est gagné, des icônes au trait (Bootstrap Icons) à la place
des émojis — une icône déposée par l'admin reste. L'Accueil reprend le même « encore en jeu » et les trois
premières lignes du plan (`components/primes_etape.twig`, ses textes dans `components.json`). Les textes sont dans
les cinq langues.

Dans le cockpit, le rail range le module sous ERP franchisé › **Primes · module app worker** : Paramètres primes
(la marque, les sponsors, les promotions, les moyennes), Paramètres primes franchisé (les primes que chaque
franchisé pose pour son magasin), et Mes primes dans l'app ↗.

## L'onglet Primes (16/10/2026) : des carrés deux par ligne, une demi-jauge, le classement dans un menu qui monte du bas

En tête de page, **le sélecteur de mois** (16/10/2026, à la place de « Halle · octobre 2026, au 16 ») : centré, une
flèche à gauche (le mois d'avant, douze mois en arrière au plus), le mois au milieu (« en cours · au 16 » pour le
mois en cours), une flèche à droite (jusqu'à trois mois à venir). Les flèches sont des liens
`/primes?m=AAAA-MM` ; un mois clos dit « il manquait » au lieu de « encore ». **Un mois à venir** (16/10/2026)
montre les primes prévues — « à venir » sous le mois, le total en jeu, puis chaque target et chaque promo de ce
mois dans son magasin avec sa récompense, sa règle (la 1re du magasin, dès N pcs, objectif du magasin, ton
objectif), son objectif et ses dates ; rien de compté, pas de détail ni de classement. **La fiche d'une prime** (16/10/2026) : toucher
un carré de promo (ou une ligne d'un mois à venir) ouvre une feuille — ce qu'on gagne (de l'argent, ou un cadeau 🎁 avec
sa valeur), la ligne de description, qui l'offre (la marque, le magasin, ou le sponsor avec son logo, une ligne et son
site), qui gagne, la période et « Voir le classement ». Le détail d'une ligne de target montre aussi « À gagner » et
« Offerte par ». Sous la description réglée au cockpit, une indication calculée (17/10/2026) : « Il manque N avis 5★
pour atteindre 4,5 ★ » (Note Google), « Encore N ventes croisées pour atteindre 55 % » (ventes croisées), « Le
magasin doit encore faire N € de chiffre ce mois » (objectif magasin). La ligne « Tes heures » n'est plus dans le
détail de l'objectif ni de Google (17/10/2026) : le minimum d'heures est une condition qu'on allume ou coupe au cockpit ;
quand elle joue et qu'on est en dessous, la ligne dit seulement « sous 20 h ».

Refait le 16/10/2026 sur la base du 14/10 (ci-dessous) :

- **trois sections** : les **Targets** (ventes croisées, Bats ton record, objectif magasin, note Google, et la
  meilleure vendeuse avec son étiquette « Classement »), les **Catégories**, les **Promos** ; chacune dit ce
  qu'elle peut rapporter (« jusqu'à 500 € ») ;
- les **targets** (ventes croisées, record, objectif du magasin, note Google, meilleure vendeuse) sont **une par
  ligne**, en couleur d'office, en « drapeau » (16/10/2026, maquette B de `propositions-targets.html`) : la valeur
  en haut à droite ; une barre avec **le trait noir de l'objectif**, toujours au même endroit (75 %), et la
  récompense posée dessus (« 50 € », sans cadenas depuis le 16/10/2026) ; **passer le trait active la prime** (la barre, la valeur et
  le drapeau passent en vert, « ✓ Prime activée »). Dessous : ce qu'il reste (« encore 0,26 ») et l'objectif ;
  pour l'objectif du magasin, la projection et un pointillé où en être au jour J. **Toucher la ligne déroule
  son détail** (une seule ouverte à la fois, clavier : Entrée ou Espace) : ses chiffres (son taux et ses tickets,
  aujourd'hui ; ses lignes par ticket, son record et son mois ; le CA du magasin, le jour J, la fin de mois au
  rythme actuel, chaque palier, ses heures ; la note et les avis ; son score, sa place, la 1re, son CA par heure)
  et la règle, plus « Voir le classement » quand il y en a un ;
- les **catégories** : leur section est **retirée de la page** depuis le 16/10/2026 (à la demande) ; leurs
  classements restent dans le **Classement** en bas de page (le menu qui monte du bas). Le code des lignes reste
  prêt (l'entrée `categories` des `groupes` de primes.twig, en commentaire). Quand elle était affichée, chacune était
  **une par ligne, en drapeau** (16/10/2026, maquette A de
  `propositions-categories-barre.html`) : ses pièces en haut à droite ; le trait, c'est ce qu'il lui faut pour être
  1re (la 1re + 1 pièce, le minimum compris) — ou, quand elle mène, la 2e à garder derrière ; dessous sa place et ce
  qui lui manque (« 2e / 6 · encore 10 pcs pour être 1re », « 👑 1re / 4 · +3 pcs d'avance ») et la 1re (ou la 2e).
  Le détail déroulé montre le podium du magasin, le minimum de pièces et « Voir le classement » ;
- les promos sont des **carrés**, deux par ligne : l'icône, la récompense, le nom, une **demi-jauge**, l'objectif
  en clair, l'état. Le point rubis sur la jauge, c'est **elle aujourd'hui** (la valeur au centre, avec « Toi »,
  « magasin » ou « J16 ») ; le repère sombre, c'est **l'objectif à atteindre**, écrit dessous (« ◉ Objectif
  55 % »). La cible pour un target, le seuil pour la note Google, **52 pcs** pour passer la 1re d'une promo
  (la sienne + 1), « Garder la 1re place » quand elle mène. Les catégories sont passées en lignes (ci-dessus). Pour l'objectif du magasin, la jauge montre
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

## Statistiques (16/10/2026) : la page existe, hors du menu

Retirée de la barre du bas le 16/10/2026 à la demande, avec l'Accueil et le Planning : la barre n'a plus que
**Primes** et **Profil**. Les pages restent joignables (`/statistiques`, `/dashboard`, `/schedule`) et la connexion
ouvre toujours l'Accueil. Pour remettre un onglet : sa ligne dans `components/bottom_nav.twig`
(`{ key: 'stats', label: translations.statistics, icon: 'bi-bar-chart-line', href: ROOT ~ '/statistiques' }`,
`dashboard` → `bi-house` `/dashboard`, `schedule` → `bi-calendar3` `/schedule`).

`/statistiques` (`StatsController`, `Views/stats/statistiques.twig`, styles `assets/atelier/stats.css`, textes
`translations/page/{fr,en,it,nl,pl}/stats.json`) : **ses chiffres à elle, rien que les siens**, depuis la même
fiche que Primes (`GET /ventes/moi` du cockpit, rien n'est calculé dans l'app).

- **Aujourd'hui** : ses tickets, ses lignes par ticket, son taux de ventes croisées face à la cible ;
- **ce mois-ci, au jour J** : d'abord le geste de vente, en avant — son **ticket moyen** et ses **lignes par
  ticket** (son record en regard) —, puis ses ventes croisées (la cible en regard), son CA par heure (ses
  heures), son chiffre d'affaires, ses tickets, son score et sa place dans le magasin et dans le réseau ;
- **12 semaines** : trois lignes, un indicateur à la fois, choisi dans une rangée de puces (ticket moyen, lignes
  par ticket avec la ligne de son record, ventes croisées avec la ligne de la cible, CA par heure, tickets) —
  **moi** (rubis, épaisse, avec ses points), **mon magasin** (ambre) et **réseau** (bleu, en tirets) : la vendeuse
  moyenne de la semaine, jamais une collègue. Une semaine sans donnée laisse un trou. Au-dessus, les trois valeurs
  de la semaine touchée (la dernière au départ). Couleurs : `--la-serie-moi/-mag/-res` (marque.css), vérifiées
  pour les daltoniens. La page ouvre sur le premier indicateur qui a des données. Les trois séries existent en
  tableau pour les lecteurs d'écran ;
- **mes titres** des six derniers mois.

Sans vente à son nom ce mois-ci, la page ne montre pas une grille de zéros mais un mot (« Pas encore de vente à
ton nom ce mois-ci — tes chiffres s'affichent dès ton premier ticket »), puis les douze semaines : sans aucune
vente à son nom, les moyennes du magasin et du réseau s'affichent seules, avec un mot qui le dit. Les tâches n'ont plus d'onglet : on y arrive par la tuile « tâches à faire » de l'Accueil (les pages des
tâches allument « Accueil » dans la barre du bas). Rendu d'essai : `php docs/maquettes/primes-epure/rendu.php`
écrit aussi `rendu-stats.html`.
