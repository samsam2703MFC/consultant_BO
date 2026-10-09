# Audit — tâches et visites des consultants (09/10/2026)

Demande : une gestion des tâches et des visites des consultants dans tous les magasins — par consultant, sa liste de
tâches (liste de contrôle), le suivi, et l'agenda Google — avec une entrée « Gestion consultant » dans le rail du
cockpit. Cet audit dit ce qui existe déjà (cockpit, panel), ce qui manque, et ce que les trois maquettes de
`docs/maquettes/gestion-consultant/` proposent.

## 1. Verdict en six lignes

- Le cockpit a déjà le **cœur du métier** : le module Visites (PWA `visites/`) planifie et fait la visite, avec une
  checklist de 23 points en six modules, une review, un plan d'action suivi jusqu'à la clôture, les photos, le client
  mystère, l'effectif, des rappels par courriel. Il vit hors du rail, dans une application à part.
- Le panel a un **cadre de tâches par poste** pour les consultants (`/consultant/tasks`, marquer comme faite,
  complétions) — vide aujourd'hui pour le poste « Stratégie et Développement » —, des **notes de visite** typées
  (`VISIT`, 5 types), un **helpdesk** dont les cas s'attribuent à un consultant par domaine de responsabilité, et toute
  la **tâche quotidienne des magasins** (checklists du cadre opérationnel, complétions, revues, validation admin).
- Ce qui manque : une **liste de tâches par consultant** qui réunisse ses sources (visites et leurs points de
  contrôle, plans d'action qui lui sont assignés, cas du helpdesk, tâches récurrentes, tâches propres), avec un suivi
  d'échéances ; une **vue mois** ; une **vue réseau** consultants × magasins (qui couvre quoi, à quelle fréquence,
  avec quel retard) ; et un **écran dans le rail**.
- Le **cadre de visite** demandé le 09/10 — par magasin, le nombre de visites, le type de chaque visite, le profil de
  consultant qu'elle demande, la checklist attribuée — n'existe pas tel quel : le module a une fréquence par magasin en
  jours, un motif par visite (régulière, asap, due, revisite) et une seule checklist pour toutes les visites. Il faut des
  types de visite (la liste déroulante) portant chacun sa checklist, ses tâches à faire, sa durée et son profil, puis un
  plan par magasin qui dit combien de visites de chaque type par mois, et qui les fait (§5).
- **Rassembler** (demande du 09/10) : l'évaluation et le suivi des franchisés sont dispersés dans quatre sections du
  rail et deux applications à part. Une section « Franchisés · évaluation et suivi » les réunit, en deux volets :
  suivi journalier et opérations (ce que les données disent chaque jour) et suivi de terrain (ce que le consultant
  constate sur place), avec une fiche par franchisé qui lit tout sans rien ressaisir (§7).
- **Agenda Google** : rien n'existe. Trois voies, de la plus simple à la plus complète : le lien « Ajouter à Google
  Agenda » sur chaque visite (immédiat, sans compte), le flux ICS par consultant auquel Google s'abonne (lecture seule,
  relu par Google toutes les 12 à 24 h) et l'invitation par courriel avec pièce jointe `.ics` à chaque visite
  planifiée (atterrit dans l'agenda à l'acceptation, se met à jour par `SEQUENCE`), puis l'API Google Calendar avec
  OAuth pour un agenda bidirectionnel (projet Google Cloud, écran de consentement, jetons gardés au serveur).
- Recommandation : construire « Gestion consultant » dans le cockpit **au-dessus** du module Visites (mêmes tables,
  mêmes règles), y ajouter le cadre de visite (types, nombre par magasin, profils), une table de tâches consultant et
  une affectation consultant ↔ magasins, livrer l'agenda
  Google par ICS + invitations dès le premier lot, l'OAuth en second lot si le bidirectionnel s'impose.
- Le panel porte aussi des **évaluations d'employés** (`/employees/{id}/evaluations`, `/schedule/{id}/evaluation`,
  `/shops/{id}/employees/evaluation-summary`) : les remarques aux opérateurs faites depuis la modale des invendus
  pourraient y être poussées un jour, au lieu de rester dans `ceo_operateur_remarque` seulement.

## 2. Ce que le cockpit a déjà

### 2.1 Le module Visites (`src/visites.php`, `src/visites_conformite.php`, PWA `public/visites/`)

