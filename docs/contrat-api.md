# Cockpit CEO — contrat API et mapping base de données

Le HTML ne contient plus aucune donnée métier. Tout passe par `api.js`, qui appelle
un endpoint par écran, normalise la réponse, et l'expose au composant. Si l'API ne
répond pas, `data.js` (jeu de démonstration) prend le relais et `p.source` vaut `demo`.

- Base d'URL : `window.COCKPIT_API_BASE`, défaut `/api/cockpit`
- Format : JSON, `Content-Type: application/json`, montants en euros (nombres, pas de chaînes)
- Dates : `YYYY-MM-DD`. Pourcentages : nombres en points (`32` = 32 %), sauf `margePct` (ratio 0–1)
- Granularité des réels : **mensuelle**
- Timeout client : 4 s, les 19 endpoints sont appelés en parallèle

---

## `GET /carte-sources` et `POST /connecteurs/{code}/test`

**La carte** dit d'où vient ce que chaque écran affiche. Elle est lue dans le
**code**, pas dans une liste tenue à la main — une liste à la main ment dès la
semaine suivante. `bin/carte_sources.php` découpe chaque fonction `ep_*` et
regarde ce qu'elle appelle (`Db::`, `PanelApi::`, `ErpApi::`, `GoogleApi::`,
`Anthropic::`, `ScoutingOsm::`), puis écrit le résultat dans `ceo_app_setting`
sous `carteSources`. Cron quotidien à 3 h 40, et un passage au déploiement.

Quatre catégories : `base` (la base seule), `api` (une API extérieure seule),
`mixte` (les deux), `calcule` (ni l'une ni l'autre — un calcul pur). La réponse
porte `jours`, l'âge de la carte : une carte de trois semaines ne décrit plus
le code d'aujourd'hui.

**Le test** — `POST /connecteurs/{code}/test` — fait un **vrai appel**, pas une
relecture de réglage : une clé peut être présente et refusée. Le résultat
s'écrit dans `ceo_connecteur` comme n'importe quel geste.

| connecteur | ce que le test fait |
|---|---|
| `panel` | authentification, **puis** lecture de `/shops` — un jeton valide ne prouve pas que les données suivent |
| `erp` | `ErpApi::tester()` |
| `google` | une recherche de lieu, la requête la moins chère de l'API |
| `anthropic` | **aucun appel** : une proposition de note se facture. On dit si la clé est là, rien de plus |

## Les notifications push — `GET /push/cle`, `POST /push/abonnements`, `POST /push/essai`

Le Web Push sans dépendance : PHP 8 + OpenSSL. Deux normes assemblées dans
`src/push.php` — **VAPID** (RFC 8292) pour dire qui envoie, et le **chiffrement
`aes128gcm`** (RFC 8291) pour que le service de push transporte sans lire.

Les clés VAPID vivent dans `ceo_app_setting` sous `pushVapid`, générées à la
première demande. **Les changer invalide tous les abonnements** : le navigateur
lie chaque abonnement à la clé publique qu'on lui a donnée.

- `GET /push/cle` → `{ pret, cle, abonnements }`, ou `{ pret: false, motif }`
  quand PHP n'a pas `openssl_pkey_derive`, `hash_hkdf` ou `aes-128-gcm`.
- `POST /push/abonnements` ← `{ shop, endpoint, p256dh, auth }`. Un même
  appareil qui se réabonne garde son endpoint : la ligne est mise à jour.
- `DELETE /push/abonnements` ← `{ endpoint }`.
- `POST /push/essai` ← `{ shop }` → `{ abonnements, envoyes, retires, erreurs }`.

Table `ceo_push_abonnement` (créée à la volée) : `shop_id`, `endpoint` (unique),
`p256dh`, `auth`, `agent`, `cree_le`, `vu_le`, `echecs`. Un envoi qui rend
**404 ou 410** retire l'abonnement : l'appareil ne reviendra pas.

L'envoi est fait par `bin/push_stock.php`, en cron toutes les quinze minutes de
5 h à 20 h. Il retient dans `ceo_app_setting` (`pushStock:<id>`) la liste des
références en alerte au passage précédent et **ne notifie que les nouvelles** —
sans cela, chaque passage rappellerait les quatre-vingt-quinze ruptures de la
veille. Le premier passage d'un magasin n'envoie rien : il pose l'état.

## `GET /ventes/stock`

L'inventaire matière d'**un** magasin. `/centrale/stock` fait la même lecture
pour tout le réseau et tronque à 600 lignes : sur 2 100 références, un magasin
peut y passer entier à la trappe. Le dashboard d'un magasin a besoin du sien,
complet.

Source : `/shops/{id}/material-inventory` (API panel). Une référence jamais
comptée n'est **pas** « à zéro », elle est **absente** — elle n'est pas rendue.

Une référence est **en alerte** quand son stock est négatif (écart de caisse ou
de comptage) ou sous le minimum journalier : les deux appellent un geste.
`manque` dit de combien il faut recharger pour repasser au minimum. Les alertes
sortent en tête, les plus creuses d'abord.

```json
{
  "shop": "2", "references": 128,
  "alertes": 3, "ruptures": 2, "negatifs": 1,
  "dernierComptage": "2026-09-14 18:22:00", "quand": "2026-09-15T14:02:11+02:00",
  "lignes": [
    { "ref": "Beurre doux 82 %", "categorie": "Matières premières",
      "stock": -2.5, "mini": 12, "unite": "kg", "modif": "2026-09-14 18:22:00",
      "alerte": true, "manque": 14.5 }
  ]
}
```

## `GET /ventes/mensuel`

Le CA mois par mois d'un magasin. Sert la **valeur du magasin** du dashboard.

**Deux sources**, parce qu'aucune n'est complète à elle seule :

| source | ce qu'elle a | ce qui lui manque |
|---|---|---|
| `mac_shop_monthly_pnl` (P&L du panel) | les mois clos jusqu'au dernier | aucune ligne en 2025 |
| `transaction` (ventes de caisse) | 2025 | s'arrête au 14 juillet 2026 |

Le P&L fait foi quand il a le mois — c'est un chiffre arrêté ; la caisse comble
le reste. Chaque mois dit d'où il vient (`source`), et `tickets` / `jours`
viennent toujours de la caisse quand elle a le mois, même si le CA vient du
P&L.

Paramètres : `shop` (requis), `mois` (1 à 60, 24 par défaut).

Le **mois en cours est exclu** : incomplet, il tirerait toute moyenne vers le
bas jusqu'à son dernier jour. Les mois sans rien sont rendus quand même, `ca`
à `null` — un trou doit se voir, pas se combler tout seul.

```json
{
  "shop": "4",
  "du": "2024-09", "au": "2026-08",
  "source": "P&L mensuel du panel quand il a le mois (7), ventes de caisse sinon (0) — mois en cours exclu",
  "mois": [
    { "mois": "2024-09", "ca": null, "source": null, "tickets": 0, "jours": 0 },
    { "mois": "2026-02", "ca": 6506.85, "source": "pnl", "tickets": 612, "jours": 7 }
  ]
}
```

`jours` compte les jours distincts où au moins un ticket a été passé : il dit
qu'un mois à 6 507 € sur 7 jours est un mois d'ouverture et non un mois raté.

## 1. Endpoints

