<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Dostawca</h3>
                </div>
                <div class="col-12 col-md-6 order-md-2 order-first">
                    <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?=ROOT?>/dashboard">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Dostawca
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <section class="section">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Edytuj dostawcę</h4>
                </div>
                <div class="card-body">

                    <div class="row">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <?php if(!empty($errors)):?>
                                <div class="alert alert-danger text-center"><?= implode("<br>", array_map('htmlspecialchars', $errors)) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if(!empty($row) && (isset($_GET['created']) || isset($_GET['saved']))): ?>
                        <div class="at-note at-note-safe" role="status"><i class="bi bi-check-circle"></i><strong><?= isset($_GET['created']) ? 'Dostawca utworzony.' : 'Zapisano.' ?></strong>
                            <?php if (isset($_GET['created'])): ?> Teraz : <a href="<?= ROOT ?>/materials/all">przypisz go do surowców</a> (karta surowca › dostawca) albo <a href="<?= ROOT ?>/workshop/supply">zamów w Zaopatrzeniu</a>.<?php endif; ?></div>
                    <?php endif; ?>
                    <?php if(!empty($row)): ?>

                        <form method="POST" class="mx-auto" style="max-width: 860px">
                            <?php $this->view("supplier/material/_fields", ['row' => $row ?? []]); ?>
                            <div class="d-flex justify-content-between mt-4">
                                <button class="btn btn-warning"><i class="bi bi-check2"></i> Zapisz</button>
                                <a href="<?= ROOT ?>/material-suppliers" role="button" class="btn btn-secondary">Powrót</a>
                            </div>
                        </form>

                    <?php else: ?>
                        <div class="row">
                            <div class="col-4"></div>
                            <div class="col-4">
                                <div class="alert alert-danger text-center">Dostawca nie istnieje nie istnieje!</div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <a href="<?=ROOT?>/material-suppliers" role="button" class="btn btn-secondary">Powrót</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>