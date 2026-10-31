-- Lot 3 [229] : zadanie dnia stanowisk pracowni (tablety), zaliczane przez zatwierdzenie na tablecie — grupy produkcji
-- (dzień grupy zatwierdzony), Brygadzista (wszystkie grupy z produkcją zatwierdziły dzień), Pakowacz (wszystko spakowane
-- na dzień wysyłki), Kierowca (trasa zakończona). Bez przypomnień w dzwonku (osoby nie mają konta ERP) : przełożony widzi
-- wykonanie w « Kto co zrobił ». Tylko gdy stanowisko jest i zadania jeszcze nie ma ; rejouable.

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, link_path, auto_code, remind_time, escalate_days, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA' LIMIT 1),
       CONCAT('Dzień zatwierdzony na tablecie — ', g.name_pl, ' (plan → zrobione → straty → zatwierdź)'),
       CONCAT('Journée validée sur la tablette — ', g.name_pl, ' (plan → fait → pertes → valider)'),
       'daily', 10, '/production/follow-up', 'group_day_closed', NULL, NULL, 5, 1
FROM n_position p
JOIN n_prod_group g ON g.code = CASE p.code WHEN 'PIEKARZ' THEN 'PIEKARNIA' WHEN 'ROGAL' THEN 'ROGAL' WHEN 'CUKIERNIK' THEN 'CUKIERNIA' WHEN 'GARMAZER' THEN 'GARMAZERIA' END
WHERE p.code IN ('PIEKARZ', 'ROGAL', 'CUKIERNIK', 'GARMAZER')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.auto_code = 'group_day_closed');

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, link_path, auto_code, remind_time, escalate_days, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA' LIMIT 1),
       'Zmiana : wszystkie grupy z produkcją zatwierdziły dzień (tablety)', 'Équipe : tous les groupes ayant produit ont validé la journée (tablettes)',
       'daily', 15, '/production/follow-up', 'shift_day_validated', NULL, NULL, 5, 1
FROM n_position p
WHERE p.code = 'BRYGADZISTA' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.auto_code = 'shift_day_validated');

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, link_path, auto_code, remind_time, escalate_days, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'LOGISTYKA' LIMIT 1),
       'Pakowanie dnia na tablecie (wszystko spakowane na dzień wysyłki)', 'Emballage du jour sur la tablette (tout emballé pour le jour d''expédition)',
       'daily', 20, '/logistics/live', 'packing_done', NULL, NULL, 5, 1
FROM n_position p
WHERE p.code = 'PAKOWACZ' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.auto_code = 'packing_done');

INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, link_path, auto_code, remind_time, escalate_days, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'LOGISTYKA' LIMIT 1),
       'Trasa dnia na tablecie kierowcy (dostawy potwierdzone, trasa zakończona)', 'Tournée du jour sur la tablette chauffeur (livraisons confirmées, tournée terminée)',
       'daily', 15, '/logistics/deliveries', 'route_done', NULL, NULL, 5, 1
FROM n_position p
WHERE p.code = 'KIEROWCA' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.auto_code = 'route_done');
