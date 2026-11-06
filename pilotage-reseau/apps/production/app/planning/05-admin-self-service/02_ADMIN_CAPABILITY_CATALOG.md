# Katalog zdolności administracyjnych

## Konwencje

`A` oznacza approval drugiej osoby; `R` obowiązkowe uzasadnienie; `L` append-only audit; `C` kompensację/rollback. Częstotliwość jest hipotezą (`dzienna`, `tygodniowa`, `miesięczna`, `rzadka`) do potwierdzenia z telemetryką. Interfejsy są wzorcami, nie projektem ekranów.

## Dane podstawowe

| ID | Nazwa i cel | Obecny sposób / aplikacja | Dane zmieniane | Ryzyko / częstość | Użytkownik / uprawnienie | A/R/L/C | Zabezpieczenia / interfejs |
|---|---|---|---|---|---|---|---|
| ADM-MD-01 | produkt: utworzenie, korekta draftu, aktywacja | lokalne CRUD-y App | product, SKU, status, klasyfikacja | HIGH / dzienna | administrator danych / `master.product.manage` | A*:R:L:C | unikalność, diff, scope; rejestr + wersja (*approval aktywacji) |
| ADM-MD-02 | kategorie i przypisania | CRUD/SQL App | category, product-category | MEDIUM / tygodniowa | administrator danych / `master.category.manage` | -:R:L:C | wpływ na raporty i marże; drzewo/rejestr |
| ADM-MD-03 | jednostki i przeliczniki | rozproszone modele | unit, conversion | CRITICAL / rzadka | steward danych / `master.unit.manage` | A:R:L:C | dimension check, zakaz zmiany użytego przelicznika; wersja |
| ADM-MD-04 | opakowania i domyślne packi | Agent/App, punktowe repo | package, conversion, default | HIGH / tygodniowa | steward / `master.package.manage` | A:R:L:C | effective date, zależności dostawcy; rejestr |
| ADM-MD-05 | receptura: wersja, review, activate/withdraw | edycja bieżąca App | recipe graph/version/yield | CRITICAL / tygodniowa | technolog / `recipe.version.manage` | A:R:L:C | clone/diff/cycle/cost preview; workflow wersji |
| ADM-MD-06 | dostawca: onboard/suspend/warunki | CRUD fragmentaryczny | supplier, terms, status | HIGH / tygodniowa | kupiec / `supplier.manage` | A*:R:L:C | duplikaty, otwarte PO; rejestr (*suspend) |
| ADM-MD-07 | klient: onboard/suspend/merge | CRUD App | customer, addresses, tax IDs, links | HIGH / dzienna | obsługa klienta / `customer.manage` | A*:R:L:C | duplicate match, dependencies; merge case |
| ADM-MD-08 | zakład/sklep/magazyn/lokalizacja | rozproszone ustawienia | organization/site/shop/warehouse/location | CRITICAL / rzadka | admin organizacji / `organization.structure.manage` | A:R:L:C | drzewo scope, aktywne dokumenty; rejestr hierarchii |
| ADM-MD-09 | pracownik i przypisanie | Agent/rozproszone | employee, assignment, site, validity | HIGH / tygodniowa | HR / `workforce.employee.manage` | A*:R:L:C | privacy, datowanie, konflikt zakresów; karta rekordu |

## Konfiguracja

| ID | Nazwa i cel | Obecny sposób / aplikacja | Dane zmieniane | Ryzyko / częstość | Użytkownik / uprawnienie | A/R/L/C | Zabezpieczenia / interfejs |
|---|---|---|---|---|---|---|---|
| ADM-CFG-01 | słowniki i reason codes | migracje/kod/alias CRUD Agent | dictionary entry, locale, active | MEDIUM / miesięczna | admin konfiguracji / `config.dictionary.manage` | -:R:L:C | kod immutable, usage count; wspólny rejestr |
| ADM-CFG-02 | etykiety/status metadata | kod/migracje | label, order, terminal flag | HIGH / rzadka | właściciel procesu / `config.workflow.metadata` | A:R:L:C | bez edycji grafu przejść; versioned registry |
| ADM-CFG-03 | numeracje dokumentów | config/SQL | sequence policy, prefix, scope | CRITICAL / roczna | księgowość / `document.numbering.manage` | A:R:L:C | preview 20 numerów, overlap/gap, effective date |
| ADM-CFG-04 | typy i szablony dokumentów | kod/pliki | template version, locale, channel | HIGH / miesięczna | admin dokumentów / `document.template.publish` | A:R:L:C | sample render/hash; draft→publish |
| ADM-CFG-05 | podatki | bieżące dane/kod | tax code/rate/validity | CRITICAL / rzadka | finanse / `finance.tax.manage` | A:R:L:C | overlap, jurisdiction, simulation; version timeline |
| ADM-CFG-06 | cenniki i polityki marż | UI + SQL seed/migracje | price/margin version/scope | CRITICAL / tygodniowa | pricing manager / `pricing.publish` | A:R:L:C | effective dates, overlap, impact preview; publish workflow |
| ADM-CFG-07 | ustawienia sklepu | rozproszone | shop policy, cutoff, locale | HIGH / miesięczna | admin sklepu / `config.shop.manage` | A*:R:L:C | scope i dziedziczenie; config registry |
| ADM-CFG-08 | ustawienia produkcji | kod/ustalenia | tolerances, planning/QC policy | HIGH / miesięczna | kierownik produkcji / `config.production.manage` | A:R:L:C | validation/simulation; versioned policy |
| ADM-CFG-09 | ustawienia magazynu | rozproszone | reservation, negative stock, lot rules | CRITICAL / rzadka | kierownik magazynu / `config.inventory.manage` | A:R:L:C | impact on open work, no retroactivity |
| ADM-CFG-10 | metadane integracji | `.env`, DB, kod | endpoint ref, enabled, timeout, mapping | CRITICAL / rzadka | integration admin / `integration.config.manage` | A:R:L:C | test connection, allowlist; secret tylko przez vault |
| ADM-CFG-11 | progi i macierz approval | brak wspólnego modelu | policy version, amount/risk threshold | CRITICAL / rzadka | risk owner / `policy.approval.manage` | A:R:L:C | four-eyes, no self-approval; policy simulator |