| Clé | Endpoint | Alimente |
|---|---|---|
| `meta` | `GET /meta` | en-tête, marque, utilisateur, exercice, seuils |
| `leviers` | `GET /referentiels/leviers` | pastilles levier, Paramètres |
| `kpis` | `GET /referentiels/kpis` | listes KPI des assistants |
| `emailTemplates` | `GET /referentiels/email-templates` | relances, Paramètres |
| `projTemplates` | `GET /referentiels/ceo_project-templates` | assistant Nouveau projet |
| `stores` | `GET /stores?statut=tous` | tous les écrans magasins |
| `perf` | `GET /stores/perf?granularite=mois&annees=2025,2026` | Tableau, Heatmap, Objectifs, Suivi budget, Marge |
| `budgets` | `GET /stores/budgets?exercice=2026` | Suivi budget magasin, cumul réseau |
| `targets` | `GET /targets` | Objectifs de CA (1/3/5 ans) |
| `consultants` | `GET /consultants` | Tâches consultants, Reporting |
| `suppliers` | `GET /fournisseurs` | Tâches consultants |
| `projects` | `GET /projects` | Projets, Tâches consultants |
| `reporting` | `GET /reporting` | Reporting automatisé |
| `journal` | `GET /journal` | Journal |
| `products` | `GET /products/scoring?periode=AAAA-MM` | Scoring produits |
| — | `GET /scouting` | Scouting commercial : saisies, hypothèses, état du connecteur Google, inventaire du cache OSM ; chaque concurrent porte `statut` (businessStatus Google) et `dernierAvis` (date du plus récent avis rendu par sa fiche) — fermé ou sans avis depuis un an, il est écarté par l’écran |
| — | `GET /scouting/tiles/{secteur}` | Scouting commercial : un secteur du cache OpenStreetMap |
| — | `GET /scouting/reseau` | Scouting commercial : magasins du réseau, position (fiche Google ou pointée) et CA réel des douze derniers mois clos — le calage du modèle ; `panier` : panier moyen TTC du réseau et par magasin (même source que l'écran Exploitation, mois en cours, gardé un jour) |
| — | `GET /scouting/etude?lat=&lng=&r=` | Scouting commercial : l'étude de marché locale d'un point — ce qu'OpenStreetMap sait du rayon `r` (mètres, 500 à 15 000) : entreprises par famille (`ent`), zonings avec emprise et entreprises dedans (`zonings`), écoles (`ecoles`), générateurs de flux (`flux`), concurrence indirecte (`indirecte`). Servi du cache `ceo_scouting_etude` s'il a moins de 45 jours (`cache: true`, `releve`), sinon relevé chez Overpass depuis le serveur (jusqu'à deux minutes) ; `force=1` relit ; un relevé qui échoue rend le cache périmé avec `perime: true`, ou 502 |
| `fbRegles` | `GET /referentiels/facebook-regles` | Contrôle posts Facebook — pack de règles |
| `fbPosts` | `GET /facebook/posts` | Contrôle posts Facebook — posts, écarts, décisions |

### `/meta`

```json
{
  "reseau": { "nom": "L'Atelier by", "sousTitre": "Cockpit CEO — Réseau" },
  "utilisateur": { "initiales": "GB", "nom": "G. Baert", "role": "CEO · admin" },
  "aujourdhui": "2026-07-31",
  "dateLabel": "Vendredi 31 juillet 2026",
  "periodeLabel": "Données : juillet 2026",
  "exercice": 2026,
  "moisLabels": ["Jan","Fév","Mar","Avr","Mai","Juin","Juil","Août","Sep","Oct","Nov","Déc"],
  "seuils": { "food": 32, "labour": 33, "overhead": 13.5, "royalties": 3, "financieres": 2.2 },
  "contribOuverture": 210000,
  "notes": { "objectifsOuverture": "Dont contribution attendue de l'ouverture de Knokke (oct.–déc.) : env. 210 k€." }
}
```

### `/stores`

```json
[{ "id": "cha", "code": "BXL-CHA", "nom": "Bruxelles — Châtelain", "fr": "M. Lambert",
   "zone": "Bruxelles", "status": "Ouvert", "opened": "2019-03", "valT": 920000, "panier": 11.8 }]
```

`status` ∈ `Ouvert` | `En ouverture` | `Fermé`. Seuls les `Ouvert` entrent dans les cumuls réseau.

### `/stores/perf` — une ligne par magasin et par mois

```json
[{ "storeId": "cha", "annee": 2026, "mois": 7,
   "ca": 96600, "caBudget": 102900, "margeNette": 15200, "margePct": 0.158,
   "tickets": 8181, "panierMoyen": 11.81,
   "foodCostPct": 31.4, "labourCostPct": 32.8, "overheadPct": 13.2, "valorisation": 861000 }]
```

`mois` est 1–12. Un mois non encodé : ligne absente, ou valeurs réelles à `null` avec `caBudget` renseigné.

`caTheoriqueAn` = chiffre d'affaires annuel théorique issu de l'étude de marché du point de vente
(potentiel de la zone de chalandise), indépendant du budget validé avec le franchisé. Le client le
répartit sur les mois au prorata de la saisonnalité du budget et le compare au réel et au budget.
`etudeMarche.date` date la dernière étude ; une étude de plus de 24 mois est signalée comme à
rafraîchir.

### `/stores/budgets` — le budget validé par le consultant

```json
[{ "storeId": "cha", "exercice": 2026, "moisEncodes": 7, "moisTotal": 12,
   "dernierEncodage": "2026-08-04", "panierEngagement": 12.74,
   "caTheoriqueAn": 1620000,
   "etudeMarche": { "date": "2025-09-15", "source": "Étude de marché — zone de chalandise 10 min",
                    "potentielMenages": 4850 },
   "charges": [
     { "poste": "Matières premières", "levier": "food-cost", "pctBudget": 32, "champReel": "food" },
     { "poste": "Rémunérations & charges sociales", "levier": "labour-cost", "pctBudget": 33, "champReel": "labour" },
     { "poste": "Services & biens divers", "levier": "overhead-cost", "pctBudget": 13.5, "champReel": "overhead" },
     { "poste": "Royalties", "levier": "", "pctBudget": 3, "champReel": null },
     { "poste": "Charges financières", "levier": "", "pctBudget": 2.2, "champReel": null }
   ] }]
```

`champReel` désigne le champ de `/stores/perf` qui porte le réel du poste ; `null` = le poste
suit le CA au pourcentage budgété. L'ordre du tableau `charges` est l'ordre affiché.

### `/stores/budget-notes` — les annotations mensuelles d'un exercice

`GET /stores/budget-notes?shop=cha&exercice=2026`

```json
{ "shop": "cha", "exercice": 2026,
  "auteurs": ["franchise", "consultant", "marque"],
  "mois": { "6": { "franchise":  { "texte": "Chantier de voirie devant la boutique tout le mois.",
                                   "par": "M. Dupuis", "le": "2026-07-02 09:14:00" },
                   "consultant": { "texte": "…", "par": "S. Verhoeven", "le": "2026-07-08 16:40:00" } } } }
```

Le tableau du suivi budget dit CE QUI s'est passé, jamais POURQUOI : un mois à −24 % se lit
autrement selon qu'on tenait la caisse, qu'on est passé en visite ou qu'on a lancé la campagne.
D'où **trois voix par mois**, une par intervenant, et une impression qui les emporte.

`mois` est un objet indexé par numéro de mois (`"1"` … `"12"`) ; **les mois sans annotation sont
absents**, et une annotation vide n'est jamais rendue — un texte vide est une annotation effacée,
pas une annotation blanche. `par` et `le` peuvent valoir `null` sur une ligne écrite avant que
l'auteur ne soit connu.

Écriture : `POST /stores/budget-note` avec `{ shop, exercice, mois, auteur, texte, par }`.
`auteur` ∈ `franchise | consultant | marque`, `mois` ∈ 1–12, `texte` plafonné à 2 000 caractères ;
**un texte vide SUPPRIME la ligne**. Rend `{ ok: true, texte, par, le }`. Le serveur écrit
`ceo_shop_month_note` (clé `shop_id, year, month, auteur`) et crée la table au premier appel si
elle manque — une installation qui n'a pas repassé `schema.sql` répond donc normalement.

### `/products/scoring` — une ligne par référence vendue sur la période

```json
[
  { "id": "vi1", "nom": "Croissant pur beurre", "categorie": "Viennoiserie",
    "volume": 48200, "prix": 1.35, "coutUnit": 0.47, "tendVol": 1.04, "magasins": 9 }
]
```

`volume` = unités vendues sur le réseau (magasins ouverts) pour la période ; `prix` = prix de
vente TTC moyen constaté ; `coutUnit` = coût de revient matière + emballage ; `tendVol` = ratio
de volume vs même mois N-1 (1,04 = +4 %) ; `magasins` = nombre de magasins ouverts ayant vendu la
référence sur la période (le taux de pénétration est `magasins / magasins ouverts`, calculé côté
client à partir de `/stores`). Le client calcule CA réseau, marge unitaire, marge brute, rang par
catégorie (CA décroissant) et les trois notes du score — l'API n'envoie que les mesures brutes.

### Champs projet ajoutés

`famille` (obligatoire) classe le projet dans le kanban : `Produits`, `Services`,
`Organisation & coûts`, `Développement réseau` (table de référence `ceo_project_famille`). Le
statut reste un champ à part, affiché sur la carte. Chaque tâche accepte deux champs optionnels
`desc` (texte libre) et `budget` (montant € affecté à l'étape) ; à défaut le client affiche la
quote-part du budget projet. Saisie franchisé (CA réel, clients, panier moyen déduit) :
`PUT /stores/{id}/perf/{annee}-{mois}` avec `{ ca, tickets, charges: [{poste, montant}] }` — le panier
moyen n'est jamais transmis, il vaut toujours `ca / tickets` ; les ratios food/labour/overhead sont
recalculés serveur (`montant / ca`).

Écran Encodage du budget — routes d'écriture : `PUT /stores/{id}/budget?exercice=AAAA` avec le corps
`{ caMensuel: [12 nombres], caTheoriqueMensuel: [12 nombres], panierEngagement,
etudeMarche: {date, source, potentielMenages, potentielMaturite, anneeExploitation,
monteeEnRegime: {a1: 70, a2: 80, a3: 90}, saisonnalite: [12 pourcentages],
annexe: {nom, url, taille, date}},
charges: [{poste, levier, pctBudget, pctTheorique}] }`
(`caTheoriqueAn` = somme de `caTheoriqueMensuel` ; le CA théorique de l'exercice vaut
`potentielMaturite × monteeEnRegime[annéeExploitation]`, réparti par `saisonnalite`)
(`caTheoriqueAn` = somme de `caTheoriqueMensuel`, calculée côté serveur). Le fichier d'annexe passe par `POST /stores/{id}/budget/annexe` (multipart) qui renvoie
`{nom, url, taille, date}` à replacer dans `etudeMarche.annexe`.
Le serveur écrit `ceo_shop_budget` + `ceo_shop_month_perf.ca_budget`
et journalise l'opération (`ceo_journal_entry`, type `Budget`).

Chaque tâche accepte aussi `magasinId` (optionnel, `null` = tâche réseau) : une tâche est rattachée
à un projet et, si elle porte sur un point de vente précis, à un magasin. L'écran Tâches filtre sur
ce champ et l'affiche sur la ligne. `POST /projects/{id}/tasks` et `PATCH /projects/{id}/tasks/{taskId}`
acceptent `magasinId`.

Routes d'écriture projets : `PATCH /projects/{id}` (`famille`, `statut`),
`PATCH /projects/{id}/tasks/{taskId}` (`done`, `magasinId`, `note`), `PATCH /projects/{id}/milestones/{index}` (`reel`).

### Validation d'une tâche consultant

Une tâche n'est plus seulement cochée, elle est **notée**. La note porte à la fois la conformité
et la gravité — il n'y a donc pas de champ « gravité » à côté :

| Note | Niveau | Effet |
|---|---|---|
| 5 | Exemplaire | clôture |
| 4 | Conforme | clôture |
| 3 | Non conforme — mineur | clôture **+ signalement** |
| 2 | Non conforme — majeur | clôture **+ signalement** |
| 1 | Non conforme — critique | clôture **+ signalement** |

Chaque tâche de `/projects` porte désormais `note` (1..5, `null` = rendue mais pas encore
validée — c'est ce qui alimente le groupe « À valider »), `valideePar`, et `signalement` (le
dernier signalement de la tâche, ou `null`).

`PATCH /projects/{id}/tasks/{taskId}` accepte `{ note, famille, type, commentaire, copie, par }`.
La note, la clôture et le signalement sont écrits dans **une seule transaction** : une tâche close
dont le signalement s'est perdu en route est pire que les deux ensemble. Deux refus en `422` :
une note hors `1..5`, et une note sous le seuil sans `famille` ni `type`.

Les cinq niveaux, le seuil et le référentiel famille → type viennent de `GET /meta`, clé
`signalement` (réglage `ceo_app_setting`, modifiable par `PUT /parametres/signalement`) :

```json
{ "seuil": 4,
  "niveaux": [{ "n": 5, "nom": "Exemplaire", "couleur": "#C9A227", "aide": "au-dessus de l'attendu" }],
  "familles": [{ "nom": "Livrable", "types": ["Incomplet", "Non conforme au brief"] }] }
```

**Une seule source.** Aucun libellé ni couleur n'est recopié dans le JavaScript : le jour où
« mineur » devient « à surveiller », il ne change qu'ici. Les mêmes cinq niveaux servent au panel
consultant (`pwa_consultant`) pour les tâches boutique — un « majeur » doit vouloir dire la même
chose des deux côtés, sinon les chiffres ne s'additionnent pas. Le référentiel famille/type, lui,
est propre à chaque application.

### `GET /pwa/tasks/nc` — les non-conformités d'une journée ou d'une période

    ?shop=4&date=2026-09-13        une journée (la veille, sous la vue Jour)
    ?shop=4&du=2026-09-07&au=…     une période (sous les vues Semaine et Mois)

Ce que le **dashboard magasin** lit : les tâches d'une boutique notées sous le
seuil sur la fenêtre demandée, avec leur motif. Tout vient de `mac_task_review` — aucun appel au panel, la
journée est révolue donc figée. Le barème part avec la réponse : la page n'a pas à lire `/meta`
pour nommer une gravité.

```json
{ "shopId": "4", "date": "2026-09-13", "seuil": 4,
  "niveaux": [{ "n": 1, "nom": "Non conforme — critique", "couleur": "#8D1D2C" }],
  "notees": 18,
  "nc": [{ "taskId": "101", "jour": "2026-09-13",
           "tache": "Contrôle Qualité – Températures frigo vitrine",
           "note": 1, "comment": "Frigo vitrine relevé à 9 °C à 16 h.",
           "consultant": "K. Moreau", "releveeLe": "2026-09-13 16:20",
           "recidive": 3,
           "suite": { "jour": "2026-09-14", "note": 4, "conforme": true },
           "valideeLe": null, "valideePar": null }],
  "semaine": [{ "jour": "2026-09-07", "nc": 1, "notees": 16, "releve": true }],
  "indispo": false }
```

- **non-conformité** = `rating < seuil`, ou `is_accepted = 0` pour un avis venu d'un panel qui
  aurait un autre seuil. `notees` compte toutes les tâches notées, conformes comprises : sans le
  dénominateur, « cinq écarts » ne veut rien dire.
- **`recidive`** : le nombre de journées où la MÊME tâche était déjà non conforme sur les sept
  jours précédents, bornes comprises. `null` en dessous de deux.
- **`tache`** : le référentiel `todo_task`, à défaut le relevé quotidien `ceo_tache_jour`, à
  défaut l'identifiant — jamais un nom inventé.
- **`suite`** : la PREMIÈRE note posée sur la même tâche après ce jour-là, `null` s'il n'y en a
  pas eu. C'est elle qui dit si l'écart a été repris, et quand — sans elle, la liste des
  non-conformités d'un mois ne serait qu'un palmarès des reproches. La vue Jour ne s'en sert pas :
  elle lit le devenir de la tâche dans la journée en cours, plus riche (rendue sans note, pas
  rendue du tout).
- **`semaine`** : la série des sept derniers jours, renseignée **seulement** pour une journée.
  Sur une période, le compte de la période est déjà l'échelle.
- **`indispo`** : `mac_task_review` absente. L'écran se tait alors, il n'invente pas un zéro.

Ce que la MÊME tâche est devenue depuis ne vient PAS d'ici : le dashboard le lit dans
`/pwa/tasks?date=<aujourd'hui>`, qu'il charge déjà.

**Le dashboard ne fait que regarder.** Il n'écrit rien sur les tâches : noter (`POST
/pwa/tasks/review`), contresigner (`POST /pwa/tasks/validate`) et relancer se font dans
**Contrôle des tâches**, l'écran qui porte la responsabilité de l'avis. Une contresignature
déjà posée s'affiche ici — c'est un fait à connaître, pas une commande à actionner.

### Qui fait autorité sur quoi

Le cockpit vit dans la base du panel. Certaines données lui appartiennent, la
plupart non — et pour celles-là, la table `ceo_*` n'est qu'un **repli
d'installation autonome**, vide sur une base réelle.

| Donnée | Source d'autorité | Repli local |
|---|---|---|
| Magasins | `shops` | `ceo_shop` (miroir, rempli à la volée pour les clés étrangères) |
| Consultants | `user_membership` ⨝ `user_profile` (`app = 'CONSULTANT'`, actifs) | `ceo_consultant` |
| Destinataires des rapports | `user_membership` ⨝ `user_profile` (actifs, avec e-mail) | `ceo_person` |
| CA / marge mensuels | `mac_shop_monthly_pnl` | `ceo_shop_month_perf` |
| Tickets, panier moyen | `transaction` | — |
| Catalogue produits | `sig_products`, `transaction_product` | `ceo_product` |
| Leviers, KPI, positions | `of_tag`, `kpi`, `position` | créées si absentes |
| **Budget encodé** | **`ceo_shop_month_perf`** | — (donnée propre au cockpit) |
| **Validation des tâches** | **`ceo_project_task`, `ceo_task_issue`** | — (donnée propre au cockpit) |
| Projets, jalons, coûts | `ceo_project*` | — (données propres au cockpit) |
| Fournisseurs | `ceo_supplier` | — (aucune table partagée équivalente) |

**Le jeu de démonstration ne se charge jamais tout seul.** `sql/seed.sql` est
gardé derrière `seed: true` (config) ou `COCKPIT_SEED=1` ; par défaut une base
neuve reste vide, prête pour les vraies données.

**Rien n'est écrit en dur côté écran.** Les échéances proposées par les
assistants sont relatives à la date du jour, l'intervenant proposé est le
premier intervenant réel, et les liens de rapport partent de l'adresse d'où
l'application est servie.

### `GET /stores/perf` — d'où vient chaque colonne

Trois sources, fusionnées par (magasin, année, mois) :

| Champ | Source | Remarque |
|---|---|---|
| `ca`, `margeNette`, `margePct`, `labourCostPct`, `overheadPct` | `mac_shop_monthly_pnl` | table partagée avec le panel |
| `tickets`, `panierMoyen`, et `ca` de repli | `transaction` | ventes caisse de l'exercice courant |
| **`caBudget`, `caTheorique`** | **`ceo_shop_month_perf`** | **le budget encodé — aucune autre table ne le porte** |

La troisième passe n'est pas facultative : ni `mac_shop_monthly_pnl` ni
`transaction` ne connaissent le budget. Sans elle, l'encodage était écrit en
base et jamais relu, et tous les écrans qui comparent au budget — suivi budget,
heatmap, objectifs de CA — affichaient un objectif vide **sans lever la moindre
erreur**. Le défaut ne se voyait qu'en présence des tables partagées : sans
elles, l'endpoint tombait dans son repli, qui lisait le budget correctement.
C'est-à-dire qu'il marchait partout sauf en production.

Un mois **budgété sans réel** est rendu, avec `ca: null` : « budget 80 k, rien
encaissé » est une information, pas une ligne à masquer.

### Suivi des tâches — traitement et reporting semaine / mois

`GET /taches/suivi?periode=semaine|mois` (défaut `mois` ; 7 ou 30 jours glissants) :

```json
{ "periode": "semaine", "depuis": "2026-08-08",
  "validees": 12, "moyenne": 4.08,
  "repartition": { "5": 3, "4": 6, "3": 2, "2": 1, "1": 0 },
  "ouverts": 2, "traites": 4,
  "signalements": [ { "id": 7, "tacheId": "t12", "tache": "…", "projet": "…",
                      "owner": {"t":"c","id":"c1"}, "note": 2,
                      "famille": "Délai", "type": "Rendu hors délai",
                      "statut": "vu", "ouvert": true,
                      "creeLe": "…", "vuLe": "…", "closLe": null } ],
  "parIntervenant": [ { "owner": {"t":"c","id":"c1"}, "validees": 5, "moyenne": 4.2, "ouverts": 1 } ] }
```

Deux règles de lecture qui ne se devinent pas :

1. **La période porte sur la date de VALIDATION** (`ceo_project_task.validated_at`),
   pas sur `done_on` qui est la livraison. Une tâche rendue en mars et jugée en
   août appartient au suivi d'août — la borner sur la livraison la ferait
   disparaître de tout suivi utile.
2. **Les signalements ouverts remontent tous**, quelle que soit leur date. Un
   signalement de trois semaines qui traîne doit apparaître dans le suivi de la
   semaine : c'est même le premier à devoir sauter aux yeux, et une borne de
   date l'aurait masqué.

`PATCH /task-issues/{id}` avec `{ statut, commentaire, par }` — cycle
`nouveau` → `vu` → `traite`, et retour possible à `nouveau`.

| Statut | Effet |
|---|---|
| `vu` | pose `seen_at` — **ne clôt pas**. Voir n'est pas régler. |
| `traite` | pose `closed_at` et `closed_by`, et **exige un commentaire** (`422` sinon) : clore sans dire ce qui a été fait, c'est perdre l'information que le suivi cherchait à produire. |
| `nouveau` | rouvre, et laisse une trace au journal. |

Un statut hors de ces trois valeurs est refusé en `422`. Chaque transition
écrit une ligne dans `ceo_journal_entry`.

```sql
ALTER TABLE ceo_project_task ADD COLUMN validated_at DATETIME NULL;
```

**Aucune migration à lancer à la main.** `ensureValidation()` (`src/installer.php`) pose les deux
colonnes, la table `ceo_task_issue` et le réglage `signalement` au démarrage, sur une base neuve
comme sur une base déjà en service — `schema.sql` et `seed.sql`, eux, ne repassent jamais sur une
installation existante. Pour information, l'équivalent manuel :

```sql
ALTER TABLE ceo_project_task ADD COLUMN note TINYINT NULL;
ALTER TABLE ceo_project_task ADD COLUMN validated_by VARCHAR(80) NULL;
-- + la table ceo_task_issue (voir sql/schema.sql)
```

```sql
ALTER TABLE ceo_shop_budget ADD COLUMN ca_theorique_an DECIMAL(12,2) NULL;
ALTER TABLE ceo_shop_month_perf ADD COLUMN ca_theorique DECIMAL(12,2) NULL;
ALTER TABLE ceo_shop_budget_charge ADD COLUMN pct_theorique DECIMAL(5,2) NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN etude_date DATE NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN etude_source VARCHAR(160) NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN etude_potentiel_menages INT NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN etude_potentiel_maturite DECIMAL(12,2) NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN annee_exploitation TINYINT NULL;
ALTER TABLE ceo_shop_budget ADD COLUMN montee_regime JSON NULL;   -- {"a1":70,"a2":80,"a3":90}
ALTER TABLE ceo_shop_budget ADD COLUMN saisonnalite JSON NULL;     -- 12 pourcentages
ALTER TABLE ceo_shop_budget ADD COLUMN etude_annexe JSON NULL;     -- {"nom","url","taille","date"}

-- Annotations mensuelles du suivi budget (créée aussi au vol par ensureBudgetNotes()) :
CREATE TABLE IF NOT EXISTS ceo_shop_month_note (
  shop_id VARCHAR(8) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL,
  auteur VARCHAR(12) NOT NULL,          -- franchise | consultant | marque
  texte TEXT NULL, maj_par VARCHAR(120) NULL, maj_le DATETIME NULL,
  PRIMARY KEY (shop_id, year, month, auteur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE ceo_project ADD COLUMN famille VARCHAR(40) NOT NULL;
ALTER TABLE ceo_project_task ADD COLUMN description TEXT NULL;
ALTER TABLE ceo_project_task ADD COLUMN budget DECIMAL(10,2) NULL;
ALTER TABLE ceo_project_task ADD COLUMN shop_id VARCHAR(8) NULL;   -- magasin de rattachement (NULL = réseau)
ALTER TABLE ceo_project_task ADD CONSTRAINT fk_task_shop FOREIGN KEY (shop_id) REFERENCES ceo_shop(id);
```

### `/targets`

```json
{ "ca": { "h1": { "an": 2026, "cible": 7400000 },
          "h3": { "an": 2028, "cible": 11500000, "note": "Hypothèse : {ouvertures} ouvertures d'ici 2028…" },
          "h5": { "an": 2030, "cible": 18000000, "note": "…" } },
  "expansion": { "h1": { "an": 2026, "cible": 2, "reel": 1 } },
  "caMoyenOuverture": 820000 }
```

`note` accepte les jetons `{ouvertures}` et `{caMoyen}`, substitués côté client.

### `/projects`

```json
[{ "id": "p1", "nom": "Gamme snacking été", "statut": "En cours", "prio": "Haute",
   "debut": "2026-03-02", "fin": "2026-09-15", "axes": ["Ventes"], "leviers": ["trafic","food-cost"],
   "budget": 24000, "valeurEst": 62000, "valeurReal": null, "kpis": ["CA réseau"],
   "jalons": [{ "nom": "Étude gamme & pricing", "cible": "2026-04-10", "reel": "2026-04-08" }],
   "couts":  [{ "poste": "Agence", "prevu": 9000, "reel": 9400 }],
   "taches": [{ "id": "t1", "nom": "Fiches techniques", "owner": { "t": "c", "id": "c1" },
                "due": "2026-08-14", "done": null }] }]
```

`owner.t` : `c` = consultant, `s` = fournisseur ; `owner.id` référence `/consultants` ou `/fournisseurs`.

### `/referentiels/facebook-regles` — le pack de règles de l'agent

```json
{
  "seuil": 4,
  "familles": [
    { "nom": "Charte de marque", "types": ["Marque absente", "Casse de marque", "Hashtag de marque absent", "Magasin non identifié", "Ton non conforme"] },
    { "nom": "Mentions légales", "types": ["Conditions de promotion absentes", "Règlement de concours absent", "Allégation santé", "Superlatif non justifié"] }
  ],
  "regles": [
    { "code": "marque-absente", "nom": "Marque citée", "famille": "Charte de marque",
      "type": "Marque absente", "gravite": 3, "actif": true, "aide": "Le post doit nommer la marque du réseau." }
  ]
}
```

`gravite` ∈ 1 (mineur) | 2 (majeur) | 3 (critique). Les `params` de chaque règle
(mots déclencheurs, seuils de longueur, domaines autorisés, lexique…) restent
côté serveur : le client n'a pas à connaître le détail du moteur, seulement ce
qu'il affiche. Les **cinq niveaux de notes ne sont pas ici** — ce sont ceux de
`meta.signalement`, une seule échelle de conformité pour les tâches consultants
et pour les posts.

Le client renvoie le pack **entier** par `PUT /parametres/fbControle`
(`{ "valeur": { … } }`) quand le CEO active ou désactive une règle : le serveur
stocke un réglage, pas un delta.

### `/facebook/posts` — les posts soumis, leur contrôle et leur décision

```json
[{
  "id": "fb03", "magasinId": "gnd", "magasin": "Gand — Korenmarkt",
  "auteur": "J. De Smet", "format": "Carrousel",
  "message": "Grand concours de rentrée à L'Atelier by Korenmarkt !…",
  "lien": null,
  "medias": [{ "nom": "panier-1.jpg", "type": "image", "alt": "Panier gourmand", "largeur": 1440, "hauteur": 1440 }],
  "publierLe": "2026-08-03 11:30", "soumisLe": "2026-07-30 16:05",
  "statut": "a_valider",
  "agent": { "note": 1, "resume": "1 écart — plus grave : critique · Mentions légales",
             "le": "2026-07-30 16:05", "passages": 1 },
  "ecarts": [{ "id": 7, "code": "concours-reglement", "regle": "Règlement de concours",
               "famille": "Mentions légales", "type": "Règlement de concours absent",
               "gravite": 3, "message": "Jeu-concours sans règlement cité ni lien vers celui-ci.",
               "extrait": "concours", "statut": "ouvert" }],
  "decision": { "note": null, "famille": null, "type": null, "commentaire": null, "le": null, "par": null },
  "publie": { "le": null, "fbId": null }
}]
```

`statut` ∈ `brouillon` | `a_controler` | `a_valider` | `valide` | `refuse` | `publie`.
`magasinId` à `null` = post réseau (l'ancrage local n'est alors pas exigé).

`agent.note` est la **proposition** du moteur, `decision.note` la décision
**signée** par un humain ; les deux sont distinctes et affichées comme telles —
l'écart entre elles mesure le calibrage du pack de règles. `statut` d'un écart
∈ `ouvert` | `ignore` | `corrige` ; un écart `ignore` reste renvoyé mais sort du
calcul de la note.

**Calcul de la note** (sur les écarts non ignorés) :

| Écarts retenus | Note | Niveau |
|---|---|---|
| aucun | 5 | Exemplaire |
| 1 ou 2 mineurs | 4 | Conforme — détails signalés |
| 3 mineurs et plus | 3 | Non conforme — mineur |
| au moins un majeur | 2 | Non conforme — majeur |
| au moins un critique | 1 | Non conforme — critique |

---

### `/scouting` — saisies du scouting commercial

Chargé à l'ouverture de l'écran (pas au démarrage du cockpit). Les données
OpenStreetMap elles-mêmes ne transitent pas par ce endpoint : elles vivent dans
`ceo_scouting_tile`, relues par le serveur — chaque semaine par cron
(`bin/scouting_refresh.php`), ou à la demande par `POST /scouting/refresh/{secteur}`
(« Recharger les données »). Le navigateur n'interroge Overpass lui-même qu'en
repli, sans API ou si le serveur n'atteint pas Overpass.

```json
{
  "params": { "spend": 586, "emprise": 0, "passage": 15, "surface": 250, "empriseMax": 30,
              "compK": 0.22, "hhSize": 2.31, "minScore": 55, "radius": 2, "thresh": 4.5 },
  "google": { "configure": true, "langue": "fr", "empreinte": "AIzaSy…Qx4c" },
  "competitors": [{ "id": "n123456", "name": "Boulangerie Dupont", "commune": "Halle", "arr": "Hal-Vilvorde",
                    "rating": 4.6, "reviews": 132, "source": "google", "comment": "File le samedi" }],
  "candidates": [{ "id": 1756900000000, "name": "Halle — zone 50.733/4.237", "commune": "Halle", "arr": "Hal-Vilvorde",
                   "prov": "Brabant flamand", "lat": 50.7336, "lng": 4.2372, "hh": 9800, "market": 5742800,
                   "emprise": 0.155, "ca": 1047000, "score": 72, "n": 6, "strong": 2, "m2": 4188 }],
  "populations": { "23027": 42608 },
  "tiles": [{ "sector": 0, "fetchedAt": "2026-09-03 17:12:40", "bytes": 91234 }]
}
```

- `params` : `null` tant qu'aucune hypothèse n'a été enregistrée (défauts de l'étude Halle côté client).
- `google` : l'état du connecteur Google de Paramètres (`PUT /parametres/google-cle`) — jamais la clé. Les notes des
  concurrents se demandent au serveur par `POST /scouting/notes` ; sans clé, il répond 422.
| Scouting — signe de vie Google de toute la carte (statut, date du dernier avis), par lots | `POST /scouting/concurrents/vie` (`{ n }`, 25 par défaut, 40 au plus ; rend `faits`, `fermes`, `dormants`, `reste`, `ecartes` ; colonnes `business_status`, `last_review_at`) |
- `competitors[].source` ∈ `google` | `manuel` ; une note `manuel` prime sur Google. `rating: null` avec
  `source: "google"` = commerce déjà interrogé sans note (pas réinterrogé).
- `id` d'un concurrent = type + id OSM (`n`, `w`, `r`). `id` d'une zone candidate = horodatage client (ms).
- `tiles` : inventaire seulement ; `GET /scouting/tiles/{secteur}` renvoie le JSON `{ t, c, b, p }` déposé
  par le navigateur (communes, commerces, nœuds `place`). 404 si le secteur n'est pas en cache.

### `/prospection/{shop}` — la liste de démarchage du magasin, au serveur

Ce que le franchisé fait de chaque lieu relevé par `/scouting/demarchage` : dans ma liste, visite datée,
retour, note (part au CRM marketing), et l'**action à suivre** décidée sur place avec sa date. Une seule
réserve pour le cockpit (écrans Prospection et Développement commercial) et la page mobile
`prospection/?shop={id}` (`ceo_prospect`, clé magasin + id OSM).

```json
{ "shop": "2", "n": 3, "lieux": { "n123456": { "coche": true, "visite": "2026-09-19", "retour": "rdv", "note": "Devis 40 pers.",
                                               "action": "devis", "actionLe": "2026-09-25", "le": "2026-09-19", "nom": "École de Blanmont" } } }
```

- `retour` ∈ `''` | `rappeler` | `rdv` | `interesse` | `refus` | `client` ; `action` ∈ `''` | `mail` | `test` | `devis` | `rappel` | `passer` | `commande` ;
  `offre` = le type d'offre choisi pour ce lieu (vide = celui de son genre).
- `GET /prospection/offres` → `{ offres: [{ id, nom, pitch, contenu, genres (regex sur le libellé du genre), familles[], depense, commandes, part }] }` :
  le **type d'offre par client** — un hôpital ne prend pas la même chose qu'une maison communale. Dix offres (plateau bureau, petit-déjeuner
  d'équipe, collations d'école, pause soignants, réceptions communales, pause formation, café après cérémonie, après-match, petit-déjeuner
  d'hôtel, pause du personnel) avec leur argument, leur contenu et les hypothèses du calcul de CA. `PUT /prospection/offres` `{ offres: [{ id, depense?,
  commandes?, part?, nom?, pitch?, contenu? }] }` règle le catalogue pour tout le réseau (`ceo_app_setting.prospectionOffres`).
- `PUT /prospection/{shop}` `{ lieux: { id: { coche?, visite?, retour?, note?, action?, actionLe?, nom? } } }` : fusion champ par
  champ (400 lieux par appel au plus) ; une ligne vidée disparaît. Rend les lignes relues.

### `/newsletter` — campagnes email + SMS, marque et franchisés (mode test)

`GET /newsletter?role=brand|{shopId}`. Le rôle est porté par la requête, comme le dashboard porte son magasin :
le serveur applique les droits du rôle demandé et ne les élargit jamais. **Mode test** : aucun envoi ne part ;
les bases et leurs comptes (`sources`, `segments[].count`) sont un jeu d'essai tant que les vraies bases clients
ne sont pas raccordées — la réponse le dit (`test: true`).

```json
{ "test": true, "role": "2", "canSend": true, "domaine": "latelier.by", "smtp": false,
  "moi": { "id": "2", "nom": "Corbais", "kind": "franchise", "senderName": "L'Atelier By Corbais", "email": "", "status": "unverified", "canSend": true },
  "magasins": [ … ], "sources": [{ "id": "indiv", "table": "tfb_customers", "name": { "fr": "Clients individuels", "nl": "…", "en": "…" }, "count": 4210, "optin": 3480 }],
  "segments": [{ "id": "tous-2", "src": "indiv", "shop": "2", "name": { "fr": "…" }, "rule": { "fr": "…" }, "rules": { "magasin": "2", "produit": null, "periode": "365", "panier_min": null }, "count": 1248, "split": [886, 318, 44] }],
  "campagnes": [{ "id": 1, "name": "Tarte diamant — Corbais", "segment": "tous-2", "langs": ["fr", "nl"], "templateId": "gagne", "channel": "both", "subjects": { "fr": "…" },
                  "sendMode": "manual", "trigger": null, "sendAt": "2026-09-12 08:30:00", "maxVouchers": 30, "sender": "2", "status": "sent",
                  "stats": { "sent": 1248, "open": 599, "click": 237, "vouchers": 22, "revenue": 1184 }, "exemple": true, "createdBy": "brand" }],
  "chiffres": { "optin": 6934, "envoisMois": 2, "vouchers": [58, 100] } }
```

- Vue **marque** (`brand`) : tous les magasins, segments et campagnes. Vue **magasin** : ses segments (`shop` = lui ou `""`),
  ses campagnes (créées par lui ou parties de son adresse), son magasin seul dans `magasins`.
- `magasins[].status` ∈ `verified` | `warmup` | `unverified` ; `canSend` : la marque autorise ou bloque l'envoi du franchisé.
- `POST /newsletter/campagnes` `{ role, name, segment, langs[], templateId, channel, subjects{}, bodies{}, sms{}, headlines{}, ctas{}, sendMode, trigger?, date, time, maxVouchers?, sender, testSent?, status? }`
  → `{ ok, campagne }`. Franchisé : refusé si bloqué (403), segment d'un autre magasin (403), mode `auto` (403), expéditeur autre que le sien (403).
- `DELETE /newsletter/campagnes/{id}` : brouillon ou programmée ; une campagne envoyée reste (409). Franchisé : les siennes seulement.
- `POST /newsletter/segments` (marque seule) `{ name, src, shop, product, period, minBasket, count, rule }` → `{ ok, segment }` ; la règle est gardée en `rules_json`.
- `PUT /newsletter/magasins/{id}` `{ role, senderName?, email?, status?, canSend? }` : le franchisé ne touche qu'à son nom et son adresse ;
  le statut suit l'adresse (domaine de la marque = vérifié) et la marque peut le fixer ; `canSend` marque seule.
- `POST /newsletter/test` `{ role, subject }` → `{ ok, simule: true }` : journalisé, rien ne part.
- Tables : `ceo_nl_magasin`, `ceo_nl_source`, `ceo_nl_segment`, `ceo_nl_campagne` (créées et semées par `ensureNewsletter()` ; les magasins
  suivent `ep_stores()` à chaque démarrage). Les modèles (copy FR/NL/EN, SMS) vivent dans le module front `assets/js/newsletter.js`.

**Le moteur** (`src/newsletter_envoi.php`) — contacts, envoi, suivi, horloge :

- `POST /newsletter/contacts/import` (marque) `{ source, shop?, lot?, csv }` : CSV avec en-tête (`email` requis ; `prenom`, `nom`, `langue`,
  `magasin`, `telephone`, `optin`, `optin_sms`, `dernier_achat`, `panier`, `produits`, `anniversaire`, `cree_le`) → `{ nouveaux, misAJour, ignores, lot, contacts }`.
  Une base qui a des contacts compte pour de vrai (`sources[].reel`, `segments[].reel`) ; sinon le jeu d'essai reste.
  `GET /newsletter/contacts/lots`, `DELETE /newsletter/contacts/lots/{lot}`.
- `PUT /newsletter/reglages` (marque) `{ dispatch?, testAdresses?, domaine? }`. `dispatch: true` exige un SMTP configuré ;
  tant qu'il est faux, **rien ne part** (mode test), la réponse de `/newsletter` porte `test: true`.
- `POST /newsletter/test` `{ subject, body, headline, cta, templateId, lang, sender, voucher }` : le mail rendu part aux adresses de test
  (`nlTestAdresses`, sinon l'expéditeur SMTP), même en mode test → `{ ok, simule, adresses, erreur }`.
- `POST /newsletter/campagnes/{id}/envoyer` : la date passe à maintenant et l'horloge passe ; `POST /newsletter/tick` (marque) : l'horloge à la demande.
- `GET /newsletter/cron?jeton=…` (`nlJeton`, cron toutes les 5 minutes par `bin/newsletter_cron.sh`) : campagnes dues par lots de 100 par minute
  (500 par passage), statut `sched → live → sent` ; automatisations évaluées une fois par jour (`dormant45` : dernier achat il y a 45 jours
  exactement ; `birthday` : J-3 ; `firstOffice` : compte office créé il y a 2 jours ; `third` : deux mails déjà reçus ; `season` : J-7 avant la
  date de la campagne). Jamais deux mails au même contact le même jour, jamais deux fois la même campagne.
- Suivi, routes publiques : `GET /newsletter/o/{token}` (pixel), `GET /newsletter/c/{token}` (clic → redirection vers `nlLienWebshop` ou le cockpit),
  `GET /newsletter/u/{token}` (désinscription en un clic). Les stats d'une campagne envoyée viennent de `ceo_nl_envoi` (`stats.reel`).
- Vouchers : codes uniques (8 caractères, `ceo_nl_voucher`) générés au départ jusqu'à `maxVouchers` ; `POST /newsletter/vouchers/{code}/utiliser` les encaisse.
- Non branchés, et dits tels quels dans Paramètres : SMS (aucun fournisseur), Stripe. La déclinaison sur les réseaux (LinkedIn, Instagram,
  Slack) du brief a été retirée de l'assistant, qui compte quatre étapes : segment, template, texte, envoi.
- Page franchisé hors cockpit : `newsletter/?shop={id}` (même module, rôle = magasin).

### `/visites` — l'application terrain (consultant, franchisé, admin)

Une PWA (`visites/`) sur le téléphone du consultant et de l'admin, et l'onglet
« Plan d'action » du dashboard magasin pour le franchisé ; rien dans le rail. Le
serveur relit l'existant (CA `/exploitation/jour`, Google `/reputation`,
non-conformités `/pwa/tasks/nc`, push, SMTP) et porte ce qui n'existait pas :
la visite, ses points, ses photos, le plan d'action à trois acteurs, le
rapport mystery shopper, l'effectif. Rôle par lien : `?role=consultant&id=u8`,
`?shop=3` (franchisé), `?role=admin`.

| Route | Rôle | Ce qu'elle rend ou fait |
|---|---|---|
| `GET /visites/app?role=&id=&shop=` | tous | tout ce que l'application affiche : `boutiques[]` (feu, motifs, `ca`, `google`, `plano`, `equipe`, `msp`, `plansOuverts`, dernière et prochaine visite), `visites[]` (−90 j … +21 j), `points[]`, `photos[]`, `plans[]`, `msp{}`, `equipe{}`, `checklist[]`, `causes{}`, `seuils{}`, `frequence{}`, `reseau{}`. Le franchisé n'a que sa boutique. |
| `GET /visites/boutique/{shop}` | tous | ce qui coûte un appel au panel (`nc`) et l'historique 3 mois : `visites[]` terminées, `plansParMois`, `planoParVisite[]`, `photosJour[]`, `msp[]`, `equipe`. |
| `GET /visites/synthese` | admin | la synthèse du jour : `compteurs`, `boutiques[]`, `escalades[]`, `actions[]`, `consultants{}`. Envoyée par mail le matin si `seuils.mails` et `seuils.mailSynthese`. |
| `GET /visites/reglages` | admin | checklist, seuils, fréquences, état de l'horloge, adresse du cron. |
| `GET /visites/conformite?shop=` | tous | ce que le cockpit sait du comptoir sans rien ressaisir : `planogramme` (emplacements dessinés et tenus, %, comptoirs photographiés montés aujourd'hui, obligatoires sans place) et `assortiment` (références obligatoires vues en caisse sur la fenêtre calée sur la dernière vente, manquantes nommées). Une source absente rend son `motif`, pas un chiffre. |
| `GET /visites/cron?jeton=` | cron | l'horloge : escalade auto des P0 dépassés, rappels J-1 (18 h) et jour J (7 h), synthèse (7 h). Une fois par jour chacune (`visitesCron`). |
| `POST /visites` | consultant, admin | planifier : `client_id`, `shop`, `consultant`, `prevu_le`, `debut_h`, `duree_min`, `motif` (reguliere, asap, due, revisite). Rejouable : même `client_id`, même visite. |
| `PUT /visites/{id}` | consultant, admin | `statut` (planifiee, confirmee, en_cours, terminee, annulee), créneau, et la review : `sentiment` 1..5 (énergie de l'équipe), `execution` (standards, raccourcis, ecarts), `clients` (satisfaits, mitiges, insatisfaits), `causes[]` (production, equipe, decor, prix, appro, accueil, hygiene, autre), `diagnostic` (pourquoi, vu et mesuré), `reco` (lue par le franchisé, reprise dans la synthèse), `positif`, `notes`. `{id}` est l'identifiant ou le `client_id`. |
| `PUT /visites/{id}/points` | consultant | `points[]` : `ref`, `module`, `libelle`, `etat` (ok, ko, na), `note` 1..5, `valeur` (% planogramme), `commentaire`, `causes[]`. Idempotent par (visite, ref). |
| `POST /visites/photos` | tous | `client_id`, `shop` ou `visite_id`, `ref`, `plan_id`, `genre` (jour_facade, jour_interieur, jour_arriere, point, avant, apres, correction), `data` (data-URL ≤ 2 Mo, JPEG/PNG/WebP), `prise_a`, `lat`, `lng`. Fichier sous `uploads/visites/{shop}/`. |
| `POST /plans` | consultant, admin | un plan ou `plans[]` : `client_id`, `shop` ou `visite_id`, `ref`, `titre`, `detail`, `priorite` (P0, P1, P2), `assigne` (franchise, equipe, consultant, admin), `echeance`. Push au franchisé. |
| `PUT /plans/{id}` | tous | `statut` + `role` : transitions permises par rôle (franchisé : ouvert/reprendre → attente ; admin et consultant : attente → valide ou reprendre, valide → ferme, ouvert → escalade, …), `retour` (à reprendre), `escalade_motif`, `photo_client_id`, et le contenu pour consultant et admin. 409 si le passage est refusé. Push à qui de droit. |
| `POST /msp` | consultant, admin | `shop`, `mois` AAAA-MM, `rubriques{}` (/20), `total`, `commentaires`, `fichier` (data-URL PDF ≤ 8 Mo). Un rapport par boutique et par mois. |
| `PUT /equipe/{shop}` | consultant, franchisé | `effectif`, `prevu`, `departs`, `releve_le`. |
| `PUT /visites/reglages` | admin | `checklist[]` (modules produit, hygiene, visuel, planogramme, msp ; points `ref`, `libelle`, `photo`, `pct`), `seuils{}`, `frequence{shop: jours}`. |
| `POST /visites/tick` | admin | l'horloge tout de suite (`force` : sans attendre l'heure). |

Feu tricolore (`seuils`, la première règle qui s'applique) : 🔴 P0 ouvert
plus de `p0Jours`, Google sous `googleCible − googleRouge`, planogramme sous
`planoRouge`, rubrique MSP sous `mspAlerte` ; 🟡 P0 ou P1 ouvert, Google sous
la cible, deux avis ≤ 2/5 sur 30 jours, planogramme sous `planoOrange`, visite
due (fréquence par boutique), CA sous −`caOrange` % seulement si `caSeul` ;
🟢 sinon.

Tables : `ceo_visite`, `ceo_visite_point`, `ceo_visite_photo`,
`ceo_visite_action`, `ceo_visite_action_evt`, `ceo_msp`, `ceo_equipe_releve` ;
réglages `visitesChecklist`, `visitesSeuils`, `visitesFrequence`,
`visitesJeton`, `visitesCron`. Abonnements push : `shop_id` = la boutique
(franchisé), `c:{consultant}` ou `admin`.

Écrans du consultant : la fiche de visite ouvre le **contrôle guidé**
(`#controle/{id}`), un seul écran vertical, étape après étape — photo du jour,
chiffres et alertes, un module de checklist par étape, vu sur place et
recommandation, plan d'action et fin de visite ; chaque étape se replie une
fois faite. Le franchisé n'a pas de page à part : son plan d'action, la photo
de correction et la recommandation sont dans son dashboard magasin
(`dashboard/?shop=&vue=actions`), où le module est monté ; les notifications
push du franchisé y mènent.

Hors ligne (module `assets/js/visites.js`) : la lecture `/visites/app` est
gardée en IndexedDB ; chaque écriture porte un `client_id`, est appliquée à
l'écran, mise en file et rejouée au retour du réseau ; les photos sont
réduites sur l'appareil (bord long 1600 px, JPEG 0,7, EXIF retiré) avant
d'entrer dans la file.

## 2. Mapping base de données → écran → champ

### Tables existantes réutilisées (`franchise_buddy_db`)

| Table | Colonnes | Endpoint | Écran · champ |
|---|---|---|---|
| `of_tag` | `id, name, color` | `/referentiels/leviers` | pastilles levier partout · `slug` = slug(`name`), `nom`, `color` |
| `kpi` | `id, name, unit, target, seuil_bas, seuil_haut, levid` | `/referentiels/kpis`, `/meta.seuils` | assistants (liste KPI) · Marge & coûts (seuils food/labour/overhead) |
| `position` | `id, app_type, name` (`app_type='CONSULTANT'`) | `/consultants` | Tâches consultants · rôle de l'intervenant |
| `formation`, `checklist`, `todo_task` | — | non utilisés par le cockpit | (périmètre Manuel Opératoire) |

Les seuils du cockpit viennent de `kpi.seuil_haut` pour les codes food / labour / overhead ;
`meta.seuils` est la projection de ces lignes.

### Tables à créer (DDL proposé)

Toutes les tables propres au cockpit portent le préfixe `ceo_`. Les tables réutilisées du
Manuel Opératoire (`of_tag`, `kpi`, `position`) gardent leur nom d'origine — elles ne sont
pas créées par le cockpit, seulement lues.

```sql
-- Points de vente
CREATE TABLE ceo_shop (
  id            VARCHAR(8) PRIMARY KEY,
  code          VARCHAR(16) NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  franchisee    VARCHAR(120) NOT NULL,
  zone          VARCHAR(60) NOT NULL,
  status        ENUM('Ouvert','En ouverture','Fermé') NOT NULL DEFAULT 'Ouvert',
  opened_on     DATE NULL,
  valuation_target DECIMAL(12,2) NULL,
  basket_ref    DECIMAL(6,2) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Réel mensuel encodé par le franchisé (une ligne par magasin et par mois)
CREATE TABLE ceo_shop_month_perf (
  shop_id       VARCHAR(8) NOT NULL,
  year          SMALLINT NOT NULL,
  month         TINYINT NOT NULL,
  revenue       DECIMAL(12,2) NULL,   -- ca
  revenue_budget DECIMAL(12,2) NULL,  -- caBudget (issu de ceo_shop_budget_month)
  net_margin    DECIMAL(12,2) NULL,
  tickets       INT NULL,
  basket_avg    DECIMAL(6,2) NULL,
  food_pct      DECIMAL(5,2) NULL,
  labour_pct    DECIMAL(5,2) NULL,
  overhead_pct  DECIMAL(5,2) NULL,
  valuation     DECIMAL(12,2) NULL,
  encoded_at    DATETIME NULL,
  PRIMARY KEY (shop_id, year, month),
  FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Budget validé une fois par exercice avec le consultant
CREATE TABLE ceo_shop_budget (
  shop_id       VARCHAR(8) NOT NULL,
  fiscal_year   SMALLINT NOT NULL,
  validated_on  DATE NULL,
  validated_by  INT NULL,              -- position.id du consultant
  basket_target DECIMAL(6,2) NULL,
  months_total  TINYINT NOT NULL DEFAULT 12,
  PRIMARY KEY (shop_id, fiscal_year),
  FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_shop_budget_month (
  shop_id       VARCHAR(8) NOT NULL,
  fiscal_year   SMALLINT NOT NULL,
  month         TINYINT NOT NULL,
  revenue_budget DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (shop_id, fiscal_year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_shop_budget_line (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  shop_id       VARCHAR(8) NOT NULL,
  fiscal_year   SMALLINT NOT NULL,
  label         VARCHAR(120) NOT NULL, -- poste
  levid         INT UNSIGNED NULL,     -- of_tag.id
  pct_budget    DECIMAL(5,2) NOT NULL,
  real_field    VARCHAR(20) NULL,      -- food | labour | overhead | NULL
  sort_order    SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Objectifs réseau 1/3/5 ans
CREATE TABLE ceo_network_target (
  horizon       ENUM('h1','h3','h5') PRIMARY KEY,
  target_year   SMALLINT NOT NULL,
  revenue_target DECIMAL(14,2) NOT NULL,
  openings_target TINYINT NULL,
  openings_real TINYINT NULL,
  note          TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Projets, jalons, coûts, tâches
CREATE TABLE ceo_project (
  id VARCHAR(8) PRIMARY KEY, name VARCHAR(200) NOT NULL,
  status ENUM('À lancer','En cours','En retard','En pause','Terminé','Abandonné') NOT NULL,
  priority ENUM('Basse','Moyenne','Haute') NOT NULL DEFAULT 'Moyenne',
  starts_on DATE, ends_on DATE, budget DECIMAL(12,2), value_est DECIMAL(12,2), value_real DECIMAL(12,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ceo_project_levid  (project_id VARCHAR(8), levid INT UNSIGNED, PRIMARY KEY (project_id, levid));
CREATE TABLE ceo_project_milestone (id INT AUTO_INCREMENT PRIMARY KEY, project_id VARCHAR(8), name VARCHAR(200), target_on DATE, done_on DATE NULL);
CREATE TABLE ceo_project_cost   (id INT AUTO_INCREMENT PRIMARY KEY, project_id VARCHAR(8), label VARCHAR(120), planned DECIMAL(12,2), actual DECIMAL(12,2));
CREATE TABLE ceo_project_task (
  id VARCHAR(10) PRIMARY KEY, project_id VARCHAR(8) NOT NULL, name VARCHAR(200) NOT NULL,
  owner_kind ENUM('c','s') NOT NULL, owner_id VARCHAR(10) NOT NULL,
  shop_id VARCHAR(8) NULL,
  due_on DATE NOT NULL, done_on DATE NULL, reminded_on DATE NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal + reporting
CREATE TABLE ceo_journal_entry (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, happened_at DATETIME NOT NULL,
  actor VARCHAR(80) NOT NULL, kind VARCHAR(40) NOT NULL, project VARCHAR(200) NULL, message TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ceo_report_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, frequency VARCHAR(30) NOT NULL,
  recipients TEXT NOT NULL, next_run DATE NULL, format VARCHAR(10) NOT NULL DEFAULT 'PDF', active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ceo_app_setting (`key` VARCHAR(60) PRIMARY KEY, value TEXT NOT NULL);
```

`ceo_app_setting` porte tout ce que `/meta` renvoie hors référentiels (libellés réseau, exercice
courant, contribution attendue des ouvertures, notes).

```sql
-- Catalogue et ventes mensuelles par référence (alimente /products/scoring)
CREATE TABLE ceo_product (
  id            VARCHAR(24) PRIMARY KEY,
  nom           VARCHAR(120) NOT NULL,
  categorie     VARCHAR(60)  NOT NULL,
  actif         TINYINT(1)   NOT NULL DEFAULT 1
);

CREATE TABLE ceo_product_month_sales (
  product_id    VARCHAR(24) NOT NULL,
  annee         SMALLINT    NOT NULL,
  mois          TINYINT     NOT NULL,
  volume        INT         NOT NULL,          -- unités vendues, réseau
  nb_magasins   SMALLINT    NOT NULL,          -- magasins ouverts ayant vendu la référence
  prix_moyen    DECIMAL(8,2) NOT NULL,         -- prix de vente TTC moyen
  cout_unitaire DECIMAL(8,2) NOT NULL,         -- matière + emballage
  PRIMARY KEY (product_id, annee, mois),
  FOREIGN KEY (product_id) REFERENCES ceo_product(id)
);
```


```sql
-- Contrôle des posts Facebook des magasins
CREATE TABLE ceo_fb_post (
  id               VARCHAR(16) PRIMARY KEY,
  shop_id          VARCHAR(8)   NULL,          -- NULL = post réseau
  author           VARCHAR(120) NOT NULL,
  format           VARCHAR(20)  NOT NULL,      -- Photo | Carrousel | Vidéo | Texte | Lien | Événement
  message          TEXT         NOT NULL,
  link             VARCHAR(400) NULL,
  medias_json      JSON         NULL,          -- [{"nom","type","alt","largeur","hauteur"}]
  planned_at       DATETIME     NULL,
  submitted_at     DATETIME     NOT NULL,
  status           ENUM('brouillon','a_controler','a_valider','valide','refuse','publie') NOT NULL DEFAULT 'a_controler',
  agent_note       TINYINT      NULL,          -- proposition de l'agent
  agent_summary    VARCHAR(400) NULL,
  agent_ran_at     DATETIME     NULL,
  agent_runs       SMALLINT     NOT NULL DEFAULT 0,
  note             TINYINT      NULL,          -- décision humaine
  decision_famille VARCHAR(60)  NULL,          -- obligatoire sous le seuil / en cas de refus
  decision_type    VARCHAR(80)  NULL,
  decision_comment TEXT         NULL,
  decided_at       DATETIME     NULL,
  decided_by       VARCHAR(80)  NULL,
  published_at     DATETIME     NULL,
  fb_post_id       VARCHAR(64)  NULL,          -- id Facebook, quand la publication sera branchée
  CONSTRAINT fk_fbpost_shop FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
);

CREATE TABLE ceo_fb_finding (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_id    VARCHAR(16)  NOT NULL,
  rule_code  VARCHAR(40)  NOT NULL,
  rule_name  VARCHAR(120) NOT NULL,
  famille    VARCHAR(60)  NOT NULL,
  type       VARCHAR(80)  NOT NULL,
  gravite    TINYINT      NOT NULL,            -- 1 mineur | 2 majeur | 3 critique
  message    VARCHAR(400) NOT NULL,
  extrait    VARCHAR(200) NULL,
  status     ENUM('ouvert','ignore','corrige') NOT NULL DEFAULT 'ouvert',
  created_at DATETIME     NOT NULL,
  CONSTRAINT fk_finding_post FOREIGN KEY (post_id) REFERENCES ceo_fb_post(id) ON DELETE CASCADE
);
```

Les écarts sont **réécrits à chaque contrôle** : ils décrivent le texte tel
qu'il est, pas son historique (le journal, lui, garde la trace). Seul
`status = 'ignore'` est reporté d'un contrôle au suivant, par `rule_code` — une
dérogation accordée ne se rejoue pas à chaque passage de l'agent. L'id d'un
écart change donc au recontrôle.

`tendVol` est calculé côté API : `volume(annee, mois) / volume(annee-1, mois)`.

-- Scouting commercial
CREATE TABLE ceo_scouting_tile (
  sector      TINYINT UNSIGNED PRIMARY KEY,    -- secteur Overpass 0–8
  fetched_at  DATETIME NOT NULL,
  payload     MEDIUMTEXT NOT NULL              -- JSON { t, c, b, p } déposé par le navigateur
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_scouting_competitor (
  osm_id         VARCHAR(20) PRIMARY KEY,      -- n123 / w456 / r789
  name           VARCHAR(200) NOT NULL DEFAULT '',
  commune        VARCHAR(120) NOT NULL DEFAULT '',
  arrondissement VARCHAR(60)  NOT NULL DEFAULT '',
  rating         DECIMAL(2,1) NULL,
  reviews        INT UNSIGNED NULL,
  rating_source  ENUM('google','manuel') NULL,
  comment        VARCHAR(200) NULL,
  updated_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_scouting_candidate (
  id             BIGINT UNSIGNED PRIMARY KEY,  -- horodatage client (ms)
  name           VARCHAR(200) NOT NULL,
  commune        VARCHAR(120) NOT NULL,
  arrondissement VARCHAR(60)  NOT NULL,
  province       VARCHAR(60)  NOT NULL,
  lat            DECIMAL(9,6) NOT NULL,
  lng            DECIMAL(9,6) NOT NULL,
  households     INT UNSIGNED NOT NULL,
  market         INT UNSIGNED NOT NULL,
  emprise        DECIMAL(5,4) NOT NULL,        -- ratio 0–1
  revenue        INT UNSIGNED NOT NULL,        -- CA annuel TTC estimé
  score          TINYINT UNSIGNED NOT NULL,
  shops          SMALLINT UNSIGNED NOT NULL,
  strong         SMALLINT UNSIGNED NOT NULL,
  revenue_m2     INT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ceo_scouting_population (
  ins         CHAR(5) PRIMARY KEY,             -- code NIS
  population  INT UNSIGNED NOT NULL,
  imported_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Les hypothèses du modèle sont une ligne de `ceo_app_setting` (`scoutingParams`).
La clé Google est celle du connecteur Google de Paramètres (ligne `google`,
`PUT /parametres/google-cle`) : le serveur interroge Places, la clé n'est jamais
renvoyée à l'écran.

```sql
### Écran → tables

| Écran | Tables |
|---|---|
| Tâches consultants | `ceo_project_task`, `ceo_project`, `ceo_shop`, `position`, `of_tag` |
| Tableau des magasins | `ceo_shop`, `ceo_shop_month_perf` |
| Heatmap mensuelle | `ceo_shop`, `ceo_shop_month_perf`, `ceo_shop_budget_month` |
| Objectifs de CA | `ceo_network_target`, `ceo_shop_budget_month`, `ceo_shop_month_perf`, `ceo_app_setting` |
| Suivi budget magasin | `ceo_shop_budget`, `ceo_shop_budget_month`, `ceo_shop_budget_line`, `ceo_shop_month_perf` |
| Marge & coûts | `ceo_shop_month_perf`, `kpi` (seuils) |
| Projets | `ceo_project`, `ceo_project_milestone`, `ceo_project_cost`, `ceo_project_task`, `ceo_project_levid` |
| Reporting | `ceo_report_schedule`, `ceo_shop`, `position` |
| Scoring produits | `ceo_product`, `ceo_product_month_sales` |
| Contrôle posts Facebook | `ceo_fb_post`, `ceo_fb_finding`, `ceo_shop`, `ceo_app_setting` (clé `fbControle`) |
| Journal | `ceo_journal_entry` |
| Paramètres | `of_tag`, `kpi`, `ceo_app_setting`, `ceo_shop` |

---

## 3. Écritures (assistants et actions du cockpit)

Les écrans qui écrivent aujourd'hui en mémoire attendent ces routes :

| Action | Route |
|---|---|
| Nouveau projet (assistant 4 étapes) | `POST /projects` |
| Nouvelle tâche (assistant 3 étapes) | `POST /projects/{id}/tasks` |
| Changement de statut projet | `PATCH /projects/{id}` |
| Relance d'une tâche | `POST /tasks/{id}/reminder` |
| Modification d'un seuil ou d'un modèle d'email | `PUT /parametres/{key}` |
| Scouting — hypothèses du modèle | `PUT /parametres/scoutingParams` (`{ "valeur": … }`) |
| Scouting — dépôt d'un secteur OSM dans le cache partagé (repli navigateur) | `PUT /scouting/tiles/{secteur}` (corps : `{ t, c, b, p }`, ≤ 12 Mo) |
| Scouting — relecture d'un secteur OpenStreetMap par le serveur | `POST /scouting/refresh/{secteur}` (0 à 8 ; une à trois minutes ; rend le secteur `{ t, c, b, p }` et le dépose dans le cache ; 502 si Overpass ne répond pas, le cache est alors conservé) |
| Scouting — notes, avis, source, commentaire terrain (lot ≤ 500) | `PUT /scouting/competitors` (`{ "rows": [{ id, name?, commune?, arr?, rating?, reviews?, source?, comment? }] }` — seules les clés présentes sont modifiées) |
| Scouting — notes Google d'un lot de commerces (≤ 40 ; clé de Paramètres, côté serveur) | `POST /scouting/notes` (`{ "rows": [{ id, name, addr?, commune?, arr?, lat, lng }] }` → `{ rows: [{ id, rating, reviews }], erreur? }` ; 422 sans clé) |
| Scouting — zone candidate retenue / retirée | `POST /scouting/candidates` (objet zone) · `DELETE /scouting/candidates/{id}` |
| Scouting — position d'un magasin du réseau pointée sur la carte | `PUT /scouting/reseau/{id}` (`{ lat, lng }`, Belgique seulement ; prime sur la fiche Google ; ligne `ceo_app_setting.scoutingReseau`) |
| Scouting — import CSV StatBel | `PUT /scouting/populations` (`{ "populations": { "NIS": population }, "fichier"? }`) |
| Soumission d'un post Facebook au contrôle | `POST /facebook/posts` |
| (Re)passer l'agent de contrôle sur un post | `POST /facebook/posts/{id}/controle` |
| Décision du CEO (valider / refuser) ou publication déclarée | `PATCH /facebook/posts/{id}` |
| Écarter, rouvrir ou clore un écart | `PATCH /facebook/posts/{id}/ecarts/{ecartId}` |
| Activation/désactivation d'une règle de contrôle | `PUT /parametres/fbControle` |

Toute écriture doit aussi produire une ligne `ceo_journal_entry` (l'écran Journal en dépend).
