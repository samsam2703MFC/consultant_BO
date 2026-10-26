<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Pozostałe ustawienia</h3>
                <p class="text-subtitle text-muted">Zarządzaj ustawieniami i parametrami aplikacji</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
            </div>
        </div>
    </div>

    <section class="section">

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Ustawienia i parametry</h4>
            </div>

            <div class="card-body">
                <table class="table table-hover">
                    <thead>
                        <th style="width: 66%"></th>
                        <th style="width: 33%"></th>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Wypłaszcz wszystkie receptury dostępnie w aplikacji</td>
                            <td>
                                <button class="btn btn-primary" onclick="flatAllRecipes()">
                                    Wypłaszcz receptury
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td>Przelicz koszt wszystkich receptur dostępnych w aplikacji </td>
                            <td>
                                <button class="btn btn-primary" onclick="recalcAllRecipes()">
                                    Przelicz receptury
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td>Przeładuj treść etykiet produktów</td>
                            <td>
                                <button class="btn btn-primary" onclick="rewriteAllLabels()">
                                    Przeładuj
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td>Zarządzaj obsługiwanymi językami etykiet produktów</td>
                            <td>
                                <a href="<?= ROOT ?>/settings/languages" class="btn btn-primary">
                                    Języki
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php $sp = $stock_params ?? []; ?>
        <div class="card" id="stock-params">
            <div class="card-header">
                <h4 class="card-title mb-0">Zapasy produktów na magazynie (MTS)</h4>
                <p class="text-muted small mb-0">Stany min. / maks. liczone ze średniej sprzedaży (WDT) — przycisk w karcie produktu i widok « Zapasy wg kategorii » w Kartach produktów.</p>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" id="stockParamsForm">
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="spMin">Zapas minimalny (tygodnie sprzedaży)</label>
                        <input class="form-control" id="spMin" name="stock.suggest_min_weeks" inputmode="decimal" value="<?= htmlspecialchars(str_replace('.', ',', (string) ($sp['stock.suggest_min_weeks'] ?? '1'))) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="spMax">Zapas maksymalny (tygodnie sprzedaży)</label>
                        <input class="form-control" id="spMax" name="stock.suggest_max_weeks" inputmode="decimal" value="<?= htmlspecialchars(str_replace('.', ',', (string) ($sp['stock.suggest_max_weeks'] ?? '3'))) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="spWeeks">Średnia sprzedaży z ostatnich (tygodni)</label>
                        <input class="form-control" id="spWeeks" name="stock.suggest_weeks" inputmode="numeric" value="<?= htmlspecialchars((string) ($sp['stock.suggest_weeks'] ?? '6')) ?>">
                    </div>
                    <div class="col-12 col-md-3 d-grid">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Zapisz parametry zapasów</button>
                    </div>
                    <div class="col-12 form-text" id="stockParamsInfo">Przykład : średnio 10 kartonów sprzedaży na tydzień, minimum 1 tydzień i maksimum 3 tygodnie → stan min. 10 kartonów, maks. 30 kartonów.</div>
                </form>
            </div>
        </div>

        <?php $ap = $alert_params ?? []; ?>
        <div class="card" id="alert-params">
            <div class="card-header">
                <h4 class="card-title mb-0">Alerty i terminy (dzwonek)</h4>
                <p class="text-muted small mb-0">Sprawdzane co kilka minut ; każdy alert pojawia się w dzwonku raz, te same punkty są w « Do zrobienia » na stronie Dzisiaj.
                    Także : zaległe testy nowych produktów (data minęła), minięty termin wprowadzenia i różnice wysyłki bez decyzji (Wysyłki › Różnice i problemy dostaw).</p>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" id="alertParamsForm">
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="apComplaint">Reklamacja bez decyzji : alert po (dniach)</label>
                        <input class="form-control" id="apComplaint" name="alerts.complaint_days" inputmode="numeric" value="<?= htmlspecialchars((string) ($ap['alerts.complaint_days'] ?? '3')) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="apEscalate">Alert pilny (czerwony) po (dniach)</label>
                        <input class="form-control" id="apEscalate" name="alerts.complaint_escalate_days" inputmode="numeric" value="<?= htmlspecialchars((string) ($ap['alerts.complaint_escalate_days'] ?? '7')) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="apCost">Koszty poprzedniego miesiąca wprowadzone do (dnia miesiąca)</label>
                        <input class="form-control" id="apCost" name="alerts.cost_due_day" inputmode="numeric" value="<?= htmlspecialchars((string) ($ap['alerts.cost_due_day'] ?? '10')) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="apIssueDays">Różnica / problem dostawy : decyzja w (dniach)</label>
                        <input class="form-control" id="apIssueDays" name="shipping_issue.decision_days" inputmode="numeric" value="<?= htmlspecialchars((string) ($ap['shipping_issue.decision_days'] ?? '3')) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="apIssueMin">Różnica proforma ↔ WDT od (EUR)</label>
                        <input class="form-control" id="apIssueMin" name="shipping_issue.min_amount" inputmode="numeric" value="<?= htmlspecialchars((string) ($ap['shipping_issue.min_amount'] ?? '1')) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="apIssueBack">Kolejka różnic : ostatnie (dni)</label>
                        <input class="form-control" id="apIssueBack" name="shipping_issue.lookback_days" inputmode="numeric" value="<?= htmlspecialchars((string) ($ap['shipping_issue.lookback_days'] ?? '45')) ?>">
                    </div>
                    <div class="col-12 col-md-3 d-grid">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Zapisz alerty</button>
                    </div>
                    <div class="col-12 form-text">Brak kosztów miesiąca (personel w kalkulatorze kosztów) : rentowność, wynik grup i raport miesięczny liczą go na kosztach z ostatniego wprowadzonego miesiąca.</div>
                </form>
            </div>
        </div>

    </section>
