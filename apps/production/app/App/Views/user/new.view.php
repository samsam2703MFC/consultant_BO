<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Nowy użytkownik</h3>
                </div>
                <div class="col-12 col-md-6 order-md-2 order-first">
                    <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?=ROOT?>/dashboard">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Nowy użytkownik
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    <section class="section">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Tworzenie nowego użytkownika</h4>
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
                            <div class="form-group row align-items-center">
                                <div class="col-3">
                                    <label class="col-form-label">Imię</label>
                                </div>
                                <div class="col-9">
                                    <input value="<?=old_value('name')?>" type="text" id="name" class="form-control" name="name"
                                           placeholder="Imię" required>
                                </div>
                            </div>
                            <div class="form-group row align-items-center">
                                <div class="col-3">
                                    <label class="col-form-label">Nazwisko</label>
                                </div>
                                <div class="col-9">
                                    <input value="<?=old_value('surname')?>" type="text" id="surname" class="form-control" name="surname"
                                           placeholder="Nazwisko" required>
                                </div>
                            </div>
                            <div class="form-group row align-items-center">
                                <div class="col-3">
                                    <label class="col-form-label">Wyświetlana nazwa</label>
                                </div>
                                <div class="col-9">
                                    <input value="<?=old_value('displayed_name')?>" type="text" id="displayed_name" class="form-control" name="displayed_name"
                                           placeholder="Wyświetlana nazwa" required>
                                </div>
                            </div>
                            <div class="form-group row align-items-center">
                                <div class="col-3">
                                    <label class="col-form-label">Email</label>
                                </div>
                                <div class="col-9">
                                    <input value="<?=old_value('email')?>" type="email" id="email" class="form-control" name="email"
                                           placeholder="Email" required>
                                </div>
                            </div>
                            <div class="form-group row align-items-center">
                                <div class="col-3">
                                    <label class="col-form-label">Numer kontaktowy</label>
                                </div>
                                <div class="col-9">
                                    <input value="<?=old_value('phone')?>" type="text" id="phone" class="form-control" name="phone"
                                           placeholder="Numer kontaktowy" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="form-group row align-items-center">
                                <div class="col-3">
                                    <label class="col-form-label">Uprawnienia</label>
                                </div>
                                <div class="col-9">
                                    <select class="form-select" name="permission" id="permission" required>
                                        <option value="2">T</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group row align-items-center">
                                <div class="col-3">
                                    <label class="col-form-label">Login</label>
                                </div>
                                <div class="col-9">
                                    <input  value="<?=old_value('login')?>" type="text" id="login" class="form-control" name="login"
                                            placeholder="Login" required>
                                </div>
                            </div>
                            <div class="form-group row align-items-center">
                                <div class="col-3">
                                    <label class="col-form-label">Hasło</label>
                                </div>
                                <div class="col-9">
                                    <input value="" type="password" autocomplete="new-password" id="password" class="form-control" name="password"
                                           placeholder="Hasło" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-4">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <button class="btn btn-success">Utwórz</button>
                            <a href="<?=ROOT?>/users" role="button" class="btn btn-secondary float-end">Powrót</a>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer")?>