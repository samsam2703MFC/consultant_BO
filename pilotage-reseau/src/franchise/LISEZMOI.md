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
| `automatisations.php` | Paramètres › Mails et automatisations (`automatisations.js`) | `GET|POST /dashboard/boite/automatisations`, `POST …/automatisations/essai` — ce que le magasin envoie tout seul (16/11/2026, maquette `docs/maquettes/parametres-mails/`). En service : le **rappel la veille du retrait** (les commandes clients du panel à retirer demain, ni remises ni annulées ; e-mail depuis la boîte du magasin et/ou SMS par `src/sms.php`, à l'heure choisie ; une fois par commande et par canal, au journal `pil_boite_journal`). Réglage : `pil_auto_reglage`. Routes de la boîte, donc le même **verrou** (boîte ouverte) ; l'horloge est celle de la boîte (`bmCron` appelle `auCron`). **L'équipe** (17/11/2026) : les adresses qui reçoivent la fiche d'une promotion lancée avec le Coach Vente (`pil_equipe_mail`, `POST /dashboard/boite/equipe`, boîte ouverte). À venir : le dossier comptable du mois, « Commande prête » (via la tablette). Tests : `php bin/tests_automatisations.php` |
| `assistant_ia.php` | L'assistant (bouton « Assistant », modale) | Chargé par `src/assistant_magasin.php` (17/11/2026). Les tables **`aia_`** : `aia_reglage` (coachs, modèle, Professeur, sel — repris de `ceo_app_setting`), `aia_fiche` (la fiche de faits, 10 min), `aia_conversation`, `aia_question` (reprise de `ceo_assistant_question`), `aia_visuel`, `aia_modele`, `aia_manuel_passage`. **Le manuel opératoire entier** pour tous les agents : outils `manuel_sommaire`, `manuel_chercher`, `manuel_lire` (toutes les fiches de `ceo_sop`, brouillons signalés). **Le Professeur** (`avatars/professeur.svg`) : l'agent rapide du manuel — Claude Haiku 5.5, effort bas, sans lecture de l'ERP, réglage `assistantProfesseur`. **Les tableaux et graphiques** : le modèle décrit la demande (`visuels`), le serveur relit les chiffres dans l'historique des ventes ; seul un tableau du manuel est recopié d'un document lu. Routes : `/assistant/magasin/modeles*` (Mes modèles), `POST /assistant/magasin/pdf` (la réponse en PDF A4 : le gabarit du cockpit, `rapPdfRendu`, les graphiques envoyés en PNG par l'écran, leurs tableaux toujours). **Le Coach Vente** (`assistant_vente.php`, portrait `avatars/vente.png`) : ciblé sur les ventes, l'adaptation des prix face à la concurrence et les bundles bâtis sur les points forts et faibles — outils `prix_concurrence` (les relevés du module Benchmark, comme l'onglet Concurrence, face au prix et à la marge du magasin), `forces_faiblesses` (catégories et produits au-dessus / en dessous du réseau, avec leur marge), `promotions_en_cours` (pvRegles), visuel `prix_concurrence` ; lectures lentes gardées dans `aia_cache`. **Le radar** (forme `radar`) : `categories` (la part des ventes face au réseau) et `marges_categories` (la marge brute de chaque catégorie, chaque magasin avec ses prix et ses coûts : `aivMarges`) ; moins de trois catégories, il devient des barres. **En pleine page** : l'onglet `?vue=assistant` du rail (« Assistant IA », sous la Boîte mail) ; la modale reste pour la demande au bureau et au téléphone. Tests : `php bin/tests_assistant_ia.php` |
| `bundles.php` | Vendre › Promotions (`bundles.js`), à la place de la liste des promotions | Chargé par `assistant_vente.php` (17/11/2026, maquette `docs/maquettes/promo-coach/`). Les **bundles du Coach Vente** : 1 le constat (la marge de base : prix HT − coût de recette, pondérée par les ventes hors TVA de 30 jours ; chaque produit en marge × pièces), 2 les couples (un produit qui attire, sous la marge de base, + un produit à 78 % de marge et plus, d'une autre famille, au même moment de la journée ; prix conseillé −10 %), 3 le prix, 4 la fiche pré-encodée, 5 le lancement. Routes `GET /assistant/magasin/bundles`, `POST …/apercu`, `…/lancer`, `…/renvoyer`, `GET …/fiche` (sous `/assistant/magasin` : ouvertes à la session du magasin). Le lancement crée au panel une « Buy X Get Y » du magasin (`pmEnregistrer`, Y à prix fixe, du lundi qui suit, 1 mois par défaut), la garde dans **`aia_bundle`**, et envoie la fiche PDF de l'équipe (ni marge ni coefficient) depuis la boîte du magasin aux adresses de `pil_equipe_mail`. Tests : `php bin/tests_bundles.php` |
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
