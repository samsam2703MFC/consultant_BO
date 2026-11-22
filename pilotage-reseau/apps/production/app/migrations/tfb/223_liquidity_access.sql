-- [373] Asystent « Płynność i gotówka » : prawo osobne « liquidity » (décision du 22.11.2026), au lieu de « margins »
-- (Finanse : faktury i koszty). Le Kierownik operacyjny ouvre ce seul écran, sans les factures ni les coûts.
--  - postes qui avaient « margins » : gardent l'accès à l'assistant (rien ne se perd) ;
--  - poste KIEROWNIK_OPERACYJNY : reçoit « liquidity » (l'assistant est sa tâche hebdomadaire).
-- L'administrateur voit tout. Rejouable : INSERT IGNORE sur la clé (id_position, page_key).

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT a.id_position, 'liquidity' FROM n_page_access a WHERE a.page_key = 'margins';

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'liquidity' FROM n_position p WHERE p.code = 'KIEROWNIK_OPERACYJNY';
