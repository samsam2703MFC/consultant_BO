<?php $this->view('page_components/header'); ?>
<?php
$categories = ['PLASTIC' => 'Tworzywa sztuczne', 'ALUMINIUM' => 'Aluminium', 'FERROUS_METAL' => 'Metale żelazne', 'PAPER_CARDBOARD' => 'Papier i tektura', 'GLASS' => 'Szkło', 'WOOD' => 'Drewno', 'MULTIMATERIAL' => 'Wielomateriałowe', 'OTHER' => 'Pozostałe'];
$usageTypes = ['SINGLE_USE' => 'Jednorazowe', 'REUSABLE' => 'Wielokrotne'];
?>
<div class="page-heading">
    <div class="page-title">
        <h3>Raport opakowań BDO</h3>
        <p class="text-subtitle text-muted">Podsumowanie kompletnych pozycji opakowaniowych z wybranego okresu.</p>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body">
                <?php if (!empty($errors)): ?><div class="alert alert-danger"><?= implode('<br>', $errors) ?></div><?php endif; ?>
                <form method="GET" class="row g-3 align-items-end" id="packagingReportForm">
                    <div class="col-md-3">
                        <label class="form-label" for="date_from">Od</label>
                        <input class="form-control" type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($dateFrom) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="date_to">Do</label>
                        <input class="form-control" type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($dateTo) ?>" required>
                    </div>
                    <div class="col-md-6 d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary" type="submit">Pokaż raport</button>
                        <button class="btn btn-outline-secondary period-preset" type="button" data-period="month">Bieżący miesiąc</button>
                        <button class="btn btn-outline-secondary period-preset" type="button" data-period="quarter">Bieżący kwartał</button>
                        <?php if ($report): ?>
                            <a class="btn btn-outline-danger" href="<?= ROOT ?>/audits/packaging-reports/pdf?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>"><i class="bi bi-file-earmark-text me-1"></i>PDF</a>
                            <a class="btn btn-outline-success" href="<?= ROOT ?>/audits/packaging-reports/csv?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>"><i class="bi bi-download me-1"></i>CSV</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($report): ?>
            <div class="row">
                <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Wysyłki w okresie</div><div class="fs-4 fw-bold"><?= (int)$report['coverage']['shipment_count'] ?></div></div></div></div>
                <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Ewidencje kompletne</div><div class="fs-4 fw-bold text-success"><?= (int)$report['coverage']['complete_evidence_count'] ?></div></div></div></div>
                <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Ewidencje z brakami</div><div class="fs-4 fw-bold text-warning"><?= (int)$report['coverage']['requires_data_count'] ?></div></div></div></div>
                <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Bez ewidencji</div><div class="fs-4 fw-bold text-secondary"><?= (int)$report['coverage']['without_evidence_count'] ?></div></div></div></div>
            </div>

            <?php if ((int)$report['coverage']['requires_data_count'] > 0 || (int)$report['coverage']['without_evidence_count'] > 0): ?>
                <div class="alert alert-warning">Podsumowanie mas obejmuje wyłącznie kompletne pozycje. Uzupełnij wysyłki z brakami przed wykorzystaniem raportu do rozliczenia.</div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><h4 class="card-title">Masa opakowań według kategorii BDO</h4></div>
                <div class="card-body p-0"><div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Kategoria BDO</th><th>Rodzaj użycia</th><th class="text-end">Masa [g]</th><th class="text-end">Masa [kg]</th></tr></thead>
                        <tbody>
                            <?php foreach ($report['summary'] as $row): ?>
                                <?php $grams = (float)$row['total_weight_g']; ?>
                                <tr>
                                    <td><?= htmlspecialchars($categories[$row['category']] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($usageTypes[$row['usage_type']] ?? '—') ?></td>
                                    <td class="text-end"><?= number_format($grams, abs($grams - round($grams)) < 0.000001 ? 0 : 2, ',', ' ') ?></td>
                                    <td class="text-end"><?= number_format($grams / 1000, abs(($grams / 1000) - round($grams / 1000)) < 0.000001 ? 0 : 2, ',', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($report['summary'])): ?><tr><td colspan="4" class="text-center text-muted py-4">Brak kompletnych pozycji opakowaniowych w wybranym okresie.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php $this->view('page_components/footer'); ?>
<script>
    document.querySelectorAll('.period-preset').forEach(button => button.addEventListener('click', () => {
        const today = new Date();
        let start;
        let end;
        if (button.dataset.period === 'quarter') {
            const quarterStartMonth = Math.floor(today.getMonth() / 3) * 3;
            start = new Date(today.getFullYear(), quarterStartMonth, 1);
            end = new Date(today.getFullYear(), quarterStartMonth + 3, 0);
        } else {
            start = new Date(today.getFullYear(), today.getMonth(), 1);
            end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        }
        const toInputDate = value => {
            const year = value.getFullYear();
            const month = String(value.getMonth() + 1).padStart(2, '0');
            const day = String(value.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        };
        document.getElementById('date_from').value = toInputDate(start);
        document.getElementById('date_to').value = toInputDate(end);
        document.getElementById('packagingReportForm').submit();
    }));
</script>
