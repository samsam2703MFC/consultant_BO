# Macierz AS-IS vs TO-BE

Macierz obejmuje wszystkie 42 rodziny procesów TO-BE. `F0–F3` oznacza poziom dowodu AS-IS, a nie kompletność biznesową. `NONE` oznacza brak potwierdzonego odpowiednika.

| Proces TO-BE | Zdolność | Najbliższe funkcje AS-IS | Ocena pokrycia | Główne luki |
|---|---|---|---|---|
| MD-001 | produkt, kategorie, jednostki, opakowania | MASTERDATA-001/002 F3/F2 | PARTIAL | GAP-007/008/048/049 |
| MD-002 | surowiec i parametry jakości | MASTERDATA-003/004 F3 | PARTIAL | GAP-008/029/036/048 |
| MD-003 | półprodukt | RECIPE-002 F3 | PARTIAL | GAP-011/012/013 |
| MD-004 | dostawca | MASTERDATA-005 F3 | PARTIAL | GAP-009/037/039/040 |
| MD-005 | klient | SALES-001 F3 | PARTIAL | GAP-003/010/048 |
| MD-006 | magazyn i lokalizacja | INVENTORY-001/002 F2/F1 | LOW | GAP-004/032/034/036 |
| HR-001 | pracownik, assignment i dostęp | EMPLOYEE-001 F2, ADMIN-001/002 | LOW | GAP-003/004/042 |
| HR-002 | grafik i lokalizacja | EMPLOYEE-002 F1 Agent? | UNVERIFIED | GAP-018/042/043 |
| HR-003 | czas pracy i nieobecność | EMPLOYEE-003 F0 | NONE | GAP-043/046/048 |
| HR-004 | zadanie i raport pracy | EMPLOYEE-002 F1 Agent? | UNVERIFIED | GAP-018/042/044 |
| ADM-001 | organizacja i zakres danych | rozproszone modele App/Agent | LOW | GAP-003/004/042 |
| ADM-002 | naprawa i ręczna korekta | fragmenty CRUD/changelog/outbox | LOW | GAP-006/020/031/051/053 |
| ADM-003 | konfiguracja, numeracja, statusy | ADMIN-003 F3 + config w kodzie | PARTIAL | GAP-005/021/046/049 |
| ADM-004 | import i eksport | eksporty CSV/PDF/JSON, importy punktowe | PARTIAL | GAP-006/049/053 |
| REC-001 | receptura i wersja | RECIPE-001/002/003 F2-F3 | PARTIAL | GAP-011/012/048 |
| REC-002 | przeliczenie kosztu receptury | RECIPE-005 F2 | UNRELIABLE | GAP-013/014/045/048 |
| SAL-001 | sprzedaż detaliczna i płatność | SALES-005/006 F1 Agent? | UNVERIFIED | GAP-016/018/019/020 |
| SAL-002 | zamówienie B2B i realizacja | SALES-003/004 F3/F2 | PARTIAL | GAP-017/023/033/041 |
| SAL-003 | promocja i voucher | SALES-006 F1 Agent? | UNVERIFIED | GAP-015/016/020 |
| SAL-004 | anulowanie, zwrot, korekta | SALES-006/DOCUMENT-003 F1 Agent? | LOW | GAP-020/022/032/046 |
| SAL-005 | raportowanie sprzedaży | ANALYTICS-001/003 F2/F1 | UNRELIABLE | GAP-016/048/055 |
| DOC-001 | wystawienie dokumentu | DOCUMENT-001/002/004 F3 | PARTIAL | GAP-021/023/024/048 |
| DOC-002 | korekta dokumentu | DOCUMENT-003 F1 Agent? | UNVERIFIED | GAP-020/021/022/046 |
| PLN-001 | prognoza popytu | brak formalnego procesu | NONE | GAP-025/048 |
| PLN-002 | zapotrzebowanie i plan | PRODUCTION-001/002 F3/F2 | PARTIAL | GAP-026/027/033 |
| PRD-001 | zlecenie produkcyjne | PRODUCTION-001 jako lista, PRODUCTION-003 F0 | NONE | GAP-026/027 |
| PRD-002 | wykonanie, zużycie i straty | PRODUCTION-003 F0, PRODUCTION-005 F1 Agent? | NONE/UNVERIFIED | GAP-027/028/030/032/044 |
| PRD-003 | przyjęcie wyrobu i QC | PRODUCTION-004 F0 | NONE | GAP-028/029/032/036 |
| PRD-004 | zamknięcie i korekta zlecenia | brak | NONE | GAP-031/045/046 |
| INV-001 | przyjęcie | PURCHASING-001/002 i inventory fragmenty | LOW | GAP-032/036/037/038/040 |
| INV-002 | przesunięcie | INVENTORY-003 F1 | LOW | GAP-032/034/036 |
| INV-003 | rezerwacja | INVENTORY-004 F0 | NONE | GAP-033 |
| INV-004 | inwentaryzacja i korekta | INVENTORY-005 F1 Agent? | UNVERIFIED | GAP-032/035/046 |
| INV-005 | partie, ważność, stan historyczny | INVENTORY-004 F0 | NONE | GAP-032/036/048 |
| PUR-001 | zapotrzebowanie zakupowe | PURCHASING-002 F1 Agent? | UNVERIFIED | GAP-018/037 |
| PUR-002 | zamówienie i akceptacja | PURCHASING-001/002 F2/F1 | LOW | GAP-009/037/040 |
| PUR-003 | dostawa i rozbieżność | PURCHASING-002 + reklamacje fragmentaryczne | LOW | GAP-032/037/038 |
| PUR-004 | cena i ocena dostawcy | pricing Agent?, PURCHASING-003 F0 | LOW | GAP-039/040/048 |
| LOG-001 | transport i dostarczenie | SHIPPING-001 F3 | PARTIAL | GAP-023/041/045 |
| COST-001 | rejestr kosztów | COST-001 F3 | PARTIAL/UNRELIABLE | GAP-030/040/043/045 |
| COST-002 | koszt produktu, partii, sklepu | RECIPE-005, ANALYTICS-002 F2 | UNRELIABLE | GAP-013/036/044/045/047/048 |
| COST-003 | okres, zamknięcie i korekta | miesięczne `cost_*`, brak lifecycle | LOW | GAP-020/031/045/046/049 |

## Wniosek macierzy

Najlepiej pokryte są formularze i dokumenty głównej ścieżki. Najsłabiej pokryte są zdarzenia wykonawcze, księgi, korekty i historia. Żaden proces TO-BE nie ma potwierdzonego pokrycia `COMPLETE` end-to-end.
