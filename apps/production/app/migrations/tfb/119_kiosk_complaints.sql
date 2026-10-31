-- Tablet produkcji (28/10/2026) : reklamacje klientów przyjęte przez biuro (acceptance_status = ACCEPTED), informacyjnie
-- dla grupy, która robi produkt. Rejouable.
-- 1. n_complaint_translation : tłumaczenie komentarza klienta i odpowiedzi biura na język tabletu (DeepL, gdy włączony) —
--    raz na reklamację i język ; nowe tłumaczenie, gdy tekst się zmienił (skrót sha1).
-- 2. n_translation_log.context : nowy kontekst « complaint » (licznik znaków DeepL).

CREATE TABLE IF NOT EXISTS n_complaint_translation (
    id_complaint INT NOT NULL,
    lang CHAR(2) NOT NULL,
    source_hash CHAR(40) NOT NULL COMMENT 'sha1 komentarza i odpowiedzi w chwili tłumaczenia',
    source_lang CHAR(2) NULL,
    comment_text TEXT NULL,
    response_text TEXT NULL,
    translated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_complaint, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE n_translation_log MODIFY COLUMN context ENUM('mail_in', 'mail_out', 'footer', 'communication', 'complaint') NOT NULL;
