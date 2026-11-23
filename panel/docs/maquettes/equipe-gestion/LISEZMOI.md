# Mon équipe : la gestion de l'équipe du panel dans le dashboard franchisé (maquette)

Demande du 23/11/2026 : « check le module, adapte pour intégrer dans le dashboard franchisé, fais d'abord une maquette
screenshot ». Le module est le paquet `panel-team-files.zip` (README en polonais : « PAKIET PANEL — PRACOWNICY / TEAM »),
c'est-à-dire la gestion de l'équipe du panel TF Buddy : `/team/manage` (onglets employés, responsabilités, horaires),
`/team/schedule.pdf`, `PUT /ajax/team/employees/{id}/workstations`, et les routes `/employees`, `/ajax/employees`.

Ce dossier contient les maquettes (01 à 07), la revue du module, et les captures de l'écran livré à l'étape 1 (`ecran-*.png`).

## Captures

| Fichier | Ce que c'est |
|---|---|
| `01-responsabilites.png` | l'onglet par défaut : qui a quel poste, et les produits qui en dépendent (le secteur du poste) ; la fiche de la personne choisie à droite ; « À compléter » en bas |
| `02-modifier-les-affectations.png` | la fenêtre des postes d'une personne, avec ce que le changement donne **avant** d'enregistrer |
| `03-planning.png` | la semaine personne par personne, les totaux, le coût, les secteurs que personne ne tient un jour donné, le PDF du planning |
| `04-employes.png` | la liste : les postes, les produits dont la personne a la charge, le téléphone, les heures de la semaine, l'accès à l'app employés, le statut |

**Comment elles sont faites.** Le vrai dashboard (site assemblé servi en local, `shop=5`, les lectures GET relayées vers
la production, aucune écriture), et la maquette posée par-dessus (`maquette.css`, `maquette.js`, `capturer.cjs`). Le
cadre (rail, magasin, objectif du jour, note Google) est réel ; **l'équipe, les postes, les produits et le planning sont
illustratifs** : aucune personne réelle.

## Ce que propose la maquette

- **Une entrée du rail, « Mon équipe »**, dans « La journée », sous Temps morts (`?vue=equipe`) ; trois onglets de page,
  comme le panel : Employés · Responsabilités (ouvert par défaut) · Planning. Une seule entrée, des onglets : le
  §2 du design system (« chaque donnée à une seule place »).
- **Le design system du cockpit**, pas celui du panel : ni Bootstrap, ni Mazer, ni FullCalendar, ni DayPilot.
- **Ce qui change par rapport au panel** :
  - une colonne « Autres postes » : au panel, une vendeuse qui n'a que le poste « Vente » (sans secteur) n'a que des
    tirets dans la carte ; ses postes généraux sont maintenant visibles ;
  - la fenêtre des affectations dit ce que le changement donne (« 56 produits (+12). Le Traiteur n'avait personne :
    il sera couvert ») ;
  - « Secteurs sans personne », sous le planning : le planning croisé avec les postes de chacun (lundi : Snacking ;
    dimanche : Pâtisserie, Snacking), présenté comme un indice et pas comme une certitude (le panel avertit déjà : une
    présence planifiée ne garantit pas la production) ;
  - « Demander au bureau » pour les produits sans secteur (le secteur se règle au panel, pas au magasin) : la demande
    part dans le helpdesk existant (`/helpdesk/magasin`) ;
  - la fiche de la personne montre ses heures de la semaine (lien vers le planning) ; la liste montre l'accès à l'app
    employés (`/employee`).

## Le planogramme des horaires et le budget d'heures (maquettes 05 à 07)

Demandes du 23/11/2026 : « fais-moi un planogramme avec les linéaires pour les horaires » ; « le budget heures semaine
est de 17 % ; au-dessus un marqueur orange, rouge au-dessus de 20 %, noir au-dessus de 22 % (variable), pour que le volume
d'heures et le coût de l'heure arrivent à 17 % » ; « les éditions des horaires doivent pouvoir se faire sur un linéaire,
pour voir sur la journée qui est présent et quand, avec en plus les données des temps morts : quand et combien de temps ».

