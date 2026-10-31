# Routes API du référentiel formation (panel TFBuddy)

> Fichier GÉNÉRÉ par `tools/formation/catalogue.py` — ne pas éditer à la main.
> Swagger du panel TFBuddy (/api/swagger/openapi.json), 933 chemins · généré le 2026-10-31. Le module /formation lit les routes marquées « lue ».
> Contexte, modèle de données et constats : `docs/formation.md`.

**237 routes** — 13 contrat documenté, 42 décrite, sans schéma, 159 sans contrat, 23 cassée (500).

| Famille | Routes |
|---|---:|
| [Postes & sous-postes (niveaux)](#postes) | 28 |
| [Parcours de carrière](#carriere) | 22 |
| [Liens d’une compétence](#liens) | 6 |
| [Compétences RH](#competences) | 6 |
| [Formations](#formations) | 10 |
| [Structure fonctionnelle](#structure) | 27 |
| [Postes de travail](#postesTravail) | 13 |
| [Procédures opératoires](#procedures) | 39 |
| [Base de connaissances](#connaissances) | 12 |
| [Employés : postes et compétences](#employes) | 10 |
| [Cours & sessions](#cours) | 42 |
| [Compétences produit](#produit) | 11 |
| [Packages](#packages) | 11 |

<a id="postes"></a>
## Postes & sous-postes (niveaux)

Les postes du réseau et leurs niveaux (les sous-postes) : compétences exigées, exigences, tâches, fiche PDF.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/levels/{param1}/tasks` | sans contrat | Liste : niveaux › tâches. |  |
| `POST` | `/api/v1/levels/{param1}/tasks` | sans contrat | Crée / ajoute : niveaux › tâches. |  |
| `DELETE` | `/api/v1/levels/{param1}/tasks/{param2}` | sans contrat | Supprime / retire : niveaux › tâches. |  |
| `GET` | `/api/v1/position-levels` | sans contrat | Tous les niveaux de tous les postes. | lue |
| `POST` | `/api/v1/position-levels` | sans contrat | Crée / ajoute : niveaux. |  |
| `GET` | `/api/v1/position-levels/{param1}` | sans contrat | Lit un élément : niveaux. | lue |
| `PATCH` | `/api/v1/position-levels/{param1}` | sans contrat | Modifie : niveaux. |  |
| `DELETE` | `/api/v1/position-levels/{param1}` | sans contrat | Supprime / retire : niveaux. |  |
| `GET` | `/api/v1/position-levels/{param1}/competencies` | sans contrat | Les compétences exigées à ce niveau : le cœur du référentiel poste → compétences. | lue |
| `POST` | `/api/v1/position-levels/{param1}/competencies` | sans contrat | Ajoute une compétence exigée au niveau. |  |
| `DELETE` | `/api/v1/position-levels/{param1}/competencies/{param2}` | sans contrat | Retire une compétence du niveau. |  |
| `PATCH` | `/api/v1/position-levels/{param1}/move-down` | sans contrat | Descend le niveau d’un cran. |  |
| `PATCH` | `/api/v1/position-levels/{param1}/move-up` | sans contrat | Remonte le niveau d’un cran dans son poste. |  |
| `GET` | `/api/v1/position-levels/{param1}/pdf` | sans contrat | Fiche du niveau en PDF. | lue |
| `GET` | `/api/v1/position-levels/{param1}/requirements` | sans contrat | Les exigences du niveau (hors compétences : ancienneté, conditions…). | lue |
| `POST` | `/api/v1/position-levels/{param1}/requirements` | sans contrat | Crée / ajoute : niveaux › exigences. |  |
| `PATCH` | `/api/v1/position-levels/{param1}/requirements/{param2}` | sans contrat | Modifie : niveaux › exigences. |  |
| `DELETE` | `/api/v1/position-levels/{param1}/requirements/{param2}` | sans contrat | Supprime / retire : niveaux › exigences. |  |
| `GET` | `/api/v1/position-levels/{param1}/tasks` | sans contrat | Les tâches attachées au niveau. | lue |
| `POST` | `/api/v1/position-levels/{param1}/tasks` | sans contrat | Crée / ajoute : niveaux › tâches. |  |
| `DELETE` | `/api/v1/position-levels/{param1}/tasks/{param2}` | sans contrat | Supprime / retire : niveaux › tâches. |  |
| `GET` | `/api/v1/positions` | sans contrat | Liste des postes RH du réseau. | lue |
| `POST` | `/api/v1/positions` | sans contrat | Crée / ajoute : postes. |  |
| `GET` | `/api/v1/positions/{param1}` | sans contrat | Lit un élément : postes. |  |
| `PATCH` | `/api/v1/positions/{param1}` | sans contrat | Modifie : postes. |  |
| `DELETE` | `/api/v1/positions/{param1}` | sans contrat | Supprime / retire : postes. |  |
| `GET` | `/api/v1/positions/{param1}/levels` | sans contrat | Les niveaux (sous-postes) d’un poste, dans leur ordre. | lue |
| `GET` | `/api/v1/positions/{param1}/pdf` | sans contrat | Fiche de poste en PDF, générée par le panel. | lue |

<a id="carriere"></a>
## Parcours de carrière

Parcours et étapes de carrière. Le swagger les déclare TOUTES en 500 (« missing controller ») : les postes et sous-postes passent par /positions et /position-levels.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/career-paths` | **cassée (500)** | Liste : parcours de carrière. |  |
| `POST` | `/api/v1/career-paths` | **cassée (500)** | Crée / ajoute : parcours de carrière. |  |
| `GET` | `/api/v1/career-paths/{careerPathId}` | **cassée (500)** | Lit un élément : parcours de carrière. |  |
| `PATCH` | `/api/v1/career-paths/{careerPathId}` | **cassée (500)** | Modifie : parcours de carrière. |  |
| `DELETE` | `/api/v1/career-paths/{careerPathId}` | **cassée (500)** | Supprime / retire : parcours de carrière. |  |
| `GET` | `/api/v1/career-paths/{careerPathId}/pdf` | **cassée (500)** | Liste : parcours de carrière › PDF. |  |
| `GET` | `/api/v1/career-paths/{careerPathId}/stages` | **cassée (500)** | Liste : parcours de carrière › étapes. |  |
| `GET` | `/api/v1/career-stages` | **cassée (500)** | Liste : étapes de carrière. |  |
| `POST` | `/api/v1/career-stages` | **cassée (500)** | Crée / ajoute : étapes de carrière. |  |
| `GET` | `/api/v1/career-stages/{careerStageId}` | **cassée (500)** | Lit un élément : étapes de carrière. |  |
| `PATCH` | `/api/v1/career-stages/{careerStageId}` | **cassée (500)** | Modifie : étapes de carrière. |  |
| `DELETE` | `/api/v1/career-stages/{careerStageId}` | **cassée (500)** | Supprime / retire : étapes de carrière. |  |
| `GET` | `/api/v1/career-stages/{careerStageId}/competencies` | **cassée (500)** | Liste : étapes de carrière › compétences. *(citée dans la demande)* |  |
| `POST` | `/api/v1/career-stages/{careerStageId}/competencies` | **cassée (500)** | Crée / ajoute : étapes de carrière › compétences. *(citée dans la demande)* |  |
| `DELETE` | `/api/v1/career-stages/{careerStageId}/competencies/{competencyId}` | **cassée (500)** | Supprime / retire : étapes de carrière › compétences. *(citée dans la demande)* |  |
| `PATCH` | `/api/v1/career-stages/{careerStageId}/move-down` | **cassée (500)** | Modifie : étapes de carrière › descendre d’un cran. |  |
| `PATCH` | `/api/v1/career-stages/{careerStageId}/move-up` | **cassée (500)** | Modifie : étapes de carrière › monter d’un cran. |  |
| `GET` | `/api/v1/career-stages/{careerStageId}/pdf` | **cassée (500)** | Liste : étapes de carrière › PDF. |  |
| `GET` | `/api/v1/career-stages/{careerStageId}/requirements` | **cassée (500)** | Liste : étapes de carrière › exigences. |  |
| `POST` | `/api/v1/career-stages/{careerStageId}/requirements` | **cassée (500)** | Crée / ajoute : étapes de carrière › exigences. |  |
| `PATCH` | `/api/v1/career-stages/{careerStageId}/requirements/{requirementId}` | **cassée (500)** | Modifie : étapes de carrière › exigences. |  |
| `DELETE` | `/api/v1/career-stages/{careerStageId}/requirements/{requirementId}` | **cassée (500)** | Supprime / retire : étapes de carrière › exigences. |  |

<a id="liens"></a>
## Liens d’une compétence

Ce qui s’apprend pour une compétence : procédures opératoires, ressources de la base de connaissances, formations.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `POST` | `/api/v1/competencies/{competencyId}/knowledge-resources` | contrat documenté | Attache une ressource de la base de connaissances. *(citée dans la demande)* |  |
| `DELETE` | `/api/v1/competencies/{competencyId}/knowledge-resources/{resourceId}` | contrat documenté | Détache une ressource. *(citée dans la demande)* |  |
| `POST` | `/api/v1/competencies/{competencyId}/operational-procedures` | contrat documenté | Attache une procédure opératoire à la compétence. *(citée dans la demande)* |  |
| `DELETE` | `/api/v1/competencies/{competencyId}/operational-procedures/{procedureId}` | contrat documenté | Détache une procédure. *(citée dans la demande)* |  |
| `POST` | `/api/v1/competencies/{competencyId}/trainings` | contrat documenté | Attache une formation à la compétence. *(citée dans la demande)* |  |
| `DELETE` | `/api/v1/competencies/{competencyId}/trainings/{trainingId}` | contrat documenté | Détache une formation. *(citée dans la demande)* |  |

#### `POST /api/v1/competencies/{competencyId}/knowledge-resources`

- Corps : `knowledge_resource_id` integer
- Réponse : `status` string (success), `message` string, `inserted_id` integer
- Erreurs : 401 Missing or invalid Bearer token.; 500 Missing or invalid link input can fail at runtime.

#### `DELETE /api/v1/competencies/{competencyId}/knowledge-resources/{resourceId}`

- Réponse : `status` string (success), `message` string
- Erreurs : 401 Missing or invalid Bearer token.

#### `POST /api/v1/competencies/{competencyId}/operational-procedures`

- Corps : `id_operational_procedure` integer
- Réponse : `status` string (success), `message` string, `inserted_id` integer
- Erreurs : 401 Missing or invalid Bearer token.; 500 Missing or invalid link input can fail at runtime.

#### `DELETE /api/v1/competencies/{competencyId}/operational-procedures/{procedureId}`

- Réponse : `status` string (success), `message` string
- Erreurs : 401 Missing or invalid Bearer token.

#### `POST /api/v1/competencies/{competencyId}/trainings`

- Corps : `training_id` integer
- Réponse : `status` string (success), `message` string, `inserted_id` integer
- Erreurs : 401 Missing or invalid Bearer token.; 500 Missing or invalid link input can fail at runtime.

#### `DELETE /api/v1/competencies/{competencyId}/trainings/{trainingId}`

- Réponse : `status` string (success), `message` string
- Erreurs : 401 Missing or invalid Bearer token.

<a id="competences"></a>
## Compétences RH

Le catalogue des compétences, rangé par section › catégorie › sous-catégorie fonctionnelle.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/competencies` | contrat documenté | Le catalogue complet des compétences (filtre facultatif section_id). Pas de pagination. *(citée dans la demande)* | lue |
| `POST` | `/api/v1/competencies` | contrat documenté | Crée une compétence dans une sous-catégorie. *(citée dans la demande)* |  |
| `GET` | `/api/v1/competencies/{competencyId}` | contrat documenté | Une compétence ; include=operational_procedures,trainings,knowledge_resources ajoute ses liens. Rend [] si elle n’existe pas. *(citée dans la demande)* | lue |
| `PATCH` | `/api/v1/competencies/{competencyId}` | décrite, sans schéma | Modifie une compétence. Un corps vide fait échouer la requête SQL. *(citée dans la demande)* |  |
| `DELETE` | `/api/v1/competencies/{competencyId}` | contrat documenté | Supprime une compétence (succès même si elle n’existait pas). *(citée dans la demande)* |  |
| `GET` | `/api/v1/competency-subcategories/{subcategoryId}/competencies` | **cassée (500)** | Compétences d’une sous-catégorie. Cassée : TypeError côté panel (sendUpdated reçoit un tableau). *(citée dans la demande)* |  |

#### `GET /api/v1/competencies`

- Paramètres : `section_id` (integer) — Optional functional-category section ID. It is passed to the repository without validation; no pagination, sorting, or other filters exist.; `lang_code` (string) — Normalised to pl, en, fr, it, or nl; unsupported values fall back to fr. The current repository query does not use it.; `alias_type` (string) — Normalised to default, webshop, label, or product_label; unsupported values fall back to default. The current repository query does not use it.
- Réponse : `[].id` integer, `[].id_subcategory` integer, `[].subcategory_id` integer, `[].name` string, `[].base_name` string, `[].description` string?, `[].base_description` string?, `[].verification_method` string?, `[].created_at` string, `[].subcategory_name` string, `[].base_subcategory_name` string, `[].id_category` integer, `[].category_id` integer, `[].category_name` string, `[].base_category_name` string, `[].section_id` integer
- Erreurs : 401 Missing or invalid Bearer token.

#### `POST /api/v1/competencies`

- Corps : `id_subcategory` integer, `name` string, `description` string, `verification_method` string
- Réponse : `status` string (success), `message` string, `inserted_id` integer
- Erreurs : 401 Missing or invalid Bearer token.; 500 Persistence or malformed/empty input failure.

#### `GET /api/v1/competencies/{competencyId}`

- Paramètres : `include` (string) — Comma-separated values operational_procedures, trainings, and/or knowledge_resources. Unknown values are ignored.; `lang_code` (string) — Normalised to pl, en, fr, it, or nl; unsupported values fall back to fr. It is currently not applied by the SQL.; `alias_type` (string) — Normalised to default, webshop, label, or product_label; unsupported values fall back to default. It is currently not applied by the SQL.
- Réponse : `id` integer, `id_subcategory` integer, `subcategory_id` integer, `name` string, `base_name` string, `description` string?, `base_description` string?, `verification_method` string?, `created_at` string, `subcategory_name` string, `base_subcategory_name` string, `id_category` integer, `category_id` integer, `category_name` string, `base_category_name` string, `section_id` integer, `operational_procedures` array, `operational_procedures[].id` integer, `operational_procedures[].id_operational_procedure` integer, `operational_procedures[].title` string, `operational_procedures[].base_title` string, `trainings` array, `trainings[].id` integer, `trainings[].training_id` integer, `trainings[].name` string, `trainings[].base_name` string, `knowledge_resources` array, `knowledge_resources[].id` integer, `knowledge_resources[].knowledge_resource_id` integer, `knowledge_resources[].title` string, `knowledge_resources[].base_title` string
- Erreurs : 401 Missing or invalid Bearer token.

#### `PATCH /api/v1/competencies/{competencyId}`

- Corps : `id_subcategory` integer, `name` string, `description` string, `verification_method` string
- Erreurs : 401 Missing or invalid Bearer token.; 500 Malformed/empty update data can fail at runtime.

#### `DELETE /api/v1/competencies/{competencyId}`

- Réponse : `status` string (success), `message` string
- Erreurs : 401 Missing or invalid Bearer token.

<a id="formations"></a>
## Formations

Les formations du cadre opérationnel et leurs formateurs (administrateurs ou employés).

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/operational-framework/trainings` | sans contrat | Liste des formations. | lue |
| `POST` | `/api/v1/operational-framework/trainings` | sans contrat | Crée / ajoute : formations. |  |
| `GET` | `/api/v1/operational-framework/trainings/{param1}` | sans contrat | Lit un élément : formations. | lue |
| `PATCH` | `/api/v1/operational-framework/trainings/{param1}` | sans contrat | Modifie : formations. |  |
| `DELETE` | `/api/v1/operational-framework/trainings/{param1}` | sans contrat | Supprime / retire : formations. |  |
| `POST` | `/api/v1/operational-framework/trainings/{param1}/admin-trainers` | sans contrat | Crée / ajoute : formations › formateurs (administrateurs). |  |
| `DELETE` | `/api/v1/operational-framework/trainings/{param1}/admin-trainers/{param2}` | sans contrat | Supprime / retire : formations › formateurs (administrateurs). |  |
| `POST` | `/api/v1/operational-framework/trainings/{param1}/employee-trainers` | sans contrat | Crée / ajoute : formations › formateurs (employés). |  |
| `DELETE` | `/api/v1/operational-framework/trainings/{param1}/employee-trainers/{param2}` | sans contrat | Supprime / retire : formations › formateurs (employés). |  |
| `GET` | `/api/v1/operational-framework/trainings/{param1}/trainers` | sans contrat | Formateurs d’une formation. | lue |

<a id="structure"></a>
## Structure fonctionnelle

Sections, catégories et sous-catégories qui rangent compétences, formations et procédures.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/operational-framework/categories/description-aliases` | sans contrat | Liste : catégories › traductions de la description. |  |
| `GET` | `/api/v1/operational-framework/categories/name-aliases` | sans contrat | Liste : catégories › traductions du nom. |  |
| `PATCH` | `/api/v1/operational-framework/categories/{param1}/description-aliases` | sans contrat | Modifie : catégories › traductions de la description. |  |
| `PATCH` | `/api/v1/operational-framework/categories/{param1}/name-aliases` | sans contrat | Modifie : catégories › traductions du nom. |  |
| `GET` | `/api/v1/operational-framework/sections/description-aliases` | sans contrat | Liste : sections › traductions de la description. |  |
| `GET` | `/api/v1/operational-framework/sections/name-aliases` | sans contrat | Liste : sections › traductions du nom. |  |
| `PATCH` | `/api/v1/operational-framework/sections/{param1}/description-aliases` | sans contrat | Modifie : sections › traductions de la description. |  |
| `PATCH` | `/api/v1/operational-framework/sections/{param1}/name-aliases` | sans contrat | Modifie : sections › traductions du nom. |  |
| `GET` | `/api/v1/operational-framework/structure/categories` | sans contrat | Liste : structure › catégories. | lue |
| `POST` | `/api/v1/operational-framework/structure/categories` | sans contrat | Crée / ajoute : structure › catégories. |  |
| `GET` | `/api/v1/operational-framework/structure/categories/{param1}` | sans contrat | Lit un élément : structure › catégories. |  |
| `PATCH` | `/api/v1/operational-framework/structure/categories/{param1}` | sans contrat | Modifie : structure › catégories. |  |
| `DELETE` | `/api/v1/operational-framework/structure/categories/{param1}` | sans contrat | Supprime / retire : structure › catégories. |  |
| `GET` | `/api/v1/operational-framework/structure/categories/{param1}/subcategories` | sans contrat | Liste : structure › catégories › sous-catégories. |  |
| `POST` | `/api/v1/operational-framework/structure/categories/{param1}/subcategories` | sans contrat | Crée / ajoute : structure › catégories › sous-catégories. |  |
| `GET` | `/api/v1/operational-framework/structure/sections` | sans contrat | Liste : structure › sections. | lue |
| `POST` | `/api/v1/operational-framework/structure/sections` | sans contrat | Crée / ajoute : structure › sections. |  |
| `GET` | `/api/v1/operational-framework/structure/sections/{param1}` | sans contrat | Lit un élément : structure › sections. |  |
| `PATCH` | `/api/v1/operational-framework/structure/sections/{param1}` | sans contrat | Modifie : structure › sections. |  |
| `DELETE` | `/api/v1/operational-framework/structure/sections/{param1}` | sans contrat | Supprime / retire : structure › sections. |  |
| `POST` | `/api/v1/operational-framework/structure/sections/{param1}/categories` | sans contrat | Crée / ajoute : structure › sections › catégories. |  |
| `PATCH` | `/api/v1/operational-framework/structure/subcategories/{param1}` | sans contrat | Modifie : structure › sous-catégories. |  |
| `DELETE` | `/api/v1/operational-framework/structure/subcategories/{param1}` | sans contrat | Supprime / retire : structure › sous-catégories. |  |
| `GET` | `/api/v1/operational-framework/subcategories/description-aliases` | sans contrat | Liste : sous-catégories › traductions de la description. |  |
| `GET` | `/api/v1/operational-framework/subcategories/name-aliases` | sans contrat | Liste : sous-catégories › traductions du nom. |  |
| `PATCH` | `/api/v1/operational-framework/subcategories/{param1}/description-aliases` | sans contrat | Modifie : sous-catégories › traductions de la description. |  |
| `PATCH` | `/api/v1/operational-framework/subcategories/{param1}/name-aliases` | sans contrat | Modifie : sous-catégories › traductions du nom. |  |

<a id="postesTravail"></a>
## Postes de travail

Les postes de travail (zones) du magasin, distincts des postes RH.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/operational-framework/workstations` | sans contrat | Liste : postes de travail. | lue |
| `POST` | `/api/v1/operational-framework/workstations` | sans contrat | Crée / ajoute : postes de travail. |  |
| `GET` | `/api/v1/operational-framework/workstations/description-aliases` | sans contrat | Liste : postes de travail › traductions de la description. |  |
| `GET` | `/api/v1/operational-framework/workstations/name-aliases` | sans contrat | Liste : postes de travail › traductions du nom. |  |
| `GET` | `/api/v1/operational-framework/workstations/{param1}` | sans contrat | Lit un élément : postes de travail. |  |
| `PATCH` | `/api/v1/operational-framework/workstations/{param1}` | sans contrat | Modifie : postes de travail. |  |
| `DELETE` | `/api/v1/operational-framework/workstations/{param1}` | sans contrat | Supprime / retire : postes de travail. |  |
| `PATCH` | `/api/v1/operational-framework/workstations/{param1}/description-aliases` | sans contrat | Modifie : postes de travail › traductions de la description. |  |
| `PATCH` | `/api/v1/operational-framework/workstations/{param1}/name-aliases` | sans contrat | Modifie : postes de travail › traductions du nom. |  |
| `GET` | `/api/v1/shops/workstations` | décrite, sans schéma | Liste : magasins › postes de travail. |  |
| `GET` | `/api/v1/shops/workstations/{id}` | décrite, sans schéma | Lit un élément : magasins › postes de travail. |  |
| `GET` | `/api/v1/workstations/aliases` | sans contrat | Liste : postes de travail › traductions. |  |
| `PATCH` | `/api/v1/workstations/{param1}/aliases` | sans contrat | Modifie : postes de travail › traductions. |  |

<a id="procedures"></a>
## Procédures opératoires

Les procédures du manuel opératoire : sections, pièces jointes, PDF, alias de traduction.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/operational-procedure-attachments/label-aliases` | sans contrat | Liste : pièces jointes de procédure › traductions du libellé. |  |
| `DELETE` | `/api/v1/operational-procedure-attachments/{param1}` | sans contrat | Supprime / retire : pièces jointes de procédure. |  |
| `PATCH` | `/api/v1/operational-procedure-attachments/{param1}/label-aliases` | sans contrat | Modifie : pièces jointes de procédure › traductions du libellé. |  |
| `GET` | `/api/v1/operational-procedure-categories` | sans contrat | Liste : catégories de procédures. |  |
| `POST` | `/api/v1/operational-procedure-categories` | sans contrat | Crée / ajoute : catégories de procédures. |  |
| `GET` | `/api/v1/operational-procedure-categories/description-aliases` | sans contrat | Liste : catégories de procédures › traductions de la description. |  |
| `GET` | `/api/v1/operational-procedure-categories/name-aliases` | sans contrat | Liste : catégories de procédures › traductions du nom. |  |
| `GET` | `/api/v1/operational-procedure-categories/{param1}` | sans contrat | Lit un élément : catégories de procédures. |  |
| `PATCH` | `/api/v1/operational-procedure-categories/{param1}` | sans contrat | Modifie : catégories de procédures. |  |
| `DELETE` | `/api/v1/operational-procedure-categories/{param1}` | sans contrat | Supprime / retire : catégories de procédures. |  |
| `PATCH` | `/api/v1/operational-procedure-categories/{param1}/description-aliases` | sans contrat | Modifie : catégories de procédures › traductions de la description. |  |
| `PATCH` | `/api/v1/operational-procedure-categories/{param1}/name-aliases` | sans contrat | Modifie : catégories de procédures › traductions du nom. |  |
| `GET` | `/api/v1/operational-procedure-section-titles` | sans contrat | Liste : titres de section. |  |
| `POST` | `/api/v1/operational-procedure-section-titles` | sans contrat | Crée / ajoute : titres de section. |  |
| `GET` | `/api/v1/operational-procedure-section-titles/{param1}` | sans contrat | Lit un élément : titres de section. |  |
| `PATCH` | `/api/v1/operational-procedure-section-titles/{param1}` | sans contrat | Modifie : titres de section. |  |
| `DELETE` | `/api/v1/operational-procedure-section-titles/{param1}` | sans contrat | Supprime / retire : titres de section. |  |
| `GET` | `/api/v1/operational-procedure-sections` | sans contrat | Liste : sections de procédure. |  |
| `POST` | `/api/v1/operational-procedure-sections` | sans contrat | Crée / ajoute : sections de procédure. |  |
| `GET` | `/api/v1/operational-procedure-sections/description-aliases` | sans contrat | Liste : sections de procédure › traductions de la description. |  |
| `GET` | `/api/v1/operational-procedure-sections/{param1}` | sans contrat | Lit un élément : sections de procédure. |  |
| `PATCH` | `/api/v1/operational-procedure-sections/{param1}` | sans contrat | Modifie : sections de procédure. |  |
| `DELETE` | `/api/v1/operational-procedure-sections/{param1}` | sans contrat | Supprime / retire : sections de procédure. |  |
| `PATCH` | `/api/v1/operational-procedure-sections/{param1}/description-aliases` | sans contrat | Modifie : sections de procédure › traductions de la description. |  |
| `GET` | `/api/v1/operational-procedure-todo-links/note-aliases` | sans contrat | Liste : liens procédure ↔ tâche › traductions de la note. |  |
| `PATCH` | `/api/v1/operational-procedure-todo-links/{param1}/note-aliases` | sans contrat | Modifie : liens procédure ↔ tâche › traductions de la note. |  |
| `GET` | `/api/v1/operational-procedures` | sans contrat | Liste : procédures. | lue |
| `POST` | `/api/v1/operational-procedures` | sans contrat | Crée / ajoute : procédures. |  |
| `GET` | `/api/v1/operational-procedures/description-aliases` | sans contrat | Liste : procédures › traductions de la description. |  |
| `GET` | `/api/v1/operational-procedures/title-aliases` | sans contrat | Liste : procédures › traductions du titre. |  |
| `GET` | `/api/v1/operational-procedures/{param1}` | sans contrat | Une procédure avec ses sections (le contenu du manuel). | lue |
| `PATCH` | `/api/v1/operational-procedures/{param1}` | sans contrat | Modifie : procédures. |  |
| `DELETE` | `/api/v1/operational-procedures/{param1}` | sans contrat | Supprime / retire : procédures. |  |
| `GET` | `/api/v1/operational-procedures/{param1}/attachments` | sans contrat | Liste : procédures › pièces jointes. | lue |
| `POST` | `/api/v1/operational-procedures/{param1}/attachments` | sans contrat | Crée / ajoute : procédures › pièces jointes. |  |
| `DELETE` | `/api/v1/operational-procedures/{param1}/attachments/{param2}` | sans contrat | Supprime / retire : procédures › pièces jointes. |  |
| `PATCH` | `/api/v1/operational-procedures/{param1}/description-aliases` | sans contrat | Modifie : procédures › traductions de la description. |  |
| `GET` | `/api/v1/operational-procedures/{param1}/pdf` | sans contrat | Procédure en PDF. | lue |
| `PATCH` | `/api/v1/operational-procedures/{param1}/title-aliases` | sans contrat | Modifie : procédures › traductions du titre. |  |

<a id="connaissances"></a>
## Base de connaissances

Les ressources (documents, liens, vidéos) rangées par catégorie.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/knowledge-base/categories` | sans contrat | Liste : base de connaissances › catégories. |  |
| `POST` | `/api/v1/knowledge-base/categories` | sans contrat | Crée / ajoute : base de connaissances › catégories. |  |
| `GET` | `/api/v1/knowledge-base/categories/{param1}` | sans contrat | Lit un élément : base de connaissances › catégories. |  |
| `PATCH` | `/api/v1/knowledge-base/categories/{param1}` | sans contrat | Modifie : base de connaissances › catégories. |  |
| `DELETE` | `/api/v1/knowledge-base/categories/{param1}` | sans contrat | Supprime / retire : base de connaissances › catégories. |  |
| `GET` | `/api/v1/knowledge-base/categories/{param1}/resources` | sans contrat | Liste : base de connaissances › catégories › ressources. |  |
| `GET` | `/api/v1/knowledge-base/resources` | sans contrat | Liste : base de connaissances › ressources. | lue |
| `POST` | `/api/v1/knowledge-base/resources` | sans contrat | Crée / ajoute : base de connaissances › ressources. |  |
| `GET` | `/api/v1/knowledge-base/resources/{param1}` | sans contrat | Lit un élément : base de connaissances › ressources. | lue |
| `PATCH` | `/api/v1/knowledge-base/resources/{param1}` | sans contrat | Modifie : base de connaissances › ressources. |  |
| `DELETE` | `/api/v1/knowledge-base/resources/{param1}` | sans contrat | Supprime / retire : base de connaissances › ressources. |  |
| `GET` | `/api/v1/shops/{param1}/knowledge-base/resources` | sans contrat | Liste : magasins › base de connaissances › ressources. |  |

<a id="employes"></a>
## Employés : postes et compétences

Ce qu’une personne tient (poste, niveau) et ce qu’elle a validé (compétences).

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/employees/{employeeId}` | contrat documenté | Fiche employé ; include=competencies,positions ajoute compétences validées et postes tenus. | lue |
| `GET` | `/api/v1/employees/{employeeId}/competencies` | contrat documenté | Compétences validées par l’employé. *(citée dans la demande)* |  |
| `PUT` | `/api/v1/employees/{employeeId}/competencies` | décrite, sans schéma | Valide une compétence pour l’employé (upsert). *(citée dans la demande)* |  |
| `DELETE` | `/api/v1/employees/{employeeId}/competencies/{competencyId}` | décrite, sans schéma | Retire une compétence validée. *(citée dans la demande)* |  |
| `GET` | `/api/v1/employees/{employeeId}/positions` | contrat documenté | Postes et niveaux tenus par l’employé. |  |
| `PUT` | `/api/v1/employees/{employeeId}/positions` | décrite, sans schéma | Affecte un poste et un niveau à l’employé. |  |
| `DELETE` | `/api/v1/employees/{employeeId}/positions/{positionId}` | décrite, sans schéma | Supprime / retire : employés › postes. |  |
| `PUT` | `/api/v1/employees/{employeeId}/workstations` | décrite, sans schéma | Remplace / associe : employés › postes de travail. |  |
| `GET` | `/api/v1/franchisee-employee/{id}/competences` | décrite, sans schéma | Compétences d’un employé franchisé (modèle « cours » de l’ancien panel). *(citée dans la demande)* |  |
| `GET` | `/api/v1/franchisee-employee/{id}/courses` | décrite, sans schéma | Cours suivis par un employé franchisé. |  |

#### `GET /api/v1/employees/{employeeId}`

- Paramètres : `include` (string) — RequestContext include value; competencies and positions are recognized.
- Réponse : `id` integer, `id_shop` integer, `name` string, `surname` string, `display_name` string, `phone` string, `lang_code` string, `id_role` integer, `login` string, `money_per_hour` number, `email` string, `franchise_role` string, `employment_type` string, `workstations` array, `roles` array, `competencies` array, `competencies[].id` integer, `competencies[].id_subcategory` integer, `competencies[].subcategory_id` integer, `competencies[].name` string, `competencies[].base_name` string, `competencies[].description` string?, `competencies[].base_description` string?, `competencies[].verification_method` string?, `competencies[].created_at` string, `competencies[].subcategory_name` string, `competencies[].id_category` integer, `competencies[].category_id` integer, `competencies[].category_name` string, `competencies[].section_id` integer, `positions` array, `positions[].id` integer, `positions[].name` string, `positions[].description` string?, `positions[].level_id` integer, `positions[].level_name` string, `positions[].level_description` string?, `positions[].level_order` integer
- Erreurs : 401 Missing or invalid Bearer token.

#### `GET /api/v1/employees/{employeeId}/competencies`

- Réponse : `[].id` integer, `[].id_subcategory` integer, `[].subcategory_id` integer, `[].name` string, `[].base_name` string, `[].description` string?, `[].base_description` string?, `[].verification_method` string?, `[].created_at` string, `[].subcategory_name` string, `[].id_category` integer, `[].category_id` integer, `[].category_name` string, `[].section_id` integer

#### `PUT /api/v1/employees/{employeeId}/competencies`

- Corps : `competency_id` integer
- Erreurs : 400 competency_id missing.

#### `GET /api/v1/employees/{employeeId}/positions`

- Réponse : `[].id` integer, `[].name` string, `[].description` string?, `[].level_id` integer, `[].level_name` string, `[].level_description` string?, `[].level_order` integer
- Erreurs : 401 Missing or invalid Bearer token.

#### `PUT /api/v1/employees/{employeeId}/positions`

- Corps : `position_id` integer, `level_id` integer

#### `PUT /api/v1/employees/{employeeId}/workstations`

- Corps : `workstation_ids` array
- Erreurs : 401 Missing or invalid Bearer token.

<a id="cours"></a>
## Cours & sessions

Le modèle « cours » de l’ancien panel : sessions (commissions), groupes, ensembles, participants.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `POST` | `/api/v1/course` | sans contrat | Crée / ajoute : cours. |  |
| `POST` | `/api/v1/course-commission` | décrite, sans schéma | Crée / ajoute : session de cours. |  |
| `PATCH` | `/api/v1/course-commission/course/{id}` | décrite, sans schéma | Modifie : session de cours › cours. |  |
| `DELETE` | `/api/v1/course-commission/course/{id}` | décrite, sans schéma | Supprime / retire : session de cours › cours. |  |
| `GET` | `/api/v1/course-commission/courses` | sans contrat | Liste : session de cours › cours. |  |
| `GET` | `/api/v1/course-commission/{id}` | décrite, sans schéma | Lit un élément : session de cours. |  |
| `DELETE` | `/api/v1/course-commission/{id}` | décrite, sans schéma | Supprime / retire : session de cours. |  |
| `GET` | `/api/v1/course-commission/{id}/competences` | décrite, sans schéma | Liste : session de cours › compétences. |  |
| `GET` | `/api/v1/course-commission/{id}/franchisee-employee/{id}/document` | décrite, sans schéma | Liste : session de cours › employé franchisé › document. |  |
| `POST` | `/api/v1/course-commission/{param1}/participant` | sans contrat | Crée / ajoute : session de cours › participant. |  |
| `DELETE` | `/api/v1/course-commission/{param1}/participant/{param2}` | sans contrat | Supprime / retire : session de cours › participant. |  |
| `GET` | `/api/v1/course-commission/{param1}/participants` | sans contrat | Liste : session de cours › participants. |  |
| `GET` | `/api/v1/course-commissions` | décrite, sans schéma | Liste : sessions de cours. |  |
| `POST` | `/api/v1/course-group` | décrite, sans schéma | Crée / ajoute : groupe de cours. |  |
| `GET` | `/api/v1/course-group/{id}` | décrite, sans schéma | Lit un élément : groupe de cours. |  |
| `PATCH` | `/api/v1/course-group/{id}` | décrite, sans schéma | Modifie : groupe de cours. |  |
| `DELETE` | `/api/v1/course-group/{id}` | décrite, sans schéma | Supprime / retire : groupe de cours. |  |
| `GET` | `/api/v1/course-group/{param1}/subgroups` | sans contrat | Liste : groupe de cours › subgroups. |  |
| `GET` | `/api/v1/course-groups` | décrite, sans schéma | Liste : groupes de cours. |  |
| `POST` | `/api/v1/course-set` | décrite, sans schéma | Crée / ajoute : ensemble de cours. |  |
| `GET` | `/api/v1/course-set/{id}` | décrite, sans schéma | Lit un élément : ensemble de cours. |  |
| `PATCH` | `/api/v1/course-set/{id}` | décrite, sans schéma | Modifie : ensemble de cours. |  |
| `DELETE` | `/api/v1/course-set/{id}` | décrite, sans schéma | Supprime / retire : ensemble de cours. |  |
| `GET` | `/api/v1/course-set/{id}/courses` | décrite, sans schéma | Liste : ensemble de cours › cours. |  |
| `GET` | `/api/v1/course-sets` | décrite, sans schéma | Liste : ensembles de cours. |  |
| `POST` | `/api/v1/course-subgroup` | décrite, sans schéma | Crée / ajoute : sous-groupe de cours. |  |
| `GET` | `/api/v1/course-subgroup/{id}` | décrite, sans schéma | Lit un élément : sous-groupe de cours. |  |
| `PATCH` | `/api/v1/course-subgroup/{id}` | décrite, sans schéma | Modifie : sous-groupe de cours. |  |
| `DELETE` | `/api/v1/course-subgroup/{id}` | décrite, sans schéma | Supprime / retire : sous-groupe de cours. |  |
| `GET` | `/api/v1/course-subgroups` | décrite, sans schéma | Liste : sous-groupes de cours. |  |
| `GET` | `/api/v1/course/{id}/competences` | décrite, sans schéma | Liste : cours › compétences. |  |
| `GET` | `/api/v1/course/{param1}` | sans contrat | Lit un élément : cours. |  |
| `PATCH` | `/api/v1/course/{param1}` | sans contrat | Modifie : cours. |  |
| `DELETE` | `/api/v1/course/{param1}` | sans contrat | Supprime / retire : cours. |  |
| `GET` | `/api/v1/courses` | sans contrat | Liste : cours. |  |
| `POST` | `/api/v1/franchisee-employee-role` | décrite, sans schéma | Crée / ajoute : rôle d’employé. |  |
| `GET` | `/api/v1/franchisee-employee-role/{id}` | décrite, sans schéma | Lit un élément : rôle d’employé. |  |
| `PATCH` | `/api/v1/franchisee-employee-role/{id}` | décrite, sans schéma | Modifie : rôle d’employé. |  |
| `DELETE` | `/api/v1/franchisee-employee-role/{id}` | décrite, sans schéma | Supprime / retire : rôle d’employé. |  |
| `GET` | `/api/v1/franchisee-employee-role/{param1}/course` | sans contrat | Liste : rôle d’employé › cours. |  |
| `PATCH` | `/api/v1/franchisee-employee-role/{param1}/course` | sans contrat | Modifie : rôle d’employé › cours. |  |
| `GET` | `/api/v1/franchisee-employee-roles` | décrite, sans schéma | Liste : rôles d’employé. |  |

#### `POST /api/v1/course-commission`

- Corps : `id_brand` integer, `name` string
- Erreurs : 404 Empty data; 500 Internal

#### `POST /api/v1/course-group`

- Corps : `id_brand` integer, `name` string
- Erreurs : 404 Empty data; 500 Internal

#### `POST /api/v1/course-set`

- Corps : `id_brand` integer, `name` string
- Erreurs : 404 Empty data; 500 Internal

#### `POST /api/v1/course-subgroup`

- Corps : `id_brand` integer, `name` string
- Erreurs : 404 Empty data; 500 Internal

#### `POST /api/v1/franchisee-employee-role`

- Corps : `id_brand` integer, `name` string
- Erreurs : 404 Empty data; 500 Internal

<a id="produit"></a>
## Compétences produit

Les compétences attachées aux produits et sous-recettes (savoir-faire de fabrication).

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/product-competences` | décrite, sans schéma | Liste des compétences produit. | lue |
| `POST` | `/api/v1/product-competences` | sans contrat | Crée / ajoute : compétences produit. *(citée dans la demande)* |  |
| `GET` | `/api/v1/product-competences/description-aliases` | sans contrat | Traductions des descriptions de compétences produit. *(citée dans la demande)* |  |
| `GET` | `/api/v1/product-competences/name-aliases` | sans contrat | Traductions des noms de compétences produit. *(citée dans la demande)* |  |
| `GET` | `/api/v1/product-competences/{param1}` | sans contrat | Lit un élément : compétences produit. *(citée dans la demande)* |  |
| `PATCH` | `/api/v1/product-competences/{param1}` | sans contrat | Modifie : compétences produit. *(citée dans la demande)* |  |
| `DELETE` | `/api/v1/product-competences/{param1}` | sans contrat | Supprime / retire : compétences produit. *(citée dans la demande)* |  |
| `PATCH` | `/api/v1/product-competences/{param1}/description-aliases` | sans contrat | Écrit les traductions de la description. *(citée dans la demande)* |  |
| `PATCH` | `/api/v1/product-competences/{param1}/name-aliases` | sans contrat | Écrit les traductions du nom. *(citée dans la demande)* |  |
| `POST` | `/api/v1/products/{param1}/competences` | sans contrat | Crée / ajoute : produits › compétences. |  |
| `POST` | `/api/v1/subrecipes/{param1}/competences` | sans contrat | Crée / ajoute : sous-recettes › compétences. |  |

<a id="packages"></a>
## Packages

Les packages (conditionnements) de produits et leur coût — rangés côté produit par le panel.

| Verbe | Route | État | Ce qu’elle fait | Module |
|---|---|---|---|---|
| `GET` | `/api/v1/packages` | décrite, sans schéma | Liste des packages. |  |
| `POST` | `/api/v1/packages` | sans contrat | Crée un package (conditionnement produit). Route sans contrat dans le swagger. *(citée dans la demande)* |  |
| `GET` | `/api/v1/packages/{param1}` | sans contrat | Lit un élément : packages. |  |
| `PATCH` | `/api/v1/packages/{param1}` | sans contrat | Modifie : packages. |  |
| `DELETE` | `/api/v1/packages/{param1}` | sans contrat | Supprime / retire : packages. |  |
| `GET` | `/api/v1/packages/{param1}/cost` | sans contrat | Liste : packages › coût. |  |
| `GET` | `/api/v1/product-packages/description-aliases` | sans contrat | Liste : packages produit › traductions de la description. |  |
| `GET` | `/api/v1/product-packages/name-aliases` | sans contrat | Liste : packages produit › traductions du nom. |  |
| `PATCH` | `/api/v1/product-packages/{param1}/description-aliases` | sans contrat | Modifie : packages produit › traductions de la description. |  |
| `PATCH` | `/api/v1/product-packages/{param1}/name-aliases` | sans contrat | Modifie : packages produit › traductions du nom. |  |
| `GET` | `/api/v1/shops/{param1}/packages/{param2}/cost` | sans contrat | Liste : magasins › packages › coût. |  |
