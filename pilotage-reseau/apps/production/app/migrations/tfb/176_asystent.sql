-- [282] « Asystent » partout dans l'ERP (demande du 12/11/2026) : le mot « kreator » devient « asystent » dans les
--   textes enregistrés en base — titres des tâches de poste et descriptions des paramètres (le code et les vues sont
--   renommés dans le même lot). Formes polonaises : kreatorze → asystencie, kreatory → asystenci, sinon kreator → asystent
--   (kreatora, kreatorem, kreatorów gardent leur terminaison). Historique (notes, rappels envoyés) inchangé. Rejouable.
UPDATE n_position_task SET title_pl = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(title_pl,
    'kreatorze', 'asystencie'), 'Kreatory', 'Asystenci'), 'kreatory', 'asystenci'), 'Kreator', 'Asystent'), 'kreator', 'asystent')
WHERE title_pl LIKE BINARY '%reator%';
UPDATE n_prod_param SET description_pl = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(description_pl,
    'kreatorze', 'asystencie'), 'Kreatory', 'Asystenci'), 'kreatory', 'asystenci'), 'Kreator', 'Asystent'), 'kreator', 'asystent')
WHERE description_pl LIKE BINARY '%reator%';
