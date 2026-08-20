-- Référentiels et structures de l'assistant de campagne, repris du dépôt
-- marketing (migrations + seeds). CREATE TABLE IF NOT EXISTS : rejouable.
-- Les tables de DONNÉES perdues au nettoyage (prospects B2B, leads CRM)
-- reviennent VIDES : la structure permet aux écrans de rendre, les 893
-- prospects supprimés ne se réinventent pas.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mar_channel (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(40)     NOT NULL,
  label      VARCHAR(120)    NOT NULL,
  family     VARCHAR(10)     NOT NULL COMMENT 'DIGITAL | PHYSIQUE',
  sort_order SMALLINT        NOT NULL DEFAULT 0,
  is_active  TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_channel_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_format (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(40)     NOT NULL,
  name       VARCHAR(120)    NOT NULL,
  width_px   SMALLINT UNSIGNED NOT NULL,
  height_px  SMALLINT UNSIGNED NOT NULL,
  note       VARCHAR(200)        NULL,
  sort_order SMALLINT        NOT NULL DEFAULT 0,
  is_active  TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_format_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_tone (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(40)     NOT NULL,
  label      VARCHAR(80)     NOT NULL,
  sort_order SMALLINT        NOT NULL DEFAULT 0,
  is_active  TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_tone_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_client_target (
  code       VARCHAR(10)  NOT NULL,
  label      VARCHAR(80)  NOT NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_position (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(40)     NOT NULL,
  label      VARCHAR(120)    NOT NULL,
  sort_order SMALLINT        NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_position_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_uniform (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED     NULL,
  code        VARCHAR(40)     NOT NULL,
  name        VARCHAR(160)    NOT NULL,
  description VARCHAR(400)        NULL,
  icon_path   TEXT                NULL,
  sort_order  SMALLINT        NOT NULL DEFAULT 0,
  is_active   TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_uniform_code (code),
  KEY ix_mar_uniform_campaign (campaign_id),
  CONSTRAINT fk_mar_uniform_campaign FOREIGN KEY (campaign_id) REFERENCES mar_campaign (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_promotion_mechanic (
  code       VARCHAR(20)  NOT NULL,
  label      VARCHAR(80)  NOT NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_pos_answer_type (
  code       VARCHAR(20)  NOT NULL,
  label      VARCHAR(80)  NOT NULL,
  hint       VARCHAR(200)     NULL COMMENT 'Ce que la caisse affiche au vendeur',
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_cost_kind (
  code       VARCHAR(20)  NOT NULL,
  label      VARCHAR(80)  NOT NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_review_platform (
  code       VARCHAR(20)  NOT NULL,
  label      VARCHAR(80)  NOT NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canaux de distribution d'un bon : web-shop, caisse, office, B2B.
CREATE TABLE IF NOT EXISTS mar_sales_channel (
  code       VARCHAR(10)  NOT NULL,
  label      VARCHAR(80)  NOT NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_lead_status (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20)     NOT NULL COMMENT 'todo | called | sent | ordered | dropped',
  label       VARCHAR(80)     NOT NULL,
  color_hex   CHAR(7)         NOT NULL,
  bg_hex      VARCHAR(40)     NOT NULL COMMENT 'Valeur CSS complète (rgba) — la maquette utilise de la transparence',
  border_hex  VARCHAR(40)     NOT NULL,
  sort_order  SMALLINT        NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_lead_status_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_b2b_sector (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                   VARCHAR(40)     NOT NULL,
  label                  VARCHAR(120)    NOT NULL,
  estimated_leads_count  INT UNSIGNED    NOT NULL DEFAULT 0,
  sort_order             SMALLINT        NOT NULL DEFAULT 0,
  is_active              TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_b2b_sector_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_b2b_option (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)     NOT NULL,
  label       VARCHAR(160)    NOT NULL,
  description VARCHAR(400)        NULL,
  sort_order  SMALLINT        NOT NULL DEFAULT 0,
  is_active   TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_b2b_option_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_agency (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160)    NOT NULL,
  speciality      VARCHAR(160)        NULL,
  main_lever_id   BIGINT UNSIGNED     NULL,
  avg_roi         DECIMAL(8,2)        NULL,
  hit_rate_pct    DECIMAL(5,2)        NULL,
  avg_cost_amount DECIMAL(12,2)       NULL,
  campaigns_count INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by      BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  KEY ix_mar_agency_lever (main_lever_id),
  CONSTRAINT fk_mar_agency_lever FOREIGN KEY (main_lever_id) REFERENCES mar_lever (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_agency_ask (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(40)     NOT NULL,
  label      VARCHAR(160)    NOT NULL,
  sort_order SMALLINT        NOT NULL DEFAULT 0,
  is_active  TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_agency_ask_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_agency_campaign (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agency_id   BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  channel_id  BIGINT UNSIGNED     NULL,
  fee_amount  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  roi_value   DECIMAL(8,2)        NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  KEY ix_mar_ac_agency (agency_id),
  KEY ix_mar_ac_campaign (campaign_id),
  CONSTRAINT fk_mar_ac_agency   FOREIGN KEY (agency_id)   REFERENCES mar_agency (id)   ON DELETE CASCADE,
  CONSTRAINT fk_mar_ac_campaign FOREIGN KEY (campaign_id) REFERENCES mar_campaign (id) ON DELETE CASCADE,
  CONSTRAINT fk_mar_ac_channel  FOREIGN KEY (channel_id)  REFERENCES mar_channel (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_crm_segment (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(40)     NOT NULL,
  label      VARCHAR(120)    NOT NULL,
  color_hex  CHAR(7)         NOT NULL DEFAULT '#8A847C',
  rule_json  JSON                NULL,
  sort_order SMALLINT        NOT NULL DEFAULT 0,
  is_active  TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_crm_segment_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_crm_lead (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id      BIGINT UNSIGNED NOT NULL,
  sector_id        BIGINT UNSIGNED     NULL,
  shop_id          BIGINT UNSIGNED     NULL COMMENT 'Boutique référente, responsable du contact',
  company_name     VARCHAR(200)    NOT NULL,
  contact_name     VARCHAR(160)        NULL,
  contact_email    VARCHAR(190)        NULL,
  contact_phone    VARCHAR(40)         NULL,
  size_label       VARCHAR(80)         NULL,
  potential_amount DECIMAL(12,2)       NULL,
  status_code      VARCHAR(20)     NOT NULL DEFAULT 'todo'
                   COMMENT 'État courant dénormalisé — l''historique vit dans mar_crm_lead_event',
  assigned_user_id BIGINT UNSIGNED     NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by       BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  KEY ix_mar_lead_campaign (campaign_id, status_code),
  KEY ix_mar_lead_shop (shop_id),
  KEY ix_mar_lead_sector (sector_id),
  CONSTRAINT fk_mar_lead_campaign FOREIGN KEY (campaign_id) REFERENCES mar_campaign (id)      ON DELETE CASCADE,
  CONSTRAINT fk_mar_lead_sector   FOREIGN KEY (sector_id)   REFERENCES mar_b2b_sector (id)    ON DELETE SET NULL,
  CONSTRAINT fk_mar_lead_shop     FOREIGN KEY (shop_id)     REFERENCES mar_shop (id)          ON DELETE SET NULL,
  CONSTRAINT fk_mar_lead_status   FOREIGN KEY (status_code) REFERENCES mar_lead_status (code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_b2b_prospect (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  brand_id         BIGINT UNSIGNED NOT NULL,
  sector_id        BIGINT UNSIGNED     NULL,
  -- Référence d'origine : c'est elle qui rend l'import rejouable. Réimporter
  -- le même fichier met à jour les comptes au lieu de les dupliquer.
  external_ref     VARCHAR(120)        NULL,
  company_name     VARCHAR(200)    NOT NULL,
  contact_name     VARCHAR(160)        NULL,
  contact_email    VARCHAR(190)        NULL,
  contact_phone    VARCHAR(40)         NULL,
  size_label       VARCHAR(80)         NULL COMMENT 'Volumétrie estimée (« ~450 couverts/sem »)',
  potential_amount DECIMAL(12,2)       NULL,
  city             VARCHAR(120)        NULL,
  postal_code      VARCHAR(20)         NULL,
  -- Boutique référente si elle est connue à l'import, sinon la génération
  -- répartit sur les boutiques de la campagne.
  shop_id          BIGINT UNSIGNED     NULL,
  source           VARCHAR(80)         NULL COMMENT 'Provenance du fichier ou du connecteur',
  is_active        TINYINT(1)      NOT NULL DEFAULT 1,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by       BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_b2b_prospect_ref (brand_id, external_ref),
  KEY ix_mar_b2b_prospect_sector (sector_id),
  KEY ix_mar_b2b_prospect_shop (shop_id),
  CONSTRAINT fk_mar_bp_brand  FOREIGN KEY (brand_id)  REFERENCES mar_brand (id)      ON DELETE CASCADE,
  CONSTRAINT fk_mar_bp_sector FOREIGN KEY (sector_id) REFERENCES mar_b2b_sector (id) ON DELETE SET NULL,
  CONSTRAINT fk_mar_bp_shop   FOREIGN KEY (shop_id)   REFERENCES mar_shop (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_b2b_prospect_sector (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prospect_id BIGINT UNSIGNED NOT NULL,
  sector_id   BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_bps (prospect_id, sector_id),
  KEY ix_mar_bps_sector (sector_id),
  CONSTRAINT fk_mar_bps_prospect FOREIGN KEY (prospect_id) REFERENCES mar_b2b_prospect (id) ON DELETE CASCADE,
  CONSTRAINT fk_mar_bps_sector   FOREIGN KEY (sector_id)   REFERENCES mar_b2b_sector (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_retroplanning_default (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label              VARCHAR(200)    NOT NULL,
  days_before_launch SMALLINT        NOT NULL,
  position_id        BIGINT UNSIGNED     NULL,
  sort_order         SMALLINT        NOT NULL DEFAULT 0,
  is_active          TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_mar_rd_position (position_id),
  CONSTRAINT fk_mar_rd_position FOREIGN KEY (position_id) REFERENCES mar_position (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_retroplanning_step (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id        BIGINT UNSIGNED NOT NULL,
  label              VARCHAR(200)    NOT NULL,
  days_before_launch SMALLINT        NOT NULL DEFAULT 0,
  position_id        BIGINT UNSIGNED     NULL,
  assignee_user_id   BIGINT UNSIGNED     NULL,
  done_at            DATETIME            NULL,
  sort_order         SMALLINT        NOT NULL DEFAULT 0,
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  KEY ix_mar_rs_campaign (campaign_id, sort_order),
  KEY ix_mar_rs_position (position_id),
  CONSTRAINT fk_mar_rs_campaign FOREIGN KEY (campaign_id) REFERENCES mar_campaign (id) ON DELETE CASCADE,
  CONSTRAINT fk_mar_rs_position FOREIGN KEY (position_id) REFERENCES mar_position (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_offer_template (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)     NOT NULL,
  label       VARCHAR(120)    NOT NULL,
  description VARCHAR(400)        NULL,
  sort_order  SMALLINT        NOT NULL DEFAULT 0,
  is_active   TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_offer_template_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_offer_template_item (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id   BIGINT UNSIGNED NOT NULL,
  offer_item_id BIGINT UNSIGNED NOT NULL,
  quantity      SMALLINT        NOT NULL DEFAULT 1,
  sort_order    SMALLINT        NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_oti (template_id, offer_item_id),
  KEY ix_mar_oti_item (offer_item_id),
  CONSTRAINT fk_mar_oti_template FOREIGN KEY (template_id)   REFERENCES mar_offer_template (id) ON DELETE CASCADE,
  CONSTRAINT fk_mar_oti_item     FOREIGN KEY (offer_item_id) REFERENCES mar_offer_item (id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_offer_item (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category      VARCHAR(20)     NOT NULL COMMENT 'produit | bundle | saison | promo | voucher',
  sku_ref       VARCHAR(80)         NULL COMMENT 'Référence produit ERP',
  name          VARCHAR(200)    NOT NULL,
  detail        VARCHAR(400)        NULL,
  price_amount  DECIMAL(12,2)       NULL,
  cost_amount   DECIMAL(12,2)       NULL,
  image_url     VARCHAR(500)        NULL,
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by    BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  KEY ix_mar_offer_item_cat (category),
  KEY ix_mar_offer_item_sku (sku_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_offer_item_season (
  item_id        BIGINT UNSIGNED NOT NULL COMMENT 'Référence produit du catalogue',
  season_item_id BIGINT UNSIGNED NOT NULL COMMENT 'Gamme saisonnière du catalogue',
  PRIMARY KEY (item_id, season_item_id),
  KEY ix_mar_ois_season (season_item_id),
  CONSTRAINT fk_mar_ois_item   FOREIGN KEY (item_id)        REFERENCES mar_offer_item (id) ON DELETE CASCADE,
  CONSTRAINT fk_mar_ois_season FOREIGN KEY (season_item_id) REFERENCES mar_offer_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mar_crm_lead
  ADD COLUMN prospect_id BIGINT UNSIGNED NULL
    COMMENT 'Compte du vivier à l''origine du lead'
    AFTER sector_id,
  ADD UNIQUE KEY uq_mar_crm_lead_prospect (campaign_id, prospect_id),
  ADD CONSTRAINT fk_mar_cl_prospect
    FOREIGN KEY (prospect_id) REFERENCES mar_b2b_prospect (id) ON DELETE SET NULL;

ALTER TABLE mar_b2b_prospect
  MODIFY COLUMN postal_code VARCHAR(40) NULL,
  MODIFY COLUMN contact_phone VARCHAR(80) NULL,
  MODIFY COLUMN size_label VARCHAR(160) NULL;

ALTER TABLE mar_b2b_prospect
  DROP FOREIGN KEY fk_mar_bp_sector,
  DROP COLUMN sector_id;

ALTER TABLE mar_b2b_sector
  ADD COLUMN erp_type_id BIGINT UNSIGNED NULL
    COMMENT 'Type de compte B2B d''origine, côté ERP'
    AFTER code,
  ADD UNIQUE KEY uq_mar_b2b_sector_erp (erp_type_id);

ALTER TABLE mar_offer_item
  DROP INDEX ix_mar_offer_item_sku,
  ADD UNIQUE KEY uq_mar_offer_item_sku (sku_ref);

INSERT INTO mar_client_target (code, label, sort_order) VALUES
  ('b2c',   'B2C — particuliers',    1),
  ('b2b',   'B2B — professionnels',  2),
  ('mixte', 'Mixte B2C + B2B',       3);

INSERT INTO mar_cost_kind (code, label, sort_order) VALUES
  ('MEDIA',      'Achat média',             1),
  ('PRODUCTION', 'Production & impression', 2),
  ('AGENCE',     'Honoraires agence',       3),
  ('REMISE',     'Remises accordées',       4),
  ('LOGISTIQUE', 'Logistique',              5),
  ('AUTRE',      'Autres',                  6);

INSERT INTO mar_promotion_mechanic (code, label, sort_order) VALUES
  ('PERCENT',       'Pourcentage',        1),
  ('BUNDLE_FIXED',  'Prix de formule',    2),
  ('BUY_X_GET_Y',   'Offre liée',         3),
  ('CROSSED_PRICE', 'Prix barré',         4),
  ('FREE_DELIVERY', 'Livraison offerte',  5);

INSERT INTO mar_review_platform (code, label, sort_order) VALUES
  ('GOOGLE',      'Google Business', 1),
  ('FACEBOOK',    'Facebook',        2),
  ('INSTAGRAM',   'Instagram',       3),
  ('TRIPADVISOR', 'Tripadvisor',     4),
  ('DELIVEROO',   'Deliveroo',       5),
  ('UBEREATS',    'Uber Eats',       6);

INSERT INTO mar_sales_channel (code, label, sort_order) VALUES
  ('WS',  'Web shop', 1),
  ('POS', 'Caisse',   2),
  ('OFF', 'Office',   3),
  ('B2B', 'B2B',      4);

INSERT INTO mar_client_target (code, label, sort_order)
SELECT DISTINCT c.client_target, c.client_target, 99
  FROM mar_campaign c
 WHERE c.client_target NOT IN (SELECT code FROM mar_client_target);

INSERT INTO mar_pos_answer_type (code, label, hint, sort_order) VALUES
  ('yes_no',  'Oui / Non',        'Réponse binaire, la plus rapide en caisse',      1),
  ('choice',  'Choix dans une liste', 'Les propositions se saisissent dans la question', 2),
  ('rating',  'Note sur 5',       'Échelle courte, comparable d''une campagne à l''autre', 3),
  ('number',  'Nombre',           'Quantité, âge, nombre de convives',                4),
  ('text',    'Réponse libre',    'À réserver aux questions ouvertes : long à saisir', 5);

INSERT INTO mar_b2b_prospect_sector (prospect_id, sector_id)
SELECT id, sector_id FROM mar_b2b_prospect WHERE sector_id IS NOT NULL;

INSERT INTO mar_lead_status (code, label, color_hex, bg_hex, border_hex, sort_order) VALUES
  ('todo',    'À appeler',       '#8a6d0f', 'rgba(216,170,30,.18)', 'rgba(216,170,30,.55)', 1),
  ('called',  'Contacté · reçu', '#2b6a8f', 'rgba(43,106,143,.13)', 'rgba(43,106,143,.4)',  2),
  ('sent',    'Offre envoyée',   '#b26a00', 'rgba(200,120,20,.15)', 'rgba(200,120,20,.45)', 3),
  ('ordered', 'Commande passée', '#3f7a52', 'rgba(63,122,82,.15)',  'rgba(63,122,82,.45)',  4),
  ('dropped', 'Sans suite',      '#8A847C', 'rgba(34,34,34,.06)',   'rgba(34,34,34,.16)',   5);

INSERT INTO mar_b2b_sector (code, label, estimated_leads_count, sort_order) VALUES
  ('offices',       'Offices & entreprises', 184, 1),
  ('ecoles',        'Écoles & universités',   62, 2),
  ('horeca',        'Horeca',                 97, 3),
  ('evenementiel',  'Événementiel',           41, 4),
  ('administrations','Administrations',       38, 5),
  ('sante',         'Santé & hôpitaux',       29, 6);

INSERT INTO mar_format (code, name, width_px, height_px, note, sort_order) VALUES
  ('landing',   'Landing page',    800,  800, 'Carré site campagne', 1),
  ('pwa',       'PWA',            1080, 1920, 'Écran mobile plein',  2),
  ('fb_post',   'Post Facebook',  1200,  630, 'Fil d''actualité',    3),
  ('ig_post',   'Post Instagram', 1080, 1080, 'Carré feed',          4),
  ('fb_header', 'Header Facebook', 820,  312, 'Couverture de page',  5);

INSERT INTO mar_position (code, label, sort_order) VALUES
  ('chef_projet',  'Chef de projet marketing', 1),
  ('dir_artistique','Directeur artistique',    2),
  ('production',   'Chargé de production',     3),
  ('consultant_digital','Consultant digital',  4),
  ('community',    'Community manager',        5);

INSERT INTO mar_offer_template (code, label, description, sort_order) VALUES
  ('menu',       'Menu complet',      'Bundle repas + boisson + dessert',        1),
  ('prixbarre',  'Prix barré',        'Produit phare à prix réduit',             2),
  ('decouverte', 'Bundle découverte', 'Nouveauté + best-seller',                 3),
  ('office',     'Offre office B2B',  'Plateau + livraison + voucher B2B',       4);

INSERT INTO mar_uniform (code, name, description, icon_path, sort_order) VALUES
  ('tablier',  'Tablier brandé été',       'Tablier lin coréen aux couleurs de la saison',    'M8 3h8l-1 4a4 4 0 0 1-6 0zM7 21v-7a5 5 0 0 1 10 0v7z', 1),
  ('couronne', 'Couronne Galette des Rois','Couronne carton dorée portée en boutique',        'M4 18h16l-1-9-4 4-3-6-3 6-4-4z', 2),
  ('chapeau',  'Chapeau de Noël',          'Bonnet rouge équipe pendant les fêtes',           'M4 20c2-7 5-11 8-15 5 3 7 9 8 15z', 3),
  ('tshirt',   'T-shirt événement',        'T-shirt co-brandé (partenariats, portes ouvertes)','M4 7l4-3 4 2 4-2 4 3-2 3-2-1v9H8v-9L6 10z', 4),
  ('badge',    'Badge / pin''s thématique','Épinglette message campagne sur la tenue',        'M12 3a4 4 0 0 1 4 4c0 3-4 8-4 8s-4-5-4-8a4 4 0 0 1 4-4z', 5);

INSERT INTO mar_channel (code, label, family, sort_order) VALUES
  ('meta_ads',    'Meta Ads',            'DIGITAL',  1),
  ('google_local','Google Search local', 'DIGITAL',  2),
  ('email',       'Email',               'DIGITAL',  3),
  ('sms',         'SMS',                 'DIGITAL',  4),
  ('pwa_push',    'Notification PWA',    'DIGITAL',  5),
  ('plv',         'PLV boutique',        'PHYSIQUE', 6),
  ('affichage',   'Affichage extérieur', 'PHYSIQUE', 7),
  ('flyer',       'Flyer / toutes-boîtes','PHYSIQUE',8);

INSERT INTO mar_crm_segment (code, label, color_hex, sort_order) VALUES
  ('nouveaux',  'Nouveaux clients',  '#4A6D8C', 1),
  ('reguliers', 'Clients réguliers', '#6B8E5A', 2),
  ('vip',       'VIP',               '#8D1D2C', 3),
  ('dormants',  'Dormants',          '#b26a00', 4),
  ('perdus',    'Perdus',            '#8A847C', 5);

INSERT INTO mar_tone (code, label, sort_order) VALUES
  ('gourmand',   'Gourmand',   1),
  ('festif',     'Festif',     2),
  ('premium',    'Premium',    3),
  ('convivial',  'Convivial',  4),
  ('chaleureux', 'Chaleureux', 5);

INSERT INTO mar_agency_ask (code, label, sort_order) VALUES
  ('cobranding',  'Collaboration / co-branding',  1),
  ('influenceurs','Influenceurs locaux',          2),
  ('shooting',    'Shooting photo produits',      3),
  ('jeu',         'Jeu concours',                 4),
  ('presse',      'Relations presse locale',      5),
  ('video',       'Vidéo réseaux sociaux',        6);

INSERT INTO mar_b2b_option (code, label, description, sort_order) VALUES
  ('page',        'Page boutique co-brandée',      'Logo du client + habillage campagne sur son espace WS', 1),
  ('catalogue',   'Catalogue restreint',           'Assortiment dédié (plateaux office, formules réunion)', 2),
  ('tarifs',      'Tarifs négociés',               'Grille B2B + remise volume appliquée automatiquement',  3),
  ('code',        'Code d''accès entreprise',      'Accès réservé aux collaborateurs (SSO / code)',         4),
  ('facturation', 'Facturation centralisée',       'Commande groupée, facture mensuelle unique',            5),
  ('livraison',   'Créneaux de livraison dédiés',  'Plages réservées au site du grand compte',              6);

INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Brief agence', 30, p.id, 1 FROM mar_position p WHERE p.label = 'Chef de projet marketing';

INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Validation créa (BAT)', 21, p.id, 2 FROM mar_position p WHERE p.label = 'Directeur artistique';

INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Production physique', 15, p.id, 3 FROM mar_position p WHERE p.label = 'Chargé de production';

INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Mise en ligne digitale', 5, p.id, 4 FROM mar_position p WHERE p.label = 'Consultant digital';

INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Go live', 0, p.id, 5 FROM mar_position p WHERE p.label = 'Chef de projet marketing';

-- =============================================================================
-- Périmètre & rendus — le reste du chemin de création de campagne.
--
-- mar_shop_user : rattachement utilisateur → boutique. Sans elle, un appelant
-- non-admin fait tomber Scope::shopIds() en erreur au premier écran.
-- mar_asset_render : rendus par format d'un visuel de campagne, écrits dès
-- l'enregistrement de l'étape Communication de l'assistant.
-- =============================================================================

CREATE TABLE IF NOT EXISTS mar_shop_user (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL COMMENT 'Utilisateur du SI hôte',
  shop_id     BIGINT UNSIGNED NOT NULL,
  role        VARCHAR(20)     NOT NULL COMMENT 'BRAND_ADMIN | FRANCHISEE | SHOP_STAFF',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_shop_user (user_id, shop_id),
  KEY ix_mar_shop_user_shop (shop_id),
  CONSTRAINT fk_mar_shop_user_shop FOREIGN KEY (shop_id) REFERENCES mar_shop (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bons et codes. Le détail d'une campagne fait un LEFT JOIN dessus (l'offre
-- peut porter un bon) : sans la table, GET /campaigns/{id} — et donc la
-- suppression, qui relit la campagne — tombe en erreur.
CREATE TABLE IF NOT EXISTS mar_voucher (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id       BIGINT UNSIGNED     NULL,
  code              VARCHAR(60)     NOT NULL,
  mechanic_label    VARCHAR(200)        NULL,
  scope_label       VARCHAR(120)        NULL,
  usage_limit_label VARCHAR(80)         NULL,
  status            VARCHAR(20)     NOT NULL DEFAULT 'Actif',
  source            VARCHAR(40)         NULL COMMENT 'partner | office_referral | …',
  partner_name      VARCHAR(160)        NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by        BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_voucher_code (code),
  KEY ix_mar_voucher_campaign (campaign_id),
  CONSTRAINT fk_mar_voucher_campaign FOREIGN KEY (campaign_id) REFERENCES mar_campaign (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique d'un lead : écrit à chaque changement d'état depuis la fiche
-- campagne. Vide après restauration — l'historique d'avant est perdu.
CREATE TABLE IF NOT EXISTS mar_crm_lead_event (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id     BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(20)         NULL,
  to_status   VARCHAR(20)         NULL,
  event_type  VARCHAR(20)     NOT NULL COMMENT 'CALL | OFFER_SENT | ORDER | NOTE',
  note        TEXT                NULL,
  occurred_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id     BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  KEY ix_mar_lead_event_lead (lead_id, occurred_at),
  CONSTRAINT fk_mar_cle_lead FOREIGN KEY (lead_id) REFERENCES mar_crm_lead (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CA mensuel par boutique : lu par le résumé par levier (pénétration). Vide
-- après restauration, la pénétration s'affiche « — » tant que rien n'est saisi.
CREATE TABLE IF NOT EXISTS mar_shop_revenue (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_id        BIGINT UNSIGNED NOT NULL,
  period_month   DATE            NOT NULL COMMENT 'Premier jour du mois concerné',
  revenue_amount DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_shop_revenue (shop_id, period_month),
  CONSTRAINT fk_mar_sr_shop FOREIGN KEY (shop_id) REFERENCES mar_shop (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mar_asset_render (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_asset_id BIGINT UNSIGNED NOT NULL,
  format_id         BIGINT UNSIGNED NOT NULL,
  file_url          VARCHAR(500)        NULL,
  override_file_url VARCHAR(500)        NULL,
  status            VARCHAR(20)     NOT NULL DEFAULT 'pending',
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mar_asset_render (campaign_asset_id, format_id),
  KEY ix_mar_ar_format (format_id),
  CONSTRAINT fk_mar_ar_asset  FOREIGN KEY (campaign_asset_id) REFERENCES mar_campaign_asset (id) ON DELETE CASCADE,
  CONSTRAINT fk_mar_ar_format FOREIGN KEY (format_id)         REFERENCES mar_format (id)         ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Vues de lecture. CREATE OR REPLACE : rejouables sans condition. Seules les
-- vues dont TOUTES les tables sous-jacentes existent encore sont recréées —
-- mar_v_roi_quarterly reste écartée (elle exige mar_roi_cost, hors périmètre).
-- =============================================================================

CREATE OR REPLACE VIEW mar_v_fund_ledger_by_period AS
SELECT
  fm.id,
  fm.movement_date,
  DATE_FORMAT(fm.movement_date, '%Y-%m-01')            AS period_month,
  CONCAT(YEAR(fm.movement_date), '-T', QUARTER(fm.movement_date)) AS period_quarter,
  YEAR(fm.movement_date)                                AS period_year,
  fm.direction,
  fm.label,
  fm.amount,
  CASE WHEN fm.direction = 'IN' THEN fm.amount ELSE -fm.amount END AS signed_amount,
  fm.source,
  fm.supplier_name,
  fm.document_ref,
  fm.shop_id,
  s.name                                                AS shop_name,
  fm.campaign_id,
  c.name                                                AS campaign_name,
  fm.lever_id,
  l.code                                                AS lever_code,
  l.label                                               AS lever_label,
  l.color_hex                                           AS lever_color_hex
FROM mar_fund_movement fm
LEFT JOIN mar_shop     s ON s.id = fm.shop_id
LEFT JOIN mar_campaign c ON c.id = fm.campaign_id
LEFT JOIN mar_lever    l ON l.id = fm.lever_id;

CREATE OR REPLACE VIEW mar_v_lever_performance AS
SELECT
  l.id                                    AS lever_id,
  l.code                                  AS lever_code,
  l.label                                 AS lever_label,
  l.color_hex,
  COALESCE(spend.spent_amount, 0)         AS spent_amount,
  COALESCE(target.target_value, 0)        AS target_value,
  COALESCE(target.actual_value, 0)        AS actual_value,
  CASE
    WHEN COALESCE(spend.spent_amount, 0) = 0 THEN NULL
    ELSE ROUND(COALESCE(target.actual_value, 0) / spend.spent_amount, 2)
  END                                     AS roi_value
FROM mar_lever l
LEFT JOIN (
  SELECT lever_id, SUM(amount) AS spent_amount
  FROM mar_fund_movement
  WHERE direction = 'OUT' AND lever_id IS NOT NULL
  GROUP BY lever_id
) spend ON spend.lever_id = l.id
LEFT JOIN (
  SELECT clt.lever_id, SUM(clt.target_value) AS target_value, SUM(clt.actual_value) AS actual_value
  FROM mar_campaign_lever_target clt
  JOIN mar_campaign c ON c.id = clt.campaign_id AND c.status_code <> 'draft'
  GROUP BY clt.lever_id
) target ON target.lever_id = l.id;

CREATE OR REPLACE VIEW mar_v_campaign_monitor AS
SELECT
  k.campaign_id,
  k.shop_id,
  s.name        AS shop_name,
  k.kpi_code,
  k.kpi_label,
  k.value,
  k.target_value,
  k.unit,
  k.measured_at,
  CASE
    WHEN k.target_value IS NULL OR k.target_value = 0 THEN NULL
    ELSE ROUND(k.value / k.target_value * 100, 1)
  END           AS attainment_pct
FROM mar_campaign_kpi_snapshot k
LEFT JOIN mar_shop s ON s.id = k.shop_id
WHERE k.measured_at = (
  SELECT MAX(k2.measured_at)
  FROM mar_campaign_kpi_snapshot k2
  WHERE k2.campaign_id = k.campaign_id
    AND k2.kpi_code    = k.kpi_code
    AND (k2.shop_id = k.shop_id OR (k2.shop_id IS NULL AND k.shop_id IS NULL))
);

CREATE OR REPLACE VIEW mar_v_lead_funnel AS
SELECT
  c.id          AS campaign_id,
  st.code       AS status_code,
  st.label      AS status_label,
  st.color_hex,
  st.bg_hex,
  st.border_hex,
  st.sort_order,
  COUNT(ld.id)  AS leads_count
FROM mar_campaign c
CROSS JOIN mar_lead_status st
LEFT JOIN mar_crm_lead ld
       ON ld.campaign_id = c.id
      AND ld.status_code = st.code
GROUP BY c.id, st.code, st.label, st.color_hex, st.bg_hex, st.border_hex, st.sort_order;

CREATE OR REPLACE VIEW mar_v_lead_funnel_by_shop AS
SELECT
  cs.campaign_id,
  cs.shop_id,
  s.name        AS shop_name,
  st.code       AS status_code,
  st.label      AS status_label,
  st.color_hex,
  st.sort_order,
  COUNT(ld.id)  AS leads_count
FROM mar_campaign_shop cs
JOIN mar_shop s ON s.id = cs.shop_id
CROSS JOIN mar_lead_status st
LEFT JOIN mar_crm_lead ld
       ON ld.campaign_id = cs.campaign_id
      AND ld.shop_id     = cs.shop_id
      AND ld.status_code = st.code
GROUP BY cs.campaign_id, cs.shop_id, s.name, st.code, st.label, st.color_hex, st.sort_order;
