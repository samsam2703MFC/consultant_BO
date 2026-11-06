-- Tydzień produkcji, tabela « Co trzeba wyprodukować w tygodniu » : godziny grupy ÷ godziny etatu = ETP
-- (osoby na pełen etat potrzebne w tygodniu).

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('week.fte_hours', '40', '40', 'decimal', 'h', 'Godziny pełnego etatu w tygodniu (ETP = godziny tygodnia grupy ÷ ta wartość)', 'Heures d''un temps plein par semaine (ETP = heures de la semaine du groupe ÷ cette valeur)');
