-- Aktywność w ERP (rentowność czasu pracy w ERP) : sesje użytkowników, wyświetlenia stron z czasem aktywnym
-- i liczbą kliknięć, kliknięcia według elementu (przycisk / link) i strony, koszt godziny użytkownika.
-- Bez treści wpisywanych w formularze : element kliknięcia = etykieta przycisku albo trasa linku.

CREATE TABLE IF NOT EXISTS tfb_activity_session (
    id            BIGINT NOT NULL AUTO_INCREMENT,
    id_user       INT NOT NULL,
    started_at    DATETIME NOT NULL,
    last_seen_at  DATETIME NOT NULL,
    user_agent    VARCHAR(160) NULL,
    PRIMARY KEY (id),
    KEY ix_tfb_act_session_user (id_user, last_seen_at),
    KEY ix_tfb_act_session_start (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_activity_view (
    id            BIGINT NOT NULL AUTO_INCREMENT,
    id_session    BIGINT NOT NULL,
    id_user       INT NOT NULL,
    route         VARCHAR(190) NOT NULL COMMENT 'ścieżka z identyfikatorami zastąpionymi {id}',
    path          VARCHAR(255) NOT NULL,
    page_key      VARCHAR(40) NULL COMMENT 'pozycja katalogu stron (PageAccess) ; NULL = strona wspólna',
    title         VARCHAR(160) NULL,
    started_at    DATETIME NOT NULL,
    last_seen_at  DATETIME NOT NULL,
    active_s      INT NOT NULL DEFAULT 0 COMMENT 'sekundy z aktywnością (karta widoczna, ruch w ostatniej minucie)',
    clicks        INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_tfb_act_view_session (id_session),
    KEY ix_tfb_act_view_user (id_user, started_at),
    KEY ix_tfb_act_view_start (started_at),
    CONSTRAINT fk_tfb_act_view_session FOREIGN KEY (id_session) REFERENCES tfb_activity_session (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_activity_click (
    day           DATE NOT NULL,
    route         VARCHAR(190) NOT NULL,
    target        VARCHAR(120) NOT NULL,
    clicks        INT NOT NULL DEFAULT 0,
    PRIMARY KEY (day, route, target)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_user_cost (
    id_user       INT NOT NULL,
    hourly_cost   DECIMAL(10,2) NOT NULL COMMENT 'koszt godziny pracy (zł brutto pracodawcy)',
    updated_by    INT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_user),
    CONSTRAINT fk_tfb_user_cost_user FOREIGN KEY (id_user) REFERENCES user (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
