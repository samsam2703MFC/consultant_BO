<?php $this->view("page_components/header"); ?>
<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Surowiec</h3>
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
                <h4 class="card-title">Lista zmian cen</h4>
            </div>
            <div class="card-body">
                <div class="row mt-4">
                    <div class="col-4">
                        <input class="form-control" type="text" placeholder="Wyszukaj po nazwie..."
                               onkeyup="searchInTable(this.value)">
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-12">
                        <table class="table table-striped">
                            <thead>
                            <th style="width: 30%">Produkt</th>
                            <th style="width: 20%">Obecny dostawca</th>
                            <th style="width: 10%">Wielkość opakowania</th>
                            <th style="width: 15%">Cena [opakowanie]</th>
                            <th style="width: 15%">Cena [1000j]</th>
                            <th style="width: 10%"></th>
                            </thead>
                            <tbody id="id_materials_table">
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr id="material_<?= $row['id'] ?>">
                                        <td><?= $row['name'] ?></td>
                                        <td><?= $row['supplier_name'] ?></td>
                                        <td><?= $row['package_size_in_base_unit'] ?> <?= $row['unit_name'] ?></td>
                                        <td><span><?= $row['pack_price'] ?></span> zł</td>
                                        <td><span><?= $row['price_per_1000'] ?></span> zł</td>
                                        <td>
                                            <button class="btn btn-warning btn-open-price-modal"
                                                    data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
                                                    data-id="<?= (int)$row['id'] ?>"
                                                    data-supplier="<?= (int)$row['id_current_supplier'] ?>"
                                                    data-package-size="<?= (float)$row['package_size_in_base_unit'] ?>"
                                                    data-unit="<?= (int)$row['id_unit'] ?>">
                                                Nowa cena
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

<div class="modal fade" id="newPriceModal" tabindex="-1" role="dialog"
     aria-labelledby="exampleModalCenterTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-centered"
         role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"></h5>
                <button type="button" class="close" data-bs-dismiss="modal"
                        aria-label="Close">
                    <i data-feather="x"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-12 text-center">
                        <h3 id="id_material_name"></h3>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-2">
                        <input type="hidden" id="id_selected_material">
                    </div>
                    <div class="col-8">
                        <label for="id_current_supplier" class="form-label">
                            Dostawca
                        </label>
                        <select class="form-select choices" name="id_current_supplier" id="id_current_supplier">
                            <?php if (!empty($suppliers)): ?>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= $supplier['id'] ?>"><?= $supplier['name'] ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="row mt-5">
                    <div class="col-5">
                        <label class="form-label" for="package_size_in_base_unit">
                            Wielkość opakowania [g/ml/szt]
                        </label>
                        <input type="number" class="form-control" name="package_size_in_base_unit"
                               id="package_size_in_base_unit" onfocus="this.select();">
                    </div>
                    <div class="col-2">
                        <label class="form-label" for="id_unit">
                            Jednostka
                        </label>
                        <select class="form-select" name="id_unit" id="id_unit">
                            <?php if (!empty($units)): ?>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?= $unit['id'] ?>"><?= $unit['name'] ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-5">
                        <label class="form-label" for="pack_price">
                            Nowa cena za opakowanie [zł]
                        </label>
                        <input type="number" class="form-control" name="pack_price" step="0.01" id="pack_price"
                               required>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" onclick="saveNewMaterialPrice()">
                    <span class="d-none d-sm-block">Zapisz</span>
                </button>
                <button type="button" class="btn btn-light-secondary"
                        data-bs-dismiss="modal">
                    <i class="bx bx-x d-block d-sm-none"></i>
                    <span class="d-none d-sm-block">Zamknij</span>
                </button>
            </div>
        </div>
    </div>
</div>

<?php $this->view("page_components/footer"); ?>