| Objet | Table | Ce qu'il porte |
|---|---|---|
| Visite | `ceo_visite` | magasin, consultant (id, nom), date, heure, durée, motif (régulière, asap, due, revisite), statut (planifiée → confirmée → en cours → terminée, annulée), début et fin réels, review (exécution, clients, causes, diagnostic, recommandation) |
| Point de contrôle | `ceo_visite_point` | module, référence, libellé, verdict, note, photo, % (planogramme) |
| Photo | `ceo_visite_photo` | genre (façade, intérieur, arrière, point, avant, après, correction, msp), chemin |
| Plan d'action | `ceo_visite_action`, `_evt` | titre, priorité P0 à P2, assigné (franchisé, équipe, consultant, admin), échéance, statut (ouvert, attente, validé, reprendre, fermé, escalade), transitions par rôle, campagne |
| Client mystère | `ceo_msp` | rapport, note |
| Effectif | `ceo_equipe_releve` | effectif, prévu, départs |

Règles déjà là : fréquence de visite par magasin (`viFrequence`, réglable), visite « due » et feu par magasin
(`viFeu` : P0 ouvert, note Google, planogramme, dernière visite), checklist standard de 23 points en six modules
(produit, hygiène, visuel, planogramme, assortiment, client mystère) réglable dans le cockpit (`viChecklist`),
consultants lus dans le panel (`viConsultants`), rôles consultant / franchisé / admin, file hors-ligne avec
`client_id`, rappels par courriel (`viHorloge`, cron `/visites/cron?jeton=`), synthèse (`/visites/synthese`),
conformité (`/visites/conformite`), campagnes (`/visites/campagnes`).

Lecture et écriture : `GET /visites/app` (tout ce que l'application montre : boutiques avec feu, visites à ± 90/21
jours, points, photos, plans, msp, équipe), `POST /visites`, `PUT /visites/{id}`, `PUT /visites/{id}/points`,
`POST /visites/photos`, `PUT /visites/reglages`, `POST /visites/tick`.

Ce qu'il ne fait pas : une seule checklist pour toutes les visites et un motif sans type, donc pas de cadre par
magasin (nombre de visites, type, profil, checklist attribuée) ; pas de vue mois, pas de liste de tâches hors visite, pas d'affectation consultant ↔ magasins
(chaque visite nomme son consultant, rien ne dit qui est responsable de quoi), pas d'agenda externe, pas d'écran
dans le rail du cockpit.

### 2.2 Les écrans du cockpit qui touchent au sujet

- **Tâches consultants** (`#/taches`, hors rail, atteint par son adresse) : ce qui attend le consultant — tâches
  photographiées à noter (revues), ses propres tâches de projets, projets en retard, alertes de marge ; puis la
  liste filtrable par intervenant et par magasin.
- **Contrôle des tâches** (heatmap magasin × mois, `/taches/suivi`, relevé `ceo_tache_jour`) et le classement du
  jour (`/taches/classement`, lu sur `/consultant/network/tasks/ranking`).
- **Plan de développement** : projets avec jalons et tâches (qui, échéance).
- **Scoring du trimestre**, **client mystère**, **journal** (`journalAdd`).
- Identité du consultant connecté : `consultantIdCompte()` lit `/consultant/tasks` → `position.membership_id`
  (« u6 ») ; notes vers le panel : `POST /consultants/note` → `/consultant/shops/{id}/notes`.

### 2.3 Les consultants

`GET /consultants` : les membres `user_membership` (app `CONSULTANT`) avec `user_profile` — nom, courriel. Le panel
liste aussi `/panel/consultants` et gère les adhésions (`/admin/consultant-memberships`), les domaines de
responsabilité (`/consultant/responsibility-areas` : 6 clés, dont `strategy`) et leur description par poste
(`/positions/{id}/consultant-areas`, `/consultant-memberships/{id}/area-descriptions`).

## 3. Ce que le panel expose (document OpenAPI 3.0.0, 933 routes, lu le 09/10/2026)

Lu par la sonde du cockpit (`/exploitation/invendus/sonde?q=/../swagger/openapi.json`) : 144 routes parlent de
consultants, tâches, checklists, revues, notes, planning ou évaluations. Les routes utiles, et ce qu'elles rendent
(mesuré par `/diagnostic/panel-consultant?shop=4`) :

