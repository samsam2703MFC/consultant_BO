-- Marże kategorii : « Przelicz wszystkie marże » zapisuje każde przeliczenie (kiedy, kto, ile zmian), a
-- tfb_margin_recalc trzyma ostatnią rzeczywistą zmianę kosztu każdego produktu — przeliczenie bez zmian
-- cen surowców nie zeruje już kolumny « Efekt na sztukę ».

CREATE TABLE IF NOT EXISTS tfb_margin_recalc_run (
    id         INT NOT NULL AUTO_INCREMENT,
    run_at     DATETIME NOT NULL,
    run_by     INT NULL,
    recipes    INT NOT NULL DEFAULT 0,
    products   INT NOT NULL DEFAULT 0,
    changed    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_tfb_margin_recalc_run_at (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
