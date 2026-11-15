-- [277] Finanse › Płace (makieta 41, validée le 12/11/2026) : liste de paie du mois selon le droit polonais, fiche de
--   paie à imprimer, virements des salaires en XML ISO 20022 pain.001 (« paczka płacowa », import ING Business), CSV
--   pour le bureau comptable. ZUS et impôt (PIT-4R) affichés avec leur montant, payés à part (décision du 12/11/2026).
-- [276] HR › Szablony umów (makieta 42) : modèles de contrats et d'écrits RH remplis depuis la fiche HR.
-- Barèmes par année (n_pay_rate), chacun avec sa source ; à revoir chaque 1er janvier (Ustawienia płac).
-- Données personnelles chiffrées (HrCipher) : compte bancaire, adresse, PESEL. Montants de la paie : accès « payroll »
--   (administrateur, Administracja i fakturowanie). Rejouable : CREATE IF NOT EXISTS, INSERT IGNORE, ALTER conditionnel.

-- Fiche HR : ce qu'il faut pour la paie et les contrats.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_hr_employee' AND COLUMN_NAME = 'pay_type');
SET @sql := IF(@c = 0, 'ALTER TABLE n_hr_employee
    ADD COLUMN pay_type ENUM(''monthly'', ''hourly'') NULL COMMENT ''wynagrodzenie miesięczne albo stawka godzinowa'' AFTER emergency_enc,
    ADD COLUMN pay_amount DECIMAL(10,2) NULL COMMENT ''brutto : miesięcznie albo za godzinę'' AFTER pay_type,
    ADD COLUMN bank_enc VARCHAR(400) NULL COMMENT ''numer rachunku chiffré (HrCipher)'' AFTER pay_amount,
    ADD COLUMN address_enc VARCHAR(700) NULL COMMENT ''adresse chiffrée (HrCipher)'' AFTER bank_enc,
    ADD COLUMN pesel_enc VARCHAR(400) NULL COMMENT ''PESEL chiffré (HrCipher)'' AFTER address_enc,
    ADD COLUMN pit2 TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''PIT-2 złożony : kwota zmniejszająca podatek'' AFTER pesel_enc,
    ADD COLUMN kup_raised TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''podwyższone koszty uzyskania przychodu (dojazd)'' AFTER pit2,
    ADD COLUMN young_relief TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''ulga dla młodych < 26 lat (gdy wiek pozwala)'' AFTER kup_raised,
    ADD COLUMN student TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''zlecenie : uczeń / student < 26 lat, bez ZUS'' AFTER young_relief,
    ADD COLUMN sick_insurance TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''zlecenie : dobrowolne chorobowe'' AFTER student',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Barèmes de l'année (code => valeur, source). Les taux sont en pour cent.
CREATE TABLE IF NOT EXISTS n_pay_rate (
    year       SMALLINT NOT NULL,
    code       VARCHAR(40) NOT NULL,
    value      DECIMAL(12,4) NOT NULL,
    source     VARCHAR(255) NULL,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (year, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_pay_rate (year, code, value, source) VALUES
(2026, 'min_wage', 4806.00, 'Rozporządzenie Rady Ministrów (Dz.U. 2025 poz. 1242)'),
(2026, 'min_hourly', 31.40, 'Rozporządzenie Rady Ministrów (Dz.U. 2025 poz. 1242)'),
(2026, 'zus_cap', 282600.00, 'Obwieszczenie MRPiPS z 19.11.2025 (M.P. 2025 poz. 1206) : 30 × 9 420 zł'),
(2026, 'emp_pension', 9.76, 'Ustawa o systemie ubezpieczeń społecznych, art. 22'),
(2026, 'emp_disability', 1.50, 'Ustawa o systemie ubezpieczeń społecznych, art. 22'),
(2026, 'emp_sickness', 2.45, 'Ustawa o systemie ubezpieczeń społecznych, art. 22'),
(2026, 'health', 9.00, 'Ustawa o świadczeniach opieki zdrowotnej, art. 79'),
(2026, 'er_pension', 9.76, 'Ustawa o systemie ubezpieczeń społecznych, art. 22'),
(2026, 'er_disability', 6.50, 'Ustawa o systemie ubezpieczeń społecznych, art. 22'),
(2026, 'er_accident', 1.67, 'Stopa wypadkowa firmy (ZUS, informacja o stopie) — sprawdzić'),
(2026, 'er_fp', 2.45, 'Ustawa o promocji zatrudnienia, art. 104'),
(2026, 'er_fgsp', 0.10, 'Ustawa o ochronie roszczeń pracowniczych, art. 29'),
(2026, 'kup', 250.00, 'Ustawa o PIT, art. 22 ust. 2 pkt 1'),
(2026, 'kup_raised', 300.00, 'Ustawa o PIT, art. 22 ust. 2 pkt 2'),
(2026, 'kup_civil', 20.00, 'Ustawa o PIT, art. 22 ust. 9 pkt 4 (umowa zlecenie)'),
(2026, 'pit_low', 12.00, 'Ustawa o PIT, art. 27 ust. 1'),
(2026, 'pit_high', 32.00, 'Ustawa o PIT, art. 27 ust. 1'),
(2026, 'pit_threshold', 120000.00, 'Ustawa o PIT, art. 27 ust. 1'),
(2026, 'pit_reduction', 300.00, 'Ustawa o PIT, art. 32 ust. 3 (PIT-2 : 1/12 z 3 600 zł)'),
(2026, 'young_limit', 85528.00, 'Ustawa o PIT, art. 21 ust. 1 pkt 148 (ulga dla młodych)'),
(2026, 'sick_pay', 80.00, 'Ustawa zasiłkowa, art. 11 ; Kodeks pracy art. 92 (wynagrodzenie chorobowe)'),
(2026, 'sick_days_employer', 33.00, 'Kodeks pracy art. 92 § 1 (do 33 dni w roku płaci pracodawca)');

-- Mois de paie : brouillon (calcul en direct) puis approuvé (lignes figées) ; fichier XML pour la banque.
CREATE TABLE IF NOT EXISTS n_pay_run (
    month       CHAR(7) NOT NULL PRIMARY KEY COMMENT 'YYYY-MM',
    status      ENUM('draft', 'approved') NOT NULL DEFAULT 'draft',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    totals      TEXT NULL COMMENT 'JSON : brutto, netto, ZUS, zdrowotna, PIT, koszt',
    xml_at      DATETIME NULL,
    xml_by      INT NULL,
    exec_date   DATE NULL COMMENT 'date d''exécution des virements'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ligne figée à l'approbation (détail JSON + cumuls de l'année : plafond ZUS, seuil 32 %, ulga dla młodych, L4).
CREATE TABLE IF NOT EXISTS n_pay_line (
    id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    month          CHAR(7) NOT NULL,
    id_employee    INT NOT NULL COMMENT 'n_prod_employee.id',
    kind           ENUM('praca', 'zlecenie') NOT NULL,
    gross          DECIMAL(12,2) NOT NULL,
    net            DECIMAL(12,2) NOT NULL,
    employer_cost  DECIMAL(12,2) NOT NULL,
    pension_base   DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'podstawa emerytalna/rentowa (limit 30-krotności)',
    tax_base       DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'podstawa opodatkowania (próg 32 %)',
    exempt_revenue DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'przychód zwolniony (ulga dla młodych)',
    sick_days      INT NOT NULL DEFAULT 0,
    detail         MEDIUMTEXT NOT NULL COMMENT 'JSON : składniki paska',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_n_pay_line (month, id_employee),
    KEY idx_n_pay_line_employee (id_employee, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Saisies du mois avant approbation : nadgodziny płatne, premia, potrącenia (netto).
CREATE TABLE IF NOT EXISTS n_pay_adjust (
    month         CHAR(7) NOT NULL,
    id_employee   INT NOT NULL,
    overtime50_h  DECIMAL(6,2) NOT NULL DEFAULT 0,
    overtime100_h DECIMAL(6,2) NOT NULL DEFAULT 0,
    bonus         DECIMAL(10,2) NOT NULL DEFAULT 0,
    deduction     DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'potrącenie z netto (zaliczka, komornik…)',
    note          VARCHAR(255) NULL,
    updated_by    INT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (month, id_employee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- [276] Modèles de contrats : texte avec champs {pracownik}, {stanowisko}… ; modifiables par l'administrateur.
CREATE TABLE IF NOT EXISTS n_hr_template (
    code       VARCHAR(40) NOT NULL PRIMARY KEY,
    title      VARCHAR(160) NOT NULL,
    legal      VARCHAR(160) NULL COMMENT 'podstawa prawna / uwaga',
    kind       ENUM('umowa', 'aneks', 'inne') NOT NULL DEFAULT 'umowa' COMMENT 'rodzaj dokumentu w karcie HR',
    body       MEDIUMTEXT NOT NULL,
    sort       INT NOT NULL DEFAULT 0,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO n_hr_template (code, title, legal, kind, sort, body) VALUES
('praca_nieokreslony', 'Umowa o pracę — na czas nieokreślony', 'Kodeks pracy art. 25, 29', 'umowa', 1,
'UMOWA O PRACĘ NA CZAS NIEOKREŚLONY

zawarta w dniu {data_zawarcia} w {miejscowosc} pomiędzy {firma}, {adres_firmy}, NIP {nip_firmy}, reprezentowaną przez {reprezentant}, zwaną dalej Pracodawcą, a {pracownik}, zamieszkałym/ą {adres_pracownika}, PESEL {pesel}, zwanym/ą dalej Pracownikiem.

§ 1. Pracodawca zatrudnia Pracownika na czas nieokreślony od dnia {data_od} na stanowisku {stanowisko} w wymiarze {wymiar}.

§ 2. Miejsce wykonywania pracy : {miejsce_pracy}.

§ 3. Wynagrodzenie zasadnicze : {wynagrodzenie} zł brutto miesięcznie, płatne do 10. dnia następnego miesiąca na rachunek bankowy wskazany przez Pracownika.

§ 4. Dobowa norma czasu pracy wynosi 8 godzin, a tygodniowa przeciętnie 40 godzin w przeciętnie pięciodniowym tygodniu pracy, w okresie rozliczeniowym 1 miesiąca. Pora nocna i inne zasady czasu pracy zgodnie z regulaminem pracy.

§ 5. Pracownikowi przysługuje urlop wypoczynkowy w wymiarze określonym w art. 154 Kodeksu pracy.

§ 6. Okres wypowiedzenia umowy zgodnie z art. 36 Kodeksu pracy.

§ 7. W sprawach nieuregulowanych umową stosuje się przepisy Kodeksu pracy. Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze stron.'),
('praca_okreslony', 'Umowa o pracę — na czas określony', 'Kodeks pracy art. 25, 25¹ (do 33 mies., maks. 3 umowy)', 'umowa', 2,
'UMOWA O PRACĘ NA CZAS OKREŚLONY

zawarta w dniu {data_zawarcia} w {miejscowosc} pomiędzy {firma}, {adres_firmy}, NIP {nip_firmy}, reprezentowaną przez {reprezentant}, zwaną dalej Pracodawcą, a {pracownik}, zamieszkałym/ą {adres_pracownika}, PESEL {pesel}, zwanym/ą dalej Pracownikiem.

§ 1. Pracodawca zatrudnia Pracownika na czas określony od dnia {data_od} do dnia {data_do} na stanowisku {stanowisko} w wymiarze {wymiar}.

§ 2. Miejsce wykonywania pracy : {miejsce_pracy}.

§ 3. Wynagrodzenie zasadnicze : {wynagrodzenie} zł brutto miesięcznie, płatne do 10. dnia następnego miesiąca na rachunek bankowy wskazany przez Pracownika.

§ 4. Dobowa norma czasu pracy wynosi 8 godzin, a tygodniowa przeciętnie 40 godzin w przeciętnie pięciodniowym tygodniu pracy, w okresie rozliczeniowym 1 miesiąca.

§ 5. Pracownikowi przysługuje urlop wypoczynkowy w wymiarze określonym w art. 154 Kodeksu pracy, proporcjonalnie do okresu zatrudnienia.

§ 6. Okres wypowiedzenia umowy zgodnie z art. 36 Kodeksu pracy. Łączny okres zatrudnienia na podstawie umów na czas określony nie może przekraczać 33 miesięcy, a łączna liczba tych umów — trzech (art. 25¹ Kodeksu pracy).

§ 7. W sprawach nieuregulowanych umową stosuje się przepisy Kodeksu pracy. Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze stron.'),
('praca_probny', 'Umowa o pracę — na okres próbny', 'Kodeks pracy art. 25 § 2 (do 3 miesięcy)', 'umowa', 3,
'UMOWA O PRACĘ NA OKRES PRÓBNY

zawarta w dniu {data_zawarcia} w {miejscowosc} pomiędzy {firma}, {adres_firmy}, NIP {nip_firmy}, reprezentowaną przez {reprezentant}, zwaną dalej Pracodawcą, a {pracownik}, zamieszkałym/ą {adres_pracownika}, PESEL {pesel}, zwanym/ą dalej Pracownikiem.

§ 1. Pracodawca zatrudnia Pracownika na okres próbny od dnia {data_od} do dnia {data_do} (nie dłużej niż 3 miesiące) w celu sprawdzenia kwalifikacji Pracownika i możliwości jego zatrudnienia na stanowisku {stanowisko}, w wymiarze {wymiar}.

§ 2. Miejsce wykonywania pracy : {miejsce_pracy}.

§ 3. Wynagrodzenie zasadnicze : {wynagrodzenie} zł brutto miesięcznie, płatne do 10. dnia następnego miesiąca na rachunek bankowy wskazany przez Pracownika.

§ 4. Dobowa norma czasu pracy wynosi 8 godzin, a tygodniowa przeciętnie 40 godzin w okresie rozliczeniowym 1 miesiąca.

§ 5. Okres wypowiedzenia umowy na okres próbny zgodnie z art. 34 Kodeksu pracy.

§ 6. W sprawach nieuregulowanych umową stosuje się przepisy Kodeksu pracy. Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze stron.'),
('zlecenie', 'Umowa zlecenie', 'Kodeks cywilny art. 734 ; stawka ≥ minimalnej stawki godzinowej, ewidencja godzin', 'umowa', 4,
'UMOWA ZLECENIE

zawarta w dniu {data_zawarcia} w {miejscowosc} pomiędzy {firma}, {adres_firmy}, NIP {nip_firmy}, reprezentowaną przez {reprezentant}, zwaną dalej Zleceniodawcą, a {pracownik}, zamieszkałym/ą {adres_pracownika}, PESEL {pesel}, zwanym/ą dalej Zleceniobiorcą.

§ 1. Zleceniodawca zleca, a Zleceniobiorca przyjmuje do wykonania : {przedmiot}, w okresie od {data_od} do {data_do}.

§ 2. Zleceniobiorca wykonuje zlecenie osobiście, z należytą starannością, w miejscu : {miejsce_pracy}.

§ 3. Za wykonanie zlecenia Zleceniobiorcy przysługuje wynagrodzenie w wysokości {stawka_godzinowa} zł brutto za każdą godzinę wykonywania zlecenia, nie niższe niż minimalna stawka godzinowa obowiązująca w danym roku. Liczba godzin jest potwierdzana w ewidencji prowadzonej przez Zleceniodawcę.

§ 4. Wynagrodzenie płatne jest do 10. dnia następnego miesiąca na rachunek bankowy wskazany przez Zleceniobiorcę, na podstawie ewidencji godzin.

§ 5. Każda ze stron może wypowiedzieć umowę w każdym czasie, z zachowaniem przepisów Kodeksu cywilnego.

§ 6. W sprawach nieuregulowanych umową stosuje się przepisy Kodeksu cywilnego. Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze stron.'),
('dzielo', 'Umowa o dzieło', 'Kodeks cywilny art. 627 ; zgłoszenie do ZUS (RUD)', 'umowa', 5,
'UMOWA O DZIEŁO

zawarta w dniu {data_zawarcia} w {miejscowosc} pomiędzy {firma}, {adres_firmy}, NIP {nip_firmy}, reprezentowaną przez {reprezentant}, zwaną dalej Zamawiającym, a {pracownik}, zamieszkałym/ą {adres_pracownika}, PESEL {pesel}, zwanym/ą dalej Wykonawcą.

§ 1. Zamawiający zamawia, a Wykonawca zobowiązuje się wykonać dzieło : {przedmiot}.

§ 2. Termin wykonania dzieła : {data_do}.

§ 3. Za wykonanie dzieła Wykonawcy przysługuje wynagrodzenie ryczałtowe w wysokości {wynagrodzenie} zł brutto, płatne po odbiorze dzieła, na rachunek bankowy wskazany przez Wykonawcę.

§ 4. Wykonawca oświadcza, że dzieło będzie wynikiem jego samodzielnej pracy. Odbiór dzieła następuje protokołem podpisanym przez obie strony.

§ 5. W sprawach nieuregulowanych umową stosuje się przepisy Kodeksu cywilnego. Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze stron.'),
('aneks', 'Aneks — wynagrodzenie / stanowisko / wymiar', 'Kodeks pracy art. 29 § 4 (zmiana warunków na piśmie)', 'aneks', 6,
'ANEKS DO UMOWY O PRACĘ

zawarty w dniu {data_zawarcia} w {miejscowosc} pomiędzy {firma}, reprezentowaną przez {reprezentant}, zwaną dalej Pracodawcą, a {pracownik}, zwanym/ą dalej Pracownikiem.

§ 1. Strony zgodnie postanawiają, że od dnia {data_od} :
— stanowisko pracy : {stanowisko},
— wymiar czasu pracy : {wymiar},
— wynagrodzenie zasadnicze : {wynagrodzenie} zł brutto miesięcznie.

§ 2. Pozostałe warunki umowy o pracę nie ulegają zmianie.

§ 3. Aneks sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze stron.'),
('wypowiedzenie', 'Wypowiedzenie umowy o pracę', 'Kodeks pracy art. 30, 36 (okres wypowiedzenia wg stażu)', 'inne', 7,
'WYPOWIEDZENIE UMOWY O PRACĘ

{miejscowosc}, {data_zawarcia}

{firma}, reprezentowana przez {reprezentant}, wypowiada umowę o pracę zawartą z Panią / Panem {pracownik} na stanowisku {stanowisko}, z zachowaniem okresu wypowiedzenia wynoszącego {okres_wypowiedzenia}, który upływa z końcem właściwego tygodnia albo miesiąca kalendarzowego (art. 30 § 2¹ Kodeksu pracy).

Przyczyna wypowiedzenia (umowa na czas nieokreślony — art. 30 § 4 Kodeksu pracy) : {przyczyna}.

Pouczenie : od wypowiedzenia przysługuje odwołanie do sądu pracy w terminie 21 dni od dnia doręczenia pisma (art. 264 § 1 Kodeksu pracy).'),
('swiadectwo', 'Świadectwo pracy', 'Kodeks pracy art. 97 ; Rozporządzenie MRPiPS w sprawie świadectwa pracy', 'inne', 8,
'ŚWIADECTWO PRACY

{miejscowosc}, {data_zawarcia}

Pracodawca : {firma}, {adres_firmy}, NIP {nip_firmy}.

1. Stwierdza się, że {pracownik}, PESEL {pesel}, był/a zatrudniony/a w {firma} w okresie od {data_od} do {data_do} w wymiarze {wymiar}.

2. W okresie zatrudnienia pracownik wykonywał pracę na stanowisku : {stanowisko}.

3. Stosunek pracy ustał w wyniku : {przyczyna}.

4. Informacje o urlopie wypoczynkowym, zwolnieniach, okresach nieskładkowych i pozostałe informacje wymagane rozporządzeniem — do uzupełnienia przez kadry przed podpisem.

Pouczenie : pracownik może w ciągu 14 dni od otrzymania świadectwa pracy wystąpić z wnioskiem do pracodawcy o jego sprostowanie, a w razie odmowy — w ciągu 14 dni wystąpić do sądu pracy (art. 97 § 2¹ Kodeksu pracy).'),
('informacja', 'Informacja o warunkach zatrudnienia', 'Kodeks pracy art. 29 § 3 (do 7 dni od dopuszczenia do pracy)', 'inne', 9,
'INFORMACJA O WARUNKACH ZATRUDNIENIA

{miejscowosc}, {data_zawarcia}

Pracodawca {firma} informuje Panią / Pana {pracownik}, zatrudnionego/ą od {data_od} na stanowisku {stanowisko}, że :

1. Dobowa norma czasu pracy wynosi 8 godzin, a tygodniowa przeciętnie 40 godzin w przeciętnie pięciodniowym tygodniu pracy ; okres rozliczeniowy wynosi 1 miesiąc ; wymiar : {wymiar}.

2. Przerwy w pracy, odpoczynek dobowy i tygodniowy oraz zasady pracy w godzinach nadliczbowych i rekompensaty za nią — zgodnie z Kodeksem pracy i regulaminem pracy.

3. Wynagrodzenie wypłacane jest raz w miesiącu, do 10. dnia następnego miesiąca, przelewem na rachunek wskazany przez pracownika.

4. Pracownikowi przysługuje urlop wypoczynkowy w wymiarze określonym w art. 154 Kodeksu pracy.

5. Okres wypowiedzenia umowy zgodnie z art. 36 Kodeksu pracy. Pracodawca nie przewiduje szkoleń poza wymaganymi przepisami BHP, chyba że uzgodniono inaczej.');

-- Réglages de la paie et des contrats (société : erp_company_data, comme les documents WZ).
INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('pay.debtor_iban', '', '', 'string', NULL, 'Płace : rachunek firmy do przelewów wynagrodzeń (puste = rachunek z danych firmy WZ)', 'Paie : compte de la société pour les virements de salaires (vide = compte des données société WZ)'),
('pay.company_city', '', '', 'string', NULL, 'Umowy : miejscowość zawarcia umowy', 'Contrats : lieu de signature'),
('pay.company_rep', '', '', 'string', NULL, 'Umowy : osoba reprezentująca pracodawcę', 'Contrats : représentant de l''employeur'),
('pay.workplace', '', '', 'string', NULL, 'Umowy : miejsce wykonywania pracy (adres zakładu)', 'Contrats : lieu de travail (adresse de l''atelier)');

-- Accès : Płace pour Administracja i fakturowanie (l'administrateur voit tout) ; menu si le poste a son propre menu.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'payroll' FROM n_position p WHERE p.code = 'ADMINISTRACJA';

INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'payroll', NULL FROM n_position p WHERE p.code = 'ADMINISTRACJA'
  AND EXISTS (SELECT 1 FROM n_position_menu m WHERE m.id_position = p.id);