| Fichier | Ce que c'est |
|---|---|
| `05-planogramme-jour.png` | la journée : une ligne par personne, rangée par secteur, ses services en barres ; dessous, les linéaires : la présence quart d'heure par quart d'heure face aux tickets d'un jour habituel, les temps morts, une ligne par secteur et le comptoir ; le budget du jour (26,7 %, noir) |
| `06-planogramme-semaine.png` | la semaine : un petit axe par jour, un marqueur par jour (noir, rouge, orange, dans la cible), la jauge de la semaine (20,6 %, rouge), et ce qu'il faut pour revenir à 17 % : retirer 53 h au coût moyen, ou un coût moyen de 14,32 €/h pour les mêmes heures |
| `07-planogramme-modifier-un-service.png` | un service étiré sur sa barre : la fenêtre montre l'effet avant d'enregistrer (le trou du comptoir de midi disparaît, le budget du jour passe de 26,7 % à 27,5 %) |

## Revue du module (le paquet)

**Ce qu'il contient.** 360 fichiers : les contrôleurs, services, dépôts et modèles du panel (dont beaucoup hors du
module, « joints comme contexte »), les vues Twig, `team-management.js` / `.css`, `team-schedule-pdf.js`, les
traductions (pl, en, fr, it, nl) et des tests PHP et Playwright. Le cœur tient en peu de lignes :

| Fichier | Rôle | Dans le dashboard |
|---|---|---|
| `services/Employee/TeamResponsibilityMap.php` (78 l.) | projection pure : employés × postes × produits → secteurs, couverture, manques | **repris** tel quel en fonction PHP (`panel/src/equipe.php`) |
| `services/Employee/TeamManagementService.php` (52 l.) | contrôles d'écriture : liste d'identifiants (≤ 300), verrou optimiste (`expected_workstation_ids`), poste connu, employé du magasin et actif | **repris** |
| `repositories/Employee/TeamManagementRepository.php` | les appels à l'API TF Buddy | réécrit sur `PanelApi` / `ErpApi` (côté serveur) |
| `controllers/…/TeamManagementController.php`, vues Twig, `team-management.js` | l'écran du panel | **réécrits** : `panel/public/dashboard/equipe.js` + `equipe.css` |
| `views/franchise/schedule.twig` (1 100 l., FullCalendar + DayPilot) | le planning éditable et les évaluations | **réécrit** en grille de semaine |
| `views/employee/overview.twig` (1 080 l.), `new`, `edit`, `show` | la liste et la fiche des employés | **réécrits** (liste + fiche) |

**Les routes de l'API TF Buddy qu'il utilise** : `GET /shops/{s}/employees?include=positions`,
`GET /operational-framework/workstations` (chaque poste doit porter `sector_id`, sinon le module refuse d'afficher la
carte : `mapping_unavailable`), `GET /shops/{s}/products/available`, `PUT /employees/{id}/workstations`,
`GET /shops/{s}/schedule/document?start_date&days&layout` (le PDF), `GET /shops/{s}/schedule`, `POST|PATCH|DELETE
/schedule/{id}`, `GET|POST /schedule/{id}/evaluation`, `GET /shops/{s}/employees/evaluation-summary`,
`POST|PATCH /employees`, `GET /franchisee-employee-roles`.

**Points à vérifier avant de coder** (non vérifiables d'ici : le jeton présenté par le proxy de cette session est
refusé par ces routes, 401) :

1. que `/operational-framework/workstations` renvoie bien `sector_id` en production, et `/shops/{s}/products/available`
   `sector_id` / `sector_name` ;
2. que le compte de service du cockpit (`PanelApi` ou `ErpApi`) a le droit d'écrire `PUT /employees/{id}/workstations`,
   le planning et les employés : au panel, ces appels partent avec le jeton du magasin connecté ;
