-- [392] Asystent « Poczta od klientów » : droit propre « customer_mail » (décision du 23.11.2026), réservé au poste
-- Administracja i fakturowanie. L'administrateur voit tout. Rejouable : INSERT IGNORE sur (id_position, page_key).

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'customer_mail' FROM n_position p WHERE p.code = 'ADMINISTRACJA';