## Naprawa i recovery

| ID | Nazwa i cel | Obecny sposób / aplikacja | Dane zmieniane | Ryzyko / częstość | Użytkownik / uprawnienie | A/R/L/C | Zabezpieczenia / interfejs |
|---|---|---|---|---|---|---|---|
| ADM-REP-01 | retry komunikatu integracji | worker/API/CLI | attempt/status, bez zmiany payload | HIGH / dzienna | support L2 / `integration.message.retry` | -*:R:L:C | stały idempotency key, preview; operations inbox (*A dla batch) |
| ADM-REP-02 | regenerate dokumentu | rozproszone | render artifact/hash | HIGH / tygodniowa | operator dokumentów / `document.regenerate` | -:R:L:C | immutable snapshot, wyraźnie bez resend |
| ADM-REP-03 | resend dokumentu | rozproszone | delivery attempt/channel | HIGH / tygodniowa | operator dokumentów / `document.resend` | -:R:L:C | recipient preview/redaction; timeline |
| ADM-REP-04 | korekta statusu domenowego | SQL/lokalny CRUD — hipoteza | nowy transition/correction event | CRITICAL / rzadka | owner procesu / `repair.transition.execute` | A:R:L:C | dozwolone komendy, preconditions; repair case |
| ADM-REP-05 | rozwiązanie duplikatu | SQL/programista — hipoteza | canonical mapping/aliases | HIGH / tygodniowa | steward / `data.duplicate.resolve` | A:R:L:C | dependency graph, survivorship preview; merge case |
| ADM-REP-06 | korekta powiązania | SQL/programista — hipoteza | link/relation + correction | HIGH / tygodniowa | steward / `data.link.correct` | A:R:L:C | oba rekordy i skutki; repair case |
| ADM-REP-07 | przeliczenie kosztu/receptury | akcje App/Agent | derived values/job metadata | HIGH / tygodniowa | controller/technolog / `calculation.rebuild` | A*:R:L:C | scope, source watermark, diff; async job |
| ADM-REP-08 | odblokowanie procesu | SQL/unlock punktowy | lock/process state | CRITICAL / rzadka | owner procesu / `repair.process.unlock` | A:R:L:C | root cause fixed, concurrency token; repair case |
| ADM-REP-09 | korekta magazynowa | SQL/stocktake fragmenty | compensating movement | CRITICAL / dzienna | kierownik magazynu / `inventory.adjust` | A:R:L:C | lot/location/unit/source document; adjustment wizard |
| ADM-REP-10 | reopen/korekta produkcji | brak wspólnej funkcji | correction events/movements/cost | CRITICAL / rzadka | produkcja+controller / `production.correct` | A:R:L:C | impact graph, period check; repair case |
| ADM-REP-11 | retry/rollback batcha importu | punktowe importy | batch rows/status/compensation | HIGH / tygodniowa | data operator / `data.import.recover` | A*:R:L:C | staging, per-row errors, immutable manifest |
| ADM-REP-12 | dead-letter resolution | log/SQL/worker | resolution/owner/status | HIGH / dzienna | support L2 / `integration.dead.resolve` | A*:R:L:C | classify retry/cancel/manual resolution; inbox |
| ADM-REP-13 | przypisanie brakujących danych | SQL/arkusz | canonical foreign key | HIGH / tygodniowa | steward / `data.missing.assign` | A*:R:L:C | candidates/confidence/batch preview; quality queue |

## Dane, raporty i okres

