<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-7 order-md-1 order-last">
                <h3>Podgląd dostawy</h3>
                <p class="text-subtitle text-muted">
                    Dokument zakupu <strong>#<?= (int)($doc['id'] ?? 0) ?></strong>
                </p>
            </div>
            <div class="col-12 col-md-5 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/purchases">Zakupy</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Podgląd</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <?php
    $status = (string)($doc['status'] ?? '');
    $badge = 'bg-light-secondary';
    if ($status === 'POSTED')   $badge = 'bg-light-success';
    if ($status === 'DRAFT')    $badge = 'bg-light-warning';
    if ($status === 'CANCELED') $badge = 'bg-light-danger';

    $currency = (string)($doc['currency_code'] ?? 'PLN');

    $sumNet = 0.0;
    $sumVat = 0.0;

    foreach (($items ?? []) as $it) {
        $lineNet = (float)($it['line_total_net'] ?? 0);
        $sumNet += $lineNet;

        $vatRate = $it['vat_rate'] !== null && $it['vat_rate'] !== '' ? (float)$it['vat_rate'] : null;
        if ($vatRate !== null) {
            $sumVat += $lineNet * ($vatRate / 100.0);
        }
    }

    $sumGross = $sumNet + $sumVat;

    function nf($n, $dec = 2) {
        return number_format((float)$n, $dec, ',', ' ');
    }
    ?>

    <section class="section">

        <!-- HEADER: dane dokumentu -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Dane dokumentu</h4>

                <div class="d-flex gap-2">
                    <a href="<?= ROOT ?>/purchases" class="btn btn-outline-secondary btn-sm">← Wróć</a>

                    <?php if (($doc['status'] ?? '') !== 'POSTED'): ?>
                        <a href="<?= ROOT ?>/purchases/<?= (int)$doc['id'] ?>/edit" class="btn btn-warning btn-sm">
                            <i class="icon dripicons-document-edit"></i> Edytuj
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-12 col-lg-8">
                        <div class="border rounded p-3 h-100">
                            <div class="row g-2">
                                <div class="col-12 col-md-6">
                                    <div class="text-muted text-sm">Dostawca</div>
                                    <div class="fw-bold"><?= htmlspecialchars($doc['supplier_name'] ?? '-') ?></div>
                                </div>

                                <div class="col-6 col-md-3">
                                    <div class="text-muted text-sm">Data</div>
                                    <div class="fw-bold"><?= htmlspecialchars($doc['doc_date'] ?? '-') ?></div>
                                </div>

                                <div class="col-6 col-md-3">
                                    <div class="text-muted text-sm">Status</div>
                                    <div>
                                        <span class="badge <?= $badge ?>">
                                            <?= htmlspecialchars($status ?: '-') ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <div class="text-muted text-sm">Nr dokumentu</div>
                                    <div class="fw-bold"><?= htmlspecialchars($doc['doc_no'] ?: '-') ?></div>
                                </div>

                                <div class="col-6 col-md-3">
                                    <div class="text-muted text-sm">Waluta</div>
                                    <div class="fw-bold"><?= htmlspecialchars($currency) ?></div>
                                </div>

                                <div class="col-6 col-md-3">
                                    <div class="text-muted text-sm">Utworzono</div>
                                    <div class="fw-bold"><?= htmlspecialchars($doc['created_at'] ?? '-') ?></div>
                                </div>

                                <?php if (!empty($doc['note'])): ?>
                                    <div class="col-12">
                                        <div class="text-muted text-sm">Notatka</div>
                                        <div><?= nl2br(htmlspecialchars($doc['note'])) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-4">
                        <div class="border rounded p-3 h-100 bg-light">
                            <div class="d-flex justify-content-between">
                                <div class="text-muted">Pozycji</div>
                                <div class="fw-bold"><?= (int)count($items ?? []) ?></div>
                            </div>
                            <hr class="my-2">

                            <div class="d-flex justify-content-between">
                                <div class="text-muted">Suma netto</div>
                                <div class="fw-bold"><?= nf($sumNet, 2) ?> <?= htmlspecialchars($currency) ?></div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <div class="text-muted">VAT</div>
                                <div class="fw-bold"><?= nf($sumVat, 2) ?> <?= htmlspecialchars($currency) ?></div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <div class="text-muted">Suma brutto</div>
                                <div class="fw-bold"><?= nf($sumGross, 2) ?> <?= htmlspecialchars($currency) ?></div>
                            </div>

                            <div class="form-text mt-2">
                                VAT liczony z linii, jeśli vat_rate jest ustawione na pozycji.
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>


        <!-- ITEMS: tabela pozycji -->
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">Pozycje</h4>
            </div>

            <div class="card-body">

                <?php if (empty($items)): ?>
                    <div class="alert alert-light text-center text-muted mb-0">
                        Brak pozycji w dokumencie.
                    </div>
                <?php else: ?>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle text-sm">
                            <thead>
                            <tr>
                                <th style="width: 34%">Surowiec</th>
                                <th style="width: 16%">Ilość</th>
                                <th style="width: 18%">Cena</th>
                                <th style="width: 10%">VAT</th>
                                <th style="width: 12%">Netto</th>
                                <th style="width: 10%">Brutto</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $it): ?>
                                <?php
                                $qtyBase = (float)($it['qty_base'] ?? 0);
                                $unitPrice = (float)($it['unit_price_net'] ?? 0);
                                $lineNet = (float)($it['line_total_net'] ?? 0);

                                $vatRate = ($it['vat_rate'] !== null && $it['vat_rate'] !== '') ? (float)$it['vat_rate'] : null;
                                $lineVat = $vatRate !== null ? $lineNet * ($vatRate / 100.0) : 0.0;
                                $lineGross = $lineNet + $lineVat;

                                $packSize = ($it['package_size_base'] !== null && $it['package_size_base'] !== '') ? (float)$it['package_size_base'] : null;
                                $packPrice = ($it['package_price_net'] !== null && $it['package_price_net'] !== '') ? (float)$it['package_price_net'] : null;

                                // jeśli mamy dane opakowania, pokaż w UI jako PACK
                                $isPack = ($packSize !== null && $packSize > 0 && $packPrice !== null && $packPrice > 0);

                                $qtyPacks = $isPack ? ($qtyBase / $packSize) : null;
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($it['raw_material_name'] ?? '-') ?></div>
                                        <div class="text-muted" style="font-size: 12px;">
                                            ID: <?= (int)($it['raw_material_id'] ?? 0) ?>
                                            <?php if (!empty($it['unit_name'])): ?>
                                                • jednostka: <?= htmlspecialchars($it['unit_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?php if ($isPack): ?>
                                            <div class="fw-bold"><?= nf($qtyPacks, 0) ?> opak.</div>
                                            <div class="text-muted" style="font-size: 12px;">
                                                <?= nf($qtyBase, 3) ?> baz.
                                                <?php if (!empty($it['unit_name'])): ?> (<?= htmlspecialchars($it['unit_name']) ?>)<?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="fw-bold"><?= nf($qtyBase, 3) ?></div>
                                            <div class="text-muted" style="font-size: 12px;">
                                                baz.
                                                <?php if (!empty($it['unit_name'])): ?> (<?= htmlspecialchars($it['unit_name']) ?>)<?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($isPack): ?>
                                            <div><?= nf($packPrice, 2) ?> / opak.</div>
                                            <div class="text-muted" style="font-size: 12px;">
                                                <?= nf($unitPrice, 6) ?> / baz.
                                            </div>
                                            <div class="text-muted" style="font-size: 12px;">
                                                rozmiar: <?= nf($packSize, 3) ?> baz.
                                            </div>
                                        <?php else: ?>
                                            <div><?= nf($unitPrice, 6) ?> / baz.</div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= $vatRate !== null ? nf($vatRate, 2) . '%' : '—' ?>
                                    </td>

                                    <td class="fw-bold"><?= nf($lineNet, 2) ?></td>
                                    <td class="fw-bold"><?= nf($lineGross, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>

                            <tfoot>
                            <tr class="bg-light">
                                <td colspan="4" class="text-end"><strong>Razem:</strong></td>
                                <td><strong><?= nf($sumNet, 2) ?> <?= htmlspecialchars($currency) ?></strong></td>
                                <td><strong><?= nf($sumGross, 2) ?> <?= htmlspecialchars($currency) ?></strong></td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>

                <?php endif; ?>

            </div>
        </div>

    </section>
</div>

<?php $this->view("page_components/footer"); ?>
