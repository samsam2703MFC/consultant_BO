-- [443] Różnice kursowe : le Kierownik operacyjny reçoit aussi le droit « fx_differences » (décision du 25.11.2026,
-- lecture et calcul comme l'Administracja : page, wyciągi, kursy NBP, zestawienie PDF / CSV). Les montants de cette page
-- lui sont ouverts par PageAccessService::seesFxValues (Finanse, CEO, Kierownik operacyjny) ; les autres écrans avec
-- montants ne changent pas.
-- Rejouable : INSERT IGNORE sur la clé (id_position, page_key).

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'fx_differences' FROM n_position p WHERE p.code = 'KIEROWNIK_OPERACYJNY';
