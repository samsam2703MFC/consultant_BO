<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Dodaj sieć franczyzową</h3>
                <p class="text-subtitle text-muted">Utwórz nową sieć franczyzową klientów</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Nowa sieć franczyzowa</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Formularz dodawania</h4>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-danger text-center"><?= implode("<br>", $errors) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <div class="row mb-3">
                        <label for="name" class="col-sm-2 col-form-label">Nazwa *</label>
                        <div class="col-sm-10">
                            <input type="text" name="name" id="name" class="form-control"
                                   value="<?= old_value('name') ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label for="contact_email" class="col-sm-2 col-form-label">Email kontaktowy</label>
                        <div class="col-sm-10">
                            <input type="email" name="contact_email" id="contact_email" class="form-control"
                                   value="<?= old_value('contact_email') ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label for="contact_phone" class="col-sm-2 col-form-label">Telefon kontaktowy</label>
                        <div class="col-sm-10">
                            <input type="text" name="contact_phone" id="contact_phone" class="form-control"
                                   value="<?= old_value('contact_phone') ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <label for="code" class="col-sm-2 col-form-label">Kod *</label>
                        <div class="col-sm-10">
                            <input type="text" name="code" id="code" class="form-control"
                                   value="<?= old_value('code') ?>" readonly required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-12">
                            <button type="submit" class="btn btn-primary">Zapisz</button>
                            <a href="<?= ROOT ?>/client-franchise-networks" class="btn btn-secondary float-end">Anuluj</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

<script>
    // Automatyczne generowanie kodu na podstawie nazwy
    document.getElementById('name').addEventListener('input', function () {
        const name = this.value;
        const code = name
            .toLowerCase()
            .normalize('NFD') // usunięcie znaków diakrytycznych
            .replace(/[\u0300-\u036f]/g, '') // dalej usuwanie akcentów
            .replace(/[^a-z0-9\s-]/g, '') // usunięcie znaków specjalnych
            .trim()
            .replace(/\s+/g, '-'); // zamiana spacji na "-"
        document.getElementById('code').value = code;
    });
</script>

<?php $this->view("page_components/footer"); ?>
