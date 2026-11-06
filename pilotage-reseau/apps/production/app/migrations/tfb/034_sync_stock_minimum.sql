-- Minimalny stan ERP (final_product.stock_minimum, kartony) = stan min. karty produktu MTS (sztuki),
-- zaokrąglony w górę do pełnych kartonów. Od teraz zapis karty aktualizuje ERP sam ; tu jednorazowo
-- dla kart już ustawionych. Karty MTO i karty bez stanu min. nie zmieniają wartości ERP.

UPDATE final_product fp
JOIN tfb_prod_item i ON i.id_final_product = fp.id
JOIN tfb_prod_product_settings s ON s.id_item = i.id
SET fp.stock_minimum = CEIL(s.stock_min / fp.ppc - 0.000001)
WHERE s.mode = 'MTS' AND s.stock_min IS NOT NULL AND fp.ppc > 0
  AND fp.stock_minimum <> CEIL(s.stock_min / fp.ppc - 0.000001);
