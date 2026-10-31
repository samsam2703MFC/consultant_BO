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

Celui du module Marketing, **à l'identique** : `public/formation/formation.css` commence par une
copie conforme des règles de `tools/assistant-src/src/styles/global.css` (le front du module
marketing) — jetons de couleur, barre latérale de 246 px (logo, « Module … », retour, enseigne,
groupes), barre supérieure (sur-titre + titre de 26 px), cartes, tableaux, filtres, cartes de
campagne, étapes de l'assistant, cartes de choix. Seuls changent les chemins des polices : la page
vit sous `/formation/`, les fontes sont celles du cockpit (`../assets/ds/fonts/`, mêmes fichiers
Gotham que la marque, mappés comme le marketing et la tablette : 400 → `Gotham_Regular.otf`,
500 → `Gotham_Medium.otf`). La partie 2 du fichier ajoute les pièces propres au module (arbre des
postes, verbes HTTP, lecture d'une procédure, mode tablette) avec les mêmes jetons.

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
| Manuel opératoire | Manuel en ligne | `#/manuel/…` | Postes en cartes → niveaux en étapes → compétences en cartes → procédure à lire. Recherche plein texte. Mode tablette (plein écran, cibles tactiles agrandies). |
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

Tables (créées au premier appel) : `ceo_parcours` (personne, objectif, rythme, statut, compétences déclarées)
et `ceo_parcours_etape` (une ligne par formation : évaluations, date prévue, statut, origine panel / déclarée /
parcours). Routes : `GET /parcours/destinations?employe=&shop=`, `POST /parcours`, `GET /parcours?id=`,
`GET /parcours/liste`, `PATCH /parcours/{id}/etape/{FO}`, `POST /parcours/{id}/acquis/{cid}`,
`PATCH /parcours/{id}` (rythme, arrêt, objectif atteint), `GET /formation/v25`. Chaque geste est journalisé
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
- Traductions : `lang_code` n'est pas appliqué par le panel aujourd'hui ; le module lit le français.
