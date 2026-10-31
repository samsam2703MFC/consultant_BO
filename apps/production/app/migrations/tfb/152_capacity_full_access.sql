-- Moce produkcyjne [234] : pełna wersja (co jeszcze zmieszczę, ilość na zmianę, ustawienia maszyn, co jeśli, wartość w €)
-- tylko dla administratora i dyrektora zakładu ; produkcja (prawo « production ») widzi stan obecny, do odczytu.
-- Prawo « capacity_full » : stanowiska « Dyrektor… », jeśli istnieją (dziś żadne — tylko administrator) ; inne nadaje
-- administrator w Uprawnieniach funkcji. Rejouable.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT id, 'capacity_full' FROM n_position WHERE name_pl LIKE 'Dyrektor%';
