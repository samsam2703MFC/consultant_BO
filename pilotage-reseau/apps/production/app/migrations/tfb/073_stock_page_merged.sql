-- /stock połączone z zakładką « Stan magazynu » Kart produktów (decyzja 24.10.2026) : stanowiska z dostępem do Produkcji
-- widzą też Karty produktów, żeby zakładka « Stan magazynu » Produkcji nadal się otwierała.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT a.id_position, 'product_cards' FROM n_page_access a WHERE a.page_key = 'production';