| Route | Mesuré | Usage pour « Gestion consultant » |
|---|---|---|
| `GET /consultant/tasks?date=` | `position` (membership 6, poste « Stratégie et Développement », niveau « Indépandant »), `tasks` : **0** | les tâches du poste, définies par le cadre opérationnel (`/position-levels/{id}/tasks`, `/levels/{id}/tasks`, `/tasks` + contrôleurs, alias) ; vide aujourd'hui : à remplir côté panel, ou à ignorer |
| `POST /consultant/tasks/{taskId}/mark-as-done`, `GET /consultant/tasks/completions/{id}` | — | marquer une tâche de poste faite |
| `GET /consultant/tasks/helpdesk?area_key=&q=` | cas attribués à l'adhésion ou à son domaine | une source de tâches : les cas du helpdesk ; `PATCH /cases/{id}/consultant`, `GET /cases/{id}/eligible-consultants` |
| `GET /consultant/shops/{id}/tasks?date=` | 11 tâches du jour à Halle : checklist, obligatoire, priorité, photo requise, fréquence, heure, statut, complétion, pièce jointe, revue (note, commentaire, accepté), validation admin ; `trend` sur 7 jours | ce que le consultant contrôle sur place et à distance |
| `GET /consultant/network/tasks?shop_ids=`, `GET /consultant/network/tasks/ranking` | réseau du jour : 44 tâches, 9 faites, 20,5 % | la vue réseau |
| `GET /consultant/shops/{id}/checklists`, `/checklists/{cid}/progress`, `/checklists/progress?from=&to=` | — | la progression des checklists magasin sur une période |
| `POST /consultant/shops/{id}/task-reviews`, `POST …/{reviewId}/validate` | — | noter une tâche photographiée (déjà utilisé) |
| `GET /consultant/note-types` | 5 types, dont `VISIT` (« Wizyta ») | typer la note de visite |
| `GET/POST /consultant/shops/{id}/notes`, `/consultant/shops/{id}/employees/{eid}/notes`, `/consultant/notes/{id}`, `/notes/{id}/comments` | 1 note VISIT (20/07/2026, contenu « e ») | la note de visite et la note sur un employé, commentables : à écrire à la clôture d'une visite |
| `GET /consultant/responsibility-areas`, `/positions/{id}/consultant-areas` | 6 domaines | router les tâches par domaine |
| `GET /panel/consultants`, `/admin/consultant-memberships` | — | la liste des consultants |
| `GET /employees/{id}/evaluations`, `GET/POST /schedule/{id}/evaluation`, `/shops/{id}/evaluations`, `/employees/evaluation-summary` | — | les évaluations d'employés du panel (voir §1) |
| `/shops/{id}/schedule`, `/employees/{id}/schedule`, `/owner/network/schedule/weekly` | — | les horaires du personnel, pas l'agenda des consultants |

Ce que le panel **n'a pas** : une entité « visite » (seulement une note de type VISIT), un agenda ou un flux ICS, une
checklist de visite consultant (les checklists du cadre opérationnel sont celles des magasins), une affectation
consultant ↔ magasins (seulement des domaines de responsabilité).

## 4. Agenda Google — ce qui est possible

