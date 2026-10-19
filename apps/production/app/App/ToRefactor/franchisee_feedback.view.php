<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Zgłoszenia franczyzobiorców</h3>
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

            <?php if ($action == 'response'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Odpowiedź dla franczyzobiorcy</h4>
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
                                <div class="col-8">
                                    <div class="row">
                                        <div class="col-4">
                                            <label>Franczyzobiorca</label>
                                        </div>
                                        <div class="col-8 form-group">
                                            <div class="form-control"><?= $order_note->franchisee_name ?></div>
                                        </div>
                                        <div class="col-4">
                                            <label>Produkt</label>
                                        </div>
                                        <div class="col-8 form-group">
                                            <div class="form-control"><?= $order_note->product_name ?></div>
                                        </div>
                                        <div class="col-4">
                                            <label>Temat</label>
                                        </div>
                                        <div class="col-8 form-group">
                                            <div class="form-control"><?= $order_note->subject ?></div>
                                        </div>
                                        <div class="col-4">
                                            <label>Opis</label>
                                        </div>
                                        <div class="col-8 form-group">
                                            <div class="form-control"><?= $order_note->content ?></div>
                                        </div>
                                    </div>
                                    <div class="row mt-5">
                                        <div class="col-2"></div>
                                        <div class="col-8">
                                            <label for="response" class="form-label">
                                                Odpowiedź
                                            </label>
                                            <textarea class="form-control" name="response"
                                                      id="response"><?= $order_note->response ?></textarea>
                                        </div>
                                    </div>
                                    <div class="row mt-5">
                                        <div class="col-1"></div>
                                        <div class="col-5">
                                            <button class="btn btn-success">Prześlij odpowiedź</button>
                                        </div>
                                        <div class="col-5">
                                            <a href="<?= ROOT ?>/Franchisee/feedback/progress" role="button"
                                               class="btn btn-secondary float-end">Powrót</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action == 'closed_response'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Odpowiedź dla franczyzobiorcy</h4>
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
                                <div class="col-8">
                                    <div class="row">
                                        <div class="col-4">
                                            <label>Franczyzobiorca</label>
                                        </div>
                                        <div class="col-8 form-group">
                                            <div class="form-control"><?= $order_note->franchisee_name ?></div>
                                        </div>
                                        <div class="col-4">
                                            <label>Produkt</label>
                                        </div>
                                        <div class="col-8 form-group">
                                            <div class="form-control"><?= $order_note->product_name ?></div>
                                        </div>
                                        <div class="col-4">
                                            <label>Temat</label>
                                        </div>
                                        <div class="col-8 form-group">
                                            <div class="form-control"><?= $order_note->subject ?></div>
                                        </div>
                                        <div class="col-4">
                                            <label>Opis</label>
                                        </div>
                                        <div class="col-8 form-group">
                                            <div class="form-control"><?= $order_note->content ?></div>
                                        </div>
                                    </div>
                                    <div class="row mt-5">
                                        <div class="col-2"></div>
                                        <div class="col-8">
                                            <label for="response" class="form-label">
                                                Odpowiedź
                                            </label>
                                            <textarea class="form-control" name="response"
                                                      id="response"><?= $order_note->response ?></textarea>
                                        </div>
                                    </div>
                                    <div class="row mt-5">
                                        <div class="col-1"></div>
                                        <div class="col-5">
                                            <button class="btn btn-success">Prześlij odpowiedź</button>
                                        </div>
                                        <div class="col-5">
                                            <a href="<?= ROOT ?>/Franchisee/feedback/close" role="button"
                                               class="btn btn-secondary float-end">Powrót</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action == 'edit'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Edycja komentarza</h4>
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
                                <div class="col-8">
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <label for="response">
                                                Komentarz do reklamacji ze strony klienta (BE)
                                            </label>
                                            <textarea class="form-control"
                                                      id="response"><?= $order_note->content ?></textarea>
                                        </div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <label for="content_pl">
                                                Przetłumaczona odpowiedź (PL)
                                            </label>
                                            <textarea class="form-control" name="content_pl"
                                                      id="content_pl"><?= $order_note->content_pl ?></textarea>
                                        </div>
                                    </div>

                                    <div class="row mt-5">
                                        <div class="col-1"></div>
                                        <div class="col-5">
                                            <button class="btn btn-success">Zapisz</button>
                                        </div>
                                        <div class="col-5">
                                            <a href="<?= ROOT ?>/Franchisee/feedback/new" role="button"
                                               class="btn btn-secondary float-end">Powrót</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action == 'new'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Uwagi klientów</h4>
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
                        <div class="row mt-4">
                            <div class="col-4">
                                <label for="status-select" class="form-label">
                                    Filtruj po statusie
                                </label>
                                <select class="form-select" id="status-select" onchange="showItems(this.value)">
                                    <option value="-1">Wybierz..</option>
                                    <option value="new">Nowy</option>
                                    <option value="progress">W trakcie</option>
                                    <option value="close">Zamknięte</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <?php if (!empty($status)): ?>
                                    Status: <h4><?= $status->status_name ?></h4>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <table class="table table-striped text-sm">
                                    <thead>
                                    <th style="width: 10%">Franczyzobiorca</th>
                                    <th style="width: 15%">Temat</th>
                                    <th style="width: 20%">Produkt</th>
                                    <th style="width: 20%">Ilość</th>
                                    <th style="width: 15%">Komentarz</th>
                                    <th style="width: 10%">Data wpłynięcia</th>
                                    <th style="width: 10%">Zdjęcie</th>
                                    <th style="width: 10%">Zdjęcie</th>
                                    <th style="width: 10%"></th>
                                    <th style="width: 10%"></th>
                                    </thead>
                                    <tbody>
                                    <?php if (!empty($order_notes)): ?>
                                        <?php foreach ($order_notes as $order_note): ?>
                                            <tr>
                                                <td><?= $order_note->franchisee_name ?></td>
                                                <td><?= $order_note->subject ?></td>
                                                <td><?= $order_note->product_name ?></td>
                                                <td><?= $order_note->product_quantity ?></td>
                                                <td><?= $order_note->content ?></td>
                                                <td><?= $order_note->incident_date ?></td>
                                                <?php if ($order_note->file_path == null): ?>
                                                    <td></td>
                                                <?php else: ?>
                                                    <td>
                                                        <a href="<?= ATELIER_RESOURCES_PREFIXE ?><?= $order_note->file_path ?>"
                                                           role="button" class="btn btn-info"
                                                           target="_blank">Zdjęcie</a></td>
                                                <?php endif; ?>
                                                <?php if ($order_note->file_path_2 == null): ?>
                                                    <td></td>
                                                <?php else: ?>
                                                    <td>
                                                        <a href="<?= ATELIER_RESOURCES_PREFIXE ?><?= $order_note->file_path_2 ?>"
                                                           role="button" class="btn btn-info"
                                                           target="_blank">Zdjęcie</a></td>
                                                <?php endif; ?>
                                                <td>
                                                    <button class="btn btn-secondary" type="button"
                                                            onclick="updateStatus(<?= $order_note->id ?>, 2, this)">
                                                        Przyjmij do realizacji
                                                    </button>
                                                </td>
                                                <td>
                                                    <button class="btn btn-danger" type="button"
                                                            onclick="updateStatus(<?= $order_note->id ?>, -1, this)">
                                                        Odrzuć
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($action == 'progress'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Uwagi klientów</h4>
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
                        <div class="row mt-4">
                            <div class="col-4">
                                <label for="status-select" class="form-label">
                                    Filtruj po statusie
                                </label>
                                <select class="form-select" id="status-select" onchange="showItems(this.value)">
                                    <option value="-1">Wybierz..</option>
                                    <option value="new">Nowy</option>
                                    <option value="progress">W trakcie</option>
                                    <option value="close">Zamknięte</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <?php if (!empty($status)): ?>
                                    Status: <h4><?= $status->status_name ?></h4>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <table class="table table-striped text-sm">
                                    <thead>
                                    <th style="width: 10%">Franczyzobiorca</th>
                                    <th style="width: 10%">Temat</th>
                                    <th style="width: 15%">Produkt</th>
                                    <th style="width: 15%">Ilość</th>
                                    <th style="width: 20%">Komentarz</th>
                                    <th style="width: 10%">Komentarz PL</th>
                                    <th style="width: 10%"></th>
                                    <th style="width: 10%">Zdjęcie</th>
                                    <th style="width: 10%">Zdjęcie</th>
                                    <th style="width: 10%"></th>
                                    <th style="width: 10%"></th>
                                    </thead>
                                    <tbody>
                                    <?php if (!empty($order_notes)): ?>
                                        <?php foreach ($order_notes as $order_note): ?>
                                            <tr>
                                                <td><?= $order_note->franchisee_name ?></td>
                                                <td><?= $order_note->subject ?></td>
                                                <td><?= $order_note->product_name ?></td>
                                                <td><?= $order_note->product_quantity ?></td>
                                                <td><?= $order_note->content ?></td>
                                                <?php if ($order_note->content_pl == ""): ?>
                                                    <td colspan="2"><a
                                                                href="<?= ROOT ?>/Franchisee/feedback/edit/<?= $order_note->id ?>"
                                                                class="btn btn-success">Dodaj</a></td>
                                                <?php else: ?>
                                                    <td><?= $order_note->content_pl ?></td>
                                                    <td>
                                                        <a href="<?= ROOT ?>/Franchisee/feedback/edit/<?= $order_note->id ?>"
                                                           class="btn btn-warning">Edytuj</a></td>
                                                <?php endif; ?>
                                                <?php if ($order_note->file_path == null): ?>
                                                    <td></td>
                                                <?php else: ?>
                                                    <td>
                                                        <a href="<?= ATELIER_RESOURCES_PREFIXE ?><?= $order_note->file_path ?>"
                                                           role="button" class="btn btn-info"
                                                           target="_blank">Zdjęcie</a></td>
                                                <?php endif; ?>
                                                <?php if ($order_note->file_path_2 == null): ?>
                                                    <td></td>
                                                <?php else: ?>
                                                    <td>
                                                        <a href="<?= ATELIER_RESOURCES_PREFIXE ?><?= $order_note->file_path_2 ?>"
                                                           role="button" class="btn btn-info"
                                                           target="_blank">Zdjęcie</a></td>
                                                <?php endif; ?>
                                                <td>
                                                    <a href="<?= ROOT ?>/Franchisee/feedback/response/<?= $order_note->id ?>"
                                                       role="button" class="btn btn-outline-primary">Odpowiedz</a></td>
                                                <td><a href="<?= ROOT ?>/Franchisee/feedback/pdf/<?= $order_note->id ?>"
                                                       role="button" class="btn btn-info">PDF</a></td>
                                                <td>
                                                    <a href="<?= ROOT ?>/Complaint/complaints/new_from_note/<?= $order_note->id ?>"
                                                       role="button" class="btn btn-secondary">Uznaj reklamację</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($action == 'close'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Uwagi klientów</h4>
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
                        <div class="row mt-4">
                            <div class="col-4">
                                <label for="status-select" class="form-label">
                                    Filtruj po statusie
                                </label>
                                <select class="form-select" id="status-select" onchange="showItems(this.value)">
                                    <option value="-1">Wybierz..</option>
                                    <option value="new">Nowy</option>
                                    <option value="progress">W trakcie</option>
                                    <option value="close">Zamknięte</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <?php if (!empty($status)): ?>
                                    Status: <h4><?= $status->status_name ?></h4>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <table class="table table-striped text-sm" id="notes_table">
                                    <colgroup>
                                        <col style="width:10%;">
                                        <col style="width:10%;">
                                        <col style="width:10%;">
                                        <col style="width:15%;">
                                        <col style="width:10%;">
                                        <col style="width:15%;">
                                        <col style="width:5%;">
                                        <col style="width:20%;">
                                        <col style="width:5%;">
                                    </colgroup>
                                    <thead>
                                    <tr>
                                        <td>Franczyzobiorca</td>
                                        <td>Temat</td>
                                        <td>Produkt</td>
                                        <td>Komentarz</td>
                                        <td>Komentarz PL</td>
                                        <td>Data wpłynięcia</td>
                                        <td>Zdjęcie</td>
                                        <td>Odpowiedź</td>
                                        <td></td>
                                    </tr>

                                    </thead>
                                    <tbody>
                                    <?php if (!empty($order_notes)): ?>
                                        <?php foreach ($order_notes as $order_note): ?>
                                            <tr>
                                                <td><?= $order_note->franchisee_name ?></td>
                                                <td><?= $order_note->subject ?></td>
                                                <td><?= $order_note->product_name ?></td>
                                                <td><?= $order_note->content ?></td>
                                                <?php if ($order_note->content_pl == ""): ?>
                                                    <td><a
                                                                href="<?= ROOT ?>/Franchisee/feedback/edit/<?= $order_note->id ?>"
                                                                class="btn btn-success btn-sm">Dodaj</a></td>
                                                <?php else: ?>
                                                    <td>
                                                        <?= $order_note->content_pl ?>
                                                        <a href="<?= ROOT ?>/Franchisee/feedback/edit/<?= $order_note->id ?>"
                                                           class="btn btn-outline-warning mt-3 btn-sm">Edytuj</a>
                                                    </td>
                                                <?php endif; ?>

                                                <td><?= $order_note->incident_date ?></td>
                                                <td>
                                                    <?php if ($order_note->file_path == null): ?>
                                                    <?php else: ?>
                                                        <a href="<?= ATELIER_RESOURCES_PREFIXE ?><?= $order_note->file_path ?>"
                                                           role="button" class="btn btn-info" target="_blank">
                                                            <i class="icon dripicons-photo"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($order_note->file_path_2 == null): ?>
                                                    <?php else: ?>
                                                        <a href="<?= ATELIER_RESOURCES_PREFIXE ?><?= $order_note->file_path_2 ?>"
                                                           role="button" class="btn btn-info mt-4" target="_blank">
                                                            <i class="icon dripicons-photo"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= $order_note->response ?> <br/>
                                                    <a href="<?= ROOT ?>/Franchisee/feedback/closed_response/<?= $order_note->id ?>"
                                                       role="button"
                                                       class="btn btn-outline-primary mt-4 btn-sm">Edytuj</a>
                                                </td>
                                                <td><a href="<?= ROOT ?>/Franchisee/feedback/pdf/<?= $order_note->id ?>"
                                                       role="button" class="btn btn-info btn-sm">PDF</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>


                <script src="<?= ROOT ?>/assets/js/bootstrap.bundle.min.js"></script>
                <script src="<?= ROOT ?>/assets/vendors/simple-datatables/simple-datatables.js"></script>

                <script type="text/javascript">
                    let notes_table = document.querySelector('#notes_table');
                    let dataTable = new simpleDatatables.DataTable(notes_table, {
                        perPage: 10,
                        perPageSelect: false,
                        searchable: true,
                        sortable: true,
                        labels: {
                            placeholder: "Szukaj po nazwie",
                            noRows: "Nie znaleziono danych",
                            info: "Wyświetlane {start} - {end} z {rows} wprowadzonych",
                        },
                    });
                </script>

            <?php else: ?>

            <?php endif; ?>
        </section>
    </div>


    <script>


        function updateStatus(id_note, id_status, btn) {

            const data = {
                id: id_note,
                id_order_note_status: id_status
            };

            const promise = getJSON(validAjaxUrl() + `ajax/note_status/update`, "data=" + JSON.stringify(data));
            promise.then(function (res) {
                showSnack(res);
                btn.parentNode.parentNode.remove();
            })
        }

        function showItems(status) {
            if (status == -1) return;

            window.location.href = validAjaxUrl() + `feedback/${status}`;

        }

    </script>

<?php $this->view("page_components/footer"); ?>