-- Replaces runtime encryption of SMTP/IMAP application passwords.
-- Existing encrypted columns are retained only for historical compatibility and are no longer read.
-- After this migration, open the mail configuration screen and enter both passwords again.
ALTER TABLE communication_email_account
    ADD COLUMN smtp_secret TEXT NULL AFTER smtp_secret_encrypted,
    ADD COLUMN imap_secret TEXT NULL AFTER imap_secret_encrypted;
