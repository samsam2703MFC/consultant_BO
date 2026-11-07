-- Tablette de bureau (fixe, tous les services) : la personne touche son service puis son nom ; QR personnel (personne +
-- jour) validé sur son téléphone, ou — sans téléphone enregistré — PIN puis questions et signature sur la tablette.
-- kiosk.office_pin_all (0) : 1 = PIN + signature proposés aussi aux personnes qui ont un téléphone. Rejouable.

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'kiosk.office_pin_all', '0', '0', 'bool', NULL,
    'Tablet biurowy : PIN + podpis także dla osób z telefonem (0 = tylko osoby bez zarejestrowanego telefonu, reszta kodem QR)',
    'Tablette de bureau : PIN + signature aussi pour les personnes avec un téléphone (0 = seulement sans téléphone enregistré, les autres par QR)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'kiosk.office_pin_all');
