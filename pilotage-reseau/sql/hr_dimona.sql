-- Module RH / Dimona — tables hr_dimona_* (version 1).
-- Généré par : php bin/hr_dimona_schema.php > sql/hr_dimona.sql — ne pas modifier à la main.
-- Créées aussi automatiquement au premier appel de l'API (Hr\Support\Schema::ensure).

CREATE TABLE IF NOT EXISTS hr_dimona_meta (
  k VARCHAR(64) NOT NULL,
  v TEXT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_dimona_employers (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  enterprise_number CHAR(10) NOT NULL,
  onss_employer_id BIGINT NULL,
  joint_commission VARCHAR(10) NULL,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY ux_hr_dimona_employers_enterprise_number (enterprise_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_dimona_employer_shops (
  shop_id VARCHAR(20) NOT NULL,
  employer_id BIGINT NOT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (shop_id),
  KEY ix_hr_dimona_employer_shops_employer_id (employer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_dimona_credentials (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employer_id BIGINT NOT NULL,
  env VARCHAR(4) NOT NULL,
  client_id VARCHAR(128) NOT NULL,
  private_key_encrypted TEXT NOT NULL,
  certificate_pem TEXT NOT NULL,
  certificate_subject VARCHAR(255) NULL,
  certificate_fingerprint CHAR(64) NULL,
  certificate_expires_at DATETIME NULL,
  is_active TINYINT NOT NULL DEFAULT 1,
  last_test_at DATETIME NULL,
  last_test_ok TINYINT NULL,
  last_test_message VARCHAR(400) NULL,
  expiry_alert_at DATETIME NULL,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  KEY ix_hr_dimona_credentials_employer_id_env_is_active (employer_id, env, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_dimona_tokens (
  credential_id BIGINT NOT NULL,
  token_encrypted TEXT NOT NULL,
  expires_at BIGINT NOT NULL,
  obtained_at BIGINT NOT NULL,
  PRIMARY KEY (credential_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_dimona_workers (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employer_id BIGINT NOT NULL,
  erp_employee_id BIGINT NULL,
  shop_id VARCHAR(20) NULL,
  given_name VARCHAR(48) NOT NULL,
  family_name VARCHAR(128) NOT NULL,
  ssin_encrypted TEXT NULL,
  ssin_hash CHAR(64) NULL,
  birth_date DATE NULL,
  worker_type VARCHAR(3) NULL,
  joint_commission VARCHAR(10) NULL,
  planned_hours INT NULL,
  created_by VARCHAR(64) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY ux_hr_dimona_workers_employer_id_ssin_hash (employer_id, ssin_hash),
  UNIQUE KEY ux_hr_dimona_workers_employer_id_erp_employee_id (employer_id, erp_employee_id),
  KEY ix_hr_dimona_workers_shop_id (shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_dimona_declarations (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employer_id BIGINT NOT NULL,
  worker_id BIGINT NOT NULL,
  erp_employee_id BIGINT NULL,
  credential_id BIGINT NULL,
  env VARCHAR(4) NOT NULL,
  type VARCHAR(6) NOT NULL,
  request_payload MEDIUMTEXT NOT NULL,
  request_encrypted MEDIUMTEXT NULL,
  dimona_declaration_id BIGINT NULL,
  dimona_period_id BIGINT NULL,
  status VARCHAR(16) NOT NULL,
  result_code CHAR(1) NULL,
  result_payload MEDIUMTEXT NULL,
  anomalies MEDIUMTEXT NULL,
  http_status INT NULL,
  last_error VARCHAR(500) NULL,
  attempts INT NOT NULL DEFAULT 0,
  next_poll_at DATETIME NULL,
  submitted_at DATETIME NULL,
  resolved_at DATETIME NULL,
  submitted_by_user_id VARCHAR(64) NULL,
  submitted_by_name VARCHAR(120) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY ux_hr_dimona_declarations_dimona_declaration_id (dimona_declaration_id),
  KEY ix_hr_dimona_declarations_status_next_poll_at (status, next_poll_at),
  KEY ix_hr_dimona_declarations_employer_id (employer_id),
  KEY ix_hr_dimona_declarations_worker_id (worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_dimona_periods (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employer_id BIGINT NOT NULL,
  worker_id BIGINT NOT NULL,
  dimona_period_id BIGINT NOT NULL,
  start_date DATE NOT NULL,
  start_hour CHAR(4) NULL,
  end_date DATE NULL,
  end_hour CHAR(4) NULL,
  worker_type VARCHAR(3) NULL,
  joint_commission VARCHAR(3) NULL,
  planned_hours INT NULL,
  status VARCHAR(10) NOT NULL,
  source VARCHAR(10) NOT NULL,
  last_declaration_id BIGINT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY ux_hr_dimona_periods_dimona_period_id (dimona_period_id),
  KEY ix_hr_dimona_periods_worker_id_status (worker_id, status),
  KEY ix_hr_dimona_periods_employer_id (employer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_dimona_audit (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  at DATETIME NULL,
  user_id VARCHAR(64) NULL,
  user_name VARCHAR(120) NULL,
  role VARCHAR(16) NULL,
  action VARCHAR(48) NOT NULL,
  employer_id BIGINT NULL,
  target_type VARCHAR(24) NULL,
  target_id VARCHAR(64) NULL,
  detail VARCHAR(500) NULL,
  ip VARCHAR(45) NULL,
  KEY ix_hr_dimona_audit_employer_id_at (employer_id, at),
  KEY ix_hr_dimona_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
