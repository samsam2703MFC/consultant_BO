# REORG_REPORT — Réorganisation du bac à sable en quatre domaines

**État au 19/11/2026 : phases 1 à 3 faites sur la branche `reorg/structure-domaines`, rien n'est livré sur le serveur
de test, rien n'est supprimé.** La phase 4 (purge) attend « PURGE » ; ce rapport sera complété à ce moment-là (tables
supprimées et leurs lignes, dump, tests finaux).

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

## 6. Liste définitive « à supprimer » (phase 4, après « PURGE »)

Uniquement des tables créées par ce dépôt (décision A3). Les nombres de lignes viennent de la ligne de base sur le
serveur (`php reorg/ligne_de_base.php`), **pas encore mesurée** : aucune exécution sur le serveur sans votre accord.

| Table | Lignes | FK venant d'une table conservée | Code qui la cite encore (à retirer avant le contrôle bloquant) | Statut |
|---|---|---|---|---|
| `ceo_assistant_question` | à mesurer (absente ?) | aucune | `panel/src/assistant_ia.php` (reprise une fois), `panel/src/assistant_magasin.php` (commentaire) | à supprimer après contrôle que `panel_aia_question` a toutes ses lignes |
| `ceo_parcours`, `ceo_parcours_etape` | à mesurer (déjà effacées ?) | aucune | `formation/src/formation_postes.php` (leur effacement) | à supprimer si encore présentes |
| `ceo_prod_planogram` | à mesurer (absente ?) | aucune | `shared/src/installer.php` (`planoMigrer`) | à supprimer si encore présente |
| `ceo_x` | à mesurer (absente ?) | aucune | `shared/bin/tests.php` (test) | à supprimer si présente |
| anciens noms des registres (`ceo_fb_post`, `ceo_nl_*`, `ceo_visite*`…) | à mesurer | — | registres `marketing_tables.php`, `con_tables.php` | seulement s'ils existent en double : cas par cas, avec vous |

**Gardées (décisions du 19/11/2026)** : le scouting (`ceo_scouting_*`, A4) et le module RH (`hr_dimona_*`, A5) restent
en place, hors périmètre, dans `pilotage-reseau/` pour le code ; leurs tables ne font plus partie de la purge.

Jamais supprimées : les tables des autres applications de la base (panel TF Buddy, PWA consultant, CRM, signage,
Social, landing), la base `erp` de la copie de l'ERP, `erp_cles`, les tables partagées.

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
- **Pas fait** : la vérification des nombres de lignes sur la vraie base du serveur de test (rien n'y a été lancé).

## 8. Points à trancher avant le codage final

1. **Livrer sur le serveur de test** : la ligne de base (lecture seule) puis la branche. Le serveur est livré par la
   branche de déploiement, où d'autres sessions poussent chaque jour dans l'ancienne disposition : il faut fusionner
   `reorg/structure-domaines` dans cette branche (et y reporter ce qui a été ajouté depuis le 19/11, la routine du
   matin le signale), puis ne plus livrer l'ancienne disposition — sinon l'ancien code recréerait des tables vides sous
   les anciens noms.
2. A4 et A5 tranchés le 19/11 : scouting et module RH gardés.
3. Les doublons de clés (`pil_*` du cockpit / clés communes) : lesquels fusionner ; le chiffrement d'`erp_cles`.
4. Les secrets restés dans `config.php` / l'environnement : les migrer vers `erp_cles` (il faut toucher au déploiement).
5. Les lectures croisées entre domaines (REORG_PLAN.md §7) et les accès directs aux tables d'autres applications (§8) :
   à passer par des API.
6. Les monolithes de `shared/` (`endpoints.php`, `writes.php`, `installer.php`, `templates.js`…) : les découper par
   domaine.
7. Les routes hors format `/api/v1/{app}/{domaine}/{ressource}` et les URL écrites en dur (REORG_PLAN.md §3.3, §8).
8. Un déploiement par domaine (aujourd'hui un seul) et un `bin/export.sh` par domaine pour l'export vers l'ERP.
