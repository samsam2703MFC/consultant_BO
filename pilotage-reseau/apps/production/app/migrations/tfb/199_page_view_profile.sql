-- [314] Licznik stron według profilu : kto (stanowisko, CEO, administrator, sesja stanowiska administratora) otwiera które
-- strony — średnio na dzień i na tydzień. Profil zapisany przy każdym otwarciu ; wcześniejsze wizyty : stanowisko osoby dziś.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_page_view' AND COLUMN_NAME = 'profile');
SET @sql := IF(@has = 0,
    'ALTER TABLE n_page_view ADD COLUMN profile VARCHAR(40) NOT NULL DEFAULT '''' COMMENT ''kod stanowiska, ceo, admin albo none'' AFTER id_user,
        DROP PRIMARY KEY, ADD PRIMARY KEY (day, route, id_user, profile), ADD KEY idx_n_page_view_profile (profile, day)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE n_page_view v JOIN n_prod_user_role r ON r.id_user = v.id_user AND r.role_code = 'prod_admin' AND r.id_group IS NULL
SET v.profile = 'admin' WHERE v.profile = '';
UPDATE n_page_view v JOIN n_user_position up ON up.id_user = v.id_user JOIN n_position p ON p.id = up.id_position
SET v.profile = p.code WHERE v.profile = '';
UPDATE n_page_view SET profile = 'none' WHERE profile = '';
