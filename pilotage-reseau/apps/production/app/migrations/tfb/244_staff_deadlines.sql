-- [404] Asystent « Badania i szkolenia (przegląd terminów) » (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik
-- produkcji 9.5 « Umawianie badań lekarskich i szkoleń pracowniczych » et 9.6 « Monitorowanie terminów badań i szkoleń »,
-- proposed_wizard = 'Badania i szkolenia (terminy)') : chaque mois, les échéances des salariés actifs (n_prod_employee.active) :
--   - badania lekarskie : date « ważne do » de la fiche HR (n_hr_employee.medical_until, en clair — la même que le tableau HR
--     montre au Kierownik produkcji ; aucune donnée chiffrée HrCipher n'est lue) — absente, dépassée, ou dans les hr.alert_days
--     jours (le réglage du tableau HR : même règle que son alerte « medical_alert ») ; le dernier document HR « Badania
--     lekarskie » (n_hr_document.kind = 'badania' : sa date seulement, jamais son contenu) est montré pour information ;
--   - szkolenia : l'ERP n'en connaît aucune échéance (tfb_employee_competency = niveaux de compétence recette, sans date ;
--     notes HR « szkolenie / BHP » = texte chiffré sans échéance) : registre propre n_staff_training (salarié, type BHP /
--     higieniczne / pierwsza pomoc / ppoż. / obsługa urządzeń / inne, fait le, valable jusqu'au — vide = sans échéance),
--     saisi dans l'assistant ; signaux : dépassé, dans les hr.alert_days jours, et « brak szkolenia BHP » si le réglage
--     staff_deadlines.bhp_required vaut 1 (défaut : l'employeur n'admet personne au travail sans formation BHP).
-- Une décision par élément (badania d'un salarié, type de szkolenie d'un salarié) et par mois (n_staff_deadline_review) :
-- « Umówione » (date du rendez-vous obligatoire, note ; reprise les mois suivants jusqu'à cette date), « Aktualne —
-- sprawdzone » (acceptée seulement quand le signal a disparu : date mise à jour dans HR ou szkolenie saisi ici), « Nie
-- dotyczy » (note obligatoire, reprise tant que les signaux sont les mêmes) ; « Zakończ » écrit n_wizard_done (code
-- staff_deadlines_reviewed). Prendre rendez-vous est un geste humain : rien n'est envoyé (ni e-mail, ni SMS).
--  - matrice des obligations (237) : les lignes KIEROWNIK_PRODUKCJI « Badania i szkolenia (terminy) » → staff_deadlines_reviewed ;
--  - tâche du poste KIEROWNIK_PRODUKCJI (co miesiąc, 3e jour ouvré) ; droit « staff_deadlines » pour ce poste seulement (il a
--    déjà « hr », que l'assistant exige aussi : pas d'autre droit).
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_staff_training (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_employee  INT NOT NULL COMMENT 'n_prod_employee.id (bez klucza obcego)',
    kind         ENUM('bhp','sanitary','first_aid','fire','equipment','other') NOT NULL COMMENT 'bhp = BHP (wstępne / okresowe) ; sanitary = higieniczno-sanitarne / HACCP ; first_aid = pierwsza pomoc ; fire = ppoż. ; equipment = obsługa urządzeń (np. UDT) ; other = inne',
    title        VARCHAR(160) NULL COMMENT 'nazwa szkolenia (opcjonalnie)',
    done_on      DATE NULL COMMENT 'data szkolenia',
    valid_until  DATE NULL COMMENT 'ważne do ; NULL = bezterminowe',
    note         VARCHAR(255) NULL,
    active       TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = usunięte (błędny wpis)',
    created_by   INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by   INT NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_training_employee (id_employee, kind, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Szkolenia pracowników i ich terminy [404]';

CREATE TABLE IF NOT EXISTS n_staff_deadline_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień miesiąca przeglądu',
    item_key     VARCHAR(80) NOT NULL COMMENT 'element : medical:<id_employee>, training:<id_employee>:<kind>',
    kind         ENUM('medical','training') NOT NULL,
    id_employee  INT NOT NULL COMMENT 'n_prod_employee.id (bez klucza obcego)',
    decision     ENUM('booked','current','not_applicable') NOT NULL COMMENT 'booked = umówione ; current = aktualne — sprawdzone ; not_applicable = nie dotyczy',
    booked_on    DATE NULL COMMENT 'umówione : data badań / szkolenia',
    note         VARCHAR(500) NULL COMMENT 'wymagana dla not_applicable',
    flags        VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_deadline_review (month_start, item_key),
    KEY idx_staff_deadline_review_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Badania i szkolenia : decyzja dla terminu w miesiącu [404]';

-- Réglage : Ustawienia › Parametry (bornes dans ParamRules). Le délai d'alerte est celui du tableau HR (hr.alert_days, 170,
-- réglé dans HR › Ustawienia) : pas de second délai.
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'staff_deadlines.bhp_required', '1', '1', 'int', NULL,
    'Badania i szkolenia : 1 = brak szkolenia BHP w rejestrze to sygnał dla każdego aktywnego pracownika ; 0 = bez tego sygnału',
    'Visites et formations : 1 = l''absence de formation BHP au registre est un signal pour chaque salarié actif ; 0 = sans ce signal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'staff_deadlines.bhp_required');

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 9.5 et 9.6 (monthly).
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.wizard_code = 'staff_deadlines_reviewed', d.proposed_wizard = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.proposed_wizard = 'Badania i szkolenia (terminy)';

-- Tâche du poste (co miesiąc, 3e jour ouvré) : ne double pas une tâche existante (même code ou même lien actif).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'ADMIN' LIMIT 1),
    'Badania i szkolenia : badania lekarskie i szkolenia (BHP…) po terminie, wkrótce albo bez daty → umówione, aktualne, nie dotyczy (asystent)',
    'Visites et formations : visites médicales et formations (BHP…) échues, bientôt échues ou sans date → rendez-vous, à jour, sans objet (assistant)',
    'monthly', 20, 'wd3', '/hr/deadlines', 'staff_deadlines_reviewed',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'staff_deadlines_reviewed')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/hr/deadlines');

-- Droit de l'assistant : Kierownik produkcji seulement (l'administrateur voit tout). Les données HR restent derrière le
-- droit « hr » (vérifié aussi par l'assistant) : rien de plus n'est donné.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'staff_deadlines' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';