| ID | Nazwa i cel | Obecny sposób / aplikacja | Dane zmieniane | Ryzyko / częstość | Użytkownik / uprawnienie | A/R/L/C | Zabezpieczenia / interfejs |
|---|---|---|---|---|---|---|---|
| ADM-DAT-01 | kontrolowany import | punktowe/pliki | staging + canonical records | HIGH / tygodniowa | data operator / `data.import.execute` | A*:R:L:C | schema/template, dry-run, row errors, checksum |
| ADM-DAT-02 | eksport danych | osobne eksporty | brak domenowej zmiany; audit export | MEDIUM / dzienna | uprawniony użytkownik / `data.export` | -:R*:L:- | scope, masking, expiry/watermark (*PII) |
| ADM-DAT-03 | podgląd błędów | logi/rozproszone kolejki | owner/annotation | LOW / dzienna | support / `operations.error.read` | -:-:L:- | redaction, correlation; wspólny inbox |
| ADM-DAT-04 | historia zmian | changelogi/lokalne logi | brak | MEDIUM / dzienna | audytor/owner / `audit.read` | -:R*:L:- | osobny scope, export audit (*eksport) |
| ADM-DAT-05 | diagnostyka | serwer/SQL | tylko zapis uruchomienia/wyniku | LOW / dzienna | support L1 / `diagnostics.run.safe` | -:-:L:- | allowlisted checks, timeout, redaction |
| ADM-DAT-06 | uzgodnienie sprzedaży/płatności | SQL/arkusz | reconciliation result/case | HIGH / dzienna | finanse / `reconciliation.sales.run` | A*:R:L:C | totals + drill-down; approval adjustment |
| ADM-DAT-07 | uzgodnienie magazynu | SQL/arkusz | result + adjustment case | CRITICAL / dzienna | controller/magazyn / `reconciliation.inventory.run` | A:R:L:C | source snapshots, tolerances, blind approval |
| ADM-DAT-08 | uzgodnienie kosztu | kalkulator/ręczne wpisy | result + adjustment | CRITICAL / miesięczna | controller / `reconciliation.cost.run` | A:R:L:C | completeness, lineage, source totals |
| ADM-DAT-09 | zamknięcie okresu | lock miesiąca | period status/report hash | CRITICAL / miesięczna | controller / `period.close` | A:R:L:C | checklist blockers, immutable close report |
| ADM-DAT-10 | ponowne otwarcie okresu | unlock | reopen event/status | CRITICAL / rzadka | controller+finance owner / `period.reopen` | A:R:L:C | two-person, affected reports, reclose required |
| ADM-DAT-11 | rebuild read model/KPI | kod/skrypt — hipoteza | projection/job metadata | HIGH / rzadka | data ops / `reporting.projection.rebuild` | A*:R:L:C | version/watermark/shadow compare; job center |

## Użytkownicy i bezpieczeństwo

| ID | Nazwa i cel | Obecny sposób / aplikacja | Dane zmieniane | Ryzyko / częstość | Użytkownik / uprawnienie | A/R/L/C | Zabezpieczenia / interfejs |
|---|---|---|---|---|---|---|---|
| ADM-IAM-01 | konto: zaproszenie, blokada, reaktywacja | CRUD App | user/status/identity link | CRITICAL / tygodniowa | IAM admin / `iam.user.manage` | A*:R:L:C | no shared passwords, notify, revoke sessions |
| ADM-IAM-02 | role i granty | pole permission/Agent RBAC | role/grant/validity | CRITICAL / tygodniowa | IAM admin / `iam.grant.manage` | A:R:L:C | SoD, no self-elevation, access diff |
| ADM-IAM-03 | zakres organizacyjny | rozproszone | membership/scope/validity | CRITICAL / tygodniowa | IAM admin / `iam.scope.manage` | A:R:L:C | deny cross-org, preview effective access |
| ADM-IAM-04 | reset dostępu | brak bezpiecznego flow | reset token, credential state | CRITICAL / tygodniowa | helpdesk / `iam.credential.reset` | A*:R:L:C | one-time token, MFA policy, session revoke |
| ADM-IAM-05 | sesje i historia logowania | sesja App/lokalne modele | revoke event/session | HIGH / dzienna | security/helpdesk / `iam.session.manage` | -:R:L:C | device/IP redaction, revoke all option |
| ADM-IAM-06 | konto serwisowe/urządzenie | konfiguracja/Agent sessions | service principal/device grant | CRITICAL / rzadka | security admin / `iam.service_account.manage` | A:R:L:C | expiry, least privilege, secret via vault |
| ADM-IAM-07 | wnioski/approval operacji wrażliwych | brak wspólnego modelu | approval case/decision | HIGH / dzienna | approver / `approval.decide` | -:R:L:C | cannot approve own request, expiry/escalation |

## Pokrycie wymaganych pól

Każdy wiersz podaje ID, nazwę/cel, obecny sposób i aplikację, zmieniane dane, ryzyko, hipotetyczną częstotliwość, użytkownika, permission, approval, uzasadnienie, audit, cofnięcie/kompensację, zabezpieczenia i rekomendowany wzorzec interfejsu. Szczegółowy kontrakt operacji naprawczych opisuje plik `03`, konfiguracji `04`, IAM `05`, audytu `06`, a kontroli wysokiego ryzyka `07`.

