-- [270] Poczta dla Kierownika produkcji, ustawiana i zatrzymywana przez administratora (demande du 11/11/2026).
--   n_user_mail_admin : na użytkownika (niezależnie od podłączonej skrzynki, zostaje po jej odłączeniu)
--     managed = 1 : skrzynkę ustawia tylko administrator (Poczta › Skrzynki użytkowników) ; użytkownik jej nie zmienia ;
--     stopped = 1 : poczta wstrzymana przez administratora (bez odbioru, wysyłki, licznika i synchronizacji w cronie ;
--                   wiadomości zostają w ERP, wracają po wznowieniu).
--   Kierownik produkcji : « Poczta » w menu stanowiska ; użytkownicy tego stanowiska — skrzynka ustawiana przez administratora.
CREATE TABLE IF NOT EXISTS n_user_mail_admin (
    id_user INT NOT NULL PRIMARY KEY,
    managed TINYINT(1) NOT NULL DEFAULT 0,
    stopped TINYINT(1) NOT NULL DEFAULT 0,
    stopped_at DATETIME NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_n_user_mail_admin_user FOREIGN KEY (id_user) REFERENCES user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'mail', NULL FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';

INSERT IGNORE INTO n_user_mail_admin (id_user, managed)
SELECT up.id_user, 1 FROM n_user_position up JOIN n_position p ON p.id = up.id_position WHERE p.code = 'KIEROWNIK_PRODUKCJI';
