# REORG_REPORT — Réorganisation du bac à sable en quatre domaines

**État au 19/11/2026, 18:15 : les quatre phases sont faites sur le serveur de test.** Phases 1 à 3 livrées (§5 ter) ;
phase 4 (après « PURGE ») : une table supprimée, `ceo_assistant_question`, avec sa sauvegarde vérifiée (§6 bis).

Décisions appliquées : A1 (la mission remplace le registre du 06/11, le Recrutement n'est plus un des quatre domaines),
A3 (la purge ne touche que des tables créées par ce dépôt), A4 et A5 (le scouting et le module RH sont gardés). Les autres questions du plan (§6) ont suivi ma
recommandation, sauf ce qui demande une action sur le serveur (A9) : rien n'y a été exécuté.

## 1. Les commits de la phase 3

| Étape | Commit | Contenu |
|---|---|---|
| 0 | `66925ba` | Ligne de base en lecture seule (`reorg/ligne_de_base.php`) et banc local (`reorg/banc.php`) |
| 1 | `53879ed` | `erp_keys` (= `erp_cles`) : un seul accès aux clés, reprise des clés des réglages |
| 2 | `7ad0412` | Le dépôt en quatre domaines + `shared/`, migrations, site assemblé, déploiement, workflows |
| 3 | `0975647` | Formation : 19 tables `ceo_` → `for_` |
| 4 | `f95f031` | Marketing : 12 tables `ceo_` → `mar_` |
| 5 | `1cd2db2` | Dashboard franchisé : 29 tables → `panel_` |
| 6 | `11b4bc4` | Pilotage consultant : 84 tables → `con_` |
| 8 | (ce commit) | Documentation : `CLAUDE.md` (racine et un par domaine), `README.md`, `docs/PORTAILS.md`, chemins des docs |

## 2. Ce qui a été déplacé

3 251 fichiers déplacés par `git mv` (historique gardé), selon `reorg/fichiers.tsv` (plus les dépendances commises
`vendor/` de l'app employés et des écrans). `pilotage-reseau/` ne garde que le hors-périmètre : la copie de l'ERP
(`apps/production`) et le module RH. Chemins recalculés : 89 fichiers PHP automatiquement (`reorg/deplacer.py`),
le reste à la main (outils d'inventaire du code, tests, sondes, crons, maquettes, déploiement, workflows) ; 536 chemins
cités dans 31 fichiers de documentation.

Sur le serveur, rien ne change d'adresse : `shared/bin/assembler_public.php` reconstitue `TARGET_DIR/public` en liens
vers le `public/` de chaque domaine ; `public/uploads/` et `public/assistant/uploads/` (fichiers déposés) ne bougent
pas ; `config/config.php` passe dans `shared/config/` (l'ancien chemin y renvoie).

## 3. Ce qui a été renommé

144 tables (registre complet : `shared/src/reorg_tables.php`, correspondances : `REORG_PLAN.md` §1) :

| Domaine | Tables | Préfixes d'origine | Migration |
|---|---:|---|---|
| Formation | 19 | `ceo_sop*`, `ceo_ref_*`, `ceo_manuel_*`, `ceo_tablette_*` | `002_formation_tables` |
| Marketing | 12 | `ceo_md_*`, `ceo_fid_*`, `ceo_promo*`, `ceo_prospect` | `003_marketing_tables` |
| Dashboard franchisé | 29 | `aia_*`, `mot_*`, `pil_boite_*` et autres `pil_` du dashboard, 8 `ceo_*` | `004_panel_tables` |
| Pilotage consultant | 84 | `ceo_*`, `pla_*`, `hdk_*`, `pil_cq_*`, `pil_fil*` | `005_consultant_tables` |

Gardent leur nom : les 8 tables partagées, les `mar_` et `con_` déjà au bon préfixe, et les tables des autres
applications que le code lit (ERP TF Buddy, PWA consultant `mac_*`, CRM `crm_mo_*`, signage `sig_*`, et `pwa_orders`,
`pwa_cafe_orders`, relevées au balayage de l'API).

## 4. Les migrations créées

Toutes dans `shared/db/migrations/`, chacune avec `up` et `down`, passées dans l'ordre et notées dans
`ceo_app_setting` (clé `migrations`, pas de nouvelle table) ; lancées en tête de `ensureInstalled()` et par
`shared/bin/deploy.sh` (`php shared/bin/migrer.php up`) avant tout appel de l'API.

| Migration | Aller | Retour |
|---|---|---|
| `001_cles_vers_erp_cles` | les champs-clés des réglages → `erp_cles` | `erpClesRendre()` |
| `002_formation_tables` à `005_consultant_tables` | un `RENAME TABLE` par domaine, lignes comptées avant/après | le même dans l'autre sens |

Garde-fous : aucun `DROP` ; arrêt sans rien renommer si l'ancien et le nouveau nom existent tous les deux, ou si une vue
lit l'ancien nom ; verrou MySQL contre deux passages simultanés ; la livraison s'arrête en erreur si une migration
s'arrête.

## 5. État de `erp_keys` (`erp_cles`) — noms seulement

Valeurs **en clair** (pas de clé de chiffrement). Seul accès : `shared/src/erp_cles.php` (lit les portails `commun`,
`pil`, `mar`, `frm`). Plus aucune requête SQL directe ailleurs (MétaDonnée et la sonde de l'assistant passent par lui).

| Clés | Portail | Domaines consommateurs |
|---|---|---|
| `meta_app_id`, `meta_app_secret`, `meta_page_id`, `meta_page_token`, `meta_system_token` | commun | Marketing |
| `smtp_host`, `smtp_port`, `smtp_secure`, `smtp_user`, `smtp_pass`, `smtp_from`, `smtp_from_name` | commun | tous (envois) |
| `anthropic_api_key`, `anthropic_workspace_id` | commun | Pilotage, Dashboard |
| `tfbuddy_base`, `tfbuddy_phone`, `tfbuddy_password` | commun | tous (API du panel) |
| `erp_jeton_service`, `rec_api_url`, `pil_api_url`, `mar_api_url` | commun | partagé, Dashboard |
| `pil_boite_{magasin}_adresse` / `_utilisateur` / `_mot_de_passe` / `_imap` / `_nom` | pil | Dashboard |
| **Nouveaux (reprise 001)** `pil_anthropic_api_key`, `pil_anthropic_workspace_id` | pil | Pilotage, Dashboard |
| `pil_google_places_api_key` | pil | Marketing (avis) |
| `pil_tfbuddy_base`, `pil_tfbuddy_phone`, `pil_tfbuddy_photo_base` (le mot de passe reste `tfbuddy_password`) | pil | tous |
| `pil_erp_admin_base`, `pil_erp_admin_phone`, `pil_erp_admin_password` | pil | Dashboard, Pilotage |
| `pil_smtp_host`, `pil_smtp_port`, `pil_smtp_secure`, `pil_smtp_user`, `pil_smtp_pass`, `pil_smtp_from` | pil | tous |
| `pil_push_vapid_pem`, `pil_push_vapid_public` | pil | partagé (Web Push) |
| `mar_meta_verify_token`, `mar_meta_app_secret`, `mar_meta_page_token`, `mar_meta_page_token_crm`, `mar_smsapi_token` | mar | Marketing |

Les nouveaux noms sont propres au cockpit : une clé commune tenue par un autre portail n'est jamais écrasée, et la
priorité de lecture n'a pas changé (`setting()` relit ces champs à la même place). Les **doublons** avec les clés
communes (par ex. `pil_smtp_*` / `smtp_*`, `pil_anthropic_api_key` / `anthropic_api_key`) seront signalés par la reprise
au premier passage sur le serveur (bilan dans le réglage `migrations`), noms seulement : les fusionner est une
décision (points à trancher, §8).

Restent hors d'`erp_cles`, sans y toucher (règle « .env ») : l'accès à la base (`COCKPIT_DB_*`, `MAR_DB_*`, `HR_DB_*`),
les secrets du déploiement (`DEPLOY_SSH_PASSWORD`…), les secrets de `config.php` et de l'environnement non migrés
(`META_*`, `SMSAPI_*`, `TWILIO_*`, `GOOGLE_API_KEY`, `LINKEDIN_ACCESS_TOKEN`, `PANEL_API_*`, `ERP_ADMIN_*`,
`MAR_ERP_API_*`), les secrets internes (signatures de session et de liens, mot de passe haché, codes d'accès, sel,
code des tablettes) et le module RH.

## 5 bis. Ligne de base du serveur de test (mesurée le 19/11/2026 à 17:30, lecture seule)

Sonde `.github/workflows/sonde-reorg.yml` (runs 1 et 2, succès), `reorg/ligne_de_base.php` contre le code déployé.
Détail par table : `reorg/ligne_de_base_serveur.tsv` ; JSON complet dans le dossier privé du serveur
(`/var/lib/consulant_bo/prive/reorg/`).

- Base `atelierby_db`, hôte 127.0.0.1:3306, MySQL 8.0 : **919 tables**, 8 vues, 279 clés étrangères, 1 déclencheur,
  aucune routine ni événement. Le compte a les droits d'un `RENAME TABLE` (ALTER, DROP, CREATE, INSERT).
- **Les 144 tables à renommer sont toutes présentes, aucune collision** (aucun nouveau nom déjà pris), aucune table
  `panel_*` ou `for_*` existante. Lignes concernées : Formation 19 tables / 542 lignes, Marketing 12 / 2 292,
  Dashboard franchisé 29 / 497 843, Pilotage consultant 84 / 19 374.
- **Vues** : aucune ne lit une table à renommer (`mar_v_*` lisent des tables `mar_` inchangées ; `max_inv` et
  `planogram_berdiff` lisent des tables de l'ERP). **Déclencheur** : un seul, sur `client` (ERP). **Clés étrangères**
  touchant nos tables : `ceo_product_month_sales` → `ceo_product`, et `ceo_project_task`, `ceo_shop_budget`,
  `ceo_shop_month_perf` → `ceo_shop` (suivies par le renommage) ; aucune table d'une autre application ne pointe vers
  une table à renommer. Les FK de `mar_avis`, `mar_avis_fiche`, `mar_social_post` vers `ceo_shop` et de
  `ceo_task_issue` vers `ceo_project_task`, déclarées dans `schema.sql`, n'existent pas en base.
- Les cinq `ceo_verif_commande / geste / matiere / produit / stock_jour` n'existent pas (documentation seulement).
  Les anciens noms des registres (`ceo_fb_post`, `ceo_visite`…) n'existent plus : aucun doublon.
- 588 tables de la base n'appartiennent pas à l'inventaire de ce dépôt (panel TF Buddy, CRM `crm_*`, landing `lp_*`,
  PWA `pwa_*`, signage `sig_*`, Social `mar_sa_*`…) : jamais touchées.

## 5 ter. Livraison sur le serveur de test (19/11/2026, 17:45–17:54)

PR [#163](https://github.com/samsam2703MFC/consultant_BO/pull/163) fusionnée dans la branche par défaut (avec les
83 commits du 19/11 repris dans la disposition en domaines), branche de déploiement avancée en avance rapide
(`b74f307` → `b831193`), livraison par le workflow de déploiement (run 2323, lancé à la main : le push de plus de
3 000 fichiers dépassait les 300 fichiers que GitHub examine pour son filtre de chemins).

- Contrôles avant livraison : syntaxe, chargement de l'API, noms globaux, 45 fichiers de tests verts, RH.
- Sur le serveur : `config.php` repris dans `shared/config/`, site assemblé (90 liens), puis les migrations :
  `001` 14 champs-clés rangés dans `erp_cles`, 0 conflit, 7 doublons avec des clés communes signalés ;
  `002` 19, `003` 12, `004` 29, `005` 84 tables renommées (aucune déjà faite, aucune absente).
- Recette locale et recette publique OK (cockpit, dashboard, tablette, Formation, RH, API) ; ERP `/production` OK.
- **Contrôle des lignes après livraison** (sonde, run 3) : 919 tables avant et après ; plus aucun ancien nom ; **140 des
  144 tables renommées ont exactement leurs lignes de 17:30** ; 4 en ont une de plus, par l'activité du serveur
  entre les deux mesures (`for_sop` et `for_sop_version` : la fiche 5.6.02 publiée par la livraison de 17:31 ;
  `con_pwa_client` et `con_pwa_visite` : un passage client relevé). Ont aussi bougé, normalement : `erp_cles`
  (+13, les clés rangées), le journal, les compteurs d'usage, les réglages.

## 6. Liste définitive « à supprimer » (phase 4, après « PURGE »)

Uniquement des tables créées par ce dépôt (décision A3). Nombres de lignes : ligne de base du serveur (§5 bis) ; ils
seront relus au moment de la purge.

| Table | Lignes | FK venant d'une table conservée | Code qui la cite encore (à retirer avant le contrôle bloquant) | Statut |
|---|---|---|---|---|
| `ceo_assistant_question` | **15** (mesuré) | aucune | `panel/src/assistant_ia.php` (reprise une fois), `panel/src/assistant_magasin.php` (commentaire) | **supprimée le 19/11/2026** (§6 bis) : ses 15 lignes avaient toutes leur copie dans `panel_aia_question` ; sauvegarde vérifiée |
| `ceo_parcours`, `ceo_parcours_etape`, `ceo_prod_planogram`, `ceo_x` | absentes | — | — | rien à faire : déjà effacées par le code ou jamais créées en base |
| anciens noms des registres | absents | — | — | rien à faire : aucun doublon |

**La purge se réduit donc à une table, `ceo_assistant_question` (15 lignes).**

**Gardées (décisions du 19/11/2026)** : le scouting (`ceo_scouting_*`, A4) et le module RH (`hr_dimona_*`, A5) restent
en place, hors périmètre, dans `pilotage-reseau/` pour le code ; leurs tables ne font plus partie de la purge.

Jamais supprimées : les tables des autres applications de la base (panel TF Buddy, PWA consultant, CRM, signage,
Social, landing), la base `erp` de la copie de l'ERP, `erp_cles`, les tables partagées.

## 6 bis. La purge (phase 4, 19/11/2026, 18:09–18:11)

Par `reorg/purger.php`, lancé sur le serveur par `.github/workflows/purge-reorg.yml` ; d'abord en mode contrôle
(run 44573943477, rien supprimé), puis en mode « purger » (run 44574150973). Essayé avant sur le banc local : quatre
cas d'arrêt (vue qui la lit, ligne sans copie, une ligne de plus, mauvaise base), purge, restauration du fichier.

Contrôles bloquants, tous passés sur le serveur (127.0.0.1:3306, base `atelierby_db`, MySQL 8.0) :
- la table est là, est une table, a **15 lignes** comme validé ;
- les 15 ont leur copie dans `panel_aia_question` (date, magasin, question, réponse) ; la reprise est marquée faite ;
- aucune vue, clé étrangère, déclencheur, routine ni événement ne la touche ;
- le code déployé ne la nomme que dans la reprise déjà faite et des commentaires ;
- **sauvegarde** dans le dossier privé, lisible par root seul :
  `/var/lib/consulant_bo/prive/reorg/purge_20261119_171024_ceo_assistant_question.sql` (36 188 octets, sha256
  `f9ad2260…49d98`), **vérifiée** en la restaurant dans une table temporaire : 15 lignes identiques colonne par colonne.
  Restaurer : `mysql atelierby_db < ce_fichier`. (Une première sauvegarde, du run de contrôle, est à côté.)

Résultat : `DROP TABLE ceo_assistant_question` ; **919 tables avant, 918 après** ; seule disparue :
`ceo_assistant_question` ; aucune apparue ; aucune ligne changée dans les 918 autres pendant la purge. Aucune vue,
aucun déclencheur ni aucune fonction à supprimer avec elle (aucun ne la touchait).

Code mort, dans un commit à part : la reprise unique (`aiaReprendreQuestions`) retirée de `panel/src/assistant_ia.php`,
commentaires mis à jour.

## 7. Résultats des tests

- Hors ligne, à chaque étape : **48 fichiers verts, 4 rouges déjà connus et ignorés par la CI** (`tests_ca_net`,
  `tests_catalogue`, `tests_cq_mail`, `tests_documents`) — identique à la ligne de base. Bloquants de la CI verts
  (`tests_noms_globaux`, `test_chargement`). RH complet : 141 ok. `shared/bin/tests.php` : 121 ok, dont le nouveau test
  « aucun ancien nom ne reste dans le code ».
- Banc local (MariaDB 10.11, base reconstruite par le code) : chaque migration aller, retour, aller, avec chaque table
  gardant exactement ses lignes et aucune autre ne bougeant ; conflit refusé sans suppression ; clés : 34
  vérifications ; installation neuve : 203 tables créées directement sous les nouveaux noms ; balayage des 507 routes
  GET de l'API sur la base renommée : aucune table des quatre domaines introuvable.
- Répétition locale du site : site assemblé + API sur le banc, pages et API aux mêmes adresses.
- Ligne de base du serveur mesurée (§5 bis), migrations livrées et lignes comparées après (§5 ter), purge (§6 bis).
- Après la livraison et le retrait du code mort : **49 fichiers verts, 3 rouges déjà connus** (`tests_ca_net`,
  `tests_cq_mail`, `tests_documents` ; `tests_catalogue` est repassé au vert entre-temps), RH `--unit` vert.

## 8. Points à trancher avant le codage final

1. **Fait le 19/11** : livré (§5 ter). À surveiller : toute session encore partie de l'ancienne disposition
   (`pilotage-reseau/src`…) doit repartir de la branche par défaut ; livrer l'ancien code recréerait des tables vides
   sous les anciens noms (les migrations, elles, refuseraient alors de renommer sans rien supprimer).
2. A4 et A5 tranchés le 19/11 : scouting et module RH gardés.
3. Les doublons de clés (`pil_*` du cockpit / clés communes) : lesquels fusionner ; le chiffrement d'`erp_cles`.
4. Les secrets restés dans `config.php` / l'environnement : les migrer vers `erp_cles` (il faut toucher au déploiement).
5. Les lectures croisées entre domaines (REORG_PLAN.md §7) et les accès directs aux tables d'autres applications (§8) :
   à passer par des API.
6. Les monolithes de `shared/` (`endpoints.php`, `writes.php`, `installer.php`, `templates.js`…) : les découper par
   domaine.
7. Les routes hors format `/api/v1/{app}/{domaine}/{ressource}` et les URL écrites en dur (REORG_PLAN.md §3.3, §8).
8. Un déploiement par domaine (aujourd'hui un seul) et un `bin/export.sh` par domaine pour l'export vers l'ERP.

## 9. Le signage rejoint le Marketing (20/11/2026)

Décision de Sam (19/11/2026) : « tout le signage doit aller dans marketing, le reste reste dans cette structure ».
Destination choisie : le dépôt `marketing` (dossier `signage/`, historique gardé), comme Social le 06/11. Tables
`sig_` → `mar_sig_` (37 noms ; base SQLite de la régie au démarrage, copie MariaDB pendant la livraison), livraison
`marketing/.github/workflows/deploy-signage.yml`, adresses inchangées. Ici : le diagnostic et le câblage de
`shared/bin/deploy.sh` lisent `mar_sig_products` / `mar_sig_product_categories`. `landing` lit `mar_sig_seasons`
(ancien nom en repli). `landing` (`lp_`) et `atelier-espace-candidat` (`crm_`) restent tels quels.
