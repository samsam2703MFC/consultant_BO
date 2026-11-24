-- [416] « Wstrzymaj partię » (makieta hali, ekran K « śledzenie partii ») : le Kierownik produkcji / Jakość bloque une partie
-- en attendant la décision Jakość, avec confirmation, depuis la tablette qualité. Blocage SÛR : aucun statut de carton_stock
-- ni aucune formule de stock n'est modifié ; le blocage est enregistré ici et lu par :
--   - la palettisation de la tablette d'emballage (kartony du produit posés sur une palette : refus tant que l'opérateur
--     ne confirme pas que les cartons ne viennent pas de la partie bloquée) ;
--   - le chargement de la tablette chauffeur (place de la remorque « załadowane » refusée si la palette porte des cartons
--     de la partie bloquée) ;
--   - la tablette qualité (badge « Wstrzymana », levée par Kierownik produkcji / Jakość avec une note).
-- Partie = produit + jours d'étiquette des cartons (carton_stock.date_add du jour de production au jour + trace.label_days),
-- le même lien que la traçabilité de l'ERP (l'étiquette du carton ne porte pas le numéro de partie de la tablette).
-- Rejouable : CREATE TABLE IF NOT EXISTS. Pas de clé étrangère vers les tables de l'ERP.

CREATE TABLE IF NOT EXISTS n_qc_hold (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_output             BIGINT NULL COMMENT 'n_prod_output.id : partia, od której wstrzymano',
    lot_code              VARCHAR(32) NULL COMMENT 'numer partii w chwili wstrzymania',
    id_final_product      INT NOT NULL COMMENT 'final_product.id (kartony carton_stock.id_product)',
    label_from            DATE NOT NULL COMMENT 'pierwszy dzień etykiety kartonów partii',
    label_to              DATE NOT NULL COMMENT 'ostatni dzień etykiety kartonów partii',
    reason                VARCHAR(500) NOT NULL,
    held_at               DATETIME NOT NULL,
    held_by_employee      INT NULL COMMENT 'n_prod_employee.id',
    held_device           INT NULL COMMENT 'n_prod_device.id',
    released_at           DATETIME NULL COMMENT 'NULL = partia wstrzymana',
    released_by_employee  INT NULL,
    release_note          VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_qc_hold_product (id_final_product, released_at),
    KEY idx_qc_hold_output (id_output)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Partie wstrzymane do decyzji Jakości (bez zmiany stanów kartonów) [416]';
