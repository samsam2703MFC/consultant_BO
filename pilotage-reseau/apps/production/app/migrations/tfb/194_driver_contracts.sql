-- [309] Kierowcy (makieta 57) : etat (umowa o pracę, pełna karta HR) albo zlecenie (tylko dane do umowy). Rodzaj
--   współpracy = karta HR (n_hr_employee.contract_type : 'zlecenie' albo umowa o pracę). Tu dane transportowe
--   kierowcy : kategoria prawa jazdy i wynagrodzenie za przewóz (za kurs, za godzinę, za km, ryczałt miesięczny).
CREATE TABLE IF NOT EXISTS n_log_driver (
    id_employee INT NOT NULL PRIMARY KEY COMMENT 'n_prod_employee.id',
    licence     VARCHAR(20) NULL COMMENT 'kategoria prawa jazdy (C, C+E…)',
    pay_basis   ENUM('kurs', 'godzina', 'km', 'ryczalt') NULL COMMENT 'zlecenie : podstawa wynagrodzenia za przewóz',
    pay_amount  DECIMAL(10, 2) NULL COMMENT 'zlecenie : kwota brutto za jednostkę (kurs, godzinę, km, miesiąc)',
    updated_by  INT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Szablon (HR › Szablony umów, edytowalny) używany z Logistyki : umowa zlecenia na przewóz towaru z danymi kierowcy.
INSERT IGNORE INTO n_hr_template (code, title, legal, kind, sort, body) VALUES
('zlecenie_transport', 'Umowa zlecenia na przewóz towaru (kierowca)', 'Kodeks cywilny art. 734 ; czas pracy kierowcy : rozporządzenie (WE) 561/2006 ; stawka godzinowa ≥ minimalnej', 'umowa', 7,
'UMOWA ZLECENIA NA PRZEWÓZ TOWARU

zawarta w dniu {data_zawarcia} w {miejscowosc} pomiędzy {firma}, {adres_firmy}, NIP {nip_firmy}, reprezentowaną przez {reprezentant}, zwaną dalej Zleceniodawcą, a {pracownik}, zamieszkałym/ą {adres_pracownika}, PESEL {pesel}, posiadającym/ą prawo jazdy kategorii {prawo_jazdy}, zwanym/ą dalej Zleceniobiorcą.

§ 1. Zleceniodawca zleca, a Zleceniobiorca przyjmuje do wykonania przewóz towarów Zleceniodawcy (żywność mrożona i chłodzona) pojazdem {pojazd}, na trasie : {trasa}, w okresie od {data_od} do {data_do}, zgodnie z listą kursów przekazywaną przez Zleceniodawcę.

§ 2. Zleceniobiorca w szczególności : odbiera towar z magazynu Zleceniodawcy, sprawdza liczbę palet i temperaturę ładunku, dostarcza towar odbiorcom w ustalonej kolejności, odbiera podpisane dokumenty przewozowe (CMR, WZ) i potwierdza dostawy na tablecie kierowcy.

§ 3. Zleceniobiorca wykonuje zlecenie osobiście, z należytą starannością, przestrzegając przepisów ruchu drogowego oraz czasu jazdy i odpoczynku kierowców (rozporządzenie (WE) nr 561/2006). Zleceniobiorca dba o powierzony pojazd i towar i niezwłocznie zgłasza Zleceniodawcy szkody, awarie i opóźnienia.

§ 4. Za wykonanie zlecenia Zleceniobiorcy przysługuje wynagrodzenie : {wynagrodzenie_transport}. Podstawą rozliczenia jest ewidencja kursów i godzin prowadzona przez Zleceniodawcę (tablet kierowcy). Wynagrodzenie płatne jest do 10. dnia następnego miesiąca na rachunek bankowy wskazany przez Zleceniobiorcę. Przy rozliczeniu godzinowym stawka nie jest niższa niż minimalna stawka godzinowa obowiązująca w danym roku.

§ 5. Zleceniobiorca zachowuje w tajemnicy dane odbiorców, adresy, kody dostępu i procedury rozładunku przekazane przez Zleceniodawcę, także po zakończeniu umowy.

§ 6. Każda ze stron może wypowiedzieć umowę w każdym czasie, z zachowaniem przepisów Kodeksu cywilnego.

§ 7. W sprawach nieuregulowanych umową stosuje się przepisy Kodeksu cywilnego. Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze stron.');
