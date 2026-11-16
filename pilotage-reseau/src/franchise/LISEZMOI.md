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
| `offres_client.php` | Vendre › Offres | `/exploitation/offres-client`, `…/employes`, `…/pdf` — l'API Client offers de TF Buddy, par le compte admin ERP ; chaque offre relue doit être du magasin. La marge de l'offre se calcule à l'écran avec les coûts de recette de `/exploitation/promos-magasin/produits` |
| `offres_newsletter.php` | Vendre › Offres › Newsletter | `/dashboard/marketing/newsletter*` — le module newsletter du cockpit (`src/newsletter*.php`), au rôle du magasin vérifié contre la session, affiché par `public/assets/js/newsletter.js` ; sans la base des candidats à la franchise ; mode test tant que la marque n'envoie pas |
| `dashboard_marketing.php` | Marketing › Mon marketing ; Marketing › Réseaux sociaux | `GET /dashboard/marketing/annee` (les campagnes de l'année, pour le calendrier ; les saisons des produits viennent de `/production/saisons` et `/production/catalogue`) ; `GET /dashboard/marketing/campagnes` (les campagnes en cours du magasin et leur note de campagne, `mktBriefDonnees`, réduite au magasin) et `…/campagnes/annexe` (un document joint) ; `GET /dashboard/marketing` (le hub du marketing pour le magasin) ; `GET /dashboard/social/ouvrir` (un lien court de l'API Social du Marketing, jeton de service : l'espace boutique en mode intégré) |
| `boite_mail.php`, `boite_protocole.php` | Boîte mail (en tête du rail) | `/dashboard/boite*` — la boîte e-mail OVH du magasin (IMAP, MIME, SMTP écrits à la main, sans extension imap) : dossiers, lecture, pièces jointes, répondre/écrire/transférer, modèles (`pil_boite_modele`), messages automatiques (`pil_boite_auto`, `pil_boite_journal`, `pil_boite_etat` ; horloge `bin/boite_cron.sh`, 10 min). Accès dans `erp_cles` (`pil_boite_{shop}_adresse`, `…_mot_de_passe`), posés par le magasin (« Relier la boîte »). **Verrou** : la boîte s'ouvre avec SON mot de passe (cookie signé `cockpit_b{shop}`), même quand l'API est « ouverte » ; ses routes ne passent jamais par le cache `_cache`. Tests : `php bin/tests_boite_mail.php` (+ un IMAP et un SMTP de test, voir l'en-tête) |
| `catalogues_magasin.php` | Marketing › Mon catalogue | `/dashboard/catalogues/*` — relais vers l'API d'intégration du Marketing |
| `tresorerie.php` | Les chiffres › Trésorerie | `/tresorerie/*` — table `pil_tresorerie_plan` |
| `frais.php` | Les chiffres › Frais | `GET /exploitation/frais`, `POST /exploitation/frais/tva` — les frais du mois au panel, « TVA comprise ? » et le taux de chaque poste (table `pil_frais_tva`). Le calcul est dans `src/pnl_ht.php`, partagé avec le siège : le P&L hors TVA de Résultat (jour, semaine, mois). Tests : `php bin/tests_pnl_ht.php` |
| `dashboard_semaine.php` | la semaine jour par jour (téléphone) | `/exploitation/semaine-jours` |
| `temps_morts.php` | La journée › Temps morts | `/exploitation/temps-morts` |

Les autres routes du dashboard (Opérationnel, Tâches, Semaine, Mois, Année, Produits, Promotions, Primes, Concurrence,
Fidélité, l'assistant, la revue du soir…) restent dans `src/`, parce que le cockpit du siège les sert aussi. L'index de
l'API dit, route par route, le fichier et la fonction qui la servent.

- `dashboard_analyse.php` — Vendre › Analyse (15/11/2026, maquettes A, E et F) : le magasin sur la période choisie — semaine,
  mois, trimestre, année, 13 semaines ou du … au, face à la période d'avant au jour (`/dashboard/analyse/periode`) ; face aux
  autres magasins à taille
  égale, sans nom ni volume (`/dashboard/analyse/reseau`), les produits mois par mois depuis l'ouverture (`/mois`), un produit
  jour par jour avec ses invendus (`/jours`) ; la règle de marge commune (`daMarge`). Écran : `public/dashboard/analyse.js`.
  Tests : `php bin/tests_analyse.php`.

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
- **Les tests** : `php bin/tests_tresorerie.php`, `php bin/tests_pnl_ht.php`, `php bin/tests_temps_morts.php`, `php bin/tests_boite_mail.php` ; l'espace boutique intégré est
  testé côté Marketing (`api/social/bin/tests.php`, cas « cockpit »).
