-- [279] HR › Szybka notatka (makieta 44, validée le 12/11/2026) : un bouton « + Szybka notatka » ouvre une fenêtre —
--   rodzaj (ocena, pochwała, uwaga, rozmowa, szkolenie, BHP, nieobecność, inne), pracownik, temat, treść, dla kogo
--   (administrateur, finances, Kierownik produkcji, logistique, qualité, ou « tylko ja » = privée), follow-up (date et
--   responsable), alertes immédiates (cloche ERP, e-mail, SMS, tâche dans « Mój tydzień »).
-- Sujet chiffré comme le texte (HrCipher). E-mail et SMS : qui, type, sujet et lien — jamais le texte de la note.
-- Rejouable : ALTER conditionnel.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_hr_note' AND COLUMN_NAME = 'kind');
SET @sql := IF(@c = 0, 'ALTER TABLE n_hr_note
    ADD COLUMN kind VARCHAR(20) NULL COMMENT ''rodzaj : ocena, pochwala, uwaga, rozmowa, szkolenie, bhp, nieobecnosc, inne'' AFTER id_employee,
    ADD COLUMN topic_enc VARCHAR(700) NULL COMMENT ''temat chiffré (HrCipher)'' AFTER kind,
    ADD COLUMN audience VARCHAR(255) NULL COMMENT ''dla kogo : admin + codes de postes (n_position.code), séparés par des virgules'' AFTER to_admin,
    ADD COLUMN channels VARCHAR(60) NULL COMMENT ''alertes envoyées : bell, email, sms, task'' AFTER audience,
    ADD COLUMN followup_due DATE NULL AFTER channels,
    ADD COLUMN followup_user INT NULL COMMENT ''user.id responsable du follow-up'' AFTER followup_due,
    ADD COLUMN followup_done_at DATETIME NULL AFTER followup_user,
    ADD COLUMN followup_done_by INT NULL AFTER followup_done_at,
    ADD COLUMN followup_done_note VARCHAR(255) NULL AFTER followup_done_by,
    ADD KEY idx_n_hr_note_followup (followup_user, followup_done_at, followup_due)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
