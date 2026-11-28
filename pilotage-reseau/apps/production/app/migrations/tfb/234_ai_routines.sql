-- [387] Rutyny poranne AI (pilot) : chaque matin, pour les signaux des assistants « Zamówienia dnia »
-- (orders_day_checked), « Opóźnienia dostaw » (deliveries_delays_checked) et « Faktury i KSeF dnia »
-- (invoices_ksef_checked), l'IA prépare une PROPOSITION de décision (décision, note en brouillon, raison, confiance).
-- L'humain valide dans l'étape 2 de l'assistant (« Przyjmij » / « Popraw » / « Odrzuć ») : l'IA n'enregistre jamais
-- de décision, n'envoie rien (ni e-mail, ni SMS, ni KSeF) et n'écrit dans aucune table de l'ERP — seulement ici.
-- Vers l'API Anthropic ne part qu'une charge anonymisée (clients, personnes, véhicules → codes K1, P1, V1… ; ni nom,
-- ni e-mail, ni téléphone, ni adresse, ni NIP) ; l'ERP remet les vrais noms à l'affichage.
--  - n_ai_routine_run : un passage (assistant, jour) — statut, modèle servi, tokens, coût, nombre de propositions,
--    correspondance code → clé stable (customer:12, person:<empreinte>…, jamais un nom) pour l'affichage ;
--  - n_ai_routine_proposal : une proposition par ligne signalée de l'assistant (ref = id de la commande / de
--    l'expédition), statut pending → accepted (enregistrée telle quelle) / edited (modifiée) / rejected (« Odrzuć ») ;
--  - n_prod_param ai.routines_* : interrupteur général (DÉFAUT 0 : rien ne tourne avant que l'administrateur l'allume),
--    modèle, budget mensuel (zł), taux USD → PLN pour le coût affiché, et pour chaque assistant du pilote une heure et
--    un interrupteur (bornes numériques dans ParamRules ; heures HH:MM vérifiées par MorningRoutineService).
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT IGNORE. Pas de clé étrangère vers les tables de l'ERP.

