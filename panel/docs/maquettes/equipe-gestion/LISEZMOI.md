# Mon équipe : la gestion de l'équipe du panel dans le dashboard franchisé (maquette)

Demande du 23/11/2026 : « check le module, adapte pour intégrer dans le dashboard franchisé, fais d'abord une maquette
screenshot ». Le module est le paquet `panel-team-files.zip` (README en polonais : « PAKIET PANEL — PRACOWNICY / TEAM »),
c'est-à-dire la gestion de l'équipe du panel TF Buddy : `/team/manage` (onglets employés, responsabilités, horaires),
`/team/schedule.pdf`, `PUT /ajax/team/employees/{id}/workstations`, et les routes `/employees`, `/ajax/employees`.

Rien n'est encore codé : ce dossier contient la maquette, et la revue du module qui la précède.

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
