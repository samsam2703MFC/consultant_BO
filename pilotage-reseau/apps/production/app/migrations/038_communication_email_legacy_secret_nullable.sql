-- Migration 036 introduced plaintext secret columns, but the legacy columns from
-- migration 030 were still NOT NULL. They are no longer read by the application.
ALTER TABLE communication_email_account
    MODIFY COLUMN smtp_secret_encrypted TEXT NULL,
    MODIFY COLUMN imap_secret_encrypted TEXT NULL;
