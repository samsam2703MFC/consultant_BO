<?php $this->view("page_components/header"); ?>
<style>
    .price-warning {
        color: #f39c12; /* żółty */
        margin-left: 8px;
        cursor: help;
    }
</style>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Cena produktu gotowego</h3>
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
                <h4 class="card-title">Edytuj cenę produktu</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-2"></div>
                    <div class="col-8">
                        <div id="zero-price-alert" class="alert alert-warning d-none text-black" role="alert"></div>
                    </div>


                    <div class="col-4"></div>
                    <div class="col-4 text-center">
                        <h4 id="client_name"><?=$client['company']?></h4>
                    </div>
                </div>
                <div class="row mt-5">

                    <div class="col-4">
                        <div class="row mx-3">
                            <input class="form-control" type="text" placeholder="Wyszukaj po nazwie/SKU..."
                                   onkeyup="searchInTable(this.value)">
                        </div>
                        <div class="row mt-4 mx-3">
                            <div class="form-switch">
                                <input class="form-check-input" type="checkbox" name="show-inactive" id="show-inactive" onchange="toggleInactive(this);" value="1">
                                <label for="show-inactive" class="form-label">Pokaż wyłączone</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-5"></div>
                    <div class="col-3">
                        <div class="row">
                            <button class="btn btn-primary" onclick="getPriceListByClientCSV(<?=$id_customer?>, this)">
                                Pobierz cennik dostępnych produktów [.csv]
                            </button>
                        </div>
                        <div class="row mt-2">
                            <button class="btn btn-info" onclick="getPriceListByClientJSON(<?=$id_customer?>, this)">
                                Pobierz cennik dostępnych produktów [.json]
                            </button>
                        </div>
                        <div class="row mt-4">
                            <a href="<?= ROOT ?>/clients/<?= $id_customer ?>/price-list/global-increase" role="button"
                               class="btn btn-dark ">Podnieś wszystkie ceny</a>
                        </div>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-12">
                        <table class="table table-hover">
                            <thead>
                            <th style="width: 1%"></th>
                            <th style="width: 5%"></th>
                            <th style="width: 10%">SKU</th>
                            <th style="width: 65%">Produkt</th>
                            <th style="width: 19%"></th>
                            </thead>
                            <tbody id="id_final_product_table">
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $row): ?>
                                <?php $rowClass = $row['is_available'] == 0 ? "hideRow prod-inactive bg-light-danger" : ""; ?>
                                    <tr class="<?=$rowClass?>">
                                        <td></td>
                                        <td>
                                            <?php if($row['is_available'] == 0): ?>
                                                <button class="btn btn-success btn-sm" onclick="enableProduct(<?=$row['id']?>, <?=$id_customer?>, this)"> <i class="icon dripicons-return"></i></button>
                                            <?php else: ?>
                                                <button class="btn btn-danger btn-sm" onclick="disableProduct(<?=$row['id']?>, <?=$id_customer?>, this)" > <i class="icon dripicons-trash"></i></button>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $row['id'] ?></td>
                                        <td><?= $row['name'] ?></td>
                                        <td>
                                            <input type="number" class="form-control" min="0" step="0.01"
                                                   value="<?= $row['price'] ?>" disabled>
                                        </td>
                                        <td>
                                            <button class="btn btn-warning" type="button" onclick="enableUpdate(this)">
                                                Zmień
                                            </button>
                                            <button class="btn btn-success" type="button"
                                                    onclick="saveNewPrice('<?= $row['id'] ?>', '<?= $row['id_customer'] ?>', this)"
                                                    style="display: none">Zapisz
                                            </button>
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
    </section>
</div>


<?php $this->view("page_components/footer"); ?>

