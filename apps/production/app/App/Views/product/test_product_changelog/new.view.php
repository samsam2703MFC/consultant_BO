<?php $this->view("page_components/header"); ?>
    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Dziennik produktu testowego</h3>
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
                    <h4 class="card-title">Nowy wpis w dzienniku produktu</h4>
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
                            <div class="col-4"></div>
                            <div class="col-4">
                                <div class="row">
                                    <label class="form-label" for="id_stage">
                                        Nazwa etapu
                                    </label>
                                    <select class="form-select" name="id_stage" id="id_stage">
                                        <?php if(!empty($test_product_stages)): ?>
                                            <?php foreach($test_product_stages as $stage): ?>
                                                <option value="<?=$stage['id']?>" <?=old_select('title', $stage['id'])?> ><?=$stage['name']?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="row mt-3">
                                    <label class="form-label" for="description">
                                        Komentarz
                                    </label>
                                    <textarea class="form-control" name="description" id="description" placeholder="Opis"><?=old_value('description')?></textarea>
                                </div>
                                <div class="row mt-3">
                                    <label class="form-label" for="deadline">
                                        Termin ostatecznny
                                    </label>
                                    <input value="<?=old_value('deadline')?>" type="datetime-local" class="form-control" name="deadline" id="deadline">
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <button class="btn btn-success">Utwórz</button>
                                <a href="<?=ROOT?>/test-products/<?=$id_product?>/changelogs" role="button" class="btn btn-secondary float-end">Powrót</a>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </section>
    </div>
<?php $this->view("page_components/footer"); ?>