<script type="text/javascript">

    function searchInTable(input_value) {
        // Declare variables
        var filter, table, tr, td, i, txtValue;
        filter = input_value.toUpperCase();
        table = document.getElementById("id_materials_table");
        tr = table.getElementsByTagName("tr");

        // Loop through all table rows, and hide those who don't match the search query
        for (i = 0; i < tr.length; i++) {
            td = tr[i].getElementsByTagName("td")[0];
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


    const newPriceModal = new bootstrap.Modal(document.getElementById("newPriceModal"), {});

    document.getElementById('id_materials_table').addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-open-price-modal');
        if (!btn) return;
        runNewPriceModal(
            btn.dataset.name,
            btn.dataset.id,
            btn.dataset.supplier,
            btn.dataset.packageSize,
            btn.dataset.unit
        );
    });

    function runNewPriceModal(material_name, id_material, id_supplier, package_size, id_unit) {
        document.getElementById('id_material_name').innerText = material_name;
        document.getElementById('package_size_in_base_unit').value = package_size;
        document.getElementById('id_selected_material').value = id_material;
        const supplier_select = document.getElementById('id_current_supplier');
        const unit_select = document.getElementById('id_unit');

        for (let i = 0; i < supplier_select.options.length; i++) {
            if (supplier_select.options[i].value == id_supplier) {
                supplier_select.options[i].selected = true;
                break;
            }
        }

        for (let i = 0; i < unit_select.options.length; i++) {
            if (unit_select.options[i].value == id_unit) {
                unit_select.options[i].selected = true;
                break;
            }
        }


        newPriceModal.show();


    }

    function validate() {


        if (document.getElementById('package_size_in_base_unit').value == "" || document.getElementById('package_size_in_base_unit').value == 0) {
            document.getElementById('package_size_in_base_unit').classList.add("is-invalid");
            return false;
        } else {
            document.getElementById('package_size_in_base_unit').classList.remove("is-invalid");
        }

        if (document.getElementById('pack_price').value == "" || document.getElementById('pack_price').value == 0) {
            document.getElementById('pack_price').classList.add("is-invalid");
            return false;
        } else {
            document.getElementById('pack_price').classList.remove("is-invalid");
        }

        return true;
    }


    function saveNewMaterialPrice() {

        if (!validate()) return;

        const id_material = document.getElementById('id_selected_material').value;
        const unit_sel = document.getElementById('id_unit');
        let package_size_in_base_unit_val = document.getElementById('package_size_in_base_unit').value;
        let pack_price = document.getElementById('pack_price');

        const data = {
            id_raw_material: id_material,
            id_current_supplier: document.getElementById('id_current_supplier').value,
            package_size_in_base_unit: package_size_in_base_unit_val,
            id_unit: unit_sel.value,
            pack_price: pack_price.value
        }

        Swal.fire({
            title: 'Zapisuję cenę i przeliczam koszty receptur…',
            text: 'Proszę czekać',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        api(`/ajax/materials/${id_material}/price`, {
            method: 'PATCH',
            data
        })
            .then((resp) => {
                if (!resp || !resp.ok) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Błąd',
                        text: (resp && resp.message) ? resp.message : 'Nie udało się zaktualizować ceny'
                    });
                    return;
                }

                // Aktualizacja UI
                const row = document.getElementById('material_' + id_material);
                row.cells[2].innerText = `${package_size_in_base_unit_val} ${unit_sel[unit_sel.selectedIndex].innerHTML}`;
                const pack_price_formatted = parseFloat(pack_price.value).toFixed(2);
                row.cells[3].innerText = `${pack_price_formatted} zł`;
                const price_per_1000 = ((1000 * pack_price.value) / package_size_in_base_unit_val).toFixed(2);
                row.cells[4].innerText = `${price_per_1000} zł`;

                Swal.fire({
                    icon: 'success',
                    title: 'Zapisano',
                    text: 'Cena zaktualizowana, koszty receptur przeliczone'
                });

                newPriceModal.hide();
                pack_price.value = "";
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Błąd',
                    text: 'Nie udało się wykonać zapytania. Sprawdź połączenie i spróbuj ponownie.'
                });
            });
    }


</script>