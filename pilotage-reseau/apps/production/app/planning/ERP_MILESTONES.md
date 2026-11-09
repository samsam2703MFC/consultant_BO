# ERP Milestones

Kamień milowy kończy się działającą zdolnością użytkownika i dowodem E2E. Nie jest listą warstw technicznych.

| ID | Faza | Działający rezultat | Zakres inicjatyw | Dowód ukończenia |
|---|---|---|---|---|
| MS-01 | F0/F1 | zespół odtwarza czystą bazę i uruchamia deterministyczny test bez użycia produkcji; znany jest aktywny runtime | ERP-001–004 | clean/upgrade/schema diff + CI PASS; secret/auth remediation; runtime inventory signed |
| MS-02 | F1 | administrator loguje się, widzi tylko swój scope, wykonuje jedną krytyczną komendę z reason/audit, a revoke działa | ERP-005/006/042 | deny/cross-scope/revoke i audit completeness E2E PASS |
| MS-03 | F1/F4 | operator znajduje failed message, diagnozuje i retry’uje bez duplikatu | ERP-007/008/042 | timeout→dead→manual retry→one outcome E2E PASS |
| MS-04 | F2 | steward tworzy/zmienia/publikuje wersję produktu, jednostki i config, a historyczny snapshot pozostaje stabilny | ERP-009–012/032 | import/diff/approval/history i historical stability PASS |
| MS-05 | F3 | magazynier przyjmuje dostawę częściową; powstaje receipt i jeden stock movement z ceną/lotem | ERP-015/019/020/022/042 | PO→receipt→ledger→reconciliation do dokumentu PASS |
| MS-06 | F3 | sprzedawca rejestruje sprzedaż/płatność, wystawia dokument i wykonuje korektę powiązaną z oryginałem | ERP-015/016/018/042 | sale/payment/document/correction totals, VAT i retry PASS |
| MS-07 | F2/F3 | technolog klonuje recepturę, widzi diff, zatwierdza i publikuje immutable RecipeVersion | ERP-013 | cycle/atomic activation/version history PASS |
| MS-08 | F3/F4 | magazyn przeprowadza freeze/count/recount/approval; różnica staje się ruchem kompensującym | ERP-021/033/042 | stocktake E2E, maker-checker i ledger reconciliation PASS |
| MS-09 | F3 | operator uruchamia zlecenie na RecipeVersion, raportuje consume/output/waste i zamyka je | ERP-023/024/027/042 | production→movements→lot/time/audit; correction scenario PASS |
| MS-10 | F6 | controller księguje źródłowe koszty, zamyka okres i wykonuje kontrolowany adjustment/reopen | ERP-014/028/029/042 | source→entry→allocation totals, blockers/hash/reopen PASS |
| MS-11 | F4 | administrator obsługuje retry, config, import i co najmniej dwie domenowe naprawy w jednym Workbench | ERP-008/012/032/033 | brak SQL w wybranych klasach, permission/preview/outcome/audit PASS |
| MS-12 | F5 | kierownik widzi pierwsze KPI sprzedaży, stock i produkcji z freshness/lineage/drill-down | ERP-031/037 | KPI `AVAILABLE`, totals/control docs zgodne |
| MS-13 | F6 | controller publikuje rentowność produktu/partii/sklepu/klienta dla zamkniętego okresu | ERP-030/038 | drill-down do source costs/sale, suma i stability PASS |
| MS-14 | F7 | planista tworzy wersję planu, widzi shortage i release’uje production orders | ERP-017/019/023/034 | plan version→shortage→orders→plan-vs-actual PASS |
| MS-15 | F7 | planista widzi forecast advisory z błędem i porównaniem do naive baseline | ERP-041 | backtest/bias/drift/shadow outcome zatwierdzone |
| MS-16 | F7 | system przechodzi restore/recovery test, a jeden zastąpiony legacy mechanizm zostaje wycofany | ERP-039/040 | RTO/RPO/restore PASS; zero traffic/read/write + retention sign-off |

## Pierwsze trzy rekomendowane

1. **MS-01 — Wiarygodna i bezpieczna baza zmiany.**
2. **MS-02 — Bezpieczna, scoped i audytowana operacja.**
3. **MS-03 — Odzyskanie integracji bez programisty.**

Ta kolejność dostarcza zdolność testowania, zamyka granicę zaufania i jako pierwszy realny self-service wykorzystuje istniejące workery/outboxy bez przedwczesnej migracji głównych faktów biznesowych.

