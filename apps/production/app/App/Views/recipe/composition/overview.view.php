<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Kompozycja</h3>
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
                <h4 class="card-title">Lista utworzonych kompozycji <a href="<?= ROOT ?>/compositions/new" class="btn btn-primary" role="button">Dodaj
                        nową</a></h4>
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
                        <table class="table table-hover">
                            <thead>
                            <th style="width: 50%">Nazwa</th>
                            <th style="width: 20%"></th>
                            <th style="width: 10%"></th>
                            <th style="width: 10%"></th>
                            <th style="width: 10%"></th>
                            <th style="width: 10%"></th>
                            <th style="width: 10%"></th>
                            </thead>
                            <tbody id="id_recipe_table">
                            <?php if (!empty($compositions)): ?>
                                <?php foreach ($compositions as $composition): ?>
                                    <tr>
                                        <td><?= $composition['name'] ?></td>
                                        <td><?= $composition['precalculated_material_cost'] ?> zł
                                            / <?= $composition['yield_quantity'] ?> <?= $composition['unit_name'] ?></td>
                                        <td class="text-center"><a
                                                    href="<?= ROOT ?>/compositions/<?= $composition['id'] ?>/preparation-steps"
                                                    class="btn btn-primary"><?= $translations['instruction'] ?? 'Instrukcja' ?></a></td>
                                        <td class="text-center"><a
                                                    href="<?= ROOT ?>/compositions/<?= $composition['id'] ?>/calculation"
                                                    class="btn btn-info">Kalkulacja</a></td>
                                        <td class="text-center">
                                            <?php if (!empty($composition['has_elements'])): ?>
                                                <button class="btn btn-secondary btn-sm composition-card-btn"
                                                        data-id-recipe="<?= $composition['id'] ?>">
                                                    Karta
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-secondary btn-sm" disabled
                                                        title="Kompozycja nie ma składników">
                                                    Brak składników
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><a
                                                    href="<?= ROOT ?>/compositions/<?= $composition['id'] ?>/edit"
                                                    class="btn btn-warning">Edytuj</a></td>
                                        <td class="text-center"><a
                                                    href="<?= ROOT ?>/compositions/<?= $composition['id'] ?>/delete"
                                                    class="btn btn-danger">Usuń</a></td>
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
    document.querySelectorAll('.composition-card-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            const idRecipe = this.getAttribute('data-id-recipe');
            downloadPDF(
                `/ajax/compositions/${idRecipe}/technical-card`,
                {},
                "Karta_kompozycji"
            );
        });
    });
    function searchInTable(input_value) {
        // Declare variables
        var filter, table, tr, td, i, txtValue;
        filter = input_value.toUpperCase();
        table = document.getElementById("id_recipe_table");
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

    function filterTable(select_value) {
        const table = document.getElementById('id_recipe_table');
        const tr = table.getElementsByTagName("tr");
        let txtValue;
        for (let i = 0; i < tr.length; i++) {
            console.log(tr[i]);

            let td = tr[i].getElementsByTagName("td")[0].getElementsByTagName("input")[0].value;
            if (td) {
                txtValue = td;
                if (txtValue.toUpperCase().indexOf(select_value) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    }
</script>

