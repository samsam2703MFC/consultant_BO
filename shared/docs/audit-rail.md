# Audit des vues du cockpit et réorganisation du rail (19/11/2026)

Demande de Sam : « beaucoup de vues se ressemblent, on fait double emploi — audit complet, réorganiser le rail et
les pages ; rester lean, simple. »

Méthode : chaque écran du rail, chaque écran hors rail et chaque page autonome de `public/` lus dans le code (la
fonction `vals…` de l'écran, son gabarit, les routes qu'il appelle et ce qu'elles lisent côté PHP). Un doublon n'est
retenu que si le code le montre (même route, même calcul, même table, même formulaire). Les chiffres d'usage réels
(Journal & diagnostic › Usage de la console) n'ont pas pu être lus : ils sont derrière la connexion du siège.

Capture avant / après : `shared/docs/design-system/captures/rail-avant-apres-2026-11-19.png`.

## 1. Le constat

| | Avant | Après |
|---|---|---|
| Entrées du rail | 63 | 34 |
| Sections | 11 | 8 |
| Sous-menus | 5 (Suivi des franchisés, Budget, Suivi journalier, MétaDonnée, API du module) | 0 |
| Écrans retirés du code | — | 0 : toutes les adresses ouvrent toujours un écran |

« Avant » est le rail en ligne le 19/11/2026 à 15:02 (branche de déploiement), Fil du franchisé, Entraînement de
l'IA et Catalogue des manuels compris, Dashboard magasin ↗ déjà retiré.

Le rail avait poussé module par module. Une même question avait plusieurs entrées (Budget en trois, MétaDonnée
en quatre, API du module en six), un même écran était à deux endroits (Réputation : entrée de Suivi journalier et
onglet d'Analyse magasin), et des écrans qui calculent la même chose vivaient dans des sections différentes.

## 2. Les doublons confirmés, et ce qui a été fait

| # | Écrans | Ce qui est identique (preuve) | Fait le 19/11 |
|---|---|---|---|
| 1 | Audit des flux, Fuites par magasin, Évolution | Même collecte et même lecture : `vxCollecte`, `vxAgregeJour`, `vxLecture` (`src/verification_flux.php:109-319`) ; Évolution relit ce calcul enregistré chaque jour (`con_verif_jour`) | Une entrée **Flux & poubelle**, trois onglets |
| 2 | Invendus et poubelle, poubelle des flux | Même source : `invLignes` → `/products/waste` (`src/production_flux.php:973`) ; Invendus valorise au coût de recette, les flux au prix de vente | Invendus devient le 4e onglet de **Flux & poubelle** |
| 3 | Entre deux inventaires, Matières ↔ recettes | Même moteur `invEcart` / `invListe` (`src/verification_matieres.php:6, 74, 111`) | Une entrée **Matières & inventaires** (avec Mouvements d'un produit, qui en est le détail) |
| 4 | Client mystère (Paramètres) et Client mystère (Suivi des franchisés) | Même table `con_scoring_msp` ; le formulaire de Paramètres a d'autres rubriques et n'écrit ni la date du passage ni les suites (`app.js` `sqMsp…`, `src/suivi.php:1655`) | Un seul formulaire : **Visites › Client mystère**. `#/parametres/mystere` et `#/client-mystere` y mènent ; le bouton « encoder » de la fiche aussi, le magasin choisi |
| 5 | Note Google et avis, Visibilité Google | Visibilité lit les magasins et la fiche Google de Réputation (`src/visibilite_google.php:80`) ; Réputation était à deux endroits du rail | Une entrée **Présence Google**, onglets Avis et Visibilité |
| 6 | Contrôle des tâches, Contrôles qualité du mois | Même route du panel `/consultant/shops/{id}/tasks`, même modale, même écriture `POST /pwa/tasks/review` | Une entrée **Tâches & contrôles**, cinq onglets (le jour, le mois, faites / pas faites, notes et signalements, paramétrage des checklists) |
| 7 | Budget : Suivi, Encodage, Paramètres | Encodage et Paramètres partagent `valsEncodage` et le même magasin choisi | Une entrée **Budget**, trois onglets |
| 8 | MétaDonnée 1 à 4 | Un seul drapeau `isMD`, un seul gabarit ; le module affiche lui-même ses quatre étapes | Une entrée **MétaDonnée** |
| 9 | API du module ×6 | Un seul appel `/ventes/primes-api` ; les sous-entrées ne posaient qu'un filtre que les tuiles de l'écran font déjà | Un onglet de **Journal & diagnostic** |
| 10 | Diagnostic API, API du module, Sources et API | Trois écrans de santé des API (tout le cockpit, module primes, visites) dans trois sections | Réunis en onglets de **Journal & diagnostic** |
| 11 | Primes : page admin, page franchisé | Un seul écran, deux entrées qui ne changeaient que `prSection` | Une entrée **Primes** ; la page d'un magasin s'ouvre de son onglet (elle n'affiche rien de la marque, pas même un onglet) |
| 12 | Module Formation ↗, Manuel du réseau ↗ | Deux liens vers la même application | Une entrée **Formation ↗** (le Catalogue des manuels ↗, une page à part, garde la sienne) |
| 13 | Visite sur tablette | Iframe de `controle/` ; chaque visite de l'écran Visites donne déjà son lien et son QR code | Retirée du rail, adresse gardée |

Corrections au passage :

- Le groupe « Tâches » disait « la même checklist à trois moments » : faux, l'onglet Suivi lit les notes et
  signalements des consultants (`con_project_task`, `con_task_issue`), pas les checklists du panel. Il s'appelle
  désormais **Notes & signalements**.
- Les tuiles de la fiche franchisé « Contrôle des tâches » menaient à cet onglet Suivi : elles mènent au contrôle du
  jour ; « Tâches faites · 30 j » mène à **Faites / pas faites**.
- Fil du franchisé devient un onglet de **Visites**, Entraînement de l'IA un onglet de **Tâches & contrôles** (il
  l'était déjà du groupe Tâches).
- Benchmark (prix des concurrents) passe de Pilotage à **Produits** ; Cadre des tâches devient l'onglet
  **Paramétrage des checklists** de Tâches & contrôles ; Reporting automatisé passe en **Administration** ;
  Objectifs produits (tables de campagne) passe en **Marque & marketing**.
- Les pages qui s'ouvrent hors du cockpit (↗) sont rangées ensemble, en bas : **Applications**.

## 3. Le rail

| Section | Entrées (onglets) |
|---|---|
| Pilotage | Résultat · Demandes des franchisés · Tâches & contrôles (Le jour, Contrôles photo du mois, Faites / pas faites, Notes & signalements, Entraînement de l'IA, Paramétrage des checklists) |
| Franchisés | Fiche & scoring · Visites (Visites, Fil du franchisé, Client mystère, Page du franchisé, Qui fait quoi) · Budget (Suivi, Encodage, Paramètres) · Équipe & ventes (Équipe & ventes, Panier · croisements) · Audit fournisseurs |
| Vérification | Flux & poubelle (Audit des flux, Fuites par magasin, Évolution, Invendus) · Matières & inventaires (Matières ↔ recettes, Entre deux inventaires, Mouvements d'un produit) · Recettes à corriger · Chiffre d'affaires |
| Produits | Catalogue · Comptoir (Comptoir, Assortiment obligatoire, Structure de gamme) · Paramétrage produits · Benchmark · Promotions (Tableau d'analyse, Édition) |
| Marque & marketing | Fonds & Royalties · Présence Google (Avis, Visibilité) · Objectifs produits · MétaDonnée · Fidélité |
| Outils magasin | Tablette vendeuses · Assistant · coachs · Primes (Marque & sponsors, Page d'un magasin) · Pointages |
| Administration | Paramètres (Général, Scoring produits, Types de campagne, Table KPI, Brand Guard) · Reporting automatisé · Journal & diagnostic (Journal, Usage de la console, Diagnostic API, API du module primes, Sources des visites) |
| Applications | Caisse ↗ · Prospection ↗ · Application employé ↗ · Formation ↗ · Catalogue des manuels ↗ |

Une entrée de groupe porte la somme des compteurs de ses onglets (Visites : les rapports à adapter, les corrections
et les fils ouverts), et chaque onglet garde le sien. Dashboard magasin ↗ reste hors du rail (retiré le 19/11/2026,
demande de Sam) : Résultat l'ouvre magasin par magasin.

**Ce qui ne change pas** : aucune adresse (`#/…`) ne change, aucune route ni table du serveur, aucun fichier du
dashboard magasin (`panel/public/dashboard/`), aucune page autonome. La recherche du rail trouve toujours chaque écran,
onglets compris. La mesure d'usage range chaque onglet dans la famille de son entrée.

Code : `navDef` et `GROUPES` (`shared/public/assets/js/app.js`), icônes `ICO_ECRAN` (`shared/public/assets/js/templates.js`).

## 4. Ce qui reste à décider (non fait : touche aux données ou au métier)

Par ordre de valeur :

1. **Le produit a deux sources de vérité.** Paramétrage produits écrit au panel l'obligatoire, le prix et la
   durée de vie ; la fiche de production du Catalogue écrit obligatoire, minimum, prix, coût matière et DLV dans
   `con_prod_product` (`src/writes.php:1336`), jamais renvoyés au panel. L'obligatoire s'écrit par quatre gestes
   (Catalogue, Assortiment, Structure `/appliquer`, Paramétrage). Proposition : un seul endroit, le panel ;
   l'obligatoire seulement dans Assortiment.
2. **Deux calculs de l'assortiment proposé** : le PDF 20/80 du Catalogue (6 classiques + 2 par saison,
   `src/analyse_produits.php:1390`) et Structure de gamme (6 + 4 par saison, sortie sous 3 %,
   `structure-regles.js`). Proposition : Structure calcule, le PDF la lit.
3. **Les seuils du scoring produits se règlent à deux endroits** (barre du Catalogue et Paramètres › Scoring
   produits, même `PUT /parametres/scoring`). Proposition : Paramètres ne garde que la pondération.
4. **Deux pourcentages pour la même semaine d'inventaire** : la justesse (Entre deux inventaires, matières
   « fiables » seulement) et l'explication (Matières ↔ recettes, toutes les matières chiffrées). Proposition : un
   seul indicateur, l'onglet Entre deux inventaires gardé pour la paire au choix.
5. **Résultat a huit onglets** ; Trafic clients et Évolution magasin partagent le moteur `vsPanelJours`
   (`src/ventes_semaines.php:195`), Marge & coûts reprend les ratios du Mois. Proposition : fusionner Trafic
   clients et Évolution magasin.
6. **Le % de tâches faites a trois formules** sur `con_tache_jour` (`src/scoring.php:223`, `src/franchises.php:33`,
   la heatmap), et les tâches des consultants deux stocks (`con_project_task`, `con_consultant_tache`).
7. **La validation des corrections existe deux fois** : Visites (`PUT /plans`) et `visites/?role=admin`.
8. **Les promotions du panel sont lues deux fois** (`pvRegles` et `primesPromosPanel`, deux caches).
9. **Deux programmes de fidélité** : l'écran Fidélité (`marketing/src/fidelite.php`) et `marketing/public/fidelite-test/`
   (`marketing/src/fidelite_bingo.php`), qui définissent tous deux `ep_fidelite`. À trancher.
10. **Les portails** (`docs/PORTAILS.md`) : Fonds, Types de campagne, Brand Guard, MétaDonnée, Fidélité et
    Objectifs produits relèvent du Marketing ; Tablette vendeuses et Formation des Formations. Le cockpit écrit
    encore des tables `mar_` (`marketing/src/objectifs_produits.php`, synchro de la réputation dans `src/writes.php:3281`,
    bouton de nettoyage du Diagnostic) : la règle du dépôt l'interdit.
11. **Mesure à vide** : Flux, Fuites et Évolution donnent un écart au plan, pas une mesure, tant qu'aucun magasin
    ne valide ses cuissons ni n'enregistre sa clôture (`src/verification_flux.php:16`) — et la Production est hors
    du rail.

## 5. Code mort repéré (à retirer dans un second temps)

- Gabarits et titres de `magasins`, `objectifs`, `marge` (redirigés vers les onglets de Résultat), de `produits`,
  `seuil`, `usage` (redirigés vers le Catalogue) ; routes `/produits/utilisation*` sans appelant.
- `tplScoringTri` (l'écran est réécrit en Fiche & scoring avant le rendu) ; la vue « Client mystère » de
  `valsScoring` et l'écran `clientMystere` (désormais réécrit en Visites › Client mystère).
- `panel/public/dashboard/mq_reseau.html` (maquette aux chiffres en dur, liée de nulle part) ; le lien
  `dashboard/suivi.html` de la fiche (une simple redirection).
- `?app=consultant` (fiche en plein écran, manifeste sur une ancienne adresse) ; le rôle franchisé de
  `visites/?shop=` (inaccessible : la garde renvoie le franchisé à son dashboard).
- La recherche du rail lit l'ancienne table de démonstration `con_report_schedule` ; le sous-titre de Reporting
  parle encore d'« alertes push ».
- Pages orphelines : `marketing/public/newsletter/`, `marketing/public/fidelite-test/` ; le dossier `marketing/public/assistant/assets/` n'est
  chargé par rien ; l'édition « emplacement » de la fiche produit appelle `PUT /production/planogramme/`, qui
  n'existe pas (rien ne l'ouvre).
- Noms trompeurs : « cross » (Bats ton record, lignes par ticket) et « croisements » (achats associés).
