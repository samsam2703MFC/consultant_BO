# CLAUDE.md — panel/ (domaine Dashboard / day panel franchisé · tables `panel_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des quatre domaines, clés, migrations), puis `src/LISEZMOI.md`.

- **Périmètre** : ce que voit et fait un magasin au quotidien — le dashboard magasin (`/dashboard`), l'assistant IA et
  le Coach Vente, la boîte mail et les automatisations, la trésorerie et les frais, la revue du soir, la note du jour,
  la tablette des vendeuses (`/tablette`, identité des tablettes installées : ne pas renommer), la production du
  magasin (`/production`), le résultat au téléphone, l'app employés (`/employee`), les primes et les grands prix. Le
  pointage (l'app, `/borne`, la caisse) est retiré le 19/11/2026 ; ses tables `panel_mot_pointage` et `_borne` restent.
- **Tables** : `panel_*` (`panel_aia_*` pour l'assistant, `panel_mot_*` pour l'app employés et les primes,
  `panel_boite_*` pour la boîte mail, `panel_equipe_*` pour Mon équipe : la matrice A B C D, les roulements, les options du magasin, les tâches posées dans les temps morts, les absences (`panel_equipe_absence`) ; `panel_tournee*` pour les tournées de la page Commandes).
- **Code** : `src/` (les anciens `panel/src/*` et les modules propres au dashboard), `panel/apps/employee/` (l'app
  employés, dépendances commises dans `vendor/`), écrans `public/`. L'index de l'API du dashboard :
  `panel/public/dashboard/api-index.json`, régénéré par `python3 panel/tools/dashboard-api-index.py` après chaque route.
- **Accès** : une session de magasin n'ouvre que les préfixes de `ACCES_FRANCHISE` (`shared/src/acces.php`) et son
  propre magasin. Les autres portails : par leur API (`mar_api_url`, `erp_jeton_service` d'`erp_cles`).
- **Crons** : `panel/bin/boite_cron.sh` (10 min), `panel/bin/historique_moisson.php`.
- **Mon équipe** (`src/equipe.php`, `public/dashboard/equipe.js`, 23/11/2026) — **le but premier : aider le franchisé à
  faire ses horaires.** Sam : « le plus important est que ça aide le franchisé à faire ses horaires ». Chaque ajout s'y
  mesure : le planogramme, les linéaires (qui est là, quand ; le comptoir ; les secteurs), les temps morts et le budget
  d'heures (17 %, orange, rouge au-dessus de 20 %, noir au-dessus de 22 %) servent à construire et corriger le planning,
  pas à le commenter. Ce qui n'aide pas à faire les horaires passe après. La fiche d'une personne (`src/equipe_fiche.php`, 24/11/2026) :
  identité, contrat et taux modifiables, évaluations par service, ventes des 12 semaines, compétences, primes. Les modules ajoutés (assistant, tâches des creux, matrice et shift maker, recopie) sont des options, éteintes par défaut (Mon équipe › Options).
  **Les absences** (`src/equipe_absences.php`, 27/11/2026, module principal) : sous contrat de travail, une absence payée (maladie, congé payé,
  circonstance) **garde son service au panel** (son coût reste partout, Résultat compris) et sort de la présence ; le remplaçant aux mêmes heures
  est un vrai service (son coût s'ajoute) ou un extérieur connu du cockpit ; seule l'absence non payée retire le service du panel.
  Une maladie suit le salaire garanti (30 jours, puis la mutuelle ; employé·e ou ouvrier·ère : `panel_equipe_statut`, dans la fiche) ;
  l'app employés montre l'absence à la place du service ; l'Opérationnel, les primes et le consultant (l'équipe de la
  visite, les ventes par heure) ne la comptent pas comme du travail
  (son coût reste au coût du planning). Pas de solde des congés (Sam, 27/11/2026 : « non »).
- **Commandes** (`src/commandes_tournees.php`, `public/dashboard/commandes.js`, 24/11/2026) : les commandes du jour
  (webshop, comptoir, bureau) liées aux tournées et aux bureaux à livrer ; deux vues, Liste et Tournées, sur un
  interrupteur à l'Abricot de la marque (Sam : « A et B on toggle (apricot) »), au jour, à la semaine et au mois
  (« faire une vue semaine et mois (en plus) »). Les commandes se lisent au panel, jamais
  gardées ; les tournées et leurs bureaux n'existent pas au panel : tables `panel_tournee*`.
- **Clients** (`src/clients_panel.php`, `public/dashboard/clients.js`, 25/11/2026) : le module « Klienci » du panel TF
  Buddy (Vendre › Clients : Particuliers, Entreprises B2B, Bureaux, Analyse B2B), lu et écrit dans TF Buddy par trois
  comptes (Sam : « all should work either with admin, consultant or shop token ») : celui du magasin, connecté depuis le
  dashboard (`src/tfb_magasin.php`, `tfb-magasin.js` : téléphone, PIN, SMS ; la session reste dans le navigateur, en-tête
  `X-TFB-Jeton`), le consultant, l'admin. Les routes `/panel/shops/{s}/…` n'ouvrent qu'au compte du magasin ; sans lui, le
  module se rabat sur `/shops/{s}/clients…` et le relevé des tickets pro. Commandes lit aussi ses livraisons avec lui.
  Les offres restent la page Offres. **Le compte en ligne** (28/11/2026) : la colonne et le filtre des particuliers
  (`compte=en_ligne|sans_compte`) et la fiche lisent `last_login_at` de TF Buddy (rempli seulement si le client a un mot de passe).
  La jauge « Compte en ligne » (la part des particuliers qui en ont un) est dans le résumé. **Nettoyer la base**
  (`src/clients_nettoyage.php`, 28/11/2026) : les noms en MAJUSCULES ou en minuscules passent en Nom Propre dans TF Buddy, après un aperçu
  avant → après, par lots de 25, annulable (table `panel_clients_nettoyage`, 90 jours). Les noms incomplets (« ... » de la caisse) se
  signalent (filtre `filtre=incomplet`), **ne s'effacent jamais d'ici** (Sam : « aucune, juste les signaler »).
  **Bureaux en développement** (`src/bureaux_dev.php`, 26/11/2026) : une nouvelle personne et son futur bureau, saisis au
  dashboard ou par le questionnaire public du magasin (`public/bureau/`, un par magasin, lien et QR dans Clients ›
  Bureaux) ; la personne, la société (si TVA) et le bon de bienvenue se créent tout de suite, le bureau à la validation
  (compte du magasin). Tables `panel_bureau_questionnaire`, `panel_bureau_dev`. Le formulaire public en six étapes
  (`public/bureau/`, 26/11/2026) crée aussi le compte webshop, sans jamais recevoir de mot de passe ; le générateur de liens
  par magasin (`…/bureau/?m={shop}`, QR) est dans l'onglet Bureaux.
  **Les demandes hors zone** (27/11/2026) : « Ma zone n'est pas dans la liste » des deux pages Livraison au bureau (landing
  atelierby.be, landing du webshop) part dans TF Buddy (`delivery-interest`) ; une copie reste dans `panel_bureau_dev`
  (source `hors_zone`, route publique `POST /bureau-hors-zone`), au magasin le plus proche choisi par le visiteur. La base
  des zones est celle des tournées que le webshop publie (`/webshop/shops/{n}/delivery-zones`), montrées dans « Ce que voient vos clients ».
  Sur chaque demande : « Créer le bureau » (l'assistant « Ajouter un bureau » pré-rempli, « Ajouter la société » à l'étape 1 ;
  le bureau créé, `…/reprendre` passe la demande `reprise`) et la poubelle (`…/effacer` : la copie seule, l'original reste dans
  TF Buddy). Le bouton « Nouveau bureau en développement » est retiré (27/11/2026).
- **Vidéos d'instruction** (23/11/2026) : le carré du rail, à côté d'Assistant IA (`public/dashboard/tutos.js`), le registre
  `public/dashboard/videos/videos.json`, le tournage `panel/tools/videos/` (données fictives). Une nouvelle fonction du
  dashboard peut y annoncer sa vidéo (genre `nouveau`) ; un écran filmé qui change se retourne. Détail : `src/LISEZMOI.md`.
- **Tests** : `php panel/bin/tests_*.php` (assistant, bundles, boîte mail, trésorerie, primes, profil, vidéos…).
