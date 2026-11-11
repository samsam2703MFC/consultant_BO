<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Edytuj sieć franczyzową</h3>
                <p class="text-subtitle text-muted">Zmodyfikuj dane istniejącej sieci franczyzowej</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/client-franchise-networks">Sieci franczyzowe</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edycja</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Formularz edycji</h4>
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
                            <div class="col-8">
                                <div class="row mb-3">
                                    <label class="form-label" for="name">Nazwa *</label>
                                    <input value="<?= old_value('name', $row['name']) ?>" type="text" class="form-control" id="name" name="name" required>
                                </div>

                                <div class="row mb-3">
                                    <label class="form-label" for="contact_email">Email kontaktowy</label>
                                    <input value="<?= old_value('contact_email', $row['contact_email'] ?? '') ?>" type="email" class="form-control" id="contact_email" name="contact_email">
                                </div>

                                <div class="row mb-3">
                                    <label class="form-label" for="contact_phone">Telefon kontaktowy</label>
                                    <input value="<?= old_value('contact_phone', $row['contact_phone'] ?? '') ?>" type="text" class="form-control" id="contact_phone" name="contact_phone">
                                </div>

                                <div class="row mb-3">
                                    <label class="form-label" for="code">Kod *</label>
                                    <input value="<?= old_value('code', $row['code']) ?>" type="text" class="form-control" id="code" name="code" required readonly>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <button type="submit" class="btn btn-primary">Zapisz</button>
                                <a href="<?= ROOT ?>/client-franchise-networks" class="btn btn-secondary float-end">Anuluj</a>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="row">
                        <div class="col-4"></div>
                        <div class="col-4">
                            <div class="alert alert-danger text-center">Sieć nie istnieje!</div>
                        </div>
                    </div>
                    <div class="row mt-4">
                        <div class="col-4"></div>
                        <div class="col-4 text-center">
                            <a href="<?= ROOT ?>/client-franchise-networks" role="button" class="btn btn-secondary">Powrót</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<script>
    // Generuj kod automatycznie na podstawie nazwy (można edytować ręcznie po wygenerowaniu)
    document.getElementById('name').addEventListener('input', function () {
        const name = this.value;
        const code = name
            .toLowerCase()
            .normalize('NFD') // usunięcie znaków diakrytycznych
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/\s+/g, '-');

        document.getElementById('code').value = code;
    });
</script>

<?php $this->view("page_components/footer"); ?>
