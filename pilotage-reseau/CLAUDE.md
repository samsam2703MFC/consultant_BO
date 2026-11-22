# CLAUDE.md — pilotage-reseau/ (hors des quatre domaines)

Depuis la réorganisation du 19/11/2026, ce dossier ne garde que ce qui n'appartient à aucun des quatre domaines, en
attendant la décision de la phase 4 (REORG_PLAN.md, REORG_REPORT.md) : rien ici n'est supprimé sans « PURGE ».

## La copie de l'ERP (`pilotage-reseau/apps/production`)
L'ERP SLFood servi à `/production`, avec sa propre base (`erp`, tables `n_`) : lire `pilotage-reseau/apps/production/LISEZMOI.md`.
Livré par le même workflow (`.github/workflows/deploy.yml`, étape « Déployer l'ERP »).

## Module RH · Dimona (`/hr`)
Lire `pilotage-reseau/docs/dimona.md`. En bref :
- **Code** : `pilotage-reseau/src/hr/` (namespace `Hr\`, autoload `pilotage-reseau/src/hr/autoload.php`, assemblage `pilotage-reseau/src/hr/bootstrap.php`, qui charge
  `shared/src/Db.php`).
- **Page** : `pilotage-reseau/public/hr/` ; **API** : `pilotage-reseau/public/api/hr/index.php` → `Hr\Http\Api`, routes `/api/v1/erp/dimona/*`.
- **Types ONSS** : générés depuis `pilotage-reseau/docs/dimona/openapi_dimona_v2.yaml` par `pilotage-reseau/tools/hr-dimona/generate.py`. Ne pas
  modifier `pilotage-reseau/src/hr/Dimona/Dto` ni `Generated` à la main.
- **Identité** : la session de la console franchisé (dépôt WebShop). Jamais le jeton admin réseau.
- **Tests** : `php pilotage-reseau/tests/hr/run.php` (aucun appel à l'ONSS réel ; faux serveurs dans `pilotage-reseau/tests/hr/mock/`).
- **Cron** : `pilotage-reseau/bin/hr_dimona_cron.sh`, chaque minute, sous www-data.
- **Environnements** : simulation seule tant que `HR_DIMONA_PROD_ENABLED` ne vaut pas `1`.
- Chaque règle métier ONSS cite sa source. Ce qui n'est pas cité n'est pas supposé.