<script>

    document.addEventListener("DOMContentLoaded", checkZeroPrices);


    function toggleInactive(inactive_switch){
        const table = document.getElementById("id_final_product_table");
        const rows = table.getElementsByTagName("tr");

        for (let i = 0; i < rows.length; i++) {
            if(inactive_switch.checked){
                rows[i].classList.remove("hideRow");
            } else {
                if(rows[i].classList.contains("prod-inactive")){
                    rows[i].classList.add("hideRow");
                }
            }
        }
    }

    function disableProduct(id_product, id_client, btn){
        api(`/ajax/clients/${id_client}/products/${id_product}/status`, {
            method: 'PATCH',
            data: {available: 0}
        })
            .then(function (res) {
                showSnack(res);
                if (res) {
                    const row = btn.parentNode.parentNode;
                    row.classList.add("bg-light-danger");
                    row.classList.add("prod-inactive");
                    btn.parentNode.innerHTML = `<button class="btn btn-success btn-sm" onclick="enableProduct(${id_product}, ${id_client}, this)"> <i class="icon dripicons-return"></button>`;

                    if(!document.getElementById("show-inactive").checked){
                        row.classList.add("hideRow");
                    }
                }
            })
    }

    function enableProduct(id_product, id_client, btn){
        api(`/ajax/clients/${id_client}/products/${id_product}/status`, {
            method: 'PATCH',
            data: {available: 1}
        })
            .then(function (res) {
                showSnack(res);
                if (res) {
                    btn.parentNode.parentNode.classList.remove("bg-light-danger");
                    btn.parentNode.parentNode.classList.remove("prod-inactive");
                    btn.parentNode.parentNode.classList.remove("prod-hideRow");
                    btn.parentNode.innerHTML = `<button class="btn btn-danger btn-sm" onclick="disableProduct(${id_product}, ${id_client}, this)"> <i class="icon dripicons-trash"></button>`;

                    checkZeroPrices();
                }
            })
    }

    function enableUpdate(btn) {
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let saveBtn = btn.parentNode.children[1];
        btn.style.display = "none";
        saveBtn.style.display = "inline-block";
        previousColInput.disabled = false;
    }

    function disableUpdate(btn) {
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let enableBtn = btn.parentNode.children[0];
        btn.style.display = "none";
        enableBtn.style.display = "inline-block";
        previousColInput.disabled = true;
    }

    function saveNewPrice(id_product, id_customer, btn) {
        const data = {
            "id_final_product": id_product,
            "id_customer": id_customer,
            "price": btn.parentNode.parentNode.children[4].children[0].value
        }

        api(`/ajax/clients/${id_customer}/products/${id_product}/price`, {
            method: 'PATCH',
            data
        })
            .then(function (res) {
            showSnack(res);
            if (res) {
                disableUpdate(btn);
                checkZeroPrices();

            }
        })
    }

    async function getPriceListByClientCSV(idCustomer, btn){

        if(document.getElementById("zero-price-alert").innerText !== ""){
            const confirmResult = await Swal.fire({
                title: "Uwaga!",
                text: "Produkty z ceną 0 nie będą zawarte w pliku. Czy chcesz kontynuować?",
                icon: "warning",
                showCancelButton: true,
                confirmButtonText: "Tak",
                cancelButtonText: "Anuluj"
            });
            if (!confirmResult.isConfirmed) return;
        }

        const clientName = document.getElementById('client_name').innerText;

        //add spiner to btn
        btn.disabled = true;
        btn.innerHTML = 'Pobieranie...';


        try {
            await downloadCSV(
                `/ajax/clients/${idCustomer}/price-list/csv`,
                {},
                `price_list_${clientName}.csv`
            );


        } catch (err) {
            console.error(err);
            showSnack(false, err.message || 'Something went wrong.');
        } finally {
            btn.innerHTML = 'Pobierz cennik dostępnych produktów [.csv]';
            btn.disabled = false;
        }
    }

    async function getPriceListByClientJSON(idCustomer, btn){

        if(document.getElementById("zero-price-alert").innerText !== ""){
            const confirmResult = await Swal.fire({
                title: "Uwaga!",
                text: "Produkty z ceną 0 nie będą zawarte w pliku. Czy chcesz kontynuować?",
                icon: "warning",
                showCancelButton: true,
                confirmButtonText: "Tak",
                cancelButtonText: "Anuluj"
            });
            if (!confirmResult.isConfirmed) return;
        }

        const clientName = document.getElementById('client_name').innerText;

        // Spinner
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = 'Pobieranie...';

        try {
            const response = await fetch(`<?= ROOT ?>/ajax/clients/${idCustomer}/price-list/json`);

            if (!response.ok) {
                throw new Error('Błąd pobierania pliku');
            }

            // Pobierz dane JSON
            const blob = await response.blob();

            // Stwórz link do pobrania
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `price_list_${clientName}_${new Date().toISOString().slice(0,10)}.json`;
            document.body.appendChild(a);
            a.click();

            // Cleanup
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);

            showSnack(true, 'Plik JSON został pobrany');
        } catch (err) {
            console.error(err);
            showSnack(false, err.message || 'Wystąpił błąd podczas pobierania pliku JSON');
        } finally {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }

    function searchInTable(input_value) {
        const filter = input_value.toUpperCase();
        const table = document.getElementById("id_final_product_table");
        const tr = table.getElementsByTagName("tr");

        for (let i = 0; i < tr.length; i++) {
            const tds = tr[i].getElementsByTagName("td");
            if (tds.length >= 2) {
                const skuText = (tds[1].textContent || tds[1].innerText).toUpperCase();
                const nameText = (tds[2].textContent || tds[2].innerText).toUpperCase();

                // sprawdzenie, czy którakolwiek kolumna zawiera filtr
                if (skuText.indexOf(filter) > -1 || nameText.indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    }

    function checkZeroPrices() {
        const rows = document.querySelectorAll("#id_final_product_table tr");
        const alertBox = document.getElementById("zero-price-alert");
        let zeroPriceCount = 0;

        rows.forEach(row => {
            if(row.classList.contains("hideRow") || row.classList.contains("prod-inactive")) return;


            const priceInput = row.querySelector("input[type='number']");
            if (!priceInput) return;

            const price = parseFloat(priceInput.value);
            const firstCell = row.cells[0]; // kolumna z ikoną

            // usuń starą ikonę, jeśli istnieje
            const existingIcon = firstCell.querySelector(".price-warning");
            if (existingIcon) existingIcon.remove();

            row.classList.remove("bg-light-warning");

            // jeśli cena = 0 → dodaj nową ikonę
            if (price === 0) {
                zeroPriceCount++;

                const warning = document.createElement("i");
                warning.className = "bi bi-exclamation-triangle-fill price-warning";
                warning.title = "Cena produktu wynosi 0";
                warning.setAttribute("data-bs-toggle", "tooltip");
                new bootstrap.Tooltip(warning);

                firstCell.appendChild(warning);
                row.classList.add("bg-light-warning");
            }

        });

        // aktualizacja komunikatu
        if (zeroPriceCount > 0) {
            alertBox.classList.remove("d-none");
            alertBox.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>
            ${zeroPriceCount} ${zeroPriceCount === 1 ? "produkt ma" : "produktów ma"} cenę 0.`;
        } else {
            alertBox.classList.add("d-none");
            alertBox.innerHTML = "";
        }
    }

</script>
