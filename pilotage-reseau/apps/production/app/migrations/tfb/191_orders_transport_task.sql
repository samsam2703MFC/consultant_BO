-- [303] Logistyka : zamówienia na dziś i jutro bez transportu → zadanie obowiązkowe (blokuje ERP) z asystentem
--   « Transport dla zamówień » (makieta 56, /logistics/transport-wizard). Codziennie, blokada od razu (00:00) ; zadanie
--   zalicza się samo, gdy każdy klient z zamówieniem ma pojazd, i otwiera się znowu, gdy dojdzie zamówienie bez transportu.
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, link_path, auto_code, sort_order, active,
                             mandatory, block_mode, block_time, block_tolerance)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'LOGISTYKA' LIMIT 1),
       'Transport dla zamówień na dziś i jutro (asystent : przypisz klientów do pojazdu)',
       'Transport des commandes d''aujourd''hui et de demain (assistant : affecter les clients à un véhicule)',
       'daily', 10, '/logistics/transport-wizard', 'orders_transport', 5, 1, 1, 'due', '00:00:00', 0
FROM n_position p
WHERE p.code = 'LOGISTYKA' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.auto_code = 'orders_transport');
