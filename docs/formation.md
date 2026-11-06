# Module Formation — `/formation/` (31/10/2026)

Le référentiel RH du panel TFBuddy, rangé pour être lu : **postes et sous-postes (niveaux)**,
**compétences** exigées, **formations**, **procédures opératoires**, **base de connaissances**,
**compétences produit** ; les **équipes** d'un magasin face à leur niveau ; et le **manuel
opératoire en ligne** pour la tablette. Toutes les routes API qui portent ces données sont
documentées dans le module (écran *Routes API*) et dans `docs/formation-routes.md`.

- Adresse : `https://185.180.206.46/formation/` (à la racine, comme `/marketing/` — alias Apache posé par
  `bin/deploy.sh`), et `…/consulant_bo/formation/` — rail du cockpit › **Formation** › *Module Formation ↗*.
- Manuel sur tablette : `…/consulant_bo/formation/?tablette=1#/manuel` — rail › *Manuel opératoire ↗*.
  La page s'ajoute à l'écran d'accueil (manifeste `formation/manifest.webmanifest`) et s'ouvre
  directement sur le manuel, sans barre latérale.
- **Écritures** (voir plus bas) : compétences exigées par un niveau, formations / procédures / ressources
  d'une compétence, catalogue des compétences, compétences validées et niveau d'une personne. Toute
  écriture est relue au panel avant d'être annoncée, et journalisée dans le cockpit.

## Design

Le design system « L'Atelier By » du module Marketing (référence commune du 06/11/2026), dans une
feuille unique : `public/formation/ds.css`, chargée en premier par `index.html`, `sop/tablette.html` et
`parcours/index.html`. Elle porte les deux `@font-face` (400 = `fonts/Gotham_Medium.otf`, le trait du
texte ; 700 = `fonts/Gotham_Regular.otf`, réservé aux accents : l'entrée active du rail, la valeur d'un
chiffre clé), les jetons de couleur, les composants (boutons `.btn`, champs, filtres, sélecteur segmenté
`.seg`, onglets `.page-tabs`, tableaux, pastilles et badges en rectangles de 6 px, KPI, interrupteur,
fenêtre de confirmation), le rail de 246 px, la barre supérieure, le mode incorporé et l'impression (A4,
couleurs exactes, en-têtes de tableau répétés, « Page n / N »). `formation.css`, `sop/sop.css` et
`sop/tablette.css` n'ajoutent que les pièces du module, sur ces jetons : texte en 400, aucune ombre,
aucune pilule.

**Incorporé dans le Marketing** (`/formation/?embed=1#/…` dans un cadre) : `formation.js` pose la classe
`is-embedded` (sans rail ni menu, la barre supérieure reste) ; la navigation se fait par l'ancre, la
chaîne `?embed=1` reste donc dans l'adresse, et les liens vers une autre page du module la reçoivent
(`garderEmbed`).

**Rail ouvert seul** : « Retour au cockpit » mène à l'adresse de la configuration — clé `cockpitUrl` de
`config/config.php` (écrite par `bin/deploy.sh` d'après `ALIAS_PATH`, soit `/consulant_bo/` ; `COCKPIT_URL` pour
une autre), rendue par `GET /formation/config` et lue au démarrage (pas en mode incorporé). Clé vide : le repli
d'avant (le dossier parent sous le cockpit, `/consulant_bo/` depuis `/formation/`). Pas de « Retour à l'ERP » :
la configuration n'a pas d'adresse de l'ERP (seulement la base de son API, `erpAdmin.base`, qui n'est pas une page).

