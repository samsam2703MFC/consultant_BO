-- [383] Asystent « Poczta od klientów » (codziennie ; matrice des obligations 224 : ADMINISTRACJA 6 « Korespondencja z
-- klientami ») : chaque jour, les e-mails de clients reçus dans la boîte Poczta de la personne (n_user_mail, migration 071 :
-- folder 'INBOX', id_customer reconnu à la synchronisation par UserMailRepository::contact) et restés sans réponse depuis plus
-- de customer_mail.reply_hours heures, comptées sur les jours ouvrés (week.production_days : samedi et dimanche ne comptent
-- pas, la nuit compte), sur les customer_mail.lookback_days derniers jours :
--   - « odpowiedziany » : n_user_mail.answered = 1 (drapeau \Answered de la boîte, ou réponse envoyée depuis Poczta), ou une
--     réponse envoyée dans le fil — n_user_mail_sent.id_reply_to = message, status 'sent' (Poczta, « Wysłane z ERP »), ou un
--     message du dossier 'SENT' de la même boîte dont in_reply_to = message_id du message ;
--   - état de la synchronisation de la boîte (n_user_mailbox.last_sync_at, last_error ; n_user_mail_admin.stopped), pour ne
--     jamais conclure « aucun e-mail » quand la synchronisation est en panne.
-- Boîte visible : exactement celle de l'écran Poczta (MailController::mailUser : la sienne ; l'administrateur dans la session
-- d'un poste voit celle de la personne de ce poste). Une décision par e-mail signalé — « Odpowiedziane » (accepté seulement si
-- le message est maintenant répondu), « Bez odpowiedzi — nie wymaga » (note obligatoire), « Przekazane » (note obligatoire,
-- personne et poste facultatifs) —, puis « Zakończ » écrit n_wizard_done (code customer_mail_checked).
-- L'assistant n'envoie rien, ne se connecte à aucune boîte (ni IMAP ni SMTP), ne marque aucun message lu ou répondu : il
-- renvoie vers Poczta (lien par message). Aucun contenu d'e-mail n'est copié ici : seulement l'id du message.
--  - n_customer_mail_check : une décision par message (n_user_mail.id) ;
--  - n_prod_param customer_mail.reply_hours, customer_mail.lookback_days (bornes dans ParamRules) ;
--  - matrice des obligations (224) : l'assistant proposé « Poczta od klientów » devient customer_mail_checked.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_customer_mail_check (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_mail        BIGINT NOT NULL COMMENT 'n_user_mail.id (wiadomość od klienta) — bez treści, tematu ani nadawcy',
    id_mailbox     INT NOT NULL COMMENT 'n_user_mailbox.id_user — skrzynka, w której jest wiadomość',
    checked_on     DATE NOT NULL COMMENT 'dzień kontroli (asystent dnia)',
    decision       ENUM('answered','not_needed','forwarded') NOT NULL COMMENT 'answered = odpowiedziane (sprawdzone) ; not_needed = bez odpowiedzi — nie wymaga ; forwarded = przekazane',
    note           VARCHAR(500) NULL COMMENT 'wymagana dla not_needed i forwarded',
    reply_source   ENUM('flag','erp','sent') NULL COMMENT 'answered : flaga odpowiedzi, wysłane z ERP (n_user_mail_sent) albo folder Wysłane (n_user_mail SENT)',
    id_reply       BIGINT NULL COMMENT 'answered : n_user_mail_sent.id albo n_user_mail.id (SENT)',
    id_responsible INT NULL COMMENT 'forwarded : user.id (opcjonalnie)',
    id_position    INT NULL COMMENT 'forwarded : n_position.id (opcjonalnie)',
    flags          VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user        INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_mail_check (id_mail),
    KEY idx_customer_mail_check_day (checked_on),
    KEY idx_customer_mail_check_box (id_mailbox)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Poczta od klientów : decyzja dla e-maila klienta bez odpowiedzi [383]';

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'customer_mail.reply_hours', '24', '24', 'int', 'h',
    'Poczta od klientów : po ilu godzinach (liczonych w dniach roboczych) e-mail klienta bez odpowiedzi trafia do wyjaśnienia',
    'Courrier des clients : nombre d''heures (comptées sur les jours ouvrés) après lequel un e-mail de client sans réponse est à expliquer'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'customer_mail.reply_hours');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'customer_mail.lookback_days', '30', '30', 'int', 'dni',
    'Poczta od klientów : z ilu ostatnich dni sprawdzane są e-maile od klientów',
    'Courrier des clients : nombre de jours en arrière sur lesquels les e-mails des clients sont contrôlés'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'customer_mail.lookback_days');

-- Matrice des obligations : ADMINISTRACJA 6 « Korespondencja z klientami » (daily).
UPDATE n_position_duty SET wizard_code = 'customer_mail_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Poczta od klientów';
