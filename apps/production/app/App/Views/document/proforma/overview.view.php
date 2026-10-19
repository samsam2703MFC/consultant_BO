<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Proforma</h3>
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
            <div class="card-header">
                <h4 class="card-title">Dostępne proformy</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-4">
                        <label for="gen_date" class="form-label">
                            Wyszukiwanie po dacie zamówienia
                        </label>
                        <select class="form-select" name="gen_date" id="gen_date" onchange="searchInTable(this.value)">
                            <option value="-1" selected>Wybierz datę...</option>
                        </select>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-2"></div>
                    <div class="col-8">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger text-center"><?= implode("<br>", $errors) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <table class="table table-hover" id="proforma_table">
                            <thead>
                            <th style="width: 20%"></th>
                            <th style="width: 25%">Data</th>
                            <th style="width: 30%">Klient</th>
                            <th style="width: 15%">Status</th>
                            <th style="width: 25%"></th>
                            </thead>
                            <tbody id="id_table">
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?= $row['doc_num'] ?></td>
                                        <td><?= $row['expected_shipping_date'] ?></td>
                                        <td><?= $row['company'] ?></td>
                                        <td>
                                            <?php if (strtolower(trim((string) ($row['proforma_status'] ?? ''))) === 'paid'): ?>
                                                <span class="badge bg-success">Opłacona</span>
                                            <?php elseif (strtolower(trim((string) ($row['proforma_status'] ?? ''))) === 'cancelled'): ?>
                                                <span class="badge bg-danger">Anulowana</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Nieopłacona</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <?php if (!empty($row['id_proforma'])): ?>
                                                <button class="btn btn-info"
                                                        onclick="getProformaDocument(<?= (int) $row['id_proforma'] ?>)" type="button">
                                                    Proforma
                                                </button>
                                                <a class="btn btn-outline-primary" href="<?= ROOT ?>/communication/compose/proforma/<?= (int) $row['id_proforma'] ?>">
                                                    <i class="bi bi-envelope-paper"></i> Przygotuj e-mail
                                                </a>
                                                <?php if (!in_array(strtolower(trim((string) ($row['proforma_status'] ?? ''))), ['paid', 'cancelled'], true)): ?>
                                                    <button class="btn btn-success" type="button"
                                                            onclick="markProformaPaid(<?= (int) $row['id_proforma'] ?>)">
                                                        Oznacz jako opłaconą
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">Brak dokumentu proformy</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <script src="<?= ROOT ?>/assets/js/bootstrap.bundle.min.js"></script>
        <script src="<?= ROOT ?>/assets/vendors/simple-datatables/simple-datatables.js"></script>


        <script type="text/javascript">
            let notes_table = document.querySelector('#proforma_table');
            let dataTable = new simpleDatatables.DataTable(notes_table, {
                perPage: 10,
                perPageSelect: false,
                searchable: true,
                sortable: true,
                labels: {
                    placeholder: "Szukaj po nazwie",
                    noRows: "Nie znaleziono danych",
                    info: "Wyświetlane {start} - {end} z {rows} wprowadzonych",
                },
            });
        </script>
    </section>
</div>


<?php $this->view("page_components/footer"); ?>

<script type="text/javascript">


    document.addEventListener('DOMContentLoaded', function () {
        loadSelectOpt();
    }, false);

    function getUniqeFromTable(id_table_body, cell_index) {
        const table = document.getElementById(id_table_body);
        let unique_val_arr = [];

        for (let i = 0; i < table.rows.length; i++) {
            if (!unique_val_arr.includes(table.rows[i].cells[cell_index].innerText))
                unique_val_arr.push(table.rows[i].cells[cell_index].innerText)
        }

        return unique_val_arr;
    }

    function loadSelectOpt() {
        const select = document.getElementById('gen_date');
        const unique = getUniqeFromTable("id_table", 1);

        for (let i = 0; i < unique.length; i++) {
            let opt = document.createElement("option");
            opt.value = unique[i];
            opt.innerText = unique[i]
            select.appendChild(opt);
        }
    }

    function searchInTable(input_value) {
        // Declare variables
        var filter, table, tr, td, i, txtValue;
        filter = input_value.toUpperCase();
        table = document.getElementById("id_table");
        tr = table.getElementsByTagName("tr");

        // Loop through all table rows, and hide those who don't match the search query
        for (i = 0; i < tr.length; i++) {
            td = tr[i].getElementsByTagName("td")[1];
            if (td) {
                txtValue = td.textContent || td.innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    }

    function getProformaDocument(id){
        downloadPDF(`/ajax/proforma-documents/${id}/document`)
    }

    async function markProformaPaid(id) {
        if (!window.confirm('Potwierdzasz otrzymanie płatności? Po tej operacji pozycje proformy nie będą już synchronizowane z zamówieniem.')) {
            return;
        }

        const response = await fetch(`<?= ROOT ?>/ajax/proforma-documents/${id}/paid`, {
            method: 'POST',
            headers: { 'Accept': 'application/json' }
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            alert(payload.message || 'Nie udało się oznaczyć proformy jako opłaconej.');
            return;
        }
        alert(payload.message || 'Proforma została oznaczona jako opłacona.');
        window.location.reload();
    }

</script>
