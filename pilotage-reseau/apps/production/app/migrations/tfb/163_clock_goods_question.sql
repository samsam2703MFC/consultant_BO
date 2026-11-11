-- [263] Pointage de sortie : « Avez-vous repris de la marchandise ? » (demande du 11/11/2026).
--   Questions de pointage : genre « confirm » (case à confirmer, comme avant) ou « yes_no » (réponse Tak / Nie, les deux
--   valables ; obligatoire = il faut choisir), information (i) PL / FR ; réponse « Tak » → ce qui est pris (note gardée
--   avec le pointage, visible dans Czas pracy › Dzień). Rejouable : ALTER conditionnels, question ajoutée une seule fois.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_clock_question' AND COLUMN_NAME = 'kind') = 0,
    'ALTER TABLE n_clock_question ADD COLUMN kind ENUM(''confirm'',''yes_no'') NOT NULL DEFAULT ''confirm'' AFTER direction, ADD COLUMN info_pl TEXT NULL AFTER text_fr, ADD COLUMN info_fr TEXT NULL AFTER info_pl',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_clock_answer' AND COLUMN_NAME = 'note') = 0,
    'ALTER TABLE n_clock_answer ADD COLUMN note VARCHAR(255) NULL COMMENT ''réponse Tak d''''une question yes_no : ce qui est pris'' AFTER answer',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO n_clock_question (text_pl, text_fr, info_pl, info_fr, direction, kind, required, active, sort_order)
SELECT 'Czy zabierasz dziś ze sobą towar (produkty, braki, surowce) ?',
       'Avez-vous repris de la marchandise (produits, rebuts, matières) ?',
       'To nie problem. W firmie zawsze było tak, że na terenie zakładu możesz jeść wszystko, na co masz ochotę. Jeśli zabierasz braki (odpady) produktów, trzeba je wpisać. Jeśli masz szczególną prośbę, np. na imprezę czy święto, zapytaj w biurze — nigdy nie odmawiamy. Ale proszę, nie daj się złapać na pół tarty pod płaszczem.',
       'Ce n''est pas un problème. La politique de l''entreprise a toujours été que vous pouvez manger tout ce que vous voulez à l''intérieur de l''enceinte. Si vous prenez des rebuts de produits, il faut les encoder. Si vous avez une demande spécifique pour une fête, demandez au bureau : on ne dit jamais non. Mais s''il vous plaît, ne vous faites pas prendre pour une demi-tarte sous le manteau.',
       'out', 'yes_no', 1, 1, 50
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_clock_question WHERE kind = 'yes_no' AND direction IN ('out', 'both'));
