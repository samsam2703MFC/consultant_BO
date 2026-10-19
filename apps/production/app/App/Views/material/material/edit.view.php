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
                    <h4 class="card-title">Edytuj materiał</h4>
                </div>
                <div class="card-body">

                    <div class="row">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger text-center"><?= implode("<br>", $errors) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($row)): ?>
                        <?php
                        $hasPostedPackagingUnitWeight = array_key_exists('packaging_unit_weight_g', $_POST);
                        $packagingUnitWeight = $hasPostedPackagingUnitWeight
                            ? $_POST['packaging_unit_weight_g']
                            : ($row['packaging_unit_weight_g'] ?? '');
                        if (!$hasPostedPackagingUnitWeight && $packagingUnitWeight !== '' && is_numeric($packagingUnitWeight)) {
                            $packagingUnitWeight = rtrim(rtrim(number_format((float)$packagingUnitWeight, 4, '.', ''), '0'), '.');
                        }
                        ?>

                        <form method="POST">
                            <div class="row">
                                <div class="col-4">
                                    <div class="row">
                                        <label class="form-label" for="name">
                                            Nazwa
                                        </label>
                                        <input value="<?= old_value('name', $row['name']) ?>" type="text"
                                               class="form-control" id="name" name="name" placeholder="Nazwa">
                                    </div>
                                    <div class="row mt-3">
                                        <label class="form-label" for="id_category">
                                            Kategoria
                                        </label>
                                        <select class="form-select" name="id_category" id="id_category">
                                            <?php if (!empty($category_material)): ?>
                                                <?php foreach ($category_material as $category): ?>
                                                    <option value="<?= $category['id'] ?>" <?= old_select('id_category', $category['id'], $row['id_category']) ?> ><?= $category['name'] ?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                </div>
                                <div class="col-1"></div>
                                <div class="col-3">
                                    <div class="row">
                                        <label class="form-label" for="waste_perc">
                                            Wykorzystanie materiału [%]
                                        </label>
                                        <input value="<?= old_value('waste_perc', $row['waste_perc']) ?>" type="number"
                                               class="form-control" id="waste_perc" name="waste_perc" placeholder="0">
                                    </div>
                                    <div class="row mt-5">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox"
                                                   name="is_part_of_package" id="is_part_of_package" <?= old_checkbox('is_part_of_package', 1, $row['is_part_of_package']) ?>
                                                   value="1">
                                            <label class="form-check-label" for="is_part_of_package">
                                                Jest elementem opakowania
                                            </label>
                                        </div>
                                    </div>
                                    <div id="bdo-packaging-fields" class="mt-4" <?= empty($row['is_part_of_package']) ? 'style="display:none"' : '' ?>>
                                        <div class="row mt-3">
                                            <label class="form-label" for="bdo_material_category">Kategoria BDO</label>
                                            <select class="form-select" name="bdo_material_category" id="bdo_material_category">
                                                <option value="">Wybierz</option>
                                                <?php foreach (['PLASTIC' => 'Tworzywa sztuczne', 'ALUMINIUM' => 'Aluminium', 'FERROUS_METAL' => 'Metale żelazne', 'PAPER_CARDBOARD' => 'Papier i tektura', 'GLASS' => 'Szkło', 'WOOD' => 'Drewno', 'MULTIMATERIAL' => 'Wielomateriałowe', 'OTHER' => 'Pozostałe'] as $value => $label): ?>
                                                    <option value="<?= $value ?>" <?= old_select('bdo_material_category', $value, $row['bdo_material_category'] ?? '') ?>><?= $label ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="row mt-3">
                                            <label class="form-label" for="packaging_usage_type">Rodzaj użycia</label>
                                            <select class="form-select" name="packaging_usage_type" id="packaging_usage_type">
                                                <option value="">Wybierz</option>
                                                <option value="SINGLE_USE" <?= old_select('packaging_usage_type', 'SINGLE_USE', $row['packaging_usage_type'] ?? '') ?>>Jednorazowe</option>
                                                <option value="REUSABLE" <?= old_select('packaging_usage_type', 'REUSABLE', $row['packaging_usage_type'] ?? '') ?>>Wielokrotnego użytku</option>
                                            </select>
                                        </div>
                                        <div class="row mt-3">
                                            <label class="form-label" for="packaging_unit_weight_g">Masa jednej sztuki [g]</label>
                                            <input value="<?= htmlspecialchars((string)$packagingUnitWeight) ?>" type="number" min="0" step="0.0001" class="form-control" id="packaging_unit_weight_g" name="packaging_unit_weight_g" placeholder="Nie dotyczy materiałów w g">
                                            <small class="text-muted">Dla materiału w jednostce g masa pochodzi bezpośrednio z receptury.</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-1"></div>
                                <div class="col-3">
                                    <div class="row">
                                        <label class="form-label" for="energy_100g">
                                            Wartość energetyczna (kcal) w 100g
                                        </label>
                                        <input value="<?= old_value('energy_100g', $row['energy_100g']) ?>" type="number"
                                               class="form-control" id="energy_100g" name="energy_100g"
                                               placeholder="Wartość energ.">
                                    </div>
                                    <div class="row mt-3">
                                        <label class="form-label" for="fat_100g">
                                            Tłuszcz [g] w 100g
                                        </label>
                                        <input value="<?= old_value('fat_100g', $row['fat_100g']) ?>" type="number"
                                               class="form-control" id="fat_100g" name="fat_100g"
                                               placeholder="Tłuszcz [g]">
                                    </div>
                                    <div class="row mt-3">
                                        <label class="form-label" for="saturated_fat_100g">
                                            w tym kwasy tłuszczowe nasycone [g] w 100g
                                        </label>
                                        <input value="<?= old_value('saturated_fat_100g', $row['saturated_fat_100g']) ?>"
                                               type="number" class="form-control" id="saturated_fat_100g"
                                               name="saturated_fat_100g" placeholder="Nasycone [g]">
                                    </div>
                                    <div class="row mt-3">
                                        <label class="form-label" for="carbohydrates_100g">
                                            Węglowodany [g] w 100g
                                        </label>
                                        <input value="<?= old_value('carbohydrates_100g', $row['carbohydrates_100g']) ?>"
                                               type="number" class="form-control" id="carbohydrates_100g"
                                               name="carbohydrates_100g" placeholder="Węglowodany [g]">
                                    </div>
                                    <div class="row mt-3">
                                        <label class="form-label" for="sugars_100g">
                                            w tym cukry [g] w 100g
                                        </label>
                                        <input value="<?= old_value('sugars_100g', $row['sugars_100g']) ?>" type="number"
                                               class="form-control" id="sugars_100g" name="sugars_100g"
                                               placeholder="Cukry [g]">
                                    </div>
                                    <div class="row mt-3">
                                        <label class="form-label" for="salt_100g">
                                            Sól [g] w 100g
                                        </label>
                                        <input value="<?= old_value('salt_100g', $row['salt_100g']) ?>" type="number"
                                               class="form-control" id="salt_100g" name="salt_100g"
                                               placeholder="Sól [g]">
                                    </div>
                                    <div class="row mt-3">
                                        <label class="form-label" for="fiber_100g">
                                            Błonnik [g] w 100g
                                        </label>
                                        <input value="<?= old_value('fiber_100g', $row['fiber_100g']) ?>" type="number"
                                               class="form-control" id="fiber_100g" name="fiber_100g"
                                               placeholder="Błonnik [g]">
                                    </div>
                                    <div class="row mt-3">
                                        <label class="form-label" for="proteins_100g">
                                            Białko [g] w 100g
                                        </label>
                                        <input value="<?= old_value('proteins_100g', $row['proteins_100g']) ?>"
                                               type="number" class="form-control" id="proteins_100g"
                                               name="proteins_100g" placeholder="Białko [g]">
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-2"></div>
                                <div class="col-8">
                                    <button class="btn btn-warning">Zapisz</button>
                                    <a href="<?= ROOT ?>/material-categories/<?= $row['id_category'] ?>/materials"
                                       role="button" class="btn btn-secondary float-end">Powrót</a>
                                </div>
                            </div>
                        </form>

                    <?php else: ?>
                        <div class="row">
                            <div class="col-4"></div>
                            <div class="col-4">
                                <div class="alert alert-danger text-center">Surowiec nie istnieje nie istnieje!</div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <a href="<?= ROOT ?>/material-categories" role="button" class="btn btn-secondary">Powrót</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
<?php $this->view("page_components/footer"); ?>
<script>
    document.getElementById('is_part_of_package').addEventListener('change', function () {
        document.getElementById('bdo-packaging-fields').style.display = this.checked ? '' : 'none';
    });
</script>