3. que `GET /shops/{s}/schedule/document` existe en production (le README du paquet dit que le PDF vient de l'API).

**Remarques sur le code du paquet** (corrigées dans l'adaptation, pas dans le paquet) :

- le lien vers la fiche est écrit en dur (`/panel/employees/{id}`) ;
- après un enregistrement, la page entière se recharge : le dashboard relira la carte seule ;
- le PDF du planning transmet `start_date`, `days` et `layout` tels quels à l'API (le contrôleur ne vérifie que leur
  type) : la route du cockpit les validera (date, 7 ou 14, `shop` ou `departments`) ;
- les postes sans secteur ne sont visibles que dans l'encadré d'avertissement (voir « Autres postes »).

## Étape 1 : livrée (23/11/2026)

`panel/src/equipe.php` (les routes `/dashboard/equipe/*`), `panel/public/dashboard/equipe.js` et `equipe.css`, l'entrée
« Mon équipe » du rail (La journée, sous Temps morts), `resObjectifsJours` (shared/src/resultat.php : le CA prévu de chaque
jour, la règle du Résultat, aussi pour un jour à venir), `personnelPaliers` / `personnelNiveau` (shared/src/cibles_reseau.php :
la cible du réseau, 20 % et 22 %, réglables dans le réglage `personnelPaliers`). Tests : `php panel/bin/tests_equipe.php`.

| Capture | Ce que c'est |
|---|---|
| `ecran-1-responsabilites.png` | l'onglet Responsabilités du module livré (sans le bloc « À compléter », retiré le 23/11/2026) |
| `ecran-2-planning-jour.png` | le planogramme du jour : présence, tickets habituels, temps morts habituels et du jour, linéaires, constats |
| `ecran-3-modifier-un-service.png` | un service étiré à la souris (sans mode à ouvrir), la fenêtre de son effet |
| `ecran-4-planning-mois.png` | le mois (23/11/2026, « rajouter mois ») : les heures de chaque service jour par jour (matin, après-midi), le week-end grisé, par jour les heures et le point du budget, par semaine les heures et le budget, la jauge du mois |
| `ecran-5-recopier-la-semaine.png` | « Recopier la semaine précédente » sur une semaine vide : l'aperçu jour par jour, un service décoché, le budget de la semaine après la recopie (20,9 %, rouge) et les heures à retirer pour revenir à 17 % |
| `ecran-6-shift-maker.png` | le shift maker (test) sur une semaine vide : la semaine 2 du roulement tirée de la matrice, chacun avec sa lettre et son créneau ; ceux qui n'ont pas de lettre (la production) sont nommés et ne sont pas générés ; le budget après (9,0 %) |
| `ecran-7-matrice-abcd.png` | la matrice A B C D : les deux créneaux, la semaine de référence, les deux semaines case par case (un clic : matin → après-midi → repos), qui porte quelle lettre, et la couverture matin · après-midi de chaque jour |
| `ecran-8-fiche-roulement.png` | la fiche d'une personne : sa lettre (A B C D ou aucun), et ce qu'elle donne cette semaine et la suivante |
| `ecran-9-semaine-tableau.png` | la semaine (le tableau et le planogramme de la semaine fondus le 23/11/2026) : une ligne par personne, une case par jour, chaque service avec sa petite barre sur la journée, les heures et le coût ; la jauge du budget ; par jour les personnes, les heures et le budget ; dessous, les linéaires (comptoir, secteurs, trous) |
| `ecran-10-clic-sur-un-service.png` | un clic sur un service : adapter ses heures (ou un créneau de la matrice), le retirer du shift, voir qui est sur le même shift (ouvrir, retirer), ajouter une personne sur ce shift avec l'effet sur le budget du jour |
| `ecran-11-clic-sur-une-case-vide.png` | un clic sur une case vide : un nouveau service, au créneau de sa lettre (ou le matin), avec l'effet sur le budget |
| `ecran-12-tracer-une-ligne.png` | au jour, tirer une ligne à la souris sur la ligne d'une personne au repos : la trace dit ses heures pendant le geste |
| `ecran-13-ligne-tracee.png` | la ligne lâchée : la fenêtre du nouveau service (14:00–18:00) et son effet sur le budget du jour, avant « Ajouter le service » |
| `ecran-14-planning-imprime.png` | le planning imprimé, fait par le cockpit (le panel ne sert pas de PDF du planning) : une page A4 paysage par semaine, les heures de chacun, le total par jour |
| `ecran-15-fenetre-ligne-verte.png` | la fenêtre d'un service construite (la 2 bis, 23/11/2026) : la ligne tirée de 09:00 à 15:00, verte (6 h) |
| `ecran-16-fenetre-ligne-rouge.png` | la même, le bord tiré jusqu'à 18:00 : rouge, « 9 h · plus de 8 h », marqué aussi en bas ; l'ajout reste possible |
| `ecran-17-parcourir-le-jour.png` | parcourir le jour dans la fenêtre : les sections (Comptoir 1/2…), chacun avec ses heures, « Ajouter et suivant : Laura › » |

**Comment elles sont faites.** Le vrai dashboard servi en local ; les routes `/dashboard/equipe/*` répondent avec ce que le
vrai code de `panel/src/equipe.php` calcule sur un panel de doublure (`ecran/panel_illustratif.php`, l'équipe et les tickets
illustratifs des maquettes), le reste est lu en production. `ecran/capturer.cjs` joue les gestes (choisir une personne,
cocher un poste, enregistrer, étirer un service, enregistrer) et vérifie les écritures envoyées.

**Ce qui n'est pas dans l'étape 1** : l'onglet Employés (liste et fiche : étape 3), les évaluations des services (étape 2),
l'écran du siège pour régler les paliers (le réglage existe, son écran pas encore), les heures d'ouverture saisies (elles
sont tirées des tickets des 4 dernières semaines). Les points de la revue restent à vérifier en production : `sector_id` des
postes, droits d'écriture du compte du cockpit sur `/employees/{id}/workstations` et `/schedule`, la route du PDF.

## L'intégration prévue (après validation de la maquette)

- **Écran** : `panel/public/dashboard/equipe.js` + `equipe.css` (`window.DashEquipe.racine(shop, {apiBase, aller})`,
  comme le coffre-fort et la formation), l'entrée `equipe` du rail (`RAIL`, `RAIL_ICO`), le même module au téléphone.
- **Serveur** : `panel/src/equipe.php`, routes sous `/dashboard/equipe/…` (préfixe déjà ouvert aux magasins,
  `ACCES_FRANCHISE`) ; le magasin de la requête doit être celui de la session, et chaque employé, poste ou service
  désigné par son numéro est revérifié comme étant de ce magasin. Aucune table nouvelle : tout vit au panel.
- **Une seule lecture du planning** : celle de l'Opérationnel (`/shops/{id}/schedule`, `src/planning_sync.php`), pas
  une deuxième.
- **Tests** : `panel/bin/tests_equipe.php`, qui reprend les cas des tests du paquet (`tests/Team/*.php`) ; l'index de
  l'API régénéré (`python3 panel/tools/dashboard-api-index.py`).
- **Par étapes** proposées : 1. Responsabilités (lecture + affectations) et Planning en lecture + PDF ; 2. l'édition des
  services et les évaluations ; 3. la liste et la fiche des employés.

## La fenêtre d'un service : trois propositions (23/11/2026)

Sam : « retravailler le formulaire, fais-moi 3 propositions ». Dossier `formulaire/` (`formulaire.js`, `formulaire.css`,
`capturer.cjs`), posées sur le vrai écran Semaine avec les données illustratives. Le même cas pour les trois : ajouter
Chloé Peeters (Vente, lettre A) le mercredi 25, où le comptoir est vide de 7 h à 10 h. Toutes donnent les heures en 24 h
au quart d'heure (− / +) : le champ « heure » du navigateur affichait 07:00 AM / 02:00 PM selon la langue de l'ordinateur.

| Capture | La proposition |
|---|---|
| `formulaire/form-1-fenetre-compacte.png` | la fenêtre d'aujourd'hui, rangée : les heures, les créneaux, la petite journée (sa barre, le comptoir), puis « Ce que ça change » en quatre lignes (comptoir, budget du jour, de la semaine, ses heures) |
| `formulaire/form-2-creneaux-d-abord.png` | les créneaux d'abord, chacun avec son effet avant d'être choisi (comble le comptoir ou non, budget du jour, coût) ; « Sur mesure » en touchant les heures (tablette) ; à droite, qui est là ce jour-là |
| `formulaire/form-3-panneau-a-droite.png` | un panneau à droite, le tableau reste visible et la case est cerclée : sa semaine (jours travaillés, repos), les heures, « le même horaire d'autres jours » (poser plusieurs jours d'un coup), l'effet, la journée |

**Choix de Sam : la 2, sans champ (« au lieu de faire un input, je préfère tirer une ligne horizontale sur un axe qui
représente les heures de la journée, qui se met en rouge si plus court que 4 et si plus que 8 »).** La proposition 2 bis :
les deux créneaux et « Sur mesure » en haut, chacun avec son effet ; la ligne se tire sur l'axe des heures (4 h → 20 h, au
quart d'heure, les bords se tirent pour ajuster), au-dessus de qui est là, à la même échelle ; verte de 4 h à 8 h, rouge
en dessous de 4 h ou au-dessus de 8 h ; l'effet en bas (comptoir, budget du jour, de la semaine, ses heures). `EQF_BIS=1
node formulaire/capturer.cjs <données> <sortie>`.

| Capture | L'état |
|---|---|
| `formulaire/form-2b-ligne-ok.png` | 07:00–13:30, 6,5 h : verte ; le trou du comptoir comblé, mercredi 28,4 %, +103 € |
| `formulaire/form-2b-ligne-long.png` | 06:00–15:30, 9,5 h : rouge, « plus de 8 h » (et commence 1 h avant l'ouverture) |
| `formulaire/form-2b-ligne-court.png` | 07:00–10:00, 3 h : rouge, « moins de 4 h » |