CREATE TABLE IF NOT EXISTS n_ai_routine_run (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                VARCHAR(40) NOT NULL COMMENT 'kod asystenta (orders_day_checked, deliveries_delays_checked, invoices_ksef_checked)',
    day                 DATE NOT NULL COMMENT 'dzień asystenta',
    trigger_kind        ENUM('cron','manual') NOT NULL DEFAULT 'cron' COMMENT 'cron = Bin/ai_routines.php ; manual = « Uruchom teraz » w Ustawieniach',
    started_at          DATETIME NOT NULL,
    finished_at         DATETIME NULL,
    status              ENUM('ok','error','refused','skipped_budget','skipped_off','no_signals') NOT NULL,
    model               VARCHAR(60) NULL COMMENT 'model zamówiony (ai.routines_model)',
    model_served        VARCHAR(60) NULL COMMENT 'model, który odpowiedział (fallback po stronie serwera może go zmienić)',
    input_tokens        INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens       INT UNSIGNED NOT NULL DEFAULT 0,
    cache_read_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
    cache_write_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
    cost_usd            DECIMAL(10,5) NOT NULL DEFAULT 0,
    cost_pln            DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT 'cost_usd × ai.routines_usd_pln w chwili przebiegu',
    signals             INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'linie wysłane do AI (z sygnałem, bez wyniku)',
    proposals           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'propozycje przyjęte przez walidację',
    rejected            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'propozycje odrzucone przez walidację (nieznana ref, decyzja spoza listy…)',
    summary             VARCHAR(500) NULL COMMENT 'podsumowanie AI (z kodami K1…, bez nazw)',
    codes_json          TEXT NULL COMMENT 'kod → klucz stały (customer:12, person:<skrót>) ; bez nazw',
    error               VARCHAR(255) NULL COMMENT 'krótki opis błędu, bez danych',
    id_user             INT NULL COMMENT 'kto uruchomił (manual)',
    PRIMARY KEY (id),
    KEY idx_ai_routine_run_day (code, day),
    KEY idx_ai_routine_run_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Rutyny poranne AI : przebiegi [387]';

CREATE TABLE IF NOT EXISTS n_ai_routine_proposal (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_run       INT UNSIGNED NOT NULL COMMENT 'n_ai_routine_run.id',
    code         VARCHAR(40) NOT NULL,
    day          DATE NOT NULL,
    ref          VARCHAR(40) NOT NULL COMMENT 'linia asystenta : id zamówienia (zamówienia, dostawy) albo wysyłki (KSeF)',
    decision     VARCHAR(20) NOT NULL COMMENT 'jeden z wyników asystenta',
    note         VARCHAR(500) NULL COMMENT 'szkic notatki (kody K1… zamieniane na nazwy przy wyświetleniu)',
    reason       VARCHAR(500) NULL,
    confidence   ENUM('high','medium','low') NOT NULL,
    status       ENUM('pending','accepted','edited','rejected') NOT NULL DEFAULT 'pending',
    final_decision VARCHAR(20) NULL COMMENT 'decyzja zapisana przez człowieka (accepted / edited)',
    decided_by   INT NULL,
    decided_at   DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_routine_proposal_day (code, day, status),
    KEY idx_ai_routine_proposal_run (id_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Rutyny poranne AI : propozycje decyzji [387]';

-- Réglages (Ustawienia › Asystent AI › Rutyny poranne ; bornes dans ParamRules).
INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('ai.routines_enabled', '0', '0', 'bool', NULL,
 'Rutyny poranne AI : wyłącznik główny (0 = nic nie jest wysyłane do AI)',
 'Routines IA du matin : interrupteur général (0 = rien n''est envoyé à l''IA)'),
('ai.routines_model', 'claude-opus-5-5', 'claude-opus-5-5', 'string', NULL,
 'Rutyny poranne AI : model (claude-opus-5-5 albo claude-sonnet-5-5)',
 'Routines IA du matin : modèle (claude-opus-5-5 ou claude-sonnet-5-5)'),
('ai.routines_budget_pln', '50', '50', 'decimal', 'zł',
 'Rutyny poranne AI : budżet miesięczny — po jego wyczerpaniu rutyny się zatrzymują do końca miesiąca',
 'Routines IA du matin : budget mensuel — une fois épuisé, les routines s''arrêtent jusqu''à la fin du mois'),
('ai.routines_usd_pln', '4.0', '4.0', 'decimal', 'zł/USD',
 'Rutyny poranne AI : kurs USD → PLN do pokazania kosztu (cennik Anthropic w USD)',
 'Routines IA du matin : taux USD → PLN pour afficher le coût (tarif Anthropic en USD)'),
('ai.routines_time.orders_day_checked', '06:30', '06:30', 'time', NULL,
 'Rutyny poranne AI : godzina propozycji dla « Zamówienia dnia »',
 'Routines IA du matin : heure des propositions pour « Zamówienia dnia »'),
('ai.routines_time.deliveries_delays_checked', '06:30', '06:30', 'time', NULL,
 'Rutyny poranne AI : godzina propozycji dla « Opóźnienia dostaw »',
 'Routines IA du matin : heure des propositions pour « Opóźnienia dostaw »'),
('ai.routines_time.invoices_ksef_checked', '07:00', '07:00', 'time', NULL,
 'Rutyny poranne AI : godzina propozycji dla « Faktury i KSeF dnia »',
 'Routines IA du matin : heure des propositions pour « Faktury i KSeF dnia »'),
('ai.routines_on.orders_day_checked', '1', '1', 'bool', NULL,
 'Rutyny poranne AI : « Zamówienia dnia » włączone (działa tylko z wyłącznikiem głównym)',
 'Routines IA du matin : « Zamówienia dnia » active (seulement avec l''interrupteur général)'),
('ai.routines_on.deliveries_delays_checked', '1', '1', 'bool', NULL,
 'Rutyny poranne AI : « Opóźnienia dostaw » włączone (działa tylko z wyłącznikiem głównym)',
 'Routines IA du matin : « Opóźnienia dostaw » active (seulement avec l''interrupteur général)'),
('ai.routines_on.invoices_ksef_checked', '1', '1', 'bool', NULL,
 'Rutyny poranne AI : « Faktury i KSeF dnia » włączone (działa tylko z wyłącznikiem głównym)',
 'Routines IA du matin : « Faktury i KSeF dnia » active (seulement avec l''interrupteur général)');
