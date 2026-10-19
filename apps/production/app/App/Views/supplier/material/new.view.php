<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Dostawca</h3>
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
                    <h4 class="card-title">Nowy dostawca</h4>
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

                    <form method="POST">
                        <div class="row">
                            <div class="col-2"></div>
                            <div class="col-4">
                                <div class="row">
                                    <label class="form-label" for="name">
                                        Nazwa
                                    </label>
                                    <input value="<?= old_value('name') ?>" type="text" class="form-control" id="name"
                                           name="name" placeholder="Nazwa">
                                </div>
                            </div>
                            <div class="col-1"></div>
                            <div class="col-3">
                                <div class="row">
                                    <label class="form-label" for="phone">
                                        Numer telefonu
                                    </label>
                                    <input value="<?= old_value('phone') ?>" type="text" class="form-control" id="phone"
                                           name="phone" placeholder="Numer telefonu">
                                </div>
                                <div class="row mt-3">
                                    <label class="form-label" for="email">
                                        Email
                                    </label>
                                    <input value="<?= old_value('email') ?>" type="email" class="form-control"
                                           id="email" name="email" placeholder="przykladowy@email.com">
                                </div>
                            </div>

                        </div>
                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <button class="btn btn-success">Utwórz</button>
                                <a href="<?= ROOT ?>/material-suppliers" role="button"
                                   class="btn btn-secondary float-end">Powrót</a>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer") ?>