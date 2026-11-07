-- Pointage (tablette fixe au bureau, QR scanné au téléphone) : questions à confirmer avant « OK, zatwierdzam »,
-- paramétrables (texte PL / FR, à l'entrée, à la sortie ou aux deux, obligatoire ou non, actives).
CREATE TABLE IF NOT EXISTS tfb_clock_question (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    text_pl VARCHAR(200) NOT NULL,
    text_fr VARCHAR(200) NULL,
    direction ENUM('in', 'out', 'both') NOT NULL DEFAULT 'in',
    required TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réponses données au pointage (texte de la question figé : la question peut changer ensuite).
CREATE TABLE IF NOT EXISTS tfb_clock_answer (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_clock BIGINT NOT NULL,
    direction ENUM('in', 'out') NOT NULL,
    id_question INT NOT NULL,
    question VARCHAR(200) NOT NULL,
    answer TINYINT(1) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tfb_clock_answer (id_clock, direction, id_question)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tfb_clock_question (text_pl, text_fr, direction, required, active, sort_order)
SELECT * FROM (
    SELECT 'Jestem w odzieży roboczej (czepek, fartuch, obuwie)' AS text_pl, 'Je suis en tenue de travail (charlotte, blouse, chaussures)' AS text_fr, 'in' AS direction, 1 AS required, 1 AS active, 10 AS sort_order
    UNION ALL SELECT 'Umyłem / umyłam i zdezynfekowałem / zdezynfekowałam ręce', 'Je me suis lavé et désinfecté les mains', 'in', 1, 1, 20
    UNION ALL SELECT 'Nie mam objawów choroby (gorączka, biegunka, wymioty, rany)', 'Je n''ai pas de symptômes (fièvre, diarrhée, vomissements, plaies)', 'in', 1, 1, 30
    UNION ALL SELECT 'Zostawiam stanowisko czyste i uporządkowane', 'Je laisse mon poste propre et rangé', 'out', 1, 1, 40
) seed
WHERE NOT EXISTS (SELECT 1 FROM tfb_clock_question);
