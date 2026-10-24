-- WDT zastąpione późniejszym dokumentem (ten sam numer, klient, data i te same palety) : pomijane
-- w analizach sprzedaży, żeby wysyłka nie liczyła się dwa razy. Dokumenty WDT i KSeF bez zmian —
-- wpis tylko oznacza, który z dwóch dokumentów analizy pomijają (usunięcie wpisu przywraca liczenie).

CREATE TABLE IF NOT EXISTS tfb_wdt_superseded (
    id_document_wdt INT NOT NULL PRIMARY KEY,
    superseded_by INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 20263859 (15.09.2026, Berdiff) : 769 (17 palet, wysłany do KSeF) i 773 (te same palety + 4199,
-- powiązany z zamówieniem). 20253259 (06.08.2025) : 530 i 531 identyczne, zamówienie wskazuje 531.
-- Wpis tylko gdy para nadal się zgadza (numer, klient, data) — inna baza nie dostaje błędnego wpisu.
INSERT IGNORE INTO tfb_wdt_superseded (id_document_wdt, superseded_by, reason)
SELECT a.id, b.id, CONCAT('Duplikat WDT ', a.doc_num, ' z ', LEFT(a.established_date, 10), ' — zastąpiony przez WDT #', b.id, ' (te same palety)')
FROM document_wdt a
JOIN document_wdt b ON (a.id, b.id) IN ((769, 773), (530, 531))
WHERE a.doc_num = b.doc_num AND a.id_customer = b.id_customer AND LEFT(a.established_date, 10) = LEFT(b.established_date, 10);
