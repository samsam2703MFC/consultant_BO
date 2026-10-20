-- Grupy pracowni = działy produkcji ERP (production_sections : Piekarnia, Tarty, Rogal, Cukiernia,
-- Garmazeria, Pakowanie/wysyłka…), bez Administracji i działów bez ludzi i bez aktywnych produktów.
--
-- Jedna grupa na dział : grupy pomocnicze tego samego działu (np. zakwas i formowanie w Piekarni)
-- łączą się w jedną — stanowiska, zespoły, tablety, uprawnienia, karty produktów i pracownicy
-- przechodzą do grupy działu, a grupa pomocnicza zostaje wyłączona. Dział bez grupy (np. Tarty)
-- dostaje nową grupę. Produkt należy do grupy swojego działu (final_product.id_section), pracownik
-- pracowni do grupy swojego działu (production_employee.id_section). Obsada zmiany dziennej =
-- liczba pracowników działu (godziny tygodnia = osoby × 8 h × dni produkcji).
-- Bez danych ERP (baza testowa) nic się nie zmienia.

DROP TEMPORARY TABLE IF EXISTS tmp_section_group;
CREATE TEMPORARY TABLE tmp_section_group (
    id_section INT NOT NULL PRIMARY KEY,
    id_group   INT NULL,
    code       VARCHAR(32) NOT NULL,
    name_pl    VARCHAR(100) NOT NULL,
    name_fr    VARCHAR(100) NOT NULL,
    color      CHAR(7) NOT NULL,
    sort_order INT NOT NULL,
    headcount  INT NOT NULL
) ENGINE=MEMORY;

INSERT INTO tmp_section_group (id_section, id_group, code, name_pl, name_fr, color, sort_order, headcount)
SELECT s.id,
       (SELECT MIN(g.id) FROM tfb_prod_group g WHERE g.id_section = s.id),
       CASE WHEN s.name = 'Piekarnia' THEN 'PIEKARNIA' WHEN s.name = 'Tarty' THEN 'TARTY' WHEN s.name = 'Rogal' THEN 'ROGAL'
            WHEN s.name = 'Cukiernia' THEN 'CUKIERNIA' WHEN s.name = 'Garmazeria' THEN 'GARMAZERIA' WHEN s.name LIKE 'Pakowanie%' THEN 'PAKOWANIE'
            WHEN s.name LIKE 'Czekolada%' THEN 'CZEKOLADA' ELSE CONCAT('DZIAL_', s.id) END,
       LEFT(s.name, 100),
       CASE WHEN s.name = 'Piekarnia' THEN 'Boulangerie' WHEN s.name = 'Tarty' THEN 'Tartes' WHEN s.name = 'Rogal' THEN 'Viennoiserie'
            WHEN s.name = 'Cukiernia' THEN 'Pâtisserie' WHEN s.name = 'Garmazeria' THEN 'Traiteur' WHEN s.name LIKE 'Pakowanie%' THEN 'Conditionnement / expédition'
            WHEN s.name LIKE 'Czekolada%' THEN 'Chocolat - biscuits' ELSE LEFT(s.name, 100) END,
       CASE WHEN s.name = 'Piekarnia' THEN '#B5651D' WHEN s.name = 'Tarty' THEN '#8D1D2C' WHEN s.name = 'Rogal' THEN '#C9A227'
            WHEN s.name = 'Cukiernia' THEN '#6D4C8F' WHEN s.name = 'Garmazeria' THEN '#2E7D4F' WHEN s.name LIKE 'Pakowanie%' THEN '#4A6FA5' ELSE '#5D4037' END,
       CASE WHEN s.name = 'Piekarnia' THEN 10 WHEN s.name = 'Tarty' THEN 20 WHEN s.name = 'Rogal' THEN 30 WHEN s.name = 'Cukiernia' THEN 40
            WHEN s.name = 'Garmazeria' THEN 50 WHEN s.name LIKE 'Pakowanie%' THEN 90 ELSE 60 + s.id END,
       (SELECT COUNT(*) FROM production_employee e WHERE e.id_section = s.id)
FROM production_sections s
WHERE s.name NOT LIKE 'Administrac%'
  AND (EXISTS (SELECT 1 FROM production_employee e WHERE e.id_section = s.id)
       OR EXISTS (SELECT 1 FROM final_product fp WHERE fp.id_section = s.id AND fp.active = 1));

