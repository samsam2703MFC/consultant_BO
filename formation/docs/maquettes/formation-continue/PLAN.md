# Formation continue — analyse, plan et maquettes (22/11/2026)

Demande : un type « formation continue » (quatre catégories, trois statuts) ; le consultant vérifie, adapte et valide
les formations de ses franchisés, chaque validation tracée ; un bouton abricot vers le dossier de formation du
franchisé, avec le Professeur relié ; un bloc « Nouveautés » dans « Formations et suivi ».

Ajouts en cours de demande :
- dans le Manuel opératoire, la procédure lors d'un nouveau produit et le fonctionnement des formations ;
- **la validation passe par des tâches uniques** : pendant deux semaines, une tâche par jour, photo obligatoire de la
  photo qualité du produit. Les tâches sont gardées ; à la validation, elles sont archivées dans un document de
  formation du franchisé.

**Rien n'est codé.** Ce dossier contient le plan et huit maquettes (HTML sur le design system, captures PNG). Pour
refaire les captures : `php -S 127.0.0.1:8099 -t .` à la racine, puis
`node formation/docs/maquettes/formation-continue/capturer.cjs`.

| Écran | Fichier |
|---|---|
| Module Formation (siège) : initiale / continue, 4 catégories, 3 statuts | `01-module-formation-continue.png` |
| Cockpit consultant : vérifier (avec les 14 photos), adapter, valider, journal | `02-cockpit-consultant.png` |
| Dashboard du magasin : le bouton abricot | `03-dashboard-bouton-abricot.png` |
| Formations et suivi : Nouveautés, dossier, suivi de l'équipe, Professeur | `04-formations-et-suivi.png` |
| Une formation du dossier et le Professeur qui répond | `05-formation-et-professeur.png` |
| Manuel : 0.0.61 « Un nouveau produit arrive : la procédure » | `06-manuel-nouveau-produit.png` |
| Manuel : 0.0.62 « Comment fonctionnent les formations » | `07-manuel-comment-fonctionnent-les-formations.png` |
| Le document de formation du franchisé (l'archive des validations) | `08-document-de-formation.png` |

## 1. Ce qui existe (analyse)

- **Formations FO** (54, référentiel v25) : chacune a des phases pré-ouverture, ouverture et continue, rangées dans
  `for_ref_formation`. La « formation continue » n'existe que comme **phase** (24 FO de recyclage). Elle n'a ni
  contenu propre, ni statut, ni suivi par magasin.
- **Manuel opératoire** : `for_sop` porte les SOP, les articles et les checklists (FR/NL, versions dans
  `for_sop_version`, statut `brouillon | a_valider | publiee`). Sa partie `formation` s'appelle « Formation continue ».
  Il dispose déjà d'un éditeur, de l'assistant de création en 5 étapes, de la tablette et du PDF.
- **Suivi par personne** : `for_sop_suivi` (lu, quiz, vu faire). La table existe toujours ; son écran a quitté le
  portail le 06/11.
- **Portail franchisé** (`panel/`) : il n'a **aucune vue Formation**. L'entrée « Assistant IA » du rail est déjà en
  Abricot `#F2C9A0`, texte Ruby (`.db-rail-ia`).
- **Le Professeur** lit tout le Manuel du réseau avec `manuel_sommaire`, `manuel_chercher` et `manuel_lire` sur
  `for_sop`. Il n'a aucun contenu propre à un magasin. Le magasin est connu dans `amOutil` (`$demandeur`), mais il
  n'est pas transmis aux outils.
- **Cockpit** : il n'y a **plus de groupe « Gestion »** (retiré le 18/10). Le groupe « Franchisés » contient Fiche &
  scoring, Visites, Budget… Le lien consultant ↔ magasin vient du réglage `visitesCadre` (`vcCadreDe`).
- **Droits** :
  - `accesIdentite()` ne connaît que deux rôles : siège (tout le réseau) et magasin (ses `shops`).
  - Il n'y a **pas de rôle consultant par session**. `consultantIdCompte()` est le compte du panel du cockpit, un
    seul pour tout le cockpit.
  - En production, `acces = ouvert` depuis le 13/11 : toutes les routes passent.
- **« Formations et suivi »** : aucune page ne porte ce nom aujourd'hui.

### Les tâches dans l'API du panel (vérifié le 22/11/2026 sur `/api/swagger/openapi.json`, 933 chemins)

| | Ce que dit l'API |
|---|---|
| Existe | Une tâche (`POST /tasks`, `PATCH /tasks/{id}`) avec `requires_photo`, `is_mandatory`, `day_of_week`, `execution_time`, `frequency` ; l'activer ou la désactiver : `PATCH /tasks/{id}/active` |
| Existe | Les tâches d'un magasin pour un jour (`GET /consultant/shops/{shop}/tasks?date=`) ; la photo via `checklists/{id}/progress` (`completion_id`, `attachment_id`) ; une exécution : `GET /tasks/completions/{id}` ; la photo : `/attachments/{id}/presigned-url` (valable une heure) |
| Existe | L'avis du consultant sur chaque photo : `POST /consultant/shops/{shop}/task-reviews` (`is_accepted`, `rating`, `comment`), et son contreseing `…/validate` (déjà employé par le cockpit) |
| **N'existe pas** | Une **tâche unique** propre à **un** magasin et à **une période** : une tâche vit dans une checklist et des niveaux de poste, pour tout le réseau. Aucun champ magasin ni dates. Le corps de `POST /tasks` n'est pas documenté (« contracts are not inferred ») |
| Modèle à reprendre | Les avis de notification (`POST /notifications`) ont exactement cette forme : `shops`, `visible_from`, `visible_to`, `source_type`, `source_id` |

Les tâches réelles n'ont pas pu être relues d'ici : `GET /api/v1/tasks` répond 401, faute d'identifiants pour ce
chemin. Seul le swagger, public, a été lu.

## 2. Ce que je propose

### Modèle de données (domaine Formation, tables `for_`)

- **Le contenu d'une formation continue est une ligne de `for_sop`, de type `FC`** (codes FC-001…), rangée dans la
  partie `formation`. Deux colonnes s'ajoutent, nulles pour tout l'existant :
  - `categorie` : `technique | connaissance | nouveau_produit | nouvelle_technique` ;
  - `nature` : `initiale | continue`.

  On réutilise ainsi l'éditeur, l'assistant (étapes, photos, vidéo, quiz), les versions FR/NL, la tablette, le PDF et
  la lecture du Professeur. Le statut réseau est le `statut` existant ; `publiee` s'affiche « validée » pour une FC.
  Un nouveau produit pointe sa fiche technique du panel (`fiches_techniques.php`).
- **`for_fc_dossier`** : une ligne par magasin × formation. Elle porte :
  - le statut du magasin : `brouillon` (préparée, pas visible), `en_cours`, `a_valider` (14 jours de photos faits),
    `validee` ;
  - l'échéance et les personnes ou postes assignés ;
  - `contenu_adapte` : le JSON complet ; NULL = le modèle du réseau ;
  - qui a adapté et quand, qui a validé et quand.
- **`for_fc_tache`** : la tâche unique d'un dossier. Elle porte l'id de la tâche au panel, la période (du / au,
  14 jours), l'état (`active | terminee | archivee`), qui l'a créée et quand.
- **`for_fc_photo`** : une ligne par jour de la tâche. Elle porte `completion_id`, `attachment_id`, l'avis du
  consultant et, à l'archivage, le fichier copié dans le dossier privé du cockpit (les adresses du panel expirent).
- **`for_fc_trace`** : le journal des actions, sans modification ni effacement. Actions : diffusée, tâche créée,
  adaptée, photo relue, terminée, validée, archivée, renvoyée. Chaque ligne porte la version, qui (nom et rôle) et
  quand. Chaque action s'écrit aussi dans `ceo_journal_entry` (`journalAdd`).
- **Le document de formation du franchisé** (écran 08) est tiré de ces tables et rendu en PDF A4 par `rapPdfRendu`
  (gabarit des documents de gestion). Chaque validation en ajoute une version, gardée avec la trace. Il est déposé,
  avec l'archive de la validation, dans l'onglet Formation du **coffre-fort du franchisé**
  (`consultant/docs/maquettes/coffre-fort/`).
- Les tables sont créées par `ensureFormationContinue()` (`CREATE TABLE IF NOT EXISTS` / `ADD COLUMN`, comme
  `ensureSop`). Aucune table existante n'est renommée ni vidée.

### La validation par la tâche unique (14 jours, photo obligatoire)

1. À la mise en vente (ou à la diffusion d'une formation reliée à un produit), le cockpit crée la tâche « Photo
   qualité · <produit> » pour ce magasin : photo et tâche obligatoires, chaque jour pendant 14 jours.
2. Chaque jour, l'équipe fait la photo dans ses tâches, comme les autres photos de contrôle. Le consultant la relit
   avec les avis existants (`task-reviews`) : écran 02, « Vérifier ».
3. Une fois les 14 photos conformes, le dossier passe « à valider ».
4. À la validation par le consultant :
   - la tâche est **désactivée et gardée** (`PATCH /tasks/{id}/active`, jamais `DELETE`) ;
   - les photos et leurs avis sont copiés dans l'archive ;
   - le document de formation du franchisé est réédité, avec qui et quand.
5. Une horloge quotidienne (comme `rapports_cron.sh`) lit les photos du jour et ferme les tâches arrivées au bout.

**Ce qui bloque** : le panel n'a pas de tâche par magasin et par période (tableau ci-dessus). Deux voies (décision 6) :
- **A. Tout de suite, avec ce qui existe.** Une tâche dédiée par formation et par magasin, dans une checklist
  « Formation continue », activée pendant les 14 jours puis désactivée. Risque : une tâche portée par un niveau de
  poste apparaît dans **tous** les magasins qui ont ce niveau. Il faut l'essayer d'abord sur un magasin d’essai.
  `GET /shops/{shop}/tasks/{task}/eligible-employees` laisse penser qu'un filtre par magasin existe, mais c'est à
  vérifier.
- **B. Proprement.** Demander à TF Buddy une tâche ponctuelle par magasin, sur le modèle des notifications :
  `shops`, `date_from`, `date_to`, `requires_photo`, `source_type: formation`, `source_id: FC-023`.

### Code et routes

- **Formation** : un module `formation/src/formation_continue.php` (`fcRoute`, branché comme `sopRoute`).
  - Siège :
    - `GET /formation/continue` : la liste et ses compteurs ;
    - `PATCH /formation/continue/{id}` : catégorie, statut, diffusion ;
    - `POST /formation/continue/{id}/diffuser` : crée les dossiers des magasins.
  - Écrans : l'écran Formations du portail (`portail.js`) reçoit les deux types et les filtres catégorie × statut
    (écran 01). L'assistant des SOP reçoit le type FC et la catégorie.
- **Cockpit** : l'entrée **Franchisés › Formations continues** (`navDef`), écran 02.
  - Routes :
    - `GET /formation/continue/franchises` : seulement les magasins du consultant ;
    - `GET /formation/continue/franchise/{shop}` ;
    - `PUT …/{shop}/{id}/adaptation` ;
    - `POST …/{shop}/{id}/tache` : créer ou relancer la tâche unique ;
    - `POST …/{shop}/{id}/valider` (désactive, archive, réédite le document) et `…/renvoyer` ;
    - `GET …/{shop}/document` : le PDF.
  - La fiche franchisé reçoit un onglet « Formation » qui mène au même écran.
- **Dashboard** :
  - la vue `?vue=formation` « Formations et suivi » (écrans 04 et 05) ;
  - le groupe de rail « Se former » ;
  - le bouton abricot « Mon dossier de formation » dans la barre du haut (écran 03), aussi au téléphone ;
  - le lien « Mon document de formation ».
  - Routes, sous le préfixe `/formation/continue/magasin`, ajouté à `ACCES_FRANCHISE` :
    - `GET ?shop=` : le dossier, les Nouveautés, le suivi et les photos ;
    - `GET /{id}` : la version du magasin ;
    - `POST /{id}/avancement` ;
    - `GET /document` : son PDF.
- **Nouveautés** : les FC `publiee` diffusées au magasin depuis 60 jours, la plus récente d'abord. Chacune montre son
  titre, sa catégorie, sa date et un lien `?vue=formation&fc=FC-023`. « Nouveau » s'affiche tant qu'elle n'a pas été
  ouverte.
- **Professeur** : un outil de plus, `dossier_formation`. Il liste le dossier du magasin et lit la version adaptée.
  Le `shop` lui est transmis par `amOutil`, jamais par le modèle.
  - Ses faits d'office : le résumé du dossier. Ses questions toutes prêtes sont tirées du dossier.
  - Il s'ouvre préchoisi depuis la page, par `IA.ton = 'professeur'`, comme le coach des bundles.
- **Manuel** : les deux articles demandés (écrans 06 et 07, avec les 14 jours de photos), rédigés en JSON dans
  `formation/data/formation/continue/`, encodés par `encoder_sop.py` en v1.0 « à valider », puis publiés quand tu le
  demandes. Codes proposés : 0.0.61 et 0.0.62.

### Droits (critère d'acceptation)

- **Franchisé** : les routes `/formation/continue/magasin` vérifient le `shop` contre la session du magasin. Le
  Professeur ne reçoit que ce magasin. Le document n'est rendu qu'à son magasin.
- **Consultant** : le serveur ne rend que les magasins dont il est le consultant dans `visitesCadre`. Le siège voit
  tout.
- **Limite actuelle** : il n'y a ni session consultant, ni connexion (`acces = ouvert`), donc ce filtre n'est
  aujourd'hui qu'un filtre d'écran. Voir la décision 2.

### Ne rien casser

- Les FO, leurs phases, les SOP, les articles, la tablette et le Professeur restent tels quels : `nature` vide vaut
  « initiale ».
- Il faut une garde : une FC non validée ne doit apparaître ni sur la tablette, ni dans le Manuel imprimé, ni dans
  les Nouveautés. Aujourd'hui, la tablette montre les documents « à valider ».
- Aucune tâche du panel n'est supprimée. Les écritures au panel passent par l'aperçu et les garde-fous du Cadre des
  tâches (`tkConstruire` : rien de recréé en double, arrêt au premier refus).
- Tests :
  - `tests_formation.php`, étendu ;
  - un test des droits (franchisé A ne lit pas B, consultant hors cadre refusé) ;
  - `tests_assistant_ia.php`, pour l'outil du Professeur ;
  - un test de l'archivage (14 photos copiées, tâche désactivée, document réédité).

### Étapes (une PR par étape, testée)

1. Le modèle et les routes du siège, puis l'écran 01.
2. Le dossier par magasin, la trace, puis l'écran du cockpit 02 (vérifier, adapter, valider).
3. La tâche unique et ses photos (voie A sur un magasin d’essai, ou voie B si TF Buddy l'ouvre), l'archive et le
   document de formation (écran 08).
4. Le dashboard : le bouton, « Formations et suivi », les Nouveautés, le suivi de l'équipe (écrans 03 et 04).
5. Le Professeur relié au dossier (écran 05).
6. Les deux articles du Manuel (écrans 06 et 07), encodés « à valider ».

## 3. Décisions à prendre avant de coder

1. **« Formations et suivi » = la page Formation du franchisé**, celle du bouton abricot (c'est mon hypothèse,
   maquettes 03 à 05). Ou bien est-ce une page du cockpit ?
2. **Les droits du consultant** : imposer une session consultant (compte de la console relié à son appartenance
   CONSULTANT) sur ces routes, même avec `acces = ouvert` (recommandé), ou garder un simple filtre d'écran pour l'instant ?
3. **L'avancement de l'équipe** (lu, quiz, vu faire) : déclaré par le franchisé dans son dossier (recommandé), ou
   enregistré par la tablette avec un PIN par personne, comme avant le 06/11 ?
4. **Le cockpit** : l'entrée sous « Franchisés » (proposé), ou un groupe « Gestion » recréé ?
5. **Les routes** : suivre la forme du module (`/formation/continue/…`), comme tout le dépôt. La forme
   `/api/v1/{app}/{domaine}/{ressource}` de `docs/API_STANDARD.md` est un écart connu (REORG_PLAN §8).
6. **La tâche unique** :
   - essayer la voie A sur un magasin d’essai pendant qu'on demande la voie B à TF Buddy (recommandé) ;
   - ou attendre la voie B ;
   - ou faire les 14 photos dans le cockpit, sans tâche du panel.
7. **Les règles des 14 jours** :
   - photos pour « nouveau produit » toujours, pour « technique » et « nouvelle technique » quand un produit est relié,
     jamais pour « connaissance » (proposé) ;
   - un jour sans photo ou une photo refusée ajoute un jour (proposé), ou bien la période est fixe.
