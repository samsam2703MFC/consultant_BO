# Le cockpit franchisé — le dossier

Regroupé le 15/11/2026 (demande : « regrouper tout le nouveau cockpit franchisé dans un dossier » ; choix : un dossier
dédié, un seul déploiement). Le cockpit franchisé est le **dashboard magasin** : ce que voit un magasin ou un franchisé,
connecté par son code ou la console. Le cockpit du siège et lui partagent la même API (`public/api/index.php`), la même
base et le même déploiement.

## Où est quoi

| Partie | Dossier |
|---|---|
| L'écran (vanilla JS, sans compilation) | `public/dashboard/` : `index.html`, `dashboard.js` (le rail, la barre, les vues), et un module par page autonome — `offres.js`, `marketing.js`, `reseaux.js`, `tresorerie.js`, `catalogue.js`, `api-index.js` (+ leurs `.css`) |
| Les modules serveur propres au franchisé | `src/franchise/` (ci-dessous) |
| Le code partagé avec le siège | `src/` : l'accès (`acces.php`), l'ERP (`erp_api.php`, `panel_api.php`), les ventes, le résultat, les tâches, la fidélité… |
| L'index de l'API du dashboard | `public/dashboard/api-index.json`, généré par `tools/dashboard-api-index.py` ; le bouton « API » de la barre l'affiche, avec les appels de la session |

## `src/franchise/`

| Fichier | Page du dashboard | Routes |
|---|---|---|
| `offres_client.php` | Vendre › Offres | `/exploitation/offres-client`, `…/employes`, `…/pdf` — l'API Client offers de TF Buddy, par le compte admin ERP ; chaque offre relue doit être du magasin |
| `dashboard_marketing.php` | Marketing › Mon marketing ; Marketing › Réseaux sociaux | `GET /dashboard/marketing` (le hub du marketing pour le magasin) ; `GET /dashboard/social/ouvrir` (un lien court de l'API Social du Marketing, jeton de service : l'espace boutique en mode intégré) |
| `catalogues_magasin.php` | Marketing › Mon catalogue | `/dashboard/catalogues/*` — relais vers l'API d'intégration du Marketing |
| `tresorerie.php` | Les chiffres › Trésorerie | `/tresorerie/*` — table `pil_tresorerie_plan` |
| `dashboard_semaine.php` | la semaine jour par jour (téléphone) | `/exploitation/semaine-jours` |
| `temps_morts.php` | La journée › Temps morts | `/exploitation/temps-morts` |

Les autres routes du dashboard (Opérationnel, Tâches, Semaine, Mois, Année, Produits, Promotions, Primes, Concurrence,
Fidélité, l'assistant, la revue du soir…) restent dans `src/`, parce que le cockpit du siège les sert aussi. L'index de
l'API dit, route par route, le fichier et la fonction qui la servent.

## Les règles

- **L'accès** : une session de magasin n'ouvre que les préfixes de `ACCES_FRANCHISE` (`src/acces.php`), et le magasin
  de la requête (`shop`…) doit être le sien. Une route qui désigne un objet par son numéro (une offre, un post) vérifie
  elle-même qu'il est de ce magasin. L'index de l'API signale les chemins qu'une session de magasin se verrait refuser
  en mode « console » (aujourd'hui : `/marketing/avis`, `/meta`, `/produits`).
- **Les autres portails** : par leur API, jamais leurs tables (`CLAUDE.md` à la racine). Le Marketing : `mar_api_url` et
  `erp_jeton_service` d'`erp_cles` (Mon catalogue, Réseaux sociaux). L'ERP TF Buddy : `ErpApi` (compte admin).
- **Le déploiement** : celui du cockpit (`bin/deploy.sh`, `deploy.trigger`). `bin/deploy.sh`, `bin/tests.php`,
  `bin/tests_primes.php`, `bin/carte_sources.php` et `src/marketing_sources.php` lisent `src/franchise/*.php` comme
  `src/*.php`.
- **Les tests** : `php bin/tests_tresorerie.php`, `php bin/tests_temps_morts.php` ; l'espace boutique intégré est
  testé côté Marketing (`api/social/bin/tests.php`, cas « cockpit »).
