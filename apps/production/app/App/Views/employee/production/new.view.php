<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Pracownik</h3>
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
                    <h4 class="card-title">Wprowadzanie nowego pracownika produkcji</h4>
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

                    <form method="POST">
                        <div class="row">
                            <div class="col-2"></div>
                            <div class="col-4">
                                <label class="form-label" for="name">
                                    Imię
                                </label>
                                <input value="<?=old_value('name')?>" type="text" class="form-control" id="name" name="name" placeholder="Imię">
                            </div>
                            <div class="col-4">
                                <label class="form-label" for="surname">
                                    Nazwisko
                                </label>
                                <input value="<?=old_value('surname')?>" type="text" class="form-control" id="surname" name="surname" placeholder="Nazwisko">
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-4">
                                <div class="row">
                                    <label class="form-label" for="id_section">
                                        Dział produkcyjny
                                    </label>
                                    <select class="form-select" name="id_section" id="id_section">
                                        <?php if(!empty($production_sections)): ?>
                                            <?php foreach($production_sections as $section): ?>
                                                <option value="<?=$section['id']?>" <?=old_select('id_section', $section['id'])?>><?=$section['name']?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label" for="cost">
                                    Koszt [zł]
                                </label>
                                <input value="<?=old_value('cost')?>" type="text" class="form-control" id="cost" name="cost" placeholder="Koszt">
                            </div>
                        </div>


                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <button class="btn btn-success">Dodaj</button>
                                <a href="<?=ROOT?>/production-employees" role="button" class="btn btn-secondary float-end">Powrót</a>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
    </section>
</div>

<?php $this->view("page_components/footer")?>