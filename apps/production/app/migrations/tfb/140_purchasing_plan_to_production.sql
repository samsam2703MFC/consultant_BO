-- Plan tygodnia [219] : stanowiska « Specjalista ds. zakupów » (ZAKUPY) nikt nie ma — zakupy, faktury, nowe ceny i upload
-- prowadzi kierownik produkcji (decyzja 30/10/2026). Wszystkie zadania stanowiska ZAKUPY (z historią wystąpień) przechodzą
-- do KIEROWNIK_PRODUKCJI, za jego zadaniami ; stanowisko ZAKUPY nieaktywne (do ponownego włączenia, gdy będzie kupiec), chyba
-- że ktoś je ma. Rejouable : bez zadań na ZAKUPY nic się nie zmienia.
SET @src := (SELECT id FROM n_position WHERE code = 'ZAKUPY');
SET @dst := (SELECT id FROM n_position WHERE code = 'KIEROWNIK_PRODUKCJI');

UPDATE n_position_task SET id_position = @dst, sort_order = sort_order + 100
WHERE @src IS NOT NULL AND @dst IS NOT NULL AND id_position = @src;

UPDATE n_position SET active = 0
WHERE @dst IS NOT NULL AND id = @src AND NOT EXISTS (SELECT 1 FROM n_user_position up WHERE up.id_position = @src);
