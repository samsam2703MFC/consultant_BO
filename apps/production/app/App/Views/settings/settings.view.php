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

    </section>
</div>

<script>
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