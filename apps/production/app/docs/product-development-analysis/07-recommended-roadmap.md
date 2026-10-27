# Rekomendowana roadmapa

## Etap A — szybkie usprawnienia

**Cel:** użytkownik widzi następny krok i odzyskuje błędną komunikację bez CLI.

Funkcje: QW-01–QW-05, QW-08, QW-12–QW-14; FEAT-005, FEAT-006, FEAT-011–FEAT-013 w MVP. Zależności: właściciele terminów/SLA, mapa statusów dokumentów. Korzyści: mniej pilnowania w e-mailu i mniej pominięć. Ryzyko: nieprecyzyjne definicje terminów generują szum. Warunek rozpoczęcia: potwierdzić, które App/Bin workery są produkcyjne.

## Etap B — domknięcie procesów

**Cel:** przekształcić plan i dokumenty w audytowalne cykle pracy.

Funkcje: FEAT-001, FEAT-003, FEAT-007, FEAT-009, FEAT-012. Zależności: właściciel statusów, decyzja momentu przyjęcia produkcji i zasad korekt. Korzyści: plan kontra wykonanie, kompletne zakupy i powtarzalna historia receptury/kosztu. Ryzyko: próba naraz objąć wszystkie wyjątki. Warunek: MVP dla jednego rodzaju zlecenia i jednego magazynu.

## Etap C — automatyzacja operacyjna

**Cel:** system wykrywa braki i proponuje działania, ale człowiek je zatwierdza.

Funkcje: FEAT-002, FEAT-008, FEAT-015, FEAT-016; alerty terminów, cen i niedoborów. Zależności: canonical stan/rezerwacje i zlecenia z Etapu B. Korzyści: mniej Excela, lepsze wykorzystanie materiałów i kwalifikacji. Ryzyko: automatyzowanie na nieuzgodnionych stanach. Warunek: test uzgodnienia stanów i pilotaż na wybranych surowcach.

## Etap D — kontrola zarządcza

**Cel:** decyzje są oparte na zdefiniowanych, odtwarzalnych danych.

Funkcje: FEAT-010 i FEAT-014, rozszerzenie analizy odpadu/terminowości dostaw/rentowności. Zależności: snapshoty i zamknięcie okresu. Korzyści: widoczne odchylenia i źródło każdego KPI. Ryzyko: prezentacja wskaźników jako faktu przed potwierdzeniem jakości danych. Warunek: oznaczenie jakości danych i kontrolne uzgodnienie okresu.

## Etap E — pełniejsze ERP

**Cel:** objąć wszystkie ruchy i pełną identyfikowalność bez mnożenia równoległych źródeł.

Funkcje: FEAT-004 dla materiałów i wyrobów, lokalizacje, partie/FEFO, rozwinięte QC, portal dostawcy/klienta, grafiki/RCP dopiero po potwierdzeniu potrzeb. Zależności: decyzja o konsolidacji App i Agent oraz migracja danych. Korzyści: traceability, wiarygodny MRP/WMS i analityka. Ryzyko: duża migracja i zmiana sposobu pracy. Warunek: poprawnie działające MVP ruchów wyrobów, właściciel danych i plan cutover.

## Pomysły odroczone

Pełne WMS ze skanerami, automatyczne prognozowanie popytu, rozbudowane RCP/kadry, portal dostawcy/EDI, wielowariantowe budżetowanie oraz zaawansowane BI. Są wartościowe dopiero po uzgodnieniu stanów, zleceń i historycznych kosztów; obecnie ich złożoność przewyższa potwierdzoną wartość.
