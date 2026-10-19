<?php $this->view('page_components/header'); ?>

<div class="page-heading">
    <h3>Przypomnienia o płatności</h3>
    <p class="text-subtitle text-muted">Wybierz źródło, następnie przygotuj, sprawdź i ręcznie zatwierdź wiadomość do wysyłki.</p>
</div>

<section class="section">
    <div class="card mb-4"><div class="card-body"><div class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label" for="difference-id">Dopłata z dokumentu różnic</label><select id="difference-id" class="form-select"><option value="">Wybierz dokument...</option><?php foreach (($differences ?? []) as $difference): ?><option value="<?= (int)$difference['id'] ?>"><?= htmlspecialchars($difference['label'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="message-type">Typ przypomnienia</label><select id="message-type" class="form-select"><option value="payment_reminder">Zwykłe</option><option value="payment_reminder_final">Stanowcze</option></select></div>
        <div class="col-md-3"><label class="form-label" for="language-code">Język</label><select id="language-code" class="form-select"><?php foreach (($languages ?? []) as $code => $label): ?><option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button id="prepare-difference" class="btn btn-primary w-100" type="button">Przygotuj</button></div>
    </div></div></div>

    <div class="card"><div class="card-header"><h5 class="mb-0">Nieopłacone proformy</h5><small class="text-muted">Lista nie obejmuje proform opłaconych ani anulowanych. Wartość jest sumą netto historycznych pozycji proformy.</small></div><div class="card-body table-responsive"><table class="table table-sm mb-0" id="unpaid-proformas-table"><thead><tr><th class="sortable-proforma" data-sort="doc_num" role="button">Proforma <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="sortable-proforma" data-sort="company" role="button">Klient <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="sortable-proforma" data-sort="date" role="button">Data <i class="bi bi-arrow-down text-primary small"></i></th><th class="sortable-proforma text-end" data-sort="amount" role="button">Wartość netto <i class="bi bi-arrow-down-up text-muted small"></i></th><th></th></tr></thead><tbody>
        <?php foreach (($proformas ?? []) as $proforma): ?><tr data-sort-doc_num="<?= htmlspecialchars(mb_strtolower((string)$proforma['doc_num']), ENT_QUOTES, 'UTF-8') ?>" data-sort-company="<?= htmlspecialchars(mb_strtolower((string)$proforma['company']), ENT_QUOTES, 'UTF-8') ?>" data-sort-date="<?= (int)(strtotime((string)$proforma['established_date']) ?: 0) ?>" data-sort-amount="<?= (float)($proforma['total_price_net'] ?? 0) ?>"><td><?= htmlspecialchars($proforma['doc_num'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($proforma['company'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($proforma['established_date'], ENT_QUOTES, 'UTF-8') ?></td><td class="text-end text-nowrap"><?= number_format((float)($proforma['total_price_net'] ?? 0), 2, ',', ' ') ?> EUR</td><td><button class="btn btn-sm btn-outline-primary reminder-proforma" type="button" data-id="<?= (int)$proforma['id'] ?>">Przygotuj przypomnienie</button></td></tr><?php endforeach; ?>
        <?php if (empty($proformas)): ?><tr><td colspan="5" class="text-muted text-center py-3">Brak nieopłaconych proform.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</section>

<?php $this->view('page_components/footer'); ?>
<script>
const reminderType = () => document.getElementById('message-type').value;
document.getElementById('prepare-difference').addEventListener('click', () => {
    const id = document.getElementById('difference-id').value;
    if (!id) { alert('Wybierz dokument różnic.'); return; }
    window.location.href = `<?= ROOT ?>/communication/compose/reminder/difference/${id}?type=${reminderType()}&language=${document.getElementById('language-code').value}`;
});
document.querySelectorAll('.reminder-proforma').forEach(button => button.addEventListener('click', () => {
    window.location.href = `<?= ROOT ?>/communication/compose/reminder/proforma/${button.dataset.id}?type=${reminderType()}&language=${document.getElementById('language-code').value}`;
}));

(() => {
    const table = document.getElementById('unpaid-proformas-table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    let activeColumn = 'date';
    let direction = -1;

    const sortRows = (column, sortDirection) => {
        const rows = Array.from(tbody.querySelectorAll('tr[data-sort-' + column + ']'));
        rows.sort((a, b) => {
            const aValue = a.getAttribute('data-sort-' + column);
            const bValue = b.getAttribute('data-sort-' + column);
            if (column === 'amount' || column === 'date') return (Number(aValue) - Number(bValue)) * sortDirection;
            return String(aValue).localeCompare(String(bValue), 'pl') * sortDirection;
        });
        rows.forEach(row => tbody.appendChild(row));

        table.querySelectorAll('.sortable-proforma i').forEach(icon => icon.className = 'bi bi-arrow-down-up text-muted small');
        const icon = table.querySelector('.sortable-proforma[data-sort="' + column + '"] i');
        icon.className = sortDirection === 1 ? 'bi bi-arrow-up text-primary small' : 'bi bi-arrow-down text-primary small';
    };

    table.querySelectorAll('.sortable-proforma').forEach(header => header.addEventListener('click', () => {
        const column = header.dataset.sort;
        direction = column === activeColumn ? direction * -1 : (column === 'date' ? -1 : 1);
        activeColumn = column;
        sortRows(activeColumn, direction);
    }));

    sortRows(activeColumn, direction);
})();
</script>