| Voie | Ce qu'il faut | Ce que ça donne | Limites |
|---|---|---|---|
| Lien « Ajouter à Google Agenda » sur chaque visite | rien (une URL `calendar.google.com/calendar/render?action=TEMPLATE&…`) | un clic, l'événement est dans l'agenda du consultant | manuel, un événement à la fois, pas de mise à jour automatique |
| Flux ICS par consultant (`/consultants/{id}/visites.ics`, jeton dans l'adresse) | servir un `VCALENDAR` avec les visites planifiées et confirmées ; Google « Ajouter un agenda à partir d'une URL » | toutes les visites, à jour toutes seules | Google relit le flux toutes les 12 à 24 h ; lecture seule (un changement fait dans Google ne revient pas) |
| Invitation par courriel avec `.ics` (`METHOD:REQUEST`, `SEQUENCE`) | le SMTP du cockpit, déjà là ; l'adresse du consultant, déjà connue | la visite planifiée arrive comme une invitation ; acceptée, elle est dans l'agenda ; replanifiée, elle se met à jour ; annulée, elle disparaît (`METHOD:CANCEL`) | une adresse par consultant ; les réponses (accepté, refusé) ne reviennent que si on lit la boîte |
| API Google Calendar (OAuth 2) | un projet Google Cloud, un écran de consentement, un jeton par consultant gardé au serveur, un rafraîchissement | bidirectionnel : la visite déplacée dans Google revient dans le cockpit (webhook ou relecture) | le plus de travail ; dépend d'un compte Google par consultant ; à revoir si Google change ses règles de vérification |

Recommandation : lot 1 = lien par visite + flux ICS + invitation `.ics` à la planification ; lot 2 = OAuth si le
bidirectionnel s'impose.

## 5. Le cadre de visite — modèle proposé

Demandé le 09/10 : « chaque visite, la liste déroulante prend sa checklist avec les tâches à faire ; nombre de visites,
type de visite, profil de consultant pour la visite, checklist attribuée… ». Aujourd'hui le module a une fréquence par
magasin (`visitesFrequence`, en jours), un motif par visite (`VI_MOTIFS`) et une seule checklist (`visitesChecklist`,
23 points en 6 modules par défaut, `viChecklistDefaut()`). Le cadre ajoute deux objets et un champ.

| Objet | Champs | Rôle |
|---|---|---|
| Type de visite (réglage `visitesTypes`) | code, libellé, durée, profil demandé, checklist (modules et points, même forme que `visitesChecklist`), tâches à faire (avant J−n, pendant, après J+n), actif, dynamique | la liste déroulante : choisir le type, c'est prendre sa checklist et ses tâches |
| Cadre par magasin (réglage `visitesCadre`) | magasin, type, nombre par mois (ou par trimestre), profil, consultant responsable | combien de visites de chaque type, par qui ; génère « À planifier », la couverture et les visites dues |
| Visite `ceo_visite` | + `type_code` (défaut `reguliere`), + `cadre_id` | le motif reste (régulière, asap, due, revisite) : il dit pourquoi, le type dit quoi |
| Tâche consultant `ceo_consultant_tache` | consultant, magasin, visite, source, quand (avant, pendant, après), délai, titre, échéance, statut | les tâches du type deviennent des tâches du consultant à la planification, avec leur échéance |

Types proposés, modifiables dans l'onglet Cadre de visite : Régulière (90 min, 23 points, 6 modules), Production
(180 min, 14 points : mise en place, cuisson, recuissons, invendus), Hygiène et qualité (60 min, 12 points), Client
mystère (45 min, 8 points), Bilan trimestriel (120 min, 10 points : scoring, P&L, plan de développement, objectifs),
Ouverture / lancement (240 min, 31 points), Suivi plan d'action (45 min, les points des plans ouverts), Revisite
(60 min, les points non conformes de la dernière visite). Les deux derniers naissent d'un P0 ou d'un point non
conforme et ne comptent pas dans le nombre mensuel.

Profils : le poste du panel (`position_name` de `/consultant/tasks`, « Stratégie et Développement » pour le compte u6,
`/panel/consultants` pour les autres) ou un libellé réglé dans le cockpit — Stratégie et Développement, Opérations et
qualité, Produit et production. Le formulaire de planification ne propose que les consultants du profil demandé par
le type ; la durée et la checklist viennent du type ; les tâches « avant » et « après » reçoivent leur échéance
(J−2, J+1…) et entrent dans la liste du consultant.

Migration : la checklist actuelle devient celle du type « Régulière » ; la fréquence en jours devient une ligne
« Régulière, n par mois » par magasin ; les visites existantes reçoivent `type_code = reguliere`. `viFeu` et
`viFrequence` lisent le cadre, avec l'ancien réglage en repli. L'application Visites (PWA) reçoit le type dans
`GET /visites/app` et la checklist du type à la place de la liste unique.

## 6. Ce que les maquettes proposent (`docs/maquettes/gestion-consultant/`)

Entrée « Gestion consultant » dans le rail, sous « Suivi de terrain » de la section « Franchisés · évaluation et
suivi » (§7), quatre onglets : Mon planning, Tâches et contrôles,
Réseau, Cadre de visite. Noms des consultants fictifs, magasins réels.

- **A — Mon planning** : le mois du consultant tous magasins, les visites par statut, les échéances ; la semaine en
  détail avec l'avancement de la checklist et les alertes P0 ; la carte Agenda Google (abonnement ICS, invitation à
  chaque visite, lien par visite) ; « À planifier » d'après la fréquence par magasin.
