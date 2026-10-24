-- Gmail pilot configuration and durable email delivery/audit.
CREATE TABLE IF NOT EXISTS communication_email_account (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    from_name VARCHAR(160) NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    reply_to_email VARCHAR(255) NOT NULL,
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    smtp_encryption ENUM('tls','ssl') NOT NULL DEFAULT 'tls',
    smtp_username VARCHAR(255) NOT NULL,
    smtp_secret_encrypted TEXT NOT NULL,
    imap_host VARCHAR(255) NOT NULL,
    imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    imap_username VARCHAR(255) NOT NULL,
    imap_secret_encrypted TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_tested_at DATETIME NULL,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_communication_email_account_from (from_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE communication_message
    ADD COLUMN id_proforma INT UNSIGNED NULL AFTER id_wdt,
    ADD COLUMN id_email_account INT UNSIGNED NULL AFTER id_proforma,
    ADD COLUMN sender_email VARCHAR(255) NULL AFTER recipient_email,
    ADD COLUMN reply_to_email VARCHAR(255) NULL AFTER sender_email,
    ADD COLUMN message_id_header VARCHAR(255) NULL AFTER provider_message_id,
    ADD COLUMN queued_at DATETIME NULL AFTER created_at,
    ADD COLUMN locked_at DATETIME NULL AFTER queued_at,
    ADD COLUMN locked_by VARCHAR(160) NULL AFTER locked_at,
    ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER locked_by,
    ADD COLUMN next_try_at DATETIME NULL AFTER attempts,
    ADD UNIQUE KEY uq_communication_message_id_header (message_id_header),
    ADD KEY idx_communication_message_delivery (status, next_try_at, locked_at);

ALTER TABLE communication_message
    MODIFY COLUMN status ENUM('draft','queued','processing','sent','failed') NOT NULL DEFAULT 'draft';

CREATE TABLE IF NOT EXISTS communication_message_attachment (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_message BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    content LONGBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_communication_attachment_message FOREIGN KEY (id_message) REFERENCES communication_message(id) ON DELETE CASCADE,
    KEY idx_communication_attachment_message (id_message)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_message_attempt (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_message BIGINT UNSIGNED NOT NULL,
    attempt_no INT UNSIGNED NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    provider_message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NOT NULL,
    CONSTRAINT fk_communication_attempt_message FOREIGN KEY (id_message) REFERENCES communication_message(id) ON DELETE CASCADE,
    UNIQUE KEY uq_communication_attempt (id_message, attempt_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_incoming_message (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_email_account INT UNSIGNED NOT NULL,
    id_message BIGINT UNSIGNED NULL,
    imap_uid BIGINT UNSIGNED NOT NULL,
    uid_validity VARCHAR(64) NOT NULL,
    sender_email VARCHAR(255) NOT NULL,
    subject_text VARCHAR(255) NULL,
    body_text MEDIUMTEXT NOT NULL,
    message_id_header VARCHAR(255) NULL,
    in_reply_to_header VARCHAR(255) NULL,
    received_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_communication_incoming_uid (id_email_account, uid_validity, imap_uid),
    KEY idx_communication_incoming_thread (id_message, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
