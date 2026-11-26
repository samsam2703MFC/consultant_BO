-- [421] Jakość wchodzi do Kierownika produkcji (décision du 23.11.2026 : « tout le poste contrôle qualité sera sous la
-- Kierownik produkcji » — fusion, comme Zakupy en 140/236). Rien n'est supprimé :
--   - tâches du poste JAKOSC → KIEROWNIK_PRODUKCJI ; si le KP a déjà la même tâche d'assistant (auto_code), elle est
--     réactivée chez le KP et celle de Jakość désactivée (pas de doublon) ;
--   - droits des pages de Jakość ajoutés au KP ; menu du KP complété (s'il a son propre menu) ;
--   - matrice Struktura i obowiązki : les obligations actives de Jakość sont recopiées chez le KP (obszar 13, numéros
--     J1, J2…) puis désactivées chez Jakość ;
--   - postes rattachés à Jakość → rattachés au KP ; poste Jakość inactif, sauf si une personne l'occupe encore (ses droits
--     restent alors les siens jusqu'à ce qu'on la déplace dans Administracja › Stanowiska).
-- Rejouable : tout est limité aux lignes encore actives / pas encore copiées.
SET @src := (SELECT id FROM n_position WHERE code = 'JAKOSC');
SET @dst := (SELECT id FROM n_position WHERE code = 'KIEROWNIK_PRODUKCJI');

-- Tâches d'assistant que le KP a déjà : réactiver chez le KP si actives chez Jakość, puis désactiver chez Jakość.
UPDATE n_position_task k
JOIN (SELECT DISTINCT auto_code FROM n_position_task WHERE id_position = @src AND active = 1 AND auto_code IS NOT NULL) j ON j.auto_code = k.auto_code
SET k.active = 1
WHERE @src IS NOT NULL AND @dst IS NOT NULL AND k.id_position = @dst;

UPDATE n_position_task t
JOIN (SELECT DISTINCT auto_code FROM n_position_task WHERE id_position = @dst AND auto_code IS NOT NULL) k ON k.auto_code = t.auto_code
SET t.active = 0
WHERE @src IS NOT NULL AND @dst IS NOT NULL AND t.id_position = @src;

-- Les autres tâches de Jakość (avec leur historique) passent au KP, après les siennes.
UPDATE n_position_task SET id_position = @dst, sort_order = sort_order + 200
WHERE @src IS NOT NULL AND @dst IS NOT NULL AND id_position = @src AND active = 1;

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT @dst, a.page_key FROM n_page_access a WHERE @src IS NOT NULL AND @dst IS NOT NULL AND a.id_position = @src;

INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT @dst, m.item, NULL FROM n_position_menu m
WHERE @src IS NOT NULL AND @dst IS NOT NULL AND m.id_position = @src
  AND EXISTS (SELECT 1 FROM (SELECT id_position FROM n_position_menu) x WHERE x.id_position = @dst);

INSERT INTO n_position_duty (id_position, number, area_no, area, text_pl, text_fr, wizard_code, proposed_wizard, rhythm, source, sort_order)
SELECT @dst, CONCAT('J', d.number), 13, '13. Kontrola jakości (dawne stanowisko Jakość)', d.text_pl, d.text_fr, d.wizard_code, d.proposed_wizard, d.rhythm,
       'Jakość → Kierownik produkcji (23.11.2026)', 13000 + d.sort_order
FROM n_position_duty d
WHERE @src IS NOT NULL AND @dst IS NOT NULL AND d.id_position = @src AND d.active = 1
  AND NOT EXISTS (SELECT 1 FROM (SELECT id_position, number FROM n_position_duty) x WHERE x.id_position = @dst AND x.number = CONCAT('J', d.number));

UPDATE n_position_duty SET active = 0
WHERE @src IS NOT NULL AND @dst IS NOT NULL AND id_position = @src AND active = 1;

UPDATE n_position SET id_reports_to = @dst
WHERE @src IS NOT NULL AND @dst IS NOT NULL AND id_reports_to = @src AND id <> @dst;

UPDATE n_position SET active = 0
WHERE @dst IS NOT NULL AND id = @src AND NOT EXISTS (SELECT 1 FROM n_user_position up WHERE up.id_position = @src);
