<?php $this->view('page_components/header'); ?>
<?php $labels = ['PLASTIC' => 'Tworzywo sztuczne', 'PAPER_CARDBOARD' => 'Papier i tektura', 'GLASS' => 'Szkło', 'ALUMINIUM' => 'Aluminium', 'FERROUS_METAL' => 'Metale żelazne', 'WOOD' => 'Drewno', 'MULTIMATERIAL' => 'Wielomateriałowe', 'OTHER' => 'Pozostałe', 'UNKNOWN' => 'Brak snapshotu PPWR']; ?>
<div class="page-heading">
    <div class="page-title">
        <h3>Raport opakowań PPWR</h3>
        <p class="text-subtitle text-muted">Agregacja historycznych snapshotów PPWR zapisanych przy wysyłkach.</p>
    </div>
    <section class="section">
        <div class="card"><div class="card-body">
            <?php if (!empty($errors)): ?><div class="alert alert-danger"><?= htmlspecialchars(implode(' ', $errors)) ?></div><?php endif; ?>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label" for="date_from">Od</label><input class="form-control" type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($dateFrom) ?>" required></div>
                <div class="col-md-3"><label class="form-label" for="date_to">Do</label><input class="form-control" type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($dateTo) ?>" required></div>
                <div class="col-md-6 d-flex gap-2"><button class="btn btn-primary" type="submit">Pokaż raport</button><?php if ($report): ?><a class="btn btn-outline-danger" href="<?= ROOT ?>/ppwr/reports/pdf?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Pobierz PDF</a><?php endif; ?></div>
            </form>
        </div></div>

        <?php if ($report): ?>
            <?php $coverage = $report['coverage']; ?>
            <div class="row">
                <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Wysyłki</div><div class="fs-4 fw-bold"><?= (int)$coverage['shipment_count'] ?></div></div></div></div>
                <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Bez ewidencji</div><div class="fs-4 fw-bold text-warning"><?= (int)$coverage['without_evidence_count'] ?></div></div></div></div>
                <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Braki BDO</div><div class="fs-4 fw-bold text-warning"><?= (int)$coverage['requires_data_count'] ?></div></div></div></div>
                <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Pozycje bez PPWR</div><div class="fs-4 fw-bold text-warning"><?= (int)$coverage['missing_ppwr_snapshot_count'] ?></div></div></div></div>
            </div>
            <?php if ((int)$coverage['without_evidence_count'] || (int)$coverage['requires_data_count'] || (int)$coverage['missing_ppwr_snapshot_count'] || (int)$coverage['current_ppwr_reconstruction_count']): ?><div class="alert alert-warning">Raport obejmuje wyłącznie kompletne pozycje BDO. Wiersz „Brak snapshotu PPWR” oznacza dane historyczne lub nieuzupełniony profil w dniu wysyłki. Raporty odtworzone później: <?= (int)$coverage['current_ppwr_reconstruction_count'] ?>.</div><?php endif; ?>
            <div class="card"><div class="card-header"><h4 class="card-title">Podsumowanie ogólne</h4></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Materiał dominujący PPWR</th><th class="text-end">Masa [g]</th><th class="text-end">Masa [kg]</th></tr></thead><tbody>
                <?php foreach ($report['summary'] as $row): $grams = (float)$row['total_weight_g']; ?><tr><td><?= htmlspecialchars($labels[$row['material']] ?? $row['material']) ?></td><td class="text-end"><?= number_format($grams, 2, ',', ' ') ?></td><td class="text-end"><?= number_format($grams / 1000, 2, ',', ' ') ?></td></tr><?php endforeach; ?>
                <?php if (empty($report['summary'])): ?><tr><td colspan="3" class="text-center text-muted py-4">Brak kompletnych danych w wybranym okresie.</td></tr><?php endif; ?>
            </tbody></table></div></div></div>
        <?php endif; ?>
    </section>
</div>
<?php $this->view('page_components/footer'); ?>
