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

    <?php $autoLevels = (int)($auto_levels ?? 0); $autoPlan = $auto_plan ?? []; ?>
    <div class="card mb-4" id="reminder-auto"><div class="card-body">
        <div class="d-flex flex-wrap align-items-end gap-3">
            <div class="flex-grow-1" style="min-width:280px"><h5 class="mb-1">Wysyłka automatyczna <span class="badge <?= $autoLevels ? 'bg-light-success text-success' : 'bg-light text-muted' ?>"><?= $autoLevels ? ($autoLevels === 1 ? 'poziom 1' : 'poziomy 1–2') : 'wyłączona' ?></span></h5>
                <small class="text-muted">Poziomy 1–2 (uprzejme) mogą wychodzić same co 5 minut : szablon w języku klienta, PDF proformy, adres z karty klienta ; każdy poziom raz na proformę, co najmniej 4 dni po poprzednim, bez proform starszych niż 90 dni. Poziomy 3–5 zawsze sprawdzasz i wysyłasz tutaj.</small></div>
            <label class="small m-0">Poziomy automatyczne<select id="auto-levels" class="form-select form-select-sm mt-1"><option value="0" <?= $autoLevels === 0 ? 'selected' : '' ?>>Wyłączone (wszystko ręcznie)</option><option value="1" <?= $autoLevels === 1 ? 'selected' : '' ?>>Poziom 1</option><option value="2" <?= $autoLevels === 2 ? 'selected' : '' ?>>Poziomy 1–2</option></select></label>
            <button id="save-auto-levels" class="btn btn-sm btn-primary" type="button">Zapisz</button>
        </div>
        <details class="mt-2 small"><summary><?= $autoLevels ? 'Wyjdzie w następnym przebiegu' : 'Po włączeniu poziomów 1–2 wyszłoby teraz' ?> : <strong><?= count($autoPlan) ?></strong> <?= count($autoPlan) === 1 ? 'przypomnienie' : 'przypomnień' ?></summary>
            <?php if ($autoPlan): ?><ul class="mb-0 mt-1"><?php foreach (array_slice($autoPlan, 0, 30) as $p): ?><li><?= htmlspecialchars($p['doc_num'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($p['company'], ENT_QUOTES, 'UTF-8') ?> · poziom <?= (int)$p['level'] ?>/5 · <?= (int)$p['days'] ?> dni</li><?php endforeach; ?></ul><?php else: ?><p class="text-muted mb-0 mt-1">Nic do wysłania.</p><?php endif; ?>
        </details>
    </div></div>

    <div class="card"><div class="card-header d-flex flex-wrap justify-content-between gap-2"><div><h5 class="mb-0">Nieopłacone proformy : prośba o potwierdzenie płatności (1–5)</h5><small class="text-muted">Bez proform opłaconych i anulowanych. Poziomy : 1 po 3 dniach (uprzejme), 2 po 7, 3 po 14 (stanowcze), 4 po 21 (pilne), 5 po 30 (ostatnie). E-mail w języku klienta (karta klienta), do sprawdzenia przed wysyłką.</small></div><div class="d-flex flex-column align-items-end gap-1"><label class="form-check-label small text-nowrap"><input type="checkbox" class="form-check-input" id="show-old-proformas"> pokaż starsze niż 90 dni</label><button id="mark-paid" class="btn btn-sm btn-outline-success" type="button" disabled><i class="bi bi-check2-all"></i> Opłacone (<span id="mark-paid-count">0</span>)</button></div></div><div class="card-body table-responsive"><table class="table table-sm mb-0" id="unpaid-proformas-table"><thead><tr><th><input type="checkbox" class="form-check-input" id="paid-all" aria-label="Zaznacz widoczne"></th><th class="sortable-proforma" data-sort="doc_num" role="button">Proforma <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="sortable-proforma" data-sort="company" role="button">Klient <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="sortable-proforma" data-sort="date" role="button">Data <i class="bi bi-arrow-down text-primary small"></i></th><th class="sortable-proforma text-end" data-sort="amount" role="button">Wartość netto <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="sortable-proforma text-end" data-sort="days" role="button">Dni <i class="bi bi-arrow-down-up text-muted small"></i></th><th>Przypomnienia</th><th></th></tr></thead><tbody>
        <?php foreach (($proformas ?? []) as $proforma): ?><?php $level = (int)($proforma['level'] ?? 0); $next = $proforma['next_level'] ?? null; ?><tr data-days="<?= (int)($proforma['days'] ?? 0) ?>" data-sort-days="<?= (int)($proforma['days'] ?? 0) ?>" data-sort-doc_num="<?= htmlspecialchars(mb_strtolower((string)$proforma['doc_num']), ENT_QUOTES, 'UTF-8') ?>" data-sort-company="<?= htmlspecialchars(mb_strtolower((string)$proforma['company']), ENT_QUOTES, 'UTF-8') ?>" data-sort-date="<?= (int)(strtotime((string)$proforma['established_date']) ?: 0) ?>" data-sort-amount="<?= (float)($proforma['total_price_net'] ?? 0) ?>"><td><input type="checkbox" class="form-check-input paid-check" value="<?= (int)$proforma['id'] ?>" aria-label="Zaznacz <?= htmlspecialchars($proforma['doc_num'], ENT_QUOTES, 'UTF-8') ?>"></td><td><?= htmlspecialchars($proforma['doc_num'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($proforma['company'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($proforma['established_date'], ENT_QUOTES, 'UTF-8') ?></td><td class="text-end text-nowrap"><?= number_format((float)($proforma['total_price_net'] ?? 0), 2, ',', ' ') ?> EUR</td><td class="text-end"><?= (int)($proforma['days'] ?? 0) ?></td><td class="text-nowrap"><span class="badge <?= $level ? 'bg-light-warning text-dark' : 'bg-light text-muted' ?>" title="<?= $proforma['last_reminder_at'] ? 'Ostatnie : ' . htmlspecialchars((string)$proforma['last_reminder_at'], ENT_QUOTES, 'UTF-8') : 'Jeszcze bez przypomnień' ?>"><?= $level ?>/5</span><?php if (!empty($proforma['due'])): ?> <span class="badge bg-danger" title="Zalecane po <?= \App\Services\Communication\CommunicationService::PROFORMA_REMINDER_DAYS[$next] ?> dniach">czas na <?= (int)$next ?>/5</span><?php endif; ?></td><td class="text-nowrap"><div class="btn-group btn-group-sm"><?php if ($next): ?><button class="btn <?= !empty($proforma['due']) ? 'btn-primary' : 'btn-outline-primary' ?> reminder-proforma" type="button" data-id="<?= (int)$proforma['id'] ?>" data-level="<?= (int)$next ?>">Przypomnienie <?= (int)$next ?>/5</button><?php endif; ?><button class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-label="Inny poziom"></button><ul class="dropdown-menu dropdown-menu-end"><?php for ($l = 1; $l <= 5; $l++): ?><li><button class="dropdown-item reminder-proforma" type="button" data-id="<?= (int)$proforma['id'] ?>" data-level="<?= $l ?>">Przypomnienie <?= $l ?>/5 (od <?= \App\Services\Communication\CommunicationService::PROFORMA_REMINDER_DAYS[$l] ?> dni)</button></li><?php endfor; ?></ul></div></td></tr><?php endforeach; ?>
        <?php if (empty($proformas)): ?><tr><td colspan="8" class="text-muted text-center py-3">Brak nieopłaconych proform.</td></tr><?php endif; ?>
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
    // język z karty klienta (formularz pozwala go zmienić)
    window.location.href = `<?= ROOT ?>/communication/compose/reminder/proforma/${button.dataset.id}?type=proforma_reminder_${button.dataset.level}`;
}));
// P5 : wysyłka automatyczna poziomów 1–2 i « Opłacone » dla zaznaczonych proform
const postJson = (url, method, data) => fetch(`<?= ROOT ?>${url}`, {method, credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: JSON.stringify(data)})
    .then(r => r.json().catch(() => ({})).then(b => ({ok: r.ok, ...b})), () => ({ok: false, message: 'Brak połączenia z serwerem.'}));
const notify = (ok, msg) => (typeof showSnack === 'function' ? showSnack(ok, msg, msg) : alert(msg));
document.getElementById('save-auto-levels').addEventListener('click', async (e) => {
    const levels = document.getElementById('auto-levels').value;
    if (levels !== '0' && !confirm(levels === '1' ? 'Przypomnienia poziomu 1 będą wychodzić automatycznie. Kontynuować ?' : 'Przypomnienia poziomów 1 i 2 będą wychodzić automatycznie. Kontynuować ?')) return;
    e.currentTarget.disabled = true;
    const res = await postJson('/ajax/communication/payment-reminders/settings', 'PUT', {auto_levels: levels});
    notify(res.ok, res.message || 'Błąd');
    if (res.ok) setTimeout(() => location.reload(), 900); else e.currentTarget.disabled = false;
});
const paidChecks = () => [...document.querySelectorAll('#unpaid-proformas-table .paid-check')];
const refreshPaid = () => {
    const n = paidChecks().filter(c => c.checked).length;
    document.getElementById('mark-paid-count').textContent = n;
    document.getElementById('mark-paid').disabled = !n;
};
paidChecks().forEach(c => c.addEventListener('change', refreshPaid));
document.getElementById('paid-all').addEventListener('change', (e) => { paidChecks().forEach(c => { if (!c.closest('tr').hidden) c.checked = e.target.checked; }); refreshPaid(); });
document.getElementById('mark-paid').addEventListener('click', async (e) => {
    const ids = paidChecks().filter(c => c.checked).map(c => Number(c.value));
    if (!ids.length || !confirm(`Oznaczyć ${ids.length} proform jako opłacone ? Ich pozycje staną się niezmienne.`)) return;
    e.currentTarget.disabled = true;
    const res = await postJson('/ajax/communication/payment-reminders/paid', 'POST', {ids});
    notify(res.ok, res.message || 'Błąd');
    if (res.ok) setTimeout(() => location.reload(), 900); else e.currentTarget.disabled = false;
});
// Starsze proformy (> 90 dni) ukryte : zwykle zaległości w ewidencji płatności, nie bieżące należności
const toggleOld = () => document.querySelectorAll('#unpaid-proformas-table tbody tr[data-days]').forEach(tr => { tr.hidden = !document.getElementById('show-old-proformas').checked && Number(tr.dataset.days) > 90; });
document.getElementById('show-old-proformas').addEventListener('change', toggleOld);
toggleOld();

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
            if (column === 'amount' || column === 'date' || column === 'days') return (Number(aValue) - Number(bValue)) * sortDirection;
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
