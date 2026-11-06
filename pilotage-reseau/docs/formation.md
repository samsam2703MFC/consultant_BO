# Module Formation — `/formation/` (31/10/2026)

Le référentiel RH du panel TFBuddy, rangé pour être lu : **postes et sous-postes (niveaux)**,
**compétences** exigées, **formations**, **procédures opératoires**, **base de connaissances**,
**compétences produit** ; les **équipes** d'un magasin face à leur niveau ; et le **Manuel du réseau**
(standards, classeur, phases, postes, guides, tablettes). L'ancien « Manuel en ligne » a été retiré au lot 6
(voir § Lot 6). Toutes les routes API qui portent ces données sont
documentées dans le module (écran *Routes API*) et dans `docs/formation-routes.md`.

- Adresse : `https://185.180.206.46/formation/` (à la racine, comme `/marketing/` — alias Apache posé par
  `bin/deploy.sh`), et `…/consulant_bo/formation/` — rail du cockpit › **Formation** › *Module Formation ↗*.
- Manuel du réseau : `…/consulant_bo/formation/#/rfclasseur` — rail › *Manuel du réseau ↗* (il s'appelait *Manuel
  opératoire ↗* et ouvrait `?tablette=1#/manuel` jusqu'au lot 6). La page s'ajoute à l'écran d'accueil (manifeste
  `formation/manifest.webmanifest`, « Manuel du réseau ») et s'ouvre alors sur le Classeur en mode tablette, sans barre
  latérale. La tablette du MAGASIN est une autre page : `formation/sop/tablette.html` (§ Tablettes des magasins).
- **Écritures** (voir plus bas) : compétences exigées par un niveau, formations / procédures / ressources
  d'une compétence, catalogue des compétences, compétences validées et niveau d'une personne. Toute
  écriture est relue au panel avant d'être annoncée, et journalisée dans le cockpit.

## Design

Le design system « L'Atelier By » du module Marketing (référence commune du 06/11/2026), dans une
feuille unique : `public/formation/ds.css`, chargée en premier par `index.html` et `sop/tablette.html`.
Elle porte les deux `@font-face` (400 = `fonts/Gotham_Medium.otf`, le trait du
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
| ~~Manuel opératoire~~ | ~~Manuel en ligne~~ | `#/manuel/…` | **Retiré au lot 6** (doublon du Manuel du réseau) : `#/manuel` mène au Classeur, `#/manuel/partie/{cle}` à `#/rfphase/{cle}`, un poste / une compétence / une procédure à sa page du référentiel ; seule `#/manuel/formation/FO-xx` (la fiche à lire d'une formation) reste. Voir § Lot 6. |
| Documentation | Routes API | `#/api[/{famille}]` | Les 237 routes du référentiel dans le swagger : verbe, état, paramètres, corps, champs de réponse, codes — et un bouton **Tester** qui appelle la route en lecture avec le compte du cockpit. |

## Parcours compétences — retiré le 06/11/2026

La page `/formation/parcours/` (assistant en trois étapes, frise de suivi de chaque personne), l'écran *Parcours des
équipes* (`#/parcours`) et l'entrée *Formation › Parcours compétences ↗* du rail du cockpit ont été retirés à la
demande du client (« je ne vois pas la nécessité de l'avoir dans les modules »). Voir § « Parcours retiré » en fin de
document. Ce qui suit (critères d'admission, création d'un poste) reste : ce sont des écrans du référentiel.

### Critères d'admission — Adjoint Manager, Manager, Franchisé multi-boutique

Ce qu'il faut pour être **nommé** au poste visé (et pas seulement formé). Les exigences du niveau dans le
panel (`/position-levels/{id}/requirements`, lignes sans nom écartées) passent en premier ; le cockpit
complète avec `src/data/admission.json`, sans doublon (une formation déjà exigée par le panel n'est pas
répétée). Trois sortes :

| Sorte | Vérifié par | Exemple |
|---|---|---|
| `poste` | le panel : le poste tenu | Manager ← *Adjoint Manager en poste* ; multi-boutique ← *Franchisé en activité* |
| `fo` | le panel : formations acquises | Manager ← FO-28 Adjoint Manager + FO-24 Plannings |
| `manuel` | la manager (le consultant pour la franchise) | ancienneté, entretien, P&L conforme, accord du franchiseur |

| Poste | Critères |
|---|---|
| Adjoint Manager (P7) | Responsable d'équipe (chef de section) · bases validées (FO-01 vente, FO-02 HACCP, FO-16 allergènes) · 6 mois dans le réseau · proposé par le manager ou le franchisé |
| Manager (P8) | Adjoint Manager en poste · FO-28 + FO-24 · 12 mois d'encadrement · entretien avec le franchisé et le consultant |
| Franchisé multi-boutique (P12) | Franchisé en activité · Plan Gérant (FO-05, FO-06, FO-24) · 12 mois sur la 1re boutique · 1re boutique rentable (P&L, audit qualité) · un Manager formé pour la tenir · accord du franchiseur |

Module, *Postes & sous-postes* : la carte *Critères d'admission* de chaque niveau, et
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
  de personnes). Commun : *Ce qui manque*. Données : `GET /formation/carte`
  (`pcCarte`) — des **comptes**, aucun nom ; équipes lues par magasin, en cache 5 min.
- **Règle des trois métiers** (31/10/2026) : le gérant de magasin maîtrise la vente, la
  boulangerie-pâtisserie et le traiteur. Maîtriser un métier = toutes ses compétences jusqu'au dernier
  niveau avant « Responsable d'équipe » (`pcMaitrises`). Critère d'admission `metiers` : il **remplace**
  « Responsable d'équipe » pour l'Adjoint et **s'ajoute** à « Adjoint en poste » pour le Manager ; ce qui
  manque passe par les formations qui l'enseignent ; validable par la manager.
- **Fiche du niveau** : l'escalier du poste (+N · total), *Ce qu'il faut savoir faire* (nouveau / acquis
  avant / tout, la FO de chaque compétence), *Pour les apprendre* (FO dans l'ordre), *Pour y accéder*
  (niveau précédent + critères d'admission), *Ensuite*.
- **Compétences** et **Formations** : bascule *Liste* / *Matrice* — compétences × niveaux d'un poste (carré
  plein où elle entre, clair ensuite) ; « Qui doit suivre quoi » (formations × postes par famille, O / R).
- **Équipes** : par personne, compétences tenues sur le **cumul** de son niveau, ce qui manque, prochaine
  étape ; le détail et ses gestes (valider, changer de niveau) restent.

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
  postes à créer), *Les postes* par famille avec la part des compétences couvertes par une formation ;
  le détail technique des appels au panel est replié.

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

> **Depuis le lot 6 (06/11/2026), l'écran décrit ci-dessous est retiré** : ses parties sont les phases du Manuel du
> réseau, ses procédures du panel se reprennent en standards, le mode tablette lit le Manuel du réseau. Ce qui reste et
> où : § Lot 6. Les réglages (partie et vidéos d'une procédure, partie et supports d'une formation) et leurs routes
> restent tels quels.

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
  débordement horizontal.
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
| ~~**Manuel opératoire** › chaque partie~~ | jusqu’au lot 6, un bloc *Standards (SOP)* dans chaque partie du Manuel en ligne ; depuis, les SOP d’une phase se lisent dans Manuel du réseau › *Par phase* (§ Lot 6) |
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
ni sur la tablette, ni dans le Manuel imprimé (Par phase les montre, marqués « Brouillon »). Rédaction › Statut la fait
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
- **Code de gestion (lot 6, décision du client)** : en production l’API n’exige pas de connexion (`bin/deploy.sh`
  écrit `auth => false`) et la liste porte les PIN ; les routes de gestion (`/formation/tablettes*` : liste, créer,
  renommer, nouveau PIN, déconnecter, archiver, reprise du CRM) exigent donc le **code de gestion**, envoyé dans
  l’en-tête `X-Code-Gestion` et comparé à temps constant (deux HMAC de même longueur) à la clé de configuration —
  variable d’environnement `TABLETTES_CODE`, puis `config/config.php › tablettesCode` (écrite par `bin/deploy.sh`
  d’après le secret GitHub `TABLETTES_CODE` ou le fichier privé `/var/lib/consulant_bo/prive/tablettes_code` ; voir
  `docs/DEPLOIEMENT.md`). Code non configuré : **gestion fermée** (403 `gestion_fermee`, « Code de gestion non
  configuré »). Essais limités comme le PIN du magasin, dans une table à part (`ceo_tablette_gestion_essai`, adresse
  IP hachée) : 5 codes faux par adresse en 15 minutes, puis 10 en tout par heure ou 50 par jour suspendent la gestion
  (429, même avec le bon code) ; un refus attend 0,4 s ; jamais de code dans le journal. L’écran Tablettes demande le
  code (champ masqué, « Ouvrir la gestion »), le garde dans l’onglet seulement (`sessionStorage` `fm.tablettes.code`,
  « Fermer la gestion » l’oublie), dit clairement un refus (code refusé, trop d’essais, gestion fermée) et redemande.
  Les routes de la tablette elle-même (ouvrir l’appareil par le PIN du magasin, état, fermer, puis
  `/formation/sop/tablette/*`) ne changent pas.
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
| `ceo_tablette_gestion_essai` | un code de gestion faux (lot 6) : `ip_hash`, `cree_le` (purgé après 7 jours) |

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

## Lot 6 — retrait des doublons (06/11/2026)

Dernière passe de la fusion (`docs/maquettes/fusion-manuel/PLAN.md`, lot 6) : rien n'est retiré sans remplaçant.

**Le « Manuel en ligne » (`#/manuel`) quitte le module.** Il relisait les procédures du panel TF Buddy en quatre parties,
ce que le Manuel du réseau fait désormais sur nos propres standards. Avant de le retirer :

- **Reprise des procédures du panel** dans le référentiel : `POST /formation/ref/importer-panel` (`wr_rf_importer_panel`,
  `src/referentiel.php`), bouton « Reprendre les procédures du panel » du Classeur, à côté de « Reprendre le Manuel du
  CRM ». Chaque procédure (`GET /operational-procedures`, puis lue comme la page du manuel : `fmProcedureLue`,
  `src/formation.php`, la lecture de `GET /formation/procedure`) devient une SOP **brouillon** : id `panel-<id>`, source
  `panel:<id>`, code `TFB-<id>` (le code que le CRM lui donnait). Pas reprise quand elle est déjà un standard : déjà
  reprise, reprise par le Manuel du CRM (code `TFB-<id>`), ou une SOP de même titre (mêmes mots : « Ouverture du magasin
  et des comptoirs » ne double pas SOP-WKL-001). Une étape par section du panel qui a un contenu (le HTML de l'éditeur du
  panel ramené à du texte ; une section vide ou à « 1 » est sautée), l'objectif = sa description ; phase = la partie
  réglée dans le cockpit, sinon déduite comme le faisait l'écran ; sous-catégorie déduite par le nom de la sous-catégorie
  puis de la catégorie du panel (`rfSouscatParNom`), sinon vide et **signalée** (« à classer depuis leur fiche ») ;
  famille et poste de tablette déduits de la catégorie (nettoyage / hygiène → 03, cuisson / production → 02, sinon 01) ;
  pièces jointes du panel et vidéos réglées notées dans les remarques. Bilan à l'écran (reprises, déjà là, illisibles,
  à classer) et au journal. Idempotent. Essai : faux panel (`tests/formation/faux_panel.php`, cinq procédures FICTIVES).
- Puis l'entrée de menu « Manuel opératoire › Manuel en ligne » disparaît, et les anciennes adresses mènent ailleurs
  (`ancienManuel`, `formation.js` ; l'adresse est remplacée, `?tablette=1` et `?embed=1` restent).

| Ce que le Manuel en ligne offrait | Ce qu'il est devenu |
|---|---|
| Accueil `#/manuel` (les quatre parties) | → **Classeur** (`#/rfclasseur`) ; les quatre parties = les quatre phases de **Par phase** |
| Une partie `#/manuel/partie/{cle}` : procédures, SOP, formations FO de la partie | → `#/rfphase/{cle}` : les standards de la phase (« À appliquer », les SOP comprises, brouillons repris du panel compris), les formations (« À apprendre » : celles des standards et la répartition des FO par partie, inchangée), les compétences |
| « Par poste » d'Opérationnel, `#/manuel/poste/{p}/{n}` | → `#/postes/{p}/{n}` (Référentiel › Postes & sous-postes : mêmes niveaux et compétences, fiches PDF du poste et du niveau) ; les postes v25 se lisent dans **Par poste** |
| Une compétence `#/manuel/competence/{id}` | → `#/competences/{id}` (Référentiel › Compétences) |
| Une procédure `#/manuel/procedure/{id}` : lecture, PDF, partie du manuel, vidéos | → `#/procedures/{id}` (Référentiel › Procédures : la même lecture, le PDF du panel, la partie du manuel et les vidéos, réglables) ; et, reprise, un standard brouillon `panel-<id>` |
| La fiche à lire d'une formation `#/manuel/formation/FO-xx` (programme, matériel didactique, fenêtres de détail, « Imprimer ») | **gardée** et rattachée au Manuel du réseau : fil d'Ariane « Manuel du réseau › <phase> », les formations de Par phase et de Par poste y mènent d'un clic ; l'ancien lien « Guide › » du CRM (`?tablette=1#/manuel/formation/FO-xx`) marche toujours |
| Réglage « Partie du manuel » d'une procédure, d'une formation ; vidéos ; supports | **gardés** (page d'une procédure du référentiel, fiche d'une formation) ; les liens de la partie mènent à Par phase |
| Recherche plein texte (compétences, procédures du panel) | Référentiel › Compétences et Procédures (recherche), Standards (recherche des SOP) |
| Mode tablette (`?tablette=1`, sans barre latérale, cibles agrandies, sans réglages) | **gardé** et rattaché au Manuel du réseau : bouton « Mode tablette » sur le Classeur, Par phase, Par poste, Standards et la fiche d'une formation ; en tablette, un sélecteur de la barre supérieure (Classeur · Par phase · Par poste · Standards) remplace la barre latérale ; sans adresse, la tablette s'ouvre sur le Classeur (manifeste : « Manuel du réseau », `?tablette=1#/rfclasseur`). La tablette du **magasin** reste `sop/tablette.html` |
| PDF | le Manuel opératoire des SOP en PDF (`GET /formation/sop/manuel.pdf`, existait sans bouton) : « Manuel PDF FR / NL » du Classeur ; PDF d'une SOP (sa fiche), d'une procédure, d'un poste, d'un niveau (référentiel) inchangés |
| Rail du cockpit « Manuel opératoire ↗ » (`formation/?tablette=1#/manuel`) | « Manuel du réseau ↗ » (`formation/#/rfclasseur`) |

Code retiré de `formation.js` : accueil, parties, postes, compétences et procédures du manuel (et leurs styles :
`fm-parts`, `fm-part*`, `fm-parties*`, `fm-manuel-search`, cartes des postes) ; de `formation-sop.js` : les SOP d'une
partie (`sectionPartie`, `nbPartie`). **Gardé côté serveur** : `GET /formation/referentiel` et `GET /formation/procedure`
(le CRM les lit), `GET|PATCH /formation/manuel*` (réglages des parties, vidéos, supports).

**Côté CRM** (dépôt atelier-espace-candidat, même lot) : les routes d'écriture que son écran n'appelle plus (éditeur du
Manuel opératoire, réglages de la pré-ouverture et des compétences) répondent 410 « s'édite désormais dans le Cockpit
(Manuel du réseau) » ; les lectures restent. Son `CLAUDE.md` § Lot 6.

**Code de gestion des tablettes** (même lot) : § Tablettes des magasins.


## Portail de formation restructuré (06/11/2026)

Client : « le portail est très compliqué à comprendre ; le restructurer : 1. les formations (pré-ouverture, ouverture,
continue) ; les métiers sont des postes ; les compétences sont liées aux formations ; les SOP sont pour le manuel
opérationnel ». Maquette validée (`docs/maquettes/portail-formation/`), puis : les 21 formations sans phase rangées
dans les phases nécessaires ; l'Ouverture = la période de lancement (six semaines avec le garant d'ouverture,
contrat article 4.5) avec le suivi des KPI et le développement commercial ; le Guide pré-ouverture en bloc « Démarches ».

- **Une seule chaîne**, en tête de chaque écran, toujours dans le même ordre : **Postes › Formations › Compétences ›
  Manuel opérationnel**. Chaque fiche renvoie aux trois autres.
- **Rail** (`window.FormationPortail.rail`, `portail.js`, lu par `formation.js`) : PORTAIL DE FORMATION — 1 Formations
  (`#/rfformations`) · 2 Postes (`#/rfmetiers`) · 3 Compétences (`#/rfcompetences`) · 4 Manuel opérationnel (le Classeur,
  `#/rfclasseur`) ; SUIVI — Suivi des équipes (`#/equipes`) ; RÉGLAGES — Correspondance panel, Tablettes,
  Rédaction des SOP, Données du panel (`#/rfdonnees` : les écrans d'avant — carte des métiers, postes et sous-postes,
  compétences, évaluations, procédures, base de connaissances, compétences produit, compétences des équipes, routes API —
  à leur adresse, inchangés). Accueil du module : `#/rfformations` (la tablette garde le Classeur). Les anciennes adresses
  marchent toutes ; une entrée reste allumée sur ses écrans voisins (Formations sur `#/rfpre`, Compétences sur `#/rfform`,
  Manuel sur `#/sop` et `#/rfphase`…).
- **La phase n'appartient qu'aux formations** : Pré-ouverture (`pre`) · Ouverture (`ouv`) · Formation continue (`con`) ;
  une formation peut être dans plusieurs phases (apprise avant, recyclée ensuite). Table `ceo_ref_formation` (code,
  `phases` JSON, `maj_le`, `maj_par`), posée une fois par `RF_FORMATIONS_PHASES_2026_11` (INSERT IGNORE : jamais
  réécrite) : 32 phases viennent des blocs du Guide des formations, 21 formations hors blocs rangées le 06/11/2026
  (pré-ouverture : FO-03, 04, 14, 17, 26, 34, 46, 47, 53 ; continue : FO-03, 15, 17, 21, 22, 23, 28, 39, 40, 42, 44, 48,
  49, 50) ; l'Ouverture reçoit aussi FO-05 (suivi des KPI), FO-15, FO-43, FO-45 (développement commercial). Total :
  31 pré-ouverture, 7 ouverture, 24 continue. Libellés et descriptions : `RF_PHASES_FORMATION` (NL à relire). Les
  standards gardent leurs phases d'avant dans les données (la tablette, le CRM) mais le portail ne les classe plus que
  par domaine et par moment de la journée.
- **Formations** (`#/rfformations[/pre|ouv|con][/FO-xx]`) : trois cartes de phase (nombre, sous-titre), en Pré-ouverture
  les onglets « Formations · N » / « Démarches · N » (`#/rfformations/pre/demarches` : les sections et points du Guide
  pré-ouverture, J-, qui, preuve ; « Modifier le guide » → `#/rfpre`), en Ouverture la carte « Période de lancement »
  (mise en route, suivi des KPI, développement commercial). Liste par famille (`RF_FAMILLES_FORMATION` : Franchise &
  gestion · Production · Vente & développement commercial · Traiteur & snacking · Hygiène & réglementation ·
  Encadrement), colonnes fixes : code · formation (« aussi continue ») · les 12 postes (plein = obligatoire, contour =
  recommandée, d'après `attendu` du v25) · compétences · SOP · fréquence ; filtre par groupe de postes, recherche. Fiche :
  phases en trois boutons (un clic ajoute ou retire, `PUT /formation/ref/formations/{code}`), pour qui, ce qu'on apprend
  (compétences et leur compétence du panel), dans le manuel (SOP), évaluation au panel (les évaluations `[FO-xx]`).
  Sans formation choisie : le résumé de la phase (formations, compétences, SOP, obligatoires par groupe de postes).
- **Postes** (`#/rfmetiers[/Px]`) : les 12 postes du v25 en cinq groupes (Production, Vente, Traiteur, Encadrement,
  Franchisé) ; la fiche = le plan de formation en trois colonnes de phase (obligatoires d'abord), ses compétences (celles
  des formations obligatoires, « ● panel » / « ○ hors panel ») et son manuel (SOP publiées du poste, par moment).
- **Compétences** (`#/rfcompetences[/code]`, `#/rfcompetences/produit`) : les 56 compétences par famille, colonnes
  fixes code · compétence · formation · phases (celles de sa formation) · SOP · panel ; filtres Toutes · Sans SOP · Hors
  panel. Fiche : description, « réussi si » du bloc (bloquante, 5 pôles, renouvellement ; « Modifier le critère » →
  `#/rfform/<n>/<code>`), formation, pour qui, SOP, compétence du panel (« Modifier la correspondance » → `#/sopcomp`).
  Onglet « Compétences produit » = celles du panel.
- **API** : `GET /formation/ref` rend en plus, sur chaque formation, `phases`, `postes` (O / R), `famille`,
  `competences`, et `phasesFormation`, `famillesFormation` (contrat : `docs/contrat-api.md`). Rien n'est retiré : le CRM
  lit comme avant.
- Fichiers : `public/formation/portail.js` (chargé entre `formation-sop.js` et `formation.js`, il étend
  `window.FormationSop` comme les tablettes), `public/formation/portail.css`.

## Parcours retiré (06/11/2026)

Client : « retirer tout ce qui a affaire à `#/parcours`, je ne vois pas la nécessité de l'avoir dans les modules ; il faut
effacer ». Retiré :
- **Écrans** : la page `public/formation/parcours/` (assistant, frise), l'écran *Parcours des équipes* (`#/parcours`, qui
  mène désormais à l'accueil du module), *Parcours en cours* du tableau de bord, les liens *Ouvrir un parcours* (fiche d'un
  poste hors panel, *Compétences des équipes*), l'entrée *Formation › Parcours compétences ↗* du rail du cockpit
  (`app.js`, `templates.js`). Le rail du portail : SUIVI — *Suivi des équipes* = `#/equipes`.
- **API** : `GET /parcours/destinations`, `GET /parcours/candidats` (la lecture du CRM candidat), `GET /parcours`,
  `GET /parcours/liste`, `POST /parcours`, `PATCH /parcours/{id}[/etape/{FO}|/admission/{cle}]`,
  `POST /parcours/{id}/acquis/{cid}` → 404 « route inconnue ».
- **Code serveur** : `src/parcours.php` supprimé. Ce que d'autres écrans lisent passe dans **`src/formation_postes.php`**
  (noms de fonctions inchangés, préfixe `pc`) : `GET /formation/carte` (carte des métiers, `pcCarte`, `pcMaitrises`),
  `GET /formation/v25` (référentiel v25, critères d'admission), `POST /formation/postes` (créer un poste du référentiel
  dans le panel). Tests : les vérifications du parcours retirées de `bin/tests_formation.php` (102 ok).
- **Tables** : `ceo_parcours` et `ceo_parcours_etape` effacées UNE fois par l'installation qui suit la mise en ligne
  (`formationParcoursEffacer`, appelée par `ensureInstalled`) ; une table qui avait encore des lignes est d'abord gardée en
  JSON dans le réglage `parcours_sauvegarde_2026_11` (en production, le 06/11/2026 : un seul parcours, arrêté — il y sera gardé) ; bilan dans
  `parcours_efface_2026_11` et au journal (projet « Formation »).
- **Maquettes** : `docs/maquettes/parcours-competences/` supprimé.

