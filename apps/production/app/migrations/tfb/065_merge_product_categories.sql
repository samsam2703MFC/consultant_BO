-- Kategorie produktów : zakładka « Kategorie i marże » w Kartach produktów (/workshop/products#/categories)
-- zamiast osobnej strony /product-categories ; listy produktów ERP (/products/all, /product-categories/{id}/products)
-- to lista Kart produktów z filtrami kategorii i aktywności w ERP. Stanowiska z prawem « Produkty » dostają
-- prawo « Karty produktów », żeby nie stracić dostępu do kategorii i list produktów.
INSERT IGNORE INTO tfb_page_access (id_position, page_key, updated_by)
SELECT id_position, 'product_cards', updated_by FROM tfb_page_access WHERE page_key = 'products';
