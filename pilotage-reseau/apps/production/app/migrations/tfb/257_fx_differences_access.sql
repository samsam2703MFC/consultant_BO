-- [440] Różnice kursowe (Finanse) : droit propre « fx_differences » (page /finances/fx-differences, appels ajax, wyciągi,
-- zestawienie PDF / CSV). Donné au poste Finanse — ADMINISTRACJA (« Administracja i fakturowanie »), celui qui voit les
-- wpłaty et les montants des clients (PageAccessService::FINANCE_POSITION, Płatności dnia, potwierdzenia wpłat). Le
-- Kierownik operacyjny (« liquidity ») ne le reçoit pas : sa page reste Płynność i gotówka, sans factures des clients.
-- L'administrateur voit tout, le profil CEO lit (règle commune) ; montants : profil Finanse et CEO (seesOrderValues).
-- Rejouable : INSERT IGNORE sur la clé (id_position, page_key).

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'fx_differences' FROM n_position p WHERE p.code = 'ADMINISTRACJA';