- **B — Tâches et contrôles** : la liste du consultant réunie de ses cinq sources (visite, plan d'action, panel,
  récurrente, perso), en trois colonnes (à faire, en cours, fait cette semaine), avec échéance, magasin, avancement ;
  à droite, la liste de contrôle de la visite choisie : la liste déroulante du type, ses tâches à faire (avant,
  pendant, après) avec leur échéance, puis ses points, module par module.
- **C — Réseau** : consultants × magasins (dernière visite, prochaine, plans ouverts, feu), la charge par consultant,
  la couverture des magasins face au cadre, les règles (affectation, cadre, profils, checklists, agenda).
- **D — Cadre de visite** : par magasin, le nombre de visites par mois, le type, le profil, la checklist attribuée, le
  consultant et le réalisé ; la liste déroulante des types et, pour le type choisi, sa durée, son profil, sa checklist
  et ses tâches à faire ; le formulaire « Planifier une visite » où choisir le type charge la checklist, filtre les
  consultants par profil, préremplit la durée et crée les tâches avant et après.
- **E — Fiche franchisé** : le rail réorganisé (section « Franchisés · évaluation et suivi » en deux volets), le
  tableau des franchisés avec les indicateurs des deux volets, puis la fiche d'un magasin : à gauche le suivi
  journalier et opérations (tâches et photos, production et invendus, objectifs, remarques aux opérateurs, note
  Google, résultat), à droite le suivi de terrain (dernière visite, plans d'action, prochaines visites au cadre,
  client mystère, conformité du comptoir, scoring du trimestre), et le journal de l'évaluation.

## 7. Rassembler l'évaluation et le suivi des franchisés, en deux volets

Demandé le 09/10 : « rassembler le scoring, la gestion des visites et tout ce qui a trait à l'évaluation et au suivi
des franchisés », puis « diviser en suivi journalier et opérations, et suivi de terrain ». Aujourd'hui ces écrans
vivent dans quatre sections du rail (Magasins, Contrôle, Marque & marketing, ERP franchisé) et deux applications à
part (Visites, dashboard magasin).

| Ce qui évalue ou suit le franchisé | Où ça vit aujourd'hui | Volet proposé |
|---|---|---|
| Scoring du trimestre : Note Google, Tâches, Client mystère, Budget (4 × 5 points), rapport A4 envoyé le 1er du trimestre | Magasins › Scoring du trimestre (`/scoring`, `/scoring/rapport`) | l'évaluation, en tête de section |
| Tâches du jour, photos à noter, heatmap magasin × mois, classement réseau | Contrôle › Tâches (onglets Suivi, Contrôle, Mensuel : `/taches/suivi`, `/pwa/tasks/heatmap`) ; Tâches consultants hors rail (`/taches/classement`) | Suivi journalier et opérations |
| Production : plan, validation, clôture, dernière vente, recuissons, écart production / ventes | ERP franchisé › Gestion de production | Suivi journalier et opérations (en lecture) |
| Invendus et poubelle, modale du détail, actions qualité | Magasins › Invendus et poubelle ; dashboard magasin (`/exploitation/invendus`) | Suivi journalier et opérations |
| Objectifs produits, objectifs de campagne, jauge du jour | Marque & marketing › Campagnes › Objectifs ; dashboard (`/exploitation/objectifs-produits`) | Suivi journalier et opérations |
| Remarques aux opérateurs (évaluations) | `GET /equipe/remarques`, sans écran | Suivi journalier et opérations (nouvel écran) |
| Note Google, avis, réputation | Magasins › Analyse magasin › Réputation | Suivi journalier et opérations |
| Reporting automatisé | Contrôle › Reporting automatisé | Suivi journalier et opérations |
| Visites : planning, checklist, review, photos, plans d'action P0 à P2, campagnes, synthèse | application Visites (PWA), hors rail (`/visites/app`, `/visites/boutique/{id}`) | Suivi de terrain |
| Gestion consultant : planning, tâches, réseau, cadre de visite | à construire (maquettes A à D) | Suivi de terrain |
| Client mystère : encodage obtenu / maximum, rapport PDF | dans Scoring du trimestre (`/scoring/msp`) | Suivi de terrain (entrée propre) |
| Conformité du comptoir : planogramme tenu, assortiment obligatoire | `/visites/conformite`, lu par l'app Visites ; Produits › Catalogue | Suivi de terrain |
| Fiche franchisé : tout ce qui précède pour un magasin, en deux colonnes, avec le journal | à construire (maquette E) | en tête de section |

