<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Użytkownicy systemu</h3>
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
                    <h4 class="card-title">Edytuj użytkownika</h4>
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
                                <div class="col-2"></div>
                                <div class="col-4">
                                    <div class="form-group row align-items-center">
                                        <div class="col-3">
                                            <label class="col-form-label">Imię</label>
                                        </div>
                                        <div class="col-9">
                                            <input value="<?= old_value('name', $row['name']) ?>" type="text" id="name"
                                                   class="form-control" name="name"
                                                   placeholder="Imię">
                                        </div>
                                    </div>
                                    <div class="form-group row align-items-center">
                                        <div class="col-3">
                                            <label class="col-form-label">Nazwisko</label>
                                        </div>
                                        <div class="col-9">
                                            <input value="<?= old_value('surname', $row['surname']) ?>" type="text"
                                                   id="surname" class="form-control" name="surname"
                                                   placeholder="Nazwisko">
                                        </div>
                                    </div>
                                    <div class="form-group row align-items-center">
                                        <div class="col-3">
                                            <label class="col-form-label">Wyświetlana nazwa</label>
                                        </div>
                                        <div class="col-9">
                                            <input value="<?= old_value('displayed_name', $row['displayed_name']) ?>"
                                                   type="text" id="displayed_name" class="form-control"
                                                   name="displayed_name"
                                                   placeholder="Wyświetlana nazwa">
                                        </div>
                                    </div>
                                    <div class="form-group row align-items-center">
                                        <div class="col-3">
                                            <label class="col-form-label">Email</label>
                                        </div>
                                        <div class="col-9">
                                            <input value="<?= old_value('email', $row['email']) ?>" type="text" id="email"
                                                   class="form-control" name="email"
                                                   placeholder="Email">
                                        </div>
                                    </div>
                                    <div class="form-group row align-items-center">
                                        <div class="col-3">
                                            <label class="col-form-label">Numer kontaktowy</label>
                                        </div>
                                        <div class="col-9">
                                            <input value="<?= old_value('phone', $row['phone']) ?>" type="text" id="phone"
                                                   class="form-control" name="phone"
                                                   placeholder="Numer kontaktowy">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="form-group row align-items-center">
                                        <div class="col-3">
                                            <label class="col-form-label">Uprawnienia</label>
                                        </div>
                                        <div class="col-9">
                                            <select class="form-select" name="permission" id="permission">
                                                <option value="2">T</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group row align-items-center">
                                        <div class="col-3">
                                            <label class="col-form-label">Login</label>
                                        </div>
                                        <div class="col-9">
                                            <input value="<?= old_value('login', $row['login']) ?>" type="text" id="login"
                                                   class="form-control" name="login"
                                                   placeholder="Login">
                                        </div>
                                    </div>
                                    <div class="form-group row align-items-center">
                                        <div class="col-3">
                                            <label class="col-form-label">Hasło</label>
                                        </div>
                                        <div class="col-9">
                                            <input value="<?= old_value('password', $row['password']) ?>" type="text"
                                                   id="password" class="form-control" name="password"
                                                   placeholder="Hasło">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-2"></div>
                                <div class="col-8">
                                    <button class="btn btn-warning">Zapisz</button>
                                    <a href="<?= ROOT ?>/users" role="button" class="btn btn-secondary float-end">Powrót</a>
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
                                <a href="<?= ROOT ?>/users" role="button" class="btn btn-secondary">Powrót</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>