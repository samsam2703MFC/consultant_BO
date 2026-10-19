<?php $this->view("page_components/header"); ?>

<style>
    #clients-table { font-size: .8rem; }
    #clients-table thead th {
        font-size: .7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #6c757d;
        padding: .4rem .6rem;
        white-space: nowrap;
        border-bottom: 1px solid #dee2e6;
        background: #f8f9fa;
    }
    #clients-table tbody td {
        padding: .35rem .6rem;
        vertical-align: middle;
    }
    #clients-table tbody tr:hover { background: #f5f8ff; }
    #clients-table .col-fixed { width: 1%; white-space: nowrap; }
    .client-company { font-weight: 500; color: #212529; }
    .client-meta    { font-size: .75rem; color: #868e96; }
    .franchise-row  { border-left: 3px solid #0dcaf0; }
</style>

<div class="page-heading">
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3 class="mb-0">Klienci</h3>
                <p class="text-subtitle text-muted mb-0">Zarządzanie bazą klientów</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Klienci</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">

            <div class="card-header d-flex flex-wrap align-items-center gap-2 justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-people fs-5 text-primary"></i>
                    <h4 class="card-title mb-0">Lista klientów</h4>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <select class="form-select form-select-sm" id="select_option"
                            onchange="displaySelected(this.value)" style="width: auto;">
                        <option value="-1" <?= !isset($_GET['type']) ? 'selected' : '' ?>>Wszyscy</option>
                        <option value="active"     <?= ($_GET['type'] ?? '') === 'active'     ? 'selected' : '' ?>>Aktywni</option>
                        <option value="inactive"   <?= ($_GET['type'] ?? '') === 'inactive'   ? 'selected' : '' ?>>Nieaktywni</option>
                        <option value="franchisee" <?= ($_GET['type'] ?? '') === 'franchisee' ? 'selected' : '' ?>>Franczyzobiorcy</option>
                    </select>
                    <a href="<?= ROOT ?>/clients/new" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus me-1"></i>Nowy klient
                    </a>
                </div>
            </div>

            <div class="card-body px-0 py-0">
                <table class="table table-sm table-hover mb-0" id="clients-table">
                    <thead>
                        <tr>
                            <th>Firma</th>
                            <th>Imię i nazwisko</th>
                            <th>Email</th>
                            <th>Telefon</th>
                            <th class="col-fixed text-center">Aktywny</th>
                            <th class="col-fixed text-end pe-3">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $isFranchise = !is_null($row['franchise_network_id']);
                                $isActive    = $row['active'] == '1';
                            ?>
                            <tr class="<?= $isFranchise ? 'franchise-row' : '' ?>">

                                <td>
                                    <div class="client-company">
                                        <?php if ($isFranchise): ?>
                                            <i class="bi bi-diagram-3 text-info me-1" title="Franczyzobiorca"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($row['company']) ?>
                                    </div>
                                    <?php if (!$isActive): ?>
                                        <span class="badge bg-secondary mt-1" style="font-size:.62rem;">nieaktywny</span>
                                    <?php endif; ?>
                                </td>

                                <td class="client-meta">
                                    <?= htmlspecialchars(trim($row['name'] . ' ' . $row['surname'])) ?: '<span class="opacity-50">—</span>' ?>
                                </td>

                                <td class="client-meta">
                                    <?php if (!empty($row['email_head'])): ?>
                                        <a href="mailto:<?= htmlspecialchars($row['email_head']) ?>"
                                           class="text-muted text-decoration-none">
                                            <?= htmlspecialchars($row['email_head']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="opacity-50">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="client-meta">
                                    <?= htmlspecialchars($row['phone_head']) ?: '<span class="opacity-50">—</span>' ?>
                                </td>

                                <td class="col-fixed text-center">
                                    <div class="form-switch d-flex justify-content-center">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               name="active"
                                               onchange="update(<?= $row['id'] ?>, this);"
                                               value="<?= $row['active'] ?>"
                                               <?= $isActive ? 'checked' : '' ?>>
                                    </div>
                                </td>

                                <td class="col-fixed text-end pe-3">
                                    <div class="d-flex gap-1 justify-content-end flex-nowrap">
                                        <a href="<?= ROOT ?>/clients/<?= $row['id'] ?>/edit"
                                           class="btn btn-outline-warning btn-sm py-0 px-2"
                                           title="Edytuj">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button"
                                                class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                onclick="getPriceListByClient(<?= $row['id'] ?>)"
                                                title="Cennik PDF">
                                            <i class="bi bi-file-earmark-text"></i>
                                        </button>
                                        <a href="<?= ROOT ?>/clients/<?= $row['id'] ?>/profitability"
                                           class="btn btn-outline-primary btn-sm py-0 px-2"
                                           title="Rentowność">
                                            <i class="bi bi-graph-up"></i>
                                        </a>
                                        <a href="<?= ROOT ?>/clients/<?= $row['id'] ?>/sales-summary"
                                           class="btn btn-outline-success btn-sm py-0 px-2"
                                           title="Sprzedaż WDT">
                                            <i class="bi bi-box-seam"></i>
                                        </a>
                                        <?php if (!empty($row['franchisee_uid'])): ?>
                                            <a href="<?= ROOT ?>/clients/<?= $row['id'] ?>/franchise-product-snapshot"
                                               class="btn btn-outline-info btn-sm py-0 px-2"
                                               title="Diagnostyka produktów franczyzowych">
                                                <i class="bi bi-clipboard2-pulse"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= ROOT ?>/clients/<?= $row['id'] ?>/delete"
                                           class="btn btn-outline-danger btn-sm py-0 px-2"
                                           title="Usuń">
                                            <i class="bi bi-trash2"></i>
                                        </a>
                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-people fs-2 d-block mb-2 opacity-25"></i>
                                Brak klientów do wyświetlenia
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!empty($rows)): ?>
                    <div class="px-3 py-2 border-top d-flex justify-content-end">
                        <span class="text-muted" style="font-size: .75rem;">
                            Łącznie: <strong><?= count($rows) ?></strong> klientów
                        </span>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>
    function getPriceListByClient(id) {
        downloadPDF(`/ajax/clients/${id}/price-list`);
    }

    function displaySelected(type) {
        const url = new URL(window.location.href);
        if (type === '-1') {
            url.searchParams.delete('type');
        } else {
            url.searchParams.set('type', type);
        }
        window.location.href = url.toString();
    }

    function update(id, control) {
        const data = { id };
        data[control.name] = Number(control.checked);
        api(`/ajax/clients/${id}`, { method: 'PATCH', data })
            .then(data => showSnack(data.ok, data.message))
            .catch(error => console.error('Error:', error));
    }

</script>
