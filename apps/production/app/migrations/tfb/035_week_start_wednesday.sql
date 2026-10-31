-- Tydzień produkcji pracowni zaczyna się w środę (śr → wt) : Gantt tygodnia, przydziały dni,
-- szeregowanie i karta produktu liczą tydzień od week.start_day (1 = pn … 7 = nd).

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('week.start_day', '3', '1', 'int', NULL, 'Pierwszy dzień tygodnia produkcji (1 = pn … 7 = nd) ; tydzień = 7 dni od tego dnia', 'Premier jour de la semaine de production (1 = lun … 7 = dim) ; semaine = 7 jours à partir de ce jour');

-- Przydziały dni zapisane z kluczem poniedziałku : klucz = środa tygodnia, do którego należy dzień.
UPDATE tfb_prod_week_slot SET week_start = work_date - INTERVAL ((WEEKDAY(work_date) + 5) % 7) DAY
WHERE week_start <> work_date - INTERVAL ((WEEKDAY(work_date) + 5) % 7) DAY;