**PDF du serveur** (fiche d'une SOP, Manuel opératoire — `foPdfHtml` dans `src/sop.php`, rendus par
`rapPdfRendu`) : §13 du design system, gabarit des documents de gestion (version A). Gotham intégrée (400 =
`fonts/Gotham_Medium.otf`, 700 = `fonts/Gotham_Regular.otf` pour le type de pièce, les titres d'étape et les
valeurs des références), palette du back-office, Ruby au filet d'en-tête et à l'encadré des points critiques,
pied « marque · document · Page n / N » (page de pied propre donnée à wkhtmltopdf, clé `piedHtml` de
`rapPdfRendu` ; marges de `@page` pour Chromium et l'impression du navigateur). Écrit pour wkhtmltopdf 0.12.6 :
tableaux et mm, ni variables CSS, ni flexbox, ni calc ; photos réduites par GD dans un cadre de 42 × 56 mm ; un
titre de section part avec sa première étape, une étape ne se coupe pas, l'en-tête du sommaire se répète.
`GET /formation/pdf` (fiche d'un poste, d'un niveau, d'une procédure) relaie le PDF que le panel produit : sa mise
en page est celle du panel.

Comme le reste du cockpit : HTML/CSS/JS sans dépendance ni compilation (`index.html`,
`formation.js`, `formation.css`, `catalogue.json`).

## Les écrans

| Groupe | Écran | Ancre | Ce qu'il montre |
|---|---|---|---|
| Pilotage | Tableau de bord | `#/tableau` | Comptes (postes, niveaux, compétences, formations, procédures, ressources), couverture par poste (compétences exigées avec formation, avec procédure, sans aucun support), et *ce que le panel a rendu* route par route (HTTP, lignes, durée). |
| Référentiel | Postes & sous-postes | `#/postes/{poste}/{niveau}` | L'arbre poste › niveaux ; pour un niveau : ses compétences exigées rangées par catégorie, leurs formations, procédures et ressources, les exigences et les tâches du niveau, les fiches PDF du panel. |
| | Compétences | `#/competences[/{id}]` | Le catalogue complet, filtrable (recherche, catégorie, exigées / hors niveau / sans formation / sans procédure) ; la fiche : description, méthode de vérification, niveaux qui l'exigent, formations, procédures, ressources. |
| | Formations | `#/formations[/{id}]` | Chaque formation, les compétences qu'elle enseigne, les niveaux concernés, ses formateurs. |
| | Procédures opératoires | `#/procedures[/{id}]` | Les procédures par catégorie ; la lecture d'une procédure (sections, pièces jointes, PDF, impression). |
| | Base de connaissances | `#/ressources` | Les ressources (type, catégorie, lien) et les compétences qui s'y appuient. |
| | Compétences produit | `#/produit` | Les compétences attachées aux produits et sous-recettes. |
| Équipes | Compétences des équipes | `#/equipes/{magasin}` | Pour chaque personne : poste et niveau tenus, compétences validées / exigées, ce qui manque, les formations qui le couvrent. |
| Manuel opératoire | Manuel en ligne | `#/manuel/…` | Quatre parties : Pré-ouverture · Ouverture · Opérationnel · Formation continue (`#/manuel/partie/{cle}`) ; Opérationnel garde l'entrée par poste (postes → niveaux → compétences → procédure). Vidéos dans les procédures. Recherche plein texte. Mode tablette (plein écran, cibles tactiles agrandies). |
| Documentation | Routes API | `#/api[/{famille}]` | Les 237 routes du référentiel dans le swagger : verbe, état, paramètres, corps, champs de réponse, codes — et un bouton **Tester** qui appelle la route en lecture avec le compte du cockpit. |

## Parcours compétences (31/10/2026) — `/formation/parcours/`

Pour chaque personne (employé du panel ou candidat franchisé), le chemin vers le poste qu'elle vise.
Page mobile d'abord (elle rejoindra l'application employé) ; pour l'instant une entrée dans le rail du
cockpit, *Formation › Parcours compétences ↗*. Maquettes validées : `docs/maquettes/parcours-competences/`.

- **Assistant en 3 étapes** : *Où veux-tu aller ?* (les niveaux au-dessus du sien dans son métier,
  encadrer le magasin, devenir entrepreneur) → *Ce que tu sais déjà* (compétences du niveau à cocher,
  confirmées ensuite par la manager ; formations déjà suivies) → *Ton parcours est prêt* (nombre de
  formations, rythme : tranquille 1/mois, normal 1/3 sem., rapide 1/2 sem.).
- **Frise de suivi** : le déjà fait à gauche, l'étape en cours, l'objectif au bout. Chaque formation se
  valide par ses évaluations ; la dernière cochée valide la formation **et coche dans le panel** les
  compétences qui y sont rattachées (relues). Fin du parcours : *Valider le niveau* l'affecte dans le panel.
- **Cockpit, Parcours des équipes** : une ligne par personne, frise compacte, avancement, retard.
- **Formations** : une fiche par FO (référentiel v25) avec ses évaluations et ses compétences du panel.

Règle : **les données des API ont priorité**. Ce qu'un niveau exige = ses compétences dans le panel ; les
formations du chemin = celles que le panel y rattache (évaluations « [FO-xx] … »), **complétées** par les
obligatoires de la matrice v25 ; l'ordre = le plan de formation (Étudiant, Vendeur, Gérant, Multi).
Le référentiel v25 vit dans `src/data/referentiel_v25.json` (relu de l'Excel par
`tools/formation/referentiel_excel.py`). *Locataire-gérant* et *Franchisé multi-boutique* n'existant pas
dans le panel, ce sont des destinations du référentiel (rien n'est créé dans le panel).

### Critères d'admission — Adjoint Manager, Manager, Franchisé multi-boutique

Ce qu'il faut pour être **nommé** au poste visé (et pas seulement formé). Les exigences du niveau dans le
panel (`/position-levels/{id}/requirements`, lignes sans nom écartées) passent en premier ; le cockpit
complète avec `src/data/admission.json`, sans doublon (une formation déjà exigée par le panel n'est pas
répétée). Trois sortes :

| Sorte | Vérifié par | Exemple |
|---|---|---|
| `poste` | le panel : le poste tenu | Manager ← *Adjoint Manager en poste* ; multi-boutique ← *Franchisé en activité* |
| `fo` | le panel ou le parcours : formations acquises — celles qui manquent **s'ajoutent au chemin**, en tête | Manager ← FO-28 Adjoint Manager + FO-24 Plannings |
| `manuel` | la manager (le consultant pour la franchise) dans le suivi : bouton *Valider* | ancienneté, entretien, P&L conforme, accord du franchiseur |

| Poste | Critères |
|---|---|
| Adjoint Manager (P7) | Responsable d'équipe (chef de section) · bases validées (FO-01 vente, FO-02 HACCP, FO-16 allergènes) · 6 mois dans le réseau · proposé par le manager ou le franchisé |
| Manager (P8) | Adjoint Manager en poste · FO-28 + FO-24 · 12 mois d'encadrement · entretien avec le franchisé et le consultant |
| Franchisé multi-boutique (P12) | Franchisé en activité · Plan Gérant (FO-05, FO-06, FO-24) · 12 mois sur la 1re boutique · 1re boutique rentable (P&L, audit qualité) · un Manager formé pour la tenir · accord du franchiseur |

L'assistant ne propose que ce qui est à portée : pas de poste plus bas que celui tenu (un franchisé ne vise
plus Manager) ; un critère `poste` non rempli cache la destination (il faut d'abord ce poste-là) — sauf pour
un employé **sans poste dans le panel** (fréquent) : tout reste ouvert, la manager valide. Un candidat ne voit
pas le multi-boutique. Étape 1 : *admission 1/6* sur la carte ; étape 3 : *Pour être admis* ; frise : la liste
des critères, et *Valider le niveau* n'apparaît (et n'est accepté, `409`) qu'avec **toutes les formations et
tous les critères**. Module, *Postes & sous-postes* : la carte *Critères d'admission* de chaque niveau, et
*Pas encore dans le panel* (Franchisé multi-boutique, Locataire-gérant) : critères, formations, plan.
Le multi-boutique n'est pas créé dans le panel : dès qu'un poste dont le nom contient « multi » y existe, il
prend le relais (ses niveaux, ses compétences, ses exigences).

**Créer le poste dans le panel** : sur la fiche d'un poste « à créer » (Franchisé multi-boutique, Locataire-gérant),
le bouton *Créer ce poste dans le panel* appelle `POST /formation/postes {code: P12|P9}` : le poste (`POST /positions
{name, description, app_type: EMPLOYEE}`), puis un niveau du même nom (`POST /position-levels {position_id, id_position,
name, level_order: 1}`), chaque pas relu ; un second appel reprend où le premier s'est arrêté, sans doublon. Journalisé.

### Carte des métiers et écrans refaits (31/10/2026)

Maquettes validées : `docs/maquettes/formation-donnees/` (01a, 01c, 02 à 05). Deux constats sur le panel :
**les niveaux ne sont pas cumulatifs** (chaque niveau ne liste que ses nouvelles compétences : partout, ce
qu'il faut maîtriser à un niveau = le cumul des niveaux du poste jusqu'à lui) ; **aucune compétence n'a de
procédure ni de ressource** reliée.

- **Carte des métiers** (l'accueil, `#/tableau`), sur une bascule mémorisée : *Plan des métiers* (plan de
  métro : trois lignes de métier — vente, boulangerie-pâtisserie, traiteur — qui entrent **toutes** dans
  « Pour encadrer : maîtriser les trois métiers », puis le tronc adjoint → manager, puis candidat → franchisé
  → multi-boutique, locataire-gérant en embranchement) et *Effectifs* (personnes actives, placées, sans
  poste ; le vivier de gérants : qui maîtrise 3, 2, 1, 0 métier ; chaque poste et ses niveaux avec le nombre
  de personnes). Commun : *Ce qui manque*, parcours en cours. Données : `GET /formation/carte`
  (`pcCarte`) — des **comptes**, aucun nom ; équipes lues par magasin, en cache 5 min.
- **Règle des trois métiers** (31/10/2026) : le gérant de magasin maîtrise la vente, la
  boulangerie-pâtisserie et le traiteur. Maîtriser un métier = toutes ses compétences jusqu'au dernier
  niveau avant « Responsable d'équipe » (`pcMaitrises`). Critère d'admission `metiers` : il **remplace**
  « Responsable d'équipe » pour l'Adjoint et **s'ajoute** à « Adjoint en poste » pour le Manager ; ce qui
  manque passe par les formations qui l'enseignent, ajoutées au parcours ; validable par la manager.
- **Fiche du niveau** : l'escalier du poste (+N · total), *Ce qu'il faut savoir faire* (nouveau / acquis
  avant / tout, la FO de chaque compétence), *Pour les apprendre* (FO dans l'ordre), *Pour y accéder*
  (niveau précédent + critères d'admission), *Ensuite*.
- **Compétences** et **Formations** : bascule *Liste* / *Matrice* — compétences × niveaux d'un poste (carré
  plein où elle entre, clair ensuite) ; « Qui doit suivre quoi » (formations × postes par famille, O / R).
- **Équipes** : par personne, compétences tenues sur le **cumul** de son niveau, ce qui manque, prochaine
  étape, parcours en cours ; le détail et ses gestes (valider, changer de niveau) restent.

### Lisibilité et familles de postes (31/10/2026)

- **Deux portées, trois familles** (arbre des postes, fiches, carte) : **dans la franchise** (le magasin) —
  *Opérationnel* (P1–P6), *Cadre et direction* (Adjoint, Manager), *Devenir entrepreneur* (candidat, franchisé,
  locataire-gérant, multi-boutique) ; **dans la marque** (le franchiseur, 31/10/2026) — direction opérationnelle,
  stratégie, consultants, contrôle qualité, client mystère… (famille `marque` : les postes hors matrice v25). Rangement côté serveur
  (`pcFamillePoste`, sur les noms du panel), rendu par `GET /formation/v25` (`familles`).
- **Police** (remplacé le 06/11/2026 par le design system, voir « Design ») : `fonts/Gotham_Regular.otf` est en
  réalité Gotham *Black* (nom interne « Gotham-Black ») ; la famille « Gotham Lisible » (Light / Medium / Black) a été
  retirée : le texte est en `Gotham_Medium.otf` (400), le fichier « Regular » ne sert qu'aux accents (700).
- **Pas de badges** : étiquettes en rectangles aux coins arrondis (toujours vrai : 6 px) ; les titres de carte, eux,
  reviennent en petites capitales grises (design system, 06/11/2026).
- **Tableau de bord** : six chiffres cliquables, *À compléter* (compétences sans formation, sans procédure, niveaux vides,
  postes à créer), *Parcours en cours*, *Les postes* par famille avec la part des compétences couvertes par une formation ;
  le détail technique des appels au panel est replié.

**Candidats franchisés (31/10/2026)** : l'étape « Pour qui ? » liste les dossiers en cours du CRM candidat
(atelier-espace-candidat), qui vit dans la même base que le cockpit. Sa table est `crm_candidates` (le pipeline : `name`,
`zone`, `stage_idx` → nom de l'étape dans `crm_stages` triées par `position`, `person_status` contact / candidat, `closed`,
`last_activity_at`) — pas `crm_cd_candidates`, les comptes de l'espace candidat. Elle est trouvée par information_schema
(ailleurs : un nom finissant par candidat(s) / candidate(s), puis lead(s), puis prospect(s), hors `ceo_`, `mar_`, journal
Meta et tables de comptes), gardée une heure (`ceo_app_setting` `pcCrmSource`), puis lue sans jamais y écrire : nom, étape,
zone, dates — ni e-mail ni téléphone ; les dossiers arrêtés sont écartés. Les candidats d'abord, les contacts (pas encore
passés par l'appel de qualification) sur demande ; recherche sans accents sur le nom, la zone ou l'étape ; 30 lignes, les plus
récemment actives d'abord ; un candidat qui a déjà un parcours y mène directement. Le parcours garde la référence de la fiche
(`ceo_parcours.candidat_ref`). Hors CRM, ou sans CRM dans la base, le nom se saisit à la main.

Tables (créées au premier appel) : `ceo_parcours` (personne, candidat du CRM, objectif, rythme, statut, compétences déclarées, critères d'admission)
et `ceo_parcours_etape` (une ligne par formation : évaluations, date prévue, statut, origine panel / déclarée /
parcours). Routes : `GET /parcours/destinations?employe=&shop=`, `GET /parcours/candidats`, `POST /parcours`, `GET /parcours?id=`,
`GET /parcours/liste`, `PATCH /parcours/{id}/etape/{FO}`, `POST /parcours/{id}/acquis/{cid}`,
`PATCH /parcours/{id}/admission/{cle} {fait}`, `PATCH /parcours/{id}` (rythme, arrêt, objectif atteint), `GET /formation/v25`. Chaque geste est journalisé
(type « Parcours »).

## Le modèle de données du panel

```
/positions                                   poste (Vendeuse, Boulanger…)
  └── /positions/{id}/levels                 niveau = sous-poste (Débutante, Confirmée…)
        ├── /position-levels/{id}/competencies   compétences exigées à ce niveau
        ├── /position-levels/{id}/requirements   exigences (hors compétences)
        └── /position-levels/{id}/tasks          tâches attachées au niveau

/competencies                                catalogue, rangé par
  section › catégorie › sous-catégorie       (/operational-framework/structure/…)
  └── /competencies/{id}?include=operational_procedures,trainings,knowledge_resources
        ├── procédures   (/operational-procedures/{id}, sections, pièces jointes, PDF)
        ├── formations   (/operational-framework/trainings/{id}, formateurs)
        └── ressources   (/knowledge-base/resources/{id})

/employees/{id}?include=competencies,positions   ce qu'une personne tient et a validé
/product-competences                             savoir-faire attachés aux produits
```

## Ce que dit le swagger (et ce que le module en fait)

Le swagger du panel (`https://atelierby.tfbuddy.com/api/swagger/openapi.json`, 933 chemins, lu le
31/10/2026) décrit 237 routes du référentiel formation : 13 avec un contrat de réponse, 42 décrites
sans schéma, 159 sans contrat (« runtime route »), **23 cassées**.

1. **Les parcours de carrière sont cassés.** Toutes les routes `/career-paths` et `/career-stages`
   — dont `GET/POST/DELETE /career-stages/{careerStageId}/competencies` citées dans la demande —
   sont déclarées en **500 « Registered handler requests a missing controller »**. Les postes et
   sous-postes passent par `/positions` et `/position-levels`, et l'équivalent des compétences
   d'une étape est `GET/POST/DELETE /position-levels/{id}/competencies`. Le module lit ces
   dernières ; la sonde de l'écran API permet de vérifier l'état des premières.
2. **`GET /competency-subcategories/{id}/competencies` est cassée** (TypeError côté panel : le
   tableau est passé à `sendUpdated()`). Le module filtre `/competencies` par `subcategory_id`.
3. `GET /competencies` : pas de pagination ; `section_id` filtre ; `lang_code` et `alias_type`
   sont acceptés **mais pas appliqués** par la requête SQL.
4. `GET /competencies/{id}` rend `[]` (HTTP 200) pour une compétence absente — le module répond 404.
   `include=` ajoute les liens ; chaque lien porte l'identifiant de la liaison (`id`) **et** celui de
   la cible (`training_id`, `id_operational_procedure`, `knowledge_resource_id`) : c'est la cible
   qui compte.
5. `PATCH /competencies/{id}` avec un corps vide produit un UPDATE invalide (500) ; les DELETE
   répondent « success » même si rien n'existait ; plusieurs routes 204 renvoient malgré tout un
   corps JSON.
6. Postes, niveaux, formations, procédures, base de connaissances, compétences produit, packages :
   routes **sans contrat**. Le module lit chaque champ parmi plusieurs noms plausibles (`name`,
   `level_name`, `title`, `base_name`…) et garde les autres champs scalaires (`brut`), affichés tels
   quels. Une liaison niveau ↔ compétence est lue par `competency_id` s'il existe, sinon `id`.
7. `/franchisee-employee/{id}/competences` et `/courses`, `/course-*` : l'ancien modèle « cours »
   du panel (sessions, groupes, participants), distinct des compétences RH. Documentés, non lus.
8. `/packages` (dont `POST /packages`, citée dans la demande) et `/product-packages` : les
   **conditionnements** de produits, rangés côté produit par le panel — rien à voir avec la
   formation, mais documentés puisqu'ils figurent dans la demande.

## L'API du cockpit — `GET /api/cockpit/formation/*`

Les lectures ci-dessous (les écritures ont leur tableau plus haut), toutes avec le compte consultant du panel (`PanelApi`, Paramètres › Panel
consultant). Sans compte : `503 {error, indispo: true, raison}` — l'écran le dit, et la
documentation des routes reste consultable.

| Route | Paramètres | Rend | Routes du panel lues |
|---|---|---|---|
| `/formation/referentiel` | `_cache=600`, `rafraichir=1` | `postes[]` (avec `niveaux[]` et leurs `competences[]` en identifiants), `competences[]`, `formations[]`, `procedures[]`, `ressources[]`, `competencesProduit[]`, `postesTravail[]`, `structure{sections, categories}`, `appels[]` | 10 listes en parallèle, puis `/positions/{id}/levels` par poste, puis `/position-levels/{id}/competencies` par niveau |
| `/formation/liens` | `_cache=900`, `en_plus=1,2` | `liens{idCompétence: {procedures[], formations[], ressources[]}}`, `appels[]` | `/competencies`, puis `/competencies/{id}?include=…` par compétence (800 au plus) |
| `/formation/competence?id=` | | `competence`, `liens` | `/competencies/{id}?include=…` |
| `/formation/niveau?id=` | | `niveau`, `competences[]`, `exigences[]`, `taches[]` | `/position-levels/{id}` et ses `competencies`, `requirements`, `tasks` |
| `/formation/procedure?id=` | | `procedure`, `sections[]` (`titre`, `contenu` brut), `pieces[]` | `/operational-procedures/{id}` et `/attachments` |
| `/formation/formation?id=` | | `formation`, `formateurs[]` | `/operational-framework/trainings/{id}` et `/trainers` |
| `/formation/ressource?id=` | | `ressource` | `/knowledge-base/resources/{id}` |
| `/formation/equipe` | `shop=` | `magasins[]` ; avec `shop` : `employes[]` (`nom`, `actif`, `role`, `postes[]` avec `niveauId`, `competences[]`) | `/shops/{id}/employees`, puis `/employees/{id}?include=competencies,positions` par personne (120 au plus) |
| `/formation/sonde` | `chemin=/competencies` | `code`, `ms`, `type`, `n`, `champs[]`, `exemple` | la route demandée, si elle est dans la liste blanche `FM_SONDE` |
| `/formation/pdf` | `type=poste\|niveau\|procedure`, `id=` | le PDF du panel (`application/pdf`), sinon 502 | `/positions/{id}/pdf`, `/position-levels/{id}/pdf`, `/operational-procedures/{id}/pdf` |

`appels[]` : pour chaque lecture, `{cle, chemin, code, ms, n, erreur}` — ou, pour une volée
(un appel par poste, par niveau…), `{…, ok, codes{"200": 7}}`. Le tableau de bord les affiche :
un vide et un 403 ne se confondent pas.

Les volées passent par `PanelApi::getParalleleDetail()` (même file de quatre connexions que
`getParallele()`, mais chaque réponse garde son code HTTP et sa durée ; si toute la volée revient
en 401, une reconnexion et un second passage). Le PDF passe par `PanelApi::binaire()`.

## Les écritures (31/10/2026)

| Geste (écran) | Route du cockpit | Route du panel |
|---|---|---|
| Exiger une compétence à un niveau (Postes & sous-postes) | `POST /formation/niveau/{nid}/competences {competence}` | `POST /position-levels/{nid}/competencies {competency_id, id_competency}` |
| Ne plus l'exiger | `DELETE /formation/niveau/{nid}/competences/{cid}` | `DELETE /position-levels/{nid}/competencies/{cid}`, puis l'id de liaison |
| Rattacher une formation / procédure / ressource (fiche compétence) | `POST /formation/competence/{cid}/liens {type, id}` | `POST /competencies/{cid}/trainings {training_id}` (resp. `operational-procedures {id_operational_procedure}`, `knowledge-resources {knowledge_resource_id}`) |
| La détacher | `DELETE /formation/competence/{cid}/liens/{type}/{id}` | `DELETE /competencies/{cid}/{route}/{id}`, puis l'id de liaison |
| Créer une compétence (Compétences) | `POST /formation/competences {sousCategorie, nom, description, verification}` | `POST /competencies {id_subcategory, name, description, verification_method}` |
| La modifier | `PATCH /formation/competences/{cid}` (champs changés seulement) | `PATCH /competencies/{cid}` |
| La supprimer (confirmation, impact affiché) | `DELETE /formation/competences/{cid}` | `DELETE /competencies/{cid}` |
| Valider / retirer une compétence pour une personne (Équipes) | `PUT` / `DELETE /formation/employe/{eid}/competences/{cid}?shop=` | `PUT /employees/{eid}/competencies {competency_id}` / `DELETE …/{cid}` |
| Affecter un poste et un niveau, passer au niveau suivant | `PUT /formation/employe/{eid}/poste?shop= {poste, niveau}` | `PUT /employees/{eid}/positions {position_id, level_id}` |

Règles : (1) **tout est relu** — le panel répond « success » à un DELETE qui n'a rien supprimé et à un
POST dont il ignore le corps ; un écart entre la demande et la relecture est rendu en erreur (502) ;
(2) un retrait essaie l'identifiant de la **cible**, puis celui de la **liaison** si la cible est encore
là (les routes sans contrat ne disent pas lequel elles attendent) ; (3) chaque écriture réussie écrit
une ligne au journal du cockpit (type « Formation ») et **corrige sur place** les lectures en cache
(référentiel, liens, équipe du magasin) — l'écran suivant montre l'état écrit sans tout relire.
`PanelApi::requete()` porte ces appels (code HTTP et corps de la réponse, reconnexion sur 401).
Les erreurs du panel (403, 500…) s'affichent dans la fenêtre, avec sa description.

**Accès** : sur le serveur, le cockpit tourne sans authentification (`auth => false`). Quiconque
atteint l'adresse peut donc écrire dans le référentiel RH du panel, avec le compte consultant du
cockpit — comme pour les autres écritures du cockpit. Activer l'authentification intégrée
(`COCKPIT_AUTH=1`) la protège.

## Sécurité

- **Rien de personnel ne sort** : `fmSecret()` retire, à toute profondeur, les clés de mots de
  passe, PIN, jetons, hachages, identifiants de connexion, téléphone, e-mail, taux horaire, IBAN,
  adresse, date de naissance, session. L'écran Équipes ne rend que nom affiché, rôle, postes et
  compétences.
- **La sonde n'ouvre pas le panel** : GET seulement, et seulement les chemins du référentiel
  formation (`FM_SONDE`) — ni authentification, ni pièces jointes, ni listes d'employés.
- **Le contenu des procédures est nettoyé** avant affichage (`riche()` dans `formation.js`) :
  balises de texte seulement, liens `http(s)`/`mailto` ouverts dans un nouvel onglet, images
  `http(s)`/`data:image` ; scripts, styles, cadres, formulaires et attributs `on…` sont retirés.
- Le module suit l'authentification du cockpit (`authEnabled()`) comme toute route `/api/cockpit`.

## Temps de réponse

Première lecture : 10 listes + 1 appel par poste + 1 appel par niveau, quatre de front ; puis les
liens, un appel par compétence. Les deux lectures sont gardées (`_cache=600` et `_cache=900`, le
cache du Résultat) : une copie périmée part tout de suite et se refait en arrière-plan. Le bouton
**Rafraîchir** relit tout sans cache. Tant que les liens ne sont pas lus, les comptes de formations
et de procédures affichent « … » et un bandeau le dit.

## Maintenance

- Régénérer le catalogue après une évolution du panel :

  ```bash
  curl -sS -o /tmp/openapi.json https://atelierby.tfbuddy.com/api/swagger/openapi.json
  python3 -I tools/formation/catalogue.py /tmp/openapi.json public/formation/catalogue.json docs/formation-routes.md
  ```

  Les familles, les notes en français, les routes citées dans la demande et celles que le module
  lit sont en tête du script.
- Tests (sans base ni réseau) : `php bin/tests_formation.php` — filtrage des données sensibles,
  liste blanche de la sonde, normalisation des liaisons.

## Suites possibles (non faites)

- Écrire aussi les formations, procédures et ressources elles-mêmes (création, contenu des sections),
  les compétences produit et leurs traductions, et `POST /packages` : routes sans contrat, corps à
  mesurer d'abord à la sonde.
- Manuel **hors ligne** sur la tablette (service worker et copie des procédures).

## Manuel opératoire en quatre parties, vidéos, tablette (1er nov. 2026)

Le client : « séparer le Manuel opératoire en Pré-ouverture · Ouverture · Opérationnel · Formation
continue », « le tout sur tablette », « réadapter pour une optimisation sur tablette de toutes les vues
et de la lecture », « sur certaines procédures, des vidéos intégrées ».

- **Accueil du manuel** = les quatre parties, dans l'ordre de la vie du magasin (cartes 1 à 4 : nom,
  une ligne, nombre de procédures ; « · N postes » pour Opérationnel, « · N formations » pour Formation
  continue). Une partie (`#/manuel/partie/{preouverture|ouverture|operationnel|formation}`) : barre des
  quatre parties collée en haut, ses procédures rangées par thème (catégorie du panel) ; Opérationnel
  commence par « Par poste » (l'ancien manuel) ; Formation continue finit par les formations du réseau.
- **La partie d'une procédure** : le panel ne la connaît pas (ses procédures sont rangées par thème) ;
  elle vit dans le cockpit, table `ceo_manuel_procedure` (`procedure_id`, `partie`, `videos` JSON,
  `maj_le`). Sans réglage, elle se **déduit** de la section du panel : « Formations & Contrôle
  Continu » (ou une catégorie « Formation… ») → Formation continue, le reste → Opérationnel.
  Pré-ouverture et Ouverture ne se remplissent qu'à la main. Réglage : page de la procédure (manuel ou
  Référentiel › Procédures), « Partie du manuel », quatre boutons et « ↺ Partie déduite » ; la liste
  des procédures a une colonne « Partie du manuel » (pleine = rangée, pâle = déduite). Rien n'est
  écrit au panel ; journal « Formation ».
- **Les 53 formations du référentiel (FO-xx) dans les quatre parties** (client : « réorganiser les
  formations dans ces 4 sections ») : chaque partie liste ses formations (carte : code, nom, fréquence,
  évaluations, qui forme) après ses procédures ; la fiche d'une formation se lit dans le manuel
  (`#/manuel/formation/FO-xx`, liens vers les compétences du manuel, sans retour au référentiel).
  Répartition **par défaut**, versionnée dans `src/data/manuel_formations.json` (avec la règle de chaque
  partie) : Pré-ouverture 9 (FO-29 recrutement, FO-30 intégration, FO-31 à FO-37 le parcours du
  candidat franchisé) · Ouverture 4 (FO-38 stratégie d'ouverture, et ce que tient le garant :
  FO-20 vitrine, FO-24 planning et effectif, FO-27 casse et invendus) · Opérationnel 30 (le métier au
  quotidien, vente, production, hygiène, stocks, encadrement de la boutique, click & collect, webshop) ·
  Formation continue 10 (FO-39 à FO-45, FO-48 à FO-50 : master class, veille, finance avancée,
  multi-boutique, communication locale, réglementation avancée, animation commerciale, e-commerce).
  Un consultant la change formation par formation (fiche de la formation, « Partie du manuel » ;
  « ↺ Par défaut »), table `ceo_manuel_formation` ; la liste des formations a une colonne « Partie du
  manuel ». Une formation du panel sans code FO se lit dans Formation continue (« Autres formations du
  panel »).
- **La fiche d'une formation dans le manuel = « une fiche à lire »** (proposition A des maquettes
  `docs/maquettes/manuel-formation/`, choisie le 1er nov. 2026, « avec au clic une fenêtre avec les
  détails, vidéo, matériel didactique, PDF intégré ») : `#/manuel/formation/FO-xx` — code et rubrique
  du manuel (`S.v25.manuel`, envoyé par `/formation/v25`), titre, la phrase de la rubrique, quatre
  repères (fréquence, nombre d'étapes, qui la suit, qui la valide — le rôle, sans le nom), « Ouvrir la
  procédure » (la première procédure du panel rattachée à ses compétences ; sinon la référence MO §),
  « Imprimer », puis les sections numérotées : Ce que vous saurez faire · Au programme (bloc du plan
  de formation) · Matériel didactique · Comment on la valide · En boutique, ensuite (checklist du bloc)
  · Pour qui (obligatoire ; recommandée en pointillés). Une section vide n'est pas affichée.
  **Au toucher, une fenêtre de détail** (`S.modale.type = 'mn'`, `fenetreManuel`) : une compétence
  (description, vérification, procédures, supports), le programme (contenu, indicateurs, formations
  du même bloc), un support (son lecteur), une étape (sa description du panel, « Validée par » sur
  l'étape de validation, ses supports). Grande fenêtre (980 px), plein écran sous 760 px, ✕ et Échap.
  **Supports** : rangés dans le cockpit (`ceo_manuel_formation.supports`, JSON `[{url, titre, etape}]`,
  https, vingt au plus, `etape` = index de l'étape ou null pour la formation entière), plus les
  ressources du panel rattachées à ses compétences et leur lien de support. Lecteurs : vidéos (comme les
  procédures), PDF (cadre + « Ouvrir en plein écran », l'iPad n'affiche qu'une page dans un cadre),
  Google Docs / Slides / Sheets et fichiers Drive en aperçu (`/preview` : le document doit être partagé
  à « toute personne disposant du lien »), autre adresse = un bouton « Ouvrir ». Ajout et retrait par un
  consultant (hors mode tablette) : « Matériel didactique », adresse, titre, étape. La fiche du
  référentiel (`#/formations/FO-xx`) ne change pas.
- **Vidéos** : en tête de la procédure, avant son texte. Celles ajoutées dans le cockpit (adresse +
  titre, dix au plus, https seulement) puis celles que le panel cite déjà (liens du contenu et des
  sections, pièces jointes vidéo). Lues dans la page : YouTube (youtube-nocookie), Vimeo (avec le
  hachage d'une vidéo non répertoriée), Loom, fichiers .mp4/.webm/.mov ; Google Drive seulement si on
  l'ajoute comme vidéo (un lien Drive du texte peut être un PDF). Le cadre est construit depuis
  l'**identifiant** extrait par expression régulière, jamais depuis l'adresse brute. Une page ne se
  redessine pas sous une vidéo en cours (cadre touché, fichier qui joue) : ce qui arrive pendant ce
  temps se dessine quand on la quitte. Les champs d'ajout ne redessinent pas la page (`data-vinput`).
- **Mode tablette** (`?tablette=1`, l'icône du manifeste) : ni barre latérale, ni « Rafraîchir » (une
  relecture complète du panel est un geste de consultant), ni réglages (partie, vidéos) ; texte de
  lecture à 18 px, colonne de 860 px, sections aérées.
- **Toutes les vues sur écran tactile** (`@media (pointer: coarse)`) : boutons, onglets, liens du fil
  d'Ariane, entrées du menu, lignes de l'arbre des postes et des compétences à 44 px ; puces-liens à
  40 px ; champs à 16 px (sinon l'iPad zoome). Contrôlé sur 14 vues à 1180 × 820, 1024 × 768,
  820 × 1180 et 768 × 1024 en tactile : aucun chevauchement, aucune cible sous 40 px, aucun
  débordement horizontal. La page mobile des parcours s'élargit à 720 px sur tablette.
- Routes : `GET /formation/manuel` → `{parties[], procedures{id: {partie, videos[{url, titre}], majLe}}, formationsDefaut{FO: partie}, formations{FO: partie}}` ;
  `PATCH /formation/manuel/formation/{FO} {partie?: cle | null, supports?: [{url, titre, etape}]}` (null = répartition par défaut ; clé absente = inchangée) ;
  `PATCH /formation/manuel/procedure/{id} {partie?: cle | null, videos?: [{url, titre}]}` (clé absente =
  inchangée ; partie inconnue ou adresse refusée = 422).
- Traductions : `lang_code` n'est pas appliqué par le panel aujourd'hui ; le module lit le français.

## Standards (SOP) — les SOP du réseau en FR et en NL (06/11/2026)

Les 25 SOP du Drive « L’Atelier Halle — SOP — dernières versions retrouvées » sont reprises **en entier** et rangées
dans **nos tables** (`src/sop.php` : `ceo_sop`, `ceo_sop_version`, `ceo_sop_suivi`, `ceo_sop_personne`, créées et
remplies au premier appel depuis `data/formation/sops/*.json`). Nos bases sont la source, le Drive l’archive.
Chaque SOP existe en français et en néerlandais (la langue d’origine fait foi, l’autre est traduite à la reprise,
« à relire » jusqu’à relecture). Maquettes validées : `docs/maquettes/formation/` ; relevé de la reprise (corrections,
points à confirmer) : `data/formation/REPRISE.md`.

| Où | Ce qu’on y trouve |
|---|---|
| Barre latérale › **Standards (SOP)** › *Les SOP du réseau* (`#/sop`) | les SOP par famille (01 Magasin et vente, 02 Cuisson et production, 03 Nettoyage et fermeture, 04 Fiches de référence), FR/NL, compétence, partie du manuel, progression du réseau |
| une SOP (`#/sop/{id}`) | les étapes FR et NL côte à côte (ou une langue), photos, PDF FR et NL, **partie du manuel** (réglable, sinon déduite : ouverture → Ouverture, le reste → Opérationnel), l’équipe d’un magasin face à elle, points critiques, quiz, versions, relevé de la reprise |
| *Compétences et SOP* (`#/sopcomp`) | réseau × compétence, magasin × personne, ce que la responsable doit voir faire, réglages de l’équipe (postes, langue, qui valide), le fil |
| *Rédaction et versions* (`#/sopred`, `#/sop/{id}/editer`) | marquer une langue relue, rattacher une **compétence du panel** (`GET /competencies`), statut, nouvelle SOP ; l’éditeur FR/NL publie une nouvelle version (précision, ou « le geste change » : les personnes validées repassent à revoir) |
| **Manuel opératoire** › chaque partie | un bloc *Standards (SOP)* à côté des procédures et des formations FO ; l’accueil compte les SOP de chaque partie |
| **Tablette du poste** `formation/sop/tablette.html?shop=&poste=&langue=` | l’onglet SOP de l’app Cuisine y mène. Depuis le lot 5 (voir « Tablettes des magasins ») le **PIN du magasin** ouvre d’abord l’appareil, une fois ; puis son nom, son PIN (vérifié au panel, `GET /shops/{id}/employees`, comme les checklists), le pas à pas, le quiz ; la **responsable du magasin** valide « vu faire » avec son PIN |

**Compétences** : une compétence est acquise quand chacune de ses SOP publiées (pour les postes de la personne) a été vue
faire à la version en vigueur ; elle est alors **cochée au panel** pour la personne (`PUT /employees/{id}/competencies`,
le même appel que « valider la compétence » de l’écran Équipes, relu puis journalisé). Un refus du panel est dit sur la
tablette ; la validation « vu faire » reste enregistrée.

Les écrans SOP vivent dans `public/formation/formation-sop.js` (chargé avant `formation.js`, qui l’appelle par ses
accroches : `NAV`, `ICONES`, `ECRANS`, `apres()`, l’accueil et chaque partie du manuel) et `sop/sop.css` ; ils
s’affichent même quand le référentiel du panel est illisible. Routes : `docs/contrat-api.md` § Formation · Standards (SOP).

## Manuel du réseau — fusion du 06/11/2026

Le Manuel opératoire, les SOP et les guides parlaient du même métier dans trois classements. Décisions du client
(`docs/maquettes/fusion-manuel/PLAN.md`, validées le 06/11/2026) :

1. **Colonne vertébrale** : les 8 domaines D1–D8 du référentiel v25 (`src/data/referentiel_v25.json`, clé `manuel`)
   et leurs 36 sous-catégories. La **phase** (les quatre parties du Manuel opératoire : pré-ouverture, ouverture,
   opérationnel, formation continue) et le **poste** (P1–P12 du v25) sont des étiquettes : les vues « par phase » et
   « par poste » se calculent, rien n'y est saisi.
2. **Éditeur unique** : ce module. Le CRM lit `GET /formation/ref` (`nl_cockpit_get`) et ne garde que ce qui est
   propre à un dossier candidat.
3. **Clé des compétences** : le code `C-…` du v25 (56 codes). Le panel TF Buddy garde l'état par employé ; chaque
   code est relié à UNE compétence du panel, et le « vu faire » coche celle-là.

**Code** : `src/referentiel.php` (fonctions `rf*`, chargé après `src/sop.php`, `ensureReferentiel()` appelé par
l'installeur après `ensureSop()`). Écrans : `public/formation/formation-sop.js` (bloc « Manuel du réseau ») et
`public/formation/sop/ref.css`. Tablette : `public/formation/sop/tablette.js` (« Ma journée »).

**Tables** (créées au premier appel, `CREATE TABLE IF NOT EXISTS`) :

| Table | Contenu |
|---|---|
| `ceo_ref_domaine` | les 8 domaines : code, rang, libellés FR et NL, `nl_relu` |
| `ceo_ref_souscat` | les 36 sous-catégories (`cle` = « D5.3 », rang dans l'ordre du v25), description, compétences, formations et checklists du v25 (JSON) ; semées une fois (`INSERT IGNORE`), jamais réécrites par le semis |
| `ceo_ref_corresp` | code `C-…` → compétence du panel : `etat` `valide` (choisie par une personne), `certain` (proposée sans ambiguïté), `propose` (à trancher, jamais utilisée pour cocher), `aucun` ; score, trois meilleures propositions |
| `ceo_sop` (+ colonnes) | `souscat`, `phases`, `postes`, `competences`, `formations`, `checklists` (listes JSON) |

**Libellés NL** des domaines, sous-catégories, postes et phases : fournis le 06/11/2026, **à relire** (traduction de
travail). Une sous-catégorie dont le NL est enregistré dans le Classeur passe « relue ».

**Classement initial** : les 25 SOP reçoivent une fois le classement du contrat de la fusion (sous-catégorie,
phases, postes, compétences, formations, checklists ; constante `RF_CLASSEMENT_2026_11`), seulement celles qui n'ont
pas encore de sous-catégorie ; drapeau `rfClassement_2026_11` dans `ceo_app_setting`.

**Correspondance des compétences** : proposée automatiquement depuis les compétences du panel (`GET /competencies`,
même lecture que le module, gardée un jour) — noms ramenés à leurs mots (sans accents ni casse, mots vides retirés),
coefficient de Dice entre les mots du code et du nom v25 et ceux du nom du panel. `certain` seulement sans
ambiguïté : meilleur score ≥ 0,75, avec 0,25 d'avance sur le deuxième, et aucun autre code n'a un score aussi haut
sur la même compétence du panel (ni ne l'a déjà validée). Sinon `propose` avec les trois meilleures, ou `aucun`.
Une ligne tranchée par une personne (`valide`, ou `aucun` choisi) n'est jamais réécrite. La première lecture de
l'écran Compétences lance la proposition quand la table est vide.

**« Vu faire »** : quand la responsable valide une SOP, chaque code `C-…` de la SOP est évalué pour la personne —
acquis quand chacune des SOP publiées qui portent ce code et la concernent (un de ses postes v25, ou commencée) a été
vue faire à la version en vigueur. Acquis et relié au panel (`valide` ou `certain`), il est coché au panel
(`foCocherPanel` : `PUT /employees/{id}/competencies`, relu, journalisé). Repli : une SOP sans aucun code relié au
panel garde l'ancienne règle (la compétence du panel rattachée dans Rédaction, `competence_id`).

**Postes d'une personne** : la tablette connaît ses postes de tablette (vente, cuisson, production, nettoyage) ; ils
se traduisent en postes v25 (`RF_POSTES_PERSONNE` : vente → P3, cuisson et production → P1) et une personne qui
valide « vu faire » tient aussi P4, P5, P8. Une SOP sans postes v25 garde l'ancienne règle (son poste de tablette).

**Brouillons** : une SOP reprise du Manuel opératoire du CRM arrive au statut `brouillon` (FR seul, le NL à écrire) :
ni sur la tablette, ni dans le Manuel imprimé, ni dans les parties du Manuel en ligne. Rédaction › Statut la fait
passer « à valider » puis « publiée ».

| Où | Ce qu'on y trouve |
|---|---|
| Barre latérale › **Manuel du réseau** › *Classeur* (`#/rfclasseur/D5`) | les 8 domaines (standards, FO, compétences, checklists), le tableau du domaine (sous-catégorie, standards, formations, compétences, checklists, phases ; « aucun standard », compétence sans standard, code cité mais non décrit), la chaîne de chaque standard (standard → formation → compétence et sa compétence du panel → contrôle), les standards à classer ; « Modifier » une sous-catégorie (NL, description) ; « Reprendre le Manuel du CRM » |
| *Par phase* (`#/rfphase/preouverture`) | pour la phase : les standards à appliquer (par domaine), les formations à apprendre (celles des standards et celles que le Manuel opératoire range dans cette partie), les compétences à prouver |
| *Par poste* (`#/rfposte/P3/operationnel`) | la matrice P1–P12 × phases : standards du poste et de la phase, formations (des standards, et celles que le v25 attend pour ce poste dans cette partie), compétences ; un clic détaille la cellule |
| *Standards* (`#/sop`) | l'écran des SOP (sous-catégorie ou « à classer » sur chaque carte) ; la fiche gagne le bloc **Classement** (sous-catégorie, phases, postes, compétences, formations, checklists ; propositions de la sous-catégorie) |
| *Compétences* (`#/sopcomp`) | onglet « Correspondance avec le panel » : couverture, filtres, chaque code avec ses propositions à trancher en un clic, une autre compétence du panel, ou « Aucune » ; les compétences du panel sans code. Onglet « Acquisition en magasin » (`#/sopcomp/magasins`) : l'ancien écran (réseau, magasin, équipe, fil) |
| *Rédaction* (`#/sopred`) | inchangée (statut `brouillon` en plus) |
| **Tablette du poste** › *Ma journée* | une fois identifiée, les standards de la personne rangés par moment (ouverture, journée, après-midi, fermeture, autres), avec leur état (à lire, lu, quiz réussi, vu faire, à revoir) ; « Toutes les SOP » garde la liste d'avant |

**Reprise du Manuel opératoire du CRM** (`POST /formation/ref/importer-crm`, bouton du Classeur) : quand la table
`crm_mo_procedures` existe dans la base (même base en production), chaque procédure devient une SOP `brouillon`
(id `crm-<id>`, source `crm:<id>`, idempotent), sauf AC-01, AC-02, AC-03 (les checklists des SOP d'ouverture et de
fermeture). Chapitre → sous-catégorie par son nom (mêmes mots, sinon le meilleur recouvrement s'il est unique) ;
postes vente → P3, production → P1, traiteur → P2, responsable → P8 ; partie → phase ; moment avant → ouverture,
service et rush → journée, fermeture → fermeture ; étapes → une section « Étapes » (titre, consigne, point critique) ;
formations des étapes et de la procédure → formations ; compétence `C-…` reconnue → compétences. Les photos restent
dans le CRM (comptées dans le bilan, notées sur la SOP) ; les vidéos sont notées dans le relevé.

**Essais locaux** : `tests/formation/faux_panel.php` imite les routes du panel que le module appelle (107
compétences et trois personnes FICTIVES, `tests/formation/fixtures/faux_panel.json`) :
`php -S 127.0.0.1:8191 tests/formation/faux_panel.php`, puis le cockpit avec `PANEL_API_BASE=http://127.0.0.1:8191
PANEL_API_PHONE=essai PANEL_API_PASSWORD=essai` (sans réglage `panelApi` en base, qui primerait).

**Reste à décider** : six codes cités par les sous-catégories du v25 n'ont pas de ligne dans sa feuille des
compétences (`C-ID.ADN`, `C-ID.FB`, `C-ID.MO`, `C-MKT.Digital`, `C-MKT.Local`, `C-MKT.Saisons`) : affichés en
pointillés dans le Classeur, refusés sur un standard tant qu'ils ne sont pas décrits. L'onglet « Acquisition en
magasin » compte encore par l'ancienne compétence du panel de chaque SOP.

Routes : `docs/contrat-api.md` § Formation · Manuel du réseau.

## Tablettes des magasins — une seule tablette par magasin (lot 5 de la fusion, 06/11/2026)

Décision du client : la tablette du **Cockpit** (`public/formation/sop/tablette.html`) devient la seule. L’ancienne
tablette du CRM (`/crm/manuel/`, PIN du magasin, plan de la journée) renvoie ici (CRM : page « La tablette du magasin
a déménagé », `GET /mo/demenage`, plus aucune session ouverte là-bas).

- **Deux PIN** : le **PIN du magasin** (6 chiffres, un par tablette, ni `000000` ni une suite) ouvre l’APPAREIL, une
  fois ; la session est gardée sur l’appareil (jeton aléatoire dans `localStorage` `foTablette`, envoyé en en-tête
  `X-Tablette`, et cookie `ceo_tablette` HttpOnly, SameSite=Lax, 400 jours, chemin = le dossier de l’API). Ensuite,
  comme avant, le **PIN de l’employé** (panel) l’identifie et signe : lu, quiz, « vu faire » validé par la
  responsable, « Ma journée ».
- **Le magasin vient de la session** de l’appareil : `?shop=` ne fait plus que pré-choisir le magasin à l’écran du
  PIN (son nom s’affiche s’il a une tablette active). Le lien de l’app Cuisine (`?shop=…&poste=…&langue=…`) marche
  toujours : `poste` et `langue` gardent leur rôle ; si l’appareil est déjà ouvert pour un autre magasin, la tablette
  le dit (bandeau) et reste sur le sien. Un jeton d’employé ne vaut que sur une tablette de son magasin.
- **Essais du PIN du magasin limités** (table `ceo_tablette_essai`, adresse IP hachée avec le secret du cockpit) :
  5 erreurs par adresse en 15 minutes, puis 10 erreurs en tout par heure ou 50 par jour suspendent les essais
  (429 ; noté une fois par heure au journal) ; un refus attend 0,4 s. Une session déjà ouverte n’est pas touchée.
  Jamais de PIN ni de jeton dans le journal ; le jeton n’est gardé qu’haché (sha256).
- **Gestion** : barre latérale › Manuel du réseau › **Tablettes** (`#/tablettes`, `formation-sop.js`, styles
  `sop/tablettes.css`) — l’adresse de la tablette (⧉ copier), « ＋ Tablette de magasin » (nom, boutique du panel),
  puis par tablette : nom et boutique (enregistrés au départ du champ), PIN, « ⧉ Copier adresse + PIN » (l’adresse
  porte `?shop=`), « ↻ Nouveau PIN » (tous ses appareils sont déconnectés), appareils connectés (« Déconnecter »),
  gestes du jour sur la tablette, « Archiver » (appareils déconnectés, le PIN n’ouvre plus rien ; « ↺ Réactiver »
  garde le même PIN). Signalés : « Sans boutique » (la tablette s’ouvre mais ne montre rien), « Même boutique qu’une
  autre tablette ». Pas de gestion en mode tablette.
- **Reprise des magasins du CRM** (même base) : chaque ligne de `crm_mo_magasins` devient une tablette ici, une fois
  (`crm_id` unique), avec la boutique (`store_id` = `shops.id`) et le **même PIN** quand il est lisible et libre — les
  équipes n’ont rien à changer ; sinon un PIN neuf, dit dans le bilan (réglage `tabRepriseCrm`, carte « Reprise du
  Manuel du CRM » de l’écran, journal sans PIN). Lancée par l’installeur (`ensureTablettes`), à chaque lecture de
  l’écran et par « Reprendre les magasins du CRM ». Les appareils ouverts au CRM ne sont pas repris (autre adresse) :
  la tablette retape le PIN du magasin une fois.
- **Code** : `src/tablettes.php` (fonctions `tab*`, chargé après `src/referentiel.php`) ; la garde des routes de la
  tablette est dans `sopRoute` (`tabGarde`), le magasin dans `foShopTablette`, le jeton dans `foJetonTablette`
  (`src/sop.php`). Écran du PIN : `tablette.js` (`vuePinMagasin`, FR | NL, pavé et clavier), styles
  `sop/tablette-magasin.css`.

| Table | Contenu |
|---|---|
| `ceo_tablette_magasin` | une tablette : `nom`, `shop_id` (boutique du panel), `pin` (6 chiffres, unique), `pin_le`, `archive`, `crm_id` (magasin du CRM repris), `cree_le`, `cree_par`, `maj_le` |
| `ceo_tablette_acces` | un appareil ouvert : `magasin_id`, `appareil` (d’après le navigateur), `jeton_hash` (sha256), `cree_le`, `vu_le`, `revoque` |
| `ceo_tablette_essai` | un essai de PIN du magasin : `ip_hash`, `ok`, `cree_le` (purgé après 7 jours) |

Routes : `docs/contrat-api.md` § Formation · Tablettes des magasins.


### Les deux guides édités dans le Cockpit (lot 4b, 06/11/2026)

Le Guide pré-ouverture et le Guide des formations et compétences s'éditaient dans le CRM (écran Guides). Ils
s'éditent désormais ici ; le CRM les lit dans `GET /formation/ref` (`preouverture`, `blocs`, `poles`) pour son écran
Guides (en lecture, « Modifier dans le Cockpit ↗ »), l'application des formateurs, l'attestation, le rapport et les
documents qu'il génère (Guide pré-ouverture : `data-guide` ; Guide des formations : `data-formation`). Ce qui est
propre à un dossier candidat reste au CRM : dates du countdown, formateurs désignés, validations et signatures,
attestation, pack de signature, renouvellements faits.

**Code** : `src/referentiel_guides.php` (fonctions `rg*`, `ensureRefGuides()` appelée par `ensureReferentiel()`).
Écrans : `public/formation/formation-sop.js` (bloc « les deux guides ») et `public/formation/sop/ref.css` (`rf-g*`).

| Table / réglage | Contenu |
|---|---|
| `ceo_ref_po_section` | sections du Guide pré-ouverture : `cle`, `rang`, `type` (`points` / `contacts`), titre `fr` / `nl`, `intro_fr` / `intro_nl` |
| `ceo_ref_po_point` | leurs points (clé `section` + `cle`) : `rang`, libellé `fr` / `nl`, `qui`, `j` (jours avant l'ouverture, NULL = sans date), `preuve_fr` / `preuve_nl` |
| `ceo_ref_bloc` | les 11 blocs : `n`, titre, `role` / `role_nl`, `fo`, `heures` (et `heures_semis`, celles du départ) |
| `ceo_ref_item` | leurs points : `id` (code `C-…` ou `FO-…`, comparé à l'octet près), `bloc`, `rang`, libellés et descriptions FR/NL, `fo`, `phase` (`pre`/`ouv`/`con`), `bloq`, `poles`, critère accepté (`critere_fr`/`_nl`, `critere_accepte`) et proposé (`critere_prop_fr`/`_nl`), `renouvellement`, `heures`, `fiche`, `fiche_nom`, `video`, `profils` (JSON), `semis` (valeurs du départ, JSON) |
| `ceo_app_setting` › `rfPoles` | noms des 5 pôles FR/NL et repère du réseau (`{fr, nl, repere, le, par}`) |
| `ceo_app_setting` › `rfGuidesReprise_2026_11` | bilan de la reprise des réglages du CRM (fait une fois) |

**Semis** : `data/formation/guides/preouverture.json` et `formations.json`, recopiés du CRM par
`php tools/formation/guides_depuis_crm.php <atelier-espace-candidat>/app/crm/api/lib` (les fichiers du CRM ne sont pas
lisibles d'ici en production) : `GD_SECTIONS_DEFAUT`, `formation_blocs.php`, les critères proposés de
`formation_criteres.php`, `FMT_HEURES_DEFAUT`, `FMT_POLES_DEFAUT`. Posés quand les tables sont vides (une écriture
refuse un guide vide ; un point retiré n'est jamais remis).

**Reprise UNE fois des réglages du CRM** (`crm_settings`, même base en production ; drapeau
`rfGuidesReprise_2026_11`) : `guide_preouverture` (le guide entier, s'il passe les règles du contrat), `formation_referentiel`
(par compétence : phase, bloquante, pôles, critère accepté `crit`/`critNl`, renouvellement, heures, fiche, nom de la
fiche, vidéo, profils), `formation_blocs_heures`, `formation_poles` (l'ancien 5e pôle « Autonomie » suit le défaut,
comme dans le CRM), `formation_repere`. Sans `crm_settings` dans la base, le contenu de départ sert. Essai local du
06/11/2026 : réglages fabriqués par les routes du CRM, repris à l'identique (guide, 45 points, heures, pôles, repère).

| Où | Ce qu'on y trouve |
|---|---|
| **Manuel du réseau** › *Pré-ouverture* (`#/rfpre`) | FR \| NL ; les sections (glisser par ⋮⋮ ou flèches, ajouter, supprimer) ; la section choisie : titre, type, introduction, ses points (libellé, qui, échéance J-, preuve ; glisser, flèches, retirer, ajouter) ; « Enregistrer le guide » envoie tout le guide (`PUT /formation/ref/preouverture`), « Annuler » repart du serveur ; « N sans NL » compte les libellés néerlandais manquants |
| *Formations et compétences* (`#/rfform/<n>/<id>`) | recherche dans les 45 points ; chiffres clés (heures, bloquants, critères proposés, repère) ; « Les 5 pôles » (noms FR/NL, repère) ; les 11 blocs (heures, critères proposés) ; le bloc : « Modifier le bloc » (titres, rôles, FO, heures), « Accepter les N critères proposés », ses points (glisser ou flèches pour ordonner, « Ajouter un point ») ; la fiche du point : libellés et descriptions FR/NL, « Réussi si… » (« Accepter la proposition », « Revenir à la proposition »), phase, bloquante, 5 pôles, renouvellement, heures, FO, bloc, fiche, vidéo, profils, « Valeurs de départ », « Retirer le point » |

Au-delà de 1640 px les blocs, le tableau et la fiche sont côte à côte ; en dessous, les blocs passent au-dessus, en
grille ; sous 1100 px tout s'empile.
