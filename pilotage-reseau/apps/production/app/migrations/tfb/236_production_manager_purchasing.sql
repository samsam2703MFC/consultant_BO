-- [394] Zakupy wracają do Kierownika produkcji (décision du 23.11.2026 : le poste Zakupy reste inactif, ses tâches
-- d'achat reviennent au Kierownik produkcji). La migration 162 l'avait limité à « Czas pracy » ; on rouvre seulement les
-- achats — prévision 13 semaines, suivi de production et rendement restent suspendus.
--   - tâches réactivées : Zakupy tygodnia (buy_sent), Asystent faktur (inv_checked), Ocena dostawców (suppliers_reviewed),
--     Koszty surowców (material_costs_reviewed) ;
--   - pages : Zaopatrzenie (supply), Surowce i ceny (purchasing), Ocena dostawców (supplier_review) ;
--   - menu du poste : Zakupy, Surowce i ceny (s'il a son propre menu).
-- Rejouable : UPDATE limité aux tâches inactives, INSERT IGNORE sur les droits et le menu. Les autres tâches suspendues
-- se rouvrent à la main (Administracja › Zadania stanowisk).

UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position
SET t.active = 1
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND t.active = 0
  AND t.auto_code IN ('buy_sent', 'inv_checked', 'suppliers_reviewed', 'material_costs_reviewed');

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, k.page_key FROM n_position p
JOIN (SELECT 'supply' AS page_key UNION ALL SELECT 'purchasing' UNION ALL SELECT 'supplier_review') k
WHERE p.code = 'KIEROWNIK_PRODUKCJI';

INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, m.item, NULL FROM n_position p
JOIN (SELECT 'purchasing' AS item UNION ALL SELECT 'materials') m
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND EXISTS (SELECT 1 FROM n_position_menu x WHERE x.id_position = p.id);
