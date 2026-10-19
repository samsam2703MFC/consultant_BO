<?php $this->view("page_components/header"); ?>
    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Produkt testowy</h3>
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
                    <h4 class="card-title">Edytuj testowy produkt</h4>
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

                        <form method="POST">
                            <div class="row">
                                <div class="col-4"></div>
                                <div class="col-4">
                                    <div class="row">
                                        <label class="form-label" for="id_type">
                                            Typ
                                        </label>
                                        <select class="form-select" name="id_type" id="id_type">
                                            <?php if (!empty($product_type)): ?>
                                                <?php foreach ($product_type as $type): ?>
                                                    <option value="<?= $type['id'] ?>" <?= old_select('id_type', $type['id'], $row['id_type']) ?> ><?= $type['name'] ?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="row mt-4">
                                        <label class="form-label" for="name">
                                            Nazwa
                                        </label>
                                        <input value="<?= old_value('name', $row['name']) ?>" type="text"
                                               class="form-control" id="name" name="name" placeholder="Nazwa">
                                    </div>
                                    <div class="row mt-4">
                                        <label class="form-label" for="id_category">
                                            Kategoria
                                        </label>
                                        <select class="form-select" name="id_category" id="id_category">
                                            <?php if (!empty($category_product)): ?>
                                                <?php foreach ($category_product as $category): ?>
                                                    <option value="<?= $category['id'] ?>" <?= old_select('id_category', $category['id'], $row['id_category']) ?> ><?= $category['name'] ?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="row mt-4">
                                        <label class="form-label" for="id_section">
                                            Dział produkcyjny
                                        </label>
                                        <select class="form-select" name="id_section" id="id_section">
                                            <?php if (!empty($production_sections)): ?>
                                                <?php foreach ($production_sections as $section): ?>
                                                    <option value="<?= $section['id'] ?>" <?= old_select('id_section', $section['id'], $row['id_section']) ?>><?= $section['name'] ?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="row mt-4">
                                        <label class="form-label" for="id_status">
                                            Status produktu
                                        </label>
                                        <select class="form-select" name="id_status" id="id_status">
                                            <?php if (!empty($product_status)): ?>
                                                <?php foreach ($product_status as $status): ?>
                                                    <option value="<?= $status['id'] ?>" <?= old_select('id_status', $status['id'], $row['id_status']) ?>><?= $status['name'] ?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="row mt-4">
                                        <label class="form-label" for="label_type">
                                            Typ etykiety
                                        </label>
                                        <select class="form-select" name="label_type" id="label_type">
                                            <option value="0" <?php if (old_value('label_type', $row['label_type']) == 0) echo "selected"; ?>>
                                                Mała etykieta
                                            </option>
                                            <option value="1" <?php if (old_value('label_type', $row['label_type']) == 1) echo "selected"; ?>>
                                                Duża etykieta
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-2"></div>
                                <div class="col-8">
                                    <button class="btn btn-warning">Zapisz</button>
                                    <a href="<?= ROOT ?>/test-products" role="button"
                                       class="btn btn-secondary float-end">Powrót</a>
                                </div>
                            </div>
                        </form>

                    <?php else: ?>
                        <div class="row">
                            <div class="col-4"></div>
                            <div class="col-4">
                                <div class="alert alert-danger text-center">Produkt testowy nie istnieje!</div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <a href="<?= ROOT ?>/test-products" role="button" class="btn btn-secondary">Powrót</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
<?php $this->view("page_components/footer"); ?>