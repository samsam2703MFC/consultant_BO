-- ============================================================================
-- Cockpit CEO — L'Atelier by · schéma MySQL
-- Source : contrat-api.md (bundle Claude Design « Cockpit CEO cliquable complet »)
--
-- Toutes les tables propres au cockpit portent le préfixe `ceo_`.
-- Les tables `of_tag`, `kpi` et `position` appartiennent au Manuel Opératoire
-- (franchise_buddy_db) : elles sont seulement LUES par le cockpit. Les blocs
-- CREATE TABLE IF NOT EXISTS ci-dessous ne servent qu'à un déploiement
-- autonome (dev / démo) — en production, pointez la connexion sur la base
-- existante et ces CREATE ne toucheront à rien.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- Tables réutilisées du Manuel Opératoire (créées seulement si absentes)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS of_tag (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name   VARCHAR(60)  NOT NULL,
  color  VARCHAR(9)   NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kpi (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(40)  NULL,
  name       VARCHAR(120) NOT NULL,
  unit       VARCHAR(12)  NULL,
  target     DECIMAL(14,2) NULL,
  seuil_bas  DECIMAL(10,2) NULL,
  seuil_haut DECIMAL(10,2) NULL,
  levid      INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS position (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  app_type  VARCHAR(20)  NOT NULL,          -- 'CONSULTANT' pour le cockpit
  name      VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Points de vente
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_shop (
  id               VARCHAR(8) PRIMARY KEY,
  code             VARCHAR(16) NOT NULL UNIQUE,
  name             VARCHAR(120) NOT NULL,
  franchisee       VARCHAR(120) NOT NULL,
  zone             VARCHAR(60) NOT NULL,
  status           ENUM('Ouvert','En ouverture','Fermé') NOT NULL DEFAULT 'Ouvert',
  opened_on        DATE NULL,
  valuation_target DECIMAL(12,2) NULL,
  basket_ref       DECIMAL(6,2) NULL,
  pwa_shop_id      BIGINT UNSIGNED NULL   -- id de la boutique dans le panel consultant (atelierby_db)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Réel mensuel encodé par le franchisé (une ligne par magasin et par mois)
CREATE TABLE IF NOT EXISTS ceo_shop_month_perf (
  shop_id        VARCHAR(8) NOT NULL,
  year           SMALLINT NOT NULL,
  month          TINYINT NOT NULL,
  revenue        DECIMAL(12,2) NULL,    -- ca
  revenue_budget DECIMAL(12,2) NULL,    -- caBudget (issu de ceo_shop_budget_month)
  ca_theorique   DECIMAL(12,2) NULL,    -- CA théorique du mois (étude de marché)
  net_margin     DECIMAL(12,2) NULL,
  tickets        INT NULL,
  basket_avg     DECIMAL(6,2) NULL,
  food_pct       DECIMAL(5,2) NULL,
  labour_pct     DECIMAL(5,2) NULL,
  overhead_pct   DECIMAL(5,2) NULL,
  valuation      DECIMAL(12,2) NULL,
  encoded_at     DATETIME NULL,
  PRIMARY KEY (shop_id, year, month),
  FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Budget validé une fois par exercice avec le consultant
CREATE TABLE IF NOT EXISTS ceo_shop_budget (
  shop_id                  VARCHAR(8) NOT NULL,
  fiscal_year              SMALLINT NOT NULL,
  validated_on             DATE NULL,
  validated_by             INT NULL,              -- position.id du consultant
  basket_target            DECIMAL(6,2) NULL,
  months_total             TINYINT NOT NULL DEFAULT 12,
  ca_theorique_an          DECIMAL(12,2) NULL,    -- somme de ceo_shop_month_perf.ca_theorique
  etude_date               DATE NULL,
  etude_source             VARCHAR(160) NULL,
  etude_potentiel_menages  INT NULL,
  etude_potentiel_maturite DECIMAL(12,2) NULL,    -- potentiel à maturité (100 %)
  annee_exploitation       TINYINT NULL,          -- 1 | 2 | 3 | 4 (régime établi)
  montee_regime            JSON NULL,             -- {"a1":70,"a2":80,"a3":90}
  saisonnalite             JSON NULL,             -- 12 pourcentages
  etude_annexe             JSON NULL,             -- {"nom","url","taille","date"}
  PRIMARY KEY (shop_id, fiscal_year),
  FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_shop_budget_month (
  shop_id        VARCHAR(8) NOT NULL,
  fiscal_year    SMALLINT NOT NULL,
  month          TINYINT NOT NULL,
  revenue_budget DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (shop_id, fiscal_year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_shop_budget_line (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  shop_id       VARCHAR(8) NOT NULL,
  fiscal_year   SMALLINT NOT NULL,
  label         VARCHAR(120) NOT NULL,  -- poste
  levid         INT UNSIGNED NULL,      -- of_tag.id
  pct_budget    DECIMAL(5,2) NOT NULL,
  pct_theorique DECIMAL(5,2) NULL,
  real_field    VARCHAR(20) NULL,       -- food | labour | overhead | NULL
  sort_order    SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Objectifs réseau 1/3/5 ans
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_network_target (
  horizon         ENUM('h1','h3','h5') PRIMARY KEY,
  target_year     SMALLINT NOT NULL,
  revenue_target  DECIMAL(14,2) NOT NULL,
  openings_target TINYINT NULL,
  openings_real   TINYINT NULL,
  note            TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Projets, jalons, coûts, tâches
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_project (
  id          VARCHAR(16) PRIMARY KEY,
  name        VARCHAR(200) NOT NULL,
  famille     VARCHAR(40) NOT NULL,     -- Produits | Services | Organisation & coûts | Développement réseau
  status      ENUM('À lancer','En cours','En retard','En pause','Terminé','Abandonné') NOT NULL,
  priority    ENUM('Basse','Moyenne','Haute') NOT NULL DEFAULT 'Moyenne',
  axe         VARCHAR(60) NULL,         -- axe stratégique principal (JSON list dans axes_json)
  axes_json   JSON NULL,
  kpis_json   JSON NULL,                -- noms des KPI de suivi
  value_txt   VARCHAR(255) NULL,
  starts_on   DATE,
  ends_on     DATE,
  budget      DECIMAL(12,2),
  value_est   DECIMAL(12,2),
  value_real  DECIMAL(12,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_project_levid (
  project_id VARCHAR(16),
  levid      INT UNSIGNED,
  PRIMARY KEY (project_id, levid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_project_milestone (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  project_id VARCHAR(16),
  name       VARCHAR(200),
  target_on  DATE,
  done_on    DATE NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_project_cost (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  project_id VARCHAR(16),
  label      VARCHAR(120),
  planned    DECIMAL(12,2),
  actual     DECIMAL(12,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_project_task (
  id          VARCHAR(16) PRIMARY KEY,
  project_id  VARCHAR(16) NOT NULL,
  name        VARCHAR(200) NOT NULL,
  owner_kind  ENUM('c','s') NOT NULL,
  owner_id    VARCHAR(10) NOT NULL,
  shop_id     VARCHAR(8) NULL,          -- magasin de rattachement (NULL = tâche réseau)
  due_on      DATE NOT NULL,
  done_on     DATE NULL,
  reminded_on DATE NULL,
  description TEXT NULL,
  budget      DECIMAL(10,2) NULL,
  note        TINYINT NULL,              -- 1..5, NULL = rendue mais pas encore validée
  validated_by VARCHAR(80) NULL,
  CONSTRAINT fk_task_shop FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Intervenants (consultants + fournisseurs) et visites magasin
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_consultant (
  id          VARCHAR(10) PRIMARY KEY,
  position_id INT UNSIGNED NULL,        -- position.id (app_type = 'CONSULTANT')
  name        VARCHAR(120) NOT NULL,
  role        VARCHAR(120) NOT NULL,
  email       VARCHAR(160) NOT NULL,
  daily_rate  DECIMAL(8,2) NULL,        -- TJM
  workload    TINYINT NULL              -- % de charge trimestre
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_supplier (
  id        VARCHAR(10) PRIMARY KEY,
  name      VARCHAR(120) NOT NULL,
  perimeter VARCHAR(160) NULL,
  email     VARCHAR(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_consultant_visit (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  consultant_id VARCHAR(10) NOT NULL,
  visited_on    DATE NOT NULL,
  store_label   VARCHAR(120) NOT NULL,
  subject       VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Personnel interne (destinataires des rapports)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_person (
  id    VARCHAR(10) PRIMARY KEY,
  name  VARCHAR(120) NOT NULL,
  role  VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Reporting automatisé + alertes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_report_schedule (
  id          VARCHAR(10) PRIMARY KEY,
  name        VARCHAR(160) NOT NULL,
  type        VARCHAR(40) NOT NULL,     -- Financier | Commercial | Contrôle qualité | …
  description VARCHAR(255) NULL,
  frequency   VARCHAR(30) NOT NULL,     -- Hebdomadaire | Mensuel | Trimestriel | Annuel
  postes_json JSON NOT NULL,            -- ["p1","p3"] écrans sources P1–P12
  dest_id     VARCHAR(10) NULL,         -- ceo_person.id
  cc_id       VARCHAR(10) NULL,
  last_run    DATE NULL,
  next_run    DATE NULL,
  format      VARCHAR(10) NOT NULL DEFAULT 'PDF',
  active      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_alert_rule (
  id      VARCHAR(10) PRIMARY KEY,
  name    VARCHAR(160) NOT NULL,
  channel VARCHAR(40) NOT NULL,         -- Push + email | Email | Push
  active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Modèles d'email et templates de projet par axe
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_email_template (
  id      VARCHAR(10) PRIMARY KEY,
  name    VARCHAR(120) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  body    TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ceo_project_template (
  axe         VARCHAR(60) PRIMARY KEY,  -- Ventes | Marge nette franchisé | Développement réseau | Produit — Interne… | Produit — Externe…
  jalons_json JSON NOT NULL,            -- [{"nom","j"}] j = jours avant échéance (≤ 0)
  couts_json  JSON NOT NULL             -- [{"poste","prevu"}]
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- CRM projet (gain marque / apport / objectif / attendu vs réalisé)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_project_crm (
  project_id VARCHAR(16) PRIMARY KEY,
  gain       TEXT NULL,
  apport     TEXT NULL,
  objectif   TEXT NULL,
  attendu    DECIMAL(12,2) NULL,
  realise    DECIMAL(12,2) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Journal (traçabilité intégrale)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_journal_entry (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  happened_at DATETIME NOT NULL,
  actor       VARCHAR(80) NOT NULL,
  kind        VARCHAR(40) NOT NULL,
  project     VARCHAR(200) NULL,
  message     TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Paramètres applicatifs (tout ce que /meta renvoie hors référentiels)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_app_setting (
  `key` VARCHAR(60) PRIMARY KEY,
  value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Catalogue et ventes mensuelles par référence (alimente /products/scoring)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_product (
  id        VARCHAR(24) PRIMARY KEY,
  nom       VARCHAR(120) NOT NULL,
  categorie VARCHAR(60)  NOT NULL,
  actif     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Panel consultant (pwa_consultant) — table LUE par le cockpit.
-- Elle appartient au panel (atelierby_db) qui la crée lui-même au premier
-- partage ; ce CREATE IF NOT EXISTS ne sert qu'à un déploiement autonome.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mac_report_share (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token           VARCHAR(64)     NOT NULL,
  id_shop         BIGINT UNSIGNED NOT NULL,
  ym              CHAR(7)         NOT NULL,
  label           VARCHAR(190)    NOT NULL,
  html            MEDIUMBLOB      NULL,
  id_consultant   BIGINT UNSIGNED NOT NULL,
  consultant_name VARCHAR(190)    NULL,
  created_at      DATETIME        NOT NULL,
  expires_at      DATETIME        NOT NULL,
  revoked_at      DATETIME        NULL,
  opens           INT UNSIGNED    NOT NULL DEFAULT 0,
  last_opened_at  DATETIME        NULL,
  last_ip         VARCHAR(45)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  KEY idx_consultant (id_consultant, created_at),
  KEY idx_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_product_month_sales (
  product_id    VARCHAR(24) NOT NULL,
  annee         SMALLINT    NOT NULL,
  mois          TINYINT     NOT NULL,
  volume        INT         NOT NULL,         -- unités vendues, réseau
  nb_magasins   SMALLINT    NOT NULL,         -- magasins ouverts ayant vendu la référence
  prix_moyen    DECIMAL(8,2) NOT NULL,        -- prix de vente TTC moyen
  cout_unitaire DECIMAL(8,2) NOT NULL,        -- matière + emballage
  PRIMARY KEY (product_id, annee, mois),
  FOREIGN KEY (product_id) REFERENCES ceo_product(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Validation des tâches consultants
--
-- Une tâche n'est plus « cochée » : elle est NOTÉE de 1 à 5. La note porte à
-- la fois la conformité et la gravité — 5 exemplaire, 4 conforme, 3/2/1 non
-- conforme mineur/majeur/critique. Sous le seuil (réglage `signalement`,
-- défaut 4), la validation ouvre un signalement.
--
-- `done_on` reste la date de clôture ; `note` nulle = tâche rendue mais pas
-- encore validée, ce qui alimente le groupe « À valider » de l'écran.
--
-- Sur une base créée par une version antérieure :
--   ALTER TABLE ceo_project_task ADD COLUMN note TINYINT NULL;
--   ALTER TABLE ceo_project_task ADD COLUMN validated_by VARCHAR(80) NULL;
-- ----------------------------------------------------------------------------
-- Le signalement ouvert par une validation sous le seuil.
--
-- Pas de colonne `gravite` : elle serait la copie de `note`, et deux sources
-- pour un même fait finissent par diverger.
CREATE TABLE IF NOT EXISTS ceo_task_issue (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  task_id     VARCHAR(16)  NOT NULL,
  note        TINYINT      NOT NULL,          -- 1..3, recopiée pour l'historique
  famille     VARCHAR(60)  NOT NULL,          -- Livrable, Délai, Qualité de service…
  type        VARCHAR(80)  NOT NULL,          -- filtré par la famille
  comment     TEXT         NULL,
  recipients  VARCHAR(400) NULL,              -- « c:c1,c:c3 » — en copie
  status      ENUM('nouveau','vu','traite') NOT NULL DEFAULT 'nouveau',
  created_at  DATETIME     NOT NULL,
  created_by  VARCHAR(80)  NOT NULL,
  seen_at     DATETIME     NULL,
  closed_at   DATETIME     NULL,
  closed_by   VARCHAR(80)  NULL,
  KEY idx_task (task_id),
  KEY idx_status (status, created_at),
  CONSTRAINT fk_issue_task FOREIGN KEY (task_id) REFERENCES ceo_project_task(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Contrôle des posts Facebook des magasins
--
-- Un franchisé soumet un post ; l'agent de contrôle (src/fbcontrole.php) le
-- relit et note de 1 à 5 sur la MÊME échelle que les tâches consultants
-- (réglage `signalement`) ; le CEO valide ou refuse. Rien n'est publié par le
-- cockpit : `published_at` et `fb_post_id` sont remplis à la main aujourd'hui,
-- et resteront les mêmes colonnes le jour où l'API Meta Graph sera branchée.
--
-- `status` porte tout le cycle — un post refusé qui revient corrigé repasse en
-- `a_controler`, et `agent_runs` compte les passages de l'agent.
-- Le pack de règles vit dans `ceo_app_setting` sous la clé `fbControle` : la
-- charte du réseau bouge plus souvent que le code.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ceo_fb_post (
  id               VARCHAR(16) PRIMARY KEY,
  shop_id          VARCHAR(8)   NULL,           -- NULL = post réseau (pas d'ancrage local exigé)
  author           VARCHAR(120) NOT NULL,       -- le franchisé ou le community manager qui soumet
  format           VARCHAR(20)  NOT NULL,       -- Photo | Carrousel | Vidéo | Texte | Lien | Événement
  message          TEXT         NOT NULL,
  link             VARCHAR(400) NULL,
  medias_json      JSON         NULL,           -- [{"nom","type","alt","largeur","hauteur"}]
  planned_at       DATETIME     NULL,           -- publication souhaitée
  submitted_at     DATETIME     NOT NULL,
  status           ENUM('brouillon','a_controler','a_valider','valide','refuse','publie') NOT NULL DEFAULT 'a_controler',
  agent_note       TINYINT      NULL,           -- 1..5, proposition de l'agent
  agent_summary    VARCHAR(400) NULL,
  agent_ran_at     DATETIME     NULL,
  agent_runs       SMALLINT     NOT NULL DEFAULT 0,
  note             TINYINT      NULL,           -- 1..5, décision humaine (peut diverger de l'agent)
  decision_famille VARCHAR(60)  NULL,           -- obligatoire sous le seuil / en cas de refus
  decision_type    VARCHAR(80)  NULL,
  decision_comment TEXT         NULL,
  decided_at       DATETIME     NULL,
  decided_by       VARCHAR(80)  NULL,
  published_at     DATETIME     NULL,
  fb_post_id       VARCHAR(64)  NULL,           -- id Facebook, quand la publication sera branchée
  KEY idx_status (status, planned_at),
  KEY idx_shop (shop_id, planned_at),
  CONSTRAINT fk_fbpost_shop FOREIGN KEY (shop_id) REFERENCES ceo_shop(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un écart relevé par l'agent sur un post.
--
-- Les écarts sont réécrits à chaque contrôle : ils décrivent le texte tel qu'il
-- est, pas l'historique. Seul `status = 'ignore'` survit d'un contrôle au
-- suivant (voir wr_fb_controle) — une dérogation accordée par le CEO ne doit
-- pas se rejouer à chaque passage de l'agent.
CREATE TABLE IF NOT EXISTS ceo_fb_finding (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_id    VARCHAR(16)  NOT NULL,
  rule_code  VARCHAR(40)  NOT NULL,
  rule_name  VARCHAR(120) NOT NULL,
  famille    VARCHAR(60)  NOT NULL,
  type       VARCHAR(80)  NOT NULL,
  gravite    TINYINT      NOT NULL,             -- 1 mineur | 2 majeur | 3 critique
  message    VARCHAR(400) NOT NULL,
  extrait    VARCHAR(200) NULL,                 -- le passage en cause, pour la relecture
  status     ENUM('ouvert','ignore','corrige') NOT NULL DEFAULT 'ouvert',
  created_at DATETIME     NOT NULL,
  KEY idx_post (post_id, gravite),
  CONSTRAINT fk_finding_post FOREIGN KEY (post_id) REFERENCES ceo_fb_post(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