-- Działy bez grupy : nowa grupa.
INSERT INTO tfb_prod_group (code, name_pl, name_fr, color, sort_order, id_section, active)
SELECT m.code, m.name_pl, m.name_fr, m.color, m.sort_order, m.id_section, 1
FROM tmp_section_group m
WHERE m.id_group IS NULL AND NOT EXISTS (SELECT 1 FROM tfb_prod_group g WHERE g.code = m.code);
UPDATE tmp_section_group m JOIN tfb_prod_group g ON g.code = m.code SET m.id_group = g.id WHERE m.id_group IS NULL;

-- Grupy pomocnicze tego samego działu → grupa działu.
DROP TEMPORARY TABLE IF EXISTS tmp_group_merge;
CREATE TEMPORARY TABLE tmp_group_merge (id_old INT NOT NULL PRIMARY KEY, id_new INT NOT NULL) ENGINE=MEMORY;
INSERT INTO tmp_group_merge (id_old, id_new)
SELECT g.id, m.id_group FROM tfb_prod_group g JOIN tmp_section_group m ON m.id_section = g.id_section WHERE g.id <> m.id_group;

UPDATE tfb_prod_workstation w JOIN tmp_group_merge x ON x.id_old = w.id_group SET w.id_group = x.id_new;
UPDATE tfb_prod_team t JOIN tmp_group_merge x ON x.id_old = t.id_group SET t.id_group = x.id_new, t.active = 0;
UPDATE tfb_prod_device d JOIN tmp_group_merge x ON x.id_old = d.id_group SET d.id_group = x.id_new;
UPDATE tfb_prod_product_settings s JOIN tmp_group_merge x ON x.id_old = s.id_primary_group SET s.id_primary_group = x.id_new;
UPDATE tfb_prod_employee e JOIN tmp_group_merge x ON x.id_old = e.id_primary_group SET e.id_primary_group = x.id_new;
UPDATE IGNORE tfb_prod_user_role r JOIN tmp_group_merge x ON x.id_old = r.id_group SET r.id_group = x.id_new;
DELETE r FROM tfb_prod_user_role r JOIN tmp_group_merge x ON x.id_old = r.id_group;
DELETE st FROM tfb_prod_staffing st JOIN tmp_group_merge x ON x.id_old = st.id_group;
UPDATE tfb_prod_group g JOIN tmp_group_merge x ON x.id_old = g.id SET g.active = 0, g.id_section = NULL;

-- Grupa działu : kod, nazwa i kolejność działu.
UPDATE tfb_prod_group g JOIN tmp_section_group m ON m.id_group = g.id
SET g.code = m.code, g.name_pl = m.name_pl, g.name_fr = m.name_fr, g.color = m.color, g.sort_order = m.sort_order, g.id_section = m.id_section, g.active = 1;

-- Produkt w grupie swojego działu, pracownik pracowni w grupie swojego działu.
UPDATE tfb_prod_product_settings s
JOIN tfb_prod_item i ON i.id = s.id_item
JOIN final_product fp ON fp.id = i.id_final_product
JOIN tmp_section_group m ON m.id_section = fp.id_section
SET s.id_primary_group = m.id_group;
UPDATE tfb_prod_employee pe
JOIN production_employee e ON e.id = pe.id_production_employee
JOIN tmp_section_group m ON m.id_section = e.id_section
SET pe.id_primary_group = m.id_group;

-- Zmiana dzienna : obsada = pracownicy działu ; dział bez zespołu dziennego dostaje zespół.
UPDATE tfb_prod_team t
JOIN tmp_section_group m ON m.id_group = t.id_group
JOIN tfb_prod_shift_template sh ON sh.id = t.id_shift_template AND sh.code = 'JOUR'
SET t.planned_headcount = m.headcount, t.name = CONCAT(m.name_pl, ' — zmiana dzienna')
WHERE t.active = 1;
INSERT INTO tfb_prod_team (code, id_group, id_shift_template, name, planned_headcount, active)
SELECT CONCAT(m.code, '_DZIEN'), m.id_group, sh.id, CONCAT(m.name_pl, ' — zmiana dzienna'), m.headcount, 1
FROM tmp_section_group m JOIN tfb_prod_shift_template sh ON sh.code = 'JOUR'
WHERE NOT EXISTS (SELECT 1 FROM tfb_prod_team t WHERE t.id_group = m.id_group AND t.id_shift_template = sh.id AND t.active = 1)
  AND NOT EXISTS (SELECT 1 FROM tfb_prod_team t2 WHERE t2.code = CONCAT(m.code, '_DZIEN'));

DROP TEMPORARY TABLE IF EXISTS tmp_group_merge;
DROP TEMPORARY TABLE IF EXISTS tmp_section_group;
