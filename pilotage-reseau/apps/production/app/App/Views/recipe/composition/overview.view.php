<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Kompozycja</h3>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?=ROOT?>/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Kompozycja
                        </li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>
    <section class="section">
        <?php $recipeTab = 'composition'; require dirname(__DIR__) . '/_tabs.view.php'; ?>

        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Lista utworzonych kompozycji <a href="<?= ROOT ?>/compositions/new" class="btn btn-primary" role="button">Dodaj
                        nową</a></h4>
            </div>
            <div class="card-body">
                <div class="row mt-2">
                    <div class="col-12">
                        <table class="table table-hover" data-at-table>
                            <thead>
                            <th style="width: 55%">Nazwa</th>
                            <th style="width: 25%">Koszt surowców</th>
                            <th style="width: 20%" class="text-end">Akcje</th>
                            </thead>
                            <tbody id="id_recipe_table">
                            <?php if (!empty($compositions)): ?>
                                <?php foreach ($compositions as $composition): ?>
                                    <tr>
                                        <td><?= esc($composition['name'] ?? '') ?></td>
                                        <td><?= $composition['precalculated_material_cost'] ?> zł
                                            / <?= $composition['yield_quantity'] ?> <?= esc($composition['unit_name'] ?? '') ?></td>
                                        <td class="text-end">
                                            <div class="at-actions">
                                                <a href="<?= ROOT ?>/compositions/<?= $composition['id'] ?>/calculation" class="btn btn-sm btn-primary">Kalkulacja</a>
                                                <div class="at-more">
                                                    <button type="button" class="at-more-toggle" aria-haspopup="menu" aria-expanded="false" aria-label="Więcej akcji" title="Więcej akcji"><i class="bi bi-three-dots"></i></button>
                                                    <div class="at-more-menu" role="menu" hidden>
                                                        <a role="menuitem" href="<?= ROOT ?>/compositions/<?= $composition['id'] ?>/edit"><i class="bi bi-pencil"></i>Edytuj</a>
                                                        <a role="menuitem" href="<?= ROOT ?>/recipes/<?= $composition['id'] ?>/preparation-steps"><i class="bi bi-list-ol"></i><?= esc($translations['instruction'] ?? 'Instrukcja') ?></a>
                                                        <?php if (!empty($composition['has_elements'])): ?>
                                                            <button type="button" role="menuitem" class="composition-card-btn" data-id-recipe="<?= $composition['id'] ?>"><i class="bi bi-file-earmark-arrow-down"></i>Karta (PDF)</button>
                                                        <?php else: ?>
                                                            <button type="button" role="menuitem" disabled title="Kompozycja nie ma składników"><i class="bi bi-file-earmark-arrow-down"></i>Karta — brak składników</button>
                                                        <?php endif; ?>
                                                        <hr>
                                                        <a role="menuitem" class="at-more-danger" href="<?= ROOT ?>/compositions/<?= $composition['id'] ?>/delete"><i class="bi bi-trash"></i>Usuń…</a>
                                                    </div>
                                                </div>
                                            </div>
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

