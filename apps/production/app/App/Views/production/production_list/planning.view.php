<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Produkt gotowy</h3>
                    <p class="text-subtitle text-muted">Navbar will appear in top of the page.</p>
                </div>
                <div class="col-12 col-md-6 order-md-2 order-first">
                    <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.html">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Layout Vertical Navbar
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <section class="section">
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h4 class="card-title mb-0">Oczekujące zamówienia</h4>
                    <div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/future/production-lists">Archiwum list produkcyjnych</a><a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/future/requirement-lists">Archiwum zapotrzebowania</a><a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/future/complaint-production-lists">Archiwum zwrotów</a></div>
                </div>
                <div class="card-body">
                    <form method="POST" id="id_form">
                        <div class="row">
                            <div class="col-12">
                                <table class="table table-hover">
                                    <thead>
                                    <th><input type='checkbox' id="id_toggle_chck" class='form-check'
                                               onClick="togglePending(this)" checked></th>
                                    <th>Klient</th>
                                    <th>Komentarz</th>
                                    <th>Złożono dnia</th>
                                    <th>Data wysyłki</th>
                                    </thead>
                                    <tbody id="id_order_list_body">
                                    <?php if (!empty($rows)): ?>
                                        <?php for ($i = 0; $i < count($rows); $i++): ?>
                                            <?php if ($rows[$i]['expected_shipping_date'] != @$rows[$i - 1]['expected_shipping_date'] || $i == 0): ?>
                                                <tr class='bg-light-secondary' style='font-size: 1.2rem'>
                                                    <td colspan='6'><?= $rows[$i]['expected_shipping_date'] ?></td>
                                                </tr>
                                            <?php endif ?>
                                            <tr class="order_row">
                                                <td><input type='checkbox' class='form-check' name='order_id[]'
                                                           value='<?= $rows[$i]['id'] ?>' onclick="checkAll(this)"
                                                           checked></td>
                                                <td><?= $rows[$i]['company'] ?></td>
                                                <td><?= $rows[$i]['order_description'] ?></td>
                                                <td><?= $rows[$i]['create_date'] ?></td>
                                                <td><?= $rows[$i]['expected_shipping_date'] ?></td>
                                            </tr>
                                        <?php endfor; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <table class="table">
                                    <thead>
                                    <th></th>
                                    <th></th>
                                    </thead>
                                    <tbody>
                                    <tr class="bg-light-success">
                                        <td><input type="checkbox" class="form-check" id="stock_minimum"
                                                   name="stock_minimum" value="1" checked></td>
                                        <td><label class="form-check-label text-xl" for="stock_minimum">Uwzględnij
                                                minimalne stany magazynowe</label></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4">
                                <button class="btn btn-success" type="button"
                                        onclick="validPostRequest('production_list')">Generuj listę produkcyjną
                                </button>
                            </div>
                            <div class="col-4">
                                <button class="btn btn-outline-warning" type="button"
                                        onclick="getRequirementList()">Generuj listę zapotrzebowania
                                    materiałowego
                                </button>
                            </div>
                            <div class="col-4">
                                <button class="btn btn-secondary float-end" type="button" onclick="getComplaintProductionList()">Generuj listę produkcyjną dla zwrotów</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </div>

    <script>
        function togglePending(source) {
            checkboxes = document.getElementsByName('order_id[]');
            for (var i = 0, n = checkboxes.length; i < n; i++) {
                checkboxes[i].checked = source.checked;
            }
        }

        function checkAll(chck) {
            const tbody = chck.closest('tbody');
            const row_count = tbody.rows.length;
            const checked_count = tbody.querySelectorAll('input[type=checkbox]:checked').length;

            if (Number(row_count) != Number(checked_count)) {
                document.getElementById('id_toggle_chck').checked = false;
            }
        }

        function checkQuanityOfUniqueDates() {
            const rows = document.getElementsByClassName("order_row");
            let usedDates = [];
            let chck, date;

            for (let i = 0; i < rows.length; i++) {
                chck = rows[i].cells[0].children[0];
                date = rows[i].cells[4].innerText;

                if (chck.checked == true && !usedDates.includes(date)) {
                    usedDates.push(date);
                }

                if (usedDates.length > 4) return false;
            }

            return true;
        }

        async function validPostRequest(request_type) {

            if (!checkQuanityOfUniqueDates()) {
                Swal.fire({
                    icon: "error",
                    title: "Błąd",
                    html: "Liczba wybranych dat jest poza zakresem<br>Wybierz maksymalnie 4 unikalne daty dostawy"
                });
                return;
            }

            const form = document.getElementById('id_form');

            const data = {
                order_id: [],
                stock_minimum: 0
            };

            const checkboxes = form.querySelectorAll("input[name='order_id[]']:checked");
            checkboxes.forEach(cb => {
                data.order_id.push(cb.value);
            });

            const stockMinimum = form.querySelector("input[name='stock_minimum']");
            data.stock_minimum = stockMinimum.checked ? 1 : 0;

            data[request_type] = 1;

            downloadPDF(`/ajax/production-list/standard`, data, "planning_list.pdf", "POST");

            // try {
            //
            //     if (!response.ok) {
            //         throw new Error(`Błąd pobierania PDF: ${response.status} ${response.statusText}`);
            //     }
            //
            //     const blob = await response.blob();
            //
            //     // Pobierz nazwę pliku z nagłówka lub użyj domyślnej
            //     const disposition = response.headers.get("Content-Disposition") || "";
            //     const match = /filename="?(.+?)"?(;|$)/.exec(disposition);
            //     const filename = match ? match[1] : "production_list.pdf";
            //
            //     // Pobierz plik
            //     const url = URL.createObjectURL(blob);
            //     const a = document.createElement("a");
            //     a.href = url;
            //     a.download = filename;
            //     document.body.appendChild(a);
            //     a.click();
            //     document.body.removeChild(a);
            //     URL.revokeObjectURL(url);
            //
            // } catch (err) {
            //     Swal.fire({
            //         icon: "error",
            //         title: "Błąd",
            //         text: err.message || "Nie udało się pobrać pliku PDF"
            //     });
            // }
        }

        async function getComplaintProductionList() {


            try {
                const response = await fetch("/ajax/production-list/complaint", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                });

                if (!response.ok) {
                    throw new Error(`Błąd pobierania PDF: ${response.status} ${response.statusText}`);
                }

                const blob = await response.blob();

                // Pobierz nazwę pliku z nagłówka lub użyj domyślnej
                const disposition = response.headers.get("Content-Disposition") || "";
                const match = /filename="?(.+?)"?(;|$)/.exec(disposition);
                const filename = match ? match[1] : "production_list.pdf";

                // Pobierz plik
                const url = URL.createObjectURL(blob);
                const a = document.createElement("a");
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

            } catch (err) {
                Swal.fire({
                    icon: "error",
                    title: "Błąd",
                    text: err.message || "Nie udało się pobrać pliku PDF"
                });
            }
        }


        function getRequirementList() {
            try {
                if (!checkQuanityOfUniqueDates()) {
                    Swal.fire({
                        icon: "error",
                        title: "Błąd",
                        html: "Liczba wybranych dat jest poza zakresem<br>Wybierz maksymalnie 4 unikalne daty dostawy"
                    });
                    return;
                }

                const form = document.getElementById('id_form');

                const data = {
                    order_id: [],
                    stock_minimum: 0
                };

                const checkboxes = form.querySelectorAll("input[name='order_id[]']:checked");
                checkboxes.forEach(cb => {
                    data.order_id.push(cb.value);
                });

                const stockMinimum = form.querySelector("input[name='stock_minimum']");
                data.stock_minimum = stockMinimum && stockMinimum.checked ? 1 : 0;

                if (!data.order_id.length) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Błąd',
                        text: 'Nie wybrano żadnych zamówień.'
                    });
                    return;
                }

                downloadPDF(`/ajax/production-list/requirement/pdf`, data, "requirement_list.pdf", "POST");
            } catch (err) {
                Swal.fire({
                    icon: "error",
                    title: "Błąd",
                    text: err?.message || "Nie udało się pobrać listy zapotrzebowania materiałowego"
                });
                console.error('getRequirementList error:', err);
            }
        }


    </script>

<?php $this->view("page_components/footer"); ?>
