-- [313] SMS przez SMSAPI (ta sama bramka co CRM / Cockpit : POST {base}/sms.do, token OAuth z uprawnieniem « sms »)
-- i jeden dziennik wszystkich SMS-ów ERP — źródło historii wysyłek na stronie klienta (e-maile + SMS).
-- Token : SMSAPI_TOKEN w .env, albo erp_cles (smsapi_token, portal commun), albo Ustawienia › SMS (n_app_secret, szyfrowany).
CREATE TABLE IF NOT EXISTS n_sms_log (
    id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_customer  INT NULL COMMENT 'customer.id (SMS do klienta) ; NULL : SMS do pracownika',
    context      VARCHAR(30) NOT NULL COMMENT 'delivery_notice, payment_reminder, hr_note, test',
    ref_id       INT NULL COMMENT 'n_delivery_notice.id / document_proforma.id / n_hr_note.id',
    recipient    VARCHAR(40) NOT NULL,
    body         VARCHAR(1000) NOT NULL,
    status       VARCHAR(16) NOT NULL COMMENT 'sent (wysłany), logged (zapis bez wysyłki), failed',
    provider     VARCHAR(40) NULL,
    provider_id  VARCHAR(64) NULL COMMENT 'identyfikator SMSAPI',
    error        VARCHAR(255) NULL,
    created_by   VARCHAR(160) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sms_log_customer (id_customer, created_at),
    KEY idx_sms_log_context (context, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dotychczasowe SMS-y (powiadomienia o dostawie, przypomnienia o płatności) — raz, bez duplikatów.
INSERT INTO n_sms_log (id_customer, context, ref_id, recipient, body, status, provider, error, created_by, created_at)
SELECT n.id_customer, 'delivery_notice', n.id, LEFT(n.recipient, 40), LEFT(n.body, 1000), n.status, n.provider, n.error, n.created_by, n.created_at
FROM n_delivery_notice n
WHERE n.channel = 'sms'
  AND NOT EXISTS (SELECT 1 FROM n_sms_log l WHERE l.context = 'delivery_notice' AND l.ref_id = n.id);

-- Przypomnienia proform : klient z document_proforma (baza testowa API nie ma tabel ERP : wtedy nic).
SET @tbl_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma');
SET @sql := IF(@tbl_exists = 1,
    'INSERT INTO n_sms_log (id_customer, context, ref_id, recipient, body, status, provider, error, created_by, created_at)
     SELECT p.id_customer, ''payment_reminder'', s.id_proforma, s.recipient, s.body, s.status, s.provider, s.error, s.created_by, s.created_at
     FROM n_proforma_sms s JOIN document_proforma p ON p.id = s.id_proforma
     WHERE NOT EXISTS (SELECT 1 FROM n_sms_log l WHERE l.context = ''payment_reminder'' AND l.ref_id = s.id_proforma AND l.created_at = s.created_at)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Dostawca : smsapi (wysyłka, gdy jest token i tryb wysyłki « live ») albo log_only (wyłącznik : tylko zapis).
UPDATE n_prod_param SET value = 'smsapi', default_value = 'smsapi',
       description_pl = 'Dostawca SMS : smsapi (SMSAPI, gdy jest token) albo log_only (tylko zapis w historii, bez wysyłki)',
       description_fr = 'Fournisseur SMS : smsapi (SMSAPI, si un jeton est réglé) ou log_only (journal seulement, pas d''envoi)'
WHERE code = 'sms.provider' AND value = 'log_only' AND default_value = 'log_only';

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('sms.provider', 'smsapi', 'smsapi', 'string', NULL, 'Dostawca SMS : smsapi (SMSAPI, gdy jest token) albo log_only (tylko zapis w historii, bez wysyłki)', 'Fournisseur SMS : smsapi (SMSAPI, si un jeton est réglé) ou log_only (journal seulement, pas d''envoi)'),
('sms.from', '', '', 'string', NULL, 'Nadawca SMS (nazwa zatwierdzona w SMSAPI ; puste = domyślny nadawca konta)', 'Expéditeur SMS (nom validé chez SMSAPI ; vide = expéditeur par défaut du compte)'),
('sms.default_prefix', '32', '32', 'string', NULL, 'Numer kierunkowy kraju dla numerów bez prefiksu (32 = Belgia, 48 = Polska)', 'Indicatif pays des numéros sans préfixe (32 = Belgique, 48 = Pologne)');

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('sms.base', 'https://api.smsapi.com', 'https://api.smsapi.com', 'string', NULL, 'Adres API SMSAPI (api.smsapi.com ; konto polskie : api.smsapi.pl)', 'Adresse de l''API SMSAPI (api.smsapi.com ; compte polonais : api.smsapi.pl)');
