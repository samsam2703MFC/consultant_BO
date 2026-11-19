-- [307] Zadanie Logistyki « Transport dla zamówień » : chodzi o dzień wysyłki (expected_shipping_date), nie o dzień zamówienia.
--   Tytuł zmieniony tylko, gdy jest jeszcze ten z migracji 191 (tytuł zmieniony ręcznie zostaje).
UPDATE n_position_task
SET title_pl = 'Transport dla wysyłki dziś i jutro (asystent : przypisz klientów do pojazdu i kierowcy)',
    title_fr = 'Transport des expéditions d''aujourd''hui et de demain (assistant : affecter les clients à un véhicule et à un chauffeur)'
WHERE auto_code = 'orders_transport' AND title_pl = 'Transport dla zamówień na dziś i jutro (asystent : przypisz klientów do pojazdu)';
