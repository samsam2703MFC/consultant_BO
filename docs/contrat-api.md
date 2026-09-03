# Cockpit CEO — contrat API et mapping base de données

Le HTML ne contient plus aucune donnée métier. Tout passe par `api.js`, qui appelle
un endpoint par écran, normalise la réponse, et l'expose au composant. Si l'API ne
répond pas, `data.js` (jeu de démonstration) prend le relais et `p.source` vaut `demo`.

- Base d'URL : `window.COCKPIT_API_BASE`, défaut `/api/cockpit`
- Format : JSON, `Content-Type: application/json`, montants en euros (nombres, pas de chaînes)
- Dates : `YYYY-MM-DD`. Pourcentages : nombres en points (`32` = 32 %), sauf `margePct` (ratio 0–1)
- Granularité des réels : **mensuelle**
- Timeout client : 4 s, les 14 endpoints sont appelés en parallèle

---

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
| — | `GET /scouting` | Scouting commercial : saisies, hypothèses, clé Google, inventaire du cache OSM |
| — | `GET /scouting/tiles/{secteur}` | Scouting commercial : un secteur du cache OpenStreetMap |

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

---

### `/scouting` — saisies du scouting commercial

Chargé à l'ouverture de l'écran (pas au démarrage du cockpit). Les données
OpenStreetMap elles-mêmes ne transitent pas par ce endpoint : le navigateur
interroge Overpass et dépose chaque secteur dans le cache partagé.

```json
{
  "params": { "spend": 586, "emprise": 0, "passage": 15, "surface": 250, "empriseMax": 30,
              "compK": 0.22, "hhSize": 2.31, "minScore": 55, "radius": 2, "thresh": 4.5 },
  "googleKey": "",
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
- `competitors[].source` ∈ `google` | `manuel` ; une note `manuel` prime sur Google. `rating: null` avec
  `source: "google"` = commerce déjà interrogé sans note (pas réinterrogé).
- `id` d'un concurrent = type + id OSM (`n`, `w`, `r`). `id` d'une zone candidate = horodatage client (ms).
- `tiles` : inventaire seulement ; `GET /scouting/tiles/{secteur}` renvoie le JSON `{ t, c, b, p }` déposé
  par le navigateur (communes, commerces, nœuds `place`). 404 si le secteur n'est pas en cache.

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

Les hypothèses du modèle et la clé Google Places sont des lignes de
`ceo_app_setting` (`scoutingParams`, `scoutingGoogleKey`).

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
| Scouting — hypothèses du modèle / clé Google Places | `PUT /parametres/scoutingParams` · `PUT /parametres/scoutingGoogleKey` (`{ "valeur": … }`) |
| Scouting — dépôt d'un secteur OSM dans le cache partagé | `PUT /scouting/tiles/{secteur}` (corps : `{ t, c, b, p }`, ≤ 12 Mo) |
| Scouting — notes, avis, source, commentaire terrain (lot ≤ 500) | `PUT /scouting/competitors` (`{ "rows": [{ id, name?, commune?, arr?, rating?, reviews?, source?, comment? }] }` — seules les clés présentes sont modifiées) |
| Scouting — zone candidate retenue / retirée | `POST /scouting/candidates` (objet zone) · `DELETE /scouting/candidates/{id}` |
| Scouting — import CSV StatBel | `PUT /scouting/populations` (`{ "populations": { "NIS": population }, "fichier"? }`) |

Toute écriture doit aussi produire une ligne `ceo_journal_entry` (l'écran Journal en dépend).
