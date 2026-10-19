<?php $this->view('page_components/header'); ?>
<div class="page-heading d-flex justify-content-between align-items-start gap-3">
    <div><h3>Wiadomości e-mail</h3><p class="text-subtitle text-muted mb-0">Audyt szkiców, kolejki oraz wiadomości wysłanych do klientów.</p></div>
    <a class="btn btn-outline-primary" href="<?= ROOT ?>/communication/email-settings">Ustawienia konta</a>
</div>
<section class="section"><div class="card"><div class="card-body table-responsive">
    <table class="table table-hover align-middle"><thead><tr><th>Data</th><th>Typ</th><th>Klient / odbiorca</th><th>Temat</th><th>Status</th><th>Próby</th><th></th></tr></thead><tbody>
    <?php foreach (($messages ?? []) as $item): ?>
        <?php $status = ['draft' => 'Szkic', 'queued' => 'W kolejce', 'processing' => 'Wysyłanie', 'sent' => 'Wysłana', 'failed' => 'Błąd'][$item['status']] ?? $item['status']; ?>
        <tr><td><?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($item['message_type'], ENT_QUOTES, 'UTF-8') ?></td><td><div><?= htmlspecialchars($item['company'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div><small class="text-muted"><?= htmlspecialchars($item['recipient_email'], ENT_QUOTES, 'UTF-8') ?></small></td><td><?= htmlspecialchars($item['subject_text'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></td><td><?= (int)$item['attempts'] ?></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= ROOT ?>/communication/messages/<?= (int)$item['id'] ?>">Szczegóły</a><?php if ($item['status'] === 'queued'): ?> <button class="btn btn-sm btn-primary send-now" type="button" data-id="<?= (int)$item['id'] ?>">Wyślij teraz</button><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($messages)): ?><tr><td colspan="7" class="text-center text-muted py-4">Brak przygotowanych wiadomości.</td></tr><?php endif; ?>
    </tbody></table>
</div></div></section>
<?php $this->view('page_components/footer'); ?>
<script>
document.querySelectorAll('.send-now').forEach(button => button.addEventListener('click', async () => {
    const confirmation = await Swal.fire({
        icon: 'question', title: 'Wysłać e-mail teraz?',
        text: 'Zostanie wykonana natychmiastowa próba wysyłki SMTP, bez oczekiwania na cron.',
        showCancelButton: true, confirmButtonText: 'Wyślij teraz', cancelButtonText: 'Anuluj'
    });
    if (!confirmation.isConfirmed) return;
    button.disabled = true;
    Swal.fire({
        title: 'Trwa wysyłanie e-maila',
        html: 'Trwa bezpośrednia komunikacja z serwerem SMTP. Proszę nie zamykać tego okna.',
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });
    try {
        const response = await fetch(`<?= ROOT ?>/ajax/communication/messages/${button.dataset.id}/send-now`, {method: 'POST', headers: {'Accept': 'application/json'}});
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Nie udało się wysłać wiadomości.');
        await Swal.fire({icon: 'success', title: 'Wysłano', text: data.message || 'Wiadomość została wysłana.'});
        window.location.reload();
    } catch (error) {
        await Swal.fire({icon: 'error', title: 'Błąd wysyłki', text: error.message});
        button.disabled = false;
    }
}));
</script>