</div>

<script>
    // Alerty i terminy (n_prod_param) : reklamacje bez decyzji, dzień miesiąca na koszty.
    document.getElementById('alertParamsForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const form = e.target;
        const body = {};
        new FormData(form).forEach((v, k) => { body[k] = v; });
        form.querySelectorAll('.is-invalid').forEach(i => i.classList.remove('is-invalid'));
        const res = await fetch('<?= ROOT ?>/ajax/settings/alert-params', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify(body)});
        const json = await res.json().catch(() => ({}));
        Object.keys(json.errors || {}).forEach(k => { const i = form.querySelector('[name="' + k + '"]'); if (i) i.classList.add('is-invalid'); });
        const msg = json.message || json.error || 'Błąd zapisu.';
        if (typeof Swal !== 'undefined') Swal.fire({icon: json.ok ? 'success' : 'error', text: msg}); else alert(msg);
    });

    // Parametry zapasów MTS (n_prod_param) : tygodnie min. / maks. i okres średniej sprzedaży.
    document.getElementById('stockParamsForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const form = e.target;
        const body = {};
        new FormData(form).forEach((v, k) => { body[k] = v; });
        form.querySelectorAll('.is-invalid').forEach(i => i.classList.remove('is-invalid'));
        const res = await fetch('<?= ROOT ?>/ajax/settings/stock-params', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify(body)});
        const json = await res.json().catch(() => ({}));
        Object.keys(json.errors || {}).forEach(k => { const i = form.querySelector('[name="' + k + '"]'); if (i) i.classList.add('is-invalid'); });
        const msg = json.message || json.error || 'Błąd zapisu.';
        if (typeof Swal !== 'undefined') Swal.fire({icon: json.ok ? 'success' : 'error', text: msg}); else alert(msg);
    });

    async function flatAllRecipes() {
        // Loader na czas długiego przeliczania
        const hasSwal = (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function');

        if (hasSwal) {
            Swal.fire({
                title: 'Proszę czekać',
                text: 'Trwa przeliczanie i wypłaszczanie receptur... To może potrwać kilka minut.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        }

        try {
            const data = await api('/ajax/settings/flat-all-recipes', {
                method: 'POST'
            });

            if (hasSwal) Swal.close();

            if (data?.ok) {
                if (hasSwal) {
                    await Swal.fire('Sukces', data.message || 'Receptury zostały wypłaszczone.', 'success');
                } else {
                    alert(data.message || 'Receptury zostały wypłaszczone.');
                }
            } else {
                const msg = data?.message || 'Nie udało się wypłaszczyć receptur.';
                if (hasSwal) {
                    Swal.fire('Błąd', msg, 'error');
                } else {
                    alert(msg);
                }
            }
        } catch (error) {
            if (hasSwal) Swal.close();

            const msg = (error && error.message) ? ('Wystąpił błąd: ' + error.message) : 'Wystąpił błąd podczas przeliczania receptur.';
            if (hasSwal) {
                Swal.fire('Błąd', msg, 'error');
            } else {
                alert(msg);
            }

            console.error('flatAllRecipes error:', error);
        }
    }

    async function recalcAllRecipes() {
        const hasSwal = (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function');

        if (hasSwal) {
            const result = await Swal.fire({
                title: 'Potwierdzenie',
                text: 'Czy na pewno chcesz przeliczyć wszystkie receptury? To może potrwać kilka minut.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Tak, przelicz',
                cancelButtonText: 'Anuluj'
            });

            if (!result.isConfirmed) return;

            Swal.fire({
                title: 'Proszę czekać',
                text: 'Trwa przeliczanie kosztów receptur... To może potrwać kilka minut.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        } else {
            if (!confirm('Czy na pewno chcesz przeliczyć wszystkie receptury? To może potrwać kilka minut.')) {
                return;
            }
        }

        try {
            const data = await api('/ajax/settings/recalc-all-recipes', {
                method: 'POST'
            });

            if (hasSwal) Swal.close();

            if (data?.ok) {
                if (hasSwal) {
                    await Swal.fire('Sukces', data.message || 'Receptury zostały przeliczone.', 'success');
                } else {
                    alert(data.message || 'Receptury zostały przeliczone.');
                }
            } else {
                const msg = data?.message || 'Nie udało się przeliczyć receptur.';
                if (hasSwal) {
                    Swal.fire('Błąd', msg, 'error');
                } else {
                    alert(msg);
                }
            }
        } catch (error) {
            if (hasSwal) Swal.close();

            const msg = (error && error.message) ? ('Wystąpił błąd: ' + error.message) : 'Wystąpił błąd podczas przeliczania receptur.';
            if (hasSwal) {
                Swal.fire('Błąd', msg, 'error');
            } else {
                alert(msg);
            }

            console.error('recalcAllRecipes error:', error);
        }
    }

    async function rewriteAllLabels() {
        const hasSwal = (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function');

        if (hasSwal) {
            const result = await Swal.fire({
                title: 'Potwierdzenie',
                text: 'Czy na pewno chcesz przeładować treść etykiet dla wszystkich receptur? To może potrwać kilka minut.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Tak, przeładuj',
                cancelButtonText: 'Anuluj'
            });

            if (!result.isConfirmed) return;

            Swal.fire({
                title: 'Proszę czekać',
                text: 'Trwa przeładowywanie treści etykiet... To może potrwać kilka minut.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        } else {
            if (!confirm('Czy na pewno chcesz przeładować treść etykiet dla wszystkich receptur? To może potrwać kilka minut.')) {
                return;
            }
        }

        try {
            const data = await api('/ajax/settings/rewrite-all-labels', {
                method: 'POST'
            });

            if (hasSwal) Swal.close();

            if (data?.ok) {
                if (hasSwal) {
                    await Swal.fire('Sukces', data.message || 'Etykiety zostały przeładowane.', 'success');
                } else {
                    alert(data.message || 'Etykiety zostały przeładowane.');
                }
            } else {
                const msg = data?.message || 'Nie udało się przeładować etykiet.';
                if (hasSwal) {
                    Swal.fire('Błąd', msg, 'error');
                } else {
                    alert(msg);
                }
            }
        } catch (error) {
            if (hasSwal) Swal.close();

            const msg = (error && error.message) ? ('Wystąpił błąd: ' + error.message) : 'Wystąpił błąd podczas przeładowywania etykiet.';
            if (hasSwal) {
                Swal.fire('Błąd', msg, 'error');
            } else {
                alert(msg);
            }

            console.error('rewriteAllLabels error:', error);
        }
    }
</script>

<?php $this->view("page_components/footer"); ?>