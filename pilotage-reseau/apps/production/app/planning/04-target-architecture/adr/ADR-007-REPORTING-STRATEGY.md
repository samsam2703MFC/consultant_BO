# ADR-007 — Read models w bazie operacyjnej na początek

**Status:** proponowany do akceptacji

## Kontekst

Dane są nieuzgodnione, a skala i obciążenie nie zostały zmierzone. Hurtownia nie naprawi semantyki źródeł.

## Opcje

1. bezpośrednie zapytania do tabel operacyjnych;
2. widoki i odbudowywalne agregaty w tej samej DB;
3. read replica;
4. osobna analytics DB/hurtownia od początku.

## Decyzja

Stosować query services, SQL views i incremental aggregate tables w tej samej DB, z fact snapshots, watermark, version i reconciliation.

## Uzasadnienie

Najniższy koszt i prosty lineage; pozwala najpierw ustalić wiarygodne fakty.

## Konsekwencje

- read models nie są source of truth i muszą być rebuildable;
- duże eksporty async;
- monitoring query plans/locks jest wymagany.

## Ponowne rozważenie

Gdy raporty mimo optymalizacji naruszają SLA operacji, pojawia się wiele niezależnych źródeł lub potrzebna jest odrębna retencja/BI.
