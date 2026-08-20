-- Référentiels de l'assistant de campagne, repris du dépôt marketing
-- (migrations + seeds). Rejoués tels quels — CREATE TABLE IF NOT EXISTS
-- pour pouvoir repasser sans casser.
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

CREATE TABLE IF NOT EXISTS mar_cost_kind (
  code       VARCHAR(20)  NOT NULL,
  label      VARCHAR(80)  NOT NULL,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
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

-- 020_reprise_produits.sql
ALTER TABLE mar_offer_item
  DROP INDEX ix_mar_offer_item_sku,
  ADD UNIQUE KEY uq_mar_offer_item_sku (sku_ref);

-- 014_referentiels_libelles.sql (mar_client_target)
INSERT INTO mar_client_target (code, label, sort_order) VALUES
  ('b2c',   'B2C — particuliers',    1),
  ('b2b',   'B2B — professionnels',  2),
  ('mixte', 'Mixte B2C + B2B',       3);

-- 014_referentiels_libelles.sql (mar_cost_kind)
INSERT INTO mar_cost_kind (code, label, sort_order) VALUES
  ('MEDIA',      'Achat média',             1),
  ('PRODUCTION', 'Production & impression', 2),
  ('AGENCE',     'Honoraires agence',       3),
  ('REMISE',     'Remises accordées',       4),
  ('LOGISTIQUE', 'Logistique',              5),
  ('AUTRE',      'Autres',                  6);

-- 014_referentiels_libelles.sql (mar_promotion_mechanic)
INSERT INTO mar_promotion_mechanic (code, label, sort_order) VALUES
  ('PERCENT',       'Pourcentage',        1),
  ('BUNDLE_FIXED',  'Prix de formule',    2),
  ('BUY_X_GET_Y',   'Offre liée',         3),
  ('CROSSED_PRICE', 'Prix barré',         4),
  ('FREE_DELIVERY', 'Livraison offerte',  5);

-- 014_referentiels_libelles.sql (mar_client_target)
INSERT INTO mar_client_target (code, label, sort_order)
SELECT DISTINCT c.client_target, c.client_target, 99
  FROM mar_campaign c
 WHERE c.client_target NOT IN (SELECT code FROM mar_client_target);

-- 017_questionnaire_pos.sql (mar_pos_answer_type)
INSERT INTO mar_pos_answer_type (code, label, hint, sort_order) VALUES
  ('yes_no',  'Oui / Non',        'Réponse binaire, la plus rapide en caisse',      1),
  ('choice',  'Choix dans une liste', 'Les propositions se saisissent dans la question', 2),
  ('rating',  'Note sur 5',       'Échelle courte, comparable d''une campagne à l''autre', 3),
  ('number',  'Nombre',           'Quantité, âge, nombre de convives',                4),
  ('text',    'Réponse libre',    'À réserver aux questions ouvertes : long à saisir', 5);

-- 001_referentiels.sql (mar_format)
INSERT INTO mar_format (code, name, width_px, height_px, note, sort_order) VALUES
  ('landing',   'Landing page',    800,  800, 'Carré site campagne', 1),
  ('pwa',       'PWA',            1080, 1920, 'Écran mobile plein',  2),
  ('fb_post',   'Post Facebook',  1200,  630, 'Fil d''actualité',    3),
  ('ig_post',   'Post Instagram', 1080, 1080, 'Carré feed',          4),
  ('fb_header', 'Header Facebook', 820,  312, 'Couverture de page',  5);

-- 001_referentiels.sql (mar_position)
INSERT INTO mar_position (code, label, sort_order) VALUES
  ('chef_projet',  'Chef de projet marketing', 1),
  ('dir_artistique','Directeur artistique',    2),
  ('production',   'Chargé de production',     3),
  ('consultant_digital','Consultant digital',  4),
  ('community',    'Community manager',        5);

-- 001_referentiels.sql (mar_offer_template)
INSERT INTO mar_offer_template (code, label, description, sort_order) VALUES
  ('menu',       'Menu complet',      'Bundle repas + boisson + dessert',        1),
  ('prixbarre',  'Prix barré',        'Produit phare à prix réduit',             2),
  ('decouverte', 'Bundle découverte', 'Nouveauté + best-seller',                 3),
  ('office',     'Offre office B2B',  'Plateau + livraison + voucher B2B',       4);

-- 001_referentiels.sql (mar_uniform)
INSERT INTO mar_uniform (code, name, description, icon_path, sort_order) VALUES
  ('tablier',  'Tablier brandé été',       'Tablier lin coréen aux couleurs de la saison',    'M8 3h8l-1 4a4 4 0 0 1-6 0zM7 21v-7a5 5 0 0 1 10 0v7z', 1),
  ('couronne', 'Couronne Galette des Rois','Couronne carton dorée portée en boutique',        'M4 18h16l-1-9-4 4-3-6-3 6-4-4z', 2),
  ('chapeau',  'Chapeau de Noël',          'Bonnet rouge équipe pendant les fêtes',           'M4 20c2-7 5-11 8-15 5 3 7 9 8 15z', 3),
  ('tshirt',   'T-shirt événement',        'T-shirt co-brandé (partenariats, portes ouvertes)','M4 7l4-3 4 2 4-2 4 3-2 3-2-1v9H8v-9L6 10z', 4),
  ('badge',    'Badge / pin''s thématique','Épinglette message campagne sur la tenue',        'M12 3a4 4 0 0 1 4 4c0 3-4 8-4 8s-4-5-4-8a4 4 0 0 1 4-4z', 5);

-- 001_referentiels.sql (mar_channel)
INSERT INTO mar_channel (code, label, family, sort_order) VALUES
  ('meta_ads',    'Meta Ads',            'DIGITAL',  1),
  ('google_local','Google Search local', 'DIGITAL',  2),
  ('email',       'Email',               'DIGITAL',  3),
  ('sms',         'SMS',                 'DIGITAL',  4),
  ('pwa_push',    'Notification PWA',    'DIGITAL',  5),
  ('plv',         'PLV boutique',        'PHYSIQUE', 6),
  ('affichage',   'Affichage extérieur', 'PHYSIQUE', 7),
  ('flyer',       'Flyer / toutes-boîtes','PHYSIQUE',8);

-- 004_assistant.sql (mar_tone)
INSERT INTO mar_tone (code, label, sort_order) VALUES
  ('gourmand',   'Gourmand',   1),
  ('festif',     'Festif',     2),
  ('premium',    'Premium',    3),
  ('convivial',  'Convivial',  4),
  ('chaleureux', 'Chaleureux', 5);

-- 004_assistant.sql (mar_retroplanning_default)
INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Brief agence', 30, p.id, 1 FROM mar_position p WHERE p.label = 'Chef de projet marketing';

-- 004_assistant.sql (mar_retroplanning_default)
INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Validation créa (BAT)', 21, p.id, 2 FROM mar_position p WHERE p.label = 'Directeur artistique';

-- 004_assistant.sql (mar_retroplanning_default)
INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Production physique', 15, p.id, 3 FROM mar_position p WHERE p.label = 'Chargé de production';

-- 004_assistant.sql (mar_retroplanning_default)
INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Mise en ligne digitale', 5, p.id, 4 FROM mar_position p WHERE p.label = 'Consultant digital';

-- 004_assistant.sql (mar_retroplanning_default)
INSERT INTO mar_retroplanning_default (label, days_before_launch, position_id, sort_order)
SELECT 'Go live', 0, p.id, 5 FROM mar_position p WHERE p.label = 'Chef de projet marketing';