Le rail proposé : une section « Franchisés · évaluation et suivi » avec « Fiche franchisé » et « Scoring du
trimestre » en tête, puis deux sous-menus — **Suivi journalier et opérations** (ce que les données disent chaque jour,
à distance : tâches et contrôles photo, production et invendus, objectifs, remarques aux opérateurs, note Google et
avis, reporting) et **Suivi de terrain** (ce que le consultant constate sur place : gestion consultant, visites, plans
d'action, client mystère, conformité du comptoir). Le rail du cockpit sait déjà faire les sous-menus (`sub` et
`children`, comme Gestion de production) : c'est un déplacement d'entrées, pas de nouveaux écrans, sauf la fiche
franchisé et les remarques aux opérateurs. Les anciennes adresses restent valables, l'entrée qui les couvre s'allume.

La fiche franchisé ne crée aucune donnée : elle lit `/scoring`, `/taches/suivi`, `/pwa/tasks/heatmap`,
`/exploitation/invendus`, `/exploitation/objectifs-produits`, `/equipe/remarques`, la réputation,
`/visites/boutique/{id}`, `/visites/conformite`, `/scoring/msp` et le cadre de visite, et tient un journal des
constats des deux volets. Le scoring du trimestre reste la synthèse : deux de ses postes viennent du volet
journalier (Note Google, Tâches), un du terrain (Client mystère), un du pilotage (Budget).

## 8. Ce qui a été construit (09/10/2026) et ce qui reste

Réalisé le jour même : les lots 1 à 5 et 8 à 10 ci-dessous — `src/visites_cadre.php`, `src/franchises.php`, l'écran
« Gestion consultant » à quatre onglets, la section du rail en deux volets, la fiche franchisé et l'écran des remarques,
la page du franchisé `dashboard/suivi.html?shop=`, l'application Visites qui lit le type (liste déroulante, checklist
du type, tâches sur la fiche, lien Google Agenda). Le détail des routes est dans `docs/contrat-api.md`. Reste le
lot 7 (OAuth Google, bidirectionnel) et, côté panel, la lecture des cas du helpdesk qui dépend de la forme de sa
réponse (lue en meilleur effort).

### Estimation d'origine

| Lot | Contenu | Serveur | Écran |
|---|---|---|---|
| 1 — Écran dans le rail | lecture `GET /consultants/gestion?consultant=` (visites, plans assignés, tâches, cas helpdesk, fréquences, feu) ; vue mois ; vue réseau | 2 j | 3 j |
| 2 — Cadre de visite | `ceo_visite_type`, `ceo_visite_cadre`, `type_code` sur la visite, migration de la checklist et de la fréquence, la PWA lit le type | 2 j | 2 j |
| 3 — Tâches consultant | table `ceo_consultant_tache` (consultant, magasin, titre, source, échéance, statut, visite, quand), `POST/PUT`, tâches générées par le cadre et par le type | 2 j | 2 j |
| 4 — Agenda Google | `GET /consultants/{id}/visites.ics` (jeton), lien par visite, invitation `.ics` à la planification et à chaque changement (SMTP) | 1,5 j | 0,5 j |
| 5 — Affectation et réglages | le consultant de chaque ligne du cadre, les profils, l'onglet Cadre de visite | 0,5 j | 1,5 j |
| 6 — Panel | note `VISIT` écrite à la clôture d'une visite ; cas du helpdesk lus comme tâches ; plus tard, évaluations d'employés | 1 j | — |
| 7 — OAuth Google (optionnel) | projet Google, jetons, synchronisation bidirectionnelle | 3 j | 1 j |
| 8 — Section Franchisés du rail | déplacer les entrées en deux sous-menus, alias des anciennes adresses | — | 0,5 j |
| 9 — Fiche franchisé | lecture `GET /franchises/{id}/fiche` (agrégat des lectures existantes), écran en deux volets, journal | 1,5 j | 2 j |
| 10 — Remarques aux opérateurs | écran de liste et de suivi par opérateur (`GET /equipe/remarques` existe) | — | 1 j |
