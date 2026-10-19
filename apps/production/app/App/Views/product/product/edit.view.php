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
        <div class="card-header">
            <h4 class="card-title">Edytuj produkt</h4>
        </div>
        <div class="card-body">

            <div class="row">
                <div class="col-2"></div>
                <div class="col-8">
                    <?php if(!empty($errors)):?>
                        <div class="alert alert-danger text-center"><?=implode("<br>", $errors)?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if(!empty($row)): ?>

                <form method="POST">
                    <div class="row">
                        <div class="col-2"></div>
                        <div class="col-4">
                            <div class="row">
                                <label class="form-label" for="name">
                                    Nazwa
                                </label>
                                <input value="<?=old_value('name', $row['name'])?>" type="text" class="form-control" id="name" name="name" placeholder="Nazwa">
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="id_category">
                                    Kategoria
                                </label>
                                <select class="form-select choices" name="id_category" id="id_category">
                                    <?php if(!empty($category_product)): ?>
                                        <?php foreach($category_product as $category): ?>
                                            <option value="<?=$category['id']?>" <?=old_select('id_category', $category['id'], $row['id_category'])?> ><?=$category['name']?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="id_section">
                                    Dział produkcyjny
                                </label>
                                <select class="form-select choices" name="id_section" id="id_section">
                                    <?php if(!empty($production_sections)): ?>
                                        <?php foreach($production_sections as $section): ?>
                                            <option value="<?=$section['id']?>" <?=old_select('id_section', $section['id'], $row['id_section'])?>><?=$section['name']?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="weight">
                                    Waga produktu
                                </label>
                                <input value="<?=old_value('weight', $row['weight'])?>" type="number" class="form-control" id="weight" name="weight" placeholder="Waga">
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="label_type">
                                    Typ etykiety
                                </label>
                                <select class="form-select" name="label_type" id="label_type">
                                    <option value="0" <?php if(old_value('label_type', $row['label_type']) == 0) echo "selected"; ?>>Mała etykieta</option>
                                    <option value="1" <?php if(old_value('label_type', $row['label_type']) == 1) echo "selected"; ?>>Duża etykieta</option>
                                </select>
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="shelf_life">
                                    Czas przydatności do spożycia (dni)
                                </label>
                                <input value="<?=old_value('shelf_life', $row['shelf_life'])?>" type="number" class="form-control" id="shelf_life" name="shelf_life" placeholder="Czas przydatności do spożycia">
                            </div>
                        </div>
                        <div class="col-1"></div>
                        <div class="col-3">
                            <div class="row">
                                <label class="form-label" for="ppc">
                                    Ilość sztuk w kartonie
                                </label>
                                <input value="<?=old_value('ppc', $row['ppc'])?>" type="number" class="form-control" id="ppc" name="ppc" placeholder="0">
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="ppb">
                                    Ilość sztuk w opakowaniu
                                </label>
                                <input value="<?=old_value('ppb', $row['ppb'])?>" type="number" class="form-control" id="ppb" name="ppb" placeholder="0">
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="bpc">
                                    Ilość opakowań w kartonie
                                </label>
                                <input value="<?=old_value('bpc', $row['bpc'])?>" type="number" class="form-control" id="bpc" name="bpc" placeholder="0">
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="cpl">
                                    Ilość kartonów/warstwa
                                </label>
                                <input value="<?=old_value('cpl', $row['cpl'])?>" type="number" class="form-control" id="cpl" name="cpl" placeholder="0">
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="lpp">
                                    Ilość warstw na palecie
                                </label>
                                <input value="<?=old_value('lpp', $row['lpp'])?>" type="number" class="form-control" id="lpp" name="lpp" placeholder="0">
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="cpp">
                                    Ilość kartonów na palecie
                                </label>
                                <input value="<?=old_value('cpp', $row['cpp'])?>" type="number" class="form-control" id="cpp" name="cpp" placeholder="0">
                            </div>
                            <div class="row mt-3">
                                <label class="form-label" for="stock_minimum">
                                    Minimalny stan magazynowy
                                </label>
                                <input value="<?=old_value('stock_minimum', $row['stock_minimum'])?>" type="number" class="form-control" id="stock_minimum" name="stock_minimum" placeholder="0">
                            </div>
                        </div>
                    </div>
                    <div class="row mt-4">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <button class="btn btn-warning">Zapisz</button>
                            <a href="<?=ROOT?>/product-categories/<?=$row['id_category']?>/products" role="button" class="btn btn-secondary float-end">Powrót</a>
                        </div>
                    </div>
                </form>

            <?php else: ?>
                <div class="row">
                    <div class="col-4"></div>
                    <div class="col-4">
                        <div class="alert alert-danger text-center">Produkt nie istnieje!</div>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-4"></div>
                    <div class="col-4 text-center">
                        <a href="<?=ROOT?>/product-categories" role="button" class="btn btn-secondary">Powrót</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </section>
</div>
<?php $this->view("page_components/footer"); ?>