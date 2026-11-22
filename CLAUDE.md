# Dépôt des quatre domaines — L'Atelier By

Décision du 19/11/2026 (elle remplace celle du 06/11 : `rec_` / `mar_` / `pil_` / `frm_`) : quatre domaines, un dossier
et un préfixe de tables chacun. Chaque dossier a son `CLAUDE.md` : lis celui du dossier où tu travailles.
Le portail Commercial (`commercial/`, `sm_`, servi à `/commercial/`) s'y est ajouté le même jour, sur les mêmes règles.

| Domaine | Dossier | Tables |
|---|---|---|
| Marketing | `marketing/` | `mar_` |
| Formation | `formation/` | `for_` |
| Pilotage (consultant) | `consultant/` | `con_` |
| Dashboard / day panel franchisé | `panel/` | `panel_` |
| Commercial (portail du commercial B2B, ajouté le 19/11/2026) | `commercial/` | `sm_` |
| Partagé | `shared/` | noms conservés (`ceo_shop`, `ceo_app_setting`, `ceo_journal_entry`, `erp_cles`…) |
| Hors périmètre | `pilotage-reseau/` | copie de l'ERP (`pilotage-reseau/apps/production`, base `erp`), module RH (`hr_dimona_*`) |

- **L'application Marketing** (campagnes, catalogues imprimés, Social, signage) vit dans le dépôt `marketing`
  (samsam2703MFC/marketing), qui reste séparé (décision du 21/11/2026) : `marketing/` d'ici n'est que le marketing du
  cockpit, il parle au module par son API (`mar_api_url`). Détail : `docs/PORTAILS.md`.
- **Tables** : une table, un domaine, `{préfixe}_{entité}` en snake_case. Ce qui sert à plusieurs domaines va dans
  `shared/` (code) et garde son nom (tables). Renommer une table : une migration `shared/db/migrations/NNN_*.php`
  (`up` / `down`, un `RENAME TABLE`, jamais de `DROP`), le registre `shared/src/reorg_tables.php`, et
  `php shared/bin/migrer.php statut | up | down <nom>`.
- **Clés** : toutes dans `erp_cles` (la table des clés commune, la « erp_keys » de la réorganisation), lues et écrites
  par `shared/src/erp_cles.php` et par lui seul — aucune requête SQL directe sur `erp_cles`. Les valeurs y sont en
  clair. Dans l'environnement : l'accès à la base, rien d'autre de nouveau.
- **Code** : un module serveur dans `<domaine>/src/`, ses pages dans `<domaine>/public/`. Le site servi est assemblé
  (`shared/bin/assembler_public.php`) : les adresses publiques ne dépendent pas du dossier ; `public/uploads/` (fichiers
  déposés) ne bouge jamais. `shared/src/depot.php` dit où est la racine et où est le code.
- **Déploiement** : un seul, `.github/workflows/deploy.yml` + `shared/bin/deploy.sh`, déclenché par
  `shared/deploy.trigger` sur la branche de déploiement.
  Mise en ligne **toujours depuis la branche par défaut** : PR fusionnée dans `claude/new-session-deedox` (avec sa
  ligne dans `shared/deploy.trigger`), puis avance rapide de `claude/deploiement-application-serveur-lmz1bm` jusqu'à
  ce commit ; jamais de commit ni de fusion directe sur la branche de déploiement (détail : `shared/CLAUDE.md`).
- **Tests** : `php shared/bin/tests_noms_globaux.php` et `php shared/bin/test_chargement.php` (bloquants en CI), puis
  `*/bin/tests_*.php` et `php pilotage-reseau/tests/hr/run.php`.
- **Écarts connus, non corrigés** (REORG_PLAN.md §8) : lectures SQL directes des tables de l'ERP et d'autres
  applications, URL écrites en dur, routes hors format `/api/v1/{app}/{domaine}/{ressource}`.
- Registre et règles des portails : `docs/PORTAILS.md`. Design system : `docs/DESIGN_SYSTEM.md`. Standard d'API :
  `docs/API_STANDARD.md`. La réorganisation : `REORG_PLAN.md`, `REORG_REPORT.md`.
